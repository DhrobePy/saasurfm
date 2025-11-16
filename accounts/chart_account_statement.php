<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-rampura', 'accounts-srg', 'accounts-demra'];
restrict_access($allowed_roles);

global $db;
$pageTitle = 'Account Statement';

// 1. Get Parameters
$account_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : null;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01'); // Start of current month
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d'); // Today

if (!$account_id) {
    $_SESSION['error_flash'] = 'Invalid account selected.';
    header('Location: all_accounts.php');
    exit();
}

// 2. Fetch Account Details
$account = $db->query(
    "SELECT * FROM chart_of_accounts WHERE id = ?",
    [$account_id]
)->first();

if (!$account) {
    $_SESSION['error_flash'] = 'Account not found.';
    header('Location: all_accounts.php');
    exit();
}

// 3. Calculate Opening Balance (Transactions before date_from)
$opening_sql = "
    SELECT 
        IFNULL(SUM(tl.debit_amount), 0) as total_debit,
        IFNULL(SUM(tl.credit_amount), 0) as total_credit
    FROM transaction_lines tl
    JOIN journal_entries je ON tl.journal_entry_id = je.id
    WHERE tl.account_id = ? AND je.transaction_date < ?
";
$opening_res = $db->query($opening_sql, [$account_id, $date_from])->first();

$opening_balance = 0;
$normal_balance_type = strtolower($account->normal_balance);

if ($normal_balance_type == 'debit') {
    $opening_balance = $opening_res->total_debit - $opening_res->total_credit;
} else {
    $opening_balance = $opening_res->total_credit - $opening_res->total_debit;
}

// 4. Fetch Transactions (Within date range)
$trans_sql = "
    SELECT 
        tl.*,
        je.transaction_date,
        je.description as journal_description,
        je.uuid as journal_uuid,
        je.related_document_type,
        je.related_document_id
    FROM transaction_lines tl
    JOIN journal_entries je ON tl.journal_entry_id = je.id
    WHERE tl.account_id = ? 
      AND je.transaction_date BETWEEN ? AND ?
    ORDER BY je.transaction_date ASC, je.id ASC
";
$transactions = $db->query($trans_sql, [$account_id, $date_from, $date_to])->results();

// Calculate summary for the period
$period_debit = 0;
$period_credit = 0;
foreach ($transactions as $t) {
    $period_debit += $t->debit_amount;
    $period_credit += $t->credit_amount;
}

// Closing Balance Calculation
$closing_balance = 0;
if ($normal_balance_type == 'debit') {
    $closing_balance = $opening_balance + $period_debit - $period_credit;
} else {
    $closing_balance = $opening_balance + $period_credit - $period_debit;
}

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    <!-- Header -->
    <div class="flex flex-wrap justify-between items-end mb-6 gap-4">
        <div>
            <div class="flex items-center gap-2 text-gray-500 text-sm mb-1">
                <a href="all_accounts.php" class="hover:underline">Accounts</a>
                <i class="fas fa-chevron-right text-xs"></i>
                <span>Statement</span>
            </div>
            <h1 class="text-3xl font-bold text-gray-900">
                <?php echo htmlspecialchars($account->name); ?>
                <span class="text-lg font-normal text-gray-500 ml-2">(<?php echo htmlspecialchars($account->account_number ?? 'No Code'); ?>)</span>
            </h1>
            <p class="text-sm text-gray-600 mt-1">
                Type: <span class="font-medium"><?php echo htmlspecialchars($account->account_type); ?></span> | 
                Normal Balance: <span class="font-medium"><?php echo htmlspecialchars($account->normal_balance); ?></span>
            </p>
        </div>
        
        <div class="flex gap-2">
            <a href="chart_account_statement_export.php?account_id=<?php echo $account_id; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
               class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition shadow-sm">
                <i class="fas fa-file-csv mr-2"></i>Export CSV
            </a>
        </div>
    </div>

    <!-- Filters & Summary Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        
        <!-- Filter Form -->
        <div class="bg-white rounded-lg shadow-md p-5 lg:col-span-2">
            <form method="GET" class="flex flex-wrap items-end gap-4">
                <input type="hidden" name="account_id" value="<?php echo $account_id; ?>">
                
                <div class="flex-1 min-w-[150px]">
                    <label class="block text-xs font-medium text-gray-700 mb-1">From Date</label>
                    <input type="date" name="date_from" value="<?php echo $date_from; ?>" class="w-full px-3 py-2 border rounded-md focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="flex-1 min-w-[150px]">
                    <label class="block text-xs font-medium text-gray-700 mb-1">To Date</label>
                    <input type="date" name="date_to" value="<?php echo $date_to; ?>" class="w-full px-3 py-2 border rounded-md focus:ring-2 focus:ring-blue-500">
                </div>
                
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition">
                    Filter
                </button>
            </form>
        </div>

        <!-- Summary Cards -->
        <div class="bg-white rounded-lg shadow-md p-5">
            <div class="flex justify-between items-center mb-2 border-b pb-2">
                <span class="text-gray-600 text-sm">Opening Balance</span>
                <span class="font-bold text-gray-800">৳<?php echo number_format($opening_balance, 2); ?></span>
            </div>
            <div class="flex justify-between items-center mb-2 text-xs">
                <span class="text-gray-500">Debit Turnover</span>
                <span class="text-gray-700">৳<?php echo number_format($period_debit, 2); ?></span>
            </div>
            <div class="flex justify-between items-center mb-2 text-xs border-b pb-2">
                <span class="text-gray-500">Credit Turnover</span>
                <span class="text-gray-700">৳<?php echo number_format($period_credit, 2); ?></span>
            </div>
            <div class="flex justify-between items-center pt-1">
                <span class="text-gray-800 font-medium">Closing Balance</span>
                <span class="font-bold text-xl <?php echo $closing_balance < 0 ? 'text-red-600' : 'text-green-600'; ?>">
                    ৳<?php echo number_format($closing_balance, 2); ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Statement Table -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ref</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Debit</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Credit</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Running Balance</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <!-- Opening Balance Row -->
                    <tr class="bg-gray-50 italic text-gray-600">
                        <td class="px-6 py-3 text-sm"><?php echo date('d M Y', strtotime($date_from)); ?></td>
                        <td class="px-6 py-3 text-sm" colspan="4"><strong>Balance Brought Forward</strong></td>
                        <td class="px-6 py-3 text-sm text-right font-bold">৳<?php echo number_format($opening_balance, 2); ?></td>
                    </tr>

                    <?php 
                    $running_balance = $opening_balance;
                    
                    if (empty($transactions)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-gray-500">No transactions found for this period.</td>
                        </tr>
                    <?php else: 
                        foreach ($transactions as $entry): 
                            // Calculate Running Balance
                            if ($normal_balance_type == 'debit') {
                                $running_balance += ($entry->debit_amount - $entry->credit_amount);
                            } else {
                                $running_balance += ($entry->credit_amount - $entry->debit_amount);
                            }
                    ?>
                        <tr class="hover:bg-gray-50 transition">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo date('d M Y', strtotime($entry->transaction_date)); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-700 max-w-md truncate" title="<?php echo htmlspecialchars($entry->journal_description); ?>">
                                <?php echo htmlspecialchars($entry->journal_description); ?>
                                <?php if($entry->description): ?>
                                    <span class="text-gray-400 text-xs block"><?php echo htmlspecialchars($entry->description); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-xs text-gray-500">
                                <?php echo $entry->related_document_type ? htmlspecialchars($entry->related_document_type . ' #' . $entry->related_document_id) : '-'; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-600">
                                <?php echo $entry->debit_amount > 0 ? number_format($entry->debit_amount, 2) : '-'; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-600">
                                <?php echo $entry->credit_amount > 0 ? number_format($entry->credit_amount, 2) : '-'; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium text-gray-900">
                                <?php echo number_format($running_balance, 2); ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>

                    <!-- Closing Balance Row -->
                    <tr class="bg-gray-100 font-bold">
                        <td colspan="3" class="px-6 py-3 text-right text-sm">Totals / Closing Balance</td>
                        <td class="px-6 py-3 text-right text-sm text-gray-800">৳<?php echo number_format($period_debit, 2); ?></td>
                        <td class="px-6 py-3 text-right text-sm text-gray-800">৳<?php echo number_format($period_credit, 2); ?></td>
                        <td class="px-6 py-3 text-right text-sm text-purple-700">৳<?php echo number_format($closing_balance, 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php require_once '../templates/footer.php'; ?>