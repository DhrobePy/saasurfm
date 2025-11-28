<?php
/**
 * AJAX Handler for Advance Payment Collection
 * Place this file at: /cr/ajax_handler_payment.php
 */

// Prevent any output before JSON
ob_start();

// Initialize the system
require_once __DIR__ . '/../core/init.php';

// Set JSON header immediately
header('Content-Type: application/json');

// Security: Must be logged in
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized - Please log in']);
    exit;
}

// Get global database instance
global $db;

// Get input data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// If JSON decode failed, try $_POST
if ($data === null) {
    $data = $_POST;
}

// Get action
$action = $data['action'] ?? $_POST['action'] ?? null;

// Log the request (for debugging - remove in production)
error_log("AJAX Payment Handler - Action: " . ($action ?? 'none') . " | Customer ID: " . ($data['customer_id'] ?? 'none'));

try {
    // ============================================================================
    // Get pending orders for advance payment collection
    // ============================================================================
    if ($action === 'get_pending_orders_for_advance') {
        $customer_id = isset($data['customer_id']) ? (int)$data['customer_id'] : 0;
        
        if ($customer_id <= 0) {
            throw new Exception('Invalid customer ID provided');
        }
        
        // Verify customer exists and is active credit customer
        $customer_check = $db->query(
            "SELECT id, name, customer_type, status 
             FROM customers 
             WHERE id = ? AND status = 'active' AND customer_type = 'Credit'",
            [$customer_id]
        )->first();
        
        if (!$customer_check) {
            throw new Exception('Customer not found or not an active credit customer');
        }
        
        // Fetch pending orders that can receive advance payments
        // Statuses: draft, pending_approval, approved, in_production
        // Must have balance_due > 0
        $orders = $db->query(
            "SELECT 
                id, 
                order_number, 
                order_date, 
                order_type,
                subtotal,
                discount_amount,
                tax_amount,
                total_amount, 
                advance_paid, 
                balance_due, 
                status,
                priority,
                required_date,
                special_instructions,
                assigned_branch_id,
                total_weight_kg,
                created_at
             FROM credit_orders
             WHERE customer_id = ? 
             AND status IN ('draft', 'pending_approval', 'approved', 'in_production')
             AND balance_due > 0
             ORDER BY 
                FIELD(status, 'in_production', 'approved', 'pending_approval', 'draft'),
                FIELD(priority, 'urgent', 'high', 'normal', 'low'),
                order_date ASC",
            [$customer_id]
        )->results();
        
        // Log found orders count
        error_log("Found " . count($orders) . " pending orders for customer " . $customer_id);
        
        // Convert objects to arrays and format numbers
        $formatted_orders = array_map(function($order) {
            return [
                'id' => (int)$order->id,
                'order_number' => $order->order_number,
                'order_date' => $order->order_date,
                'order_type' => $order->order_type,
                'subtotal' => (float)$order->subtotal,
                'discount_amount' => (float)$order->discount_amount,
                'tax_amount' => (float)$order->tax_amount,
                'total_amount' => (float)$order->total_amount,
                'advance_paid' => (float)$order->advance_paid,
                'balance_due' => (float)$order->balance_due,
                'status' => $order->status,
                'priority' => $order->priority,
                'required_date' => $order->required_date,
                'special_instructions' => $order->special_instructions ?? '',
                'assigned_branch_id' => $order->assigned_branch_id,
                'total_weight_kg' => (float)($order->total_weight_kg ?? 0)
            ];
        }, $orders);
        
        // Clean output buffer and send response
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'orders' => $formatted_orders,
            'count' => count($formatted_orders),
            'customer' => [
                'id' => (int)$customer_check->id,
                'name' => $customer_check->name
            ]
        ]);
        exit;
    }
    
    // ============================================================================
    // Get outstanding orders (for regular payment collection - delivered orders)
    // ============================================================================
    elseif ($action === 'get_outstanding_orders') {
        $customer_id = isset($data['customer_id']) ? (int)$data['customer_id'] : 0;
        
        if ($customer_id <= 0) {
            throw new Exception('Invalid customer ID');
        }
        
        // Fetch orders that have been delivered/shipped but still have balance due
        $orders = $db->query(
            "SELECT 
                id, 
                order_number, 
                order_date,
                total_amount, 
                advance_paid, 
                balance_due
             FROM credit_orders
             WHERE customer_id = ? 
             AND status IN ('shipped', 'delivered')
             AND balance_due > 0
             ORDER BY order_date ASC",
            [$customer_id]
        )->results();
        
        $formatted_orders = array_map(function($order) {
            return [
                'id' => (int)$order->id,
                'order_number' => $order->order_number,
                'order_date' => $order->order_date,
                'total_amount' => (float)$order->total_amount,
                'advance_paid' => (float)$order->advance_paid,
                'balance_due' => (float)$order->balance_due
            ];
        }, $orders);
        
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'orders' => $formatted_orders,
            'count' => count($formatted_orders)
        ]);
        exit;
    }
    
    // ============================================================================
    // Invalid action
    // ============================================================================
    else {
        throw new Exception('Invalid or missing action parameter. Received: ' . ($action ?? 'none'));
    }
    
} catch (Exception $e) {
    // Log the error
    error_log("AJAX Payment Handler Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    // Clean output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Send error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'action_received' => $action ?? 'none',
        'customer_id_received' => $data['customer_id'] ?? 'none'
    ]);
    exit;
}