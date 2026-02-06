<?php
/**
 * Single Page Expense Voucher Creation
 * Self-sufficient - no external AJAX files needed
 */

require_once '../core/init.php';
require_once '../core/classes/ExpenseManager.php';
require_once '../core/classes/AuditLogger.php';  // ← ADD THIS LINE


global $db;

// Check permission
if (!canAccessExpense()) {
    header('Location: ' . url('unauthorized.php'));
    exit();
}

// Get Database instance
$dbInstance = Database::getInstance();

// Create ExpenseManager
$expenseManager = new ExpenseManager($dbInstance);

// =============================================
// HANDLE FORM SUBMISSION
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'expense_date' => $_POST['expense_date'],
        'category_id' => $_POST['category_id'],
        'subcategory_id' => $_POST['subcategory_id'],
        'handled_by_person' => $_POST['handled_by_person'] ?? null,
        'employee_id' => $_POST['employee_id'] ?? null,
        'unit_quantity' => $_POST['unit_quantity'] ?? 0,
        'per_unit_cost' => $_POST['per_unit_cost'] ?? 0,
        'total_amount' => $_POST['total_amount'],
        'remarks' => $_POST['remarks'] ?? null,
        'payment_method' => $_POST['payment_method'],
        'bank_account_id' => $_POST['bank_account_id'] ?? null,
        'cash_account_id' => $_POST['cash_account_id'] ?? null,
        'payment_reference' => $_POST['payment_reference'] ?? null,
        'branch_id' => $_POST['branch_id'] ?? null
    ];

    $result = $expenseManager->createExpenseVoucher($data);

    if ($result['success']) {
    // Log the expense creation for audit trail
    try {
        // Get category name for better description
        $categoryQuery = $db->query("SELECT category_name FROM expense_categories WHERE id = ?", [$data['category_id']]);
        $categoryName = $categoryQuery->first()->category_name ?? 'Unknown Category';
        
        AuditLogger::logExpense('created', $result['voucher_id'], $result['voucher_number'], [
            'description' => "Created expense voucher for $categoryName",
            'data' => [
                'amount' => $data['total_amount'],
                'category_id' => $data['category_id'],
                'category_name' => $categoryName,
                'payment_method' => $data['payment_method'],
                'branch_id' => $data['branch_id'],
                'handled_by' => $data['handled_by_person'] ?? null,
                'employee_id' => $data['employee_id'] ?? null
            ]
        ]);
    } catch (Exception $e) {
        // Don't break if logging fails
        error_log("Audit log failed: " . $e->getMessage());
    }
    
    $_SESSION['success_flash'] = $result['message'];
    header('Location: ' . url('expense/expense_voucher_list.php'));
    exit();
        } else {
            $_SESSION['error_flash'] = $result['message'];
    }
}

// =============================================
// LOAD ALL DATA
// =============================================

// Get all categories
$categories = $expenseManager->getAllCategories();

// Get ALL subcategories (we'll filter by JavaScript)
$allSubcategories = $db->query("
    SELECT id, category_id, subcategory_name, unit_of_measurement 
    FROM expense_subcategories 
    WHERE is_active = 1 
    ORDER BY subcategory_name ASC
")->results();

// Get branches
$branches = $expenseManager->getAllBranches();

// Get bank accounts
$bankAccounts = $expenseManager->getAllBankAccounts();

// Get cash accounts
$cashAccounts = $expenseManager->getAllCashAccounts();

// Get employees
$employees = $expenseManager->getAllEmployees();

$pageTitle = "Create Expense Voucher";
require_once '../templates/header.php';
?>

<div class="container mx-auto px-4 py-6 max-w-5xl">
    
    <!-- Page Header -->
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">
            <i class="fas fa-plus-circle text-primary-600"></i> Create Expense Voucher
        </h1>
        <p class="text-gray-600 mt-2">Create a new expense voucher</p>
    </div>

    <?php echo display_message(); ?>

    <!-- Form -->
    <form method="POST" id="expenseForm" class="bg-white rounded-lg shadow-md p-6">
        
        <!-- Basic Information -->
        <div class="mb-6">
            <h2 class="text-xl font-semibold text-gray-900 mb-4 border-b pb-2">
                <i class="fas fa-info-circle text-blue-500"></i> Basic Information
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Expense Date <span class="text-red-500">*</span>
                    </label>
                    <input type="date" name="expense_date" required
                           value="<?php echo date('Y-m-d'); ?>"
                           max="<?php echo date('Y-m-d'); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Branch <span class="text-red-500">*</span>
                    </label>
                    <select name="branch_id" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Select Branch</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?php echo $branch->id; ?>">
                                <?php echo htmlspecialchars($branch->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Expense Details -->
        <div class="mb-6">
            <h2 class="text-xl font-semibold text-gray-900 mb-4 border-b pb-2">
                <i class="fas fa-file-invoice-dollar text-green-500"></i> Expense Details
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Category <span class="text-red-500">*</span>
                    </label>
                    <select name="category_id" id="category_id" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category->id; ?>">
                                <?php echo htmlspecialchars($category->category_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Subcategory <span class="text-red-500">*</span>
                    </label>
                    <select name="subcategory_id" id="subcategory_id" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Select Category First</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Quantity
                    </label>
                    <input type="number" name="unit_quantity" id="unit_quantity" 
                           step="0.0001" min="0" value="0"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Per Unit Cost
                    </label>
                    <input type="number" name="per_unit_cost" id="per_unit_cost" 
                           step="0.01" min="0" value="0"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Total Amount <span class="text-red-500">*</span>
                    </label>
                    <input type="number" name="total_amount" id="total_amount" 
                           step="0.01" min="0.01" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 text-lg font-semibold">
                    <p class="text-sm text-gray-500 mt-1">
                        <i class="fas fa-info-circle"></i> Auto-calculates from quantity × unit cost, or enter manually
                    </p>
                </div>
            </div>
        </div>

        <!-- Payment Information -->
        <div class="mb-6">
            <h2 class="text-xl font-semibold text-gray-900 mb-4 border-b pb-2">
                <i class="fas fa-credit-card text-purple-500"></i> Payment Information
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Payment Method <span class="text-red-500">*</span>
                    </label>
                    <select name="payment_method" id="payment_method" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Select Method</option>
                        <option value="cash">💵 Cash</option>
                        <option value="bank">🏦 Bank Transfer</option>
                    </select>
                </div>

                <div id="bank_account_div" style="display: none;">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Bank Account <span class="text-red-500">*</span>
                    </label>
                    <select name="bank_account_id" id="bank_account_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Select Bank Account</option>
                        <?php foreach ($bankAccounts as $account): ?>
                            <option value="<?php echo $account->id; ?>">
                                <?php echo htmlspecialchars($account->bank_name . ' - ' . $account->account_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="cash_account_div" style="display: none;">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Cash Account <span class="text-red-500">*</span>
                    </label>
                    <select name="cash_account_id" id="cash_account_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Select Cash Account</option>
                        <?php foreach ($cashAccounts as $account): ?>
                            <option value="<?php echo $account->id; ?>">
                                <?php echo htmlspecialchars($account->account_name); ?>
                                <?php if (isset($account->branch_name)): ?>
                                    (<?php echo htmlspecialchars($account->branch_name); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Payment Reference
                    </label>
                    <input type="text" name="payment_reference"
                           placeholder="Check number, transaction ID, receipt number, etc."
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>
        </div>

        <!-- Handler Information -->
        <div class="mb-6">
            <h2 class="text-xl font-semibold text-gray-900 mb-4 border-b pb-2">
                <i class="fas fa-user-tie text-orange-500"></i> Handler Information (Optional)
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Handled By Person
                    </label>
                    <input type="text" name="handled_by_person"
                           placeholder="Name of person who handled this expense"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Employee
                    </label>
                    <select name="employee_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Select Employee</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?php echo $employee->id; ?>">
                                <?php echo htmlspecialchars($employee->first_name . ' ' . $employee->last_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Remarks -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">
                <i class="fas fa-comment-alt text-gray-500"></i> Remarks / Description
            </label>
            <textarea name="remarks" rows="3"
                      placeholder="Additional notes, purpose of expense, etc..."
                      class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500"></textarea>
        </div>

        <!-- Form Actions -->
        <div class="flex justify-end space-x-3 pt-4 border-t">
            <a href="<?php echo url('expense/expense_voucher_list.php'); ?>"
               class="px-6 py-3 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition font-medium">
                <i class="fas fa-times mr-2"></i>Cancel
            </a>
            <button type="submit"
                    class="px-6 py-3 bg-gradient-to-r from-blue-500 to-blue-600 text-white rounded-md hover:from-blue-600 hover:to-blue-700 transition shadow-md font-medium">
                <i class="fas fa-check mr-2"></i>Create Expense Voucher
            </button>
        </div>
    </form>

</div>

<!-- JavaScript - All in one place -->
<script>
// Embed all subcategories data directly in the page
const allSubcategories = <?php echo json_encode($allSubcategories); ?>;

$(document).ready(function() {
    
    // =============================================
    // Load subcategories when category changes
    // =============================================
    $('#category_id').on('change', function() {
        const categoryId = parseInt($(this).val());
        const subcategorySelect = $('#subcategory_id');
        
        subcategorySelect.html('<option value="">Select Subcategory</option>');
        
        if (categoryId) {
            // Filter subcategories by category_id
            const filtered = allSubcategories.filter(sub => 
                parseInt(sub.category_id) === categoryId
            );
            
            if (filtered.length > 0) {
                filtered.forEach(function(item) {
                    const text = item.subcategory_name + 
                                (item.unit_of_measurement ? ' (' + item.unit_of_measurement + ')' : '');
                    subcategorySelect.append(
                        $('<option></option>')
                            .val(item.id)
                            .text(text)
                    );
                });
            } else {
                subcategorySelect.html('<option value="">No subcategories available</option>');
            }
        } else {
            subcategorySelect.html('<option value="">Select Category First</option>');
        }
    });

    // =============================================
    // Auto-calculate total amount
    // =============================================
    function calculateTotal() {
        const quantity = parseFloat($('#unit_quantity').val()) || 0;
        const unitCost = parseFloat($('#per_unit_cost').val()) || 0;
        
        if (quantity > 0 && unitCost > 0) {
            const total = quantity * unitCost;
            $('#total_amount').val(total.toFixed(2));
        }
    }

    $('#unit_quantity, #per_unit_cost').on('input', calculateTotal);

    // =============================================
    // Show/hide payment account fields
    // =============================================
    $('#payment_method').on('change', function() {
        const method = $(this).val();
        
        // Hide both
        $('#bank_account_div').hide();
        $('#cash_account_div').hide();
        $('#bank_account_id').prop('required', false);
        $('#cash_account_id').prop('required', false);
        
        // Show selected
        if (method === 'bank') {
            $('#bank_account_div').show();
            $('#bank_account_id').prop('required', true);
        } else if (method === 'cash') {
            $('#cash_account_div').show();
            $('#cash_account_id').prop('required', true);
        }
    });

    // =============================================
    // Form validation before submit
    // =============================================
    $('#expenseForm').on('submit', function(e) {
        const totalAmount = parseFloat($('#total_amount').val());
        
        if (!totalAmount || totalAmount <= 0) {
            e.preventDefault();
            alert('⚠️ Please enter a valid total amount greater than 0');
            $('#total_amount').focus();
            return false;
        }
        
        // Show loading indicator
        const submitBtn = $(this).find('button[type="submit"]');
        submitBtn.prop('disabled', true);
        submitBtn.html('<i class="fas fa-spinner fa-spin mr-2"></i>Creating...');
    });

    // =============================================
    // Number input formatting
    // =============================================
    $('input[type="number"]').on('focus', function() {
        $(this).select();
    });

});
</script>

<style>
/* Custom styles for this page */
.form-section {
    background: linear-gradient(to right, #f8f9fa, #ffffff);
    border-left: 4px solid #3b82f6;
}

input:focus, select:focus, textarea:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

button[type="submit"]:hover {
    transform: translateY(-1px);
}

/* Loading animation */
@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>

<?php require_once '../templates/footer.php'; ?>