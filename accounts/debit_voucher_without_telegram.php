<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$user_role = $currentUser['role'] ?? '';
$pageTitle = 'Create Debit Voucher';
$error = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_voucher') {
    $voucher_date = $_POST['voucher_date'];
    $expense_account_id = (int)$_POST['expense_account_id'];
    $payment_account_id = (int)$_POST['payment_account_id'];
    $amount = (float)$_POST['amount'];
    $paid_to = trim($_POST['paid_to']);
    $employee_id = !empty($_POST['employee_id']) ? (int)$_POST['employee_id'] : null;
    $description = trim($_POST['description']);
    $reference_number = trim($_POST['reference_number'] ?? '');
    $branch_id = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
    
    // Validate
    if (empty($voucher_date)) {
        $error = "Please select voucher date";
    } elseif ($expense_account_id <= 0) {
        $error = "Please select expense account";
    } elseif ($payment_account_id <= 0) {
        $error = "Please select payment account";
    } elseif ($amount <= 0) {
        $error = "Amount must be greater than zero";
    } elseif (empty($paid_to)) {
        $error = "Please enter beneficiary name";
    } elseif (empty($description)) {
        $error = "Please enter payment description";
    } else {
        try {
            $db->getPdo()->beginTransaction();
            
            // Generate voucher number
            $voucher_number = 'DV-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            // Insert debit voucher
            $voucher_id = $db->insert('debit_vouchers', [
                'voucher_number' => $voucher_number,
                'voucher_date' => $voucher_date,
                'expense_account_id' => $expense_account_id,
                'payment_account_id' => $payment_account_id,
                'amount' => $amount,
                'paid_to' => $paid_to,
                'employee_id' => $employee_id,
                'description' => $description,
                'reference_number' => $reference_number ?: null,
                'branch_id' => $branch_id,
                'created_by_user_id' => $user_id,
                'status' => 'approved' // Auto-approve for now
            ]);
            
            if (!$voucher_id) {
                throw new Exception("Failed to create voucher");
            }
            
            // Create journal entry
            $journal_id = $db->insert('journal_entries', [
                'transaction_date' => $voucher_date,
                'description' => "Debit Voucher #{$voucher_number} - {$description}",
                'related_document_id' => $voucher_id,
                'related_document_type' => 'debit_vouchers',
                'created_by_user_id' => $user_id
            ]);
            
            // DEBIT: Expense Account (increases expense)
            $db->insert('transaction_lines', [
                'journal_entry_id' => $journal_id,
                'account_id' => $expense_account_id,
                'debit_amount' => $amount,
                'credit_amount' => 0,
                'description' => "Payment to {$paid_to} - {$description}"
            ]);
            
            // CREDIT: Cash/Bank Account (decreases asset)
            $db->insert('transaction_lines', [
                'journal_entry_id' => $journal_id,
                'account_id' => $payment_account_id,
                'debit_amount' => 0,
                'credit_amount' => $amount,
                'description' => "Payment via debit voucher {$voucher_number}"
            ]);
            
            // Update voucher with journal entry ID
            $db->query(
                "UPDATE debit_vouchers SET journal_entry_id = ? WHERE id = ?",
                [$journal_id, $voucher_id]
            );
            
            $db->getPdo()->commit();
            
            $_SESSION['success_flash'] = "Debit voucher created successfully!";
            header('Location: debit_voucher_print.php?id=' . $voucher_id);
            exit();
            
        } catch (Exception $e) {
            if ($db->getPdo()->inTransaction()) {
                $db->getPdo()->rollBack();
            }
            $error = "Failed to create voucher: " . $e->getMessage();
        }
    }
}

// Get expense accounts
$expense_accounts = $db->query(
    "SELECT id, account_number, name 
     FROM chart_of_accounts 
     WHERE account_type IN ('Expense', 'Cost of Goods Sold', 'Other Expense')
     AND status = 'active'
     ORDER BY name ASC"
)->results();

// Get cash/bank accounts
$payment_accounts = $db->query(
    "SELECT id, account_number, name, account_type
     FROM chart_of_accounts 
     WHERE account_type IN ('Cash', 'Petty Cash', 'Bank')
     AND status = 'active'
     ORDER BY account_type, name ASC"
)->results();

// Get branches
$branches = $db->query(
    "SELECT id, name, code FROM branches WHERE status = 'active' ORDER BY name ASC"
)->results();

// Get employees
$employees = $db->query(
    "SELECT id, CONCAT(first_name, ' ', last_name) as full_name, email 
     FROM employees 
     WHERE status = 'active' 
     ORDER BY first_name ASC"
)->results();

// Get recent vouchers
$recent_vouchers = $db->query(
    "SELECT dv.*, 
            ea.name as expense_account_name,
            pa.name as payment_account_name,
            u.display_name as created_by_name,
            CONCAT(e.first_name, ' ', e.last_name) as employee_name
     FROM debit_vouchers dv
     LEFT JOIN chart_of_accounts ea ON dv.expense_account_id = ea.id
     LEFT JOIN chart_of_accounts pa ON dv.payment_account_id = pa.id
     LEFT JOIN users u ON dv.created_by_user_id = u.id
     LEFT JOIN employees e ON dv.employee_id = e.id
     ORDER BY dv.id DESC
     LIMIT 20"
)->results();

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
    <p class="text-lg text-gray-600 mt-1">Issue payment vouchers for expenses with proper accounting</p>
</div>

<?php if ($error): ?>
<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg">
    <p class="font-bold">Error</p>
    <p><?php echo htmlspecialchars($error); ?></p>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    
    <!-- Left: Create Voucher Form -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <div class="p-6 border-b bg-gray-50">
            <h2 class="text-xl font-bold text-gray-900">New Debit Voucher</h2>
        </div>
        
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="action" value="create_voucher">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Voucher Date *</label>
                <input type="date" name="voucher_date" required 
                       value="<?php echo date('Y-m-d'); ?>"
                       max="<?php echo date('Y-m-d'); ?>"
                       class="w-full px-4 py-2 border rounded-lg">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Employee (Optional)</label>
                <select name="employee_id" id="employee_select" class="w-full px-4 py-2 border rounded-lg">
                    <option value="">-- Select Employee (if applicable) --</option>
                    <?php foreach ($employees as $emp): ?>
                    <option value="<?php echo $emp->id; ?>" data-name="<?php echo htmlspecialchars($emp->full_name); ?>">
                        <?php echo htmlspecialchars($emp->full_name); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-500 mt-1">Select if payment is to an employee (will auto-fill name)</p>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Paid To (Beneficiary) *</label>
                <input type="text" name="paid_to" id="paid_to" required 
                       placeholder="Supplier/Vendor/Employee name"
                       class="w-full px-4 py-2 border rounded-lg">
                <p class="text-xs text-gray-500 mt-1">Auto-filled if employee selected above</p>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Amount (৳) *</label>
                <input type="number" name="amount" required 
                       min="0.01" step="0.01"
                       placeholder="0.00"
                       class="w-full px-4 py-2 border rounded-lg text-lg font-bold">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Expense Account *</label>
                <select name="expense_account_id" required class="w-full px-4 py-2 border rounded-lg">
                    <option value="">-- Select Expense Account --</option>
                    <?php foreach ($expense_accounts as $acc): ?>
                    <option value="<?php echo $acc->id; ?>">
                        <?php if ($acc->account_number): ?>[<?php echo htmlspecialchars($acc->account_number); ?>] <?php endif; ?>
                        <?php echo htmlspecialchars($acc->name); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Payment From (Cash/Bank) *</label>
                <select name="payment_account_id" required class="w-full px-4 py-2 border rounded-lg">
                    <option value="">-- Select Payment Account --</option>
                    <?php 
                    $current_type = '';
                    foreach ($payment_accounts as $acc): 
                        if ($current_type != $acc->account_type) {
                            if ($current_type != '') echo '</optgroup>';
                            echo '<optgroup label="' . htmlspecialchars($acc->account_type) . '">';
                            $current_type = $acc->account_type;
                        }
                    ?>
                    <option value="<?php echo $acc->id; ?>">
                        <?php if ($acc->account_number): ?>[<?php echo htmlspecialchars($acc->account_number); ?>] <?php endif; ?>
                        <?php echo htmlspecialchars($acc->name); ?>
                    </option>
                    <?php endforeach; ?>
                    <?php if ($current_type != '') echo '</optgroup>'; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Description/Narration *</label>
                <textarea name="description" required rows="3" 
                          placeholder="Purpose of payment, bill details, etc."
                          class="w-full px-4 py-2 border rounded-lg"></textarea>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Reference Number</label>
                <input type="text" name="reference_number" 
                       placeholder="Bill/Invoice number (optional)"
                       class="w-full px-4 py-2 border rounded-lg">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Branch</label>
                <select name="branch_id" class="w-full px-4 py-2 border rounded-lg">
                    <option value="">-- Select Branch (Optional) --</option>
                    <?php foreach ($branches as $branch): ?>
                    <option value="<?php echo $branch->id; ?>">
                        <?php echo htmlspecialchars($branch->name); ?>
                        <?php if ($branch->code): ?>(<?php echo htmlspecialchars($branch->code); ?>)<?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="pt-4 border-t">
                <button type="submit" 
                        class="w-full px-6 py-3 bg-red-600 text-white rounded-lg hover:bg-red-700 text-lg font-bold">
                    <i class="fas fa-file-invoice mr-2"></i>Create Debit Voucher
                </button>
            </div>
        </form>
    </div>
    
    <!-- Right: Recent Vouchers -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <div class="p-6 border-b bg-gray-50">
            <h2 class="text-xl font-bold text-gray-900">Recent Debit Vouchers</h2>
        </div>
        
        <div class="p-4 max-h-[800px] overflow-y-auto">
            <?php if (!empty($recent_vouchers)): ?>
            <div class="space-y-3">
                <?php foreach ($recent_vouchers as $voucher): ?>
                <div class="border rounded-lg p-4 hover:bg-gray-50">
                    <div class="flex justify-between items-start mb-2">
                        <div>
                            <p class="font-bold text-gray-900"><?php echo htmlspecialchars($voucher->voucher_number); ?></p>
                            <p class="text-sm text-gray-600">
                                <?php echo date('M j, Y', strtotime($voucher->voucher_date)); ?>
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="text-lg font-bold text-red-600">৳<?php echo number_format($voucher->amount, 2); ?></p>
                            <span class="inline-block px-2 py-1 text-xs rounded 
                                <?php echo $voucher->status === 'approved' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                <?php echo ucfirst($voucher->status); ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="text-sm mb-2">
                        <p class="text-gray-600"><strong>Paid To:</strong> <?php echo htmlspecialchars($voucher->paid_to); ?></p>
                        <?php if ($voucher->employee_name): ?>
                        <p class="text-gray-600"><strong>Employee:</strong> <?php echo htmlspecialchars($voucher->employee_name); ?></p>
                        <?php endif; ?>
                        <p class="text-gray-600"><strong>Expense:</strong> <?php echo htmlspecialchars($voucher->expense_account_name); ?></p>
                        <p class="text-gray-600"><strong>Paid From:</strong> <?php echo htmlspecialchars($voucher->payment_account_name); ?></p>
                    </div>
                    
                    <p class="text-sm text-gray-700 mb-2"><?php echo htmlspecialchars($voucher->description); ?></p>
                    
                    <div class="flex gap-2 mt-3 pt-3 border-t">
                        <a href="debit_voucher_print.php?id=<?php echo $voucher->id; ?>" 
                           target="_blank"
                           class="text-blue-600 hover:text-blue-900 text-sm">
                            <i class="fas fa-print mr-1"></i>Print
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-center py-8">
                <i class="fas fa-file-invoice text-6xl text-gray-300 mb-4"></i>
                <p class="text-gray-500">No vouchers created yet</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
</div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const employeeSelect = document.getElementById('employee_select');
    const paidToInput = document.getElementById('paid_to');
    
    employeeSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (this.value && selectedOption.dataset.name) {
            paidToInput.value = selectedOption.dataset.name;
        }
    });
});
</script>

<?php require_once '../templates/footer.php'; ?>
