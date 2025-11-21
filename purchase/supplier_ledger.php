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
$db->query("SELECT * FROM suppliers WHERE id = :id", ['id' => $supplier_id]);
$supplier = $db->first();

if (!$supplier) {
    redirect('suppliers.php', 'Supplier not found', 'error');
}

$pageTitle = $supplier->company_name . " - Ledger";

// Date filter
$from_date = $_GET['from_date'] ?? date('Y-m-01'); // First day of current month
$to_date = $_GET['to_date'] ?? date('Y-m-d'); // Today

// Get ledger entries
$ledger_sql = "
    SELECT 
        sl.*,
        u.display_name as created_by_name,
        b.name as branch_name
    FROM supplier_ledger sl
    LEFT JOIN users u ON sl.created_by_user_id = u.id
    LEFT JOIN branches b ON sl.branch_id = b.id
    WHERE sl.supplier_id = :supplier_id
    AND sl.transaction_date BETWEEN :from_date AND :to_date
    ORDER BY sl.transaction_date ASC, sl.created_at ASC, sl.id ASC
";

$db->query($ledger_sql, [
    'supplier_id' => $supplier_id,
    'from_date' => $from_date,
    'to_date' => $to_date
]);
$ledger_entries = $db->results();

// Calculate summary
$total_debit = 0;
$total_credit = 0;
$opening_balance = 0;

// Get opening balance (balance before from_date)
$opening_sql = "
    SELECT COALESCE(balance, 0) as balance
    FROM supplier_ledger
    WHERE supplier_id = :supplier_id
    AND transaction_date < :from_date
    ORDER BY transaction_date DESC, created_at DESC, id DESC
    LIMIT 1
";

$db->query($opening_sql, [
    'supplier_id' => $supplier_id,
    'from_date' => $from_date
]);

$opening_result = $db->first();
if ($opening_result) {
    $opening_balance = $opening_result->balance;
}

foreach ($ledger_entries as $entry) {
    $total_debit += $entry->debit_amount;
    $total_credit += $entry->credit_amount;
}

$closing_balance = $opening_balance + $total_credit - $total_debit;

require_once '../templates/header.php';
?>

<div class="container mx-auto">

    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Supplier Ledger</h1>
            <p class="mt-2 text-gray-600">
                <a href="view_supplier.php?id=<?php echo $supplier_id; ?>" class="text-primary-600 hover:text-primary-700">
                    <?php echo htmlspecialchars($supplier->company_name); ?>
                </a>
                <span class="mx-2">•</span>
                <span class="text-gray-500"><?php echo htmlspecialchars($supplier->supplier_code); ?></span>
            </p>
        </div>
        <div class="flex items-center gap-3">
            <button onclick="window.print()" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg transition">
                <i class="fas fa-print mr-2"></i>Print
            </button>
            <a href="view_supplier.php?id=<?php echo $supplier_id; ?>" class="text-gray-600 hover:text-gray-800">
                <i class="fas fa-arrow-left mr-2"></i>Back
            </a>
        </div>
    </div>

    <!-- Date Filter -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
        <form method="GET" action="" class="flex items-end gap-4">
            <input type="hidden" name="id" value="<?php echo $supplier_id; ?>">
            
            <div class="flex-1">
                <label class="block text-sm font-medium text-gray-700 mb-2">From Date</label>
                <input type="date" 
                       name="from_date" 
                       value="<?php echo htmlspecialchars($from_date); ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
            </div>
            
            <div class="flex-1">
                <label class="block text-sm font-medium text-gray-700 mb-2">To Date</label>
                <input type="date" 
                       name="to_date" 
                       value="<?php echo htmlspecialchars($to_date); ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
            </div>
            
            <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-6 py-2 rounded-lg transition">
                <i class="fas fa-filter mr-2"></i>Apply
            </button>
            
            <a href="supplier_ledger.php?id=<?php echo $supplier_id; ?>" class="text-gray-600 hover:text-gray-800 px-4 py-2">
                <i class="fas fa-redo mr-2"></i>Reset
            </a>
        </form>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
        
        <div class="bg-white rounded-lg shadow-md p-6">
            <p class="text-sm font-medium text-gray-600">Opening Balance</p>
            <p class="text-2xl font-bold text-gray-900 mt-2">BDT <?php echo number_format($opening_balance, 2); ?></p>
            <p class="text-xs text-gray-500 mt-1">As of <?php echo date('M d, Y', strtotime($from_date)); ?></p>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6">
            <p class="text-sm font-medium text-gray-600">Total Purchases</p>
            <p class="text-2xl font-bold text-red-600 mt-2">BDT <?php echo number_format($total_credit, 2); ?></p>
            <p class="text-xs text-gray-500 mt-1">Credit (Increases liability)</p>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6">
            <p class="text-sm font-medium text-gray-600">Total Payments</p>
            <p class="text-2xl font-bold text-green-600 mt-2">BDT <?php echo number_format($total_debit, 2); ?></p>
            <p class="text-xs text-gray-500 mt-1">Debit (Reduces liability)</p>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6">
            <p class="text-sm font-medium text-gray-600">Closing Balance</p>
            <p class="text-2xl font-bold <?php echo $closing_balance > 0 ? 'text-red-600' : 'text-gray-900'; ?> mt-2">
                BDT <?php echo number_format($closing_balance, 2); ?>
            </p>
            <p class="text-xs text-gray-500 mt-1">As of <?php echo date('M d, Y', strtotime($to_date)); ?></p>
        </div>

    </div>

    <!-- Ledger Table -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
            <h2 class="text-lg font-bold text-gray-900">Transaction History</h2>
            <p class="text-sm text-gray-600 mt-1">
                Period: <?php echo date('M d, Y', strtotime($from_date)); ?> to <?php echo date('M d, Y', strtotime($to_date)); ?>
            </p>
        </div>

        <?php if (count($ledger_entries) > 0): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reference</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Debit (Dr)</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Credit (Cr)</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <!-- Opening Balance Row -->
                    <?php if ($opening_balance != 0): ?>
                    <tr class="bg-blue-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-semibold">
                            <?php echo date('M d, Y', strtotime($from_date)); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700" colspan="3">
                            <span class="font-semibold">Opening Balance</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">-</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">-</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900 text-right">
                            BDT <?php echo number_format($opening_balance, 2); ?>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <!-- Transaction Rows -->
                    <?php foreach ($ledger_entries as $entry): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo date('M d, Y', strtotime($entry->transaction_date)); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php
                            $type_colors = [
                                'opening_balance' => 'bg-blue-100 text-blue-800',
                                'purchase' => 'bg-red-100 text-red-800',
                                'payment' => 'bg-green-100 text-green-800',
                                'debit_note' => 'bg-orange-100 text-orange-800',
                                'credit_note' => 'bg-purple-100 text-purple-800',
                                'adjustment' => 'bg-gray-100 text-gray-800'
                            ];
                            $type_icons = [
                                'opening_balance' => 'fa-balance-scale',
                                'purchase' => 'fa-shopping-cart',
                                'payment' => 'fa-money-bill-wave',
                                'debit_note' => 'fa-minus-circle',
                                'credit_note' => 'fa-plus-circle',
                                'adjustment' => 'fa-adjust'
                            ];
                            $color = $type_colors[$entry->transaction_type] ?? 'bg-gray-100 text-gray-800';
                            $icon = $type_icons[$entry->transaction_type] ?? 'fa-circle';
                            ?>
                            <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $color; ?>">
                                <i class="fas <?php echo $icon; ?> mr-1"></i>
                                <?php echo ucfirst(str_replace('_', ' ', $entry->transaction_type)); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-900">
                            <?php if ($entry->reference_number): ?>
                                <span class="font-mono"><?php echo htmlspecialchars($entry->reference_number); ?></span>
                                <?php if ($entry->reference_type): ?>
                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($entry->reference_type); ?></p>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-700">
                            <?php echo htmlspecialchars($entry->description ?? ''); ?>
                            <?php if ($entry->branch_name): ?>
                            <p class="text-xs text-gray-500 mt-1">
                                <i class="fas fa-map-marker-alt mr-1"></i><?php echo htmlspecialchars($entry->branch_name); ?>
                            </p>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                            <?php if ($entry->debit_amount > 0): ?>
                                <span class="font-bold text-green-600">BDT <?php echo number_format($entry->debit_amount, 2); ?></span>
                            <?php else: ?>
                                <span class="text-gray-300">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                            <?php if ($entry->credit_amount > 0): ?>
                                <span class="font-bold text-red-600">BDT <?php echo number_format($entry->credit_amount, 2); ?></span>
                            <?php else: ?>
                                <span class="text-gray-300">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900 text-right">
                            BDT <?php echo number_format($entry->balance, 2); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <!-- Closing Balance Row -->
                    <tr class="bg-gray-100 font-bold">
                        <td class="px-6 py-4 text-sm text-gray-900" colspan="4">
                            <span class="font-bold">Closing Balance</span>
                            <span class="text-xs text-gray-600 ml-2">(as of <?php echo date('M d, Y', strtotime($to_date)); ?>)</span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-900 text-right">
                            BDT <?php echo number_format($total_debit, 2); ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-900 text-right">
                            BDT <?php echo number_format($total_credit, 2); ?>
                        </td>
                        <td class="px-6 py-4 text-sm font-bold <?php echo $closing_balance > 0 ? 'text-red-600' : 'text-gray-900'; ?> text-right">
                            BDT <?php echo number_format($closing_balance, 2); ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-12">
            <i class="fas fa-book text-gray-300 text-6xl mb-4"></i>
            <h3 class="text-xl font-semibold text-gray-900 mb-2">No transactions found</h3>
            <p class="text-gray-600">
                No ledger entries found for the selected date range.<br>
                Try adjusting the date filter.
            </p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Ledger Legend -->
    <div class="bg-white rounded-lg shadow-md p-6 mt-6">
        <h3 class="text-lg font-bold text-gray-900 mb-4">Understanding the Ledger</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <h4 class="font-semibold text-gray-700 mb-2">Transaction Types:</h4>
                <ul class="space-y-2 text-sm">
                    <li><span class="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800"><i class="fas fa-balance-scale mr-1"></i>Opening Balance</span> - Starting balance for the period</li>
                    <li><span class="px-2 py-1 text-xs rounded-full bg-red-100 text-red-800"><i class="fas fa-shopping-cart mr-1"></i>Purchase</span> - Purchase invoice (increases what we owe)</li>
                    <li><span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-800"><i class="fas fa-money-bill-wave mr-1"></i>Payment</span> - Payment made (reduces what we owe)</li>
                    <li><span class="px-2 py-1 text-xs rounded-full bg-orange-100 text-orange-800"><i class="fas fa-minus-circle mr-1"></i>Debit Note</span> - Return or adjustment reducing liability</li>
                    <li><span class="px-2 py-1 text-xs rounded-full bg-purple-100 text-purple-800"><i class="fas fa-plus-circle mr-1"></i>Credit Note</span> - Additional charges or adjustments</li>
                </ul>
            </div>
            <div>
                <h4 class="font-semibold text-gray-700 mb-2">Column Meanings:</h4>
                <ul class="space-y-2 text-sm">
                    <li><strong>Debit (Dr):</strong> Payments and reductions (decreases liability)</li>
                    <li><strong>Credit (Cr):</strong> Purchases and additions (increases liability)</li>
                    <li><strong>Balance:</strong> Running total of what we owe the supplier</li>
                </ul>
                <div class="mt-4 p-4 bg-blue-50 rounded-lg">
                    <p class="text-sm text-blue-900">
                        <i class="fas fa-info-circle mr-2"></i>
                        <strong>Note:</strong> A positive balance means we owe money to the supplier. 
                        Balance increases with purchases (Credit) and decreases with payments (Debit).
                    </p>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Print Styles -->
<style media="print">
    body * {
        visibility: hidden;
    }
    .container, .container * {
        visibility: visible;
    }
    .container {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
    }
    button, .no-print {
        display: none !important;
    }
</style>

<?php require_once '../templates/footer.php'; ?>