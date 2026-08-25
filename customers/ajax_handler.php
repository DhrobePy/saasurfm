<?php
/**
 * Customer AJAX Handler - FIXED VERSION
 * Handles customer-related AJAX requests (for POS customer creation)
 * Fixed for PDO Database class
 */

require_once '../core/init.php';

// Set JSON header
header('Content-Type: application/json');

// Security check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// CSRF Token validation for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrf_token) || $csrf_token !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

global $db;
$user_id = $_SESSION['user_id'];

try {
    // Get action
    $action = $data['action'] ?? '';
    
    if ($action === 'add_pos_customer') {
        // Add a new POS customer
        $name = trim($data['name'] ?? '');
        $phone_number = trim($data['phone_number'] ?? '');
        
        // Validation
        if (empty($name)) {
            throw new Exception('Customer name is required');
        }
        
        // Phone validation if provided
        if (!empty($phone_number) && !preg_match('/^[\d\s\-\+\(\)]+$/', $phone_number)) {
            throw new Exception('Invalid phone number format');
        }
        
        // Insert customer
        $sql = "INSERT INTO customers (
            customer_type,
            name,
            phone_number,
            credit_limit,
            status
        ) VALUES ('POS', ?, ?, 0.00, 'active')";
        
        $db->query($sql, [
            $name,
            $phone_number ?: ''
        ]);
        
        // Get last insert ID using PDO
        $customer_id = $db->getPdo()->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'id' => (int)$customer_id,
            'message' => 'Customer added successfully'
        ]);
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    error_log("Customer AJAX Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}