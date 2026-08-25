<?php
require_once '../core/init.php';
restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$currentUser = getCurrentUser();

$supplier_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($supplier_id === 0) {
    redirect('suppliers.php', 'Invalid supplier', 'error');
}

// Database connection
$db = Database::getInstance()->getPdo();

// Get supplier details with Purchase Adnan aggregations
$supplier_sql = "
    SELECT 
        s.*,
        u.display_name as created_by_name,
        COUNT(DISTINCT po.id) as total_pos,
        COALESCE(SUM(CASE WHEN po.po_status != 'cancelled' THEN po.total_order_value ELSE 0 END), 0) as total_purchase_value,
        COALESCE(SUM(CASE WHEN po.po_status != 'cancelled' THEN po.total_received_value ELSE 0 END), 0) as total_received_value,
        COALESCE(SUM(CASE WHEN po.po_status != 'cancelled' THEN po.total_paid ELSE 0 END), 0) as total_paid,
        COALESCE(SUM(CASE WHEN po.payment_status IN ('unpaid', 'partial') THEN po.balance_payable ELSE 0 END), 0) as outstanding_amount,
        COUNT(DISTINCT pmt.id) as total_payments,
        COALESCE(SUM(pmt.amount_paid), 0) as total_payment_value
    FROM suppliers s
    LEFT JOIN users u ON s.created_by_user_id = u.id
    LEFT JOIN purchase_orders_adnan po ON s.id = po.supplier_id
    LEFT JOIN purchase_payments_adnan pmt ON s.id = pmt.supplier_id AND pmt.is_posted = 1
    WHERE s.id = ?
    GROUP BY s.id, s.uuid, s.supplier_code, s.company_name, s.contact_person, s.email,
             s.phone, s.mobile, s.address, s.city, s.country, s.tax_id, s.payment_terms,
             s.credit_limit, s.opening_balance, s.current_balance, s.supplier_type,
             s.status, s.notes, s.created_by_user_id, s.created_at, s.updated_at, u.display_name
";

$stmt = $db->prepare($supplier_sql);
$stmt->execute([$supplier_id]);
$supplier = $stmt->fetch(PDO::FETCH_OBJ);

if (!$supplier) {
    redirect('suppliers.php', 'Supplier not found', 'error');
}

$pageTitle = $supplier->company_name;

// Get recent purchase orders from Adnan module
$recent_pos_sql = "
    SELECT 
        po.id,
        po.po_number,
        po.po_date,
        po.quantity_kg,
        po.wheat_origin,
        po.total_order_value,
        po.delivery_status,
        po.payment_status,
        po.balance_payable,
        po.created_at
    FROM purchase_orders_adnan po
    WHERE po.supplier_id = ?
    AND po.po_status != 'cancelled'
    ORDER BY po.created_at DESC
    LIMIT 5
";
$stmt = $db->prepare($recent_pos_sql);
$stmt->execute([$supplier_id]);
$recent_pos = $stmt->fetchAll(PDO::FETCH_OBJ);

// Get recent payments from Adnan module
$recent_payments_sql = "
    SELECT 
        pmt.id,
        pmt.payment_voucher_number,
        pmt.payment_date,
        pmt.amount_paid,
        pmt.payment_method,
        pmt.bank_name,
        pmt.is_posted,
        pmt.created_at,
        pmt.po_number,
        pmt.purchase_order_id
    FROM purchase_payments_adnan pmt
    WHERE pmt.supplier_id = ?
    ORDER BY pmt.payment_date DESC
    LIMIT 5
";
$stmt = $db->prepare($recent_payments_sql);
$stmt->execute([$supplier_id]);
$recent_payments = $stmt->fetchAll(PDO::FETCH_OBJ);

// Get GRNs (Goods Received Notes)
$recent_grns_sql = "
    SELECT 
        grn.id,
        grn.grn_number,
        grn.grn_date,
        grn.quantity_received_kg AS received_quantity_kg,
        grn.truck_number,
        grn.total_value,
        grn.created_at,
        grn.po_number,
        grn.purchase_order_id,
        po.wheat_origin
    FROM goods_received_adnan grn
    LEFT JOIN purchase_orders_adnan po ON grn.purchase_order_id = po.id
    WHERE grn.supplier_id = ?
    ORDER BY grn.created_at DESC
    LIMIT 5
";
$stmt = $db->prepare($recent_grns_sql);
$stmt->execute([$supplier_id]);
$recent_grns = $stmt->fetchAll(PDO::FETCH_OBJ);

require_once '../templates/header.php';
?>

<div class="w-full px-4 py-6">

    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <h1 class="text-3xl font-bold text-gray-900"><?php echo htmlspecialchars($supplier->company_name); ?></h1>
                <?php
                $status_colors = [
                    'active' => 'bg-green-100 text-green-800',
                    'inactive' => 'bg-gray-100 text-gray-800',
                    'blocked' => 'bg-red-100 text-red-800'
                ];
                $color = $status_colors[$supplier->status] ?? 'bg-gray-100 text-gray-800';
                ?>
                <span class="px-3 py-1 text-sm font-medium rounded-full <?php echo $color; ?>">
                    <?php echo ucfirst($supplier->status); ?>
                </span>
            </div>
            <p class="text-gray-600"><?php echo htmlspecialchars($supplier->supplier_code); ?></p>
        </div>
        <div class="flex items-center gap-3">
            <a href="supplier_ledger.php?id=<?php echo $supplier_id; ?>" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition">
                <i class="fas fa-book mr-2"></i>View Ledger
            </a>
            <a href="supplier_edit.php?id=<?php echo $supplier_id; ?>" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg transition">
                <i class="fas fa-edit mr-2"></i>Edit
            </a>
            <a href="suppliers.php" class="text-gray-600 hover:text-gray-800">
                <i class="fas fa-arrow-left mr-2"></i>Back
            </a>
        </div>
    </div>

    <!-- Financial Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
        
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Current Balance</p>
                    <p class="text-2xl font-bold text-red-600 mt-2">৳<?php echo number_format($supplier->current_balance, 2); ?></p>
                    <p class="text-xs text-gray-500 mt-1">Amount we owe</p>
                </div>
                <div class="p-3 bg-red-100 rounded-full">
                    <i class="fas fa-wallet text-red-600 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Purchase Orders</p>
                    <p class="text-2xl font-bold text-blue-600 mt-2"><?php echo number_format($supplier->total_pos); ?></p>
                    <p class="text-xs text-gray-500 mt-1">৳<?php echo number_format($supplier->total_purchase_value, 0); ?></p>
                </div>
                <div class="p-3 bg-blue-100 rounded-full">
                    <i class="fas fa-file-invoice text-blue-600 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Total Payments</p>
                    <p class="text-2xl font-bold text-green-600 mt-2"><?php echo number_format($supplier->total_payments); ?></p>
                    <p class="text-xs text-gray-500 mt-1">৳<?php echo number_format($supplier->total_paid, 0); ?></p>
                </div>
                <div class="p-3 bg-green-100 rounded-full">
                    <i class="fas fa-money-bill-wave text-green-600 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Outstanding</p>
                    <p class="text-2xl font-bold text-orange-600 mt-2">৳<?php echo number_format($supplier->outstanding_amount, 2); ?></p>
                    <p class="text-xs text-gray-500 mt-1">Balance due</p>
                </div>
                <div class="p-3 bg-orange-100 rounded-full">
                    <i class="fas fa-exclamation-triangle text-orange-600 text-xl"></i>
                </div>
            </div>
        </div>

    </div>

    <!-- Supplier Information -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        
        <!-- Contact Information -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                <i class="fas fa-address-card text-primary-600"></i> Contact Information
            </h3>
            <div class="space-y-3 text-sm">
                <div>
                    <p class="text-gray-600">Contact Person</p>
                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($supplier->contact_person ?? 'N/A'); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Phone</p>
                    <p class="font-medium text-gray-900">
                        <i class="fas fa-phone text-primary-600 mr-1"></i>
                        <?php echo htmlspecialchars($supplier->phone ?? 'N/A'); ?>
                    </p>
                </div>
                <?php if ($supplier->mobile): ?>
                <div>
                    <p class="text-gray-600">Mobile</p>
                    <p class="font-medium text-gray-900">
                        <i class="fas fa-mobile-alt text-primary-600 mr-1"></i>
                        <?php echo htmlspecialchars($supplier->mobile); ?>
                    </p>
                </div>
                <?php endif; ?>
                <div>
                    <p class="text-gray-600">Email</p>
                    <p class="font-medium text-gray-900">
                        <i class="fas fa-envelope text-primary-600 mr-1"></i>
                        <?php echo htmlspecialchars($supplier->email ?? 'N/A'); ?>
                    </p>
                </div>
                <?php if ($supplier->address): ?>
                <div>
                    <p class="text-gray-600">Address</p>
                    <p class="font-medium text-gray-900"><?php echo nl2br(htmlspecialchars($supplier->address)); ?></p>
                </div>
                <?php endif; ?>
                <?php if ($supplier->city): ?>
                <div>
                    <p class="text-gray-600">City</p>
                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($supplier->city); ?></p>
                </div>
                <?php endif; ?>
                <?php if ($supplier->country): ?>
                <div>
                    <p class="text-gray-600">Country</p>
                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($supplier->country); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Business Details -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                <i class="fas fa-building text-primary-600"></i> Business Details
            </h3>
            <div class="space-y-3 text-sm">
                <div>
                    <p class="text-gray-600">Supplier Type</p>
                    <?php
                    $type_colors = [
                        'local' => 'bg-green-100 text-green-800',
                        'international' => 'bg-blue-100 text-blue-800',
                        'both' => 'bg-purple-100 text-purple-800'
                    ];
                    $color = $type_colors[$supplier->supplier_type] ?? 'bg-gray-100 text-gray-800';
                    ?>
                    <span class="inline-block mt-1 px-3 py-1 text-xs font-medium rounded-full <?php echo $color; ?>">
                        <?php echo ucfirst($supplier->supplier_type); ?>
                    </span>
                </div>
                <?php if ($supplier->tax_id): ?>
                <div>
                    <p class="text-gray-600">Tax ID / TIN</p>
                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($supplier->tax_id); ?></p>
                </div>
                <?php endif; ?>
                <div>
                    <p class="text-gray-600">Payment Terms</p>
                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($supplier->payment_terms ?? 'N/A'); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Credit Limit</p>
                    <p class="font-medium text-gray-900">৳<?php echo number_format($supplier->credit_limit, 2); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Opening Balance</p>
                    <p class="font-medium text-gray-900">৳<?php echo number_format($supplier->opening_balance, 2); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Created By</p>
                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($supplier->created_by_name ?? 'System'); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Created Date</p>
                    <p class="font-medium text-gray-900"><?php echo date('M d, Y', strtotime($supplier->created_at)); ?></p>
                </div>
            </div>
        </div>

        <!-- Notes -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                <i class="fas fa-sticky-note text-primary-600"></i> Notes
            </h3>
            <?php if ($supplier->notes): ?>
            <div class="text-sm text-gray-700 whitespace-pre-wrap">
                <?php echo nl2br(htmlspecialchars($supplier->notes)); ?>
            </div>
            <?php else: ?>
            <p class="text-sm text-gray-500 italic">No notes available</p>
            <?php endif; ?>
        </div>

    </div>

    <!-- Transaction History Tabs -->
    <div class="bg-white rounded-lg shadow-md" x-data="{ activeTab: 'orders' }">
        
        <!-- Tab Navigation -->
        <div class="border-b border-gray-200">
            <nav class="flex -mb-px">
                <button @click="activeTab = 'orders'" 
                        :class="activeTab === 'orders' ? 'border-primary-600 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="px-6 py-4 border-b-2 font-medium text-sm transition">
                    <i class="fas fa-shopping-cart mr-2"></i>Purchase Orders (<?php echo count($recent_pos); ?>)
                </button>
                <button @click="activeTab = 'grns'" 
                        :class="activeTab === 'grns' ? 'border-primary-600 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="px-6 py-4 border-b-2 font-medium text-sm transition">
                    <i class="fas fa-truck-loading mr-2"></i>Goods Received (<?php echo count($recent_grns); ?>)
                </button>
                <button @click="activeTab = 'payments'" 
                        :class="activeTab === 'payments' ? 'border-primary-600 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="px-6 py-4 border-b-2 font-medium text-sm transition">
                    <i class="fas fa-money-bill-wave mr-2"></i>Payments (<?php echo count($recent_payments); ?>)
                </button>
            </nav>
        </div>

        <div class="p-6">

            <!-- Purchase Orders Tab -->
            <div x-show="activeTab === 'orders'" x-cloak>
                <?php if (count($recent_pos) > 0): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">PO Number</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Wheat Origin</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Quantity (KG)</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($recent_pos as $po): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars($po->po_number); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo date('M d, Y', strtotime($po->po_date)); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo htmlspecialchars($po->wheat_origin); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                                    <?php echo number_format($po->quantity_kg, 0); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900 text-right">
                                    ৳<?php echo number_format($po->total_order_value, 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-red-600 text-right">
                                    ৳<?php echo number_format($po->balance_payable, 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <?php
                                    $status_colors = [
                                        'pending' => 'bg-gray-100 text-gray-800',
                                        'partial' => 'bg-yellow-100 text-yellow-800',
                                        'completed' => 'bg-green-100 text-green-800',
                                        'closed' => 'bg-red-100 text-red-800'
                                    ];
                                    $color = $status_colors[$po->delivery_status] ?? 'bg-gray-100 text-gray-800';
                                    ?>
                                    <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $color; ?>">
                                        <?php echo ucfirst($po->delivery_status); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                                    <a href="purchase_adnan_view_po.php?id=<?php echo $po->id; ?>" class="text-primary-600 hover:text-primary-800">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">
                    <a href="purchase_adnan_index.php?supplier_id=<?php echo $supplier_id; ?>" class="text-sm text-primary-600 hover:text-primary-700">
                        View all purchase orders →
                    </a>
                </div>
                <?php else: ?>
                <div class="text-center py-12">
                    <i class="fas fa-file-invoice text-gray-300 text-5xl mb-4"></i>
                    <p class="text-gray-500">No purchase orders yet</p>
                    <a href="purchase_adnan_create_po.php?supplier_id=<?php echo $supplier_id; ?>" class="mt-4 inline-block text-primary-600 hover:text-primary-700 font-medium">
                        <i class="fas fa-plus-circle mr-2"></i>Create First Purchase Order
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <!-- GRNs Tab -->
            <div x-show="activeTab === 'grns'" x-cloak>
                <?php if (count($recent_grns) > 0): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">GRN Number</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">PO Number</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Wheat Origin</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Truck#</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Received (KG)</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Value</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($recent_grns as $grn): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars($grn->grn_number); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo date('M d, Y', strtotime($grn->grn_date)); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <a href="purchase_adnan_view_po.php?id=<?php echo $grn->purchase_order_id ?? 0; ?>" class="text-primary-600 hover:underline">
                                        <?php echo htmlspecialchars($grn->po_number ?? 'N/A'); ?>
                                    </a>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo htmlspecialchars($grn->wheat_origin ?? 'N/A'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo htmlspecialchars($grn->truck_number ?? 'N/A'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 text-right">
                                    <?php echo number_format($grn->received_quantity_kg, 0); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900 text-right">
                                    ৳<?php echo number_format($grn->total_value, 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                                    <a href="purchase_adnan_grn_receipt.php?id=<?php echo $grn->id; ?>" class="text-primary-600 hover:text-primary-800" title="View Receipt">
                                        <i class="fas fa-receipt"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-12">
                    <i class="fas fa-truck-loading text-gray-300 text-5xl mb-4"></i>
                    <p class="text-gray-500">No goods received yet</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Payments Tab -->
            <div x-show="activeTab === 'payments'" x-cloak>
                <?php if (count($recent_payments) > 0): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payment #</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">PO Number</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Bank/Account</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($recent_payments as $payment): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars($payment->payment_voucher_number); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo date('M d, Y', strtotime($payment->payment_date)); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php if ($payment->po_number): ?>
                                    <a href="purchase_adnan_view_po.php?id=<?php echo $payment->purchase_order_id ?? 0; ?>" class="text-primary-600 hover:underline">
                                        <?php echo htmlspecialchars($payment->po_number); ?>
                                    </a>
                                    <?php else: ?>
                                    N/A
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <i class="fas fa-<?php 
                                        echo $payment->payment_method === 'bank' ? 'university' : 
                                             ($payment->payment_method === 'cheque' ? 'money-check' : 'money-bill-wave'); 
                                    ?> mr-1"></i>
                                    <?php echo ucfirst(str_replace('_', ' ', $payment->payment_method)); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo htmlspecialchars($payment->bank_name ?? 'N/A'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-green-600 text-right">
                                    ৳<?php echo number_format($payment->amount_paid, 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <?php
                                    $status_colors = [
                                        '0' => 'bg-yellow-100 text-yellow-800',
                                        '1' => 'bg-green-100 text-green-800'
                                    ];
                                    $color = $status_colors[$payment->is_posted] ?? 'bg-gray-100 text-gray-800';
                                    ?>
                                    <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $color; ?>">
                                        <?php echo $payment->is_posted ? 'Posted' : 'Pending'; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                                    <a href="purchase_adnan_payment_receipt.php?id=<?php echo $payment->id; ?>" class="text-primary-600 hover:text-primary-800" title="View Receipt">
                                        <i class="fas fa-receipt"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">
                    <a href="purchase_adnan_index.php?supplier_id=<?php echo $supplier_id; ?>" class="text-sm text-primary-600 hover:text-primary-700">
                        View all transactions →
                    </a>
                </div>
                <?php else: ?>
                <div class="text-center py-12">
                    <i class="fas fa-money-bill-wave text-gray-300 text-5xl mb-4"></i>
                    <p class="text-gray-500">No payments yet</p>
                </div>
                <?php endif; ?>
            </div>

        </div>

    </div>

</div>

<!-- Alpine.js for tabs -->
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>

<?php require_once '../templates/footer.php'; ?>