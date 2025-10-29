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
$pageTitle = 'Bank Accounts';
$accounts = [];
$error = null;
$totalBalance = 0;

// --- DATA: GET ALL BANK ACCOUNTS WITH SIMPLIFIED BALANCE CALCULATION ---
try {
    // FIXED: Simplified query without complex subqueries
    $accounts = $db->query(
        "SELECT 
            ba.id, 
            ba.uuid,
            ba.bank_name, 
            ba.branch_name, 
            ba.account_name, 
            ba.account_number, 
            ba.account_type,
            ba.status,
            ba.chart_of_account_id,
            ba.current_balance,
            ba.initial_balance,
            coa.name as account_ledger_name,
            coa.normal_balance,
            coa.account_number as ledger_account_number
        FROM 
            bank_accounts ba
        LEFT JOIN 
            chart_of_accounts coa ON ba.chart_of_account_id = coa.id
        WHERE
            ba.status != 'closed'
        ORDER BY 
            ba.status ASC,
            ba.bank_name ASC, 
            ba.account_name ASC"
    )->results();

    // Now calculate balances from transaction_lines for each account
    foreach ($accounts as $account) {
        // Get the last transaction date
        if ($account->chart_of_account_id) {
            try {
                $lastTrans = $db->query(
                    "SELECT MAX(je.transaction_date) as last_date
                     FROM journal_entries je
                     JOIN transaction_lines tl ON tl.journal_entry_id = je.id
                     WHERE tl.account_id = ?",
                    [$account->chart_of_account_id]
                )->first();
                
                $account->last_transaction_date = $lastTrans ? $lastTrans->last_date : null;
                
                // Calculate actual balance from transactions
                $balanceResult = $db->query(
                    "SELECT 
                        SUM(tl.debit_amount) as total_debits,
                        SUM(tl.credit_amount) as total_credits
                     FROM transaction_lines tl
                     WHERE tl.account_id = ?",
                    [$account->chart_of_account_id]
                )->first();
                
                if ($balanceResult) {
                    $debits = $balanceResult->total_debits ?? 0;
                    $credits = $balanceResult->total_credits ?? 0;
                    
                    // Calculate based on normal balance
                    if ($account->normal_balance === 'Debit') {
                        $account->calculated_balance = $debits - $credits;
                    } else {
                        $account->calculated_balance = $credits - $debits;
                    }
                    
                    // Use calculated balance if it exists, otherwise use stored balance
                    if ($debits > 0 || $credits > 0) {
                        $account->current_balance = $account->calculated_balance;
                    }
                }
            } catch (Exception $e) {
                error_log("Error calculating balance for account {$account->id}: " . $e->getMessage());
                // Keep the stored current_balance if calculation fails
            }
        } else {
            $account->last_transaction_date = null;
        }
    }

    // Calculate total balance across all accounts
    $totalBalance = 0;
    foreach ($accounts as $account) {
        $totalBalance += $account->current_balance;
    }

} catch (Exception $e) {
    // Log the full error for debugging
    error_log("Bank Accounts Query Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    // Show user-friendly error message
    $error = "Unable to load bank accounts at this time. Please try again later.";
    
    // In development mode, show detailed error
    if (defined('APP_DEBUG') && APP_DEBUG === true) {
        $error .= " (Debug: " . $e->getMessage() . ")";
    }
}

// --- Include Header ---
require_once '../templates/header.php'; 
?>

<!-- ======================================== -->
<!-- 1. PAGE HEADER & SUMMARY STATS -->
<!-- ======================================== -->
<div class="mb-6">
    <div class="flex justify-between items-center mb-4">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Bank Accounts</h1>
            <p class="text-lg text-gray-600 mt-1">
                Manage all your company's bank accounts and view statements
            </p>
        </div>
        <a href="manage_bank_account.php" 
           class="inline-flex items-center px-5 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors">
            <i class="fas fa-plus mr-2"></i>Add New Account
        </a>
    </div>
    
    <!-- Summary Statistics -->
    <?php if (!empty($accounts) && !$error): ?>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg shadow-md p-5 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-100 text-sm font-medium">Total Accounts</p>
                    <p class="text-3xl font-bold mt-1"><?php echo count($accounts); ?></p>
                </div>
                <div class="bg-blue-400 bg-opacity-30 rounded-full p-3">
                    <i class="fas fa-university text-2xl"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-lg shadow-md p-5 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-green-100 text-sm font-medium">Total Balance</p>
                    <p class="text-3xl font-bold mt-1">৳<?php echo number_format($totalBalance, 2, '.', ','); ?></p>
                </div>
                <div class="bg-green-400 bg-opacity-30 rounded-full p-3">
                    <i class="fas fa-money-bill-wave text-2xl"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-lg shadow-md p-5 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-purple-100 text-sm font-medium">Active Accounts</p>
                    <p class="text-3xl font-bold mt-1">
                        <?php 
                        $activeCount = array_reduce($accounts, function($carry, $acc) {
                            return $carry + ($acc->status === 'active' ? 1 : 0);
                        }, 0);
                        echo $activeCount;
                        ?>
                    </p>
                </div>
                <div class="bg-purple-400 bg-opacity-30 rounded-full p-3">
                    <i class="fas fa-check-circle text-2xl"></i>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
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
                <p class="font-bold">Error Loading Bank Accounts</p>
                <p class="mt-1"><?php echo htmlspecialchars($error); ?></p>
                <p class="mt-2 text-sm">If this problem persists, please contact technical support.</p>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- ======================================== -->
<!-- 3. BANK ACCOUNTS LIST (CARDS) -->
<!-- ======================================== -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php if (empty($accounts) && !$error): ?>
        <div class="md:col-span-2 lg:col-span-3 text-center py-16">
            <div class="inline-flex items-center justify-center w-20 h-20 bg-gray-100 rounded-full mb-4">
                <i class="fas fa-university fa-3x text-gray-300"></i>
            </div>
            <h3 class="text-xl font-semibold text-gray-700">No Bank Accounts Found</h3>
            <p class="mt-2 text-gray-500">Get started by adding your first bank account.</p>
            <a href="manage_bank_account.php" 
               class="mt-4 inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700">
                <i class="fas fa-plus mr-2"></i>Add Bank Account
            </a>
        </div>
    <?php endif; ?>

    <?php foreach ($accounts as $account): ?>
        <?php 
        // Determine status badge styling
        $statusClasses = [
            'active' => 'bg-green-100 text-green-800',
            'inactive' => 'bg-yellow-100 text-yellow-800',
            'closed' => 'bg-red-100 text-red-800'
        ];
        $statusClass = $statusClasses[$account->status] ?? 'bg-gray-100 text-gray-800';
        
        // Determine balance styling (negative = red)
        $balanceClass = $account->current_balance < 0 
            ? 'text-red-600' 
            : 'text-gray-900';
        ?>
        
        <div class="bg-white rounded-lg shadow-md overflow-hidden transition-all duration-300 hover:shadow-xl border border-gray-200">
            <!-- Account Header -->
            <div class="p-5 border-b border-gray-200">
                <div class="flex items-start justify-between">
                    <div class="flex items-center flex-1">
                        <div class="flex-shrink-0 h-12 w-12 flex items-center justify-center bg-primary-100 text-primary-600 rounded-lg">
                            <i class="fas fa-university text-xl"></i>
                        </div>
                        <div class="ml-4 flex-1">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-bold text-gray-900 leading-tight">
                                    <?php echo htmlspecialchars($account->bank_name); ?>
                                </h3>
                                <span class="ml-2 px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $statusClass; ?>">
                                    <?php echo ucfirst($account->status); ?>
                                </span>
                            </div>
                            <p class="text-sm text-gray-600 mt-1">
                                <?php echo htmlspecialchars($account->account_name); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Account Balance Section -->
            <div class="px-5 py-5 bg-gradient-to-br from-gray-50 to-white">
                <div class="text-center mb-4">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Current Balance</p>
                    <p class="text-3xl font-bold <?php echo $balanceClass; ?>">
                        ৳<?php echo number_format($account->current_balance ?? 0.00, 2, '.', ','); ?>
                    </p>
                    <?php if ($account->current_balance < 0): ?>
                        <p class="text-xs text-red-600 mt-1">
                            <i class="fas fa-exclamation-triangle"></i> Overdrawn
                        </p>
                    <?php endif; ?>
                </div>
                
                <!-- Account Details -->
                <div class="space-y-2.5 text-sm border-t border-gray-200 pt-4">
                    <div class="flex justify-between items-center">
                        <span class="text-gray-500">Account No:</span>
                        <span class="font-medium text-gray-900">
                            <?php echo htmlspecialchars($account->account_number); ?>
                        </span>
                    </div>

                    <?php if ($account->branch_name): ?>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-500">Branch:</span>
                        <span class="font-medium text-gray-900">
                            <?php echo htmlspecialchars($account->branch_name); ?>
                        </span>
                    </div>
                    <?php endif; ?>

                    <div class="flex justify-between items-center">
                        <span class="text-gray-500">Type:</span>
                        <span class="font-medium text-gray-900">
                            <?php echo htmlspecialchars($account->account_type); ?>
                        </span>
                    </div>

                    <?php if ($account->last_transaction_date): ?>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-500">Last Activity:</span>
                        <span class="font-medium text-gray-900">
                            <?php echo date('d M Y', strtotime($account->last_transaction_date)); ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div class="p-4 bg-white border-t border-gray-200 flex space-x-3">
                <a href="account_statement.php?uuid=<?php echo urlencode($account->uuid); ?>" 
                   class="flex-1 text-center px-4 py-2.5 border border-transparent rounded-lg text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-file-invoice mr-1"></i> Statement
                </a>
                <a href="manage_bank_account.php?edit=<?php echo urlencode($account->uuid); ?>" 
                   class="flex-1 text-center px-4 py-2.5 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-edit mr-1"></i> Edit
                </a>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- ======================================== -->
<!-- 4. HELPFUL TIPS SECTION -->
<!-- ======================================== -->
<?php if (!empty($accounts) && !$error): ?>
<div class="mt-8 bg-blue-50 border border-blue-200 rounded-lg p-6">
    <div class="flex">
        <div class="flex-shrink-0">
            <i class="fas fa-info-circle text-blue-600 text-xl"></i>
        </div>
        <div class="ml-4">
            <h3 class="text-sm font-semibold text-blue-900 mb-2">Account Management Tips</h3>
            <ul class="text-sm text-blue-800 space-y-1 list-disc list-inside">
                <li>Balances are calculated in real-time from your transaction history</li>
                <li>Click "Statement" to view detailed transaction history for any account</li>
                <li>Negative balances indicate overdrafts and are highlighted in red</li>
                <li>Inactive accounts are shown but won't appear in transaction dropdowns</li>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
// --- Include Footer ---
require_once '../templates/footer.php'; 
?>