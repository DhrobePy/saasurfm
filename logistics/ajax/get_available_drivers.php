<?php
require_once '../../core/init.php';
header('Content-Type: application/json');

global $db;

try {
    $vehicle_id = $_GET['vehicle_id'] ?? null;
    $date = $_GET['date'] ?? date('Y-m-d');
    
    if (!$vehicle_id) {
        throw new Exception('Vehicle ID is required');
    }
    
    // Get drivers not assigned to any trip on this date
    $drivers = $db->query(
        "SELECT 
            d.id,
            d.driver_name,
            d.phone_number,
            d.driver_type,
            d.status,
            COALESCE(d.rating, 3.0) as rating,
            d.total_trips,
            d.assigned_vehicle_id,
            COUNT(DISTINCT ta_completed.id) as completed_trips,
            AVG(CASE 
                WHEN ta_completed.status = 'Completed' 
                AND ta_completed.actual_end_time IS NOT NULL 
                THEN 1 
                ELSE 0 
            END) as completion_rate
         FROM drivers d
         LEFT JOIN trip_assignments ta_completed 
            ON d.id = ta_completed.driver_id 
            AND ta_completed.status = 'Completed'
         WHERE d.status = 'Active'
         AND d.id NOT IN (
             SELECT driver_id 
             FROM trip_assignments 
             WHERE trip_date = ? 
             AND status IN ('Scheduled', 'In Progress')
         )
         GROUP BY d.id
         ORDER BY 
            d.rating DESC,
            d.total_trips DESC,
            d.driver_name ASC",
        [$date]
    )->results();
    
    // Mark the best driver as recommended (highest rating, most trips)
    if (!empty($drivers)) {
        $drivers[0]->is_recommended = true;
        
        // Also check if driver is already assigned to this vehicle
        foreach ($drivers as $driver) {
            if ($driver->assigned_vehicle_id == $vehicle_id) {
                $driver->is_recommended = true;
                $driver->recommendation_reason = 'Usually drives this vehicle';
                break;
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'drivers' => $drivers,
        'date' => $date
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}