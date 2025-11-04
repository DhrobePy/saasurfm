<?php
// This widget is loaded by admin/index.php
// All variables ($db, $widget_title, $date_range, $widget_icon, $widget_color) are inherited

$chart_data = [];
$chart_labels = [];
$error_message = null;

try {
    // --- Date Helper Logic ---
    // This logic converts the string $date_range into SQL-ready start and end dates
    
    $start_date_str = date('Y-m-d 00:00:00');
    $end_date_str = date('Y-m-d 23:59:59');
    
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
            $start_date_str = date('Y-m-d 00:00:00', strtotime('-1 day'));
            $end_date_str = date('Y-m-d 23:59:59', strtotime('-1 day'));
            break;
        case 'this_week':
            $start_date_str = (new DateTime('monday this week', $tz))->format('Y-m-d 00:00:00');
            $end_date_str = (new DateTime('sunday this week', $tz))->format('Y-m-d 23:59:59');
            break;
        case 'last_week':
            $start_date_str = (new DateTime('monday last week', $tz))->format('Y-m-d 00:00:00');
            $end_date_str = (new DateTime('sunday last week', $tz))->format('Y-m-d 23:59:59');
            break;
        case 'this_month':
            $start_date_str = date('Y-m-01 00:00:00');
            $end_date_str = date('Y-m-t 23:59:59');
            break;
        case 'last_month':
            $start_date_str = date('Y-m-01 00:00:00', strtotime('first day of last month'));
            $end_date_str = date('Y-m-t 23:59:59', strtotime('last day of last month'));
            break;
        case 'this_quarter':
            $current_quarter = ceil(date('n') / 3);
            $start_date_str = (new DateTime(date('Y') . '-' . (($current_quarter - 1) * 3 + 1) . '-01 00:00:00', $tz))->format('Y-m-d H:i:s');
            $end_date_str = (new DateTime(date('Y') . '-' . ($current_quarter * 3) . '-' . date('t', strtotime(date('Y') . '-' . ($current_quarter * 3) . '-01')), $tz))->format('Y-m-d 23:59:59');
            break;
        case 'this_year':
            $start_date_str = date('Y-01-01 00:00:00');
            $end_date_str = date('Y-12-31 23:59:59');
            break;
        case 'last_30_days':
            $start_date_str = date('Y-m-d 00:00:00', strtotime('-29 days')); // Includes today
            break;
        case 'last_90_days':
            $start_date_str = date('Y-m-d 00:00:00', strtotime('-89 days')); // Includes today
            break;
    }
    // --- End Date Helper ---

    // 1. Create a "scaffold" of all months in the selected range
    // This ensures that months with 0 sales are still shown on the chart.
    
    $start_dt = new DateTime($start_date_str);
    $end_dt = new DateTime($end_date_str);
    // Ensure we are at the start of the first month for the period
    $start_dt->modify('first day of this month'); 
    
    $period = new DatePeriod(
        $start_dt,
        new DateInterval('P1M'), // P1M = Period 1 Month
        $end_dt->modify('first day of next month') // Include the end month
    );
    
    $monthly_scaffold = [];
    foreach ($period as $dt) {
        $month_key = $dt->format('Y-m'); // "2025-10"
        $month_label = $dt->format('M Y'); // "Oct 2025"
        
        $chart_labels[] = $month_label;
        $monthly_scaffold[$month_key] = 0; // Initialize sales for this month at 0
    }

    // 2. Build the SQL Query
    // We combine POS sales and completed Credit sales
    $sql = "
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as sales_month,
            SUM(total_amount) as total
        FROM (
            -- Query 1: POS Sales
            SELECT created_at, total_amount 
            FROM pos_sales
            WHERE created_at BETWEEN ? AND ?
            
            UNION ALL
            
            -- Query 2: Completed Credit Sales
            SELECT created_at, total_amount 
            FROM credit_orders
            WHERE created_at BETWEEN ? AND ?
            AND status IN ('Shipped', 'Dispatched', 'Delivered')
        ) AS combined_sales
        GROUP BY sales_month
        ORDER BY sales_month ASC
    ";
    
    // Execute the query
    $sales_data = $db->query($sql, [$start_date_str, $end_date_str, $start_date_str, $end_date_str])->results();

    // 3. Populate the scaffold with real data
    if ($sales_data) {
        foreach ($sales_data as $row) {
            if (isset($monthly_scaffold[$row->sales_month])) {
                $monthly_scaffold[$row->sales_month] = (float) $row->total;
            }
        }
    }
    
    // 4. Finalize chart data
    // The $chart_labels are already in order
    // We just need the values from the scaffold
    $chart_data = array_values($monthly_scaffold);
    
    if (empty($chart_data)) {
        $error_message = "No sales data found for this period.";
    }

} catch (Exception $e) {
    // In case of a database error
    error_log("Error in monthly_sales_chart widget: " . $e->getMessage());
    $error_message = "Error loading chart data.";
}

// Generate a unique ID for the canvas
$chart_id = "monthlySalesChart_" . rand(1000, 9999);

?>

<!-- Chart Widget HTML (Fajracct style) -->
<div class="bg-white rounded-lg shadow-md overflow-hidden h-full">
    <!-- Header -->
    <div class="p-4 flex justify-between items-center border-b border-gray-200">
        <div class="flex items-center">
            <div class="p-2 rounded-full text-<?php echo $widget_color; ?>-600 bg-<?php echo $widget_color; ?>-100 mr-3">
                <i class="fas <?php echo $widget_icon; ?>"></i>
            </div>
            <h3 class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($widget_title); ?></h3>
        </div>
        <span class="text-xs font-medium text-gray-500">
            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $date_range))); ?>
        </span>
    </div>
    
    <!-- Chart Canvas -->
    <div classs="p-4">
        <?php if ($error_message): ?>
            <div class="p-8 text-center text-gray-500">
                <i class="fas fa-exclamation-circle text-2xl mb-2"></i><br>
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php else: ?>
            <canvas id="<?php echo $chart_id; ?>" class="p-4" style="max-height: 350px;"></canvas>
        <?php endif; ?>
    </div>
</div>

<!-- Chart.js Initialization Script -->
<?php if (!$error_message): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Ensure Chart.js is loaded
    if (typeof Chart === 'undefined') {
        console.error('Chart.js is not loaded. This widget will not work.');
        return;
    }
    
    const ctx = document.getElementById('<?php echo $chart_id; ?>').getContext('2d');
    
    new Chart(ctx, {
        type: 'bar', // 'bar' or 'line'
        data: {
            labels: <?php echo json_encode($chart_labels); ?>,
            datasets: [{
                label: 'Total Sales',
                data: <?php echo json_encode($chart_data); ?>,
                backgroundColor: 'rgba(59, 130, 246, 0.6)', // "Fajracct style" blue
                borderColor: 'rgba(59, 130, 246, 1)',
                borderWidth: 2,
                borderRadius: 4,
                tension: 0.1 // Use for line charts
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        // Format as currency
                        callback: function(value, index, values) {
                            return '৳' + new Intl.NumberFormat('en-US').format(value);
                        }
                    }
                },
                x: {
                    grid: {
                        display: false // Hide vertical grid lines
                    }
                }
            },
            plugins: {
                legend: {
                    display: false // Hide the legend as there is only one dataset
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.parsed.y !== null) {
                                label += '৳' + new Intl.NumberFormat('en-US').format(context.parsed.y);
                            }
                            return label;
                        }
                    }
                }
            }
        }
    });
});
</script>
<?php endif; ?>
