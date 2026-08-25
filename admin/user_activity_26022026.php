<?php
/**
 * User Activity / Audit Trail Page
 * Superadmin only - View all user activities across the system
 * FIXED VERSION - All SQL and display errors corrected
 */

require_once '../core/init.php';
require_once '../core/classes/AuditLogger.php';

global $db;

// Superadmin only access
if (($_SESSION['user_role'] ?? '') !== 'Superadmin') {
    $_SESSION['error_flash'] = 'Only Superadmin can access user activity logs.';
    header('Location: ' . url('index.php'));
    exit();
}

// Get filter parameters
$selectedUserId = $_GET['user_id'] ?? '';
$selectedModule = $_GET['module'] ?? '';
$selectedAction = $_GET['action'] ?? '';
$selectedSeverity = $_GET['severity'] ?? '';
$startDate = $_GET['start_date'] ?? ''; // NO DEFAULT - show all logs
$endDate = $_GET['end_date'] ?? ''; // NO DEFAULT - show all logs
$search = $_GET['search'] ?? '';
$limit = max(1, min(1000, (int)($_GET['limit'] ?? 100))); // Safe integer between 1-1000

// Build SQL query
$sql = "SELECT 
        sal.*,
        u.display_name as user_name,
        u.email as user_email,
        u.role as user_role
    FROM system_audit_log sal
    LEFT JOIN users u ON sal.user_id = u.id
    WHERE 1=1";

$params = [];

if ($selectedUserId) {
    $sql .= " AND sal.user_id = ?";
    $params[] = $selectedUserId;
}

if ($selectedModule) {
    $sql .= " AND sal.module = ?";
    $params[] = $selectedModule;
}

if ($selectedAction) {
    $sql .= " AND sal.action = ?";
    $params[] = $selectedAction;
}

if ($selectedSeverity) {
    $sql .= " AND sal.severity = ?";
    $params[] = $selectedSeverity;
}

if ($startDate) {
    $sql .= " AND DATE(sal.created_at) >= ?";
    $params[] = $startDate;
}

if ($endDate) {
    $sql .= " AND DATE(sal.created_at) <= ?";
    $params[] = $endDate;
}

if ($search) {
    $sql .= " AND (sal.description LIKE ? OR sal.reference_number LIKE ? OR u.display_name LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

// FIX: Add LIMIT directly to SQL, not as parameter
$sql .= " ORDER BY sal.created_at DESC LIMIT " . $limit;

$activities = $db->query($sql, $params)->results();

// Get statistics
$statsSQL = "SELECT 
        COUNT(*) as total_actions,
        COUNT(DISTINCT user_id) as unique_users,
        COUNT(DISTINCT DATE(created_at)) as active_days,
        COUNT(CASE WHEN severity = 'critical' THEN 1 END) as critical_count
    FROM system_audit_log
    WHERE 1=1";

$statsParams = [];

if ($startDate && $endDate) {
    $statsSQL .= " AND DATE(created_at) BETWEEN ? AND ?";
    $statsParams[] = $startDate;
    $statsParams[] = $endDate;
} elseif ($startDate) {
    $statsSQL .= " AND DATE(created_at) >= ?";
    $statsParams[] = $startDate;
} elseif ($endDate) {
    $statsSQL .= " AND DATE(created_at) <= ?";
    $statsParams[] = $endDate;
}

if ($selectedUserId) {
    $statsSQL .= " AND user_id = ?";
    $statsParams[] = $selectedUserId;
}

$stats = $db->query($statsSQL, $statsParams)->first();

// Get all users for dropdown
$allUsers = $db->query("SELECT id, display_name, role, email FROM users WHERE status = 'active' ORDER BY display_name")->results();

// Get module breakdown
$moduleStatsSQL = "SELECT 
        module,
        COUNT(*) as count
    FROM system_audit_log
    WHERE 1=1";

$moduleStatsParams = [];

if ($startDate && $endDate) {
    $moduleStatsSQL .= " AND DATE(created_at) BETWEEN ? AND ?";
    $moduleStatsParams[] = $startDate;
    $moduleStatsParams[] = $endDate;
} elseif ($startDate) {
    $moduleStatsSQL .= " AND DATE(created_at) >= ?";
    $moduleStatsParams[] = $startDate;
} elseif ($endDate) {
    $moduleStatsSQL .= " AND DATE(created_at) <= ?";
    $moduleStatsParams[] = $endDate;
}

if ($selectedUserId) {
    $moduleStatsSQL .= " AND user_id = ?";
    $moduleStatsParams[] = $selectedUserId;
}

$moduleStatsSQL .= " GROUP BY module ORDER BY count DESC LIMIT 10";

$moduleStats = $db->query($moduleStatsSQL, $moduleStatsParams)->results();

include '../templates/header.php';
?>

<div class="space-y-6">
    
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-history text-primary-600"></i>
                User Activity & Audit Trail
            </h1>
            <p class="mt-1 text-gray-600">Monitor all user activities and system changes</p>
        </div>
        <div class="flex gap-2">
            <button onclick="exportToExcel()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                <i class="fas fa-file-excel mr-2"></i>Export
            </button>
            <button onclick="window.print()" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors">
                <i class="fas fa-print mr-2"></i>Print
            </button>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl shadow-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-100 text-sm font-medium">Total Actions</p>
                    <p class="text-3xl font-bold mt-2"><?= number_format($stats->total_actions ?? 0) ?></p>
                </div>
                <div class="bg-blue-400 bg-opacity-30 rounded-full p-3">
                    <i class="fas fa-chart-line text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl shadow-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-purple-100 text-sm font-medium">Active Users</p>
                    <p class="text-3xl font-bold mt-2"><?= number_format($stats->unique_users ?? 0) ?></p>
                </div>
                <div class="bg-purple-400 bg-opacity-30 rounded-full p-3">
                    <i class="fas fa-users text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl shadow-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-green-100 text-sm font-medium">Active Days</p>
                    <p class="text-3xl font-bold mt-2"><?= number_format($stats->active_days ?? 0) ?></p>
                </div>
                <div class="bg-green-400 bg-opacity-30 rounded-full p-3">
                    <i class="fas fa-calendar-check text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-red-500 to-red-600 rounded-xl shadow-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-red-100 text-sm font-medium">Critical Actions</p>
                    <p class="text-3xl font-bold mt-2"><?= number_format($stats->critical_count ?? 0) ?></p>
                </div>
                <div class="bg-red-400 bg-opacity-30 rounded-full p-3">
                    <i class="fas fa-exclamation-triangle text-2xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-xl shadow-md p-6">
        <form method="GET" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                
                <!-- User Filter -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-user text-primary-600"></i> User
                    </label>
                    <select name="user_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        <option value="">All Users</option>
                        <?php foreach ($allUsers as $user): ?>
                            <option value="<?= $user->id ?>" <?= $selectedUserId == $user->id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($user->display_name) ?> (<?= $user->role ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Module Filter -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-cube text-primary-600"></i> Module
                    </label>
                    <select name="module" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        <option value="">All Modules</option>
                        <option value="expense" <?= $selectedModule == 'expense' ? 'selected' : '' ?>>Expense</option>
                        <option value="credit_order" <?= $selectedModule == 'credit_order' ? 'selected' : '' ?>>Credit Order</option>
                        <option value="customer" <?= $selectedModule == 'customer' ? 'selected' : '' ?>>Customer</option>
                        <option value="customer_payment" <?= $selectedModule == 'customer_payment' ? 'selected' : '' ?>>Customer Payment</option>
                        <option value="supplier" <?= $selectedModule == 'supplier' ? 'selected' : '' ?>>Supplier</option>
                        <option value="supplier_payment" <?= $selectedModule == 'supplier_payment' ? 'selected' : '' ?>>Supplier Payment</option>
                        <option value="production" <?= $selectedModule == 'production' ? 'selected' : '' ?>>Production</option>
                        <option value="shipping" <?= $selectedModule == 'shipping' ? 'selected' : '' ?>>Shipping</option>
                        <option value="dispatch" <?= $selectedModule == 'dispatch' ? 'selected' : '' ?>>Dispatch</option>
                        <option value="purchase" <?= $selectedModule == 'purchase' ? 'selected' : '' ?>>Purchase</option>
                        <option value="wheat_shipment" <?= $selectedModule == 'wheat_shipment' ? 'selected' : '' ?>>Wheat Shipment</option>
                        <option value="inventory" <?= $selectedModule == 'inventory' ? 'selected' : '' ?>>Inventory</option>
                        <option value="vehicle" <?= $selectedModule == 'vehicle' ? 'selected' : '' ?>>Vehicle</option>
                        <option value="driver" <?= $selectedModule == 'driver' ? 'selected' : '' ?>>Driver</option>
                        <option value="employee" <?= $selectedModule == 'employee' ? 'selected' : '' ?>>Employee</option>
                        <option value="user_management" <?= $selectedModule == 'user_management' ? 'selected' : '' ?>>User Management</option>
                        <option value="account" <?= $selectedModule == 'account' ? 'selected' : '' ?>>Accounting</option>
                        <option value="petty_cash" <?= $selectedModule == 'petty_cash' ? 'selected' : '' ?>>Petty Cash</option>
                        <option value="bank_account" <?= $selectedModule == 'bank_account' ? 'selected' : '' ?>>Bank Account</option>
                    </select>
                </div>

                <!-- Action Filter -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-bolt text-primary-600"></i> Action
                    </label>
                    <select name="action" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        <option value="">All Actions</option>
                        <option value="created" <?= $selectedAction == 'created' ? 'selected' : '' ?>>Created</option>
                        <option value="updated" <?= $selectedAction == 'updated' ? 'selected' : '' ?>>Updated</option>
                        <option value="deleted" <?= $selectedAction == 'deleted' ? 'selected' : '' ?>>Deleted</option>
                        <option value="approved" <?= $selectedAction == 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="rejected" <?= $selectedAction == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        <option value="shipped" <?= $selectedAction == 'shipped' ? 'selected' : '' ?>>Shipped</option>
                        <option value="dispatched" <?= $selectedAction == 'dispatched' ? 'selected' : '' ?>>Dispatched</option>
                        <option value="received" <?= $selectedAction == 'received' ? 'selected' : '' ?>>Received</option>
                        <option value="allocated" <?= $selectedAction == 'allocated' ? 'selected' : '' ?>>Allocated</option>
                        <option value="paid" <?= $selectedAction == 'paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="logged_in" <?= $selectedAction == 'logged_in' ? 'selected' : '' ?>>Logged In</option>
                        <option value="logged_out" <?= $selectedAction == 'logged_out' ? 'selected' : '' ?>>Logged Out</option>
                        <option value="status_changed" <?= $selectedAction == 'status_changed' ? 'selected' : '' ?>>Status Changed</option>
                        <option value="viewed" <?= $selectedAction == 'viewed' ? 'selected' : '' ?>>Viewed</option>
                        <option value="printed" <?= $selectedAction == 'printed' ? 'selected' : '' ?>>Printed</option>
                        <option value="exported" <?= $selectedAction == 'exported' ? 'selected' : '' ?>>Exported</option>
                    </select>
                </div>

                <!-- Severity Filter -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-exclamation-circle text-primary-600"></i> Severity
                    </label>
                    <select name="severity" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        <option value="">All Severity</option>
                        <option value="info" <?= $selectedSeverity == 'info' ? 'selected' : '' ?>>Info</option>
                        <option value="warning" <?= $selectedSeverity == 'warning' ? 'selected' : '' ?>>Warning</option>
                        <option value="critical" <?= $selectedSeverity == 'critical' ? 'selected' : '' ?>>Critical</option>
                    </select>
                </div>

                <!-- Start Date -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-calendar text-primary-600"></i> Start Date
                    </label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                </div>

                <!-- End Date -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-calendar text-primary-600"></i> End Date
                    </label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                </div>

                <!-- Search -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-search text-primary-600"></i> Search
                    </label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                           placeholder="Search description, reference..." 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                </div>

                <!-- Limit -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-list text-primary-600"></i> Limit
                    </label>
                    <select name="limit" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
                        <option value="200" <?= $limit == 200 ? 'selected' : '' ?>>200</option>
                        <option value="500" <?= $limit == 500 ? 'selected' : '' ?>>500</option>
                        <option value="1000" <?= $limit == 1000 ? 'selected' : '' ?>>1000</option>
                    </select>
                </div>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="px-6 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700 transition-colors">
                    <i class="fas fa-filter mr-2"></i>Apply Filters
                </button>
                <a href="user_activity.php" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">
                    <i class="fas fa-redo mr-2"></i>Reset
                </a>
            </div>
        </form>
    </div>

    <!-- Module Breakdown Chart -->
    <?php if (!empty($moduleStats)): ?>
    <div class="bg-white rounded-xl shadow-md p-6">
        <h2 class="text-lg font-bold text-gray-900 mb-4">
            <i class="fas fa-chart-bar text-primary-600"></i> Activity by Module
        </h2>
        <div class="space-y-3">
            <?php 
            $maxCount = $moduleStats[0]->count ?? 1;
            foreach ($moduleStats as $moduleStat): 
                $percentage = ($moduleStat->count / $maxCount) * 100;
            ?>
                <div>
                    <div class="flex justify-between text-sm mb-1">
                        <span class="font-medium text-gray-700 capitalize"><?= str_replace('_', ' ', $moduleStat->module) ?></span>
                        <span class="text-gray-600"><?= number_format($moduleStat->count) ?></span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="bg-primary-600 h-2 rounded-full" style="width: <?= $percentage ?>%"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Activity Table -->
    <div class="bg-white rounded-xl shadow-md overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-bold text-gray-900">
                <i class="fas fa-list text-primary-600"></i> Activity Log
                <span class="text-sm font-normal text-gray-600">(<?= count($activities) ?> records)</span>
            </h2>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Timestamp</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Module</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reference</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Severity</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">IP</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($activities)): ?>
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-inbox text-4xl mb-3"></i>
                                <p>No activities found matching your criteria</p>
                                <p class="text-sm mt-2">Try adjusting your filters or click Reset to see all logs</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($activities as $activity): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?= date('Y-m-d H:i:s', strtotime($activity->created_at)) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-8 w-8 bg-primary-100 rounded-full flex items-center justify-center">
                                            <span class="text-primary-700 font-medium text-xs">
                                                <?= strtoupper(substr($activity->user_name ?? 'U', 0, 1)) ?>
                                            </span>
                                        </div>
                                        <div class="ml-3">
                                            <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($activity->user_name ?? 'Unknown') ?></div>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars($activity->user_role ?? '') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 text-xs font-medium rounded-full 
                                        <?php
                                        $moduleColors = [
                                            'expense' => 'bg-blue-100 text-blue-800',
                                            'credit_order' => 'bg-green-100 text-green-800',
                                            'customer_payment' => 'bg-purple-100 text-purple-800',
                                            'shipping' => 'bg-yellow-100 text-yellow-800',
                                            'user_management' => 'bg-pink-100 text-pink-800',
                                            'purchase' => 'bg-orange-100 text-orange-800',
                                            'wheat_shipment' => 'bg-amber-100 text-amber-800',
                                        ];
                                        echo $moduleColors[$activity->module] ?? 'bg-gray-100 text-gray-800';
                                        ?>">
                                        <?= ucfirst(str_replace('_', ' ', $activity->module)) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 text-xs font-medium rounded-full 
                                        <?php
                                        $actionColors = [
                                            'created' => 'bg-green-100 text-green-800',
                                            'updated' => 'bg-blue-100 text-blue-800',
                                            'deleted' => 'bg-red-100 text-red-800',
                                            'approved' => 'bg-emerald-100 text-emerald-800',
                                            'rejected' => 'bg-orange-100 text-orange-800',
                                            'shipped' => 'bg-indigo-100 text-indigo-800',
                                        ];
                                        echo $actionColors[$activity->action] ?? 'bg-gray-100 text-gray-800';
                                        ?>">
                                        <?= ucfirst($activity->action) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900 max-w-md truncate" title="<?= htmlspecialchars($activity->description) ?>">
                                    <?= htmlspecialchars($activity->description) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <?php if ($activity->reference_number): ?>
                                        <?php
                                        // Generate link based on module and reference
                                        $viewLink = '';
                                        if (str_starts_with($activity->reference_number, 'EXP-')) {
                                            $viewLink = url("expense/view_expense_voucher.php?id=" . $activity->record_id);
                                        } elseif (str_starts_with($activity->reference_number, 'CR-')) {
                                            $viewLink = url("orders/view_credit_order.php?id=" . $activity->record_id);
                                        }
                                        ?>
                                        <?php if ($viewLink): ?>
                                            <a href="<?= $viewLink ?>" class="text-primary-600 hover:text-primary-900 font-medium">
                                                <?= htmlspecialchars($activity->reference_number) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-gray-700 font-medium"><?= htmlspecialchars($activity->reference_number) ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-gray-400">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 text-xs font-medium rounded-full 
                                        <?php
                                        $severityColors = [
                                            'info' => 'bg-gray-100 text-gray-800',
                                            'warning' => 'bg-yellow-100 text-yellow-800',
                                            'critical' => 'bg-red-100 text-red-800',
                                        ];
                                        echo $severityColors[$activity->severity] ?? 'bg-gray-100 text-gray-800';
                                        ?>">
                                        <?= ucfirst($activity->severity) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?= htmlspecialchars($activity->ip_address ?? '—') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
function exportToExcel() {
    const table = document.querySelector('table');
    let html = '<table>';
    html += table.innerHTML;
    html += '</table>';
    
    const blob = new Blob([html], {
        type: 'application/vnd.ms-excel'
    });
    
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'user_activity_<?= date('Y-m-d') ?>.xls';
    a.click();
}
</script>

<?php include '../templates/footer.php'; ?>