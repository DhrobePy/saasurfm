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
AND po.po_status != 'cancelled'
AND po.delivery_status NOT IN ('closed','completed')";

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
AND po.delivery_status NOT IN ('closed','completed')
GROUP BY s.id, s.company_name
ORDER BY total_value DESC
LIMIT 10";

$stmt = $db->prepare($supplier_perf_sql);
$stmt->execute([$date_from, $date_to]);
$supplier_performance = $stmt->fetchAll(PDO::FETCH_OBJ);


// ============================================================
// CARD REPORT: ORIGIN OVERVIEW  (date range only, no sub-filters)
// ============================================================
$card_grn_subq = "(SELECT purchase_order_id, SUM(expected_quantity) AS total_expected
                   FROM goods_received_adnan WHERE grn_status!='cancelled'
                   GROUP BY purchase_order_id) cg";

$card_origin_stmt = $db->prepare("
    SELECT
        COALESCE(po.wheat_origin,'Unknown')                                                                  AS wheat_origin,
        COUNT(DISTINCT po.id)                                                                                AS po_count,
        COALESCE(SUM(po.quantity_kg),0)                                                                      AS ordered_kg,
        COALESCE(SUM(COALESCE(po.total_received_qty,0)),0)                                                   AS received_kg,
        COALESCE(SUM(GREATEST(COALESCE(po.quantity_kg,0)-COALESCE(po.total_received_qty,0),0)),0)             AS pending_kg,
        COALESCE(SUM(
            COALESCE(cg.total_expected,0)*po.unit_price_per_kg
            +COALESCE(po.total_adjustment_amount,0)
            -COALESCE(po.total_paid,0)
        ),0)                                                                                                  AS total_payable,
        COALESCE(SUM(po.total_order_value),0)                                                                AS total_value,
        COALESCE(SUM(COALESCE(po.total_paid,0)),0)                                                           AS total_paid
    FROM purchase_orders_adnan po
    LEFT JOIN {$card_grn_subq} ON cg.purchase_order_id = po.id
    WHERE po.po_status != 'cancelled' AND po.delivery_status NOT IN ('closed','completed') AND po.po_date BETWEEN ? AND ?
    GROUP BY po.wheat_origin
    ORDER BY ordered_kg DESC
");
$card_origin_stmt->execute([$date_from, $date_to]);
$card_origins = $card_origin_stmt->fetchAll(PDO::FETCH_OBJ);

// Overall card totals
$card_total_ordered  = array_sum(array_column($card_origins, 'ordered_kg'));
$card_total_received = array_sum(array_column($card_origins, 'received_kg'));
$card_total_pending  = array_sum(array_column($card_origins, 'pending_kg'));
$card_total_payable  = array_sum(array_column($card_origins, 'total_payable'));

// CARD REPORT: BRANCH RECEIPTS  (by GRN date in period)
// Normalize branch names in SQL — production DB has both Bengali and English variants
// of the same branch (e.g. 'ডেমরা' and 'Demra', 'সিরাজগঞ্জ' and 'Sirajgonj').
$card_branch_stmt = $db->prepare("
    SELECT
        CASE
            WHEN grn.unload_point_name IN ('ডেমরা','Demra','demra')           THEN 'ডেমরা'
            WHEN grn.unload_point_name IN ('সিরাজগঞ্জ','Sirajgonj','Sirajganj','sirajgonj') THEN 'সিরাজগঞ্জ'
            WHEN grn.unload_point_name IN ('রামপুরা','Rampura','rampura')     THEN 'রামপুরা'
            WHEN grn.unload_point_name IN ('Head Office','head office')        THEN 'Head Office'
            ELSE COALESCE(grn.unload_point_name,'Unknown')
        END                                                                     AS branch,
        COALESCE(po.wheat_origin,'Unknown')                                     AS wheat_origin,
        COUNT(DISTINCT grn.id)                                                  AS grn_count,
        COALESCE(SUM(grn.expected_quantity),0)                                  AS expected_qty,
        COALESCE(SUM(grn.quantity_received_kg),0)                               AS received_qty
    FROM goods_received_adnan grn
    JOIN purchase_orders_adnan po ON grn.purchase_order_id = po.id
    WHERE grn.grn_status != 'cancelled'
    AND grn.grn_date BETWEEN ? AND ?
    GROUP BY branch, po.wheat_origin
    ORDER BY expected_qty DESC
");
$card_branch_stmt->execute([$date_from, $date_to]);
$card_branch_raw = $card_branch_stmt->fetchAll(PDO::FETCH_OBJ);

// Pivot branch data (branch key is now the canonical Bengali/standard value)
$card_branch_map  = [];
$card_branch_name = ['ডেমরা'=>'Demra','সিরাজগঞ্জ'=>'Sirajganj','রামপুরা'=>'Rampura','Head Office'=>'Head Office','Other'=>'Other'];
foreach ($card_branch_raw as $br) {
    $b = $br->branch;
    if (!isset($card_branch_map[$b])) {
        $card_branch_map[$b] = ['label'=>$card_branch_name[$b] ?? $b, 'expected'=>0, 'received'=>0, 'grns'=>0, 'origins'=>[]];
    }
    $card_branch_map[$b]['expected']    += $br->expected_qty;
    $card_branch_map[$b]['received']    += $br->received_qty;
    $card_branch_map[$b]['grns']        += $br->grn_count;
    $card_branch_map[$b]['origins'][]    = ['name'=>$br->wheat_origin, 'qty'=>$br->expected_qty, 'recv'=>$br->received_qty];
}
uasort($card_branch_map, fn($a,$b) => $b['expected'] - $a['expected']);

// Origin colour palette
$origin_palette = [
    'কানাডা'    => ['border'=>'border-red-500',   'bg'=>'bg-red-50',    'badge'=>'bg-red-100 text-red-800',    'bar'=>'bg-red-400'],
    'রাশিয়া'  => ['border'=>'border-blue-500',  'bg'=>'bg-blue-50',   'badge'=>'bg-blue-100 text-blue-800',   'bar'=>'bg-blue-400'],
    'Argentina' => ['border'=>'border-sky-500',   'bg'=>'bg-sky-50',    'badge'=>'bg-sky-100 text-sky-800',     'bar'=>'bg-sky-400'],
    'Brazil'    => ['border'=>'border-green-500', 'bg'=>'bg-green-50',  'badge'=>'bg-green-100 text-green-800', 'bar'=>'bg-green-400'],
    'Australia' => ['border'=>'border-yellow-500','bg'=>'bg-yellow-50', 'badge'=>'bg-yellow-100 text-yellow-800','bar'=>'bg-yellow-400'],
    'Ukraine'   => ['border'=>'border-amber-500', 'bg'=>'bg-amber-50',  'badge'=>'bg-amber-100 text-amber-800', 'bar'=>'bg-amber-400'],
    'India'     => ['border'=>'border-orange-500','bg'=>'bg-orange-50', 'badge'=>'bg-orange-100 text-orange-800','bar'=>'bg-orange-400'],
    'USA'       => ['border'=>'border-indigo-500','bg'=>'bg-indigo-50', 'badge'=>'bg-indigo-100 text-indigo-800','bar'=>'bg-indigo-400'],
    'Local'     => ['border'=>'border-teal-500',  'bg'=>'bg-teal-50',   'badge'=>'bg-teal-100 text-teal-800',   'bar'=>'bg-teal-400'],
];
$palette_default = ['border'=>'border-gray-400','bg'=>'bg-gray-50','badge'=>'bg-gray-100 text-gray-700','bar'=>'bg-gray-400'];

// ============================================================
// ORIGIN-BASED PROCUREMENT REPORT QUERIES
// ============================================================
$or_filter_origin   = $_GET['origin_filter']    ?? '';
$or_filter_supplier = $_GET['origin_supplier']  ?? '';
$or_filter_delstatus = $_GET['origin_del_status'] ?? '';

$or_params = [$date_from, $date_to];
$or_extra  = '';
if ($or_filter_origin)   { $or_extra .= " AND po.wheat_origin = ?";    $or_params[] = $or_filter_origin; }
if ($or_filter_supplier) { $or_extra .= " AND po.supplier_id = ?";     $or_params[] = $or_filter_supplier; }
if ($or_filter_delstatus){ $or_extra .= " AND po.delivery_status = ?"; $or_params[] = $or_filter_delstatus; }

$or_where = "po.po_status != 'cancelled' AND po.delivery_status NOT IN ('closed','completed') AND po.po_date BETWEEN ? AND ?{$or_extra}";

$grn_subq = "(SELECT purchase_order_id, SUM(expected_quantity) AS total_expected
               FROM goods_received_adnan WHERE grn_status != 'cancelled'
               GROUP BY purchase_order_id) grn_agg";

// Summary per origin
$or_sum_stmt = $db->prepare("
    SELECT
        COALESCE(po.wheat_origin,'Unknown')                                                           AS wheat_origin,
        COUNT(DISTINCT po.id)                                                                         AS po_count,
        COALESCE(SUM(po.quantity_kg), 0)                                                              AS ordered_kg,
        COALESCE(SUM(COALESCE(po.total_received_qty,0)), 0)                                           AS received_kg,
        COALESCE(SUM(GREATEST(COALESCE(po.quantity_kg,0)-COALESCE(po.total_received_qty,0),0)), 0)    AS pending_kg,
        COALESCE(SUM(po.total_order_value), 0)                                                        AS total_value,
        COALESCE(SUM(COALESCE(po.total_paid,0)), 0)                                                   AS total_paid,
        COALESCE(SUM(
            COALESCE(grn_agg.total_expected,0) * po.unit_price_per_kg
            + COALESCE(po.total_adjustment_amount,0)
            - COALESCE(po.total_paid,0)
        ), 0)                                                                                          AS total_payable,
        AVG(po.unit_price_per_kg)                                                                     AS avg_price
    FROM purchase_orders_adnan po
    LEFT JOIN {$grn_subq} ON grn_agg.purchase_order_id = po.id
    WHERE {$or_where}
    GROUP BY po.wheat_origin
    ORDER BY ordered_kg DESC
");
$or_sum_stmt->execute($or_params);
$or_summary = $or_sum_stmt->fetchAll(PDO::FETCH_OBJ);

// Per-PO detail
$or_det_stmt = $db->prepare("
    SELECT
        po.id,
        po.po_number,
        po.po_date,
        po.supplier_name,
        po.supplier_id,
        COALESCE(po.wheat_origin,'Unknown')                                                              AS wheat_origin,
        COALESCE(po.quantity_kg,0)                                                                       AS ordered_qty,
        po.unit_price_per_kg,
        COALESCE(po.total_order_value,0)                                                                 AS total_order_value,
        COALESCE(po.total_received_qty,0)                                                                AS received_qty,
        GREATEST(COALESCE(po.quantity_kg,0)-COALESCE(po.total_received_qty,0),0)                         AS pending_qty,
        COALESCE(po.total_paid,0)                                                                        AS total_paid,
        COALESCE(po.total_adjustment_amount,0)                                                           AS adjustment_amount,
        po.delivery_status,
        po.payment_status,
        COALESCE(grn_agg.total_expected,0)                                                               AS grn_expected_qty,
        (COALESCE(grn_agg.total_expected,0) * po.unit_price_per_kg
         + COALESCE(po.total_adjustment_amount,0)
         - COALESCE(po.total_paid,0))                                                                    AS computed_payable
    FROM purchase_orders_adnan po
    LEFT JOIN {$grn_subq} ON grn_agg.purchase_order_id = po.id
    WHERE {$or_where}
    ORDER BY po.wheat_origin, po.po_date DESC
");
$or_det_stmt->execute($or_params);
$or_detail = $or_det_stmt->fetchAll(PDO::FETCH_OBJ);

// Dropdown lists (full history, not date-filtered)
$or_origins_list   = $db->query("SELECT DISTINCT wheat_origin FROM purchase_orders_adnan WHERE po_status!='cancelled' AND wheat_origin IS NOT NULL AND wheat_origin!='' ORDER BY wheat_origin")->fetchAll(PDO::FETCH_COLUMN);
$or_suppliers_list = $db->query("SELECT supplier_id, MAX(supplier_name) AS supplier_name FROM purchase_orders_adnan WHERE po_status!='cancelled' GROUP BY supplier_id ORDER BY supplier_name")->fetchAll(PDO::FETCH_OBJ);

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
            <a href="#origin-report" class="bg-emerald-600 text-white px-4 py-2 rounded-lg hover:bg-emerald-700 flex items-center gap-2">
                <i class="fas fa-globe-asia"></i> Origin Report
            </a>
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

    <!-- Executive KPIs (open orders only: pending / partial / over_received) -->
    <div class="mb-2 flex items-center gap-2">
        <span class="inline-flex items-center gap-1 px-3 py-1 bg-blue-100 text-blue-700 text-xs font-semibold rounded-full">
            <i class="fas fa-filter"></i> Showing open orders only (pending / partial / over-received)
        </span>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <!-- Total Purchase Value -->
        <div class="stat-card bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between mb-4">
                <div class="bg-white bg-opacity-20 rounded-full p-3">
                    <i class="fas fa-shopping-cart text-2xl"></i>
                </div>
                <div class="text-right">
                    <p class="text-sm opacity-80">Open Orders</p>
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

    <!-- ══════════════════════════════════════════════════════════
         CARD SECTION A — SUMMARY TOTALS
         ══════════════════════════════════════════════════════════ -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <!-- Total Goods to be Received -->
        <div class="bg-white rounded-lg shadow border-l-4 border-orange-500 p-5 flex items-center gap-4">
            <div class="bg-orange-100 rounded-full p-4 flex-shrink-0">
                <i class="fas fa-truck-loading text-orange-600 text-2xl"></i>
            </div>
            <div>
                <p class="text-xs text-gray-500 uppercase tracking-wide">Goods to be Received</p>
                <p class="text-2xl font-bold text-gray-900"><?php echo number_format($card_total_pending / 1000, 2); ?> MT</p>
                <p class="text-xs text-gray-500 mt-0.5"><?php echo number_format($card_total_pending, 0); ?> KG pending across all origins</p>
            </div>
        </div>
        <!-- Total Ordered -->
        <div class="bg-white rounded-lg shadow border-l-4 border-blue-500 p-5 flex items-center gap-4">
            <div class="bg-blue-100 rounded-full p-4 flex-shrink-0">
                <i class="fas fa-file-invoice text-blue-600 text-2xl"></i>
            </div>
            <div>
                <p class="text-xs text-gray-500 uppercase tracking-wide">Total Ordered</p>
                <p class="text-2xl font-bold text-gray-900"><?php echo number_format($card_total_ordered / 1000, 2); ?> MT</p>
                <p class="text-xs text-gray-500 mt-0.5"><?php echo number_format($card_total_received / 1000, 2); ?> MT received
                    (<?php echo $card_total_ordered > 0 ? number_format($card_total_received / $card_total_ordered * 100, 1) : 0; ?>%)</p>
            </div>
        </div>
        <!-- Total Payable -->
        <div class="bg-white rounded-lg shadow border-l-4 border-red-500 p-5 flex items-center gap-4">
            <div class="bg-red-100 rounded-full p-4 flex-shrink-0">
                <i class="fas fa-money-bill-wave text-red-600 text-2xl"></i>
            </div>
            <div>
                <p class="text-xs text-gray-500 uppercase tracking-wide">Total Payable (POs)</p>
                <p class="text-2xl font-bold text-red-700">৳<?php echo number_format($card_total_payable / 1000000, 2); ?>M</p>
                <p class="text-xs text-gray-500 mt-0.5">৳<?php echo number_format($card_total_payable, 0); ?> outstanding</p>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         CARD SECTION B — PER-ORIGIN CARDS
         ══════════════════════════════════════════════════════════ -->
    <div class="mb-6">
        <h2 class="text-lg font-bold text-gray-800 mb-3 flex items-center gap-2">
            <i class="fas fa-globe-asia text-emerald-600"></i> Procurement by Origin
        </h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
        <?php foreach ($card_origins as $co):
            $pal      = $origin_palette[$co->wheat_origin] ?? $palette_default;
            $fulfil   = $co->ordered_kg > 0 ? ($co->received_kg / $co->ordered_kg * 100) : 0;
            $payable  = $co->total_payable;
        ?>
        <div class="bg-white rounded-lg shadow border-t-4 <?php echo $pal['border']; ?> <?php echo $pal['bg']; ?> p-5">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-bold text-gray-800 text-base leading-tight"><?php echo htmlspecialchars($co->wheat_origin); ?></h3>
                <span class="text-xs <?php echo $pal['badge']; ?> px-2 py-0.5 rounded-full font-medium"><?php echo $co->po_count; ?> PO<?php echo $co->po_count != 1 ? 's' : ''; ?></span>
            </div>

            <!-- Ordered -->
            <div class="mb-2">
                <div class="flex justify-between text-xs text-gray-500 mb-1">
                    <span>Ordered</span>
                    <span class="font-semibold text-gray-700"><?php echo number_format($co->ordered_kg / 1000, 2); ?> MT</span>
                </div>
            </div>

            <!-- Received + progress bar -->
            <div class="mb-3">
                <div class="flex justify-between text-xs text-gray-500 mb-1">
                    <span>Received</span>
                    <span class="font-semibold text-green-700"><?php echo number_format($co->received_kg / 1000, 2); ?> MT</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div class="<?php echo $pal['bar']; ?> h-2 rounded-full" style="width:<?php echo min(100, round($fulfil)); ?>%"></div>
                </div>
                <div class="text-right text-xs text-gray-400 mt-0.5"><?php echo number_format($fulfil, 0); ?>% fulfilled</div>
            </div>

            <!-- Pending -->
            <?php if ($co->pending_kg > 0): ?>
            <div class="flex justify-between text-xs mb-3 bg-orange-50 border border-orange-100 rounded px-2 py-1.5">
                <span class="text-orange-700 font-medium"><i class="fas fa-hourglass-half mr-1 text-xs"></i>Pending</span>
                <span class="font-bold text-orange-700"><?php echo number_format($co->pending_kg / 1000, 2); ?> MT</span>
            </div>
            <?php else: ?>
            <div class="flex justify-between text-xs mb-3 bg-green-50 border border-green-100 rounded px-2 py-1.5">
                <span class="text-green-700 font-medium"><i class="fas fa-check-circle mr-1 text-xs"></i>All Received</span>
            </div>
            <?php endif; ?>

            <!-- Payable -->
            <div class="border-t border-gray-200 pt-2 mt-1">
                <div class="flex justify-between items-center">
                    <span class="text-xs text-gray-500">Payable</span>
                    <span class="font-bold text-sm <?php echo $payable > 0.5 ? 'text-red-600' : ($payable < -0.5 ? 'text-blue-600' : 'text-gray-400'); ?>">
                        <?php
                        if ($payable > 0.5)       echo '৳' . number_format($payable, 0);
                        elseif ($payable < -0.5)  echo '(৳' . number_format(abs($payable), 0) . ')';
                        else                       echo '—';
                        ?>
                    </span>
                </div>
                <div class="flex justify-between items-center mt-1">
                    <span class="text-xs text-gray-400">Paid</span>
                    <span class="text-xs text-blue-600 font-medium">৳<?php echo number_format($co->total_paid, 0); ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         CARD SECTION C — BRANCH GOODS RECEIVED
         ══════════════════════════════════════════════════════════ -->
    <?php if (!empty($card_branch_map)): ?>
    <div class="mb-6">
        <h2 class="text-lg font-bold text-gray-800 mb-3 flex items-center gap-2">
            <i class="fas fa-warehouse text-indigo-600"></i> Goods Received by Branch
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        <?php
        $branch_colors = [
            'ডেমরা'      => ['header'=>'bg-indigo-700',  'bar'=>'bg-indigo-400', 'badge'=>'bg-indigo-100 text-indigo-800'],
            'সিরাজগঞ্জ' => ['header'=>'bg-purple-700',  'bar'=>'bg-purple-400', 'badge'=>'bg-purple-100 text-purple-800'],
            'রামপুরা'   => ['header'=>'bg-teal-700',    'bar'=>'bg-teal-400',   'badge'=>'bg-teal-100 text-teal-800'],
            'Head Office'=> ['header'=>'bg-gray-700',    'bar'=>'bg-gray-400',   'badge'=>'bg-gray-100 text-gray-700'],
        ];
        $bc_default = ['header'=>'bg-slate-600','bar'=>'bg-slate-400','badge'=>'bg-slate-100 text-slate-700'];

        foreach ($card_branch_map as $branch_key => $bdata):
            $bc = $branch_colors[$branch_key] ?? $bc_default;
            // Sort origins by qty desc
            usort($bdata['origins'], fn($a,$b) => $b['qty'] - $a['qty']);
            $branch_total = $bdata['expected'];
        ?>
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <!-- Branch header -->
            <div class="<?php echo $bc['header']; ?> text-white px-5 py-3 flex items-center justify-between">
                <div>
                    <h3 class="font-bold text-lg"><?php echo htmlspecialchars($bdata['label']); ?></h3>
                    <p class="text-xs opacity-80"><?php echo htmlspecialchars($branch_key); ?></p>
                </div>
                <div class="text-right">
                    <p class="text-2xl font-bold"><?php echo number_format($bdata['expected'] / 1000, 2); ?></p>
                    <p class="text-xs opacity-80">MT received</p>
                </div>
            </div>

            <!-- Branch stats row -->
            <div class="grid grid-cols-3 divide-x divide-gray-100 bg-gray-50 border-b border-gray-100">
                <div class="text-center py-2 px-1">
                    <p class="text-sm font-bold text-gray-800"><?php echo $bdata['grns']; ?></p>
                    <p class="text-xs text-gray-500">GRNs</p>
                </div>
                <div class="text-center py-2 px-1">
                    <p class="text-sm font-bold text-gray-800"><?php echo number_format($bdata['received'] / 1000, 2); ?></p>
                    <p class="text-xs text-gray-500">Actual MT</p>
                </div>
                <div class="text-center py-2 px-1">
                    <?php $var = $bdata['expected'] > 0 ? ($bdata['received'] - $bdata['expected']) / $bdata['expected'] * 100 : 0; ?>
                    <p class="text-sm font-bold <?php echo abs($var) < 1 ? 'text-gray-800' : ($var < 0 ? 'text-red-600' : 'text-green-600'); ?>">
                        <?php echo ($var >= 0 ? '+' : '') . number_format($var, 1); ?>%
                    </p>
                    <p class="text-xs text-gray-500">Variance</p>
                </div>
            </div>

            <!-- Origin breakdown -->
            <div class="p-4 space-y-2">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">By Origin</p>
                <?php foreach ($bdata['origins'] as $orig):
                    $pct = $branch_total > 0 ? ($orig['qty'] / $branch_total * 100) : 0;
                    $opal = $origin_palette[$orig['name']] ?? $palette_default;
                ?>
                <div>
                    <div class="flex items-center justify-between text-xs mb-0.5">
                        <span class="<?php echo $opal['badge']; ?> px-1.5 py-0.5 rounded text-xs font-medium truncate max-w-[120px]">
                            <?php echo htmlspecialchars($orig['name']); ?>
                        </span>
                        <span class="font-semibold text-gray-700 ml-2"><?php echo number_format($orig['qty'] / 1000, 2); ?> MT</span>
                        <span class="text-gray-400 ml-1 w-10 text-right"><?php echo number_format($pct, 0); ?>%</span>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-1.5">
                        <div class="<?php echo $opal['bar']; ?> h-1.5 rounded-full" style="width:<?php echo min(100, round($pct)); ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

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

    <!-- ============================================================
         ORIGIN-BASED PROCUREMENT REPORT
         ============================================================ -->
    <div class="bg-white rounded-lg shadow mb-6" id="origin-report">

        <!-- Header -->
        <div class="bg-emerald-700 text-white px-6 py-4 rounded-t-lg flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-semibold text-lg flex items-center gap-2">
                <i class="fas fa-globe-asia"></i> Origin-Based Procurement Report
            </h3>
            <span class="text-sm opacity-80">
                <?php echo date('d M Y', strtotime($date_from)); ?> – <?php echo date('d M Y', strtotime($date_to)); ?>
                <?php if ($or_filter_origin || $or_filter_supplier || $or_filter_delstatus): ?>
                <span class="ml-2 bg-white bg-opacity-20 px-2 py-0.5 rounded text-xs">filtered</span>
                <?php endif; ?>
            </span>
        </div>

        <!-- Filter Bar -->
        <div class="p-4 bg-gray-50 border-b">
            <form method="GET" id="originFilterForm" class="grid grid-cols-2 md:grid-cols-5 gap-3 items-end">
                <input type="hidden" name="period"      value="<?php echo htmlspecialchars($period); ?>">
                <input type="hidden" name="custom_from" value="<?php echo htmlspecialchars($custom_from); ?>">
                <input type="hidden" name="custom_to"   value="<?php echo htmlspecialchars($custom_to); ?>">

                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Origin</label>
                    <select name="origin_filter" class="w-full px-2 py-2 border border-gray-300 rounded text-sm">
                        <option value="">All Origins</option>
                        <?php foreach ($or_origins_list as $orig): ?>
                        <option value="<?php echo htmlspecialchars($orig); ?>" <?php echo $or_filter_origin === $orig ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($orig); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Supplier</label>
                    <select name="origin_supplier" class="w-full px-2 py-2 border border-gray-300 rounded text-sm">
                        <option value="">All Suppliers</option>
                        <?php foreach ($or_suppliers_list as $sup): ?>
                        <option value="<?php echo htmlspecialchars($sup->supplier_id); ?>" <?php echo $or_filter_supplier == $sup->supplier_id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($sup->supplier_name); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Delivery Status</label>
                    <select name="origin_del_status" class="w-full px-2 py-2 border border-gray-300 rounded text-sm">
                        <option value="">All</option>
                        <option value="pending"   <?php echo $or_filter_delstatus === 'pending'   ? 'selected' : ''; ?>>Pending</option>
                        <option value="partial"   <?php echo $or_filter_delstatus === 'partial'   ? 'selected' : ''; ?>>Partial</option>
                        <option value="completed" <?php echo $or_filter_delstatus === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="closed"    <?php echo $or_filter_delstatus === 'closed'    ? 'selected' : ''; ?>>Closed</option>
                    </select>
                </div>

                <div class="flex gap-2 items-end">
                    <button type="submit" class="flex-1 bg-emerald-600 text-white px-3 py-2 rounded text-sm hover:bg-emerald-700 flex items-center justify-center gap-1">
                        <i class="fas fa-filter"></i> Apply
                    </button>
                    <a href="?period=<?php echo htmlspecialchars($period); ?>&custom_from=<?php echo htmlspecialchars($custom_from); ?>&custom_to=<?php echo htmlspecialchars($custom_to); ?>#origin-report"
                       class="flex-1 text-center border border-gray-300 text-gray-600 px-3 py-2 rounded text-sm hover:bg-gray-100">
                        Reset
                    </a>
                </div>
            </form>
        </div>

        <?php if (!empty($or_summary)): ?>

        <!-- Origin Summary Table -->
        <div class="p-6">
            <h4 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-3 flex items-center gap-2">
                <i class="fas fa-chart-bar text-emerald-600"></i> Summary by Origin
            </h4>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-emerald-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Origin</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">POs</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Ordered (MT)</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Received (MT)</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Pending (MT)</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Fulfillment</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Avg ৳/KG</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Total Value</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Total Paid</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Payable</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                    <?php
                    $or_totals = ['po_count'=>0,'ordered_kg'=>0,'received_kg'=>0,'pending_kg'=>0,'total_value'=>0,'total_paid'=>0,'total_payable'=>0];
                    foreach ($or_summary as $os):
                        $or_totals['po_count']     += $os->po_count;
                        $or_totals['ordered_kg']   += $os->ordered_kg;
                        $or_totals['received_kg']  += $os->received_kg;
                        $or_totals['pending_kg']   += $os->pending_kg;
                        $or_totals['total_value']  += $os->total_value;
                        $or_totals['total_paid']   += $os->total_paid;
                        $or_totals['total_payable']+= $os->total_payable;
                        $fulfil = $os->ordered_kg > 0 ? ($os->received_kg / $os->ordered_kg * 100) : 0;
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-semibold">
                            <a href="?period=<?php echo htmlspecialchars($period); ?>&custom_from=<?php echo htmlspecialchars($custom_from); ?>&custom_to=<?php echo htmlspecialchars($custom_to); ?>&origin_filter=<?php echo urlencode($os->wheat_origin); ?>#origin-report"
                               class="text-emerald-700 hover:underline flex items-center gap-1">
                                <i class="fas fa-flag text-xs"></i> <?php echo htmlspecialchars($os->wheat_origin); ?>
                            </a>
                        </td>
                        <td class="px-4 py-3 text-right text-gray-700"><?php echo $os->po_count; ?></td>
                        <td class="px-4 py-3 text-right text-gray-700"><?php echo number_format($os->ordered_kg / 1000, 2); ?></td>
                        <td class="px-4 py-3 text-right font-semibold text-green-700"><?php echo number_format($os->received_kg / 1000, 2); ?></td>
                        <td class="px-4 py-3 text-right font-semibold <?php echo $os->pending_kg > 0 ? 'text-orange-600' : 'text-gray-400'; ?>">
                            <?php echo number_format($os->pending_kg / 1000, 2); ?>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2 justify-center">
                                <div class="w-20 bg-gray-200 rounded-full h-2 flex-shrink-0">
                                    <div class="bg-emerald-500 h-2 rounded-full" style="width:<?php echo min(100, round($fulfil)); ?>%"></div>
                                </div>
                                <span class="text-xs text-gray-600 w-9 text-left"><?php echo number_format($fulfil, 0); ?>%</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-right text-gray-600">৳<?php echo number_format($os->avg_price, 2); ?></td>
                        <td class="px-4 py-3 text-right text-gray-700">৳<?php echo number_format($os->total_value, 0); ?></td>
                        <td class="px-4 py-3 text-right text-blue-700">৳<?php echo number_format($os->total_paid, 0); ?></td>
                        <td class="px-4 py-3 text-right font-bold <?php echo $os->total_payable > 0.5 ? 'text-red-600' : ($os->total_payable < -0.5 ? 'text-blue-600' : 'text-gray-400'); ?>">
                            <?php
                            if ($os->total_payable > 0.5)       echo '৳' . number_format($os->total_payable, 0);
                            elseif ($os->total_payable < -0.5)  echo '(৳' . number_format(abs($os->total_payable), 0) . ')';
                            else                                 echo '—';
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="bg-emerald-50 font-bold border-t-2 border-emerald-300">
                            <td class="px-4 py-3 text-gray-800">TOTAL</td>
                            <td class="px-4 py-3 text-right"><?php echo $or_totals['po_count']; ?></td>
                            <td class="px-4 py-3 text-right"><?php echo number_format($or_totals['ordered_kg'] / 1000, 2); ?></td>
                            <td class="px-4 py-3 text-right text-green-700"><?php echo number_format($or_totals['received_kg'] / 1000, 2); ?></td>
                            <td class="px-4 py-3 text-right text-orange-600"><?php echo number_format($or_totals['pending_kg'] / 1000, 2); ?></td>
                            <td class="px-4 py-3"></td>
                            <td class="px-4 py-3"></td>
                            <td class="px-4 py-3 text-right">৳<?php echo number_format($or_totals['total_value'], 0); ?></td>
                            <td class="px-4 py-3 text-right text-blue-700">৳<?php echo number_format($or_totals['total_paid'], 0); ?></td>
                            <td class="px-4 py-3 text-right text-red-600">৳<?php echo number_format($or_totals['total_payable'], 0); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Per-PO Detail Table -->
        <div class="px-6 pb-6 border-t border-gray-100">
            <div class="flex flex-wrap items-center justify-between gap-3 py-4">
                <h4 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                    <i class="fas fa-list-alt text-emerald-600"></i>
                    Purchase Order Detail
                    <span class="ml-1 text-xs font-normal text-gray-400 normal-case"><?php echo count($or_detail); ?> orders</span>
                </h4>
                <div class="flex items-center gap-2">
                    <i class="fas fa-search text-gray-400 text-sm"></i>
                    <input type="text" id="orDetailSearch"
                           placeholder="Search PO#, supplier, origin…"
                           class="px-3 py-2 border border-gray-300 rounded-lg text-sm w-64 focus:outline-none focus:ring-2 focus:ring-emerald-400"
                           oninput="filterOrDetailTable(this.value)">
                    <span id="orMatchCount" class="text-xs text-gray-400 whitespace-nowrap"></span>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm" id="orDetailTable">
                    <thead>
                        <tr class="bg-gray-50 border-y border-gray-200">
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">PO #</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">Date</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">Supplier</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">Origin</th>
                            <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">Ordered KG</th>
                            <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">Received KG</th>
                            <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">Pending KG</th>
                            <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">৳/KG</th>
                            <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">Total Value</th>
                            <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">Paid</th>
                            <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">Payable</th>
                            <th class="px-3 py-3 text-center text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">Delivery</th>
                            <th class="px-3 py-3 text-center text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">Payment</th>
                        </tr>
                    </thead>
                    <tbody id="orDetailTbody">
                    <?php
                    $del_cls = ['pending'=>'bg-yellow-100 text-yellow-800','partial'=>'bg-blue-100 text-blue-800','completed'=>'bg-green-100 text-green-800','closed'=>'bg-gray-100 text-gray-700'];
                    $pay_cls = ['unpaid'=>'bg-red-100 text-red-800','partial'=>'bg-orange-100 text-orange-800','paid'=>'bg-green-100 text-green-800'];
                    $last_or = null;
                    foreach ($or_detail as $od):
                        // Insert origin group separator row
                        if ($od->wheat_origin !== $last_or):
                            $last_or = $od->wheat_origin;
                    ?>
                    <tr class="or-separator bg-emerald-50 border-t border-emerald-200" data-separator="1">
                        <td colspan="13" class="px-3 py-2 text-xs font-bold text-emerald-800 uppercase tracking-wide">
                            <i class="fas fa-flag mr-1"></i> <?php echo htmlspecialchars($od->wheat_origin); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr class="hover:bg-gray-50 border-b border-gray-100 or-detail-row"
                        data-search="<?php echo htmlspecialchars(strtolower($od->po_number . ' ' . $od->supplier_name . ' ' . $od->wheat_origin), ENT_QUOTES); ?>"
                        data-origin="<?php echo htmlspecialchars(strtolower($od->wheat_origin), ENT_QUOTES); ?>">
                        <td class="px-3 py-2.5 font-medium whitespace-nowrap">
                            <a href="purchase_adnan_view_po.php?id=<?php echo $od->id; ?>" class="text-blue-600 hover:underline">
                                <?php echo htmlspecialchars($od->po_number); ?>
                            </a>
                        </td>
                        <td class="px-3 py-2.5 text-gray-500 whitespace-nowrap"><?php echo date('d M Y', strtotime($od->po_date)); ?></td>
                        <td class="px-3 py-2.5 text-gray-700 max-w-xs truncate" title="<?php echo htmlspecialchars($od->supplier_name); ?>">
                            <?php echo htmlspecialchars($od->supplier_name); ?>
                        </td>
                        <td class="px-3 py-2.5 whitespace-nowrap">
                            <span class="px-2 py-0.5 bg-emerald-50 text-emerald-800 rounded text-xs font-medium">
                                <?php echo htmlspecialchars($od->wheat_origin); ?>
                            </span>
                        </td>
                        <td class="px-3 py-2.5 text-right font-mono text-gray-700"><?php echo number_format($od->ordered_qty, 0); ?></td>
                        <td class="px-3 py-2.5 text-right font-mono font-semibold text-green-700"><?php echo number_format($od->received_qty, 0); ?></td>
                        <td class="px-3 py-2.5 text-right font-mono font-semibold <?php echo $od->pending_qty > 0 ? 'text-orange-600' : 'text-gray-400'; ?>">
                            <?php echo $od->pending_qty > 0 ? number_format($od->pending_qty, 0) : '—'; ?>
                        </td>
                        <td class="px-3 py-2.5 text-right font-mono text-gray-600">৳<?php echo number_format($od->unit_price_per_kg, 2); ?></td>
                        <td class="px-3 py-2.5 text-right font-mono text-gray-700">৳<?php echo number_format($od->total_order_value, 0); ?></td>
                        <td class="px-3 py-2.5 text-right font-mono text-blue-700">৳<?php echo number_format($od->total_paid, 0); ?></td>
                        <td class="px-3 py-2.5 text-right font-mono font-bold
                            <?php echo $od->computed_payable > 0.5 ? 'text-red-600' : ($od->computed_payable < -0.5 ? 'text-blue-600' : 'text-gray-400'); ?>">
                            <?php
                            if ($od->computed_payable > 0.5)      echo '৳' . number_format($od->computed_payable, 0);
                            elseif ($od->computed_payable < -0.5) echo '(৳' . number_format(abs($od->computed_payable), 0) . ')';
                            else                                   echo '—';
                            ?>
                        </td>
                        <td class="px-3 py-2.5 text-center whitespace-nowrap">
                            <span class="px-2 py-0.5 rounded text-xs font-medium <?php echo $del_cls[$od->delivery_status] ?? 'bg-gray-100 text-gray-700'; ?>">
                                <?php echo ucfirst($od->delivery_status); ?>
                            </span>
                        </td>
                        <td class="px-3 py-2.5 text-center whitespace-nowrap">
                            <span class="px-2 py-0.5 rounded text-xs font-medium <?php echo $pay_cls[$od->payment_status] ?? 'bg-gray-100 text-gray-700'; ?>">
                                <?php echo ucfirst($od->payment_status); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (empty($or_detail)): ?>
            <div class="text-center py-10 text-gray-400">
                <i class="fas fa-search text-3xl mb-2"></i>
                <p class="text-sm">No purchase orders found for the selected filters.</p>
            </div>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <div class="text-center py-12 text-gray-400">
            <i class="fas fa-globe text-4xl mb-3"></i>
            <p class="text-sm">No procurement data for the selected period.</p>
        </div>
        <?php endif; ?>

    </div><!-- /origin-report -->

</div>

<script>
function toggleCustomDates() {
    const select = document.getElementById('periodSelect');
    const customDates = document.getElementById('customDates');
    customDates.style.display = select.value === 'custom' ? 'flex' : 'none';
}

function exportToPDF() {
    window.print();
}

// ── Origin Report: dynamic table search ──────────────────────────────────────
function filterOrDetailTable(query) {
    const q = query.toLowerCase().trim();
    const rows     = document.querySelectorAll('.or-detail-row');
    const sepRows  = document.querySelectorAll('.or-separator');
    let visible = 0;

    // First pass: show/hide data rows
    rows.forEach(row => {
        const match = !q || row.dataset.search.includes(q);
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });

    // Second pass: show separator only if at least one sibling row in its origin group is visible
    sepRows.forEach(sep => {
        if (!q) { sep.style.display = ''; return; }
        // Check next siblings until the next separator
        let next = sep.nextElementSibling;
        let anyVisible = false;
        while (next && !next.classList.contains('or-separator')) {
            if (next.style.display !== 'none') { anyVisible = true; break; }
            next = next.nextElementSibling;
        }
        sep.style.display = anyVisible ? '' : 'none';
    });

    const counter = document.getElementById('orMatchCount');
    if (counter) counter.textContent = q ? (visible + ' of ' + rows.length + ' shown') : '';
}

// Auto-scroll to origin report after filter apply
const orForm = document.getElementById('originFilterForm');
if (orForm) {
    orForm.addEventListener('submit', function() {
        sessionStorage.setItem('scrollToOriginReport', '1');
    });
}
if (sessionStorage.getItem('scrollToOriginReport') === '1') {
    sessionStorage.removeItem('scrollToOriginReport');
    const el = document.getElementById('origin-report');
    if (el) setTimeout(() => el.scrollIntoView({ behavior: 'smooth', block: 'start' }), 200);
}
</script>

<?php require_once '../templates/footer.php'; ?>