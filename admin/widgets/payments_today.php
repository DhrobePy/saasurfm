<?php
// Widget: payments_today — Customer payments collected for selected date range
$stat_value = '0.00';
$pay_count  = 0;

$start_date = date('Y-m-d');
$end_date   = date('Y-m-d');
try { $tz = new DateTimeZone(date_default_timezone_get()); } catch (Exception $e) { $tz = new DateTimeZone('UTC'); }

switch ($date_range ?? 'today') {
    case 'yesterday':   $start_date = $end_date = date('Y-m-d', strtotime('-1 day')); break;
    case 'this_week':   $start_date = (new DateTime('monday this week', $tz))->format('Y-m-d'); $end_date = (new DateTime('sunday this week', $tz))->format('Y-m-d'); break;
    case 'last_week':   $start_date = (new DateTime('monday last week', $tz))->format('Y-m-d'); $end_date = (new DateTime('sunday last week', $tz))->format('Y-m-d'); break;
    case 'this_month':  $start_date = date('Y-m-01'); $end_date = date('Y-m-t'); break;
    case 'last_month':  $start_date = date('Y-m-01', strtotime('first day of last month')); $end_date = date('Y-m-t', strtotime('last day of last month')); break;
    case 'this_year':   $start_date = date('Y-01-01'); $end_date = date('Y-12-31'); break;
    case 'last_30_days':$start_date = date('Y-m-d', strtotime('-29 days')); break;
    case 'last_90_days':$start_date = date('Y-m-d', strtotime('-89 days')); break;
}
try {
    $result = $db->query(
        "SELECT IFNULL(SUM(amount), 0) AS v, COUNT(id) AS c
         FROM customer_payments WHERE payment_date BETWEEN ? AND ?",
        [$start_date, $end_date]
    )->first();
    if ($result) {
        $stat_value = number_format((float)$result->v, 2);
        $pay_count  = (int)$result->c;
    }
} catch (Exception $e) { error_log('Widget payments_today: ' . $e->getMessage()); }
?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="p-5 flex items-center gap-4">
        <div class="w-12 h-12 rounded-full bg-<?php echo $widget_color; ?>-100 flex items-center justify-center flex-shrink-0">
            <i class="fas <?php echo $widget_icon; ?> text-<?php echo $widget_color; ?>-600 text-lg"></i>
        </div>
        <div>
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide"><?php echo htmlspecialchars($widget_title); ?></p>
            <p class="text-2xl font-bold text-gray-900">৳<?php echo $stat_value; ?></p>
            <p class="text-xs text-gray-400"><?php echo $pay_count; ?> payment<?php echo $pay_count !== 1 ? 's' : ''; ?></p>
        </div>
    </div>
    <div class="bg-gray-50 px-5 py-2 text-xs text-gray-500">
        <?php echo ucwords(str_replace('_', ' ', $date_range ?? 'today')); ?>
    </div>
</div>
