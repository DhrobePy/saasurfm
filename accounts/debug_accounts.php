<?php
require_once '../core/init.php';

// --- SECURITY ---
$allowed_roles = ['Superadmin', 'admin', 'Accounts'];
restrict_access($allowed_roles);

global $db;
$pageTitle = 'Debug: Chart of Accounts';

// Get all accounts
$all_accounts = [];
try {
    $all_accounts = $db->query(
        "SELECT 
            id, 
            name, 
            account_number,
            account_type, 
            account_type_group,
            normal_balance, 
            status,
            is_active,
            created_at
         FROM chart_of_accounts
         ORDER BY id ASC"
    )->results();
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

require_once '../templates/header.php';
?>

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900">Chart of Accounts Debug</h1>
    <p class="text-lg text-gray-600 mt-1">
        Diagnostic view of all accounts in your system
    </p>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <?php
    $total = count($all_accounts);
    $active = array_filter($all_accounts, fn($a) => $a->status === 'active' && $a->is_active == 1);
    $banks = array_filter($all_accounts, fn($a) => $a->account_type === 'Bank');
    $petty_cash = array_filter($all_accounts, fn($a) => $a->account_type === 'Petty Cash');
    $revenue = array_filter($all_accounts, fn($a) => $a->account_type === 'Revenue');
    $expense = array_filter($all_accounts, fn($a) => $a->account_type === 'Expense');
    ?>
    
    <div class="bg-blue-500 text-white rounded-lg p-4">
        <div class="text-sm opacity-90">Total Accounts</div>
        <div class="text-3xl font-bold"><?php echo $total; ?></div>
    </div>
    
    <div class="bg-green-500 text-white rounded-lg p-4">
        <div class="text-sm opacity-90">Active Accounts</div>
        <div class="text-3xl font-bold"><?php echo count($active); ?></div>
    </div>
    
    <div class="bg-purple-500 text-white rounded-lg p-4">
        <div class="text-sm opacity-90">Bank Accounts</div>
        <div class="text-3xl font-bold"><?php echo count($banks); ?></div>
    </div>
    
    <div class="bg-orange-500 text-white rounded-lg p-4">
        <div class="text-sm opacity-90">Petty Cash</div>
        <div class="text-3xl font-bold"><?php echo count($petty_cash); ?></div>
    </div>
</div>

<!-- Accounts by Type -->
<div class="bg-white rounded-lg shadow-md p-6 mb-6">
    <h2 class="text-xl font-bold text-gray-900 mb-4">Accounts Grouped by Type</h2>
    
    <?php
    // Group accounts by type
    $grouped = [];
    foreach ($all_accounts as $account) {
        $type = $account->account_type;
        if (!isset($grouped[$type])) {
            $grouped[$type] = [];
        }
        $grouped[$type][] = $account;
    }
    
    // Sort by type name
    ksort($grouped);
    ?>
    
    <div class="space-y-6">
        <?php foreach ($grouped as $type => $accounts): ?>
            <div class="border border-gray-200 rounded-lg p-4">
                <h3 class="text-lg font-semibold text-gray-900 mb-3">
                    <?php echo htmlspecialchars($type); ?>
                    <span class="text-sm font-normal text-gray-500">(<?php echo count($accounts); ?> accounts)</span>
                </h3>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Account Number</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type Group</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Normal Balance</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Active</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($accounts as $account): ?>
                                <tr class="<?php echo ($account->status !== 'active' || $account->is_active != 1) ? 'bg-red-50' : ''; ?>">
                                    <td class="px-3 py-2 text-sm text-gray-900"><?php echo $account->id; ?></td>
                                    <td class="px-3 py-2 text-sm text-gray-900 font-medium">
                                        <?php echo htmlspecialchars($account->name); ?>
                                    </td>
                                    <td class="px-3 py-2 text-sm text-gray-500">
                                        <?php echo $account->account_number ? htmlspecialchars($account->account_number) : '-'; ?>
                                    </td>
                                    <td class="px-3 py-2 text-sm text-gray-500">
                                        <?php echo htmlspecialchars($account->account_type_group); ?>
                                    </td>
                                    <td class="px-3 py-2 text-sm">
                                        <span class="px-2 py-1 text-xs font-medium rounded <?php echo $account->normal_balance === 'Debit' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800'; ?>">
                                            <?php echo htmlspecialchars($account->normal_balance); ?>
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-sm">
                                        <span class="px-2 py-1 text-xs font-medium rounded <?php echo $account->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                            <?php echo htmlspecialchars($account->status); ?>
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-sm text-center">
                                        <?php if ($account->is_active): ?>
                                            <i class="fas fa-check-circle text-green-600"></i>
                                        <?php else: ?>
                                            <i class="fas fa-times-circle text-red-600"></i>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Categorization Test -->
<div class="bg-white rounded-lg shadow-md p-6">
    <h2 class="text-xl font-bold text-gray-900 mb-4">Transaction Form Categorization Test</h2>
    <p class="text-sm text-gray-600 mb-4">
        This shows how accounts would be categorized for the transaction form:
    </p>
    
    <?php
    $asset_accounts = [];
    $income_accounts = [];
    $expense_accounts = [];
    $liability_accounts = [];
    $other_accounts = [];
    
    foreach ($all_accounts as $account) {
        // Only include active accounts
        if ($account->status !== 'active' || $account->is_active != 1) {
            continue;
        }
        
        $type = $account->account_type;
        
        if (in_array($type, ['Bank', 'Petty Cash', 'Cash', 'Other Current Asset', 'Fixed Asset'])) {
            $asset_accounts[] = $account;
        } elseif (in_array($type, ['Revenue', 'Other Income'])) {
            $income_accounts[] = $account;
        } elseif (in_array($type, ['Expense', 'Cost of Goods Sold', 'Other Expense'])) {
            $expense_accounts[] = $account;
        } elseif (in_array($type, ['Accounts Payable', 'Credit Card', 'Loan', 'Other Liability'])) {
            $liability_accounts[] = $account;
        } else {
            $other_accounts[] = $account;
        }
    }
    ?>
    
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <!-- Asset Accounts -->
        <div class="border border-gray-200 rounded-lg p-4">
            <h3 class="font-semibold text-gray-900 mb-2">
                Asset Accounts (<?php echo count($asset_accounts); ?>)
            </h3>
            <p class="text-xs text-gray-500 mb-2">For "Paid From" / "Deposited To"</p>
            <?php if (empty($asset_accounts)): ?>
                <p class="text-sm text-red-600">⚠️ No asset accounts found!</p>
            <?php else: ?>
                <ul class="text-sm space-y-1">
                    <?php foreach ($asset_accounts as $acc): ?>
                        <li class="flex items-start">
                            <i class="fas fa-check-circle text-green-500 mt-1 mr-2"></i>
                            <span><?php echo htmlspecialchars($acc->name); ?>
                                <span class="text-xs text-gray-500">(<?php echo $acc->account_type; ?>)</span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        
        <!-- Income Accounts -->
        <div class="border border-gray-200 rounded-lg p-4">
            <h3 class="font-semibold text-gray-900 mb-2">
                Income Accounts (<?php echo count($income_accounts); ?>)
            </h3>
            <p class="text-xs text-gray-500 mb-2">For "Income Category"</p>
            <?php if (empty($income_accounts)): ?>
                <p class="text-sm text-red-600">⚠️ No income accounts found!</p>
            <?php else: ?>
                <ul class="text-sm space-y-1">
                    <?php foreach ($income_accounts as $acc): ?>
                        <li class="flex items-start">
                            <i class="fas fa-check-circle text-green-500 mt-1 mr-2"></i>
                            <span><?php echo htmlspecialchars($acc->name); ?>
                                <span class="text-xs text-gray-500">(<?php echo $acc->account_type; ?>)</span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        
        <!-- Expense Accounts -->
        <div class="border border-gray-200 rounded-lg p-4">
            <h3 class="font-semibold text-gray-900 mb-2">
                Expense Accounts (<?php echo count($expense_accounts); ?>)
            </h3>
            <p class="text-xs text-gray-500 mb-2">For "Expense Category"</p>
            <?php if (empty($expense_accounts)): ?>
                <p class="text-sm text-red-600">⚠️ No expense accounts found!</p>
            <?php else: ?>
                <ul class="text-sm space-y-1">
                    <?php foreach ($expense_accounts as $acc): ?>
                        <li class="flex items-start">
                            <i class="fas fa-check-circle text-green-500 mt-1 mr-2"></i>
                            <span><?php echo htmlspecialchars($acc->name); ?>
                                <span class="text-xs text-gray-500">(<?php echo $acc->account_type; ?>)</span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if (!empty($other_accounts)): ?>
    <div class="mt-4 border border-yellow-200 bg-yellow-50 rounded-lg p-4">
        <h3 class="font-semibold text-yellow-900 mb-2">
            Other Accounts (<?php echo count($other_accounts); ?>)
        </h3>
        <p class="text-xs text-yellow-700 mb-2">These won't appear in transaction dropdowns:</p>
        <ul class="text-sm space-y-1">
            <?php foreach ($other_accounts as $acc): ?>
                <li><?php echo htmlspecialchars($acc->name); ?> 
                    <span class="text-xs text-gray-500">(<?php echo $acc->account_type; ?>)</span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
</div>

<!-- Test JSON Output -->
<div class="mt-6 bg-gray-900 text-gray-100 rounded-lg p-6">
    <h2 class="text-xl font-bold mb-4">JSON Output for Alpine.js</h2>
    <div class="space-y-4">
        <div>
            <h3 class="text-sm font-semibold text-gray-300 mb-2">assetAccounts:</h3>
            <pre class="bg-gray-800 p-3 rounded text-xs overflow-x-auto"><?php 
                echo json_encode(array_values(array_map(function($a) { 
                    return ['id' => $a->id, 'name' => $a->name, 'type' => $a->account_type]; 
                }, $asset_accounts)), JSON_PRETTY_PRINT); 
            ?></pre>
        </div>
        
        <div>
            <h3 class="text-sm font-semibold text-gray-300 mb-2">incomeCategories:</h3>
            <pre class="bg-gray-800 p-3 rounded text-xs overflow-x-auto"><?php 
                echo json_encode(array_values(array_map(function($a) { 
                    return ['id' => $a->id, 'name' => $a->name]; 
                }, $income_accounts)), JSON_PRETTY_PRINT); 
            ?></pre>
        </div>
        
        <div>
            <h3 class="text-sm font-semibold text-gray-300 mb-2">expenseCategories:</h3>
            <pre class="bg-gray-800 p-3 rounded text-xs overflow-x-auto"><?php 
                echo json_encode(array_values(array_map(function($a) { 
                    return ['id' => $a->id, 'name' => $a->name]; 
                }, $expense_accounts)), JSON_PRETTY_PRINT); 
            ?></pre>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-6">
    <h3 class="text-lg font-semibold text-blue-900 mb-3">
        <i class="fas fa-tools mr-2"></i>Quick Actions
    </h3>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <a href="new_transaction.php" class="block p-4 bg-white border border-blue-200 rounded-lg hover:border-blue-400 transition-colors">
            <i class="fas fa-plus-circle text-blue-600 text-xl mb-2"></i>
            <h4 class="font-medium text-gray-900">Record Transaction</h4>
            <p class="text-xs text-gray-600 mt-1">Go to transaction form</p>
        </a>
        
        <a href="chart_of_accounts.php" class="block p-4 bg-white border border-blue-200 rounded-lg hover:border-blue-400 transition-colors">
            <i class="fas fa-list text-blue-600 text-xl mb-2"></i>
            <h4 class="font-medium text-gray-900">Chart of Accounts</h4>
            <p class="text-xs text-gray-600 mt-1">Manage all accounts</p>
        </a>
        
        <a href="bank_accounts.php" class="block p-4 bg-white border border-blue-200 rounded-lg hover:border-blue-400 transition-colors">
            <i class="fas fa-university text-blue-600 text-xl mb-2"></i>
            <h4 class="font-medium text-gray-900">Bank Accounts</h4>
            <p class="text-xs text-gray-600 mt-1">Manage bank accounts</p>
        </a>
    </div>
</div>

<!-- Recommendations -->
<?php
$recommendations = [];

if (empty($asset_accounts)) {
    $recommendations[] = [
        'type' => 'critical',
        'message' => 'You have no active Bank, Cash, or Petty Cash accounts. Transactions cannot be recorded without payment accounts.',
        'action' => 'Add bank accounts or mark existing ones as active.'
    ];
}

if (empty($income_accounts)) {
    $recommendations[] = [
        'type' => 'warning',
        'message' => 'You have no active Revenue accounts. You won\'t be able to record income transactions.',
        'action' => 'Add revenue accounts to your Chart of Accounts.'
    ];
}

if (empty($expense_accounts)) {
    $recommendations[] = [
        'type' => 'warning',
        'message' => 'You have no active Expense accounts. You won\'t be able to record expense transactions.',
        'action' => 'Add expense accounts to your Chart of Accounts.'
    ];
}

// Check for inactive accounts that should be active
$inactive_but_used = array_filter($all_accounts, fn($a) => 
    ($a->status !== 'active' || $a->is_active != 1) && 
    in_array($a->account_type, ['Bank', 'Petty Cash', 'Revenue', 'Expense'])
);

if (!empty($inactive_but_used)) {
    $recommendations[] = [
        'type' => 'info',
        'message' => 'You have ' . count($inactive_but_used) . ' accounts that are marked as inactive but could be useful for transactions.',
        'action' => 'Review and activate these accounts if needed.'
    ];
}
?>

<?php if (!empty($recommendations)): ?>
<div class="mt-6 space-y-3">
    <h3 class="text-lg font-semibold text-gray-900">
        <i class="fas fa-lightbulb mr-2"></i>Recommendations
    </h3>
    
    <?php foreach ($recommendations as $rec): ?>
        <?php
        $colors = [
            'critical' => ['bg' => 'bg-red-50', 'border' => 'border-red-200', 'icon' => 'text-red-600', 'text' => 'text-red-800'],
            'warning' => ['bg' => 'bg-yellow-50', 'border' => 'border-yellow-200', 'icon' => 'text-yellow-600', 'text' => 'text-yellow-800'],
            'info' => ['bg' => 'bg-blue-50', 'border' => 'border-blue-200', 'icon' => 'text-blue-600', 'text' => 'text-blue-800']
        ];
        $color = $colors[$rec['type']];
        ?>
        <div class="<?php echo $color['bg']; ?> border <?php echo $color['border']; ?> rounded-lg p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-info-circle <?php echo $color['icon']; ?>"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium <?php echo $color['text']; ?>">
                        <?php echo htmlspecialchars($rec['message']); ?>
                    </p>
                    <p class="text-sm <?php echo $color['text']; ?> mt-1 opacity-80">
                        <strong>Action:</strong> <?php echo htmlspecialchars($rec['action']); ?>
                    </p>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
require_once '../templates/footer.php';
?>