<?php
/**
 * Purchase Module - Executive Analytics & AI-Powered Insights
 * Smart reporting dashboard with forecasts, trends, and recommendations
 * 
 * @package Ujjal Flour Mills
 * @subpackage Purchase (Adnan) Module
 */

require_once '../core/init.php';

// Restrict access - CEO level access
restrict_access(['Superadmin', 'admin', 'Accounts']);

$currentUser = getCurrentUser();
$pageTitle = "Purchase Analytics & Insights";

$db = Database::getInstance()->getPdo();

// ===============================================
// TIME PERIOD SELECTION
// ===============================================
$period = $_GET['period'] ?? 'last_30_days';
$custom_from = $_GET['custom_from'] ?? '';
$custom_to = $_GET['custom_to'] ?? '';

// Calculate date ranges
switch ($period) {
    case 'today':
        $date_from = date('Y-m-d');
        $date_to = date('Y-m-d');
        break;
    case 'yesterday':
        $date_from = date('Y-m-d', strtotime('-1 day'));
        $date_to = date('Y-m-d', strtotime('-1 day'));
        break;
    case 'last_7_days':
        $date_from = date('Y-m-d', strtotime('-7 days'));
        $date_to = date('Y-m-d');
        break;
    case 'last_30_days':
        $date_from = date('Y-m-d', strtotime('-30 days'));
        $date_to = date('Y-m-d');
        break;
    case 'last_90_days':
        $date_from = date('Y-m-d', strtotime('-90 days'));
        $date_to = date('Y-m-d');
        break;
    case 'this_month':
        $date_from = date('Y-m-01');
        $date_to = date('Y-m-t');
        break;
    case 'last_month':
        $date_from = date('Y-m-01', strtotime('first day of last month'));
        $date_to = date('Y-m-t', strtotime('last day of last month'));
        break;
    case 'this_year':
        $date_from = date('Y-01-01');
        $date_to = date('Y-12-31');
        break;
    case 'custom':
        $date_from = $custom_from ?: date('Y-m-01');
        $date_to = $custom_to ?: date('Y-m-d');
        break;
    default:
        $date_from = date('Y-m-d', strtotime('-30 days'));
        $date_to = date('Y-m-d');
}

// ===============================================
// EXECUTIVE KPIs
// ===============================================
$kpi_sql = "SELECT 
    COUNT(DISTINCT po.id) as total_orders,
    COALESCE(SUM(po.total_order_value), 0) as total_order_value,
    COALESCE(SUM(po.quantity_kg), 0) as total_ordered_qty,
    COALESCE(SUM(po.total_received_qty), 0) as total_received_qty,
    COALESCE(SUM(po.total_received_value), 0) as total_received_value,
    COALESCE(SUM(po.total_paid), 0) as total_paid,
    COALESCE(SUM(po.balance_payable), 0) as total_outstanding,
    COUNT(DISTINCT po.supplier_id) as active_suppliers,
    AVG(po.unit_price_per_kg) as avg_unit_price,
    COUNT(DISTINCT CASE WHEN po.delivery_status = 'completed' THEN po.id END) as completed_orders,
    COUNT(DISTINCT CASE WHEN po.payment_status = 'paid' THEN po.id END) as fully_paid_orders
FROM purchase_orders_adnan po
WHERE po.po_date BETWEEN ? AND ?
AND po.po_status != 'cancelled'";

$stmt = $db->prepare($kpi_sql);
$stmt->execute([$date_from, $date_to]);
$kpis = $stmt->fetch(PDO::FETCH_OBJ);

// Calculate derived metrics
$kpis->fulfillment_rate = $kpis->total_ordered_qty > 0 
    ? ($kpis->total_received_qty / $kpis->total_ordered_qty * 100) 
    : 0;
$kpis->payment_rate = $kpis->total_received_value > 0 
    ? ($kpis->total_paid / $kpis->total_received_value * 100) 
    : 0;
$kpis->completion_rate = $kpis->total_orders > 0 
    ? ($kpis->completed_orders / $kpis->total_orders * 100) 
    : 0;

// ===============================================
// SUPPLIER PERFORMANCE ANALYSIS
// ===============================================
$supplier_perf_sql = "SELECT 
    s.company_name,
    COUNT(DISTINCT po.id) as order_count,
    COALESCE(SUM(po.total_order_value), 0) as total_value,
    COALESCE(SUM(po.quantity_kg), 0) as total_ordered,
    COALESCE(SUM(po.total_received_qty), 0) as total_received,
    COALESCE(SUM(po.balance_payable), 0) as outstanding_balance,
    AVG(po.unit_price_per_kg) as avg_price,
    AVG(DATEDIFF(grn.grn_date, po.po_date)) as avg_delivery_days,
    COUNT(DISTINCT grn.id) as grn_count,
    AVG(ABS(grn.quantity_received_kg - grn.expected_quantity) / NULLIF(grn.expected_quantity, 0) * 100) as avg_variance_percent
FROM suppliers s
INNER JOIN purchase_orders_adnan po ON s.id = po.supplier_id
LEFT JOIN goods_received_adnan grn ON po.id = grn.purchase_order_id AND grn.grn_status != 'cancelled'
WHERE po.po_date BETWEEN ? AND ?
AND po.po_status != 'cancelled'
GROUP BY s.id, s.company_name
ORDER BY total_value DESC
LIMIT 10";

$stmt = $db->prepare($supplier_perf_sql);
$stmt->execute([$date_from, $date_to]);
$supplier_performance = $stmt->fetchAll(PDO::FETCH_OBJ);

// ===============================================
// WHEAT ORIGIN ANALYSIS
// ===============================================
$origin_sql = "SELECT 
    po.wheat_origin,
    COUNT(DISTINCT po.id) as order_count,
    COALESCE(SUM(po.quantity_kg), 0) as total_quantity,
    COALESCE(SUM(po.total_order_value), 0) as total_value,
    AVG(po.unit_price_per_kg) as avg_price,
    MIN(po.unit_price_per_kg) as min_price,
    MAX(po.unit_price_per_kg) as max_price
FROM purchase_orders_adnan po
WHERE po.po_date BETWEEN ? AND ?
AND po.po_status != 'cancelled'
GROUP BY po.wheat_origin
ORDER BY total_quantity DESC";

$stmt = $db->prepare($origin_sql);
$stmt->execute([$date_from, $date_to]);
$wheat_origins = $stmt->fetchAll(PDO::FETCH_OBJ);

// ===============================================
// PRICE TREND ANALYSIS (Last 12 months)
// ===============================================
$price_trend_sql = "SELECT 
    DATE_FORMAT(po.po_date, '%Y-%m') as month,
    AVG(po.unit_price_per_kg) as avg_price,
    MIN(po.unit_price_per_kg) as min_price,
    MAX(po.unit_price_per_kg) as max_price,
    COUNT(*) as order_count,
    po.wheat_origin
FROM purchase_orders_adnan po
WHERE po.po_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
AND po.po_status != 'cancelled'
GROUP BY DATE_FORMAT(po.po_date, '%Y-%m'), po.wheat_origin
ORDER BY month ASC, wheat_origin";

$stmt = $db->query($price_trend_sql);
$price_trends = $stmt->fetchAll(PDO::FETCH_OBJ);

// ===============================================
// VARIANCE ANALYSIS
// ===============================================
$variance_sql = "SELECT 
    COUNT(*) as total_grns,
    AVG(ABS(grn.quantity_received_kg - grn.expected_quantity)) as avg_variance_kg,
    AVG(ABS(grn.quantity_received_kg - grn.expected_quantity) / NULLIF(grn.expected_quantity, 0) * 100) as avg_variance_percent,
    SUM(CASE WHEN grn.quantity_received_kg < grn.expected_quantity THEN 1 ELSE 0 END) as shortage_count,
    SUM(CASE WHEN grn.quantity_received_kg > grn.expected_quantity THEN 1 ELSE 0 END) as excess_count,
    SUM(CASE WHEN ABS(grn.quantity_received_kg - grn.expected_quantity) / NULLIF(grn.expected_quantity, 0) * 100 > 5 THEN 1 ELSE 0 END) as high_variance_count
FROM goods_received_adnan grn
INNER JOIN purchase_orders_adnan po ON grn.purchase_order_id = po.id
WHERE grn.grn_date BETWEEN ? AND ?
AND grn.grn_status != 'cancelled'
AND grn.expected_quantity > 0";

$stmt = $db->prepare($variance_sql);
$stmt->execute([$date_from, $date_to]);
$variance_analysis = $stmt->fetch(PDO::FETCH_OBJ);

// ===============================================
// PAYMENT PERFORMANCE
// ===============================================
$payment_perf_sql = "SELECT 
    COUNT(*) as total_payments,
    COALESCE(SUM(amount_paid), 0) as total_amount,
    AVG(DATEDIFF(payment_date, created_at)) as avg_payment_delay_days,
    SUM(CASE WHEN payment_method = 'bank' THEN 1 ELSE 0 END) as bank_payments,
    SUM(CASE WHEN payment_method = 'cash' THEN 1 ELSE 0 END) as cash_payments,
    SUM(CASE WHEN payment_method = 'cheque' THEN 1 ELSE 0 END) as cheque_payments,
    SUM(CASE WHEN payment_type = 'advance' THEN amount_paid ELSE 0 END) as advance_payments_total
FROM purchase_payments_adnan
WHERE payment_date BETWEEN ? AND ?";

$stmt = $db->prepare($payment_perf_sql);
$stmt->execute([$date_from, $date_to]);
$payment_performance = $stmt->fetch(PDO::FETCH_OBJ);

// ===============================================
// AI INSIGHTS & RECOMMENDATIONS
// ===============================================
$insights = [];
$warnings = [];
$recommendations = [];

// Price volatility check
if (!empty($price_trends)) {
    $prices = array_column($price_trends, 'avg_price');
    $price_std_dev = count($prices) > 1 ? sqrt(array_sum(array_map(function($x) use ($prices) { 
        $mean = array_sum($prices) / count($prices);
        return pow($x - $mean, 2); 
    }, $prices)) / count($prices)) : 0;
    
    $price_volatility = ($kpis->avg_unit_price > 0) ? ($price_std_dev / $kpis->avg_unit_price * 100) : 0;
    
    if ($price_volatility > 15) {
        $warnings[] = [
            'icon' => 'fa-chart-line',
            'color' => 'red',
            'title' => 'High Price Volatility Detected',
            'message' => "Wheat prices fluctuated by " . number_format($price_volatility, 1) . "% in the selected period. Consider forward contracts.",
            'severity' => 'high'
        ];
    }
}

// Fulfillment rate check
if ($kpis->fulfillment_rate < 90) {
    $warnings[] = [
        'icon' => 'fa-truck',
        'color' => 'orange',
        'title' => 'Low Fulfillment Rate',
        'message' => "Only " . number_format($kpis->fulfillment_rate, 1) . "% of ordered quantities received. Review supplier contracts.",
        'severity' => 'medium'
    ];
}

// Outstanding balance check
if ($kpis->total_outstanding > ($kpis->total_received_value * 0.3)) {
    $warnings[] = [
        'icon' => 'fa-exclamation-triangle',
        'color' => 'red',
        'title' => 'High Outstanding Balance',
        'message' => "৳" . number_format($kpis->total_outstanding, 0) . " outstanding (" . number_format(($kpis->total_outstanding / $kpis->total_received_value * 100), 1) . "% of received value). Cash flow risk.",
        'severity' => 'high'
    ];
}

// Variance analysis warnings
if ($variance_analysis && $variance_analysis->avg_variance_percent > 3) {
    $warnings[] = [
        'icon' => 'fa-balance-scale',
        'color' => 'orange',
        'title' => 'High Delivery Variance',
        'message' => "Average variance of " . number_format($variance_analysis->avg_variance_percent, 1) . "% between expected and received quantities. Tighten quality controls.",
        'severity' => 'medium'
    ];
}

// Positive insights
if ($kpis->payment_rate > 95) {
    $insights[] = [
        'icon' => 'fa-check-circle',
        'color' => 'green',
        'title' => 'Excellent Payment Performance',
        'message' => number_format($kpis->payment_rate, 1) . "% payment rate maintains strong supplier relationships.",
        'type' => 'success'
    ];
}

if ($kpis->completion_rate > 80) {
    $insights[] = [
        'icon' => 'fa-trophy',
        'color' => 'green',
        'title' => 'Strong Order Completion',
        'message' => number_format($kpis->completion_rate, 1) . "% of orders fully delivered. Reliable procurement process.",
        'type' => 'success'
    ];
}

// AI Recommendations
if ($kpis->total_orders > 0) {
    $avg_order_value = $kpis->total_order_value / $kpis->total_orders;
    
    if ($avg_order_value < 1000000) {
        $recommendations[] = [
            'icon' => 'fa-lightbulb',
            'title' => 'Bulk Ordering Opportunity',
            'message' => "Average order value is ৳" . number_format($avg_order_value, 0) . ". Consolidating orders could improve bulk discounts by 3-5%.",
            'potential_saving' => $kpis->total_order_value * 0.04
        ];
    }
}

// Supplier concentration risk
if (count($supplier_performance) > 0 && $supplier_performance[0]->total_value > ($kpis->total_order_value * 0.5)) {
    $recommendations[] = [
        'icon' => 'fa-users',
        'title' => 'Supplier Diversification Needed',
        'message' => "Top supplier accounts for " . number_format(($supplier_performance[0]->total_value / $kpis->total_order_value * 100), 0) . "% of orders. Reduce concentration risk.",
        'potential_saving' => 0
    ];
}

// Price trend forecast
if (!empty($price_trends) && count($price_trends) >= 3) {
    $recent_prices = array_slice(array_column($price_trends, 'avg_price'), -3);
    $price_change = ($recent_prices[count($recent_prices)-1] - $recent_prices[0]) / $recent_prices[0] * 100;
    
    if ($price_change > 5) {
        $recommendations[] = [
            'icon' => 'fa-chart-line',
            'title' => 'Price Increase Trend Detected',
            'message' => "Prices trending up " . number_format($price_change, 1) . "%. Consider forward booking for next 2-3 months.",
            'potential_saving' => $kpis->total_order_value * 0.03
        ];
    } elseif ($price_change < -5) {
        $insights[] = [
            'icon' => 'fa-arrow-down',
            'color' => 'blue',
            'title' => 'Favorable Price Trend',
            'message' => "Prices declining " . number_format(abs($price_change), 1) . "%. Good time for strategic procurement.",
            'type' => 'info'
        ];
    }
}

require_once '../templates/header.php';
?>

<style>
.stat-card {
    transition: transform 0.2s, box-shadow 0.2s;
}
.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 20px rgba(0,0,0,0.1);
}
.insight-card {
    border-left: 4px solid;
    transition: all 0.3s;
}
.insight-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.chart-container {
    position: relative;
    height: 300px;
}
@media print {
    .no-print { display: none !important; }
}
</style>

<div class="container mx-auto px-4 py-6">
    
    <!-- Page Header -->
    <div class="flex justify-between items-center mb-6 no-print">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-chart-pie text-blue-600"></i> Purchase Analytics Dashboard
            </h1>
            <p class="text-gray-600 mt-1">AI-Powered Insights & Executive Reports</p>
        </div>
        <div class="flex gap-2">
            <button onclick="window.print()" class="bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 flex items-center gap-2">
                <i class="fas fa-print"></i> Print
            </button>
            <button onclick="exportToPDF()" class="bg-red-600 text-white px-4 py-2 rounded-lg hover:bg-red-700 flex items-center gap-2">
                <i class="fas fa-file-pdf"></i> Export PDF
            </button>
        </div>
    </div>

    <!-- Period Selector -->
    <div class="bg-white rounded-lg shadow p-4 mb-6 no-print">
        <form method="GET" class="flex items-end gap-4">
            <div class="flex-1">
                <label class="block text-sm font-medium text-gray-700 mb-1">Time Period</label>
                <select name="period" id="periodSelect" onchange="toggleCustomDates()" class="w-full px-3 py-2 border border-gray-300 rounded-md">
                    <option value="today" <?php echo $period == 'today' ? 'selected' : ''; ?>>Today</option>
                    <option value="yesterday" <?php echo $period == 'yesterday' ? 'selected' : ''; ?>>Yesterday</option>
                    <option value="last_7_days" <?php echo $period == 'last_7_days' ? 'selected' : ''; ?>>Last 7 Days</option>
                    <option value="last_30_days" <?php echo $period == 'last_30_days' ? 'selected' : ''; ?>>Last 30 Days</option>
                    <option value="last_90_days" <?php echo $period == 'last_90_days' ? 'selected' : ''; ?>>Last 90 Days</option>
                    <option value="this_month" <?php echo $period == 'this_month' ? 'selected' : ''; ?>>This Month</option>
                    <option value="last_month" <?php echo $period == 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                    <option value="this_year" <?php echo $period == 'this_year' ? 'selected' : ''; ?>>This Year</option>
                    <option value="custom" <?php echo $period == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                </select>
            </div>
            <div id="customDates" style="<?php echo $period == 'custom' ? '' : 'display:none;'; ?>" class="flex gap-2">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">From</label>
                    <input type="date" name="custom_from" value="<?php echo $custom_from; ?>" class="px-3 py-2 border border-gray-300 rounded-md">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">To</label>
                    <input type="date" name="custom_to" value="<?php echo $custom_to; ?>" class="px-3 py-2 border border-gray-300 rounded-md">
                </div>
            </div>
            <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700">
                <i class="fas fa-sync-alt mr-2"></i>Update
            </button>
        </form>
    </div>

    <!-- AI Warnings & Alerts -->
    <?php if (!empty($warnings)): ?>
    <div class="mb-6 space-y-3">
        <?php foreach ($warnings as $warning): ?>
        <div class="insight-card bg-<?php echo $warning['color']; ?>-50 border-<?php echo $warning['color']; ?>-500 rounded-lg p-4">
            <div class="flex items-start gap-3">
                <div class="bg-<?php echo $warning['color']; ?>-100 rounded-full p-3">
                    <i class="fas <?php echo $warning['icon']; ?> text-<?php echo $warning['color']; ?>-600 text-xl"></i>
                </div>
                <div class="flex-1">
                    <h3 class="font-bold text-<?php echo $warning['color']; ?>-900 mb-1"><?php echo $warning['title']; ?></h3>
                    <p class="text-<?php echo $warning['color']; ?>-700 text-sm"><?php echo $warning['message']; ?></p>
                </div>
                <span class="px-3 py-1 bg-<?php echo $warning['color']; ?>-200 text-<?php echo $warning['color']; ?>-800 rounded-full text-xs font-semibold uppercase">
                    <?php echo $warning['severity']; ?> Priority
                </span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Executive KPIs -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <!-- Total Purchase Value -->
        <div class="stat-card bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between mb-4">
                <div class="bg-white bg-opacity-20 rounded-full p-3">
                    <i class="fas fa-shopping-cart text-2xl"></i>
                </div>
                <div class="text-right">
                    <p class="text-sm opacity-80">Total Orders</p>
                    <p class="text-3xl font-bold"><?php echo number_format($kpis->total_orders); ?></p>
                </div>
            </div>
            <div class="text-right">
                <p class="text-2xl font-bold">৳<?php echo number_format($kpis->total_order_value / 1000000, 1); ?>M</p>
                <p class="text-xs opacity-80">Order Value</p>
            </div>
        </div>

        <!-- Fulfillment Rate -->
        <div class="stat-card bg-gradient-to-br from-green-500 to-green-600 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between mb-4">
                <div class="bg-white bg-opacity-20 rounded-full p-3">
                    <i class="fas fa-truck text-2xl"></i>
                </div>
                <div class="text-right">
                    <p class="text-sm opacity-80">Received</p>
                    <p class="text-3xl font-bold"><?php echo number_format($kpis->total_received_qty / 1000, 0); ?>T</p>
                </div>
            </div>
            <div class="text-right">
                <p class="text-2xl font-bold"><?php echo number_format($kpis->fulfillment_rate, 1); ?>%</p>
                <p class="text-xs opacity-80">Fulfillment Rate</p>
            </div>
        </div>

        <!-- Payment Performance -->
        <div class="stat-card bg-gradient-to-br from-purple-500 to-purple-600 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between mb-4">
                <div class="bg-white bg-opacity-20 rounded-full p-3">
                    <i class="fas fa-money-check-alt text-2xl"></i>
                </div>
                <div class="text-right">
                    <p class="text-sm opacity-80">Total Paid</p>
                    <p class="text-3xl font-bold">৳<?php echo number_format($kpis->total_paid / 1000000, 1); ?>M</p>
                </div>
            </div>
            <div class="text-right">
                <p class="text-2xl font-bold"><?php echo number_format($kpis->payment_rate, 1); ?>%</p>
                <p class="text-xs opacity-80">Payment Rate</p>
            </div>
        </div>

        <!-- Outstanding Balance -->
        <div class="stat-card bg-gradient-to-br from-orange-500 to-orange-600 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between mb-4">
                <div class="bg-white bg-opacity-20 rounded-full p-3">
                    <i class="fas fa-exclamation-triangle text-2xl"></i>
                </div>
                <div class="text-right">
                    <p class="text-sm opacity-80">Outstanding</p>
                    <p class="text-3xl font-bold">৳<?php echo number_format($kpis->total_outstanding / 1000000, 1); ?>M</p>
                </div>
            </div>
            <div class="text-right">
                <p class="text-2xl font-bold"><?php echo $kpis->active_suppliers; ?></p>
                <p class="text-xs opacity-80">Active Suppliers</p>
            </div>
        </div>
    </div>

    <!-- AI Insights & Recommendations -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Positive Insights -->
        <div class="bg-white rounded-lg shadow">
            <div class="bg-green-600 text-white px-6 py-4 rounded-t-lg">
                <h3 class="font-semibold flex items-center gap-2">
                    <i class="fas fa-lightbulb"></i> AI Insights
                </h3>
            </div>
            <div class="p-6 space-y-3">
                <?php if (empty($insights)): ?>
                <p class="text-gray-500 text-center py-4">
                    <i class="fas fa-info-circle text-gray-300 text-3xl mb-2"></i><br>
                    No insights available for this period
                </p>
                <?php else: ?>
                    <?php foreach ($insights as $insight): ?>
                    <div class="flex items-start gap-3 p-3 bg-<?php echo $insight['color']; ?>-50 rounded-lg">
                        <i class="fas <?php echo $insight['icon']; ?> text-<?php echo $insight['color']; ?>-600 mt-1"></i>
                        <div>
                            <h4 class="font-semibold text-gray-900"><?php echo $insight['title']; ?></h4>
                            <p class="text-sm text-gray-600"><?php echo $insight['message']; ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Strategic Recommendations -->
        <div class="bg-white rounded-lg shadow">
            <div class="bg-blue-600 text-white px-6 py-4 rounded-t-lg">
                <h3 class="font-semibold flex items-center gap-2">
                    <i class="fas fa-robot"></i> AI Recommendations
                </h3>
            </div>
            <div class="p-6 space-y-3">
                <?php if (empty($recommendations)): ?>
                <p class="text-gray-500 text-center py-4">
                    <i class="fas fa-check-circle text-gray-300 text-3xl mb-2"></i><br>
                    All operations are optimal
                </p>
                <?php else: ?>
                    <?php foreach ($recommendations as $rec): ?>
                    <div class="p-4 bg-blue-50 rounded-lg border-l-4 border-blue-600">
                        <div class="flex items-start gap-3">
                            <i class="fas <?php echo $rec['icon']; ?> text-blue-600 mt-1"></i>
                            <div class="flex-1">
                                <h4 class="font-semibold text-gray-900"><?php echo $rec['title']; ?></h4>
                                <p class="text-sm text-gray-600 mb-2"><?php echo $rec['message']; ?></p>
                                <?php if ($rec['potential_saving'] > 0): ?>
                                <div class="text-xs text-green-600 font-semibold">
                                    💰 Potential Savings: ৳<?php echo number_format($rec['potential_saving'], 0); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Price Trend Chart -->
        <div class="bg-white rounded-lg shadow">
            <div class="bg-gray-600 text-white px-6 py-4 rounded-t-lg">
                <h3 class="font-semibold flex items-center gap-2">
                    <i class="fas fa-chart-line"></i> Price Trend Analysis (12 Months)
                </h3>
            </div>
            <div class="p-6">
                <div class="chart-container">
                    <canvas id="priceTrendChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Wheat Origin Distribution -->
        <div class="bg-white rounded-lg shadow">
            <div class="bg-gray-600 text-white px-6 py-4 rounded-t-lg">
                <h3 class="font-semibold flex items-center gap-2">
                    <i class="fas fa-pie-chart"></i> Procurement by Origin
                </h3>
            </div>
            <div class="p-6">
                <div class="chart-container">
                    <canvas id="originChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Supplier Performance Table -->
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="bg-indigo-600 text-white px-6 py-4 rounded-t-lg">
            <h3 class="font-semibold flex items-center gap-2">
                <i class="fas fa-users"></i> Top Supplier Performance
            </h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Supplier</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Orders</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total Value</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Avg Price</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Avg Delivery</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Variance %</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Outstanding</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Rating</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($supplier_performance as $supplier): 
                        // Calculate performance score
                        $delivery_score = max(0, 100 - ($supplier->avg_delivery_days ?? 7) * 5);
                        $variance_score = max(0, 100 - ($supplier->avg_variance_percent ?? 5) * 10);
                        $overall_score = ($delivery_score + $variance_score) / 2;
                        
                        if ($overall_score >= 80) {
                            $rating_color = 'green';
                            $rating_text = 'Excellent';
                        } elseif ($overall_score >= 60) {
                            $rating_color = 'blue';
                            $rating_text = 'Good';
                        } elseif ($overall_score >= 40) {
                            $rating_color = 'yellow';
                            $rating_text = 'Average';
                        } else {
                            $rating_color = 'red';
                            $rating_text = 'Poor';
                        }
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap font-medium text-gray-900">
                            <?php echo htmlspecialchars($supplier->company_name); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            <?php echo number_format($supplier->order_count); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-semibold">
                            ৳<?php echo number_format($supplier->total_value / 1000000, 2); ?>M
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            ৳<?php echo number_format($supplier->avg_price, 2); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            <?php echo number_format($supplier->avg_delivery_days ?? 0, 0); ?> days
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            <span class="<?php echo ($supplier->avg_variance_percent ?? 0) > 5 ? 'text-red-600' : 'text-green-600'; ?> font-semibold">
                                <?php echo number_format($supplier->avg_variance_percent ?? 0, 1); ?>%
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            <span class="<?php echo $supplier->outstanding_balance > 0 ? 'text-red-600' : 'text-gray-600'; ?>">
                                ৳<?php echo number_format($supplier->outstanding_balance, 0); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <span class="px-3 py-1 bg-<?php echo $rating_color; ?>-100 text-<?php echo $rating_color; ?>-800 rounded-full text-xs font-semibold">
                                <?php echo $rating_text; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Variance & Quality Metrics -->
    <?php if ($variance_analysis && $variance_analysis->total_grns > 0): ?>
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="bg-yellow-600 text-white px-6 py-4 rounded-t-lg">
            <h3 class="font-semibold flex items-center gap-2">
                <i class="fas fa-balance-scale"></i> Variance & Quality Analysis
            </h3>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                <div class="text-center">
                    <div class="text-4xl font-bold text-gray-900">
                        <?php echo number_format($variance_analysis->total_grns); ?>
                    </div>
                    <div class="text-sm text-gray-600 mt-1">Total GRNs</div>
                </div>
                <div class="text-center">
                    <div class="text-4xl font-bold text-blue-600">
                        <?php echo number_format($variance_analysis->avg_variance_percent, 1); ?>%
                    </div>
                    <div class="text-sm text-gray-600 mt-1">Avg Variance</div>
                </div>
                <div class="text-center">
                    <div class="text-4xl font-bold text-red-600">
                        <?php echo number_format($variance_analysis->shortage_count); ?>
                    </div>
                    <div class="text-sm text-gray-600 mt-1">Shortages</div>
                </div>
                <div class="text-center">
                    <div class="text-4xl font-bold text-green-600">
                        <?php echo number_format($variance_analysis->excess_count); ?>
                    </div>
                    <div class="text-sm text-gray-600 mt-1">Excess Deliveries</div>
                </div>
            </div>
            
            <?php if ($variance_analysis->high_variance_count > 0): ?>
            <div class="mt-4 p-4 bg-red-50 border-l-4 border-red-500 rounded">
                <p class="text-sm text-red-800">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong>Alert:</strong> <?php echo $variance_analysis->high_variance_count; ?> GRNs had variance exceeding 5%. 
                    Review supplier quality controls.
                </p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
function toggleCustomDates() {
    const select = document.getElementById('periodSelect');
    const customDates = document.getElementById('customDates');
    customDates.style.display = select.value === 'custom' ? 'flex' : 'none';
}

// Price Trend Chart
<?php if (!empty($price_trends)): ?>
const priceTrendCtx = document.getElementById('priceTrendChart').getContext('2d');

// Group data by origin
const origins = [...new Set(<?php echo json_encode(array_column($price_trends, 'wheat_origin')); ?>)];
const months = [...new Set(<?php echo json_encode(array_column($price_trends, 'month')); ?>)];

const datasets = origins.map((origin, index) => {
    const colors = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6'];
    const data = months.map(month => {
        const point = <?php echo json_encode($price_trends); ?>.find(p => p.month === month && p.wheat_origin === origin);
        return point ? parseFloat(point.avg_price) : null;
    });
    
    return {
        label: origin,
        data: data,
        borderColor: colors[index % colors.length],
        backgroundColor: colors[index % colors.length] + '20',
        tension: 0.4,
        fill: true
    };
});

new Chart(priceTrendCtx, {
    type: 'line',
    data: {
        labels: months,
        datasets: datasets
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'top',
            },
            title: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: false,
                ticks: {
                    callback: function(value) {
                        return '৳' + value.toFixed(2);
                    }
                }
            }
        }
    }
});
<?php endif; ?>

// Wheat Origin Pie Chart
<?php if (!empty($wheat_origins)): ?>
const originCtx = document.getElementById('originChart').getContext('2d');
new Chart(originCtx, {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode(array_column($wheat_origins, 'wheat_origin')); ?>,
        datasets: [{
            data: <?php echo json_encode(array_column($wheat_origins, 'total_quantity')); ?>,
            backgroundColor: [
                '#3B82F6',
                '#10B981',
                '#F59E0B',
                '#EF4444',
                '#8B5CF6',
                '#EC4899',
                '#14B8A6'
            ]
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'right',
            }
        }
    }
});
<?php endif; ?>

function exportToPDF() {
    window.print();
}
</script>

<?php require_once '../templates/footer.php'; ?>