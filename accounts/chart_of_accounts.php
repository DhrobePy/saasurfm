<?php
require_once '../core/init.php';

// --- SECURITY ---
// Set which roles can access this page.
$allowed_roles = [
    'Superadmin', 
    'admin',
    'Accounts',
];
restrict_access($allowed_roles);

// Get the $db instance
global $db; 
$pageTitle = 'Chart of Accounts';

// --- VARIABLE INITIALIZATION ---
$edit_mode = false;
$account_to_edit = null;
$form_action = 'add_account';

// --- LOGIC: HANDLE POST REQUESTS (ADD & UPDATE) ---
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        
        // --- ADD NEW ACCOUNT ---
        if (isset($_POST['add_account'])) {
            $db->query(
                "INSERT INTO chart_of_accounts (name, description, account_type, account_type_group, normal_balance, status) 
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $_POST['name'],
                    $_POST['description'],
                    $_POST['account_type'],
                    $_POST['account_type_group'],
                    $_POST['normal_balance'],
                    $_POST['status']
                ]
            );
            $_SESSION['success_flash'] = 'Account successfully created.';
            header('Location: chart_of_accounts.php');
            exit();
        }

        // --- UPDATE EXISTING ACCOUNT ---
        if (isset($_POST['update_account'])) {
            $account_id = (int)$_POST['account_id'];
            $db->query(
                "UPDATE chart_of_accounts 
                 SET name = ?, description = ?, account_type = ?, account_type_group = ?, normal_balance = ?, status = ? 
                 WHERE id = ?",
                [
                    $_POST['name'],
                    $_POST['description'],
                    $_POST['account_type'],
                    $_POST['account_type_group'],
                    $_POST['normal_balance'],
                    $_POST['status'],
                    $account_id
                ]
            );
            $_SESSION['success_flash'] = 'Account successfully updated.';
            header('Location: chart_of_accounts.php');
            exit();
        }
    }

    // --- LOGIC: HANDLE GET REQUESTS (EDIT & DELETE) ---

    // --- DELETE ACCOUNT ---
    if (isset($_GET['delete'])) {
        $delete_id = (int)$_GET['delete'];
        // TODO: Check if account has transactions before deleting?
        // For now, we allow delete.
        $db->query("DELETE FROM chart_of_accounts WHERE id = ?", [$delete_id]);
        $_SESSION['success_flash'] = 'Account successfully deleted.';
        header('Location: chart_of_accounts.php');
        exit();
    }

    // --- GET ACCOUNT TO EDIT ---
    if (isset($_GET['edit'])) {
        $edit_id = (int)$_GET['edit'];
        $account_to_edit = $db->query("SELECT * FROM chart_of_accounts WHERE id = ?", [$edit_id])->first();
        if ($account_to_edit) {
            $edit_mode = true;
            $form_action = 'update_account';
        }
    }

} catch (PDOException $e) {
    if ($e->getCode() == '23000') { // Integrity constraint violation
        $_SESSION['error_flash'] = 'Error: An account with this name already exists.';
    } else {
        $_SESSION['error_flash'] = 'Database Error: ' . $e->getMessage();
    }
    header('Location: chart_of_accounts.php');
    exit();
} catch (Exception $e) {
    $_SESSION['error_flash'] = 'An unexpected error occurred: ' . $e->getMessage();
    header('Location: chart_of_accounts.php');
    exit();
}


// --- DATA: GET ALL ACCOUNTS, GROUPED ---
$all_accounts_raw = $db->query("SELECT * FROM chart_of_accounts ORDER BY name ASC")->results();
$grouped_accounts = [];
foreach ($all_accounts_raw as $account) {
    $grouped_accounts[$account->account_type_group][] = $account;
}

// Define the order of groups
$group_order = ['Asset', 'Liability', 'Equity', 'Revenue', 'Cost of Goods Sold', 'Expense', 'Other Income'];

// --- Include Header ---
require_once '../templates/header.php'; 
?>

<!-- ======================================== -->
<!-- 1. PAGE HEADER -->
<!-- ======================================== -->
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">Chart of Accounts</h1>
        <p class="text-lg text-gray-600">
            Manage all financial categories for your company.
        </p>
    </div>
</div>

<!-- ======================================== -->
<!-- 2. "SMART" ADD / EDIT FORM -->
<!-- ======================================== -->
<!-- 
  This form uses Alpine.js to auto-populate the
  Group and Normal Balance fields based on the Account Type selected.
  This simplifies the form and enforces accounting rules.
-->
<div class="bg-white rounded-lg shadow-md p-6 mb-6" 
     x-data="{
        accountType: '<?php echo $account_to_edit->account_type ?? ''; ?>',
        accountTypeGroup: '<?php echo $account_to_edit->account_type_group ?? ''; ?>',
        normalBalance: '<?php echo $account_to_edit->normal_balance ?? ''; ?>',
        
        updateFields() {
            switch (this.accountType) {
                case 'Bank':
                case 'Petty Cash':
                case 'Cash':
                case 'Accounts Receivable':
                case 'Other Current Asset':
                case 'Fixed Asset':
                    this.accountTypeGroup = 'Asset';
                    this.normalBalance = 'debit';
                    break;
                case 'Accounts Payable':
                case 'Credit Card':
                case 'Loan':
                case 'Other Liability':
                    this.accountTypeGroup = 'Liability';
                    this.normalBalance = 'credit';
                    break;
                case 'Owner Equity':
                    this.accountTypeGroup = 'Equity';
                    this.normalBalance = 'credit';
                    break;
                case 'Revenue':
                case 'Other Income':
                    this.accountTypeGroup = 'Revenue';
                    this.normalBalance = 'credit';
                    break;
                case 'Cost of Goods Sold':
                    this.accountTypeGroup = 'Cost of Goods Sold';
                    this.normalBalance = 'debit';
                    break;
                case 'Expense':
                case 'Other Expense':
                    this.accountTypeGroup = 'Expense';
                    this.normalBalance = 'debit';
                    break;
                default:
                    this.accountTypeGroup = '';
                    this.normalBalance = '';
            }
        }
     }"
     x-init="updateFields()">

    <h2 class="text-2xl font-bold text-gray-800 mb-4">
        <?php echo $edit_mode ? 'Edit Account' : 'Add New Account'; ?>
    </h2>
    
    <form action="chart_of_accounts.php" method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-6">
        
        <!-- Hidden fields -->
        <input type="hidden" name="<?php echo $form_action; ?>" value="1">
        <?php if ($edit_mode): ?>
            <input type="hidden" name="account_id" value="<?php echo htmlspecialchars($account_to_edit->id); ?>">
        <?php endif; ?>

        <!-- Account Name -->
        <div class="md:col-span-2">
            <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Account Name <span class="text-red-500">*</span></label>
            <input type="text" id="name" name="name" required
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                   value="<?php echo htmlspecialchars($account_to_edit->name ?? ''); ?>"
                   placeholder="e.g., Sales Revenue - Demra or Office Supplies Expense">
        </div>
        
        <!-- Account Type (The "Smart" Selector) -->
        <div>
            <label for="account_type" class="block text-sm font-medium text-gray-700 mb-1">Account Type <span class="text-red-500">*</span></label>
            <select id="account_type" name="account_type" x-model="accountType" @change="updateFields()" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                <option value="" disabled selected>Select a type...</option>
                <optgroup label="Assets">
                    <option value="Bank">Bank</option>
                    <option value="Petty Cash">Petty Cash</option>
                    <option value="Cash">Cash</option>
                    <option value="Accounts Receivable">Accounts Receivable</option>
                    <option value="Other Current Asset">Other Current Asset</option>
                    <option value="Fixed Asset">Fixed Asset</option>
                </optgroup>
                <optgroup label="Liabilities & Equity">
                    <option value="Accounts Payable">Accounts Payable</option>
                    <option value="Credit Card">Credit Card</option>
                    <option value="Loan">Loan</option>
                    <option value="Other Liability">Other Liability</option>
                    <option value="Owner Equity">Owner Equity</option>
                </optgroup>
                <optgroup label="Income / Revenue">
                    <option value="Revenue">Revenue</option>
                    <option value="Other Income">Other Income</option>
                </optgroup>
                <optgroup label="Expenses">
                    <option value="Expense">Expense</option>
                    <option value="Cost of Goods Sold">Cost of Goods Sold</option>
                    <option value="Other Expense">Other Expense</option>
                </optgroup>
            </select>
        </div>

        <!-- Account Group (Read-only, auto-filled) -->
        <div>
            <label for="account_type_group" class="block text-sm font-medium text-gray-700 mb-1">Account Group</label>
            <input type="text" id="account_type_group" name="account_type_group" x-model="accountTypeGroup" readonly
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-gray-100 cursor-not-allowed">
        </div>
        
        <!-- Normal Balance (Read-only, auto-filled) -->
        <div>
            <label for="normal_balance" class="block text-sm font-medium text-gray-700 mb-1">Normal Balance</label>
            <input type="text" id="normal_balance" name="normal_balance" x-model="normalBalance" readonly
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-gray-100 cursor-not-allowed">
        </div>

        <!-- Status -->
        <div>
            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select id="status" name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                <option value="active" <?php echo ($account_to_edit->status ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo ($account_to_edit->status ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </div>

        <!-- Description -->
        <div class="md:col-span-2">
            <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
            <textarea id="description" name="description" rows="2"
                      class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                      placeholder="Optional: What is this account used for?"><?php echo htmlspecialchars($account_to_edit->description ?? ''); ?></textarea>
        </div>
        
        <!-- Submit Button -->
        <div class="md:col-span-2 flex justify-end space-x-3">
            <?php if ($edit_mode): ?>
                <a href="chart_of_accounts.php" class="px-5 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancel
                </a>
                <button type="submit" class="px-5 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-save mr-2"></i>Update Account
                </button>
            <?php else: ?>
                <button type="submit" class="px-5 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-plus mr-2"></i>Add Account
                </button>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ======================================== -->
<!-- 3. ACCOUNTS LIST (GROUPED) -->
<!-- ======================================== -->
<div class="bg-white rounded-lg shadow-md overflow-hidden">
    
    <div class="p-6 border-b border-gray-200">
        <h2 class="text-2xl font-bold text-gray-800">All Accounts</h2>
        <p class="text-sm text-gray-600">All available financial accounts, grouped by type.</p>
    </div>
    
    <div class="space-y-4 p-6">
        <?php if (empty($grouped_accounts)): ?>
            <p class="text-center text-gray-500 py-4">No accounts found. Start by adding one above.</p>
        <?php endif; ?>

        <?php foreach ($group_order as $group_name): ?>
            <?php if (isset($grouped_accounts[$group_name])): ?>
                <div class="rounded-lg border border-gray-200 overflow-hidden">
                    <div class="bg-gray-50 px-4 py-3 border-b border-gray-200">
                        <h3 class="text-lg font-bold text-gray-800"><?php echo htmlspecialchars($group_name); ?></h3>
                    </div>
                    <ul class="divide-y divide-gray-200">
                        <?php foreach ($grouped_accounts[$group_name] as $account): ?>
                            <li class="flex items-center justify-between p-4 hover:bg-gray-50">
                                <div>
                                    <p class="text-sm font-medium text-primary-600">
                                        <?php echo htmlspecialchars($account->name); ?>
                                        <?php if ($account->status == 'inactive'): ?>
                                            <span class="ml-2 px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800">Inactive</span>
                                        <?php endif; ?>
                                    </p>
                                    <p class="text-sm text-gray-600"><?php echo htmlspecialchars($account->account_type); ?> (Normal: <?php echo ucfirst($account->normal_balance); ?>)</p>
                                    <p class="text-xs text-gray-500 italic mt-1"><?php echo htmlspecialchars($account->description); ?></p>
                                </div>
                                <div class="flex-shrink-0 flex items-center space-x-4">
                                    <a href="chart_of_accounts.php?edit=<?php echo $account->id; ?>" class="text-primary-600 hover:text-primary-900" title="Edit Account">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <!-- Add delete confirmation -->
                                    <a href="chart_of_accounts.php?delete=<?php echo $account->id; ?>" class="text-red-600 hover:text-red-900" 
                                       title="Delete Account"
                                       onclick="return confirm('Are you sure you want to delete this account? This action cannot be undone.');">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<?php
// --- Include Footer ---
require_once '../templates/footer.php'; 
?>
