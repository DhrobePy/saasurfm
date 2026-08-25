<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Transport Manager', 'Accounts'];
restrict_access($allowed_roles);

global $db;
$pageTitle = 'Logistics Dashboard';

try {
    // Get stats
    $stats = [
        'total_vehicles' => $db->query("SELECT COUNT(*) as count FROM vehicles WHERE status = 'Active'")->first()->count ?? 0,
        'own_vehicles' => $db->query("SELECT COUNT(*) as count FROM vehicles WHERE vehicle_type = 'Own' AND status = 'Active'")->first()->count ?? 0,
        'rented_vehicles' => $db->query("SELECT COUNT(*) as count FROM vehicles WHERE vehicle_type = 'Rented' AND status = 'Active'")->first()->count ?? 0,
        'maintenance_vehicles' => $db->query("SELECT COUNT(*) as count FROM vehicles WHERE status = 'Maintenance'")->first()->count ?? 0,
        
        'total_drivers' => $db->query("SELECT COUNT(*) as count FROM drivers WHERE status = 'Active'")->first()->count ?? 0,
        'permanent_drivers' => $db->query("SELECT COUNT(*) as count FROM drivers WHERE driver_type = 'Permanent' AND status = 'Active'")->first()->count ?? 0,
        'available_drivers' => $db->query("SELECT COUNT(*) as count FROM drivers WHERE status = 'Active' AND assigned_vehicle_id IS NULL")->first()->count ?? 0,
        
        'today_trips' => $db->query("SELECT COUNT(*) as count FROM trip_assignments WHERE trip_date = CURDATE()")->first()->count ?? 0,
        'scheduled_trips' => $db->query("SELECT COUNT(*) as count FROM trip_assignments WHERE trip_date = CURDATE() AND status = 'Scheduled'")->first()->count ?? 0,
        'in_progress_trips' => $db->query("SELECT COUNT(*) as count FROM trip_assignments WHERE trip_date = CURDATE() AND status = 'In Progress'")->first()->count ?? 0,
        'completed_trips' => $db->query("SELECT COUNT(*) as count FROM trip_assignments WHERE trip_date = CURDATE() AND status = 'Completed'")->first()->count ?? 0,
        
        'month_fuel_cost' => $db->query("SELECT COALESCE(SUM(total_cost), 0) as total FROM fuel_logs WHERE MONTH(fill_date) = MONTH(CURDATE()) AND YEAR(fill_date) = YEAR(CURDATE())")->first()->total ?? 0,
        'month_maintenance_cost' => $db->query("SELECT COALESCE(SUM(total_cost), 0) as total FROM maintenance_logs WHERE MONTH(maintenance_date) = MONTH(CURDATE()) AND YEAR(maintenance_date) = YEAR(CURDATE())")->first()->total ?? 0,
        'month_total_expenses' => $db->query("SELECT COALESCE(SUM(amount), 0) as total FROM transport_expenses WHERE MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE())")->first()->total ?? 0,
    ];
    
    // Calculate total month cost
    $stats['month_total_cost'] = $stats['month_fuel_cost'] + $stats['month_maintenance_cost'] + $stats['month_total_expenses'];
    
    // Get expiring documents (next 30 days)
    $expiring_vehicle_docs = $db->query(
        "SELECT vd.*, v.vehicle_number 
         FROM vehicle_documents vd
         JOIN vehicles v ON vd.vehicle_id = v.id
         WHERE vd.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
         AND vd.status = 'Active'
         ORDER BY vd.expiry_date ASC
         LIMIT 10"
    )->results();
    
    $expiring_driver_docs = $db->query(
        "SELECT dd.*, d.driver_name 
         FROM driver_documents dd
         JOIN drivers d ON dd.driver_id = d.id
         WHERE dd.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
         AND dd.status = 'Active'
         ORDER BY dd.expiry_date ASC
         LIMIT 10"
    )->results();
    
    // Get vehicles needing service
    $vehicles_needing_service = $db->query(
        "SELECT * FROM vehicles 
         WHERE status = 'Active' 
         AND (next_service_due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) 
         OR current_mileage >= next_service_due_mileage)
         LIMIT 10"
    )->results();
    
    // Get recent trips
    $recent_trips = $db->query(
        "SELECT ta.*, v.vehicle_number, d.driver_name, co.order_number
         FROM trip_assignments ta
         JOIN vehicles v ON ta.vehicle_id = v.id
         JOIN drivers d ON ta.driver_id = d.id
         JOIN credit_orders co ON ta.order_id = co.id
         ORDER BY ta.trip_date DESC, ta.created_at DESC
         LIMIT 10"
    )->results();
    
} catch (Exception $e) {
    $error = "Error loading dashboard: " . $e->getMessage();
}

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<!-- Header -->
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">🚚 Logistics Dashboard</h1>
        <p class="text-gray-600 mt-1">Transport & Fleet Management Overview</p>
    </div>
    <div class="flex gap-3">
        <a href="vehicles/" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            <i class="fas fa-truck mr-2"></i>Vehicles
        </a>
        <a href="drivers/" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
            <i class="fas fa-user-tie mr-2"></i>Drivers
        </a>
        <a href="trips/" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
            <i class="fas fa-route mr-2"></i>Trips
        </a>
    </div>
</div>

<?php if (isset($error)): ?>
<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
    <p><?php echo htmlspecialchars($error); ?></p>
</div>
<?php endif; ?>

<!-- Top KPI Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <!-- Vehicles -->
    <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl shadow-lg p-6 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-blue-100 text-sm font-medium uppercase">Active Vehicles</p>
                <p class="text-4xl font-bold mt-1"><?php echo $stats['total_vehicles']; ?></p>
                <p class="text-xs text-blue-100 mt-2">
                    Own: <?php echo $stats['own_vehicles']; ?> | Rented: <?php echo $stats['rented_vehicles']; ?>
                </p>
            </div>
            <div class="bg-blue-400 bg-opacity-30 rounded-full p-3">
                <i class="fas fa-truck text-3xl"></i>
            </div>
        </div>
    </div>
    
    <!-- Drivers -->
    <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl shadow-lg p-6 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-green-100 text-sm font-medium uppercase">Active Drivers</p>
                <p class="text-4xl font-bold mt-1"><?php echo $stats['total_drivers']; ?></p>
                <p class="text-xs text-green-100 mt-2">
                    Available: <?php echo $stats['available_drivers']; ?>
                </p>
            </div>
            <div class="bg-green-400 bg-opacity-30 rounded-full p-3">
                <i class="fas fa-user-tie text-3xl"></i>
            </div>
        </div>
    </div>
    
    <!-- Today's Trips -->
    <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl shadow-lg p-6 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-purple-100 text-sm font-medium uppercase">Today's Trips</p>
                <p class="text-4xl font-bold mt-1"><?php echo $stats['today_trips']; ?></p>
                <p class="text-xs text-purple-100 mt-2">
                    In Progress: <?php echo $stats['in_progress_trips']; ?> | Done: <?php echo $stats['completed_trips']; ?>
                </p>
            </div>
            <div class="bg-purple-400 bg-opacity-30 rounded-full p-3">
                <i class="fas fa-route text-3xl"></i>
            </div>
        </div>
    </div>
    
    <!-- Month's Cost -->
    <div class="bg-gradient-to-br from-orange-500 to-orange-600 rounded-xl shadow-lg p-6 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-orange-100 text-sm font-medium uppercase">This Month's Cost</p>
                <p class="text-3xl font-bold mt-1">৳<?php echo number_format($stats['month_total_cost'], 0); ?></p>
                <p class="text-xs text-orange-100 mt-2">
                    Fuel: ৳<?php echo number_format($stats['month_fuel_cost'], 0); ?>
                </p>
            </div>
            <div class="bg-orange-400 bg-opacity-30 rounded-full p-3">
                <i class="fas fa-chart-line text-3xl"></i>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <a href="trips/schedule.php" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow border-l-4 border-blue-500">
        <div class="flex items-center">
            <div class="bg-blue-100 rounded-full p-3 mr-4">
                <i class="fas fa-calendar-plus text-blue-600 text-2xl"></i>
            </div>
            <div>
                <h3 class="text-lg font-bold text-gray-900">Schedule Trip</h3>
                <p class="text-sm text-gray-600">Assign vehicle & driver to order</p>
            </div>
        </div>
    </a>
    
    <a href="fuel/add.php" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow border-l-4 border-green-500">
        <div class="flex items-center">
            <div class="bg-green-100 rounded-full p-3 mr-4">
                <i class="fas fa-gas-pump text-green-600 text-2xl"></i>
            </div>
            <div>
                <h3 class="text-lg font-bold text-gray-900">Log Fuel</h3>
                <p class="text-sm text-gray-600">Record fuel purchase</p>
            </div>
        </div>
    </a>
    
    <a href="maintenance/add.php" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow border-l-4 border-orange-500">
        <div class="flex items-center">
            <div class="bg-orange-100 rounded-full p-3 mr-4">
                <i class="fas fa-wrench text-orange-600 text-2xl"></i>
            </div>
            <div>
                <h3 class="text-lg font-bold text-gray-900">Log Maintenance</h3>
                <p class="text-sm text-gray-600">Record service/repair</p>
            </div>
        </div>
    </a>
</div>

<!-- Main Content Grid -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    
    <!-- Expiring Documents Alert -->
    <?php if (!empty($expiring_vehicle_docs) || !empty($expiring_driver_docs)): ?>
    <div class="bg-white rounded-lg shadow-md p-6">
        <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center">
            <i class="fas fa-exclamation-triangle text-yellow-500 mr-2"></i>
            Expiring Documents (Next 30 Days)
        </h2>
        <div class="space-y-3">
            <?php foreach (array_slice(array_merge($expiring_vehicle_docs, $expiring_driver_docs), 0, 5) as $doc): ?>
            <div class="flex items-center justify-between p-3 bg-yellow-50 rounded-lg border border-yellow-200">
                <div class="flex-1">
                    <p class="font-medium text-gray-900">
                        <?php echo isset($doc->vehicle_number) ? $doc->vehicle_number : $doc->driver_name; ?>
                    </p>
                    <p class="text-sm text-gray-600">
                        <?php echo $doc->document_type; ?> - Expires: <?php echo date('d M Y', strtotime($doc->expiry_date)); ?>
                    </p>
                </div>
                <span class="px-3 py-1 bg-yellow-100 text-yellow-800 rounded-full text-xs font-semibold">
                    <?php 
                    $days = (strtotime($doc->expiry_date) - time()) / 86400;
                    echo ceil($days) . ' days';
                    ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <a href="reports/expiring_documents.php" class="mt-4 text-blue-600 hover:text-blue-800 text-sm font-medium inline-block">
            View All Expiring Documents →
        </a>
    </div>
    <?php endif; ?>
    
    <!-- Vehicles Needing Service -->
    <?php if (!empty($vehicles_needing_service)): ?>
    <div class="bg-white rounded-lg shadow-md p-6">
        <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center">
            <i class="fas fa-tools text-orange-500 mr-2"></i>
            Vehicles Needing Service
        </h2>
        <div class="space-y-3">
            <?php foreach ($vehicles_needing_service as $vehicle): ?>
            <div class="flex items-center justify-between p-3 bg-orange-50 rounded-lg border border-orange-200">
                <div class="flex-1">
                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($vehicle->vehicle_number); ?></p>
                    <p class="text-sm text-gray-600">
                        <?php if ($vehicle->next_service_due_date && strtotime($vehicle->next_service_due_date) <= time()): ?>
                            Service overdue since <?php echo date('d M Y', strtotime($vehicle->next_service_due_date)); ?>
                        <?php elseif ($vehicle->current_mileage >= $vehicle->next_service_due_mileage): ?>
                            Mileage limit reached: <?php echo number_format($vehicle->current_mileage, 0); ?> km
                        <?php else: ?>
                            Service due: <?php echo date('d M Y', strtotime($vehicle->next_service_due_date)); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <a href="maintenance/add.php?vehicle_id=<?php echo $vehicle->id; ?>" 
                   class="px-3 py-1 bg-orange-600 text-white rounded-lg text-sm hover:bg-orange-700">
                    Schedule
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Recent Trips -->
    <div class="bg-white rounded-lg shadow-md p-6 lg:col-span-2">
        <h2 class="text-xl font-bold text-gray-900 mb-4">Recent Trips</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order #</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vehicle</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Driver</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Route</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($recent_trips)): ?>
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                            No trips scheduled yet
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($recent_trips as $trip): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm font-medium text-blue-600">
                                <?php echo htmlspecialchars($trip->order_number); ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                <?php echo date('d M Y', strtotime($trip->trip_date)); ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-900">
                                <?php echo htmlspecialchars($trip->vehicle_number); ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-900">
                                <?php echo htmlspecialchars($trip->driver_name); ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                <?php echo htmlspecialchars(substr($trip->destination, 0, 30)); ?><?php echo strlen($trip->destination) > 30 ? '...' : ''; ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php
                                $status_colors = [
                                    'Scheduled' => 'bg-blue-100 text-blue-800',
                                    'In Progress' => 'bg-yellow-100 text-yellow-800',
                                    'Completed' => 'bg-green-100 text-green-800',
                                    'Cancelled' => 'bg-red-100 text-red-800'
                                ];
                                $color = $status_colors[$trip->status] ?? 'bg-gray-100 text-gray-800';
                                ?>
                                <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $color; ?>">
                                    <?php echo $trip->status; ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right text-sm">
                                <a href="trips/view.php?id=<?php echo $trip->id; ?>" class="text-blue-600 hover:text-blue-900">
                                    View →
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <a href="trips/" class="mt-4 text-blue-600 hover:text-blue-800 text-sm font-medium inline-block">
            View All Trips →
        </a>
    </div>
    
</div>

</div>

<?php require_once '../templates/footer.php'; ?>