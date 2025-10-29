<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-rampura', 'accounts-srg', 'accounts-demra'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$pageTitle = 'Record Customer Payment';
$error = null;
$success = null;

// Get customers with outstanding balances
$customers = $db->query(
    "SELECT id, name, phone_number, credit_limit, current_balance as outstanding_balance, status
     FROM customers
     WHERE c.status = 'active'
     ORDER BY c.name ASC"
)->results();

// Get bank accounts for payment methods
$bank_accounts = $db->query(
    "SELECT id, account_name, account_number, bank_name 
     FROM bank_accounts 
     WHERE status = 'active' 
     ORDER BY account_name"
)->results();

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_payment') {
    try {
        $db->getPdo()->beginTransaction();
        
        $customer_id = (int)$_POST['customer_id'];
        $payment_date = $_POST['payment_date'];
        $payment_amount = floatval($_POST['payment_amount']);
        $payment_method = $_POST['payment_method'];
        $payment_type = $_POST['payment_type']; // advance, invoice_payment, partial_payment
        $bank_account_id = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
        $reference_number = trim($_POST['reference_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $invoice_allocations = isset($_POST['invoice_allocations']) ? json_decode($_POST['invoice_allocations'], true) : [];
        
        if ($payment_amount <= 0) {
            throw new Exception("Payment amount must be greater than zero");
        }
        
        // Generate payment number
        $payment_number = 'PAY-' . date('Ymd', strtotime($payment_date)) . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Check if payment number exists
        $exists = $db->query("SELECT id FROM customer_payments WHERE payment_number = ?", [$payment_number])->first();
        if ($exists) {
            $payment_number .= '-' . time();
        }
        
        // Insert payment record
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
            'allocated_to_invoices' => !empty($invoice_allocations) ? json_encode($invoice_allocations) : null,
            'created_by_user_id' => $user_id
        ]);
        
        if (!$payment_id) {
            throw new Exception("Failed to record payment");
        }
        
        // Get previous balance
        $prev_balance_result = $db->query(
            "SELECT COALESCE(MAX(balance_after), 0) as balance 
             FROM customer_ledger 
             WHERE customer_id = ?",
            [$customer_id]
        )->first();
        
        $prev_balance = $prev_balance_result ? $prev_balance_result->balance : 0;
        $new_balance = $prev_balance - $payment_amount;
        
        // Create ledger entry for payment
        $transaction_type = $payment_type === 'advance' ? 'advance_payment' : 'payment';
        $description = $payment_type === 'advance' 
            ? "Advance payment received - Receipt #$payment_number"
            : "Payment received - Receipt #$payment_number";
        
        $db->insert('customer_ledger', [
            'customer_id' => $customer_id,
            'transaction_date' => $payment_date,
            'transaction_type' => $transaction_type,
            'reference_type' => 'customer_payments',
            'reference_id' => $payment_id,
            'invoice_number' => $payment_number,
            'description' => $description,
            'debit_amount' => 0,
            'credit_amount' => $payment_amount,
            'balance_after' => $new_balance,
            'created_by_user_id' => $user_id
        ]);
        
        // Update credit limits - restore available credit
        // Update customer balance
$db->query(
    "UPDATE customers 
     SET current_balance = GREATEST(0, current_balance - ?)
     WHERE id = ?",
    [$payment_amount, $customer_id]
);
        
        // If allocations provided, create allocation records
        if (!empty($invoice_allocations)) {
            foreach ($invoice_allocations as $alloc) {
                if (!empty($alloc['order_id']) && !empty($alloc['amount'])) {
                    $db->insert('advance_payment_allocations', [
                        'payment_id' => $payment_id,
                        'order_id' => $alloc['order_id'],
                        'allocated_amount' => $alloc['amount'],
                        'allocation_date' => $payment_date,
                        'allocated_by_user_id' => $user_id,
                        'notes' => $alloc['notes'] ?? null
                    ]);
                }
            }
        }
        
        $db->getPdo()->commit();
        $_SESSION['success_flash'] = "Payment of ৳" . number_format($payment_amount, 2) . " recorded successfully. Receipt #$payment_number";
        header('Location: customer_payment.php');
        exit();
        
    } catch (Exception $e) {
        if ($db->getPdo()->inTransaction()) {
            $db->getPdo()->rollBack();
        }
        $error = "Failed to record payment: " . $e->getMessage();
    }
}

// Get recent payments for display
$recent_payments = $db->query(
    "SELECT cp.*, c.name as customer_name
     FROM customer_payments cp
     JOIN customers c ON cp.customer_id = c.id
     ORDER BY cp.created_at DESC
     LIMIT 20"
)->results();

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
    <p class="text-lg text-gray-600 mt-1">Record customer payments and update ledgers</p>
</div>

<?php if ($error): ?>
<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg">
    <p class="font-bold">Error</p>
    <p><?php echo htmlspecialchars($error); ?></p>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- Payment Form -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Payment Details</h2>
            
            <form method="POST" id="paymentForm">
                <input type="hidden" name="action" value="record_payment">
                <input type="hidden" name="invoice_allocations" id="invoice_allocations">
                
                <div class="space-y-4">
                    <!-- Customer Selection -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Customer *</label>
                        <select name="customer_id" id="customer_id" required class="w-full px-4 py-2 border rounded-lg" onchange="updateCustomerInfo()">
                            <option value="">-- Select Customer --</option>
                            <?php foreach ($customers as $customer): ?>
                            <option value="<?php echo $customer->id; ?>"
                                    data-balance="<?php echo $customer->outstanding_balance; ?>"
                                    data-credit-limit="<?php echo $customer->credit_limit ?? 0; ?>"
                                    data-available="<?php echo $customer->available_credit ?? 0; ?>">
                                <?php echo htmlspecialchars($customer->name); ?>
                                <?php if ($customer->outstanding_balance > 0): ?>
                                    (Balance: ৳<?php echo number_format($customer->outstanding_balance, 0); ?>)
                                <?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Customer Info Display -->
                    <div id="customerInfo" class="hidden bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <div class="grid grid-cols-3 gap-4 text-sm">
                            <div>
                                <p class="text-gray-600">Outstanding Balance</p>
                                <p class="text-lg font-bold text-red-600" id="displayBalance">৳0</p>
                            </div>
                            <div>
                                <p class="text-gray-600">Credit Limit</p>
                                <p class="text-lg font-bold text-gray-900" id="displayCreditLimit">৳0</p>
                            </div>
                            <div>
                                <p class="text-gray-600">Available Credit</p>
                                <p class="text-lg font-bold text-green-600" id="displayAvailable">৳0</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Payment Date -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Payment Date *</label>
                            <input type="date" name="payment_date" required class="w-full px-4 py-2 border rounded-lg"
                                   value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <!-- Payment Amount -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Payment Amount *</label>
                            <input type="number" name="payment_amount" step="0.01" required class="w-full px-4 py-2 border rounded-lg"
                                   placeholder="0.00">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Payment Method -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Payment Method *</label>
                            <select name="payment_method" required class="w-full px-4 py-2 border rounded-lg" onchange="toggleBankAccount(this)">
                                <option value="">-- Select Method --</option>
                                <option value="Cash">Cash</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Mobile Banking">Mobile Banking (bKash/Nagad/Rocket)</option>
                                <option value="Card">Card Payment</option>
                            </select>
                        </div>
                        
                        <!-- Payment Type -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Payment Type *</label>
                            <select name="payment_type" required class="w-full px-4 py-2 border rounded-lg">
                                <option value="invoice_payment">Invoice Payment</option>
                                <option value="partial_payment">Partial Payment</option>
                                <option value="advance">Advance Payment</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Bank Account (shown for non-cash payments) -->
                    <div id="bankAccountDiv" style="display:none;">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Bank Account</label>
                        <select name="bank_account_id" class="w-full px-4 py-2 border rounded-lg">
                            <option value="">-- Select Bank Account --</option>
                            <?php foreach ($bank_accounts as $account): ?>
                            <option value="<?php echo $account->id; ?>">
                                <?php echo htmlspecialchars($account->account_name); ?> - 
                                <?php echo htmlspecialchars($account->bank_name); ?>
                                (<?php echo htmlspecialchars($account->account_number); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Reference Number -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Reference/Transaction Number</label>
                        <input type="text" name="reference_number" class="w-full px-4 py-2 border rounded-lg"
                               placeholder="Cheque number, transaction ID, etc.">
                    </div>
                    
                    <!-- Notes -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
                        <textarea name="notes" rows="2" class="w-full px-4 py-2 border rounded-lg"
                                  placeholder="Any additional notes..."></textarea>
                    </div>
                    
                    <!-- Submit Button -->
                    <div class="flex gap-3 pt-4 border-t">
                        <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                            <i class="fas fa-check mr-2"></i>Record Payment
                        </button>
                        <button type="reset" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                            Clear Form
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Quick Actions Sidebar -->
    <div class="space-y-6">
        <div class="bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Quick Links</h3>
            <div class="space-y-2">
                <a href="customer_ledger.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded">
                    <i class="fas fa-book w-5 mr-2 text-blue-500"></i>Customer Ledger
                </a>
                <a href="customer_credit_management.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded">
                    <i class="fas fa-credit-card w-5 mr-2 text-purple-500"></i>Credit Management
                </a>
                <a href="credit_dashboard.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded">
                    <i class="fas fa-dashboard w-5 mr-2 text-teal-500"></i>Dashboard
                </a>
            </div>
        </div>
        
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-5">
            <h3 class="text-sm font-medium text-blue-800 mb-2">Payment Types</h3>
            <div class="text-xs text-blue-700 space-y-1">
                <p><strong>Invoice Payment:</strong> Payment against specific invoice(s)</p>
                <p><strong>Partial Payment:</strong> Partial amount towards outstanding balance</p>
                <p><strong>Advance Payment:</strong> Payment before order/delivery</p>
            </div>
        </div>
    </div>

</div>

<!-- Recent Payments -->
<div class="mt-6 bg-white rounded-lg shadow-md">
    <div class="p-6 border-b border-gray-200">
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
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-blue-600">
                        <?php echo htmlspecialchars($payment->payment_number); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        <?php echo date('M j, Y', strtotime($payment->payment_date)); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        <?php echo htmlspecialchars($payment->customer_name); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold text-green-600">
                        ৳<?php echo number_format($payment->payment_amount, 2); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        <?php echo htmlspecialchars($payment->payment_method); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                        <span class="px-2 py-1 text-xs font-semibold rounded-full 
                                     bg-<?php echo $payment->payment_type === 'advance' ? 'blue' : 'green'; ?>-100 
                                     text-<?php echo $payment->payment_type === 'advance' ? 'blue' : 'green'; ?>-800">
                            <?php echo ucwords(str_replace('_', ' ', $payment->payment_type)); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="p-8 text-center text-gray-500">
            <p>No payment records found</p>
        </div>
        <?php endif; ?>
    </div>
</div>

</div>

<script>
function updateCustomerInfo() {
    const select = document.getElementById('customer_id');
    const option = select.options[select.selectedIndex];
    
    if (!option.value) {
        document.getElementById('customerInfo').classList.add('hidden');
        return;
    }
    
    const balance = parseFloat(option.dataset.balance) || 0;
    const creditLimit = parseFloat(option.dataset.creditLimit) || 0;
    const available = parseFloat(option.dataset.available) || 0;
    
    document.getElementById('displayBalance').textContent = '৳' + balance.toFixed(2);
    document.getElementById('displayCreditLimit').textContent = '৳' + creditLimit.toFixed(0);
    document.getElementById('displayAvailable').textContent = '৳' + available.toFixed(0);
    document.getElementById('customerInfo').classList.remove('hidden');
}

function toggleBankAccount(select) {
    const bankDiv = document.getElementById('bankAccountDiv');
    if (select.value && select.value !== 'Cash') {
        bankDiv.style.display = 'block';
    } else {
        bankDiv.style.display = 'none';
    }
}
</script>

<?php require_once '../templates/footer.php'; ?>