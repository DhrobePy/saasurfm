<?php
require_once '../core/init.php';

// --- SECURITY ---
$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra', 'collector'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$pageTitle = 'Record Customer Payment';
$error = null;
$success = null;

// --- DATA: Get Customers — true balance = initial_due + net ledger transactions ---
$customers = $db->query(
    "SELECT c.id, c.name, c.business_name, c.phone_number, c.credit_limit, c.initial_due,
            COALESCE(c.initial_due, 0)
                + COALESCE(tb.total_debit,  0)
                - COALESCE(tb.total_credit, 0) AS outstanding_balance,
            c.credit_limit - (
                COALESCE(c.initial_due, 0)
                + COALESCE(tb.total_debit,  0)
                - COALESCE(tb.total_credit, 0)
            ) AS available_credit
     FROM customers c
     LEFT JOIN (
         SELECT customer_id,
                SUM(debit_amount)  AS total_debit,
                SUM(credit_amount) AS total_credit
         FROM customer_ledger
         WHERE reference_type != 'initial_due'
         GROUP BY customer_id
     ) tb ON tb.customer_id = c.id
     WHERE c.status = 'active' AND c.customer_type = 'Credit'
     ORDER BY c.name ASC"
)->results();

// --- DATA: Get Bank Accounts ---
$bank_accounts = $db->query(
    "SELECT ba.id, ba.chart_of_account_id, ba.bank_name, ba.account_name, ba.account_number
     FROM bank_accounts ba
     JOIN chart_of_accounts coa ON ba.chart_of_account_id = coa.id
     WHERE ba.status = 'active' AND coa.account_type = 'Bank'
     ORDER BY ba.account_name"
)->results();

// --- DATA: Get Accounting Accounts (for Journal Entry) ---
$cash_account = $db->query(
    "SELECT id, name FROM chart_of_accounts
     WHERE account_type = 'Petty Cash' OR name = 'Undeposited Funds'
     ORDER BY CASE WHEN name = 'Undeposited Funds' THEN 0 ELSE 1 END
     LIMIT 1"
)->first();
$ar_account = $db->query("SELECT id FROM chart_of_accounts WHERE account_type = 'Accounts Receivable' LIMIT 1")->first();

$ar_account_id = null; // Initialize
if (!$ar_account || !$cash_account) {
    $error = "FATAL ERROR: Missing 'Accounts Receivable' or 'Undeposited Funds' account in Chart of Accounts. Cannot proceed.";
} else {
    $ar_account_id = $ar_account->id; // Set the ID for later use
}


// --- LOGIC: Handle Payment Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_payment' && !$error) {
    try {
        $pdo = $db->getPdo();
        $pdo->beginTransaction();
        
        $customer_id = (int)$_POST['customer_id'];
        $customer_name = sanitize($_POST['customer_name']);
        $payment_date = $_POST['payment_date'];
        $payment_method = $_POST['payment_method'];
        $bank_account_id = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
        $reference_number = trim($_POST['reference_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        $payment_type = $_POST['payment_type']; // 'advance' or 'invoice_payment'
        $payment_amount = floatval($_POST['paymentAmount']); // The single total amount
        $allocations = $_POST['allocations'] ?? []; // Array of [order_id => amount]
        
        if ($payment_amount <= 0) {
            throw new Exception("Payment amount must be greater than zero");
        }
        
        // --- 1. Determine Deposit Account ID ---
        $deposit_chart_of_account_id = null;
        if ($payment_method === 'Cash') {
            $deposit_chart_of_account_id = $cash_account->id;
        } else {
            if (empty($bank_account_id)) throw new Exception("Bank Account is required for this payment method.");
            $selected_bank = $db->query("SELECT chart_of_account_id FROM bank_accounts WHERE id = ?", [$bank_account_id])->first();
            if (!$selected_bank) throw new Exception("Invalid deposit bank account selected.");
            $deposit_chart_of_account_id = $selected_bank->chart_of_account_id;
        }

        // --- 2. Generate Payment Number (sequence-based, no rand()) ---
        $pay_date_prefix = date('Ymd', strtotime($payment_date));
        $last_pay        = $db->query(
            "SELECT payment_number FROM customer_payments WHERE payment_number LIKE ? ORDER BY id DESC LIMIT 1",
            ["PAY-{$pay_date_prefix}-%"]
        )->first();
        $pay_seq        = $last_pay ? ((int)substr($last_pay->payment_number, -4) + 1) : 1;
        $payment_number = sprintf("PAY-%s-%04d", $pay_date_prefix, $pay_seq);
        
        // --- 3. Insert ONE Payment Record ---
        $payment_id = $db->insert('customer_payments', [
            'payment_number' => $payment_number,
            'customer_id' => $customer_id,
            'payment_date' => $payment_date,
            'amount' => $payment_amount,
            'payment_method' => $payment_method,
            'payment_type' => $payment_type,
            'bank_account_id' => $bank_account_id,
            'reference_number' => $reference_number,
            'notes' => $notes,
            'allocated_to_invoices' => ($payment_type === 'invoice_payment') ? json_encode($allocations) : null,
            'created_by_user_id' => $user_id
        ]);
        if (!$payment_id) throw new Exception("Failed to record payment");
        
        // --- 4. Compute running balance from aggregate (immune to balance_after drift) ---
        // OB ledger entry already encodes initial_due as a debit, so SUM(debit)-SUM(credit)
        // is correct when entries exist. Fallback to initial_due when no entries exist yet.
        $cust_init_pay = $db->query("SELECT initial_due FROM customers WHERE id = ?", [$customer_id])->first();
        $agg_pay = $db->query(
            "SELECT COALESCE(SUM(debit_amount), 0)  AS td,
                    COALESCE(SUM(credit_amount), 0) AS tc
             FROM customer_ledger WHERE customer_id = ?",
            [$customer_id]
        )->first();
        $agg_pay_td = (float)($agg_pay->td ?? 0);
        $agg_pay_tc = (float)($agg_pay->tc ?? 0);
        $prev_balance = ($agg_pay_td > 0 || $agg_pay_tc > 0)
            ? $agg_pay_td - $agg_pay_tc
            : (float)($cust_init_pay->initial_due ?? 0);
        $new_balance  = $prev_balance - $payment_amount;
        
        // --- 5. Create ONE Ledger Entry ---
        $ledger_transaction_type = ($payment_type === 'advance') ? 'advance_payment' : 'payment';
        $description = ($payment_type === 'advance') 
            ? "Advance payment received - Receipt #$payment_number"
            : "Payment received (Receipt #$payment_number) allocated to invoices.";
        
        $ledger_id = $db->insert('customer_ledger', [
            'customer_id' => $customer_id,
            'transaction_date' => $payment_date,
            'transaction_type' => $ledger_transaction_type,
            'reference_type' => 'customer_payments',
            'reference_id' => $payment_id,
            'invoice_number' => $payment_number,
            'description' => $description,
            'debit_amount' => 0,
            'credit_amount' => $payment_amount,
            'balance_after' => $new_balance,
            'created_by_user_id' => $user_id
        ]);
        if (!$ledger_id) throw new Exception("Failed to create customer ledger entry.");
        
        // --- 6. Update Customer Balance ---
        $db->update('customers', $customer_id, ['current_balance' => $new_balance]);
        
        // --- 7. Create Journal Entry (Double-Entry) ---
        $customer_name = sanitize($db->query("SELECT name FROM customers WHERE id = ?", [$customer_id])->first()?->name ?? 'Customer');
        $journal_desc = "Customer payment $payment_number from $customer_name";
        $journal_id = $db->insert('journal_entries', [
            'transaction_date' => $payment_date,
            'description' => $journal_desc,
            'related_document_type' => 'customer_payments',
            'related_document_id' => $payment_id,
            'created_by_user_id' => $user_id
        ]);
        if (!$journal_id) throw new Exception("Failed to create journal entry header.");

        // Debit: Bank/Cash Account
        $db->insert('transaction_lines', ['journal_entry_id' => $journal_id, 'account_id' => $deposit_chart_of_account_id, 'debit_amount' => $payment_amount, 'credit_amount' => 0]);
        // Credit: Accounts Receivable
        $db->insert('transaction_lines', ['journal_entry_id' => $journal_id, 'account_id' => $ar_account_id, 'debit_amount' => 0, 'credit_amount' => $payment_amount]);
        
        // --- 8. Link Journal Entry Back ---
        $db->update('customer_payments', $payment_id, ['journal_entry_id' => $journal_id]);
        $db->update('customer_ledger', $ledger_id, ['journal_entry_id' => $journal_id]);

        // --- 9. Process Allocations (if any) ---
        if ($payment_type === 'invoice_payment' && !empty($allocations)) {
            foreach ($allocations as $order_id => $amount) {
                $alloc_amount = floatval($amount);
                if ($alloc_amount > 0) {
                    $db->insert('payment_allocations', [
                        'payment_id' => $payment_id,
                        'order_id' => (int)$order_id,
                        'allocated_amount' => $alloc_amount,
                        'allocation_date' => $payment_date,
                        'allocated_by_user_id' => $user_id
                    ]);
                    
                    // Update paid amount and recompute balance_due from total_amount − amount_paid.
                    // MySQL evaluates SET left-to-right, so balance_due sees the already-incremented
                    // amount_paid — self-healing any prior drift in the balance_due column.
                    $db->query(
                        "UPDATE credit_orders
                         SET amount_paid = amount_paid + ?,
                             balance_due = total_amount - amount_paid
                         WHERE id = ?",
                        [$alloc_amount, (int)$order_id]
                    );
                }
            }
        }
        
        // --- 10. Commit ---
        $pdo->commit();

        // --- 11. Bridge → bank_transactions (bank statement visibility) ---
        // Matches bank_accounts.account_number → bank_tx_accounts.account_number.
        // Fails silently so payment is never rolled back due to bank module issues.
        if ($payment_method !== 'Cash' && $bank_account_id) {
            try {
                require_once dirname(__DIR__) . '/bank/BankManager.php';
                $bm_bridge = $db->query(
                    "SELECT bta.id AS bta_id
                     FROM bank_tx_accounts bta
                     INNER JOIN bank_accounts ba ON ba.account_number = bta.account_number
                     WHERE ba.id = ? AND bta.status = 'active'
                     LIMIT 1",
                    [$bank_account_id]
                )->first();
                if ($bm_bridge) {
                    $bankMgr = new BankManager();
                    $cust_label = $db->query("SELECT name FROM customers WHERE id = ?", [$customer_id])->first();
                    $bankMgr->createTransaction([
                        'transaction_date'   => $payment_date,
                        'entry_type'         => 'credit', // money IN: customer paying us
                        'bank_tx_account_id' => (int)$bm_bridge->bta_id,
                        'amount'             => $payment_amount,
                        'reference_number'   => $payment_number,
                        'payee_payer_name'   => $cust_label ? $cust_label->name : null,
                        'description'        => "Credit sale payment — " . ($cust_label ? $cust_label->name : "Customer #{$customer_id}") . " — Receipt #{$payment_number}",
                    ], $user_id, $currentUser['display_name'] ?? ($currentUser['username'] ?? 'System'));
                }
            } catch (\Throwable $bm_ex) {
                error_log("BankManager bridge (customer_payment): " . $bm_ex->getMessage());
            }
        }

        auditLogPayment('created', $payment_id, $payment_number,
            "Payment ৳" . number_format($payment_amount, 2) . " received from customer #{$customer_id} via {$payment_method} ({$payment_type})",
            ['amount' => $payment_amount, 'method' => $payment_method, 'type' => $payment_type, 'new_balance' => $new_balance]
        );

        // ============================================
        // TELEGRAM NOTIFICATION - PAYMENT RECEIVED
        // ============================================
        try {
            if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
                require_once '../core/classes/TelegramNotifier.php';
                $telegram = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);

                // Collector display name
                $collector_name = 'System User';
                if ($user_id) {
                    $user_info = $db->query("SELECT display_name FROM users WHERE id = ?", [$user_id])->first();
                    $collector_name = $user_info ? $user_info->display_name : 'System User';
                }

                // Customer phone
                $cust_for_tg = $db->query("SELECT name, phone_number FROM customers WHERE id = ?", [$customer_id])->first();
                $tg_customer_name  = $cust_for_tg ? $cust_for_tg->name  : $customer_name;
                $tg_customer_phone = $cust_for_tg ? ($cust_for_tg->phone_number ?: 'N/A') : 'N/A';

                // Branch name from current user
                $branch_name = 'Head Office';
                $user_branch_id = $currentUser['branch_id'] ?? null;
                if ($user_branch_id) {
                    $branch_info = $db->query("SELECT name FROM branches WHERE id = ?", [$user_branch_id])->first();
                    $branch_name = $branch_info ? $branch_info->name : 'Head Office';
                }

                // Build allocated invoices list
                $tg_allocated = [];
                if ($payment_type === 'invoice_payment' && !empty($allocations)) {
                    foreach ($allocations as $order_id => $alloc_amount) {
                        $alloc_amount = floatval($alloc_amount);
                        if ($alloc_amount > 0) {
                            $order_info = $db->query("SELECT order_number FROM credit_orders WHERE id = ?", [(int)$order_id])->first();
                            if ($order_info) {
                                $tg_allocated[] = [
                                    'order_number' => $order_info->order_number,
                                    'amount'       => $alloc_amount,
                                ];
                            }
                        }
                    }
                }

                $paymentData = [
                    'receipt_no'        => $payment_number,
                    'payment_date'      => date('d M Y, h:i A', strtotime($payment_date)),
                    'amount'            => floatval($payment_amount),
                    'payment_method'    => $payment_method,
                    'reference_number'  => $reference_number ?: '',
                    'customer_name'     => $tg_customer_name,
                    'customer_phone'    => $tg_customer_phone,
                    'new_balance'       => floatval($new_balance),
                    'payment_type'      => empty($tg_allocated) ? 'Advance Payment' : 'Invoice Payment',
                    'allocated_invoices'=> $tg_allocated,
                    'notes'             => $notes ?: '',
                    'branch_name'       => $branch_name,
                    'collected_by'      => $collector_name,
                ];

                $result = $telegram->sendPaymentNotification($paymentData);
                if ($result['success']) {
                    error_log("✓ Telegram payment notification sent for receipt: $payment_number");
                } else {
                    error_log("✗ Telegram notification failed: " . json_encode($result['response']));
                }
            }
        } catch (Exception $tg_e) {
            error_log("✗ Telegram payment notification error: " . $tg_e->getMessage());
        }
        // END TELEGRAM NOTIFICATION

        $_SESSION['success_flash'] = "Payment of ৳" . number_format($payment_amount, 2) . " recorded successfully. Receipt #$payment_number";
        header('Location: customer_ledger.php?customer_id=' . $customer_id); // Redirect to ledger
        exit();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Failed to record payment: " . $e->getMessage();
    }
}

// --- Pre-load from ?order_id= param ---
$preload_order    = null;
$preload_customer = null;
$preload_order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if ($preload_order_id) {
    $preload_order = $db->query(
        "SELECT co.*, c.id AS c_id, c.name AS c_name, c.phone_number,
                c.credit_limit, c.initial_due,
                COALESCE(c.initial_due, 0)
                    + COALESCE(tb.total_debit,  0)
                    - COALESCE(tb.total_credit, 0) AS outstanding_balance,
                c.credit_limit - (
                    COALESCE(c.initial_due, 0)
                    + COALESCE(tb.total_debit,  0)
                    - COALESCE(tb.total_credit, 0)
                ) AS available_credit
         FROM credit_orders co
         JOIN customers c ON co.customer_id = c.id
         LEFT JOIN (
             SELECT customer_id,
                    SUM(debit_amount)  AS total_debit,
                    SUM(credit_amount) AS total_credit
             FROM customer_ledger
             WHERE reference_type != 'initial_due'
             GROUP BY customer_id
         ) tb ON tb.customer_id = c.id
         WHERE co.id = ?",
        [$preload_order_id]
    )->first();
    if ($preload_order) {
        $preload_customer = (object)[
            'id'            => $preload_order->c_id,
            'name'          => $preload_order->c_name,
            'credit_limit'  => $preload_order->credit_limit,
            'outstanding_balance' => $preload_order->outstanding_balance,
            'available_credit'    => $preload_order->available_credit,
        ];
    }
}

// Get recent payments (with bank account label and reference)
$recent_payments = $db->query(
    "SELECT cp.*, c.name as customer_name,
            CONCAT(ba.bank_name, ' – ', ba.account_name) as bank_account_label
     FROM customer_payments cp
     JOIN customers c ON cp.customer_id = c.id
     LEFT JOIN bank_accounts ba ON cp.bank_account_id = ba.id
     ORDER BY cp.created_at DESC
     LIMIT 25"
)->results();

require_once '../templates/header.php';
?>


<div class="max-w-screen-xl mx-auto px-4 sm:px-6 py-4">

<!-- Page Header -->
<div class="mb-4 flex flex-wrap items-center gap-2">
    <div class="flex-1 min-w-0">
        <h1 class="text-base font-bold text-gray-800">Record Customer Payment</h1>
        <p class="text-[11px] text-gray-500 mt-0.5">Receive and allocate customer payments to outstanding invoices</p>
    </div>
    <a href="customer_ledger.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 shadow-sm">
        <i class="fas fa-book text-blue-500"></i> Ledger
    </a>
    <a href="customer_credit_management.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 shadow-sm">
        <i class="fas fa-credit-card text-purple-500"></i> Credit Mgmt
    </a>
    <a href="index.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 shadow-sm">
        <i class="fas fa-tachometer-alt text-teal-500"></i> Dashboard
    </a>
</div>

<?php if ($error): ?>
<div class="bg-red-50 border-l-4 border-red-500 text-red-700 px-4 py-3 mb-4 rounded-r-lg text-sm">
    <p class="font-semibold">Error</p>
    <p class="text-xs mt-0.5"><?php echo htmlspecialchars($error); ?></p>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['success_flash'])): ?>
<div class="bg-green-50 border-l-4 border-green-500 text-green-700 px-4 py-3 mb-4 rounded-r-lg text-sm">
    <p class="font-semibold">Success</p>
    <p class="text-xs mt-0.5"><?php echo htmlspecialchars($_SESSION['success_flash']); ?></p>
</div>
<?php unset($_SESSION['success_flash']); ?>
<?php endif; ?>

<?php if ($preload_order): ?>
<div class="mb-4 px-4 py-2.5 bg-blue-50 border border-blue-200 rounded-lg text-blue-800 text-xs flex flex-wrap items-center gap-2">
    <i class="fas fa-link text-blue-500"></i>
    Pre-loading from order <strong class="ml-1"><?php echo htmlspecialchars($preload_order->order_number); ?></strong>
    &nbsp;—&nbsp; Customer: <strong><?php echo htmlspecialchars($preload_order->c_name); ?></strong>
    &nbsp;|&nbsp; Balance Due: <strong class="text-red-600">৳<?php echo number_format($preload_order->balance_due, 2); ?></strong>
    <a href="customer_payment.php" class="ml-auto text-blue-500 hover:text-blue-700"><i class="fas fa-times mr-1"></i>Clear</a>
</div>
<?php endif; ?>

<!-- Main Form Grid -->
<div x-data="paymentForm()" @customer-selected.window="selectCustomer($event.detail)" class="grid grid-cols-1 lg:grid-cols-3 gap-4">

    <div class="lg:col-span-2 space-y-4">
        <form method="POST" id="payment_main_form" x-ref="payment_form" @submit.prevent="validateAndSubmit">
        <input type="hidden" name="action" value="record_payment">
        <input type="hidden" name="customer_name" x-model="customer.name">
        <input type="hidden" name="payment_type" x-model="paymentType">

        <!-- Card 1: Select Customer -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
            <h2 class="flex items-center gap-2 text-[10px] font-semibold uppercase tracking-wider text-gray-500 mb-3">
                <span class="inline-flex items-center justify-center w-5 h-5 bg-primary-600 text-white rounded-full text-[10px] font-bold">1</span>
                Select Customer
            </h2>
            <input type="hidden" name="customer_id" id="customer_id_hidden" x-model="customer.id">
            <div class="relative" x-ignore>
                <input type="text" id="customer_search_box" autocomplete="off"
                       placeholder="Type customer name or phone to search…"
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-400"
                       oninput="cpSearchCustomers(this.value)"
                       onfocus="cpSearchCustomers(this.value)">
                <div id="cp_customer_dropdown"
                     class="absolute z-50 left-0 right-0 mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto hidden">
                </div>
            </div>
            <p id="cp_selected_label" class="mt-1 text-[11px] text-green-700 font-medium hidden"></p>

            <div x-show="customer.id" x-transition class="mt-3 bg-primary-50 border border-primary-100 rounded-lg p-3">
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <p class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold">Outstanding</p>
                        <p class="text-sm font-bold text-red-600 mt-0.5" x-text="'৳' + customer.balance"></p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold">Credit Limit</p>
                        <p class="text-sm font-bold text-gray-800 mt-0.5" x-text="'৳' + customer.credit_limit"></p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold">Available</p>
                        <p class="text-sm font-bold text-green-600 mt-0.5" x-text="'৳' + customer.available"></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Card 2: Payment Details -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200" :class="{ 'opacity-50 pointer-events-none': !customer.id }">
            <fieldset :disabled="!customer.id">
                <div class="p-4 border-b border-gray-100">
                    <h2 class="flex items-center gap-2 text-[10px] font-semibold uppercase tracking-wider text-gray-500 mb-3">
                        <span class="inline-flex items-center justify-center w-5 h-5 bg-primary-600 text-white rounded-full text-[10px] font-bold">2</span>
                        Payment Details
                    </h2>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[10px] font-semibold uppercase tracking-wider text-gray-500 mb-1">Payment Date *</label>
                            <input type="date" name="payment_date" required
                                   class="w-full px-2.5 py-1.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500"
                                   value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div>
                            <label class="block text-[10px] font-semibold uppercase tracking-wider text-gray-500 mb-1">Payment Method *</label>
                            <select name="payment_method" required
                                    class="w-full px-2.5 py-1.5 text-sm border border-gray-300 rounded-lg bg-white focus:ring-2 focus:ring-primary-500"
                                    onchange="toggleBankAccount(this)">
                                <option value="Cash">Cash</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Mobile Banking">Mobile Banking</option>
                                <option value="Card">Card Payment</option>
                            </select>
                        </div>
                    </div>

                    <div id="bankAccountDiv" style="display:none;" class="mt-3">
                        <label class="block text-[10px] font-semibold uppercase tracking-wider text-gray-500 mb-1">Deposit To Bank Account *</label>
                        <input type="hidden" name="bank_account_id" id="bank_account_id_hidden">
                        <div class="relative">
                            <input type="text" id="bank_search_box" autocomplete="off"
                                   placeholder="Type bank name or account number…"
                                   class="w-full px-2.5 py-1.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500"
                                   oninput="bankSearchAccounts(this.value)"
                                   onfocus="bankSearchAccounts(this.value)">
                            <div id="bank_dropdown"
                                 class="absolute z-50 left-0 right-0 mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-48 overflow-y-auto hidden">
                            </div>
                        </div>
                        <p id="bank_selected_label" class="mt-1 text-[11px] text-green-700 font-medium hidden"></p>
                    </div>
                    <div id="cashAccountDiv" style="display:block;" class="mt-3">
                        <label class="block text-[10px] font-semibold uppercase tracking-wider text-gray-500 mb-1">Deposit To Account</label>
                        <p class="w-full px-2.5 py-1.5 text-sm border border-gray-100 bg-gray-50 rounded-lg text-gray-600"><?php echo htmlspecialchars($cash_account->name ?? 'N/A'); ?></p>
                    </div>

                    <div class="grid grid-cols-2 gap-3 mt-3">
                        <div>
                            <label class="block text-[10px] font-semibold uppercase tracking-wider text-gray-500 mb-1">Reference Number</label>
                            <input type="text" name="reference_number"
                                   class="w-full px-2.5 py-1.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500"
                                   placeholder="Cheque no, TXN ID, etc.">
                        </div>
                        <div>
                            <label class="block text-[10px] font-semibold uppercase tracking-wider text-gray-500 mb-1">Notes</label>
                            <input type="text" name="notes"
                                   class="w-full px-2.5 py-1.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500"
                                   placeholder="Optional notes…">
                        </div>
                    </div>
                </div>

                <!-- Allocate Payment -->
                <div class="p-4">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="flex items-center gap-2 text-[10px] font-semibold uppercase tracking-wider text-gray-500">
                            <span class="inline-flex items-center justify-center w-5 h-5 bg-primary-600 text-white rounded-full text-[10px] font-bold">3</span>
                            Allocate Payment
                        </h2>
                        <div x-show="totalAllocated > 0" x-transition class="text-xs">
                            <span class="text-gray-500">Unallocated: </span>
                            <span class="font-bold"
                                 :class="{ 'text-red-600': (paymentAmount - totalAllocated) < -0.01, 'text-gray-700': (paymentAmount - totalAllocated) > 0.01 }"
                                 x-text="'৳' + (paymentAmount - totalAllocated).toFixed(2)"></span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="block text-[10px] font-semibold uppercase tracking-wider text-gray-500 mb-1">Total Payment Amount Received *</label>
                        <input type="number" name="paymentAmount" step="0.01" required
                               class="w-full px-2.5 py-2 text-base font-bold border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500"
                               placeholder="0.00" x-model.number="paymentAmount" @input="calculateTotal">
                        <p class="text-[11px] text-gray-400 mt-1">Enter the total amount received from the customer.</p>
                    </div>

                    <div x-show="isLoadingInvoices" class="text-center p-4">
                        <i class="fas fa-spinner fa-spin text-xl text-primary-600"></i>
                        <p class="text-xs text-gray-500 mt-1">Loading outstanding invoices…</p>
                    </div>

                    <div x-show="!isLoadingInvoices && outstandingOrders.length === 0 && customer.id" class="text-center p-3 bg-gray-50 rounded-lg text-xs text-gray-500">
                        No outstanding invoices found. This payment will be recorded as an <strong>Advance Payment</strong>.
                    </div>

                    <div x-show="outstandingOrders.length > 0" x-transition class="overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase tracking-wider text-gray-500">Order #</th>
                                    <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase tracking-wider text-gray-500">Date</th>
                                    <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase tracking-wider text-gray-500">Balance Due</th>
                                    <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase tracking-wider text-gray-500 w-32">Amount to Pay</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="order in outstandingOrders" :key="order.id">
                                    <tr class="border-b border-gray-100">
                                        <td class="px-3 py-2 font-medium text-primary-700" x-text="order.order_number"></td>
                                        <td class="px-3 py-2 text-gray-500" x-text="new Date(order.order_date).toLocaleDateString('en-GB')"></td>
                                        <td class="px-3 py-2 text-right text-red-600 font-medium" x-text="'৳' + parseFloat(order.balance_due).toFixed(2)"></td>
                                        <td class="px-3 py-2 text-right">
                                            <input type="number"
                                                   :name="'allocations[' + order.id + ']'"
                                                   class="w-full px-2 py-1 text-xs border border-gray-300 rounded text-right focus:ring-1 focus:ring-primary-500"
                                                   placeholder="0.00" step="0.01"
                                                   :max="order.balance_due"
                                                   x-model.number="order.amountToPay"
                                                   @input="calculateTotal">
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="px-4 py-3 bg-gray-50 border-t border-gray-100 flex justify-end rounded-b-lg">
                    <button type="submit"
                            class="inline-flex items-center gap-2 px-6 py-2.5 bg-primary-600 text-white text-sm font-bold rounded-lg hover:bg-primary-700 shadow-sm transition-colors"
                            :class="{ 'opacity-50 cursor-not-allowed': paymentAmount <= 0 || (totalAllocated > paymentAmount + 0.01) }"
                            :disabled="paymentAmount <= 0 || (totalAllocated > paymentAmount + 0.01)">
                        <i class="fas fa-check-circle"></i>
                        Record Payment &nbsp;(৳<span x-text="paymentAmount.toFixed(2)"></span>)
                    </button>
                </div>
            </fieldset>
        </form>
    </div>

    <!-- Right Sidebar -->
    <div class="space-y-3">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
            <h3 class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 mb-2">Quick Links</h3>
            <div class="space-y-1">
                <a href="customer_ledger.php" class="flex items-center gap-2 px-3 py-2 text-xs text-gray-700 hover:bg-gray-50 rounded-lg">
                    <i class="fas fa-book w-4 text-blue-500 text-center"></i> Customer Ledger
                </a>
                <a href="customer_credit_management.php" class="flex items-center gap-2 px-3 py-2 text-xs text-gray-700 hover:bg-gray-50 rounded-lg">
                    <i class="fas fa-credit-card w-4 text-purple-500 text-center"></i> Credit Management
                </a>
                <a href="index.php" class="flex items-center gap-2 px-3 py-2 text-xs text-gray-700 hover:bg-gray-50 rounded-lg">
                    <i class="fas fa-tachometer-alt w-4 text-teal-500 text-center"></i> CR Dashboard
                </a>
            </div>
        </div>

        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-amber-700 mb-2">Payment Types</p>
            <p class="text-xs text-amber-800 mb-1"><strong>Invoice Payment:</strong> Allocates funds to specific outstanding invoices.</p>
            <p class="text-xs text-amber-800"><strong>Advance Payment:</strong> Held on account when no invoices are selected.</p>
        </div>
    </div>
</div>

<!-- Recent Payments -->
<div class="mt-4 bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
    <div class="px-4 py-3 border-b border-gray-100 flex items-center gap-2">
        <i class="fas fa-history text-gray-400 text-xs"></i>
        <h2 class="text-sm font-semibold text-gray-700">Recent Payments</h2>
        <span class="text-[11px] text-gray-400 font-normal">— click any row to view &amp; print receipt</span>
    </div>
    <div class="overflow-x-auto">
        <?php if (!empty($recent_payments)): ?>
        <table class="min-w-full text-xs">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr>
                    <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase tracking-wider text-gray-500">Receipt #</th>
                    <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase tracking-wider text-gray-500">Date</th>
                    <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase tracking-wider text-gray-500">Customer</th>
                    <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase tracking-wider text-gray-500">Amount</th>
                    <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase tracking-wider text-gray-500">Method</th>
                    <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase tracking-wider text-gray-500">Account / Ref</th>
                    <th class="px-3 py-2 text-center text-[10px] font-semibold uppercase tracking-wider text-gray-500">Type</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($recent_payments as $pmt): ?>
                <tr class="hover:bg-blue-50 cursor-pointer transition-colors group"
                    onclick="window.open('credit_payment_receipt.php?id=<?php echo (int)$pmt->id; ?>', '_blank')"
                    title="View receipt #<?php echo htmlspecialchars($pmt->payment_number); ?>">
                    <td class="px-3 py-2 font-mono text-blue-600 font-medium group-hover:text-blue-700"><?php echo htmlspecialchars($pmt->payment_number); ?></td>
                    <td class="px-3 py-2 text-gray-500 whitespace-nowrap"><?php echo date('d M Y', strtotime($pmt->payment_date)); ?></td>
                    <td class="px-3 py-2 font-medium text-gray-800"><?php echo htmlspecialchars($pmt->customer_name); ?></td>
                    <td class="px-3 py-2 text-right font-bold text-emerald-600 whitespace-nowrap">৳<?php echo number_format($pmt->amount, 2); ?></td>
                    <td class="px-3 py-2 text-gray-600 whitespace-nowrap"><?php echo htmlspecialchars($pmt->payment_method); ?></td>
                    <td class="px-3 py-2 text-gray-500 max-w-[200px] truncate">
                        <?php if (!empty($pmt->bank_account_label)): ?>
                            <span class="text-gray-600"><?php echo htmlspecialchars($pmt->bank_account_label); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($pmt->reference_number)): ?>
                            <span class="<?php echo !empty($pmt->bank_account_label) ? 'ml-1 text-gray-400' : 'text-gray-600 font-mono'; ?>">#<?php echo htmlspecialchars($pmt->reference_number); ?></span>
                        <?php endif; ?>
                        <?php if (empty($pmt->bank_account_label) && empty($pmt->reference_number)): ?>—<?php endif; ?>
                    </td>
                    <td class="px-3 py-2 text-center whitespace-nowrap">
                        <span class="px-1.5 py-0.5 text-[10px] font-semibold rounded-full <?php echo $pmt->payment_type === 'advance' ? 'bg-blue-100 text-blue-700' : 'bg-emerald-100 text-emerald-700'; ?>">
                            <?php echo $pmt->payment_type === 'advance' ? 'Advance' : 'Invoice'; ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="p-8 text-center text-gray-500 text-xs">No payment records found</div>
        <?php endif; ?>
    </div>
</div>

</div>

<script>
// ── Customer live-search ────────────────────────────────────
const cpCustomerData = <?php echo json_encode(array_map(function($c) {
    return [
        'id'          => $c->id,
        'name'        => $c->name,
        'business'    => $c->business_name ?? '',
        'phone'       => $c->phone_number ?? '',
        'balance'     => (float)($c->outstanding_balance ?? 0),
        'creditLimit' => (float)($c->credit_limit ?? 0),
        'available'   => (float)($c->available_credit ?? 0),
    ];
}, $customers)); ?>;

function cpSearchCustomers(query) {
    const dd = document.getElementById('cp_customer_dropdown');
    const q = query.toLowerCase().trim();
    const matches = q.length === 0
        ? cpCustomerData.slice(0, 20)
        : cpCustomerData.filter(c =>
            c.name.toLowerCase().includes(q) || c.phone.includes(q)
          ).slice(0, 20);

    if (matches.length === 0) {
        dd.innerHTML = '<div class="px-4 py-3 text-sm text-gray-500">No customers found</div>';
    } else {
        dd.innerHTML = matches.map(c => {
            const dueStr = c.balance > 0 ? ` — Due: ৳${c.balance.toFixed(0)}` : '';
            return `<div class="px-4 py-2 hover:bg-blue-50 cursor-pointer text-sm border-b border-gray-100"
                         onclick="cpSelectCustomer(${c.id})">
                <span class="font-medium text-gray-900">${c.name}</span>
                ${c.business ? `<span class="text-gray-400 text-xs ml-1">(${c.business})</span>` : ''}
                <span class="text-gray-400 text-xs ml-2">${c.phone}</span>
                <span class="text-red-600 text-xs font-medium">${dueStr}</span>
            </div>`;
        }).join('');
    }
    dd.classList.remove('hidden');
}

function cpSelectCustomer(id) {
    const c = cpCustomerData.find(x => x.id === id);
    if (!c) return;
    document.getElementById('customer_id_hidden').value = c.id;
    document.getElementById('customer_search_box').value = c.name;
    document.getElementById('cp_customer_dropdown').classList.add('hidden');

    const label = document.getElementById('cp_selected_label');
    label.textContent = `✓ ${c.name} — Phone: ${c.phone}`;
    label.classList.remove('hidden');

    // Trigger Alpine event so the panel updates
    window.dispatchEvent(new CustomEvent('customer-selected', {
        detail: {
            id: c.id, name: c.name,
            balance: c.balance, creditLimit: c.creditLimit, available: c.available
        }
    }));
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('#customer_search_box') && !e.target.closest('#cp_customer_dropdown')) {
        const dd = document.getElementById('cp_customer_dropdown');
        if (dd) dd.classList.add('hidden');
    }
    if (!e.target.closest('#bank_search_box') && !e.target.closest('#bank_dropdown')) {
        const dd = document.getElementById('bank_dropdown');
        if (dd) dd.classList.add('hidden');
    }
});

// ── Bank account live-search ────────────────────────────────
const bankAccountData = <?php echo json_encode(array_map(function($a) {
    return [
        'id'     => $a->id,
        'label'  => $a->bank_name . ' – ' . $a->account_name . ' (' . $a->account_number . ')',
    ];
}, $bank_accounts)); ?>;

function bankSearchAccounts(query) {
    const dd = document.getElementById('bank_dropdown');
    const q = query.toLowerCase().trim();
    const matches = q.length === 0
        ? bankAccountData.slice(0, 15)
        : bankAccountData.filter(b => b.label.toLowerCase().includes(q)).slice(0, 15);

    if (matches.length === 0) {
        dd.innerHTML = '<div class="px-4 py-3 text-sm text-gray-500">No bank accounts found</div>';
    } else {
        dd.innerHTML = matches.map(b =>
            `<div class="px-4 py-2 hover:bg-blue-50 cursor-pointer text-sm border-b border-gray-100"
                  onclick="bankSelectAccount(${b.id}, '${b.label.replace(/'/g,"\\'")}')">
                ${b.label}
            </div>`
        ).join('');
    }
    dd.classList.remove('hidden');
}

function bankSelectAccount(id, label) {
    document.getElementById('bank_account_id_hidden').value = id;
    document.getElementById('bank_search_box').value = label;
    document.getElementById('bank_dropdown').classList.add('hidden');
    document.getElementById('bank_selected_label').textContent = '✓ ' + label;
    document.getElementById('bank_selected_label').classList.remove('hidden');
}

function toggleBankAccount(select) {
    const bankDiv = document.getElementById('bankAccountDiv');
    const cashDiv = document.getElementById('cashAccountDiv');
    const bankHidden = document.getElementById('bank_account_id_hidden');

    if (select.value && select.value !== 'Cash') {
        bankDiv.style.display = 'block';
        cashDiv.style.display = 'none';
    } else {
        bankDiv.style.display = 'none';
        cashDiv.style.display = 'block';
        bankHidden.value = '';
        document.getElementById('bank_search_box').value = '';
        document.getElementById('bank_selected_label').classList.add('hidden');
    }
}

// Alpine.js component
function paymentForm() {
    return {
        customer: { id: null, name: '', balance: '0.00', credit_limit: '0.00', available: '0.00' },
        outstandingOrders: [],
        isLoadingInvoices: false,
        paymentAmount: 0, 
        totalAllocated: 0, 
        paymentType: 'advance', // Default to 'advance'
        
        // **FIX 1:** This function is now called by our new custom event
        selectCustomer(customerData) {
            // Check if customerData is valid
            if (!customerData || !customerData.id) {
                this.resetCustomer();
                return;
            }
            this.customer = {
                id: customerData.id,
                name: customerData.name,
                balance: parseFloat(customerData.balance || 0).toFixed(2),
                credit_limit: parseFloat(customerData.creditLimit || 0).toFixed(2),
                available: parseFloat(customerData.available || 0).toFixed(2)
            };
            this.fetchOutstandingOrders();
        },
        
        resetCustomer() {
            this.customer = { id: null, name: '', balance: '0.00', credit_limit: '0.00', available: '0.00' };
            this.outstandingOrders = [];
            this.paymentAmount = 0;
            this.totalAllocated = 0;
            this.paymentType = 'advance';
            document.getElementById('customer_id_hidden').value = '';
            document.getElementById('customer_search_box').value = '';
            document.getElementById('cp_selected_label').classList.add('hidden');
        },

        fetchOutstandingOrders() {
            if (!this.customer.id) return;
            this.isLoadingInvoices = true;
            this.outstandingOrders = [];

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

            fetch('../cr/ajax_handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    action: 'get_outstanding_orders',
                    customer_id: this.customer.id,
                    csrf_token: csrfToken
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    this.outstandingOrders = data.orders.map(order => ({
                        ...order,
                        amountToPay: ''
                    }));
                    this.paymentType = this.outstandingOrders.length > 0 ? 'invoice_payment' : 'advance';
                } else {
                    console.error('get_outstanding_orders error:', data.error);
                    alert('Error loading invoices: ' + (data.error || 'Unknown error'));
                }
                this.isLoadingInvoices = false;
            })
            .catch(err => {
                console.error(err);
                this.isLoadingInvoices = false;
                alert('An error occurred while fetching invoices.');
            });
        },

        calculateTotal() {
            this.totalAllocated = this.outstandingOrders.reduce((sum, order) => {
                return sum + (parseFloat(order.amountToPay) || 0);
            }, 0);

            if (this.outstandingOrders.length === 0) {
                this.totalAllocated = this.paymentAmount;
            }
        },

        validateAndSubmit(event) {
            // **FIX 2:** We use $refs to find the form
            const form = this.$refs.payment_form;
            let invalid = false;

            this.calculateTotal(); 

            if (this.outstandingOrders.length > 0) {
                this.paymentType = 'invoice_payment';
                if (this.totalAllocated > (this.paymentAmount + 0.01)) {
                    invalid = true;
                    alert(`Error: Total allocated (৳${this.totalAllocated.toFixed(2)}) cannot be greater than the Total Payment Amount (৳${this.paymentAmount.toFixed(2)}).`);
                }
                this.outstandingOrders.forEach(order => {
                    const amount = parseFloat(order.amountToPay) || 0;
                    const balance = parseFloat(order.balance_due);
                    if (amount > (balance + 0.01)) {
                        invalid = true;
                        alert(`Error: Amount for order ${order.order_number} (৳${amount.toFixed(2)}) cannot be greater than its balance due (৳${balance.toFixed(2)}).`);
                    }
                });
            } else {
                this.paymentType = 'advance';
                this.totalAllocated = this.paymentAmount;
            }
            
            if (this.paymentAmount <= 0) {
                 invalid = true;
                 alert('Error: Total Payment Amount must be greater than zero.');
            }

            if (!invalid) {
                form.submit();
            }
        }
    }
}

// ── Pre-load from order_id param ──────────────────────────────
<?php if ($preload_customer): ?>
document.addEventListener('DOMContentLoaded', () => {
    // Pre-select the customer
    const pc = {
        id:          <?php echo (int)$preload_customer->id; ?>,
        name:        <?php echo json_encode($preload_customer->name); ?>,
        phone:       <?php echo json_encode($preload_order->phone_number ?? ''); ?>,
        balance:     <?php echo (float)$preload_customer->outstanding_balance; ?>,
        creditLimit: <?php echo (float)$preload_customer->credit_limit; ?>,
        available:   <?php echo (float)$preload_customer->available_credit; ?>,
    };
    document.getElementById('customer_search_box').value = pc.name;
    document.getElementById('cp_selected_label').textContent = '✓ ' + pc.name + ' (from order <?php echo htmlspecialchars($preload_order->order_number ?? ''); ?>)';
    document.getElementById('cp_selected_label').classList.remove('hidden');

    // Trigger Alpine to select and load invoices
    window.dispatchEvent(new CustomEvent('customer-selected', { detail: pc }));

    // Init bank/cash toggle
    toggleBankAccount(document.querySelector('select[name="payment_method"]'));
});
<?php else: ?>
// **FIX 3:** This new script block runs AFTER all libraries are loaded
document.addEventListener('DOMContentLoaded', () => {
    // Init bank/cash toggle
    toggleBankAccount(document.querySelector('select[name="payment_method"]'));
});
<?php endif; ?>
</script>

<?php require_once '../templates/footer.php'; ?>