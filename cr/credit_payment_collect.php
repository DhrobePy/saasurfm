<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'collection-srg', 'collection-demra'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$user_role = $currentUser['role'] ?? '';
$pageTitle = 'Collect Payment';
$error = null;
$success = null;

$is_admin = in_array($user_role, ['Superadmin', 'admin']);

// Get user's branch
$user_branch = null;
if (!$is_admin) {
    $emp = $db->query("SELECT branch_id FROM employees WHERE user_id = ?", [$user_id])->first();
    if ($emp && $emp->branch_id) {
        $user_branch = $emp->branch_id;
    } else {
        $user_record = $db->query("SELECT branch_id FROM users WHERE id = ?", [$user_id])->first();
        if ($user_record && isset($user_record->branch_id)) {
            $user_branch = $user_record->branch_id;
        }
    }
}

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'collect_payment') {
    // DEBUG LOGGING
    error_log("=== PAYMENT SUBMISSION START ===");
    error_log("POST data: " . print_r($_POST, true));
    
    $customer_id = (int)$_POST['customer_id'];
    $payment_amount = (float)$_POST['payment_amount'];
    $payment_method = trim($_POST['payment_method']);
    $payment_date = $_POST['payment_date'];
    $reference_number = trim($_POST['reference_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $invoice_allocations = $_POST['invoice_allocation'] ?? [];
    
    // Payment method specific fields
    $collected_by_employee_id = isset($_POST['collected_by_employee_id']) ? (int)$_POST['collected_by_employee_id'] : null;
    $cash_account_id = isset($_POST['cash_account_id']) ? (int)$_POST['cash_account_id'] : null;
    $bank_account_id = isset($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
    $cheque_number = trim($_POST['cheque_number'] ?? '');
    $cheque_date = $_POST['cheque_date'] ?? null;
    $bank_transaction_type = trim($_POST['bank_transaction_type'] ?? '');
    
    // Validate
    if ($customer_id <= 0) {
        $error = "Please select a customer";
        error_log("✗ Validation failed: customer_id = $customer_id");
    } elseif ($payment_amount <= 0) {
        $error = "Payment amount must be greater than zero";
        error_log("✗ Validation failed: payment_amount = $payment_amount");
    } elseif (empty($payment_method)) {
        $error = "Please select payment method";
        error_log("✗ Validation failed: empty payment_method");
    } elseif (empty($payment_date)) {
        $error = "Please select payment date";
        error_log("✗ Validation failed: empty payment_date");
    } elseif ($payment_method === 'Cash' && !$cash_account_id) {
        $error = "Please select cash account";
        error_log("✗ Validation failed: Cash method but no cash_account_id");
    } elseif (in_array($payment_method, ['Bank Transfer', 'Cheque']) && !$bank_account_id) {
        $error = "Please select bank account";
        error_log("✗ Validation failed: Bank method but no bank_account_id");
    } else {
        try {
            $db->getPdo()->beginTransaction();
            
            // Get customer current balance
            $customer = $db->query("SELECT * FROM customers WHERE id = ?", [$customer_id])->first();
            if (!$customer) throw new Exception("Customer not found");
            
            // Generate payment receipt number
            $receipt_number = 'RCP-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $payment_number = 'PAY-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            // Insert payment record
            error_log("Attempting payment insert with data: " . print_r([
                'payment_number' => $payment_number,
                'receipt_number' => $receipt_number,
                'customer_id' => $customer_id,
                'amount' => $payment_amount
            ], true));
            
            $payment_id = $db->insert('customer_payments', [
                'payment_number' => $payment_number,
                'receipt_number' => $receipt_number,
                'customer_id' => $customer_id,
                'payment_date' => $payment_date,
                'amount' => $payment_amount,
                'payment_method' => $payment_method,
                'payment_type' => 'invoice_payment',
                'reference_number' => $reference_number ?: null,
                'notes' => $notes ?: null,
                'created_by_user_id' => $user_id,
                'collected_by_employee_id' => $collected_by_employee_id ?: null,
                'branch_id' => $user_branch ?: null,
                'cash_account_id' => $cash_account_id ?: null,
                'bank_account_id' => $bank_account_id ?: null,
                'cheque_number' => $cheque_number ?: null,
                'cheque_date' => $cheque_date ?: null,
                'bank_transaction_type' => $bank_transaction_type ?: null
            ]);
            
            if (!$payment_id) {
                throw new Exception("Failed to insert payment record");
            }
            
            error_log("✓✓✓ Payment inserted successfully! ID: " . $payment_id);
            
            // Get previous balance from customer_ledger
            $prev_balance_result = $db->query(
                "SELECT COALESCE(MAX(balance_after), 0) as balance 
                 FROM customer_ledger 
                 WHERE customer_id = ?", 
                [$customer_id]
            )->first();
            
            $prev_balance = $prev_balance_result ? $prev_balance_result->balance : 0;
            $new_balance = $prev_balance - $payment_amount; // Payment reduces balance
            
            // Insert into customer_ledger (CREDIT entry - reduces receivable)
            $db->insert('customer_ledger', [
                'customer_id' => $customer_id,
                'transaction_date' => $payment_date,
                'transaction_type' => 'payment',
                'reference_type' => 'customer_payments',
                'reference_id' => $payment_id,
                'invoice_number' => $receipt_number,
                'description' => "Payment received - Receipt #" . $receipt_number . 
                                ($payment_method !== 'Cash' ? " ({$payment_method})" : ""),
                'debit_amount' => 0,
                'credit_amount' => $payment_amount, // Credit reduces the receivable
                'balance_after' => $new_balance,
                'created_by_user_id' => $user_id
            ]);
            
            // Update customer balance
            $db->query(
                "UPDATE customers 
                 SET current_balance = current_balance - ?
                 WHERE id = ?",
                [$payment_amount, $customer_id]
            );
            
            // ===== PROPER DOUBLE-ENTRY ACCOUNTING =====
            // Get Accounts Receivable account ID
            $accounts_receivable_id = $db->query(
                "SELECT id FROM chart_of_accounts WHERE account_type = 'Accounts Receivable' LIMIT 1"
            )->first();
            
            if (!$accounts_receivable_id) {
                throw new Exception("Accounts Receivable account not found in Chart of Accounts");
            }
            
            $accounts_receivable_id = $accounts_receivable_id->id;
            
            $transaction_id = 'PMT-' . $receipt_number;
            
            // Create journal entry header
            $journal_id = $db->insert('journal_entries', [
                'transaction_date' => $payment_date,
                'description' => "Payment received from {$customer->name} - Receipt #{$receipt_number}",
                'related_document_id' => $payment_id,
                'related_document_type' => 'customer_payments',
                'created_by_user_id' => $user_id
            ]);
            
            // DEBIT: Cash/Bank Account (increases asset)
            if ($payment_method === 'Cash' && $cash_account_id) {
                $db->insert('transaction_lines', [
                    'journal_entry_id' => $journal_id,
                    'account_id' => $cash_account_id,
                    'debit_amount' => $payment_amount,
                    'credit_amount' => 0,
                    'description' => "Payment received - Receipt #{$receipt_number}"
                ]);
            } elseif (in_array($payment_method, ['Bank Transfer', 'Cheque']) && $bank_account_id) {
                $db->insert('transaction_lines', [
                    'journal_entry_id' => $journal_id,
                    'account_id' => $bank_account_id,
                    'debit_amount' => $payment_amount,
                    'credit_amount' => 0,
                    'description' => "Payment received via {$payment_method} - Receipt #{$receipt_number}" .
                                   ($cheque_number ? " - Cheque: {$cheque_number}" : "")
                ]);
            } elseif ($payment_method === 'Mobile Banking') {
                // Mobile banking goes to Undeposited Funds
                $undeposited_funds = $db->query(
                    "SELECT id FROM chart_of_accounts WHERE account_type = 'Other Current Asset' AND name LIKE '%Undeposited%' LIMIT 1"
                )->first();
                
                if ($undeposited_funds) {
                    $db->insert('transaction_lines', [
                        'journal_entry_id' => $journal_id,
                        'account_id' => $undeposited_funds->id,
                        'debit_amount' => $payment_amount,
                        'credit_amount' => 0,
                        'description' => "Payment received via Mobile Banking - Receipt #{$receipt_number}"
                    ]);
                }
            }
            
            // CREDIT: Accounts Receivable (decreases asset)
            $db->insert('transaction_lines', [
                'journal_entry_id' => $journal_id,
                'account_id' => $accounts_receivable_id,
                'debit_amount' => 0,
                'credit_amount' => $payment_amount,
                'description' => "Payment from {$customer->name} - Receipt #{$receipt_number}"
            ]);
            
            // Process invoice allocations if provided
            $allocated_amount = 0;
            foreach ($invoice_allocations as $order_id => $alloc_amount) {
                $alloc_amount = (float)$alloc_amount;
                if ($alloc_amount > 0) {
                    $db->insert('payment_allocations', [
                        'payment_id' => $payment_id,
                        'order_id' => $order_id,
                        'allocated_amount' => $alloc_amount,
                        'allocation_date' => $payment_date,
                        'allocated_by_user_id' => $user_id,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                    $allocated_amount += $alloc_amount;
                    
                    // Update order paid amount
                    $db->query(
                        "UPDATE credit_orders 
                         SET amount_paid = amount_paid + ?,
                             balance_due = total_amount - (amount_paid + ?)
                         WHERE id = ?",
                        [$alloc_amount, $alloc_amount, $order_id]
                    );
                }
            }
            
            // Update allocation status
            if ($allocated_amount == 0) {
                $db->query(
                    "UPDATE customer_payments 
                     SET allocation_status = 'unallocated'
                     WHERE id = ?",
                    [$payment_id]
                );
            } else {
                $db->query(
                    "UPDATE customer_payments 
                     SET allocation_status = 'allocated', allocated_amount = ?
                     WHERE id = ?",
                    [$allocated_amount, $payment_id]
                );
            }
            
            $db->getPdo()->commit();
            
            error_log("✓ Transaction committed successfully");
            error_log("Payment ID: $payment_id, Receipt: $receipt_number");
            
            $_SESSION['success_flash'] = "Payment of ৳" . number_format($payment_amount, 2) . " collected successfully. Receipt: {$receipt_number}";
            $_SESSION['payment_receipt_id'] = $payment_id;
            
            header('Location: credit_payment_collect.php?success=1&receipt=' . $payment_id);
            exit();
            
        } catch (Exception $e) {
            if ($db->getPdo()->inTransaction()) {
                $db->getPdo()->rollBack();
                error_log("✗ Transaction rolled back");
            }
            error_log("✗✗✗ EXCEPTION: " . $e->getMessage());
            error_log("File: " . $e->getFile() . " Line: " . $e->getLine());
            error_log("Trace: " . $e->getTraceAsString());
            $error = "Failed to process payment: " . $e->getMessage();
        }
    }
}

// Get customer if selected
$selected_customer = null;
$customer_orders = [];
$customer_payments = [];

if (isset($_GET['customer_id']) && $_GET['customer_id'] > 0) {
    $customer_id = (int)$_GET['customer_id'];
    $selected_customer = $db->query(
        "SELECT c.* FROM customers c WHERE c.id = ?",
        [$customer_id]
    )->first();
    
    if ($selected_customer) {
        // Get outstanding orders
        $customer_orders = $db->query(
            "SELECT co.*, 
                    b.name as branch_name,
                    (co.total_amount - co.amount_paid) as outstanding
             FROM credit_orders co
             LEFT JOIN branches b ON co.assigned_branch_id = b.id
             WHERE co.customer_id = ? 
             AND co.status IN ('shipped', 'delivered')
             AND (co.total_amount - co.amount_paid) > 0
             ORDER BY co.order_date ASC",
            [$customer_id]
        )->results();
        
        // Get payment history
        $customer_payments = $db->query(
            "SELECT cp.*, 
                    u.display_name as collected_by_name,
                    CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                    b.name as branch_name
             FROM customer_payments cp
             LEFT JOIN users u ON cp.collected_by_user_id = u.id
             LEFT JOIN employees e ON cp.collected_by_employee_id = e.id
             LEFT JOIN branches b ON cp.branch_id = b.id
             WHERE cp.customer_id = ?
             ORDER BY cp.payment_date DESC, cp.created_at DESC
             LIMIT 10",
            [$customer_id]
        )->results();
    }
}

// Get customers with outstanding balance
$customers_with_balance = $db->query(
    "SELECT c.id, c.name, c.phone_number, c.current_balance
     FROM customers c
     WHERE c.current_balance > 0
     ORDER BY c.current_balance DESC"
)->results();

// Get employees for cash collection
$employees = $db->query(
    "SELECT id, CONCAT(first_name, ' ', last_name) as full_name, email 
     FROM employees 
     WHERE status = 'active' 
     ORDER BY first_name ASC"
)->results();

// Get cash accounts from chart of accounts
$cash_accounts = $db->query(
    "SELECT id, account_number, name 
     FROM chart_of_accounts 
     WHERE account_type IN ('Cash', 'Petty Cash')
     AND status = 'active'
     ORDER BY name ASC"
)->results();

// Get bank accounts from chart of accounts
$bank_accounts = $db->query(
    "SELECT id, account_number, name 
     FROM chart_of_accounts 
     WHERE account_type = 'Bank'
     AND status = 'active'
     ORDER BY name ASC"
)->results();

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
    <p class="text-lg text-gray-600 mt-1">Collect payments from customers with proper accounting</p>
</div>

<?php if ($error): ?>
<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg">
    <p class="font-bold">Error</p>
    <p><?php echo htmlspecialchars($error); ?></p>
</div>
<?php endif; ?>

<?php if (isset($_GET['success']) && isset($_GET['receipt'])): ?>
<div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-r-lg">
    <p class="font-bold">Success!</p>
    <p>Payment collected successfully!</p>
    <div class="mt-3 flex gap-2">
        <a href="./credit_payment_receipt.php?id=<?php echo (int)$_GET['receipt']; ?>" 
           target="_blank"
           class="inline-block px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
            <i class="fas fa-print mr-2"></i>Print Receipt
        </a>
        <a href="credit_payment_collect.php" 
           class="inline-block px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
            <i class="fas fa-plus mr-2"></i>Collect Another Payment
        </a>
    </div>
</div>
<?php endif; ?>

<!-- Customer Selection -->
<div class="bg-white rounded-lg shadow-md p-6 mb-6">
    <h2 class="text-xl font-bold text-gray-900 mb-4">Select Customer</h2>
    
    <form method="GET" class="flex gap-4">
        <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-2">Customer with Outstanding Balance</label>
            <select name="customer_id" 
                    onchange="this.form.submit()"
                    class="w-full px-4 py-2 border rounded-lg">
                <option value="">-- Select Customer --</option>
                <?php foreach ($customers_with_balance as $cust): ?>
                <option value="<?php echo $cust->id; ?>" 
                        <?php echo ($selected_customer && $selected_customer->id == $cust->id) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($cust->name); ?> - 
                    <?php echo htmlspecialchars($cust->phone_number); ?> - 
                    Outstanding: ৳<?php echo number_format($cust->current_balance, 2); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<?php if ($selected_customer): ?>

<!-- Customer Summary -->
<div class="bg-blue-50 border-l-4 border-blue-500 p-6 mb-6 rounded-r-lg">
    <div class="flex justify-between items-start">
        <div>
            <h3 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($selected_customer->name); ?></h3>
            <p class="text-gray-600"><?php echo htmlspecialchars($selected_customer->phone_number); ?></p>
            <?php if (!empty($selected_customer->business_address)): ?>
            <p class="text-gray-600"><?php echo htmlspecialchars($selected_customer->business_address); ?></p>
            <?php endif; ?>
        </div>
        <div class="text-right">
            <p class="text-sm text-gray-600">Total Outstanding Balance</p>
            <p class="text-3xl font-bold text-red-600">৳<?php echo number_format($selected_customer->current_balance, 2); ?></p>
            <p class="text-xs text-gray-500 mt-1">Credit Limit: ৳<?php echo number_format($selected_customer->credit_limit, 2); ?></p>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Left: Outstanding Invoices -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <div class="p-6 border-b bg-gray-50">
            <h2 class="text-xl font-bold text-gray-900">Outstanding Invoices</h2>
            <p class="text-sm text-gray-600 mt-1"><?php echo count($customer_orders); ?> unpaid invoice(s)</p>
        </div>
        
        <div class="p-6 max-h-[600px] overflow-y-auto">
            <?php if (!empty($customer_orders)): ?>
            <div class="space-y-4" id="invoices-list">
                <?php foreach ($customer_orders as $order): 
                    $outstanding = $order->total_amount - $order->amount_paid;
                ?>
                <div class="border rounded-lg p-4 hover:bg-gray-50">
                    <div class="flex justify-between items-start mb-2">
                        <div>
                            <p class="font-bold text-gray-900"><?php echo htmlspecialchars($order->order_number); ?></p>
                            <p class="text-sm text-gray-600">
                                Date: <?php echo date('M j, Y', strtotime($order->order_date)); ?>
                            </p>
                            <p class="text-sm text-gray-600">Branch: <?php echo htmlspecialchars($order->branch_name); ?></p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm text-gray-600">Total: ৳<?php echo number_format($order->total_amount, 2); ?></p>
                            <p class="text-sm text-green-600">Paid: ৳<?php echo number_format($order->amount_paid, 2); ?></p>
                            <p class="font-bold text-red-600">Due: ৳<?php echo number_format($outstanding, 2); ?></p>
                        </div>
                    </div>
                    
                    <div class="mt-2 pt-2 border-t">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" 
                                   class="invoice-checkbox" 
                                   data-order-id="<?php echo $order->id; ?>"
                                   data-balance="<?php echo $outstanding; ?>">
                            <span class="text-sm">Allocate payment to this invoice</span>
                        </label>
                        <input type="number" 
                               class="allocation-amount mt-2 w-full px-3 py-2 border rounded hidden" 
                               id="alloc-<?php echo $order->id; ?>"
                               placeholder="Amount to allocate"
                               min="0"
                               step="0.01"
                               max="<?php echo $outstanding; ?>">
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-center py-8">
                <i class="fas fa-check-circle text-6xl text-green-500 mb-4"></i>
                <p class="text-gray-600">No outstanding invoices</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Right: Payment Form -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <div class="p-6 border-b bg-gray-50">
            <h2 class="text-xl font-bold text-gray-900">Collect Payment</h2>
        </div>
        
        <form method="POST" id="payment-form" class="p-6 space-y-4 max-h-[600px] overflow-y-auto">
            <input type="hidden" name="action" value="collect_payment">
            <input type="hidden" name="customer_id" value="<?php echo $selected_customer->id; ?>">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Payment Date *</label>
                <input type="date" name="payment_date" required 
                       value="<?php echo date('Y-m-d'); ?>"
                       max="<?php echo date('Y-m-d'); ?>"
                       class="w-full px-4 py-2 border rounded-lg">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Payment Amount * (৳)</label>
                <input type="number" name="payment_amount" id="payment_amount" required 
                       min="0.01" step="0.01"
                       max="<?php echo $selected_customer->current_balance; ?>"
                       class="w-full px-4 py-2 border rounded-lg text-lg font-bold"
                       placeholder="0.00">
                <p class="text-xs text-gray-500 mt-1">Max: ৳<?php echo number_format($selected_customer->current_balance, 2); ?></p>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Payment Method *</label>
                <select name="payment_method" id="payment_method" required class="w-full px-4 py-2 border rounded-lg">
                    <option value="">-- Select Method --</option>
                    <option value="Cash">Cash</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                    <option value="Cheque">Cheque</option>
                    <option value="Mobile Banking">Mobile Banking (bKash/Nagad)</option>
                </select>
            </div>
            
            <!-- Cash Payment Fields -->
            <div id="cash-fields" class="hidden space-y-4 p-4 bg-green-50 rounded border border-green-200">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Collected By Employee *</label>
                    <select name="collected_by_employee_id" class="w-full px-4 py-2 border rounded-lg">
                        <option value="">-- Select Employee --</option>
                        <?php foreach ($employees as $emp): ?>
                        <option value="<?php echo $emp->id; ?>">
                            <?php echo htmlspecialchars($emp->full_name); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Deposit to Cash Account *</label>
                    <select name="cash_account_id" class="w-full px-4 py-2 border rounded-lg">
                        <option value="">-- Select Cash Account --</option>
                        <?php foreach ($cash_accounts as $acc): ?>
                        <option value="<?php echo $acc->id; ?>">
                            <?php if ($acc->account_number): ?>[<?php echo htmlspecialchars($acc->account_number); ?>] <?php endif; ?>
                            <?php echo htmlspecialchars($acc->name); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <!-- Bank Transfer / Cheque Fields -->
            <div id="bank-fields" class="hidden space-y-4 p-4 bg-blue-50 rounded border border-blue-200">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Bank Account *</label>
                    <select name="bank_account_id" class="w-full px-4 py-2 border rounded-lg">
                        <option value="">-- Select Bank Account --</option>
                        <?php foreach ($bank_accounts as $acc): ?>
                        <option value="<?php echo $acc->id; ?>">
                            <?php if ($acc->account_number): ?>[<?php echo htmlspecialchars($acc->account_number); ?>] <?php endif; ?>
                            <?php echo htmlspecialchars($acc->name); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div id="cheque-fields" class="hidden space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Cheque Number *</label>
                        <input type="text" name="cheque_number" 
                               class="w-full px-4 py-2 border rounded-lg"
                               placeholder="Enter cheque number">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Cheque Date</label>
                        <input type="date" name="cheque_date" 
                               class="w-full px-4 py-2 border rounded-lg">
                    </div>
                </div>
                
                <div id="transfer-fields" class="hidden">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Transaction Type *</label>
                    <select name="bank_transaction_type" class="w-full px-4 py-2 border rounded-lg">
                        <option value="">-- Select Type --</option>
                        <option value="RTGS">RTGS (Real Time Gross Settlement)</option>
                        <option value="BEFTN">BEFTN (Bangladesh Electronic Funds Transfer)</option>
                        <option value="NPSB">NPSB (National Payment Switch Bangladesh)</option>
                        <option value="Online">Online Banking</option>
                        <option value="Deposit">Direct Deposit</option>
                    </select>
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Reference Number / Transaction ID</label>
                <input type="text" name="reference_number" 
                       class="w-full px-4 py-2 border rounded-lg"
                       placeholder="Transaction ID / Deposit Voucher / Reference">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
                <textarea name="notes" rows="2" 
                          class="w-full px-4 py-2 border rounded-lg"
                          placeholder="Any additional notes..."></textarea>
            </div>
            
            <!-- Hidden fields for invoice allocation -->
            <div id="allocation-inputs"></div>
            
            <div class="pt-4 border-t">
                <button type="submit" 
                        class="w-full px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 text-lg font-bold">
                    <i class="fas fa-check-circle mr-2"></i>Collect Payment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Payment History -->
<?php if (!empty($customer_payments)): ?>
<div class="bg-white rounded-lg shadow-md mt-6 overflow-hidden">
    <div class="p-6 border-b bg-gray-50">
        <h2 class="text-xl font-bold text-gray-900">Recent Payment History</h2>
    </div>
    
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receipt #</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Collected By</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($customer_payments as $payment): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap font-medium"><?php echo htmlspecialchars($payment->receipt_number); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap"><?php echo date('M j, Y', strtotime($payment->payment_date)); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-right font-bold text-green-600">৳<?php echo number_format($payment->amount, 2); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm"><?php echo htmlspecialchars($payment->payment_method); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm"><?php echo htmlspecialchars($payment->reference_number ?? '-'); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                        <?php echo htmlspecialchars($payment->employee_name ?? $payment->collected_by_name); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-center">
                        <a href="./credit_payment_receipt.php?id=<?php echo $payment->id; ?>" 
                           target="_blank"
                           class="text-blue-600 hover:text-blue-900">
                            <i class="fas fa-print mr-1"></i>Print
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const paymentMethodSelect = document.getElementById('payment_method');
    const cashFields = document.getElementById('cash-fields');
    const bankFields = document.getElementById('bank-fields');
    const chequeFields = document.getElementById('cheque-fields');
    const transferFields = document.getElementById('transfer-fields');
    
    // Show/hide fields based on payment method
    paymentMethodSelect.addEventListener('change', function() {
        cashFields.classList.add('hidden');
        bankFields.classList.add('hidden');
        chequeFields.classList.add('hidden');
        transferFields.classList.add('hidden');
        
        // Clear required attributes
        document.querySelectorAll('#cash-fields select, #bank-fields select, #cheque-fields input, #transfer-fields select').forEach(el => {
            el.removeAttribute('required');
        });
        
        if (this.value === 'Cash') {
            cashFields.classList.remove('hidden');
            document.querySelector('select[name="collected_by_employee_id"]').setAttribute('required', 'required');
            document.querySelector('select[name="cash_account_id"]').setAttribute('required', 'required');
        } else if (this.value === 'Bank Transfer') {
            bankFields.classList.remove('hidden');
            transferFields.classList.remove('hidden');
            document.querySelector('select[name="bank_account_id"]').setAttribute('required', 'required');
            document.querySelector('select[name="bank_transaction_type"]').setAttribute('required', 'required');
        } else if (this.value === 'Cheque') {
            bankFields.classList.remove('hidden');
            chequeFields.classList.remove('hidden');
            document.querySelector('select[name="bank_account_id"]').setAttribute('required', 'required');
            document.querySelector('input[name="cheque_number"]').setAttribute('required', 'required');
        }
    });
    
    // Invoice allocation logic
    const checkboxes = document.querySelectorAll('.invoice-checkbox');
    const paymentAmountInput = document.getElementById('payment_amount');
    const allocationInputsContainer = document.getElementById('allocation-inputs');
    
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const orderId = this.dataset.orderId;
            const balance = parseFloat(this.dataset.balance);
            const allocInput = document.getElementById('alloc-' + orderId);
            
            if (this.checked) {
                allocInput.classList.remove('hidden');
                allocInput.value = balance.toFixed(2);
            } else {
                allocInput.classList.add('hidden');
                allocInput.value = '';
            }
            
            updateTotalAllocation();
        });
    });
    
    document.querySelectorAll('.allocation-amount').forEach(input => {
        input.addEventListener('input', updateTotalAllocation);
    });
    
    function updateTotalAllocation() {
        let total = 0;
        document.querySelectorAll('.allocation-amount:not(.hidden)').forEach(input => {
            const val = parseFloat(input.value) || 0;
            total += val;
        });
        
        if (total > 0) {
            paymentAmountInput.value = total.toFixed(2);
        }
    }
    
    // On form submit, add allocation inputs
    document.getElementById('payment-form').addEventListener('submit', function(e) {
        allocationInputsContainer.innerHTML = '';
        
        document.querySelectorAll('.allocation-amount:not(.hidden)').forEach(input => {
            if (input.value && parseFloat(input.value) > 0) {
                const orderId = input.id.replace('alloc-', '');
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'invoice_allocation[' + orderId + ']';
                hiddenInput.value = input.value;
                allocationInputsContainer.appendChild(hiddenInput);
            }
        });
    });
});
</script>

<?php require_once '../templates/footer.php'; ?>