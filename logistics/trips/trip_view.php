<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'dispatch-srg', 'dispatch-demra', 'dispatchpos-demra', 'dispatchpos-srg'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$user_role = $currentUser['role'] ?? '';
$pageTitle = 'Trip Details';
$error = null;
$success = null;

$trip_id = (int)($_GET['id'] ?? 0);

if (!$trip_id) {
    header('Location: trips.php');
    exit();
}

// Get trip details
$trip = $db->query(
    "SELECT ta.*,
            v.vehicle_number,
            v.category as vehicle_category,
            v.vehicle_type,
            v.capacity_kg,
            v.fuel_type,
            v.registration_number,
            d.driver_name,
            d.phone_number as driver_phone,
            d.driver_type,
            d.license_number,
            d.rating as driver_rating,
            u.display_name as created_by_name
     FROM trip_assignments ta
     LEFT JOIN vehicles v ON ta.vehicle_id = v.id
     LEFT JOIN drivers d ON ta.driver_id = d.id
     LEFT JOIN users u ON ta.created_by_user_id = u.id
     WHERE ta.id = ?",
    [$trip_id]
)->first();

if (!$trip) {
    $_SESSION['error_flash'] = "Trip not found";
    header('Location: trips.php');
    exit();
}

// Get orders for this trip
$trip_orders = $db->query("
    SELECT toa.*,
           co.order_number,
           co.total_amount,
           co.shipping_address,
           co.special_instructions,
           co.total_weight_kg as order_weight,
           c.name as customer_name,
           c.phone_number as customer_phone,
           c.email as customer_email,
           b.name as branch_name
    FROM trip_order_assignments toa
    JOIN credit_orders co ON toa.order_id = co.id
    JOIN customers c ON co.customer_id = c.id
    LEFT JOIN branches b ON co.assigned_branch_id = b.id
    WHERE toa.trip_id = ?
    ORDER BY toa.sequence_number ASC
", [$trip_id])->results();

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $db->getPdo()->beginTransaction();
        
        if ($action === 'start_trip') {
            $db->query("
                UPDATE trip_assignments 
                SET status = 'In Progress',
                    actual_start_time = NOW()
                WHERE id = ?
            ", [$trip_id]);
            
            $success = "Trip started successfully";
            
        } elseif ($action === 'complete_trip') {
            // Check if all orders are delivered
            $pending = $db->query("
                SELECT COUNT(*) as cnt 
                FROM trip_order_assignments 
                WHERE trip_id = ? AND delivery_status != 'delivered'
            ", [$trip_id])->first();
            
            if ($pending->cnt > 0) {
                throw new Exception("Cannot complete trip. $pending->cnt order(s) not yet delivered.");
            }
            
            $db->query("
                UPDATE trip_assignments 
                SET status = 'Completed',
                    actual_end_time = NOW()
                WHERE id = ?
            ", [$trip_id]);
            
            // Update driver availability
            $db->query("
                UPDATE drivers 
                SET is_available = 1 
                WHERE id = ?
            ", [$trip->driver_id]);
            
            $success = "Trip completed successfully";
            
        } elseif ($action === 'update_order_delivery') {
            $order_id = (int)$_POST['order_id'];
            $delivery_status = $_POST['delivery_status'];
            $delivery_notes = trim($_POST['delivery_notes'] ?? '');
            
            // Update trip_order_assignments
            $update_data = ['delivery_status' => $delivery_status];
            
            if ($delivery_status === 'delivered') {
                $update_data['actual_arrival'] = date('Y-m-d H:i:s');
            }
            
            if ($delivery_notes) {
                $update_data['delivery_notes'] = $delivery_notes;
            }
            
            $db->query("
                UPDATE trip_order_assignments 
                SET delivery_status = ?,
                    actual_arrival = " . ($delivery_status === 'delivered' ? 'NOW()' : 'actual_arrival') . ",
                    delivery_notes = ?
                WHERE trip_id = ? AND order_id = ?
            ", [$delivery_status, $delivery_notes, $trip_id, $order_id]);
            
            // Update order status if delivered
            if ($delivery_status === 'delivered') {
                $db->query("UPDATE credit_orders SET status = 'delivered' WHERE id = ?", [$order_id]);
                
                // Update shipping record
                $db->query("
                    UPDATE credit_order_shipping 
                    SET delivered_date = NOW(),
                        delivered_by_user_id = ?,
                        delivery_notes = ?
                    WHERE order_id = ?
                ", [$user_id, $delivery_notes, $order_id]);
                
                // Log workflow
                $db->insert('credit_order_workflow', [
                    'order_id' => $order_id,
                    'from_status' => 'shipped',
                    'to_status' => 'delivered',
                    'action' => 'deliver',
                    'performed_by_user_id' => $user_id,
                    'comments' => 'Delivered via Trip #' . $trip_id . ($delivery_notes ? ': ' . $delivery_notes : '')
                ]);
            }
            
            $success = "Delivery status updated";
            
        } elseif ($action === 'update_notes') {
            $notes = trim($_POST['notes'] ?? '');
            $db->query("UPDATE trip_assignments SET notes = ? WHERE id = ?", [$notes, $trip_id]);
            $success = "Notes updated";
            
        } elseif ($action === 'cancel_trip') {
            $reason = trim($_POST['cancel_reason'] ?? '');
            if (empty($reason)) {
                throw new Exception("Please provide a cancellation reason");
            }
            
            $db->query("
                UPDATE trip_assignments 
                SET status = 'Cancelled',
                    notes = CONCAT(COALESCE(notes, ''), '\nCancelled: ', ?)
                WHERE id = ?
            ", [$reason, $trip_id]);
            
            // Remove all order assignments
            $db->query("DELETE FROM trip_order_assignments WHERE trip_id = ?", [$trip_id]);
            
            // Reset orders back to ready_to_ship
            $order_ids = array_column($trip_orders, 'order_id');
            if (!empty($order_ids)) {
                $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
                $db->query("UPDATE credit_orders SET status = 'ready_to_ship' WHERE id IN ($placeholders)", $order_ids);
            }
            
            // Update driver availability
            $db->query("UPDATE drivers SET is_available = 1 WHERE id = ?", [$trip->driver_id]);
            
            $success = "Trip cancelled";
        }
        
        $db->getPdo()->commit();
        $_SESSION['success_flash'] = $success;
        header('Location: trip_view.php?id=' . $trip_id);
        exit();
        
    } catch (Exception $e) {
        if ($db->getPdo()->inTransaction()) {
            $db->getPdo()->rollBack();
        }
        $error = $e->getMessage();
    }
}

// Refresh trip data after updates
$trip = $db->query(
    "SELECT ta.*,
            v.vehicle_number,
            v.category as vehicle_category,
            v.vehicle_type,
            v.capacity_kg,
            v.fuel_type,
            v.registration_number,
            d.driver_name,
            d.phone_number as driver_phone,
            d.driver_type,
            d.license_number,
            d.rating as driver_rating,
            u.display_name as created_by_name
     FROM trip_assignments ta
     LEFT JOIN vehicles v ON ta.vehicle_id = v.id
     LEFT JOIN drivers d ON ta.driver_id = d.id
     LEFT JOIN users u ON ta.created_by_user_id = u.id
     WHERE ta.id = ?",
    [$trip_id]
)->first();

$trip_orders = $db->query("
    SELECT toa.*,
           co.order_number,
           co.total_amount,
           co.shipping_address,
           co.special_instructions,
           co.total_weight_kg as order_weight,
           c.name as customer_name,
           c.phone_number as customer_phone,
           c.email as customer_email,
           b.name as branch_name
    FROM trip_order_assignments toa
    JOIN credit_orders co ON toa.order_id = co.id
    JOIN customers c ON co.customer_id = c.id
    LEFT JOIN branches b ON co.assigned_branch_id = b.id
    WHERE toa.trip_id = ?
    ORDER BY toa.sequence_number ASC
", [$trip_id])->results();

require_once '../templates/header.php';

$status_colors = [
    'Scheduled' => 'blue',
    'In Progress' => 'purple',
    'Completed' => 'green',
    'Cancelled' => 'red'
];
$color = $status_colors[$trip->status] ?? 'gray';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<!-- Header -->
<div class="mb-6 flex justify-between items-start">
    <div>
        <div class="flex items-center gap-3 mb-2">
            <h1 class="text-3xl font-bold text-gray-900">Trip #<?php echo $trip->id; ?></h1>
            <span class="px-3 py-1 text-sm font-semibold rounded-full bg-<?php echo $color; ?>-100 text-<?php echo $color; ?>-800">
                <?php echo $trip->status; ?>
            </span>
            <span class="px-3 py-1 text-sm font-semibold rounded-full bg-purple-100 text-purple-800">
                <?php echo ucfirst($trip->trip_type); ?>
            </span>
        </div>
        <p class="text-lg text-gray-600">
            <i class="fas fa-calendar mr-1"></i>
            <?php echo date('l, F j, Y', strtotime($trip->trip_date)); ?>
            <?php if ($trip->scheduled_time): ?>
                at <?php echo date('g:i A', strtotime($trip->scheduled_time)); ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="flex gap-3">
        <a href="trips.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100">
            <i class="fas fa-arrow-left mr-2"></i>Back to Trips
        </a>
        <button onclick="window.print()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            <i class="fas fa-print mr-2"></i>Print
        </button>
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

<!-- Trip Summary -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-blue-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Total Orders</p>
        <p class="text-3xl font-bold mt-2"><?php echo $trip->total_orders; ?></p>
    </div>
    <div class="bg-purple-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Total Weight</p>
        <p class="text-3xl font-bold mt-2"><?php echo number_format($trip->total_weight_kg, 0); ?> kg</p>
    </div>
    <div class="bg-green-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Remaining Capacity</p>
        <p class="text-3xl font-bold mt-2"><?php echo number_format($trip->remaining_capacity_kg, 0); ?> kg</p>
    </div>
    <div class="bg-orange-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Total Value</p>
        <p class="text-3xl font-bold mt-2">৳<?php echo number_format(array_sum(array_column($trip_orders, 'total_amount')), 0); ?></p>
    </div>
</div>

<!-- Vehicle & Driver Info -->
<div class="bg-white rounded-lg shadow-md mb-6 overflow-hidden">
    <div class="p-6 border-b border-gray-200 bg-gray-50">
        <h2 class="text-xl font-bold text-gray-800">Vehicle & Driver Information</h2>
    </div>
    <div class="p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <!-- Vehicle Info -->
            <div>
                <h3 class="font-semibold text-lg text-gray-800 mb-4 flex items-center">
                    <i class="fas fa-truck text-blue-600 mr-2 text-xl"></i>Vehicle
                </h3>
                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Vehicle Number:</span>
                        <span class="font-semibold"><?php echo htmlspecialchars($trip->vehicle_number); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Registration:</span>
                        <span class="font-semibold"><?php echo htmlspecialchars($trip->registration_number ?? 'N/A'); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Category:</span>
                        <span class="font-semibold"><?php echo htmlspecialchars($trip->vehicle_category); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Type:</span>
                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-semibold <?php echo $trip->vehicle_type === 'Own' ? 'bg-green-100 text-green-800' : 'bg-purple-100 text-purple-800'; ?>">
                            <?php echo htmlspecialchars($trip->vehicle_type); ?>
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Fuel Type:</span>
                        <span class="font-semibold"><?php echo htmlspecialchars($trip->fuel_type); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Capacity:</span>
                        <span class="font-semibold"><?php echo number_format($trip->capacity_kg, 0); ?> kg</span>
                    </div>
                </div>
            </div>
            
            <!-- Driver Info -->
            <div>
                <h3 class="font-semibold text-lg text-gray-800 mb-4 flex items-center">
                    <i class="fas fa-user text-green-600 mr-2 text-xl"></i>Driver
                </h3>
                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Name:</span>
                        <span class="font-semibold"><?php echo htmlspecialchars($trip->driver_name); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Phone:</span>
                        <span class="font-semibold">
                            <a href="tel:<?php echo htmlspecialchars($trip->driver_phone); ?>" class="text-blue-600 hover:text-blue-800">
                                <i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($trip->driver_phone); ?>
                            </a>
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">License:</span>
                        <span class="font-semibold"><?php echo htmlspecialchars($trip->license_number ?? 'N/A'); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Type:</span>
                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-semibold <?php echo $trip->driver_type === 'Permanent' ? 'bg-blue-100 text-blue-800' : 'bg-purple-100 text-purple-800'; ?>">
                            <?php echo htmlspecialchars($trip->driver_type); ?>
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Rating:</span>
                        <span class="font-semibold">
                            <?php 
                            $rating = floatval($trip->driver_rating ?? 0);
                            echo str_repeat('⭐', floor($rating));
                            echo ' ' . number_format($rating, 1) . '/5';
                            ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Timeline -->
<div class="bg-white rounded-lg shadow-md mb-6 overflow-hidden">
    <div class="p-6 border-b border-gray-200 bg-gray-50">
        <h2 class="text-xl font-bold text-gray-800">Trip Timeline</h2>
    </div>
    <div class="p-6">
        <div class="space-y-4">
            <?php if ($trip->created_at): ?>
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-plus-circle text-blue-600"></i>
                    </div>
                </div>
                <div class="ml-4 flex-1">
                    <p class="font-semibold text-gray-900">Trip Created</p>
                    <p class="text-sm text-gray-600">
                        <?php echo date('F j, Y g:i A', strtotime($trip->created_at)); ?>
                        <?php if ($trip->created_by_name): ?>
                            by <?php echo htmlspecialchars($trip->created_by_name); ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($trip->actual_start_time): ?>
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <div class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-play-circle text-green-600"></i>
                    </div>
                </div>
                <div class="ml-4 flex-1">
                    <p class="font-semibold text-gray-900">Trip Started</p>
                    <p class="text-sm text-gray-600">
                        <?php echo date('F j, Y g:i A', strtotime($trip->actual_start_time)); ?>
                    </p>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($trip->actual_end_time): ?>
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <div class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-check-circle text-green-600"></i>
                    </div>
                </div>
                <div class="ml-4 flex-1">
                    <p class="font-semibold text-gray-900">Trip Completed</p>
                    <p class="text-sm text-gray-600">
                        <?php echo date('F j, Y g:i A', strtotime($trip->actual_end_time)); ?>
                    </p>
                    <?php if ($trip->actual_start_time): 
                        $duration = strtotime($trip->actual_end_time) - strtotime($trip->actual_start_time);
                        $hours = floor($duration / 3600);
                        $minutes = floor(($duration % 3600) / 60);
                    ?>
                    <p class="text-sm text-gray-500">
                        Duration: <?php echo $hours; ?>h <?php echo $minutes; ?>m
                    </p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($trip->status === 'Scheduled' && !$trip->actual_start_time): ?>
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <div class="w-10 h-10 bg-gray-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-clock text-gray-400"></i>
                    </div>
                </div>
                <div class="ml-4 flex-1">
                    <p class="font-semibold text-gray-500">Awaiting Start</p>
                    <p class="text-sm text-gray-400">Trip has not started yet</p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Orders in Trip -->
<div class="bg-white rounded-lg shadow-md mb-6 overflow-hidden">
    <div class="p-6 border-b border-gray-200 bg-gray-50">
        <h2 class="text-xl font-bold text-gray-800">Orders in Trip (<?php echo count($trip_orders); ?>)</h2>
    </div>
    <div class="p-6">
        <?php if (!empty($trip_orders)): ?>
        <div class="space-y-4">
            <?php foreach ($trip_orders as $order): 
                $delivery_colors = [
                    'pending' => 'gray',
                    'in_transit' => 'blue',
                    'delivered' => 'green',
                    'failed' => 'red'
                ];
                $delivery_color = $delivery_colors[$order->delivery_status] ?? 'gray';
            ?>
            <div class="border-2 rounded-lg p-6 hover:shadow-lg transition-shadow <?php echo $order->delivery_status === 'delivered' ? 'border-green-200 bg-green-50' : 'border-gray-200'; ?>">
                <div class="flex justify-between items-start mb-4">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-blue-600 text-white text-lg font-bold">
                            <?php echo $order->sequence_number; ?>
                        </span>
                        <div>
                            <h3 class="text-lg font-bold">
                                <a href="../credit/credit_order_view.php?id=<?php echo $order->order_id; ?>" 
                                   class="text-blue-600 hover:text-blue-800">
                                    <?php echo htmlspecialchars($order->order_number); ?>
                                </a>
                            </h3>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-<?php echo $delivery_color; ?>-100 text-<?php echo $delivery_color; ?>-800">
                                <?php echo ucwords(str_replace('_', ' ', $order->delivery_status)); ?>
                            </span>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-2xl font-bold text-blue-600">৳<?php echo number_format($order->total_amount, 2); ?></p>
                        <?php if ($order->order_weight): ?>
                        <p class="text-sm text-gray-600"><?php echo number_format($order->order_weight, 2); ?> kg</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <h4 class="font-semibold text-gray-700 mb-1">Customer</h4>
                        <p class="text-gray-900"><?php echo htmlspecialchars($order->customer_name); ?></p>
                        <p class="text-sm text-gray-600">
                            <i class="fas fa-phone mr-1"></i>
                            <a href="tel:<?php echo htmlspecialchars($order->customer_phone); ?>" class="text-blue-600 hover:text-blue-800">
                                <?php echo htmlspecialchars($order->customer_phone); ?>
                            </a>
                        </p>
                        <?php if ($order->customer_email): ?>
                        <p class="text-sm text-gray-600">
                            <i class="fas fa-envelope mr-1"></i>
                            <a href="mailto:<?php echo htmlspecialchars($order->customer_email); ?>" class="text-blue-600 hover:text-blue-800">
                                <?php echo htmlspecialchars($order->customer_email); ?>
                            </a>
                        </p>
                        <?php endif; ?>
                    </div>
                    
                    <div>
                        <h4 class="font-semibold text-gray-700 mb-1">Delivery Address</h4>
                        <p class="text-gray-900">
                            <i class="fas fa-map-marker-alt mr-1 text-red-500"></i>
                            <?php echo htmlspecialchars($order->destination_address); ?>
                        </p>
                        <?php if ($order->branch_name): ?>
                        <p class="text-sm text-gray-600 mt-1">
                            <i class="fas fa-building mr-1"></i>Branch: <?php echo htmlspecialchars($order->branch_name); ?>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($order->special_instructions): ?>
                <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded">
                    <p class="text-sm font-semibold text-gray-700 mb-1">
                        <i class="fas fa-info-circle text-blue-600 mr-1"></i>Special Instructions:
                    </p>
                    <p class="text-sm text-gray-700"><?php echo htmlspecialchars($order->special_instructions); ?></p>
                </div>
                <?php endif; ?>
                
                <?php if ($order->actual_arrival): ?>
                <div class="mb-4 p-3 bg-green-50 border border-green-200 rounded">
                    <p class="text-sm text-green-700">
                        <i class="fas fa-check-circle mr-1"></i>
                        <strong>Delivered:</strong> <?php echo date('F j, Y g:i A', strtotime($order->actual_arrival)); ?>
                    </p>
                    <?php if ($order->delivery_notes): ?>
                    <p class="text-sm text-gray-700 mt-1">
                        <strong>Notes:</strong> <?php echo htmlspecialchars($order->delivery_notes); ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <div class="flex gap-3">
                    <a href="../credit/credit_order_view.php?id=<?php echo $order->order_id; ?>" 
                       class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 text-sm">
                        <i class="fas fa-eye mr-1"></i>View Order
                    </a>
                    
                    <?php if (in_array($trip->status, ['In Progress']) && $order->delivery_status !== 'delivered'): ?>
                    <button onclick="showDeliveryModal(<?php echo $order->order_id; ?>, '<?php echo htmlspecialchars(addslashes($order->order_number)); ?>')"
                            class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm">
                        <i class="fas fa-check-circle mr-1"></i>Mark Delivered
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-center text-gray-500 py-8">No orders assigned to this trip</p>
        <?php endif; ?>
    </div>
</div>

<!-- Notes & Route -->
<?php if ($trip->notes || $trip->route_summary): ?>
<div class="bg-white rounded-lg shadow-md mb-6 overflow-hidden">
    <div class="p-6 border-b border-gray-200 bg-gray-50">
        <h2 class="text-xl font-bold text-gray-800">Additional Information</h2>
    </div>
    <div class="p-6">
        <?php if ($trip->route_summary): ?>
        <div class="mb-4">
            <h3 class="font-semibold text-gray-700 mb-2">
                <i class="fas fa-route text-blue-600 mr-2"></i>Route Summary
            </h3>
            <p class="text-gray-700"><?php echo htmlspecialchars($trip->route_summary); ?></p>
        </div>
        <?php endif; ?>
        
        <?php if ($trip->notes): ?>
        <div>
            <h3 class="font-semibold text-gray-700 mb-2">
                <i class="fas fa-sticky-note text-yellow-600 mr-2"></i>Notes
            </h3>
            <p class="text-gray-700 whitespace-pre-wrap"><?php echo htmlspecialchars($trip->notes); ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Actions -->
<?php if (in_array($trip->status, ['Scheduled', 'In Progress'])): ?>
<div class="bg-white rounded-lg shadow-md mb-6 overflow-hidden">
    <div class="p-6 border-b border-gray-200 bg-gray-50">
        <h2 class="text-xl font-bold text-gray-800">Trip Actions</h2>
    </div>
    <div class="p-6">
        <div class="flex flex-wrap gap-3">
            <?php if ($trip->status === 'Scheduled'): ?>
            <form method="POST" class="inline">
                <input type="hidden" name="trip_id" value="<?php echo $trip->id; ?>">
                <button type="submit" name="action" value="start_trip"
                        onclick="return confirm('Start this trip now?');"
                        class="px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium">
                    <i class="fas fa-play mr-2"></i>Start Trip
                </button>
            </form>
            <?php endif; ?>
            
            <?php if ($trip->status === 'In Progress'): ?>
            <form method="POST" class="inline">
                <input type="hidden" name="trip_id" value="<?php echo $trip->id; ?>">
                <button type="submit" name="action" value="complete_trip"
                        onclick="return confirm('Mark this trip as completed? All orders must be delivered.');"
                        class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium">
                    <i class="fas fa-check mr-2"></i>Complete Trip
                </button>
            </form>
            <?php endif; ?>
            
            <button onclick="showNotesModal(<?php echo $trip->id; ?>, '<?php echo htmlspecialchars(addslashes($trip->notes ?? '')); ?>')"
                    class="px-6 py-3 border-2 border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 font-medium">
                <i class="fas fa-edit mr-2"></i>Edit Notes
            </button>
            
            <button onclick="showCancelModal(<?php echo $trip->id; ?>)"
                    class="px-6 py-3 bg-red-600 text-white rounded-lg hover:bg-red-700 font-medium">
                <i class="fas fa-times mr-2"></i>Cancel Trip
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

</div>

<!-- Delivery Modal -->
<div id="deliveryModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900 mb-4" id="deliveryModalTitle">Mark Order as Delivered</h3>
            <form method="POST" id="deliveryForm">
                <input type="hidden" name="action" value="update_order_delivery">
                <input type="hidden" name="trip_id" value="<?php echo $trip_id; ?>">
                <input type="hidden" name="order_id" id="delivery_order_id">
                <input type="hidden" name="delivery_status" value="delivered">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Delivery Notes (Optional)</label>
                    <textarea name="delivery_notes" rows="3"
                              class="w-full px-4 py-2 border rounded-lg"
                              placeholder="Any notes about the delivery..."></textarea>
                </div>
                
                <div class="flex gap-3">
                    <button type="submit" 
                            class="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                        <i class="fas fa-check-circle mr-2"></i>Confirm Delivery
                    </button>
                    <button type="button" onclick="closeDeliveryModal()"
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
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Cancel Trip</h3>
            <form method="POST" id="cancelForm">
                <input type="hidden" name="action" value="cancel_trip">
                <input type="hidden" name="trip_id" id="cancel_trip_id" value="<?php echo $trip_id; ?>">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Cancellation Reason *</label>
                    <textarea name="cancel_reason" rows="3" required
                              class="w-full px-4 py-2 border rounded-lg"
                              placeholder="Please provide reason for cancellation..."></textarea>
                </div>
                
                <div class="bg-yellow-50 border border-yellow-200 rounded p-3 mb-4">
                    <p class="text-sm text-yellow-800">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        Warning: All orders will be reset to "Ready to Ship" status.
                    </p>
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

<!-- Notes Modal -->
<div id="notesModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Edit Trip Notes</h3>
            <form method="POST" id="notesForm">
                <input type="hidden" name="action" value="update_notes">
                <input type="hidden" name="trip_id" id="notes_trip_id" value="<?php echo $trip_id; ?>">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
                    <textarea name="notes" id="notes_textarea" rows="5"
                              class="w-full px-4 py-2 border rounded-lg"
                              placeholder="Add notes about this trip..."><?php echo htmlspecialchars($trip->notes ?? ''); ?></textarea>
                </div>
                
                <div class="flex gap-3">
                    <button type="submit" 
                            class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        Save Notes
                    </button>
                    <button type="button" onclick="closeNotesModal()"
                            class="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100">
                        Close
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showDeliveryModal(orderId, orderNumber) {
    document.getElementById('delivery_order_id').value = orderId;
    document.getElementById('deliveryModalTitle').textContent = 'Mark Order ' + orderNumber + ' as Delivered';
    document.getElementById('deliveryModal').classList.remove('hidden');
}

function closeDeliveryModal() {
    document.getElementById('deliveryModal').classList.add('hidden');
}

function showCancelModal(tripId) {
    document.getElementById('cancel_trip_id').value = tripId;
    document.getElementById('cancelModal').classList.remove('hidden');
}

function closeCancelModal() {
    document.getElementById('cancelModal').classList.add('hidden');
}

function showNotesModal(tripId, currentNotes) {
    document.getElementById('notes_trip_id').value = tripId;
    document.getElementById('notes_textarea').value = currentNotes;
    document.getElementById('notesModal').classList.remove('hidden');
}

function closeNotesModal() {
    document.getElementById('notesModal').classList.add('hidden');
}

// Close modals when clicking outside
window.onclick = function(event) {
    const deliveryModal = document.getElementById('deliveryModal');
    const cancelModal = document.getElementById('cancelModal');
    const notesModal = document.getElementById('notesModal');
    
    if (event.target == deliveryModal) {
        closeDeliveryModal();
    }
    if (event.target == cancelModal) {
        closeCancelModal();
    }
    if (event.target == notesModal) {
        closeNotesModal();
    }
}
</script>

<?php require_once '../templates/footer.php'; ?>