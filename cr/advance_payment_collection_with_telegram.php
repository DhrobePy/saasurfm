<?php
require_once '../core/init.php';

// --- SECURITY ---
$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-rampura', 'accounts-srg', 'accounts-demra', 'collector'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$pageTitle = 'Collect Advance Payment on Credit Orders';
$error = null;
$success = null;

// --- DATA: Get Active Credit Customers ---
$customers = $db->query(
    "SELECT id, name, business_name, phone_number, credit_limit, current_balance as outstanding_balance, 
           (credit_limit - current_balance) as available_credit
     FROM customers
     WHERE status = 'active' AND customer_type = 'Credit'
     ORDER BY name ASC"
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
     ORDER BY account_type DESC
     LIMIT 1"
)->first();

$ar_account = $db->query(
    "SELECT id FROM chart_of_accounts WHERE account_type = 'Accounts Receivable' LIMIT 1"
)->first();

$ar_account_id = null;
if (!$ar_account || !$cash_account) {
    $error = "FATAL ERROR: Missing 'Accounts Receivable' or 'Undeposited Funds' account in Chart of Accounts. Cannot proceed.";
} else {
    $ar_account_id = $ar_account->id;
}

// --- LOGIC: Handle Advance Payment Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_advance_payment' && !$error) {
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
        
        $total_payment_amount = floatval($_POST['totalPaymentAmount']);
        $allocations = $_POST['allocations'] ?? []; // Array of [order_id => amount]
        
        if ($total_payment_amount <= 0) {
            throw new Exception("Payment amount must be greater than zero");
        }
        
        // Validate allocations
        $total_allocated = 0;
        foreach ($allocations as $order_id => $amount) {
            $alloc_amount = floatval($amount);
            if ($alloc_amount > 0) {
                $total_allocated += $alloc_amount;
                
                // Verify order exists and belongs to customer
                $order_check = $db->query(
                    "SELECT id, total_amount, advance_paid, balance_due, order_number 
                     FROM credit_orders 
                     WHERE id = ? AND customer_id = ? 
                     AND status IN ('draft', 'pending_approval', 'approved', 'in_production')",
                    [(int)$order_id, $customer_id]
                )->first();
                
                if (!$order_check) {
                    throw new Exception("Invalid order selected or order not in pending status");
                }
                
                // Check if advance payment exceeds order total
                if (($order_check->advance_paid + $alloc_amount) > $order_check->total_amount) {
                    throw new Exception("Advance payment for order {$order_check->order_number} exceeds order total amount");
                }
            }
        }
        
        if ($total_allocated > $total_payment_amount + 0.01) {
            throw new Exception("Total allocated amount (৳" . number_format($total_allocated, 2) . ") cannot exceed payment amount (৳" . number_format($total_payment_amount, 2) . ")");
        }
        
        if ($total_allocated < 0.01) {
            throw new Exception("You must allocate the payment to at least one order");
        }
        
        // --- 1. Determine Deposit Account ID ---
        $deposit_chart_of_account_id = null;
        if ($payment_method === 'Cash') {
            $deposit_chart_of_account_id = $cash_account->id;
        } else {
            if (empty($bank_account_id)) {
                throw new Exception("Bank Account is required for this payment method");
            }
            $selected_bank = $db->query(
                "SELECT chart_of_account_id FROM bank_accounts WHERE id = ?", 
                [$bank_account_id]
            )->first();
            if (!$selected_bank) {
                throw new Exception("Invalid deposit bank account selected");
            }
            $deposit_chart_of_account_id = $selected_bank->chart_of_account_id;
        }

        // --- 2. Generate Payment Number ---
        $payment_number = 'ADVPAY-' . date('Ymd', strtotime($payment_date)) . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $check_payment = $db->query(
            "SELECT id FROM customer_payments WHERE payment_number = ?", 
            [$payment_number]
        )->first();
        if ($check_payment) {
            $payment_number .= '-' . time();
        }
        
        // --- 3. Insert Payment Record ---
        $payment_id = $db->insert('customer_payments', [
            'payment_number' => $payment_number,
            'customer_id' => $customer_id,
            'payment_date' => $payment_date,
            'amount' => $total_payment_amount,
            'payment_method' => $payment_method,
            'payment_type' => 'advance',
            'bank_account_id' => $bank_account_id,
            'reference_number' => $reference_number,
            'notes' => $notes,
            'allocation_status' => 'allocated',
            'allocated_amount' => $total_allocated,
            'allocated_to_invoices' => json_encode($allocations),
            'created_by_user_id' => $user_id
        ]);
        
        if (!$payment_id) {
            throw new Exception("Failed to record payment");
        }
        
        // --- 4. Get Previous Customer Ledger Balance ---
        $prev_balance_result = $db->query(
            "SELECT balance_after FROM customer_ledger 
             WHERE customer_id = ? 
             ORDER BY transaction_date DESC, id DESC 
             LIMIT 1", 
            [$customer_id]
        )->first();
        
        if ($prev_balance_result) {
            $prev_balance = (float)$prev_balance_result->balance_after;
        } else {
            // If no ledger entries, get initial_due from customer
            $customer_record = $db->query(
                "SELECT initial_due FROM customers WHERE id = ?", 
                [$customer_id]
            )->first();
            $prev_balance = (float)($customer_record->initial_due ?? 0);
        }
        
        $new_balance = $prev_balance - $total_payment_amount;
        
        // --- 5. Create Customer Ledger Entry ---
        $order_numbers_list = [];
        foreach ($allocations as $oid => $amt) {
            if (floatval($amt) > 0) {
                $order_info = $db->query(
                    "SELECT order_number FROM credit_orders WHERE id = ?", 
                    [(int)$oid]
                )->first();
                if ($order_info) {
                    $order_numbers_list[] = $order_info->order_number;
                }
            }
        }
        
        $description = "Advance payment received (Receipt #$payment_number) allocated to orders: " . implode(', ', $order_numbers_list);
        
        $ledger_id = $db->insert('customer_ledger', [
            'customer_id' => $customer_id,
            'transaction_date' => $payment_date,
            'transaction_type' => 'advance_payment',
            'reference_type' => 'customer_payments',
            'reference_id' => $payment_id,
            'invoice_number' => $payment_number,
            'description' => $description,
            'debit_amount' => 0,
            'credit_amount' => $total_payment_amount,
            'balance_after' => $new_balance,
            'created_by_user_id' => $user_id
        ]);
        
        if (!$ledger_id) {
            throw new Exception("Failed to create customer ledger entry");
        }
        
        // --- 6. Update Customer Balance ---
        $db->update('customers', $customer_id, ['current_balance' => $new_balance]);
        
        // --- 7. Create Journal Entry (Double-Entry Accounting) ---
        /*
         * Journal Entry for Advance Payment:
         * Debit: Bank/Cash Account (Asset increases)
         * Credit: Accounts Receivable (Asset decreases - customer owes less)
         */
        $journal_desc = "Advance payment $payment_number from $customer_name for pending orders";
        $journal_id = $db->insert('journal_entries', [
            'transaction_date' => $payment_date,
            'description' => $journal_desc,
            'related_document_type' => 'customer_payments',
            'related_document_id' => $payment_id,
            'created_by_user_id' => $user_id
        ]);
        
        if (!$journal_id) {
            throw new Exception("Failed to create journal entry header");
        }

        // Debit: Bank/Cash Account
        $db->insert('transaction_lines', [
            'journal_entry_id' => $journal_id,
            'account_id' => $deposit_chart_of_account_id,
            'debit_amount' => $total_payment_amount,
            'credit_amount' => 0,
            'description' => "Advance payment received from $customer_name"
        ]);
        
        // Credit: Accounts Receivable
        $db->insert('transaction_lines', [
            'journal_entry_id' => $journal_id,
            'account_id' => $ar_account_id,
            'debit_amount' => 0,
            'credit_amount' => $total_payment_amount,
            'description' => "Advance payment reduces receivable from $customer_name"
        ]);
        
        // --- 8. Link Journal Entry Back to Payment and Ledger ---
        $db->update('customer_payments', $payment_id, ['journal_entry_id' => $journal_id]);
        $db->update('customer_ledger', $ledger_id, ['journal_entry_id' => $journal_id]);

        // --- 9. Process Allocations to Credit Orders ---
        foreach ($allocations as $order_id => $amount) {
            $alloc_amount = floatval($amount);
            if ($alloc_amount > 0) {
                // Insert into payment_allocations
                $db->insert('payment_allocations', [
                    'payment_id' => $payment_id,
                    'order_id' => (int)$order_id,
                    'allocated_amount' => $alloc_amount,
                    'allocation_date' => $payment_date,
                    'allocated_by_user_id' => $user_id
                ]);
                
                // Update credit_orders: increase advance_paid, decrease balance_due
                $db->query(
                    "UPDATE credit_orders 
                     SET advance_paid = advance_paid + ?, 
                         balance_due = balance_due - ?
                     WHERE id = ?",
                    [$alloc_amount, $alloc_amount, (int)$order_id]
                );
            }
        }
        
        // --- 10. Handle Unallocated Amount (if any) ---
        $unallocated_amount = $total_payment_amount - $total_allocated;
        if ($unallocated_amount > 0.01) {
            // This remains as customer credit/advance on account
            $notes_update = $notes . " | Unallocated amount: ৳" . number_format($unallocated_amount, 2);
            $db->update('customer_payments', $payment_id, ['notes' => $notes_update]);
        }
        
        // --- 11. Commit Transaction ---
        $pdo->commit();
        
        // ============================================
        // TELEGRAM NOTIFICATION - ADVANCE PAYMENT
        // ============================================
        try {
            if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
                require_once '../core/classes/TelegramNotifier.php';
                $telegram = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);
                
                // Get customer details
                $customer_info = $db->query("SELECT name, phone_number FROM customers WHERE id = ?", [$customer_id])->first();
                
                // Get collector name
                $collector_name = 'System User';
                if ($user_id) {
                    $user_info = $db->query("SELECT display_name FROM users WHERE id = ?", [$user_id])->first();
                    $collector_name = $user_info ? $user_info->display_name : 'System User';
                }
                
                // Determine branch from user role
                $user_role = isset($_SESSION['user_role']) ? $_SESSION['user_role'] : '';
                $branch_name = 'Head Office';
                
                if (strpos($user_role, 'srg') !== false) {
                    $branch_name = 'Sirajgonj Branch';
                } elseif (strpos($user_role, 'demra') !== false) {
                    $branch_name = 'Demra Branch';
                } elseif (strpos($user_role, 'rampura') !== false) {
                    $branch_name = 'Rampura Branch';
                }
                
                // Build allocated orders list
                $allocated_invoices = [];
                foreach ($allocations as $order_id => $amount) {
                    $alloc_amount = floatval($amount);
                    if ($alloc_amount > 0) {
                        $order_info = $db->query("SELECT order_number FROM credit_orders WHERE id = ?", [(int)$order_id])->first();
                        if ($order_info) {
                            $allocated_invoices[] = [
                                'order_number' => $order_info->order_number,
                                'amount' => $alloc_amount
                            ];
                        }
                    }
                }
                
                // Prepare payment data
                $paymentData = [
                    'receipt_no' => $payment_number,
                    'payment_date' => date('d M Y, h:i A', strtotime($payment_date)),
                    'amount' => floatval($total_payment_amount),
                    'payment_method' => $payment_method,
                    'customer_name' => $customer_info ? $customer_info->name : 'Unknown Customer',
                    'customer_phone' => $customer_info ? ($customer_info->phone_number ?: 'N/A') : 'N/A',
                    'payment_type' => 'Advance Payment on Credit Orders',
                    'reference_number' => $reference_number ?: '',
                    'notes' => $notes ?: '',
                    'branch_name' => $branch_name,
                    'collected_by' => $collector_name,
                    'new_balance' => floatval($new_balance),
                    'allocated_invoices' => $allocated_invoices,
                    'unallocated_amount' => floatval($unallocated_amount)
                ];
                
                // Send notification
                $result = $telegram->sendAdvancePaymentNotification($paymentData);
                
                if ($result['success']) {
                    error_log("✓ Telegram advance payment notification sent for receipt: $payment_number");
                } else {
                    error_log("✗ Telegram advance payment notification failed: " . json_encode($result['response']));
                }
            }
        } catch (Exception $e) {
            error_log("✗ Telegram advance payment notification error: " . $e->getMessage());
        }
        // END TELEGRAM NOTIFICATION
        
        $_SESSION['success_flash'] = "Advance payment of ৳" . number_format($total_payment_amount, 2) . " recorded successfully. Receipt #$payment_number";
        header('Location: advance_payment_collection.php');
        exit();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Failed to record advance payment: " . $e->getMessage();
    }
}

// Get recent advance payments
$recent_payments = $db->query(
    "SELECT cp.*, c.name as customer_name
     FROM customer_payments cp
     JOIN customers c ON cp.customer_id = c.id
     WHERE cp.payment_type = 'advance'
     ORDER BY cp.created_at DESC
     LIMIT 20"
)->results();

require_once '../templates/header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
    /* Select2 Style Overrides */
    .select2-container .select2-selection--single {
        height: 42px !important;
        border: 1px solid #d1d5db !important;
        border-radius: 0.5rem !important;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 40px !important;
        padding-left: 1rem !important;
        color: #1f2937;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 40px !important;
    }
    .select2-dropdown {
        border: 1px solid #d1d5db !important;
        border-radius: 0.5rem !important;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
    }
    .select2-search--dropdown .select2-search__field {
        border-radius: 0.375rem;
    }
    .select2-container--disabled .select2-selection--single {
        background-color: #f3f4f6 !important;
    }
/* Order Cards Styling */
.order-card {
    transition: all 0.2s;
    border: 2px solid transparent;
}
.order-card:hover {
    border-color: #3b82f6;
    box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.1);
}
.order-card.has-allocation {
    border-color: #10b981;
    background-color: #f0fdf4;
}

</style>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
    <p class="text-lg text-gray-600 mt-1">Collect advance payments from customers for their pending credit orders before dispatch.</p>
</div>

<?php if ($error): ?>

<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg shadow-md">
    <p class="font-bold">Error</p>
    <p><?php echo htmlspecialchars($error); ?></p>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['success_flash'])): ?>

```
<div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-r-lg shadow-md">
    <p class="font-bold">Success</p>
    <p><?php echo htmlspecialchars($_SESSION['success_flash']); ?></p>
</div>
<?php unset($_SESSION['success_flash']); ?>

<?php endif; ?>

<div x-data="advancePaymentForm()" @customer-selected.window="selectCustomer($event.detail)" class="grid grid-cols-1 lg:grid-cols-3 gap-6">

<!-- Main Form Section -->
<div class="lg:col-span-2">
    <form method="POST" id="advance_payment_form" x-ref="payment_form" @submit.prevent="validateAndSubmit">
    <input type="hidden" name="action" value="record_advance_payment">
    <input type="hidden" name="customer_name" x-model="customer.name">
    <input type="hidden" name="totalPaymentAmount" x-model="totalPaymentAmount">
    
    <!-- Step 1: Select Customer -->
    <div class="bg-white rounded-lg shadow-lg p-6 mb-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4">
            <i class="fas fa-user-circle text-primary-600 mr-2"></i>1. Select Customer
        </h2>
        <div x-ignore>
            <select name="customer_id" id="customer_id" required class="w-full">
                <option value="">-- Select Customer --</option>
                <?php foreach ($customers as $customer): ?>
                <option value="<?php echo $customer->id; ?>"
                        data-balance="<?php echo $customer->outstanding_balance; ?>"
                        data-name="<?php echo htmlspecialchars($customer->name); ?>"
                        data-credit-limit="<?php echo $customer->credit_limit ?? 0; ?>"
                        data-available="<?php echo $customer->available_credit ?? 0; ?>">
                    <?php echo htmlspecialchars($customer->name); ?> 
                    (<?php echo htmlspecialchars($customer->business_name); ?>)
                    <?php if ($customer->outstanding_balance > 0): ?>
                        - Due: ৳<?php echo number_format($customer->outstanding_balance, 0); ?>
                    <?php endif; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <!-- Customer Info Display -->
        <div x-show="customer.id" x-transition class="mt-4 bg-primary-50 border border-primary-200 rounded-lg p-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                <div>
                    <p class="text-gray-600">Outstanding Balance</p>
                    <p class="text-lg font-bold text-red-600" x-text="'৳' + parseFloat(customer.balance).toLocaleString('en-BD', {minimumFractionDigits: 2})"></p>
                </div>
                <div>
                    <p class="text-gray-600">Credit Limit</p>
                    <p class="text-lg font-bold text-gray-900" x-text="'৳' + parseFloat(customer.credit_limit).toLocaleString('en-BD', {minimumFractionDigits: 2})"></p>
                </div>
                <div>
                    <p class="text-gray-600">Available Credit</p>
                    <p class="text-lg font-bold text-green-600" x-text="'৳' + parseFloat(customer.available).toLocaleString('en-BD', {minimumFractionDigits: 2})"></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Step 2: Payment Details -->
    <div class="bg-white rounded-lg shadow-lg" :class="{ 'opacity-50 pointer-events-none': !customer.id }">
        <fieldset :disabled="!customer.id">
            <div class="p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">
                    <i class="fas fa-money-bill-wave text-green-600 mr-2"></i>2. Payment Details
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Payment Date *</label>
                        <input type="date" name="payment_date" required 
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                               value="<?php echo date('Y-m-d'); ?>"
                               max="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Payment Method *</label>
                        <select name="payment_method" required 
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-white focus:ring-2 focus:ring-primary-500 focus:border-primary-500" 
                                onchange="toggleBankAccount(this)">
                            <option value="Cash">Cash</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Cheque">Cheque</option>
                            <option value="Mobile Banking">Mobile Banking (bKash/Nagad/Rocket)</option>
                            <option value="Card">Card Payment</option>
                        </select>
                    </div>
                </div>
                
                <!-- Bank Account Selection (Hidden by default) -->
                <div id="bankAccountDiv" style="display:none;" class="mt-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Deposit To Bank Account * 
                        <span class="text-xs text-gray-500">(Required for non-cash payments)</span>
                    </label>
                    <select name="bank_account_id" id="bank_account_select" 
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-white focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        <option value="">-- Select Bank Account --</option>
                        <?php foreach ($bank_accounts as $account): ?>
                        <option value="<?php echo $account->id; ?>">
                            <?php echo htmlspecialchars($account->bank_name . ' - ' . $account->account_name . ' (' . $account->account_number . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Cash Account Display (Shown by default) -->
                <div id="cashAccountDiv" style="display:block;" class="mt-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Deposit To Account</label>
                    <p class="w-full px-4 py-2 border border-gray-200 bg-gray-50 rounded-lg text-gray-700">
                        <i class="fas fa-wallet text-green-600 mr-2"></i>
                        <?php echo htmlspecialchars($cash_account->name ?? 'Cash Account'); ?>
                    </p>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Reference Number 
                            <span class="text-xs text-gray-500">(Cheque No, TXN ID, etc.)</span>
                        </label>
                        <input type="text" name="reference_number" 
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500" 
                               placeholder="e.g., CHQ-123456, TXN-789012">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Total Payment Amount Received *
                        </label>
                        <div class="relative">
                            <span class="absolute left-3 top-2.5 text-gray-500 font-bold">৳</span>
                            <input type="number" 
                                   step="0.01" 
                                   required 
                                   class="w-full pl-8 pr-4 py-2 border border-gray-300 rounded-lg text-lg font-bold focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                                   placeholder="0.00" 
                                   x-model.number="totalPaymentAmount" 
                                   @input="validateAllocations">
                        </div>
                    </div>
                </div>
                
                <div class="mt-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
                    <textarea name="notes" rows="2" 
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500" 
                              placeholder="Any additional notes about this advance payment..."></textarea>
                </div>
            </div>

            <!-- Step 3: Allocate to Pending Orders -->
            <div class="border-t border-gray-200 p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-bold text-gray-800">
                        <i class="fas fa-clipboard-list text-blue-600 mr-2"></i>3. Allocate to Pending Orders
                    </h2>
                    <div x-show="totalAllocated > 0" x-transition>
                        <span class="text-sm font-medium text-gray-700">Remaining: </span>
                        <span class="text-lg font-bold"
                             :class="{ 
                                 'text-red-600': (totalPaymentAmount - totalAllocated) < -0.01, 
                                 'text-green-600': (totalPaymentAmount - totalAllocated) > 0.01,
                                 'text-gray-700': Math.abs(totalPaymentAmount - totalAllocated) <= 0.01
                             }"
                             x-text="'৳' + (totalPaymentAmount - totalAllocated).toFixed(2)"></span>
                    </div>
                </div>

                <!-- Loading State -->
                <div x-show="isLoadingOrders" class="text-center p-8">
                    <i class="fas fa-spinner fa-spin text-3xl text-primary-600"></i>
                    <p class="text-gray-500 mt-2">Loading pending orders...</p>
                </div>
                
                <!-- No Orders State -->
                <div x-show="!isLoadingOrders && pendingOrders.length === 0 && customer.id" 
                     class="text-center p-8 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
                    <i class="fas fa-inbox text-4xl text-gray-400 mb-3"></i>
                    <p class="font-medium text-gray-700">No pending orders found for this customer</p>
                    <p class="text-sm text-gray-500 mt-1">This customer has no orders in draft, pending approval, approved, or in production status.</p>
                </div>

                <!-- Orders Grid -->
                <div x-show="pendingOrders.length > 0" x-transition class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <template x-for="order in pendingOrders" :key="order.id">
                        <div class="order-card bg-white border-2 rounded-lg p-4 shadow-sm"
                             :class="{ 'has-allocation': order.advanceAmount > 0 }">
                            <div class="flex justify-between items-start mb-3">
                                <div>
                                    <h3 class="font-bold text-primary-700" x-text="order.order_number"></h3>
                                    <p class="text-xs text-gray-500" x-text="'Date: ' + new Date(order.order_date).toLocaleDateString('en-GB')"></p>
                                </div>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full"
                                      :class="{
                                          'bg-yellow-100 text-yellow-800': order.status === 'draft',
                                          'bg-blue-100 text-blue-800': order.status === 'pending_approval',
                                          'bg-green-100 text-green-800': order.status === 'approved',
                                          'bg-purple-100 text-purple-800': order.status === 'in_production'
                                      }"
                                      x-text="order.status.replace(/_/g, ' ').toUpperCase()"></span>
                            </div>
                            
                            <div class="space-y-2 text-sm mb-3">
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Order Total:</span>
                                    <span class="font-bold text-gray-900" x-text="'৳' + parseFloat(order.total_amount).toLocaleString('en-BD', {minimumFractionDigits: 2})"></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Already Paid:</span>
                                    <span class="font-medium text-green-600" x-text="'৳' + parseFloat(order.advance_paid).toLocaleString('en-BD', {minimumFractionDigits: 2})"></span>
                                </div>
                                <div class="flex justify-between border-t pt-2">
                                    <span class="text-gray-600 font-medium">Balance Due:</span>
                                    <span class="font-bold text-red-600" x-text="'৳' + parseFloat(order.balance_due).toLocaleString('en-BD', {minimumFractionDigits: 2})"></span>
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Advance Payment Amount</label>
                                <div class="relative">
                                    <span class="absolute left-3 top-2 text-gray-500">৳</span>
                                    <input type="number" 
                                           :name="'allocations[' + order.id + ']'"
                                           class="w-full pl-8 pr-3 py-2 border border-gray-300 rounded-md text-right font-bold focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                                           placeholder="0.00"
                                           step="0.01"
                                           :max="order.balance_due"
                                           x-model.number="order.advanceAmount"
                                           @input="calculateTotal">
                                </div>
                                <p class="text-xs text-gray-500 mt-1" x-show="order.advanceAmount > order.balance_due">
                                    <i class="fas fa-exclamation-triangle text-red-500"></i>
                                    Cannot exceed balance due
                                </p>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="p-6 bg-gray-50 border-t border-gray-200 flex justify-end items-center gap-4">
                <div x-show="totalAllocated > 0" class="text-sm">
                    <span class="text-gray-600">Total Allocated:</span>
                    <span class="text-lg font-bold text-primary-700 ml-2" x-text="'৳' + totalAllocated.toFixed(2)"></span>
                </div>
                <button type="submit"
                        class="px-8 py-3 bg-primary-600 text-white font-bold rounded-lg hover:bg-primary-700 shadow-md transition-all transform hover:scale-105"
                        :class="{ 'opacity-50 cursor-not-allowed': !canSubmit }"
                        :disabled="!canSubmit">
                    <i class="fas fa-check-circle mr-2"></i>
                    Record Advance Payment
                </button>
            </div>
        </fieldset>
    </div>
</form>
</div>

<!-- Sidebar -->
<div class="space-y-6">
    <!-- Quick Links -->
    <div class="bg-white rounded-lg shadow-md p-6">
        <h3 class="text-lg font-bold text-gray-800 mb-4">
            <i class="fas fa-link text-primary-600 mr-2"></i>Quick Links
        </h3>
        <div class="space-y-2">
            <a href="customer_payment.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded transition">
                <i class="fas fa-hand-holding-usd w-5 mr-3 text-green-500"></i>
                Regular Payment Collection
            </a>
            <a href="customer_ledger.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded transition">
                <i class="fas fa-book w-5 mr-3 text-blue-500"></i>
                Customer Ledger
            </a>
            <a href="customer_credit_management.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded transition">
                <i class="fas fa-credit-card w-5 mr-3 text-purple-500"></i>
                Credit Management
            </a>
            <a href="index.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded transition">
                <i class="fas fa-tachometer-alt w-5 mr-3 text-teal-500"></i>
                CR Dashboard
            </a>
        </div>
    </div>
    
    <!-- Info Box -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-bold text-blue-900 mb-3">
            <i class="fas fa-info-circle mr-2"></i>About Advance Payments
        </h3>
        <div class="text-xs text-blue-800 space-y-2">
            <p><strong>Purpose:</strong> Collect payments from customers before their orders are approved or dispatched.</p>
            <p><strong>Status:</strong> Works with orders in Draft, Pending Approval, Approved, or In Production status.</p>
            <p><strong>Allocation:</strong> Payment is allocated to specific orders and reduces their balance due.</p>
            <p><strong>Accounting:</strong> Creates proper journal entries with debit to Bank/Cash and credit to Accounts Receivable.</p>
        </div>
    </div>

    <!-- Stats Box -->
    <div class="bg-gradient-to-br from-primary-500 to-primary-700 rounded-lg p-5 text-white shadow-lg" x-show="pendingOrders.length > 0" x-transition>
        <h3 class="text-sm font-bold mb-3">
            <i class="fas fa-chart-pie mr-2"></i>Pending Orders Summary
        </h3>
        <div class="space-y-2 text-sm">
            <div class="flex justify-between">
                <span>Total Orders:</span>
                <span class="font-bold" x-text="pendingOrders.length"></span>
            </div>
            <div class="flex justify-between">
                <span>Total Value:</span>
                <span class="font-bold" x-text="'৳' + pendingOrders.reduce((sum, o) => sum + parseFloat(o.total_amount), 0).toLocaleString('en-BD')"></span>
            </div>
            <div class="flex justify-between">
                <span>Total Due:</span>
                <span class="font-bold" x-text="'৳' + pendingOrders.reduce((sum, o) => sum + parseFloat(o.balance_due), 0).toLocaleString('en-BD')"></span>
            </div>
        </div>
    </div>
</div>

</div>

<!-- Recent Advance Payments Table -->

<div class="mt-8 bg-white rounded-lg shadow-lg overflow-hidden border border-gray-200">
    <div class="p-5 border-b border-gray-200 bg-gradient-to-r from-primary-50 to-blue-50">
        <h2 class="text-xl font-bold text-gray-800">
            <i class="fas fa-history text-primary-600 mr-2"></i>Recent Advance Payments
        </h2>
    </div>
    <div class="overflow-x-auto">
        <?php if (!empty($recent_payments)): ?>
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Receipt #</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Allocated</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Method</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($recent_payments as $payment): ?>
                <tr class="hover:bg-gray-50 transition">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="text-sm font-medium text-blue-600"><?php echo htmlspecialchars($payment->payment_number); ?></span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        <?php echo date('M j, Y', strtotime($payment->payment_date)); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium">
                        <?php echo htmlspecialchars($payment->customer_name); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold text-green-600">
                        ৳<?php echo number_format($payment->amount, 2); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium text-primary-600">
                        ৳<?php echo number_format($payment->allocated_amount ?? 0, 2); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        <?php echo htmlspecialchars($payment->payment_method); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2 py-1 text-xs font-semibold rounded-full 
                            <?php 
                            $status_colors = [
                                'allocated' => 'bg-green-100 text-green-800',
                                'partial' => 'bg-yellow-100 text-yellow-800',
                                'unallocated' => 'bg-gray-100 text-gray-800'
                            ];
                            echo $status_colors[$payment->allocation_status] ?? 'bg-gray-100 text-gray-800';
                            ?>">
                            <?php echo ucwords(str_replace('_', ' ', $payment->allocation_status)); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="p-8 text-center text-gray-500">
            <i class="fas fa-inbox text-4xl mb-3"></i>
            <p>No advance payment records found</p>
        </div>
        <?php endif; ?>
    </div>
</div>

</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
function toggleBankAccount(select) {
    const bankDiv = document.getElementById('bankAccountDiv');
    const cashDiv = document.getElementById('cashAccountDiv');
    const bankSelect = document.getElementById('bank_account_select');
    
    if (select.value && select.value !== 'Cash') {
        bankDiv.style.display = 'block';
        cashDiv.style.display = 'none';
        bankSelect.required = true;
    } else {
        bankDiv.style.display = 'none';
        cashDiv.style.display = 'block';
        bankSelect.required = false;
        bankSelect.value = '';
    }
}

// Alpine.js Component
function advancePaymentForm() {
    return {
        customer: { 
            id: null, 
            name: '', 
            balance: '0.00', 
            credit_limit: '0.00', 
            available: '0.00' 
        },
        pendingOrders: [],
        isLoadingOrders: false,
        totalPaymentAmount: 0,
        totalAllocated: 0,
        
        get canSubmit() {
            return this.totalPaymentAmount > 0 && 
                   this.totalAllocated > 0 && 
                   this.totalAllocated <= (this.totalPaymentAmount + 0.01) &&
                   this.pendingOrders.every(o => o.advanceAmount <= o.balance_due + 0.01);
        },
        
        selectCustomer(customerData) {
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
            
            this.fetchPendingOrders();
        },
        
        resetCustomer() {
            this.customer = { id: null, name: '', balance: '0.00', credit_limit: '0.00', available: '0.00' };
            this.pendingOrders = [];
            this.totalPaymentAmount = 0;
            this.totalAllocated = 0;
            $('#customer_id').val(null).trigger('change.select2');
        },

        fetchPendingOrders() {
            if (!this.customer.id) return;
            
            console.log('Fetching pending orders for customer:', this.customer.id);
            this.isLoadingOrders = true;
            this.pendingOrders = [];

            // ✅ FIXED PATH - Same directory, not ../cr/
            fetch('ajax_handler_payment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'get_pending_orders_for_advance',
                    customer_id: this.customer.id
                })
            })
            .then(res => {
                console.log('Response status:', res.status);
                if (!res.ok) {
                    throw new Error(`HTTP ${res.status}: ${res.statusText}`);
                }
                return res.json();
            })
            .then(data => {
                console.log('Response data:', data);
                if (data.success) {
                    this.pendingOrders = data.orders.map(order => ({
                        ...order,
                        advanceAmount: 0
                    }));
                    console.log('Loaded', this.pendingOrders.length, 'orders');
                } else {
                    console.error('Server error:', data.error);
                    alert('Error: ' + data.error);
                }
                this.isLoadingOrders = false;
            })
            .catch(err => {
                console.error('Fetch error:', err);
                this.isLoadingOrders = false;
                alert('Network error while fetching pending orders: ' + err.message);
            });
        },

        calculateTotal() {
            this.totalAllocated = this.pendingOrders.reduce((sum, order) => {
                return sum + (parseFloat(order.advanceAmount) || 0);
            }, 0);
        },

        validateAllocations() {
            this.calculateTotal();
        },

        validateAndSubmit(event) {
            const form = this.$refs.payment_form;
            let invalid = false;
            
            this.calculateTotal();
            
            if (this.totalPaymentAmount <= 0) {
                invalid = true;
                alert('Error: Total Payment Amount must be greater than zero.');
            }
            
            if (this.totalAllocated <= 0) {
                invalid = true;
                alert('Error: You must allocate payment to at least one order.');
            }
            
            if (this.totalAllocated > (this.totalPaymentAmount + 0.01)) {
                invalid = true;
                alert(`Error: Total allocated (৳${this.totalAllocated.toFixed(2)}) cannot exceed payment amount (৳${this.totalPaymentAmount.toFixed(2)}).`);
            }
            
            this.pendingOrders.forEach(order => {
                const amount = parseFloat(order.advanceAmount) || 0;
                const balance = parseFloat(order.balance_due);
                if (amount > (balance + 0.01)) {
                    invalid = true;
                    alert(`Error: Advance amount for order ${order.order_number} (৳${amount.toFixed(2)}) cannot exceed balance due (৳${balance.toFixed(2)}).`);
                }
            });
            
            if (!invalid) {
                form.submit();
            }
        }
    }
}

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    $(document).ready(function() {
        // Initialize Select2
        $('#customer_id').select2({
            placeholder: '-- Select Customer --',
            width: '100%',
            allowClear: true
        }).on('change', function(e) {
            const selectedOption = this.options[this.selectedIndex];
            let detail = { id: null };
            
            if (selectedOption.value) {
                detail = {
                    id: selectedOption.value,
                    name: selectedOption.dataset.name,
                    balance: selectedOption.dataset.balance,
                    creditLimit: selectedOption.dataset.creditLimit,
                    available: selectedOption.dataset.available
                };
            }
            
            window.dispatchEvent(new CustomEvent('customer-selected', { detail: detail }));
        });

        // Initialize bank/cash toggle
        toggleBankAccount(document.querySelector('select[name="payment_method"]'));
    });
});
</script>

<?php require_once '../templates/footer.php'; ?>