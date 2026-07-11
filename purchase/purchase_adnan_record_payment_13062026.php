<?php
require_once __DIR__ . '/../core/init.php';
restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$pageTitle = "Record Payment";

// Get PO ID
$po_id = $_GET['po_id'] ?? null;

// Initialize managers
$po_manager = new Purchaseadnanmanager();
$payment_manager = new Purchasepaymentadnanmanager();

// Get list of POs with outstanding balance
$outstanding_pos = $po_manager->listPurchaseOrders(['payment_status' => ['unpaid', 'partial','overpaid','paid']]);

// Get bank accounts and cash accounts
$bank_accounts = $payment_manager->getAllBankAccounts();
$cash_accounts = $payment_manager->getAllCashAccounts();

// Get employees
$employees = $payment_manager->getAllEmployees();

// Pre-load supplier credit balances for all unique supplier_ids in the PO list
$supplier_credits = [];
foreach ($outstanding_pos as $po_item) {
    $sid = (int)($po_item->supplier_id ?? 0);
    if ($sid && !isset($supplier_credits[$sid])) {
        $cr = $po_manager->getSupplierCreditBalance($sid);
        $supplier_credits[$sid] = $cr ? floatval($cr->available_balance) : 0;
    }
}

// If PO ID provided, get PO details
$selected_po = null;
if ($po_id) {
    $selected_po = $po_manager->getPurchaseOrder($po_id);
}

// ── Supplier Sale: Load all distinct suppliers ──────────────
$db_pdo = Database::getInstance()->getPdo();
$_sup_q = $db_pdo->prepare("SELECT DISTINCT supplier_id, supplier_name FROM purchase_orders_adnan WHERE supplier_id IS NOT NULL ORDER BY supplier_name ASC");
$_sup_q->execute();
$all_suppliers = $_sup_q->fetchAll(PDO::FETCH_OBJ);

// ── Supplier Sale: POST handler ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'supplier_sale') {
    try {
        $supplier_id  = (int)($_POST['sale_supplier_id']       ?? 0);
        $sale_amount  = floatval($_POST['sale_total_amount']    ?? 0);
        $sale_date    = $_POST['sale_date']                     ?? date('Y-m-d');
        $item_desc    = trim($_POST['sale_item_description']    ?? '');
        $qty          = floatval($_POST['sale_quantity']        ?? 0);
        $unit         = trim($_POST['sale_unit']                ?? 'KG');
        $rate         = floatval($_POST['sale_unit_price']      ?? 0);
        $settlement   = $_POST['sale_settlement']               ?? 'barter';
        $challan      = trim($_POST['sale_challan_number']      ?? '');
        $sale_remarks = trim($_POST['sale_remarks']             ?? '');

        if (!$supplier_id)     throw new Exception('Please select a supplier.');
        if ($sale_amount <= 0) throw new Exception('Sale amount must be greater than zero.');
        if (empty($item_desc)) throw new Exception('Item / goods description is required.');

        $cur     = getCurrentUser();
        $user_id = $cur['id'] ?? null;

        // Get supplier name for display
        $sn_q = $db_pdo->prepare("SELECT supplier_name FROM purchase_orders_adnan WHERE supplier_id = ? LIMIT 1");
        $sn_q->execute([$supplier_id]);
        $supplier_name = $sn_q->fetchColumn() ?: "Supplier #{$supplier_id}";

        // Build descriptive remarks line
        $built_desc = "SALE: {$item_desc}";
        if ($qty > 0)  $built_desc .= " | " . number_format($qty, 2) . " {$unit}";
        if ($rate > 0) $built_desc .= " @ ৳" . number_format($rate, 2) . "/unit";
        $built_desc .= match($settlement) {
            'bank'  => " | [Bank Receipt]",
            'cash'  => " | [Cash Receipt]",
            default => " | [Barter/Netting]",
        };
        if ($challan)      $built_desc .= " | Challan: {$challan}";
        if ($sale_remarks) $built_desc .= "\n" . $sale_remarks;

        // Auto-generate voucher number SLS-YYYY-NNNN
        $yr   = date('Y');
        $c_q  = $db_pdo->prepare("SELECT COUNT(*) FROM supplier_ledger_adjustments WHERE YEAR(created_at) = ?");
        $c_q->execute([$yr]);
        $adj_number = 'SLS-' . $yr . '-' . str_pad($c_q->fetchColumn() + 1, 4, '0', STR_PAD_LEFT);

        // Bank / cash info
        $bank_acct_id   = null;
        $bank_acct_name = null;
        if ($settlement === 'bank') {
            $bank_acct_id = !empty($_POST['sale_bank_account_id']) ? (int)$_POST['sale_bank_account_id'] : null;
            if ($bank_acct_id) {
                $bk = $db_pdo->prepare("SELECT CONCAT(bank_name,' – ',account_name) AS n FROM bank_tx_accounts WHERE id = ?");
                $bk->execute([$bank_acct_id]);
                $bk_row = $bk->fetch(PDO::FETCH_OBJ);
                $bank_acct_name = $bk_row->n ?? null;
            }
        } elseif ($settlement === 'cash') {
            $bank_acct_name = $_POST['sale_cash_account_name'] ?? null;
        }

        $ins = $db_pdo->prepare("
            INSERT INTO supplier_ledger_adjustments
                (adj_number, supplier_id, adj_type, amount, debit, credit,
                 remarks, payment_method, payment_reference,
                 bank_account_id, bank_account_name,
                 event_date, entry_date, status, created_by_user_id)
            VALUES (?, ?, 'reduce_payable', ?, 0, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, CURDATE(), 'posted', ?)
        ");
        $ins->execute([
            $adj_number, $supplier_id,
            $sale_amount, $sale_amount,
            $built_desc, $settlement, ($challan ?: null),
            $bank_acct_id, $bank_acct_name,
            $sale_date, $user_id,
        ]);

        if (function_exists('auditLog')) {
            auditLog('purchase', 'created',
                "Supplier Sale {$adj_number}: {$item_desc} — ৳" . number_format($sale_amount, 2) .
                " [{$settlement}] for {$supplier_name}",
                [
                    'record_type' => 'supplier_sale',
                    'adj_number'  => $adj_number,
                    'supplier_id' => $supplier_id,
                    'amount'      => $sale_amount,
                    'settlement'  => $settlement,
                    'created_by'  => $cur['display_name'] ?? 'System',
                ]
            );
        }

        $_SESSION['success'] = "Supplier Sale <strong>{$adj_number}</strong> recorded for {$supplier_name}. " .
            ($settlement === 'barter'
                ? "Payable reduced by ৳" . number_format($sale_amount, 2) . " via barter netting."
                : ucfirst($settlement) . " receipt of ৳" . number_format($sale_amount, 2) . " noted.");
        redirect('purchase/purchase_adnan_supplier_ledger.php?supplier_id=' . $supplier_id);

    } catch (Exception $e) {
        $_SESSION['error'] = "Sale recording failed: " . $e->getMessage();
    }
}

// ── Regular Payment: POST handler ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? 'payment') === 'payment') {
    try {
        // ✅ CRITICAL FIX: Handle bank_account_id based on payment method
        $bank_account_id = null;
        $bank_name = null;
        
        if ($_POST['payment_method'] === 'bank' || $_POST['payment_method'] === 'cheque') {
            // Bank/Cheque: Use bank_account_id from form
            $bank_account_id = !empty($_POST['bank_account_id']) ? $_POST['bank_account_id'] : null;
        } elseif ($_POST['payment_method'] === 'cash') {
            // Cash: bank_account_id stays NULL (to avoid FK violation)
            // Store cash account info in bank_name field
            $bank_account_id = null;
            $bank_name = $_POST['cash_account_name'] ?? null; // From hidden field
        }
        
        $data = [
            'purchase_order_id' => $_POST['purchase_order_id'],
            'payment_date' => $_POST['payment_date'],
            'amount_paid' => $_POST['amount_paid'],
            'payment_method' => $_POST['payment_method'],
            'bank_account_id' => $bank_account_id,  // ✅ NULL for cash, ID for bank/cheque
            'bank_name' => $bank_name,              // ✅ Cash account name for cash payments
            'cash_account_id' => $_POST['cash_account_id'] ?? null,  // ✅ NEW: Cash account ID from branch_petty_cash_accounts
            'reference_number' => $_POST['reference_number'] ?? null,
            'payment_type' => $_POST['payment_type'] ?? 'regular',
            'handled_by_employee' => $_POST['handled_by_employee'] ?? null,
            'remarks' => $_POST['remarks'] ?? null
        ];

        $result = $payment_manager->recordPayment($data);
        
        if ($result['success']) {

            // ── Apply supplier credit if requested ─────────────────────────────
            $apply_credit  = ($_POST['apply_credit'] ?? '') === '1';
            $credit_amount = floatval($_POST['credit_amount'] ?? 0);

            if ($apply_credit && $credit_amount > 0) {
                $po_for_credit   = $po_manager->getPurchaseOrder($data['purchase_order_id']);
                $actual_pmt_id   = is_array($result['payment_id'])
                    ? ($result['payment_id']['id'] ?? $result['payment_id'][0] ?? null)
                    : $result['payment_id'];
                $cur = getCurrentUser();
                $po_manager->applySupplierCredit(
                    $po_for_credit->supplier_id,
                    $data['purchase_order_id'],
                    $actual_pmt_id,
                    $credit_amount,
                    $cur['id']            ?? null,
                    $cur['display_name']  ?? 'System'
                );
                $_SESSION['info'] = "Supplier credit of ৳" . number_format($credit_amount, 2) . " applied against this payment.";
            }

            try {
                if (function_exists('auditLog')) {
                    $currentUser = getCurrentUser();
                    $user_name = $currentUser['display_name'] ?? 'System User';
                    
                    // Get PO details for audit
                    $po = $po_manager->getPurchaseOrder($data['purchase_order_id']);
                    
                    auditLog(
                        'purchase',
                        'created',
                        "Payment {$result['voucher_number']} created - ৳" . number_format($data['amount_paid'], 2) . " for PO #{$po->po_number} ({$po->supplier_name}) via {$data['payment_method']}",
                        [
                            'record_type' => 'purchase_payment',
                            'record_id' => $result['payment_id'],
                            'reference_number' => $result['voucher_number'],
                            'po_id' => $po->id,
                            'po_number' => $po->po_number,
                            'supplier_name' => $po->supplier_name,
                            'amount_paid' => $data['amount_paid'],
                            'payment_method' => $data['payment_method'],
                            'payment_type' => $data['payment_type'],
                            'bank_account_id' => $bank_account_id,
                            'reference_number' => $data['reference_number'],
                            'payment_date' => $data['payment_date'],
                            'created_by' => $user_name
                        ]
                    );
                }
            } catch (Exception $e) {
                error_log("✗ Audit log error: " . $e->getMessage());
            }
            
            
            // ============================================
            // TELEGRAM NOTIFICATION - PAYMENT RECORDED
            // ============================================
            try {
                if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
                    require_once '../core/classes/TelegramNotifier.php';
                    $telegram = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);
                    
                    $db = Database::getInstance();
                    
                    // Handle if payment_id is an array
                    $actual_payment_id = is_array($result['payment_id']) 
                        ? ($result['payment_id']['id'] ?? $result['payment_id'][0] ?? null) 
                        : $result['payment_id'];
                    
                    if (!$actual_payment_id) {
                        error_log("✗ Telegram payment notification: Invalid payment ID - " . print_r($result['payment_id'], true));
                        throw new Exception("Invalid payment ID");
                    }
                    
                    // Get complete payment details (most data is cached)
                    $payment = $db->query(
                        "SELECT pp.*, 
                                po.wheat_origin, po.total_order_value,
                                po.total_paid, po.balance_payable
                         FROM purchase_payments_adnan pp
                         LEFT JOIN purchase_orders_adnan po ON pp.purchase_order_id = po.id
                         WHERE pp.id = ?",
                        [$actual_payment_id]
                    )->first();
                    
                    if ($payment) {
                        // Get current user info
                        $currentUser = getCurrentUser();
                        $user_name = $currentUser['display_name'] ?? 'System User';
                        
                        // Calculate payment percentage
                        $payment_percentage = floatval($payment->total_order_value) > 0 
                            ? (floatval($payment->total_paid) / floatval($payment->total_order_value)) * 100 
                            : 0;
                        
                        // Prepare payment data
                        $paymentData = [
                            'voucher_number' => $payment->payment_voucher_number,
                            'payment_date' => date('d M Y', strtotime($payment->payment_date)),
                            'po_number' => $payment->po_number,
                            'supplier_name' => $payment->supplier_name,
                            'wheat_origin' => $payment->wheat_origin,
                            'amount_paid' => floatval($payment->amount_paid),
                            'payment_method' => ucfirst($payment->payment_method),
                            'bank_account' => $payment->bank_name ?: 'Cash',
                            'reference_number' => $payment->reference_number ?: '',
                            'total_order_value' => floatval($payment->total_order_value),
                            'total_paid' => floatval($payment->total_paid),
                            'balance_payable' => floatval($payment->balance_payable),
                            'payment_percentage' => $payment_percentage,
                            'payment_type' => ucfirst($payment->payment_type),
                            'employee_name' => $payment->handled_by_employee ?: '',
                            'remarks' => $payment->remarks ?: '',
                            'recorded_by' => $user_name
                        ];
                        
                        // Send notification
                        $notif_result = $telegram->sendPurchasePaymentNotification($paymentData);
                        
                        if ($notif_result['success']) {
                            error_log("✓ Telegram purchase payment notification sent: " . $payment->payment_voucher_number);
                        } else {
                            error_log("✗ Telegram purchase payment notification failed: " . json_encode($notif_result['response']));
                        }
                    } else {
                        error_log("✗ Telegram payment notification: Payment not found with ID: " . $actual_payment_id);
                    }
                }
            } catch (Exception $e) {
                error_log("✗ Telegram purchase payment notification error: " . $e->getMessage());
            }
            // END TELEGRAM NOTIFICATION
            
            $_SESSION['success'] = $result['message'] . " Voucher: {$result['voucher_number']}";
            redirect('purchase/purchase_adnan_view_po.php?id=' . $data['purchase_order_id']);
        } else {
            $_SESSION['error'] = $result['message'];
        }
        
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
}

require_once '../templates/header.php';
?>

<div class="w-full px-4 py-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-4">
        <div>
            <h2 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-exchange-alt text-yellow-600"></i> Supplier Transactions
            </h2>
            <nav class="text-sm text-gray-600 mt-1">
                <a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>" class="hover:text-primary-600">Purchase (Adnan)</a>
                <span class="mx-2">›</span>
                <span>Record Transaction</span>
            </nav>
        </div>
        <a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>" class="border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 flex items-center gap-2">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>

    <!-- Tab Switcher -->
    <div class="flex gap-1 mb-6 bg-gray-100 p-1 rounded-xl w-fit">
        <button type="button" id="tab-btn-payment"
                onclick="switchTab('payment')"
                class="tab-btn px-5 py-2.5 rounded-lg text-sm font-semibold transition-all bg-white shadow text-yellow-700">
            <i class="fas fa-money-bill-wave mr-2"></i> Record Payment
        </button>
        <button type="button" id="tab-btn-sale"
                onclick="switchTab('sale')"
                class="tab-btn px-5 py-2.5 rounded-lg text-sm font-semibold transition-all text-gray-600 hover:text-gray-800">
            <i class="fas fa-handshake mr-2 text-teal-600"></i> Supplier Sale / Barter
        </button>
    </div>

    <?php if (isset($_SESSION['error'])): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-6 flex items-center justify-between">
        <span><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
        <button onclick="this.parentElement.remove()" class="text-red-600 hover:text-red-800">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['info'])): ?>
    <div class="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-lg mb-6 flex items-center justify-between">
        <span><?php echo $_SESSION['info']; unset($_SESSION['info']); ?></span>
        <button onclick="this.parentElement.remove()" class="text-blue-600 hover:text-blue-800">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <?php endif; ?>

    <!-- ══ TAB 1: Regular Payment ══════════════════════════════ -->
    <div id="tab-panel-payment">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow">
                <div class="bg-yellow-500 text-white px-6 py-4 rounded-t-lg">
                    <h5 class="font-semibold">Payment Details</h5>
                </div>
                <div class="p-6">
                    <form method="POST" id="paymentForm">
                        <input type="hidden" name="form_action" value="payment">
                        <!-- Purchase Order Selection -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Purchase Order <span class="text-red-500">*</span>
                            </label>
                            <select name="purchase_order_id" id="purchase_order_id" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500" 
                                    required>
                                <option value="">-- Select Purchase Order --</option>
                                <?php foreach ($outstanding_pos as $po):
                                    $po_supplier_id  = (int)($po->supplier_id ?? 0);
                                    $po_credit_avail = $supplier_credits[$po_supplier_id] ?? 0;
                                ?>
                                <option value="<?php echo $po->id; ?>"
                                        data-supplier="<?php echo htmlspecialchars($po->supplier_name); ?>"
                                        data-supplier-id="<?php echo $po_supplier_id; ?>"
                                        data-balance="<?php echo $po->balance_payable; ?>"
                                        data-received-value="<?php echo $po->total_received_value; ?>"
                                        data-paid="<?php echo $po->total_paid; ?>"
                                        data-credit="<?php echo $po_credit_avail; ?>"
                                        <?php echo $selected_po && $selected_po->id == $po->id ? 'selected' : ''; ?>>
                                    PO #<?php echo $po->po_number; ?> - <?php echo htmlspecialchars($po->supplier_name); ?>
                                    (Balance: ৳<?php echo number_format($po->balance_payable, 2); ?>
                                     <?php if ($po_credit_avail > 0): ?>| Credit: ৳<?php echo number_format($po_credit_avail, 0); ?><?php endif; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- PO Summary -->
                        <div id="poSummary" class="mb-4 hidden">
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="space-y-1">
                                        <div><strong>Supplier:</strong> <span id="poSupplier"></span></div>
                                    </div>
                                    <div class="space-y-1 text-right">
                                        <div><strong>Received Value:</strong> ৳<span id="poReceivedValue"></span></div>
                                        <div><strong>Already Paid:</strong> ৳<span id="poPaid"></span></div>
                                        <div class="text-red-600"><strong>Balance Due:</strong> ৳<span id="poBalance"></span></div>
                                    </div>
                                </div>
                                <!-- Supplier credit row (shown only when credit > 0) -->
                                <div id="creditAvailRow" class="hidden mt-3 pt-3 border-t border-blue-200 flex items-center justify-between">
                                    <div class="flex items-center gap-2 text-green-700">
                                        <i class="fas fa-piggy-bank"></i>
                                        <strong>Supplier Credit Available:</strong>
                                        <span id="poCreditBalance" class="font-bold text-green-800">৳0.00</span>
                                    </div>
                                    <button type="button" id="btnApplyCredit"
                                            class="text-sm bg-green-100 text-green-800 border border-green-300 px-3 py-1 rounded hover:bg-green-200">
                                        Apply Credit
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Credit Application Section (shown when "Apply Credit" clicked) -->
                        <div id="creditSection" class="mb-4 hidden">
                            <div class="bg-green-50 border border-green-300 rounded-lg p-4">
                                <h6 class="font-semibold text-green-800 mb-3 flex items-center gap-2">
                                    <i class="fas fa-piggy-bank"></i> Apply Supplier Credit Balance
                                </h6>
                                <p class="text-sm text-green-700 mb-3">
                                    This supplier has an available credit from a posted Credit Note (CAN).
                                    You can apply part or all of it against this payment — it reduces the
                                    <em>effective</em> cash outflow without changing the payment amount.
                                </p>
                                <div class="flex items-center gap-3">
                                    <div class="flex-1">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">
                                            Credit Amount to Apply (৳)
                                        </label>
                                        <input type="number" name="credit_amount" id="credit_amount"
                                               class="w-full px-3 py-2 border border-green-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                                               step="0.01" min="0.01" placeholder="0.00"
                                               oninput="updateCreditSummary()">
                                        <p class="text-xs text-gray-500 mt-1">Max: ৳<span id="maxCreditDisplay">0.00</span></p>
                                    </div>
                                    <button type="button" id="btnApplyMaxCredit"
                                            class="bg-green-600 text-white px-3 py-2 rounded text-sm hover:bg-green-700 mt-4">
                                        Apply Max
                                    </button>
                                </div>
                                <div id="creditSummary" class="mt-3 text-sm text-green-800 hidden">
                                    <strong>Net cash required:</strong>
                                    ৳<span id="netCashRequired">0.00</span>
                                    (Payment: ৳<span id="csTotalPay">0.00</span> − Credit: ৳<span id="csCreditAmt">0.00</span>)
                                </div>
                                <div class="mt-2 flex items-center gap-2">
                                    <input type="checkbox" name="apply_credit" id="apply_credit" value="1" checked class="w-4 h-4">
                                    <label for="apply_credit" class="text-sm text-green-700">
                                        Confirm credit application (will be recorded in supplier ledger)
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <!-- Payment Date -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Payment Date <span class="text-red-500">*</span>
                                </label>
                                <input type="date" name="payment_date" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
                                       value="<?php echo date('Y-m-d'); ?>" required max="<?php echo date('Y-m-d'); ?>">
                            </div>

                            <!-- Amount -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Amount Paid (৳) <span class="text-red-500">*</span>
                                </label>
                                <input type="number" name="amount_paid" id="amount_paid" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
                                       step="0.01" min="0.01" required>
                                <button type="button" class="text-sm text-primary-600 hover:underline mt-1" id="payFullBalance">
                                    Pay Full Balance
                                </button>
                            </div>
                        </div>

                        <!-- Advance Payment Alert -->
                        <div id="advanceAlert" class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-lg mb-4 hidden">
                            <i class="fas fa-exclamation-triangle"></i> 
                            <strong>Advance Payment:</strong> Amount exceeds received value. This will be recorded as an advance payment.
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <!-- Payment Method -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Payment Method <span class="text-red-500">*</span>
                                </label>
                                <select name="payment_method" id="payment_method" 
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500" 
                                        required>
                                    <option value="">-- Select Method --</option>
                                    <option value="bank">Bank Transfer/Deposit</option>
                                    <option value="cash">Cash</option>
                                    <option value="cheque">Cheque</option>
                                </select>
                            </div>

                            <!-- Payment Type -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Payment Type
                                </label>
                                <select name="payment_type" 
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500">
                                    <option value="regular">Regular Payment</option>
                                    <option value="advance">Advance Payment</option>
                                    <option value="final">Final Payment</option>
                                </select>
                            </div>
                        </div>

                        <!-- Bank Account (for bank/cheque payment) -->
                        <div id="bankAccountDiv" class="mb-4 hidden">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Bank Account <span class="text-red-500">*</span>
                            </label>
                            <select name="bank_account_id" id="bank_account_id" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500">
                                <option value="">-- Select Bank Account --</option>
                                <?php 
                                $current_user = getCurrentUser();
                                $can_see_balance = in_array($current_user['role'], ['Superadmin', 'Accounts']);
                                foreach ($bank_accounts as $bank): 
                                ?>
                                <option value="<?php echo $bank->id; ?>">
                                    <?php echo htmlspecialchars($bank->bank_name); ?> - <?php echo htmlspecialchars($bank->account_name); ?> 
                                    (<?php echo htmlspecialchars($bank->account_number); ?>)
                                    <?php if ($can_see_balance): ?>
                                        - Bal: ৳<?php echo number_format($bank->current_balance, 2); ?>
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Cash Account (for cash payment) -->
                        <div id="cashAccountDiv" class="mb-4 hidden">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Cash Account <span class="text-red-500">*</span>
                            </label>
                            <select id="cash_account_select" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500">
                                <option value="">-- Select Cash Account --</option>
                                <?php if (empty($cash_accounts)): ?>
                                    <option value="" disabled>No cash accounts found - contact admin</option>
                                <?php else: ?>
                                    <?php foreach ($cash_accounts as $cash): ?>
                                    <option value="<?php echo $cash->id; ?>" 
                                            data-name="<?php echo htmlspecialchars($cash->account_name); ?>"
                                            data-branch="<?php echo htmlspecialchars($cash->branch_name ?? 'N/A'); ?>">
                                        <?php echo htmlspecialchars($cash->account_name); ?>
                                        <?php if ($cash->branch_name): ?>
                                            - <?php echo htmlspecialchars($cash->branch_name); ?>
                                        <?php endif; ?>
                                        <?php if ($can_see_balance): ?>
                                            - Bal: ৳<?php echo number_format($cash->current_balance, 2); ?>
                                        <?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <!-- Hidden fields to pass cash account info to backend -->
                            <input type="hidden" name="cash_account_id" id="cash_account_id">
                            <input type="hidden" name="cash_account_name" id="cash_account_name">
                            <p class="text-xs text-gray-500 mt-1">
                                <i class="fas fa-info-circle"></i> Select the petty cash account from which payment is made
                            </p>
                        </div>

                        <!-- Employee (for cash payment) -->
                        <div id="employeeDiv" class="mb-4 hidden">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Handled By (Employee) <span class="text-red-500">*</span>
                            </label>
                            <select name="handled_by_employee" id="handled_by_employee" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500">
                                <option value="">-- Select Employee --</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo htmlspecialchars($emp->name); ?>">
                                    <?php echo htmlspecialchars($emp->name); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Reference Number -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Reference / Cheque / Transaction Number
                            </label>
                            <input type="text" name="reference_number" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
                                   placeholder="Transaction ID, Cheque Number, etc.">
                        </div>

                        <!-- Remarks -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Remarks
                            </label>
                            <textarea name="remarks" rows="3"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500"
                                      placeholder="Any notes about this payment..."></textarea>
                        </div>

                        <!-- Submit Buttons -->
                        <div class="flex justify-between">
                            <a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>" 
                               class="border border-gray-300 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-50">
                                Cancel
                            </a>
                            <button type="submit" 
                                    class="bg-yellow-500 text-white px-6 py-2 rounded-lg hover:bg-yellow-600 flex items-center gap-2">
                                <i class="fas fa-save"></i> Record Payment
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div>
            <!-- Help Card -->
            <div class="bg-white rounded-lg shadow mb-4">
                <div class="bg-blue-600 text-white px-6 py-4 rounded-t-lg">
                    <h5 class="font-semibold flex items-center gap-2">
                        <i class="fas fa-info-circle"></i> Instructions
                    </h5>
                </div>
                <div class="p-6">
                    <h6 class="font-semibold mb-2">Payment Methods:</h6>
                    <ul class="space-y-2 text-sm text-gray-700">
                        <li><strong>Bank:</strong> Transfer/deposit through bank account</li>
                        <li><strong>Cash:</strong> Payment from petty cash account (requires employee & cash account)</li>
                        <li><strong>Cheque:</strong> Payment by cheque from bank account</li>
                    </ul>

                    <hr class="my-4">

                    <h6 class="font-semibold mb-2">Payment Types:</h6>
                    <ul class="space-y-2 text-sm text-gray-700">
                        <li><strong>Regular:</strong> Normal payment against delivered goods</li>
                        <li><strong>Advance:</strong> Payment before goods received</li>
                        <li><strong>Final:</strong> Last payment settling balance</li>
                    </ul>

                    <hr class="my-4">

                    <div class="bg-yellow-50 border border-yellow-200 rounded p-3 text-sm">
                        <i class="fas fa-exclamation-triangle text-yellow-600"></i>
                        <strong>Cash Payments:</strong> Select the branch petty cash account and employee who handled the transaction.
                    </div>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="bg-white rounded-lg shadow">
                <div class="bg-gray-600 text-white px-6 py-4 rounded-t-lg">
                    <h5 class="font-semibold flex items-center gap-2">
                        <i class="fas fa-chart-pie"></i> Payment Summary
                    </h5>
                </div>
                <div class="p-6">
                    <div class="mb-3">
                        <small class="text-gray-600">Total Outstanding POs:</small>
                        <h4 class="text-2xl font-bold"><?php echo count($outstanding_pos); ?></h4>
                    </div>
                    <div class="mb-3">
                        <small class="text-gray-600">Total Balance Due:</small>
                        <h4 class="text-2xl font-bold text-red-600">
                            ৳<?php echo number_format(array_sum(array_column($outstanding_pos, 'balance_payable')), 2); ?>
                        </h4>
                    </div>
                    <?php if (!empty($cash_accounts)): ?>
                    <div class="mt-4 pt-4 border-t border-gray-200">
                        <small class="text-gray-600">Available Cash Accounts:</small>
                        <h4 class="text-lg font-semibold text-green-600"><?php echo count($cash_accounts); ?></h4>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    </div><!-- end tab-panel-payment -->

    <!-- ══ TAB 2: Supplier Sale / Barter ══════════════════════ -->
    <div id="tab-panel-sale" class="hidden">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Sale Form -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow">
                <div class="bg-teal-600 text-white px-6 py-4 rounded-t-lg flex items-center gap-3">
                    <i class="fas fa-handshake text-xl"></i>
                    <div>
                        <h5 class="font-semibold">Record Supplier Sale / Barter</h5>
                        <p class="text-teal-100 text-xs mt-0.5">Sold goods to supplier — net against payable (barter) or record cash/bank receipt</p>
                    </div>
                </div>
                <div class="p-6">
                    <form method="POST" id="saleForm">
                        <input type="hidden" name="form_action" value="supplier_sale">

                        <!-- Supplier -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2 flex items-center gap-2">
                                Supplier <span class="text-red-500">*</span>
                                <?php if ($selected_po): ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-teal-100 text-teal-700 text-xs rounded-full font-normal">
                                    <i class="fas fa-link text-xs"></i>
                                    Auto-loaded from PO #<?php echo htmlspecialchars($selected_po->po_number); ?>
                                </span>
                                <?php endif; ?>
                            </label>
                            <select name="sale_supplier_id" id="sale_supplier_id"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500"
                                    required>
                                <option value="">-- Select Supplier --</option>
                                <?php foreach ($all_suppliers as $sup): ?>
                                <option value="<?php echo $sup->supplier_id; ?>"
                                        <?php echo ($selected_po && (int)$selected_po->supplier_id === (int)$sup->supplier_id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($sup->supplier_name); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Sale Date -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Sale Date <span class="text-red-500">*</span>
                            </label>
                            <input type="date" name="sale_date"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500"
                                   value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                        </div>

                        <!-- Item / Goods sold -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Item / Goods Sold <span class="text-red-500">*</span>
                            </label>
                            <input type="text" name="sale_item_description" id="sale_item_description"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500"
                                   placeholder="e.g. Flour, Bran, Wheat offal, Broken wheat..." required>
                        </div>

                        <!-- Quantity + Unit + Rate → Auto-total -->
                        <div class="grid grid-cols-3 gap-3 mb-1">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Quantity <span class="text-gray-400 text-xs">(optional)</span></label>
                                <input type="number" name="sale_quantity" id="sale_quantity"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500"
                                       step="0.01" min="0" placeholder="0.00"
                                       oninput="calcSaleTotal()">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Unit</label>
                                <select name="sale_unit" id="sale_unit"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500"
                                        onchange="calcSaleTotal()">
                                    <option value="KG">KG</option>
                                    <option value="MT">MT (Metric Ton)</option>
                                    <option value="bags">Bags</option>
                                    <option value="pcs">Pieces</option>
                                    <option value="units">Units</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Unit Price (৳) <span class="text-gray-400 text-xs">(optional)</span></label>
                                <input type="number" name="sale_unit_price" id="sale_unit_price"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500"
                                       step="0.01" min="0" placeholder="0.00"
                                       oninput="calcSaleTotal()">
                            </div>
                        </div>
                        <p class="text-xs text-gray-400 mb-4">Fill Qty + Unit Price to auto-calculate total, or enter total directly below.</p>

                        <!-- Total Sale Amount -->
                        <div class="mb-5">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Total Sale Amount (৳) <span class="text-red-500">*</span>
                            </label>
                            <input type="number" name="sale_total_amount" id="sale_total_amount"
                                   class="w-full px-3 py-2 border-2 border-teal-400 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500 font-semibold text-lg"
                                   step="0.01" min="0.01" placeholder="0.00" required
                                   oninput="updateSaleBanner()">
                        </div>

                        <!-- Settlement Type — large radio cards -->
                        <div class="mb-5">
                            <label class="block text-sm font-medium text-gray-700 mb-3">
                                Settlement Method <span class="text-red-500">*</span>
                            </label>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">

                                <label class="sale-settlement-card cursor-pointer border-2 rounded-lg p-4 flex items-start gap-3 transition-all border-teal-500 bg-teal-50">
                                    <input type="radio" name="sale_settlement" value="barter" checked
                                           class="mt-0.5" onchange="onSettlementChange()">
                                    <div>
                                        <p class="font-semibold text-teal-700 text-sm"><i class="fas fa-retweet mr-1"></i> Barter / Netting</p>
                                        <p class="text-xs text-gray-500 mt-0.5">No cash changes hands. Sale amount is netted directly against your payable to this supplier.</p>
                                    </div>
                                </label>

                                <label class="sale-settlement-card cursor-pointer border-2 rounded-lg p-4 flex items-start gap-3 transition-all border-gray-200 hover:border-blue-400">
                                    <input type="radio" name="sale_settlement" value="bank"
                                           class="mt-0.5" onchange="onSettlementChange()">
                                    <div>
                                        <p class="font-semibold text-blue-700 text-sm"><i class="fas fa-university mr-1"></i> Bank Receipt</p>
                                        <p class="text-xs text-gray-500 mt-0.5">Supplier paid via bank transfer / cheque. Select the receiving bank account.</p>
                                    </div>
                                </label>

                                <label class="sale-settlement-card cursor-pointer border-2 rounded-lg p-4 flex items-start gap-3 transition-all border-gray-200 hover:border-green-400">
                                    <input type="radio" name="sale_settlement" value="cash"
                                           class="mt-0.5" onchange="onSettlementChange()">
                                    <div>
                                        <p class="font-semibold text-green-700 text-sm"><i class="fas fa-coins mr-1"></i> Cash Receipt</p>
                                        <p class="text-xs text-gray-500 mt-0.5">Supplier paid in cash. Select the cash account where funds are deposited.</p>
                                    </div>
                                </label>

                            </div>
                        </div>

                        <!-- Bank account (shown for bank settlement) -->
                        <div id="saleBankDiv" class="mb-4 hidden">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Receiving Bank Account <span class="text-red-500">*</span>
                            </label>
                            <select name="sale_bank_account_id" id="sale_bank_account_id"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">-- Select Bank Account --</option>
                                <?php foreach ($bank_accounts as $bank): ?>
                                <option value="<?php echo $bank->id; ?>">
                                    <?php echo htmlspecialchars($bank->bank_name . ' - ' . $bank->account_name . ' (' . $bank->account_number . ')'); ?>
                                    <?php if ($can_see_balance): ?> — Bal: ৳<?php echo number_format($bank->current_balance, 2); ?><?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Cash account (shown for cash settlement) -->
                        <div id="saleCashDiv" class="mb-4 hidden">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Cash Account <span class="text-red-500">*</span>
                            </label>
                            <select id="sale_cash_account_select"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                                <option value="">-- Select Cash Account --</option>
                                <?php foreach ($cash_accounts as $cash): ?>
                                <option value="<?php echo $cash->id; ?>"
                                        data-name="<?php echo htmlspecialchars($cash->account_name . ($cash->branch_name ? ' - ' . $cash->branch_name : '')); ?>">
                                    <?php echo htmlspecialchars($cash->account_name); ?>
                                    <?php if ($cash->branch_name): ?> - <?php echo htmlspecialchars($cash->branch_name); ?><?php endif; ?>
                                    <?php if ($can_see_balance): ?> — Bal: ৳<?php echo number_format($cash->current_balance, 2); ?><?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="sale_cash_account_name" id="sale_cash_account_name">
                        </div>

                        <!-- Challan / Invoice number -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Challan / Invoice Number <span class="text-gray-400 text-xs">(optional)</span>
                            </label>
                            <input type="text" name="sale_challan_number"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500"
                                   placeholder="Delivery challan, invoice or cheque number...">
                        </div>

                        <!-- Remarks -->
                        <div class="mb-5">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Remarks</label>
                            <textarea name="sale_remarks" rows="2"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500"
                                      placeholder="Any additional notes..."></textarea>
                        </div>

                        <!-- Summary banner -->
                        <div id="saleBanner" class="mb-5 hidden">
                            <div class="bg-teal-50 border border-teal-300 rounded-lg p-4 text-center">
                                <p class="text-xs text-teal-600 mb-1">This entry will REDUCE your payable to the selected supplier by</p>
                                <p class="text-3xl font-bold text-teal-700">৳<span id="saleBannerAmt">0.00</span></p>
                                <p id="saleBannerNote" class="text-xs text-teal-500 mt-1"></p>
                            </div>
                        </div>

                        <!-- Buttons -->
                        <div class="flex justify-between">
                            <button type="button" onclick="switchTab('payment')"
                                    class="border border-gray-300 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-50">
                                <i class="fas fa-arrow-left mr-1"></i> Back to Payment
                            </button>
                            <button type="submit"
                                    class="bg-teal-600 text-white px-6 py-2 rounded-lg hover:bg-teal-700 flex items-center gap-2">
                                <i class="fas fa-save"></i> Record Sale Entry
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Sale Sidebar -->
        <div class="space-y-4">

            <!-- How It Works -->
            <div class="bg-white rounded-lg shadow">
                <div class="bg-teal-700 text-white px-6 py-4 rounded-t-lg">
                    <h5 class="font-semibold flex items-center gap-2"><i class="fas fa-info-circle"></i> How It Works</h5>
                </div>
                <div class="p-5 text-sm text-gray-700 space-y-4">
                    <div class="flex items-start gap-3">
                        <span class="bg-teal-100 text-teal-800 px-2 py-0.5 rounded text-xs font-bold mt-0.5 shrink-0">Barter</span>
                        <p>Sale amount is <strong>netted against your payable</strong>. If you owe supplier ৳100 and sell ৳30 of goods, new balance = ৳70. No cash changes hands.</p>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="bg-blue-100 text-blue-800 px-2 py-0.5 rounded text-xs font-bold mt-0.5 shrink-0">Bank</span>
                        <p>Supplier <strong>paid you by bank transfer</strong>. Reduces their outstanding payable to you AND notes the bank receipt for reconciliation.</p>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="bg-green-100 text-green-800 px-2 py-0.5 rounded text-xs font-bold mt-0.5 shrink-0">Cash</span>
                        <p>Supplier <strong>paid in cash</strong>. Records the cash receipt and adjusts payable balance in the supplier ledger.</p>
                    </div>
                    <div class="bg-amber-50 border border-amber-200 rounded p-3 text-xs text-amber-800">
                        <i class="fas fa-bolt text-amber-500 mr-1"></i>
                        <strong>Instantly posted</strong> to the supplier ledger. Visible in the supplier's ledger and summary immediately.
                    </div>
                </div>
            </div>

            <!-- Sale Stats -->
            <div class="bg-white rounded-lg shadow">
                <div class="bg-gray-600 text-white px-6 py-4 rounded-t-lg">
                    <h5 class="font-semibold flex items-center gap-2">
                        <i class="fas fa-users"></i> Supplier Count
                    </h5>
                </div>
                <div class="p-6">
                    <div class="mb-3">
                        <small class="text-gray-600">Active Suppliers:</small>
                        <h4 class="text-2xl font-bold"><?php echo count($all_suppliers); ?></h4>
                    </div>
                    <div>
                        <small class="text-gray-600">Outstanding POs:</small>
                        <h4 class="text-2xl font-bold text-red-600"><?php echo count($outstanding_pos); ?></h4>
                    </div>
                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <p class="text-xs text-gray-400">After recording a sale, you will be redirected to that supplier's ledger to verify the entry.</p>
                    </div>
                </div>
            </div>

        </div>
    </div>
    </div><!-- end tab-panel-sale -->

</div><!-- end container -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    const poSelect = document.getElementById('purchase_order_id');
    const amountInput = document.getElementById('amount_paid');
    const paymentMethodSelect = document.getElementById('payment_method');
    const bankAccountDiv = document.getElementById('bankAccountDiv');
    const cashAccountDiv = document.getElementById('cashAccountDiv');
    const bankAccountSelect = document.getElementById('bank_account_id');
    const cashAccountSelect = document.getElementById('cash_account_select');
    const cashAccountNameInput = document.getElementById('cash_account_name');
    const employeeDiv = document.getElementById('employeeDiv');
    const employeeSelect = document.getElementById('handled_by_employee');
    const poSummary = document.getElementById('poSummary');
    const advanceAlert = document.getElementById('advanceAlert');
    const payFullBalanceBtn = document.getElementById('payFullBalance');

    const creditAvailRow   = document.getElementById('creditAvailRow');
    const creditSection    = document.getElementById('creditSection');
    const poCreditBalance  = document.getElementById('poCreditBalance');
    const maxCreditDisplay = document.getElementById('maxCreditDisplay');
    const creditAmtInput   = document.getElementById('credit_amount');
    const btnApplyCredit   = document.getElementById('btnApplyCredit');
    const btnApplyMax      = document.getElementById('btnApplyMaxCredit');

    // Update PO summary
    poSelect.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        if (this.value) {
            document.getElementById('poSupplier').textContent      = option.dataset.supplier;
            document.getElementById('poReceivedValue').textContent = parseFloat(option.dataset.receivedValue || 0).toFixed(2);
            document.getElementById('poPaid').textContent          = parseFloat(option.dataset.paid         || 0).toFixed(2);
            document.getElementById('poBalance').textContent       = parseFloat(option.dataset.balance      || 0).toFixed(2);
            poSummary.classList.remove('hidden');

            // Supplier credit
            const credit = parseFloat(option.dataset.credit || 0);
            if (credit > 0.01) {
                poCreditBalance.textContent  = '৳' + credit.toFixed(2);
                maxCreditDisplay.textContent = credit.toFixed(2);
                creditAvailRow.classList.remove('hidden');
            } else {
                creditAvailRow.classList.add('hidden');
                creditSection.classList.add('hidden');
                if (creditAmtInput) creditAmtInput.value = '';
            }
        } else {
            poSummary.classList.add('hidden');
            creditAvailRow.classList.add('hidden');
            creditSection.classList.add('hidden');
        }
        checkAdvancePayment();
    });

    // Show credit application section
    if (btnApplyCredit) {
        btnApplyCredit.addEventListener('click', function() {
            creditSection.classList.toggle('hidden');
        });
    }

    // Apply maximum credit
    if (btnApplyMax) {
        btnApplyMax.addEventListener('click', function() {
            const option  = poSelect.options[poSelect.selectedIndex];
            const credit  = parseFloat(option.dataset.credit || 0);
            const payment = parseFloat(amountInput.value) || 0;
            creditAmtInput.value = Math.min(credit, payment > 0 ? payment : credit).toFixed(2);
            updateCreditSummary();
        });
    }

    // Update credit summary
    window.updateCreditSummary = function() {
        const creditAmt = parseFloat(creditAmtInput?.value || 0);
        const payAmt    = parseFloat(amountInput.value) || 0;
        const net       = Math.max(0, payAmt - creditAmt);
        if (creditAmt > 0) {
            document.getElementById('netCashRequired').textContent = net.toFixed(2);
            document.getElementById('csTotalPay').textContent      = payAmt.toFixed(2);
            document.getElementById('csCreditAmt').textContent     = creditAmt.toFixed(2);
            document.getElementById('creditSummary').classList.remove('hidden');
        } else {
            document.getElementById('creditSummary').classList.add('hidden');
        }
    };

    // Pay full balance
    payFullBalanceBtn.addEventListener('click', function() {
        const option = poSelect.options[poSelect.selectedIndex];
        if (poSelect.value) {
            amountInput.value = parseFloat(option.dataset.balance).toFixed(2);
            checkAdvancePayment();
        }
    });

    // Show/hide fields based on payment method
    paymentMethodSelect.addEventListener('change', function() {
        // Reset all
        bankAccountDiv.classList.add('hidden');
        cashAccountDiv.classList.add('hidden');
        employeeDiv.classList.add('hidden');
        bankAccountSelect.required = false;
        cashAccountSelect.required = false;
        employeeSelect.required = false;
        
        if (this.value === 'bank') {
            // Bank payment - show bank accounts
            bankAccountDiv.classList.remove('hidden');
            bankAccountSelect.required = true;
        } else if (this.value === 'cash') {
            // Cash payment - show cash accounts AND employee
            cashAccountDiv.classList.remove('hidden');
            employeeDiv.classList.remove('hidden');
            cashAccountSelect.required = true;
            employeeSelect.required = true;
        } else if (this.value === 'cheque') {
            // Cheque payment - show bank accounts
            bankAccountDiv.classList.remove('hidden');
            bankAccountSelect.required = true;
        }
    });

    // Update hidden fields when cash account is selected
    cashAccountSelect.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        if (this.value) {
            const accountName = option.dataset.name;
            const branchName = option.dataset.branch;
            const fullName = accountName + (branchName !== 'N/A' ? ' - ' + branchName : '');
            
            // Set both hidden fields
            document.getElementById('cash_account_id').value = this.value;  // ✅ Cash account ID
            cashAccountNameInput.value = fullName;  // ✅ Full cash account name
        } else {
            document.getElementById('cash_account_id').value = '';
            cashAccountNameInput.value = '';
        }
    });

    // Check for advance payment
    function checkAdvancePayment() {
        const option = poSelect.options[poSelect.selectedIndex];
        if (poSelect.value && amountInput.value) {
            const receivedValue = parseFloat(option.dataset.receivedValue) || 0;
            const paid = parseFloat(option.dataset.paid) || 0;
            const paymentAmount = parseFloat(amountInput.value) || 0;
            
            if ((paid + paymentAmount) > receivedValue) {
                advanceAlert.classList.remove('hidden');
            } else {
                advanceAlert.classList.add('hidden');
            }
        }
    }

    amountInput.addEventListener('input', checkAdvancePayment);

    // Trigger on page load if PO already selected
    if (poSelect.value) {
        poSelect.dispatchEvent(new Event('change'));
    }
});

// ── Tab switching ─────────────────────────────────────────
function switchTab(tab) {
    const panels = ['payment', 'sale'];
    panels.forEach(t => {
        document.getElementById('tab-panel-' + t).classList.toggle('hidden', t !== tab);
        const btn = document.getElementById('tab-btn-' + t);
        if (t === tab) {
            btn.classList.add('bg-white', 'shadow', t === 'payment' ? 'text-yellow-700' : 'text-teal-700');
            btn.classList.remove('text-gray-600');
        } else {
            btn.classList.remove('bg-white', 'shadow', 'text-yellow-700', 'text-teal-700');
            btn.classList.add('text-gray-600');
        }
    });
}

// ── Sale Form JS ──────────────────────────────────────────
function calcSaleTotal() {
    const qty   = parseFloat(document.getElementById('sale_quantity').value)   || 0;
    const rate  = parseFloat(document.getElementById('sale_unit_price').value) || 0;
    if (qty > 0 && rate > 0) {
        document.getElementById('sale_total_amount').value = (qty * rate).toFixed(2);
    }
    updateSaleBanner();
}

function updateSaleBanner() {
    const amt = parseFloat(document.getElementById('sale_total_amount').value) || 0;
    const banner = document.getElementById('saleBanner');
    if (amt > 0) {
        document.getElementById('saleBannerAmt').textContent = amt.toLocaleString('en-US', {minimumFractionDigits: 2});
        const settlement = document.querySelector('input[name="sale_settlement"]:checked')?.value || 'barter';
        const noteMap = {
            barter: 'Barter netting — no cash exchange. Payable reduced instantly.',
            bank:   'Bank receipt will be noted. Payable reduced + bank account credited.',
            cash:   'Cash receipt will be noted. Payable reduced + cash account credited.',
        };
        document.getElementById('saleBannerNote').textContent = noteMap[settlement] || '';
        banner.classList.remove('hidden');
    } else {
        banner.classList.add('hidden');
    }
}

function onSettlementChange() {
    const val = document.querySelector('input[name="sale_settlement"]:checked')?.value;

    // Style settlement cards
    document.querySelectorAll('.sale-settlement-card').forEach(card => {
        const radio = card.querySelector('input[type="radio"]');
        const isSelected = radio.value === val;
        card.classList.toggle('border-teal-500', isSelected && radio.value === 'barter');
        card.classList.toggle('bg-teal-50',      isSelected && radio.value === 'barter');
        card.classList.toggle('border-blue-500',  isSelected && radio.value === 'bank');
        card.classList.toggle('bg-blue-50',       isSelected && radio.value === 'bank');
        card.classList.toggle('border-green-500', isSelected && radio.value === 'cash');
        card.classList.toggle('bg-green-50',      isSelected && radio.value === 'cash');
        if (!isSelected) {
            card.classList.remove('border-teal-500','bg-teal-50','border-blue-500','bg-blue-50','border-green-500','bg-green-50');
            card.classList.add('border-gray-200');
        } else {
            card.classList.remove('border-gray-200');
        }
    });

    // Show/hide bank or cash account sections
    document.getElementById('saleBankDiv').classList.toggle('hidden', val !== 'bank');
    document.getElementById('saleCashDiv').classList.toggle('hidden', val !== 'cash');

    // Required attributes
    document.getElementById('sale_bank_account_id').required = (val === 'bank');
    document.getElementById('sale_cash_account_select').required = (val === 'cash');

    updateSaleBanner();
}

// Sync cash account hidden field
document.getElementById('sale_cash_account_select')?.addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    document.getElementById('sale_cash_account_name').value = opt?.dataset.name || '';
});

// Auto-open sale tab if PHP session error was from sale form
<?php if (!empty($_SESSION['error']) && ($_POST['form_action'] ?? '') === 'supplier_sale'): ?>
switchTab('sale');
<?php endif; ?>
</script>

<?php require_once '../templates/footer.php'; ?>