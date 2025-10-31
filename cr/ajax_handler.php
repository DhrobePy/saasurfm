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


// ==========================================================
// ===== START: REVISED CSRF TOKEN VALIDATION BLOCK =====
// ==========================================================
$session_token = $_SESSION['csrf_token'] ?? '';
$received_token = '';

// 1. Check the 'X-CSRF-TOKEN' header first (this is the best practice for AJAX)
$token_from_header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;



global $db;
$user_role = $_SESSION['user_role'] ?? '';
$data = []; // Initialize

try {
    // Get data
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // We already read php://input once for the token, so let's use that data
        // If $data_check is not set (e.g., was a GET), then read it now.
        if (!isset($data_check)) {
             $data = json_decode(file_get_contents('php://input'), true);
             if (json_last_error() !== JSON_ERROR_NONE) { throw new Exception('Invalid JSON'); }
        } else {
             $data = $data_check;
        }
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
        
       case 'get_outstanding_orders':
            if (!isset($data['customer_id'])) {
                throw new Exception('Customer ID is required.');
            }
            $customer_id = (int)$data['customer_id'];

            // Fetches delivered orders that still have a balance due
            //
            $orders = $db->query(
                "SELECT id, order_number, order_date, total_amount, balance_due
                 FROM credit_orders
                 WHERE customer_id = ? AND status = 'delivered' AND balance_due > 0.01
                 ORDER BY order_date ASC",
                [$customer_id]
            )->results();
            
            $result = ['success' => true, 'orders' => $orders];
            break;

        // ==========================================================
        // ===== START: CODE FOR CUSTOMER VIEW MODAL =====
        // ==========================================================
        case 'get_transaction_details':
            // Note: Security/Auth is already handled above
            if (!isset($data['ref_id']) || !isset($data['ref_type'])) {
                throw new Exception('Missing reference ID or type.');
            }
            
            $ref_id = (int)$data['ref_id'];
            $ref_type = $data['ref_type'];
            $html = '';
            
            // Start output buffering to capture HTML
            ob_start();

            // --- Handle Credit Orders ---
            if ($ref_type == 'credit_orders') {
                $order = $db->query("SELECT * FROM credit_orders WHERE id = ?", [$ref_id])->first();
                $items = $db->query(
                    "SELECT ci.*, p.base_name, pv.grade, pv.weight_variant
                     FROM credit_order_items ci
                     JOIN product_variants pv ON ci.variant_id = pv.id
                     JOIN products p ON pv.product_id = p.id
                     WHERE ci.order_id = ?",
                    [$ref_id]
                )->results();
                
                if ($order) {
                    $status_color = 'blue';
                    if ($order->status == 'delivered') $status_color = 'green';
                    if ($order->status == 'cancelled' || $order->status == 'rejected') $status_color = 'red';
                    
                    $status_html = '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-'.$status_color.'-100 text-'.$status_color.'-800">' . htmlspecialchars(ucwords(str_replace('_', ' ', $order->status))) . '</span>';
                    
                    ?>
                    <div class="space-y-4">
                        <div class="grid grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-500">Status</label>
                                <?php echo $status_html; ?>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-500">Order Date</label>
                                <p class="text-md font-semibold text-gray-900">
                                    <?php echo date('d-M-Y', strtotime($order->order_date)); ?>
                                </p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-500">Order Total</label>
                                <p class="text-xl font-bold text-gray-900">
                                    <?php echo number_format($order->total_amount, 2); ?> BDT
                                </p>
                            </div>
                        </div>
                        
                        <h4 class="text-lg font-medium text-gray-800 border-t pt-4">Order Items</h4>
                        <table class="min-w-full divide-y divide-gray-200 mt-2">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Unit Price</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Line Total</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($items as $item): 
                                    $product_name = htmlspecialchars($item->base_name . ' (' . $item->weight_variant . 'kg - ' . $item->grade . ')');
                                ?>
                                <tr>
                                    <td class="px-3 py-3 text-sm text-gray-800"><?php echo $product_name; ?></td>
                                    <td class="px-3 py-3 text-sm text-gray-700 text-right"><?php echo $item->quantity; ?></td>
                                    <td class="px-3 py-3 text-sm text-gray-700 text-right"><?php echo number_format($item->unit_price, 2); ?></td>
                                    <td class="px-3 py-3 text-sm text-gray-900 text-right font-medium"><?php echo number_format($item->line_total, 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                } else {
                    echo '<p class="text-red-500 text-center p-4">Error: Order not found.</p>';
                }
            } 
            
            // --- Handle Customer Payments ---
            elseif ($ref_type == 'customer_payments') {
                $payment = $db->query("SELECT * FROM customer_payments WHERE id = ?", [$ref_id])->first();
                if ($payment) {
                    ?>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-500">Payment Date</label>
                            <p class="text-md font-semibold text-gray-900"><?php echo date('d-M-Y', strtotime($payment->payment_date)); ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-500">Payment Amount</label>
                            <p class="text-2xl font-bold text-green-600"><?php echo number_format($payment->payment_amount, 2); ?> BDT</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-500">Payment Method</label>
                            <p class="text-md font-semibold text-gray-900">
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800"><?php echo htmlspecialchars($payment->payment_method); ?></span>
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-500">Reference / Cheque No.</label>
                            <p class="text-md font-semibold text-gray-900"><?php echo htmlspecialchars($payment->reference_number ?? 'N/A'); ?></p>
                        </div>
                        <div class="border-t pt-4">
                            <label class="block text-sm font-medium text-gray-500">Notes</label>
                            <p class="text-md text-gray-800"><?php echo nl2br(htmlspecialchars($payment->notes ?? 'No notes provided.')); ?></p>
                        </div>
                    </div>
                    <?php
                } else {
                    echo '<p class="text-red-500 text-center p-4">Error: Payment not found.</p>';
                }
            } 
            
            // --- Handle Unknown Types ---
            else {
                 echo '<p class="text-red-500 text-center p-4">Error: Unknown reference type provided.</p>';
            }

            // Get the buffered HTML
            $html = ob_get_clean();
            // Set the result to be JSON-encoded
            $result = ['success' => true, 'html' => $html];
            break;
        // ==========================================================
        // ===== END: CODE FOR CUSTOMER VIEW MODAL =====
        // ==========================================================


        default:
            throw new Exception('Invalid sales action');
    }
    
    // Clean buffer (which now contains the HTML for the modal) and send response
    // ** We removed the ob_end_clean() from here in the previous step **
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