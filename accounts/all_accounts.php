<?php
require_once '../core/init.php';

// Security: Restrict access to financial roles
$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-rampura', 'accounts-srg', 'accounts-demra'];
restrict_access($allowed_roles);

global $db;
$pageTitle = 'Account Balances (Non-Bank)';

try {
    // Query to get summary of non-bank accounts
    $query = "
        SELECT 
            coa.id,
            coa.account_number AS account_code, 
            coa.name AS account_name,
            coa.account_type,                   
            coa.normal_balance,                 
            IFNULL(SUM(tl.debit_amount), 0) AS total_debit, 
            IFNULL(SUM(tl.credit_amount), 0) AS total_credit
        FROM 
            chart_of_accounts coa
        LEFT JOIN 
            transaction_lines tl ON coa.id = tl.account_id
        WHERE 
            coa.account_type != 'Bank'
        GROUP BY 
            coa.id, coa.account_number, coa.name, coa.account_type, coa.normal_balance
        ORDER BY 
            coa.account_number ASC
    ";
    
    $accounts = $db->query($query)->results();

    // Calculate totals for the footer
    $total_debit_all = 0;
    $total_credit_all = 0;

} catch (Exception $e) {
    $accounts = [];
    $_SESSION['error_flash'] = 'Error fetching account balances: ' . $e->getMessage();
}

require_once '../templates/header.php';
?>

<div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    <!-- Page Header -->
    <div class="flex flex-wrap justify-between items-center mb-6 gap-4">
        <div>
            <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
            <p class="text-lg text-gray-600 mt-1">A snapshot of all account balances, excluding bank accounts.</p>
        </div>
        <div class="flex gap-3">
            <!-- Export Button -->
            <a href="all_accounts_export.php" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition shadow-sm">
                <i class="fas fa-file-csv mr-2"></i>Export CSV
            </a>
            <a href="index.php" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition shadow-sm">
                <i class="fas fa-arrow-left mr-2"></i>Back to Accounts
            </a>
        </div>
    </div>

    <!-- Main Content: Account Balances Table -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Account Code</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Account Name</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Account Type</th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total Debit</th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total Credit</th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                        <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($accounts)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-folder-open text-4xl mb-2"></i>
                                <p>No accounts found or an error occurred.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($accounts as $account): ?>
                            <?php
                                // Calculate balance based on normal balance
                                $balance = 0;
                                if (strtolower($account->normal_balance) == 'debit') {
                                    $balance = $account->total_debit - $account->total_credit;
                                } else {
                                    $balance = $account->total_credit - $account->total_debit;
                                }

                                // Add to totals
                                $total_debit_all += $account->total_debit;
                                $total_credit_all += $account->total_credit;
                                
                                // Determine balance color
                                $balance_color = 'text-gray-900';
                                if ($balance < 0) {
                                    $balance_color = 'text-red-600';
                                } elseif ($balance > 0) {
                                    $balance_color = 'text-green-700';
                                }
                            ?>
                            <tr class="hover:bg-gray-50 transition">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($account->account_code ?? '-'); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($account->account_name); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($account->account_type); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?php echo number_format($account->total_debit, 2); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?php echo number_format($account->total_credit, 2); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-right <?php echo $balance_color; ?>">
                                    <?php echo number_format($balance, 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                    <a href="chart_account_statement.php?account_id=<?php echo $account->id; ?>" class="text-blue-600 hover:text-blue-900 font-medium">
                                        Statement <i class="fas fa-chevron-right ml-1 text-xs"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot class="bg-gray-100">
                    <tr>
                        <th scope="row" colspan="3" class="px-6 py-3 text-right text-sm font-bold text-gray-700 uppercase tracking-wider">
                            Totals
                        </th>
                        <td class="px-6 py-3 text-right text-sm font-bold text-gray-900"><?php echo number_format($total_debit_all, 2); ?></td>
                        <td class="px-6 py-3 text-right text-sm font-bold text-gray-900"><?php echo number_format($total_credit_all, 2); ?></td>
                        <td class="px-6 py-3" colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

    </div>

</div>

<?php require_once '../templates/footer.php'; ?>