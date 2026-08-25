<?php
require_once '../core/init.php';

global $db;

// Only Superadmin, admin, and Accounts can create expense vouchers
restrict_access(['Superadmin', 'admin', 'Accounts']);

$pageTitle = "Create Expense Voucher";

require_once '../core/classes/ExpenseManager.php';

$currentUser = getCurrentUser();
$expenseManager = new ExpenseManager($db, $currentUser['id']);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['ajax'])) {
    $result = $expenseManager->createExpenseVoucher($_POST);
    
    if ($result['success']) {
        // ============================================
        // TELEGRAM NOTIFICATION - EXPENSE VOUCHER CREATED
        // ============================================
        try {
            if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
                require_once '../core/classes/TelegramNotifier.php';
                $telegram = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);
                
                // Get complete voucher details
                $voucher = $expenseManager->getExpenseVoucherForNotification($result['voucher_id']);
                
                if ($voucher) {
                    // Prepare message
                    $message = "🧾 *EXPENSE VOUCHER CREATED*\n\n";
                    $message .= "📋 *Voucher:* `{$voucher->voucher_number}`\n";
                    $message .= "📅 *Date:* " . date('d M Y', strtotime($voucher->expense_date)) . "\n\n";
                    
                    $message .= "💰 *Amount:* ৳" . number_format($voucher->total_amount, 2) . "\n\n";
                    
                    $message .= "📂 *Category:* {$voucher->category_name}\n";
                    $message .= "📌 *Subcategory:* {$voucher->subcategory_name}";
                    if ($voucher->unit_of_measurement) {
                        $message .= " ({$voucher->unit_of_measurement})";
                    }
                    $message .= "\n\n";
                    
                    if ($voucher->unit_quantity && $voucher->per_unit_cost) {
                        $message .= "📊 *Calculation:*\n";
                        $message .= "   • Quantity: " . number_format($voucher->unit_quantity, 2) . " {$voucher->unit_of_measurement}\n";
                        $message .= "   • Rate: ৳" . number_format($voucher->per_unit_cost, 2) . "\n";
                        $message .= "   • Total: ৳" . number_format($voucher->total_amount, 2) . "\n\n";
                    }
                    
                    $message .= "💳 *Payment:* " . ucfirst($voucher->payment_method) . "\n";
                    $message .= "🏦 *Account:* {$voucher->payment_account_name}\n";
                    
                    if ($voucher->payment_reference) {
                        $message .= "🔖 *Reference:* {$voucher->payment_reference}\n";
                    }
                    
                    if ($voucher->branch_name) {
                        $message .= "🏢 *Branch:* {$voucher->branch_name}\n";
                    }
                    
                    if ($voucher->handled_by_person) {
                        $message .= "👤 *Handled By:* {$voucher->handled_by_person}\n";
                    }
                    
                    if ($voucher->employee_name) {
                        $message .= "👨‍💼 *Employee:* {$voucher->employee_name}\n";
                    }
                    
                    if ($voucher->remarks) {
                        $message .= "\n📝 *Remarks:* " . substr($voucher->remarks, 0, 100) . (strlen($voucher->remarks) > 100 ? '...' : '') . "\n";
                    }
                    
                    $message .= "\n✅ *Created By:* {$voucher->created_by_name}";
                    
                    // Send notification
                    $notif_result = $telegram->sendMessage($message);
                    
                    if ($notif_result['success']) {
                        error_log("✓ Telegram expense voucher notification sent: " . $voucher->voucher_number);
                    } else {
                        error_log("✗ Telegram expense voucher notification failed: " . json_encode($notif_result['response']));
                    }
                }
            }
        } catch (Exception $e) {
            error_log("✗ Telegram expense voucher notification error: " . $e->getMessage());
        }
        // END TELEGRAM NOTIFICATION
        
        $_SESSION['success_flash'] = $result['message'] . ' (Voucher: ' . $result['voucher_number'] . ')';
        header('Location: ' . url('expense/expense_history.php'));
        exit;
    } else {
        $_SESSION['error_flash'] = $result['message'];
    }
}

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] === 'get_subcategories') {
        $category_id = $_GET['category_id'] ?? 0;
        $subcategories = $expenseManager->getSubcategoriesByCategory($category_id);
        echo json_encode(['success' => true, 'subcategories' => $subcategories]);
        exit;
    }
    
    if ($_GET['ajax'] === 'get_subcategory_details') {
        $subcategory_id = $_GET['subcategory_id'] ?? 0;
        $subcategory = $expenseManager->getSubcategoryById($subcategory_id);
        echo json_encode(['success' => true, 'subcategory' => $subcategory]);
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// Load dropdown data
$categories = $expenseManager->getAllCategories();
$bank_accounts = $expenseManager->getAllBankAccounts();
$cash_accounts = $expenseManager->getAllCashAccounts();
$branches = $expenseManager->getAllBranches();
$employees = $expenseManager->getAllEmployees();

require_once '../templates/header.php';
?>

<div class="container mx-auto px-4 py-6">
    <!-- Page Header -->
    <div class="mb-6">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Create Expense Voucher</h1>
                <p class="text-gray-600 mt-1">Record a new expense transaction</p>
            </div>
            <a href="<?php echo url('expense/expense_history.php'); ?>" 
               class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-arrow-left mr-2"></i>
                Back to History
            </a>
        </div>
    </div>

    <?php echo display_message(); ?>

    <!-- Expense Form -->
    <div class="bg-white rounded-lg shadow-md p-6">
        <form method="POST" id="expenseForm" onsubmit="return validateForm()">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                
                <!-- Expense Date -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Expense Date <span class="text-red-500">*</span>
                    </label>
                    <input type="date" name="expense_date" id="expense_date" required
                           value="<?php echo date('Y-m-d'); ?>"
                           max="<?php echo date('Y-m-d'); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                </div>

                <!-- Branch -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Branch <span class="text-gray-400 text-xs">(Optional)</span>
                    </label>
                    <select name="branch_id" id="branch_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                        <option value="">-- Select Branch --</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?php echo $branch->id; ?>">
                                <?php echo htmlspecialchars($branch->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Category -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Expense Category <span class="text-red-500">*</span>
                    </label>
                    <select name="category_id" id="category_id" required onchange="loadSubcategories()"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                        <option value="">-- Select Category --</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category->id; ?>">
                                <?php echo htmlspecialchars($category->category_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Subcategory -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Expense Subcategory <span class="text-red-500">*</span>
                    </label>
                    <select name="subcategory_id" id="subcategory_id" required onchange="loadSubcategoryDetails()"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500"
                            disabled>
                        <option value="">-- Select Category First --</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1" id="unit_display"></p>
                </div>

                <!-- Handled By Person -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Handled By
                    </label>
                    <input type="text" name="handled_by_person" id="handled_by_person"
                           placeholder="Person who handled this transaction"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                </div>

                <!-- Employee -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Employee <span class="text-gray-400 text-xs">(Optional)</span>
                    </label>
                    <select name="employee_id" id="employee_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                        <option value="">-- Select Employee --</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?php echo $employee->id; ?>">
                                <?php echo htmlspecialchars($employee->first_name . ' ' . $employee->last_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Amount Calculation Section -->
            <div class="mt-6 border-t pt-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Amount Details</h3>
                
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
                    <div class="flex items-start">
                        <i class="fas fa-info-circle text-blue-600 mt-1 mr-2"></i>
                        <div class="text-sm text-blue-800">
                            <p class="font-medium">Choose calculation method:</p>
                            <ul class="list-disc list-inside mt-1 space-y-1">
                                <li><strong>Unit-based:</strong> Quantity × Per Unit Cost (e.g., 100 Liters × ৳50 = ৳5,000)</li>
                                <li><strong>Direct amount:</strong> Enter total amount directly (leave quantity/rate empty)</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <!-- Unit Quantity -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Quantity
                        </label>
                        <input type="number" step="0.0001" name="unit_quantity" id="unit_quantity"
                               placeholder="0.00"
                               onkeyup="calculateTotal()"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                    </div>

                    <!-- Per Unit Cost -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Per Unit Cost (৳)
                        </label>
                        <input type="number" step="0.0001" name="per_unit_cost" id="per_unit_cost"
                               placeholder="0.00"
                               onkeyup="calculateTotal()"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                    </div>

                    <!-- Total Amount -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Total Amount (৳) <span class="text-red-500">*</span>
                        </label>
                        <input type="number" step="0.01" name="total_amount" id="total_amount" required
                               placeholder="0.00"
                               onkeyup="clearUnitFields()"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500 bg-yellow-50">
                        <p class="text-xs text-gray-500 mt-1">Calculated or enter directly</p>
                    </div>
                </div>
            </div>

            <!-- Payment Details Section -->
            <div class="mt-6 border-t pt-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Payment Details</h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Payment Method -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Payment Method <span class="text-red-500">*</span>
                        </label>
                        <div class="grid grid-cols-2 gap-3">
                            <button type="button" onclick="selectPaymentMethod('bank')" id="btn_bank"
                                    class="px-4 py-3 border-2 border-gray-300 rounded-lg hover:border-primary-500 transition">
                                <i class="fas fa-university text-2xl text-gray-600 mb-1"></i>
                                <p class="text-sm font-medium">Bank</p>
                            </button>
                            <button type="button" onclick="selectPaymentMethod('cash')" id="btn_cash"
                                    class="px-4 py-3 border-2 border-gray-300 rounded-lg hover:border-primary-500 transition">
                                <i class="fas fa-money-bill-wave text-2xl text-gray-600 mb-1"></i>
                                <p class="text-sm font-medium">Cash</p>
                            </button>
                        </div>
                        <input type="hidden" name="payment_method" id="payment_method" required>
                    </div>

                    <!-- Payment Reference -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Payment Reference
                        </label>
                        <input type="text" name="payment_reference" id="payment_reference"
                               placeholder="Cheque number, transaction ID, etc."
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                    </div>
                </div>

                <!-- Bank Account (shown when bank selected) -->
                <div id="bank_section" class="mt-4 hidden">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Bank Account <span class="text-red-500">*</span>
                    </label>
                    <select name="bank_account_id" id="bank_account_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                        <option value="">-- Select Bank Account --</option>
                        <?php foreach ($bank_accounts as $account): ?>
                            <option value="<?php echo $account->id; ?>" data-balance="<?php echo $account->current_balance; ?>">
                                <?php echo htmlspecialchars($account->bank_name . ' - ' . $account->account_name); ?>
                                (Balance: ৳<?php echo number_format($account->current_balance, 2); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-red-500 mt-1 hidden" id="bank_balance_warning">
                        <i class="fas fa-exclamation-triangle"></i> Warning: Amount exceeds available balance
                    </p>
                </div>

                <!-- Cash Account (shown when cash selected) -->
                <div id="cash_section" class="mt-4 hidden">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Cash Account <span class="text-red-500">*</span>
                    </label>
                    <select name="cash_account_id" id="cash_account_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500">
                        <option value="">-- Select Cash Account --</option>
                        <?php foreach ($cash_accounts as $account): ?>
                            <option value="<?php echo $account->id; ?>" data-balance="<?php echo $account->current_balance; ?>">
                                <?php echo htmlspecialchars($account->account_name); ?>
                                <?php if ($account->branch_name): ?>
                                    - <?php echo htmlspecialchars($account->branch_name); ?>
                                <?php endif; ?>
                                (Balance: ৳<?php echo number_format($account->current_balance, 2); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-red-500 mt-1 hidden" id="cash_balance_warning">
                        <i class="fas fa-exclamation-triangle"></i> Warning: Amount exceeds available balance
                    </p>
                </div>
            </div>

            <!-- Remarks -->
            <div class="mt-6 border-t pt-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Remarks
                </label>
                <textarea name="remarks" id="remarks" rows="3"
                          placeholder="Additional notes about this expense..."
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500"></textarea>
            </div>

            <!-- Summary Box -->
            <div class="mt-6 bg-gray-50 border border-gray-200 rounded-lg p-4">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-sm text-gray-600">Total Expense Amount</p>
                        <p class="text-3xl font-bold text-gray-900" id="total_display">৳0.00</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-gray-600" id="payment_summary">Select payment method</p>
                        <p class="text-sm font-medium text-gray-900" id="account_summary"></p>
                    </div>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="mt-6 flex justify-end space-x-3">
                <a href="<?php echo url('expense/expense_history.php'); ?>"
                   class="px-6 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300">
                    Cancel
                </a>
                <button type="submit" 
                        class="px-6 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700 flex items-center">
                    <i class="fas fa-save mr-2"></i>
                    Create Expense Voucher
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let currentUnitOfMeasurement = '';

// Load subcategories when category changes
async function loadSubcategories() {
    const categoryId = document.getElementById('category_id').value;
    const subcategorySelect = document.getElementById('subcategory_id');
    
    subcategorySelect.innerHTML = '<option value="">-- Loading... --</option>';
    subcategorySelect.disabled = true;
    
    if (!categoryId) {
        subcategorySelect.innerHTML = '<option value="">-- Select Category First --</option>';
        return;
    }
    
    try {
        const response = await fetch(`?ajax=get_subcategories&category_id=${categoryId}`);
        const result = await response.json();
        
        if (result.success && result.subcategories.length > 0) {
            subcategorySelect.innerHTML = '<option value="">-- Select Subcategory --</option>';
            result.subcategories.forEach(sub => {
                const option = document.createElement('option');
                option.value = sub.id;
                option.textContent = sub.subcategory_name;
                if (sub.unit_of_measurement) {
                    option.textContent += ` (${sub.unit_of_measurement})`;
                }
                subcategorySelect.appendChild(option);
            });
            subcategorySelect.disabled = false;
        } else {
            subcategorySelect.innerHTML = '<option value="">-- No Subcategories Available --</option>';
        }
    } catch (error) {
        console.error('Error loading subcategories:', error);
        subcategorySelect.innerHTML = '<option value="">-- Error Loading --</option>';
    }
}

// Load subcategory details (unit of measurement)
async function loadSubcategoryDetails() {
    const subcategoryId = document.getElementById('subcategory_id').value;
    const unitDisplay = document.getElementById('unit_display');
    
    if (!subcategoryId) {
        unitDisplay.textContent = '';
        currentUnitOfMeasurement = '';
        return;
    }
    
    try {
        const response = await fetch(`?ajax=get_subcategory_details&subcategory_id=${subcategoryId}`);
        const result = await response.json();
        
        if (result.success && result.subcategory) {
            const sub = result.subcategory;
            currentUnitOfMeasurement = sub.unit_of_measurement || '';
            
            if (currentUnitOfMeasurement) {
                unitDisplay.textContent = `Unit: ${currentUnitOfMeasurement}`;
                document.getElementById('unit_quantity').placeholder = `Quantity in ${currentUnitOfMeasurement}`;
            } else {
                unitDisplay.textContent = 'Enter total amount directly';
                document.getElementById('unit_quantity').placeholder = 'Quantity (optional)';
            }
        }
    } catch (error) {
        console.error('Error loading subcategory details:', error);
    }
}

// Calculate total from quantity and rate
function calculateTotal() {
    const quantity = parseFloat(document.getElementById('unit_quantity').value) || 0;
    const rate = parseFloat(document.getElementById('per_unit_cost').value) || 0;
    
    if (quantity > 0 && rate > 0) {
        const total = quantity * rate;
        document.getElementById('total_amount').value = total.toFixed(2);
        updateTotalDisplay();
    }
}

// Clear unit fields when total is entered directly
function clearUnitFields() {
    const total = parseFloat(document.getElementById('total_amount').value) || 0;
    if (total > 0) {
        updateTotalDisplay();
    }
}

// Update total display
function updateTotalDisplay() {
    const total = parseFloat(document.getElementById('total_amount').value) || 0;
    document.getElementById('total_display').textContent = '৳' + total.toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    checkBalance();
}

// Select payment method
function selectPaymentMethod(method) {
    document.getElementById('payment_method').value = method;
    
    document.getElementById('btn_bank').classList.remove('border-primary-600', 'bg-primary-50');
    document.getElementById('btn_cash').classList.remove('border-primary-600', 'bg-primary-50');
    
    if (method === 'bank') {
        document.getElementById('btn_bank').classList.add('border-primary-600', 'bg-primary-50');
        document.getElementById('bank_section').classList.remove('hidden');
        document.getElementById('cash_section').classList.add('hidden');
        document.getElementById('bank_account_id').required = true;
        document.getElementById('cash_account_id').required = false;
        document.getElementById('payment_summary').textContent = 'Payment via Bank';
    } else {
        document.getElementById('btn_cash').classList.add('border-primary-600', 'bg-primary-50');
        document.getElementById('cash_section').classList.remove('hidden');
        document.getElementById('bank_section').classList.add('hidden');
        document.getElementById('cash_account_id').required = true;
        document.getElementById('bank_account_id').required = false;
        document.getElementById('payment_summary').textContent = 'Payment via Cash';
    }
    
    updateAccountSummary();
}

// Update account summary
function updateAccountSummary() {
    const method = document.getElementById('payment_method').value;
    let accountName = '';
    
    if (method === 'bank') {
        const select = document.getElementById('bank_account_id');
        accountName = select.options[select.selectedIndex]?.text || '';
    } else if (method === 'cash') {
        const select = document.getElementById('cash_account_id');
        accountName = select.options[select.selectedIndex]?.text || '';
    }
    
    document.getElementById('account_summary').textContent = accountName;
    checkBalance();
}

// Check if amount exceeds balance
function checkBalance() {
    const total = parseFloat(document.getElementById('total_amount').value) || 0;
    const method = document.getElementById('payment_method').value;
    
    if (!method || total === 0) return;
    
    let balance = 0;
    let warningElement;
    
    if (method === 'bank') {
        const select = document.getElementById('bank_account_id');
        balance = parseFloat(select.options[select.selectedIndex]?.dataset.balance || 0);
        warningElement = document.getElementById('bank_balance_warning');
    } else {
        const select = document.getElementById('cash_account_id');
        balance = parseFloat(select.options[select.selectedIndex]?.dataset.balance || 0);
        warningElement = document.getElementById('cash_balance_warning');
    }
    
    if (total > balance) {
        warningElement.classList.remove('hidden');
    } else {
        warningElement.classList.add('hidden');
    }
}

// Validate form before submission
function validateForm() {
    const total = parseFloat(document.getElementById('total_amount').value) || 0;
    const method = document.getElementById('payment_method').value;
    
    if (total <= 0) {
        alert('Please enter a valid total amount');
        return false;
    }
    
    if (!method) {
        alert('Please select a payment method');
        return false;
    }
    
    if (method === 'bank' && !document.getElementById('bank_account_id').value) {
        alert('Please select a bank account');
        return false;
    }
    
    if (method === 'cash' && !document.getElementById('cash_account_id').value) {
        alert('Please select a cash account');
        return false;
    }
    
    // Confirm if amount exceeds balance
    let balance = 0;
    if (method === 'bank') {
        const select = document.getElementById('bank_account_id');
        balance = parseFloat(select.options[select.selectedIndex]?.dataset.balance || 0);
    } else {
        const select = document.getElementById('cash_account_id');
        balance = parseFloat(select.options[select.selectedIndex]?.dataset.balance || 0);
    }
    
    if (total > balance) {
        return confirm('Warning: The expense amount exceeds the available balance. Do you want to continue?');
    }
    
    return true;
}

// Event listeners
document.getElementById('bank_account_id').addEventListener('change', updateAccountSummary);
document.getElementById('cash_account_id').addEventListener('change', updateAccountSummary);
document.getElementById('total_amount').addEventListener('keyup', updateTotalDisplay);
</script>

<?php require_once '../templates/footer.php'; ?>