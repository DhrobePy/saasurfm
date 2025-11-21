<?php
require_once '../core/init.php';

global $db;

restrict_access();

$currentUser = getCurrentUser();

$supplier_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($supplier_id === 0) {
    redirect('suppliers.php', 'Invalid supplier', 'error');
}

// Get supplier details
$supplier_sql = "
    SELECT 
        s.*,
        u.display_name as created_by_name,
        COUNT(DISTINCT po.id) as total_pos,
        COALESCE(SUM(CASE WHEN po.status NOT IN ('draft', 'cancelled') THEN po.total_amount ELSE 0 END), 0) as total_purchase_value,
        COUNT(DISTINCT pi.id) as total_invoices,
        COALESCE(SUM(pi.total_amount), 0) as total_invoice_value,
        COALESCE(SUM(CASE WHEN pi.payment_status IN ('unpaid', 'partially_paid') THEN pi.balance_due ELSE 0 END), 0) as outstanding_invoices,
        COUNT(DISTINCT sp.id) as total_payments,
        COALESCE(SUM(sp.amount), 0) as total_paid
    FROM suppliers s
    LEFT JOIN users u ON s.created_by_user_id = u.id
    LEFT JOIN purchase_orders po ON s.id = po.supplier_id
    LEFT JOIN purchase_invoices pi ON s.id = pi.supplier_id AND pi.status = 'posted'
    LEFT JOIN supplier_payments sp ON s.id = sp.supplier_id AND sp.status IN ('pending', 'cleared')
    WHERE s.id = :id
    GROUP BY s.id, s.uuid, s.supplier_code, s.company_name, s.contact_person, s.email,
             s.phone, s.mobile, s.address, s.city, s.country, s.tax_id, s.payment_terms,
             s.credit_limit, s.opening_balance, s.current_balance, s.supplier_type,
             s.status, s.notes, s.created_by_user_id, s.created_at, s.updated_at, u.display_name
";

$db->query($supplier_sql, ['id' => $supplier_id]);
$supplier = $db->first();

if (!$supplier) {
    redirect('suppliers.php', 'Supplier not found', 'error');
}

$pageTitle = $supplier->company_name;

// Get recent purchase orders
$recent_pos_sql = "
    SELECT po.*, b.name as branch_name
    FROM purchase_orders po
    LEFT JOIN branches b ON po.branch_id = b.id
    WHERE po.supplier_id = :supplier_id
    ORDER BY po.created_at DESC
    LIMIT 5
";
$db->query($recent_pos_sql, ['supplier_id' => $supplier_id]);
$recent_pos = $db->results();

// Get recent invoices
$recent_invoices_sql = "
    SELECT pi.*
    FROM purchase_invoices pi
    WHERE pi.supplier_id = :supplier_id
    ORDER BY pi.created_at DESC
    LIMIT 5
";
$db->query($recent_invoices_sql, ['supplier_id' => $supplier_id]);
$recent_invoices = $db->results();

// Get recent payments
$recent_payments_sql = "
    SELECT sp.*, c.name as account_name
    FROM supplier_payments sp
    LEFT JOIN chart_of_accounts c ON sp.payment_account_id = c.id
    WHERE sp.supplier_id = :supplier_id
    ORDER BY sp.payment_date DESC
    LIMIT 5
";
$db->query($recent_payments_sql, ['supplier_id' => $supplier_id]);
$recent_payments = $db->results();

require_once '../templates/header.php';
?>

<div class="container mx-auto">

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
            <a href="supplier_form.php?id=<?php echo $supplier_id; ?>" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg transition">
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
                    <p class="text-2xl font-bold text-red-600 mt-2">BDT <?php echo number_format($supplier->current_balance, 2); ?></p>
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
                    <p class="text-xs text-gray-500 mt-1">BDT <?php echo number_format($supplier->total_purchase_value, 2); ?></p>
                </div>
                <div class="p-3 bg-blue-100 rounded-full">
                    <i class="fas fa-file-invoice text-blue-600 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Invoices</p>
                    <p class="text-2xl font-bold text-indigo-600 mt-2"><?php echo number_format($supplier->total_invoices); ?></p>
                    <p class="text-xs text-gray-500 mt-1">BDT <?php echo number_format($supplier->total_invoice_value, 2); ?></p>
                </div>
                <div class="p-3 bg-indigo-100 rounded-full">
                    <i class="fas fa-file-invoice-dollar text-indigo-600 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Total Paid</p>
                    <p class="text-2xl font-bold text-green-600 mt-2">BDT <?php echo number_format($supplier->total_paid, 2); ?></p>
                    <p class="text-xs text-gray-500 mt-1"><?php echo number_format($supplier->total_payments); ?> payments</p>
                </div>
                <div class="p-3 bg-green-100 rounded-full">
                    <i class="fas fa-money-bill-wave text-green-600 text-xl"></i>
                </div>
            </div>
        </div>

    </div>

    <!-- Details and Info -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        
        <!-- Basic Information -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-900 mb-4 pb-2 border-b">Basic Information</h2>
            
            <div class="space-y-4">
                <div>
                    <p class="text-sm text-gray-600">Supplier Code</p>
                    <p class="text-base font-semibold text-gray-900"><?php echo htmlspecialchars($supplier->supplier_code ?? 'N/A'); ?></p>
                </div>
                
                <div>
                    <p class="text-sm text-gray-600">Supplier Type</p>
                    <p class="text-base font-semibold text-gray-900">
                        <?php
                        $type_icons = [
                            'local' => 'fa-home',
                            'international' => 'fa-globe',
                            'both' => 'fa-globe-americas'
                        ];
                        $icon = $type_icons[$supplier->supplier_type] ?? 'fa-tag';
                        ?>
                        <i class="fas <?php echo $icon; ?> mr-2"></i><?php echo ucfirst($supplier->supplier_type); ?>
                    </p>
                </div>
                
                <div>
                    <p class="text-sm text-gray-600">Tax ID / VAT / TIN</p>
                    <p class="text-base font-semibold text-gray-900"><?php echo htmlspecialchars($supplier->tax_id ?? 'N/A'); ?></p>
                </div>
                
                <div>
                    <p class="text-sm text-gray-600">Payment Terms</p>
                    <p class="text-base font-semibold text-gray-900"><?php echo htmlspecialchars($supplier->payment_terms ?? 'N/A'); ?></p>
                </div>
                
                <div>
                    <p class="text-sm text-gray-600">Credit Limit</p>
                    <p class="text-base font-semibold text-gray-900">BDT <?php echo number_format($supplier->credit_limit, 2); ?></p>
                </div>
                
                <div>
                    <p class="text-sm text-gray-600">Created By</p>
                    <p class="text-base font-semibold text-gray-900"><?php echo htmlspecialchars($supplier->created_by_name ?? 'N/A'); ?></p>
                </div>
                
                <div>
                    <p class="text-sm text-gray-600">Created At</p>
                    <p class="text-base font-semibold text-gray-900"><?php echo date('M d, Y h:i A', strtotime($supplier->created_at)); ?></p>
                </div>
            </div>
        </div>

        <!-- Contact Information -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-900 mb-4 pb-2 border-b">Contact Information</h2>
            
            <div class="space-y-4">
                <div>
                    <p class="text-sm text-gray-600">Contact Person</p>
                    <p class="text-base font-semibold text-gray-900"><?php echo htmlspecialchars($supplier->contact_person ?? 'N/A'); ?></p>
                </div>
                
                <?php if ($supplier->email): ?>
                <div>
                    <p class="text-sm text-gray-600">Email</p>
                    <p class="text-base font-semibold text-gray-900">
                        <a href="mailto:<?php echo htmlspecialchars($supplier->email); ?>" class="text-primary-600 hover:text-primary-700">
                            <i class="fas fa-envelope mr-2"></i><?php echo htmlspecialchars($supplier->email); ?>
                        </a>
                    </p>
                </div>
                <?php endif; ?>
                
                <?php if ($supplier->phone): ?>
                <div>
                    <p class="text-sm text-gray-600">Phone</p>
                    <p class="text-base font-semibold text-gray-900">
                        <a href="tel:<?php echo htmlspecialchars($supplier->phone); ?>" class="text-primary-600 hover:text-primary-700">
                            <i class="fas fa-phone mr-2"></i><?php echo htmlspecialchars($supplier->phone); ?>
                        </a>
                    </p>
                </div>
                <?php endif; ?>
                
                <?php if ($supplier->mobile): ?>
                <div>
                    <p class="text-sm text-gray-600">Mobile</p>
                    <p class="text-base font-semibold text-gray-900">
                        <a href="tel:<?php echo htmlspecialchars($supplier->mobile); ?>" class="text-primary-600 hover:text-primary-700">
                            <i class="fas fa-mobile-alt mr-2"></i><?php echo htmlspecialchars($supplier->mobile); ?>
                        </a>
                    </p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Address Information -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-900 mb-4 pb-2 border-b">Address</h2>
            
            <div class="space-y-4">
                <?php if ($supplier->address): ?>
                <div>
                    <p class="text-sm text-gray-600">Street Address</p>
                    <p class="text-base font-semibold text-gray-900"><?php echo nl2br(htmlspecialchars($supplier->address)); ?></p>
                </div>
                <?php endif; ?>
                
                <?php if ($supplier->city): ?>
                <div>
                    <p class="text-sm text-gray-600">City</p>
                    <p class="text-base font-semibold text-gray-900"><?php echo htmlspecialchars($supplier->city); ?></p>
                </div>
                <?php endif; ?>
                
                <?php if ($supplier->country): ?>
                <div>
                    <p class="text-sm text-gray-600">Country</p>
                    <p class="text-base font-semibold text-gray-900"><?php echo htmlspecialchars($supplier->country); ?></p>
                </div>
                <?php endif; ?>
                
                <?php if ($supplier->notes): ?>
                <div>
                    <p class="text-sm text-gray-600">Notes</p>
                    <p class="text-base text-gray-700"><?php echo nl2br(htmlspecialchars($supplier->notes)); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- Recent Activity Tabs -->
    <div class="bg-white rounded-lg shadow-md" x-data="{ activeTab: 'pos' }">
        
        <!-- Tab Headers -->
        <div class="border-b border-gray-200">
            <div class="flex gap-4 px-6">
                <button @click="activeTab = 'pos'" 
                        :class="activeTab === 'pos' ? 'border-primary-600 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                        class="py-4 px-1 border-b-2 font-medium text-sm transition">
                    Purchase Orders (<?php echo $supplier->total_pos; ?>)
                </button>
                <button @click="activeTab = 'invoices'" 
                        :class="activeTab === 'invoices' ? 'border-primary-600 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                        class="py-4 px-1 border-b-2 font-medium text-sm transition">
                    Invoices (<?php echo $supplier->total_invoices; ?>)
                </button>
                <button @click="activeTab = 'payments'" 
                        :class="activeTab === 'payments' ? 'border-primary-600 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                        class="py-4 px-1 border-b-2 font-medium text-sm transition">
                    Payments (<?php echo $supplier->total_payments; ?>)
                </button>
            </div>
        </div>

        <!-- Tab Content -->
        <div class="p-6">
            
            <!-- Purchase Orders Tab -->
            <div x-show="activeTab === 'pos'">
                <?php if (count($recent_pos) > 0): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">PO Number</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Branch</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
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
                                    <?php echo htmlspecialchars($po->branch_name); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900 text-right">
                                    BDT <?php echo number_format($po->total_amount, 2); ?>
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
                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                                    <a href="view_po.php?id=<?php echo $po->id; ?>" class="text-primary-600 hover:text-primary-800">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">
                    <a href="purchase_orders.php?supplier_id=<?php echo $supplier_id; ?>" class="text-sm text-primary-600 hover:text-primary-700">
                        View all purchase orders →
                    </a>
                </div>
                <?php else: ?>
                <div class="text-center py-12">
                    <i class="fas fa-file-invoice text-gray-300 text-5xl mb-4"></i>
                    <p class="text-gray-500">No purchase orders yet</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Invoices Tab -->
            <div x-show="activeTab === 'invoices'" x-cloak>
                <?php if (count($recent_invoices) > 0): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice Number</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($recent_invoices as $invoice): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars($invoice->invoice_number); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo date('M d, Y', strtotime($invoice->invoice_date)); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900 text-right">
                                    BDT <?php echo number_format($invoice->total_amount, 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-red-600 text-right">
                                    BDT <?php echo number_format($invoice->balance_due, 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <?php
                                    $status_colors = [
                                        'unpaid' => 'bg-red-100 text-red-800',
                                        'partially_paid' => 'bg-yellow-100 text-yellow-800',
                                        'paid' => 'bg-green-100 text-green-800'
                                    ];
                                    $color = $status_colors[$invoice->payment_status] ?? 'bg-gray-100 text-gray-800';
                                    ?>
                                    <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $color; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $invoice->payment_status)); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                                    <a href="view_invoice.php?id=<?php echo $invoice->id; ?>" class="text-primary-600 hover:text-primary-800">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">
                    <a href="invoices.php?supplier_id=<?php echo $supplier_id; ?>" class="text-sm text-primary-600 hover:text-primary-700">
                        View all invoices →
                    </a>
                </div>
                <?php else: ?>
                <div class="text-center py-12">
                    <i class="fas fa-file-invoice-dollar text-gray-300 text-5xl mb-4"></i>
                    <p class="text-gray-500">No invoices yet</p>
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
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payment Number</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Account</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($recent_payments as $payment): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars($payment->payment_number); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo date('M d, Y', strtotime($payment->payment_date)); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo ucfirst(str_replace('_', ' ', $payment->payment_method)); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo htmlspecialchars($payment->account_name ?? 'N/A'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-green-600 text-right">
                                    BDT <?php echo number_format($payment->amount, 2); ?>
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
                <div class="mt-4">
                    <a href="payments.php?supplier_id=<?php echo $supplier_id; ?>" class="text-sm text-primary-600 hover:text-primary-700">
                        View all payments →
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

<?php require_once '../templates/footer.php'; ?>