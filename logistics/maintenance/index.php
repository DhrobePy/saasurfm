<?php
require_once '../../core/init.php';

// Access control
$allowed_roles = ['Superadmin', 'admin', 'Transport Manager', 'Accounts'];
restrict_access($allowed_roles);

global $db;
$pageTitle = 'Vehicle Maintenance Logs';

// Get month filter from URL, default to current month
$month = $_GET['month'] ?? date('Y-m');
$vehicle_filter = $_GET['vehicle'] ?? 'all';

// Build WHERE clause for filters
$where_conditions = ["DATE_FORMAT(ml.maintenance_date, '%Y-%m') = ?"];
$params = [$month];

if ($vehicle_filter !== 'all') {
    $where_conditions[] = "ml.vehicle_id = ?";
    $params[] = (int)$vehicle_filter;
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Get maintenance logs
$logs = $db->query(
    "SELECT 
        ml.*,
        v.vehicle_number
    FROM maintenance_logs ml
    JOIN vehicles v ON ml.vehicle_id = v.id
    $where_clause
    ORDER BY ml.maintenance_date DESC, ml.id DESC
    LIMIT 200",
    $params
)->results();

// Get summary for the filtered period
$summary = $db->query(
    "SELECT 
        COUNT(*) as total_logs,
        SUM(ml.cost) as total_cost,
        COUNT(DISTINCT ml.vehicle_id) as vehicles_serviced
    FROM maintenance_logs ml
    $where_clause",
    $params
)->first();

// Get vehicles for filter dropdown
$vehicles = $db->query("SELECT id, vehicle_number FROM vehicles WHERE status = 'Active' ORDER BY vehicle_number")->results();

require_once '../../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<!-- Header -->
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">🔧 Vehicle Maintenance</h1>
        <p class="text-gray-600 mt-1">Track vehicle service history and costs for <?php echo date("F Y", strtotime($month . "-01")); ?></p>
    </div>
    <a href="add.php" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 shadow-sm">
        <i class="fas fa-plus mr-2"></i>Log Maintenance
    </a>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
    <div class="bg-white rounded-lg shadow-md p-6">
        <p class="text-sm text-gray-600">Total Logs</p>
        <p class="text-3xl font-bold text-gray-900"><?php echo $summary->total_logs ?? 0; ?></p>
    </div>
    <div class="bg-white rounded-lg shadow-md p-6">
        <p class="text-sm text-gray-600">Total Maintenance Cost</p>
        <p class="text-3xl font-bold text-red-600">৳<?php echo number_format($summary->total_cost ?? 0, 0); ?></p>
    </div>
    <div class="bg-white rounded-lg shadow-md p-6">
        <p class="text-sm text-gray-600">Vehicles Serviced</p>
        <p class="text-3xl font-bold text-blue-600"><?php echo $summary->vehicles_serviced ?? 0; ?></p>
    </div>
</div>

<!-- Filters -->
<div class="bg-white rounded-lg shadow-md p-4 mb-6">
    <form method="GET" class="flex flex-wrap gap-4 items-center">
        <div>
            <label for="month_filter" class="block text-sm font-medium text-gray-700 mb-1">Month</label>
            <input type="month" id="month_filter" name="month" class="px-4 py-2 border border-gray-300 rounded-lg" 
                   value="<?php echo $month; ?>" onchange="this.form.submit()">
        </div>
        <div>
            <label for="vehicle_filter" class="block text-sm font-medium text-gray-700 mb-1">Vehicle</label>
            <select id="vehicle_filter" name="vehicle" class="px-4 py-2 border border-gray-300 rounded-lg" onchange="this.form.submit()">
                <option value="all">All Vehicles</option>
                <?php foreach ($vehicles as $vehicle): ?>
                <option value="<?php echo $vehicle->id; ?>" <?php echo $vehicle_filter == $vehicle->id ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($vehicle->vehicle_number); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($vehicle_filter !== 'all'): ?>
        <div class="pt-5">
            <a href="index.php?month=<?php echo $month; ?>" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Clear Filter</a>
        </div>
        <?php endif; ?>
    </form>
</div>

<!-- Maintenance Logs Table -->
<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vehicle</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Service Provider</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Odometer</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Next Service</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Cost</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="8" class="px-4 py-8 text-center text-gray-500">
                        <i class="fas fa-exclamation-circle text-2xl mb-2"></i>
                        <p>No maintenance logs found for this period.</p>
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-sm text-gray-900">
                            <?php echo date('d M Y', strtotime($log->maintenance_date)); ?>
                        </td>
                        <td class="px-4 py-3 text-sm font-medium text-gray-900">
                            <?php echo htmlspecialchars($log->vehicle_number); ?>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded text-xs font-medium">
                                <?php echo htmlspecialchars($log->maintenance_type); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600 max-w-xs truncate">
                            <?php echo $log->description ? htmlspecialchars($log->description) : '-'; ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">
                            <?php echo $log->service_provider ? htmlspecialchars($log->service_provider) : '-'; ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-right text-gray-600">
                            <?php echo $log->odometer_reading ? number_format($log->odometer_reading, 0) . ' km' : '-'; ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-right text-gray-600">
                            <?php if ($log->next_service_date): ?>
                                <?php $next_service_time = strtotime($log->next_service_date); ?>
                                <span class="<?php echo $next_service_time < time() ? 'font-bold text-red-600' : ''; ?>">
                                    <?php echo date('d M Y', $next_service_time); ?>
                                    <?php echo $next_service_time < time() ? ' (OVERDUE)' : ''; ?>
                                </span>
                            <?php else: echo '-'; endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-right font-bold text-red-600">
                            ৳<?php echo number_format($log->cost, 2); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($logs)): ?>
            <tfoot class="bg-gray-50">
                <tr>
                    <td colspan="7" class="px-4 py-3 text-sm font-bold text-gray-900 text-right">TOTALS FOR THIS PERIOD:</td>
                    <td class="px-4 py-3 text-sm font-bold text-red-600 text-right">
                        ৳<?php echo number_format($summary->total_cost, 2); ?>
                    </td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

</div>

<?php require_once '../../templates/footer.php'; ?>