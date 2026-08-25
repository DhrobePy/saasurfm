<?php
require_once __DIR__ . '/../core/init.php';
restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$page_title = "Weight Variance Report";

// Initialize manager
$manager = new Goodsreceivedadnanmanager();

// Get variance analysis
$variances = $manager->getVarianceAnalysis();

// Calculate statistics
$total_variances = count($variances);
$loss_count = count(array_filter($variances, fn($v) => $v->variance_type == 'loss'));
$gain_count = count(array_filter($variances, fn($v) => $v->variance_type == 'gain'));
$total_loss_value = array_sum(array_map(fn($v) => $v->variance_type == 'loss' ? abs($v->variance_value) : 0, $variances));
$total_gain_value = array_sum(array_map(fn($v) => $v->variance_type == 'gain' ? $v->variance_value : 0, $variances));
$net_variance = $total_gain_value - $total_loss_value;

include __DIR__ . '/../templates/header.php';
?>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1"><i class="fas fa-balance-scale"></i> Weight Variance Report</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?php echo url('purchase/purchase_adnan_index.php'); ?>">Purchase (Adnan)</a></li>
                    <li class="breadcrumb-item active">Variance Report</li>
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
            <div class="card bg-info text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">Total Variances</h6>
                            <h3 class="mb-0"><?php echo $total_variances; ?></h3>
                        </div>
                        <i class="fas fa-balance-scale fa-3x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">Total Losses</h6>
                            <h3 class="mb-0"><?php echo $loss_count; ?></h3>
                            <small>৳<?php echo number_format($total_loss_value, 2); ?></small>
                        </div>
                        <i class="fas fa-arrow-down fa-3x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">Total Gains</h6>
                            <h3 class="mb-0"><?php echo $gain_count; ?></h3>
                            <small>৳<?php echo number_format($total_gain_value, 2); ?></small>
                        </div>
                        <i class="fas fa-arrow-up fa-3x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-<?php echo $net_variance >= 0 ? 'success' : 'danger'; ?> text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">Net Variance</h6>
                            <h3 class="mb-0">৳<?php echo number_format(abs($net_variance), 2); ?></h3>
                            <small><?php echo $net_variance >= 0 ? 'Gain' : 'Loss'; ?></small>
                        </div>
                        <i class="fas fa-calculator fa-3x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Variance Table -->
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Variance Details</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="varianceTable">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>GRN Date</th>
                            <th>PO#</th>
                            <th>Supplier</th>
                            <th>GRN#</th>
                            <th>Truck#</th>
                            <th class="text-end">Ordered (KG)</th>
                            <th class="text-end">Received (KG)</th>
                            <th class="text-end">Variance (KG)</th>
                            <th class="text-center">Variance %</th>
                            <th class="text-end">Value Impact</th>
                            <th>Type</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($variances)): ?>
                            <tr>
                                <td colspan="13" class="text-center text-muted">No variances recorded</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($variances as $index => $variance): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo date('d M Y', strtotime($variance->grn_date)); ?></td>
                                <td><small><?php echo e($variance->po_number); ?></small></td>
                                <td><?php echo e($variance->supplier_name); ?></td>
                                <td><small><?php echo e($variance->grn_number); ?></small></td>
                                <td><?php echo e($variance->truck_number ?: 'N/A'); ?></td>
                                <td class="text-end"><?php echo number_format($variance->ordered_quantity, 2); ?></td>
                                <td class="text-end"><?php echo number_format($variance->received_quantity, 2); ?></td>
                                <td class="text-end">
                                    <span class="<?php echo $variance->variance < 0 ? 'text-danger' : 'text-success'; ?>">
                                        <?php echo $variance->variance > 0 ? '+' : ''; ?><?php echo number_format($variance->variance, 2); ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?php echo abs($variance->variance_percentage) > 1 ? 'danger' : 'warning'; ?>">
                                        <?php echo $variance->variance_percentage > 0 ? '+' : ''; ?><?php echo $variance->variance_percentage; ?>%
                                    </span>
                                </td>
                                <td class="text-end">
                                    <span class="<?php echo $variance->variance_value < 0 ? 'text-danger' : 'text-success'; ?>">
                                        ৳<?php echo number_format(abs($variance->variance_value), 2); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $type_colors = [
                                        'loss' => 'danger',
                                        'gain' => 'success',
                                        'normal' => 'secondary'
                                    ];
                                    ?>
                                    <span class="badge bg-<?php echo $type_colors[$variance->variance_type] ?? 'secondary'; ?>">
                                        <?php echo ucfirst($variance->variance_type); ?>
                                    </span>
                                </td>
                                <td><small><?php echo e($variance->remarks ?: '-'); ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Analysis Section -->
    <div class="row mt-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0">Variance Distribution</h5>
                </div>
                <div class="card-body">
                    <canvas id="varianceChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Financial Impact</h5>
                </div>
                <div class="card-body">
                    <canvas id="impactChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
// Variance Distribution Chart
const varianceCtx = document.getElementById('varianceChart');
if (varianceCtx) {
    new Chart(varianceCtx, {
        type: 'pie',
        data: {
            labels: ['Losses', 'Gains', 'Normal'],
            datasets: [{
                data: [
                    <?php echo $loss_count; ?>,
                    <?php echo $gain_count; ?>,
                    <?php echo $total_variances - $loss_count - $gain_count; ?>
                ],
                backgroundColor: ['#dc3545', '#28a745', '#6c757d']
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
}

// Financial Impact Chart
const impactCtx = document.getElementById('impactChart');
if (impactCtx) {
    new Chart(impactCtx, {
        type: 'bar',
        data: {
            labels: ['Losses', 'Gains', 'Net'],
            datasets: [{
                label: 'Amount (৳)',
                data: [
                    -<?php echo $total_loss_value; ?>,
                    <?php echo $total_gain_value; ?>,
                    <?php echo $net_variance; ?>
                ],
                backgroundColor: ['#dc3545', '#28a745', '<?php echo $net_variance >= 0 ? "#28a745" : "#dc3545"; ?>']
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
}

function exportToExcel() {
    const table = document.getElementById('varianceTable');
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
            let text = td.textContent.trim();
            text = text.replace(/৳/g, '').replace(/,/g, '').replace(/%/g, '');
            cells.push(text);
        });
        rows.push(cells.join('\t'));
    });
    
    const tsvContent = rows.join('\n');
    const blob = new Blob([tsvContent], { type: 'text/tab-separated-values' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'variance_report_' + new Date().toISOString().split('T')[0] + '.xls';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>