<?php
require_once '../core/init.php';

header('Content-Type: application/json');

$allowed_roles = ['Superadmin', 'admin', 'accountspos-demra', 'accountspos-srg', 'dispatchpos-demra', 'dispatchpos-srg'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$user_role = $currentUser['role'] ?? '';
$is_superadmin = in_array($user_role, ['Superadmin', 'admin']);

// Get POST data
$post_data = json_decode(file_get_contents('php://input'), true);
$requested_branch_id = $post_data['branch_id'] ?? null;

try {
    // Get branch_id
    $branch_id = null;
    
    if ($is_superadmin && $requested_branch_id) {
        // Superadmin can run EOD for any branch they select
        $branch_check = $db->query("SELECT id, name FROM branches WHERE id = ?", [$requested_branch_id])->first();
        if (!$branch_check) {
            throw new Exception("Selected branch does not exist.");
        }
        $branch_id = $requested_branch_id;
    } else {
        // Regular users - get from employees table
        $employee_info = $db->query("SELECT branch_id FROM employees WHERE user_id = ?", [$user_id])->first();
        
        if (!$employee_info) {
            throw new Exception("No employee record found for your user account.");
        }
        
        if (!$employee_info->branch_id) {
            throw new Exception("Your employee record has no branch assigned (branch_id is NULL).");
        }
        
        $branch_id = $employee_info->branch_id;
    }
    
    $today_date = date('Y-m-d');
    
    // Check if EOD already run today
    $existing = $db->query("SELECT id FROM eod_summary WHERE branch_id = ? AND eod_date = ?", [$branch_id, $today_date])->first();
    if ($existing) {
        throw new Exception("EOD has already been run for today.");
    }
    
    // Fetch today's orders
    $orders = $db->query("SELECT o.*, u.display_name as user_name 
                          FROM orders o
                          JOIN users u ON o.created_by_user_id = u.id
                          WHERE o.branch_id = ? 
                          AND o.order_type = 'POS' 
                          AND DATE(o.order_date) = ?
                          ORDER BY o.order_date", [$branch_id, $today_date])->results();
    
    if (empty($orders)) {
        throw new Exception("No orders found for today. Cannot run EOD.");
    }
    
    // Fetch order items
    $order_ids = array_map(function($o) { return $o->id; }, $orders);
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    
    $items = $db->query("SELECT oi.*, p.base_name, pv.weight_variant, pv.unit_of_measure
                        FROM order_items oi
                        JOIN product_variants pv ON oi.variant_id = pv.id
                        JOIN products p ON pv.product_id = p.id
                        WHERE oi.order_id IN ($placeholders)", $order_ids)->results();
    
    // Initialize counters
    $total_orders = count($orders);
    $total_items_sold = 0;
    $gross_sales = 0;
    $total_discount = 0;
    $net_sales = 0;
    $payment_methods = [];
    $products = [];
    $hourly_sales = [];
    
    // Group items by order
    $order_items = [];
    foreach ($items as $item) {
        if (!isset($order_items[$item->order_id])) {
            $order_items[$item->order_id] = [];
        }
        $order_items[$item->order_id][] = $item;
    }
    
    // Process orders
    foreach ($orders as $order) {
        // Items discount
        $item_discounts = 0;
        if (isset($order_items[$order->id])) {
            foreach ($order_items[$order->id] as $item) {
                $total_items_sold += $item->quantity;
                $item_discounts += floatval($item->discount_amount ?? 0);
                
                // Track products
                $product_key = $item->base_name;
                if (!isset($products[$product_key])) {
                    $products[$product_key] = ['name' => $item->base_name, 'quantity' => 0, 'revenue' => 0];
                }
                $products[$product_key]['quantity'] += $item->quantity;
                $products[$product_key]['revenue'] += $item->total_amount;
            }
        }
        
        // Order totals
        $order_discount = floatval($order->discount_amount ?? 0);
        $total_order_discount = $order_discount + $item_discounts;
        
        $gross_sales += floatval($order->total_amount) + $total_order_discount;
        $total_discount += $total_order_discount;
        $net_sales += floatval($order->total_amount);
        
        // Payment methods
        $method = $order->payment_method ?? 'Unknown';
        if (!isset($payment_methods[$method])) {
            $payment_methods[$method] = ['count' => 0, 'amount' => 0];
        }
        $payment_methods[$method]['count']++;
        $payment_methods[$method]['amount'] += floatval($order->total_amount);
        
        // Hourly sales
        $hour = date('H:00', strtotime($order->order_date));
        if (!isset($hourly_sales[$hour])) {
            $hourly_sales[$hour] = 0;
        }
        $hourly_sales[$hour]++;
    }
    
    // Find peak hour
    $peak_hour = 'N/A';
    if (!empty($hourly_sales)) {
        arsort($hourly_sales);
        $peak_hour = array_key_first($hourly_sales);
        $peak_hour = date('h:i A', strtotime($peak_hour));
    }
    
    // Top 10 products
    usort($products, function($a, $b) {
        return $b['revenue'] - $a['revenue'];
    });
    $top_products = array_slice($products, 0, 10);
    
    // Cash sales (for petty cash) - Get from petty cash transactions
    $cash_sales_result = $db->query("SELECT 
                                        SUM(CASE WHEN transaction_type = 'cash_in' THEN amount ELSE 0 END) as cash_in,
                                        SUM(CASE WHEN transaction_type = 'cash_out' THEN amount ELSE 0 END) as cash_out
                                    FROM branch_petty_cash_transactions
                                    WHERE branch_id = ?
                                    AND DATE(transaction_date) = ?",
                                    [$branch_id, $today_date])->first();
    
    $cash_sales = $cash_sales_result ? floatval($cash_sales_result->cash_in) : 0;
    $cash_withdrawals = $cash_sales_result ? floatval($cash_sales_result->cash_out) : 0;
    
    // Get opening balance from petty cash account
    $petty_cash_account = $db->query("SELECT current_balance FROM branch_petty_cash_accounts WHERE branch_id = ? AND status = 'active'", [$branch_id])->first();
    $current_petty_cash = $petty_cash_account ? floatval($petty_cash_account->current_balance) : 0;
    
    // Get previous EOD for opening balance
    $prev_eod = $db->query("SELECT actual_cash FROM eod_summary WHERE branch_id = ? AND eod_date < ? ORDER BY eod_date DESC LIMIT 1", [$branch_id, $today_date])->first();
    $opening_cash = $prev_eod ? floatval($prev_eod->actual_cash) : 0;
    
    // Expected closing = Current petty cash balance (this is real-time)
    // This already includes: opening + cash_in - cash_out
    $expected_cash = $current_petty_cash;
    
    // For now, actual cash = expected (will need manual adjustment UI later)
    $actual_cash = $expected_cash;
    
    // Insert EOD summary
    $db->query("INSERT INTO eod_summary (
                    branch_id, eod_date, total_orders, total_items_sold,
                    gross_sales, total_discount, net_sales,
                    payment_methods_json, top_products_json,
                    opening_cash, cash_sales, cash_withdrawals,
                    expected_cash, actual_cash,
                    peak_hour, created_by_user_id, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $branch_id,
                    $today_date,
                    $total_orders,
                    $total_items_sold,
                    $gross_sales,
                    $total_discount,
                    $net_sales,
                    json_encode($payment_methods),
                    json_encode($top_products),
                    $opening_cash,
                    $cash_sales,
                    $cash_withdrawals,
                    $expected_cash,
                    $actual_cash,
                    $peak_hour,
                    $user_id
                ]);
    
    echo json_encode(['success' => true, 'message' => 'EOD completed successfully']);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}