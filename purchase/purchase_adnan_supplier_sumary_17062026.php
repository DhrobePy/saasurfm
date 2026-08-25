<?php
/**
 * Purchase (Adnan) Module - Supplier Summary
 * Shows supplier-wise summary of all purchase transactions
 * FIXED: Only shows POSTED payments and VERIFIED/POSTED GRNs, excludes cancelled/deleted records
 * MODIFIED: Received value based on EXPECTED quantity × unit price
 * 
 * @package Ujjal Flour Mills
 * @subpackage Purchase (Adnan) Module
 */

require_once '../core/init.php';
require_once '../core/config/config.php';
require_once '../core/classes/Database.php';
require_once '../core/functions/helpers.php';

// Restrict access
restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$pageTitle = "Supplier Summary - Purchase (Adnan)";

$db = Database::getInstance()->getPdo();

// Get filters
$status_filter = $_GET['status'] ?? 'active';
$search = $_GET['search'] ?? '';

// ============================================================================
// Build query with proper filters for posted/verified transactions only
// ============================================================================
$sql = "SELECT
            s.id,
            s.supplier_code,
            s.company_name,
            s.phone,
            s.email,
            s.payment_terms,
            s.status,
            -- Orders count (exclude cancelled)
            COUNT(DISTINCT po.id) as total_orders,

            -- Ordered quantities and values
            COALESCE(SUM(po.quantity_kg), 0) as total_ordered_kg,
            COALESCE(SUM(po.total_order_value), 0) as total_ordered_value,

            -- Received quantities - only from VERIFIED/POSTED GRNs
            COALESCE((
                SELECT SUM(grn.quantity_received_kg)
                FROM goods_received_adnan grn
                WHERE grn.supplier_id = s.id
                AND grn.grn_status IN ('verified', 'posted')
            ), 0) as total_received_kg,

            -- Receivable value = Expected quantity × PO contract rate (matches index page calculation)
            COALESCE((
                SELECT SUM(grn.expected_quantity * po_r.unit_price_per_kg)
                FROM goods_received_adnan grn
                LEFT JOIN purchase_orders_adnan po_r ON grn.purchase_order_id = po_r.id
                WHERE grn.supplier_id = s.id
                AND grn.grn_status IN ('verified', 'posted')
            ), 0) as total_receivable_value,

            -- Total paid - only POSTED payments
            COALESCE((
                SELECT SUM(p.amount_paid)
                FROM purchase_payments_adnan p
                JOIN purchase_orders_adnan po2 ON p.purchase_order_id = po2.id
                WHERE po2.supplier_id = s.id
                AND p.is_posted = 1
            ), 0) as total_paid,

            -- Adjustment debits (add_payable / reduce_receivable) — posted only
            COALESCE((
                SELECT SUM(adj.debit)
                FROM supplier_ledger_adjustments adj
                WHERE adj.supplier_id = s.id AND adj.status = 'posted'
            ), 0) as total_adj_debit,

            -- Adjustment credits (reduce_payable / add_receivable) — posted only
            COALESCE((
                SELECT SUM(adj.credit)
                FROM supplier_ledger_adjustments adj
                WHERE adj.supplier_id = s.id AND adj.status = 'posted'
            ), 0) as total_adj_credit,

            -- Count of posted adjustments (for badge display)
            (
                SELECT COUNT(*)
                FROM supplier_ledger_adjustments adj
                WHERE adj.supplier_id = s.id AND adj.status = 'posted'
            ) as adj_count,

            -- Last order date
            MAX(po.po_date) as last_order_date,

            -- Active orders count
            COUNT(DISTINCT CASE WHEN po.po_status = 'approved' THEN po.id END) as active_orders,

            -- Completed orders count
            COUNT(DISTINCT CASE WHEN po.delivery_status = 'completed' THEN po.id END) as completed_orders

        FROM suppliers s
        LEFT JOIN purchase_orders_adnan po ON s.id = po.supplier_id
            AND po.po_status != 'cancelled'  -- Exclude cancelled POs
        WHERE 1=1";

$params = [];

if ($status_filter !== 'all') {
    $sql .= " AND s.status = ?";
    $params[] = $status_filter;
}

if ($search) {
    $sql .= " AND (s.company_name LIKE ? OR s.supplier_code LIKE ? OR s.phone LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$sql .= " GROUP BY s.id 
          ORDER BY total_paid DESC, total_orders DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$suppliers = $stmt->fetchAll(PDO::FETCH_OBJ);

// ============================================================================
// Calculate balance_payable for each supplier, including adjustments
// Formula: (GRN Debits + Adj Debits) − (Payments + Adj Credits)
// ============================================================================
foreach ($suppliers as $supplier) {
    $supplier->net_adjustment = $supplier->total_adj_debit - $supplier->total_adj_credit;
    $supplier->balance_payable = ($supplier->total_receivable_value + $supplier->total_adj_debit)
                               - ($supplier->total_paid             + $supplier->total_adj_credit);
}

// ============================================================================
// Calculate grand totals
// ============================================================================
$grand_totals = [
    'suppliers'        => count($suppliers),
    'total_orders'     => 0,
    'ordered_kg'       => 0,
    'ordered_value'    => 0,
    'received_kg'      => 0,
    'receivable_value' => 0,
    'paid'             => 0,
    'adj_debit'        => 0,
    'adj_credit'       => 0,
    'balance'          => 0,
    'advance'          => 0,
    'suppliers_with_adj' => 0,
];

foreach ($suppliers as $supplier) {
    $grand_totals['total_orders']     += $supplier->total_orders;
    $grand_totals['ordered_kg']       += $supplier->total_ordered_kg;
    $grand_totals['ordered_value']    += $supplier->total_ordered_value;
    $grand_totals['received_kg']      += $supplier->total_received_kg;
    $grand_totals['receivable_value'] += $supplier->total_receivable_value;
    $grand_totals['paid']             += $supplier->total_paid;
    $grand_totals['adj_debit']        += $supplier->total_adj_debit;
    $grand_totals['adj_credit']       += $supplier->total_adj_credit;
    if ($supplier->adj_count > 0) $grand_totals['suppliers_with_adj']++;

    // Split balance into due vs advance
    if ($supplier->balance_payable > 0) {
        $grand_totals['balance'] += $supplier->balance_payable;
    } else {
        $grand_totals['advance'] += abs($supplier->balance_payable);
    }
}
$grand_totals['net_adj'] = $grand_totals['adj_debit'] - $grand_totals['adj_credit'];

include '../templates/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <!-- Page Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Supplier Summary</h1>
            <p class="text-gray-600 mt-1">Comprehensive supplier-wise purchase overview</p>
            <p class="text-xs text-gray-500 mt-1">
                <i class="fas fa-info-circle mr-1"></i>
                <strong>Note:</strong> Receivable = Expected Qty × <strong>PO Contract Rate</strong> (verified/posted GRNs) ·
                Balance = (Receivable + Adj Debits) − (Paid + Adj Credits)
            </p>
        </div>
        <div class="flex gap-2">
            <a href="purchase_adnan_index.php" class="bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 flex items-center gap-2">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            <a href="supplier_form.php" class="bg-primary-600 text-white px-4 py-2 rounded-lg hover:bg-primary-700 flex items-center gap-2">
                <i class="fas fa-plus-circle"></i> Add Supplier
            </a>
            <button onclick="window.print()" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 flex items-center gap-2">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>

    <!-- Grand Totals Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-4">
        <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-blue-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600 font-medium">Total Suppliers</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1"><?php echo number_format($grand_totals['suppliers']); ?></p>
                    <?php if ($grand_totals['suppliers_with_adj'] > 0): ?>
                    <p class="text-xs text-indigo-600 mt-1">
                        <i class="fas fa-sliders-h mr-1"></i><?php echo $grand_totals['suppliers_with_adj']; ?> with adjustments
                    </p>
                    <?php endif; ?>
                </div>
                <div class="bg-blue-100 rounded-full p-3">
                    <i class="fas fa-users text-blue-600 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-purple-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600 font-medium">Total Orders</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1"><?php echo number_format($grand_totals['total_orders']); ?></p>
                </div>
                <div class="bg-purple-100 rounded-full p-3">
                    <i class="fas fa-file-invoice text-purple-600 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-green-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600 font-medium">Total Paid</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1">৳<?php echo number_format($grand_totals['paid'], 0); ?></p>
                    <p class="text-xs text-gray-500 mt-1">Posted payments only</p>
                </div>
                <div class="bg-green-100 rounded-full p-3">
                    <i class="fas fa-money-bill-wave text-green-600 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-red-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600 font-medium">Total Balance Due</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1">৳<?php echo number_format($grand_totals['balance'], 0); ?></p>
                    <p class="text-xs text-gray-500 mt-1">Incl. adjustments</p>
                    <?php if ($grand_totals['advance'] > 0): ?>
                    <p class="text-xs text-blue-600 mt-0.5">
                        <i class="fas fa-arrow-up mr-1"></i>৳<?php echo number_format($grand_totals['advance'], 0); ?> advance across suppliers
                    </p>
                    <?php endif; ?>
                </div>
                <div class="bg-red-100 rounded-full p-3">
                    <i class="fas fa-exclamation-circle text-red-600 text-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Adjustments summary bar (only shown when there are adjustments) -->
    <?php if ($grand_totals['adj_debit'] > 0 || $grand_totals['adj_credit'] > 0): ?>
    <div class="bg-indigo-50 border border-indigo-200 rounded-lg px-5 py-3 mb-6 flex flex-wrap items-center gap-6 text-sm">
        <span class="font-semibold text-indigo-700 flex items-center gap-2">
            <i class="fas fa-sliders-h"></i> Ledger Adjustments (All-time, posted)
        </span>
        <span class="text-red-600">
            <strong>Adj Debits:</strong> ৳<?php echo number_format($grand_totals['adj_debit'], 2); ?>
            <span class="text-gray-400 text-xs ml-1">(Add Payable / Reduce Receivable)</span>
        </span>
        <span class="text-green-600">
            <strong>Adj Credits:</strong> ৳<?php echo number_format($grand_totals['adj_credit'], 2); ?>
            <span class="text-gray-400 text-xs ml-1">(Reduce Payable / Add Receivable)</span>
        </span>
        <span class="font-bold <?php echo $grand_totals['net_adj'] >= 0 ? 'text-red-700' : 'text-green-700'; ?>">
            Net Adj Effect:
            <?php echo $grand_totals['net_adj'] >= 0 ? '+' : ''; ?>৳<?php echo number_format($grand_totals['net_adj'], 2); ?>
            <span class="text-xs font-normal text-gray-500 ml-1">
                (<?php echo $grand_totals['net_adj'] >= 0 ? 'increases total payable' : 'reduces total payable'; ?>)
            </span>
        </span>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-6 no-print">
        <form method="GET" class="flex gap-4">
            <div class="flex-1">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="Search by supplier name, code, phone..." 
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
            </div>
            <div>
                <select name="status" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Suppliers</option>
                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active Only</option>
                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive Only</option>
                </select>
            </div>
            <button type="submit" class="bg-primary-600 text-white px-6 py-2 rounded-lg hover:bg-primary-700">
                <i class="fas fa-search mr-2"></i>Search
            </button>
            <a href="purchase_adnan_supplier_summary.php" class="bg-gray-200 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-300">
                <i class="fas fa-times mr-2"></i>Clear
            </a>
        </form>
    </div>

    <!-- Suppliers Table -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">
                Supplier Breakdown
                <span class="text-sm font-normal text-gray-600">(<?php echo count($suppliers); ?> suppliers)</span>
            </h3>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left   text-xs font-medium text-gray-500   uppercase tracking-wider">Supplier</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500   uppercase tracking-wider">Orders</th>
                        <th class="px-6 py-3 text-right  text-xs font-medium text-gray-500   uppercase tracking-wider">Ordered (KG)</th>
                        <th class="px-6 py-3 text-right  text-xs font-medium text-gray-500   uppercase tracking-wider">Ordered Value (৳)</th>
                        <th class="px-6 py-3 text-right  text-xs font-medium text-gray-500   uppercase tracking-wider">Received (KG)</th>
                        <th class="px-6 py-3 text-right  text-xs font-medium text-purple-600 uppercase tracking-wider">Receivable (৳)*</th>
                        <th class="px-6 py-3 text-right  text-xs font-medium text-indigo-500 uppercase tracking-wider">Net Adj (৳)</th>
                        <th class="px-6 py-3 text-right  text-xs font-medium text-green-500  uppercase tracking-wider">Paid (৳)</th>
                        <th class="px-6 py-3 text-right  text-xs font-medium text-red-500    uppercase tracking-wider">Balance Due (৳)</th>
                        <th class="px-6 py-3 text-right  text-xs font-medium text-blue-500   uppercase tracking-wider">Advance (৳)</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500   uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500   uppercase tracking-wider">Actions</th>
                    </tr>
                    <tr class="bg-gray-100 text-xs">
                        <th colspan="5"></th>
                        <th class="px-6 py-2 text-right text-gray-500 italic">(Expected Qty × PO Rate)</th>
                        <th class="px-6 py-2 text-right text-gray-400 italic">(+debit −credit)</th>
                        <th colspan="5"></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($suppliers)): ?>
                        <tr>
                            <td colspan="11" class="px-6 py-8 text-center text-gray-500">
                                <i class="fas fa-inbox text-4xl text-gray-300 mb-2"></i>
                                <p>No suppliers found</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($suppliers as $supplier): 
                            // Calculate advance from balance_payable
                            $advance = $supplier->balance_payable < 0 ? abs($supplier->balance_payable) : 0;
                            $actual_balance = $supplier->balance_payable > 0 ? $supplier->balance_payable : 0;
                        ?>
                            <tr class="hover:bg-gray-50">
                                <!-- Supplier Info -->
                                <td class="px-6 py-4">
                                    <div>
                                        <a href="purchase_adnan_supplier_ledger.php?supplier_id=<?php echo $supplier->id; ?>" 
                                           class="text-primary-600 hover:text-primary-800 font-semibold">
                                            <?php echo htmlspecialchars($supplier->company_name); ?>
                                        </a>
                                        <?php if (!empty($supplier->supplier_code)): ?>
                                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($supplier->supplier_code); ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($supplier->phone)): ?>
                                            <p class="text-xs text-gray-500">
                                                <i class="fas fa-phone text-gray-400 mr-1"></i><?php echo htmlspecialchars($supplier->phone); ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if (!empty($supplier->last_order_date)): ?>
                                            <p class="text-xs text-gray-400 mt-1">
                                                Last Order: <?php echo date('d M Y', strtotime($supplier->last_order_date)); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                
                                <!-- Orders -->
                                <td class="px-6 py-4 text-center">
                                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-blue-100 text-blue-700 text-sm font-bold">
                                        <?php echo number_format($supplier->total_orders); ?>
                                    </span>
                                    <?php if ($supplier->active_orders > 0): ?>
                                        <p class="text-xs text-green-600 mt-1"><?php echo $supplier->active_orders; ?> active</p>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Ordered KG -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900 font-semibold">
                                    <?php echo number_format($supplier->total_ordered_kg, 0); ?>
                                </td>
                                
                                <!-- Ordered Value -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                                    ৳<?php echo number_format($supplier->total_ordered_value, 0); ?>
                                </td>
                                
                                <!-- Received KG -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold
                                    <?php 
                                    if ($supplier->total_received_kg >= $supplier->total_ordered_kg) {
                                        echo 'text-green-600';
                                    } elseif ($supplier->total_received_kg > 0) {
                                        echo 'text-yellow-600';
                                    } else {
                                        echo 'text-gray-400';
                                    }
                                    ?>">
                                    <?php echo number_format($supplier->total_received_kg, 2); ?>
                                    <p class="text-xs text-gray-500 font-normal mt-0.5">Verified only</p>
                                </td>
                                
                                <!-- Receivable Value (Expected Qty × Price) -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-purple-600 font-semibold">
                                    ৳<?php echo number_format($supplier->total_receivable_value, 0); ?>
                                </td>

                                <!-- Net Adjustment -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold">
                                    <?php if ($supplier->adj_count == 0): ?>
                                        <span class="text-gray-300">—</span>
                                    <?php elseif ($supplier->net_adjustment > 0): ?>
                                        <span class="text-red-500">
                                            +৳<?php echo number_format($supplier->net_adjustment, 0); ?>
                                        </span>
                                        <p class="text-xs text-gray-400 font-normal mt-0.5"><?php echo $supplier->adj_count; ?> adj</p>
                                    <?php elseif ($supplier->net_adjustment < 0): ?>
                                        <span class="text-green-600">
                                            −৳<?php echo number_format(abs($supplier->net_adjustment), 0); ?>
                                        </span>
                                        <p class="text-xs text-gray-400 font-normal mt-0.5"><?php echo $supplier->adj_count; ?> adj</p>
                                    <?php else: ?>
                                        <span class="text-gray-400 text-xs">
                                            ±0 <span class="text-gray-300">(<?php echo $supplier->adj_count; ?> adj cancel out)</span>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Paid -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-green-600 font-semibold">
                                    ৳<?php echo number_format($supplier->total_paid, 0); ?>
                                    <p class="text-xs text-gray-500 font-normal mt-0.5">Posted only</p>
                                </td>

                                <!-- Balance Due -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold">
                                    <?php if ($actual_balance > 0): ?>
                                        <span class="text-red-600">৳<?php echo number_format($actual_balance, 0); ?></span>
                                        <?php if ($supplier->adj_count > 0): ?>
                                        <p class="text-xs text-indigo-400 font-normal mt-0.5">incl. adj</p>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-gray-300">—</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Advance -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold">
                                    <?php if ($advance > 0): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 text-xs font-bold">
                                            <i class="fas fa-arrow-up text-[9px]"></i>৳<?php echo number_format($advance, 0); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-300">—</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Status -->
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <span class="px-3 py-1 rounded-full text-xs font-bold uppercase <?php echo $supplier->status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'; ?>">
                                        <?php echo $supplier->status; ?>
                                    </span>
                                </td>
                                
                                <!-- Actions -->
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <a href="purchase_adnan_supplier_ledger.php?supplier_id=<?php echo $supplier->id; ?>" 
                                       class="text-primary-600 hover:text-primary-800" 
                                       title="View Ledger">
                                        <i class="fas fa-book"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <!-- Grand Totals Row -->
                        <tr class="bg-gray-100 font-bold border-t-2 border-gray-300">
                            <td class="px-6 py-4 text-sm text-right text-gray-900">
                                <i class="fas fa-calculator mr-2"></i>GRAND TOTALS:
                            </td>
                            <td class="px-6 py-4 text-center text-sm text-gray-900">
                                <?php echo number_format($grand_totals['total_orders']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                                <?php echo number_format($grand_totals['ordered_kg'], 0); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                                ৳<?php echo number_format($grand_totals['ordered_value'], 0); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                                <?php echo number_format($grand_totals['received_kg'], 0); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-purple-600">
                                ৳<?php echo number_format($grand_totals['receivable_value'], 0); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right
                                <?php echo $grand_totals['net_adj'] > 0 ? 'text-red-500' : ($grand_totals['net_adj'] < 0 ? 'text-green-600' : 'text-gray-400'); ?>">
                                <?php if ($grand_totals['net_adj'] != 0): ?>
                                <?php echo $grand_totals['net_adj'] > 0 ? '+' : '−'; ?>৳<?php echo number_format(abs($grand_totals['net_adj']), 0); ?>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-green-600">
                                ৳<?php echo number_format($grand_totals['paid'], 0); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-red-600">
                                ৳<?php echo number_format($grand_totals['balance'], 0); ?>
                                <div class="text-xs font-normal text-indigo-400 mt-0.5">incl. adj</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-blue-600">
                                ৳<?php echo number_format($grand_totals['advance'], 0); ?>
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
@media print {
    .no-print { display: none !important; }
}
</style>

<?php include '../templates/footer.php'; ?>