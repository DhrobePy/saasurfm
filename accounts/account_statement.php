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
];
restrict_access($allowed_roles);

// Get the $db instance
global $db; 
$pageTitle = 'Account Statement';
$account = null;
$transactions = [];
$error = null;
$running_balance = 0.00;
$total_debits = 0.00;
$total_credits = 0.00;

// --- DATA: GET ACCOUNT & TRANSACTIONS ---
try {
    // 1. Get the account UUID from the URL
    if (!isset($_GET['uuid'])) {
        throw new Exception("No account selected. Please go back and select an account.");
    }
    $uuid = $_GET['uuid'];

    // 2. Fetch the bank account AND its linked chart_of_accounts entry
    $account = $db->query(
        "SELECT 
            ba.bank_name, 
            ba.branch_name, 
            ba.account_name, 
            ba.account_number, 
            ba.account_type,
            coa.id as chart_of_account_id,
            coa.name as coa_name,
            coa.normal_balance
        FROM 
            bank_accounts ba
        JOIN 
            chart_of_accounts coa ON ba.chart_of_account_id = coa.id
        WHERE 
            ba.uuid = ?",
        [$uuid]
    )->first();

    if (!$account) {
        throw new Exception("The selected bank account could not be found.");
    }

    $pageTitle = 'Statement for ' . htmlspecialchars($account->coa_name);

    // 3. Fetch all transaction lines for this account, joined with journal details
    // *** RECTIFIED: Use `debit_amount` and `credit_amount` ***
    $transactions = $db->query(
        "SELECT 
            tl.debit_amount, 
            tl.credit_amount, 
            je.transaction_date, 
            je.description,
            je.related_document_type,
            je.related_document_id
        FROM 
            transaction_lines tl
        JOIN 
            journal_entries je ON tl.journal_entry_id = je.id
        WHERE 
            tl.account_id = ?
        ORDER BY 
            je.transaction_date ASC, je.id ASC",
        [$account->chart_of_account_id]
    )->results();

    // 4. Calculate totals
    foreach ($transactions as $txn) {
        $total_debits += $txn->debit_amount;
        $total_credits += $txn->credit_amount;
    }

} catch (Exception $e) {
    $error = $e->getMessage();
}

// --- Include Header ---
require_once '../templates/header.php'; 
?>

<!-- ======================================== -->
<!-- 1. PAGE HEADER & NAVIGATION -->
<!-- ======================================== -->
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
        <p class="text-lg text-gray-600 mt-1">
            Detailed transaction history and running balance.
        </p>
    </div>
    <a href="bank_accounts.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors">
        <i class="fas fa-arrow-left mr-2"></i>Back to Bank List
    </a>
</div>

<!-- ======================================== -->
<!-- 2. ERROR DISPLAY (If any) -->
<!-- ======================================== -->
<?php if ($error): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg" role="alert">
        <div class="flex">
            <div class="flex-shrink-0">
                <i class="fas fa-exclamation-circle text-red-500"></i>
            </div>
            <div class="ml-3">
                <p class="font-bold">Error Loading Statement</p>
                <p><?php echo htmlspecialchars($error); ?></p>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- ======================================== -->
<!-- 3. ACCOUNT SUMMARY & STATS -->
<!-- ======================================== -->
<?php if ($account): ?>
<div class="bg-white rounded-lg shadow-md overflow-hidden mb-6">
    <!-- Account Info Header -->
    <div class="p-5 border-b border-gray-200">
        <div class="flex items-center">
            <div class="flex-shrink-0 h-12 w-12 flex items-center justify-center bg-primary-100 text-primary-600 rounded-lg">
                <i class="fas fa-university text-xl"></i>
            </div>
            <div class="ml-4">
                <h3 class="text-lg font-bold text-gray-900 leading-tight">
                    <?php echo htmlspecialchars($account->bank_name); ?>
                </h3>
                <p class="text-sm text-gray-600">
                    <?php echo htmlspecialchars($account->account_name); ?>
                </p>
            </div>
        </div>
    </div>
    
    <!-- Account Details -->
    <div class="px-5 py-4 bg-gray-50 grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <span class="text-xs font-medium text-gray-500 uppercase">Account No.</span>
            <p class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($account->account_number); ?></p>
        </div>
        <div>
            <span class="text-xs font-medium text-gray-500 uppercase">Branch</span>
            <p class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($account->branch_name); ?></p>
        </div>
        <div>
            <span class="text-xs font-medium text-gray-500 uppercase">Account Type</span>
            <p class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($account->account_type); ?></p>
        </div>
    </div>

    <!-- Financial Summary -->
    <div class="grid grid-cols-1 md:grid-cols-3 border-t border-gray-200">
        <div class="p-4 text-center border-r border-gray-200">
            <span class="text-xs font-medium text-gray-500 uppercase">Total Debits</span>
            <p class="text-lg font-bold text-green-600">৳<?php echo number_format($total_debits, 2, '.', ','); ?></p>
        </div>
        <div class="p-4 text-center border-r border-gray-200">
            <span class="text-xs font-medium text-gray-500 uppercase">Total Credits</span>
            <p class="text-lg font-bold text-red-600">৳<?php echo number_format($total_credits, 2, '.', ','); ?></p>
        </div>
        <div class="p-4 text-center bg-gray-50 rounded-br-lg">
            <span class="text-xs font-medium text-gray-500 uppercase">Ending Balance</span>
            <?php 
                // *** RECTIFIED: Use uppercase 'Debit' ***
                if ($account->normal_balance == 'Debit') {
                    $ending_balance = $total_debits - $total_credits;
                } else {
                    $ending_balance = $total_credits - $total_debits;
                }
                $balance_class = $ending_balance < 0 ? 'text-red-600' : 'text-gray-900';
            ?>
            <p class="text-lg font-bold <?php echo $balance_class; ?>">৳<?php echo number_format($ending_balance, 2, '.', ','); ?></p>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ======================================== -->
<!-- 4. TRANSACTION LEDGER -->
<!-- ======================================== -->
<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <h3 class="text-xl font-bold text-gray-800 p-5 border-b border-gray-200">
        Transaction History
    </h3>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Debit (৳)</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Credit (৳)</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Running Balance (৳)</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($transactions) && !$error): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-10 whitespace-nowrap text-sm text-gray-500 text-center">
                            <i class="fas fa-file-invoice-dollar fa-2x text-gray-300 mb-3"></i>
                            <p class="font-medium text-gray-700">No transactions found for this account.</p>
                            <p>The balance shown above reflects the initial balance.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transactions as $txn): ?>
                        <?php
                            // *** RECTIFIED: Use uppercase 'Debit' ***
                            if ($account->normal_balance == 'Debit') {
                                // *** RECTIFIED: Use `debit_amount` and `credit_amount` ***
                                $change = $txn->debit_amount - $txn->credit_amount;
                            } else {
                                $change = $txn->credit_amount - $txn->debit_amount;
                            }
                            $running_balance += $change;
                            $rb_class = $running_balance < 0 ? 'text-red-600' : 'text-gray-800';
                        ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?php echo date('d M Y', strtotime($txn->transaction_date)); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800">
                                <?php echo htmlspecialchars($txn->description); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-green-600 text-right font-mono">
                                <?php echo $txn->debit_amount > 0 ? number_format($txn->debit_amount, 2) : '—'; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-red-600 text-right font-mono">
                                <?php echo $txn->credit_amount > 0 ? number_format($txn->credit_amount, 2) : '—'; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm <?php echo $rb_class; ?> text-right font-mono font-bold">
                                <?php echo number_format($running_balance, 2, '.', ','); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($transactions)): ?>
                <tfoot class="bg-gray-50">
                    <tr class="font-bold text-gray-900">
                        <td colspan="2" class="px-6 py-3 text-right text-sm">Total Ending Balance:</td>
                        <td class="px-6 py-3 text-right text-sm font-mono text-green-600"><?php echo number_format($total_debits, 2); ?></td>
                        <td class="px-6 py-3 text-right text-sm font-mono text-red-600"><?php echo number_format($total_credits, 2); ?></td>
                        <td class="px-6 py-3 text-right text-sm font-mono <?php echo $balance_class; ?>"><?php echo number_format($ending_balance, 2, '.', ','); ?></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php
// --- Include Footer ---
require_once '../templates/footer.php'; 
?>

