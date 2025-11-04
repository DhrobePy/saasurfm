<?php
require_once '../core/init.php';

// --- SECURITY & CONTEXT ---
$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'dispatch-srg', 'dispatch-demra', 'dispatchpos-demra', 'dispatchpos-srg'];
restrict_access($allowed_roles);

global $db;

$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$order_id) {
    die('Invalid order ID. Please provide an order ID in the URL (e.g., ?id=1)');
}

// --- VALIDATION ---
try {
    // First check if order exists at all
    $check_order = $db->query("SELECT id, order_number, status FROM credit_orders WHERE id = ?", [$order_id])->first();

    if (!$check_order) {
        die("Error: Order ID #{$order_id} does not exist in the database.");
    }

    // Check if order is in a printable status
    $printable_statuses = ['shipped', 'delivered'];
    if (!in_array($check_order->status, $printable_statuses)) {
        die("Error: Order #{$check_order->order_number} has status '{$check_order->status}'. Invoice can only be printed for shipped or delivered orders.<br><br><a href='javascript:history.back()'>Go Back</a>");
    }

    // --- GET FULL ORDER DETAILS (RECTIFIED QUERY) ---
    $order = $db->query(
        "SELECT co.*, 
                c.name as customer_name,
                c.phone_number as customer_phone,
                c.email as customer_email,
                c.business_address as customer_address, -- RECTIFIED: Was c.address
                b.name as branch_name,
                b.address as branch_address,
                b.phone_number as branch_phone, -- RECTIFIED: Was b.phone
                cos.truck_number,
                cos.driver_name,
                cos.driver_contact,
                cos.shipped_date,
                cos.delivered_date,
                u.display_name as created_by_name
         FROM credit_orders co
         JOIN customers c ON co.customer_id = c.id
         LEFT JOIN branches b ON co.assigned_branch_id = b.id
         LEFT JOIN credit_order_shipping cos ON co.id = cos.order_id
         LEFT JOIN users u ON co.created_by_user_id = u.id
         WHERE co.id = ?",
        [$order_id]
    )->first();

    if (!$order) {
        // This will now only trigger if the join fails unexpectedly
        die("Error: Could not load complete order details for Order ID #{$order_id}. Database query failed.");
    }

    // Get order items
    $items = $db->query(
        "SELECT coi.*, 
                p.base_name as product_name,
                pv.grade,
                pv.weight_variant,
                pv.unit_of_measure,
                pv.sku as variant_sku
         FROM credit_order_items coi
         JOIN products p ON coi.product_id = p.id
         LEFT JOIN product_variants pv ON coi.variant_id = pv.id
         WHERE coi.order_id = ?
         ORDER BY coi.id ASC",
        [$order_id]
    )->results();

    if (empty($items)) {
        die("Error: No items found for this order.");
    }

} catch (Exception $e) {
    // Catch the SQL error and display it
     die("A fatal error occurred: " . $e->getMessage());
}

// Get company info (customize this as needed)
$company = [
    'name' => 'উজ্জল ফ্লাওয়ার মিলস ',
    'tagline' => '',
    'address' => '১৭, নুরাইবাগ ডেমরা ঢাকা ',
    'phone' => '+880-XXX-XXXXXX',
    'email' => 'info@ujjalfm.com',
    'website' => 'www.ujjalfm.com'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - <?php echo htmlspecialchars($order->order_number); ?></title>
    <style>
        /* ... (Your existing CSS styles are all correct) ... */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Arial', sans-serif;
            font-size: 12px;
            line-height: 1.6;
            color: #333;
            padding: 20px;
            background: #f5f5f5;
        }
        
        .invoice-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        
        /* Header */
        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px solid #2563eb;
        }
        
        .company-info h1 {
            font-size: 28px;
            color: #2563eb;
            margin-bottom: 5px;
        }
        
        .company-info p {
            font-size: 11px;
            color: #666;
            margin: 2px 0;
        }
        
        .invoice-title {
            text-align: right;
        }
        
        .invoice-title h2 {
            font-size: 32px;
            color: #2563eb;
            margin-bottom: 5px;
        }
        
        .invoice-title .status {
            display: inline-block;
            padding: 5px 15px;
            color: white;
            font-weight: bold;
            border-radius: 4px;
            font-size: 11px;
            text-transform: uppercase;
        }
        
        .invoice-title .status.delivered {
            background: #10b981;
        }
        
        .invoice-title .status.shipped {
            background: #3b82f6;
        }
        
        /* Info Section */
        .info-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }
        
        .info-block {
            width: 48%;
        }
        
        .info-block h3 {
            font-size: 13px;
            color: #2563eb;
            margin-bottom: 10px;
            text-transform: uppercase;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 5px;
        }
        
        .info-block p {
            margin: 5px 0;
            font-size: 12px;
        }
        
        .info-block strong {
            color: #111;
            display: inline-block;
            min-width: 110px;
        }
        
        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        
        .items-table thead {
            background: #2563eb;
            color: white;
        }
        
        .items-table th {
            padding: 12px 8px;
            text-align: left;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .items-table th.text-right {
            text-align: right;
        }
        
        .items-table tbody tr {
            border-bottom: 1px solid #e5e7eb;
        }
        
        .items-table tbody tr:nth-child(even) {
            background: #f9fafb;
        }
        
        .items-table td {
            padding: 10px 8px;
            font-size: 12px;
        }
        
        .items-table td.text-right {
            text-align: right;
        }
        
        .items-table .variant-info {
            font-size: 10px;
            color: #666;
            display: block;
            margin-top: 2px;
        }
        
        /* Totals */
        .totals-section {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 30px;
        }
        
        .totals {
            width: 350px;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 13px;
        }
        
        .total-row.subtotal {
            border-top: 1px solid #e5e7eb;
            padding-top: 10px;
        }
        
        .total-row.grand-total {
            border-top: 2px solid #2563eb;
            border-bottom: 2px solid #2563eb;
            margin-top: 5px;
            padding: 12px 0;
            font-size: 16px;
            font-weight: bold;
            color: #2563eb;
        }
        
        .total-row.balance-due {
            background: #fef3c7;
            padding: 12px 10px;
            margin-top: 10px;
            border-radius: 4px;
            font-size: 15px;
            font-weight: bold;
        }
        
        /* Additional Info */
        .additional-info {
            margin-bottom: 30px;
        }
        
        .additional-info h3 {
            font-size: 13px;
            color: #2563eb;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        
        .shipping-details {
            background: #f0f9ff;
            padding: 15px;
            border-radius: 4px;
            border-left: 4px solid #2563eb;
        }
        
        .shipping-details p {
            margin: 5px 0;
            font-size: 12px;
        }
        
        /* Signature Section */
        .signature-section {
            display: flex;
            justify-content: space-between;
            margin-top: 60px;
            margin-bottom: 20px;
        }
        
        .signature-box {
            width: 30%;
            text-align: center;
        }
        
        .signature-line {
            border-top: 2px solid #333;
            margin-top: 50px;
            padding-top: 5px;
            font-size: 11px;
            font-weight: bold;
        }
        
        /* Footer */
        .invoice-footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #e5e7eb;
            text-align: center;
        }
        
        .invoice-footer p {
            font-size: 11px;
            color: #666;
            margin: 5px 0;
        }
        
        /* Print Button */
        .print-button, .close-button {
            position: fixed;
            top: 20px;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            box-shadow: 0 4px 6px rgba(0,0,0,0.2);
            z-index: 1000;
        }
        .print-button {
            right: 20px;
            background: #2563eb;
            color: white;
        }
        .print-button:hover { background: #1d4ed8; }
        
        .close-button {
            right: 180px;
            background: #6b7280;
            color: white;
        }
        .close-button:hover { background: #4b5563; }
        
        /* Print Styles */
        @media print {
            body { padding: 0; background: white; }
            .invoice-container { box-shadow: none; padding: 20px; max-width: 100%; }
            .no-print { display: none !important; }
            .items-table thead { background: #2563eb !important; color: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .total-row.grand-total { border-top: 2px solid #2563eb !important; border-bottom: 2px solid #2563eb !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .total-row.balance-due { background: #fef3c7 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .shipping-details { background: #f0f9ff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <button onclick="window.print()" class="print-button no-print">
        🖨️ Print Invoice
    </button>
    <button onclick="window.close()" class="close-button no-print">
        ✕ Close
    </button>
    
    <div class="invoice-container">
        <!-- Header -->
        <div class="invoice-header">
            <div class="company-info">
                <h1><?php echo htmlspecialchars($company['name']); ?></h1>
                <p><?php echo htmlspecialchars($company['tagline']); ?></p>
                <p><?php echo htmlspecialchars($company['address']); ?></p>
                <p>Phone: <?php echo htmlspecialchars($company['phone']); ?></p>
                <p>Email: <?php echo htmlspecialchars($company['email']); ?></p>
                <p>Web: <?php echo htmlspecialchars($company['website']); ?></p>
            </div>
            <div class="invoice-title">
                <h2>INVOICE</h2>
                <span class="status <?php echo htmlspecialchars($order->status); ?>">
                    <?php echo ucwords(str_replace('_', ' ', $order->status)); ?>
                </span>
                <p style="margin-top: 10px;"><strong>Invoice #:</strong> <?php echo htmlspecialchars($order->order_number); ?></p>
                <p><strong>Date:</strong> <?php echo date('M j, Y', strtotime($order->shipped_date ?? $order->order_date)); ?></p>
            </div>
        </div>
        
        <!-- Info Section -->
        <div class="info-section">
            <div class="info-block">
                <h3>Bill To</h3>
                <p><strong><?php echo htmlspecialchars($order->customer_name); ?></strong></p>
                <!-- RECTIFIED: Use customer_address (which maps to business_address) -->
                <p><?php echo nl2br(htmlspecialchars($order->customer_address)); ?></p> 
                <p>Phone: <?php echo htmlspecialchars($order->customer_phone); ?></p>
                <?php if ($order->customer_email): ?>
                <p>Email: <?php echo htmlspecialchars($order->customer_email); ?></p>
                <?php endif; ?>
            </div>
            <div class="info-block">
                <h3>Ship To</h3>
                <!-- Shipping address from the order -->
                <p><strong><?php echo htmlspecialchars($order->customer_name); ?></strong></p>
                 <p><?php echo nl2br(htmlspecialchars($order->shipping_address)); ?></p>
                 <p style="margin-top: 10px;"><strong>Branch:</strong> <?php echo htmlspecialchars($order->branch_name); ?></p>
            </div>
        </div>
        
        <!-- Order Details (Moved from Ship To) -->
         <div class="info-section" style="margin-top: -15px; margin-bottom: 20px;">
             <div class="info-block">
                <h3>Order Details</h3>
                <p><strong>Order Number:</strong> <?php echo htmlspecialchars($order->order_number); ?></p>
                <p><strong>Order Date:</strong> <?php echo date('M j, Y', strtotime($order->order_date)); ?></p>
                <p><strong>Required Date:</strong> <?php echo date('M j, Y', strtotime($order->required_date)); ?></p>
                <p><strong>Order Type:</strong> <?php echo ucwords(str_replace('_', ' ', $order->order_type)); ?></p>
             </div>
             <div class="info-block">
                <!-- Can be empty or add more details -->
             </div>
         </div>
        
        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th style="width: 35%;">Product Description</th>
                    <th style="width: 15%;">SKU</th>
                    <th class="text-right" style="width: 12%;">Qty</th>
                    <th class="text-right" style="width: 15%;">Unit Price</th>
                    <th class="text-right" style="width: 18%;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $item_num = 1;
                foreach ($items as $item): 
                    $variant_display = [];
                    if ($item->grade) $variant_display[] = "Grade " . $item->grade;
                    if ($item->weight_variant) $variant_display[] = $item->weight_variant;
                ?>
                <tr>
                    <td><?php echo $item_num++; ?></td>
                    <td>
                        <strong><?php echo htmlspecialchars($item->product_name); ?></strong>
                        <?php if (!empty($variant_display)): ?>
                        <span class="variant-info">
                            <?php echo htmlspecialchars(implode(' - ', $variant_display)); ?>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($item->variant_sku ?? '-'); ?></td>
                    <td class="text-right"><?php echo rtrim(rtrim(number_format($item->quantity, 2), '0'), '.'); ?> <?php echo $item->unit_of_measure; ?></td>
                    <td class="text-right">৳<?php echo number_format($item->unit_price, 2); ?></td>
                    <td class="text-right"><strong>৳<?php echo number_format($item->line_total, 2); ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Totals -->
        <div class="totals-section">
            <div class="totals">
                <div class="total-row subtotal">
                    <span>Subtotal:</span>
                    <span>৳<?php echo number_format($order->subtotal, 2); ?></span>
                </div>
                
                <?php if ($order->discount_amount > 0): ?>
                <div class="total-row">
                    <span>Discount:</span>
                    <span style="color: #ef4444;">-৳<?php echo number_format($order->discount_amount, 2); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if ($order->tax_amount > 0): ?>
                <div class="total-row">
                    <span>Tax:</span>
                    <span>৳<?php echo number_format($order->tax_amount, 2); ?></span>
                </div>
                <?php endif; ?>
                
                <div class="total-row grand-total">
                    <span>Total Amount:</span>
                    <span>৳<?php echo number_format($order->total_amount, 2); ?></span>
                </div>
                
                <?php if ($order->advance_paid > 0): ?>
                <div class="total-row">
                    <span>Advance Paid:</span>
                    <span style="color: #10b981;">-৳<?php echo number_format($order->advance_paid, 2); ?></span>
                </div>
                <?php endif; ?>
                
                <div class="total-row balance-due">
                    <span>Balance Due:</span>
                    <span>৳<?php echo number_format($order->balance_due, 2); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Shipping Details -->
        <?php if ($order->truck_number): ?>
        <div class="additional-info">
            <h3>Shipping Details</h3>
            <div class="shipping-details">
                <p><strong>Truck Number:</strong> <?php echo htmlspecialchars($order->truck_number); ?></p>
                <p><strong>Driver Name:</strong> <?php echo htmlspecialchars($order->driver_name); ?></p>
                <p><strong>Driver Contact:</strong> <?php echo htmlspecialchars($order->driver_contact); ?></p>
                <?php if ($order->shipped_date): ?>
                <p><strong>Shipped Date:</strong> <?php echo date('M j, Y g:i A', strtotime($order->shipped_date)); ?></p>
                <?php endif; ?>
                <?php if ($order->status === 'delivered' && $order->delivered_date): ?>
                <p><strong>Delivered Date:</strong> <?php echo date('M j, Y g:i A', strtotime($order->delivered_date)); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Special Instructions -->
        <?php if ($order->special_instructions): ?>
        <div class="additional-info">
            <h3>Special Instructions</h3>
            <div class="shipping-details" style="background: #fff9e6; border-left-color: #f59e0b;">
                <p><?php echo nl2br(htmlspecialchars($order->special_instructions)); ?></p>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Signature Section -->
        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-line">Prepared By</div>
                <p style="font-size: 10px; margin-top: 5px;"><?php echo htmlspecialchars($order->created_by_name ?? 'N/A'); ?></p>
            </div>
            <div class="signature-box">
                <div class="signature-line">Authorized By</div>
            </div>
            <div class="signature-box">
                <div class="signature-line">Customer Signature</div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="invoice-footer">
            <p><strong>Payment Terms:</strong> <?php echo ($order->order_type == 'credit') ? 'Payment due as per agreement' : 'Paid in Advance'; ?></p>
            <p style="margin-top: 10px;">Thank you for your business!</p>
            <p style="font-size: 10px; color: #999; margin-top: 15px;">
                This is a computer-generated invoice. For any queries, please contact us at <?php echo htmlspecialchars($company['phone']); ?>
            </p>
        </div>
    </div>
    
    <script>
        // Auto-focus for printing
        window.onload = function() {
            // Optional: Uncomment to auto-print
            // window.print();
        }
    </script>
</body>
</html>
