<?php
/**
 * bank/view_transaction.php
 * View a single bank transaction with full detail and audit trail
 */

require_once dirname(__DIR__) . '/core/init.php';
require_once dirname(__DIR__) . '/bank/BankManager.php';

restrict_access();

$currentUser = getCurrentUser();
$userId      = $currentUser['id'];
$userRole    = $currentUser['role'];
$userName    = $currentUser['display_name'];

$adminRoles  = ['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg'];
$isAdmin     = in_array($userRole, $adminRoles);

$bankManager = new BankManager();
$txId        = (int)($_GET['id'] ?? 0);
$tx          = $bankManager->getTransactionById($txId);

if (!$tx) {
    $_SESSION['error_flash'] = 'Transaction not found.';
    header('Location: ' . url('bank/index.php'));
    exit;
}

// Permission check for non-admin
if (!$isAdmin && $tx->created_by_user_id != $userId) {
    $_SESSION['error_flash'] = 'Access denied.';
    header('Location: ' . url('bank/index.php'));
    exit;
}

// Audit log
$auditLog = $bankManager->getAuditLog($txId);

// Log this view
$bankManager->writeAuditLog($txId, 'viewed', $userId, $userName, $_SERVER['REMOTE_ADDR'] ?? null);

$pageTitle = 'View Transaction: ' . $tx->transaction_number;
require_once dirname(__DIR__) . '/templates/header.php';

$isCredit   = $tx->entry_type === 'credit';
$statusMap  = ['pending'=>'yellow','approved'=>'green','rejected'=>'red','cancelled'=>'gray'];
$statusColor = $statusMap[$tx->status] ?? 'gray';
?>

<div class="w-full max-w-3xl mx-auto px-4 sm:px-6 py-6">

    <div class="flex items-center gap-3 mb-6">
        <a href="<?php echo url('bank/index.php'); ?>" class="text-gray-400 hover:text-gray-600">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div class="flex-1">
            <h1 class="text-xl font-bold text-gray-900 font-mono"><?php echo htmlspecialchars($tx->transaction_number); ?></h1>
            <p class="text-sm text-gray-500"><?php echo date('l, d F Y', strtotime($tx->transaction_date)); ?></p>
        </div>
        <div class="flex gap-2">
            <a href="<?php echo url('bank/receipt.php?id=' . $tx->id); ?>" target="_blank"
               class="inline-flex items-center px-3 py-2 border border-gray-200 rounded-lg text-sm text-gray-600 hover:bg-gray-50">
                <i class="fas fa-print mr-1.5"></i> Receipt
            </a>
            <?php if ($isAdmin): ?>
            <a href="<?php echo url('bank/create_transaction.php?edit=' . $tx->id); ?>"
               class="inline-flex items-center px-3 py-2 bg-primary-600 rounded-lg text-sm text-white hover:bg-primary-700">
                <i class="fas fa-pencil-alt mr-1.5"></i> Edit
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Status Banner -->
    <div class="mb-5 p-4 rounded-xl border-l-4
        <?php echo $tx->status === 'approved' ? 'bg-green-50 border-green-500' :
                   ($tx->status === 'pending' ? 'bg-yellow-50 border-yellow-500' :
                    ($tx->status === 'rejected' ? 'bg-red-50 border-red-500' : 'bg-gray-50 border-gray-400')); ?>">
        <div class="flex items-center justify-between">
            <div>
                <p class="font-semibold text-gray-800">
                    Status: <span class="text-<?php echo $statusColor; ?>-700"><?php echo ucfirst($tx->status); ?></span>
                </p>
                <?php if ($tx->status === 'approved' && $tx->approved_by_name): ?>
                <p class="text-xs text-gray-500 mt-0.5">
                    Approved by <?php echo htmlspecialchars($tx->approved_by_name); ?>
                    on <?php echo date('d M Y H:i', strtotime($tx->approved_at)); ?>
                </p>
                <?php elseif ($tx->status === 'rejected' && $tx->rejection_reason): ?>
                <p class="text-xs text-red-600 mt-0.5">Reason: <?php echo htmlspecialchars($tx->rejection_reason); ?></p>
                <?php elseif ($tx->status === 'pending'): ?>
                <p class="text-xs text-gray-500 mt-0.5">Awaiting review by Accounts / Admin</p>
                <?php endif; ?>
            </div>
            <div class="text-right">
                <p class="text-3xl font-bold <?php echo $isCredit ? 'text-green-700' : 'text-red-700'; ?>">
                    <?php echo $isCredit ? '+' : '-'; ?>৳<?php echo number_format($tx->amount, 2); ?>
                </p>
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-sm font-semibold
                    <?php echo $isCredit ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                    <i class="fas fa-arrow-<?php echo $isCredit ? 'down' : 'up'; ?> text-xs"></i>
                    <?php echo ucfirst($tx->entry_type); ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Main Detail Card -->
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-5">
        <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-4">Transaction Details</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4">

            <?php
            $fields = [
                ['Transaction Number', $tx->transaction_number, 'font-mono text-primary-600'],
                ['Bank',              $tx->bank_name],
                ['Account Name',     $tx->account_name],
                ['Account Number',   $tx->account_number, 'font-mono'],
                ['Transaction Date', date('d F Y', strtotime($tx->transaction_date))],
                ['Entry Type',       ucfirst($tx->entry_type)],
                ['Amount',           '৳' . number_format($tx->amount, 2), 'font-bold text-lg'],
                ['Category',         $tx->type_name ?: '—'],
                ['Reference Number', $tx->reference_number ?: '—', 'font-mono'],
                ['Cheque Number',    $tx->cheque_number ?: '—', 'font-mono'],
                ['Payee / Payer',    $tx->payee_payer_name ?: '—'],
                ['Branch',           $tx->branch_name ?: '—'],
                ['Created By',       $tx->created_by_name],
                ['Created At',       date('d M Y H:i', strtotime($tx->created_at))],
            ];
            foreach ($fields as $f): [$label, $value] = $f; $cls = $f[2] ?? '';
            ?>
            <div>
                <p class="text-xs text-gray-400 uppercase tracking-wide font-medium"><?php echo $label; ?></p>
                <p class="text-sm font-medium text-gray-800 mt-0.5 <?php echo $cls; ?>"><?php echo htmlspecialchars((string)$value); ?></p>
            </div>
            <?php endforeach; ?>

        </div>

        <?php if ($tx->description): ?>
        <div class="mt-5 pt-5 border-t border-gray-100">
            <p class="text-xs text-gray-400 uppercase tracking-wide font-medium mb-1">Description</p>
            <p class="text-sm text-gray-700"><?php echo htmlspecialchars($tx->description); ?></p>
        </div>
        <?php endif; ?>

        <?php if ($tx->special_note): ?>
        <div class="mt-4 p-3 bg-yellow-50 border border-yellow-100 rounded-xl">
            <p class="text-xs text-yellow-600 font-semibold uppercase tracking-wide mb-1">Special Note</p>
            <p class="text-sm text-yellow-800"><?php echo htmlspecialchars($tx->special_note); ?></p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Admin Actions -->
    <?php if ($isAdmin && $tx->status === 'pending'): ?>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 mb-5">
        <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-4">Review Actions</h2>
        <div class="flex gap-3">
            <button onclick="approveTransaction(<?php echo $tx->id; ?>, '<?php echo $tx->transaction_number; ?>')"
                    class="flex-1 bg-green-600 text-white py-2.5 rounded-xl text-sm font-semibold hover:bg-green-700 transition-colors flex items-center justify-center gap-2">
                <i class="fas fa-check"></i> Approve Transaction
            </button>
            <button onclick="rejectPrompt(<?php echo $tx->id; ?>)"
                    class="flex-1 bg-red-600 text-white py-2.5 rounded-xl text-sm font-semibold hover:bg-red-700 transition-colors flex items-center justify-center gap-2">
                <i class="fas fa-times"></i> Reject Transaction
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Audit Log -->
    <?php if ($isAdmin && !empty($auditLog)): ?>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
        <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-4 flex items-center gap-2">
            <i class="fas fa-history text-gray-400"></i> Audit Trail
        </h2>
        <div class="space-y-3">
            <?php foreach ($auditLog as $log):
                $actionColors = ['created'=>'blue','updated'=>'yellow','approved'=>'green','rejected'=>'red','deleted'=>'gray','viewed'=>'gray'];
                $ac = $actionColors[$log->action] ?? 'gray';
            ?>
            <div class="flex gap-3 text-sm">
                <div class="w-2 h-2 rounded-full bg-<?php echo $ac; ?>-400 mt-2 flex-shrink-0"></div>
                <div class="flex-1">
                    <div class="flex items-baseline gap-2">
                        <span class="font-semibold text-<?php echo $ac; ?>-700 capitalize"><?php echo $log->action; ?></span>
                        <span class="text-gray-500 text-xs">by <?php echo htmlspecialchars($log->action_by_username ?? '—'); ?></span>
                        <span class="text-gray-400 text-xs ml-auto"><?php echo date('d M Y H:i', strtotime($log->created_at)); ?></span>
                    </div>
                    <?php if ($log->ip_address): ?>
                    <p class="text-xs text-gray-400 font-mono">IP: <?php echo htmlspecialchars($log->ip_address); ?></p>
                    <?php endif; ?>
                    <?php if ($log->notes): ?>
                    <p class="text-xs text-gray-500 mt-0.5"><?php echo htmlspecialchars($log->notes); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Reject Modal -->
<div id="rejectModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-40">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 p-6">
        <h3 class="text-lg font-bold text-gray-900 mb-4">Reject Transaction</h3>
        <input type="hidden" id="rejectTxId">
        <textarea id="rejectReason" rows="3"
                  class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-red-400 outline-none mb-4"
                  placeholder="Reason for rejection..."></textarea>
        <div class="flex gap-3">
            <button onclick="submitReject()" class="flex-1 bg-red-600 text-white py-2 rounded-lg text-sm hover:bg-red-700">Confirm Reject</button>
            <button onclick="document.getElementById('rejectModal').classList.add('hidden')" class="flex-1 border border-gray-200 text-gray-600 py-2 rounded-lg text-sm hover:bg-gray-50">Cancel</button>
        </div>
    </div>
</div>

<script>
const csrfToken = '<?php echo htmlspecialchars($_SESSION["csrf_token"] ?? "", ENT_QUOTES); ?>';

function approveTransaction(id, ref) {
    if (!confirm('Approve ' + ref + '?')) return;
    fetch('ajax_handler.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=approve&id=' + id + '&csrf=' + csrfToken
    }).then(r=>r.json()).then(d => {
        if (d.success) location.reload();
        else alert(d.message || 'Error');
    });
}
function rejectPrompt(id) {
    document.getElementById('rejectTxId').value = id;
    document.getElementById('rejectReason').value = '';
    document.getElementById('rejectModal').classList.remove('hidden');
}
function submitReject() {
    const reason = document.getElementById('rejectReason').value.trim();
    if (!reason) { alert('Please enter a reason.'); return; }
    const id = document.getElementById('rejectTxId').value;
    fetch('ajax_handler.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=reject&id=' + id + '&reason=' + encodeURIComponent(reason) + '&csrf=' + csrfToken
    }).then(r=>r.json()).then(d => {
        if (d.success) location.reload();
        else alert(d.message || 'Error');
    });
}
</script>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>