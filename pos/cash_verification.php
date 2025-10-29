<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-rampura', 'accounts-srg', 'accounts-demra', 
                  'accountspos-demra', 'accountspos-srg', 'dispatchpos-demra', 'dispatchpos-srg'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$user_role = $currentUser['role'] ?? '';
$pageTitle = "Cash in Box Verification";
$error = null;
$success = null;
$branch_id = null;
$branch_name = '';
$is_superadmin = in_array($user_role, ['Superadmin', 'admin', 'Accounts']);

// Get selected branch (for superadmin) or user's branch
if ($is_superadmin) {
    $branch_id = $_GET['branch_id'] ?? null;
    $all_branches = $db->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name")->results();
    
    if ($branch_id) {
        $branch_check = $db->query("SELECT id, name FROM branches WHERE id = ?", [$branch_id])->first();
        if ($branch_check) {
            $branch_name = $branch_check->name;
        }
    }
} else {
    $employee_info = $db->query("SELECT branch_id, b.name as branch_name FROM employees e JOIN branches b ON e.branch_id = b.id WHERE e.user_id = ?", [$user_id])->first();
    if ($employee_info && $employee_info->branch_id) {
        $branch_id = $employee_info->branch_id;
        $branch_name = $employee_info->branch_name;
    }
}

// Handle verification submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $branch_id) {
    $actual_cash = floatval($_POST['actual_cash'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    
    // Get expected cash
    $account = $db->query("SELECT current_balance FROM branch_petty_cash_accounts WHERE branch_id = ? AND status = 'active'", [$branch_id])->first();
    $expected_cash = $account ? floatval($account->current_balance) : 0;
    $variance = $actual_cash - $expected_cash;
    
    try {
        $db->query("INSERT INTO cash_verification_log (
                        branch_id, verification_date, expected_cash, actual_cash, 
                        variance, variance_reason, verified_by_user_id, notes, status
                    ) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, 'pending')",
                    [$branch_id, $expected_cash, $actual_cash, $variance, $notes, $user_id, $notes]);
        
        $success = "Cash verification recorded successfully.";
    } catch (Exception $e) {
        $error = "Error recording verification: " . $e->getMessage();
    }
}

// Get current cash status
$cash_status = null;
$recent_transactions = [];
$today_summary = null;

if ($branch_id) {
    // Get petty cash account
    $cash_status = $db->query("SELECT * FROM branch_petty_cash_accounts WHERE branch_id = ? AND status = 'active'", [$branch_id])->first();
    
    // Get today's transactions
    $today_date = date('Y-m-d');
    $recent_transactions = $db->query("SELECT 
                                        t.*,
                                        u.display_name as created_by_name
                                    FROM branch_petty_cash_transactions t
                                    JOIN users u ON t.created_by_user_id = u.id
                                    WHERE t.branch_id = ?
                                    AND DATE(t.transaction_date) = ?
                                    ORDER BY t.transaction_date DESC, t.id DESC",
                                    [$branch_id, $today_date])->results();
    
    // Today's summary
    $today_summary = $db->query("SELECT 
                                    SUM(CASE WHEN transaction_type = 'cash_in' THEN amount ELSE 0 END) as cash_in,
                                    SUM(CASE WHEN transaction_type = 'cash_out' THEN amount ELSE 0 END) as cash_out,
                                    COUNT(*) as transaction_count
                                FROM branch_petty_cash_transactions
                                WHERE branch_id = ?
                                AND DATE(transaction_date) = ?",
                                [$branch_id, $today_date])->first();
    
    // Get last EOD
    $last_eod = $db->query("SELECT * FROM eod_summary WHERE branch_id = ? ORDER BY eod_date DESC LIMIT 1", [$branch_id])->first();
}

require_once '../templates/header.php';
?>

<style>
.cash-display {
    font-family: 'Courier New', monospace;
    font-size: 2.5rem;
    font-weight: bold;
}
.transaction-in {
    border-left: 4px solid #10b981;
}
.transaction-out {
    border-left: 4px solid #ef4444;
}
@media print {
    .no-print { display: none !important; }
}
</style>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<!-- Page Header -->
<div class="mb-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
            <p class="text-lg text-gray-600 mt-1">Real-time cash tracking and audit verification</p>
        </div>
        <div class="flex gap-3 no-print">
            <button onclick="window.print()" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                <i class="fas fa-print mr-2"></i>Print
            </button>
        </div>
    </div>
</div>

<!-- Branch Selector -->
<?php if ($is_superadmin): ?>
<div class="bg-white rounded-lg shadow-md p-6 mb-6">
    <form method="GET" class="flex items-center gap-4">
        <label class="text-sm font-medium text-gray-700">Select Branch:</label>
        <select name="branch_id" onchange="this.form.submit()" class="flex-1 max-w-md rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500">
            <option value="">-- Select Branch --</option>
            <?php foreach ($all_branches as $branch): ?>
                <option value="<?php echo $branch->id; ?>" <?php echo ($branch->id == $branch_id) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($branch->name); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>
<?php else: ?>
<div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6">
    <div class="flex items-center">
        <i class="fas fa-building text-blue-500 text-xl mr-3"></i>
        <div>
            <p class="text-sm font-medium text-blue-800">Your Branch</p>
            <p class="text-lg font-bold text-blue-900"><?php echo htmlspecialchars($branch_name); ?></p>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6">
    <p class="font-bold"><i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?></p>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
    <p class="font-bold"><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?></p>
</div>
<?php endif; ?>

<?php if (!$branch_id && $is_superadmin): ?>
<div class="bg-gray-100 rounded-lg shadow-md p-12 text-center">
    <i class="fas fa-arrow-up text-6xl text-gray-400 mb-4"></i>
    <h2 class="text-2xl font-bold text-gray-700 mb-2">Please Select a Branch</h2>
    <p class="text-gray-600">Choose a branch to view cash status and verify cash in box.</p>
</div>
<?php elseif ($branch_id && $cash_status): ?>

<!-- Current Cash Status - Big Display -->
<div class="bg-gradient-to-br from-green-500 to-green-600 rounded-2xl shadow-2xl p-8 mb-8 text-white">
    <div class="text-center">
        <p class="text-xl opacity-90 mb-2">💰 Current Cash in Box</p>
        <p class="cash-display">৳<?php echo number_format($cash_status->current_balance, 2); ?></p>
        <p class="text-sm opacity-75 mt-2">As of <?php echo date('h:i A, M j, Y'); ?></p>
    </div>
</div>

<!-- Today's Summary -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600 uppercase tracking-wider">Cash In (Today)</p>
                <p class="text-3xl font-bold text-green-600 mt-2">৳<?php echo number_format($today_summary->cash_in ?? 0, 2); ?></p>
            </div>
            <div class="bg-green-100 rounded-full p-4">
                <i class="fas fa-arrow-down text-2xl text-green-600"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600 uppercase tracking-wider">Cash Out (Today)</p>
                <p class="text-3xl font-bold text-red-600 mt-2">৳<?php echo number_format($today_summary->cash_out ?? 0, 2); ?></p>
            </div>
            <div class="bg-red-100 rounded-full p-4">
                <i class="fas fa-arrow-up text-2xl text-red-600"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600 uppercase tracking-wider">Transactions</p>
                <p class="text-3xl font-bold text-blue-600 mt-2"><?php echo $today_summary->transaction_count ?? 0; ?></p>
            </div>
            <div class="bg-blue-100 rounded-full p-4">
                <i class="fas fa-exchange-alt text-2xl text-blue-600"></i>
            </div>
        </div>
    </div>
</div>

<!-- Quick Verification Form -->
<div class="bg-white rounded-lg shadow-md p-6 mb-8 no-print">
    <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
        <i class="fas fa-clipboard-check text-blue-500 mr-2"></i>
        Count & Verify Cash Now
    </h2>
    <form method="POST" onsubmit="return confirm('Are you sure the counted amount is correct?');">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Expected Cash (System):</label>
                <input type="text" value="৳<?php echo number_format($cash_status->current_balance, 2); ?>" readonly class="w-full px-4 py-3 bg-gray-100 border border-gray-300 rounded-lg font-mono text-lg font-bold">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Actual Cash (Counted): *</label>
                <input type="number" step="0.01" name="actual_cash" required class="w-full px-4 py-3 border border-gray-300 rounded-lg font-mono text-lg font-bold focus:ring-2 focus:ring-blue-500" placeholder="0.00">
            </div>
            
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-2">Notes / Reason (if variance exists):</label>
                <textarea name="notes" rows="2" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="Optional: Explain any difference..."></textarea>
            </div>
        </div>
        
        <div class="mt-6 flex justify-end">
            <button type="submit" class="inline-flex items-center px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg shadow text-lg font-semibold transition-colors">
                <i class="fas fa-check-circle mr-2"></i>
                Record Verification
            </button>
        </div>
    </form>
</div>

<!-- Account Information -->
<div class="bg-white rounded-lg shadow-md p-6 mb-8">
    <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
        <i class="fas fa-info-circle text-blue-500 mr-2"></i>
        Account Information
    </h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="p-4 bg-gray-50 rounded-lg">
            <p class="text-sm text-gray-600">Account Name:</p>
            <p class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($cash_status->account_name); ?></p>
        </div>
        <div class="p-4 bg-gray-50 rounded-lg">
            <p class="text-sm text-gray-600">Opening Balance:</p>
            <p class="text-lg font-semibold text-gray-900">৳<?php echo number_format($cash_status->opening_balance, 2); ?></p>
        </div>
        <div class="p-4 bg-gray-50 rounded-lg">
            <p class="text-sm text-gray-600">Opening Date:</p>
            <p class="text-lg font-semibold text-gray-900"><?php echo date('M j, Y', strtotime($cash_status->opening_date)); ?></p>
        </div>
        <?php if ($last_eod): ?>
        <div class="p-4 bg-gray-50 rounded-lg">
            <p class="text-sm text-gray-600">Last EOD:</p>
            <p class="text-lg font-semibold text-gray-900"><?php echo date('M j, Y', strtotime($last_eod->eod_date)); ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Today's Transactions -->
<div class="bg-white rounded-lg shadow-md overflow-hidden mb-8">
    <div class="p-6 border-b border-gray-200">
        <h2 class="text-xl font-bold text-gray-800 flex items-center">
            <i class="fas fa-list text-blue-500 mr-2"></i>
            Today's Cash Movements (<?php echo date('M j, Y'); ?>)
        </h2>
    </div>
    
    <?php if (!empty($recent_transactions)): ?>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Time</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">By</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($recent_transactions as $txn): ?>
                <tr class="<?php echo $txn->transaction_type == 'cash_in' ? 'transaction-in' : 'transaction-out'; ?>">
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        <?php echo date('h:i A', strtotime($txn->transaction_date)); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?php if ($txn->transaction_type == 'cash_in'): ?>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                <i class="fas fa-arrow-down mr-1"></i>Cash In
                            </span>
                        <?php else: ?>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                <i class="fas fa-arrow-up mr-1"></i>Cash Out
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-900">
                        <?php echo htmlspecialchars($txn->description); ?>
                        <?php if ($txn->reference_type): ?>
                            <span class="text-xs text-gray-500">(Ref: <?php echo htmlspecialchars($txn->reference_type); ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right font-mono">
                        <span class="font-bold <?php echo $txn->transaction_type == 'cash_in' ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo $txn->transaction_type == 'cash_in' ? '+' : '-'; ?>৳<?php echo number_format($txn->amount, 2); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right font-mono font-bold text-gray-900">
                        ৳<?php echo number_format($txn->balance_after, 2); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        <?php echo htmlspecialchars($txn->created_by_name); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="p-8 text-center text-gray-500">
        <i class="fas fa-inbox text-4xl mb-3"></i>
        <p>No transactions recorded today yet.</p>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>

</div>

<?php require_once '../templates/footer.php'; ?>