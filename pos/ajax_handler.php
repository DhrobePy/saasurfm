<?php
// CRITICAL: Prevent any output before JSON
ob_start();

/**
 * POS AJAX Handler - COMPLETE WITH ACCOUNTING INTEGRATION
 * Handles all POS-related AJAX requests with full GL integration
 */

require_once '../core/init.php';

// Clear any existing output buffers
while (ob_get_level() > 1) {
    ob_end_clean();
}

// Set JSON header
header('Content-Type: application/json');

// Security check
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// CSRF Token validation for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrf_token) || $csrf_token !== ($_SESSION['csrf_token'] ?? '')) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

global $db;
$user_id = $_SESSION['user_id'];

/**
 * Helper: Find account ID by name pattern and type with fallbacks
 */
function find_account_id($db, $name_pattern, $account_types, $branch_code = null) {
    // Try branch-specific account first
    if ($branch_code) {
        $account = $db->query(
            "SELECT id FROM chart_of_accounts 
             WHERE name LIKE ? 
             AND account_type IN (" . implode(',', array_fill(0, count($account_types), '?')) . ")
             AND is_active = 1
             LIMIT 1",
            array_merge(["%{$name_pattern}%{$branch_code}%"], $account_types)
        )->first();
        
        if ($account) {
            return $account->id;
        }
        
        // Log missing branch-specific account
        error_log("POS Accounting: Branch-specific account not found: {$name_pattern} for {$branch_code}");
    }
    
    // Fallback to general account
    $account = $db->query(
        "SELECT id FROM chart_of_accounts 
         WHERE name LIKE ? 
         AND account_type IN (" . implode(',', array_fill(0, count($account_types), '?')) . ")
         AND is_active = 1
         LIMIT 1",
        array_merge(["%{$name_pattern}%"], $account_types)
    )->first();
    
    if ($account) {
        error_log("POS Accounting: Using fallback account for: {$name_pattern}");
        return $account->id;
    }
    
    error_log("POS Accounting ERROR: No account found for pattern: {$name_pattern}, types: " . implode(',', $account_types));
    return null;
}

/**
 * Create journal entry for POS sale with full accounting logic
 */
function create_pos_sale_journal_entry($db, $order_id, $order_number, $branch_code, $payment_method, $subtotal, $discount_amount, $total, $user_id) {
    
    // Find required accounts
    $revenue_account_id = find_account_id($db, 'Sales Revenue', ['Revenue'], $branch_code);
    $discount_account_id = find_account_id($db, 'Sales Discount', ['Expense'], null);
    
    // Determine debit account based on payment method
    $debit_account_id = null;
    $debit_account_name = '';
    
    switch ($payment_method) {
        case 'Cash':
            $debit_account_id = find_account_id($db, 'POS Cash', ['Petty Cash', 'Cash'], $branch_code);
            $debit_account_name = 'POS Cash';
            break;
            
        case 'Credit':
            $debit_account_id = find_account_id($db, 'Accounts Receivable', ['Accounts Receivable'], null);
            $debit_account_name = 'Accounts Receivable';
            break;
            
        case 'Bank Deposit':
        case 'Bank Transfer':
        case 'Card':
        case 'Mobile Banking':
            // For electronic payments, use Undeposited Funds
            $debit_account_id = find_account_id($db, 'Undeposited', ['Other Current Asset', 'Cash'], null);
            if (!$debit_account_id) {
                // Fallback to POS Cash if Undeposited Funds doesn't exist
                $debit_account_id = find_account_id($db, 'POS Cash', ['Petty Cash', 'Cash'], $branch_code);
                $debit_account_name = 'POS Cash';
            } else {
                $debit_account_name = 'Undeposited Funds';
            }
            break;
            
        default:
            error_log("POS Accounting WARNING: Unknown payment method: {$payment_method}, defaulting to POS Cash");
            $debit_account_id = find_account_id($db, 'POS Cash', ['Petty Cash', 'Cash'], $branch_code);
            $debit_account_name = 'POS Cash';
    }
    
    // Validate all required accounts exist
    if (!$revenue_account_id) {
        error_log("POS Accounting ERROR: Revenue account not found for branch {$branch_code}");
        return false;
    }
    
    if (!$debit_account_id) {
        error_log("POS Accounting ERROR: {$debit_account_name} account not found");
        return false;
    }
    
    // Create journal entry
    $journal_sql = "INSERT INTO journal_entries (
        transaction_date,
        description,
        related_document_id,
        related_document_type,
        created_by_user_id
    ) VALUES (CURDATE(), ?, ?, 'Order', ?)";
    
    $db->query($journal_sql, [
        "POS Sale - Order #{$order_number} - {$payment_method}",
        $order_id,
        $user_id
    ]);
    
    $journal_id = $db->getPdo()->lastInsertId();
    
    if (!$journal_id) {
        error_log("POS Accounting ERROR: Failed to create journal entry");
        return false;
    }
    
    // Transaction lines
    $total_debits = 0;
    $total_credits = 0;
    
    // DEBIT: Asset account (Cash/AR/Undeposited Funds)
    $db->query(
        "INSERT INTO transaction_lines (
            journal_entry_id, account_id, debit_amount, credit_amount, description
        ) VALUES (?, ?, ?, 0, ?)",
        [
            $journal_id,
            $debit_account_id,
            $total,
            "{$debit_account_name} - Order #{$order_number}"
        ]
    );
    $total_debits += $total;
    
    // DEBIT: Sales Discounts (if any)
    if ($discount_amount > 0 && $discount_account_id) {
        $db->query(
            "INSERT INTO transaction_lines (
                journal_entry_id, account_id, debit_amount, credit_amount, description
            ) VALUES (?, ?, ?, 0, ?)",
            [
                $journal_id,
                $discount_account_id,
                $discount_amount,
                "Discount given - Order #{$order_number}"
            ]
        );
        $total_debits += $discount_amount;
    }
    
    // CREDIT: Sales Revenue
    $db->query(
        "INSERT INTO transaction_lines (
            journal_entry_id, account_id, debit_amount, credit_amount, description
        ) VALUES (?, ?, 0, ?, ?)",
        [
            $journal_id,
            $revenue_account_id,
            $subtotal,
            "Sales Revenue - Order #{$order_number}"
        ]
    );
    $total_credits += $subtotal;
    
    // Verify balanced entry
    if (abs($total_debits - $total_credits) > 0.01) {
        error_log("POS Accounting ERROR: Unbalanced entry! Debits: {$total_debits}, Credits: {$total_credits}");
        return false;
    }
    
    // Link journal entry to order
    $db->query(
        "UPDATE orders SET journal_entry_id = ? WHERE id = ?",
        [$journal_id, $order_id]
    );
    
    error_log("POS Accounting SUCCESS: Journal #{$journal_id} created for Order #{$order_number}. Debits: ৳{$total_debits}, Credits: ৳{$total_credits}");
    
    return $journal_id;
}

try {
    // Get action from POST or GET
    $action = $_POST['action'] ?? $_GET['action'] ?? ($data['action'] ?? '');
    
    switch ($action) {
        
        case 'get_products':
            // Get products for a specific branch
            $branch_id = $_GET['branch_id'] ?? null;
            
            if (!$branch_id) {
                throw new Exception('Branch ID is required');
            }
            
            $queryParams = ['branch_id_inv' => $branch_id];
            $inventoryJoinClause = "inv.variant_id = pv.id AND inv.branch_id = :branch_id_inv";
            
            $sql = "SELECT
                    pv.id as variant_id,
                    pv.sku,
                    pv.weight_variant,
                    pv.grade,
                    pv.unit_of_measure,
                    p.base_name,
                    (SELECT pp.unit_price
                     FROM product_prices pp
                     WHERE pp.variant_id = pv.id
                       AND pp.is_active = 1
                     ORDER BY pp.effective_date DESC, pp.created_at DESC
                     LIMIT 1) as unit_price,
                    COALESCE(inv.quantity, 0) as stock_quantity
                FROM
                    product_variants pv
                JOIN
                    products p ON pv.product_id = p.id
                LEFT JOIN
                    inventory inv ON {$inventoryJoinClause}
                WHERE
                    p.status = 'active'
                    AND pv.status = 'active'
                ORDER BY
                    p.base_name, pv.sku";
            
            $products = $db->query($sql, $queryParams)->results();
            
            // Filter out products with no price
            $valid_products = [];
            foreach ($products as $product) {
                if ($product->unit_price !== null && $product->unit_price > 0) {
                    $valid_products[] = [
                        'variant_id' => (int)$product->variant_id,
                        'sku' => $product->sku,
                        'base_name' => $product->base_name,
                        'weight_variant' => $product->weight_variant,
                        'grade' => $product->grade,
                        'unit_of_measure' => $product->unit_of_measure,
                        'unit_price' => (float)$product->unit_price,
                        'stock_quantity' => (int)$product->stock_quantity
                    ];
                }
            }
            
            // Clean output buffer
            $buffer = ob_get_clean();
            if (!empty(trim($buffer))) {
                error_log("Unexpected output in get_products: " . $buffer);
            }
            
            echo json_encode(['success' => true, 'products' => $valid_products]);
            exit;
            
        case 'place_order':
            // Process a new order with full accounting integration
            $branch_id = $data['branch_id'] ?? null;
            $customer_id = $data['customer_id'] ?? null;
            $cart = $data['cart'] ?? [];
            $subtotal = $data['subtotal'] ?? 0;
            $cart_discount_type = $data['cart_discount_type'] ?? 'none';
            $cart_discount_value = $data['cart_discount_value'] ?? 0;
            $total = $data['total'] ?? 0;
            $payment_method = $data['payment_method'] ?? 'Cash';
            $payment_reference = $data['payment_reference'] ?? null;
            $bank_name = $data['bank_name'] ?? null;
            
            // Validation
            if (!$branch_id) {
                throw new Exception('Branch ID is required');
            }
            
            if (empty($cart)) {
                throw new Exception('Cart is empty');
            }
            
            if ($total <= 0) {
                throw new Exception('Invalid order total');
            }
            
            // Calculate total discount amount
            $total_discount = $subtotal - $total;
            
            // Start transaction
            $db->query("START TRANSACTION");
            
            try {
                // Get branch code for accounting
                $branch_info = $db->query(
                    "SELECT code, name FROM branches WHERE id = ?",
                    [$branch_id]
                )->first();
                
                if (!$branch_info) {
                    throw new Exception('Branch not found');
                }
                
                $branch_code = $branch_info->code ?? 'UNKNOWN';
                
                // Generate order number
                $date_prefix = date('Ymd');
                
                $last_order = $db->query(
                    "SELECT order_number FROM orders 
                     WHERE order_number LIKE ? 
                     ORDER BY id DESC LIMIT 1",
                    ["ORD-{$date_prefix}-%"]
                )->first();
                
                if ($last_order) {
                    preg_match('/ORD-\d{8}-(\d{4})/', $last_order->order_number, $matches);
                    $sequence = isset($matches[1]) ? intval($matches[1]) + 1 : 1;
                } else {
                    $sequence = 1;
                }
                
                $order_number = sprintf("ORD-%s-%04d", $date_prefix, $sequence);
                
                // Insert order with discount and payment details
                $order_sql = "INSERT INTO orders (
                    order_number,
                    branch_id,
                    customer_id,
                    order_date,
                    order_type,
                    subtotal,
                    tax_amount,
                    discount_amount,
                    cart_discount_type,
                    cart_discount_value,
                    total_amount,
                    payment_method,
                    payment_reference,
                    bank_name,
                    payment_status,
                    order_status,
                    created_by_user_id
                ) VALUES (?, ?, ?, NOW(), 'POS', ?, 0, ?, ?, ?, ?, ?, ?, ?, 'Paid', 'Completed', ?)";
                
                $db->query($order_sql, [
                    $order_number,
                    $branch_id,
                    $customer_id ?: null,
                    $subtotal,
                    $total_discount,
                    $cart_discount_type,
                    $cart_discount_value,
                    $total,
                    $payment_method,
                    $payment_reference,
                    $bank_name,
                    $user_id
                ]);
                
                $order_id = $db->getPdo()->lastInsertId();
                
                if (!$order_id) {
                    throw new Exception('Failed to create order record');
                }
                
                // Insert order items with discounts and update inventory
                foreach ($cart as $item) {
                    $variant_id = $item['variant_id'];
                    $quantity = $item['quantity'];
                    $unit_price = $item['unit_price'];
                    $item_discount_type = $item['item_discount_type'] ?? 'none';
                    $item_discount_value = floatval($item['item_discount_value'] ?? 0);
                    
                    // Calculate item discount
                    $item_subtotal = $quantity * $unit_price;
                    $item_discount_amount = 0;
                    
                    if ($item_discount_type === 'percentage') {
                        $item_discount_amount = ($item_subtotal * $item_discount_value) / 100;
                    } elseif ($item_discount_type === 'fixed') {
                        $item_discount_amount = $item_discount_value;
                    }
                    
                    $item_total = $item_subtotal - $item_discount_amount;
                    
                    // Check stock
                    $stock_check = $db->query(
                        "SELECT quantity FROM inventory 
                         WHERE variant_id = ? AND branch_id = ?",
                        [$variant_id, $branch_id]
                    )->first();
                    
                    if (!$stock_check) {
                        throw new Exception("Product variant {$variant_id} not found in inventory");
                    }
                    
                    if ($stock_check->quantity < $quantity) {
                        throw new Exception("Insufficient stock for variant {$variant_id}. Available: {$stock_check->quantity}, Required: {$quantity}");
                    }
                    
                    // Insert order item
                    $item_sql = "INSERT INTO order_items (
                        order_id,
                        variant_id,
                        quantity,
                        unit_price,
                        item_discount_type,
                        item_discount_value,
                        item_discount_amount,
                        subtotal,
                        discount_amount,
                        tax_amount,
                        total_amount
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)";
                    
                    $db->query($item_sql, [
                        $order_id,
                        $variant_id,
                        $quantity,
                        $unit_price,
                        $item_discount_type,
                        $item_discount_value,
                        $item_discount_amount,
                        $item_subtotal,
                        $item_discount_amount,
                        $item_total
                    ]);
                    
                    // Update inventory
                    $db->query(
                        "UPDATE inventory 
                        SET quantity = quantity - ?,
                            updated_at = NOW()
                        WHERE variant_id = ? AND branch_id = ?",
                        [$quantity, $variant_id, $branch_id]
                    );
                }
                
                // Update customer balance for Credit sales
                if ($payment_method === 'Credit' && $customer_id) {
                    $db->query(
                        "UPDATE customers 
                         SET current_balance = current_balance + ?
                         WHERE id = ?",
                        [$total, $customer_id]
                    );
                    error_log("POS: Updated customer {$customer_id} balance by ৳{$total}");
                }
                
                // Create journal entry for accounting integration
                $journal_id = create_pos_sale_journal_entry(
                    $db,
                    $order_id,
                    $order_number,
                    $branch_code,
                    $payment_method,
                    $subtotal,
                    $total_discount,
                    $total,
                    $user_id
                );
                
                if (!$journal_id) {
                    error_log("POS WARNING: Order {$order_number} created but journal entry failed. Manual accounting entry required.");
                }
                
                // Commit transaction
                $db->query("COMMIT");
                
                // Log success
                error_log("POS Order {$order_number} completed successfully. Total: ৳{$total}, Discount: ৳{$total_discount}, Payment: {$payment_method}, Journal: " . ($journal_id ? "JE-{$journal_id}" : "FAILED"));
                
                // Clean output buffer
                $buffer = ob_get_clean();
                if (!empty(trim($buffer))) {
                    error_log("Unexpected output in place_order: " . $buffer);
                }
                
                // Send response
                echo json_encode([
                    'success' => true,
                    'order_id' => (int)$order_id,
                    'order_number' => $order_number,
                    'journal_entry_id' => $journal_id,
                    'message' => 'Order placed successfully'
                ]);
                exit;
                
            } catch (Exception $e) {
                $db->query("ROLLBACK");
                throw $e;
            }
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    error_log("POS AJAX Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}