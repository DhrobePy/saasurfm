<?php
/**
 * bank/central_statement.php
 * Central bank statement — unified view across all modules
 * Sources: cr_payment, expense, purchase_payment, bank_tx
 */

require_once dirname(__DIR__) . '/core/init.php';
restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

global $db;

$user_role     = $_SESSION['user_role'] ?? '';
$is_superadmin = ($user_role === 'Superadmin');
$csrf_token    = $_SESSION['csrf_token'] ?? '';

// ─── GET params ────────────────────────────────────────────────────────────
// Positive account_id = bank_accounts.id
// Negative account_id = bank_tx_accounts.id (encoded as negative to reuse one param)
$rawAccountId = (int)($_GET['account_id'] ?? 0);
$accountId    = $rawAccountId > 0 ? $rawAccountId : 0;
$btxAccountId = $rawAccountId < 0 ? abs($rawAccountId) : 0;

$source   = in_array($_GET['source'] ?? '', ['all','cr_payment','expense','purchase_payment','bank_tx'])
            ? ($_GET['source'] ?? 'all') : 'all';
$dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ?? '') ? $_GET['date_from'] : date('Y-m-d');
$dateTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to']   ?? '') ? $_GET['date_to']   : date('Y-m-d');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 50;

// ─── Account lists for dropdown ───────────────────────────────────────────
$bankAccounts = $db->query(
    "SELECT id, bank_name, account_name, account_number, initial_balance
     FROM bank_accounts WHERE status = 'active' ORDER BY bank_name, account_name"
)->results();

// Include inactive so historical transactions remain accessible
$bankTxAccounts = $db->query(
    "SELECT bta.id, bta.bank_name, bta.account_name, bta.account_number,
            bta.opening_balance, bta.status,
            COUNT(bt.id) AS tx_total,
            SUM(bt.status='approved') AS tx_approved
     FROM bank_tx_accounts bta
     LEFT JOIN bank_transactions bt ON bt.bank_tx_account_id = bta.id
     GROUP BY bta.id
     ORDER BY bta.status DESC, bta.bank_name, bta.account_name"
)->results();

$account    = null;
$btxAccount = null;

if ($accountId) {
    $account = $db->query("SELECT * FROM bank_accounts WHERE id = ?", [$accountId])->first();
    if (!$account) $accountId = 0;
}
if ($btxAccountId) {
    $btxAccount = $db->query("SELECT * FROM bank_tx_accounts WHERE id = ?", [$btxAccountId])->first();
    if (!$btxAccount) $btxAccountId = 0;
    // When filtering by bank_tx_account, force source to bank_tx only
    $source = 'bank_tx';
}

// ─── Build WHERE clauses ───────────────────────────────────────────────────
$allParams = [];
$preParams = [];

if ($accountId) {
    // Filter by bank_accounts.id — covers cr_payment, expense, purchase_payment, mapped bank_tx
    $allWhere  = 'WHERE bank_account_id = ? AND txn_date BETWEEN ? AND ?';
    $allParams = [$accountId, $dateFrom, $dateTo];
    $preWhere  = 'WHERE bank_account_id = ? AND txn_date < ?';
    $preParams = [$accountId, $dateFrom];
} elseif ($btxAccountId) {
    // Filter by bank_tx_accounts.id via source_id subquery
    // (v_bank_statement has no bank_tx_account_id column; source_id = bank_transactions.id for bank_tx rows)
    $btxSub    = "source = 'bank_tx' AND source_id IN (SELECT id FROM bank_transactions WHERE bank_tx_account_id = ?)";
    $allWhere  = "WHERE $btxSub AND txn_date BETWEEN ? AND ?";
    $allParams = [$btxAccountId, $dateFrom, $dateTo];
    $preWhere  = "WHERE $btxSub AND txn_date < ?";
    $preParams = [$btxAccountId, $dateFrom];
} else {
    $allWhere  = 'WHERE txn_date BETWEEN ? AND ?';
    $allParams = [$dateFrom, $dateTo];
    $preWhere  = 'WHERE txn_date < ?';
    $preParams = [$dateFrom];
}

// Main (paginated) WHERE — adds source filter
$mainConditions = [];
$mainParams     = [];
if ($accountId) {
    $mainConditions[] = 'bank_account_id = ?';
    $mainParams[]     = $accountId;
}
if ($btxAccountId) {
    // Subquery on source_id — avoids referencing the non-existent bank_tx_account_id VIEW column
    $mainConditions[] = "source = 'bank_tx'";
    $mainConditions[] = 'source_id IN (SELECT id FROM bank_transactions WHERE bank_tx_account_id = ?)';
    $mainParams[]     = $btxAccountId;
} elseif ($source !== 'all') {
    $mainConditions[] = 'source = ?';
    $mainParams[]     = $source;
}
$mainConditions[] = 'txn_date BETWEEN ? AND ?';
$mainParams[]     = $dateFrom;
$mainParams[]     = $dateTo;
$mainWhere        = 'WHERE ' . implode(' AND ', $mainConditions);

// ─── Period totals (all sources for the selected account dimension) ─────────
$totalsRow = $db->query(
    "SELECT COALESCE(SUM(credit_amount),0) AS tc,
            COALESCE(SUM(debit_amount), 0) AS td,
            COUNT(*) AS n
     FROM v_bank_statement $allWhere",
    $allParams
)->first();
$allCredit = (float)($totalsRow->tc ?? 0);
$allDebit  = (float)($totalsRow->td ?? 0);
$allCount  = (int)($totalsRow->n  ?? 0);

// ─── Opening balance ───────────────────────────────────────────────────────
$initialBalance = 0.0;
if ($account)    $initialBalance = (float)$account->initial_balance;
if ($btxAccount) $initialBalance = (float)$btxAccount->opening_balance;
$openingBal = 0.0;

if ($accountId || $btxAccountId) {
    $pre = $db->query(
        "SELECT COALESCE(SUM(credit_amount),0) AS tc, COALESCE(SUM(debit_amount),0) AS td
         FROM v_bank_statement $preWhere",
        $preParams
    )->first();
    $openingBal = $initialBalance + (float)($pre->tc ?? 0) - (float)($pre->td ?? 0);
}

$closingBal = $openingBal + $allCredit - $allDebit;

// ─── Filtered row count (for pagination) ──────────────────────────────────
$filteredCount = $db->query(
    "SELECT COUNT(*) AS n FROM v_bank_statement $mainWhere",
    $mainParams
)->first();
$totalRows  = (int)($filteredCount->n ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// ─── Source breakdown (all sources, same account + date range) ─────────────
$srcBreakdown = $db->query(
    "SELECT source,
            COALESCE(SUM(credit_amount),0) AS tc,
            COALESCE(SUM(debit_amount), 0) AS td,
            COUNT(*) AS n
     FROM v_bank_statement $allWhere
     GROUP BY source",
    $allParams
)->results();
$srcStats = [];
foreach ($srcBreakdown as $r) $srcStats[$r->source] = $r;

// ─── Paginated transaction rows ────────────────────────────────────────────
$pageQueryParams = array_merge($mainParams, [$perPage, $offset]);
$txRows = $db->query(
    "SELECT * FROM v_bank_statement
     $mainWhere
     ORDER BY txn_date ASC, created_at ASC
     LIMIT ? OFFSET ?",
    $pageQueryParams
)->results();

// ─── Running balance (only when one account selected and all sources shown) ──
$showBalance = ($source === 'all' || $btxAccountId);
if ($showBalance && ($accountId || $btxAccountId)) {
    // Sum of all rows in period before the current page's offset
    if ($offset > 0) {
        $preOffsetRow = $db->query(
            "SELECT COALESCE(SUM(tc),0) AS tc, COALESCE(SUM(td),0) AS td FROM (
                SELECT credit_amount AS tc, debit_amount AS td
                FROM v_bank_statement
                $allWhere
                ORDER BY txn_date ASC, created_at ASC
                LIMIT ?
             ) AS _sub",
            array_merge($allParams, [$offset])
        )->first();
        $running = $openingBal + (float)($preOffsetRow->tc ?? 0) - (float)($preOffsetRow->td ?? 0);
    } else {
        $running = $openingBal;
    }
    foreach ($txRows as $row) {
        $running     += (float)$row->credit_amount - (float)$row->debit_amount;
        $row->balance = round($running, 2);
    }
} elseif ($showBalance && !$accountId && !$btxAccountId) {
    $showBalance = false;
}

// ─── Reconciliations ──────────────────────────────────────────────────────
$reconciliations = [];
$reconDates      = [];

if ($accountId) {
    // Map bank_accounts.id → bank_tx_account_id → reconciliations
    $recons = $db->query(
        "SELECT r.id, r.reconciled_at, r.final_balance, r.notes,
                bta.account_name AS tx_account_name
         FROM bank_account_reconciliations r
         JOIN bank_account_map bam
              ON bam.bank_tx_account_id = r.bank_tx_account_id
             AND bam.bank_account_id = ?
             AND bam.confirmed = 1
         LEFT JOIN bank_tx_accounts bta ON bta.id = r.bank_tx_account_id
         ORDER BY r.reconciled_at ASC",
        [$accountId]
    )->results();
    foreach ($recons as $r) {
        $reconciliations[] = $r;
        $reconDates[substr($r->reconciled_at, 0, 10)] = true;
    }
} elseif ($btxAccountId) {
    // Direct: bank_tx_accounts.id → reconciliations
    $recons = $db->query(
        "SELECT r.id, r.reconciled_at, r.final_balance, r.notes,
                bta.account_name AS tx_account_name
         FROM bank_account_reconciliations r
         LEFT JOIN bank_tx_accounts bta ON bta.id = r.bank_tx_account_id
         WHERE r.bank_tx_account_id = ?
         ORDER BY r.reconciled_at ASC",
        [$btxAccountId]
    )->results();
    foreach ($recons as $r) {
        $reconciliations[] = $r;
        $reconDates[substr($r->reconciled_at, 0, 10)] = true;
    }
}

// ─── Source meta ──────────────────────────────────────────────────────────
$sourceMeta = [
    'cr_payment'       => ['label' => 'CR Payment',      'badge' => 'bg-emerald-100 text-emerald-800', 'icon' => 'fa-hand-holding-usd',  'color' => 'emerald'],
    'expense'          => ['label' => 'Expense',          'badge' => 'bg-red-100 text-red-800',         'icon' => 'fa-receipt',            'color' => 'red'],
    'purchase_payment' => ['label' => 'Purchase Payment', 'badge' => 'bg-orange-100 text-orange-800',   'icon' => 'fa-truck',              'color' => 'orange'],
    'bank_tx'          => ['label' => 'Bank TX',          'badge' => 'bg-blue-100 text-blue-800',       'icon' => 'fa-university',         'color' => 'blue'],
];

// ─── Build URL for row links ───────────────────────────────────────────────
function rowUrl(string $src, int $srcId): string {
    switch ($src) {
        case 'bank_tx':          return url("bank/view_transaction.php?id=$srcId");
        case 'cr_payment':       return url('cr/payment_history.php');
        case 'expense':          return url('expense/expense_voucher_list.php');
        case 'purchase_payment': return url('purchase/payments.php');
        default:                 return '#';
    }
}

// ─── Pagination URL helper ─────────────────────────────────────────────────
function pageUrl(int $p, int $rawAcctId, string $src, string $df, string $dt): string {
    $q = http_build_query(array_filter([
        'account_id' => $rawAcctId ?: null,
        'source'     => $src !== 'all' ? $src : null,
        'date_from'  => $df,
        'date_to'    => $dt,
        'page'       => $p > 1 ? $p : null,
    ], fn($v) => $v !== null));
    return url("bank/central_statement.php") . ($q ? "?$q" : '');
}

$pageTitle = 'Central Bank Statement';
require_once dirname(__DIR__) . '/templates/header.php';
?>
<style>
.recon-marker { border-left: 3px solid #7c3aed; background: #faf5ff; }
.recon-marker td:first-child { padding-left: 13px; }
.source-filter-btn { transition: all .15s; }
.source-filter-btn.active { box-shadow: 0 0 0 2px currentColor; }
.row-clickable { cursor: pointer; transition: background .12s; }
.row-clickable:hover { background-color: #f0f9ff !important; }
</style>

<div class="w-full px-4 sm:px-6 lg:px-8 py-5">

    <!-- ── Page header ─────────────────────────────────────────────────── -->
    <div class="flex items-center gap-3 mb-5">
        <a href="<?= url('bank/index.php') ?>" class="text-gray-400 hover:text-gray-600 transition-colors">
            <i class="fas fa-arrow-left text-lg"></i>
        </a>
        <div class="flex-1 min-w-0">
            <h1 class="text-xl font-bold text-gray-900 flex items-center gap-2 leading-tight">
                <i class="fas fa-landmark text-indigo-600"></i>
                Central Bank Statement
            </h1>
            <p class="text-xs text-gray-500 mt-0.5">
                Unified view — CR payments · expenses · purchase payments · bank transactions
            </p>
        </div>
        <?php if ($totalRows > 0): ?>
        <a href="?account_id=<?= $rawAccountId ?>&source=<?= urlencode($source) ?>&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>&export=csv"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium bg-gray-100 hover:bg-gray-200
                  text-gray-700 rounded-lg transition-colors">
            <i class="fas fa-download"></i> Export CSV
        </a>
        <?php endif; ?>
    </div>

    <!-- ── Filter form ─────────────────────────────────────────────────── -->
    <form method="GET" id="filterForm" class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-5">

        <!-- Row 1: Account + dates -->
        <div class="flex flex-wrap gap-3 items-end mb-3">
            <div class="flex-1 min-w-[220px]">
                <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1">Bank Account</label>
                <select name="account_id"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 bg-white">
                    <option value="0">— All Accounts —</option>
                    <?php if (!empty($bankAccounts)): ?>
                    <optgroup label="── Bank Accounts (CR / Expense / Purchase)">
                        <?php foreach ($bankAccounts as $ba): ?>
                        <option value="<?= $ba->id ?>" <?= $accountId == $ba->id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ba->bank_name . ' — ' . $ba->account_name . ' (' . $ba->account_number . ')') ?>
                        </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                    <?php if (!empty($bankTxAccounts)): ?>
                    <optgroup label="── Bank TX Accounts (Manual Entries)">
                        <?php foreach ($bankTxAccounts as $bta):
                            $inactive = ($bta->status !== 'active');
                            $approvedCount = (int)($bta->tx_approved ?? 0);
                            $totalCount    = (int)($bta->tx_total   ?? 0);
                            $label = $bta->bank_name . ' — ' . $bta->account_name . ' (' . $bta->account_number . ')';
                            if ($inactive) $label .= ' [inactive]';
                            if ($totalCount > 0) $label .= ' · ' . $approvedCount . ' approved/' . $totalCount . ' tx';
                        ?>
                        <option value="-<?= $bta->id ?>" <?= $btxAccountId == $bta->id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                </select>
            </div>

            <div class="flex items-end gap-2">
                <div>
                    <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1">From</label>
                    <input type="date" name="date_from" id="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>"
                           class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 w-36">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-1">To</label>
                    <input type="date" name="date_to" id="dateTo" value="<?= htmlspecialchars($dateTo) ?>"
                           class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 w-36">
                </div>
                <input type="hidden" name="source" id="sourceInput" value="<?= htmlspecialchars($source) ?>">
                <button type="submit"
                        class="px-5 py-2 bg-indigo-600 text-white rounded-lg text-sm font-semibold hover:bg-indigo-700 transition-colors">
                    <i class="fas fa-search mr-1"></i> View
                </button>
            </div>
        </div>

        <!-- Row 2: Date presets -->
        <div class="flex flex-wrap gap-1.5 mb-3">
            <span class="text-[11px] font-semibold text-gray-400 uppercase tracking-wide self-center mr-1">Quick:</span>
            <?php
            $today     = date('Y-m-d');
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $presets   = [
                'Today'         => [$today, $today],
                'Yesterday'     => [$yesterday, $yesterday],
                'This Week'     => [date('Y-m-d', strtotime('monday this week')), $today],
                'This Month'    => [date('Y-m-01'), $today],
                'Last Month'    => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
                'This Quarter'  => [date('Y-') . (str_pad((int)ceil(date('n')/3)*3-2, 2, '0', STR_PAD_LEFT) ) . '-01', $today],
                'This Year'     => [date('Y-01-01'), $today],
            ];
            // fix quarter start
            $qMonth = (int)ceil(date('n')/3)*3-2;
            $presets['This Quarter'][0] = date('Y-') . str_pad($qMonth, 2, '0', STR_PAD_LEFT) . '-01';
            foreach ($presets as $label => [$df, $dt]):
                $active = ($dateFrom === $df && $dateTo === $dt);
            ?>
            <button type="button"
                    onclick="setPreset('<?= $df ?>', '<?= $dt ?>')"
                    class="px-2.5 py-1 text-xs rounded-md border transition-colors
                           <?= $active ? 'bg-indigo-600 text-white border-indigo-600 font-semibold' : 'bg-gray-50 text-gray-600 border-gray-200 hover:bg-indigo-50 hover:border-indigo-300' ?>">
                <?= $label ?>
            </button>
            <?php endforeach; ?>
            <button type="button"
                    onclick="document.getElementById('dateFrom').focus()"
                    class="px-2.5 py-1 text-xs rounded-md border transition-colors bg-gray-50 text-gray-600 border-gray-200 hover:bg-indigo-50 hover:border-indigo-300">
                Custom Range
            </button>
        </div>

        <!-- Row 3: Source filter -->
        <div class="flex flex-wrap gap-2 items-center">
            <span class="text-[11px] font-semibold text-gray-400 uppercase tracking-wide mr-1">Source:</span>
            <?php
            $srcOptions = [
                'all'              => ['All Sources',      'fa-th-list',             'text-gray-700  bg-gray-100  border-gray-200'],
                'cr_payment'       => ['CR Payments',      'fa-hand-holding-usd',    'text-emerald-700 bg-emerald-50 border-emerald-200'],
                'expense'          => ['Expenses',         'fa-receipt',             'text-red-700   bg-red-50    border-red-200'],
                'purchase_payment' => ['Purchase Payments','fa-truck',               'text-orange-700 bg-orange-50 border-orange-200'],
                'bank_tx'          => ['Bank Transactions','fa-university',          'text-blue-700  bg-blue-50   border-blue-200'],
            ];
            foreach ($srcOptions as $k => [$lbl, $ico, $cls]): ?>
            <button type="button"
                    onclick="setSource('<?= $k ?>')"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg border source-filter-btn
                           <?= $cls ?> <?= $source === $k ? 'active ring-2 ring-current' : 'opacity-70 hover:opacity-100' ?>">
                <i class="fas <?= $ico ?> text-[10px]"></i>
                <?= $lbl ?>
                <?php if (isset($srcStats[$k])): ?>
                <span class="ml-0.5 text-[10px] opacity-75">(<?= number_format((int)$srcStats[$k]->n) ?>)</span>
                <?php elseif ($k === 'all'): ?>
                <span class="ml-0.5 text-[10px] opacity-75">(<?= number_format($allCount) ?>)</span>
                <?php endif; ?>
            </button>
            <?php endforeach; ?>
        </div>

    </form>

    <!-- ── KPI cards ───────────────────────────────────────────────────── -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-5">

        <?php if ($accountId || $btxAccountId): ?>
        <!-- Opening Balance -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm px-4 py-3">
            <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Opening Balance</p>
            <p class="text-base font-bold <?= $openingBal < 0 ? 'text-red-600' : 'text-gray-800' ?> leading-tight">
                <?= $openingBal < 0 ? '-' : '' ?>৳<?= number_format(abs($openingBal), 0) ?>
            </p>
            <p class="text-[10px] text-gray-400 mt-0.5"><?= date('d M Y', strtotime($dateFrom)) ?></p>
        </div>
        <?php endif; ?>

        <!-- Total Credits -->
        <div class="bg-white rounded-xl border border-emerald-200 shadow-sm px-4 py-3">
            <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Total In</p>
            <p class="text-base font-bold text-emerald-600 leading-tight">৳<?= number_format($allCredit, 0) ?></p>
            <p class="text-[10px] text-gray-400 mt-0.5"><?= number_format($allCount) ?> transactions</p>
        </div>

        <!-- Total Debits -->
        <div class="bg-white rounded-xl border border-red-200 shadow-sm px-4 py-3">
            <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Total Out</p>
            <p class="text-base font-bold text-red-600 leading-tight">৳<?= number_format($allDebit, 0) ?></p>
            <?php $net = $allCredit - $allDebit; ?>
            <p class="text-[10px] <?= $net >= 0 ? 'text-emerald-500' : 'text-red-500' ?> mt-0.5">
                Net: <?= $net < 0 ? '-' : '+' ?>৳<?= number_format(abs($net), 0) ?>
            </p>
        </div>

        <?php if ($accountId || $btxAccountId): ?>
        <!-- Closing Balance -->
        <div class="bg-white rounded-xl border border-<?= $closingBal >= 0 ? 'indigo' : 'red' ?>-200 shadow-sm px-4 py-3">
            <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Closing Balance</p>
            <p class="text-base font-bold <?= $closingBal < 0 ? 'text-red-600' : 'text-indigo-700' ?> leading-tight">
                <?= $closingBal < 0 ? '-' : '' ?>৳<?= number_format(abs($closingBal), 0) ?>
            </p>
            <p class="text-[10px] text-gray-400 mt-0.5"><?= date('d M Y', strtotime($dateTo)) ?></p>
        </div>
        <?php endif; ?>

        <!-- Reconciliation count -->
        <div class="bg-white rounded-xl border border-<?= count($reconciliations) > 0 ? 'violet' : 'gray' ?>-200 shadow-sm px-4 py-3">
            <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Reconciliations</p>
            <p class="text-base font-bold <?= count($reconciliations) > 0 ? 'text-violet-700' : 'text-gray-400' ?> leading-tight">
                <?= count($reconciliations) ?>
            </p>
            <p class="text-[10px] text-gray-400 mt-0.5">
                <?= count($reconciliations) > 0
                    ? 'Last: ' . date('d M Y', strtotime(end($reconciliations)->reconciled_at))
                    : (($accountId || $btxAccountId) ? 'None on record' : 'Select account') ?>
            </p>
        </div>

    </div>

    <!-- ── Source breakdown (compact) ─────────────────────────────────── -->
    <?php if ($allCount > 0): ?>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-5">
        <?php foreach ($sourceMeta as $sKey => $sMeta):
            $sr = $srcStats[$sKey] ?? null;
            if (!$sr) continue;
        ?>
        <button type="button"
                onclick="setSource('<?= $sKey ?>')"
                class="text-left bg-white rounded-lg border px-3 py-2.5 hover:border-indigo-300 transition-colors
                       <?= $source === $sKey ? 'border-indigo-400 ring-1 ring-indigo-300' : 'border-gray-200' ?>">
            <div class="flex items-center gap-1.5 mb-1">
                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold <?= $sMeta['badge'] ?>">
                    <i class="fas <?= $sMeta['icon'] ?> text-[9px]"></i>
                    <?= $sMeta['label'] ?>
                </span>
                <span class="text-[10px] text-gray-400"><?= number_format((int)$sr->n) ?> tx</span>
            </div>
            <div class="flex gap-3 text-xs">
                <?php if ((float)$sr->tc > 0): ?>
                <span class="text-emerald-600 font-semibold">+৳<?= number_format((float)$sr->tc, 0) ?></span>
                <?php endif; ?>
                <?php if ((float)$sr->td > 0): ?>
                <span class="text-red-600 font-semibold">-৳<?= number_format((float)$sr->td, 0) ?></span>
                <?php endif; ?>
            </div>
        </button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Reconciliation panel ────────────────────────────────────────── -->
    <?php if (!empty($reconciliations)): ?>
    <div class="bg-violet-50 border border-violet-200 rounded-xl p-4 mb-5">
        <div class="flex items-center gap-2 mb-3">
            <i class="fas fa-balance-scale text-violet-600"></i>
            <h3 class="text-sm font-bold text-violet-800">Reconciliation History</h3>
            <span class="text-xs text-violet-500"><?= count($reconciliations) ?> record(s)</span>
            <span class="text-[10px] text-violet-400 ml-auto">
                <i class="fas fa-square text-violet-400"></i> Reconciliation dates are marked in the table below
            </span>
        </div>
        <div class="flex flex-wrap gap-2">
            <?php foreach ($reconciliations as $r): ?>
            <div class="bg-white border border-violet-200 rounded-lg px-3 py-2 text-xs">
                <div class="font-semibold text-violet-800">
                    <?= date('d M Y', strtotime($r->reconciled_at)) ?>
                </div>
                <div class="text-gray-500">
                    Balance: <span class="font-medium text-gray-700">৳<?= number_format((float)$r->final_balance, 0) ?></span>
                </div>
                <?php if (!empty($r->notes)): ?>
                <div class="text-gray-400 mt-0.5 truncate max-w-[160px]" title="<?= htmlspecialchars($r->notes) ?>">
                    <?= htmlspecialchars(mb_strimwidth($r->notes, 0, 30, '…')) ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Transaction table ───────────────────────────────────────────── -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-5">

        <!-- Table header bar -->
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100 bg-gray-50">
            <div class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                <i class="fas fa-list text-gray-400"></i>
                <?php if ($source !== 'all'): ?>
                <span class="px-2 py-0.5 rounded text-xs <?= $sourceMeta[$source]['badge'] ?? 'bg-gray-100 text-gray-700' ?>">
                    <i class="fas <?= $sourceMeta[$source]['icon'] ?? 'fa-circle' ?> text-[10px] mr-0.5"></i>
                    <?= $sourceMeta[$source]['label'] ?? $source ?> only
                </span>
                <?php endif; ?>
                <span class="text-gray-500 font-normal text-xs">
                    <?= number_format($totalRows) ?> transaction<?= $totalRows !== 1 ? 's' : '' ?>
                    <?= $totalPages > 1 ? "· page $page of $totalPages" : '' ?>
                </span>
            </div>
            <?php if (!$showBalance && $source !== 'all'): ?>
            <div class="text-[11px] text-amber-600 flex items-center gap-1">
                <i class="fas fa-info-circle"></i>
                Running balance hidden when source is filtered
            </div>
            <?php endif; ?>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 text-[10px] text-gray-500 uppercase tracking-wide">
                        <th class="px-3 py-2.5 text-left w-24">Date</th>
                        <th class="px-3 py-2.5 text-left">Source</th>
                        <th class="px-3 py-2.5 text-left">Description</th>
                        <th class="px-3 py-2.5 text-left hidden md:table-cell">Reference</th>
                        <th class="px-3 py-2.5 text-left hidden lg:table-cell">Method</th>
                        <th class="px-3 py-2.5 text-right">Credit (In)</th>
                        <th class="px-3 py-2.5 text-right">Debit (Out)</th>
                        <?php if ($showBalance): ?>
                        <th class="px-3 py-2.5 text-right">Balance</th>
                        <?php endif; ?>
                        <?php if ($is_superadmin): ?>
                        <th class="px-3 py-2.5 text-center w-12">Del</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">

                    <!-- Opening balance row (account + page 1 + all sources) -->
                    <?php if ($accountId && $showBalance && $page === 1): ?>
                    <tr class="bg-gray-50 border-b border-gray-200">
                        <td class="px-3 py-2 text-[11px] text-gray-500"><?= date('d M Y', strtotime($dateFrom)) ?></td>
                        <td class="px-3 py-2" colspan="4">
                            <span class="text-xs font-semibold text-gray-500">Opening Balance</span>
                        </td>
                        <td class="px-3 py-2"></td>
                        <td class="px-3 py-2"></td>
                        <td class="px-3 py-2 text-right font-bold text-xs <?= $openingBal < 0 ? 'text-red-600' : 'text-gray-700' ?>">
                            <?= $openingBal < 0 ? '-' : '' ?>৳<?= number_format(abs($openingBal), 0) ?>
                        </td>
                        <?php if ($is_superadmin): ?><td></td><?php endif; ?>
                    </tr>
                    <?php endif; ?>

                    <?php if (empty($txRows)): ?>
                    <tr>
                        <td colspan="<?= 7 + ($showBalance ? 1 : 0) + ($is_superadmin ? 1 : 0) ?>"
                            class="px-4 py-10 text-center text-gray-400">
                            <i class="fas fa-inbox text-3xl mb-2 block text-gray-300"></i>
                            No transactions found for this period.
                            <?php if ($btxAccountId): ?>
                            <div class="mt-3 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-4 py-2 inline-block text-left max-w-sm mx-auto">
                                <strong>Bank TX account selected.</strong> Possible reasons:<br>
                                1. Transactions for this account are still <strong>pending</strong> — only approved entries appear here. Go to the
                                <a href="<?= url('bank/index.php') ?>" class="underline">Bank module</a> and approve them.<br>
                                2. No transactions exist for this account in the selected date range.<br>
                                <?php if ($is_superadmin): ?>
                                3. The VIEW may be missing the <code>bank_tx_account_id</code> column —
                                run <a href="<?= url('bank/migrate_add_btx_col.php') ?>" class="underline font-semibold">migrate_add_btx_col.php</a>
                                or <a href="<?= url('bank/diag_btx.php') ?>" class="underline">diag_btx.php</a> to diagnose.
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($txRows as $row):
                        $src    = $row->source ?? 'bank_tx';
                        $meta   = $sourceMeta[$src] ?? ['label' => $src, 'badge' => 'bg-gray-100 text-gray-700', 'icon' => 'fa-circle', 'color' => 'gray'];
                        $txDate = substr($row->txn_date ?? '', 0, 10);
                        $isRecon = isset($reconDates[$txDate]);
                        $srcId  = (int)($row->source_id ?? 0);
                        $href   = rowUrl($src, $srcId);
                    ?>
                    <?php if ($isRecon): ?>
                    <tr>
                        <td colspan="<?= 7 + ($showBalance ? 1 : 0) + ($is_superadmin ? 1 : 0) ?>"
                            class="px-3 py-1 bg-violet-100 border-t border-violet-200">
                            <span class="text-[10px] font-semibold text-violet-700 flex items-center gap-1">
                                <i class="fas fa-balance-scale text-[9px]"></i>
                                Reconciliation date — <?= date('d M Y', strtotime($txDate)) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr class="row-clickable <?= $isRecon ? 'recon-marker' : 'hover:bg-gray-50' ?>"
                        onclick="rowNavigate(event, '<?= htmlspecialchars($href) ?>')">
                        <td class="px-3 py-2.5 text-[11px] text-gray-500 whitespace-nowrap">
                            <?= date('d M Y', strtotime($txDate)) ?>
                        </td>
                        <td class="px-3 py-2.5 whitespace-nowrap">
                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold <?= $meta['badge'] ?>">
                                <i class="fas <?= $meta['icon'] ?> text-[9px]"></i>
                                <?= $meta['label'] ?>
                            </span>
                        </td>
                        <td class="px-3 py-2.5 text-gray-700 max-w-[200px] truncate"
                            title="<?= htmlspecialchars($row->description ?? '') ?>">
                            <?= htmlspecialchars(mb_strimwidth($row->description ?? '—', 0, 60, '…')) ?>
                        </td>
                        <td class="px-3 py-2.5 text-[11px] text-blue-600 font-mono hidden md:table-cell whitespace-nowrap">
                            <?= htmlspecialchars($row->reference ?? '—') ?>
                        </td>
                        <td class="px-3 py-2.5 text-[11px] text-gray-500 hidden lg:table-cell whitespace-nowrap">
                            <?= htmlspecialchars($row->payment_method ?? '—') ?>
                        </td>
                        <td class="px-3 py-2.5 text-right font-medium text-emerald-600 whitespace-nowrap">
                            <?= (float)$row->credit_amount > 0 ? '৳' . number_format((float)$row->credit_amount, 0) : '—' ?>
                        </td>
                        <td class="px-3 py-2.5 text-right font-medium text-red-600 whitespace-nowrap">
                            <?= (float)$row->debit_amount > 0 ? '৳' . number_format((float)$row->debit_amount, 0) : '—' ?>
                        </td>
                        <?php if ($showBalance): ?>
                        <td class="px-3 py-2.5 text-right font-bold text-xs whitespace-nowrap
                                   <?= isset($row->balance) && $row->balance < 0 ? 'text-red-600' : 'text-gray-800' ?>">
                            <?php if (isset($row->balance)):
                                $b = $row->balance;
                                echo ($b < 0 ? '-' : '') . '৳' . number_format(abs($b), 0);
                            endif; ?>
                        </td>
                        <?php endif; ?>
                        <?php if ($is_superadmin): ?>
                        <td class="px-3 py-2.5 text-center">
                            <button type="button"
                                    data-src="<?= htmlspecialchars($src) ?>"
                                    data-id="<?= $srcId ?>"
                                    data-desc="<?= htmlspecialchars(mb_strimwidth($row->description ?? '—', 0, 50, '…')) ?>"
                                    onclick="event.stopPropagation(); openDeleteModal(this.dataset.src, parseInt(this.dataset.id), this.dataset.desc)"
                                    class="text-gray-300 hover:text-red-500 transition-colors"
                                    title="Delete transaction">
                                <i class="fas fa-trash text-[11px]"></i>
                            </button>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>

                    <!-- Totals row -->
                    <tr class="bg-gray-100 font-semibold border-t-2 border-gray-300 text-xs">
                        <td colspan="<?= $accountId ? '4' : '4' ?>" class="px-3 py-2.5 text-right text-gray-500">
                            <?= number_format($totalRows) ?> rows
                            <?= $totalPages > 1 ? "(page $page of $totalPages)" : '' ?>
                        </td>
                        <td class="px-3 py-2.5 hidden lg:table-cell"></td>
                        <td class="px-3 py-2.5 text-right text-emerald-700">
                            ৳<?= number_format(array_sum(array_map(fn($r) => (float)$r->credit_amount, $txRows)), 0) ?>
                        </td>
                        <td class="px-3 py-2.5 text-right text-red-700">
                            ৳<?= number_format(array_sum(array_map(fn($r) => (float)$r->debit_amount, $txRows)), 0) ?>
                        </td>
                        <?php if ($showBalance): ?>
                        <td class="px-3 py-2.5 text-right <?= $closingBal < 0 ? 'text-red-600' : 'text-indigo-700' ?>">
                            <?php if ($page === $totalPages): ?>
                            <?= $closingBal < 0 ? '-' : '' ?>৳<?= number_format(abs($closingBal), 0) ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <?php if ($is_superadmin): ?><td></td><?php endif; ?>
                    </tr>
                    <?php endif; ?>

                </tbody>
            </table>
        </div>
    </div>

    <!-- ── Pagination ──────────────────────────────────────────────────── -->
    <?php if ($totalPages > 1): ?>
    <div class="flex items-center justify-center gap-1.5 flex-wrap mb-5">
        <?php if ($page > 1): ?>
        <a href="<?= pageUrl(1, $rawAccountId, $source, $dateFrom, $dateTo) ?>"
           class="px-2.5 py-1.5 rounded-lg border border-gray-200 text-xs text-gray-600 hover:bg-gray-100 transition-colors">
            « First
        </a>
        <a href="<?= pageUrl($page - 1, $rawAccountId, $source, $dateFrom, $dateTo) ?>"
           class="px-2.5 py-1.5 rounded-lg border border-gray-200 text-xs text-gray-600 hover:bg-gray-100 transition-colors">
            ‹ Prev
        </a>
        <?php endif; ?>

        <?php
        $startPage = max(1, $page - 3);
        $endPage   = min($totalPages, $page + 3);
        for ($p = $startPage; $p <= $endPage; $p++):
        ?>
        <a href="<?= pageUrl($p, $rawAccountId, $source, $dateFrom, $dateTo) ?>"
           class="px-2.5 py-1.5 rounded-lg border text-xs transition-colors
                  <?= $p === $page ? 'bg-indigo-600 text-white border-indigo-600 font-semibold' : 'border-gray-200 text-gray-600 hover:bg-gray-100' ?>">
            <?= $p ?>
        </a>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
        <a href="<?= pageUrl($page + 1, $rawAccountId, $source, $dateFrom, $dateTo) ?>"
           class="px-2.5 py-1.5 rounded-lg border border-gray-200 text-xs text-gray-600 hover:bg-gray-100 transition-colors">
            Next ›
        </a>
        <a href="<?= pageUrl($totalPages, $rawAccountId, $source, $dateFrom, $dateTo) ?>"
           class="px-2.5 py-1.5 rounded-lg border border-gray-200 text-xs text-gray-600 hover:bg-gray-100 transition-colors">
            Last »
        </a>
        <?php endif; ?>

        <span class="text-xs text-gray-400 ml-2">
            Showing <?= number_format(($page-1)*$perPage + 1) ?>–<?= number_format(min($page*$perPage, $totalRows)) ?> of <?= number_format($totalRows) ?>
        </span>
    </div>
    <?php endif; ?>

</div>

<!-- ═══════════════ DELETE MODAL ═══════════════ -->
<?php if ($is_superadmin): ?>
<div id="deleteModal"
     class="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 hidden"
     onclick="if(event.target===this) closeDeleteModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden">
        <div class="bg-red-50 border-b border-red-100 px-5 py-4 flex items-center gap-2">
            <i class="fas fa-exclamation-triangle text-red-500"></i>
            <h3 class="font-bold text-red-800 text-sm">Confirm Delete</h3>
        </div>
        <div class="px-5 py-4">
            <p class="text-sm text-gray-700 mb-1">
                You are about to delete this transaction:
            </p>
            <p id="deleteDesc" class="text-sm font-semibold text-gray-900 bg-gray-50 px-3 py-2 rounded-lg mb-4 truncate"></p>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Reason (optional)</label>
            <input type="text" id="deleteReason"
                   placeholder="e.g. Duplicate entry, data correction…"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-red-400 focus:border-red-400">
        </div>
        <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 flex justify-end gap-2">
            <button type="button" onclick="closeDeleteModal()"
                    class="px-4 py-2 text-sm text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-gray-100 transition-colors">
                Cancel
            </button>
            <button type="button" id="deleteConfirmBtn" onclick="submitDelete()"
                    class="px-4 py-2 text-sm text-white bg-red-600 hover:bg-red-700 rounded-lg font-semibold transition-colors flex items-center gap-1.5">
                <i class="fas fa-trash text-xs"></i>
                <span id="deleteBtnLabel">Delete</span>
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Toast -->
<div id="toastMsg"
     class="fixed bottom-4 left-1/2 -translate-x-1/2 z-[200] px-5 py-3 rounded-xl shadow-2xl text-sm font-medium text-white
            transition-all duration-300 opacity-0 pointer-events-none">
</div>

<script>
// ── Date presets ──────────────────────────────────────────────────────────
function setPreset(df, dt) {
    document.getElementById('dateFrom').value = df;
    document.getElementById('dateTo').value   = dt;
    document.getElementById('filterForm').submit();
}

// ── Source filter ─────────────────────────────────────────────────────────
function setSource(src) {
    document.getElementById('sourceInput').value = src;
    document.getElementById('filterForm').submit();
}

// ── Row navigation (skip delete buttons) ──────────────────────────────────
function rowNavigate(event, href) {
    if (event.target.closest('button')) return;
    if (href && href !== '#') window.location.href = href;
}

// ── Toast ─────────────────────────────────────────────────────────────────
function showToast(msg, success) {
    const t = document.getElementById('toastMsg');
    t.textContent = msg;
    t.className = t.className.replace(/bg-\w+-\d+/g, '');
    t.classList.add(success ? 'bg-emerald-700' : 'bg-red-700');
    t.classList.remove('opacity-0','pointer-events-none');
    setTimeout(() => {
        t.classList.add('opacity-0','pointer-events-none');
    }, 3500);
}

<?php if ($is_superadmin): ?>
// ── Delete modal ──────────────────────────────────────────────────────────
let _delSrc = '', _delId = 0;

function openDeleteModal(src, id, desc) {
    _delSrc = src;
    _delId  = id;
    document.getElementById('deleteDesc').textContent = desc;
    document.getElementById('deleteReason').value = '';
    document.getElementById('deleteBtnLabel').textContent = 'Delete';
    document.getElementById('deleteConfirmBtn').disabled = false;
    document.getElementById('deleteModal').classList.remove('hidden');
    setTimeout(() => document.getElementById('deleteReason').focus(), 50);
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
}

async function submitDelete() {
    const reason = document.getElementById('deleteReason').value.trim();
    const btn    = document.getElementById('deleteConfirmBtn');
    btn.disabled = true;
    document.getElementById('deleteBtnLabel').textContent = 'Deleting…';

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    let url, body, headers = {};

    if (_delSrc === 'cr_payment') {
        url  = '<?= url('cr/delete_payment.php') ?>';
        const fd = new FormData();
        fd.append('payment_id', _delId);
        fd.append('reason', reason);
        fd.append('csrf_token', csrf);
        body = fd;

    } else if (_delSrc === 'purchase_payment') {
        url  = '<?= url('purchase/purchase_adnan_delete_payment.php') ?>';
        const fd = new FormData();
        fd.append('id', _delId);
        fd.append('reason', reason);
        fd.append('csrf_token', csrf);
        body = fd;

    } else if (_delSrc === 'bank_tx') {
        url     = '<?= url('bank/ajax_handler.php') ?>';
        headers = {'Content-Type': 'application/json', 'X-CSRF-Token': csrf};
        body    = JSON.stringify({action: 'unpost', id: _delId, reason});

    } else if (_delSrc === 'expense') {
        url     = '<?= url('expense/ajax_delete_expense.php') ?>';
        headers = {'Content-Type': 'application/json', 'X-CSRF-Token': csrf};
        body    = JSON.stringify({expense_id: _delId});
    }

    try {
        const resp = await fetch(url, {method: 'POST', headers, body, credentials: 'same-origin'});
        const data = await resp.json();
        if (data.success) {
            showToast(data.message || 'Deleted successfully.', true);
            closeDeleteModal();
            setTimeout(() => window.location.reload(), 1200);
        } else {
            showToast(data.message || 'Delete failed.', false);
            btn.disabled = false;
            document.getElementById('deleteBtnLabel').textContent = 'Delete';
        }
    } catch(e) {
        showToast('Network error. Please try again.', false);
        btn.disabled = false;
        document.getElementById('deleteBtnLabel').textContent = 'Delete';
    }
}
<?php endif; ?>
</script>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>