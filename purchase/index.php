<?php
require_once '../core/init.php';
restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$pageTitle = "Purchase (Adnan) Dashboard";

// Get current user
$currentUser = getCurrentUser();
$user_role = $currentUser['role'] ?? '';

// Date ranges
$today = date('Y-m-d');
$firstDayOfMonth = date('Y-m-01');
$firstDayOfYear = date('Y-01-01');

// Database connection
$db = Database::getInstance()->getPdo();

// ============================================
// KEY METRICS - PURCHASE ADNAN MODULE
// ============================================

// Total Purchase Orders (This Year)
$total_pos_sql = "
    SELECT 
        COUNT(*) as count, 
        COALESCE(SUM(total_order_value), 0) as total,
        COALESCE(SUM(total_received_value), 0) as received_amount,
        COALESCE(SUM(total_paid), 0) as paid_amount,
        COALESCE(SUM(balance_payable), 0) as balance_due
    FROM purchase_orders_adnan 
    WHERE po_status != 'cancelled'
    AND po_date >= ?
";

$stmt = $db->prepare($total_pos_sql);
$stmt->execute([$firstDayOfYear]);
$row = $stmt->fetch(PDO::FETCH_OBJ);

$total_pos = [
    'count' => $row->count ?? 0,
    'total' => $row->total ?? 0,
    'received_amount' => $row->received_amount ?? 0,
    'paid_amount' => $row->paid_amount ?? 0,
    'balance_due' => $row->balance_due ?? 0
];

// Pending Delivery Purchase Orders
$pending_delivery_sql = "
    SELECT 
        COUNT(*) AS count, 
        COALESCE(SUM(qty_yet_to_receive), 0) AS pending_qty, 
        COALESCE(SUM(balance_payable), 0) AS pending_amount
    FROM purchase_orders_adnan
    WHERE delivery_status IN ('pending', 'partial')
    AND po_status = 'active'
";

$stmt = $db->prepare($pending_delivery_sql);
$stmt->execute();
$pending_delivery = $stmt->fetch(PDO::FETCH_OBJ);

// Payments This Month
$payments_this_month_sql = "
    SELECT 
        COUNT(*) as count, 
        COALESCE(SUM(amount_paid), 0) as total 
    FROM purchase_payments_adnan 
    WHERE payment_date >= ?
    AND is_posted = 1
";

$stmt = $db->prepare($payments_this_month_sql);
$stmt->execute([$firstDayOfMonth]);
$payments_this_month = $stmt->fetch(PDO::FETCH_OBJ);

// Wheat Purchases This Month
$wheat_purchases_sql = "
    SELECT 
        COALESCE(SUM(quantity_kg), 0) as total_quantity,
        COALESCE(SUM(total_order_value), 0) as total_amount,
        COUNT(*) as total_orders
    FROM purchase_orders_adnan
    WHERE po_date >= ?
    AND po_status != 'cancelled'
";

$stmt = $db->prepare($wheat_purchases_sql);
$stmt->execute([$firstDayOfMonth]);
$wheat_purchases = $stmt->fetch(PDO::FETCH_OBJ);

// Top Suppliers This Month - FIXED QUERY
$top_suppliers_sql = "
    SELECT 
        s.id,
        s.company_name,
        s.current_balance,
        COUNT(DISTINCT po.id) as po_count,
        COALESCE(SUM(po.total_order_value), 0) as total_purchased,
        COALESCE(SUM(po.balance_payable), 0) as balance_owed
    FROM suppliers s
    LEFT JOIN purchase_orders_adnan po ON s.id = po.supplier_id 
        AND po.po_date >= ?
        AND po.po_status != 'cancelled'
    WHERE s.status = 'active'
    GROUP BY s.id, s.company_name, s.current_balance
    HAVING total_purchased > 0
    ORDER BY total_purchased DESC
    LIMIT 5
";

$stmt = $db->prepare($top_suppliers_sql);
$stmt->execute([$firstDayOfMonth]);
$top_suppliers = $stmt->fetchAll(PDO::FETCH_OBJ);

// Recent Purchase Orders (last 7 days)
$recent_pos_sql = "
    SELECT 
        po.id,
        po.po_number,
        po.po_date,
        po.delivery_status as status,
        po.payment_status,
        po.total_order_value as total_amount,
        po.balance_payable,
        po.created_at,
        po.supplier_name,
        po.wheat_origin,
        u.display_name as created_by_name
    FROM purchase_orders_adnan po
    LEFT JOIN users u ON po.created_by_user_id = u.id
    WHERE po.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    AND po.po_status != 'cancelled'
    ORDER BY po.created_at DESC
    LIMIT 8
";

$stmt = $db->prepare($recent_pos_sql);
$stmt->execute();
$recent_pos = $stmt->fetchAll(PDO::FETCH_OBJ);

// Outstanding Payments (Urgent - unpaid/partial)
$urgent_payments_sql = "
    SELECT 
        po.id,
        po.po_number,
        po.po_date,
        po.balance_payable,
        po.supplier_id,
        po.supplier_name,
        po.total_received_value,
        po.total_paid,
        DATEDIFF(CURDATE(), po.po_date) as days_since_order
    FROM purchase_orders_adnan po
    WHERE po.payment_status IN ('unpaid', 'partial')
    AND po.po_status = 'active'
    AND po.balance_payable > 0
    ORDER BY po.po_date ASC
    LIMIT 8
";

$stmt = $db->prepare($urgent_payments_sql);
$stmt->execute();
$urgent_payments = $stmt->fetchAll(PDO::FETCH_OBJ);

// Recent Payments (last 7 days)
$recent_payments_sql = "
    SELECT 
        pmt.id,
        pmt.payment_voucher_number as payment_number,
        pmt.payment_date,
        pmt.amount_paid as amount,
        pmt.payment_method,
        pmt.is_posted,
        pmt.supplier_id,
        pmt.supplier_name,
        pmt.bank_name as account_name,
        pmt.created_at
    FROM purchase_payments_adnan pmt
    WHERE pmt.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY pmt.created_at DESC
    LIMIT 8
";

$stmt = $db->prepare($recent_payments_sql);
$stmt->execute();
$recent_payments = $stmt->fetchAll(PDO::FETCH_OBJ);

require_once '../templates/header.php';
?>

<div class="w-full px-4 py-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Purchase (Adnan) Dashboard</h1>
            <p class="text-gray-600 mt-1">Wheat Procurement Overview</p>
        </div>
        <div class="flex gap-2">
            <a href="purchase_adnan_create_po.php" class="bg-primary-600 text-white px-4 py-2 rounded-lg hover:bg-primary-700 flex items-center gap-2">
                <i class="fas fa-plus"></i> New Purchase Order
            </a>
        </div>
    </div>

    <!-- KPI Cards Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <!-- Total Orders This Year -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between mb-2">
                <div class="bg-primary-100 rounded-full p-3">
                    <i class="fas fa-shopping-cart text-primary-600 text-2xl"></i>
                </div>
                <span class="text-3xl font-bold text-gray-900"><?php echo number_format($total_pos['count']); ?></span>
            </div>
            <h3 class="text-sm font-medium text-gray-600 mb-1">Purchase Orders</h3>
            <p class="text-lg font-semibold text-gray-900">৳<?php echo number_format($total_pos['total'] / 1000000, 2); ?>M</p>
            <p class="text-xs text-gray-500 mt-1">This Year</p>
        </div>

        <!-- Pending Delivery -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between mb-2">
                <div class="bg-yellow-100 rounded-full p-3">
                    <i class="fas fa-truck-loading text-yellow-600 text-2xl"></i>
                </div>
                <span class="text-3xl font-bold text-yellow-600"><?php echo number_format($pending_delivery->count ?? 0); ?></span>
            </div>
            <h3 class="text-sm font-medium text-gray-600 mb-1">Pending Delivery</h3>
            <p class="text-lg font-semibold text-gray-900">৳<?php echo number_format(($pending_delivery->pending_amount ?? 0) / 1000000, 2); ?>M</p>
            <p class="text-xs text-gray-500 mt-1"><?php echo number_format($pending_delivery->pending_qty ?? 0); ?> KG pending</p>
        </div>

        <!-- Payments This Month -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between mb-2">
                <div class="bg-green-100 rounded-full p-3">
                    <i class="fas fa-money-bill-wave text-green-600 text-2xl"></i>
                </div>
                <span class="text-3xl font-bold text-green-600"><?php echo number_format($payments_this_month->count ?? 0); ?></span>
            </div>
            <h3 class="text-sm font-medium text-gray-600 mb-1">Payments</h3>
            <p class="text-lg font-semibold text-gray-900">৳<?php echo number_format(($payments_this_month->total ?? 0) / 1000000, 2); ?>M</p>
            <p class="text-xs text-gray-500 mt-1">This Month</p>
        </div>

        <!-- Balance Payable -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between mb-2">
                <div class="bg-red-100 rounded-full p-3">
                    <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
                </div>
                <span class="text-3xl font-bold text-red-600">৳<?php echo number_format($total_pos['balance_due'] / 1000000, 1); ?>M</span>
            </div>
            <h3 class="text-sm font-medium text-gray-600 mb-1">Outstanding</h3>
            <p class="text-lg font-semibold text-gray-900">Balance Payable</p>
            <p class="text-xs text-gray-500 mt-1">Total Due</p>
        </div>
    </div>

    <!-- Monthly Summary -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <!-- Wheat Purchases This Month -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center gap-2">
                <i class="fas fa-chart-line text-primary-600"></i> Wheat Purchases (This Month)
            </h2>
            <div class="grid grid-cols-3 gap-4">
                <div class="text-center p-4 bg-blue-50 rounded-lg">
                    <p class="text-sm text-gray-600 mb-1">Orders</p>
                    <p class="text-2xl font-bold text-blue-600"><?php echo number_format($wheat_purchases->total_orders ?? 0); ?></p>
                </div>
                <div class="text-center p-4 bg-green-50 rounded-lg">
                    <p class="text-sm text-gray-600 mb-1">Quantity</p>
                    <p class="text-2xl font-bold text-green-600"><?php echo number_format(($wheat_purchases->total_quantity ?? 0) / 1000, 1); ?>T</p>
                </div>
                <div class="text-center p-4 bg-purple-50 rounded-lg">
                    <p class="text-sm text-gray-600 mb-1">Value</p>
                    <p class="text-2xl font-bold text-purple-600">৳<?php echo number_format(($wheat_purchases->total_amount ?? 0) / 1000000, 2); ?>M</p>
                </div>
            </div>
        </div>

        <!-- Top Suppliers -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center gap-2">
                <i class="fas fa-star text-yellow-500"></i> Top Suppliers (This Month)
            </h2>
            <?php if (count($top_suppliers) > 0): ?>
            <div class="space-y-3">
                <?php foreach ($top_suppliers as $idx => $supplier): ?>
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100">
                    <div class="flex items-center gap-3">
                        <span class="flex items-center justify-center w-8 h-8 rounded-full bg-primary-600 text-white font-bold text-sm">
                            <?php echo $idx + 1; ?>
                        </span>
                        <div>
                            <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($supplier->company_name); ?></p>
                            <p class="text-xs text-gray-500"><?php echo $supplier->po_count; ?> orders</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="font-bold text-gray-900">৳<?php echo number_format($supplier->total_purchased / 1000, 0); ?>K</p>
                        <?php if ($supplier->balance_owed > 0): ?>
                        <p class="text-xs text-red-600">Due: ৳<?php echo number_format($supplier->balance_owed / 1000, 0); ?>K</p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="text-center text-gray-500 py-8">No purchases this month</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Activity Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <!-- Recent Purchase Orders -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-bold text-gray-900">Recent Purchase Orders</h2>
                <a href="purchase_adnan_index.php" class="text-sm text-primary-600 hover:text-primary-700 font-medium">View All →</a>
            </div>
            
            <?php if (count($recent_pos) > 0): ?>
            <div class="space-y-3">
                <?php foreach ($recent_pos as $po): ?>
                <?php
                    $status_colors = [
                        'pending' => 'bg-gray-100 text-gray-800',
                        'partial' => 'bg-yellow-100 text-yellow-800',
                        'completed' => 'bg-green-100 text-green-800',
                        'closed' => 'bg-red-100 text-red-800'
                    ];
                    $color = $status_colors[$po->status] ?? 'bg-gray-100 text-gray-800';
                ?>
                <div class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition">
                    <div class="flex items-start justify-between mb-2">
                        <div class="flex-1">
                            <a href="purchase_adnan_view_po.php?id=<?php echo $po->id; ?>" class="font-semibold text-primary-600 hover:text-primary-700 hover:underline">
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
                            <i class="fas fa-globe text-gray-400 mr-1"></i>
                            <?php echo htmlspecialchars($po->wheat_origin); ?>
                        </span>
                        <span class="text-gray-500">
                            <?php echo date('M d, Y', strtotime($po->po_date)); ?>
                        </span>
                        <span class="font-bold text-gray-900">
                            ৳<?php echo number_format($po->total_amount, 0); ?>
                        </span>
                    </div>
                    <?php if ($po->balance_payable > 0): ?>
                    <div class="mt-2 text-xs text-red-600">
                        <i class="fas fa-exclamation-circle"></i> Due: ৳<?php echo number_format($po->balance_payable, 0); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-center py-12">
                <i class="fas fa-file-invoice text-gray-300 text-5xl mb-4"></i>
                <p class="text-gray-500">No recent purchase orders</p>
                <a href="purchase_adnan_create_po.php" class="mt-4 inline-block text-primary-600 hover:text-primary-700 font-medium">
                    Create your first PO →
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Urgent Payments -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-bold text-gray-900">Outstanding Payments</h2>
            </div>
            
            <?php if (count($urgent_payments) > 0): ?>
            <div class="space-y-3">
                <?php foreach ($urgent_payments as $payment): ?>
                <div class="border rounded-lg p-4 <?php echo $payment->days_since_order > 30 ? 'border-red-300 bg-red-50' : ($payment->days_since_order > 14 ? 'border-orange-300 bg-orange-50' : 'border-gray-200'); ?>">
                    <div class="flex items-start justify-between mb-2">
                        <div class="flex-1">
                            <a href="purchase_adnan_view_po.php?id=<?php echo $payment->id; ?>" class="font-semibold <?php echo $payment->days_since_order > 30 ? 'text-red-700' : 'text-primary-600'; ?> hover:underline">
                                <?php echo htmlspecialchars($payment->po_number); ?>
                            </a>
                            <p class="text-sm text-gray-600 mt-1">
                                <?php echo htmlspecialchars($payment->supplier_name); ?>
                            </p>
                        </div>
                        <?php if ($payment->days_since_order > 30): ?>
                        <span class="px-2 py-1 text-xs font-bold rounded-full bg-red-100 text-red-800">
                            URGENT
                        </span>
                        <?php else: ?>
                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-orange-100 text-orange-800">
                            <?php echo $payment->days_since_order; ?> days
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="flex items-center justify-between text-sm mb-2">
                        <span class="text-gray-600">
                            Order: <?php echo date('M d, Y', strtotime($payment->po_date)); ?>
                        </span>
                        <span class="font-bold <?php echo $payment->days_since_order > 30 ? 'text-red-700' : 'text-gray-900'; ?>">
                            ৳<?php echo number_format($payment->balance_payable, 2); ?>
                        </span>
                    </div>
                    <div class="flex items-center justify-between text-xs text-gray-600">
                        <span>Received: ৳<?php echo number_format($payment->total_received_value, 0); ?></span>
                        <span>Paid: ৳<?php echo number_format($payment->total_paid, 0); ?></span>
                    </div>
                    <div class="mt-2">
                        <a href="purchase_adnan_record_payment.php?po_id=<?php echo $payment->id; ?>" 
                           class="text-xs font-medium <?php echo $payment->days_since_order > 30 ? 'text-red-600 hover:text-red-700' : 'text-primary-600 hover:text-primary-700'; ?>">
                            Make Payment →
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-center py-12">
                <i class="fas fa-check-circle text-green-400 text-5xl mb-4"></i>
                <p class="text-gray-500">No outstanding payments</p>
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
                            <span class="text-sm text-gray-900">
                                <?php echo htmlspecialchars($payment->supplier_name); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-gray-900">
                            ৳<?php echo number_format($payment->amount, 2); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <i class="fas fa-<?php 
                                echo $payment->payment_method === 'bank' ? 'university' : 
                                     ($payment->payment_method === 'cheque' ? 'money-check' : 'money-bill-wave'); 
                            ?> mr-1"></i>
                            <?php echo ucfirst(str_replace('_', ' ', $payment->payment_method)); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo htmlspecialchars($payment->account_name ?? 'N/A'); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $payment->is_posted ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                <?php echo $payment->is_posted ? 'Posted' : 'Pending'; ?>
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