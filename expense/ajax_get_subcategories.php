<?php
/**
 * Get Subcategories by Category ID
 * Returns JSON for AJAX dropdown
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../core/init.php';

global $db;

// Get category_id from request
$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

if (!$category_id) {
    echo json_encode([]);
    exit();
}

try {
    // Query using YOUR database pattern
    $sql = "SELECT id, subcategory_name, unit_of_measurement 
            FROM expense_subcategories 
            WHERE category_id = ? 
            AND is_active = 1 
            ORDER BY subcategory_name ASC";
    
    $subcategories = $db->query($sql, [$category_id])->results();
    
    // Format for JSON response
    $result = [];
    foreach ($subcategories as $sub) {
        $result[] = [
            'id' => $sub->id,
            'subcategory_name' => $sub->subcategory_name,
            'unit_of_measurement' => $sub->unit_of_measurement ?? null
        ];
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage()
    ]);
}