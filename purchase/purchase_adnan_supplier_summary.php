<?php
require_once __DIR__ . '/../core/init.php';
restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$page_title = "Supplier Summary";

// Initialize manager
$manager = new Purchaseadnanmanager();

// Get supplier summary
$summary = $manager->getSupplierSummary();

include __DIR__ . '/../templates/header.php';
?>

<div class="w-full px-4 py-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-chart-bar text-primary-600"></i> Supplier Summary (Topsheet)
            </h2>
            <nav class="text-sm text-gray-600 mt-1">
                <a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>" class="hover:text-primary-600">Purchase (Adnan)</a>
                <span class="mx-2">›</span>
                <span>Supplier Summary</span>
            </nav>
        </div>
        <div class="flex gap-2">
            <a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>" 
               class="border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 flex items-center gap-2">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <button onclick="window.print()" 
                    class="bg-primary-600 text-white px-4 py-2 rounded-lg hover:bg-primary-700 flex items-center gap-2">
                <i class="fas fa-print"></i> Print
            </button>
            <button onclick="exportToExcel()" 
                    class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 flex items-center gap-2">
                <i class="fas fa-file-excel"></i> Export
            </button>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <!-- Total Suppliers -->
        <div class="bg-gradient-to-br from-primary-500 to-primary-700 text-white rounded-lg shadow-lg p-6">
            <div class="flex justify-between items-center">
                <div>
                    <p class="text-primary-100 text-sm font-medium mb-1">Total Suppliers</p>
                    <h3 class="text-3xl font-bold"><?php echo count($summary); ?></h3>
                </div>
                <i class="fas fa-users text-5xl opacity-30"></i>
            </div>
        </div>

        <!-- Total Orders -->
        <div class="bg-gradient-to-br from-blue-500 to-blue-700 text-white rounded-lg shadow-lg p-6">
            <div class="flex justify-between items-center">
                <div>
                    <p class="text-blue-100 text-sm font-medium mb-1">Total Orders</p>
                    <h3 class="text-3xl font-bold"><?php echo array_sum(array_column($summary, 'total_orders')); ?></h3>
                </div>
                <i class="fas fa-shopping-cart text-5xl opacity-30"></i>
            </div>
        </div>

        <!-- Total Received -->
        <div class="bg-gradient-to-br from-green-500 to-green-700 text-white rounded-lg shadow-lg p-6">
            <div class="flex justify-between items-center">
                <div>
                    <p class="text-green-100 text-sm font-medium mb-1">Total Received</p>
                    <h3 class="text-3xl font-bold">৳<?php echo number_format(array_sum(array_column($summary, 'total_received_value')) / 1000000, 2); ?>M</h3>
                </div>
                <i class="fas fa-truck-loading text-5xl opacity-30"></i>
            </div>
        </div>

        <!-- Balance Payable -->
        <div class="bg-gradient-to-br from-red-500 to-red-700 text-white rounded-lg shadow-lg p-6">
            <div class="flex justify-between items-center">
                <div>
                    <p class="text-red-100 text-sm font-medium mb-1">Balance Payable</p>
                    <h3 class="text-3xl font-bold">৳<?php echo number_format(array_sum(array_column($summary, 'balance_payable')) / 1000000, 2); ?>M</h3>
                </div>
                <i class="fas fa-exclamation-triangle text-5xl opacity-30"></i>
            </div>
        </div>
    </div>

    <!-- Supplier Summary Table -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <div class="bg-primary-600 text-white px-6 py-4">
            <h5 class="text-lg font-semibold mb-0">Supplier-wise Payment Status</h5>
        </div>
        <div class="p-6">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200" id="supplierTable">
                    <thead class="bg-gray-800 text-white">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider">#</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider">Supplier Name</th>
                            <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider">Total Orders</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider">Ordered Value</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider">Received Value</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider">Total Paid</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider">Balance Payable</th>
                            <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider">Payment %</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider">Last Order</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($summary)): ?>
                            <tr>
                                <td colspan="9" class="px-4 py-8 text-center text-gray-500">
                                    <i class="fas fa-inbox text-4xl mb-2"></i>
                                    <p>No data available</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($summary as $index => $supplier): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $index + 1; ?></td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($supplier->supplier_name); ?></span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-center">
                                    <span class="inline-block bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-xs font-semibold">
                                        <?php echo $supplier->total_orders; ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-right text-sm text-gray-900">
                                    ৳<?php echo number_format($supplier->total_ordered_value, 2); ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-right text-sm text-green-600 font-semibold">
                                    ৳<?php echo number_format($supplier->total_received_value, 2); ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-right text-sm text-primary-600 font-semibold">
                                    ৳<?php echo number_format($supplier->total_paid, 2); ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-right text-sm font-bold">
                                    <span class="<?php echo $supplier->balance_payable > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                                        ৳<?php echo number_format($supplier->balance_payable, 2); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <?php 
                                    $payment_percent = $supplier->total_received_value > 0 
                                        ? ($supplier->total_paid / $supplier->total_received_value * 100) 
                                        : 0;
                                    $progress_color = $payment_percent >= 100 ? 'bg-green-500' : ($payment_percent >= 50 ? 'bg-yellow-500' : 'bg-red-500');
                                    ?>
                                    <div class="w-full bg-gray-200 rounded-full h-5">
                                        <div class="<?php echo $progress_color; ?> h-5 rounded-full flex items-center justify-center text-xs font-semibold text-white" 
                                             style="width: <?php echo min($payment_percent, 100); ?>%">
                                            <?php echo number_format($payment_percent, 1); ?>%
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-700">
                                    <?php echo $supplier->last_order_date ? date('d M Y', strtotime($supplier->last_order_date)) : 'N/A'; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <!-- Totals Row -->
                            <tr class="bg-blue-50 font-bold border-t-2 border-blue-300">
                                <td colspan="2" class="px-4 py-4 text-sm text-gray-900">TOTAL</td>
                                <td class="px-4 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                    <?php echo array_sum(array_column($summary, 'total_orders')); ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-right text-sm text-gray-900">
                                    ৳<?php echo number_format(array_sum(array_column($summary, 'total_ordered_value')), 2); ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-right text-sm text-gray-900">
                                    ৳<?php echo number_format(array_sum(array_column($summary, 'total_received_value')), 2); ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-right text-sm text-gray-900">
                                    ৳<?php echo number_format(array_sum(array_column($summary, 'total_paid')), 2); ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-right text-sm text-gray-900">
                                    ৳<?php echo number_format(array_sum(array_column($summary, 'balance_payable')), 2); ?>
                                </td>
                                <td colspan="2"></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .no-print { display: none !important; }
    body { margin: 0; padding: 20px; }
}
</style>

<script>
function exportToExcel() {
    const table = document.getElementById('supplierTable');
    const rows = [];
    
    // Get headers
    const headers = [];
    table.querySelectorAll('thead th').forEach(th => {
        headers.push(th.textContent.trim());
    });
    rows.push(headers.join('\t'));
    
    // Get data rows
    table.querySelectorAll('tbody tr').forEach(tr => {
        const cells = [];
        tr.querySelectorAll('td').forEach(td => {
            // Clean up cell content
            let text = td.textContent.trim();
            // Remove currency symbols and format
            text = text.replace(/৳/g, '').replace(/,/g, '');
            cells.push(text);
        });
        rows.push(cells.join('\t'));
    });
    
    // Create TSV content
    const tsvContent = rows.join('\n');
    
    // Download
    const blob = new Blob([tsvContent], { type: 'text/tab-separated-values' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'supplier_summary_' + new Date().toISOString().split('T')[0] + '.xls';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>