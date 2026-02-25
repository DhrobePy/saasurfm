<?php
/**
 * Variance Report - GRN Expected vs Received Analysis
 * Comprehensive variance analysis with filtering and pagination
 * 
 * @package Ujjal Flour Mills
 * @subpackage Purchase (Adnan) Module
 */

require_once '../core/init.php';

// Restrict access
restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$currentUser = getCurrentUser();
$pageTitle = "Variance Report - Expected vs Received";

$db = Database::getInstance()->getPdo();

// ===============================================
// PAGINATION SETUP
// ===============================================
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$items_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
$offset = ($page - 1) * $items_per_page;

// ===============================================
// FILTER PARAMETERS
// ===============================================
$filters = [
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'supplier_id' => $_GET['supplier_id'] ?? '',
    'wheat_origin' => $_GET['wheat_origin'] ?? '',
    'variance_type' => $_GET['variance_type'] ?? '', // all, shortage, excess, exact
    'variance_threshold' => $_GET['variance_threshold'] ?? '', // percentage threshold
    'po_number' => $_GET['po_number'] ?? '',
    'grn_number' => $_GET['grn_number'] ?? '',
    'unload_point' => $_GET['unload_point'] ?? ''
];

// Build WHERE clause
$where_conditions = ["grn.grn_status != 'cancelled'"];
$params = [];

if ($filters['date_from']) {
    $where_conditions[] = "grn.grn_date >= ?";
    $params[] = $filters['date_from'];
}
if ($filters['date_to']) {
    $where_conditions[] = "grn.grn_date <= ?";
    $params[] = $filters['date_to'];
}
if ($filters['supplier_id']) {
    $where_conditions[] = "grn.supplier_id = ?";
    $params[] = $filters['supplier_id'];
}
if ($filters['wheat_origin']) {
    $where_conditions[] = "po.wheat_origin = ?";
    $params[] = $filters['wheat_origin'];
}
if ($filters['po_number']) {
    $where_conditions[] = "po.po_number LIKE ?";
    $params[] = '%' . $filters['po_number'] . '%';
}
if ($filters['grn_number']) {
    $where_conditions[] = "grn.grn_number LIKE ?";
    $params[] = '%' . $filters['grn_number'] . '%';
}
if ($filters['unload_point']) {
    $where_conditions[] = "grn.unload_point_name = ?";
    $params[] = $filters['unload_point'];
}

$where_clause = implode(' AND ', $where_conditions);

// ===============================================
// COUNT TOTAL RECORDS
// ===============================================
$count_sql = "SELECT COUNT(*) as total
              FROM goods_received_adnan grn
              INNER JOIN purchase_orders_adnan po ON grn.purchase_order_id = po.id
              WHERE $where_clause";

$count_stmt = $db->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetch(PDO::FETCH_OBJ)->total;
$total_pages = ceil($total_records / $items_per_page);

// ===============================================
// GET PAGINATED VARIANCE DATA
// ===============================================
$sql = "SELECT 
            grn.*,
            po.po_number,
            po.unit_price_per_kg,
            po.wheat_origin,
            s.company_name as supplier_name,
            (grn.quantity_received_kg - grn.expected_quantity) as variance_kg,
            CASE 
                WHEN grn.expected_quantity > 0 
                THEN ((grn.quantity_received_kg - grn.expected_quantity) / grn.expected_quantity * 100)
                ELSE 0 
            END as variance_percent,
            (grn.quantity_received_kg - grn.expected_quantity) * po.unit_price_per_kg as variance_value
        FROM goods_received_adnan grn
        INNER JOIN purchase_orders_adnan po ON grn.purchase_order_id = po.id
        LEFT JOIN suppliers s ON grn.supplier_id = s.id
        WHERE $where_clause
        HAVING 1=1";

// Apply variance type filter
if ($filters['variance_type'] === 'shortage') {
    $sql .= " AND variance_kg < 0";
} elseif ($filters['variance_type'] === 'excess') {
    $sql .= " AND variance_kg > 0";
} elseif ($filters['variance_type'] === 'exact') {
    $sql .= " AND variance_kg = 0";
}

// Apply variance threshold filter
if ($filters['variance_threshold']) {
    $threshold = (float)$filters['variance_threshold'];
    $sql .= " AND ABS(variance_percent) >= $threshold";
}

$sql .= " ORDER BY ABS(variance_percent) DESC, grn.grn_date DESC
          LIMIT " . (int)$items_per_page . " OFFSET " . (int)$offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$grns = $stmt->fetchAll(PDO::FETCH_OBJ);

// ===============================================
// CALCULATE STATISTICS (ALL FILTERED RECORDS)
// ===============================================
$stats_sql = "SELECT 
                COUNT(*) as total_grns,
                COALESCE(SUM(grn.expected_quantity), 0) as total_expected,
                COALESCE(SUM(grn.quantity_received_kg), 0) as total_received,
                COALESCE(SUM(grn.quantity_received_kg - grn.expected_quantity), 0) as total_variance_kg,
                COALESCE(SUM((grn.quantity_received_kg - grn.expected_quantity) * po.unit_price_per_kg), 0) as total_variance_value,
                SUM(CASE WHEN grn.quantity_received_kg < grn.expected_quantity THEN 1 ELSE 0 END) as shortage_count,
                SUM(CASE WHEN grn.quantity_received_kg > grn.expected_quantity THEN 1 ELSE 0 END) as excess_count,
                SUM(CASE WHEN grn.quantity_received_kg = grn.expected_quantity THEN 1 ELSE 0 END) as exact_count,
                SUM(CASE WHEN grn.quantity_received_kg < grn.expected_quantity THEN (grn.expected_quantity - grn.quantity_received_kg) ELSE 0 END) as total_shortage_kg,
                SUM(CASE WHEN grn.quantity_received_kg > grn.expected_quantity THEN (grn.quantity_received_kg - grn.expected_quantity) ELSE 0 END) as total_excess_kg,
                AVG(ABS((grn.quantity_received_kg - grn.expected_quantity) / NULLIF(grn.expected_quantity, 0) * 100)) as avg_variance_percent
              FROM goods_received_adnan grn
              INNER JOIN purchase_orders_adnan po ON grn.purchase_order_id = po.id
              WHERE $where_clause";

$stats_stmt = $db->prepare($stats_sql);
$stats_stmt->execute($params);
$stats = $stats_stmt->fetch(PDO::FETCH_OBJ);

// Get unique suppliers
$suppliers_sql = "SELECT DISTINCT s.id, s.company_name 
                  FROM suppliers s
                  INNER JOIN goods_received_adnan grn ON s.id = grn.supplier_id
                  WHERE grn.grn_status != 'cancelled'
                  ORDER BY s.company_name";
$suppliers = $db->query($suppliers_sql)->fetchAll(PDO::FETCH_OBJ);

// Get unique origins
$origins_sql = "SELECT DISTINCT po.wheat_origin 
                FROM goods_received_adnan grn
                INNER JOIN purchase_orders_adnan po ON grn.purchase_order_id = po.id
                WHERE grn.grn_status != 'cancelled'
                ORDER BY po.wheat_origin";
$origins = $db->query($origins_sql)->fetchAll(PDO::FETCH_OBJ);

// Get unique unload points
$unload_points_sql = "SELECT DISTINCT unload_point_name 
                      FROM goods_received_adnan 
                      WHERE grn_status != 'cancelled' AND unload_point_name IS NOT NULL
                      ORDER BY unload_point_name";
$unload_points = $db->query($unload_points_sql)->fetchAll(PDO::FETCH_OBJ);

// Check if any filter is active
$active_filters = array_filter($filters, function($value) {
    return $value !== '' && $value !== null;
});

// Helper function for pagination URL
function getPaginationUrl($page_num, $filters, $per_page) {
    $params = array_merge($filters, ['page' => $page_num, 'per_page' => $per_page]);
    $params = array_filter($params, function($value) {
        return $value !== '' && $value !== null;
    });
    return 'variance_report.php?' . http_build_query($params);
}

require_once '../templates/header.php';
?>

<style>
@media print {
    .no-print { display: none !important; }
}
.variance-positive { color: #059669; }
.variance-negative { color: #DC2626; }
.variance-high { background-color: #FEE2E2; }
.variance-medium { background-color: #FEF3C7; }
.variance-low { background-color: #D1FAE5; }
</style>

<div class="container mx-auto px-4 py-6">
    
    <!-- Page Header -->
    <div class="flex justify-between items-center mb-6 no-print">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-balance-scale text-purple-600"></i> Variance Report
            </h1>
            <p class="text-gray-600 mt-1">
                Expected vs Received Quantity Analysis
                <span class="text-sm">
                    (Showing <?php echo number_format($offset + 1); ?>-<?php echo number_format(min($offset + $items_per_page, $total_records)); ?> 
                    of <?php echo number_format($total_records); ?>)
                </span>
            </p>
        </div>
        <div class="flex gap-2">
            <a href="purchase_adnan_index.php" class="border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 flex items-center gap-2">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <button onclick="window.print()" class="bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 flex items-center gap-2">
                <i class="fas fa-print"></i> Print
            </button>
            <button onclick="exportToExcel()" class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 flex items-center gap-2">
                <i class="fas fa-file-excel"></i> Export
            </button>
        </div>
    </div>

    <?php echo display_message(); ?>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        
        <!-- Total GRNs -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600 font-medium">Total GRNs</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1"><?php echo number_format($stats->total_grns); ?></p>
                    <div class="mt-2 text-xs space-y-1">
                        <div class="flex items-center gap-2">
                            <span class="text-red-600">↓ <?php echo $stats->shortage_count; ?> Shortage</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-green-600">↑ <?php echo $stats->excess_count; ?> Excess</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-blue-600">= <?php echo $stats->exact_count; ?> Exact</span>
                        </div>
                    </div>
                </div>
                <div class="bg-purple-100 rounded-full p-3">
                    <i class="fas fa-clipboard-check text-purple-600 text-2xl"></i>
                </div>
            </div>
        </div>

        <!-- Average Variance -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600 font-medium">Avg Variance</p>
                    <p class="text-3xl font-bold text-orange-600 mt-1">
                        <?php echo number_format($stats->avg_variance_percent ?? 0, 2); ?>%
                    </p>
                    <p class="text-xs text-gray-500 mt-1">Average deviation</p>
                </div>
                <div class="bg-orange-100 rounded-full p-3">
                    <i class="fas fa-percentage text-orange-600 text-2xl"></i>
                </div>
            </div>
        </div>

        <!-- Total Shortage -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600 font-medium">Total Shortage</p>
                    <p class="text-3xl font-bold text-red-600 mt-1">
                        <?php echo number_format($stats->total_shortage_kg / 1000, 1); ?>T
                    </p>
                    <p class="text-xs text-gray-500 mt-1">
                        <?php echo number_format($stats->total_shortage_kg, 0); ?> KG
                    </p>
                </div>
                <div class="bg-red-100 rounded-full p-3">
                    <i class="fas fa-arrow-down text-red-600 text-2xl"></i>
                </div>
            </div>
        </div>

        <!-- Total Excess -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600 font-medium">Total Excess</p>
                    <p class="text-3xl font-bold text-green-600 mt-1">
                        <?php echo number_format($stats->total_excess_kg / 1000, 1); ?>T
                    </p>
                    <p class="text-xs text-gray-500 mt-1">
                        <?php echo number_format($stats->total_excess_kg, 0); ?> KG
                    </p>
                </div>
                <div class="bg-green-100 rounded-full p-3">
                    <i class="fas fa-arrow-up text-green-600 text-2xl"></i>
                </div>
            </div>
        </div>

    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow p-6 mb-6 no-print">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900">
                <i class="fas fa-filter text-blue-600"></i> Filters
            </h3>
            <div class="flex items-center gap-4">
                <!-- Items per page -->
                <div class="flex items-center gap-2">
                    <label class="text-sm text-gray-600">Show:</label>
                    <select onchange="window.location.href='variance_report.php?per_page=' + this.value + '<?php 
                        foreach($filters as $key => $value) {
                            if($value !== '' && $value !== null) {
                                echo '&' . urlencode($key) . '=' . urlencode($value);
                            }
                        }
                    ?>'" 
                            class="px-3 py-1 border border-gray-300 rounded-md text-sm">
                        <option value="25" <?php echo $items_per_page == 25 ? 'selected' : ''; ?>>25</option>
                        <option value="50" <?php echo $items_per_page == 50 ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?php echo $items_per_page == 100 ? 'selected' : ''; ?>>100</option>
                        <option value="200" <?php echo $items_per_page == 200 ? 'selected' : ''; ?>>200</option>
                        <option value="500" <?php echo $items_per_page == 500 ? 'selected' : ''; ?>>500</option>
                    </select>
                    <span class="text-sm text-gray-600">per page</span>
                </div>
                
                <?php if (!empty($active_filters)): ?>
                <a href="variance_report.php?per_page=<?php echo $items_per_page; ?>" class="text-sm text-red-600 hover:text-red-800">
                    <i class="fas fa-times-circle"></i> Clear Filters
                </a>
                <?php endif; ?>
            </div>
        </div>

        <form method="GET" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4">
            
            <input type="hidden" name="per_page" value="<?php echo $items_per_page; ?>">
            
            <!-- Date From -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>

            <!-- Date To -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>

            <!-- Supplier -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Supplier</label>
                <select name="supplier_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">All Suppliers</option>
                    <?php foreach ($suppliers as $supplier): ?>
                    <option value="<?php echo $supplier->id; ?>" <?php echo $filters['supplier_id'] == $supplier->id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($supplier->company_name); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Wheat Origin -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Wheat Origin</label>
                <select name="wheat_origin" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">All Origins</option>
                    <?php foreach ($origins as $origin): ?>
                    <option value="<?php echo htmlspecialchars($origin->wheat_origin); ?>" <?php echo $filters['wheat_origin'] == $origin->wheat_origin ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($origin->wheat_origin); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Variance Type -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Variance Type</label>
                <select name="variance_type" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">All Types</option>
                    <option value="shortage" <?php echo $filters['variance_type'] == 'shortage' ? 'selected' : ''; ?>>Shortage (Less than expected)</option>
                    <option value="excess" <?php echo $filters['variance_type'] == 'excess' ? 'selected' : ''; ?>>Excess (More than expected)</option>
                    <option value="exact" <?php echo $filters['variance_type'] == 'exact' ? 'selected' : ''; ?>>Exact Match</option>
                </select>
            </div>

            <!-- Variance Threshold -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Min Variance %</label>
                <select name="variance_threshold" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">Any Variance</option>
                    <option value="1" <?php echo $filters['variance_threshold'] == '1' ? 'selected' : ''; ?>>≥ 1%</option>
                    <option value="2" <?php echo $filters['variance_threshold'] == '2' ? 'selected' : ''; ?>>≥ 2%</option>
                    <option value="5" <?php echo $filters['variance_threshold'] == '5' ? 'selected' : ''; ?>>≥ 5%</option>
                    <option value="10" <?php echo $filters['variance_threshold'] == '10' ? 'selected' : ''; ?>>≥ 10%</option>
                </select>
            </div>

            <!-- PO Number -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">PO Number</label>
                <input type="text" name="po_number" value="<?php echo htmlspecialchars($filters['po_number']); ?>" 
                       placeholder="Search PO..." 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>

            <!-- GRN Number -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">GRN Number</label>
                <input type="text" name="grn_number" value="<?php echo htmlspecialchars($filters['grn_number']); ?>" 
                       placeholder="Search GRN..." 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>

            <!-- Submit -->
            <div class="flex items-end md:col-span-3 lg:col-span-4 gap-2">
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700 transition">
                    <i class="fas fa-search mr-2"></i>Apply Filters
                </button>
                <a href="variance_report.php?per_page=<?php echo $items_per_page; ?>" class="bg-gray-200 text-gray-700 px-6 py-2 rounded-md hover:bg-gray-300 transition">
                    <i class="fas fa-redo mr-2"></i>Reset
                </a>
            </div>
        </form>
    </div>

    <!-- Variance Table -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200" id="varianceTable">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">GRN #</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">PO #</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Supplier</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Origin</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Expected (KG)</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Received (KG)</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Variance (KG)</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Variance %</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Value Impact</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider no-print">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($grns)): ?>
                    <tr>
                        <td colspan="11" class="px-6 py-8 text-center text-gray-500">
                            <i class="fas fa-inbox text-4xl text-gray-300 mb-2"></i>
                            <p class="text-lg">No variance records found</p>
                            <?php if (!empty($active_filters)): ?>
                            <a href="variance_report.php" class="text-blue-600 hover:text-blue-800 text-sm mt-2 inline-block">
                                <i class="fas fa-times-circle"></i> Clear filters to see all records
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($grns as $grn): 
                            $variance_percent = abs($grn->variance_percent);
                            $row_class = '';
                            if ($variance_percent >= 5) {
                                $row_class = 'variance-high';
                            } elseif ($variance_percent >= 2) {
                                $row_class = 'variance-medium';
                            } elseif ($variance_percent > 0) {
                                $row_class = 'variance-low';
                            }
                        ?>
                        <tr class="hover:bg-gray-50 <?php echo $row_class; ?>">
                            <!-- GRN Number -->
                            <td class="px-4 py-3 whitespace-nowrap">
                                <a href="purchase_adnan_grn_receipt.php?id=<?php echo $grn->id; ?>" 
                                   class="text-blue-600 hover:text-blue-800 font-medium text-sm">
                                    <?php echo htmlspecialchars($grn->grn_number); ?>
                                </a>
                            </td>

                            <!-- Date -->
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                <?php echo date('d M Y', strtotime($grn->grn_date)); ?>
                            </td>

                            <!-- PO Number -->
                            <td class="px-4 py-3 whitespace-nowrap text-sm">
                                <a href="purchase_adnan_view_po.php?id=<?php echo $grn->purchase_order_id; ?>" 
                                   class="text-purple-600 hover:text-purple-800">
                                    <?php echo htmlspecialchars($grn->po_number); ?>
                                </a>
                            </td>

                            <!-- Supplier -->
                            <td class="px-4 py-3 text-sm text-gray-900">
                                <?php echo htmlspecialchars($grn->supplier_name); ?>
                            </td>

                            <!-- Origin -->
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">
                                <?php echo htmlspecialchars($grn->wheat_origin); ?>
                            </td>

                            <!-- Expected -->
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right font-semibold text-gray-900">
                                <?php echo number_format($grn->expected_quantity, 2); ?>
                            </td>

                            <!-- Received -->
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right font-semibold text-gray-900">
                                <?php echo number_format($grn->quantity_received_kg, 2); ?>
                            </td>

                            <!-- Variance KG -->
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right font-bold <?php echo $grn->variance_kg < 0 ? 'variance-negative' : ($grn->variance_kg > 0 ? 'variance-positive' : 'text-gray-600'); ?>">
                                <?php 
                                $sign = $grn->variance_kg > 0 ? '+' : '';
                                echo $sign . number_format($grn->variance_kg, 2); 
                                ?>
                            </td>

                            <!-- Variance % -->
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right">
                                <span class="px-2 py-1 rounded-full text-xs font-bold <?php 
                                    if (abs($grn->variance_percent) >= 5) {
                                        echo 'bg-red-100 text-red-800';
                                    } elseif (abs($grn->variance_percent) >= 2) {
                                        echo 'bg-yellow-100 text-yellow-800';
                                    } elseif ($grn->variance_percent != 0) {
                                        echo 'bg-green-100 text-green-800';
                                    } else {
                                        echo 'bg-blue-100 text-blue-800';
                                    }
                                ?>">
                                    <?php 
                                    $sign = $grn->variance_percent > 0 ? '+' : '';
                                    echo $sign . number_format($grn->variance_percent, 2); 
                                    ?>%
                                </span>
                            </td>

                            <!-- Value Impact -->
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right font-semibold <?php echo $grn->variance_value < 0 ? 'variance-negative' : ($grn->variance_value > 0 ? 'variance-positive' : 'text-gray-600'); ?>">
                                <?php 
                                $sign = $grn->variance_value > 0 ? '+' : '';
                                echo '৳' . $sign . number_format(abs($grn->variance_value), 2); 
                                ?>
                            </td>

                            <!-- Actions -->
                            <td class="px-4 py-3 whitespace-nowrap text-center no-print">
                                <a href="purchase_adnan_grn_receipt.php?id=<?php echo $grn->id; ?>" 
                                   class="text-blue-600 hover:text-blue-800" 
                                   title="View GRN">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                        <!-- Totals Footer -->
                        <tr class="bg-gray-100 font-semibold border-t-2 border-gray-300">
                            <td colspan="5" class="px-4 py-3 text-sm text-gray-900">
                                <i class="fas fa-calculator mr-2"></i>PAGE TOTALS (<?php echo count($grns); ?> records)
                            </td>
                            <td class="px-4 py-3 text-sm text-right text-gray-900">
                                <?php 
                                $page_expected = array_sum(array_column($grns, 'expected_quantity'));
                                echo number_format($page_expected, 2); 
                                ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-right text-gray-900">
                                <?php 
                                $page_received = array_sum(array_column($grns, 'quantity_received_kg'));
                                echo number_format($page_received, 2); 
                                ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-right font-bold <?php 
                                $page_variance = $page_received - $page_expected;
                                echo $page_variance < 0 ? 'variance-negative' : ($page_variance > 0 ? 'variance-positive' : 'text-gray-600'); 
                            ?>">
                                <?php 
                                $sign = $page_variance > 0 ? '+' : '';
                                echo $sign . number_format($page_variance, 2); 
                                ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-right">
                                <?php 
                                $page_variance_pct = $page_expected > 0 ? ($page_variance / $page_expected * 100) : 0;
                                $sign = $page_variance_pct > 0 ? '+' : '';
                                echo $sign . number_format($page_variance_pct, 2) . '%'; 
                                ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-right font-semibold">
                                <?php 
                                $page_value = array_sum(array_column($grns, 'variance_value'));
                                $sign = $page_value > 0 ? '+' : '';
                                $value_class = $page_value < 0 ? 'variance-negative' : ($page_value > 0 ? 'variance-positive' : 'text-gray-600');
                                echo '<span class="' . $value_class . '">৳' . $sign . number_format(abs($page_value), 2) . '</span>'; 
                                ?>
                            </td>
                            <td class="no-print"></td>
                        </tr>

                        <!-- Grand Totals -->
                        <tr class="bg-blue-50 font-bold border-t-2 border-blue-300">
                            <td colspan="5" class="px-4 py-3 text-sm text-blue-900">
                                <i class="fas fa-chart-bar mr-2"></i>GRAND TOTAL (<?php echo number_format($stats->total_grns); ?> records)
                            </td>
                            <td class="px-4 py-3 text-sm text-right text-blue-900">
                                <?php echo number_format($stats->total_expected, 2); ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-right text-blue-900">
                                <?php echo number_format($stats->total_received, 2); ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-right font-bold <?php 
                                echo $stats->total_variance_kg < 0 ? 'variance-negative' : ($stats->total_variance_kg > 0 ? 'variance-positive' : 'text-gray-600'); 
                            ?>">
                                <?php 
                                $sign = $stats->total_variance_kg > 0 ? '+' : '';
                                echo $sign . number_format($stats->total_variance_kg, 2); 
                                ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-right">
                                <?php 
                                $grand_variance_pct = $stats->total_expected > 0 ? ($stats->total_variance_kg / $stats->total_expected * 100) : 0;
                                $sign = $grand_variance_pct > 0 ? '+' : '';
                                echo $sign . number_format($grand_variance_pct, 2) . '%'; 
                                ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-right font-bold <?php 
                                echo $stats->total_variance_value < 0 ? 'variance-negative' : ($stats->total_variance_value > 0 ? 'variance-positive' : 'text-gray-600'); 
                            ?>">
                                <?php 
                                $sign = $stats->total_variance_value > 0 ? '+' : '';
                                echo '৳' . $sign . number_format(abs($stats->total_variance_value), 2); 
                                ?>
                            </td>
                            <td class="no-print"></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="mt-6 flex items-center justify-between no-print">
        <div class="text-sm text-gray-600">
            Showing <?php echo number_format($offset + 1); ?> to <?php echo number_format(min($offset + $items_per_page, $total_records)); ?> 
            of <?php echo number_format($total_records); ?> results
        </div>
        
        <div class="flex items-center gap-2">
            <?php if ($page > 1): ?>
            <a href="<?php echo getPaginationUrl(1, $filters, $items_per_page); ?>" 
               class="px-3 py-2 border border-gray-300 rounded-md hover:bg-gray-50 transition">
                <i class="fas fa-angle-double-left"></i>
            </a>
            <a href="<?php echo getPaginationUrl($page - 1, $filters, $items_per_page); ?>" 
               class="px-3 py-2 border border-gray-300 rounded-md hover:bg-gray-50 transition">
                <i class="fas fa-angle-left"></i> Previous
            </a>
            <?php endif; ?>

            <?php
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
            
            for ($i = $start_page; $i <= $end_page; $i++):
            ?>
            <a href="<?php echo getPaginationUrl($i, $filters, $items_per_page); ?>" 
               class="px-3 py-2 border rounded-md transition <?php echo $i == $page ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 hover:bg-gray-50'; ?>">
                <?php echo $i; ?>
            </a>
            <?php endfor; ?>

            <?php if ($page < $total_pages): ?>
            <a href="<?php echo getPaginationUrl($page + 1, $filters, $items_per_page); ?>" 
               class="px-3 py-2 border border-gray-300 rounded-md hover:bg-gray-50 transition">
                Next <i class="fas fa-angle-right"></i>
            </a>
            <a href="<?php echo getPaginationUrl($total_pages, $filters, $items_per_page); ?>" 
               class="px-3 py-2 border border-gray-300 rounded-md hover:bg-gray-50 transition">
                <i class="fas fa-angle-double-right"></i>
            </a>
            <?php endif; ?>
        </div>

        <!-- Jump to Page -->
        <div class="flex items-center gap-2">
            <span class="text-sm text-gray-600">Go to:</span>
            <input type="number" 
                   min="1" 
                   max="<?php echo $total_pages; ?>" 
                   value="<?php echo $page; ?>" 
                   onchange="if(this.value >= 1 && this.value <= <?php echo $total_pages; ?>) { window.location.href='<?php echo str_replace('&page=' . $page, '', getPaginationUrl($page, $filters, $items_per_page)); ?>&page=' + this.value; }"
                   class="w-16 px-2 py-1 border border-gray-300 rounded-md text-sm">
            <span class="text-sm text-gray-600">of <?php echo number_format($total_pages); ?></span>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
function exportToExcel() {
    var table = document.getElementById('varianceTable');
    var clonedTable = table.cloneNode(true);
    
    // Remove "Actions" column
    var rows = clonedTable.getElementsByTagName('tr');
    for (var i = 0; i < rows.length; i++) {
        var cells = rows[i].getElementsByTagName('th').length > 0 
            ? rows[i].getElementsByTagName('th') 
            : rows[i].getElementsByTagName('td');
        if (cells.length > 0) {
            rows[i].removeChild(cells[cells.length - 1]);
        }
    }
    
    var html = clonedTable.outerHTML;
    var url = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
    var downloadLink = document.createElement("a");
    var filename = 'variance_report_' + new Date().toISOString().slice(0,10) + '.xls';
    downloadLink.href = url;
    downloadLink.download = filename;
    downloadLink.click();
}
</script>

<?php require_once '../templates/footer.php'; ?>