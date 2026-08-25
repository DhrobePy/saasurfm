<?php
/**
 * cr/bank_statement.php
 * Sales collection statement per bank account — credit entries only.
 * Data source: bank_transactions (bank module), same as bank/statement.php.
 */
require_once '../core/init.php';
require_once '../bank/BankManager.php';

restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);
global $db;
$bankManager = new BankManager();

// ── Account selection ─────────────────────────────────────────────────────────
$accountId    = (int)($_GET['account_id'] ?? 0);
$dateFrom     = $_GET['date_from'] ?? '';
$dateTo       = $_GET['date_to']   ?? '';
$statusFilter = in_array($_GET['status'] ?? '', ['approved', 'all', 'pending'])
    ? $_GET['status'] : 'approved';
$typeId       = (int)($_GET['type_id'] ?? 0);

// ── All bank accounts (for picker + selector) ─────────────────────────────────
$allAccounts = $db->query(
    "SELECT ba.*,
        (SELECT COUNT(*) FROM bank_transactions bt
         WHERE bt.bank_tx_account_id = ba.id
           AND bt.entry_type = 'credit'
           AND bt.status != 'unposted') AS collection_count,
        (SELECT COALESCE(SUM(bt2.amount),0) FROM bank_transactions bt2
         WHERE bt2.bank_tx_account_id = ba.id
           AND bt2.entry_type = 'credit'
           AND bt2.status = 'approved') AS total_collected
     FROM bank_tx_accounts ba
     ORDER BY collection_count DESC, ba.bank_name ASC, ba.account_name ASC"
)->results();

// ── Credit-only transaction types (for filter dropdown) ──────────────────────
$creditTypes = $db->query(
    "SELECT id, name FROM bank_tx_transaction_types
     WHERE is_active = 1 AND (nature = 'credit' OR nature IS NULL)
     ORDER BY name"
)->results();

// ── No account selected → show picker ────────────────────────────────────────
if (!$accountId) {
    $pageTitle = 'Sales Collection Statement';
    include '../templates/header.php';
    ?>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">
            <i class="fas fa-hand-holding-usd mr-2 text-gray-400"></i>Sales Collection Statement
        </h1>
        <p class="text-sm text-gray-500 mt-1">Select a bank account to view credit (collections) entries.</p>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($allAccounts as $b): ?>
        <a href="?account_id=<?= (int)$b->id ?>"
           class="block bg-white rounded-xl border border-gray-100 shadow-sm p-4 hover:shadow-md hover:border-blue-200 transition-all cursor-pointer group">
            <div class="flex items-start justify-between mb-2">
                <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-university text-blue-500"></i>
                </div>
                <?php if ($b->collection_count > 0): ?>
                <span class="text-xs font-semibold bg-green-100 text-green-700 px-2 py-0.5 rounded-full">
                    <?= (int)$b->collection_count ?> collections
                </span>
                <?php else: ?>
                <span class="text-xs text-gray-300">no collections</span>
                <?php endif; ?>
            </div>
            <p class="font-semibold text-gray-800 group-hover:text-blue-700 transition-colors">
                <?= htmlspecialchars($b->account_name) ?>
            </p>
            <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars($b->bank_name) ?></p>
            <p class="font-mono text-xs text-gray-400 mt-1"><?= htmlspecialchars($b->account_number ?? '') ?></p>
            <?php if ($b->total_collected > 0): ?>
            <p class="text-xs font-semibold text-green-600 mt-2">৳<?= number_format($b->total_collected, 2) ?> collected</p>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php if (empty($allAccounts)): ?>
        <div class="col-span-3 py-12 text-center text-gray-400">
            <i class="fas fa-university text-4xl mb-3 block opacity-20"></i>
            No bank accounts configured.
        </div>
        <?php endif; ?>
    </div>
    <?php
    include '../templates/footer.php';
    exit;
}

// ── Load selected account ─────────────────────────────────────────────────────
$account = $bankManager->getBankAccountById($accountId);
if (!$account) {
    include '../templates/header.php';
    echo '<div class="bg-red-50 border border-red-300 rounded-xl p-4 text-red-700 text-sm">Bank account not found.</div>';
    include '../templates/footer.php';
    exit;
}

// ── Pagination ────────────────────────────────────────────────────────────────
$perPage  = 50;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $perPage;

// ── Search ────────────────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');

// ── Build WHERE (shared by all queries) ──────────────────────────────────────
$where  = ['bt.bank_tx_account_id = ?', "bt.entry_type = 'credit'"];
$params = [$accountId];

if ($statusFilter === 'all') {
    $where[] = "bt.status != 'unposted'";
} else {
    $where[] = 'bt.status = ?';
    $params[] = $statusFilter;
}
if ($dateFrom) { $where[] = 'bt.transaction_date >= ?'; $params[] = $dateFrom; }
if ($dateTo)   { $where[] = 'bt.transaction_date <= ?'; $params[] = $dateTo; }
if ($typeId)   { $where[] = 'bt.transaction_type_id = ?'; $params[] = $typeId; }
if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(bt.payee_payer_name LIKE ? OR bt.reference_number LIKE ? OR bt.cheque_number LIKE ? OR bt.description LIKE ?)';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

$whereStr = implode(' AND ', $where);

// ── Opening balance = approved credits before date_from ───────────────────────
$openingBal = 0.0;
if ($dateFrom) {
    $preRow = $db->query(
        "SELECT COALESCE(SUM(amount), 0) AS pre
         FROM bank_transactions
         WHERE bank_tx_account_id = ? AND entry_type = 'credit'
           AND status = 'approved' AND transaction_date < ?",
        [$accountId, $dateFrom]
    )->first();
    $openingBal = $preRow ? (float)$preRow->pre : 0.0;
}

// ── Aggregate totals (full period, no LIMIT — for cards + sidebar) ────────────
$aggRow = $db->query(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(bt.amount), 0) AS total
     FROM bank_transactions bt
     WHERE $whereStr",
    $params
)->first();

$totalRows = (int)($aggRow->cnt ?? 0);
$totalIn   = (float)($aggRow->total ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;
$closingBal = $openingBal + $totalIn;

// ── Payer breakdown (full period) — use reference when payer is generic ──────
// "generic" = blank, 'Bank Deposit', 'Cash', 'Cheque', 'Mobile Banking'
$payerRows = $db->query(
    "SELECT
        CASE
            WHEN TRIM(COALESCE(bt.payee_payer_name,'')) = ''
              OR LOWER(TRIM(bt.payee_payer_name)) IN ('bank deposit','cash','cheque','mobile banking','deposit')
            THEN COALESCE(NULLIF(TRIM(bt.reference_number),''), bt.payee_payer_name, 'Unknown')
            ELSE bt.payee_payer_name
        END AS payer,
        SUM(bt.amount) AS total
     FROM bank_transactions bt
     WHERE $whereStr
     GROUP BY payer
     ORDER BY total DESC",
    $params
)->results();

$payerTotals = [];
foreach ($payerRows as $pr) {
    $payerTotals[$pr->payer] = (float)$pr->total;
}

// ── Balance at start of current page (sum of rows before this page) ──────────
$prePageBal = $openingBal;
if ($offset > 0) {
    $preRow2 = $db->query(
        "SELECT COALESCE(SUM(sub.amount), 0) AS pre
         FROM (SELECT bt.amount
               FROM bank_transactions bt
               WHERE $whereStr
               ORDER BY bt.transaction_date ASC, bt.id ASC
               LIMIT ?) sub",
        array_merge($params, [$offset])
    )->first();
    $prePageBal = $openingBal + (float)($preRow2->pre ?? 0);
}

// ── Current page rows ─────────────────────────────────────────────────────────
$txRows = $db->query(
    "SELECT bt.*, btt.name AS type_name, u.display_name AS entered_by
     FROM bank_transactions bt
     LEFT JOIN bank_tx_transaction_types btt ON btt.id = bt.transaction_type_id
     LEFT JOIN users u ON u.id = bt.created_by_user_id
     WHERE $whereStr
     ORDER BY bt.transaction_date ASC, bt.id ASC
     LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
)->results();

// Attach running balance to each page row
$running = $prePageBal;
foreach ($txRows as $row) {
    $running += (float)$row->amount;
    $row->running_balance = $running;
}

// ── Export CSV (all rows, no pagination limit) ────────────────────────────────
if (($_GET['export'] ?? '') === 'csv' && $totalRows > 0) {
    $allRows = $db->query(
        "SELECT bt.*, btt.name AS type_name, u.display_name AS entered_by
         FROM bank_transactions bt
         LEFT JOIN bank_tx_transaction_types btt ON btt.id = bt.transaction_type_id
         LEFT JOIN users u ON u.id = bt.created_by_user_id
         WHERE $whereStr
         ORDER BY bt.transaction_date ASC, bt.id ASC",
        $params
    )->results();

    $fname = 'collections_' . preg_replace('/[^a-z0-9]/i', '_', $account->bank_name)
           . '_' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Sales Collection Statement — ' . $account->bank_name . ' — ' . $account->account_name]);
    fputcsv($out, ['Period: ' . ($dateFrom ?: 'All') . ' to ' . ($dateTo ?: date('Y-m-d'))]);
    fputcsv($out, []);
    fputcsv($out, ['#', 'Date', 'Transaction #', 'Ref / Cheque', 'Payer / Customer', 'Category', 'Amount (৳)', 'Balance (৳)', 'Status', 'Entered By']);
    $sn = 1; $run = $openingBal;
    foreach ($allRows as $r) {
        $run += (float)$r->amount;
        fputcsv($out, [
            $sn++, $r->transaction_date, $r->transaction_number,
            trim(($r->cheque_number ?? '') . ' ' . ($r->reference_number ?? '')),
            $r->payee_payer_name ?? '', $r->type_name ?? '',
            number_format((float)$r->amount, 2), number_format($run, 2),
            ucfirst($r->status), $r->entered_by ?? '',
        ]);
    }
    fputcsv($out, []); fputcsv($out, ['', '', '', '', '', 'Total', number_format($totalIn, 2), number_format($closingBal, 2)]);
    fclose($out); exit;
}

$pageTitle = 'Collection Statement — ' . htmlspecialchars($account->account_name);
include '../templates/header.php';
?>

<!-- ── Page header ────────────────────────────────────────────────────────── -->
<div class="flex items-start justify-between mb-4">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">
            <i class="fas fa-hand-holding-usd mr-2 text-gray-400"></i>Sales Collection Statement
        </h1>
        <p class="text-sm text-gray-500 mt-0.5">
            <?= htmlspecialchars($account->bank_name) ?>
            · <?= htmlspecialchars($account->account_name) ?>
            <?php if (!empty($account->account_number)): ?>
            · <span class="font-mono text-xs"><?= htmlspecialchars($account->account_number) ?></span>
            <?php endif; ?>
            <span class="ml-2 text-xs text-blue-600 font-medium">Credits only</span>
        </p>
    </div>
    <div class="flex gap-2">
        <?php if ($totalRows > 0): ?>
        <a href="?account_id=<?= $accountId ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&status=<?= urlencode($statusFilter) ?>&type_id=<?= $typeId ?>&search=<?= urlencode($search) ?>&export=csv"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-green-300 bg-green-50 text-green-700 text-sm font-medium rounded-lg hover:bg-green-100">
            <i class="fas fa-file-csv text-xs"></i> CSV
        </a>
        <button onclick="window.print()"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-gray-300 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-50 no-print">
            <i class="fas fa-print text-xs"></i> Print
        </button>
        <?php endif; ?>
        <a href="bank_statement.php"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-gray-300 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-50 no-print">
            <i class="fas fa-exchange-alt text-xs"></i> Switch
        </a>
        <a href="<?= url('bank/statement.php?account_id=' . $accountId) ?>"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-gray-200 text-gray-400 text-xs font-medium rounded-lg hover:bg-gray-50 no-print"
           title="Full bank statement (all entries)">
            <i class="fas fa-external-link-alt text-xs"></i> Full Statement
        </a>
    </div>
</div>

<!-- ── Summary cards ──────────────────────────────────────────────────────── -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
    <?php if ($dateFrom): ?>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
        <p class="text-xs text-gray-400 uppercase tracking-wide mb-1">Opening Balance</p>
        <p class="text-lg font-bold text-gray-700">৳<?= number_format($openingBal, 2) ?></p>
        <p class="text-xs text-gray-400 mt-0.5">before <?= date('d M Y', strtotime($dateFrom)) ?></p>
    </div>
    <?php endif; ?>
    <div class="bg-white rounded-xl border border-green-100 shadow-sm p-4">
        <p class="text-xs text-gray-400 uppercase tracking-wide mb-1">Total Collected</p>
        <p class="text-lg font-bold text-green-600">৳<?= number_format($totalIn, 2) ?></p>
        <p class="text-xs text-gray-400 mt-0.5"><?= count($txRows) ?> transaction<?= count($txRows) !== 1 ? 's' : '' ?></p>
    </div>
    <div class="bg-white rounded-xl border border-primary-100 shadow-sm p-4">
        <p class="text-xs text-gray-400 uppercase tracking-wide mb-1">Closing Balance</p>
        <p class="text-lg font-bold text-gray-900">৳<?= number_format($closingBal, 2) ?></p>
        <p class="text-xs text-gray-400 mt-0.5"><?= $dateTo ? date('d M Y', strtotime($dateTo)) : 'today' ?></p>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
        <p class="text-xs text-gray-400 uppercase tracking-wide mb-1">Payers</p>
        <p class="text-lg font-bold text-gray-700"><?= count($payerTotals) ?></p>
        <p class="text-xs text-gray-400 mt-0.5">unique</p>
    </div>
</div>

<!-- ── Filter bar ─────────────────────────────────────────────────────────── -->
<div class="bg-white rounded-xl border border-gray-100 shadow-sm px-4 py-3 mb-4 no-print">
    <form method="GET" class="flex flex-wrap items-end gap-3">
        <input type="hidden" name="account_id" value="<?= $accountId ?>">

        <!-- Account selector -->
        <div class="flex items-center gap-1.5">
            <label class="text-xs text-gray-500">Account</label>
            <select name="account_id" onchange="this.form.submit()"
                    class="border border-gray-300 rounded-lg px-2 py-1.5 text-xs text-gray-700 focus:ring-1 focus:ring-primary-500">
                <?php foreach ($allAccounts as $a): ?>
                <option value="<?= (int)$a->id ?>" <?= $a->id == $accountId ? 'selected' : '' ?>>
                    <?= htmlspecialchars($a->bank_name . ' — ' . $a->account_name) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="flex items-center gap-2">
            <label class="text-xs text-gray-500 whitespace-nowrap">From</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"
                   class="border border-gray-300 rounded-lg px-2 py-1.5 text-xs text-gray-700 focus:ring-1 focus:ring-primary-500">
        </div>
        <div class="flex items-center gap-2">
            <label class="text-xs text-gray-500 whitespace-nowrap">To</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"
                   class="border border-gray-300 rounded-lg px-2 py-1.5 text-xs text-gray-700 focus:ring-1 focus:ring-primary-500">
        </div>

        <?php if (!empty($creditTypes)): ?>
        <div class="flex items-center gap-1.5">
            <label class="text-xs text-gray-500">Category</label>
            <select name="type_id" class="border border-gray-300 rounded-lg px-2 py-1.5 text-xs text-gray-700 focus:ring-1 focus:ring-primary-500">
                <option value="">All Categories</option>
                <?php foreach ($creditTypes as $t): ?>
                <option value="<?= (int)$t->id ?>" <?= $typeId == $t->id ? 'selected' : '' ?>>
                    <?= htmlspecialchars($t->name) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="flex items-center gap-1.5">
            <label class="text-xs text-gray-500">Status</label>
            <select name="status" class="border border-gray-300 rounded-lg px-2 py-1.5 text-xs text-gray-700 focus:ring-1 focus:ring-primary-500">
                <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved Only</option>
                <option value="all"      <?= $statusFilter === 'all'      ? 'selected' : '' ?>>All (excl. Unposted)</option>
                <option value="pending"  <?= $statusFilter === 'pending'  ? 'selected' : '' ?>>Pending Only</option>
            </select>
        </div>

        <!-- Free-text search -->
        <div class="flex items-center gap-1.5 flex-1 min-w-[200px]">
            <label class="text-xs text-gray-500 whitespace-nowrap">Search</label>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                   placeholder="Payer, reference, cheque, description…"
                   class="flex-1 border border-gray-300 rounded-lg px-2 py-1.5 text-xs text-gray-700 focus:ring-1 focus:ring-primary-500">
        </div>

        <button type="submit"
                class="px-3 py-1.5 bg-primary-600 text-white text-xs font-medium rounded-lg hover:bg-primary-700">
            <i class="fas fa-filter mr-1"></i>Apply
        </button>
        <?php if ($dateFrom || $dateTo || $typeId || $statusFilter !== 'approved' || $search !== ''): ?>
        <a href="?account_id=<?= $accountId ?>"
           class="px-3 py-1.5 border border-gray-300 text-gray-500 text-xs font-medium rounded-lg hover:bg-gray-50">
            Clear
        </a>
        <?php endif; ?>
    </form>
</div>

<div class="grid grid-cols-1 xl:grid-cols-4 gap-4">

    <!-- ── Statement table ──────────────────────────────────────────────────── -->
    <div class="xl:col-span-3 bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
            <p class="text-sm font-semibold text-gray-700">
                <?= number_format($totalRows) ?> collection<?= $totalRows !== 1 ? 's' : '' ?>
                <?php if ($dateFrom || $dateTo): ?>
                <span class="text-xs text-gray-400 font-normal ml-2">
                    <?= $dateFrom ? date('d M Y', strtotime($dateFrom)) : 'All' ?>
                    → <?= $dateTo ? date('d M Y', strtotime($dateTo)) : 'today' ?>
                </span>
                <?php endif; ?>
                <?php if ($totalPages > 1): ?>
                <span class="text-xs text-gray-400 font-normal ml-2">
                    · Page <?= $page ?> of <?= $totalPages ?>
                </span>
                <?php endif; ?>
            </p>
            <span class="text-xs text-gray-400">
                <span class="w-2 h-2 rounded-full bg-green-400 inline-block mr-1"></span>Credit In
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-xs" id="collectionTable">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100 text-gray-500 uppercase tracking-wide">
                        <th class="px-4 py-2.5 text-left w-6">#</th>
                        <th class="px-4 py-2.5 text-left w-24">Date</th>
                        <th class="px-4 py-2.5 text-left w-32">Tx #</th>
                        <th class="px-4 py-2.5 text-left">Particulars</th>
                        <th class="px-4 py-2.5 text-left w-28">Category</th>
                        <th class="px-4 py-2.5 text-right w-28">Collected (৳)</th>
                        <th class="px-4 py-2.5 text-right w-28">Balance (৳)</th>
                        <th class="px-4 py-2.5 text-center w-20 no-print">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">

                    <!-- Opening balance row -->
                    <?php if ($dateFrom): ?>
                    <tr class="bg-blue-50">
                        <td colspan="5" class="px-4 py-2 text-xs font-semibold text-blue-700">
                            Opening Balance (as of <?= date('d M Y', strtotime($dateFrom)) ?>)
                        </td>
                        <td class="px-4 py-2 text-right text-xs text-blue-700 font-mono"></td>
                        <td class="px-4 py-2 text-right text-xs font-bold text-blue-700 font-mono">
                            <?= number_format($openingBal, 2) ?>
                        </td>
                        <td class="no-print"></td>
                    </tr>
                    <?php endif; ?>

                    <?php if (empty($txRows)): ?>
                    <tr>
                        <td colspan="8" class="px-4 py-12 text-center text-gray-400">
                            <i class="fas fa-hand-holding-usd text-3xl mb-2 block opacity-20"></i>
                            <?php if ($search !== ''): ?>
                                No results for "<strong><?= htmlspecialchars($search) ?></strong>".
                            <?php else: ?>
                                No collections found<?= ($dateFrom || $dateTo) ? ' for the selected period' : '' ?>.
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php
                        // Payer names considered "generic" — reference field has the real info
                        $genericPayers = ['bank deposit','cash','cheque','mobile banking','deposit',''];

                        $statusBadge = [
                            'approved'  => 'bg-green-100 text-green-700',
                            'pending'   => 'bg-yellow-100 text-yellow-700',
                            'rejected'  => 'bg-red-100 text-red-700',
                            'cancelled' => 'bg-gray-100 text-gray-500',
                        ];
                        $sn = $offset + 1;
                        foreach ($txRows as $row):
                            $payer      = trim($row->payee_payer_name ?? '');
                            $ref        = trim($row->reference_number ?? '');
                            $chq        = trim($row->cheque_number ?? '');
                            $desc       = trim($row->description ?? '');
                            $isGeneric  = in_array(strtolower($payer), $genericPayers);

                            // Primary line: reference if payer is generic, else payer
                            $primaryLine   = $isGeneric ? ($ref ?: $payer) : $payer;
                            // Secondary line: whichever wasn't used as primary
                            $secondaryLine = $isGeneric ? ($payer && !in_array(strtolower($payer), ['']) ? $payer : '') : $ref;
                    ?>
                    <tr class="hover:bg-green-50 transition-colors data-row">
                        <td class="px-4 py-2.5 text-gray-300 text-[11px]"><?= $sn++ ?></td>

                        <td class="px-4 py-2.5 whitespace-nowrap text-gray-500">
                            <?= date('d M Y', strtotime($row->transaction_date)) ?>
                        </td>

                        <td class="px-4 py-2.5 whitespace-nowrap font-mono">
                            <a href="<?= url('bank/view_transaction.php?id=' . (int)$row->id) ?>"
                               target="_blank"
                               class="text-primary-600 hover:underline font-semibold">
                                <?= htmlspecialchars($row->transaction_number) ?>
                            </a>
                        </td>

                        <!-- Merged Particulars column -->
                        <td class="px-4 py-2.5">
                            <?php if ($primaryLine): ?>
                            <p class="font-semibold text-gray-800 leading-tight">
                                <?= htmlspecialchars($primaryLine) ?>
                            </p>
                            <?php endif; ?>
                            <div class="flex flex-wrap gap-x-3 mt-0.5">
                                <?php if ($secondaryLine && strtolower($secondaryLine) !== strtolower($primaryLine)): ?>
                                <span class="text-[10px] text-gray-400"><?= htmlspecialchars($secondaryLine) ?></span>
                                <?php endif; ?>
                                <?php if ($chq): ?>
                                <span class="text-[10px] text-gray-400 font-mono">Chq: <?= htmlspecialchars($chq) ?></span>
                                <?php endif; ?>
                                <?php if ($desc && strtolower($desc) !== strtolower($primaryLine) && strtolower($desc) !== strtolower($secondaryLine)): ?>
                                <span class="text-[10px] text-gray-400 italic truncate max-w-[200px]"><?= htmlspecialchars(mb_substr($desc, 0, 80)) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>

                        <td class="px-4 py-2.5 whitespace-nowrap">
                            <?php if ($row->type_name): ?>
                                <span class="text-gray-500 text-[10px]"><?= htmlspecialchars($row->type_name) ?></span>
                            <?php else: ?>
                                <span class="text-gray-200">—</span>
                            <?php endif; ?>
                        </td>

                        <td class="px-4 py-2.5 text-right font-mono font-semibold text-green-600">
                            <?= number_format((float)$row->amount, 2) ?>
                        </td>

                        <td class="px-4 py-2.5 text-right font-mono font-bold text-gray-800">
                            <?= number_format($row->running_balance, 2) ?>
                        </td>

                        <td class="px-4 py-2.5 text-center no-print">
                            <span class="px-1.5 py-0.5 text-[10px] rounded-full <?= $statusBadge[$row->status] ?? 'bg-gray-100 text-gray-500' ?>">
                                <?= ucfirst($row->status) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- Closing balance / totals row (always full-period figures) -->
                    <?php if ($totalRows > 0): ?>
                    <tr class="bg-primary-50 border-t-2 border-primary-200 font-bold text-xs">
                        <td colspan="5" class="px-4 py-3 text-primary-700">
                            <?php if ($totalPages > 1): ?>
                            Period Total
                            <span class="font-normal text-gray-400 ml-1">(all <?= $totalPages ?> pages)</span>
                            <?php else: ?>
                            Closing Balance<?= $dateTo ? ' (as of ' . date('d M Y', strtotime($dateTo)) . ')' : '' ?>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-green-600">৳<?= number_format($totalIn, 2) ?></td>
                        <td class="px-4 py-3 text-right font-mono text-primary-700">৳<?= number_format($closingBal, 2) ?></td>
                        <td class="no-print"></td>
                    </tr>
                    <?php endif; ?>

                </tbody>
            </table>
        </div>

        <!-- ── Pagination ──────────────────────────────────────────────────── -->
        <?php if ($totalPages > 1): ?>
        <?php
            $qBase = http_build_query(array_filter([
                'account_id' => $accountId,
                'date_from'  => $dateFrom,
                'date_to'    => $dateTo,
                'status'     => $statusFilter !== 'approved' ? $statusFilter : '',
                'type_id'    => $typeId ?: '',
                'search'     => $search,
            ]));
            $prevPage = $page > 1 ? $page - 1 : null;
            $nextPage = $page < $totalPages ? $page + 1 : null;

            // Build a windowed page list: always show first, last, current ±2
            $window = [];
            for ($i = 1; $i <= $totalPages; $i++) {
                if ($i === 1 || $i === $totalPages || abs($i - $page) <= 2) {
                    $window[] = $i;
                }
            }
        ?>
        <div class="flex items-center justify-between px-4 py-3 border-t border-gray-100 no-print">
            <p class="text-xs text-gray-500">
                Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $totalRows)) ?>
                of <?= number_format($totalRows) ?> collections
            </p>
            <div class="flex items-center gap-1">
                <!-- Prev -->
                <?php if ($prevPage): ?>
                <a href="?<?= $qBase ?>&page=<?= $prevPage ?>"
                   class="px-2.5 py-1.5 rounded-lg border border-gray-200 text-xs text-gray-600 hover:bg-gray-50 transition-colors">
                    <i class="fas fa-chevron-left text-[9px]"></i>
                </a>
                <?php else: ?>
                <span class="px-2.5 py-1.5 rounded-lg border border-gray-100 text-xs text-gray-300 cursor-default">
                    <i class="fas fa-chevron-left text-[9px]"></i>
                </span>
                <?php endif; ?>

                <!-- Page numbers -->
                <?php $lastPrinted = 0; foreach ($window as $pg): ?>
                    <?php if ($pg - $lastPrinted > 1): ?>
                    <span class="px-1 text-xs text-gray-300">…</span>
                    <?php endif; ?>
                    <?php if ($pg === $page): ?>
                    <span class="px-2.5 py-1.5 rounded-lg bg-primary-600 text-white text-xs font-semibold">
                        <?= $pg ?>
                    </span>
                    <?php else: ?>
                    <a href="?<?= $qBase ?>&page=<?= $pg ?>"
                       class="px-2.5 py-1.5 rounded-lg border border-gray-200 text-xs text-gray-600 hover:bg-gray-50 transition-colors">
                        <?= $pg ?>
                    </a>
                    <?php endif; ?>
                    <?php $lastPrinted = $pg; ?>
                <?php endforeach; ?>

                <!-- Next -->
                <?php if ($nextPage): ?>
                <a href="?<?= $qBase ?>&page=<?= $nextPage ?>"
                   class="px-2.5 py-1.5 rounded-lg border border-gray-200 text-xs text-gray-600 hover:bg-gray-50 transition-colors">
                    <i class="fas fa-chevron-right text-[9px]"></i>
                </a>
                <?php else: ?>
                <span class="px-2.5 py-1.5 rounded-lg border border-gray-100 text-xs text-gray-300 cursor-default">
                    <i class="fas fa-chevron-right text-[9px]"></i>
                </span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- ── Payer breakdown sidebar ──────────────────────────────────────────── -->
    <?php if (!empty($payerTotals)): ?>
    <div class="xl:col-span-1 no-print">
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden sticky top-20">
            <div class="px-4 py-3 bg-green-50 border-b border-green-100">
                <span class="text-xs font-bold text-green-700 uppercase tracking-wide">
                    <i class="fas fa-users mr-1"></i>By Payer
                </span>
            </div>
            <div class="divide-y divide-gray-50 max-h-[500px] overflow-y-auto">
                <?php foreach ($payerTotals as $name => $amt): ?>
                <div class="px-4 py-2.5 flex items-center justify-between gap-2">
                    <span class="text-xs text-gray-700 truncate flex-1" title="<?= htmlspecialchars($name) ?>">
                        <?= htmlspecialchars($name) ?>
                    </span>
                    <span class="text-xs font-mono font-semibold text-green-600 whitespace-nowrap flex-shrink-0">
                        ৳<?= number_format($amt, 2) ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="px-4 py-2.5 bg-gray-50 border-t border-gray-100 flex justify-between">
                <span class="text-xs text-gray-500"><?= count($payerTotals) ?> payers</span>
                <span class="text-xs font-mono font-bold text-gray-800">৳<?= number_format($totalIn, 2) ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- end grid -->

<style>
@media print {
    .no-print, nav, header, footer { display: none !important; }
    body { font-size: 11px; background: white; }
    table { font-size: 9px; }
    .shadow-sm, .shadow { box-shadow: none !important; }
    .rounded-xl, .rounded-lg { border-radius: 0 !important; }
    .xl\:col-span-3 { width: 100% !important; }
}
</style>

<?php include '../templates/footer.php'; ?>