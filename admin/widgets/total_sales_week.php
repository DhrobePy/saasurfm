<?php
// Widget: total_sales_week — Credit sales for current week (completed orders)
$stat_value  = '0.00';
$order_count = 0;
try {
    $tz = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
    $week_start = (new DateTime('monday this week', $tz))->format('Y-m-d 00:00:00');
    $week_end   = (new DateTime('sunday this week', $tz))->format('Y-m-d 23:59:59');
    $result = $db->query(
        "SELECT IFNULL(SUM(total_amount), 0) AS v, COUNT(id) AS c
         FROM credit_orders
         WHERE status IN ('Shipped','Dispatched','Delivered')
           AND order_date BETWEEN ? AND ?",
        [$week_start, $week_end]
    )->first();
    if ($result) {
        $stat_value  = number_format((float)$result->v, 2);
        $order_count = (int)$result->c;
    }
} catch (Exception $e) { error_log('Widget total_sales_week: ' . $e->getMessage()); }
?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="p-5 flex items-center gap-4">
        <div class="w-12 h-12 rounded-full bg-<?php echo $widget_color; ?>-100 flex items-center justify-center flex-shrink-0">
            <i class="fas <?php echo $widget_icon; ?> text-<?php echo $widget_color; ?>-600 text-lg"></i>
        </div>
        <div>
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide"><?php echo htmlspecialchars($widget_title); ?></p>
            <p class="text-2xl font-bold text-gray-900">৳<?php echo $stat_value; ?></p>
            <p class="text-xs text-gray-400"><?php echo $order_count; ?> order<?php echo $order_count !== 1 ? 's' : ''; ?></p>
        </div>
    </div>
    <div class="bg-gray-50 px-5 py-2 text-xs text-gray-500">Mon – Sun · completed credit orders</div>
</div>
