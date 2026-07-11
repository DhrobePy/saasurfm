<?php
require_once '../../core/init.php';
header('Content-Type: application/json');

global $db;

$data = json_decode(file_get_contents('php://input'), true);

$order_id = $data['order_id'] ?? null;
$vehicle_id = $data['vehicle_id'] ?? null;
$driver_id = $data['driver_id'] ?? null;
$trip_date = $data['trip_date'] ?? null;
$trip_time = $data['trip_time'] ?? null;

if (!$order_id || !$vehicle_id || !$driver_id || !$trip_date) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    $db->getPdo()->beginTransaction();
    
    // Get order details
    $order = $db->query("SELECT * FROM credit_orders WHERE id = ?", [$order_id])->first();
    
    if (!$order) {
        throw new Exception('Order not found');
    }
    
    // Get vehicle and driver info
    $vehicle = $db->query("SELECT * FROM vehicles WHERE id = ?", [$vehicle_id])->first();
    $driver = $db->query("SELECT * FROM drivers WHERE id = ?", [$driver_id])->first();
    
    // Create trip assignment
    $trip_id = $db->insert('trip_assignments', [
        'order_id' => $order_id,
        'vehicle_id' => $vehicle_id,
        'driver_id' => $driver_id,
        'trip_date' => $trip_date,
        'scheduled_time' => $trip_time,
        'destination' => $order->shipping_address,
        'status' => 'Scheduled',
        'created_by_user_id' => $_SESSION['user_id']
    ]);
    
    // Update credit_orders table with transport info
    $db->query(
        "UPDATE credit_orders 
         SET truck_number = ?, 
             driver_name = ?, 
             driver_contact = ?
         WHERE id = ?",
        [$vehicle->vehicle_number, $driver->driver_name, $driver->phone_number, $order_id]
    );
    
    // Update credit_order_shipping if exists, or insert
    $shipping = $db->query("SELECT * FROM credit_order_shipping WHERE order_id = ?", [$order_id])->first();
    
    if ($shipping) {
        $db->query(
            "UPDATE credit_order_shipping 
             SET truck_number = ?, driver_name = ?, driver_contact = ?
             WHERE order_id = ?",
            [$vehicle->vehicle_number, $driver->driver_name, $driver->phone_number, $order_id]
        );
    } else {
        $db->insert('credit_order_shipping', [
            'order_id' => $order_id,
            'truck_number' => $vehicle->vehicle_number,
            'driver_name' => $driver->driver_name,
            'driver_contact' => $driver->phone_number
        ]);
    }
    
    // Update driver assignment
    $db->query("UPDATE drivers SET assigned_vehicle_id = ? WHERE id = ?", [$vehicle_id, $driver_id]);
    
    $db->getPdo()->commit();
    
    echo json_encode([
        'success' => true, 
        'trip_id' => $trip_id,
        'message' => "Transport assigned: {$vehicle->vehicle_number} with {$driver->driver_name}"
    ]);
    
} catch (Exception $e) {
    if ($db->getPdo()->inTransaction()) {
        $db->getPdo()->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}