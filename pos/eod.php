<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'accountspos-demra', 'accountspos-srg', 'dispatchpos-demra', 'dispatchpos-srg'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$user_role = $currentUser['role'] ?? '';
$pageTitle = "End of Day (EOD) Summary";
$error = null;
$branch_id = null;
$branch_name = '';
$is_superadmin = in_array($user_role, ['Superadmin', 'admin']);

// Get selected branch (for superadmin) or user's branch
$selected_branch_id = null;

if ($is_superadmin) {
    // Superadmin can select branch
    $selected_branch_id = $_GET['branch_id'] ?? null;
    
    // Get all branches for dropdown
    $all_branches = $db->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name")->results();
    
    if ($selected_branch_id) {
        $branch_check = $db->query("SELECT id, name FROM branches WHERE id = ?", [$selected_branch_id])->first();
        if ($branch_check) {
            $branch_id = $selected_branch_id;
            $branch_name = $branch_check->name;
        }
    }
} else {
    // Regular users get their assigned branch
    try {
        $employee_info = $db->query("SELECT branch_id, b.name as branch_name FROM employees e JOIN branches b ON e.branch_id = b.id WHERE e.user_id = ?", [$user_id])->first();
        if ($employee_info && $employee_info->branch_id) {
            $branch_id = $employee_info->branch_id;
            $branch_name = $employee_info->branch_name;
        } else {
            throw new Exception("Your account is not linked to a branch.");
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Check if EOD already run today
$eod_run_today = false;
$last_eod = null;
$prev_eod = null;
$today_date = date('Y-m-d');

if ($branch_id) {
    $eod_check = $db->query("SELECT * FROM eod_summary WHERE branch_id = ? AND eod_date = ? ORDER BY created_at DESC LIMIT 1", [$branch_id, $today_date])->first();
    if ($eod_check) {
        $eod_run_today = true;
        $last_eod = $eod_check;
    }
    
    // Get previous EOD
    $prev_eod = $db->query("SELECT * FROM eod_summary WHERE branch_id = ? AND eod_date < ? ORDER BY eod_date DESC LIMIT 1", [$branch_id, $today_date])->first();
}

require_once '../templates/header.php';
?>

<style>
.eod-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.8);
    z-index: 9999;
    justify-content: center;
    align-items: center;
}
.eod-overlay.active {
    display: flex;
}
.eod-animation {
    text-align: center;
    color: white;
}
.eod-spinner {
    border: 5px solid #f3f3f3;
    border-top: 5px solid #3b82f6;
    border-radius: 50%;
    width: 80px;
    height: 80px;
    animation: spin 1s linear infinite;
    margin: 0 auto 20px;
}
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
.eod-progress {
    width: 300px;
    margin: 20px auto;
}
.eod-step {
    opacity: 0.3;
    transition: opacity 0.3s;
}
.eod-step.active {
    opacity: 1;
}
@media print {
    .no-print { display: none !important; }
    body { background: white; }
}
</style>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<!-- Page Header -->
<div class="mb-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
            <p class="text-lg text-gray-600 mt-1">
                <?php echo date('l, F j, Y'); ?>
            </p>
        </div>
    </div>
</div>

<!-- Superadmin Branch Selector -->
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
        <?php if ($branch_id): ?>
            <span class="text-sm text-gray-500">
                <i class="fas fa-check-circle text-green-500"></i> Selected
            </span>
        <?php endif; ?>
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

<?php if ($error): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
        <p class="font-bold">Error</p>
        <p><?php echo htmlspecialchars($error); ?></p>
    </div>
<?php endif; ?>

<?php if (!$branch_id && $is_superadmin): ?>
    <div class="bg-gray-100 rounded-lg shadow-md p-12 text-center">
        <i class="fas fa-arrow-up text-6xl text-gray-400 mb-4"></i>
        <h2 class="text-2xl font-bold text-gray-700 mb-2">Please Select a Branch</h2>
        <p class="text-gray-600">Choose a branch from the dropdown above to view or run EOD.</p>
    </div>
<?php elseif ($branch_id): ?>

<?php if (!$eod_run_today): ?>
<!-- Run EOD Section -->
<div class="bg-white rounded-lg shadow-md p-8 mb-8 text-center">
    <div class="max-w-2xl mx-auto">
        <div class="mb-6">
            <i class="fas fa-calendar-check text-6xl text-blue-500 mb-4"></i>
            <h2 class="text-2xl font-bold text-gray-900 mb-2">Ready to Close the Day?</h2>
            <p class="text-gray-600">Run End of Day process for <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($branch_name); ?></span></p>
        </div>
        
        <?php if ($prev_eod): ?>
        <div class="bg-gray-50 rounded-lg p-4 mb-6">
            <p class="text-sm text-gray-600">Last EOD Run:</p>
            <p class="text-lg font-semibold text-gray-900"><?php echo date('F j, Y - h:i A', strtotime($prev_eod->created_at)); ?></p>
        </div>
        <?php endif; ?>
        
        <button onclick="runEOD(<?php echo $branch_id; ?>)" class="inline-flex items-center px-8 py-4 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white rounded-lg shadow-lg text-lg font-semibold transition-all transform hover:scale-105">
            <i class="fas fa-play-circle mr-3 text-2xl"></i>
            Run End of Day
        </button>
    </div>
</div>
<?php endif; ?>

<?php if ($eod_run_today && $last_eod): ?>
<!-- EOD Already Run - Show Summary -->
<div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
    <div class="flex items-center justify-between">
        <div class="flex items-center">
            <i class="fas fa-check-circle text-2xl mr-3"></i>
            <div>
                <p class="font-bold">End of Day Completed</p>
                <p>EOD was run on <?php echo date('F j, Y \a\t h:i A', strtotime($last_eod->created_at)); ?> by <?php 
                $eod_user = $db->query("SELECT display_name FROM users WHERE id = ?", [$last_eod->created_by_user_id])->first();
                echo htmlspecialchars($eod_user ? $eod_user->display_name : 'Unknown');
                ?></p>
            </div>
        </div>
        <?php if ($is_superadmin): ?>
        <button onclick="confirmReopen(<?php echo $last_eod->id; ?>, <?php echo $branch_id; ?>)" class="no-print inline-flex items-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg shadow text-sm font-medium transition-colors">
            <i class="fas fa-undo mr-2"></i>Reopen EOD
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Export Buttons -->
<div class="flex justify-end gap-3 mb-6 no-print">
    <button onclick="window.print()" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors">
        <i class="fas fa-print mr-2"></i>Print Report
    </button>
    <a href="eod_export.php?eod_id=<?php echo $last_eod->id; ?>&format=csv" class="inline-flex items-center px-4 py-2 border border-green-600 rounded-lg shadow-sm text-sm font-medium text-green-700 bg-white hover:bg-green-50 transition-colors">
        <i class="fas fa-file-csv mr-2"></i>Export CSV
    </a>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl shadow-lg p-6 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-blue-100 text-sm font-medium uppercase tracking-wider">Total Orders</p>
                <p class="text-4xl font-bold mt-1"><?php echo $last_eod->total_orders; ?></p>
            </div>
            <div class="bg-blue-400 bg-opacity-30 rounded-full p-3">
                <i class="fas fa-shopping-cart text-2xl"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-xl shadow-lg p-6 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-indigo-100 text-sm font-medium uppercase tracking-wider">Items Sold</p>
                <p class="text-4xl font-bold mt-1"><?php echo $last_eod->total_items_sold; ?></p>
            </div>
            <div class="bg-indigo-400 bg-opacity-30 rounded-full p-3">
                <i class="fas fa-box text-2xl"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl shadow-lg p-6 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-green-100 text-sm font-medium uppercase tracking-wider">Net Sales</p>
                <p class="text-3xl font-bold mt-1">৳<?php echo number_format($last_eod->net_sales, 2); ?></p>
            </div>
            <div class="bg-green-400 bg-opacity-30 rounded-full p-3">
                <i class="fas fa-money-bill-wave text-2xl"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-xl shadow-lg p-6 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-yellow-100 text-sm font-medium uppercase tracking-wider">Total Discount</p>
                <p class="text-3xl font-bold mt-1">৳<?php echo number_format($last_eod->total_discount, 2); ?></p>
            </div>
            <div class="bg-yellow-400 bg-opacity-30 rounded-full p-3">
                <i class="fas fa-tag text-2xl"></i>
            </div>
        </div>
    </div>
</div>

<!-- Payment Methods & Petty Cash -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <!-- Payment Methods -->
    <div class="bg-white rounded-lg shadow-md p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
            <i class="fas fa-credit-card text-blue-500 mr-2"></i>
            Payment Methods Breakdown
        </h2>
        <?php 
        $payment_methods = json_decode($last_eod->payment_methods_json ?? '[]', true);
        if ($payment_methods && is_array($payment_methods) && !empty($payment_methods)):
        ?>
        <div class="space-y-3">
            <?php foreach ($payment_methods as $method => $data): ?>
            <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                <div>
                    <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($method); ?></p>
                    <p class="text-sm text-gray-500"><?php echo $data['count']; ?> transaction<?php echo $data['count'] != 1 ? 's' : ''; ?></p>
                </div>
                <p class="text-lg font-bold text-blue-600">৳<?php echo number_format($data['amount'], 2); ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-gray-500">No payment data available.</p>
        <?php endif; ?>
    </div>
    
    <!-- Petty Cash Reconciliation -->
    <div class="bg-white rounded-lg shadow-md p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
            <i class="fas fa-wallet text-green-500 mr-2"></i>
            Petty Cash Reconciliation
        </h2>
        <div class="space-y-3">
            <div class="flex justify-between items-center py-2 border-b border-gray-200">
                <span class="text-gray-600">Opening Balance:</span>
                <span class="font-mono font-semibold">৳<?php echo number_format($last_eod->opening_cash ?? 0, 2); ?></span>
            </div>
            <div class="flex justify-between items-center py-2 border-b border-gray-200">
                <span class="text-gray-600">Cash Sales:</span>
                <span class="font-mono font-semibold text-green-600">+৳<?php echo number_format($last_eod->cash_sales ?? 0, 2); ?></span>
            </div>
            <div class="flex justify-between items-center py-2 border-b border-gray-200">
                <span class="text-gray-600">Cash Withdrawals:</span>
                <span class="font-mono font-semibold text-red-600">-৳<?php echo number_format($last_eod->cash_withdrawals ?? 0, 2); ?></span>
            </div>
            <div class="flex justify-between items-center py-3 bg-blue-50 rounded-lg px-3">
                <span class="font-bold text-gray-800">Expected Closing:</span>
                <span class="font-mono font-bold text-blue-600 text-lg">৳<?php echo number_format($last_eod->expected_cash ?? 0, 2); ?></span>
            </div>
            <div class="flex justify-between items-center py-3 bg-green-50 rounded-lg px-3">
                <span class="font-bold text-gray-800">Actual Closing:</span>
                <span class="font-mono font-bold text-green-600 text-lg">৳<?php echo number_format($last_eod->actual_cash ?? 0, 2); ?></span>
            </div>
            <?php 
            $variance = ($last_eod->actual_cash ?? 0) - ($last_eod->expected_cash ?? 0);
            if (abs($variance) > 0.01):
            ?>
            <div class="flex justify-between items-center py-3 bg-<?php echo $variance >= 0 ? 'yellow' : 'red'; ?>-50 rounded-lg px-3">
                <span class="font-bold text-gray-800">Variance:</span>
                <span class="font-mono font-bold text-<?php echo $variance >= 0 ? 'yellow' : 'red'; ?>-600 text-lg">
                    <?php echo $variance >= 0 ? '+' : ''; ?>৳<?php echo number_format($variance, 2); ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="mt-6 p-4 bg-gray-50 rounded-lg">
            <p class="text-sm font-semibold text-gray-700 mb-2">Next Steps:</p>
            <ol class="text-sm text-gray-600 space-y-1 list-decimal list-inside">
                <li>Transfer cash to branch petty cash account</li>
                <li>Record in internal transfer module</li>
                <li>Deposit to bank account</li>
            </ol>
        </div>
    </div>
</div>

<!-- Top Products -->
<div class="bg-white rounded-lg shadow-md p-6 mb-8">
    <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
        <i class="fas fa-star text-yellow-500 mr-2"></i>
        Top Selling Products
    </h2>
    <?php 
    $top_products = json_decode($last_eod->top_products_json ?? '[]', true);
    if ($top_products && is_array($top_products) && !empty($top_products)):
    ?>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rank</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Product</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Qty Sold</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Revenue</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php 
                $rank = 1;
                foreach ($top_products as $product): 
                ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-100 text-blue-600 font-bold">
                            <?php echo $rank++; ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($product['name']); ?></td>
                    <td class="px-6 py-4 text-sm text-gray-500 text-center font-mono"><?php echo $product['quantity']; ?></td>
                    <td class="px-6 py-4 text-sm text-gray-900 text-right font-mono font-bold">৳<?php echo number_format($product['revenue'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p class="text-gray-500">No product data available.</p>
    <?php endif; ?>
</div>

<!-- Additional Stats -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="bg-white rounded-lg shadow-md p-6">
        <h3 class="text-lg font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-chart-line text-purple-500 mr-2"></i>
            Average Order Value
        </h3>
        <p class="text-3xl font-bold text-purple-600">
            ৳<?php 
            $total_orders = intval($last_eod->total_orders ?? 0);
            echo $total_orders > 0 ? number_format($last_eod->net_sales / $total_orders, 2) : '0.00'; 
            ?>
        </p>
    </div>
    
    <div class="bg-white rounded-lg shadow-md p-6">
        <h3 class="text-lg font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-percentage text-orange-500 mr-2"></i>
            Discount Rate
        </h3>
        <p class="text-3xl font-bold text-orange-600">
            <?php 
            $gross_sales = floatval($last_eod->gross_sales ?? 0);
            echo ($gross_sales > 0) ? number_format(($last_eod->total_discount / $gross_sales) * 100, 1) : '0.0'; 
            ?>%
        </p>
    </div>
    
    <div class="bg-white rounded-lg shadow-md p-6">
        <h3 class="text-lg font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-clock text-blue-500 mr-2"></i>
            Peak Hour
        </h3>
        <p class="text-3xl font-bold text-blue-600">
            <?php echo $last_eod->peak_hour ?? 'N/A'; ?>
        </p>
    </div>
</div>

<?php endif; ?>
<?php endif; ?>

</div>

<!-- EOD Processing Overlay -->
<div id="eodOverlay" class="eod-overlay">
    <div class="eod-animation">
        <div class="eod-spinner"></div>
        <h2 class="text-2xl font-bold mb-4">Processing End of Day...</h2>
        <div class="eod-progress">
            <div id="step1" class="eod-step mb-2">
                <i class="fas fa-spinner fa-spin mr-2"></i>Calculating sales summary...
            </div>
            <div id="step2" class="eod-step mb-2">
                <i class="fas fa-spinner fa-spin mr-2"></i>Processing payment methods...
            </div>
            <div id="step3" class="eod-step mb-2">
                <i class="fas fa-spinner fa-spin mr-2"></i>Reconciling petty cash...
            </div>
            <div id="step4" class="eod-step mb-2">
                <i class="fas fa-spinner fa-spin mr-2"></i>Analyzing product sales...
            </div>
            <div id="step5" class="eod-step mb-2">
                <i class="fas fa-spinner fa-spin mr-2"></i>Generating reports...
            </div>
            <div id="step6" class="eod-step mb-2">
                <i class="fas fa-spinner fa-spin mr-2"></i>Finalizing EOD...
            </div>
        </div>
    </div>
</div>

<script>
function runEOD(branchId) {
    const overlay = document.getElementById('eodOverlay');
    overlay.classList.add('active');
    
    const steps = ['step1', 'step2', 'step3', 'step4', 'step5', 'step6'];
    let currentStep = 0;
    
    function activateNextStep() {
        if (currentStep < steps.length) {
            document.getElementById(steps[currentStep]).classList.add('active');
            currentStep++;
            setTimeout(activateNextStep, 1000);
        } else {
            processEOD(branchId);
        }
    }
    
    activateNextStep();
}

function processEOD(branchId) {
    fetch('eod_process.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ branch_id: branchId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert('Error processing EOD: ' + data.message);
            document.getElementById('eodOverlay').classList.remove('active');
        }
    })
    .catch(error => {
        alert('Error: ' + error);
        document.getElementById('eodOverlay').classList.remove('active');
    });
}

function confirmReopen(eodId, branchId) {
    if (confirm('⚠️ WARNING: Reopening EOD will allow modifications to closed day transactions.\n\nThis action will be logged in the audit trail.\n\nPlease provide a reason for reopening:\n\n(Click OK to continue)')) {
        const reason = prompt('Reason for reopening EOD:');
        if (reason && reason.trim()) {
            reopenEOD(eodId, branchId, reason);
        }
    }
}

function reopenEOD(eodId, branchId, reason) {
    fetch('eod_reopen.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ 
            eod_id: eodId,
            branch_id: branchId,
            reason: reason
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('EOD reopened successfully. The system is now unlocked for modifications.');
            window.location.reload();
        } else {
            alert('Error reopening EOD: ' + data.message);
        }
    })
    .catch(error => {
        alert('Error: ' + error);
    });
}
</script>

<?php require_once '../templates/footer.php'; ?>