<?php
require_once '../core/init.php';

// --- SECURITY ---
$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-rampura', 'accounts-srg', 'accounts-demra', 'collector'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$pageTitle = 'Record Customer Payment';
$error = null;
$success = null;

// --- DATA: Get Customers (FIXED) ---
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
    "SELECT id FROM chart_of_accounts 
     WHERE (account_type = 'Petty Cash' AND branch_id = 4) OR name = 'Undeposited Funds'
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

        // --- 2. Generate Payment Number ---
        $payment_number = 'PAY-' . date('Ymd', strtotime($payment_date)) . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        if ($db->query("SELECT id FROM customer_payments WHERE payment_number = ?", [$payment_number])->first()) {
            $payment_number .= '-' . time();
        }
        
        // --- 3. Insert ONE Payment Record ---
        $payment_id = $db->insert('customer_payments', [
            'payment_number' => $payment_number,
            'customer_id' => $customer_id,
            'payment_date' => $payment_date,
            'payment_amount' => $payment_amount,
            'payment_method' => $payment_method,
            'payment_type' => $payment_type,
            'bank_account_id' => $bank_account_id,
            'reference_number' => $reference_number,
            'notes' => $notes,
            'allocated_to_invoices' => ($payment_type === 'invoice_payment') ? json_encode($allocations) : null,
            'created_by_user_id' => $user_id
        ]);
        if (!$payment_id) throw new Exception("Failed to record payment");
        
        // --- 4. Get Previous Ledger Balance ---
        $prev_balance_result = $db->query("SELECT balance_after FROM customer_ledger WHERE customer_id = ? ORDER BY transaction_date DESC, id DESC LIMIT 1", [$customer_id])->first();
        if ($prev_balance_result) {
            $prev_balance = (float)$prev_balance_result->balance_after;
        } else {
            $prev_balance = (float)$db->query("SELECT initial_due FROM customers WHERE id = ?", [$customer_id])->first()->initial_due;
        }
        $new_balance = $prev_balance - $payment_amount;
        
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
        $customer_name = sanitize($db->query("SELECT name FROM customers WHERE id = ?", [$customer_id])->first()->name ?? 'Customer');
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
                    
                    $db->query(
                        "UPDATE credit_orders 
                         SET advance_paid = advance_paid + ?, 
                             balance_due = balance_due - ?
                         WHERE id = ?", 
                        [$alloc_amount, $alloc_amount, (int)$order_id]
                    );
                }
            }
        }
        
        // --- 10. Commit ---
        $pdo->commit();
        
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

// Get recent payments
$recent_payments = $db->query(
    "SELECT cp.*, c.name as customer_name
     FROM customer_payments cp
     JOIN customers c ON cp.customer_id = c.id
     ORDER BY cp.created_at DESC
     LIMIT 20"
)->results();

require_once '../templates/header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
    /* Fajracct Style overrides for Select2 */
    .select2-container .select2-selection--single { height: 42px !important; border: 1px solid #d1d5db !important; border-radius: 0.5rem !important; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 40px !important; padding-left: 1rem !important; color: #1f2937; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 40px !important; }
    .select2-dropdown { border: 1px solid #d1d5db !important; border-radius: 0.5rem !important; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
    .select2-search--dropdown .select2-search__field { border-radius: 0.375rem; }
    .select2-container--disabled .select2-selection--single { background-color: #f3f4f6 !important; }
</style>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
    <p class="text-lg text-gray-600 mt-1">Record and allocate customer payments to outstanding invoices.</p>
</div>

<?php if ($error): ?>
<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg shadow-md">
    <p class="font-bold">Error</p>
    <p><?php echo htmlspecialchars($error); ?></p>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['success_flash'])): ?>
    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-r-lg shadow-md">
        <p class="font-bold">Success</p>
        <p><?php echo htmlspecialchars($_SESSION['success_flash']); ?></p>
    </div>
    <?php unset($_SESSION['success_flash']); ?>
<?php endif; ?>

<div x-data="paymentForm()" @customer-selected.window="selectCustomer($event.detail)" class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <div class="lg:col-span-2">
        <form method="POST" id="payment_main_form" x-ref="payment_form" @submit.prevent="validateAndSubmit">
        <input type="hidden" name="action" value="record_payment">
        <input type="hidden" name="customer_name" x-model="customer.name">
        <input type="hidden" name="payment_type" x-model="paymentType">
        
        <div class="bg-white rounded-lg shadow-lg p-6 mb-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">1. Select Customer</h2>
            <div x-ignore>
                <select name="customer_id" id="customer_id" required class="w-full">
                    <option value="">-- Select Customer --</option>
                    <?php foreach ($customers as $customer): ?>
                    <option value="<?php echo $customer->id; ?>"
                            data-balance="<?php echo $customer->outstanding_balance; ?>"
                            data-name="<?php echo htmlspecialchars($customer->name); ?>"
                            data-credit-limit="<?php echo $customer->credit_limit ?? 0; ?>"
                            data-available="<?php echo $customer->available_credit ?? 0; ?>">
                        <?php echo htmlspecialchars($customer->name); ?> (<?php echo htmlspecialchars($customer->business_name); ?>)
                        <?php if ($customer->outstanding_balance > 0): ?>
                            (Due: ৳<?php echo number_format($customer->outstanding_balance, 0); ?>)
                        <?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div x-show="customer.id" x-transition class="mt-4 bg-primary-50 border border-primary-200 rounded-lg p-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                    <div>
                        <p class="text-gray-600">Outstanding Balance</p>
                        <p class="text-lg font-bold text-red-600" x-text="'৳' + customer.balance"></p>
                    </div>
                    <div>
                        <p class="text-gray-600">Credit Limit</p>
                        <p class="text-lg font-bold text-gray-900" x-text="'৳' + customer.credit_limit"></p>
                    </div>
                    <div>
                        <p class="text-gray-600">Available Credit</p>
                        <p class="text-lg font-bold text-green-600" x-text="'৳' + customer.available"></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-lg" :class="{ 'opacity-50 pointer-events-none': !customer.id }">
            <fieldset :disabled="!customer.id">
                <div class="p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">2. Payment Details</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Payment Date *</label>
                            <input type="date" name="payment_date" required class="w-full px-4 py-2 border border-gray-300 rounded-lg"
                                   value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Payment Method *</label>
                            <select name="payment_method" required class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-white" onchange="toggleBankAccount(this)">
                                <option value="Cash">Cash</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Mobile Banking">Mobile Banking</option>
                                <option value="Card">Card Payment</option>
                            </select>
                        </div>
                    </div>
                    
                    <div id="bankAccountDiv" style="display:none;" class="mt-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Deposit To Bank Account *</label>
                        <select name="bank_account_id" id="bank_account_select" class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-white">
                            <option value="">-- Select Bank Account --</option>
                            <?php foreach ($bank_accounts as $account): ?>
                            <option value="<?php echo $account->id; ?>"><?php echo htmlspecialchars($account->bank_name . ' - ' . $account->account_name . ' (' . $account->account_number . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="cashAccountDiv" style="display:block;" class="mt-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Deposit To Account</label>
                        <p class="w-full px-4 py-2 border border-gray-200 bg-gray-50 rounded-lg"><?php echo htmlspecialchars($cash_account->name ?? 'N/A'); ?></p>
                    </div>
                    
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Reference Number</label>
                        <input type="text" name="reference_number" class="w-full px-4 py-2 border border-gray-300 rounded-lg" placeholder="Cheque no, TXN ID, etc.">
                    </div>
                    
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
                        <textarea name="notes" rows="2" class="w-full px-4 py-2 border border-gray-300 rounded-lg" placeholder="Any additional notes..."></textarea>
                    </div>
                </div>

                <div class="border-t border-gray-200 p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-bold text-gray-800">3. Allocate Payment</h2>
                        <div x-show="totalAllocated > 0" x-transition>
                            <span class="text-sm font-medium text-gray-700">Unallocated: </span>
                            <span class="text-sm font-bold"
                                 :class="{ 'text-red-600': (paymentAmount - totalAllocated) < -0.01, 'text-gray-700': (paymentAmount - totalAllocated) > 0.01 }"
                                 x-text="'৳' + (paymentAmount - totalAllocated).toFixed(2)"></span>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Enter Total Payment Amount Received *</label>
                        <input type="number" name="paymentAmount" step="0.01" required class="w-full px-4 py-2 border border-gray-300 rounded-lg text-lg font-bold"
                               placeholder="0.00" x-model.number="paymentAmount" @input="calculateTotal">
                        <p class="text-xs text-gray-500 mt-1">Enter the total amount received from the customer.</p>
                    </div>

                    <div x-show="isLoadingInvoices" class="text-center p-4 mt-4">
                        <i class="fas fa-spinner fa-spin text-2xl text-primary-600"></i>
                        <p class="text-gray-500">Loading outstanding invoices...</p>
                    </div>
                    
                    <div x-show="!isLoadingInvoices && outstandingOrders.length === 0 && customer.id" class="text-center p-4 bg-gray-50 rounded-lg mt-4">
                        <p class="font-medium text-gray-700">No outstanding invoices found for this customer.</p>
                        <p class="text-sm text-gray-500">This payment will be recorded as an 'Advance Payment'.</p>
                    </div>

                    <div x-show="outstandingOrders.length > 0" x-transition class="overflow-x-auto mt-4">
                        <table class="min-w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Order #</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Balance Due</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase" style="width: 150px;">Amount to Pay</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="order in outstandingOrders" :key="order.id">
                                    <tr class="border-b border-gray-200">
                                        <td class="px-4 py-3 text-sm font-medium text-primary-700" x-text="order.order_number"></td>
                                        <td class="px-4 py-3 text-sm text-gray-600" x-text="new Date(order.order_date).toLocaleDateString('en-GB')"></td>
                                        <td class="px-4 py-3 text-sm text-right text-red-600" x-text="'৳' + parseFloat(order.balance_due).toFixed(2)"></td>
                                        <td class="px-4 py-3 text-right">
                                            <input type="number" 
                                                   :name="'allocations[' + order.id + ']'"
                                                   class="w-full px-2 py-1 border border-gray-300 rounded-md text-right"
                                                   placeholder="0.00"
                                                   step="0.01"
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

                <div class="p-6 bg-gray-50 border-t border-gray-200 flex justify-end">
                    <button type="submit"
                            class="px-8 py-3 bg-primary-600 text-white font-bold rounded-lg hover:bg-primary-700 shadow-md transition-colors"
                            :class="{ 'opacity-50 cursor-not-allowed': paymentAmount <= 0 || (totalAllocated > paymentAmount + 0.01) }"
                            :disabled="paymentAmount <= 0 || (totalAllocated > paymentAmount + 0.01)">
                        <i class="fas fa-check-circle mr-2"></i>Record Payment (৳<span x-text="paymentAmount.toFixed(2)"></span>)
                    </button>
                </div>
            </fieldset>
        </form>
    </div>
    
    <div class="space-y-6">
        <div class="bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Quick Links</h3>
            <div class="space-y-2">
                <a href="customer_ledger.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded">
                    <i class="fas fa-book w-5 mr-3 text-blue-500"></i>Customer Ledger
                </a>
                <a href="customer_credit_management.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded">
                    <i class="fas fa-credit-card w-5 mr-3 text-purple-500"></i>Credit Management
                </a>
                <a href="cr/index.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded">
                    <i class="fas fa-tachometer-alt w-5 mr-3 text-teal-500"></i>CR Dashboard
                </a>
            </div>
        </div>
        
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-5 shadow-sm">
            <h3 class="text-sm font-medium text-blue-800 mb-2">Payment Types</h3>
            <div class="text-xs text-blue-700 space-y-1">
                <p><strong>Invoice Payment:</strong> Used when allocating funds to specific outstanding invoices.</p>
                <p><strong>Advance Payment:</strong> Used if no invoices are selected; payment is held on account.</p>
            </div>
        </div>
    </div>
</div>

<div class="mt-8 bg-white rounded-lg shadow-lg overflow-hidden border border-gray-200">
    <div class="p-5 border-b border-gray-200">
        <h2 class="text-xl font-bold text-gray-800">Recent Payments</h2>
    </div>
    <div class="overflow-x-auto">
        <?php if (!empty($recent_payments)): ?>
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receipt #</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($recent_payments as $payment): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-blue-600"><?php echo htmlspecialchars($payment->payment_number); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('M j, Y', strtotime($payment->payment_date)); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($payment->customer_name); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold text-green-600">৳<?php echo number_format($payment->payment_amount, 2); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($payment->payment_method); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-<?php echo $payment->payment_type === 'advance' ? 'blue' : 'green'; ?>-100 text-<?php echo $payment->payment_type === 'advance' ? 'blue' : 'green'; ?>-800">
                            <?php echo ucwords(str_replace('_', ' ', $payment->payment_type)); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="p-8 text-center text-gray-500"><p>No payment records found</p></div>
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
            $('#customer_id').val(null).trigger('change.select2'); // Reset Select2
        },

        fetchOutstandingOrders() {
            if (!this.customer.id) return;
            this.isLoadingInvoices = true;
            this.outstandingOrders = [];

            fetch('../cr/ajax_handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'get_outstanding_orders',
                    customer_id: this.customer.id
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
                    alert('Error: ' + data.error);
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

// **FIX 3:** This new script block runs AFTER all libraries are loaded
document.addEventListener('DOMContentLoaded', () => {
    // This waits for the DOM, and our scripts at the bottom are now loaded.
    $(document).ready(function() {
        
        // Initialize Select2
        $('#customer_id').select2({
            placeholder: '-- Select Customer --',
            width: '100%'
        }).on('change', function(e) {
            // This is the jQuery event
            const selectedOption = this.options[this.selectedIndex];
            let detail = { id: null }; // Default empty object
            
            if (selectedOption.value) {
                // Get data from the <option>
                detail = {
                    id: selectedOption.value,
                    name: selectedOption.dataset.name,
                    balance: selectedOption.dataset.balance,
                    creditLimit: selectedOption.dataset.creditLimit,
                    available: selectedOption.dataset.available
                };
            }
            
            // Dispatch a *custom event* that Alpine can hear
            window.dispatchEvent(new CustomEvent('customer-selected', { detail: detail }));
        });

        // Init bank/cash toggle
        toggleBankAccount(document.querySelector('select[name="payment_method"]'));
    });
});
</script>

<?php require_once '../templates/footer.php'; ?>