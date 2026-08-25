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
// 'bank Transaction initiator' (odd casing, but that's the real role string
// used consistently everywhere else — manage_user.php, transfer.php,
// create_transaction.php). This file alone had it Title-Cased, which never
// matched a real user's session role, silently hiding this page's own-account
// views/actions for that role.
$accountRoles = ['Superadmin', 'admin', 'bank Transaction initiator', 'Bank Transaction Approver'];
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
$kpis             = null;
$reconMap         = [];
$totalPositive    = 0.0;
$totalNegative    = 0.0;
$negativeAccts    = 0;
$unreconciledCount = 0;
if ($isAdmin) {
    $kpis     = $bankManager->getDashboardKPIs();
    $reconMap = $bankManager->getLastReconciliations();
    foreach ($kpis['accounts'] as $acc) {
        $bal = (float)$acc->opening_balance + (float)$acc->module_balance;
        if ($bal >= 0) { $totalPositive += $bal; }
        else           { $totalNegative += abs($bal); $negativeAccts++; }
        if (!isset($reconMap[(int)$acc->id])) $unreconciledCount++;
    }
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

        <!-- ── Row 1: Fund Overview ──────────────────────────── -->
        <?php $netPos = $totalPositive - $totalNegative; ?>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">

            <!-- Total Available Funds -->
            <div class="bg-gradient-to-br from-emerald-50 to-green-50 rounded-xl border border-emerald-100 p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold text-emerald-700 uppercase tracking-wide">Available Funds</p>
                        <p class="text-2xl font-bold text-emerald-700 mt-1 leading-none">
                            ৳<?php echo number_format($totalPositive / 1000000, 2); ?><span class="text-base font-medium text-emerald-500">M</span>
                        </p>
                        <p class="text-xs text-emerald-600 mt-1"><?php echo count($kpis['accounts']) - $negativeAccts; ?> credit account<?php echo (count($kpis['accounts']) - $negativeAccts) !== 1 ? 's' : ''; ?></p>
                    </div>
                    <div class="w-12 h-12 bg-emerald-100 rounded-xl flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-wallet text-emerald-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <!-- Loan / CC Exposure -->
            <div class="<?php echo $negativeAccts > 0 ? 'bg-gradient-to-br from-red-50 to-rose-50 border-red-100' : 'bg-white border-gray-100'; ?> rounded-xl border p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold <?php echo $negativeAccts > 0 ? 'text-red-700' : 'text-gray-500'; ?> uppercase tracking-wide">Loan / CC Exposure</p>
                        <p class="text-2xl font-bold <?php echo $negativeAccts > 0 ? 'text-red-600' : 'text-gray-300'; ?> mt-1 leading-none">
                            <?php if ($totalNegative > 0): ?>
                            ৳<?php echo number_format($totalNegative / 1000000, 2); ?><span class="text-base font-medium text-red-400">M</span>
                            <?php else: ?>
                            <span class="text-lg">None</span>
                            <?php endif; ?>
                        </p>
                        <p class="text-xs <?php echo $negativeAccts > 0 ? 'text-red-500' : 'text-gray-400'; ?> mt-1">
                            <?php echo $negativeAccts; ?> overdrawn account<?php echo $negativeAccts !== 1 ? 's' : ''; ?>
                        </p>
                    </div>
                    <div class="w-12 h-12 <?php echo $negativeAccts > 0 ? 'bg-red-100' : 'bg-gray-50'; ?> rounded-xl flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-credit-card <?php echo $negativeAccts > 0 ? 'text-red-500' : 'text-gray-300'; ?> text-xl"></i>
                    </div>
                </div>
            </div>

            <!-- Net Position -->
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Net Position</p>
                        <p class="text-2xl font-bold <?php echo $netPos >= 0 ? 'text-indigo-700' : 'text-orange-600'; ?> mt-1 leading-none">
                            <?php echo $netPos >= 0 ? '+' : '−'; ?>৳<?php echo number_format(abs($netPos) / 1000000, 2); ?><span class="text-base font-medium opacity-60">M</span>
                        </p>
                        <p class="text-xs text-gray-400 mt-1">across <?php echo count($kpis['accounts']); ?> active accounts</p>
                    </div>
                    <div class="w-12 h-12 bg-indigo-50 rounded-xl flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-balance-scale text-indigo-500 text-xl"></i>
                    </div>
                </div>
            </div>

            <!-- Unreconciled -->
            <div class="<?php echo $unreconciledCount > 0 ? 'bg-gradient-to-br from-amber-50 to-yellow-50 border-amber-100' : 'bg-white border-gray-100'; ?> rounded-xl border shadow-sm p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold <?php echo $unreconciledCount > 0 ? 'text-amber-700' : 'text-gray-500'; ?> uppercase tracking-wide">Unreconciled</p>
                        <p class="text-2xl font-bold <?php echo $unreconciledCount > 0 ? 'text-amber-600' : 'text-green-500'; ?> mt-1 leading-none">
                            <?php echo $unreconciledCount > 0 ? $unreconciledCount : '✓ All'; ?>
                        </p>
                        <p class="text-xs <?php echo $unreconciledCount > 0 ? 'text-amber-600' : 'text-green-500'; ?> mt-1">
                            <?php echo $unreconciledCount > 0 ? 'account' . ($unreconciledCount !== 1 ? 's' : '') . ' pending reconciliation' : 'accounts reconciled'; ?>
                        </p>
                    </div>
                    <div class="w-12 h-12 <?php echo $unreconciledCount > 0 ? 'bg-amber-100' : 'bg-green-50'; ?> rounded-xl flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-clipboard-check <?php echo $unreconciledCount > 0 ? 'text-amber-500' : 'text-green-500'; ?> text-xl"></i>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── Row 2: Monthly KPIs ────────────────────────────── -->
        <?php $netFlow = $kpis['monthly']->net_flow ?? 0; ?>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-gray-400 uppercase tracking-wide">Inflow This Month</p>
                        <p class="text-xl font-bold text-green-600 mt-1">৳<?php echo number_format(($kpis['monthly']->total_inflow ?? 0) / 1000, 1); ?>K</p>
                    </div>
                    <div class="w-10 h-10 bg-green-50 rounded-lg flex items-center justify-center">
                        <i class="fas fa-arrow-down text-green-500"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-gray-400 uppercase tracking-wide">Outflow This Month</p>
                        <p class="text-xl font-bold text-red-500 mt-1">৳<?php echo number_format(($kpis['monthly']->total_outflow ?? 0) / 1000, 1); ?>K</p>
                    </div>
                    <div class="w-10 h-10 bg-red-50 rounded-lg flex items-center justify-center">
                        <i class="fas fa-arrow-up text-red-400"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-gray-400 uppercase tracking-wide">Net Flow (Month)</p>
                        <p class="text-xl font-bold <?php echo $netFlow >= 0 ? 'text-indigo-600' : 'text-orange-500'; ?> mt-1">
                            <?php echo ($netFlow >= 0 ? '+' : '') . '৳' . number_format($netFlow / 1000, 1); ?>K
                        </p>
                    </div>
                    <div class="w-10 h-10 bg-indigo-50 rounded-lg flex items-center justify-center">
                        <i class="fas fa-chart-line text-indigo-400"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-gray-400 uppercase tracking-wide">Pending Approvals</p>
                        <p class="text-xl font-bold text-yellow-600 mt-1"><?php echo $kpis['monthly']->pending_count ?? 0; ?></p>
                    </div>
                    <?php if (($kpis['monthly']->pending_count ?? 0) > 0): ?>
                    <a href="?status=pending" class="w-10 h-10 bg-yellow-50 rounded-lg flex items-center justify-center hover:bg-yellow-100 transition-colors" title="View pending">
                        <i class="fas fa-clock text-yellow-500"></i>
                    </a>
                    <?php else: ?>
                    <div class="w-10 h-10 bg-gray-50 rounded-lg flex items-center justify-center">
                        <i class="fas fa-check text-gray-300"></i>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- ── Account Cards with Reconciliation ──────────────── -->
        <div class="mb-5">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-semibold text-gray-600 uppercase tracking-wide">
                    <i class="fas fa-university mr-1 text-gray-400"></i> Bank Accounts — Live Balances
                </h2>
                <span class="text-xs text-gray-400"><?php echo count($kpis['accounts']); ?> accounts</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                <?php foreach ($kpis['accounts'] as $acc):
                    $bal   = (float)$acc->opening_balance + (float)$acc->module_balance;
                    $recon = $reconMap[(int)$acc->id] ?? null;
                    $isNeg = $bal < 0;
                    // Border color: red for overdrawn, amber for unreconciled, gray otherwise
                    $cardBorder = $isNeg ? 'border-red-200' : ($recon ? 'border-gray-100' : 'border-amber-200');
                ?>
                <div class="bg-white rounded-xl border <?php echo $cardBorder; ?> shadow-sm flex flex-col hover:shadow-md transition-shadow">

                    <!-- Top: clickable to statement -->
                    <div class="p-4 cursor-pointer flex-1"
                         onclick="window.location='<?php echo url('bank/statement.php'); ?>?account_id=<?php echo $acc->id; ?>'">

                        <!-- Header row -->
                        <div class="flex items-start justify-between mb-2">
                            <div class="flex-1 min-w-0 pr-2">
                                <p class="font-bold text-gray-800 text-sm truncate leading-tight"><?php echo htmlspecialchars($acc->bank_name); ?></p>
                                <p class="text-xs text-gray-500 truncate mt-0.5"><?php echo htmlspecialchars($acc->account_name); ?></p>
                            </div>
                            <span class="flex-shrink-0 px-1.5 py-0.5 text-xs rounded-full font-medium
                                <?php echo $acc->status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'; ?>">
                                <?php echo ucfirst($acc->status); ?>
                            </span>
                        </div>

                        <!-- Account type + number -->
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-xs text-gray-400 bg-gray-50 px-1.5 py-0.5 rounded font-medium">
                                <?php echo htmlspecialchars($acc->account_type ?? 'Current'); ?>
                            </span>
                            <span class="text-xs text-gray-300 font-mono"><?php echo htmlspecialchars($acc->account_number); ?></span>
                        </div>

                        <!-- Balance -->
                        <div class="mb-1">
                            <p class="text-xs text-gray-400 mb-0.5">Current Balance</p>
                            <p class="text-2xl font-extrabold <?php echo $isNeg ? 'text-red-600' : 'text-gray-900'; ?> leading-none">
                                <?php echo $isNeg ? '−' : ''; ?>৳<?php echo number_format(abs($bal), 2); ?>
                            </p>
                            <?php if ($isNeg): ?>
                            <span class="inline-flex items-center gap-1 text-xs text-red-500 mt-1 font-medium">
                                <i class="fas fa-exclamation-triangle text-xs"></i> Overdrawn / Loan
                            </span>
                            <?php endif; ?>
                        </div>

                        <!-- Statement link -->
                        <p class="text-xs text-primary-400 mt-2 flex items-center gap-1">
                            <i class="fas fa-external-link-alt text-xs"></i> View Statement
                        </p>
                    </div>

                    <!-- Reconciliation section -->
                    <div class="border-t <?php echo $recon ? 'border-gray-100' : 'border-amber-100'; ?> px-4 py-3 bg-gray-50 rounded-b-xl">
                        <?php if ($recon): ?>
                        <!-- Reconciled state -->
                        <div class="flex items-center justify-between mb-2">
                            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-green-700">
                                <i class="fas fa-check-circle text-green-500"></i> Reconciled
                            </span>
                            <span class="text-xs text-gray-400"><?php echo date('d M Y', strtotime($recon->reconciled_at)); ?></span>
                        </div>
                        <div class="grid grid-cols-3 gap-1 text-center mb-2">
                            <div class="bg-white rounded-lg py-1.5 border border-gray-100">
                                <p class="text-xs text-gray-400 leading-none">Credit</p>
                                <p class="text-xs font-bold text-green-600 mt-0.5">
                                    ৳<?php echo number_format($recon->total_credit / 1000, 1); ?>K
                                </p>
                            </div>
                            <div class="bg-white rounded-lg py-1.5 border border-gray-100">
                                <p class="text-xs text-gray-400 leading-none">Debit</p>
                                <p class="text-xs font-bold text-red-500 mt-0.5">
                                    ৳<?php echo number_format($recon->total_debit / 1000, 1); ?>K
                                </p>
                            </div>
                            <div class="bg-white rounded-lg py-1.5 border border-gray-100">
                                <p class="text-xs text-gray-400 leading-none">Balance</p>
                                <p class="text-xs font-bold <?php echo (float)$recon->final_balance < 0 ? 'text-red-600' : 'text-gray-700'; ?> mt-0.5">
                                    ৳<?php echo number_format($recon->final_balance / 1000, 1); ?>K
                                </p>
                            </div>
                        </div>
                        <?php if ($recon->reconciled_by_name): ?>
                        <p class="text-xs text-gray-300 mb-2">by <?php echo htmlspecialchars($recon->reconciled_by_name); ?></p>
                        <?php endif; ?>
                        <?php else: ?>
                        <!-- Not reconciled -->
                        <div class="flex items-center gap-1.5 mb-2">
                            <i class="fas fa-exclamation-circle text-amber-400 text-xs"></i>
                            <span class="text-xs text-amber-600 font-medium">Not yet reconciled</span>
                        </div>
                        <?php endif; ?>

                        <!-- Mark Reconciled button (admin only) -->
                        <?php if ($isAdmin): ?>
                        <button onclick="markAccountReconciled(<?php echo $acc->id; ?>, '<?php echo htmlspecialchars($acc->bank_name . ' – ' . $acc->account_name, ENT_QUOTES); ?>')"
                                class="w-full text-xs py-1.5 rounded-lg border transition-colors font-medium
                                    <?php echo $recon
                                        ? 'border-gray-200 text-gray-400 hover:border-indigo-200 hover:text-indigo-600 hover:bg-indigo-50'
                                        : 'border-indigo-200 text-indigo-600 bg-indigo-50 hover:bg-indigo-100'; ?>">
                            <i class="fas fa-balance-scale mr-1"></i>
                            <?php echo $recon ? 'Re-reconcile' : 'Mark Reconciled'; ?>
                        </button>
                        <?php endif; ?>
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
            <?php if ($userRole === 'Bank Transaction Approver'): ?>
                <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-tasks text-primary-600"></i> All Bank Transactions
                </h1>
                <p class="text-sm text-gray-500 mt-0.5">Review, approve or reject pending transaction entries</p>
            <?php else: ?>
                <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-receipt text-primary-600"></i> My Bank Transactions
                </h1>
                <p class="text-sm text-gray-500 mt-0.5">Your submitted transaction entries</p>
            <?php endif; ?>
        </div>
        <?php if ($userRole !== 'Bank Transaction Approver'): ?>
        <a href="<?php echo url('bank/create_transaction.php'); ?>"
           class="inline-flex items-center px-4 py-2 bg-primary-600 rounded-lg text-sm font-medium text-white hover:bg-primary-700">
            <i class="fas fa-plus mr-2"></i> New Transaction
        </a>
        <?php endif; ?>
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
            <?php if ($isAccounts && isset($kpis['monthly']) && ($kpis['monthly']->pending_count ?? 0) > 0): ?>
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
                            <p class="font-medium text-gray-800 text-xs"><?php echo htmlspecialchars($tx->bank_name ?? '—'); ?></p>
                            <p class="text-gray-400 text-xs"><?php echo htmlspecialchars($tx->account_name ?? '—'); ?></p>
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

                                <!-- Unpost: Superadmin + Approver = always; others = non-approved only, not already unposted -->
                                <?php if ($tx->status !== 'unposted' && (in_array($currentUser['role'], ['Superadmin', 'Bank Transaction Approver']) || $tx->status !== 'approved')): ?>
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

<!-- Reconcile Modal -->
<div id="reconcileModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-40">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm mx-4 p-6">
        <h3 class="text-lg font-bold text-gray-900 mb-1 flex items-center gap-2">
            <i class="fas fa-balance-scale text-indigo-500"></i> Mark as Reconciled
        </h3>
        <p id="reconcileAcctName" class="text-sm text-indigo-600 font-medium mb-1"></p>
        <p class="text-xs text-gray-400 mb-4">Snapshots the current approved total credit, total debit and closing balance at this moment.</p>
        <input type="hidden" id="reconcileAcctId">
        <div class="mb-4">
            <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wide">Notes <span class="text-gray-300 font-normal">(optional)</span></label>
            <textarea id="reconcileNotes" rows="2"
                      class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-300 outline-none resize-none"
                      placeholder="e.g. Reconciled against bank statement dated 18 Jun 2026..."></textarea>
        </div>
        <div class="flex gap-3">
            <button onclick="submitReconcile()"
                    class="flex-1 bg-indigo-600 text-white text-sm py-2.5 rounded-lg hover:bg-indigo-700 transition-colors font-medium">
                <i class="fas fa-check mr-1.5"></i> Confirm Reconciliation
            </button>
            <button onclick="closeReconcileModal()"
                    class="px-4 border border-gray-200 text-gray-500 text-sm py-2.5 rounded-lg hover:bg-gray-50 transition-colors">
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

// ── Markdown renderer (order: headings → bold → numbered → bullets → br) ──
function renderAIMarkdown(text) {
    return text
        .replace(/^###\s+(.+)$/gm,
            '<h4 class="text-xs font-bold text-purple-800 uppercase tracking-wide mt-3 mb-1 pb-0.5 border-b border-purple-100">$1</h4>')
        .replace(/^##\s+(.+)$/gm,
            '<h3 class="text-xs font-semibold text-purple-900 mt-3 mb-1">$1</h3>')
        .replace(/\*\*(.*?)\*\*/g,
            '<strong class="font-semibold text-gray-800">$1</strong>')
        .replace(/^(\d+)\.\s+(.+)$/gm,
            '<div class="flex gap-2 mt-2 items-start">' +
              '<span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-purple-600 text-white text-xs font-bold flex-shrink-0 mt-0.5">$1</span>' +
              '<span>$2</span></div>')
        .replace(/^[-•]\s+(.+)$/gm,
            '<div class="flex gap-2 mt-1 items-start">' +
              '<span class="text-purple-400 flex-shrink-0 mt-0.5">▸</span>' +
              '<span>$1</span></div>')
        .replace(/\n{2,}/g, '<div class="mt-1"></div>')
        .replace(/\n/g, ' ');
}

// ── Local rule-based analysis — works with ZERO API dependency ──
function generateLocalAnalysis(ctx) {
    const fmt  = n => '৳' + Math.abs(Number(n)).toLocaleString('en-US', { maximumFractionDigits: 0 });
    const sign = n => n >= 0 ? '+' : '-';
    const sections = [];

    // 1. Cash Flow Health
    const net    = ctx.monthly_net;
    const trend  = ctx.trend_6m || [];
    const avgNet = trend.length ? trend.reduce((s, t) => s + t.net, 0) / trend.length : 0;
    const vsAvg  = net >= avgNet ? 'above' : 'below';
    const healthIcon = net >= 0 ? '✅' : '⚠️';

    let s1 = `${healthIcon} Net this month: **${sign(net)}${fmt(net)}** — ${vsAvg} the 6-month average of **${sign(avgNet)}${fmt(avgNet)}**.`;
    if (ctx.pending_count > 0)
        s1 += `\n- **${ctx.pending_count} pending transaction(s)** awaiting approval — closing balance will shift once processed.`;
    sections.push('## 1. Cash Flow Health\n' + s1);

    // 2. Liquidity Risks
    const totalBal   = (ctx.accounts || []).reduce((s, a) => s + a.balance, 0);
    const monthOut   = ctx.monthly_outflow || 1;
    const coverage   = (totalBal / monthOut).toFixed(1);
    const negMonths  = trend.slice(-3).filter(t => t.net < 0).length;

    let s2;
    if (negMonths >= 2)
        s2 = `⚠️ **${negMonths} of the last 3 months were net-negative.** Cash erosion trend detected — review recurring outflows immediately.`;
    else if (totalBal < monthOut)
        s2 = `⚠️ Total balance **${fmt(totalBal)}** is below one month's outflow (**${fmt(monthOut)}**). Accelerate receivables or delay non-critical payments.`;
    else if (parseFloat(coverage) < 2)
        s2 = `⚠️ **${coverage}-month coverage** — short runway. Maintain inflow pipeline to avoid a shortfall next month.`;
    else
        s2 = `No immediate risk. Total balance **${fmt(totalBal)}** covers **${coverage} months** of current outflow (**${fmt(monthOut)}/month**).`;
    sections.push('## 2. Liquidity Risks\n' + s2);

    // 3. Idle Funds
    const sorted = [...(ctx.accounts || [])].sort((a, b) => b.balance - a.balance);
    const top    = sorted[0];
    let s3;
    if (top && top.balance > 200000) {
        const idle = Math.floor(top.balance * 0.6);
        s3 = `**${top.bank}** holds the highest balance at **${fmt(top.balance)}**.\n- Est. idle above operating buffer: **~${fmt(idle)}** (60%).\n- Move excess to an interest-bearing account or short-term FDR.`;
    } else {
        s3 = `All account balances are modest. No single account shows significantly idle funds at this time.`;
    }
    sections.push('## 3. Idle Funds\n' + s3);

    // 4. FDR / Investment Recommendation
    let s4;
    if (top && top.balance > 500000) {
        const fdrAmt  = Math.floor(top.balance * 0.5);
        const monthly = Math.floor(fdrAmt * 0.075 / 12); // ~7.5% p.a. BD rate
        s4 = `Place **${fmt(fdrAmt)}** from **${top.bank}** in a **3-month FDR** at ~7.5% p.a. (Bangladesh Bank reference rate).\n- Estimated monthly return: **${fmt(monthly)}**.\n- Retain **${fmt(top.balance - fdrAmt)}** in current account for day-to-day operations.`;
    } else {
        s4 = `Current balances are below the practical FDR threshold (**৳5,00,000**). Build reserves first. Consider a **STD (Special Term Deposit)** once the threshold is reached.`;
    }
    sections.push('## 4. FDR / Investment Recommendation\n' + s4);

    // 5. This Week's Action
    const actions = [];
    if (ctx.pending_count > 0)
        actions.push(`**Approve ${ctx.pending_count} pending transaction(s)** to finalise this month's closing balance`);
    if (net < 0)
        actions.push(`**Investigate outflow** — this month's outflow (${fmt(ctx.monthly_outflow)}) exceeds inflow; review top expense categories`);
    if (top && top.balance > 500000)
        actions.push(`**Call ${top.bank}** to get current FDR rates for **${fmt(Math.floor(top.balance * 0.5))}**`);
    if (negMonths >= 2)
        actions.push(`**Schedule CFO review** — consecutive negative-net months require strategic attention`);
    if (actions.length === 0)
        actions.push(`**Maintain discipline** — cash flow is healthy. Schedule a quarterly bank review to optimise returns on idle funds`);

    sections.push('## 5. This Week\'s Action\n' + actions.map(a => '- ' + a).join('\n'));

    return sections.join('\n\n');
}

// ── Build the AI prompt (used only when API key is available) ──
function buildAIPrompt(ctx) {
    const fmt = n => '৳' + Number(n).toLocaleString('en-US', { maximumFractionDigits: 0 });
    return `You are a financial advisor for Ujjal Flour Mills, Bangladesh. Today: ${ctx.today}

=== THIS MONTH (${ctx.today.substring(0,7)}) ===
Inflow: ${fmt(ctx.monthly_inflow)} | Outflow: ${fmt(ctx.monthly_outflow)} | Net: ${fmt(ctx.monthly_net)} | ${ctx.monthly_tx} txns | ${ctx.pending_count} pending

=== ALL-TIME ===
Total In: ${fmt(ctx.alltime_inflow)} | Total Out: ${fmt(ctx.alltime_outflow)} | Net: ${fmt(ctx.alltime_inflow - ctx.alltime_outflow)}

=== ACCOUNTS ===
${(ctx.accounts||[]).map(a => `${a.bank} — ${a.account}: ${fmt(a.balance)} [${a.type}]`).join('\n')}

=== 6-MONTH TREND ===
${(ctx.trend_6m||[]).map(t => `${t.month}: In ${fmt(t.inflow)} Out ${fmt(t.outflow)} Net ${fmt(t.net)}`).join('\n')}

Reply with exactly 5 sections using ## headings and **bold** for key figures:
## 1. Cash Flow Health
## 2. Liquidity Risks
## 3. Idle Funds
## 4. FDR / Investment Recommendation
## 5. This Week's Action

Max 3 lines per section. Use ৳ amounts. Bangladesh banking context. Be direct.`;
}

async function loadAISuggestions() {
    const el = document.getElementById('aiSuggestions');

    // Skeleton while loading
    el.innerHTML = `
        <div class="animate-pulse space-y-2 py-1">
            <div class="h-2.5 bg-purple-100 rounded-full w-3/4"></div>
            <div class="h-2.5 bg-purple-100 rounded-full w-full"></div>
            <div class="h-2.5 bg-purple-100 rounded-full w-5/6"></div>
            <div class="h-2.5 bg-purple-50 rounded-full w-2/3 mt-3"></div>
            <div class="h-2.5 bg-purple-50 rounded-full w-full"></div>
            <div class="h-2.5 bg-purple-50 rounded-full w-4/5"></div>
            <div class="h-2.5 bg-purple-50 rounded-full w-3/5 mt-3"></div>
            <div class="h-2.5 bg-purple-50 rounded-full w-full"></div>
        </div>`;

    let text   = null;
    let source = 'local';

    // Try API (Groq/Gemini) — silently fall back to local if unavailable
    try {
        const res  = await fetch('ai_proxy.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ prompt: buildAIPrompt(aiContext) })
        });
        const data = await res.json();
        if (!data.error && !data.local_fallback && data.content && data.content[0]) {
            text   = data.content[0].text;
            source = 'ai';
        }
        // data.local_fallback = true  → no API key, use local (no user-visible error)
        // data.error          = "..." → API failure, use local (no user-visible error)
    } catch (_) {
        // Network / parse error → fall through to local
    }

    // Always have output — local analysis as guaranteed fallback
    if (!text) {
        text   = generateLocalAnalysis(aiContext);
        source = 'local';
    }

    const now         = new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    const sourceLabel = source === 'ai'
        ? '<i class="fas fa-robot text-xs text-purple-500"></i> AI Analysis'
        : '<i class="fas fa-calculator text-xs text-indigo-400"></i> Smart Local Analysis';

    el.innerHTML =
        '<div class="text-xs leading-relaxed text-gray-700">' + renderAIMarkdown(text) + '</div>' +
        '<p class="mt-3 pt-2 border-t border-purple-50 text-gray-400 text-xs flex items-center gap-1">' +
          sourceLabel + ' &middot; ' + now +
        '</p>';
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

// ── Reconcile Account ─────────────────────────────────────
function markAccountReconciled(id, name) {
    document.getElementById('reconcileAcctId').value = id;
    document.getElementById('reconcileAcctName').textContent = name;
    document.getElementById('reconcileNotes').value = '';
    document.getElementById('reconcileModal').classList.remove('hidden');
}
function closeReconcileModal() {
    document.getElementById('reconcileModal').classList.add('hidden');
}
function submitReconcile() {
    const id    = document.getElementById('reconcileAcctId').value;
    const notes = document.getElementById('reconcileNotes').value.trim();
    fetch('ajax_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=mark_reconciled&account_id=' + encodeURIComponent(id)
            + '&notes='     + encodeURIComponent(notes)
            + '&csrf='      + csrfToken
    })
    .then(r => r.json())
    .then(d => {
        closeReconcileModal();
        if (d.success) {
            showToast('Account reconciled successfully!', 'success');
            setTimeout(() => location.reload(), 900);
        } else {
            showToast(d.message || 'Error reconciling account', 'error');
        }
    })
    .catch(() => showToast('Network error. Please retry.', 'error'));
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