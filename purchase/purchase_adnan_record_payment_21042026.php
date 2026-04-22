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

// If PO ID provided, get PO details
$selected_po = null;
if ($po_id) {
    $selected_po = $po_manager->getPurchaseOrder($po_id);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-money-bill-wave text-yellow-600"></i> Record Payment
            </h2>
            <nav class="text-sm text-gray-600 mt-1">
                <a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>" class="hover:text-primary-600">Purchase (Adnan)</a>
                <span class="mx-2">›</span>
                <span>Record Payment</span>
            </nav>
        </div>
        <a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>" class="border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 flex items-center gap-2">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>

    <?php if (isset($_SESSION['error'])): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-6 flex items-center justify-between">
        <span><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
        <button onclick="this.parentElement.remove()" class="text-red-600 hover:text-red-800">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow">
                <div class="bg-yellow-500 text-white px-6 py-4 rounded-t-lg">
                    <h5 class="font-semibold">Payment Details</h5>
                </div>
                <div class="p-6">
                    <form method="POST" id="paymentForm">
                        <!-- Purchase Order Selection -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Purchase Order <span class="text-red-500">*</span>
                            </label>
                            <select name="purchase_order_id" id="purchase_order_id" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500" 
                                    required>
                                <option value="">-- Select Purchase Order --</option>
                                <?php foreach ($outstanding_pos as $po): ?>
                                <option value="<?php echo $po->id; ?>" 
                                        data-supplier="<?php echo htmlspecialchars($po->supplier_name); ?>"
                                        data-balance="<?php echo $po->balance_payable; ?>"
                                        data-received-value="<?php echo $po->total_received_value; ?>"
                                        data-paid="<?php echo $po->total_paid; ?>"
                                        <?php echo $selected_po && $selected_po->id == $po->id ? 'selected' : ''; ?>>
                                    PO #<?php echo $po->po_number; ?> - <?php echo htmlspecialchars($po->supplier_name); ?> 
                                    (Balance: ৳<?php echo number_format($po->balance_payable, 2); ?>)
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
</div>

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

    // Update PO summary
    poSelect.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        if (this.value) {
            document.getElementById('poSupplier').textContent = option.dataset.supplier;
            document.getElementById('poReceivedValue').textContent = parseFloat(option.dataset.receivedValue).toFixed(2);
            document.getElementById('poPaid').textContent = parseFloat(option.dataset.paid).toFixed(2);
            document.getElementById('poBalance').textContent = parseFloat(option.dataset.balance).toFixed(2);
            
            poSummary.classList.remove('hidden');
        } else {
            poSummary.classList.add('hidden');
        }
        checkAdvancePayment();
    });

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
</script>

<?php require_once '../templates/footer.php'; ?>