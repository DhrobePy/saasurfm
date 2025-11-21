<?php
require_once '../core/init.php';

global $db;

restrict_access();  // ensures user is logged in

$pageTitle = "Purchase Dashboard";

require_once '../includes/Purchasemanager.php';

$currentUser = getCurrentUser();
$purchaseManager = new PurchaseManager($db, $currentUser['id']);

$user_role = $currentUser['role'] ?? '';
$user_branch_id = $currentUser['branch_id'] ?? null;

$today = date('Y-m-d');
$firstDayOfMonth = date('Y-m-01');
$firstDayOfYear = date('Y-01-01');

// ============================================
// KEY METRICS - FIXED DATABASE QUERIES
// ============================================

// Total Purchase Orders (This Year)
$total_pos_sql = "
    SELECT 
        COUNT(*) as count, 
        SUM(total_amount) as total,
        SUM(CASE WHEN status IN ('received', 'closed') THEN total_amount ELSE 0 END) as received_amount
    FROM purchase_orders 
    WHERE status NOT IN ('draft', 'cancelled')
    AND po_date >= :year_start
";

$db->query($total_pos_sql, ['year_start' => $firstDayOfYear]);
$row = $db->first();

$total_pos = [
    'count'           => $row->count ?? 0,
    'total'           => $row->total ?? 0,
    'received_amount' => $row->received_amount ?? 0
];

// Pending Approval Purchase Orders
$pending_approval_sql = "
    SELECT COUNT(*) AS count, SUM(total_amount) AS total 
    FROM purchase_orders
    WHERE status = 'pending_approval'
";

$db->query($pending_approval_sql);
$pending_approval = $db->first();

// Outstanding Invoices
$outstanding_invoices_sql = "
    SELECT 
        COUNT(*) AS count, 
        SUM(balance_due) AS total,
        SUM(CASE WHEN due_date < CURDATE() THEN balance_due ELSE 0 END) AS overdue
    FROM purchase_invoices
    WHERE payment_status IN ('unpaid', 'partially_paid')
    AND status = 'posted'
";

$db->query($outstanding_invoices_sql);
$outstanding_invoices = $db->first();

// Payments This Month
$payments_this_month_sql = "
    SELECT 
        COUNT(*) as count, 
        SUM(amount) as total 
    FROM supplier_payments 
    WHERE payment_date >= :first_day
    AND status IN ('pending', 'cleared')
";

$db->query($payments_this_month_sql, ['first_day' => $firstDayOfMonth]);
$payments_this_month = $db->first();

// Wheat Purchases This Month (by item type)
$wheat_purchases_sql = "
    SELECT 
        SUM(poi.quantity) as total_quantity,
        SUM(poi.line_total) as total_amount,
        COUNT(DISTINCT po.id) as total_orders
    FROM purchase_order_items poi
    JOIN purchase_orders po ON poi.purchase_order_id = po.id
    WHERE poi.item_type = 'raw_material'
    AND po.po_date >= :month_start
    AND po.status NOT IN ('draft', 'cancelled')
";

$db->query($wheat_purchases_sql, ['month_start' => $firstDayOfMonth]);
$wheat_purchases = $db->first();

// Top Suppliers This Month (by purchase amount)
$top_suppliers_sql = "
    SELECT 
        s.id,
        s.company_name,
        s.supplier_type,
        COUNT(DISTINCT po.id) as po_count,
        COALESCE(SUM(po.total_amount), 0) as total_purchased,
        s.current_balance as balance_owed
    FROM suppliers s
    LEFT JOIN purchase_orders po ON s.id = po.supplier_id 
        AND po.po_date >= :month_start
        AND po.status NOT IN ('draft', 'cancelled')
    WHERE s.status = 'active'
    GROUP BY s.id, s.company_name, s.supplier_type, s.current_balance
    HAVING total_purchased > 0
    ORDER BY total_purchased DESC
    LIMIT 5
";

$db->query($top_suppliers_sql, ['month_start' => $firstDayOfMonth]);
$top_suppliers = $db->results();

// Recent Purchase Orders (last 7 days)
$recent_pos_sql = "
    SELECT 
        po.id,
        po.po_number,
        po.po_date,
        po.status,
        po.total_amount,
        po.created_at,
        s.company_name as supplier_name,
        s.supplier_type,
        b.name as branch_name,
        b.code as branch_code,
        u.display_name as created_by_name
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN branches b ON po.branch_id = b.id
    LEFT JOIN users u ON po.created_by_user_id = u.id
    WHERE po.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY po.created_at DESC
    LIMIT 8
";

$db->query($recent_pos_sql);
$recent_pos = $db->results();

// Outstanding Invoices (Urgent - due within 7 days or overdue)
$urgent_invoices_sql = "
    SELECT 
        pi.id,
        pi.invoice_number,
        pi.supplier_invoice_number,
        pi.due_date,
        pi.balance_due,
        pi.supplier_id,
        s.company_name as supplier_name,
        s.payment_terms,
        DATEDIFF(pi.due_date, CURDATE()) as days_until_due
    FROM purchase_invoices pi
    LEFT JOIN suppliers s ON pi.supplier_id = s.id
    WHERE pi.payment_status IN ('unpaid', 'partially_paid')
    AND pi.status = 'posted'
    AND pi.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY pi.due_date ASC
    LIMIT 8
";

$db->query($urgent_invoices_sql);
$urgent_invoices = $db->results();

// Recent Payments (last 7 days)
$recent_payments_sql = "
    SELECT 
        sp.id,
        sp.payment_number,
        sp.payment_date,
        sp.amount,
        sp.payment_method,
        sp.status,
        sp.supplier_id,
        sp.created_at,
        s.company_name as supplier_name,
        c.name as account_name,
        u.display_name as created_by_name
    FROM supplier_payments sp
    LEFT JOIN suppliers s ON sp.supplier_id = s.id
    LEFT JOIN chart_of_accounts c ON sp.payment_account_id = c.id
    LEFT JOIN users u ON sp.created_by_user_id = u.id
    WHERE sp.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY sp.payment_date DESC, sp.created_at DESC
    LIMIT 6
";

$db->query($recent_payments_sql);
$recent_payments = $db->results();

// Purchases by Item Type (This Month) - for flour mill specific items
$purchases_by_type_sql = "
    SELECT 
        poi.item_type,
        COUNT(DISTINCT po.id) as order_count,
        SUM(poi.quantity) as total_quantity,
        SUM(poi.line_total) as total_amount
    FROM purchase_order_items poi
    JOIN purchase_orders po ON poi.purchase_order_id = po.id
    WHERE po.po_date >= :month_start
    AND po.status NOT IN ('draft', 'cancelled')
    GROUP BY poi.item_type
    ORDER BY total_amount DESC
";

$db->query($purchases_by_type_sql, ['month_start' => $firstDayOfMonth]);
$purchases_by_type = $db->results();

// Purchase Order Status Breakdown (This Year)
$po_status_breakdown_sql = "
    SELECT 
        status,
        COUNT(*) as count,
        SUM(total_amount) as total_amount
    FROM purchase_orders
    WHERE po_date >= :year_start
    AND status NOT IN ('draft', 'cancelled')
    GROUP BY status
    ORDER BY total_amount DESC
";

$db->query($po_status_breakdown_sql, ['year_start' => $firstDayOfYear]);
$po_status_breakdown = $db->results();

require_once '../templates/header.php';
?>

<div class="container mx-auto">
    <!-- Page Header -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900">Purchase Dashboard</h1>
        <p class="mt-2 text-gray-600">Monitor purchase orders, invoices, and supplier payments</p>
    </div>

    <!-- Key Metrics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        
        <!-- Total Purchase Orders (This Year) -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Total POs (<?php echo date('Y'); ?>)</p>
                    <p class="text-3xl font-bold text-gray-900 mt-2">
                        <?php echo number_format($total_pos['count']); ?>
                    </p>
                    <p class="text-sm text-gray-500 mt-1">
                        BDT <?php echo number_format($total_pos['total'], 2); ?>
                    </p>
                    <?php if ($total_pos['total'] > 0): 
                        $received_percentage = ($total_pos['received_amount'] / $total_pos['total']) * 100;
                    ?>
                    <div class="mt-2">
                        <div class="flex items-center text-xs text-gray-600">
                            <span>Received: <?php echo number_format($received_percentage, 1); ?>%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-1.5 mt-1">
                            <div class="bg-green-500 h-1.5 rounded-full" style="width: <?php echo min($received_percentage, 100); ?>%"></div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="p-3 bg-blue-100 rounded-full">
                    <i class="fas fa-file-invoice text-blue-600 text-2xl"></i>
                </div>
            </div>
        </div>

        <!-- Pending Approval -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Pending Approval</p>
                    <p class="text-3xl font-bold text-orange-600 mt-2">
                        <?php echo number_format($pending_approval->count ?? 0); ?>
                    </p>
                    <p class="text-sm text-gray-500 mt-1">
                        BDT <?php echo number_format($pending_approval->total ?? 0, 2); ?>
                    </p>
                </div>
                <div class="p-3 bg-orange-100 rounded-full">
                    <i class="fas fa-clock text-orange-600 text-2xl"></i>
                </div>
            </div>
            <?php if (($pending_approval->count ?? 0) > 0): ?>
            <a href="purchase_orders.php?status=pending_approval" class="mt-4 block text-center text-sm font-medium text-orange-600 hover:text-orange-700">
                Review Now →
            </a>
            <?php endif; ?>
        </div>

        <!-- Outstanding Invoices -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Outstanding Invoices</p>
                    <p class="text-3xl font-bold text-red-600 mt-2">
                        <?php echo number_format($outstanding_invoices->count ?? 0); ?>
                    </p>
                    <p class="text-sm text-gray-500 mt-1">
                        BDT <?php echo number_format($outstanding_invoices->total ?? 0, 2); ?>
                    </p>
                    <?php if (($outstanding_invoices->overdue ?? 0) > 0): ?>
                    <p class="text-xs text-red-600 mt-1 font-semibold">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        Overdue: BDT <?php echo number_format($outstanding_invoices->overdue, 2); ?>
                    </p>
                    <?php endif; ?>
                </div>
                <div class="p-3 bg-red-100 rounded-full">
                    <i class="fas fa-exclamation-circle text-red-600 text-2xl"></i>
                </div>
            </div>
        </div>

        <!-- Payments This Month -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Payments This Month</p>
                    <p class="text-3xl font-bold text-green-600 mt-2">
                        <?php echo number_format($payments_this_month->count ?? 0); ?>
                    </p>
                    <p class="text-sm text-gray-500 mt-1">
                        BDT <?php echo number_format($payments_this_month->total ?? 0, 2); ?>
                    </p>
                </div>
                <div class="p-3 bg-green-100 rounded-full">
                    <i class="fas fa-money-bill-wave text-green-600 text-2xl"></i>
                </div>
            </div>
        </div>

    </div>

    <!-- Quick Actions -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
        <a href="create_po.php" class="bg-primary-600 hover:bg-primary-700 text-white rounded-lg shadow-md p-4 flex items-center justify-between transition">
            <div>
                <p class="font-semibold">Create PO</p>
                <p class="text-xs text-primary-100 mt-1">New purchase order</p>
            </div>
            <i class="fas fa-plus-circle text-2xl"></i>
        </a>

        <a href="goods_received.php" class="bg-blue-600 hover:bg-blue-700 text-white rounded-lg shadow-md p-4 flex items-center justify-between transition">
            <div>
                <p class="font-semibold">Record GRN</p>
                <p class="text-xs text-blue-100 mt-1">Goods received note</p>
            </div>
            <i class="fas fa-clipboard-check text-2xl"></i>
        </a>

        <a href="create_invoice.php" class="bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg shadow-md p-4 flex items-center justify-between transition">
            <div>
                <p class="font-semibold">Create Invoice</p>
                <p class="text-xs text-indigo-100 mt-1">Purchase invoice</p>
            </div>
            <i class="fas fa-file-invoice-dollar text-2xl"></i>
        </a>

        <a href="create_payment.php" class="bg-green-600 hover:bg-green-700 text-white rounded-lg shadow-md p-4 flex items-center justify-between transition">
            <div>
                <p class="font-semibold">Make Payment</p>
                <p class="text-xs text-green-100 mt-1">Pay supplier</p>
            </div>
            <i class="fas fa-money-check-alt text-2xl"></i>
        </a>
    </div>

    <!-- Charts Row -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        
        <!-- Purchases by Item Type -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-900 mb-4">Purchases by Type (This Month)</h2>
            <?php if (count($purchases_by_type) > 0): ?>
            <div class="space-y-4">
                <?php foreach ($purchases_by_type as $type): 
                    $type_colors = [
                        'raw_material' => 'bg-amber-500',
                        'finished_goods' => 'bg-green-500',
                        'packaging' => 'bg-blue-500',
                        'supplies' => 'bg-purple-500',
                        'other' => 'bg-gray-500'
                    ];
                    $color = $type_colors[$type->item_type] ?? 'bg-gray-500';
                    $type_name = ucwords(str_replace('_', ' ', $type->item_type));
                ?>
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-gray-700"><?php echo $type_name; ?></span>
                        <span class="text-sm font-bold text-gray-900">BDT <?php echo number_format($type->total_amount, 2); ?></span>
                    </div>
                    <div class="flex items-center space-x-2">
                        <div class="flex-grow bg-gray-200 rounded-full h-2">
                            <div class="<?php echo $color; ?> h-2 rounded-full" style="width: 100%"></div>
                        </div>
                        <span class="text-xs text-gray-500"><?php echo number_format($type->order_count); ?> orders</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-center py-12">
                <i class="fas fa-chart-bar text-gray-300 text-5xl mb-4"></i>
                <p class="text-gray-500">No purchase data for this month</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- PO Status Breakdown -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-900 mb-4">PO Status (<?php echo date('Y'); ?>)</h2>
            <?php if (count($po_status_breakdown) > 0): ?>
            <div class="space-y-4">
                <?php 
                $status_labels = [
                    'pending_approval' => 'Pending Approval',
                    'approved' => 'Approved',
                    'ordered' => 'Ordered',
                    'partially_received' => 'Partially Received',
                    'received' => 'Received',
                    'closed' => 'Closed'
                ];
                $status_colors = [
                    'pending_approval' => 'bg-orange-500',
                    'approved' => 'bg-yellow-500',
                    'ordered' => 'bg-blue-500',
                    'partially_received' => 'bg-indigo-500',
                    'received' => 'bg-green-500',
                    'closed' => 'bg-gray-500'
                ];
                foreach ($po_status_breakdown as $status): 
                    $label = $status_labels[$status->status] ?? ucfirst($status->status);
                    $color = $status_colors[$status->status] ?? 'bg-gray-500';
                ?>
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-gray-700"><?php echo $label; ?></span>
                        <div class="text-right">
                            <span class="text-sm font-bold text-gray-900">BDT <?php echo number_format($status->total_amount, 2); ?></span>
                            <span class="text-xs text-gray-500 ml-2">(<?php echo number_format($status->count); ?>)</span>
                        </div>
                    </div>
                    <div class="bg-gray-200 rounded-full h-2">
                        <div class="<?php echo $color; ?> h-2 rounded-full" style="width: 100%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-center py-12">
                <i class="fas fa-chart-pie text-gray-300 text-5xl mb-4"></i>
                <p class="text-gray-500">No PO status data available</p>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- Top Suppliers This Month -->
    <?php if (count($top_suppliers) > 0): ?>
    <div class="bg-white rounded-lg shadow-md p-6 mb-8">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-xl font-bold text-gray-900">Top Suppliers (This Month)</h2>
            <a href="suppliers.php" class="text-sm text-primary-600 hover:text-primary-700 font-medium">View All →</a>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Supplier</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">PO Count</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total Purchased</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance Owed</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($top_suppliers as $supplier): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <a href="supplier_ledger.php?id=<?php echo $supplier->id; ?>" class="text-sm font-medium text-primary-600 hover:text-primary-700">
                                <?php echo htmlspecialchars($supplier->company_name); ?>
                            </a>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                echo $supplier->supplier_type === 'international' ? 'bg-blue-100 text-blue-800' : 
                                     ($supplier->supplier_type === 'both' ? 'bg-purple-100 text-purple-800' : 'bg-green-100 text-green-800'); 
                            ?>">
                                <?php echo ucfirst($supplier->supplier_type); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                            <?php echo number_format($supplier->po_count); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-gray-900">
                            BDT <?php echo number_format($supplier->total_purchased, 2); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-red-600">
                            BDT <?php echo number_format($supplier->balance_owed, 2); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recent Activity Section -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">

        <!-- Recent Purchase Orders -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-bold text-gray-900">Recent Purchase Orders</h2>
                <a href="purchase_orders.php" class="text-sm text-primary-600 hover:text-primary-700 font-medium">View All →</a>
            </div>
            
            <?php if (count($recent_pos) > 0): ?>
            <div class="space-y-3">
                <?php foreach ($recent_pos as $po): 
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
                <div class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition">
                    <div class="flex items-start justify-between mb-2">
                        <div class="flex-1">
                            <a href="view_po.php?id=<?php echo $po->id; ?>" class="font-semibold text-primary-600 hover:text-primary-700 hover:underline">
                                <?php echo htmlspecialchars($po->po_number); ?>
                            </a>
                            <p class="text-sm text-gray-600 mt-1">
                                <?php echo htmlspecialchars($po->supplier_name); ?>
                            </p>
                        </div>
                        <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $color; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $po->status)); ?>
                        </span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-500">
                            <i class="fas fa-map-marker-alt text-gray-400 mr-1"></i>
                            <?php echo htmlspecialchars($po->branch_name); ?>
                        </span>
                        <span class="text-gray-500">
                            <?php echo date('M d, Y', strtotime($po->po_date)); ?>
                        </span>
                        <span class="font-bold text-gray-900">
                            BDT <?php echo number_format($po->total_amount, 2); ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-center py-12">
                <i class="fas fa-file-invoice text-gray-300 text-5xl mb-4"></i>
                <p class="text-gray-500">No recent purchase orders</p>
                <a href="create_po.php" class="mt-4 inline-block text-primary-600 hover:text-primary-700 font-medium">
                    Create your first PO →
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Urgent/Outstanding Invoices -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-bold text-gray-900">Urgent Invoices</h2>
                <a href="invoices.php?payment_status=unpaid" class="text-sm text-primary-600 hover:text-primary-700 font-medium">View All →</a>
            </div>
            
            <?php if (count($urgent_invoices) > 0): ?>
            <div class="space-y-3">
                <?php foreach ($urgent_invoices as $invoice): ?>
                <div class="border rounded-lg p-4 <?php echo $invoice->days_until_due < 0 ? 'border-red-300 bg-red-50' : ($invoice->days_until_due <= 3 ? 'border-orange-300 bg-orange-50' : 'border-gray-200'); ?>">
                    <div class="flex items-start justify-between mb-2">
                        <div class="flex-1">
                            <a href="view_invoice.php?id=<?php echo $invoice->id; ?>" class="font-semibold <?php echo $invoice->days_until_due < 0 ? 'text-red-700' : 'text-primary-600'; ?> hover:underline">
                                <?php echo htmlspecialchars($invoice->invoice_number); ?>
                            </a>
                            <p class="text-sm text-gray-600 mt-1">
                                <?php echo htmlspecialchars($invoice->supplier_name); ?>
                            </p>
                        </div>
                        <?php if ($invoice->days_until_due < 0): ?>
                        <span class="px-2 py-1 text-xs font-bold rounded-full bg-red-100 text-red-800">
                            OVERDUE
                        </span>
                        <?php else: ?>
                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-orange-100 text-orange-800">
                            <?php echo $invoice->days_until_due; ?> days
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-600">
                            Due: <?php echo date('M d, Y', strtotime($invoice->due_date)); ?>
                        </span>
                        <span class="font-bold <?php echo $invoice->days_until_due < 0 ? 'text-red-700' : 'text-gray-900'; ?>">
                            BDT <?php echo number_format($invoice->balance_due, 2); ?>
                        </span>
                    </div>
                    <div class="mt-2">
                        <a href="create_payment.php?supplier_id=<?php echo $invoice->supplier_id; ?>&invoice_id=<?php echo $invoice->id; ?>" 
                           class="text-xs font-medium <?php echo $invoice->days_until_due < 0 ? 'text-red-600 hover:text-red-700' : 'text-primary-600 hover:text-primary-700'; ?>">
                            Make Payment →
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-center py-12">
                <i class="fas fa-check-circle text-green-400 text-5xl mb-4"></i>
                <p class="text-gray-500">No urgent invoices</p>
                <p class="text-sm text-gray-400 mt-2">All payments are up to date!</p>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- Recent Payments -->
    <?php if (count($recent_payments) > 0): ?>
    <div class="bg-white rounded-lg shadow-md p-6 mt-8">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-xl font-bold text-gray-900">Recent Payments</h2>
            <a href="payments.php" class="text-sm text-primary-600 hover:text-primary-700 font-medium">View All →</a>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Payment #</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Supplier</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Method</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Account</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($recent_payments as $payment): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($payment->payment_number); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo date('M d, Y', strtotime($payment->payment_date)); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <a href="supplier_ledger.php?id=<?php echo $payment->supplier_id; ?>" class="text-sm text-primary-600 hover:text-primary-700">
                                <?php echo htmlspecialchars($payment->supplier_name); ?>
                            </a>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-gray-900">
                            BDT <?php echo number_format($payment->amount, 2); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <i class="fas fa-<?php 
                                echo $payment->payment_method === 'bank_transfer' ? 'university' : 
                                     ($payment->payment_method === 'cheque' ? 'money-check' : 
                                     ($payment->payment_method === 'cash' ? 'money-bill-wave' : 'credit-card')); 
                            ?> mr-1"></i>
                            <?php echo ucfirst(str_replace('_', ' ', $payment->payment_method)); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo htmlspecialchars($payment->account_name ?? 'N/A'); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <?php
                            $status_colors = [
                                'pending' => 'bg-yellow-100 text-yellow-800',
                                'cleared' => 'bg-green-100 text-green-800',
                                'bounced' => 'bg-red-100 text-red-800',
                                'cancelled' => 'bg-gray-100 text-gray-800'
                            ];
                            $color = $status_colors[$payment->status] ?? 'bg-gray-100 text-gray-800';
                            ?>
                            <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $color; ?>">
                                <?php echo ucfirst($payment->status); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php require_once '../templates/footer.php'; ?>