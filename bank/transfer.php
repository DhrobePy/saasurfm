<?php
/**
 * bank/transfer.php
 * Internal Bank-to-Bank Transfer Management
 * Roles: Superadmin, admin, bank-initiator, bank-approver,
 *        Accounts, accounts-demra, accounts-srg
 */

require_once dirname(__DIR__) . '/core/init.php';
require_once dirname(__DIR__) . '/bank/BankManager.php';

$allowedRoles = ['Superadmin', 'admin', 'bank Transaction initiator', 'Bank Transaction Approver'];
restrict_access($allowedRoles);

$currentUser  = getCurrentUser();
$userId       = $currentUser['id'];
$userRole     = $currentUser['role'];
$userName     = $currentUser['display_name'];
$ipAddress    = $_SERVER['REMOTE_ADDR'] ?? null;

$bankManager  = new BankManager();
$db           = Database::getInstance();

$approverRoles   = ['Superadmin', 'admin', 'bank-approver'];
$isApprover      = in_array($userRole, $approverRoles);
$initiatorRoles  = $allowedRoles;
$isInitiator     = in_array($userRole, $initiatorRoles);

$csrfToken = $_SESSION['csrf_token'] ?? '';
$error     = '';
$success   = '';

// ── Handle new transfer form submission ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_transfer') {
    try {
        $result = $bankManager->createTransfer($_POST, $userId, $userName, $ipAddress);
        $_SESSION['success_flash'] = 'Transfer ' . $result['transfer_number'] . ' submitted for approval.';
        header('Location: transfer.php');
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ── Filters ────────────────────────────────────────────────
$filters = [
    'keyword'         => trim($_GET['keyword']         ?? ''),
    'from_account_id' => (int)($_GET['from_account_id'] ?? 0),
    'to_account_id'   => (int)($_GET['to_account_id']   ?? 0),
    'status'          => $_GET['status']    ?? '',
    'date_from'       => $_GET['date_from'] ?? '',
    'date_to'         => $_GET['date_to']   ?? '',
];

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$filters['limit']  = $perPage;
$filters['offset'] = ($page - 1) * $perPage;

$transfers  = $bankManager->getTransfers($filters, $userId, $userRole);
$totalCount = $bankManager->countTransfers($filters, $userId, $userRole);
$totalPages = max(1, ceil($totalCount / $perPage));

$accounts   = $bankManager->getBankAccounts(true);

// Pending count for badge
$pendingCount = $bankManager->countTransfers(['status' => 'pending'], $userId, $userRole);

$pageTitle = 'Internal Transfers';
require_once dirname(__DIR__) . '/templates/header.php';
?>

<div class="w-full px-4 sm:px-6 lg:px-8 py-6">

    <?php echo display_message(); ?>
    <?php if ($error): ?>
    <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-5 rounded-r-lg flex items-start gap-3">
        <i class="fas fa-exclamation-circle mt-0.5"></i>
        <p><?php echo htmlspecialchars($error); ?></p>
    </div>
    <?php endif; ?>

    <!-- ── Page Header ──────────────────────────────────── -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-exchange-alt text-primary-600"></i>
                Internal Transfers
                <?php if ($pendingCount > 0): ?>
                <span class="ml-2 px-2.5 py-0.5 text-xs font-bold rounded-full bg-yellow-100 text-yellow-700">
                    <?php echo $pendingCount; ?> pending
                </span>
                <?php endif; ?>
            </h1>
            <p class="text-sm text-gray-500 mt-0.5">Transfer funds between bank accounts with full audit trail</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="<?php echo url('bank/index.php'); ?>"
               class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-600 bg-white hover:bg-gray-50">
                <i class="fas fa-arrow-left mr-1.5"></i> Back
            </a>
            <button onclick="openCreateModal()"
                    class="inline-flex items-center px-4 py-2 bg-primary-600 text-white rounded-lg text-sm font-semibold hover:bg-primary-700 transition-colors">
                <i class="fas fa-plus mr-1.5"></i> New Transfer
            </button>
        </div>
    </div>

    <!-- ── Summary Cards ────────────────────────────────── -->
    <?php
    $summaryRows = $db->query(
        "SELECT
            COUNT(*) AS total,
            COUNT(CASE WHEN status='pending'  THEN 1 END) AS pending,
            COUNT(CASE WHEN status='approved' THEN 1 END) AS approved,
            COUNT(CASE WHEN status='rejected' THEN 1 END) AS rejected,
            COALESCE(SUM(CASE WHEN status='approved' THEN amount ELSE 0 END),0) AS approved_amount
         FROM bank_tx_transfers
         WHERE transfer_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
    )->first();
    ?>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Last 30 Days</p>
            <p class="text-2xl font-bold text-gray-800 mt-1"><?php echo $summaryRows->total ?? 0; ?></p>
            <p class="text-xs text-gray-400 mt-0.5">Total transfers</p>
        </div>
        <div class="bg-white rounded-xl border border-yellow-100 shadow-sm p-4">
            <p class="text-xs font-semibold text-yellow-600 uppercase tracking-wide">Pending</p>
            <p class="text-2xl font-bold text-yellow-600 mt-1"><?php echo $summaryRows->pending ?? 0; ?></p>
            <p class="text-xs text-gray-400 mt-0.5">Awaiting approval</p>
        </div>
        <div class="bg-white rounded-xl border border-green-100 shadow-sm p-4">
            <p class="text-xs font-semibold text-green-600 uppercase tracking-wide">Approved</p>
            <p class="text-2xl font-bold text-green-600 mt-1"><?php echo $summaryRows->approved ?? 0; ?></p>
            <p class="text-xs text-gray-400 mt-0.5">৳<?php echo number_format($summaryRows->approved_amount ?? 0, 0); ?> moved</p>
        </div>
        <div class="bg-white rounded-xl border border-red-100 shadow-sm p-4">
            <p class="text-xs font-semibold text-red-500 uppercase tracking-wide">Rejected</p>
            <p class="text-2xl font-bold text-red-500 mt-1"><?php echo $summaryRows->rejected ?? 0; ?></p>
            <p class="text-xs text-gray-400 mt-0.5">This period</p>
        </div>
    </div>

    <!-- ── Filter Bar ───────────────────────────────────── -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-5">
        <form method="GET" id="filterForm" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-7 gap-3 items-end">

            <div class="lg:col-span-2">
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Search</label>
                <input type="text" name="keyword" value="<?php echo htmlspecialchars($filters['keyword']); ?>"
                       placeholder="Ref # or description..."
                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">From Account</label>
                <select name="from_account_id"
                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                    <option value="">All</option>
                    <?php foreach ($accounts as $a): ?>
                    <option value="<?php echo $a->id; ?>" <?php echo $filters['from_account_id'] == $a->id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($a->bank_name . ' — ' . $a->account_name); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">To Account</label>
                <select name="to_account_id"
                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                    <option value="">All</option>
                    <?php foreach ($accounts as $a): ?>
                    <option value="<?php echo $a->id; ?>" <?php echo $filters['to_account_id'] == $a->id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($a->bank_name . ' — ' . $a->account_name); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Status</label>
                <select name="status"
                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                    <option value="">All</option>
                    <?php foreach (['pending','approved','rejected','cancelled'] as $s): ?>
                    <option value="<?php echo $s; ?>" <?php echo $filters['status'] === $s ? 'selected' : ''; ?>>
                        <?php echo ucfirst($s); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-span-2 sm:col-span-1 lg:col-span-1">
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Date From</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>"
                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
            </div>

            <div>
                <div class="flex gap-2">
                    <button type="submit"
                            class="flex-1 bg-primary-600 text-white py-2 rounded-lg text-sm font-semibold hover:bg-primary-700">
                        <i class="fas fa-search"></i>
                    </button>
                    <a href="transfer.php"
                       class="px-3 py-2 border border-gray-200 text-gray-500 rounded-lg text-sm hover:bg-gray-50">
                        <i class="fas fa-times"></i>
                    </a>
                </div>
            </div>

        </form>
    </div>

    <!-- ── Transfer Table ───────────────────────────────── -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">

        <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
            <span class="text-sm font-semibold text-gray-700">
                <?php echo number_format($totalCount); ?> transfer(s) found
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Ref #</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Date</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">From Account</th>
                        <th class="text-center px-4 py-3 text-xs font-semibold text-gray-400 uppercase tracking-wide">→</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">To Account</th>
                        <th class="text-right px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Amount (৳)</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Description</th>
                        <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Initiated By</th>
                        <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php if (empty($transfers)): ?>
                    <tr>
                        <td colspan="10" class="px-4 py-12 text-center text-gray-400">
                            <i class="fas fa-exchange-alt text-3xl mb-2 block opacity-30"></i>
                            No transfers found. Create a new transfer using the button above.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($transfers as $trn):
                        $statusColors = [
                            'pending'   => 'bg-yellow-100 text-yellow-700',
                            'approved'  => 'bg-green-100 text-green-700',
                            'rejected'  => 'bg-red-100 text-red-700',
                            'cancelled' => 'bg-gray-100 text-gray-500',
                        ];
                        $sc = $statusColors[$trn->status] ?? 'bg-gray-100 text-gray-500';
                    ?>
                    <tr class="hover:bg-gray-50 transition-colors">

                        <td class="px-4 py-3">
                            <span class="font-mono text-xs text-primary-600 font-semibold">
                                <?php echo htmlspecialchars($trn->transfer_number); ?>
                            </span>
                        </td>

                        <td class="px-4 py-3 text-gray-700 whitespace-nowrap text-xs">
                            <?php echo date('d M Y', strtotime($trn->transfer_date)); ?>
                        </td>

                        <td class="px-4 py-3">
                            <p class="font-medium text-gray-800 text-xs"><?php echo htmlspecialchars($trn->from_bank_name); ?></p>
                            <p class="text-gray-400 text-xs"><?php echo htmlspecialchars($trn->from_account_name); ?></p>
                        </td>

                        <td class="px-4 py-3 text-center">
                            <span class="text-primary-400 font-bold">→</span>
                        </td>

                        <td class="px-4 py-3">
                            <p class="font-medium text-gray-800 text-xs"><?php echo htmlspecialchars($trn->to_bank_name); ?></p>
                            <p class="text-gray-400 text-xs"><?php echo htmlspecialchars($trn->to_account_name); ?></p>
                        </td>

                        <td class="px-4 py-3 text-right font-mono font-semibold text-gray-800">
                            <?php echo number_format((float)$trn->amount, 2); ?>
                        </td>

                        <td class="px-4 py-3 text-gray-500 text-xs max-w-xs truncate">
                            <?php echo htmlspecialchars($trn->description ?? '—'); ?>
                        </td>

                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-0.5 text-xs rounded-full <?php echo $sc; ?>">
                                <?php echo ucfirst($trn->status); ?>
                            </span>
                        </td>

                        <td class="px-4 py-3 text-xs text-gray-500">
                            <?php echo htmlspecialchars($trn->initiated_by_name ?? '—'); ?>
                        </td>

                        <td class="px-4 py-3">
                            <div class="flex items-center justify-center gap-1">
                                <!-- View detail -->
                                <button onclick="viewTransfer(<?php echo $trn->id; ?>)"
                                        title="View Details"
                                        class="p-1.5 text-gray-400 hover:text-primary-600 hover:bg-primary-50 rounded transition-colors">
                                    <i class="fas fa-eye text-xs"></i>
                                </button>

                                <?php if ($isApprover && $trn->status === 'pending'): ?>
                                <!-- Approve -->
                                <button onclick="approveTransfer(<?php echo $trn->id; ?>, '<?php echo htmlspecialchars($trn->transfer_number); ?>')"
                                        title="Approve"
                                        class="p-1.5 text-gray-400 hover:text-green-600 hover:bg-green-50 rounded transition-colors">
                                    <i class="fas fa-check text-xs"></i>
                                </button>
                                <!-- Reject -->
                                <button onclick="rejectTransfer(<?php echo $trn->id; ?>)"
                                        title="Reject"
                                        class="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded transition-colors">
                                    <i class="fas fa-times text-xs"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>

                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="px-5 py-3 border-t border-gray-100 flex items-center justify-between text-sm">
            <span class="text-gray-500 text-xs">
                Showing <?php echo (($page-1)*$perPage)+1; ?>–<?php echo min($page*$perPage, $totalCount); ?>
                of <?php echo number_format($totalCount); ?>
            </span>
            <div class="flex gap-1">
                <?php for ($p = max(1,$page-2); $p <= min($totalPages,$page+2); $p++): ?>
                <a href="?<?php echo http_build_query(array_merge(array_filter($filters), ['page'=>$p])); ?>"
                   class="px-3 py-1 rounded-lg text-xs <?php echo $p === $page ? 'bg-primary-600 text-white' : 'border border-gray-200 text-gray-600 hover:bg-gray-50'; ?>">
                    <?php echo $p; ?>
                </a>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     CREATE TRANSFER MODAL
═══════════════════════════════════════════════════════════ -->
<div id="createModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
            <h2 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-exchange-alt text-primary-600"></i>
                New Internal Transfer
            </h2>
            <button onclick="closeCreateModal()" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
        </div>
        <form method="POST" class="p-6" onsubmit="return validateTransferForm()">
            <input type="hidden" name="action" value="create_transfer">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">

            <!-- Date + Amount row -->
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wide">
                        Transfer Date <span class="text-red-500">*</span>
                    </label>
                    <input type="date" name="transfer_date" value="<?php echo date('Y-m-d'); ?>" required
                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wide">
                        Amount (৳) <span class="text-red-500">*</span>
                    </label>
                    <input type="number" name="amount" step="0.01" min="0.01" required
                           placeholder="0.00" id="transferAmount"
                           oninput="updateTransferPreview()"
                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                </div>
            </div>

            <!-- From Account -->
            <div class="mb-4">
                <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wide">
                    From Account (Source — will be Debited) <span class="text-red-500">*</span>
                </label>
                <select name="from_account_id" id="fromAccount" required onchange="updateTransferPreview(); checkSameAccount()"
                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                    <option value="">— Select source account —</option>
                    <?php foreach ($accounts as $a):
                        $bal = (float)$a->opening_balance + (float)$a->module_balance;
                    ?>
                    <option value="<?php echo $a->id; ?>"
                            data-name="<?php echo htmlspecialchars($a->bank_name . ' — ' . $a->account_name); ?>"
                            data-balance="<?php echo $bal; ?>">
                        <?php echo htmlspecialchars($a->bank_name . ' — ' . $a->account_name); ?>
                        (Bal: ৳<?php echo number_format($bal, 0); ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <p id="fromBalanceHint" class="text-xs text-gray-400 mt-1 hidden">
                    Available: <span id="fromBalanceAmt" class="font-semibold text-gray-700"></span>
                </p>
            </div>

            <!-- To Account -->
            <div class="mb-4">
                <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wide">
                    To Account (Destination — will be Credited) <span class="text-red-500">*</span>
                </label>
                <select name="to_account_id" id="toAccount" required onchange="updateTransferPreview(); checkSameAccount()"
                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                    <option value="">— Select destination account —</option>
                    <?php foreach ($accounts as $a): ?>
                    <option value="<?php echo $a->id; ?>"
                            data-name="<?php echo htmlspecialchars($a->bank_name . ' — ' . $a->account_name); ?>">
                        <?php echo htmlspecialchars($a->bank_name . ' — ' . $a->account_name); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <p id="sameAccountError" class="text-xs text-red-500 mt-1 hidden">
                    ⚠ Source and destination must be different accounts.
                </p>
            </div>

            <!-- Live Transfer Preview -->
            <div id="transferPreview" class="hidden mb-4 bg-blue-50 border border-blue-200 rounded-xl p-4">
                <p class="text-xs font-semibold text-blue-600 uppercase tracking-wide mb-2">Transfer Preview</p>
                <div class="flex items-center gap-3">
                    <div class="flex-1 text-center">
                        <p class="text-xs text-gray-500">From</p>
                        <p class="font-semibold text-red-600 text-sm" id="previewFrom">—</p>
                        <p class="text-xs text-red-500 mt-0.5">DEBIT ↑</p>
                    </div>
                    <div class="text-center px-2">
                        <div class="w-10 h-10 rounded-full bg-primary-100 flex items-center justify-center mx-auto">
                            <i class="fas fa-arrow-right text-primary-600"></i>
                        </div>
                        <p class="font-bold text-primary-700 text-sm mt-1" id="previewAmount">৳0</p>
                    </div>
                    <div class="flex-1 text-center">
                        <p class="text-xs text-gray-500">To</p>
                        <p class="font-semibold text-green-600 text-sm" id="previewTo">—</p>
                        <p class="text-xs text-green-500 mt-0.5">CREDIT ↓</p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wide">Reference Number</label>
                    <input type="text" name="reference_number" placeholder="e.g. NEFT/RTGS ref"
                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wide">Description</label>
                    <input type="text" name="description" placeholder="Purpose of transfer"
                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                </div>
            </div>

            <div class="mb-5">
                <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wide">Notes</label>
                <textarea name="notes" rows="2" placeholder="Optional notes..."
                          class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none resize-none"></textarea>
            </div>

            <div class="flex gap-3">
                <button type="submit"
                        class="flex-1 bg-primary-600 text-white py-2.5 rounded-xl text-sm font-semibold hover:bg-primary-700 transition-colors">
                    <i class="fas fa-paper-plane mr-1.5"></i> Submit for Approval
                </button>
                <button type="button" onclick="closeCreateModal()"
                        class="px-5 py-2.5 border border-gray-200 text-gray-600 rounded-xl text-sm hover:bg-gray-50">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     VIEW DETAIL MODAL
═══════════════════════════════════════════════════════════ -->
<div id="viewModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
            <h2 class="text-lg font-bold text-gray-900" id="viewModalTitle">Transfer Details</h2>
            <button onclick="document.getElementById('viewModal').classList.add('hidden')"
                    class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
        </div>
        <div id="viewModalBody" class="p-6 text-sm text-gray-700">
            <div class="text-center text-gray-400 py-6"><i class="fas fa-circle-notch fa-spin text-2xl"></i></div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     REJECT MODAL
═══════════════════════════════════════════════════════════ -->
<div id="rejectModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
        <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
            <i class="fas fa-times-circle text-red-500"></i> Reject Transfer
        </h3>
        <p class="text-sm text-gray-500 mb-3">Please provide a reason for rejection:</p>
        <textarea id="rejectReason" rows="3"
                  class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-red-400 outline-none mb-4"
                  placeholder="Reason for rejection..."></textarea>
        <div class="flex gap-3">
            <button onclick="submitRejectTransfer()"
                    class="flex-1 bg-red-600 text-white py-2 rounded-lg text-sm font-semibold hover:bg-red-700">
                Confirm Reject
            </button>
            <button onclick="document.getElementById('rejectModal').classList.add('hidden')"
                    class="flex-1 border border-gray-200 text-gray-600 py-2 rounded-lg text-sm hover:bg-gray-50">
                Cancel
            </button>
        </div>
    </div>
</div>

<script>
const csrfToken = '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES); ?>';

// ── Create Modal ───────────────────────────────────────────
function openCreateModal()  { document.getElementById('createModal').classList.remove('hidden'); }
function closeCreateModal() { document.getElementById('createModal').classList.add('hidden'); }

function updateTransferPreview() {
    const fromSel  = document.getElementById('fromAccount');
    const toSel    = document.getElementById('toAccount');
    const amountEl = document.getElementById('transferAmount');
    const preview  = document.getElementById('transferPreview');

    const fromOpt = fromSel.selectedOptions[0];
    const toOpt   = toSel.selectedOptions[0];
    const amount  = parseFloat(amountEl.value) || 0;

    if (fromOpt?.value && toOpt?.value && amount > 0) {
        preview.classList.remove('hidden');
        document.getElementById('previewFrom').textContent   = fromOpt.dataset.name || fromOpt.text;
        document.getElementById('previewTo').textContent     = toOpt.dataset.name   || toOpt.text;
        document.getElementById('previewAmount').textContent = '৳' + amount.toLocaleString('en-IN', {minimumFractionDigits:2});
    } else {
        preview.classList.add('hidden');
    }

    // Show source balance hint
    const balHint = document.getElementById('fromBalanceHint');
    if (fromOpt?.value && fromOpt.dataset.balance !== undefined) {
        const bal = parseFloat(fromOpt.dataset.balance);
        document.getElementById('fromBalanceAmt').textContent = '৳' + bal.toLocaleString('en-IN', {minimumFractionDigits:2});
        balHint.classList.remove('hidden');
        balHint.querySelector('span').className = bal < amount && amount > 0
            ? 'font-semibold text-red-600' : 'font-semibold text-gray-700';
    } else {
        balHint.classList.add('hidden');
    }
}

function checkSameAccount() {
    const from = document.getElementById('fromAccount').value;
    const to   = document.getElementById('toAccount').value;
    const err  = document.getElementById('sameAccountError');
    if (from && to && from === to) {
        err.classList.remove('hidden');
    } else {
        err.classList.add('hidden');
    }
}

function validateTransferForm() {
    const from = document.getElementById('fromAccount').value;
    const to   = document.getElementById('toAccount').value;
    if (from && to && from === to) {
        alert('Source and destination accounts must be different.');
        return false;
    }
    return true;
}

// ── Approve ────────────────────────────────────────────────
function approveTransfer(id, ref) {
    if (!confirm('Approve transfer ' + ref + '?\n\nThis will create debit/credit entries in both accounts immediately.')) return;
    fetch('ajax_handler.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=approve_transfer&id=' + id + '&csrf=' + csrfToken
    }).then(r => r.json()).then(d => {
        if (d.success) { showToast(d.message, 'success'); setTimeout(() => location.reload(), 800); }
        else showToast(d.message || 'Error', 'error');
    });
}

// ── Reject ─────────────────────────────────────────────────
let rejectTrnId = null;
function rejectTransfer(id) {
    rejectTrnId = id;
    document.getElementById('rejectReason').value = '';
    document.getElementById('rejectModal').classList.remove('hidden');
}
function submitRejectTransfer() {
    const reason = document.getElementById('rejectReason').value.trim();
    if (!reason) { alert('Please enter a rejection reason.'); return; }
    fetch('ajax_handler.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=reject_transfer&id=' + rejectTrnId + '&reason=' + encodeURIComponent(reason) + '&csrf=' + csrfToken
    }).then(r => r.json()).then(d => {
        document.getElementById('rejectModal').classList.add('hidden');
        if (d.success) { showToast('Transfer rejected.', 'success'); setTimeout(() => location.reload(), 800); }
        else showToast(d.message || 'Error', 'error');
    });
}

// ── View Detail ────────────────────────────────────────────
const allTransfers = <?php echo json_encode(array_map(function($t) {
    return [
        'id'                => $t->id,
        'transfer_number'   => $t->transfer_number,
        'transfer_date'     => $t->transfer_date,
        'from_bank_name'    => $t->from_bank_name,
        'from_account_name' => $t->from_account_name,
        'from_account_number'=> $t->from_account_number,
        'to_bank_name'      => $t->to_bank_name,
        'to_account_name'   => $t->to_account_name,
        'to_account_number' => $t->to_account_number,
        'amount'            => $t->amount,
        'reference_number'  => $t->reference_number,
        'description'       => $t->description,
        'notes'             => $t->notes,
        'status'            => $t->status,
        'rejection_reason'  => $t->rejection_reason,
        'initiated_by_name' => $t->initiated_by_name,
        'approved_by_name'  => $t->approved_by_name,
        'approved_at'       => $t->approved_at,
        'from_tx_id'        => $t->from_tx_id,
        'to_tx_id'          => $t->to_tx_id,
        'created_at'        => $t->created_at,
    ];
}, $transfers)); ?>;

function viewTransfer(id) {
    const t = allTransfers.find(x => x.id == id);
    if (!t) return;

    const statusColor = {pending:'text-yellow-700 bg-yellow-100', approved:'text-green-700 bg-green-100',
                         rejected:'text-red-700 bg-red-100', cancelled:'text-gray-600 bg-gray-100'};
    const sc = statusColor[t.status] || 'text-gray-600 bg-gray-100';

    const amount = parseFloat(t.amount).toLocaleString('en-IN', {minimumFractionDigits:2});

    document.getElementById('viewModalTitle').textContent = 'Transfer ' + t.transfer_number;
    document.getElementById('viewModalBody').innerHTML = `
        <div class="space-y-4">
            <div class="flex items-center gap-3 p-3 rounded-xl bg-blue-50">
                <div class="flex-1 text-center">
                    <p class="text-xs text-gray-500">From</p>
                    <p class="font-bold text-red-600 text-sm">${t.from_bank_name}</p>
                    <p class="text-xs text-gray-500">${t.from_account_name}</p>
                    <p class="font-mono text-xs text-gray-400">${t.from_account_number}</p>
                    <p class="text-xs text-red-500 font-semibold mt-1">DEBIT</p>
                </div>
                <div class="text-center">
                    <div class="w-12 h-12 rounded-full bg-primary-100 flex items-center justify-center mx-auto">
                        <i class="fas fa-arrow-right text-primary-600 text-lg"></i>
                    </div>
                    <p class="font-bold text-primary-700 mt-1">৳${amount}</p>
                </div>
                <div class="flex-1 text-center">
                    <p class="text-xs text-gray-500">To</p>
                    <p class="font-bold text-green-600 text-sm">${t.to_bank_name}</p>
                    <p class="text-xs text-gray-500">${t.to_account_name}</p>
                    <p class="font-mono text-xs text-gray-400">${t.to_account_number}</p>
                    <p class="text-xs text-green-500 font-semibold mt-1">CREDIT</p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 text-xs">
                <div><span class="text-gray-400">Date</span><p class="font-semibold">${t.transfer_date}</p></div>
                <div><span class="text-gray-400">Status</span>
                    <p><span class="px-2 py-0.5 rounded-full text-xs font-semibold ${sc}">${t.status.charAt(0).toUpperCase()+t.status.slice(1)}</span></p>
                </div>
                ${t.reference_number ? `<div><span class="text-gray-400">Reference</span><p class="font-mono font-semibold">${t.reference_number}</p></div>` : ''}
                ${t.description ? `<div class="col-span-2"><span class="text-gray-400">Description</span><p class="font-semibold">${t.description}</p></div>` : ''}
                ${t.notes ? `<div class="col-span-2 bg-yellow-50 p-2 rounded"><span class="text-gray-400">Notes</span><p>${t.notes}</p></div>` : ''}
                <div><span class="text-gray-400">Initiated By</span><p class="font-semibold">${t.initiated_by_name || '—'}</p></div>
                ${t.approved_by_name ? `<div><span class="text-gray-400">Approved/Rejected By</span><p class="font-semibold">${t.approved_by_name}</p></div>` : ''}
                ${t.rejection_reason ? `<div class="col-span-2 bg-red-50 p-2 rounded"><span class="text-gray-400">Rejection Reason</span><p class="text-red-700 font-semibold">${t.rejection_reason}</p></div>` : ''}
                ${t.from_tx_id ? `<div class="col-span-2 text-xs text-gray-400">Linked TX: Debit #${t.from_tx_id} · Credit #${t.to_tx_id}</div>` : ''}
            </div>
        </div>
    `;
    document.getElementById('viewModal').classList.remove('hidden');
}

// ── Toast ──────────────────────────────────────────────────
function showToast(msg, type = 'success') {
    const t = document.createElement('div');
    t.className = 'fixed bottom-5 right-5 z-[9999] px-4 py-3 rounded-xl shadow-lg text-sm font-medium ' +
        (type === 'success' ? 'bg-green-600 text-white' : 'bg-red-600 text-white');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3000);
}
</script>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>