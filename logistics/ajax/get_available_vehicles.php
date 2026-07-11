<?php
require_once '../../core/init.php';
header('Content-Type: application/json');

global $db;

try {
    // Get active vehicles
    $vehicles = $db->query(
        "SELECT 
            v.id,
            v.vehicle_number,
            v.vehicle_type,
            v.category,
            v.capacity_kg,
            v.fuel_type,
            v.current_mileage,
            v.status,
            d.driver_name,
            d.id as assigned_driver_id
         FROM vehicles v
         LEFT JOIN drivers d ON d.assigned_vehicle_id = v.id AND d.status = 'Active'
         WHERE v.status = 'Active'
         ORDER BY 
            CASE v.vehicle_type 
                WHEN 'Own' THEN 1 
                WHEN 'Rented' THEN 2 
            END,
            v.vehicle_number"
    )->results();
    
    echo json_encode([
        'success' => true,
        'vehicles' => $vehicles
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}