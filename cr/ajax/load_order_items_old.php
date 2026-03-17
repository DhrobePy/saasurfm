<?php
/**
 * Load Order Items - AJAX Endpoint
 * 
 * Fetches detailed order items with product information
 * 
 * @endpoint cr/ajax/load_order_items.php
 * @method GET
 * @param int order_id - The order ID to fetch items for
 * @returns JSON array of order items
 */

require_once '../../core/init.php';

// Set JSON header
header('Content-Type: application/json');

// Check authentication
$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-rampura', 'accounts-srg', 
                  'accounts-demra', 'production manager-srg', 'production manager-demra',
                  'sales-srg', 'sales-demra', 'sales-other', 'dispatch-srg', 'dispatch-demra'];

if (!isLoggedIn() || !in_array(getCurrentUser()['role'], $allowed_roles)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

// Validate order_id
if (!isset($_GET['order_id']) || !is_numeric($_GET['order_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid order ID']);
    exit;
}

$order_id = (int)$_GET['order_id'];

global $db;

try {
    // Fetch order items with product details
    $items = $db->query(
        "SELECT 
            coi.id,
            coi.order_id,
            coi.product_id,
            coi.variant_id,
            coi.quantity,
            coi.unit_price,
            coi.discount_amount,
            coi.tax_amount,
            coi.line_total,
            coi.notes,
            p.name as product_name,
            p.sku as product_sku,
            p.unit as product_unit,
            pv.variant_name,
            pv.sku as variant_sku,
            pv.barcode,
            COALESCE(pv.variant_name, p.name) as display_name
        FROM credit_order_items coi
        LEFT JOIN products p ON coi.product_id = p.id
        LEFT JOIN product_variants pv ON coi.variant_id = pv.id
        WHERE coi.order_id = ?
        ORDER BY coi.id ASC",
        [$order_id]
    )->results();
    
    if (empty($items)) {
        echo json_encode([
            'success' => true,
            'items' => [],
            'message' => 'No items found for this order'
        ]);
        exit;
    }
    
    // Calculate totals
    $subtotal = 0;
    $total_discount = 0;
    $total_tax = 0;
    $grand_total = 0;
    
    foreach ($items as $item) {
        $subtotal += ($item->quantity * $item->unit_price);
        $total_discount += $item->discount_amount;
        $total_tax += $item->tax_amount;
        $grand_total += $item->line_total;
    }
    
    // Return formatted response
    echo json_encode([
        'success' => true,
        'items' => $items,
        'summary' => [
            'subtotal' => $subtotal,
            'total_discount' => $total_discount,
            'total_tax' => $total_tax,
            'grand_total' => $grand_total,
            'item_count' => count($items)
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load order items',
        'message' => $e->getMessage()
    ]);
}