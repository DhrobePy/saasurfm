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

// Get supplier details
$supplier_sql = "SELECT * FROM suppliers WHERE id = ?";
$stmt = $db->prepare($supplier_sql);
$stmt->execute([$supplier_id]);
$supplier = $stmt->fetch(PDO::FETCH_OBJ);

if (!$supplier) {
    redirect('suppliers.php', 'Supplier not found', 'error');
}

$pageTitle = "Supplier Ledger - " . $supplier->company_name;

// Date filters
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Today
$transaction_type = $_GET['transaction_type'] ?? 'all'; // all, po, grn, payment

// Build comprehensive transaction query
// We'll union purchase orders, GRNs, and payments into a single chronological list

$transactions_sql = "
SELECT * FROM (
    -- Purchase Orders
    SELECT 
        'PO' as transaction_type,
        po.id as transaction_id,
        po.po_number as reference_number,
        po.po_date as transaction_date,
        po.total_order_value as debit,
        0 as credit,
        CONCAT('Purchase Order: ', po.wheat_origin, ' - ', po.quantity_kg, ' KG @ ৳', FORMAT(po.unit_price_per_kg, 2)) as description,
        po.delivery_status as status,
        po.created_at
    FROM purchase_orders_adnan po
    WHERE po.supplier_id = ?
    AND po.po_status != 'cancelled'
    AND po.po_date BETWEEN ? AND ?
    
    UNION ALL
    
    -- Goods Received Notes (GRNs)
    SELECT 
        'GRN' as transaction_type,
        grn.id as transaction_id,
        grn.grn_number as reference_number,
        grn.grn_date as transaction_date,
        grn.total_value as debit,
        0 as credit,
        CONCAT('Goods Received: ', grn.po_number, ' - ', grn.quantity_received_kg, ' KG (Truck: ', COALESCE(grn.truck_number, 'N/A'), ')') as description,
        grn.grn_status as status,
        grn.created_at
    FROM goods_received_adnan grn
    WHERE grn.supplier_id = ?
    AND grn.grn_status != 'cancelled'
    AND grn.grn_date BETWEEN ? AND ?
    
    UNION ALL
    
    -- Payments
    SELECT 
        'PAYMENT' as transaction_type,
        pmt.id as transaction_id,
        pmt.payment_voucher_number as reference_number,
        pmt.payment_date as transaction_date,
        0 as debit,
        pmt.amount_paid as credit,
        CONCAT('Payment: ', pmt.po_number, ' - ', UPPER(pmt.payment_method), ' (', COALESCE(pmt.bank_name, 'N/A'), ')') as description,
        IF(pmt.is_posted = 1, 'posted', 'pending') as status,
        pmt.created_at
    FROM purchase_payments_adnan pmt
    WHERE pmt.supplier_id = ?
    AND pmt.payment_date BETWEEN ? AND ?
) AS all_transactions
ORDER BY transaction_date DESC, created_at DESC
";

$params = [
    $supplier_id, $date_from, $date_to,  // PO params
    $supplier_id, $date_from, $date_to,  // GRN params
    $supplier_id, $date_from, $date_to   // Payment params
];

$stmt = $db->prepare($transactions_sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_OBJ);

// Filter by transaction type if specified
if ($transaction_type !== 'all') {
    $transactions = array_filter($transactions, function($t) use ($transaction_type) {
        return strtolower($t->transaction_type) === strtolower($transaction_type);
    });
}

// Calculate opening balance (all transactions before date_from)
$opening_balance_sql = "
SELECT 
    COALESCE(SUM(po.total_order_value), 0) as total_orders,
    COALESCE(SUM(pmt.amount_paid), 0) as total_paid
FROM suppliers s
LEFT JOIN purchase_orders_adnan po ON s.id = po.supplier_id 
    AND po.po_status != 'cancelled' 
    AND po.po_date < ?
LEFT JOIN purchase_payments_adnan pmt ON s.id = pmt.supplier_id 
    AND pmt.payment_date < ?
WHERE s.id = ?
";

$stmt = $db->prepare($opening_balance_sql);
$stmt->execute([$date_from, $date_from, $supplier_id]);
$opening = $stmt->fetch(PDO::FETCH_OBJ);
$opening_balance = ($opening->total_orders ?? 0) - ($opening->total_paid ?? 0);

// Calculate totals for the period
$period_debit = array_sum(array_column($transactions, 'debit'));
$period_credit = array_sum(array_column($transactions, 'credit'));
$closing_balance = $opening_balance + $period_debit - $period_credit;

// Get summary statistics
$stats_sql = "
SELECT 
    COUNT(DISTINCT po.id) as total_pos,
    COUNT(DISTINCT grn.id) as total_grns,
    COUNT(DISTINCT pmt.id) as total_payments,
    COALESCE(SUM(po.total_order_value), 0) as total_orders_value,
    COALESCE(SUM(pmt.amount_paid), 0) as total_paid_value,
    COALESCE(SUM(po.balance_payable), 0) as current_balance
FROM suppliers s
LEFT JOIN purchase_orders_adnan po ON s.id = po.supplier_id AND po.po_status != 'cancelled'
LEFT JOIN goods_received_adnan grn ON s.id = grn.supplier_id AND grn.grn_status != 'cancelled'
LEFT JOIN purchase_payments_adnan pmt ON s.id = pmt.supplier_id
WHERE s.id = ?
";

$stmt = $db->prepare($stats_sql);
$stmt->execute([$supplier_id]);
$stats = $stmt->fetch(PDO::FETCH_OBJ);

require_once '../templates/header.php';
?>

<div class="w-full px-4 py-6">

    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Supplier Ledger</h1>
            <p class="text-gray-600 mt-1"><?php echo htmlspecialchars($supplier->company_name); ?> (<?php echo htmlspecialchars($supplier->supplier_code); ?>)</p>
        </div>
        <div class="flex items-center gap-3">
            <button onclick="window.print()" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg transition">
                <i class="fas fa-print mr-2"></i>Print
            </button>
            <a href="view_supplier.php?id=<?php echo $supplier_id; ?>" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg transition">
                <i class="fas fa-eye mr-2"></i>View Details
            </a>
            <a href="suppliers.php" class="text-gray-600 hover:text-gray-800">
                <i class="fas fa-arrow-left mr-2"></i>Back
            </a>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6 print:grid-cols-5">
        <div class="bg-white rounded-lg shadow-md p-4">
            <p class="text-xs font-medium text-gray-600 mb-1">Total POs</p>
            <p class="text-2xl font-bold text-blue-600"><?php echo number_format($stats->total_pos); ?></p>
        </div>
        <div class="bg-white rounded-lg shadow-md p-4">
            <p class="text-xs font-medium text-gray-600 mb-1">Total Orders</p>
            <p class="text-lg font-bold text-gray-900">৳<?php echo number_format($stats->total_orders_value, 0); ?></p>
        </div>
        <div class="bg-white rounded-lg shadow-md p-4">
            <p class="text-xs font-medium text-gray-600 mb-1">Total GRNs</p>
            <p class="text-2xl font-bold text-green-600"><?php echo number_format($stats->total_grns); ?></p>
        </div>
        <div class="bg-white rounded-lg shadow-md p-4">
            <p class="text-xs font-medium text-gray-600 mb-1">Total Paid</p>
            <p class="text-lg font-bold text-green-900">৳<?php echo number_format($stats->total_paid_value, 0); ?></p>
        </div>
        <div class="bg-white rounded-lg shadow-md p-4">
            <p class="text-xs font-medium text-gray-600 mb-1">Current Balance</p>
            <p class="text-lg font-bold text-red-600">৳<?php echo number_format($stats->current_balance, 2); ?></p>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-6 print:hidden">
        <form method="GET" action="" class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <input type="hidden" name="id" value="<?php echo $supplier_id; ?>">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">From Date</label>
                <input type="date" 
                       name="date_from" 
                       value="<?php echo htmlspecialchars($date_from); ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">To Date</label>
                <input type="date" 
                       name="date_to" 
                       value="<?php echo htmlspecialchars($date_to); ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Transaction Type</label>
                <select name="transaction_type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    <option value="all" <?php echo $transaction_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                    <option value="po" <?php echo $transaction_type === 'po' ? 'selected' : ''; ?>>Purchase Orders</option>
                    <option value="grn" <?php echo $transaction_type === 'grn' ? 'selected' : ''; ?>>Goods Received</option>
                    <option value="payment" <?php echo $transaction_type === 'payment' ? 'selected' : ''; ?>>Payments</option>
                </select>
            </div>

            <div class="flex items-end gap-2 md:col-span-2">
                <button type="submit" class="flex-1 bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg">
                    <i class="fas fa-filter mr-2"></i>Apply Filters
                </button>
                <a href="supplier_ledger.php?id=<?php echo $supplier_id; ?>" class="flex-1 text-center border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50">
                    <i class="fas fa-redo mr-2"></i>Reset
                </a>
            </div>
        </form>
    </div>

    <!-- Ledger Table -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-gray-900">
                    Transaction Ledger
                    <span class="text-sm font-normal text-gray-600">
                        (<?php echo date('d M Y', strtotime($date_from)); ?> to <?php echo date('d M Y', strtotime($date_to)); ?>)
                    </span>
                </h2>
                <span class="text-sm text-gray-600"><?php echo count($transactions); ?> transactions</span>
            </div>
        </div>

        <?php if (count($transactions) > 0): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reference</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Debit (৳)</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Credit (৳)</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance (৳)</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider print:hidden">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <!-- Opening Balance Row -->
                    <tr class="bg-blue-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo date('d M Y', strtotime($date_from)); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap" colspan="3">
                            <span class="text-sm font-semibold text-gray-900">Opening Balance</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-500">-</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-500">-</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold <?php echo $opening_balance > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                            <?php echo number_format($opening_balance, 2); ?>
                        </td>
                        <td class="print:hidden"></td>
                    </tr>

                    <?php 
                    $running_balance = $opening_balance;
                    foreach ($transactions as $txn): 
                        $running_balance += $txn->debit - $txn->credit;
                        
                        // Type badge colors
                        $type_colors = [
                            'PO' => 'bg-blue-100 text-blue-800',
                            'GRN' => 'bg-green-100 text-green-800',
                            'PAYMENT' => 'bg-purple-100 text-purple-800'
                        ];
                        $type_color = $type_colors[$txn->transaction_type] ?? 'bg-gray-100 text-gray-800';
                        
                        // Status colors
                        $status_colors = [
                            'pending' => 'bg-gray-100 text-gray-800',
                            'partial' => 'bg-yellow-100 text-yellow-800',
                            'completed' => 'bg-green-100 text-green-800',
                            'posted' => 'bg-green-100 text-green-800',
                            'verified' => 'bg-blue-100 text-blue-800'
                        ];
                        $status_color = $status_colors[$txn->status] ?? 'bg-gray-100 text-gray-800';
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo date('d M Y', strtotime($txn->transaction_date)); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $type_color; ?>">
                                <?php echo $txn->transaction_type; ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                            <?php echo htmlspecialchars($txn->reference_number); ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-700">
                            <?php echo htmlspecialchars($txn->description); ?>
                            <div class="mt-1">
                                <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $status_color; ?>">
                                    <?php echo ucfirst($txn->status); ?>
                                </span>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-semibold text-red-600">
                            <?php echo $txn->debit > 0 ? number_format($txn->debit, 2) : '-'; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-semibold text-green-600">
                            <?php echo $txn->credit > 0 ? number_format($txn->credit, 2) : '-'; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold <?php echo $running_balance > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                            <?php echo number_format($running_balance, 2); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm print:hidden">
                            <?php if ($txn->transaction_type === 'PO'): ?>
                                <a href="purchase_adnan_view_po.php?id=<?php echo $txn->transaction_id; ?>" class="text-primary-600 hover:text-primary-800" title="View PO">
                                    <i class="fas fa-eye"></i>
                                </a>
                            <?php elseif ($txn->transaction_type === 'GRN'): ?>
                                <a href="purchase_adnan_grn_receipt.php?id=<?php echo $txn->transaction_id; ?>" class="text-primary-600 hover:text-primary-800" title="View GRN">
                                    <i class="fas fa-receipt"></i>
                                </a>
                            <?php elseif ($txn->transaction_type === 'PAYMENT'): ?>
                                <a href="purchase_adnan_payment_receipt.php?id=<?php echo $txn->transaction_id; ?>" class="text-primary-600 hover:text-primary-800" title="View Payment">
                                    <i class="fas fa-receipt"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <!-- Period Totals Row -->
                    <tr class="bg-gray-100 font-semibold">
                        <td colspan="4" class="px-6 py-4 text-sm text-gray-900">
                            Period Totals (<?php echo count($transactions); ?> transactions)
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-red-600">
                            <?php echo number_format($period_debit, 2); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-green-600">
                            <?php echo number_format($period_credit, 2); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">-</td>
                        <td class="print:hidden"></td>
                    </tr>

                    <!-- Closing Balance Row -->
                    <tr class="bg-blue-100 font-bold">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo date('d M Y', strtotime($date_to)); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap" colspan="3">
                            <span class="text-sm text-gray-900">Closing Balance</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-500">-</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-500">-</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm <?php echo $closing_balance > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                            <?php echo number_format($closing_balance, 2); ?>
                        </td>
                        <td class="print:hidden"></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php else: ?>
        <div class="text-center py-12">
            <i class="fas fa-file-invoice text-gray-300 text-6xl mb-4"></i>
            <h3 class="text-xl font-semibold text-gray-900 mb-2">No transactions found</h3>
            <p class="text-gray-600 mb-6">
                No transactions for this supplier in the selected date range.
            </p>
            <a href="supplier_ledger.php?id=<?php echo $supplier_id; ?>" class="inline-block text-primary-600 hover:text-primary-700 font-medium">
                <i class="fas fa-redo mr-2"></i>Reset Filters
            </a>
        </div>
        <?php endif; ?>

    </div>

</div>

<!-- Print Styles -->
<style>
@media print {
    .print\:hidden {
        display: none !important;
    }
    .print\:grid-cols-5 {
        grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
    }
    body {
        font-size: 12px;
    }
    .bg-blue-50, .bg-blue-100, .bg-gray-100 {
        background-color: #f3f4f6 !important;
    }
}
</style>

<?php require_once '../templates/footer.php'; ?>