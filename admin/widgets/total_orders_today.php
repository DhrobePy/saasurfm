<?php
// This widget is loaded by admin/index.php
// All variables ($db, $widget_title, $date_range, $widget_icon, $widget_color) are inherited

$stat_value = "0";

try {
    // --- Date Helper Logic ---
    // This logic converts the string $date_range into SQL-ready start and end dates
    
    $start_date = date('Y-m-d 00:00:00');
    $end_date = date('Y-m-d 23:59:59');
    
    // Set the default timezone to avoid issues, falling back to UTC
    try {
        $tz = new DateTimeZone(date_default_timezone_get());
    } catch (Exception $e) {
        $tz = new DateTimeZone('UTC');
    }

    switch ($date_range) {
        case 'today':
            // Default is already today
            break;
        case 'yesterday':
            $start_date = date('Y-m-d 00:00:00', strtotime('-1 day'));
            $end_date = date('Y-m-d 23:59:59', strtotime('-1 day'));
            break;
        case 'this_week':
            $start_date = (new DateTime('monday this week', $tz))->format('Y-m-d 00:00:00');
            $end_date = (new DateTime('sunday this week', $tz))->format('Y-m-d 23:59:59');
            break;
        case 'last_week':
            $start_date = (new DateTime('monday last week', $tz))->format('Y-m-d 00:00:00');
            $end_date = (new DateTime('sunday last week', $tz))->format('Y-m-d 23:59:59');
            break;
        case 'this_month':
            $start_date = date('Y-m-01 00:00:00');
            $end_date = date('Y-m-t 23:59:59');
            break;
        case 'last_month':
            $start_date = date('Y-m-01 00:00:00', strtotime('first day of last month'));
            $end_date = date('Y-m-t 23:59:59', strtotime('last day of last month'));
            break;
        case 'this_quarter':
            $current_quarter = ceil(date('n') / 3);
            $start_date = (new DateTime(date('Y') . '-' . (($current_quarter - 1) * 3 + 1) . '-01 00:00:00', $tz))->format('Y-m-d H:i:s');
            $end_date = (new DateTime(date('Y') . '-' . ($current_quarter * 3) . '-' . date('t', strtotime(date('Y') . '-' . ($current_quarter * 3) . '-01')), $tz))->format('Y-m-d 23:59:59');
            break;
        case 'this_year':
            $start_date = date('Y-01-01 00:00:00');
            $end_date = date('Y-12-31 23:59:59');
            break;
        case 'last_30_days':
            $start_date = date('Y-m-d 00:00:00', strtotime('-29 days')); // Includes today
            break;
        case 'last_90_days':
            $start_date = date('Y-m-d 00:00:00', strtotime('-89 days')); // Includes today
            break;
    }
    // --- End Date Helper ---

    // Query to get the COUNT of POS sales AND Credit orders
    $query = "
        SELECT 
            (
                (SELECT COUNT(id) FROM pos_sales WHERE created_at BETWEEN ? AND ?) +
                (SELECT COUNT(id) FROM credit_orders WHERE created_at BETWEEN ? AND ?)
            )
        AS total_orders
    ";
    
    // We must pass the dates twice (once for each subquery)
    $result = $db->query($query, [$start_date, $end_date, $start_date, $end_date])->first();
    
    if ($result && $result->total_orders) {
        $stat_value = number_format($result->total_orders);
    }

} catch (Exception $e) {
    // In case of a database error
    $stat_value = "Error";
    // You could log the error message: error_log($e->getMessage());
}

?>

<!-- Stat Card HTML (Fajracct style) -->
<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <div class="p-4 flex items-center">
        <!-- Icon -->
        <div class="p-3 rounded-full text-<?php echo $widget_color; ?>-600 bg-<?php echo $widget_color; ?>-100 mr-4">
            <i class="fas <?php echo $widget_icon; ?> text-xl"></i>
        </div>
        <!-- Content -->
        <div>
            <p class="text-sm font-medium text-gray-500 uppercase"><?php echo htmlspecialchars($widget_title); ?></p>
            <p class="text-3xl font-bold text-gray-900"><?php echo htmlspecialchars($stat_value); ?></p>
        </div>
    </div>
    <!-- Footer -->
    <div class="bg-gray-50 px-4 py-2 text-xs text-gray-500">
        Data for: <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $date_range))); ?>
    </div>
</div>
