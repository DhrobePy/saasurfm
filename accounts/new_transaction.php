<?php
require_once '../core/init.php';

// --- SECURITY ---
$allowed_roles = [
    'Superadmin',
    'admin',
    'Accounts',
    'accounts-rampura',
    'accounts-srg',
    'accounts-demra',
    'accountspos-demra',
    'accountspos-srg',
];
restrict_access($allowed_roles);

// Get the $db instance
global $db;
$pageTitle = 'Record New Transaction';
$error = null;
$success = null;

// Account arrays
$asset_accounts = [];
$income_accounts = [];
$expense_accounts = [];
$liability_accounts = [];
$all_accounts = [];
$employees = [];

// Get PDO instance early
$pdo = null;
try {
    $pdo = $db->getPdo();
} catch (Exception $e) {
    error_log("Database connection error in new_transaction.php: " . $e->getMessage());
    $error = "Database connection error. Please try again later.";
}

// --- DATA: GET ACCOUNTS & EMPLOYEES ---
if (!$error) {
    try {
        // Get all active accounts
        // Note: account_type_group is used for display/categorization but we filter by account_type
        $all_accounts = $db->query(
            "SELECT id, name, account_type, normal_balance, account_type_group 
             FROM chart_of_accounts
             WHERE status = 'active' AND is_active = 1
             ORDER BY name ASC"
        )->results();

        // Debug: Log the accounts retrieved
        error_log("Total accounts retrieved: " . count($all_accounts));

        // Categorize accounts by their account_type (ENUM value)
        foreach ($all_accounts as $account) {
            $type = $account->account_type;
            
            // Asset accounts (Bank, Cash, Petty Cash, Accounts Receivable, etc.)
            if (in_array($type, ['Bank', 'Petty Cash', 'Cash', 'Other Current Asset', 'Fixed Asset'])) {
                $asset_accounts[] = $account;
                error_log("Added to assets: " . $account->name . " (Type: " . $type . ")");
            }
            
            // Income/Revenue accounts
            if (in_array($type, ['Revenue', 'Other Income'])) {
                $income_accounts[] = $account;
                error_log("Added to income: " . $account->name . " (Type: " . $type . ")");
            }
            
            // Expense accounts
            if (in_array($type, ['Expense', 'Cost of Goods Sold', 'Other Expense'])) {
                $expense_accounts[] = $account;
                error_log("Added to expenses: " . $account->name . " (Type: " . $type . ")");
            }
            
            // Liability accounts (for future use)
            if (in_array($type, ['Accounts Payable', 'Credit Card', 'Loan', 'Other Liability'])) {
                $liability_accounts[] = $account;
            }
        }

        // Debug: Log counts
        error_log("Asset accounts: " . count($asset_accounts));
        error_log("Income accounts: " . count($income_accounts));
        error_log("Expense accounts: " . count($expense_accounts));

        // Fetch active employees for 'Person Responsible'
        $employees = $db->query(
            "SELECT id, first_name, last_name
             FROM employees 
             WHERE status = 'active'
             ORDER BY first_name ASC, last_name ASC"
        )->results();

    } catch (Exception $e) {
        error_log("Error loading transaction data: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        $error = "Error loading form data. Please contact support.";
    }
}


// --- LOGIC: HANDLE POST REQUEST (PROCESS TRANSACTION) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error && $pdo) {
    try {
        // Start transaction
        $pdo->beginTransaction();

        // Get and validate input
        $transaction_type = $_POST['transaction_type']; // 'income' or 'expense'
        $amount = (float)$_POST['amount'];
        $asset_account_id = (int)$_POST['asset_account_id']; // Bank/Cash account
        $category_account_id = (int)$_POST['category_account_id']; // Income/Expense category
        $responsible_employee_id = !empty($_POST['responsible_employee_id']) ? (int)$_POST['responsible_employee_id'] : null;
        $transaction_date = !empty($_POST['transaction_date']) ? $_POST['transaction_date'] : date('Y-m-d');
        $memo = trim($_POST['memo']);

        // Validation
        if (!in_array($transaction_type, ['income', 'expense'])) {
            throw new Exception("Invalid transaction type selected.");
        }
        
        if ($amount <= 0) {
            throw new Exception("Transaction amount must be greater than zero.");
        }
        
        if (empty($asset_account_id) || empty($category_account_id)) {
            throw new Exception("Please select all required accounts.");
        }
        
        // Validate date format
        $date_obj = DateTime::createFromFormat('Y-m-d', $transaction_date);
        if (!$date_obj || $date_obj->format('Y-m-d') !== $transaction_date) {
            throw new Exception("Invalid date format.");
        }
        
        // Make responsible person mandatory for expenses
        //if ($transaction_type === 'expense' && empty($responsible_employee_id)) {
            //throw new Exception("Please select the employee responsible for this expense.");
        //}

        // Fetch account details for validation and description
        $asset_acc = $db->query(
            "SELECT id, name, account_type, normal_balance FROM chart_of_accounts WHERE id = ? AND status = 'active'", 
            [$asset_account_id]
        )->first();
        
        $cat_acc = $db->query(
            "SELECT id, name, account_type, normal_balance FROM chart_of_accounts WHERE id = ? AND status = 'active'", 
            [$category_account_id]
        )->first();
        
        if (!$asset_acc || !$cat_acc) {
            throw new Exception("One or more selected accounts are invalid or inactive.");
        }

        // Validate account types
        if (!in_array($asset_acc->account_type, ['Bank', 'Cash', 'Petty Cash', 'Other Current Asset'])) {
            throw new Exception("The selected payment account must be a Bank, Cash, or Asset account.");
        }

        if ($transaction_type === 'expense') {
            if (!in_array($cat_acc->account_type, ['Expense', 'Cost of Goods Sold', 'Other Expense'])) {
                throw new Exception("The selected category must be an Expense account.");
            }
        } elseif ($transaction_type === 'income') {
            if (!in_array($cat_acc->account_type, ['Revenue', 'Other Income'])) {
                throw new Exception("The selected category must be a Revenue or Income account.");
            }
        }

        // --- Create Journal Entry ---
        $journal_desc = ucfirst($transaction_type) . ': ' . $cat_acc->name;
        if (!empty($memo)) {
            $journal_desc .= ' - ' . $memo;
        }

        // SCHEMA FIX: Use correct column names from journal_entries table
        $journal_id = $db->insert('journal_entries', [
            'transaction_date' => $transaction_date,
            'description' => substr($journal_desc, 0, 255), // Limit to VARCHAR(255)
            'related_document_type' => 'GeneralTransaction',
            'related_document_id' => null, // No specific document for manual transactions
            'responsible_employee_id' => $responsible_employee_id,
            'created_by_user_id' => $_SESSION['user_id']
        ]);

        if (!$journal_id) {
            throw new Exception("Failed to create journal entry header.");
        }

        // --- Create Transaction Lines (Debit & Credit) ---
        // SCHEMA FIX: transaction_lines uses 'journal_entry_id', 'debit_amount', 'credit_amount'
        
        if ($transaction_type === 'income') {
            // Income Transaction:
            // Debit: Asset Account (Bank/Cash) - Money coming IN
            // Credit: Income Category - Revenue recognized
            
            // Line 1: Debit the Asset Account (increases asset)
            $line1_result = $db->insert('transaction_lines', [
                'journal_entry_id' => $journal_id,
                'account_id' => $asset_account_id,
                'debit_amount' => $amount,
                'credit_amount' => 0.00,
                'description' => $memo ?: 'Income received into ' . $asset_acc->name
            ]);
            
            if (!$line1_result) {
                throw new Exception("Failed to create debit transaction line.");
            }
            
            // Line 2: Credit the Income Category Account (increases revenue)
            $line2_result = $db->insert('transaction_lines', [
                'journal_entry_id' => $journal_id,
                'account_id' => $category_account_id,
                'debit_amount' => 0.00,
                'credit_amount' => $amount,
                'description' => $memo ?: 'Income from ' . $cat_acc->name
            ]);
            
            if (!$line2_result) {
                throw new Exception("Failed to create credit transaction line.");
            }

        } elseif ($transaction_type === 'expense') {
            // Expense Transaction:
            // Debit: Expense Category - Expense recognized
            // Credit: Asset Account (Bank/Cash) - Money going OUT
            
            // Line 1: Debit the Expense Category Account (increases expense)
            $line1_result = $db->insert('transaction_lines', [
                'journal_entry_id' => $journal_id,
                'account_id' => $category_account_id,
                'debit_amount' => $amount,
                'credit_amount' => 0.00,
                'description' => $memo ?: 'Expense for ' . $cat_acc->name
            ]);
            
            if (!$line1_result) {
                throw new Exception("Failed to create debit transaction line.");
            }
            
            // Line 2: Credit the Asset Account (decreases asset)
            $line2_result = $db->insert('transaction_lines', [
                'journal_entry_id' => $journal_id,
                'account_id' => $asset_account_id,
                'debit_amount' => 0.00,
                'credit_amount' => $amount,
                'description' => $memo ?: 'Payment from ' . $asset_acc->name
            ]);
            
            if (!$line2_result) {
                throw new Exception("Failed to create credit transaction line.");
            }
        }

        // Verify the journal entry is balanced
        $balance_check = $db->query(
            "SELECT 
                SUM(debit_amount) as total_debit,
                SUM(credit_amount) as total_credit
             FROM transaction_lines
             WHERE journal_entry_id = ?",
            [$journal_id]
        )->first();

        if ($balance_check->total_debit != $balance_check->total_credit) {
            throw new Exception("Journal entry is not balanced. Transaction aborted.");
        }

        // All good, commit the transaction
        $pdo->commit();
        
        $success_msg = ucfirst($transaction_type) . ' of ৳' . number_format($amount, 2) . ' recorded successfully.';
        $_SESSION['success_flash'] = $success_msg;
        
        // Redirect to clear form and show success message
        header('Location: new_transaction.php');
        exit();

    } catch (Exception $e) {
        // Rollback on any error
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Transaction recording error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        $error = "Transaction Failed: " . $e->getMessage();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$pdo) {
    $error = "Database connection error. Cannot process transaction.";
}


// --- Include Header ---
require_once '../templates/header.php';
?>

<!-- ======================================== -->
<!-- 1. PAGE HEADER -->
<!-- ======================================== -->
<div class="mb-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Record New Transaction</h1>
            <p class="text-lg text-gray-600 mt-1">
                Record income received or expenses paid with proper accounting entries
            </p>
        </div>
        <a href="transactions.php" 
           class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors">
            <i class="fas fa-list mr-2"></i>View All Transactions
        </a>
    </div>
</div>

<!-- ======================================== -->
<!-- 2. DEBUG INFO (Remove in production) -->
<!-- ======================================== -->
<?php if (defined('APP_DEBUG') && APP_DEBUG === true): ?>
<div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
    <h3 class="text-sm font-semibold text-yellow-800 mb-2">Debug Information</h3>
    <div class="text-xs text-yellow-700 space-y-1">
        <p><strong>Asset Accounts Found:</strong> <?php echo count($asset_accounts); ?></p>
        <p><strong>Income Accounts Found:</strong> <?php echo count($income_accounts); ?></p>
        <p><strong>Expense Accounts Found:</strong> <?php echo count($expense_accounts); ?></p>
        <p><strong>Employees Found:</strong> <?php echo count($employees); ?></p>
        <?php if (!empty($asset_accounts)): ?>
            <details class="mt-2">
                <summary class="cursor-pointer font-medium">View Asset Accounts</summary>
                <ul class="ml-4 mt-1">
                    <?php foreach ($asset_accounts as $acc): ?>
                        <li><?php echo htmlspecialchars($acc->name); ?> (ID: <?php echo $acc->id; ?>, Type: <?php echo $acc->account_type; ?>)</li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ======================================== -->
<!-- 3. ERROR / SUCCESS DISPLAY -->
<!-- ======================================== -->
<?php if ($error): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg" role="alert">
        <div class="flex">
            <div class="flex-shrink-0">
                <i class="fas fa-exclamation-circle text-red-500"></i>
            </div>
            <div class="ml-3">
                <p class="font-bold">Error</p>
                <p><?php echo htmlspecialchars($error); ?></p>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (empty($asset_accounts) && !$error): ?>
    <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-6 rounded-r-lg" role="alert">
        <div class="flex">
            <div class="flex-shrink-0">
                <i class="fas fa-exclamation-triangle text-yellow-500"></i>
            </div>
            <div class="ml-3">
                <p class="font-bold">No Bank/Cash Accounts Found</p>
                <p>You need to set up at least one Bank or Cash account before recording transactions.</p>
                <p class="mt-2">
                    <a href="bank_accounts.php" class="text-yellow-800 underline font-medium">
                        Go to Bank Accounts →
                    </a>
                </p>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (empty($income_accounts) && empty($expense_accounts) && !$error): ?>
    <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-6 rounded-r-lg" role="alert">
        <div class="flex">
            <div class="flex-shrink-0">
                <i class="fas fa-exclamation-triangle text-yellow-500"></i>
            </div>
            <div class="ml-3">
                <p class="font-bold">No Income/Expense Categories Found</p>
                <p>You need to set up Revenue and Expense accounts in your Chart of Accounts.</p>
                <p class="mt-2">
                    <a href="chart_of_accounts.php" class="text-yellow-800 underline font-medium">
                        Go to Chart of Accounts →
                    </a>
                </p>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['success_flash'])): ?>
    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-r-lg" role="alert">
        <div class="flex">
            <div class="flex-shrink-0">
                <i class="fas fa-check-circle text-green-500"></i>
            </div>
            <div class="ml-3">
                <p class="font-bold">Success</p>
                <p><?php echo htmlspecialchars($_SESSION['success_flash']); ?></p>
            </div>
        </div>
    </div>
    <?php unset($_SESSION['success_flash']); ?>
<?php endif; ?>


<!-- ======================================== -->
<!-- 4. "SMART" TRANSACTION FORM with Alpine.js -->
<!-- ======================================== -->
<?php if (!empty($asset_accounts) && (!empty($income_accounts) || !empty($expense_accounts))): ?>
<div class="bg-white rounded-lg shadow-md p-6">
    
    <form action="new_transaction.php" method="POST" class="space-y-6" id="transactionForm">

        <!-- Transaction Type Selector -->
        <div class="border-b border-gray-200 pb-5">
            <label class="block text-sm font-semibold text-gray-900 mb-3">
                Transaction Type <span class="text-red-500">*</span>
            </label>
            <fieldset>
                <legend class="sr-only">Select transaction type</legend>
                <div class="grid grid-cols-2 gap-4">
                    <!-- Expense Option -->
                    <label class="relative flex cursor-pointer rounded-lg border border-gray-300 bg-white p-4 hover:border-primary-500 focus:outline-none transition-colors transaction-type-option">
                        <input type="radio" 
                               name="transaction_type" 
                               value="expense" 
                               class="sr-only transaction-type-radio"
                               checked>
                        <div class="flex flex-1">
                            <div class="flex flex-col">
                                <div class="flex items-center">
                                    <i class="fas fa-minus-circle text-red-600 text-xl mr-3"></i>
                                    <span class="block text-sm font-medium text-gray-900">Expense / Payment</span>
                                </div>
                                <span class="mt-1 flex items-center text-xs text-gray-500">
                                    Money going out (rent, supplies, etc.)
                                </span>
                            </div>
                        </div>
                        <i class="fas fa-check-circle text-primary-600 text-xl absolute top-4 right-4 check-icon hidden"></i>
                    </label>

                    <!-- Income Option -->
                    <label class="relative flex cursor-pointer rounded-lg border border-gray-300 bg-white p-4 hover:border-primary-500 focus:outline-none transition-colors transaction-type-option">
                        <input type="radio" 
                               name="transaction_type" 
                               value="income" 
                               class="sr-only transaction-type-radio">
                        <div class="flex flex-1">
                            <div class="flex flex-col">
                                <div class="flex items-center">
                                    <i class="fas fa-plus-circle text-green-600 text-xl mr-3"></i>
                                    <span class="block text-sm font-medium text-gray-900">Income / Receipt</span>
                                </div>
                                <span class="mt-1 flex items-center text-xs text-gray-500">
                                    Money coming in (sales, interest, etc.)
                                </span>
                            </div>
                        </div>
                        <i class="fas fa-check-circle text-primary-600 text-xl absolute top-4 right-4 check-icon hidden"></i>
                    </label>
                </div>
            </fieldset>
        </div>


        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Amount -->
            <div>
                <label for="amount" class="block text-sm font-medium text-gray-700 mb-1">
                    Amount (৳) <span class="text-red-500">*</span>
                </label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <span class="text-gray-500 sm:text-sm">৳</span>
                    </div>
                    <input type="number" 
                           step="0.01" 
                           id="amount" 
                           name="amount" 
                           required 
                           min="0.01"
                           class="w-full pl-8 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors"
                           placeholder="0.00">
                </div>
                <p class="mt-1 text-xs text-gray-500">Enter the transaction amount</p>
            </div>

            <!-- Transaction Date -->
            <div>
                <label for="transaction_date" class="block text-sm font-medium text-gray-700 mb-1">
                    Transaction Date <span class="text-red-500">*</span>
                </label>
                <input type="date" 
                       id="transaction_date" 
                       name="transaction_date"
                       required
                       max="<?php echo date('Y-m-d'); ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors"
                       value="<?php echo date('Y-m-d'); ?>">
                <p class="mt-1 text-xs text-gray-500">When did this transaction occur?</p>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Asset Account (Bank/Cash) -->
            <div>
                <label for="asset_account_id" class="block text-sm font-medium text-gray-700 mb-1">
                    <span id="asset_label">Paid From</span> <span class="text-red-500">*</span>
                </label>
                <select id="asset_account_id" 
                        name="asset_account_id" 
                        required 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors">
                    <option value="">-- Select Account --</option>
                    <?php foreach ($asset_accounts as $acc): ?>
                        <option value="<?php echo $acc->id; ?>">
                            <?php echo htmlspecialchars($acc->name); ?> (<?php echo htmlspecialchars($acc->account_type); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="mt-1 text-xs text-gray-500 help-text-expense">
                    Which account was the payment made from?
                </p>
                <p class="mt-1 text-xs text-gray-500 help-text-income hidden">
                    Which account was the income deposited to?
                </p>
            </div>

            <!-- Category Account (Income/Expense) -->
            <div>
                <label for="category_account_id" class="block text-sm font-medium text-gray-700 mb-1">
                    <span id="category_label">Expense Category</span> <span class="text-red-500">*</span>
                </label>
                <select id="category_account_id" 
                        name="category_account_id" 
                        required 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors">
                    <option value="">-- Select Category --</option>
                    
                    <!-- Expense Categories (shown by default) -->
                    <optgroup label="Expense Categories" class="category-expense">
                        <?php foreach ($expense_accounts as $acc): ?>
                            <option value="<?php echo $acc->id; ?>">
                                <?php echo htmlspecialchars($acc->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                    
                    <!-- Income Categories (hidden by default) -->
                    <optgroup label="Income Categories" class="category-income" style="display:none;">
                        <?php foreach ($income_accounts as $acc): ?>
                            <option value="<?php echo $acc->id; ?>">
                                <?php echo htmlspecialchars($acc->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
                <p class="mt-1 text-xs text-gray-500 help-text-expense">
                    What was this expense for?
                </p>
                <p class="mt-1 text-xs text-gray-500 help-text-income hidden">
                    What is the source of this income?
                </p>
            </div>
        </div>

        <!-- Person Responsible (Show only for Expense) -->
        <div id="responsible_person_section">
            <label for="responsible_employee_id" class="block text-sm font-medium text-gray-700 mb-1">
                Person Responsible <span class="text-red-500">*</span>
            </label>
            <select id="responsible_employee_id" 
                    name="responsible_employee_id" 
                    required
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors">
                <option value="">-- Select Employee --</option>
                <?php foreach ($employees as $employee): ?>
                    <option value="<?php echo $employee->id; ?>">
                        <?php echo htmlspecialchars($employee->first_name . ' ' . $employee->last_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="mt-1 text-xs text-gray-500">
                Employee who handled the cash or authorized this expense
            </p>
        </div>What was this expense for?
                </p>
                <p class="mt-1 text-xs text-gray-500" x-show="transactionType === 'income'">
                    What is the source of this income?
                </p>
            </div>
        </div>

        <!-- Person Responsible (Show only for Expense) -->
        <div x-show="showResponsible" 
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 transform scale-95"
             x-transition:enter-end="opacity-100 transform scale-100">
            <label for="responsible_employee_id" class="block text-sm font-medium text-gray-700 mb-1">
                Person Responsible <span class="text-red-500">*</span>
            </label>
            <select id="responsible_employee_id" 
                    name="responsible_employee_id" 
                    :required="showResponsible"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors">
                <option value="">-- Select Employee --</option>
                <?php foreach ($employees as $employee): ?>
                    <option value="<?php echo $employee->id; ?>">
                        <?php echo htmlspecialchars($employee->first_name . ' ' . $employee->last_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="mt-1 text-xs text-gray-500">
                Employee who handled the cash or authorized this expense
            </p>
        </div>

        <!-- Memo / Reference -->
        <div>
            <label for="memo" class="block text-sm font-medium text-gray-700 mb-1">
                Memo / Reference <span class="text-gray-400">(Optional)</span>
            </label>
            <textarea id="memo" 
                      name="memo" 
                      rows="2"
                      maxlength="255"
                      class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors"
                      placeholder="e.g., Invoice #123, Office supplies for Demra branch, March rent payment"></textarea>
            <p class="mt-1 text-xs text-gray-500">Add any notes or reference numbers (max 255 characters)</p>
        </div>

        <!-- Submit Button -->
        <div class="pt-6 border-t border-gray-200 flex justify-end space-x-3">
            <button type="reset"
                    class="px-5 py-2.5 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                <i class="fas fa-undo mr-2"></i>Clear Form
            </button>
            <button type="submit"
                    class="px-5 py-2.5 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                <i class="fas fa-check-circle mr-2"></i>Record Transaction
            </button>
        </div>
    </form>
</div>

<!-- JavaScript for form interactivity -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const transactionTypeRadios = document.querySelectorAll('.transaction-type-radio');
    const categorySelect = document.getElementById('category_account_id');
    const responsibleSection = document.getElementById('responsible_person_section');
    const responsibleSelect = document.getElementById('responsible_employee_id');
    const assetLabel = document.getElementById('asset_label');
    const categoryLabel = document.getElementById('category_label');
    
    function updateFormForTransactionType() {
        const selectedType = document.querySelector('.transaction-type-radio:checked').value;
        
        // Update visual selection
        document.querySelectorAll('.transaction-type-option').forEach(option => {
            const radio = option.querySelector('.transaction-type-radio');
            const checkIcon = option.querySelector('.check-icon');
            
            if (radio.checked) {
                option.classList.add('border-primary-600', 'ring-2', 'ring-primary-600', 'bg-primary-50');
                option.classList.remove('border-gray-300');
                checkIcon.classList.remove('hidden');
            } else {
                option.classList.remove('border-primary-600', 'ring-2', 'ring-primary-600', 'bg-primary-50');
                option.classList.add('border-gray-300');
                checkIcon.classList.add('hidden');
            }
        });
        
        // Update labels
        if (selectedType === 'income') {
            assetLabel.textContent = 'Deposited To';
            categoryLabel.textContent = 'Income Category';
        } else {
            assetLabel.textContent = 'Paid From';
            categoryLabel.textContent = 'Expense Category';
        }
        
        // Update help text visibility
        document.querySelectorAll('.help-text-expense').forEach(el => {
            el.classList.toggle('hidden', selectedType !== 'expense');
        });
        document.querySelectorAll('.help-text-income').forEach(el => {
            el.classList.toggle('hidden', selectedType !== 'income');
        });
        
        // Show/hide category options
        const expenseOptgroup = categorySelect.querySelector('.category-expense');
        const incomeOptgroup = categorySelect.querySelector('.category-income');
        
        if (selectedType === 'expense') {
            expenseOptgroup.style.display = '';
            incomeOptgroup.style.display = 'none';
            // Hide all income options
            incomeOptgroup.querySelectorAll('option').forEach(opt => opt.disabled = true);
            expenseOptgroup.querySelectorAll('option').forEach(opt => opt.disabled = false);
        } else {
            expenseOptgroup.style.display = 'none';
            incomeOptgroup.style.display = '';
            // Hide all expense options
            expenseOptgroup.querySelectorAll('option').forEach(opt => opt.disabled = true);
            incomeOptgroup.querySelectorAll('option').forEach(opt => opt.disabled = false);
        }
        
        // Reset category selection when switching types
        categorySelect.value = '';
        
        // Show/hide responsible person section
        if (selectedType === 'expense') {
            responsibleSection.style.display = 'block';
            responsibleSelect.required = true;
        } else {
            responsibleSection.style.display = 'none';
            responsibleSelect.required = false;
            responsibleSelect.value = '';
        }

    }
    
    // Add event listeners to radio buttons
    transactionTypeRadios.forEach(radio => {
        radio.addEventListener('change', updateFormForTransactionType);
    });
    
    // Initialize on page load
    updateFormForTransactionType();
    
    // Log accounts for debugging
    console.log('Transaction form initialized');
    console.log('Asset accounts:', <?php echo count($asset_accounts); ?>);
    console.log('Income accounts:', <?php echo count($income_accounts); ?>);
    console.log('Expense accounts:', <?php echo count($expense_accounts); ?>);
});
</script>
<?php else: ?>
<div class="bg-gray-100 border border-gray-300 rounded-lg p-8 text-center">
    <i class="fas fa-exclamation-triangle text-gray-400 text-5xl mb-4"></i>
    <h3 class="text-xl font-semibold text-gray-700 mb-2">Cannot Record Transactions</h3>
    <p class="text-gray-600 mb-4">Please set up your accounts first before recording transactions.</p>
    <div class="space-x-3">
        <a href="bank_accounts.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg text-sm font-medium text-white bg-primary-600 hover:bg-primary-700">
            <i class="fas fa-university mr-2"></i>Set Up Bank Accounts
        </a>
        <a href="chart_of_accounts.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
            <i class="fas fa-list mr-2"></i>Manage Chart of Accounts
        </a>
    </div>
</div>
<?php endif; ?>

<!-- ======================================== -->
<!-- 4. ACCOUNTING EXPLANATION SECTION -->
<!-- ======================================== -->
<div class="mt-6 bg-gradient-to-br from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-6">
    <div class="flex">
        <div class="flex-shrink-0">
            <i class="fas fa-book text-blue-600 text-xl"></i>
        </div>
        <div class="ml-4">
            <h3 class="text-sm font-semibold text-blue-900 mb-3">
                How Double-Entry Accounting Works
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm">
                <!-- Expense Explanation -->
                <div class="bg-white bg-opacity-60 rounded-lg p-4">
                    <h4 class="font-semibold text-red-700 mb-2 flex items-center">
                        <i class="fas fa-minus-circle mr-2"></i>Expense Transaction
                    </h4>
                    <div class="space-y-1 text-gray-700">
                        <p class="font-medium">When you record an expense:</p>
                        <p class="ml-4">
                            <span class="font-semibold text-red-600">Debit:</span> Expense Category 
                            <span class="text-xs text-gray-500">(increases expense)</span>
                        </p>
                        <p class="ml-4">
                            <span class="font-semibold text-green-600">Credit:</span> Bank/Cash Account 
                            <span class="text-xs text-gray-500">(decreases asset)</span>
                        </p>
                        <p class="mt-2 text-xs text-gray-600">
                            Example: Paying ৳5,000 rent debits "Rent Expense" and credits "Bank Account"
                        </p>
                    </div>
                </div>

                <!-- Income Explanation -->
                <div class="bg-white bg-opacity-60 rounded-lg p-4">
                    <h4 class="font-semibold text-green-700 mb-2 flex items-center">
                        <i class="fas fa-plus-circle mr-2"></i>Income Transaction
                    </h4>
                    <div class="space-y-1 text-gray-700">
                        <p class="font-medium">When you record income:</p>
                        <p class="ml-4">
                            <span class="font-semibold text-red-600">Debit:</span> Bank/Cash Account 
                            <span class="text-xs text-gray-500">(increases asset)</span>
                        </p>
                        <p class="ml-4">
                            <span class="font-semibold text-green-600">Credit:</span> Revenue Category 
                            <span class="text-xs text-gray-500">(increases income)</span>
                        </p>
                        <p class="mt-2 text-xs text-gray-600">
                            Example: Receiving ৳10,000 sales revenue debits "Bank Account" and credits "Sales Revenue"
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="mt-4 pt-4 border-t border-blue-200">
                <p class="text-sm text-blue-800">
                    <i class="fas fa-balance-scale mr-2"></i>
                    <strong>Key Principle:</strong> Every transaction must have equal debits and credits. 
                    This system automatically creates balanced journal entries for you.
                </p>
            </div>
        </div>
    </div>
</div>


<?php
// --- Include Footer ---
require_once '../templates/footer.php';
?>