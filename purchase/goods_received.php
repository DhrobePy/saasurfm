<?php
/**
 * Goods Received Notes - Complete List with Pagination
 * Shows all GRN records with filtering, printing, editing, and deleting
 * 
 * @package Ujjal Flour Mills
 * @subpackage Purchase (Adnan) Module
 */

require_once '../core/init.php';
require_once '../core/classes/Goodsreceivedadnanmanager.php';

// Restrict access
restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$currentUser = getCurrentUser();
$user_role = $currentUser['role'] ?? '';
$is_superadmin = ($user_role === 'Superadmin');

$pageTitle = "Goods Received Notes";

// Initialize manager
$grn_manager = new GoodsReceivedAdnanManager();
$db = Database::getInstance()->getPdo();

// ===============================================
// PAGINATION SETUP
// ===============================================
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$items_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
$offset = ($page - 1) * $items_per_page;

// Get filter parameters
$filters = [
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'supplier_id' => $_GET['supplier_id'] ?? '',
    'wheat_origin' => $_GET['wheat_origin'] ?? '',
    'grn_status' => $_GET['grn_status'] ?? '',
    'truck_number' => $_GET['truck_number'] ?? '',
    'unload_point' => $_GET['unload_point'] ?? '',
    'search' => $_GET['search'] ?? ''
];

// Build WHERE clause and params
$where_conditions = ["1=1"];
$params = [];

// Apply filters
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
if ($filters['grn_status']) {
    $where_conditions[] = "grn.grn_status = ?";
    $params[] = $filters['grn_status'];
}
if ($filters['truck_number']) {
    $where_conditions[] = "grn.truck_number LIKE ?";
    $params[] = '%' . $filters['truck_number'] . '%';
}
if ($filters['unload_point']) {
    $where_conditions[] = "grn.unload_point_name = ?";
    $params[] = $filters['unload_point'];
}
if ($filters['search']) {
    $where_conditions[] = "(grn.grn_number LIKE ? OR po.po_number LIKE ? OR grn.truck_number LIKE ?)";
    $search_term = '%' . $filters['search'] . '%';
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$where_clause = implode(' AND ', $where_conditions);

// ===============================================
// COUNT TOTAL RECORDS (for pagination)
// ===============================================
$count_sql = "SELECT COUNT(*) as total
              FROM goods_received_adnan grn
              LEFT JOIN purchase_orders_adnan po ON grn.purchase_order_id = po.id
              WHERE $where_clause";

$count_stmt = $db->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetch(PDO::FETCH_OBJ)->total;
$total_pages = ceil($total_records / $items_per_page);

// ===============================================
// GET PAGINATED RECORDS
// ===============================================
$sql = "SELECT 
            grn.*,
            po.po_number,
            po.supplier_name,
            po.wheat_origin,
            po.unit_price_per_kg,
            u.display_name as receiver_name,
            b.name as branch_name
        FROM goods_received_adnan grn
        LEFT JOIN purchase_orders_adnan po ON grn.purchase_order_id = po.id
        LEFT JOIN users u ON grn.receiver_user_id = u.id
        LEFT JOIN branches b ON grn.unload_point_branch_id = b.id
        WHERE $where_clause
        ORDER BY grn.grn_date DESC, grn.created_at DESC
        LIMIT " . (int)$items_per_page . " OFFSET " . (int)$offset;

// Don't add LIMIT/OFFSET to params array - they're in the SQL now

$stmt = $db->prepare($sql);
$stmt->execute($params);
$grns = $stmt->fetchAll(PDO::FETCH_OBJ);



// ===============================================
// CALCULATE STATISTICS FOR ALL FILTERED RECORDS (not just current page)
// ===============================================
$stats_sql = "SELECT 
                COUNT(*) as total_grns,
                COALESCE(SUM(grn.expected_quantity), 0) as total_expected_qty,
                COALESCE(SUM(grn.quantity_received_kg), 0) as total_received_qty,
                COALESCE(SUM(grn.total_value), 0) as total_value,
                SUM(CASE WHEN grn.grn_status = 'posted' THEN 1 ELSE 0 END) as posted_count,
                SUM(CASE WHEN grn.grn_status = 'draft' THEN 1 ELSE 0 END) as draft_count,
                SUM(CASE WHEN grn.grn_status = 'verified' THEN 1 ELSE 0 END) as verified_count
              FROM goods_received_adnan grn
              LEFT JOIN purchase_orders_adnan po ON grn.purchase_order_id = po.id
              WHERE $where_clause";

// Remove the LIMIT/OFFSET params for stats query
$stats_params = array_slice($params, 0, -2);
$stats_stmt = $db->prepare($stats_sql);
$stats_stmt->execute($stats_params);
$stats = $stats_stmt->fetch(PDO::FETCH_OBJ);

$stats->total_variance_qty = $stats->total_expected_qty - $stats->total_received_qty;

// Get unique suppliers for filter
$suppliers_sql = "SELECT DISTINCT s.id, s.company_name 
                  FROM suppliers s 
                  INNER JOIN goods_received_adnan grn ON grn.supplier_id = s.id 
                  ORDER BY s.company_name";
$suppliers = $db->query($suppliers_sql)->fetchAll(PDO::FETCH_OBJ);

// Get unique wheat origins
$origins_sql = "SELECT DISTINCT po.wheat_origin 
                FROM purchase_orders_adnan po 
                INNER JOIN goods_received_adnan grn ON grn.purchase_order_id = po.id 
                WHERE po.wheat_origin IS NOT NULL 
                ORDER BY po.wheat_origin";
$wheat_origins = $db->query($origins_sql)->fetchAll(PDO::FETCH_OBJ);

// Get unique unload points
$unload_points_sql = "SELECT DISTINCT unload_point_name 
                      FROM goods_received_adnan 
                      WHERE unload_point_name IS NOT NULL 
                      ORDER BY unload_point_name";
$unload_points = $db->query($unload_points_sql)->fetchAll(PDO::FETCH_OBJ);

// Check if any filter is active
$active_filters = array_filter($filters, function($value) {
    return $value !== '' && $value !== null;
});

// Helper function to build pagination URL
function getPaginationUrl($page_num, $filters, $per_page) {
    $params = array_merge($filters, ['page' => $page_num, 'per_page' => $per_page]);
    $params = array_filter($params, function($value) {
        return $value !== '' && $value !== null;
    });
    return 'goods_received.php?' . http_build_query($params);
}

require_once '../templates/header.php';
?>

<style>
@media print {
    .no-print { display: none !important; }
    .print-full-width { width: 100% !important; max-width: none !important; }
}
</style>

<div class="container mx-auto px-4 py-6">
    
    <!-- Page Header -->
    <div class="flex justify-between items-center mb-6 no-print">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-truck-loading text-green-600"></i> Goods Received Notes
            </h1>
            <p class="text-gray-600 mt-1">
                Complete list of all GRN records 
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
            <a href="purchase_adnan_record_grn.php" class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 flex items-center gap-2">
                <i class="fas fa-plus"></i> New GRN
            </a>
            <button onclick="window.print()" class="bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 flex items-center gap-2">
                <i class="fas fa-print"></i> Print
            </button>
            <button onclick="exportToExcel()" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 flex items-center gap-2">
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
                </div>
                <div class="bg-blue-100 rounded-full p-3">
                    <i class="fas fa-receipt text-blue-600 text-2xl"></i>
                </div>
            </div>
            <div class="mt-2 text-xs text-gray-500">
                <span class="text-green-600 font-semibold"><?php echo $stats->posted_count; ?> Posted</span> • 
                <span class="text-yellow-600"><?php echo $stats->verified_count; ?> Verified</span> • 
                <span class="text-gray-600"><?php echo $stats->draft_count; ?> Draft</span>
            </div>
        </div>

        <!-- Total Expected Quantity -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600 font-medium">Expected Quantity</p>
                    <p class="text-3xl font-bold text-purple-600 mt-1"><?php echo number_format($stats->total_expected_qty, 0); ?> <span class="text-sm">KG</span></p>
                </div>
                <div class="bg-purple-100 rounded-full p-3">
                    <i class="fas fa-clipboard-list text-purple-600 text-2xl"></i>
                </div>
            </div>
        </div>

        <!-- Total Received Quantity -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600 font-medium">Received Quantity</p>
                    <p class="text-3xl font-bold text-green-600 mt-1"><?php echo number_format($stats->total_received_qty, 0); ?> <span class="text-sm">KG</span></p>
                </div>
                <div class="bg-green-100 rounded-full p-3">
                    <i class="fas fa-weight text-green-600 text-2xl"></i>
                </div>
            </div>
            <div class="mt-2 text-xs">
                <?php 
                $variance_color = $stats->total_variance_qty < 0 ? 'text-red-600' : 'text-green-600';
                $variance_sign = $stats->total_variance_qty > 0 ? '+' : '';
                ?>
                <span class="<?php echo $variance_color; ?> font-semibold">
                    Variance: <?php echo $variance_sign . number_format($stats->total_variance_qty, 0); ?> KG
                </span>
            </div>
        </div>

        <!-- Total Value -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600 font-medium">Total Value</p>
                    <p class="text-3xl font-bold text-orange-600 mt-1">৳<?php echo number_format($stats->total_value, 0); ?></p>
                </div>
                <div class="bg-orange-100 rounded-full p-3">
                    <i class="fas fa-money-bill-wave text-orange-600 text-2xl"></i>
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
                    <select onchange="window.location.href='<?php echo getPaginationUrl(1, $filters, ''); ?>' + this.value" 
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
                <a href="goods_received.php?per_page=<?php echo $items_per_page; ?>" class="text-sm text-red-600 hover:text-red-800">
                    <i class="fas fa-times-circle"></i> Clear All Filters
                </a>
                <?php endif; ?>
            </div>
        </div>

        <form method="GET" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4">
            
            <!-- Hidden field to preserve items per page -->
            <input type="hidden" name="per_page" value="<?php echo $items_per_page; ?>">
            
            <!-- Date From -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
            </div>

            <!-- Date To -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
            </div>

            <!-- Supplier -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Supplier</label>
                <select name="supplier_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
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
                <select name="wheat_origin" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Origins</option>
                    <?php foreach ($wheat_origins as $origin): ?>
                    <option value="<?php echo htmlspecialchars($origin->wheat_origin); ?>" 
                            <?php echo $filters['wheat_origin'] == $origin->wheat_origin ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($origin->wheat_origin); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Status -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select name="grn_status" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Statuses</option>
                    <option value="draft" <?php echo $filters['grn_status'] == 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="verified" <?php echo $filters['grn_status'] == 'verified' ? 'selected' : ''; ?>>Verified</option>
                    <option value="posted" <?php echo $filters['grn_status'] == 'posted' ? 'selected' : ''; ?>>Posted</option>
                    <option value="cancelled" <?php echo $filters['grn_status'] == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>

            <!-- Unload Point -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Unload Point</label>
                <select name="unload_point" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Locations</option>
                    <?php foreach ($unload_points as $point): ?>
                    <option value="<?php echo htmlspecialchars($point->unload_point_name); ?>" 
                            <?php echo $filters['unload_point'] == $point->unload_point_name ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($point->unload_point_name); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Truck Number -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Truck Number</label>
                <input type="text" name="truck_number" value="<?php echo htmlspecialchars($filters['truck_number']); ?>" 
                       placeholder="Search truck..." 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
            </div>

            <!-- Search -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($filters['search']); ?>" 
                       placeholder="GRN#, PO#, Truck#..." 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
            </div>

            <!-- Submit Button -->
            <div class="flex items-end md:col-span-3 lg:col-span-4 gap-2">
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700">
                    <i class="fas fa-search mr-2"></i>Apply Filters
                </button>
                <a href="goods_received.php?per_page=<?php echo $items_per_page; ?>" class="bg-gray-200 text-gray-700 px-6 py-2 rounded-md hover:bg-gray-300">
                    <i class="fas fa-redo mr-2"></i>Reset
                </a>
            </div>
        </form>
    </div>

    <!-- GRNs Table -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200" id="grnsTable">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">GRN #</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">PO #</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Supplier</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Origin</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Truck</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Expected</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Received</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Variance</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Value</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider no-print">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($grns)): ?>
                    <tr>
                        <td colspan="12" class="px-6 py-8 text-center text-gray-500">
                            <i class="fas fa-inbox text-4xl text-gray-300 mb-2"></i>
                            <p>No goods received notes found</p>
                            <?php if (!empty($active_filters)): ?>
                            <a href="goods_received.php" class="text-blue-600 hover:text-blue-800 text-sm">Clear filters</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($grns as $grn): 
                            $variance = ($grn->expected_quantity ?? 0) - $grn->quantity_received_kg;
                            $variance_percent = ($grn->expected_quantity > 0) ? ($variance / $grn->expected_quantity * 100) : 0;
                        ?>
                        <tr class="hover:bg-gray-50">
                            <!-- GRN Number -->
                            <td class="px-4 py-3 whitespace-nowrap">
                                <a href="purchase_adnan_grn_receipt.php?id=<?php echo $grn->id; ?>" 
                                   class="text-blue-600 hover:text-blue-800 font-medium">
                                    <?php echo htmlspecialchars($grn->grn_number); ?>
                                </a>
                            </td>

                            <!-- Date -->
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                <?php echo date('d M Y', strtotime($grn->grn_date)); ?>
                            </td>

                            <!-- PO Number -->
                            <td class="px-4 py-3 whitespace-nowrap">
                                <a href="purchase_adnan_view_po.php?id=<?php echo $grn->purchase_order_id; ?>" 
                                   class="text-purple-600 hover:text-purple-800 text-sm">
                                    <?php echo htmlspecialchars($grn->po_number); ?>
                                </a>
                            </td>

                            <!-- Supplier -->
                            <td class="px-4 py-3 text-sm text-gray-900">
                                <?php echo htmlspecialchars($grn->supplier_name); ?>
                            </td>

                            <!-- Wheat Origin -->
                            <td class="px-4 py-3 whitespace-nowrap text-sm">
                                <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded text-xs">
                                    <?php echo htmlspecialchars($grn->wheat_origin); ?>
                                </span>
                            </td>

                            <!-- Truck Number -->
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                <?php echo htmlspecialchars($grn->truck_number ?? 'N/A'); ?>
                            </td>

                            <!-- Expected Quantity -->
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right font-medium text-purple-600">
                                <?php echo number_format($grn->expected_quantity ?? 0, 2); ?>
                            </td>

                            <!-- Received Quantity -->
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right font-medium text-green-600">
                                <?php echo number_format($grn->quantity_received_kg, 2); ?>
                            </td>

                            <!-- Variance -->
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right">
                                <?php 
                                $variance_color = $variance < 0 ? 'text-red-600' : ($variance > 0 ? 'text-green-600' : 'text-gray-600');
                                $variance_sign = $variance > 0 ? '+' : '';
                                ?>
                                <span class="<?php echo $variance_color; ?> font-semibold">
                                    <?php echo $variance_sign . number_format($variance, 2); ?>
                                </span>
                                <br>
                                <span class="text-xs <?php echo $variance_color; ?>">
                                    (<?php echo $variance_sign . number_format($variance_percent, 1); ?>%)
                                </span>
                            </td>

                            <!-- Value -->
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-right font-bold text-gray-900">
                                ৳<?php echo number_format($grn->total_value, 2); ?>
                            </td>

                            <!-- Status -->
                            <td class="px-4 py-3 whitespace-nowrap text-center">
                                <?php
                                $status_colors = [
                                    'draft' => 'bg-gray-100 text-gray-800',
                                    'verified' => 'bg-yellow-100 text-yellow-800',
                                    'posted' => 'bg-green-100 text-green-800',
                                    'cancelled' => 'bg-red-100 text-red-800'
                                ];
                                ?>
                                <span class="px-2 py-1 rounded text-xs font-semibold <?php echo $status_colors[$grn->grn_status] ?? 'bg-gray-100 text-gray-800'; ?>">
                                    <?php echo strtoupper($grn->grn_status); ?>
                                </span>
                            </td>

                            <!-- Actions -->
                            <td class="px-4 py-3 whitespace-nowrap text-center no-print">
                                <div class="flex items-center justify-center gap-2">
                                    <!-- View -->
                                    <a href="purchase_adnan_grn_receipt.php?id=<?php echo $grn->id; ?>" 
                                       class="text-blue-600 hover:text-blue-800" 
                                       title="View Receipt">
                                        <i class="fas fa-eye"></i>
                                    </a>

                                    <!-- Print -->
                                    <a href="purchase_adnan_grn_receipt.php?id=<?php echo $grn->id; ?>&print=1" 
                                       target="_blank"
                                       class="text-gray-600 hover:text-gray-800" 
                                       title="Print">
                                        <i class="fas fa-print"></i>
                                    </a>

                                    <?php if ($is_superadmin && $grn->grn_status != 'cancelled'): ?>
                                    <!-- Edit -->
                                    <a href="purchase_adnan_edit_grn.php?id=<?php echo $grn->id; ?>" 
                                       class="text-orange-600 hover:text-orange-800" 
                                       title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>

                                    <!-- Delete -->
                                    <button onclick="deleteGRN(<?php echo $grn->id; ?>, '<?php echo htmlspecialchars($grn->grn_number); ?>')" 
                                            class="text-red-600 hover:text-red-800" 
                                            title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
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
            <!-- First Page -->
            <?php if ($page > 1): ?>
            <a href="<?php echo getPaginationUrl(1, $filters, $items_per_page); ?>" 
               class="px-3 py-2 border border-gray-300 rounded-md hover:bg-gray-50">
                <i class="fas fa-angle-double-left"></i>
            </a>
            <?php endif; ?>

            <!-- Previous Page -->
            <?php if ($page > 1): ?>
            <a href="<?php echo getPaginationUrl($page - 1, $filters, $items_per_page); ?>" 
               class="px-3 py-2 border border-gray-300 rounded-md hover:bg-gray-50">
                <i class="fas fa-angle-left"></i> Previous
            </a>
            <?php endif; ?>

            <!-- Page Numbers -->
            <?php
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
            
            for ($i = $start_page; $i <= $end_page; $i++):
            ?>
            <a href="<?php echo getPaginationUrl($i, $filters, $items_per_page); ?>" 
               class="px-3 py-2 border rounded-md <?php echo $i == $page ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 hover:bg-gray-50'; ?>">
                <?php echo $i; ?>
            </a>
            <?php endfor; ?>

            <!-- Next Page -->
            <?php if ($page < $total_pages): ?>
            <a href="<?php echo getPaginationUrl($page + 1, $filters, $items_per_page); ?>" 
               class="px-3 py-2 border border-gray-300 rounded-md hover:bg-gray-50">
                Next <i class="fas fa-angle-right"></i>
            </a>
            <?php endif; ?>

            <!-- Last Page -->
            <?php if ($page < $total_pages): ?>
            <a href="<?php echo getPaginationUrl($total_pages, $filters, $items_per_page); ?>" 
               class="px-3 py-2 border border-gray-300 rounded-md hover:bg-gray-50">
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
                   onchange="window.location.href='<?php echo getPaginationUrl('', $filters, $items_per_page); ?>'.replace('page=', 'page=' + this.value)"
                   class="w-16 px-2 py-1 border border-gray-300 rounded-md text-sm">
            <span class="text-sm text-gray-600">of <?php echo number_format($total_pages); ?></span>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center no-print" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl p-6 max-w-md w-full mx-4">
        <h3 class="text-xl font-bold text-gray-900 mb-4">
            <i class="fas fa-exclamation-triangle text-red-600 mr-2"></i>Delete GRN
        </h3>
        
        <p class="text-gray-600 mb-4">
            Are you sure you want to delete <strong id="deleteGRNNumber"></strong>?
        </p>

        <p class="text-sm text-red-600 mb-4">
            This will:
            <ul class="list-disc ml-5 mt-2">
                <li>Cancel the GRN</li>
                <li>Reverse the journal entry</li>
                <li>Recalculate PO totals</li>
            </ul>
        </p>

        <form method="POST" action="purchase_adnan_delete_grn.php" id="deleteForm">
            <input type="hidden" name="id" id="deleteGRNId">
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Reason for Deletion <span class="text-red-500">*</span>
                </label>
                <textarea name="reason" required rows="3"
                          placeholder="Please provide a reason for deleting this GRN..."
                          class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-red-500 focus:border-red-500"></textarea>
            </div>

            <div class="flex justify-end space-x-3">
                <button type="button" onclick="hideDeleteModal()"
                        class="px-6 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                    Cancel
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">
                    <i class="fas fa-trash mr-2"></i>Delete GRN
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Delete GRN Function
function deleteGRN(grnId, grnNumber) {
    document.getElementById('deleteGRNId').value = grnId;
    document.getElementById('deleteGRNNumber').textContent = grnNumber;
    document.getElementById('deleteModal').classList.remove('hidden');
    document.getElementById('deleteModal').classList.add('flex');
}

function hideDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
    document.getElementById('deleteModal').classList.remove('flex');
    document.getElementById('deleteForm').reset();
}

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        hideDeleteModal();
    }
});

// Close modal on background click
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) {
        hideDeleteModal();
    }
});

// Export to Excel Function
function exportToExcel() {
    // Get table
    var table = document.getElementById('grnsTable');
    
    // Clone table to modify
    var clonedTable = table.cloneNode(true);
    
    // Remove "Actions" column (last column)
    var rows = clonedTable.getElementsByTagName('tr');
    for (var i = 0; i < rows.length; i++) {
        var cells = rows[i].getElementsByTagName('th').length > 0 
            ? rows[i].getElementsByTagName('th') 
            : rows[i].getElementsByTagName('td');
        if (cells.length > 0) {
            rows[i].removeChild(cells[cells.length - 1]);
        }
    }
    
    // Convert to Excel
    var html = clonedTable.outerHTML;
    var url = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
    var downloadLink = document.createElement("a");
    var filename = 'goods_received_notes_' + new Date().toISOString().slice(0,10) + '.xls';
    downloadLink.href = url;
    downloadLink.download = filename;
    downloadLink.click();
}
</script>

<?php require_once '../templates/footer.php'; ?>