<?php
require_once '../../core/init.php';

// Access control
$allowed_roles = ['Superadmin', 'admin', 'Transport Manager', 'Accounts'];
restrict_access($allowed_roles);

global $db;
$pageTitle = 'Vehicle Details';
$vehicle_id = (int)($_GET['id'] ?? 0);

if ($vehicle_id <= 0) {
    $_SESSION['error_flash'] = 'Invalid vehicle ID.';
    header('Location: index.php');
    exit();
}

// 1. Get the main vehicle data
$vehicle = $db->query("SELECT * FROM vehicles WHERE id = ?", [$vehicle_id])->first();
if (!$vehicle) {
    $_SESSION['error_flash'] = 'Vehicle not found.';
    header('Location: index.php');
    exit();
}
$pageTitle = 'Vehicle: ' . htmlspecialchars($vehicle->vehicle_number);

// 2. Get Aggregated Stats for Analysis
$stats = $db->query(
    "SELECT
        (SELECT SUM(cost) FROM maintenance_logs WHERE vehicle_id = ?) AS total_maintenance_cost,
        (SELECT SUM(total_cost) FROM fuel_logs WHERE vehicle_id = ?) AS total_fuel_cost,
        (SELECT SUM(quantity_liters) FROM fuel_logs WHERE vehicle_id = ?) AS total_fuel_liters,
        (SELECT COUNT(id) FROM trip_assignments WHERE vehicle_id = ?) AS total_trips
    ",
    [$vehicle_id, $vehicle_id, $vehicle_id, $vehicle_id]
)->first();

// 3. Perform Analysis & Generate "Comparative Decision"
$purchase_price = (float)($vehicle->purchase_price ?? 0);
$purchase_date = $vehicle->purchase_date;
$current_mileage = (float)($vehicle->current_mileage ?? 0);
$total_maintenance = (float)($stats->total_maintenance_cost ?? 0);
$total_fuel = (float)($stats->total_fuel_cost ?? 0);
$total_liters = (float)($stats->total_fuel_liters ?? 1); // Avoid division by zero
$total_trips = (int)($stats->total_trips ?? 0);

$total_cost_of_ownership = $purchase_price + $total_maintenance + $total_fuel;
$total_running_cost = $total_maintenance + $total_fuel;

$age_in_days = 0;
if ($purchase_date) {
    try {
        $age_in_days = (new DateTime())->diff(new DateTime($purchase_date))->days;
    } catch (Exception $e) {}
}
$age_in_years = $age_in_days / 365.25;

// Use purchase_mileage if it exists, otherwise assume 0
$purchase_mileage = (float)($vehicle->purchase_mileage ?? 0);
$total_km_driven = ($current_mileage > $purchase_mileage) ? ($current_mileage - $purchase_mileage) : 0;

$cost_per_km = ($total_km_driven > 0) ? ($total_running_cost / $total_km_driven) : 0;
$fuel_efficiency = ($total_liters > 0 && $total_km_driven > 0) ? ($total_km_driven / $total_liters) : 0; // km per liter

$decision = [];
if ($total_km_driven <= 0 && $age_in_years > 0.25) {
     $decision = [
        'recommendation' => 'INVESTIGATE (UNUSED)',
        'reason' => 'This asset has not logged any significant mileage. Investigate if it is being used, if mileage is being logged, or if it should be sold.',
        'color' => 'blue'
    ];
} elseif ($cost_per_km > 50 || ($total_maintenance > $purchase_price && $purchase_price > 0 && $age_in_years > 2)) {
    $decision = [
        'recommendation' => 'SELL / DISPOSE',
        'reason' => 'This vehicle has an extremely high cost per km (' . number_format($cost_per_km, 2) . ' BDT) and/or its maintenance costs have exceeded its original purchase price. It is no longer financially viable for primary operations.',
        'color' => 'red'
    ];
} elseif ($cost_per_km > 35 || $age_in_years > 7) {
    $decision = [
        'recommendation' => 'CONSIDER RENTAL / LOW-PRIORITY USE',
        'reason' => 'This vehicle is aging (' . number_format($age_in_years, 1) . ' yrs) and has a high operational cost (' . number_format($cost_per_km, 2) . ' BDT/km). It may be too costly for primary routes but could offset costs in a rental fleet or for low-priority tasks.',
        'color' => 'yellow'
    ];
} else {
    $decision = [
        'recommendation' => 'KEEP IN PRIMARY OPERATION',
        'reason' => 'This vehicle is performing within acceptable parameters. With a cost of ' . number_format($cost_per_km, 2) . ' BDT/km, it remains a core asset for primary operations.',
        'color' => 'green'
    ];
}


// 4. Get Logs for Tabs
$trip_logs = $db->query(
    "SELECT ta.*, d.driver_name 
     FROM trip_assignments ta 
     LEFT JOIN drivers d ON ta.driver_id = d.id 
     WHERE ta.vehicle_id = ? 
     ORDER BY ta.trip_date DESC, ta.id DESC LIMIT 20",
    [$vehicle_id]
)->results();

$fuel_logs = $db->query(
    "SELECT * FROM fuel_logs WHERE vehicle_id = ? ORDER BY fuel_date DESC LIMIT 20",
    [$vehicle_id]
)->results();

$maintenance_logs = $db->query(
    "SELECT * FROM maintenance_logs WHERE vehicle_id = ? ORDER BY maintenance_date DESC LIMIT 20",
    [$vehicle_id]
)->results();

$documents = $db->query(
    "SELECT * FROM vehicle_documents WHERE vehicle_id = ? ORDER BY expiry_date ASC",
    [$vehicle_id]
)->results();


require_once '../../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6" x-data="{ activeTab: 'trips' }">

    <!-- Header -->
    <div class="flex flex-wrap justify-between items-center mb-6 gap-4">
        <div>
            <h1 class="text-3xl font-bold text-gray-900"><?php echo htmlspecialchars($vehicle->vehicle_number); ?></h1>
            <p class="text-lg text-gray-600 mt-1"><?php echo htmlspecialchars($vehicle->model); ?> (<?php echo htmlspecialchars($vehicle->year); ?>)</p>
        </div>
        <div class="flex gap-2">
            <a href="index.php" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 shadow-sm">
                <i class="fas fa-arrow-left mr-2"></i>Back to List
            </a>
            <a href="add.php?id=<?php echo $vehicle->id; ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 shadow-sm">
                <i class="fas fa-edit mr-2"></i>Edit Vehicle
            </a>
        </div>
    </div>

    <!-- Main Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Left Column (Info & Analysis) -->
        <div class="lg:col-span-1 space-y-6">

            <!-- Comparative Decision Card -->
            <div class="bg-white rounded-lg shadow-md border-t-4 border-<?php echo $decision['color']; ?>-500">
                <div class="p-4">
                    <h3 class="text-lg font-bold text-gray-900 flex items-center">
                        <i class="fas fa-lightbulb mr-2 text-<?php echo $decision['color']; ?>-500"></i>
                        Management Decision
                    </h3>
                </div>
                <div class="p-4 pt-0">
                    <p class="text-xl font-bold text-<?php echo $decision['color']; ?>-700 mb-2">
                        <?php echo $decision['recommendation']; ?>
                    </p>
                    <p class="text-sm text-gray-600">
                        <?php echo $decision['reason']; ?>
                    </p>
                </div>
            </div>

            <!-- Key Info Card -->
            <div class="bg-white rounded-lg shadow-md">
                <div class="p-4">
                    <h3 class="text-lg font-bold text-gray-900">Vehicle Details</h3>
                </div>
                <ul class="divide-y divide-gray-200">
                    <li class="px-4 py-3 flex justify-between">
                        <span class="text-sm font-medium text-gray-600">Status</span>
                        <span class="px-2 py-0.5 text-sm rounded-full <?php echo $vehicle->status === 'Active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                            <?php echo htmlspecialchars($vehicle->status); ?>
                        </span>
                    </li>
                    <li class="px-4 py-3 flex justify-between">
                        <span class="text-sm font-medium text-gray-600">Model</span>
                        <span class="text-sm text-gray-900"><?php echo htmlspecialchars($vehicle->model); ?></span>
                    </li>
                    <li class="px-4 py-3 flex justify-between">
                        <span class="text-sm font-medium text-gray-600">Year</span>
                        <span class="text-sm text-gray-900"><?php echo htmlspecialchars($vehicle->year); ?></span>
                    </li>
                    <li class="px-4 py-3 flex justify-between">
                        <span class="text-sm font-medium text-gray-600">Fuel Type</span>
                        <span class="text-sm text-gray-900"><?php echo htmlspecialchars($vehicle->fuel_type); ?></span>
                    </li>
                    <li class="px-4 py-3 flex justify-between">
                        <span class="text-sm font-medium text-gray-600">Capacity</span>
                        <span class="text-sm text-gray-900"><?php echo number_format($vehicle->capacity_kg, 0); ?> kg</span>
                    </li>
                    <li class="px-4 py-3 flex justify-between">
                        <span class="text-sm font-medium text-gray-600">Current Mileage</span>
                        <span class="text-sm text-gray-900"><?php echo number_format($current_mileage, 0); ?> km</span>
                    </li>
                </ul>
            </div>

            <!-- Financial Analysis Card -->
            <div class="bg-white rounded-lg shadow-md">
                <div class="p-4">
                    <h3 class="text-lg font-bold text-gray-900">Financial Analysis</h3>
                </div>
                <ul class="divide-y divide-gray-200">
                    <li class="px-4 py-3 flex justify-between">
                        <span class="text-sm font-medium text-gray-600">Cost per KM</span>
                        <span class="text-sm font-bold text-gray-900">৳<?php echo number_format($cost_per_km, 2); ?></span>
                    </li>
                    <li class="px-4 py-3 flex justify-between">
                        <span class="text-sm font-medium text-gray-600">Fuel Efficiency</span>
                        <span class="text-sm font-bold text-gray-900"><?php echo number_format($fuel_efficiency, 2); ?> km/L</span>
                    </li>
                    <li class="px-4 py-3 flex justify-between">
                        <span class="text-sm font-medium text-gray-600">Total Trips Logged</span>
                        <span class="text-sm font-bold text-gray-900"><?php echo number_format($total_trips); ?></span>
                    </li>
                    <li class="px-4 py-3 flex justify-between">
                        <span class="text-sm font-medium text-gray-600">Vehicle Age</span>
                        <span class="text-sm text-gray-900"><?php echo number_format($age_in_years, 1); ?> years</span>
                    </li>
                    <li class="px-4 py-3 flex justify-between">
                        <span class="text-sm font-medium text-gray-600">Total Fuel Cost</span>
                        <span class="text-sm text-gray-900">৳<?php echo number_format($total_fuel, 0); ?></span>
                    </li>
                    <li class="px-4 py-3 flex justify-between">
                        <span class="text-sm font-medium text-gray-600">Total Maintenance Cost</span>
                        <span class="text-sm text-gray-900">৳<?php echo number_format($total_maintenance, 0); ?></span>
                    </li>
                    <li class="px-4 py-3 flex justify-between bg-gray-50">
                        <span class="text-sm font-bold text-gray-700">Total Running Cost</span>
                        <span class="text-sm font-bold text-gray-900">৳<?php echo number_format($total_running_cost, 0); ?></span>
                    </li>
                </ul>
            </div>

            <!-- Document Notifications Card -->
            <div class="bg-white rounded-lg shadow-md">
                <div class="p-4">
                    <h3 class="text-lg font-bold text-gray-900">Document Status</h3>
                </div>
                <div class="p-4 pt-0">
                    <?php if (empty($documents)): ?>
                        <p class="text-sm text-gray-500 text-center py-4">No documents found for this vehicle.</p>
                    <?php else: ?>
                        <ul class="space-y-3">
                            <?php 
                            $today = new DateTime();
                            foreach ($documents as $doc):
                                $status_color = 'green';
                                $status_text = 'OK';
                                $days_left = '';

                                if ($doc->expiry_date) {
                                    try {
                                        $expiry = new DateTime($doc->expiry_date);
                                        $diff = $today->diff($expiry);
                                        $days_left_num = (int)$diff->format('%r%a');

                                        if ($days_left_num <= 0) {
                                            $status_color = 'red';
                                            $status_text = 'EXPIRED';
                                            $days_left = 'Expired ' . $diff->days . ' days ago';
                                        } elseif ($days_left_num <= 30) {
                                            $status_color = 'yellow';
                                            $status_text = 'RENEW SOON';
                                            $days_left = 'Expires in ' . $diff->days . ' days';
                                        } else {
                                            $days_left = 'Expires in ' . $diff->days . ' days';
                                        }
                                    } catch (Exception $e) {
                                        $days_left = 'Invalid date';
                                    }
                                } else {
                                    $days_left = 'No expiry date';
                                }
                            ?>
                                <li class="flex items-center p-3 bg-gray-50 rounded-lg border-l-4 border-<?php echo $status_color; ?>-500">
                                    <i class="fas fa-file-alt text-<?php echo $status_color; ?>-500 text-xl mr-3"></i>
                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($doc->document_type); ?></p>
                                        <p class="text-xs text-gray-600"><?php echo htmlspecialchars($doc->document_number ?? 'N/A'); ?></p>
                                    </div>
                                    <div class="text-right">
                                        <span class="px-2 py-0.5 text-xs font-bold rounded-full bg-<?php echo $status_color; ?>-100 text-<?php echo $status_color; ?>-800">
                                            <?php echo $status_text; ?>
                                        </span>
                                        <p class="text-xs text-gray-500 mt-1"><?php echo $days_left; ?></p>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- Right Column (Logs) -->
        <div class="lg:col-span-2 space-y-6">

            <!-- Tab Navigation -->
            <div class="bg-white rounded-lg shadow-md p-2">
                <nav class="flex space-x-2">
                    <button @click="activeTab = 'trips'"
                            :class="activeTab === 'trips' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'"
                            class="px-4 py-2 font-medium text-sm rounded-lg transition-all">
                        <i class="fas fa-route mr-1"></i> Trip Logs (<?php echo count($trip_logs); ?>)
                    </button>
                    <button @click="activeTab = 'fuel'"
                            :class="activeTab === 'fuel' ? 'bg-green-600 text-white' : 'text-gray-600 hover:bg-gray-100'"
                            class="px-4 py-2 font-medium text-sm rounded-lg transition-all">
                        <i class="fas fa-gas-pump mr-1"></i> Fuel Logs (<?php echo count($fuel_logs); ?>)
                    </button>
                    <button @click="activeTab = 'maintenance'"
                            :class="activeTab === 'maintenance' ? 'bg-red-600 text-white' : 'text-gray-600 hover:bg-gray-100'"
                            class="px-4 py-2 font-medium text-sm rounded-lg transition-all">
                        <i class="fas fa-tools mr-1"></i> Maintenance (<?php echo count($maintenance_logs); ?>)
                    </button>
                </nav>
            </div>

            <!-- Tab Content -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                
                <!-- Trip Logs Tab -->
                <div x-show="activeTab === 'trips'" class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Driver</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Weight</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if(empty($trip_logs)) { echo '<tr><td colspan="4" class="p-4 text-center text-gray-500">No trip logs found.</td></tr>'; } ?>
                            <?php foreach($trip_logs as $log): ?>
                            <tr>
                                <td class="px-4 py-3 text-sm text-gray-900"><?php echo date('d M Y', strtotime($log->trip_date)); ?></td>
                                <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($log->driver_name ?? 'N/A'); ?></td>
                                <td class="px-4 py-3 text-sm">
                                    <span class="px-2 py-0.5 text-xs rounded-full <?php echo $log->status === 'Completed' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                        <?php echo htmlspecialchars($log->status); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-right text-gray-600"><?php echo number_format($log->total_weight_kg, 0); ?> kg</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Fuel Logs Tab -->
                <div x-show="activeTab === 'fuel'" class="overflow-x-auto" style="display: none;">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Station</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Liters</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Cost</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Odometer</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if(empty($fuel_logs)) { echo '<tr><td colspan="5" class="p-4 text-center text-gray-500">No fuel logs found.</td></tr>'; } ?>
                            <?php foreach($fuel_logs as $log): ?>
                            <tr>
                                <td class="px-4 py-3 text-sm text-gray-900"><?php echo date('d M Y', strtotime($log->fuel_date)); ?></td>
                                <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($log->station_name ?? 'N/A'); ?></td>
                                <td class="px-4 py-3 text-sm text-right text-blue-600"><?php echo number_format($log->quantity_liters, 2); ?> L</td>
                                <td class="px-4 py-3 text-sm text-right font-medium text-green-600">৳<?php echo number_format($log->total_cost, 2); ?></td>
                                <td class="px-4 py-3 text-sm text-right text-gray-600"><?php echo number_format($log->odometer_reading, 0); ?> km</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Maintenance Logs Tab -->
                <div x-show="activeTab === 'maintenance'" class="overflow-x-auto" style="display: none;">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Provider</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Cost</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if(empty($maintenance_logs)) { echo '<tr><td colspan="4" class="p-4 text-center text-gray-500">No maintenance logs found.</td></tr>'; } ?>
                            <?php foreach($maintenance_logs as $log): ?>
                            <tr>
                                <td class="px-4 py-3 text-sm text-gray-900"><?php echo date('d M Y', strtotime($log->maintenance_date)); ?></td>
                                <td class="px-4 py-3 text-sm">
                                    <span class="px-2 py-0.5 text-xs rounded-full bg-blue-100 text-blue-800">
                                        <?php echo htmlspecialchars($log->maintenance_type); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600"><?php echo htmlspecialchars($log->service_provider ?? 'N/A'); ?></td>
                                <td class="px-4 py-3 text-sm text-right font-medium text-red-600">৳<?php echo number_format($log->cost, 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>

    </div>

</div>

<!-- Alpine.js for tabs -->
<script src="https://cdn.jsdelivr.net/gh/alpinejs/alpine@v2.x.x/dist/alpine.min.js" defer></script>

<?php require_once '../../templates/footer.php'; ?>