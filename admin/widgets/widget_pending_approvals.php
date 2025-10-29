<!-- admin/widgets/widget_pending_approvals.php -->
<?php
// This widget fetches its own data. $db is available from index.php
if (!isset($db)) { global $db; } // Ensure $db is available
$pending_orders_data = null;
$pending_error = null;
try {
    // This query correctly uses your 'credit_orders' table
    $pending_orders_data = $db->query(
        "SELECT o.id, o.order_number, o.total_amount, o.status, c.name as customer_name
         FROM credit_orders o
         JOIN customers c ON o.customer_id = c.id
         WHERE o.status IN ('pending_approval', 'escalated')
         ORDER BY FIELD(o.status, 'escalated', 'pending_approval'), o.order_date ASC
         LIMIT 10"
    )->results();
} catch (Exception $e) {
    $pending_error = $e->getMessage();
}
?>
<div class="bg-white rounded-lg shadow-md border border-gray-200 p-6 h-full flex flex-col">
    <h3 class="text-xl font-bold text-gray-800 mb-4">Pending Credit Approvals</h3>
    <div class="flex-grow overflow-y-auto">
        <?php if ($pending_error): ?>
            <p class="text-red-500">Error: <?php echo htmlspecialchars($pending_error); ?></p>
        <?php elseif (!empty($pending_orders_data)): ?>
            <ul class="divide-y divide-gray-200">
                <?php foreach($pending_orders_data as $order): ?>
                     <li class="py-3">
                        <div class="flex justify-between items-center">
                            <div>
                                <p class="text-sm font-medium text-primary-600">
                                    <?php echo htmlspecialchars($order->order_number); ?>
                                </p>
                                <p class="text-xs text-gray-600">
                                    <?php echo htmlspecialchars($order->customer_name); ?>
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-bold">৳<?php echo number_format($order->total_amount, 0); ?></p>
                                <?php if($order->status == 'escalated'): ?>
                                    <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-700">ESCALATED</span>
                                <?php else: ?>
                                     <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-700">Pending</span>
                                <?php endif; ?>
                            </div>
                        </div>
                     </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <div class="text-center text-gray-500 py-6">
                <i class="fas fa-check-circle text-3xl text-green-400 mb-2"></i>
                <p class="font-medium">No orders are pending approval.</p>
                <p class="text-sm">Great job!</p>
            </div>
        <?php endif; ?>
    </div>
    <a href="<?php echo url('admin/credit_order_approval.php'); // Link to the main approval page ?>" class="text-sm text-primary-600 hover:underline mt-4 inline-block">
        Go to Approval Queue →
    </a>
</div>

