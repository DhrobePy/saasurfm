<?php
/**
 * bank/bulk_manage.php
 * Bulk Transaction Management — Superadmin & admin only
 *
 * Allows selecting multiple transactions and performing:
 *   • Bulk Unpost  — soft-delete (status → unposted), reversible
 *   • Bulk Delete  — permanent hard delete from the database (Superadmin only)
 *
 * Every action is written to bank_tx_audit_log.
 */

require_once dirname(__DIR__) . '/core/init.php';
require_once dirname(__DIR__) . '/bank/BankManager.php';

restrict_access(['Superadmin', 'admin']);

$currentUser = getCurrentUser();
$userId      = $currentUser['id'];
$userRole    = $currentUser['role'];
$userName    = $currentUser['display_name'];
$ipAddress   = $_SERVER['REMOTE_ADDR'] ?? null;

$isSuperadmin = ($userRole === 'Superadmin');

$bankManager = new BankManager();

// ── Filters ────────────────────────────────────────────────────────────────
$filters = [
    'keyword'             => trim($_GET['keyword']             ?? ''),
    'bank_tx_account_id'  => $_GET['bank_tx_account_id']      ?? '',
    'entry_type'          => $_GET['entry_type']               ?? '',
    'status'              => $_GET['status']                   ?? '',
    'date_from'           => $_GET['date_from']                ?? '',
    'date_to'             => $_GET['date_to']                  ?? '',
    'transaction_type_id' => $_GET['transaction_type_id']      ?? '',
    'limit'               => 500,
    'offset'              => 0,
    'include_unposted'    => true,   // bulk manage always shows unposted too
];

$transactions = $bankManager->getTransactionsForBulkManage($filters);
$totalTx      = count($transactions);
$bankAccounts = $bankManager->getBankAccounts(false);
$txTypes      = $bankManager->getTransactionTypes(false);

$pageTitle = 'Bulk Transaction Management';
require_once dirname(__DIR__) . '/templates/header.php';
?>

<div class="w-full px-4 sm:px-6 lg:px-8 py-6">

    <?php echo display_message(); ?>

    <!-- ══════════════════════════════════════════════════════
         PAGE HEADER
    ═══════════════════════════════════════════════════════ -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6 gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-layer-group text-red-600"></i> Bulk Transaction Management
            </h1>
            <p class="text-sm text-gray-500 mt-0.5">
                Select transactions and apply bulk actions. All operations are audit-logged.
            </p>
        </div>
        <a href="<?php echo url('bank/index.php'); ?>"
           class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors">
            <i class="fas fa-arrow-left mr-2"></i> Back to Bank Dashboard
        </a>
    </div>

    <!-- ══════════════════════════════════════════════════════
         DANGER BANNER
    ═══════════════════════════════════════════════════════ -->
    <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6 flex items-start gap-3">
        <i class="fas fa-exclamation-triangle text-red-500 mt-0.5 text-lg flex-shrink-0"></i>
        <div class="text-sm">
            <p class="font-semibold text-red-800 mb-1">Destructive Operations Zone</p>
            <ul class="text-red-700 space-y-0.5 list-disc list-inside">
                <li><strong>Bulk Unpost</strong> — marks transactions as Unposted (hidden from balances). Reversible by editing the record.</li>
                <?php if ($isSuperadmin): ?>
                <li><strong>Bulk Delete</strong> — <span class="font-bold uppercase">permanently removes</span> selected transactions and their audit logs from the database. This cannot be undone.</li>
                <?php else: ?>
                <li><strong>Bulk Delete</strong> — Superadmin only. You do not have this permission.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════
         FILTER BAR
    ═══════════════════════════════════════════════════════ -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
        <form id="filterForm" method="GET" action="">
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">

                <div class="col-span-2">
                    <input type="text" name="keyword" id="keyword"
                           value="<?php echo htmlspecialchars($filters['keyword']); ?>"
                           placeholder="🔍  Search ref, payee, description…"
                           class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none">
                </div>

                <div>
                    <select name="bank_tx_account_id"
                            class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary-500 outline-none">
                        <option value="">All Accounts</option>
                        <?php foreach ($bankAccounts as $ba): ?>
                        <option value="<?php echo $ba->id; ?>"
                            <?php echo $filters['bank_tx_account_id'] == $ba->id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($ba->bank_name . ' – ' . $ba->account_name); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <select name="status"
                            class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary-500 outline-none">
                        <option value="">All Statuses</option>
                        <option value="pending"  <?php echo $filters['status'] === 'pending'  ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $filters['status'] === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $filters['status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="unposted" <?php echo $filters['status'] === 'unposted' ? 'selected' : ''; ?>>Unposted</option>
                        <option value="cancelled"<?php echo $filters['status'] === 'cancelled'? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>

                <div>
                    <select name="entry_type"
                            class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary-500 outline-none">
                        <option value="">Debit & Credit</option>
                        <option value="credit" <?php echo $filters['entry_type'] === 'credit' ? 'selected' : ''; ?>>Credit (In)</option>
                        <option value="debit"  <?php echo $filters['entry_type'] === 'debit'  ? 'selected' : ''; ?>>Debit (Out)</option>
                    </select>
                </div>

                <div>
                    <input type="date" name="date_from"
                           value="<?php echo htmlspecialchars($filters['date_from']); ?>"
                           class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary-500 outline-none">
                </div>

                <div>
                    <input type="date" name="date_to"
                           value="<?php echo htmlspecialchars($filters['date_to']); ?>"
                           class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary-500 outline-none">
                </div>

            </div>
            <div class="mt-3 flex gap-2">
                <button type="submit"
                        class="px-4 py-2 bg-primary-600 text-white text-sm rounded-lg hover:bg-primary-700 transition-colors">
                    <i class="fas fa-filter mr-1"></i> Apply Filters
                </button>
                <a href="bank/bulk_manage.php"
                   class="px-4 py-2 border border-gray-300 text-gray-600 text-sm rounded-lg hover:bg-gray-50 transition-colors">
                    <i class="fas fa-times mr-1"></i> Clear
                </a>
            </div>
        </form>
    </div>

    <!-- ══════════════════════════════════════════════════════
         BULK ACTION TOOLBAR  (hidden until rows are selected)
    ═══════════════════════════════════════════════════════ -->
    <div id="bulkToolbar"
         class="hidden bg-yellow-50 border border-yellow-300 rounded-xl px-5 py-3 mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <span class="text-sm font-semibold text-yellow-800">
            <i class="fas fa-check-square mr-2"></i>
            <span id="selectedCount">0</span> transaction(s) selected
        </span>
        <div class="flex flex-wrap gap-2">
            <button id="btnBulkUnpost"
                    class="inline-flex items-center px-4 py-2 bg-orange-500 text-white text-sm font-medium rounded-lg hover:bg-orange-600 transition-colors">
                <i class="fas fa-ban mr-2"></i> Bulk Unpost
            </button>
            <?php if ($isSuperadmin): ?>
            <button id="btnBulkDelete"
                    class="inline-flex items-center px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700 transition-colors">
                <i class="fas fa-trash mr-2"></i> Bulk Delete (Permanent)
            </button>
            <?php endif; ?>
            <button onclick="clearSelection()"
                    class="inline-flex items-center px-4 py-2 border border-gray-300 text-gray-600 text-sm rounded-lg hover:bg-gray-50 transition-colors">
                <i class="fas fa-times mr-1"></i> Clear Selection
            </button>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════
         TRANSACTION TABLE
    ═══════════════════════════════════════════════════════ -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">

        <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
            <span class="text-sm font-semibold text-gray-700">
                <?php echo number_format($totalTx); ?> transaction(s) loaded
                <span class="text-xs text-gray-400 font-normal ml-1">(max 500 per view — use filters to narrow down)</span>
            </span>
            <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer select-none">
                <input type="checkbox" id="selectAll" class="w-4 h-4 rounded border-gray-300 text-primary-600 cursor-pointer">
                Select all visible
            </label>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm" id="txTable">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="px-4 py-3 w-10"></th>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide w-36">Ref #</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Date</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Bank Account</th>
                        <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Entry</th>
                        <th class="text-right px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Amount (৳)</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Payee / Payer</th>
                        <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Created By</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Created At</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50" id="txBody">

                    <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="10" class="px-4 py-12 text-center text-gray-400">
                            <i class="fas fa-inbox text-3xl mb-2 block"></i>
                            No transactions found for the selected filters.
                        </td>
                    </tr>
                    <?php else: ?>

                    <?php foreach ($transactions as $tx): ?>
                    <?php
                        $statusMap = [
                            'pending'   => 'bg-yellow-100 text-yellow-700',
                            'approved'  => 'bg-green-100 text-green-700',
                            'rejected'  => 'bg-red-100 text-red-700',
                            'unposted'  => 'bg-gray-100 text-gray-400 line-through',
                            'cancelled' => 'bg-gray-100 text-gray-500',
                        ];
                        $cls = $statusMap[$tx->status] ?? 'bg-gray-100 text-gray-600';
                    ?>
                    <tr class="hover:bg-gray-50 transition-colors" data-id="<?php echo $tx->id; ?>">

                        <td class="px-4 py-3 text-center">
                            <input type="checkbox"
                                   class="tx-checkbox w-4 h-4 rounded border-gray-300 text-primary-600 cursor-pointer"
                                   value="<?php echo $tx->id; ?>">
                        </td>

                        <td class="px-4 py-3">
                            <a href="<?php echo url('bank/view_transaction.php?id=' . $tx->id); ?>"
                               target="_blank"
                               class="font-mono text-xs text-primary-600 font-semibold hover:underline">
                                <?php echo htmlspecialchars($tx->transaction_number); ?>
                            </a>
                        </td>

                        <td class="px-4 py-3 text-gray-700 whitespace-nowrap">
                            <?php echo date('d M Y', strtotime($tx->transaction_date)); ?>
                        </td>

                        <td class="px-4 py-3">
                            <p class="font-medium text-gray-800 text-xs"><?php echo htmlspecialchars($tx->bank_name ?? '—'); ?></p>
                            <p class="text-gray-400 text-xs"><?php echo htmlspecialchars($tx->account_name ?? ''); ?></p>
                        </td>

                        <td class="px-4 py-3 text-center">
                            <?php if ($tx->entry_type === 'credit'): ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-green-100 text-green-700 rounded-full text-xs font-semibold">
                                <i class="fas fa-arrow-down text-xs"></i> Credit
                            </span>
                            <?php else: ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-red-100 text-red-700 rounded-full text-xs font-semibold">
                                <i class="fas fa-arrow-up text-xs"></i> Debit
                            </span>
                            <?php endif; ?>
                        </td>

                        <td class="px-4 py-3 text-right font-semibold <?php echo $tx->entry_type === 'credit' ? 'text-green-700' : 'text-red-700'; ?>">
                            <?php echo $tx->entry_type === 'credit' ? '+' : '-'; ?>৳<?php echo number_format($tx->amount, 2); ?>
                        </td>

                        <td class="px-4 py-3 text-xs text-gray-600 max-w-[140px] truncate">
                            <?php echo htmlspecialchars($tx->payee_payer_name ?? '—'); ?>
                        </td>

                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-0.5 text-xs font-semibold rounded-full <?php echo $cls; ?>">
                                <?php echo ucfirst($tx->status); ?>
                            </span>
                        </td>

                        <td class="px-4 py-3 text-xs text-gray-500">
                            <?php echo htmlspecialchars($tx->created_by_name ?? '—'); ?>
                        </td>

                        <td class="px-4 py-3 text-xs text-gray-400 whitespace-nowrap">
                            <?php echo date('d M Y', strtotime($tx->created_at)); ?>
                        </td>

                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>

                </tbody>
            </table>
        </div>
    </div><!-- end table card -->

</div><!-- end page wrapper -->


<!-- ══════════════════════════════════════════════════════════
     MODAL: Confirm Bulk Unpost
═════════════════════════════════════════════════════════ -->
<div id="modalUnpost" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-40 px-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
        <div class="flex items-center gap-3 mb-4">
            <div class="h-10 w-10 rounded-full bg-orange-100 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-ban text-orange-600"></i>
            </div>
            <div>
                <h3 class="text-lg font-bold text-gray-900">Confirm Bulk Unpost</h3>
                <p class="text-sm text-gray-500">This will mark selected transactions as Unposted.</p>
            </div>
        </div>

        <div class="bg-orange-50 border border-orange-200 rounded-lg p-3 mb-5 text-sm text-orange-800">
            <strong><span id="unpostCount">0</span> transaction(s)</strong> will be moved to <em>Unposted</em> status.
            They will be hidden from balance calculations and the default view.
            <br><br>
            This action is <strong>reversible</strong> — records can be edited back to an active status.
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Reason <span class="text-red-500">*</span></label>
            <textarea id="unpostReason" rows="3"
                      class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-orange-400 outline-none resize-none"
                      placeholder="Why are you unposting these transactions?"></textarea>
        </div>

        <div class="flex gap-3">
            <button onclick="submitBulkUnpost()"
                    class="flex-1 bg-orange-500 text-white text-sm font-medium py-2.5 rounded-lg hover:bg-orange-600 transition-colors">
                <i class="fas fa-ban mr-2"></i> Unpost Selected
            </button>
            <button onclick="closeModal('modalUnpost')"
                    class="flex-1 border border-gray-200 text-gray-600 text-sm py-2.5 rounded-lg hover:bg-gray-50 transition-colors">
                Cancel
            </button>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════
     MODAL: Confirm Bulk Delete (Superadmin only)
═════════════════════════════════════════════════════════ -->
<?php if ($isSuperadmin): ?>
<div id="modalDelete" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 px-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
        <div class="flex items-center gap-3 mb-4">
            <div class="h-10 w-10 rounded-full bg-red-100 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-skull-crossbones text-red-600"></i>
            </div>
            <div>
                <h3 class="text-lg font-bold text-gray-900">Confirm Permanent Delete</h3>
                <p class="text-sm text-red-600 font-medium">⚠ This cannot be undone.</p>
            </div>
        </div>

        <div class="bg-red-50 border border-red-300 rounded-lg p-3 mb-5 text-sm text-red-800">
            <strong><span id="deleteCount">0</span> transaction(s)</strong> and their complete audit logs will be
            <strong>permanently erased</strong> from the database. There is no recovery.
            <br><br>
            Type <code class="bg-red-100 px-1 rounded font-mono text-xs">DELETE</code> below to confirm.
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Type DELETE to confirm</label>
            <input type="text" id="deleteConfirmText"
                   class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-red-400 outline-none font-mono uppercase tracking-widest"
                   placeholder="DELETE">
        </div>

        <div class="flex gap-3">
            <button onclick="submitBulkDelete()"
                    class="flex-1 bg-red-600 text-white text-sm font-medium py-2.5 rounded-lg hover:bg-red-700 transition-colors">
                <i class="fas fa-trash mr-2"></i> Permanently Delete
            </button>
            <button onclick="closeModal('modalDelete')"
                    class="flex-1 border border-gray-200 text-gray-600 text-sm py-2.5 rounded-lg hover:bg-gray-50 transition-colors">
                Cancel
            </button>
        </div>
    </div>
</div>
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════════
     TOAST
═════════════════════════════════════════════════════════ -->
<div id="toast"
     class="hidden fixed bottom-6 right-6 z-50 px-5 py-3 rounded-xl shadow-lg text-sm font-medium text-white transition-all duration-300">
</div>


<script>
const csrfToken   = '<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES); ?>';
const isSuperadmin = <?php echo $isSuperadmin ? 'true' : 'false'; ?>;

// ── Selection tracking ─────────────────────────────────────
let selectedIds = new Set();

document.querySelectorAll('.tx-checkbox').forEach(cb => {
    cb.addEventListener('change', () => {
        if (cb.checked) selectedIds.add(parseInt(cb.value));
        else             selectedIds.delete(parseInt(cb.value));
        updateToolbar();
    });
});

document.getElementById('selectAll').addEventListener('change', function () {
    document.querySelectorAll('.tx-checkbox').forEach(cb => {
        cb.checked = this.checked;
        if (this.checked) selectedIds.add(parseInt(cb.value));
        else               selectedIds.delete(parseInt(cb.value));
    });
    updateToolbar();
});

function updateToolbar() {
    const n       = selectedIds.size;
    const toolbar = document.getElementById('bulkToolbar');
    document.getElementById('selectedCount').textContent = n;
    toolbar.classList.toggle('hidden', n === 0);
}

function clearSelection() {
    selectedIds.clear();
    document.querySelectorAll('.tx-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('selectAll').checked = false;
    updateToolbar();
}

// ── Modal helpers ──────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

// ── Bulk Unpost ────────────────────────────────────────────
document.getElementById('btnBulkUnpost').addEventListener('click', () => {
    if (selectedIds.size === 0) return;
    document.getElementById('unpostCount').textContent = selectedIds.size;
    document.getElementById('unpostReason').value = '';
    openModal('modalUnpost');
});

function submitBulkUnpost() {
    const reason = document.getElementById('unpostReason').value.trim();
    if (!reason) {
        alert('Please enter a reason for unposting.');
        return;
    }

    const ids = Array.from(selectedIds);
    postAction('bulk_unpost', { ids: ids, reason: reason }, () => {
        closeModal('modalUnpost');
        showToast('✅ ' + ids.length + ' transaction(s) unposted successfully.', 'success');
        setTimeout(() => location.reload(), 1200);
    });
}

// ── Bulk Delete ────────────────────────────────────────────
<?php if ($isSuperadmin): ?>
document.getElementById('btnBulkDelete').addEventListener('click', () => {
    if (selectedIds.size === 0) return;
    document.getElementById('deleteCount').textContent = selectedIds.size;
    document.getElementById('deleteConfirmText').value = '';
    openModal('modalDelete');
});
<?php endif; ?>

function submitBulkDelete() {
    const confirmation = document.getElementById('deleteConfirmText').value.trim().toUpperCase();
    if (confirmation !== 'DELETE') {
        alert('Please type DELETE (all caps) to confirm.');
        return;
    }

    const ids = Array.from(selectedIds);
    postAction('bulk_delete', { ids: ids }, () => {
        closeModal('modalDelete');
        showToast('🗑️ ' + ids.length + ' transaction(s) permanently deleted.', 'success');
        setTimeout(() => location.reload(), 1200);
    });
}

// ── Generic AJAX ───────────────────────────────────────────
function postAction(action, data, onSuccess) {
    // Disable buttons during request
    document.querySelectorAll('#bulkToolbar button, #modalUnpost button, #modalDelete button')
            .forEach(b => b.disabled = true);

    const body = new URLSearchParams({ action, csrf: csrfToken });
    data.ids.forEach(id => body.append('ids[]', id));
    if (data.reason) body.append('reason', data.reason);

    fetch('ajax_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
    .then(r => r.json())
    .then(d => {
        document.querySelectorAll('#bulkToolbar button, #modalUnpost button, #modalDelete button')
                .forEach(b => b.disabled = false);
        if (d.success) {
            onSuccess();
        } else {
            showToast('❌ ' + (d.message || 'An error occurred.'), 'error');
        }
    })
    .catch(() => {
        document.querySelectorAll('#bulkToolbar button, #modalUnpost button, #modalDelete button')
                .forEach(b => b.disabled = false);
        showToast('❌ Network error. Please try again.', 'error');
    });
}

// ── Toast ──────────────────────────────────────────────────
function showToast(msg, type = 'success') {
    const toast = document.getElementById('toast');
    toast.textContent = msg;
    toast.className = 'fixed bottom-6 right-6 z-50 px-5 py-3 rounded-xl shadow-lg text-sm font-medium text-white transition-all duration-300';
    toast.classList.add(type === 'success' ? 'bg-green-600' : 'bg-red-600');
    toast.classList.remove('hidden');
    setTimeout(() => toast.classList.add('hidden'), 4000);
}

// Close modals on backdrop click
['modalUnpost', 'modalDelete'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('click', e => { if (e.target === el) closeModal(id); });
});
</script>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
