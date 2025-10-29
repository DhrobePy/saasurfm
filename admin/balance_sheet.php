<?php
/**
 * BALANCE SHEET - Beautiful Design Matching bank_accounts.php
 * Features: Assets, Liabilities, Equity calculation, Date comparison, Export
 */

require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'accountant'];
restrict_access($allowed_roles);

global $db;
$pageTitle = 'Balance Sheet';

$as_of_date = $_GET['as_of_date'] ?? date('Y-m-d');
$compare_date = $_GET['compare_date'] ?? null;
$show_comparison = isset($_GET['compare']) && $_GET['compare'] == '1';

$currentUser = getCurrentUser();
$user_display_name = $currentUser['display_name'] ?? 'Unknown User';

function getAccountBalance($db, $account_id, $as_of_date) {
    $result = $db->query(
        "SELECT COALESCE(SUM(debit_amount), 0) - COALESCE(SUM(credit_amount), 0) as balance
         FROM journal_entry_lines jel
         JOIN journal_entries je ON jel.journal_entry_id = je.id
         WHERE jel.account_id = ? AND je.entry_date <= ? AND je.status = 'Posted'",
        [$account_id, $as_of_date]
    )->first();
    return $result ? floatval($result->balance) : 0;
}

function getBalanceSheetAccounts($db, $as_of_date, $compare_date = null) {
    $accounts = $db->query(
        "SELECT id, name, account_number, account_type, account_type_group, normal_balance
         FROM chart_of_accounts WHERE status = 'active'
         ORDER BY account_type_group, account_type, name"
    )->results();
    
    $data = ['assets' => ['current' => [], 'fixed' => []], 'liabilities' => ['current' => [], 'long_term' => []], 'equity' => []];
    
    foreach ($accounts as $account) {
        $balance = getAccountBalance($db, $account->id, $as_of_date);
        if ($account->normal_balance === 'Credit') $balance = -$balance;
        
        $account_data = [
            'id' => $account->id, 'name' => $account->name,
            'account_number' => $account->account_number, 'account_type' => $account->account_type,
            'balance' => $balance, 'compare_balance' => null
        ];
        
        if ($compare_date) {
            $compare_balance = getAccountBalance($db, $account->id, $compare_date);
            if ($account->normal_balance === 'Credit') $compare_balance = -$compare_balance;
            $account_data['compare_balance'] = $compare_balance;
        }
        
        if ($balance != 0 || ($compare_date && $compare_balance != 0)) {
            switch ($account->account_type) {
                case 'Bank': case 'Cash': case 'Petty Cash': case 'Accounts Receivable': case 'Other Current Asset':
                    $data['assets']['current'][] = $account_data; break;
                case 'Fixed Asset':
                    $data['assets']['fixed'][] = $account_data; break;
                case 'Accounts Payable': case 'Credit Card': case 'Other Liability':
                    $account_data['balance'] = -$account_data['balance'];
                    if ($compare_date) $account_data['compare_balance'] = -$account_data['compare_balance'];
                    $data['liabilities']['current'][] = $account_data; break;
                case 'Loan':
                    $account_data['balance'] = -$account_data['balance'];
                    if ($compare_date) $account_data['compare_balance'] = -$account_data['compare_balance'];
                    $data['liabilities']['long_term'][] = $account_data; break;
                case 'Owner Equity':
                    $account_data['balance'] = -$account_data['balance'];
                    if ($compare_date) $account_data['compare_balance'] = -$account_data['compare_balance'];
                    $data['equity'][] = $account_data; break;
            }
        }
    }
    return $data;
}

function getNetIncome($db, $as_of_date) {
    $revenue = $db->query(
        "SELECT COALESCE(SUM(jel.credit_amount - jel.debit_amount), 0) as total
         FROM journal_entry_lines jel
         JOIN journal_entries je ON jel.journal_entry_id = je.id
         JOIN chart_of_accounts coa ON jel.account_id = coa.id
         WHERE je.entry_date <= ? AND je.status = 'Posted' AND coa.account_type_group = 'Revenue'",
        [$as_of_date]
    )->first();
    
    $expenses = $db->query(
        "SELECT COALESCE(SUM(jel.debit_amount - jel.credit_amount), 0) as total
         FROM journal_entry_lines jel
         JOIN journal_entries je ON jel.journal_entry_id = je.id
         JOIN chart_of_accounts coa ON jel.account_id = coa.id
         WHERE je.entry_date <= ? AND je.status = 'Posted' AND coa.account_type_group = 'Expense'",
        [$as_of_date]
    )->first();
    
    return ($revenue ? floatval($revenue->total) : 0) - ($expenses ? floatval($expenses->total) : 0);
}

$balance_sheet = getBalanceSheetAccounts($db, $as_of_date, $compare_date);
$net_income = getNetIncome($db, $as_of_date);
$compare_net_income = $compare_date ? getNetIncome($db, $compare_date) : null;

$total_current_assets = array_sum(array_column($balance_sheet['assets']['current'], 'balance'));
$total_fixed_assets = array_sum(array_column($balance_sheet['assets']['fixed'], 'balance'));
$total_assets = $total_current_assets + $total_fixed_assets;

$total_current_liabilities = array_sum(array_column($balance_sheet['liabilities']['current'], 'balance'));
$total_long_term_liabilities = array_sum(array_column($balance_sheet['liabilities']['long_term'], 'balance'));
$total_liabilities = $total_current_liabilities + $total_long_term_liabilities;

$total_equity = array_sum(array_column($balance_sheet['equity'], 'balance')) + $net_income;
$total_liabilities_equity = $total_liabilities + $total_equity;

if ($compare_date) {
    $compare_total_current_assets = array_sum(array_column($balance_sheet['assets']['current'], 'compare_balance'));
    $compare_total_fixed_assets = array_sum(array_column($balance_sheet['assets']['fixed'], 'compare_balance'));
    $compare_total_assets = $compare_total_current_assets + $compare_total_fixed_assets;
    $compare_total_current_liabilities = array_sum(array_column($balance_sheet['liabilities']['current'], 'compare_balance'));
    $compare_total_long_term_liabilities = array_sum(array_column($balance_sheet['liabilities']['long_term'], 'compare_balance'));
    $compare_total_liabilities = $compare_total_current_liabilities + $compare_total_long_term_liabilities;
    $compare_total_equity = array_sum(array_column($balance_sheet['equity'], 'compare_balance')) + $compare_net_income;
    $compare_total_liabilities_equity = $compare_total_liabilities + $compare_total_equity;
}

require_once '../templates/header.php';
?>

<style>
@media print { .no-print { display: none !important; } .print-full-width { width: 100% !important; max-width: none !important; } }
</style>

<div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="mb-6 no-print">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Balance Sheet</h1>
                <p class="text-lg text-gray-600 mt-1">As of <span class="font-semibold"><?php echo date('d M Y', strtotime($as_of_date)); ?></span></p>
            </div>
            <div class="flex items-center space-x-3">
                <button onclick="window.print()" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                    <i class="fas fa-print mr-2"></i>Print
                </button>
                <button onclick="exportToExcel()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    <i class="fas fa-file-excel mr-2"></i>Export
                </button>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow-md border border-gray-200 p-5">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">As of Date</label>
                    <input type="date" name="as_of_date" value="<?php echo $as_of_date; ?>" class="w-full border-gray-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <input type="checkbox" name="compare" value="1" <?php echo $show_comparison ? 'checked' : ''; ?>> Compare with
                    </label>
                    <input type="date" name="compare_date" value="<?php echo $compare_date ?? ''; ?>" <?php echo !$show_comparison ? 'disabled' : ''; ?> class="w-full border-gray-300 rounded-lg">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700">
                        <i class="fas fa-sync-alt mr-2"></i>Update
                    </button>
                </div>
                <div class="flex items-end">
                    <a href="?" class="w-full px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-center">
                        <i class="fas fa-redo mr-2"></i>Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 no-print">
        <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg shadow-md p-5 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-100 text-sm font-medium">Total Assets</p>
                    <p class="text-3xl font-bold mt-1">৳<?php echo number_format($total_assets, 2); ?></p>
                </div>
                <div class="bg-blue-400 bg-opacity-30 rounded-full p-3">
                    <i class="fas fa-wallet text-2xl"></i>
                </div>
            </div>
        </div>
        <div class="bg-gradient-to-br from-red-500 to-red-600 rounded-lg shadow-md p-5 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-red-100 text-sm font-medium">Total Liabilities</p>
                    <p class="text-3xl font-bold mt-1">৳<?php echo number_format($total_liabilities, 2); ?></p>
                </div>
                <div class="bg-red-400 bg-opacity-30 rounded-full p-3">
                    <i class="fas fa-hand-holding-usd text-2xl"></i>
                </div>
            </div>
        </div>
        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-lg shadow-md p-5 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-green-100 text-sm font-medium">Total Equity</p>
                    <p class="text-3xl font-bold mt-1">৳<?php echo number_format($total_equity, 2); ?></p>
                </div>
                <div class="bg-green-400 bg-opacity-30 rounded-full p-3">
                    <i class="fas fa-chart-line text-2xl"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200 print-full-width">
        <div class="hidden print:block text-center py-6 border-b-2 border-gray-300">
            <h1 class="text-2xl font-bold text-gray-900">Ujjal Flour Mills</h1>
            <h2 class="text-xl font-semibold text-gray-700 mt-2">Balance Sheet</h2>
            <p class="text-gray-600 mt-1">As of <?php echo date('F d, Y', strtotime($as_of_date)); ?></p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b-2 border-gray-200">
                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-bold text-gray-700 uppercase">Account</th>
                        <th class="px-6 py-4 text-right text-sm font-bold text-gray-700 uppercase"><?php echo date('M d, Y', strtotime($as_of_date)); ?></th>
                        <?php if ($compare_date): ?>
                        <th class="px-6 py-4 text-right text-sm font-bold text-gray-700 uppercase"><?php echo date('M d, Y', strtotime($compare_date)); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr class="bg-blue-50">
                        <td colspan="<?php echo $compare_date ? 3 : 2; ?>" class="px-6 py-3">
                            <h3 class="text-lg font-bold text-blue-900"><i class="fas fa-wallet mr-2"></i>ASSETS</h3>
                        </td>
                    </tr>
                    
                    <tr class="bg-blue-25">
                        <td colspan="<?php echo $compare_date ? 3 : 2; ?>" class="px-6 py-2">
                            <h4 class="text-base font-semibold text-blue-800 ml-4">Current Assets</h4>
                        </td>
                    </tr>
                    
                    <?php foreach ($balance_sheet['assets']['current'] as $account): ?>
                    <tr class="border-b border-gray-100 hover:bg-gray-50">
                        <td class="px-6 py-3 text-sm text-gray-900">
                            <span class="ml-8"><?php echo htmlspecialchars($account['name']); ?></span>
                            <?php if ($account['account_number']): ?>
                                <span class="text-xs text-gray-500 ml-2">(<?php echo htmlspecialchars($account['account_number']); ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-3 text-sm text-right font-medium text-gray-900">৳<?php echo number_format($account['balance'], 2); ?></td>
                        <?php if ($compare_date): ?>
                        <td class="px-6 py-3 text-sm text-right font-medium text-gray-700">৳<?php echo number_format($account['compare_balance'], 2); ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    
                    <tr class="bg-blue-100 font-semibold">
                        <td class="px-6 py-3 text-sm text-gray-900"><span class="ml-4">Total Current Assets</span></td>
                        <td class="px-6 py-3 text-sm text-right text-gray-900">৳<?php echo number_format($total_current_assets, 2); ?></td>
                        <?php if ($compare_date): ?>
                        <td class="px-6 py-3 text-sm text-right text-gray-700">৳<?php echo number_format($compare_total_current_assets, 2); ?></td>
                        <?php endif; ?>
                    </tr>
                    
                    <?php if (!empty($balance_sheet['assets']['fixed'])): ?>
                    <tr class="bg-blue-25">
                        <td colspan="<?php echo $compare_date ? 3 : 2; ?>" class="px-6 py-2">
                            <h4 class="text-base font-semibold text-blue-800 ml-4">Fixed Assets</h4>
                        </td>
                    </tr>
                    
                    <?php foreach ($balance_sheet['assets']['fixed'] as $account): ?>
                    <tr class="border-b border-gray-100 hover:bg-gray-50">
                        <td class="px-6 py-3 text-sm text-gray-900"><span class="ml-8"><?php echo htmlspecialchars($account['name']); ?></span></td>
                        <td class="px-6 py-3 text-sm text-right font-medium text-gray-900">৳<?php echo number_format($account['balance'], 2); ?></td>
                        <?php if ($compare_date): ?>
                        <td class="px-6 py-3 text-sm text-right font-medium text-gray-700">৳<?php echo number_format($account['compare_balance'], 2); ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    
                    <tr class="bg-blue-100 font-semibold">
                        <td class="px-6 py-3 text-sm text-gray-900"><span class="ml-4">Total Fixed Assets</span></td>
                        <td class="px-6 py-3 text-sm text-right text-gray-900">৳<?php echo number_format($total_fixed_assets, 2); ?></td>
                        <?php if ($compare_date): ?>
                        <td class="px-6 py-3 text-sm text-right text-gray-700">৳<?php echo number_format($compare_total_fixed_assets, 2); ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endif; ?>
                    
                    <tr class="bg-blue-600 text-white font-bold border-t-2 border-blue-700">
                        <td class="px-6 py-4 text-base">TOTAL ASSETS</td>
                        <td class="px-6 py-4 text-base text-right">৳<?php echo number_format($total_assets, 2); ?></td>
                        <?php if ($compare_date): ?>
                        <td class="px-6 py-4 text-base text-right">৳<?php echo number_format($compare_total_assets, 2); ?></td>
                        <?php endif; ?>
                    </tr>
                    
                    <tr><td colspan="<?php echo $compare_date ? 3 : 2; ?>" class="py-4"></td></tr>
                    
                    <tr class="bg-red-50">
                        <td colspan="<?php echo $compare_date ? 3 : 2; ?>" class="px-6 py-3">
                            <h3 class="text-lg font-bold text-red-900"><i class="fas fa-hand-holding-usd mr-2"></i>LIABILITIES</h3>
                        </td>
                    </tr>
                    
                    <?php if (!empty($balance_sheet['liabilities']['current'])): ?>
                    <tr class="bg-red-25">
                        <td colspan="<?php echo $compare_date ? 3 : 2; ?>" class="px-6 py-2">
                            <h4 class="text-base font-semibold text-red-800 ml-4">Current Liabilities</h4>
                        </td>
                    </tr>
                    
                    <?php foreach ($balance_sheet['liabilities']['current'] as $account): ?>
                    <tr class="border-b border-gray-100 hover:bg-gray-50">
                        <td class="px-6 py-3 text-sm text-gray-900"><span class="ml-8"><?php echo htmlspecialchars($account['name']); ?></span></td>
                        <td class="px-6 py-3 text-sm text-right font-medium text-gray-900">৳<?php echo number_format($account['balance'], 2); ?></td>
                        <?php if ($compare_date): ?>
                        <td class="px-6 py-3 text-sm text-right font-medium text-gray-700">৳<?php echo number_format($account['compare_balance'], 2); ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    
                    <tr class="bg-red-100 font-semibold">
                        <td class="px-6 py-3 text-sm text-gray-900"><span class="ml-4">Total Current Liabilities</span></td>
                        <td class="px-6 py-3 text-sm text-right text-gray-900">৳<?php echo number_format($total_current_liabilities, 2); ?></td>
                        <?php if ($compare_date): ?>
                        <td class="px-6 py-3 text-sm text-right text-gray-700">৳<?php echo number_format($compare_total_current_liabilities, 2); ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endif; ?>
                    
                    <?php if (!empty($balance_sheet['liabilities']['long_term'])): ?>
                    <tr class="bg-red-25">
                        <td colspan="<?php echo $compare_date ? 3 : 2; ?>" class="px-6 py-2">
                            <h4 class="text-base font-semibold text-red-800 ml-4">Long-term Liabilities</h4>
                        </td>
                    </tr>
                    
                    <?php foreach ($balance_sheet['liabilities']['long_term'] as $account): ?>
                    <tr class="border-b border-gray-100 hover:bg-gray-50">
                        <td class="px-6 py-3 text-sm text-gray-900"><span class="ml-8"><?php echo htmlspecialchars($account['name']); ?></span></td>
                        <td class="px-6 py-3 text-sm text-right font-medium text-gray-900">৳<?php echo number_format($account['balance'], 2); ?></td>
                        <?php if ($compare_date): ?>
                        <td class="px-6 py-3 text-sm text-right font-medium text-gray-700">৳<?php echo number_format($account['compare_balance'], 2); ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    
                    <tr class="bg-red-100 font-semibold">
                        <td class="px-6 py-3 text-sm text-gray-900"><span class="ml-4">Total Long-term Liabilities</span></td>
                        <td class="px-6 py-3 text-sm text-right text-gray-900">৳<?php echo number_format($total_long_term_liabilities, 2); ?></td>
                        <?php if ($compare_date): ?>
                        <td class="px-6 py-3 text-sm text-right text-gray-700">৳<?php echo number_format($compare_total_long_term_liabilities, 2); ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endif; ?>
                    
                    <tr class="bg-red-600 text-white font-bold border-t-2 border-red-700">
                        <td class="px-6 py-4 text-base">TOTAL LIABILITIES</td>
                        <td class="px-6 py-4 text-base text-right">৳<?php echo number_format($total_liabilities, 2); ?></td>
                        <?php if ($compare_date): ?>
                        <td class="px-6 py-4 text-base text-right">৳<?php echo number_format($compare_total_liabilities, 2); ?></td>
                        <?php endif; ?>
                    </tr>
                    
                    <tr><td colspan="<?php echo $compare_date ? 3 : 2; ?>" class="py-4"></td></tr>
                    
                    <tr class="bg-green-50">
                        <td colspan="<?php echo $compare_date ? 3 : 2; ?>" class="px-6 py-3">
                            <h3 class="text-lg font-bold text-green-900"><i class="fas fa-chart-line mr-2"></i>EQUITY</h3>
                        </td>
                    </tr>
                    
                    <?php foreach ($balance_sheet['equity'] as $account): ?>
                    <tr class="border-b border-gray-100 hover:bg-gray-50">
                        <td class="px-6 py-3 text-sm text-gray-900"><span class="ml-4"><?php echo htmlspecialchars($account['name']); ?></span></td>
                        <td class="px-6 py-3 text-sm text-right font-medium text-gray-900">৳<?php echo number_format($account['balance'], 2); ?></td>
                        <?php if ($compare_date): ?>
                        <td class="px-6 py-3 text-sm text-right font-medium text-gray-700">৳<?php echo number_format($account['compare_balance'], 2); ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    
                    <tr class="border-b border-gray-100 hover:bg-gray-50">
                        <td class="px-6 py-3 text-sm text-gray-900"><span class="ml-4">Retained Earnings (Net Income)</span></td>
                        <td class="px-6 py-3 text-sm text-right font-medium text-gray-900">৳<?php echo number_format($net_income, 2); ?></td>
                        <?php if ($compare_date): ?>
                        <td class="px-6 py-3 text-sm text-right font-medium text-gray-700">৳<?php echo number_format($compare_net_income, 2); ?></td>
                        <?php endif; ?>
                    </tr>
                    
                    <tr class="bg-green-600 text-white font-bold border-t-2 border-green-700">
                        <td class="px-6 py-4 text-base">TOTAL EQUITY</td>
                        <td class="px-6 py-4 text-base text-right">৳<?php echo number_format($total_equity, 2); ?></td>
                        <?php if ($compare_date): ?>
                        <td class="px-6 py-4 text-base text-right">৳<?php echo number_format($compare_total_equity, 2); ?></td>
                        <?php endif; ?>
                    </tr>
                    
                    <tr><td colspan="<?php echo $compare_date ? 3 : 2; ?>" class="py-2"></td></tr>
                    
                    <tr class="bg-gray-800 text-white font-bold text-lg border-t-4 border-gray-900">
                        <td class="px-6 py-5">TOTAL LIABILITIES & EQUITY</td>
                        <td class="px-6 py-5 text-right">৳<?php echo number_format($total_liabilities_equity, 2); ?></td>
                        <?php if ($compare_date): ?>
                        <td class="px-6 py-5 text-right">৳<?php echo number_format($compare_total_liabilities_equity, 2); ?></td>
                        <?php endif; ?>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="px-6 py-4 bg-gray-50 border-t-2 border-gray-200">
            <?php 
            $difference = abs($total_assets - $total_liabilities_equity);
            $is_balanced = $difference < 0.01;
            ?>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">Balance Verification:</p>
                    <?php if ($is_balanced): ?>
                        <p class="text-lg font-bold text-green-600">
                            <i class="fas fa-check-circle mr-2"></i>Assets = Liabilities + Equity ✓
                        </p>
                    <?php else: ?>
                        <p class="text-lg font-bold text-red-600">
                            <i class="fas fa-exclamation-triangle mr-2"></i>Out of Balance by ৳<?php echo number_format($difference, 2); ?>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="text-right text-xs text-gray-500">
                    Generated: <?php echo date('d M Y, h:i A'); ?><br>By: <?php echo htmlspecialchars($user_display_name); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-8 bg-blue-50 border border-blue-200 rounded-lg p-6 no-print">
        <div class="flex">
            <div class="flex-shrink-0"><i class="fas fa-info-circle text-blue-600 text-xl"></i></div>
            <div class="ml-4">
                <h3 class="text-sm font-semibold text-blue-900 mb-2">Understanding the Balance Sheet</h3>
                <ul class="text-sm text-blue-800 space-y-1 list-disc list-inside">
                    <li><strong>Assets:</strong> What the company owns (cash, inventory, equipment)</li>
                    <li><strong>Liabilities:</strong> What the company owes (loans, payables)</li>
                    <li><strong>Equity:</strong> Owner's stake in the company (capital + retained earnings)</li>
                    <li><strong>Formula:</strong> Assets = Liabilities + Equity</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
function exportToExcel() {
    let csv = 'Ujjal Flour Mills - Balance Sheet\n';
    csv += 'As of: <?php echo date("F d, Y", strtotime($as_of_date)); ?>\n\n';
    const table = document.querySelector('table');
    const rows = table.querySelectorAll('tr');
    rows.forEach(row => {
        const cells = row.querySelectorAll('td, th');
        const rowData = [];
        cells.forEach(cell => { rowData.push('"' + cell.textContent.trim().replace(/"/g, '""') + '"'); });
        csv += rowData.join(',') + '\n';
    });
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'balance-sheet-<?php echo date("Y-m-d", strtotime($as_of_date)); ?>.csv';
    a.click();
}
</script>

<?php require_once '../templates/footer.php'; ?>