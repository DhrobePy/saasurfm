<?php
require_once '../core/init.php';

global $db;

restrict_access();

$pageTitle = "Purchase Orders";

$currentUser = getCurrentUser();
$user_role = $currentUser['role'] ?? '';
$user_branch_id = $currentUser['branch_id'] ?? null;

// Get filter parameters
$search = $_GET['search'] ?? '';
$supplier_id = $_GET['supplier_id'] ?? '';
$branch_id = $_GET['branch_id'] ?? '';
$status = $_GET['status'] ?? '';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'po.created_at';
$sort_order = $_GET['sort_order'] ?? 'DESC';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build WHERE clause
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(po.po_number LIKE :search 
                           OR s.company_name LIKE :search 
                           OR po.notes LIKE :search)";
    $params['search'] = "%{$search}%";
}

if (!empty($supplier_id)) {
    $where_conditions[] = "po.supplier_id = :supplier_id";
    $params['supplier_id'] = $supplier_id;
}

if (!empty($branch_id)) {
    $where_conditions[] = "po.branch_id = :branch_id";
    $params['branch_id'] = $branch_id;
}

if (!empty($status)) {
    $where_conditions[] = "po.status = :status";
    $params['status'] = $status;
}

if (!empty($from_date)) {
    $where_conditions[] = "po.po_date >= :from_date";
    $params['from_date'] = $from_date;
}

if (!empty($to_date)) {
    $where_conditions[] = "po.po_date <= :to_date";
    $params['to_date'] = $to_date;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Validate sort column
$allowed_sort_columns = ['po.po_number', 'po.po_date', 'po.status', 'po.total_amount', 'po.created_at', 's.company_name'];
if (!in_array($sort_by, $allowed_sort_columns)) {
    $sort_by = 'po.created_at';
}

$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

// Get total count
$count_sql = "
    SELECT COUNT(*) as total 
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    {$where_clause}
";
$db->query($count_sql, $params);
$total_result = $db->first();
$total_pos = $total_result->total ?? 0;
$total_pages = ceil($total_pos / $per_page);

// Get purchase orders
$pos_sql = "
    SELECT 
        po.*,
        s.company_name as supplier_name,
        s.supplier_type,
        b.name as branch_name,
        b.code as branch_code,
        u.display_name as created_by_name,
        approver.display_name as approved_by_name,
        COUNT(DISTINCT poi.id) as item_count,
        COALESCE(SUM(poi.quantity), 0) as total_quantity,
        COALESCE(SUM(poi.received_quantity), 0) as total_received
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN branches b ON po.branch_id = b.id
    LEFT JOIN users u ON po.created_by_user_id = u.id
    LEFT JOIN users approver ON po.approved_by_user_id = approver.id
    LEFT JOIN purchase_order_items poi ON po.id = poi.purchase_order_id
    {$where_clause}
    GROUP BY po.id, po.uuid, po.po_number, po.supplier_id, po.branch_id, po.po_date,
             po.expected_delivery_date, po.status, po.payment_terms, po.subtotal, po.tax_amount,
             po.discount_amount, po.shipping_cost, po.other_charges, po.total_amount,
             po.paid_amount, po.payment_status, po.notes, po.terms_conditions,
             po.approved_by_user_id, po.approved_at, po.created_by_user_id,
             po.created_at, po.updated_at,
             s.company_name, s.supplier_type, b.name, b.code, u.display_name, approver.display_name
    ORDER BY {$sort_by} {$sort_order}
    LIMIT {$per_page} OFFSET {$offset}
";

$db->query($pos_sql, $params);
$pos = $db->results();

// Get summary statistics
$stats_sql = "
    SELECT 
        COUNT(*) as total_count,
        SUM(CASE WHEN po.status = 'draft' THEN 1 ELSE 0 END) as draft_count,
        SUM(CASE WHEN po.status = 'pending_approval' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN po.status = 'approved' THEN 1 ELSE 0 END) as approved_count,
        SUM(CASE WHEN po.status = 'ordered' THEN 1 ELSE 0 END) as ordered_count,
        SUM(CASE WHEN po.status IN ('partially_received', 'received', 'closed') THEN 1 ELSE 0 END) as received_count,
        COALESCE(SUM(CASE WHEN po.status NOT IN ('draft', 'cancelled') THEN po.total_amount ELSE 0 END), 0) as total_value
    FROM purchase_orders po
";
$db->query($stats_sql);
$stats = $db->first();

// Get all suppliers for filter
$suppliers_sql = "SELECT id, company_name FROM suppliers WHERE status = 'active' ORDER BY company_name";
$db->query($suppliers_sql);
$suppliers = $db->results();

// Get all branches for filter
$branches_sql = "SELECT id, name FROM branches ORDER BY name";
$db->query($branches_sql);
$branches = $db->results();

require_once '../templates/header.php';
?>

<div class="container mx-auto">

```
<!-- Page Header -->
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">Purchase Orders</h1>
        <p class="mt-2 text-gray-600">Manage and track all purchase orders</p>
    </div>
    <a href="create_po.php" class="bg-primary-600 hover:bg-primary-700 text-white px-6 py-3 rounded-lg shadow-md flex items-center gap-2 transition">
        <i class="fas fa-plus-circle"></i>
        <span>Create Purchase Order</span>
    </a>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
    
    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600">Total POs</p>
                <p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($stats->total_count); ?></p>
                <p class="text-sm text-blue-600 mt-1">BDT <?php echo number_format($stats->total_value, 2); ?></p>
            </div>
            <div class="p-3 bg-blue-100 rounded-full">
                <i class="fas fa-file-invoice text-blue-600 text-2xl"></i>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600">Pending Approval</p>
                <p class="text-3xl font-bold text-orange-600 mt-2"><?php echo number_format($stats->pending_count); ?></p>
                <p class="text-sm text-gray-500 mt-1">Need review</p>
            </div>
            <div class="p-3 bg-orange-100 rounded-full">
                <i class="fas fa-clock text-orange-600 text-2xl"></i>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600">Ordered</p>
                <p class="text-3xl font-bold text-indigo-600 mt-2"><?php echo number_format($stats->ordered_count); ?></p>
                <p class="text-sm text-gray-500 mt-1">In process</p>
            </div>
            <div class="p-3 bg-indigo-100 rounded-full">
                <i class="fas fa-shipping-fast text-indigo-600 text-2xl"></i>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600">Received</p>
                <p class="text-3xl font-bold text-green-600 mt-2"><?php echo number_format($stats->received_count); ?></p>
                <p class="text-sm text-gray-500 mt-1">Completed</p>
            </div>
            <div class="p-3 bg-green-100 rounded-full">
                <i class="fas fa-check-circle text-green-600 text-2xl"></i>
            </div>
        </div>
    </div>

</div>

<!-- Filters and Search -->
<div class="bg-white rounded-lg shadow-md p-6 mb-6">
    <form method="GET" action="" class="space-y-4">
        
        <div class="grid grid-cols-1 md:grid-cols-6 gap-4">
            
            <!-- Search -->
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                <div class="relative">
                    <input type="text" 
                           name="search" 
                           value="<?php echo htmlspecialchars($search); ?>"
                           placeholder="Search by PO number, supplier..."
                           class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                    <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                </div>
            </div>

            <!-- Supplier -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Supplier</label>
                <select name="supplier_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                    <option value="">All Suppliers</option>
                    <?php foreach ($suppliers as $supplier): ?>
                    <option value="<?php echo $supplier->id; ?>" <?php echo $supplier_id == $supplier->id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($supplier->company_name); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Branch -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Branch</label>
                <select name="branch_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                    <option value="">All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                    <option value="<?php echo $branch->id; ?>" <?php echo $branch_id == $branch->id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($branch->name); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Status -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                <select name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                    <option value="">All Status</option>
                    <option value="draft" <?php echo $status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="pending_approval" <?php echo $status === 'pending_approval' ? 'selected' : ''; ?>>Pending Approval</option>
                    <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="ordered" <?php echo $status === 'ordered' ? 'selected' : ''; ?>>Ordered</option>
                    <option value="partially_received" <?php echo $status === 'partially_received' ? 'selected' : ''; ?>>Partially Received</option>
                    <option value="received" <?php echo $status === 'received' ? 'selected' : ''; ?>>Received</option>
                    <option value="closed" <?php echo $status === 'closed' ? 'selected' : ''; ?>>Closed</option>
                    <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>

            <!-- Date From -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">From Date</label>
                <input type="date" 
                       name="from_date" 
                       value="<?php echo htmlspecialchars($from_date); ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
            </div>

        </div>

        <div class="grid grid-cols-1 md:grid-cols-6 gap-4">
            <!-- Date To -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">To Date</label>
                <input type="date" 
                       name="to_date" 
                       value="<?php echo htmlspecialchars($to_date); ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
            </div>
        </div>

        <div class="flex items-center gap-4">
            <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-6 py-2 rounded-lg transition">
                <i class="fas fa-filter mr-2"></i>Apply Filters
            </button>
            <a href="purchase_orders.php" class="text-gray-600 hover:text-gray-800 px-4 py-2">
                <i class="fas fa-redo mr-2"></i>Reset
            </a>
            <div class="ml-auto text-sm text-gray-600">
                Showing <span class="font-semibold"><?php echo number_format(count($pos)); ?></span> of 
                <span class="font-semibold"><?php echo number_format($total_pos); ?></span> purchase orders
            </div>
        </div>
    </form>
</div>

<!-- Purchase Orders Table -->
<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <?php if (count($pos) > 0): ?>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">PO Number</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Supplier</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Branch</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Items</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total Amount</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($pos as $po): ?>
                <tr class="hover:bg-gray-50 transition">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <a href="view_po.php?id=<?php echo $po->id; ?>" class="text-sm font-semibold text-primary-600 hover:text-primary-800">
                            <?php echo htmlspecialchars($po->po_number); ?>
                        </a>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        <?php echo date('M d, Y', strtotime($po->po_date)); ?>
                        <?php if ($po->expected_delivery_date): ?>
                        <p class="text-xs text-gray-500">
                            <i class="fas fa-truck mr-1"></i>ETA: <?php echo date('M d', strtotime($po->expected_delivery_date)); ?>
                        </p>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <a href="view_supplier.php?id=<?php echo $po->supplier_id; ?>" class="text-sm font-medium text-gray-900 hover:text-primary-600">
                            <?php echo htmlspecialchars($po->supplier_name); ?>
                        </a>
                        <?php
                        $type_colors = [
                            'local' => 'text-green-600',
                            'international' => 'text-blue-600',
                            'both' => 'text-purple-600'
                        ];
                        $color = $type_colors[$po->supplier_type] ?? 'text-gray-600';
                        ?>
                        <p class="text-xs <?php echo $color; ?>">
                            <?php echo ucfirst($po->supplier_type); ?>
                        </p>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        <?php echo htmlspecialchars($po->branch_name); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-center">
                        <span class="text-sm font-medium text-gray-900"><?php echo number_format($po->item_count); ?></span>
                        <?php if ($po->total_received > 0): ?>
                        <p class="text-xs text-green-600">
                            <?php echo number_format(($po->total_received / $po->total_quantity) * 100, 0); ?>% received
                        </p>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right">
                        <p class="text-sm font-bold text-gray-900">BDT <?php echo number_format($po->total_amount, 2); ?></p>
                        <?php if ($po->paid_amount > 0): ?>
                        <p class="text-xs text-green-600">Paid: <?php echo number_format($po->paid_amount, 2); ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-center">
                        <?php
                        $status_colors = [
                            'draft' => 'bg-gray-100 text-gray-800',
                            'pending_approval' => 'bg-orange-100 text-orange-800',
                            'approved' => 'bg-blue-100 text-blue-800',
                            'ordered' => 'bg-indigo-100 text-indigo-800',
                            'partially_received' => 'bg-yellow-100 text-yellow-800',
                            'received' => 'bg-green-100 text-green-800',
                            'closed' => 'bg-gray-100 text-gray-800',
                            'cancelled' => 'bg-red-100 text-red-800'
                        ];
                        $color = $status_colors[$po->status] ?? 'bg-gray-100 text-gray-800';
                        ?>
                        <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $color; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $po->status)); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-center">
                        <div class="flex items-center justify-center gap-2">
                            <a href="view_po.php?id=<?php echo $po->id; ?>" 
                               class="text-blue-600 hover:text-blue-800" 
                               title="View Details">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php if ($po->status === 'draft'): ?>
                            <a href="create_po.php?id=<?php echo $po->id; ?>" 
                               class="text-orange-600 hover:text-orange-800" 
                               title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php endif; ?>
                            <?php if ($po->status === 'approved' || $po->status === 'ordered'): ?>
                            <a href="create_grn.php?po_id=<?php echo $po->id; ?>" 
                               class="text-green-600 hover:text-green-800" 
                               title="Receive Goods">
                                <i class="fas fa-truck-loading"></i>
                            </a>
                            <?php endif; ?>
                            <a href="print_po.php?id=<?php echo $po->id; ?>" 
                               class="text-gray-600 hover:text-gray-800" 
                               title="Print"
                               target="_blank">
                                <i class="fas fa-print"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="bg-gray-50 px-6 py-4 flex items-center justify-between border-t border-gray-200">
        <div class="text-sm text-gray-700">
            Showing page <span class="font-semibold"><?php echo $page; ?></span> of 
            <span class="font-semibold"><?php echo $total_pages; ?></span>
        </div>
        <div class="flex items-center gap-2">
            <?php if ($page > 1): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
               class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                <i class="fas fa-chevron-left mr-1"></i> Previous
            </a>
            <?php endif; ?>
            
            <?php
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
            
            for ($i = $start_page; $i <= $end_page; $i++):
            ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
               class="px-4 py-2 text-sm font-medium <?php echo $i === $page ? 'text-white bg-primary-600' : 'text-gray-700 bg-white hover:bg-gray-50'; ?> border border-gray-300 rounded-lg">
                <?php echo $i; ?>
            </a>
            <?php endfor; ?>
            
            <?php if ($page < $total_pages): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
               class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                Next <i class="fas fa-chevron-right ml-1"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="text-center py-12">
        <i class="fas fa-file-invoice text-gray-300 text-6xl mb-4"></i>
        <h3 class="text-xl font-semibold text-gray-900 mb-2">No purchase orders found</h3>
        <p class="text-gray-600 mb-6">
            <?php if (!empty($search) || !empty($supplier_id) || !empty($status)): ?>
                Try adjusting your filters or search terms.
            <?php else: ?>
                Get started by creating your first purchase order.
            <?php endif; ?>
        </p>
        <?php if (empty($search) && empty($supplier_id) && empty($status)): ?>
        <a href="create_po.php" class="inline-block bg-primary-600 hover:bg-primary-700 text-white px-6 py-3 rounded-lg">
            <i class="fas fa-plus-circle mr-2"></i>Create Your First PO
        </a>
        <?php else: ?>
        <a href="purchase_orders.php" class="inline-block text-primary-600 hover:text-primary-700 font-medium">
            <i class="fas fa-redo mr-2"></i>Clear Filters
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
```

</div>

<?php require_once '../templates/footer.php'; ?>