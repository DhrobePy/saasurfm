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

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1"><i class="fas fa-chart-bar"></i> Supplier Summary (Topsheet)</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>">Purchase (Adnan)</a></li>
                    <li class="breadcrumb-item active">Supplier Summary</li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fas fa-print"></i> Print
            </button>
            <button onclick="exportToExcel()" class="btn btn-success">
                <i class="fas fa-file-excel"></i> Export
            </button>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">Total Suppliers</h6>
                            <h3 class="mb-0"><?php echo count($summary); ?></h3>
                        </div>
                        <i class="fas fa-users fa-3x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">Total Orders</h6>
                            <h3 class="mb-0"><?php echo array_sum(array_column($summary, 'total_orders')); ?></h3>
                        </div>
                        <i class="fas fa-shopping-cart fa-3x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">Total Received</h6>
                            <h3 class="mb-0">৳<?php echo number_format(array_sum(array_column($summary, 'total_received_value')) / 1000000, 2); ?>M</h3>
                        </div>
                        <i class="fas fa-truck-loading fa-3x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">Balance Payable</h6>
                            <h3 class="mb-0">৳<?php echo number_format(array_sum(array_column($summary, 'balance_payable')) / 1000000, 2); ?>M</h3>
                        </div>
                        <i class="fas fa-exclamation-triangle fa-3x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Supplier Summary Table -->
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Supplier-wise Payment Status</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="supplierTable">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>Supplier Name</th>
                            <th class="text-center">Total Orders</th>
                            <th class="text-end">Ordered Value</th>
                            <th class="text-end">Received Value</th>
                            <th class="text-end">Total Paid</th>
                            <th class="text-end">Balance Payable</th>
                            <th class="text-center">Payment %</th>
                            <th>Last Order</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($summary)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted">No data available</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($summary as $index => $supplier): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><strong><?php echo e($supplier->supplier_name); ?></strong></td>
                                <td class="text-center">
                                    <span class="badge bg-info"><?php echo $supplier->total_orders; ?></span>
                                </td>
                                <td class="text-end">৳<?php echo number_format($supplier->total_ordered_value, 2); ?></td>
                                <td class="text-end text-success">৳<?php echo number_format($supplier->total_received_value, 2); ?></td>
                                <td class="text-end text-primary">৳<?php echo number_format($supplier->total_paid, 2); ?></td>
                                <td class="text-end">
                                    <strong class="<?php echo $supplier->balance_payable > 0 ? 'text-danger' : 'text-success'; ?>">
                                        ৳<?php echo number_format($supplier->balance_payable, 2); ?>
                                    </strong>
                                </td>
                                <td class="text-center">
                                    <?php 
                                    $payment_percent = $supplier->total_received_value > 0 
                                        ? ($supplier->total_paid / $supplier->total_received_value * 100) 
                                        : 0;
                                    ?>
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar bg-<?php echo $payment_percent >= 100 ? 'success' : ($payment_percent >= 50 ? 'warning' : 'danger'); ?>" 
                                             style="width: <?php echo min($payment_percent, 100); ?>%">
                                            <?php echo number_format($payment_percent, 1); ?>%
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo $supplier->last_order_date ? date('d M Y', strtotime($supplier->last_order_date)) : 'N/A'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <!-- Totals Row -->
                            <tr class="table-info fw-bold">
                                <td colspan="2">TOTAL</td>
                                <td class="text-center"><?php echo array_sum(array_column($summary, 'total_orders')); ?></td>
                                <td class="text-end">৳<?php echo number_format(array_sum(array_column($summary, 'total_ordered_value')), 2); ?></td>
                                <td class="text-end">৳<?php echo number_format(array_sum(array_column($summary, 'total_received_value')), 2); ?></td>
                                <td class="text-end">৳<?php echo number_format(array_sum(array_column($summary, 'total_paid')), 2); ?></td>
                                <td class="text-end">৳<?php echo number_format(array_sum(array_column($summary, 'balance_payable')), 2); ?></td>
                                <td colspan="2"></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

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