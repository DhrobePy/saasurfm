<!-- admin/widgets/widget_pos_sales_summary.php -->
<?php
// This widget fetches its own data. $db is available from index.php
$pos_summary = null;
try {
    $today = date('Y-m-d');
    $pos_summary = $db->query(
        "SELECT COUNT(id) as order_count, SUM(total_amount) as net_sales
         FROM orders
         WHERE order_type = 'POS' AND DATE(order_date) = ?",
        [$today]
    )->first();
} catch (Exception $e) { /* Silently fail */ }
?>
<div class="bg-white rounded-lg shadow-md border border-gray-200 p-6 h-full">
    <h3 class="text-xl font-bold text-gray-800 mb-4">Today's POS Sales</h3>
    <?php if ($pos_summary && $pos_summary->order_count > 0): ?>
        <div class="space-y-3">
            <div class="flex justify-between items-center">
                <span class="text-gray-600">Net Sales</span>
                <span class="font-bold text-lg text-green-600">৳<?php echo number_format($pos_summary->net_sales, 2); ?></span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-gray-600">Total Orders</span>
                <span class="font-bold text-lg text-blue-600"><?php echo $pos_summary->order_count; ?></span>
            </div>
        </div>
        <a href="<?php echo url('pos/today_sales.php'); ?>" class="text-sm text-primary-600 hover:underline mt-4 inline-block">View Full Report →</a>
    <?php else: ?>
        <p class="text-gray-500">No POS sales recorded yet today.</p>
    <?php endif; ?>
</div>
