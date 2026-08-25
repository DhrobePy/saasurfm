<?php
/**
 * bank/index.php
 * Bank Transaction Module - Main Dashboard
 * Role-based: Superadmin gets full KPI + AI suggestions
 *             Users get their own transaction history
 */

require_once dirname(__DIR__) . '/core/init.php';
require_once dirname(__DIR__) . '/bank/BankManager.php';

restrict_access(); // All logged-in users

$currentUser  = getCurrentUser();
$userId       = $currentUser['id'];
$userRole     = $currentUser['role'];
$userName     = $currentUser['display_name'];

$bankManager  = new BankManager();

$adminRoles   = ['Superadmin', 'admin'];
$accountRoles = ['Superadmin', 'admin', 'Bank Transaction Initiator', 'Bank Transaction Approver'];
$isAdmin      = in_array($userRole, $adminRoles);
$isAccounts   = in_array($userRole, $accountRoles);

// ── Filters ────────────────────────────────────────────────
$filters = [
    'keyword'             => $_GET['keyword']             ?? '',
    'bank_tx_account_id'     => $_GET['bank_tx_account_id']     ?? '',
    'entry_type'          => $_GET['entry_type']          ?? '',
    'status'              => $_GET['status']              ?? '',
    'date_from'           => $_GET['date_from']           ?? '',
    'date_to'             => $_GET['date_to']             ?? '',
    'transaction_type_id' => $_GET['transaction_type_id'] ?? '',
];

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;
$filters['limit']  = $limit;
$filters['offset'] = ($page - 1) * $limit;

$transactions  = $bankManager->getTransactions($filters, $userId, $userRole);
$totalTx       = $bankManager->countTransactions($filters, $userId, $userRole);
$totalPages    = ceil($totalTx / $limit);

$bankAccounts  = $bankManager->getBankAccounts(true);
$txTypes       = $bankManager->getTransactionTypes(true);

// Admin-only data
$kpis = null;
if ($isAdmin) {
    $kpis = $bankManager->getDashboardKPIs();
}

$pageTitle = 'Bank Transactions';
require_once dirname(__DIR__) . '/templates/header.php';
?>

<div class="w-full px-4 sm:px-6 lg:px-8 py-6">

    <?php echo display_message(); ?>

    <!-- ═══════════════════════════════════════════════════════
         SUPERADMIN / ADMIN : Smart Dashboard
    ════════════════════════════════════════════════════════ -->
    <?php if ($isAdmin && $kpis): ?>
    <div class="mb-8">

        <!-- Page Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6 gap-3">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-university text-primary-600"></i> Bank Management
                </h1>
                <p class="text-sm text-gray-500 mt-0.5">Live overview of all bank accounts & transactions</p>
            </div>
            <div class="flex gap-2 flex-wrap">
                <a href="<?php echo url('bank/manage_accounts.php'); ?>"
                   class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                    <i class="fas fa-piggy-bank mr-1.5"></i> Manage Accounts
                </a>
                <a href="<?php echo url('bank/manage_types.php'); ?>"
                   class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                    <i class="fas fa-tags mr-1.5"></i> Transaction Types
                </a>
                <a href="<?php echo url('bank/create_transaction.php'); ?>"
                   class="inline-flex items-center px-3 py-2 bg-primary-600 rounded-lg text-sm font-medium text-white hover:bg-primary-700">
                    <i class="fas fa-plus mr-1.5"></i> New Transaction
                </a>
            </div>
        </div>

        <!-- KPI Cards Row 1 -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">This Month Inflow</p>
                        <p class="text-2xl font-bold text-green-600 mt-1">৳<?php echo number_format($kpis['monthly']->total_inflow ?? 0, 0); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center">
                        <i class="fas fa-arrow-down text-green-600 text-xl"></i>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">This Month Outflow</p>
                        <p class="text-2xl font-bold text-red-600 mt-1">৳<?php echo number_format($kpis['monthly']->total_outflow ?? 0, 0); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-red-100 rounded-xl flex items-center justify-center">
                        <i class="fas fa-arrow-up text-red-600 text-xl"></i>
                    </div>
                </div>
            </div>
            <?php $netFlow = $kpis['monthly']->net_flow ?? 0; ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Net Flow (Month)</p>
                        <p class="text-2xl font-bold <?php echo $netFlow >= 0 ? 'text-primary-600' : 'text-orange-600'; ?> mt-1">
                            <?php echo ($netFlow >= 0 ? '+' : '') . '৳' . number_format($netFlow, 0); ?>
                        </p>
                    </div>
                    <div class="w-12 h-12 bg-primary-100 rounded-xl flex items-center justify-center">
                        <i class="fas fa-chart-line text-primary-600 text-xl"></i>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Pending Approvals</p>
                        <p class="text-2xl font-bold text-yellow-600 mt-1"><?php echo $kpis['monthly']->pending_count ?? 0; ?></p>
                    </div>
                    <div class="w-12 h-12 bg-yellow-100 rounded-xl flex items-center justify-center">
                        <i class="fas fa-clock text-yellow-600 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Account Cards -->
        <div class="mb-5">
            <h2 class="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-3">Bank Accounts — Live Balances</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                <?php foreach ($kpis['accounts'] as $acc):
                    $bal = (float)$acc->opening_balance + (float)$acc->module_balance;
                ?>
                <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 hover:shadow-md transition-shadow cursor-pointer"
                     onclick="window.location='<?php echo url('bank/statement.php'); ?>?account_id=<?php echo $acc->id; ?>'">
                    <div class="flex items-start justify-between mb-2">
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-gray-800 text-sm truncate"><?php echo htmlspecialchars($acc->bank_name); ?></p>
                            <p class="text-xs text-gray-500 truncate"><?php echo htmlspecialchars($acc->account_name); ?></p>
                        </div>
                        <span class="ml-2 px-1.5 py-0.5 text-xs rounded <?php echo $acc->status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'; ?>">
                            <?php echo ucfirst($acc->status); ?>
                        </span>
                    </div>
                    <p class="text-xs text-gray-400 font-mono mb-2"><?php echo htmlspecialchars($acc->account_number); ?></p>
                    <p class="text-xl font-bold <?php echo $bal >= 0 ? 'text-gray-900' : 'text-red-600'; ?>">
                        ৳<?php echo number_format($bal, 2); ?>
                    </p>
                    <div class="flex items-center justify-between mt-1">
                        <p class="text-xs text-gray-400"><?php echo $acc->account_type; ?></p>
                        <p class="text-xs text-primary-500"><i class="fas fa-external-link-alt"></i> Statement</p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Chart + AI Section -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-5">

            <!-- Flow Trend Chart -->
            <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                <h3 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-chart-bar text-primary-500"></i> 6-Month Cash Flow Trend
                </h3>
                <canvas id="flowChart" height="200"></canvas>
            </div>

            <!-- AI Suggestions -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex flex-col">
                <h3 class="font-semibold text-gray-800 mb-3 flex items-center gap-2">
                    <i class="fas fa-robot text-purple-500"></i> AI Advisor
                    <span class="ml-auto px-1.5 py-0.5 bg-purple-100 text-purple-700 text-xs rounded-full">Smart</span>
                </h3>
                <div id="aiSuggestions" class="flex-1 text-sm text-gray-600 space-y-2">
                    <div class="flex items-center gap-2 text-purple-600">
                        <i class="fas fa-circle-notch fa-spin"></i>
                        <span>Analysing your banking data...</span>
                    </div>
                </div>
                <button onclick="loadAISuggestions()"
                    class="mt-4 w-full text-xs py-2 border border-purple-200 text-purple-600 rounded-lg hover:bg-purple-50 transition-colors">
                    <i class="fas fa-sync-alt mr-1"></i> Refresh Analysis
                </button>
            </div>
        </div>

    </div><!-- end admin dashboard -->
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════
         USER (non-admin) Page Header
    ════════════════════════════════════════════════════════ -->
    <?php if (!$isAdmin): ?>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6 gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-receipt text-primary-600"></i> My Bank Transactions
            </h1>
            <p class="text-sm text-gray-500 mt-0.5">Your submitted transaction entries</p>
        </div>
        <a href="<?php echo url('bank/create_transaction.php'); ?>"
           class="inline-flex items-center px-4 py-2 bg-primary-600 rounded-lg text-sm font-medium text-white hover:bg-primary-700">
            <i class="fas fa-plus mr-2"></i> New Transaction
        </a>
    </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════
         Filter Bar
    ════════════════════════════════════════════════════════ -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-5">
        <form id="filterForm" method="GET" action="">
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3">

                <div class="col-span-2">
                    <input type="text" name="keyword" id="keyword"
                           value="<?php echo htmlspecialchars($filters['keyword']); ?>"
                           placeholder="🔍  Search ref, cheque, payee, description..."
                           class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none">
                </div>

                <div>
                    <select name="bank_tx_account_id" id="bank_tx_account_id"
                            class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary-500 outline-none">
                        <option value="">All Accounts</option>
                        <?php foreach ($bankAccounts as $ba): ?>
                        <option value="<?php echo $ba->id; ?>" <?php echo $filters['bank_tx_account_id'] == $ba->id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($ba->bank_name . ' - ' . $ba->account_name); ?>
                        </option>
                        <?php endforeach; ?>
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
                    <select name="status"
                            class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary-500 outline-none">
                        <option value="">All Status</option>
                        <option value="pending"   <?php echo $filters['status'] === 'pending'   ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved"  <?php echo $filters['status'] === 'approved'  ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected"  <?php echo $filters['status'] === 'rejected'  ? 'selected' : ''; ?>>Rejected</option>
                        <option value="cancelled" <?php echo $filters['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        <?php if ($isAccounts): ?>
                        <option value="unposted"  <?php echo $filters['status'] === 'unposted'  ? 'selected' : ''; ?>>🚫 Unposted</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>"
                           class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary-500 outline-none"
                           placeholder="Date From">
                </div>

                <div>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>"
                           class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary-500 outline-none"
                           placeholder="Date To">
                </div>

                <div>
                    <select name="transaction_type_id"
                            class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary-500 outline-none">
                        <option value="">All Types</option>
                        <?php foreach ($txTypes as $tt): ?>
                        <option value="<?php echo $tt->id; ?>" <?php echo $filters['transaction_type_id'] == $tt->id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($tt->name); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex gap-2">
                    <button type="submit"
                            class="flex-1 bg-primary-600 text-white text-sm rounded-lg px-3 py-2 hover:bg-primary-700 transition-colors">
                        <i class="fas fa-search mr-1"></i> Filter
                    </button>
                    <a href="<?php echo url('bank/index.php'); ?>"
                       class="px-3 py-2 border border-gray-200 text-gray-600 text-sm rounded-lg hover:bg-gray-50 transition-colors">
                        <i class="fas fa-times"></i>
                    </a>
                </div>

                <?php if ($isAccounts): ?>
                <div>
                    <a href="<?php echo url('bank/ajax_handler.php?action=export_excel&' . http_build_query(array_filter($filters))); ?>"
                       class="flex items-center justify-center w-full px-3 py-2 border border-green-200 text-green-700 text-sm rounded-lg hover:bg-green-50 transition-colors">
                        <i class="fas fa-file-excel mr-1.5"></i> Excel
                    </a>
                </div>
                <?php endif; ?>

            </div>
        </form>
    </div>

    <!-- ═══════════════════════════════════════════════════════
         Transaction Table
    ════════════════════════════════════════════════════════ -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">

        <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
            <span class="text-sm font-semibold text-gray-700">
                <?php echo number_format($totalTx); ?> transaction(s) found
            </span>
            <?php if ($isAccounts && ($kpis['monthly']->pending_count ?? 0) > 0): ?>
            <span class="text-xs bg-yellow-100 text-yellow-700 px-2 py-1 rounded-full">
                <i class="fas fa-clock mr-1"></i> <?php echo $kpis['monthly']->pending_count ?? 0; ?> pending review
            </span>
            <?php endif; ?>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide w-32">Ref #</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Date</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Bank Account</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Type</th>
                        <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Entry</th>
                        <th class="text-right px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Amount (৳)</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Reference / Cheque</th>
                        <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
                        <?php if ($isAccounts): ?>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">By</th>
                        <?php endif; ?>
                        <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide w-28">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="10" class="px-4 py-12 text-center text-gray-400">
                            <i class="fas fa-inbox text-3xl mb-2 block"></i>
                            No transactions found. Adjust your filters or create a new entry.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($transactions as $tx): ?>
                    <tr class="hover:bg-gray-50 transition-colors">

                        <td class="px-4 py-3">
                            <span class="font-mono text-xs text-primary-600 font-semibold"><?php echo htmlspecialchars($tx->transaction_number); ?></span>
                        </td>

                        <td class="px-4 py-3 text-gray-700 whitespace-nowrap">
                            <?php echo date('d M Y', strtotime($tx->transaction_date)); ?>
                        </td>

                        <td class="px-4 py-3">
                            <p class="font-medium text-gray-800 text-xs"><?php echo htmlspecialchars($tx->bank_name); ?></p>
                            <p class="text-gray-400 text-xs"><?php echo htmlspecialchars($tx->account_name); ?></p>
                        </td>

                        <td class="px-4 py-3">
                            <?php if ($tx->type_name): ?>
                            <span class="inline-flex items-center px-2 py-0.5 text-xs rounded-full
                                <?php echo $tx->type_nature === 'income' ? 'bg-green-100 text-green-700' :
                                           ($tx->type_nature === 'expense' ? 'bg-red-100 text-red-700' :
                                            ($tx->type_nature === 'transfer' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600')); ?>">
                                <?php echo htmlspecialchars($tx->type_name); ?>
                            </span>
                            <?php else: ?>
                            <span class="text-gray-300 text-xs">—</span>
                            <?php endif; ?>
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

                        <td class="px-4 py-3">
                            <?php if ($tx->reference_number): ?>
                            <p class="text-xs text-gray-700 font-mono"><?php echo htmlspecialchars($tx->reference_number); ?></p>
                            <?php endif; ?>
                            <?php if ($tx->cheque_number): ?>
                            <p class="text-xs text-gray-400">Chq: <?php echo htmlspecialchars($tx->cheque_number); ?></p>
                            <?php endif; ?>
                        </td>

                        <td class="px-4 py-3 text-center">
                            <?php
                            $statusMap = [
                                'pending'   => 'bg-yellow-100 text-yellow-700',
                                'approved'  => 'bg-green-100 text-green-700',
                                'rejected'  => 'bg-red-100 text-red-700',
                                'unposted'  => 'bg-gray-100 text-gray-500 line-through',
                                'cancelled' => 'bg-gray-100 text-gray-500',
                            ];
                            $cls = $statusMap[$tx->status] ?? 'bg-gray-100 text-gray-600';
                            ?>
                            <span class="px-2 py-0.5 text-xs font-semibold rounded-full <?php echo $cls; ?>">
                                <?php echo ucfirst($tx->status); ?>
                            </span>
                        </td>

                        <?php if ($isAccounts): ?>
                        <td class="px-4 py-3 text-xs text-gray-500"><?php echo htmlspecialchars($tx->created_by_name ?? '—'); ?></td>
                        <?php endif; ?>

                        <td class="px-4 py-3">
                            <div class="flex items-center justify-center gap-1.5">

                                <!-- View -->
                                <a href="<?php echo url('bank/view_transaction.php?id=' . $tx->id); ?>"
                                   title="View"
                                   class="p-1.5 text-gray-500 hover:text-primary-600 hover:bg-primary-50 rounded transition-colors">
                                    <i class="fas fa-eye text-xs"></i>
                                </a>

                                <!-- Receipt -->
                                <a href="<?php echo url('bank/receipt.php?id=' . $tx->id); ?>" target="_blank"
                                   title="Print Receipt"
                                   class="p-1.5 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded transition-colors">
                                    <i class="fas fa-print text-xs"></i>
                                </a>

                                <?php if ($isAccounts): ?>

                                <!-- Approve (pending only) -->
                                <?php if ($tx->status === 'pending'): ?>
                                <button onclick="approveTransaction(<?php echo $tx->id; ?>, '<?php echo htmlspecialchars($tx->transaction_number); ?>')"
                                        title="Approve"
                                        class="p-1.5 text-gray-500 hover:text-green-600 hover:bg-green-50 rounded transition-colors">
                                    <i class="fas fa-check text-xs"></i>
                                </button>
                                <button onclick="rejectTransaction(<?php echo $tx->id; ?>)"
                                        title="Reject"
                                        class="p-1.5 text-gray-500 hover:text-orange-600 hover:bg-orange-50 rounded transition-colors">
                                    <i class="fas fa-times text-xs"></i>
                                </button>
                                <?php endif; ?>

                                <!-- Edit -->
                                <a href="<?php echo url('bank/create_transaction.php?edit=' . $tx->id); ?>"
                                   title="Edit"
                                   class="p-1.5 text-gray-500 hover:text-yellow-600 hover:bg-yellow-50 rounded transition-colors">
                                    <i class="fas fa-pencil-alt text-xs"></i>
                                </a>

                                <!-- Unpost: Superadmin = always, others = non-approved only, not already unposted -->
                                <?php if ($tx->status !== 'unposted' && ($currentUser['role'] === 'Superadmin' || $tx->status !== 'approved')): ?>
                                <button onclick="unpostTransaction(<?php echo $tx->id; ?>, '<?php echo htmlspecialchars($tx->transaction_number); ?>', '<?php echo $tx->status; ?>')"
                                        title="<?php echo $tx->status === 'approved' ? 'Unpost (Superadmin Override)' : 'Mark as Unposted'; ?>"
                                        class="p-1.5 rounded transition-colors <?php echo $tx->status === 'approved' ? 'text-orange-500 hover:text-orange-700 hover:bg-orange-50' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-100'; ?>">
                                    <i class="fas fa-ban text-xs"></i>
                                </button>
                                <?php endif; ?>

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
        <div class="px-5 py-4 border-t border-gray-100 flex items-center justify-between">
            <p class="text-sm text-gray-500">
                Showing <?php echo number_format(($page-1)*$limit + 1); ?> – <?php echo number_format(min($page*$limit, $totalTx)); ?> of <?php echo number_format($totalTx); ?>
            </p>
            <div class="flex gap-1">
                <?php
                $qBase = array_filter($filters, fn($v) => $v !== '' && $v !== null);
                unset($qBase['limit'], $qBase['offset']);
                for ($p = max(1, $page-2); $p <= min($totalPages, $page+2); $p++):
                    $qBase['page'] = $p;
                ?>
                <a href="?<?php echo http_build_query($qBase); ?>"
                   class="px-3 py-1 text-sm rounded <?php echo $p === $page ? 'bg-primary-600 text-white' : 'border border-gray-200 text-gray-600 hover:bg-gray-50'; ?>">
                    <?php echo $p; ?>
                </a>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- end table card -->

</div><!-- end container -->

<!-- ═══════════════════════════════════════════════════════
     Modals
════════════════════════════════════════════════════════ -->

<!-- Reject Modal -->
<div id="rejectModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-40">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 p-6">
        <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
            <i class="fas fa-times-circle text-red-500"></i> Reject Transaction
        </h3>
        <input type="hidden" id="rejectTxId">
        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Reason for rejection <span class="text-red-500">*</span></label>
            <textarea id="rejectReason" rows="3"
                      class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-red-400 outline-none"
                      placeholder="Enter rejection reason..."></textarea>
        </div>
        <div class="flex gap-3">
            <button onclick="submitReject()"
                    class="flex-1 bg-red-600 text-white text-sm py-2 rounded-lg hover:bg-red-700 transition-colors">
                Confirm Reject
            </button>
            <button onclick="closeRejectModal()"
                    class="flex-1 border border-gray-200 text-gray-600 text-sm py-2 rounded-lg hover:bg-gray-50 transition-colors">
                Cancel
            </button>
        </div>
    </div>
</div>

<script>
// ── Chart.js flow trend ────────────────────────────────────
<?php if ($isAdmin && !empty($kpis['trend'])): ?>
const trendLabels  = <?php echo json_encode(array_map(fn($t) => $t->month_label, $kpis['trend'])); ?>;
const trendInflow  = <?php echo json_encode(array_map(fn($t) => (float)$t->inflow, $kpis['trend'])); ?>;
const trendOutflow = <?php echo json_encode(array_map(fn($t) => (float)$t->outflow, $kpis['trend'])); ?>;

const ctx = document.getElementById('flowChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: trendLabels,
        datasets: [
            { label: 'Inflow (Credit)',  data: trendInflow,  backgroundColor: 'rgba(16,185,129,0.7)',  borderRadius: 4 },
            { label: 'Outflow (Debit)', data: trendOutflow, backgroundColor: 'rgba(239,68,68,0.7)',   borderRadius: 4 }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'top' } },
        scales: {
            y: { ticks: { callback: v => '৳' + v.toLocaleString() } }
        }
    }
});
<?php endif; ?>

// ── AI Suggestions ─────────────────────────────────────────
<?php if ($isAdmin): ?>
const aiContext = <?php echo json_encode($bankManager->getAIContext()); ?>;

async function loadAISuggestions() {
    const el = document.getElementById('aiSuggestions');
    el.innerHTML = '<div class="flex items-center gap-2 text-purple-600"><i class="fas fa-circle-notch fa-spin"></i><span>Analysing data...</span></div>';

    const prompt = `You are a financial advisor for Ujjal Flour Mills, a flour manufacturing company in Bangladesh.
Analyse this banking data and provide 4-5 concise, actionable insights:

MONTHLY PERFORMANCE:
- Inflow: ৳${aiContext.monthly_inflow.toLocaleString()}
- Outflow: ৳${aiContext.monthly_outflow.toLocaleString()}
- Net Flow: ৳${aiContext.monthly_net.toLocaleString()}
- Pending approvals: ${aiContext.pending_count}

BANK ACCOUNTS:
${aiContext.accounts.map(a => `- ${a.bank} | ${a.account}: ৳${a.balance.toLocaleString()} (${a.type})`).join('\n')}

6-MONTH TREND:
${aiContext.trend_6m.map(t => `- ${t.month}: In ৳${t.inflow.toLocaleString()} | Out ৳${t.outflow.toLocaleString()} | Net ৳${t.net.toLocaleString()}`).join('\n')}

Provide:
1. Cash flow health assessment
2. Liquidity risk flags (if any)
3. Which bank account has idle funds that could earn interest
4. Investment or FDR suggestion based on available balance
5. One operational recommendation

Be concise, use bullet points, mention BDT amounts. Focus on practical Bangladeshi banking context.`;

    try {
        const response = await fetch('ai_proxy.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ prompt: prompt })
        });
        const data = await response.json();
        if (data.error) throw new Error(data.error);
        const text = data.content && data.content[0] ? data.content[0].text : 'Unable to generate suggestions.';

        // Render markdown-ish response
        const formatted = text
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/^(\d+\.) (.+)$/gm, '<div class="flex gap-2 mt-2"><span class="font-bold text-purple-600 min-w-4">$1</span><span>$2</span></div>')
            .replace(/^- (.+)$/gm, '<div class="flex gap-2 mt-1"><span class="text-purple-400 mt-0.5">•</span><span>$1</span></div>')
            .replace(/\n\n/g, '<br>');

        el.innerHTML = '<div class="text-xs leading-relaxed">' + formatted + '</div>';
    } catch (e) {
        el.innerHTML = '<p class="text-red-400 text-xs">⚠️ AI suggestions unavailable: ' + e.message + '</p>';
    }
}

// Auto-load on page ready
document.addEventListener('DOMContentLoaded', loadAISuggestions);
<?php endif; ?>

// ── CSRF token (PHP -> JS, safe quote handling) ──────────
const csrfToken = '<?php echo htmlspecialchars($_SESSION["csrf_token"] ?? "", ENT_QUOTES); ?>';

// ── Approve Transaction ────────────────────────────────────
function approveTransaction(id, ref) {
    if (!confirm('Approve transaction ' + ref + '?')) return;
    fetch('ajax_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=approve&id=' + id + '&csrf=' + csrfToken
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) { showToast('Transaction approved!', 'success'); setTimeout(() => location.reload(), 800); }
        else showToast(d.message || 'Error', 'error');
    });
}

// ── Reject Transaction ─────────────────────────────────────
let rejectTxIdGlobal = null;
function rejectTransaction(id) {
    rejectTxIdGlobal = id;
    document.getElementById('rejectReason').value = '';
    document.getElementById('rejectModal').classList.remove('hidden');
}
function closeRejectModal() {
    document.getElementById('rejectModal').classList.add('hidden');
}
function submitReject() {
    const reason = document.getElementById('rejectReason').value.trim();
    if (!reason) { alert('Please enter a rejection reason.'); return; }
    fetch('ajax_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=reject&id=' + rejectTxIdGlobal + '&reason=' + encodeURIComponent(reason) + '&csrf=' + csrfToken
    })
    .then(r => r.json())
    .then(d => {
        closeRejectModal();
        if (d.success) { showToast('Transaction rejected.', 'success'); setTimeout(() => location.reload(), 800); }
        else showToast(d.message || 'Error', 'error');
    });
}

// ── Unpost Transaction ────────────────────────────────────
function unpostTransaction(id, ref, status) {
    const msg = status === 'approved'
        ? '⚠️ SUPERADMIN OVERRIDE\n\nTransaction ' + ref + ' is currently APPROVED.\nMarking it as Unposted will remove it from balance calculations and all standard views.\n\nThis action is audit logged and a Telegram notification will be sent.\n\nProceed?'
        : 'Mark transaction ' + ref + ' as Unposted?\n\nIt will be hidden from the default view but can be found by filtering on "Unposted" status.\n\nProceed?';
    if (!confirm(msg)) return;
    fetch('ajax_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=unpost&id=' + id + '&csrf=' + csrfToken
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) { showToast('Transaction marked as unposted.', 'success'); setTimeout(() => location.reload(), 800); }
        else showToast(d.message || 'Error', 'error');
    });
}

// ── Toast ──────────────────────────────────────────────────
function showToast(msg, type = 'success') {
    const t = document.createElement('div');
    t.className = 'fixed bottom-5 right-5 z-[9999] px-4 py-3 rounded-xl shadow-lg text-sm font-medium transition-all ' +
        (type === 'success' ? 'bg-green-600 text-white' : 'bg-red-600 text-white');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3000);
}
</script>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>