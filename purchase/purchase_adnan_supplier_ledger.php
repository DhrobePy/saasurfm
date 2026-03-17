<?php
/**
 * Purchase (Adnan) Module - Supplier Ledger
 * Shows detailed transaction history for a specific supplier
 * MODIFIED: GRN debit based on EXPECTED quantity × unit price
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

$pageTitle = "Supplier Ledger - Purchase (Adnan)";

$db = Database::getInstance()->getPdo();

// Get supplier ID
$supplier_id = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;

if ($supplier_id === 0) {
    $_SESSION['error_flash'] = "Invalid supplier ID";
    header('Location: purchase_adnan_supplier_summary.php');
    exit;
}

// Get date filters
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Get supplier details
$stmt = $db->prepare("SELECT * FROM suppliers WHERE id = ?");
$stmt->execute([$supplier_id]);
$supplier = $stmt->fetch(PDO::FETCH_OBJ);

if (!$supplier) {
    $_SESSION['error_flash'] = "Supplier not found";
    header('Location: purchase_adnan_supplier_summary.php');
    exit;
}

// ============================================================================
// Get supplier summary with proper filters
// ============================================================================
$stmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT po.id) as total_orders,
        COALESCE(SUM(po.quantity_kg), 0) as total_ordered_kg,
        COALESCE(SUM(po.total_order_value), 0) as total_ordered_value,
        
        -- Only VERIFIED/POSTED GRNs
        COALESCE((
            SELECT SUM(grn.quantity_received_kg)
            FROM goods_received_adnan grn
            WHERE grn.supplier_id = ?
            AND grn.grn_status IN ('verified', 'posted')
        ), 0) as total_received_kg,
        
        -- Calculate total receivable value based on EXPECTED quantity
        COALESCE((
            SELECT SUM(grn.expected_quantity * grn.unit_price_per_kg)
            FROM goods_received_adnan grn
            WHERE grn.supplier_id = ?
            AND grn.grn_status IN ('verified', 'posted')
        ), 0) as total_receivable_value,
        
        -- Only POSTED payments
        COALESCE((
            SELECT SUM(p.amount_paid)
            FROM purchase_payments_adnan p
            JOIN purchase_orders_adnan po2 ON p.purchase_order_id = po2.id
            WHERE po2.supplier_id = ?
            AND p.is_posted = 1
        ), 0) as total_paid
        
    FROM purchase_orders_adnan po
    WHERE po.supplier_id = ? 
    AND po.po_status != 'cancelled'
");
$stmt->execute([$supplier_id, $supplier_id, $supplier_id, $supplier_id]);
$summary = $stmt->fetch(PDO::FETCH_OBJ);

// Calculate balance based on EXPECTED quantity value (debits) and Payments (credits)
$summary->balance_payable = $summary->total_receivable_value - $summary->total_paid;

// ============================================================================
// Build ledger query - Get all transactions (POs, GRNs, Payments)
// MODIFIED: POs are informational (no debit/credit effect)
//           GRNs create debit entries based on EXPECTED quantity × unit price
//           Payments create credit entries
// ============================================================================
$transactions = [];

// 1. Get Purchase Orders - INFORMATIONAL ONLY (no debit/credit effect)
$po_sql = "SELECT 
        po.id,
        'PO' as type,
        po.po_number as reference,
        po.po_date as transaction_date,
        CONCAT(po.wheat_origin, ' (', FORMAT(po.quantity_kg, 0), ' KG @ ৳', FORMAT(po.unit_price_per_kg, 2), '/KG)') as description,
        po.quantity_kg as quantity,
        po.unit_price_per_kg as unit_price,
        0 as debit,        -- PO does NOT affect debit
        0 as credit,       -- PO does NOT affect credit
        po.delivery_status,
        po.payment_status,
        po.remarks
    FROM purchase_orders_adnan po
    WHERE po.supplier_id = ? 
    AND po.po_status != 'cancelled'";

$params = [$supplier_id];

if ($date_from) {
    $po_sql .= " AND po.po_date >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $po_sql .= " AND po.po_date <= ?";
    $params[] = $date_to;
}

$po_sql .= " ORDER BY po.po_date ASC";

$stmt = $db->prepare($po_sql);
$stmt->execute($params);
$pos = $stmt->fetchAll(PDO::FETCH_OBJ);

foreach ($pos as $po) {
    $transactions[] = $po;
}

// 2. Get GRNs - These create DEBIT entries (liability based on EXPECTED quantity)
$grn_sql = "SELECT 
        grn.id,
        'GRN' as type,
        grn.grn_number as reference,
        grn.grn_date as transaction_date,
        CONCAT('Goods Received - Truck: ', grn.truck_number, 
               ' | Expected: ', FORMAT(grn.expected_quantity, 2), ' KG @ ৳', FORMAT(grn.unit_price_per_kg, 2), '/KG',
               ' | Received: ', FORMAT(grn.quantity_received_kg, 2), ' KG') as description,
        grn.quantity_received_kg as quantity,
        grn.unit_price_per_kg as unit_price,
        (grn.expected_quantity * grn.unit_price_per_kg) as debit,  -- Debit based on EXPECTED quantity
        0 as credit,
        grn.grn_status as delivery_status,
        '' as payment_status,
        grn.remarks
    FROM goods_received_adnan grn
    JOIN purchase_orders_adnan po ON grn.purchase_order_id = po.id
    WHERE po.supplier_id = ? 
    AND grn.grn_status IN ('verified', 'posted')";

$params = [$supplier_id];

if ($date_from) {
    $grn_sql .= " AND grn.grn_date >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $grn_sql .= " AND grn.grn_date <= ?";
    $params[] = $date_to;
}

$grn_sql .= " ORDER BY grn.grn_date ASC";

$stmt = $db->prepare($grn_sql);
$stmt->execute($params);
$grns = $stmt->fetchAll(PDO::FETCH_OBJ);

foreach ($grns as $grn) {
    $transactions[] = $grn;
}

// 3. Get Payments - These create CREDIT entries (payments made)
$payment_sql = "SELECT 
        p.id,
        'Payment' as type,
        p.payment_voucher_number as reference,
        p.payment_date as transaction_date,
        CONCAT('Payment via ', UPPER(p.payment_method), 
               CASE WHEN p.reference_number IS NOT NULL THEN CONCAT(' - Ref: ', p.reference_number) ELSE '' END,
               CASE WHEN p.payment_type != 'regular' THEN CONCAT(' (', UPPER(p.payment_type), ')') ELSE '' END) as description,
        NULL as quantity,
        NULL as unit_price,
        0 as debit,
        p.amount_paid as credit,      -- Payment goes to CREDIT
        '' as delivery_status,
        'posted' as payment_status,
        p.remarks
    FROM purchase_payments_adnan p
    JOIN purchase_orders_adnan po ON p.purchase_order_id = po.id
    WHERE po.supplier_id = ?
    AND p.is_posted = 1";

$params = [$supplier_id];

if ($date_from) {
    $payment_sql .= " AND p.payment_date >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $payment_sql .= " AND p.payment_date <= ?";
    $params[] = $date_to;
}

$payment_sql .= " ORDER BY p.payment_date ASC";

$stmt = $db->prepare($payment_sql);
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_OBJ);

foreach ($payments as $payment) {
    $transactions[] = $payment;
}

// Sort all transactions by date (oldest first for running balance)
usort($transactions, function($a, $b) {
    return strtotime($a->transaction_date) - strtotime($b->transaction_date);
});

// Calculate running balance - ONLY GRNs (debit based on expected qty) and Payments (credit) affect balance
$running_balance = 0;
foreach ($transactions as &$trans) {
    // Only GRNs and Payments affect balance (POs have 0 debit and 0 credit)
    $running_balance += ($trans->debit - $trans->credit);
    $trans->balance = $running_balance;
}
unset($trans);

include '../templates/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <!-- Page Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Supplier Ledger</h1>
            <p class="text-gray-600 mt-1">Detailed transaction history</p>
            <p class="text-xs text-gray-500 mt-1">
                <i class="fas fa-info-circle mr-1"></i>
                <strong>Note:</strong> GRN Debit = Expected Quantity × Unit Price. Payments = Credit. POs are informational only.
            </p>
        </div>
        <div class="flex gap-2">
            <a href="purchase_adnan_supplier_summary.php" class="bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 flex items-center gap-2">
                <i class="fas fa-arrow-left"></i> Back to Summary
            </a>
            <button onclick="window.print()" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 flex items-center gap-2">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>

    <!-- Supplier Info Card -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <h2 class="text-2xl font-bold text-gray-900 mb-4"><?php echo htmlspecialchars($supplier->company_name); ?></h2>
                <div class="space-y-2">
                    <?php if ($supplier->supplier_code): ?>
                        <p class="text-sm"><span class="font-medium text-gray-700">Code:</span> <?php echo htmlspecialchars($supplier->supplier_code); ?></p>
                    <?php endif; ?>
                    <?php if ($supplier->contact_person): ?>
                        <p class="text-sm"><span class="font-medium text-gray-700">Contact:</span> <?php echo htmlspecialchars($supplier->contact_person); ?></p>
                    <?php endif; ?>
                    <?php if ($supplier->phone): ?>
                        <p class="text-sm"><span class="font-medium text-gray-700">Phone:</span> <?php echo htmlspecialchars($supplier->phone); ?></p>
                    <?php endif; ?>
                    <?php if ($supplier->email): ?>
                        <p class="text-sm"><span class="font-medium text-gray-700">Email:</span> <?php echo htmlspecialchars($supplier->email); ?></p>
                    <?php endif; ?>
                    <?php if ($supplier->address): ?>
                        <p class="text-sm"><span class="font-medium text-gray-700">Address:</span> <?php echo htmlspecialchars($supplier->address); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-blue-50 p-4 rounded-lg">
                    <p class="text-xs text-blue-600 font-medium uppercase">Total Orders</p>
                    <p class="text-2xl font-bold text-blue-900 mt-1"><?php echo number_format($summary->total_orders); ?></p>
                </div>
                <div class="bg-purple-50 p-4 rounded-lg">
                    <p class="text-xs text-purple-600 font-medium uppercase">Ordered (KG)</p>
                    <p class="text-2xl font-bold text-purple-900 mt-1"><?php echo number_format($summary->total_ordered_kg, 0); ?></p>
                </div>
                <div class="bg-green-50 p-4 rounded-lg">
                    <p class="text-xs text-green-600 font-medium uppercase">Total Paid</p>
                    <p class="text-2xl font-bold text-green-900 mt-1">৳<?php echo number_format($summary->total_paid, 0); ?></p>
                    <p class="text-xs text-gray-500 mt-1">Posted only</p>
                </div>
                <div class="bg-<?php echo $summary->balance_payable >= 0 ? 'red' : 'blue'; ?>-50 p-4 rounded-lg">
                    <p class="text-xs text-<?php echo $summary->balance_payable >= 0 ? 'red' : 'blue'; ?>-600 font-medium uppercase">
                        <?php echo $summary->balance_payable >= 0 ? 'Balance Due' : 'Advance Paid'; ?>
                    </p>
                    <p class="text-2xl font-bold text-<?php echo $summary->balance_payable >= 0 ? 'red' : 'blue'; ?>-900 mt-1">
                        ৳<?php echo number_format(abs($summary->balance_payable), 0); ?>
                    </p>
                    <p class="text-xs text-gray-500 mt-1">Based on Expected Qty × Price</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Date Filter -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
        <form method="GET" class="flex gap-4 items-end">
            <input type="hidden" name="supplier_id" value="<?php echo $supplier_id; ?>">
            <div class="flex-1">
                <label class="block text-sm font-medium text-gray-700 mb-1">From Date</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" 
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
            </div>
            <div class="flex-1">
                <label class="block text-sm font-medium text-gray-700 mb-1">To Date</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" 
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
            </div>
            <button type="submit" class="bg-primary-600 text-white px-6 py-2 rounded-lg hover:bg-primary-700">
                <i class="fas fa-search mr-2"></i>Filter
            </button>
            <a href="purchase_adnan_supplier_ledger.php?supplier_id=<?php echo $supplier_id; ?>" 
               class="bg-gray-200 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-300">
                <i class="fas fa-times mr-2"></i>Clear
            </a>
        </form>
    </div>

    <!-- Ledger Table -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h3 class="text-lg font-semibold text-gray-900">
                Transaction History
                <span class="text-sm font-normal text-gray-600">(<?php echo count($transactions); ?> transactions)</span>
            </h3>
            <div class="text-xs text-gray-500">
                <span class="inline-block px-2 py-1 bg-blue-100 text-blue-800 rounded mr-2">PO = Informational Only</span>
                <span class="inline-block px-2 py-1 bg-purple-100 text-purple-800 rounded mr-2">GRN = Debit (Expected × Price)</span>
                <span class="inline-block px-2 py-1 bg-green-100 text-green-800 rounded">Payment = Credit</span>
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reference</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-red-500 uppercase tracking-wider">Debit (৳)</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-green-500 uppercase tracking-wider">Credit (৳)</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-blue-500 uppercase tracking-wider">Balance (৳)</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="8" class="px-6 py-8 text-center text-gray-500">
                                <i class="fas fa-inbox text-4xl text-gray-300 mb-2"></i>
                                <p>No transactions found</p>
                                <p class="text-xs text-gray-400 mt-2">Only posted payments and verified/posted GRNs are shown</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $total_debit = 0;
                        $total_credit = 0;
                        foreach ($transactions as $trans): 
                            $total_debit += $trans->debit;
                            $total_credit += $trans->credit;
                        ?>
                            <tr class="hover:bg-gray-50 <?php echo $trans->type === 'PO' ? 'bg-gray-50' : ''; ?>">
                                <!-- Date -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo date('d M Y', strtotime($trans->transaction_date)); ?>
                                </td>
                                
                                <!-- Type -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 rounded-full text-xs font-medium 
                                        <?php
                                        switch($trans->type) {
                                            case 'PO':
                                                echo 'bg-blue-100 text-blue-800';
                                                break;
                                            case 'GRN':
                                                echo 'bg-purple-100 text-purple-800';
                                                break;
                                            case 'Payment':
                                                echo 'bg-green-100 text-green-800';
                                                break;
                                            default:
                                                echo 'bg-gray-100 text-gray-800';
                                        }
                                        ?>">
                                        <?php echo $trans->type; ?>
                                        <?php if ($trans->type === 'PO'): ?>
                                            <span class="ml-1 text-xs opacity-75">(Info)</span>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                
                                <!-- Reference -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars($trans->reference); ?>
                                </td>
                                
                                <!-- Description -->
                                <td class="px-6 py-4 text-sm text-gray-700">
                                    <?php echo htmlspecialchars($trans->description); ?>
                                </td>
                                
                                <!-- Debit -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold">
                                    <?php if ($trans->debit > 0): ?>
                                        <span class="text-red-600">৳<?php echo number_format($trans->debit, 2); ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-300">—</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Credit -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold">
                                    <?php if ($trans->credit > 0): ?>
                                        <span class="text-green-600">৳<?php echo number_format($trans->credit, 2); ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-300">—</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Balance -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold
                                    <?php echo $trans->balance >= 0 ? 'text-blue-600' : 'text-orange-600'; ?>">
                                    ৳<?php echo number_format($trans->balance, 2); ?>
                                </td>
                                
                                <!-- Status -->
                                <td class="px-6 py-4 whitespace-nowrap text-center text-xs">
                                    <?php if ($trans->type === 'GRN'): ?>
                                        <span class="px-2 py-1 rounded-full bg-green-100 text-green-700">
                                            <?php echo ucfirst($trans->delivery_status); ?>
                                        </span>
                                    <?php elseif ($trans->type === 'Payment'): ?>
                                        <span class="px-2 py-1 rounded-full bg-green-100 text-green-700">
                                            Posted
                                        </span>
                                    <?php elseif ($trans->delivery_status): ?>
                                        <span class="px-2 py-1 rounded-full bg-gray-100 text-gray-700">
                                            <?php echo ucfirst($trans->delivery_status); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <!-- Final Balance Row -->
                        <tr class="bg-blue-50 font-bold">
                            <td colspan="4" class="px-6 py-4 text-sm text-right text-gray-900">
                                <i class="fas fa-calculator mr-2"></i>TOTALS / CURRENT BALANCE:
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-red-600">
                                ৳<?php echo number_format($total_debit, 2); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-green-600">
                                ৳<?php echo number_format($total_credit, 2); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-blue-600 text-xl">
                                ৳<?php echo number_format(abs($total_debit - $total_credit), 2); ?>
                                <?php if (($total_debit - $total_credit) < 0): ?>
                                    <span class="text-xs text-orange-600">(Advance)</span>
                                <?php endif; ?>
                            </td>
                            <td></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
@media print {
    .no-print {
        display: none !important;
    }
    .bg-gray-50 {
        background-color: #f9fafb !important;
    }
}
</style>

<?php include '../templates/footer.php'; ?>