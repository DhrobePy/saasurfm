<?php
/**
 * Thermal Receipt Printer - PRODUCTION VERSION
 * Generates printable receipt for POS orders
 * Works with or without branch phone_number column
 */

require_once '../core/init.php';

// Security check
if (!isset($_SESSION['user_id'])) {
    die('Unauthorized');
}

$order_number = $_GET['order_number'] ?? null;
$copy_type = $_GET['copy_type'] ?? 'customer';

if (!$order_number) {
    die('Order number required');
}

global $db;

try {
    // Check if branches table has phone_number column
    $has_branch_phone = false;
    try {
        $columns = $db->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = 'branches' 
             AND COLUMN_NAME = 'phone_number'"
        )->first();
        $has_branch_phone = ($columns !== null);
    } catch (Exception $e) {
        // Column check failed, assume no phone column
    }
    
    // Get order details with conditional branch phone
    if ($has_branch_phone) {
        $order = $db->query(
            "SELECT o.*, b.name as branch_name, b.address as branch_address, b.phone_number as branch_phone,
                    c.name as customer_name, c.phone_number as customer_phone
             FROM orders o
             JOIN branches b ON o.branch_id = b.id
             LEFT JOIN customers c ON o.customer_id = c.id
             WHERE o.order_number = ?",
            [$order_number]
        )->first();
    } else {
        $order = $db->query(
            "SELECT o.*, b.name as branch_name, b.address as branch_address,
                    c.name as customer_name, c.phone_number as customer_phone
             FROM orders o
             JOIN branches b ON o.branch_id = b.id
             LEFT JOIN customers c ON o.customer_id = c.id
             WHERE o.order_number = ?",
            [$order_number]
        )->first();
        // Set branch_phone to null if column doesn't exist
        if ($order) {
            $order->branch_phone = null;
        }
    }
    
    if (!$order) {
        die('Order not found');
    }
    
    // Get order items with product details
    $items = $db->query(
        "SELECT oi.*, pv.sku, pv.weight_variant, pv.grade, pv.unit_of_measure, p.base_name
         FROM order_items oi
         JOIN product_variants pv ON oi.variant_id = pv.id
         JOIN products p ON pv.product_id = p.id
         WHERE oi.order_id = ?
         ORDER BY oi.id",
        [$order->id]
    )->results();
    
    // Update print tracking
    $db->query(
        "UPDATE orders 
         SET print_count = print_count + 1,
             last_printed_at = NOW(),
             " . $copy_type . "_copy_printed = 1
         WHERE id = ?",
        [$order->id]
    );
    
} catch (Exception $e) {
    die('Error loading order: ' . $e->getMessage());
}

// Copy type display names
$copy_names = [
    'office' => 'OFFICE COPY',
    'customer' => 'CUSTOMER COPY',
    'delivery' => 'DELIVERY COPY'
];
$copy_name = $copy_names[$copy_type] ?? 'RECEIPT';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - <?php echo htmlspecialchars($order_number); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.4;
            padding: 10px;
            max-width: 302px; /* 80mm thermal paper */
            margin: 0 auto;
        }
        
        .receipt {
            width: 100%;
        }
        
        .header {
            text-align: center;
            border-bottom: 2px dashed #000;
            padding-bottom: 10px;
            margin-bottom: 10px;
        }
        
        .company-name {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .branch-info {
            font-size: 11px;
            margin-bottom: 3px;
        }
        
        .copy-type {
            font-size: 14px;
            font-weight: bold;
            margin-top: 8px;
            padding: 5px;
            border: 2px solid #000;
            display: inline-block;
        }
        
        .order-info {
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px dashed #000;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
            font-size: 11px;
        }
        
        .items-table {
            width: 100%;
            margin-bottom: 10px;
            border-bottom: 1px dashed #000;
            padding-bottom: 10px;
        }
        
        .items-header {
            font-weight: bold;
            border-bottom: 1px solid #000;
            padding-bottom: 5px;
            margin-bottom: 5px;
        }
        
        .item-row {
            margin-bottom: 8px;
            font-size: 11px;
        }
        
        .item-name {
            font-weight: bold;
            margin-bottom: 2px;
        }
        
        .item-details {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2px;
        }
        
        .item-discount {
            color: #666;
            font-size: 10px;
            margin-left: 10px;
        }
        
        .totals {
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 2px dashed #000;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .total-row.grand-total {
            font-size: 16px;
            font-weight: bold;
            margin-top: 5px;
            padding-top: 5px;
            border-top: 1px solid #000;
        }
        
        .payment-info {
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px dashed #000;
        }
        
        .footer {
            text-align: center;
            font-size: 11px;
            margin-top: 10px;
        }
        
        .footer-message {
            margin-bottom: 5px;
        }
        
        .signature-line {
            margin-top: 30px;
            border-top: 1px solid #000;
            padding-top: 5px;
            text-align: center;
        }
        
        @media print {
            body {
                padding: 0;
            }
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="receipt">
        <!-- Header -->
        <div class="header">
            <div class="company-name">উজ্জল ফ্লাওয়ার মিলস </div>
            <div class="branch-info"><?php echo htmlspecialchars($order->branch_name); ?></div>
            <?php if ($order->branch_address): ?>
            <div class="branch-info"><?php echo htmlspecialchars($order->branch_address); ?></div>
            <?php endif; ?>
            <?php if (!empty($order->branch_phone)): ?>
            <div class="branch-info">Phone: <?php echo htmlspecialchars($order->branch_phone); ?></div>
            <?php endif; ?>
            <div class="copy-type"><?php echo $copy_name; ?></div>
        </div>
        
        <!-- Order Info -->
        <div class="order-info">
            <div class="info-row">
                <span>Order #:</span>
                <span><strong><?php echo htmlspecialchars($order->order_number); ?></strong></span>
            </div>
            <div class="info-row">
                <span>Date:</span>
                <span><?php echo date('d M Y, h:i A', strtotime($order->order_date)); ?></span>
            </div>
            <?php if ($order->customer_name): ?>
            <div class="info-row">
                <span>Customer:</span>
                <span><?php echo htmlspecialchars($order->customer_name); ?></span>
            </div>
            <?php if ($order->customer_phone): ?>
            <div class="info-row">
                <span>Phone:</span>
                <span><?php echo htmlspecialchars($order->customer_phone); ?></span>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div class="info-row">
                <span>Customer:</span>
                <span>Walk-in</span>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Items -->
        <div class="items-table">
            <div class="items-header">ITEMS</div>
            <?php foreach ($items as $item): ?>
            <div class="item-row">
                <div class="item-name"><?php echo htmlspecialchars($item->base_name); ?></div>
                <div style="font-size: 10px; color: #666; margin-bottom: 3px;">
                    SKU: <?php echo htmlspecialchars($item->sku); ?>
                </div>
                <div class="item-details">
                    <span><?php echo $item->quantity; ?> x ৳<?php echo number_format($item->unit_price, 2); ?></span>
                    <span>৳<?php echo number_format($item->subtotal, 2); ?></span>
                </div>
                <?php if ($item->item_discount_type !== 'none' && $item->item_discount_amount > 0): ?>
                <div class="item-discount">
                    Discount (<?php 
                        echo $item->item_discount_type === 'percentage' 
                            ? $item->item_discount_value . '%' 
                            : '৳' . number_format($item->item_discount_value, 2);
                    ?>): -৳<?php echo number_format($item->item_discount_amount, 2); ?>
                </div>
                <div class="item-details">
                    <span></span>
                    <span><strong>৳<?php echo number_format($item->total_amount, 2); ?></strong></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Totals -->
        <div class="totals">
            <div class="total-row">
                <span>Subtotal:</span>
                <span>৳<?php echo number_format($order->subtotal, 2); ?></span>
            </div>
            <?php if ($order->discount_amount > 0): ?>
            <div class="total-row" style="color: #d00;">
                <span>
                    Discount 
                    <?php if ($order->cart_discount_type !== 'none'): ?>
                        (<?php 
                            echo $order->cart_discount_type === 'percentage' 
                                ? $order->cart_discount_value . '%' 
                                : '৳' . number_format($order->cart_discount_value, 2);
                        ?>)
                    <?php endif; ?>:
                </span>
                <span>-৳<?php echo number_format($order->discount_amount, 2); ?></span>
            </div>
            <?php endif; ?>
            <div class="total-row grand-total">
                <span>TOTAL:</span>
                <span>৳<?php echo number_format($order->total_amount, 2); ?></span>
            </div>
        </div>
        
        <!-- Payment Info -->
        <div class="payment-info">
            <div class="info-row">
                <span>Payment Method:</span>
                <span><strong><?php echo htmlspecialchars($order->payment_method); ?></strong></span>
            </div>
            <?php if ($order->payment_reference): ?>
            <div class="info-row">
                <span>Reference:</span>
                <span><?php echo htmlspecialchars($order->payment_reference); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($order->bank_name): ?>
            <div class="info-row">
                <span>Bank:</span>
                <span><?php echo htmlspecialchars($order->bank_name); ?></span>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <span>Status:</span>
                <span><?php echo htmlspecialchars($order->payment_status); ?></span>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <div class="footer-message">Thank you for your business!</div>
            <?php if (!empty($order->branch_phone)): ?>
            <div class="footer-message" style="font-size: 10px;">
                For queries: <?php echo htmlspecialchars($order->branch_phone); ?>
            </div>
            <?php else: ?>
            <div class="footer-message" style="font-size: 10px;">
                Visit us at: <?php echo htmlspecialchars($order->branch_name); ?>
            </div>
            <?php endif; ?>
            
            <?php if ($copy_type === 'delivery'): ?>
            <div class="signature-line">
                <div>Received By</div>
                <div style="margin-top: 30px;">
                    ___________________________<br>
                    Signature & Date
                </div>
            </div>
            <?php endif; ?>
            
            <div style="margin-top: 15px; font-size: 10px; color: #999;">
                Printed: <?php echo date('d M Y h:i A'); ?>
            </div>
        </div>
    </div>
    
    <!-- Print Button (hidden on print) -->
    <div class="no-print" style="text-align: center; margin-top: 20px;">
        <button onclick="window.print()" style="padding: 10px 20px; font-size: 14px; cursor: pointer; background: #007bff; color: white; border: none; border-radius: 5px;">
            🖨️ Print Receipt
        </button>
        <button onclick="window.close()" style="padding: 10px 20px; font-size: 14px; cursor: pointer; margin-left: 10px;">
            Close
        </button>
    </div>
    
    <script>
        // Auto print when page loads
        window.onload = function() {
            // Small delay to ensure content is rendered
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>