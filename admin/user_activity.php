<?php
/**
 * User Activity / Audit Trail Page
 * Superadmin only - View all user activities across the system
 * UPDATED: Now shows authentication events (login/logout)
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
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$search = $_GET['search'] ?? '';
$limit = max(1, min(1000, (int)($_GET['limit'] ?? 100)));

// ✅ FIX: Include authentication events by checking for original_action in metadata
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
    // ✅ FIX: Handle authentication module filter
    if ($selectedModule === 'authentication') {
        $sql .= " AND (sal.module = 'user_management' AND sal.record_type = 'user_session')";
    } else {
        $sql .= " AND sal.module = ?";
        $params[] = $selectedModule;
    }
}

if ($selectedAction) {
    // ✅ FIX: Handle authentication action filters
    $authActions = ['logged_in', 'logged_out', 'login_failed', 'login_error', 'session_timeout'];
    if (in_array($selectedAction, $authActions)) {
        if (in_array($selectedAction, ['logged_in', 'logged_out'])) {
            // These exist in ENUM, use directly
            $sql .= " AND sal.action = ?";
            $params[] = $selectedAction;
        } else {
            // These are stored as 'other', check metadata
            $sql .= " AND (sal.action = ? OR JSON_EXTRACT(sal.metadata, '$.original_action') = ?)";
            $params[] = 'other';
            $params[] = $selectedAction;
        }
    } else {
        $sql .= " AND sal.action = ?";
        $params[] = $selectedAction;
    }
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
    $sql .= " AND (sal.description LIKE ? OR sal.reference_number LIKE ? OR u.display_name LIKE ? OR u.email LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$sql .= " ORDER BY sal.created_at DESC LIMIT " . $limit;

$activities = $db->query($sql, $params)->results();

// Get statistics
$statsSQL = "SELECT 
        COUNT(*) as total_actions,
        COUNT(DISTINCT user_id) as unique_users,
        COUNT(DISTINCT DATE(created_at)) as active_days,
        COUNT(CASE WHEN severity = 'critical' THEN 1 END) as critical_count,
        COUNT(CASE WHEN module = 'user_management' AND record_type = 'user_session' THEN 1 END) as auth_count
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

// Get module breakdown - ✅ FIX: Group authentication events properly
$moduleStatsSQL = "SELECT 
        CASE 
            WHEN module = 'user_management' AND record_type = 'user_session' THEN 'authentication'
            ELSE module
        END as module,
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
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl shadow-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-100 text-sm font-medium mb-1">Total Actions</p>
                    <p class="text-3xl font-bold"><?= number_format($stats->total_actions ?? 0) ?></p>
                </div>
                <div class="bg-white bg-opacity-20 p-3 rounded-lg">
                    <i class="fas fa-chart-line text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl shadow-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-green-100 text-sm font-medium mb-1">Active Users</p>
                    <p class="text-3xl font-bold"><?= number_format($stats->unique_users ?? 0) ?></p>
                </div>
                <div class="bg-white bg-opacity-20 p-3 rounded-lg">
                    <i class="fas fa-users text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl shadow-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-purple-100 text-sm font-medium mb-1">Active Days</p>
                    <p class="text-3xl font-bold"><?= number_format($stats->active_days ?? 0) ?></p>
                </div>
                <div class="bg-white bg-opacity-20 p-3 rounded-lg">
                    <i class="fas fa-calendar-alt text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-red-500 to-red-600 rounded-xl shadow-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-red-100 text-sm font-medium mb-1">Critical Actions</p>
                    <p class="text-3xl font-bold"><?= number_format($stats->critical_count ?? 0) ?></p>
                </div>
                <div class="bg-white bg-opacity-20 p-3 rounded-lg">
                    <i class="fas fa-exclamation-triangle text-2xl"></i>
                </div>
            </div>
        </div>

        <!-- ✅ NEW: Authentication Statistics -->
        <div class="bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-xl shadow-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-indigo-100 text-sm font-medium mb-1">Login/Logout</p>
                    <p class="text-3xl font-bold"><?= number_format($stats->auth_count ?? 0) ?></p>
                </div>
                <div class="bg-white bg-opacity-20 p-3 rounded-lg">
                    <i class="fas fa-sign-in-alt text-2xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-xl shadow-md p-6">
        <form method="GET" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <!-- User Filter -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Filter by User</label>
                    <select name="user_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        <option value="">All Users</option>
                        <?php foreach ($allUsers as $user): ?>
                            <option value="<?= $user->id ?>" <?= $selectedUserId == $user->id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($user->display_name) ?> (<?= htmlspecialchars($user->role) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Module Filter - ✅ FIX: Add authentication option -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Filter by Module</label>
                    <select name="module" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        <option value="">All Modules</option>
                        <option value="authentication" <?= $selectedModule === 'authentication' ? 'selected' : '' ?>>🔐 Authentication (Login/Logout)</option>
                        <option value="expense" <?= $selectedModule === 'expense' ? 'selected' : '' ?>>Expense</option>
                        <option value="credit_order" <?= $selectedModule === 'credit_order' ? 'selected' : '' ?>>Credit Order</option>
                        <option value="customer_payment" <?= $selectedModule === 'customer_payment' ? 'selected' : '' ?>>Customer Payment</option>
                        <option value="purchase" <?= $selectedModule === 'purchase' ? 'selected' : '' ?>>Purchase</option>
                        <option value="shipping" <?= $selectedModule === 'shipping' ? 'selected' : '' ?>>Shipping</option>
                        <option value="user_management" <?= $selectedModule === 'user_management' ? 'selected' : '' ?>>User Management</option>
                        <option value="other" <?= $selectedModule === 'other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>

                <!-- Action Filter - ✅ FIX: Add auth actions -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Filter by Action</label>
                    <select name="action" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        <option value="">All Actions</option>
                        <optgroup label="Authentication">
                            <option value="logged_in" <?= $selectedAction === 'logged_in' ? 'selected' : '' ?>>Logged In</option>
                            <option value="logged_out" <?= $selectedAction === 'logged_out' ? 'selected' : '' ?>>Logged Out</option>
                            <option value="login_failed" <?= $selectedAction === 'login_failed' ? 'selected' : '' ?>>Login Failed</option>
                            <option value="session_timeout" <?= $selectedAction === 'session_timeout' ? 'selected' : '' ?>>Session Timeout</option>
                        </optgroup>
                        <optgroup label="General">
                            <option value="created" <?= $selectedAction === 'created' ? 'selected' : '' ?>>Created</option>
                            <option value="updated" <?= $selectedAction === 'updated' ? 'selected' : '' ?>>Updated</option>
                            <option value="deleted" <?= $selectedAction === 'deleted' ? 'selected' : '' ?>>Deleted</option>
                            <option value="approved" <?= $selectedAction === 'approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="status_changed" <?= $selectedAction === 'status_changed' ? 'selected' : '' ?>>Status Changed</option>
                        </optgroup>
                    </select>
                </div>

                <!-- Severity Filter -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Filter by Severity</label>
                    <select name="severity" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        <option value="">All Severities</option>
                        <option value="info" <?= $selectedSeverity === 'info' ? 'selected' : '' ?>>Info</option>
                        <option value="warning" <?= $selectedSeverity === 'warning' ? 'selected' : '' ?>>Warning</option>
                        <option value="critical" <?= $selectedSeverity === 'critical' ? 'selected' : '' ?>>Critical</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <!-- Date Range -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Start Date</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" 
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">End Date</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" 
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                </div>

                <!-- Search -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                           placeholder="Search description, reference..."
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                </div>

                <!-- Limit -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Results Limit</label>
                    <select name="limit" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
                        <option value="250" <?= $limit == 250 ? 'selected' : '' ?>>250</option>
                        <option value="500" <?= $limit == 500 ? 'selected' : '' ?>>500</option>
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

    <!-- Module Activity Breakdown -->
    <?php if (!empty($moduleStats)): ?>
    <div class="bg-white rounded-xl shadow-md p-6">
        <h2 class="text-lg font-bold text-gray-900 mb-4">
            <i class="fas fa-chart-bar text-primary-600"></i> Activity Breakdown by Module
        </h2>
        <div class="space-y-3">
            <?php 
            $maxCount = $moduleStats[0]->count ?? 1;
            foreach ($moduleStats as $moduleStat): 
                $percentage = ($moduleStat->count / $maxCount) * 100;
                // ✅ FIX: Display authentication module properly
                $displayModule = $moduleStat->module === 'authentication' ? '🔐 Authentication' : ucfirst(str_replace('_', ' ', $moduleStat->module));
            ?>
                <div>
                    <div class="flex justify-between text-sm mb-1">
                        <span class="font-medium text-gray-700"><?= $displayModule ?></span>
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
                        <?php foreach ($activities as $activity): 
                            // ✅ FIX: Extract original action from metadata if exists
                            $displayAction = $activity->action;
                            $displayModule = $activity->module;
                            
                            if ($activity->record_type === 'user_session') {
                                $displayModule = 'authentication';
                                // Try to get original action from metadata
                                if ($activity->metadata) {
                                    $metadata = json_decode($activity->metadata, true);
                                    if (isset($metadata['original_action'])) {
                                        $displayAction = $metadata['original_action'];
                                    }
                                }
                            }
                        ?>
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
                                            'authentication' => 'bg-indigo-100 text-indigo-800',
                                            'expense' => 'bg-blue-100 text-blue-800',
                                            'credit_order' => 'bg-green-100 text-green-800',
                                            'customer_payment' => 'bg-purple-100 text-purple-800',
                                            'shipping' => 'bg-yellow-100 text-yellow-800',
                                            'user_management' => 'bg-pink-100 text-pink-800',
                                            'purchase' => 'bg-orange-100 text-orange-800',
                                            'wheat_shipment' => 'bg-amber-100 text-amber-800',
                                        ];
                                        echo $moduleColors[$displayModule] ?? 'bg-gray-100 text-gray-800';
                                        ?>">
                                        <?php
                                        if ($displayModule === 'authentication') {
                                            echo '🔐 Authentication';
                                        } else {
                                            echo ucfirst(str_replace('_', ' ', $displayModule));
                                        }
                                        ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 text-xs font-medium rounded-full 
                                        <?php
                                        $actionColors = [
                                            'logged_in' => 'bg-green-100 text-green-800',
                                            'logged_out' => 'bg-blue-100 text-blue-800',
                                            'login_failed' => 'bg-red-100 text-red-800',
                                            'login_error' => 'bg-red-100 text-red-800',
                                            'session_timeout' => 'bg-orange-100 text-orange-800',
                                            'created' => 'bg-green-100 text-green-800',
                                            'updated' => 'bg-blue-100 text-blue-800',
                                            'deleted' => 'bg-red-100 text-red-800',
                                            'approved' => 'bg-emerald-100 text-emerald-800',
                                            'rejected' => 'bg-orange-100 text-orange-800',
                                            'shipped' => 'bg-indigo-100 text-indigo-800',
                                        ];
                                        echo $actionColors[$displayAction] ?? 'bg-gray-100 text-gray-800';
                                        ?>">
                                        <?= ucfirst(str_replace('_', ' ', $displayAction)) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900 max-w-md truncate" title="<?= htmlspecialchars($activity->description) ?>">
                                    <?= htmlspecialchars($activity->description) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <?php if ($activity->reference_number): ?>
                                        <span class="text-gray-700 font-medium"><?= htmlspecialchars($activity->reference_number) ?></span>
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