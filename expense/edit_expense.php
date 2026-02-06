<?php
/**
 * Edit Expense Voucher
 * Superadmin only - Only pending expenses can be edited
 */

require_once '../core/init.php';
require_once '../core/classes/ExpenseManager.php';

// Check permission
if (!canEditExpense()) {
    $_SESSION['error_flash'] = 'Only Superadmin can edit expenses.';
    header('Location: ' . url('expense/expense_history.php'));
    exit();
}

$expense_id = (int)($_GET['id'] ?? 0);

if (!$expense_id) {
    $_SESSION['error_flash'] = 'Invalid expense ID.';
    header('Location: ' . url('expense/expense_history.php'));
    exit();
}

// Get Database instance
$dbInstance = Database::getInstance();
$expenseManager = new ExpenseManager($dbInstance);

// Get expense details
try {
    $stmt = $dbInstance->getPdo()->prepare("
        SELECT ev.*, 
               ec.category_name,
               es.subcategory_name,
               b.name as branch_name
        FROM expense_vouchers ev
        LEFT JOIN expense_categories ec ON ev.category_id = ec.id
        LEFT JOIN expense_subcategories es ON ev.subcategory_id = es.id
        LEFT JOIN branches b ON ev.branch_id = b.id
        WHERE ev.id = :id
    ");
    $stmt->execute(['id' => $expense_id]);
    $expense = $stmt->fetch(PDO::FETCH_OBJ);
} catch (Exception $e) {
    $_SESSION['error_flash'] = 'Error loading expense: ' . $e->getMessage();
    header('Location: ' . url('expense/expense_history.php'));
    exit();
}

if (!$expense) {
    $_SESSION['error_flash'] = 'Expense voucher not found.';
    header('Location: ' . url('expense/expense_history.php'));
    exit();
}

// Only allow editing pending expenses
if ($expense->status !== 'pending') {
    $_SESSION['error_flash'] = 'Only pending expenses can be edited. This expense is ' . $expense->status . '.';
    header('Location: ' . url('expense/expense_history.php'));
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and process the update
    $data = [
        'expense_date' => $_POST['expense_date'] ?? '',
        'category_id' => $_POST['category_id'] ?? '',
        'subcategory_id' => $_POST['subcategory_id'] ?? '',
        'branch_id' => !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null,
        'total_amount' => $_POST['total_amount'] ?? 0,
        'handled_by_person' => $_POST['handled_by_person'] ?? '',
        'remarks' => $_POST['remarks'] ?? '',
        'payment_method' => $_POST['payment_method'] ?? '',
        'bank_account_id' => !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null,
        'cash_account_id' => !empty($_POST['cash_account_id']) ? (int)$_POST['cash_account_id'] : null,
        'payment_reference' => $_POST['payment_reference'] ?? '',
    ];
    
    // Basic validation
    if (empty($data['expense_date']) || empty($data['category_id']) || empty($data['subcategory_id']) || empty($data['total_amount'])) {
        $_SESSION['error_flash'] = 'Please fill in all required fields.';
    } else {
        try {
            $result = $expenseManager->updateExpenseVoucher($expense_id, $data);
            
            if ($result['success']) {
                $_SESSION['success_flash'] = $result['message'];
                header('Location: ' . url('expense/expense_history.php'));
                exit();
            } else {
                $_SESSION['error_flash'] = $result['message'];
            }
        } catch (Exception $e) {
            $_SESSION['error_flash'] = 'Error updating expense: ' . $e->getMessage();
        }
    }
}

// Get categories and branches for dropdowns
try {
    $categories = $expenseManager->getAllCategories();
    
    $branchStmt = $dbInstance->getPdo()->prepare("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name");
    $branchStmt->execute();
    $branches = $branchStmt->fetchAll(PDO::FETCH_OBJ);
    
    // Get subcategories for selected category
    $subcatStmt = $dbInstance->getPdo()->prepare("
        SELECT id, subcategory_name 
        FROM expense_subcategories 
        WHERE category_id = :category_id AND is_active = 1 
        ORDER BY subcategory_name
    ");
    $subcatStmt->execute(['category_id' => $expense->category_id]);
    $subcategories = $subcatStmt->fetchAll(PDO::FETCH_OBJ);
    
    // Get bank accounts
    $bankStmt = $dbInstance->getPdo()->prepare("
        SELECT id, CONCAT(bank_name, ' - ', account_name) as account_display 
        FROM bank_accounts 
        WHERE status = 'active' 
        ORDER BY bank_name
    ");
    $bankStmt->execute();
    $bankAccounts = $bankStmt->fetchAll(PDO::FETCH_OBJ);
    
    // Get cash accounts
    $cashStmt = $dbInstance->getPdo()->prepare("
        SELECT id, account_name 
        FROM branch_petty_cash_accounts 
        WHERE status = 'active' 
        ORDER BY account_name
    ");
    $cashStmt->execute();
    $cashAccounts = $cashStmt->fetchAll(PDO::FETCH_OBJ);
    
} catch (Exception $e) {
    $_SESSION['error_flash'] = 'Error loading form data: ' . $e->getMessage();
    header('Location: ' . url('expense/expense_history.php'));
    exit();
}

$pageTitle = "Edit Expense Voucher";
include '../templates/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-4xl mx-auto">
        <!-- Page Header -->
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Edit Expense Voucher</h1>
                <p class="text-gray-600 mt-1">
                    Voucher: <span class="font-semibold"><?php echo htmlspecialchars($expense->voucher_number); ?></span>
                    <span class="ml-3 px-2 py-1 bg-yellow-100 text-yellow-800 rounded text-xs">
                        <i class="fas fa-clock mr-1"></i><?php echo ucfirst($expense->status); ?>
                    </span>
                </p>
            </div>
            <a href="<?php echo url('expense/expense_history.php'); ?>" 
               class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                <i class="fas fa-arrow-left mr-2"></i>Back to History
            </a>
        </div>

        <?php echo displayFlashMessage(); ?>

        <!-- Edit Form -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <form method="POST" id="editExpenseForm">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Expense Date -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Expense Date <span class="text-red-500">*</span>
                        </label>
                        <input type="date" name="expense_date" 
                               value="<?php echo htmlspecialchars($expense->expense_date); ?>"
                               max="<?php echo date('Y-m-d'); ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500" 
                               required>
                    </div>

                    <!-- Total Amount -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Total Amount (৳) <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="total_amount" step="0.01" min="0.01"
                               value="<?php echo htmlspecialchars($expense->total_amount); ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500" 
                               required>
                    </div>

                    <!-- Category -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Category <span class="text-red-500">*</span>
                        </label>
                        <select name="category_id" id="category_id" 
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500" 
                                required onchange="loadSubcategories(this.value)">
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category->id; ?>" 
                                        <?php echo ($category->id == $expense->category_id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category->category_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Subcategory -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Subcategory <span class="text-red-500">*</span>
                        </label>
                        <select name="subcategory_id" id="subcategory_id"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500" 
                                required>
                            <option value="">Select Subcategory</option>
                            <?php foreach ($subcategories as $subcategory): ?>
                                <option value="<?php echo $subcategory->id; ?>" 
                                        <?php echo ($subcategory->id == $expense->subcategory_id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subcategory->subcategory_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Branch -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Branch</label>
                        <select name="branch_id" 
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                            <option value="">No Specific Branch</option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?php echo $branch->id; ?>" 
                                        <?php echo ($branch->id == $expense->branch_id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($branch->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Handled By Person -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Handled By</label>
                        <input type="text" name="handled_by_person"
                               value="<?php echo htmlspecialchars($expense->handled_by_person ?? ''); ?>"
                               placeholder="Person name"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    </div>

                    <!-- Payment Method -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Payment Method <span class="text-red-500">*</span>
                        </label>
                        <select name="payment_method" id="payment_method"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500" 
                                required onchange="togglePaymentFields()">
                            <option value="">Select Method</option>
                            <option value="cash" <?php echo ($expense->payment_method === 'cash') ? 'selected' : ''; ?>>Cash</option>
                            <option value="bank" <?php echo ($expense->payment_method === 'bank') ? 'selected' : ''; ?>>Bank</option>
                        </select>
                    </div>

                    <!-- Bank Account (shown when payment_method = bank) -->
                    <div id="bank_account_field" style="display: <?php echo ($expense->payment_method === 'bank') ? 'block' : 'none'; ?>;">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Bank Account <span class="text-red-500">*</span>
                        </label>
                        <select name="bank_account_id" id="bank_account_id"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                            <option value="">Select Bank Account</option>
                            <?php foreach ($bankAccounts as $bank): ?>
                                <option value="<?php echo $bank->id; ?>" 
                                        <?php echo ($bank->id == $expense->bank_account_id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($bank->account_display); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Cash Account (shown when payment_method = cash) -->
                    <div id="cash_account_field" style="display: <?php echo ($expense->payment_method === 'cash') ? 'block' : 'none'; ?>;">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Cash Account <span class="text-red-500">*</span>
                        </label>
                        <select name="cash_account_id" id="cash_account_id"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                            <option value="">Select Cash Account</option>
                            <?php foreach ($cashAccounts as $cash): ?>
                                <option value="<?php echo $cash->id; ?>" 
                                        <?php echo ($cash->id == $expense->cash_account_id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cash->account_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Payment Reference -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Payment Reference</label>
                        <input type="text" name="payment_reference"
                               value="<?php echo htmlspecialchars($expense->payment_reference ?? ''); ?>"
                               placeholder="Cheque number, transaction ID, etc."
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    </div>
                </div>

                <!-- Remarks -->
                <div class="mt-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Remarks</label>
                    <textarea name="remarks" rows="3"
                              placeholder="Additional notes or details..."
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"><?php echo htmlspecialchars($expense->remarks ?? ''); ?></textarea>
                </div>

                <!-- Action Buttons -->
                <div class="mt-6 flex justify-end gap-3">
                    <a href="<?php echo url('expense/expense_history.php'); ?>" 
                       class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </a>
                    <button type="submit" 
                            class="px-6 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700 transition-colors">
                        <i class="fas fa-save mr-2"></i>Update Expense
                    </button>
                </div>
            </form>
        </div>

        <!-- Info Box -->
        <div class="mt-6 bg-blue-50 border-l-4 border-blue-400 p-4 rounded">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-info-circle text-blue-400 text-xl"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-blue-700">
                        <strong>Note:</strong> Only pending expenses can be edited. Once approved, expenses cannot be modified directly.
                        Changes will be logged in the expense action history.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Toggle payment account fields based on payment method
function togglePaymentFields() {
    const paymentMethod = document.getElementById('payment_method').value;
    const bankField = document.getElementById('bank_account_field');
    const cashField = document.getElementById('cash_account_field');
    const bankSelect = document.getElementById('bank_account_id');
    const cashSelect = document.getElementById('cash_account_id');
    
    if (paymentMethod === 'bank') {
        bankField.style.display = 'block';
        cashField.style.display = 'none';
        bankSelect.required = true;
        cashSelect.required = false;
        cashSelect.value = '';
    } else if (paymentMethod === 'cash') {
        bankField.style.display = 'none';
        cashField.style.display = 'block';
        bankSelect.required = false;
        cashSelect.required = true;
        bankSelect.value = '';
    } else {
        bankField.style.display = 'none';
        cashField.style.display = 'none';
        bankSelect.required = false;
        cashSelect.required = false;
    }
}

// Load subcategories when category changes
function loadSubcategories(categoryId) {
    const subcategorySelect = document.getElementById('subcategory_id');
    
    if (!categoryId) {
        subcategorySelect.innerHTML = '<option value="">Select Subcategory</option>';
        return;
    }
    
    // Make AJAX request to load subcategories
    fetch('<?php echo url("expense/ajax_get_subcategories.php"); ?>?category_id=' + categoryId)
        .then(response => response.json())
        .then(data => {
            subcategorySelect.innerHTML = '<option value="">Select Subcategory</option>';
            data.forEach(subcat => {
                const option = document.createElement('option');
                option.value = subcat.id;
                option.textContent = subcat.subcategory_name;
                subcategorySelect.appendChild(option);
            });
        })
        .catch(error => {
            console.error('Error loading subcategories:', error);
        });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    togglePaymentFields();
});
</script>

<?php include '../templates/footer.php'; ?>