<?php
require_once '../../core/init.php';

// Access control
$allowed_roles = ['Superadmin', 'admin', 'Transport Manager', 'Accounts'];
restrict_access($allowed_roles);

global $db;
$pageTitle = 'Fuel Logs';

// Get month filter from URL, default to current month
$month = $_GET['month'] ?? date('Y-m');
$vehicle_filter = $_GET['vehicle'] ?? 'all';

// --- FIXED: Changed fl.fill_date to fl.fuel_date ---
$where_conditions = ["DATE_FORMAT(fl.fuel_date, '%Y-%m') = ?"];
$params = [$month];

// Add vehicle filter if selected
if ($vehicle_filter !== 'all') {
    $where_conditions[] = "fl.vehicle_id = ?";
    $params[] = (int)$vehicle_filter;
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// --- FIXED: This is the correct, complex JOIN to get the driver name ---
// We must join through trip_assignments to find the driver.
// We use COALESCE() to show the driver's name if it exists,
// otherwise we fall back to the 'filled_by' (employee) name.
$fuel_logs = $db->query(
    "SELECT 
        fl.*,
        v.vehicle_number,
        v.vehicle_type,
        -- This logic shows the DRIVER name if a trip is linked, 
        -- otherwise it shows the EMPLOYEE name who filled it.
        COALESCE(d.driver_name, fl.filled_by) AS driver_name_to_display
    FROM fuel_logs fl
    JOIN vehicles v ON fl.vehicle_id = v.id
    LEFT JOIN trip_assignments ta ON fl.trip_id = ta.id
    LEFT JOIN drivers d ON ta.driver_id = d.id
    $where_clause
    ORDER BY fl.fuel_date DESC, fl.id DESC
    LIMIT 100",
    $params
)->results();

// --- FIXED: Changed fl.fill_date to fl.fuel_date ---
$summary = $db->query(
    "SELECT 
        COUNT(*) as total_fills,
        SUM(fl.quantity_liters) as total_liters,
        SUM(fl.total_cost) as total_cost,
        AVG(fl.price_per_liter) as avg_price_per_liter
    FROM fuel_logs fl
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
        <h1 class="text-3xl font-bold text-gray-900">⛽ Fuel Logs</h1>
        <p class="text-gray-600 mt-1">Track fuel consumption and costs for <?php echo date("F Y", strtotime($month . "-01")); ?></p>
    </div>
    <a href="add.php" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 shadow-sm">
        <i class="fas fa-plus mr-2"></i>Log Fuel
    </a>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
    <div class="bg-white rounded-lg shadow-md p-6">
        <p class="text-sm text-gray-600">Total Fills</p>
        <p class="text-3xl font-bold text-gray-900"><?php echo $summary->total_fills ?? 0; ?></p>
    </div>
    <div class="bg-white rounded-lg shadow-md p-6">
        <p class="text-sm text-gray-600">Total Liters</p>
        <p class="text-3xl font-bold text-blue-600"><?php echo number_format($summary->total_liters ?? 0, 1); ?>L</p>
    </div>
    <div class="bg-white rounded-lg shadow-md p-6">
        <p class="text-sm text-gray-600">Total Cost</p>
        <p class="text-3xl font-bold text-green-600">৳<?php echo number_format($summary->total_cost ?? 0, 0); ?></p>
    </div>
    <div class="bg-white rounded-lg shadow-md p-6">
        <p class="text-sm text-gray-600">Avg Price/L</p>
        <p class="text-3xl font-bold text-orange-600">৳<?php echo number_format($summary->avg_price_per_liter ?? 0, 2); ?></p>
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

<!-- Fuel Logs Table -->
<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vehicle</th>
                    <!-- FIXED: Updated header text -->
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Driver / Filled By</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fuel Type</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Station</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Quantity</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Price/L</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total Cost</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Odometer</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($fuel_logs)): ?>
                <tr>
                    <td colspan="9" class="px-4 py-8 text-center text-gray-500">
                        <i class="fas fa-exclamation-circle text-2xl mb-2"></i>
                        <p>No fuel logs found for this period.</p>
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($fuel_logs as $log): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-sm text-gray-900">
                            <!-- FIXED: Changed to fuel_date -->
                            <?php echo date('d M Y', strtotime($log->fuel_date)); ?>
                            <!-- This will show 12:00 AM, as fuel_date is a DATE type. This is expected. -->
                            <div class="text-xs text-gray-500">
                                <?php echo date('h:i A', strtotime($log->fuel_date)); ?> 
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <div class="font-medium text-gray-900">
                                <?php echo htmlspecialchars($log->vehicle_number); ?>
                            </div>
                            <div class="text-xs text-gray-500">
                                <?php echo htmlspecialchars($log->vehicle_type ?? 'N/A'); ?>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">
                            <!-- FIXED: This now shows the driver name OR the filled_by name -->
                            <?php echo $log->driver_name_to_display ? htmlspecialchars($log->driver_name_to_display) : '-'; ?>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <span class="px-2 py-1 bg-gray-100 text-gray-800 rounded text-xs font-medium">
                                <?php echo htmlspecialchars($log->fuel_type); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">
                            <!-- FIXED: Changed from fuel_station to station_name -->
                            <?php echo $log->station_name ? htmlspecialchars($log->station_name) : '-'; ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-right font-medium text-blue-600">
                            <?php echo number_format($log->quantity_liters, 2); ?>L
                        </td>
                        <td class="px-4 py-3 text-sm text-right text-gray-600">
                            ৳<?php echo number_format($log->price_per_liter, 2); ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-right font-bold text-green-600">
                            ৳<?php echo number_format($log->total_cost, 2); ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-right text-gray-600">
                            <?php echo $log->odometer_reading ? number_format($log->odometer_reading, 0) . ' km' : '-'; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($fuel_logs)): ?>
            <tfoot class="bg-gray-50">
                <tr>
                    <td colspan="5" class="px-4 py-3 text-sm font-bold text-gray-900 text-right">TOTALS FOR THIS PERIOD:</td>
                    <td class="px-4 py-3 text-sm font-bold text-blue-600 text-right">
                        <?php echo number_format($summary->total_liters, 2); ?>L
                    </td>
                    <td class="px-4 py-3"></td>
                    <td class="px-4 py-3 text-sm font-bold text-green-600 text-right">
                        ৳<?php echo number_format($summary->total_cost, 2); ?>
                    </td>
                    <td class="px-4 py-3"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

</div>

<?php require_once '../../templates/footer.php'; ?>