<?php
require_once '../../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'dispatch-srg', 'dispatch-demra', 'dispatchpos-demra', 'dispatchpos-srg'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$user_role = $currentUser['role'] ?? '';
$pageTitle = 'Trip Management';
$error = null;
$success = null;

$is_admin = in_array($user_role, ['Superadmin', 'admin']);

// Get user's branch
$user_branch = null;
if (!$is_admin && $user_id) {
    $emp = $db->query("SELECT branch_id FROM employees WHERE user_id = ?", [$user_id])->first();
    if ($emp && $emp->branch_id) {
        $user_branch = $emp->branch_id;
    }
}

// Filter parameters
$status_filter = $_GET['status'] ?? 'all';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query filters
$where_clauses = [];
$params = [];

if ($status_filter !== 'all') {
    $where_clauses[] = "ta.status = ?";
    $params[] = $status_filter;
}

if ($date_from) {
    $where_clauses[] = "ta.trip_date >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_clauses[] = "ta.trip_date <= ?";
    $params[] = $date_to;
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Get all trips with details
$trips = $db->query(
    "SELECT ta.*,
            v.vehicle_number,
            v.category as vehicle_category,
            v.vehicle_type,
            v.capacity_kg,
            d.driver_name,
            d.phone_number as driver_phone,
            d.driver_type,
            u.display_name as created_by_name,
            COUNT(DISTINCT toa.order_id) as order_count,
            SUM(co.total_weight_kg) as calculated_total_weight
     FROM trip_assignments ta
     LEFT JOIN vehicles v ON ta.vehicle_id = v.id
     LEFT JOIN drivers d ON ta.driver_id = d.id
     LEFT JOIN users u ON ta.created_by_user_id = u.id
     LEFT JOIN trip_order_assignments toa ON ta.id = toa.trip_id
     LEFT JOIN credit_orders co ON toa.order_id = co.id
     $where_sql
     GROUP BY ta.id
     ORDER BY ta.trip_date DESC, ta.id DESC
     LIMIT 100",
    $params
)->results();

// Get consolidation opportunities
$consolidation_opportunities = $db->query("
    SELECT 
        ocs.*,
        co1.order_number as order1_number,
        co2.order_number as order2_number,
        c1.name as customer1_name,
        c2.name as customer2_name
    FROM order_consolidation_suggestions ocs
    JOIN credit_orders co1 ON ocs.order_id_1 = co1.id
    JOIN credit_orders co2 ON ocs.order_id_2 = co2.id
    JOIN customers c1 ON co1.customer_id = c1.id
    JOIN customers c2 ON co2.customer_id = c2.id
    WHERE ocs.status = 'pending'
    AND co1.status = 'ready_to_ship'
    AND co2.status = 'ready_to_ship'
    ORDER BY ocs.potential_savings DESC
    LIMIT 10
")->results();

// Get orders ready for dispatch (not yet in any trip)
$ready_orders = $db->query("
    SELECT co.*, 
           c.name as customer_name,
           c.phone_number as customer_phone,
           b.name as branch_name
    FROM credit_orders co
    JOIN customers c ON co.customer_id = c.id
    LEFT JOIN branches b ON co.assigned_branch_id = b.id
    LEFT JOIN trip_order_assignments toa ON co.id = toa.order_id
    WHERE co.status = 'ready_to_ship'
    AND toa.id IS NULL
    ORDER BY co.required_date ASC
    LIMIT 20
")->results();

// Handle trip status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $trip_id = (int)($_POST['trip_id'] ?? 0);
    
    try {
        $db->getPdo()->beginTransaction();
        
        if ($action === 'add_order_to_trip') {
            $order_id = (int)$_POST['order_id'];
            
            $trip = $db->query("SELECT * FROM trip_assignments WHERE id = ?", [$trip_id])->first();
            $order = $db->query("SELECT * FROM credit_orders WHERE id = ?", [$order_id])->first();
            
            if (!$trip || !$order) throw new Exception("Trip or order not found");
            
            // Calculate order weight
            try {
                $db->query("CALL sp_calculate_order_weight(?)", [$order_id]);
                $order = $db->query("SELECT * FROM credit_orders WHERE id = ?", [$order_id])->first();
            } catch (Exception $e) {
                error_log("Weight calculation warning: " . $e->getMessage());
            }
            
            $order_weight = (float)($order->total_weight_kg ?? 0);
            
            // Check capacity
            if ($trip->remaining_capacity_kg < $order_weight) {
                throw new Exception("Not enough capacity. Need {$order_weight}kg but only {$trip->remaining_capacity_kg}kg available.");
            }
            
            // Get next sequence number
            $max_seq = $db->query("SELECT COALESCE(MAX(sequence_number), 0) as max_seq FROM trip_order_assignments WHERE trip_id = ?", [$trip_id])->first();
            $sequence = ($max_seq->max_seq ?? 0) + 1;
            
            // Add order to trip
            $db->insert('trip_order_assignments', [
                'trip_id' => $trip_id,
                'order_id' => $order_id,
                'sequence_number' => $sequence,
                'destination_address' => $order->shipping_address,
                'delivery_status' => 'pending'
            ]);
            
            // Update trip
            $db->query("
                UPDATE trip_assignments 
                SET total_orders = total_orders + 1,
                    total_weight_kg = total_weight_kg + ?,
                    remaining_capacity_kg = remaining_capacity_kg - ?,
                    trip_type = 'consolidated'
                WHERE id = ?
            ", [$order_weight, $order_weight, $trip_id]);
            
            // Update order status
            $db->query("UPDATE credit_orders SET status = 'shipped' WHERE id = ?", [$order_id]);
            
            // Get vehicle and driver info
            $vehicle = $db->query("SELECT * FROM vehicles WHERE id = ?", [$trip->vehicle_id])->first();
            $driver = $db->query("SELECT * FROM drivers WHERE id = ?", [$trip->driver_id])->first();
            
            // Update shipping record
            $shipping_exists = $db->query("SELECT id FROM credit_order_shipping WHERE order_id = ?", [$order_id])->first();
            if ($shipping_exists) {
                $db->query("
                    UPDATE credit_order_shipping 
                    SET trip_id = ?,
                        truck_number = ?,
                        driver_name = ?,
                        driver_contact = ?,
                        shipped_date = NOW(),
                        shipped_by_user_id = ?
                    WHERE order_id = ?
                ", [$trip_id, $vehicle->vehicle_number, $driver->driver_name, $driver->phone_number, $user_id, $order_id]);
            } else {
                $db->insert('credit_order_shipping', [
                    'order_id' => $order_id,
                    'trip_id' => $trip_id,
                    'truck_number' => $vehicle->vehicle_number,
                    'driver_name' => $driver->driver_name,
                    'driver_contact' => $driver->phone_number,
                    'shipped_date' => date('Y-m-d H:i:s'),
                    'shipped_by_user_id' => $user_id
                ]);
            }
            
            $success = "Order added to Trip #$trip_id successfully";
            
        } elseif ($action === 'start_trip') {
            $db->query("
                UPDATE trip_assignments 
                SET status = 'In Progress',
                    actual_start_time = NOW()
                WHERE id = ?
            ", [$trip_id]);
            
            $success = "Trip #$trip_id started";
            
        } elseif ($action === 'complete_trip') {
            $pending = $db->query("
                SELECT COUNT(*) as cnt 
                FROM trip_order_assignments 
                WHERE trip_id = ? AND delivery_status != 'delivered'
            ", [$trip_id])->first();
            
            if ($pending->cnt > 0) {
                throw new Exception("Cannot complete trip. {$pending->cnt} order(s) not yet delivered.");
            }
            
            $trip = $db->query("SELECT * FROM trip_assignments WHERE id = ?", [$trip_id])->first();
            
            $db->query("
                UPDATE trip_assignments 
                SET status = 'Completed',
                    actual_end_time = NOW()
                WHERE id = ?
            ", [$trip_id]);
            
            $db->query("UPDATE drivers SET is_available = 1 WHERE id = ?", [$trip->driver_id]);
            
            $success = "Trip #$trip_id completed";
            
        } elseif ($action === 'cancel_trip') {
            $reason = trim($_POST['cancel_reason'] ?? '');
            if (empty($reason)) {
                throw new Exception("Please provide a cancellation reason");
            }
            
            $trip = $db->query("SELECT * FROM trip_assignments WHERE id = ?", [$trip_id])->first();
            
            $db->query("
                UPDATE trip_assignments 
                SET status = 'Cancelled',
                    notes = CONCAT(COALESCE(notes, ''), '\nCancelled: ', ?)
                WHERE id = ?
            ", [$reason, $trip_id]);
            
            $trip_orders = $db->query("SELECT order_id FROM trip_order_assignments WHERE trip_id = ?", [$trip_id])->results();
            
            $db->query("DELETE FROM trip_order_assignments WHERE trip_id = ?", [$trip_id]);
            
            if (!empty($trip_orders)) {
                $order_ids = array_column($trip_orders, 'order_id');
                $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
                $db->query("UPDATE credit_orders SET status = 'ready_to_ship' WHERE id IN ($placeholders)", $order_ids);
            }
            
            $db->query("UPDATE drivers SET is_available = 1 WHERE id = ?", [$trip->driver_id]);
            
            $success = "Trip #$trip_id cancelled";
            
        } elseif ($action === 'update_notes') {
            $notes = trim($_POST['notes'] ?? '');
            $db->query("UPDATE trip_assignments SET notes = ? WHERE id = ?", [$notes, $trip_id]);
            $success = "Notes updated";
        }
        
        $db->getPdo()->commit();
        $_SESSION['success_flash'] = $success;
        header('Location: index.php?status=' . $status_filter . '&date_from=' . $date_from . '&date_to=' . $date_to);
        exit();
        
    } catch (Exception $e) {
        if ($db->getPdo()->inTransaction()) {
            $db->getPdo()->rollBack();
        }
        $error = $e->getMessage();
        error_log("Trip management error: " . $e->getMessage());
    }
}

require_once '../../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="mb-6 flex justify-between items-start">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">🚛 Trip Management</h1>
        <p class="text-lg text-gray-600 mt-1">Monitor and manage delivery trips</p>
    </div>
    <div class="flex gap-3">
        <a href="consolidate.php" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
            <i class="fas fa-layer-group mr-2"></i>Smart Consolidation
            <?php if (!empty($consolidation_opportunities)): ?>
            <span class="ml-2 px-2 py-1 bg-white text-purple-600 rounded-full text-xs font-bold">
                <?php echo count($consolidation_opportunities); ?>
            </span>
            <?php endif; ?>
        </a>
        <a href="schedule.php" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
            <i class="fas fa-plus mr-2"></i>Create Trip
        </a>
    </div>
</div>

<?php if ($error): ?>
<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg">
    <p class="font-bold">Error</p>
    <p><?php echo htmlspecialchars($error); ?></p>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['success_flash'])): ?>
<div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-r-lg shadow-md">
    <p class="font-bold">Success</p>
    <p><?php echo htmlspecialchars($_SESSION['success_flash']); ?></p>
</div>
<?php unset($_SESSION['success_flash']); ?>
<?php endif; ?>

<!-- Consolidation Opportunities Alert -->
<?php if (!empty($consolidation_opportunities)): ?>
<div class="bg-gradient-to-r from-purple-50 to-blue-50 border-2 border-purple-300 rounded-lg p-6 mb-6 shadow-lg">
    <div class="flex items-start justify-between">
        <div class="flex-1">
            <h3 class="text-lg font-bold text-purple-900 mb-2">
                <i class="fas fa-lightbulb text-yellow-500 mr-2"></i>
                Smart Consolidation Opportunities Available!
            </h3>
            <p class="text-purple-800 mb-4">
                We found <?php echo count($consolidation_opportunities); ?> opportunities to reduce transportation costs by consolidating orders.
                Potential total savings: <strong class="text-green-600">৳<?php echo number_format(array_sum(array_column($consolidation_opportunities, 'potential_savings')), 2); ?></strong>
            </p>
            <div class="space-y-2">
                <?php foreach (array_slice($consolidation_opportunities, 0, 3) as $opp): ?>
                <div class="bg-white rounded p-3 border border-purple-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-semibold text-gray-900">
                                <?php echo htmlspecialchars($opp->order1_number); ?>
                            </span>
                            <span class="text-gray-500 mx-2">+</span>
                            <span class="font-semibold text-gray-900">
                                <?php echo htmlspecialchars($opp->order2_number); ?>
                            </span>
                            <span class="text-sm text-gray-600 ml-3">
                                <i class="fas fa-map-marker-alt text-red-500"></i>
                                <?php echo number_format($opp->distance_km, 1); ?> km apart
                            </span>
                        </div>
                        <span class="text-green-600 font-bold">
                            Save ৳<?php echo number_format($opp->potential_savings, 2); ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <a href="consolidate.php" class="ml-6 px-6 py-3 bg-purple-600 text-white rounded-lg hover:bg-purple-700 font-medium whitespace-nowrap">
            View All Opportunities →
        </a>
    </div>
</div>
<?php endif; ?>

<!-- Ready Orders Alert -->
<?php if (!empty($ready_orders)): ?>
<div class="bg-orange-50 border-2 border-orange-300 rounded-lg p-4 mb-6">
    <div class="flex items-center justify-between">
        <div>
            <h3 class="font-bold text-orange-900">
                <i class="fas fa-exclamation-triangle text-orange-500 mr-2"></i>
                <?php echo count($ready_orders); ?> orders ready for dispatch
            </h3>
            <p class="text-sm text-orange-800 mt-1">These orders are not yet assigned to any trip</p>
        </div>
        <button onclick="document.getElementById('readyOrdersPanel').classList.toggle('hidden')" 
                class="px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700">
            <i class="fas fa-eye mr-2"></i>View Orders
        </button>
    </div>
</div>

<!-- Ready Orders Panel (Initially Hidden) -->
<div id="readyOrdersPanel" class="hidden bg-white rounded-lg shadow-md mb-6 overflow-hidden">
    <div class="p-6 border-b border-gray-200 bg-gray-50">
        <h3 class="text-xl font-bold text-gray-800">Orders Ready for Dispatch</h3>
    </div>
    <div class="p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php foreach ($ready_orders as $order): ?>
            <div class="border-2 border-gray-200 rounded-lg p-4 hover:border-blue-400 transition-colors">
                <div class="flex justify-between items-start mb-2">
                    <div>
                        <h4 class="font-bold text-gray-900"><?php echo htmlspecialchars($order->order_number); ?></h4>
                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($order->customer_name); ?></p>
                    </div>
                    <div class="text-right">
                        <p class="font-bold text-blue-600">৳<?php echo number_format($order->total_amount, 2); ?></p>
                        <?php if ($order->total_weight_kg): ?>
                        <p class="text-xs text-gray-600"><?php echo number_format($order->total_weight_kg, 2); ?> kg</p>
                        <?php endif; ?>
                    </div>
                </div>
                <p class="text-xs text-gray-600 mb-2">
                    <i class="fas fa-map-marker-alt mr-1"></i>
                    <?php echo htmlspecialchars(substr($order->shipping_address, 0, 50)); ?>...
                </p>
                <p class="text-xs text-gray-600 mb-3">
                    <i class="fas fa-calendar mr-1"></i>
                    Required: <?php echo date('M j, Y', strtotime($order->required_date)); ?>
                </p>
                <div class="flex gap-2">
                    <a href="schedule.php?order_id=<?php echo $order->id; ?>" 
                       class="flex-1 px-3 py-2 bg-green-600 text-white rounded text-center text-sm hover:bg-green-700">
                        <i class="fas fa-plus mr-1"></i>Create New Trip
                    </a>
                    <button onclick="showAddToTripModal(<?php echo $order->id; ?>, '<?php echo htmlspecialchars(addslashes($order->order_number)); ?>')"
                            class="flex-1 px-3 py-2 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">
                        <i class="fas fa-truck-loading mr-1"></i>Add to Existing
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="bg-white rounded-lg shadow-md p-6 mb-6">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
            <select name="status" class="w-full px-4 py-2 border rounded-lg">
                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Trips</option>
                <option value="Scheduled" <?php echo $status_filter === 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                <option value="In Progress" <?php echo $status_filter === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                <option value="Completed" <?php echo $status_filter === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                <option value="Cancelled" <?php echo $status_filter === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">From Date</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" 
                   class="w-full px-4 py-2 border rounded-lg">
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">To Date</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" 
                   class="w-full px-4 py-2 border rounded-lg">
        </div>
        
        <div class="flex items-end gap-2">
            <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                <i class="fas fa-filter mr-2"></i>Filter
            </button>
            <a href="index.php" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100">
                <i class="fas fa-redo mr-2"></i>Reset
            </a>
        </div>
    </form>
</div>

<!-- Statistics -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <?php
    try {
        $scheduled = $db->query("SELECT COUNT(*) as c FROM trip_assignments WHERE status = 'Scheduled'")->first();
        $in_progress = $db->query("SELECT COUNT(*) as c FROM trip_assignments WHERE status = 'In Progress'")->first();
        $completed = $db->query("SELECT COUNT(*) as c FROM trip_assignments WHERE status = 'Completed'")->first();
        $cancelled = $db->query("SELECT COUNT(*) as c FROM trip_assignments WHERE status = 'Cancelled'")->first();
        
        $stats = [
            'scheduled' => $scheduled ? $scheduled->c : 0,
            'in_progress' => $in_progress ? $in_progress->c : 0,
            'completed' => $completed ? $completed->c : 0,
            'cancelled' => $cancelled ? $cancelled->c : 0
        ];
    } catch (Exception $e) {
        $stats = ['scheduled' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0];
    }
    ?>
    <div class="bg-blue-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Scheduled</p>
        <p class="text-3xl font-bold mt-2"><?php echo $stats['scheduled']; ?></p>
    </div>
    <div class="bg-purple-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">In Progress</p>
        <p class="text-3xl font-bold mt-2"><?php echo $stats['in_progress']; ?></p>
    </div>
    <div class="bg-green-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Completed</p>
        <p class="text-3xl font-bold mt-2"><?php echo $stats['completed']; ?></p>
    </div>
    <div class="bg-red-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Cancelled</p>
        <p class="text-3xl font-bold mt-2"><?php echo $stats['cancelled']; ?></p>
    </div>
</div>

<!-- Trips List -->
<?php if (!empty($trips)): ?>
    <?php foreach ($trips as $trip): 
        $trip_orders = $db->query("
            SELECT toa.*,
                   co.order_number,
                   co.total_amount,
                   co.shipping_address,
                   co.total_weight_kg,
                   c.name as customer_name,
                   c.phone_number as customer_phone
            FROM trip_order_assignments toa
            JOIN credit_orders co ON toa.order_id = co.id
            JOIN customers c ON co.customer_id = c.id
            WHERE toa.trip_id = ?
            ORDER BY toa.sequence_number ASC
        ", [$trip->id])->results();
        
        $status_colors = [
            'Scheduled' => 'blue',
            'In Progress' => 'purple',
            'Completed' => 'green',
            'Cancelled' => 'red'
        ];
        $color = $status_colors[$trip->status] ?? 'gray';
        
        $type_colors = [
            'single' => 'blue',
            'consolidated' => 'purple',
            'return' => 'orange'
        ];
        $type_color = $type_colors[$trip->trip_type] ?? 'gray';
        
        // Use calculated weight if available, otherwise use stored weight
        $total_weight = $trip->calculated_total_weight ?? $trip->total_weight_kg;
    ?>
    
    <div class="bg-white rounded-lg shadow-md mb-6 overflow-hidden">
        <div class="p-6 border-b border-gray-200 bg-gray-50">
            <div class="flex justify-between items-start">
                <div>
                    <div class="flex items-center gap-3 mb-2">
                        <h3 class="text-2xl font-bold text-gray-900">Trip #<?php echo $trip->id; ?></h3>
                        <span class="px-3 py-1 text-xs font-semibold rounded-full bg-<?php echo $color; ?>-100 text-<?php echo $color; ?>-800">
                            <?php echo $trip->status; ?>
                        </span>
                        <span class="px-3 py-1 text-xs font-semibold rounded-full bg-<?php echo $type_color; ?>-100 text-<?php echo $type_color; ?>-800">
                            <?php echo ucfirst($trip->trip_type); ?>
                        </span>
                        <?php if ($trip->trip_type === 'consolidated'): ?>
                        <span class="px-2 py-1 text-xs font-semibold rounded bg-yellow-100 text-yellow-800">
                            <i class="fas fa-star mr-1"></i>Cost Optimized
                        </span>
                        <?php endif; ?>
                    </div>
                    <p class="text-sm text-gray-600">
                        <i class="fas fa-calendar mr-1"></i>
                        <?php echo date('l, M j, Y', strtotime($trip->trip_date)); ?>
                        <?php if ($trip->scheduled_time): ?>
                            at <?php echo date('g:i A', strtotime($trip->scheduled_time)); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-sm text-gray-600">Total Weight</p>
                    <p class="text-2xl font-bold text-blue-600"><?php echo number_format($total_weight, 2); ?> kg</p>
                    <p class="text-xs text-gray-500">
                        Capacity: <?php echo number_format($trip->remaining_capacity_kg, 2); ?> kg left
                        (<?php echo number_format(($total_weight / $trip->capacity_kg) * 100, 1); ?>% used)
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Rest of the trip display code... (keeping it the same as before) -->
        <!-- Vehicle & Driver, Orders, Timeline, Actions sections -->
        
        <div class="p-6 border-b border-gray-200 bg-blue-50">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <h4 class="font-semibold text-gray-800 mb-2 flex items-center">
                        <i class="fas fa-truck text-blue-600 mr-2"></i>Vehicle
                    </h4>
                    <p class="text-lg font-medium"><?php echo htmlspecialchars($trip->vehicle_number); ?></p>
                    <p class="text-sm text-gray-600">
                        <?php echo htmlspecialchars($trip->vehicle_category); ?> • 
                        <?php echo htmlspecialchars($trip->vehicle_type); ?> • 
                        Capacity: <?php echo number_format($trip->capacity_kg, 0); ?> kg
                    </p>
                </div>
                <div>
                    <h4 class="font-semibold text-gray-800 mb-2 flex items-center">
                        <i class="fas fa-user text-green-600 mr-2"></i>Driver
                    </h4>
                    <p class="text-lg font-medium"><?php echo htmlspecialchars($trip->driver_name); ?></p>
                    <p class="text-sm text-gray-600">
                        <i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($trip->driver_phone); ?> • 
                        <?php echo htmlspecialchars($trip->driver_type); ?>
                    </p>
                </div>
            </div>
        </div>
        
        <div class="p-6 border-b border-gray-200">
            <div class="flex justify-between items-center mb-4">
                <h4 class="font-semibold text-gray-800">
                    <i class="fas fa-boxes mr-2"></i>Orders in Trip (<?php echo count($trip_orders); ?>)
                </h4>
                <?php if (in_array($trip->status, ['Scheduled', 'In Progress'])): ?>
                <button onclick="showAddToTripModal(null, null, <?php echo $trip->id; ?>)"
                        class="px-3 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">
                    <i class="fas fa-plus mr-1"></i>Add Order
                </button>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($trip_orders)): ?>
            <div class="space-y-3">
                <?php foreach ($trip_orders as $order): 
                    $delivery_colors = [
                        'pending' => 'gray',
                        'in_transit' => 'blue',
                        'delivered' => 'green',
                        'failed' => 'red'
                    ];
                    $delivery_color = $delivery_colors[$order->delivery_status] ?? 'gray';
                ?>
                <div class="border rounded-lg p-4 bg-gray-50 hover:bg-gray-100 transition-colors">
                    <div class="flex justify-between items-start">
                        <div class="flex-1">
                            <div class="flex items-center gap-3 mb-2">
                                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-600 text-white text-sm font-bold">
                                    <?php echo $order->sequence_number; ?>
                                </span>
                                <h5 class="font-bold text-gray-900">
                                    <a href="../../credit/credit_order_view.php?id=<?php echo $order->order_id; ?>" 
                                       class="text-blue-600 hover:text-blue-800">
                                        <?php echo htmlspecialchars($order->order_number); ?>
                                    </a>
                                </h5>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-<?php echo $delivery_color; ?>-100 text-<?php echo $delivery_color; ?>-800">
                                    <?php echo ucwords(str_replace('_', ' ', $order->delivery_status)); ?>
                                </span>
                            </div>
                            <p class="text-sm text-gray-600">
                                <strong>Customer:</strong> <?php echo htmlspecialchars($order->customer_name); ?> 
                                (<?php echo htmlspecialchars($order->customer_phone); ?>)
                            </p>
                            <p class="text-sm text-gray-600 mt-1">
                                <i class="fas fa-map-marker-alt mr-1"></i>
                                <?php echo htmlspecialchars($order->destination_address); ?>
                            </p>
                            <?php if ($order->actual_arrival): ?>
                            <p class="text-sm text-green-600 mt-1">
                                <i class="fas fa-check-circle mr-1"></i>
                                Delivered: <?php echo date('M j, g:i A', strtotime($order->actual_arrival)); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <div class="text-right ml-4">
                            <p class="text-lg font-bold text-blue-600">৳<?php echo number_format($order->total_amount, 2); ?></p>
                            <?php if ($order->total_weight_kg): ?>
                            <p class="text-xs text-gray-600"><?php echo number_format($order->total_weight_kg, 2); ?> kg</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="text-gray-500 text-center py-4">No orders assigned to this trip</p>
            <?php endif; ?>
        </div>
        
        <div class="p-6 bg-gray-50">
            <div class="flex flex-wrap gap-3">
                <?php if ($trip->status === 'Scheduled'): ?>
                <form method="POST" class="inline">
                    <input type="hidden" name="trip_id" value="<?php echo $trip->id; ?>">
                    <button type="submit" name="action" value="start_trip"
                            onclick="return confirm('Start this trip?');"
                            class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                        <i class="fas fa-play mr-2"></i>Start Trip
                    </button>
                </form>
                <?php endif; ?>
                
                <?php if ($trip->status === 'In Progress'): ?>
                <form method="POST" class="inline">
                    <input type="hidden" name="trip_id" value="<?php echo $trip->id; ?>">
                    <button type="submit" name="action" value="complete_trip"
                            onclick="return confirm('Mark this trip as completed? All orders must be delivered.');"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        <i class="fas fa-check mr-2"></i>Complete Trip
                    </button>
                </form>
                <?php endif; ?>
                
                <a href="view.php?id=<?php echo $trip->id; ?>" 
                   class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100">
                    <i class="fas fa-eye mr-2"></i>View Full Details
                </a>
                
                <?php if (in_array($trip->status, ['Scheduled', 'In Progress'])): ?>
                <button onclick="showCancelModal(<?php echo $trip->id; ?>)"
                        class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                    <i class="fas fa-times mr-2"></i>Cancel
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

<?php else: ?>
<div class="bg-white rounded-lg shadow-md p-12 text-center">
    <i class="fas fa-route text-6xl text-gray-400 mb-4"></i>
    <h3 class="text-xl font-semibold text-gray-700 mb-2">No Trips Found</h3>
    <p class="text-gray-600 mb-6">No trips match your filter criteria</p>
    <a href="schedule.php" class="inline-block px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
        <i class="fas fa-plus mr-2"></i>Create New Trip
    </a>
</div>
<?php endif; ?>

</div>

<!-- Add Order to Trip Modal -->
<div id="addToTripModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900 mb-4" id="addToTripModalTitle">Add Order to Trip</h3>
            <form method="POST" id="addToTripForm">
                <input type="hidden" name="action" value="add_order_to_trip">
                <input type="hidden" name="order_id" id="add_order_id">
                <input type="hidden" name="trip_id" id="add_trip_id">
                
                <div class="mb-4" id="tripSelectContainer">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Select Trip</label>
                    <select name="trip_id_select" id="trip_id_select" required
                            class="w-full px-4 py-2 border rounded-lg">
                        <option value="">-- Choose a trip --</option>
                        <?php foreach ($trips as $t): ?>
                            <?php if (in_array($t->status, ['Scheduled', 'In Progress'])): ?>
                            <option value="<?php echo $t->id; ?>" 
                                    data-capacity="<?php echo $t->remaining_capacity_kg; ?>"
                                    data-vehicle="<?php echo htmlspecialchars($t->vehicle_number); ?>">
                                Trip #<?php echo $t->id; ?> - <?php echo htmlspecialchars($t->vehicle_number); ?> 
                                (<?php echo number_format($t->remaining_capacity_kg, 0); ?> kg available)
                            </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="flex gap-3">
                    <button type="submit" 
                            class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        <i class="fas fa-plus-circle mr-2"></i>Add to Trip
                    </button>
                    <button type="button" onclick="closeAddToTripModal()"
                            class="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cancel Trip Modal -->
<div id="cancelModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Cancel Trip</h3>
            <form method="POST" id="cancelForm">
                <input type="hidden" name="action" value="cancel_trip">
                <input type="hidden" name="trip_id" id="cancel_trip_id">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Cancellation Reason *</label>
                    <textarea name="cancel_reason" rows="3" required
                              class="w-full px-4 py-2 border rounded-lg"
                              placeholder="Please provide reason for cancellation..."></textarea>
                </div>
                
                <div class="flex gap-3">
                    <button type="submit" 
                            class="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                        Cancel Trip
                    </button>
                    <button type="button" onclick="closeCancelModal()"
                            class="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100">
                        Close
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showAddToTripModal(orderId, orderNumber, preselectedTripId) {
    if (preselectedTripId) {
        document.getElementById('add_trip_id').value = preselectedTripId;
        document.getElementById('tripSelectContainer').style.display = 'none';
        document.getElementById('addToTripModalTitle').textContent = 'Select Order to Add to Trip #' + preselectedTripId;
    } else {
        document.getElementById('add_order_id').value = orderId;
        document.getElementById('addToTripModalTitle').textContent = 'Add Order ' + orderNumber + ' to Trip';
        document.getElementById('tripSelectContainer').style.display = 'block';
    }
    document.getElementById('addToTripModal').classList.remove('hidden');
}

function closeAddToTripModal() {
    document.getElementById('addToTripModal').classList.add('hidden');
}

document.getElementById('trip_id_select').addEventListener('change', function() {
    document.getElementById('add_trip_id').value = this.value;
});

function showCancelModal(tripId) {
    document.getElementById('cancel_trip_id').value = tripId;
    document.getElementById('cancelModal').classList.remove('hidden');
}

function closeCancelModal() {
    document.getElementById('cancelModal').classList.add('hidden');
}

window.onclick = function(event) {
    const addModal = document.getElementById('addToTripModal');
    const cancelModal = document.getElementById('cancelModal');
    if (event.target == addModal) {
        closeAddToTripModal();
    }
    if (event.target == cancelModal) {
        closeCancelModal();
    }
}
</script>

<?php require_once '../../templates/footer.php'; ?>