<?php
// CRITICAL: Prevent any output before JSON
ob_start();

require_once '../core/init.php';

// Set JSON header
header('Content-Type: application/json');

// Security check: User must be logged in
if (!isset($_SESSION['user_id'])) {
    ob_end_clean(); http_response_code(401); echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
}
$user_id = $_SESSION['user_id'];

// CSRF Token validation for POST/GET
$csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
if (empty($csrf_token) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf_token)) {
    // Allow token in GET for search, but check POST body for submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data_check = json_decode(file_get_contents('php://input'), true);
        $csrf_token = $data_check['csrf_token'] ?? $csrf_token; // Check JSON body
    }
     if (empty($csrf_token) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf_token)) {
        ob_end_clean(); http_response_code(403); echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']); exit;
     }
}


global $db;
$user_role = $_SESSION['user_role'] ?? '';
$data = []; // Initialize

try {
    // Get data
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) { throw new Exception('Invalid JSON'); }
    } else {
        $data = $_GET; // For GET requests
    }

    $action = $data['action'] ?? null;

    switch ($action) {
        
        case 'search_credit_customers':
            $term = trim($data['term'] ?? '');
            if (strlen($term) < 2) {
                echo json_encode(['success' => true, 'customers' => []]); exit;
            }
            $searchTerm = "%{$term}%";
            // Find CREDIT customers only
            $customers = $db->query(
                "SELECT id, name, business_name, phone_number, credit_limit, current_balance
                 FROM customers
                 WHERE customer_type = 'Credit' AND status = 'active'
                   AND (name LIKE ? OR business_name LIKE ? OR phone_number LIKE ?)
                 ORDER BY name ASC LIMIT 10",
                [$searchTerm, $searchTerm, $searchTerm]
            )->results();
            $result = ['success' => true, 'customers' => $customers];
            break;

        case 'search_products_for_branch':
            $term = trim($data['term'] ?? '');
            $branch_id = (int)($data['branch_id'] ?? 0);
            if (strlen($term) < 2 || $branch_id === 0) {
                echo json_encode(['success' => true, 'products' => []]); exit;
            }
            $searchTerm = "%{$term}%";

            // Find products that have a price AND stock at the selected branch
            $products = $db->query(
                "SELECT
                    pv.id as variant_id, pv.sku, pv.weight_variant, pv.grade, pv.unit_of_measure, p.base_name,
                    pp.unit_price,
                    COALESCE(inv.quantity, 0) as stock_quantity
                 FROM product_variants pv
                 JOIN products p ON pv.product_id = p.id
                 -- Join price for this branch
                 JOIN product_prices pp ON pp.variant_id = pv.id AND pp.branch_id = ? AND pp.is_active = 1
                 -- Join inventory for this branch
                 LEFT JOIN inventory inv ON inv.variant_id = pv.id AND inv.branch_id = ?
                 WHERE p.status = 'active' AND pv.status = 'active'
                   AND (p.base_name LIKE ? OR pv.sku LIKE ?)
                 ORDER BY p.base_name ASC LIMIT 10",
                [$branch_id, $branch_id, $searchTerm, $searchTerm]
            )->results();
            
            $result = ['success' => true, 'products' => $products];
            break;

        case 'place_credit_order':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
            
            $pdo = $db->getPdo();
            $pdo->beginTransaction();
            
            try {
                $customer_id = (int)$data['customer_id'];
                $fulfillment_branch_id = (int)$data['fulfillment_branch_id'];
                $payment_type = $data['payment_type'] === 'Advance Payment' ? 'Advance Paid' : 'Unpaid';
                $cart = $data['cart'];
                $subtotal = (float)$data['subtotal'];
                $discount_amount = (float)$data['discount_amount'];
                $total_amount = (float)$data['total_amount'];
                
                // Server-side credit check
                $customer = $db->query("SELECT credit_limit, current_balance FROM customers WHERE id = ?", [$customer_id])->first();
                if (!$customer) throw new Exception('Customer not found.');
                
                $available_credit = (float)$customer->credit_limit - (float)$customer->current_balance;
                
                // Set order status based on credit check
                $order_status = 'Pending Approval'; // Default for Accounts
                if ($payment_type === 'Unpaid' && $total_amount > $available_credit) {
                    throw new Exception("Order total (৳{$total_amount}) exceeds available credit (৳{$available_credit}).");
                }
                // Escalate if using over 80%
                if ($payment_type === 'Unpaid' && $available_credit > 0 && ($total_amount / $available_credit) > 0.8) {
                    $order_status = 'Pending Superadmin Approval';
                }
                // Escalate if 0 credit and placing credit order
                if ($payment_type === 'Unpaid' && $available_credit <= 0 && $total_amount > 0) {
                     $order_status = 'Pending Superadmin Approval';
                }


                // Generate Order Number
                $date_prefix = date('Ymd');
                $branch_code = $db->query("SELECT code FROM branches WHERE id = ?", [$fulfillment_branch_id])->first()->code ?? 'BRH';
                $last_order = $db->query("SELECT order_number FROM orders WHERE order_number LIKE ? ORDER BY id DESC LIMIT 1", ["ORD-{$branch_code}-{$date_prefix}-%"])->first();
                $sequence = $last_order ? (int)substr($last_order->order_number, -4) + 1 : 1;
                $order_number = sprintf("ORD-%s-%s-%04d", $branch_code, $date_prefix, $sequence);
                
                // Insert into CORRECT 'orders' table
                $order_id = $db->insert('orders', [
                    'order_number' => $order_number,
                    'branch_id' => $fulfillment_branch_id, // Use fulfillment branch
                    'customer_id' => $customer_id,
                    'order_date' => date('Y-m-d H:i:s'),
                    'order_type' => 'Credit', // Use 'Credit' type for these orders
                    'subtotal' => $subtotal,
                    'discount_amount' => $discount_amount,
                    'tax_amount' => 0, // Placeholder
                    'total_amount' => $total_amount,
                    'payment_method' => $payment_type === 'Advance Paid' ? 'Cash' : 'Credit', // Simplify
                    'payment_status' => $payment_type, // 'Unpaid' or 'Advance Paid'
                    'order_status' => $order_status, // 'Pending Approval' etc.
                    'fulfillment_branch_id' => $fulfillment_branch_id,
                    'created_by_user_id' => $user_id
                ]);
                if (!$order_id) throw new Exception('Failed to create order record.');

                // Insert into 'order_items'
                foreach ($cart as $item) {
                     // Server-side stock check (optional but recommended)
                    $stock = $db->query("SELECT quantity FROM inventory WHERE variant_id = ? AND branch_id = ? FOR UPDATE", [$item['variant_id'], $fulfillment_branch_id])->first();
                    if (!$stock || $stock->quantity < $item['quantity']) {
                        throw new Exception("Insufficient stock for item {$item['sku']}. Available: " . ($stock->quantity ?? 0));
                    }
                    
                    $db->insert('order_items', [
                        'order_id' => $order_id,
                        'variant_id' => (int)$item['variant_id'],
                        'quantity' => (int)$item['quantity'],
                        'unit_price' => (float)$item['unit_price'],
                        'subtotal' => (float)$item['quantity'] * (float)$item['unit_price'],
                        'total_amount' => (float)$item['quantity'] * (float)$item['unit_price']
                        // Add discount fields here later
                    ]);
                    
                    // NOTE: Inventory is NOT reduced here. It's reduced by DISPATCH upon 'Shipped' status.
                }
                
                // If advance payment, record it
                if ($payment_type === 'Advance Paid' && $total_amount > 0) {
                     // Update customer balance (credit)
                      $db->query("UPDATE customers SET current_balance = current_balance - ? WHERE id = ?", [$total_amount, $customer_id]);
                     // TODO: Create a journal entry for this advance payment (Debit Cash, Credit Customer/Unearned Revenue)
                }

                $pdo->commit();
                $result = ['success' => true, 'order_number' => $order_number];

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e; // Re-throw to be caught by main handler
            }
            break;

        default:
            throw new Exception('Invalid sales action');
    }
    
    // Clean buffer and send response
    ob_end_clean();
    echo json_encode($result);
    exit;

} catch (Exception $e) {
    if (ob_get_level()) ob_end_clean();
    error_log("Sales AJAX Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
?>

