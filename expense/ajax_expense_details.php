<?php
/**
 * AJAX Handler: View Expense Details
 * Returns HTML for expense voucher details modal
 */

require_once '../core/init.php';
require_once '../core/functions/helpers.php';

// Check permission
if (!canViewExpense()) {
    echo '<div class="alert alert-danger">You do not have permission to view this expense</div>';
    exit();
}

$expenseId = $_GET['id'] ?? null;

if (!$expenseId) {
    echo '<div class="alert alert-danger">Invalid expense ID</div>';
    exit();
}

// Fetch expense details
$stmt = $db->prepare("
    SELECT 
        ev.*,
        ec.name as category_name,
        esc.name as subcategory_name,
        b.name as branch_name,
        b.address as branch_address,
        creator.display_name as created_by_name,
        creator.email as created_by_email,
        approver.display_name as approved_by_name,
        approver.email as approved_by_email
    FROM expense_vouchers ev
    LEFT JOIN expense_categories ec ON ev.category_id = ec.id
    LEFT JOIN expense_subcategories esc ON ev.subcategory_id = esc.id
    LEFT JOIN branches b ON ev.branch_id = b.id
    LEFT JOIN users creator ON ev.created_by = creator.id
    LEFT JOIN users approver ON ev.approved_by = approver.id
    WHERE ev.id = :id
");

$stmt->execute(['id' => $expenseId]);
$expense = $stmt->fetch(PDO::FETCH_OBJ);

if (!$expense) {
    echo '<div class="alert alert-danger">Expense not found</div>';
    exit();
}

// Fetch action log
$logStmt = $db->prepare("
    SELECT 
        eal.*,
        u.display_name as action_by_name
    FROM expense_action_log eal
    LEFT JOIN users u ON eal.action_by = u.id
    WHERE eal.expense_voucher_id = :id
    ORDER BY eal.created_at DESC
");

$logStmt->execute(['id' => $expenseId]);
$actionLog = $logStmt->fetchAll(PDO::FETCH_OBJ);

// Status color
$statusColors = [
    'pending' => 'warning',
    'approved' => 'success',
    'rejected' => 'danger',
    'cancelled' => 'secondary'
];
$statusColor = $statusColors[$expense->status] ?? 'secondary';
?>

<div class="container-fluid">
    
    <!-- Expense Details -->
    <div class="row mb-3">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-light">
                    <h6 class="mb-0">
                        <i class="fas fa-file-invoice"></i> Voucher Information
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <th width="40%">Voucher Number:</th>
                                    <td><strong><?= htmlspecialchars($expense->voucher_number) ?></strong></td>
                                </tr>
                                <tr>
                                    <th>Expense Date:</th>
                                    <td><?= date('F d, Y', strtotime($expense->expense_date)) ?></td>
                                </tr>
                                <tr>
                                    <th>Branch:</th>
                                    <td>
                                        <?= htmlspecialchars($expense->branch_name) ?>
                                        <?php if ($expense->branch_address): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($expense->branch_address) ?></small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Category:</th>
                                    <td>
                                        <?= htmlspecialchars($expense->category_name) ?>
                                        <?php if ($expense->subcategory_name): ?>
                                            <br><small class="text-muted">Subcategory: <?= htmlspecialchars($expense->subcategory_name) ?></small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <th width="40%">Amount:</th>
                                    <td><h4 class="mb-0 text-primary">৳ <?= number_format($expense->amount, 2) ?></h4></td>
                                </tr>
                                <tr>
                                    <th>Payment Method:</th>
                                    <td><?= ucfirst(str_replace('_', ' ', $expense->payment_method)) ?></td>
                                </tr>
                                <?php if ($expense->reference_number): ?>
                                    <tr>
                                        <th>Reference Number:</th>
                                        <td><?= htmlspecialchars($expense->reference_number) ?></td>
                                    </tr>
                                <?php endif; ?>
                                <tr>
                                    <th>Status:</th>
                                    <td>
                                        <span class="badge bg-<?= $statusColor ?>">
                                            <?= ucfirst($expense->status) ?>
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <div class="row mt-2">
                        <div class="col-md-12">
                            <strong>Description:</strong>
                            <p class="mb-0"><?= nl2br(htmlspecialchars($expense->description)) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Creator & Approver Info -->
    <div class="row mb-3">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-light">
                    <h6 class="mb-0">
                        <i class="fas fa-user"></i> Created By
                    </h6>
                </div>
                <div class="card-body">
                    <p class="mb-1"><strong><?= htmlspecialchars($expense->created_by_name) ?></strong></p>
                    <p class="mb-1"><small><?= htmlspecialchars($expense->created_by_email) ?></small></p>
                    <p class="mb-0">
                        <small class="text-muted">
                            <i class="fas fa-clock"></i> 
                            <?= date('F d, Y \a\t g:i A', strtotime($expense->created_at)) ?>
                        </small>
                    </p>
                </div>
            </div>
        </div>
        
        <?php if ($expense->approved_by): ?>
            <div class="col-md-6">
                <div class="card border-success">
                    <div class="card-header bg-success text-white">
                        <h6 class="mb-0">
                            <i class="fas fa-check-circle"></i> Approved By
                        </h6>
                    </div>
                    <div class="card-body">
                        <p class="mb-1"><strong><?= htmlspecialchars($expense->approved_by_name) ?></strong></p>
                        <p class="mb-1"><small><?= htmlspecialchars($expense->approved_by_email) ?></small></p>
                        <p class="mb-0">
                            <small class="text-muted">
                                <i class="fas fa-clock"></i> 
                                <?= date('F d, Y \a\t g:i A', strtotime($expense->approved_at)) ?>
                            </small>
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Action Log (Only for Superadmin) -->
    <?php if (isSuperAdmin() && !empty($actionLog)): ?>
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="mb-0">
                            <i class="fas fa-history"></i> Action History
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Date/Time</th>
                                        <th>Action</th>
                                        <th>User</th>
                                        <th>Status Change</th>
                                        <th>Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($actionLog as $log): ?>
                                        <tr>
                                            <td>
                                                <small><?= date('M d, Y H:i', strtotime($log->created_at)) ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary">
                                                    <?= ucfirst($log->action) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($log->action_by_name) ?></td>
                                            <td>
                                                <?php if ($log->old_status && $log->new_status): ?>
                                                    <small>
                                                        <?= ucfirst($log->old_status) ?> 
                                                        <i class="fas fa-arrow-right"></i> 
                                                        <?= ucfirst($log->new_status) ?>
                                                    </small>
                                                <?php else: ?>
                                                    <small class="text-muted">-</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small><?= htmlspecialchars($log->remarks ?? '-') ?></small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
</div>

<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
        Close
    </button>
    <?php if ($expense->status === 'approved'): ?>
        <button type="button" class="btn btn-primary" onclick="window.open('/expense/print_expense_voucher.php?id=<?= $expense->id ?>', '_blank')">
            <i class="fas fa-print"></i> Print Voucher
        </button>
    <?php endif; ?>
</div>