<?php
// This widget is loaded by admin/index.php
// All variables ($db, $widget_title, $date_range, $widget_icon, $widget_color) are inherited
// The $date_range is NOT used here, as we need *all* pending orders regardless of date.

$pending_orders = [];
$total_pending = 0;

try {
    // First, get the total count for the badge
    $count_result = $db->query("SELECT COUNT(id) as count FROM credit_orders WHERE status = 'Pending Approval'")->first();
    $total_pending = $count_result ? $count_result->count : 0;
    
    // Now, get the top 10 oldest orders to display in the list
    // We assume a 'customers' table with a 'name' column, linked by 'customer_id'
    if ($total_pending > 0) {
        $pending_orders = $db->query(
            "SELECT co.id, co.order_date, co.total_amount, c.name as customer_name 
             FROM credit_orders co
             JOIN customers c ON co.customer_id = c.id
             WHERE co.status = 'Pending Approval'
             ORDER BY co.order_date ASC
             LIMIT 10"
        )->results();
    }

} catch (Exception $e) {
    // In case of a database error, e.g., 'customers.name' column doesn't exist
    error_log("Error in pending_orders widget: " . $e->getMessage());
    $pending_orders = [];
    $total_pending = 0;
    // You could set an error message to display in the widget
}

?>

<!-- Table Widget HTML (Fajracct style) -->
<div class="bg-white rounded-lg shadow-md overflow-hidden h-full flex flex-col">
    <!-- Header -->
    <div class="p-4 flex justify-between items-center border-b border-gray-200">
        <div class="flex items-center">
            <div class="p-2 rounded-full text-<?php echo $widget_color; ?>-600 bg-<?php echo $widget_color; ?>-100 mr-3">
                <i class="fas <?php echo $widget_icon; ?>"></i>
            </div>
            <h3 class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($widget_title); ?></h3>
        </div>
        <!-- Total Count Badge -->
        <span class="bg-<?php echo $widget_color; ?>-600 text-white text-xs font-bold px-2 py-1 rounded-full">
            <?php echo $total_pending; ?>
        </span>
    </div>
    
    <!-- List of Orders -->
    <div class="flex-grow p-4 space-y-3 overflow-y-auto" style="max-height: 400px;">
        <?php if (empty($pending_orders)): ?>
            <!-- Empty State -->
            <div class="flex flex-col items-center justify-center h-full text-center text-gray-500 py-8">
                <i class="fas fa-check-circle text-4xl text-green-500"></i>
                <p class="mt-3 font-medium text-lg">All caught up!</p>
                <p class="text-sm">There are no orders pending approval.</p>
            </div>
        <?php else: ?>
            <!-- Order Items -->
            <?php foreach ($pending_orders as $order): ?>
                <a href="../cr/credit_order_approval.php?id=<?php echo $order->id; ?>" 
                   class="block p-3 bg-gray-50 rounded-lg hover:bg-blue-50 border border-gray-200 hover:border-blue-400 transition shadow-sm">
                    
                    <div class="flex justify-between items-center">
                        <span class="text-sm font-semibold text-gray-800">
                            <?php echo htmlspecialchars($order->customer_name); ?>
                        </span>
                        <span class="text-sm font-bold text-gray-900">
                            ৳<?php echo number_format($order->total_amount, 2); ?>
                        </span>
                    </div>
                    <div class="text-xs text-gray-500 mt-1">
                        Order #<?php echo $order->id; ?> &bull; <?php echo date('M j, Y', strtotime($order->order_date)); ?>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- Footer Link -->
    <?php if ($total_pending > 10): ?>
        <div class="bg-gray-50 px-4 py-3 text-sm text-center border-t border-gray-200">
            <a href="../cr/credit_order_approval.php" class="font-medium text-blue-600 hover:text-blue-800 transition">
                View all <?php echo $total_pending; ?> pending orders &rarr;
            </a>
        </div>
    <?php endif; ?>
</div>
