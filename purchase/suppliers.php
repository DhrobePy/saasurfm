<?php
require_once '../core/init.php';

global $db;

restrict_access();  // ensures user is logged in

$pageTitle = "All Suppliers";

$currentUser = getCurrentUser();
$user_role = $currentUser['role'] ?? '';
$user_branch_id = $currentUser['branch_id'] ?? null;

// Get filter parameters
$search = $_GET['search'] ?? '';
$supplier_type = $_GET['supplier_type'] ?? '';
$status = $_GET['status'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'company_name';
$sort_order = $_GET['sort_order'] ?? 'ASC';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build WHERE clause
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(s.company_name LIKE :search 
                           OR s.supplier_code LIKE :search 
                           OR s.contact_person LIKE :search 
                           OR s.phone LIKE :search 
                           OR s.email LIKE :search 
                           OR s.city LIKE :search)";
    $params['search'] = "%{$search}%";
}

if (!empty($supplier_type)) {
    $where_conditions[] = "s.supplier_type = :supplier_type";
    $params['supplier_type'] = $supplier_type;
}

if (!empty($status)) {
    $where_conditions[] = "s.status = :status";
    $params['status'] = $status;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Validate sort column
$allowed_sort_columns = ['company_name', 'supplier_code', 'supplier_type', 'current_balance', 'created_at'];
if (!in_array($sort_by, $allowed_sort_columns)) {
    $sort_by = 'company_name';
}

// Validate sort order
$sort_order = strtoupper($sort_order) === 'DESC' ? 'DESC' : 'ASC';

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM suppliers s {$where_clause}";
$db->query($count_sql, $params);
$total_result = $db->first();
$total_suppliers = $total_result->total ?? 0;
$total_pages = ceil($total_suppliers / $per_page);

// Get suppliers with aggregated data
$suppliers_sql = "
    SELECT 
        s.*,
        u.display_name as created_by_name,
        COUNT(DISTINCT po.id) as total_pos,
        COALESCE(SUM(CASE WHEN po.status NOT IN ('draft', 'cancelled') THEN po.total_amount ELSE 0 END), 0) as total_purchases,
        COUNT(DISTINCT pi.id) as total_invoices,
        COALESCE(SUM(CASE WHEN pi.payment_status IN ('unpaid', 'partially_paid') THEN pi.balance_due ELSE 0 END), 0) as outstanding_amount
    FROM suppliers s
    LEFT JOIN users u ON s.created_by_user_id = u.id
    LEFT JOIN purchase_orders po ON s.id = po.supplier_id
    LEFT JOIN purchase_invoices pi ON s.id = pi.supplier_id AND pi.status = 'posted'
    {$where_clause}
    GROUP BY s.id, s.uuid, s.supplier_code, s.company_name, s.contact_person, s.email, 
             s.phone, s.mobile, s.address, s.city, s.country, s.tax_id, s.payment_terms, 
             s.credit_limit, s.opening_balance, s.current_balance, s.supplier_type, 
             s.status, s.notes, s.created_by_user_id, s.created_at, s.updated_at, u.display_name
    ORDER BY s.{$sort_by} {$sort_order}
    LIMIT {$per_page} OFFSET {$offset}
";

$db->query($suppliers_sql, $params);
$suppliers = $db->results();

// Get summary statistics
$stats_sql = "
    SELECT 
        COUNT(*) as total_count,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count,
        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_count,
        SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) as blocked_count,
        SUM(CASE WHEN supplier_type = 'local' THEN 1 ELSE 0 END) as local_count,
        SUM(CASE WHEN supplier_type = 'international' THEN 1 ELSE 0 END) as international_count,
        COALESCE(SUM(current_balance), 0) as total_outstanding
    FROM suppliers
";
$db->query($stats_sql);
$stats = $db->first();

require_once '../templates/header.php';
?>

<div class="container mx-auto">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Suppliers</h1>
            <p class="mt-2 text-gray-600">Manage your supplier relationships and track balances</p>
        </div>
        <a href="supplier_form.php" class="bg-primary-600 hover:bg-primary-700 text-white px-6 py-3 rounded-lg shadow-md flex items-center gap-2 transition">
            <i class="fas fa-plus-circle"></i>
            <span>Add New Supplier</span>
        </a>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Total Suppliers</p>
                    <p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($stats->total_count); ?></p>
                    <p class="text-sm text-green-600 mt-1">
                        <i class="fas fa-check-circle mr-1"></i><?php echo number_format($stats->active_count); ?> Active
                    </p>
                </div>
                <div class="p-3 bg-blue-100 rounded-full">
                    <i class="fas fa-users text-blue-600 text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Local Suppliers</p>
                    <p class="text-3xl font-bold text-green-600 mt-2"><?php echo number_format($stats->local_count); ?></p>
                    <p class="text-sm text-gray-500 mt-1">Bangladesh based</p>
                </div>
                <div class="p-3 bg-green-100 rounded-full">
                    <i class="fas fa-map-marker-alt text-green-600 text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">International</p>
                    <p class="text-3xl font-bold text-blue-600 mt-2"><?php echo number_format($stats->international_count); ?></p>
                    <p class="text-sm text-gray-500 mt-1">Overseas suppliers</p>
                </div>
                <div class="p-3 bg-blue-100 rounded-full">
                    <i class="fas fa-globe text-blue-600 text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Outstanding</p>
                    <p class="text-2xl font-bold text-red-600 mt-2">BDT <?php echo number_format($stats->total_outstanding, 2); ?></p>
                    <p class="text-sm text-gray-500 mt-1">Amount we owe</p>
                </div>
                <div class="p-3 bg-red-100 rounded-full">
                    <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters and Search -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
        <form method="GET" action="" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <!-- Search -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                    <div class="relative">
                        <input type="text" 
                               name="search" 
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Search by name, code, contact, phone, email..."
                               class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                        <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                    </div>
                </div>

                <!-- Supplier Type -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Supplier Type</label>
                    <select name="supplier_type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                        <option value="">All Types</option>
                        <option value="local" <?php echo $supplier_type === 'local' ? 'selected' : ''; ?>>Local</option>
                        <option value="international" <?php echo $supplier_type === 'international' ? 'selected' : ''; ?>>International</option>
                        <option value="both" <?php echo $supplier_type === 'both' ? 'selected' : ''; ?>>Both</option>
                    </select>
                </div>

                <!-- Status -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                    <select name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="blocked" <?php echo $status === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
                    </select>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-6 py-2 rounded-lg transition">
                    <i class="fas fa-filter mr-2"></i>Apply Filters
                </button>
                <a href="suppliers.php" class="text-gray-600 hover:text-gray-800 px-4 py-2">
                    <i class="fas fa-redo mr-2"></i>Reset
                </a>
                <div class="ml-auto text-sm text-gray-600">
                    Showing <span class="font-semibold"><?php echo number_format(count($suppliers)); ?></span> of 
                    <span class="font-semibold"><?php echo number_format($total_suppliers); ?></span> suppliers
                </div>
            </div>
        </form>
    </div>

    <!-- Suppliers Table -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <?php if (count($suppliers) > 0): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left">
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'supplier_code', 'sort_order' => ($sort_by === 'supplier_code' && $sort_order === 'ASC') ? 'DESC' : 'ASC'])); ?>" 
                               class="text-xs font-medium text-gray-500 uppercase tracking-wider hover:text-gray-700 flex items-center gap-1">
                                Code
                                <?php if ($sort_by === 'supplier_code'): ?>
                                    <i class="fas fa-sort-<?php echo $sort_order === 'ASC' ? 'up' : 'down'; ?>"></i>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th class="px-6 py-3 text-left">
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'company_name', 'sort_order' => ($sort_by === 'company_name' && $sort_order === 'ASC') ? 'DESC' : 'ASC'])); ?>" 
                               class="text-xs font-medium text-gray-500 uppercase tracking-wider hover:text-gray-700 flex items-center gap-1">
                                Company Name
                                <?php if ($sort_by === 'company_name'): ?>
                                    <i class="fas fa-sort-<?php echo $sort_order === 'ASC' ? 'up' : 'down'; ?>"></i>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                        <th class="px-6 py-3 text-center">
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'supplier_type', 'sort_order' => ($sort_by === 'supplier_type' && $sort_order === 'ASC') ? 'DESC' : 'ASC'])); ?>" 
                               class="text-xs font-medium text-gray-500 uppercase tracking-wider hover:text-gray-700 flex items-center justify-center gap-1">
                                Type
                                <?php if ($sort_by === 'supplier_type'): ?>
                                    <i class="fas fa-sort-<?php echo $sort_order === 'ASC' ? 'up' : 'down'; ?>"></i>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th class="px-6 py-3 text-right">
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'current_balance', 'sort_order' => ($sort_by === 'current_balance' && $sort_order === 'ASC') ? 'DESC' : 'ASC'])); ?>" 
                               class="text-xs font-medium text-gray-500 uppercase tracking-wider hover:text-gray-700 flex items-center justify-end gap-1">
                                Balance
                                <?php if ($sort_by === 'current_balance'): ?>
                                    <i class="fas fa-sort-<?php echo $sort_order === 'ASC' ? 'up' : 'down'; ?>"></i>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Payment Terms</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($suppliers as $supplier): ?>
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="text-sm font-mono text-gray-900"><?php echo htmlspecialchars($supplier->supplier_code ?? 'N/A'); ?></span>
                        </td>
                        <td class="px-6 py-4">
                            <div>
                                <a href="view_supplier.php?id=<?php echo $supplier->id; ?>" class="text-sm font-semibold text-primary-600 hover:text-primary-800">
                                    <?php echo htmlspecialchars($supplier->company_name); ?>
                                </a>
                                <p class="text-xs text-gray-500 mt-1">
                                    <i class="fas fa-map-marker-alt mr-1"></i><?php echo htmlspecialchars($supplier->city ?? 'N/A'); ?>, <?php echo htmlspecialchars($supplier->country ?? 'N/A'); ?>
                                </p>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-sm">
                                <p class="text-gray-900 font-medium"><?php echo htmlspecialchars($supplier->contact_person ?? 'N/A'); ?></p>
                                <?php if ($supplier->phone): ?>
                                <p class="text-gray-500 text-xs mt-1">
                                    <i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($supplier->phone); ?>
                                </p>
                                <?php endif; ?>
                                <?php if ($supplier->email): ?>
                                <p class="text-gray-500 text-xs">
                                    <i class="fas fa-envelope mr-1"></i><?php echo htmlspecialchars($supplier->email); ?>
                                </p>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <?php
                            $type_colors = [
                                'local' => 'bg-green-100 text-green-800',
                                'international' => 'bg-blue-100 text-blue-800',
                                'both' => 'bg-purple-100 text-purple-800'
                            ];
                            $type_icons = [
                                'local' => 'fa-home',
                                'international' => 'fa-globe',
                                'both' => 'fa-globe-americas'
                            ];
                            $color = $type_colors[$supplier->supplier_type] ?? 'bg-gray-100 text-gray-800';
                            $icon = $type_icons[$supplier->supplier_type] ?? 'fa-tag';
                            ?>
                            <span class="px-3 py-1 text-xs font-medium rounded-full <?php echo $color; ?>">
                                <i class="fas <?php echo $icon; ?> mr-1"></i><?php echo ucfirst($supplier->supplier_type); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <div>
                                <p class="text-sm font-bold <?php echo $supplier->current_balance > 0 ? 'text-red-600' : 'text-gray-900'; ?>">
                                    BDT <?php echo number_format($supplier->current_balance, 2); ?>
                                </p>
                                <?php if ($supplier->outstanding_amount > 0): ?>
                                <p class="text-xs text-orange-600 mt-1">
                                    <i class="fas fa-exclamation-triangle mr-1"></i><?php echo number_format($supplier->outstanding_amount, 2); ?> overdue
                                </p>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="text-sm text-gray-600">
                                <?php echo htmlspecialchars($supplier->payment_terms ?? 'N/A'); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <?php
                            $status_colors = [
                                'active' => 'bg-green-100 text-green-800',
                                'inactive' => 'bg-gray-100 text-gray-800',
                                'blocked' => 'bg-red-100 text-red-800'
                            ];
                            $status_icons = [
                                'active' => 'fa-check-circle',
                                'inactive' => 'fa-pause-circle',
                                'blocked' => 'fa-ban'
                            ];
                            $color = $status_colors[$supplier->status] ?? 'bg-gray-100 text-gray-800';
                            $icon = $status_icons[$supplier->status] ?? 'fa-circle';
                            ?>
                            <span class="px-3 py-1 text-xs font-medium rounded-full <?php echo $color; ?>">
                                <i class="fas <?php echo $icon; ?> mr-1"></i><?php echo ucfirst($supplier->status); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <div class="flex items-center justify-center gap-2">
                                <a href="view_supplier.php?id=<?php echo $supplier->id; ?>" 
                                   class="text-blue-600 hover:text-blue-800" 
                                   title="View Details">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="supplier_ledger.php?id=<?php echo $supplier->id; ?>" 
                                   class="text-green-600 hover:text-green-800" 
                                   title="View Ledger">
                                    <i class="fas fa-book"></i>
                                </a>
                                <a href="supplier_form.php?id=<?php echo $supplier->id; ?>" 
                                   class="text-orange-600 hover:text-orange-800" 
                                   title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php if ($supplier->status === 'active'): ?>
                                <a href="supplier_form.php?id=<?php echo $supplier->id; ?>&action=deactivate" 
                                   class="text-gray-600 hover:text-gray-800" 
                                   title="Deactivate"
                                   onclick="return confirm('Are you sure you want to deactivate this supplier?');">
                                    <i class="fas fa-power-off"></i>
                                </a>
                                <?php endif; ?>
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
                // Show page numbers
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
            <i class="fas fa-users text-gray-300 text-6xl mb-4"></i>
            <h3 class="text-xl font-semibold text-gray-900 mb-2">No suppliers found</h3>
            <p class="text-gray-600 mb-6">
                <?php if (!empty($search) || !empty($supplier_type) || !empty($status)): ?>
                    Try adjusting your filters or search terms.
                <?php else: ?>
                    Get started by adding your first supplier.
                <?php endif; ?>
            </p>
            <?php if (empty($search) && empty($supplier_type) && empty($status)): ?>
            <a href="supplier_form.php" class="inline-block bg-primary-600 hover:bg-primary-700 text-white px-6 py-3 rounded-lg">
                <i class="fas fa-plus-circle mr-2"></i>Add Your First Supplier
            </a>
            <?php else: ?>
            <a href="suppliers.php" class="inline-block text-primary-600 hover:text-primary-700 font-medium">
                <i class="fas fa-redo mr-2"></i>Clear Filters
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Quick Stats Footer -->
    <div class="mt-6 bg-white rounded-lg shadow-md p-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
            <div class="text-center">
                <p class="text-sm text-gray-600">Total Purchase Orders</p>
                <p class="text-2xl font-bold text-gray-900 mt-2">
                    <?php 
                    $total_pos = array_sum(array_column((array)$suppliers, 'total_pos'));
                    echo number_format($total_pos); 
                    ?>
                </p>
            </div>
            <div class="text-center">
                <p class="text-sm text-gray-600">Total Purchases (Value)</p>
                <p class="text-2xl font-bold text-gray-900 mt-2">
                    BDT <?php 
                    $total_value = array_sum(array_column((array)$suppliers, 'total_purchases'));
                    echo number_format($total_value, 2); 
                    ?>
                </p>
            </div>
            <div class="text-center">
                <p class="text-sm text-gray-600">Total Invoices</p>
                <p class="text-2xl font-bold text-gray-900 mt-2">
                    <?php 
                    $total_invoices = array_sum(array_column((array)$suppliers, 'total_invoices'));
                    echo number_format($total_invoices); 
                    ?>
                </p>
            </div>
            <div class="text-center">
                <p class="text-sm text-gray-600">Outstanding (Current Page)</p>
                <p class="text-2xl font-bold text-red-600 mt-2">
                    BDT <?php 
                    $page_outstanding = array_sum(array_column((array)$suppliers, 'current_balance'));
                    echo number_format($page_outstanding, 2); 
                    ?>
                </p>
            </div>
        </div>
    </div>

</div>

<?php require_once '../templates/footer.php'; ?>