<?php
/**
 * bank/statement.php
 * Per-account bank statement with running balance
 * Accessible by Superadmin, admin, Accounts roles
 */

require_once dirname(__DIR__) . '/core/init.php';
require_once dirname(__DIR__) . '/bank/BankManager.php';

restrict_access(['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg']);

$currentUser   = getCurrentUser();
$isSuperadmin  = $currentUser['role'] === 'Superadmin';
$isFullAdmin   = in_array($currentUser['role'], ['Superadmin', 'admin'], true);
$csrfToken     = $_SESSION['csrf_token'] ?? '';

$currentUser = getCurrentUser();
$userRole    = $currentUser['role'];
$bankManager = new BankManager();
$db          = Database::getInstance();

// ── Account selection ──────────────────────────────────────
// account_type = 'btx' (bank_tx_accounts, default) or 'ba' (bank_accounts)
$accountType  = in_array($_GET['account_type'] ?? '', ['btx','ba']) ? $_GET['account_type'] : 'btx';
$accountId    = (int)($_GET['account_id'] ?? 0);
$dateFrom     = $_GET['date_from'] ?? '';
$dateTo       = $_GET['date_to']   ?? date('Y-m-d');
$statusFilter = $_GET['status']    ?? 'approved'; // only applies to btx accounts

// ── Export handlers (run before any HTML output) ──────────
$exportType = $_GET['export'] ?? '';

if ($exportType && $accountId) {
    $account = $bankManager->getBankAccountById($accountId);
    if ($account) {
        // Build transaction rows for export
        $where  = ['bt.bank_tx_account_id = ?'];
        $params = [$accountId];
        if ($statusFilter === 'all') {
            $where[] = "bt.status != 'unposted'";
        } else {
            $where[] = "bt.status = ?";
            $params[] = $statusFilter;
        }
        if ($dateFrom) { $where[] = 'bt.transaction_date >= ?'; $params[] = $dateFrom; }
        if ($dateTo)   { $where[] = 'bt.transaction_date <= ?'; $params[] = $dateTo; }

        $exportRows = $db->query(
            "SELECT bt.*, btt.name AS type_name, u.display_name AS created_by_name
             FROM bank_transactions bt
             LEFT JOIN bank_tx_transaction_types btt ON btt.id = bt.transaction_type_id
             LEFT JOIN users u ON u.id = bt.created_by_user_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY bt.transaction_date ASC, bt.id ASC",
            $params
        )->results();

        // Compute opening balance
        $openBal = (float)$account->opening_balance;
        if ($dateFrom) {
            $preRow = $db->query(
                "SELECT COALESCE(SUM(CASE WHEN entry_type='credit' AND status='approved' THEN amount ELSE 0 END),0) -
                        COALESCE(SUM(CASE WHEN entry_type='debit'  AND status='approved' THEN amount ELSE 0 END),0) AS pre_balance
                 FROM bank_transactions WHERE bank_tx_account_id = ? AND transaction_date < ? AND status='approved'",
                [$accountId, $dateFrom]
            )->first();
            $openBal += $preRow ? (float)$preRow->pre_balance : 0;
        }

        if ($exportType === 'excel') {
            // ── CSV / Excel export ─────────────────────────
            $filename = 'statement_' . preg_replace('/[^a-z0-9]/i', '_', $account->bank_name)
                      . '_' . date('Ymd_His') . '.csv';
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

            fputcsv($out, [
                $account->bank_name . ' — ' . $account->account_name . ' (' . $account->account_number . ')',
            ]);
            $periodRow = ['Period: ' . ($dateFrom ?: 'All') . ' to ' . ($dateTo ?: date('Y-m-d')), '', '', '', '', '', ''];
            if ($isFullAdmin) { $periodRow[] = 'Opening Balance:'; $periodRow[] = number_format($openBal, 2); }
            fputcsv($out, $periodRow);
            fputcsv($out, []); // blank row
            $header = ['#','Date','Transaction #','Cheque #','Reference','Payee/Payer','Description','Category','Credit (৳)','Debit (৳)'];
            if ($isFullAdmin) { $header[] = 'Balance (৳)'; }
            $header[] = 'Status'; $header[] = 'Entered By';
            fputcsv($out, $header);

            $running = $openBal; $sn = 1;
            foreach ($exportRows as $r) {
                $running += $r->entry_type === 'credit' ? (float)$r->amount : -(float)$r->amount;
                $csvRow = [
                    $sn++,
                    $r->transaction_date,
                    $r->transaction_number,
                    $r->cheque_number ?? '',
                    $r->reference_number ?? '',
                    $r->payee_payer_name ?? '',
                    $r->description ?? '',
                    $r->type_name ?? '',
                    $r->entry_type === 'credit' ? number_format((float)$r->amount, 2) : '',
                    $r->entry_type === 'debit'  ? number_format((float)$r->amount, 2) : '',
                ];
                if ($isFullAdmin) { $csvRow[] = number_format($running, 2); }
                $csvRow[] = ucfirst($r->status);
                $csvRow[] = $r->created_by_name ?? '';
                fputcsv($out, $csvRow);
            }
            fputcsv($out, []); // blank
            $totalCreditExp = array_sum(array_map(fn($r) => $r->entry_type === 'credit' ? (float)$r->amount : 0, $exportRows));
            $totalDebitExp  = array_sum(array_map(fn($r) => $r->entry_type === 'debit'  ? (float)$r->amount : 0, $exportRows));
            if ($isFullAdmin) {
                fputcsv($out, ['', '', '', '', '', '', '', 'Closing Balance:', '', '', number_format($running, 2)]);
            } else {
                fputcsv($out, ['', '', '', '', '', '', 'Total Credit:', number_format($totalCreditExp, 2), 'Total Debit:', number_format($totalDebitExp, 2)]);
            }
            fclose($out);
            exit;
        }
    }
}

// ── Load both account lists ────────────────────────────────
$btxAccounts = $bankManager->getBankAccounts(false); // bank_tx_accounts (include inactive)
$baAccounts  = $db->query(
    "SELECT id, bank_name, account_name, account_number, initial_balance AS opening_balance,
            status, 'ba' AS acct_src
     FROM bank_accounts
     ORDER BY bank_name, account_name"
)->results();

// Alias for export (backward compat — still uses btx when account_type=btx)
$accounts = $btxAccounts;

$account    = null;
$txRows     = [];
$openingBal = 0;
$closingBal = 0;
$isBaAcct   = ($accountType === 'ba'); // true = using bank_accounts + v_bank_statement

if ($accountId && $isBaAcct) {
    // ── bank_accounts path: use v_bank_statement ──────────
    $account = $db->query(
        "SELECT *, initial_balance AS opening_balance FROM bank_accounts WHERE id = ?",
        [$accountId]
    )->first();

    if ($account) {
        $preBal = (float)$account->opening_balance;
        if ($dateFrom) {
            $preRow = $db->query(
                "SELECT COALESCE(SUM(credit_amount),0) - COALESCE(SUM(debit_amount),0) AS net
                 FROM v_bank_statement WHERE bank_account_id = ? AND txn_date < ?",
                [$accountId, $dateFrom]
            )->first();
            $preBal += $preRow ? (float)$preRow->net : 0;
        }
        $openingBal = $preBal;

        $where  = ['bank_account_id = ?'];
        $params = [$accountId];
        if ($dateFrom) { $where[] = 'txn_date >= ?'; $params[] = $dateFrom; }
        if ($dateTo)   { $where[] = 'txn_date <= ?'; $params[] = $dateTo; }

        $txRows = $db->query(
            "SELECT txn_date AS transaction_date, credit_amount, debit_amount, description,
                    source, source_id, reference, payment_method, created_at
             FROM v_bank_statement
             WHERE " . implode(' AND ', $where) . "
             ORDER BY txn_date ASC, created_at ASC",
            $params
        )->results();

        // Running balance
        $running = $openingBal;
        foreach ($txRows as $row) {
            $running += (float)$row->credit_amount - (float)$row->debit_amount;
            $row->running_balance = $running;
            $row->entry_type = (float)$row->credit_amount > 0 ? 'credit' : 'debit';
            $row->amount     = max((float)$row->credit_amount, (float)$row->debit_amount);
        }
        $closingBal = $running;
    }
} elseif ($accountId) {
    // ── bank_tx_accounts path: existing logic ─────────────
    $account = $bankManager->getBankAccountById($accountId);

    if ($account) {
        // Opening balance = account opening_balance + all approved tx BEFORE date_from
        $preBal = (float)$account->opening_balance;
        if ($dateFrom) {
            $preRow = $db->query(
                "SELECT
                    COALESCE(SUM(CASE WHEN entry_type='credit' AND status='approved' THEN amount ELSE 0 END), 0) -
                    COALESCE(SUM(CASE WHEN entry_type='debit'  AND status='approved' THEN amount ELSE 0 END), 0) AS pre_balance
                 FROM bank_transactions
                 WHERE bank_tx_account_id = ? AND transaction_date < ? AND status = 'approved'",
                [$accountId, $dateFrom]
            )->first();
            $preBal += $preRow ? (float)$preRow->pre_balance : 0;
        } else {
            // No date_from: opening balance is just the account's opening_balance
            $preBal = (float)$account->opening_balance;
        }
        $openingBal = $preBal;

        // Build WHERE for transaction rows
        $where  = ['bt.bank_tx_account_id = ?'];
        $params = [$accountId];

        if ($statusFilter === 'all') {
            // show everything except unposted
            $where[] = "bt.status != 'unposted'";
        } else {
            $where[] = "bt.status = ?";
            $params[] = $statusFilter;
        }

        if ($dateFrom) { $where[] = 'bt.transaction_date >= ?'; $params[] = $dateFrom; }
        if ($dateTo)   { $where[] = 'bt.transaction_date <= ?'; $params[] = $dateTo; }

        $whereStr = implode(' AND ', $where);

        $txRows = $db->query(
            "SELECT bt.*,
                btt.name AS type_name,
                u.display_name AS created_by_name,
                au.display_name AS approved_by_name
             FROM bank_transactions bt
             LEFT JOIN bank_tx_transaction_types btt ON btt.id = bt.transaction_type_id
             LEFT JOIN users u  ON u.id  = bt.created_by_user_id
             LEFT JOIN users au ON au.id = bt.approved_by_user_id
             WHERE $whereStr
             ORDER BY bt.transaction_date ASC, bt.id ASC",
            $params
        )->results();

        // Compute running balance
        $running = $openingBal;
        foreach ($txRows as $row) {
            if ($row->entry_type === 'credit') {
                $running += (float)$row->amount;
            } else {
                $running -= (float)$row->amount;
            }
            $row->running_balance = $running;
        }
        $closingBal = $running;
    }
} // end elseif bank_tx_accounts

// Summary totals
$totalCredit = array_sum(array_map(fn($r) => (float)($r->credit_amount ?? ($r->entry_type === 'credit' ? $r->amount : 0)), $txRows));
$totalDebit  = array_sum(array_map(fn($r) => (float)($r->debit_amount  ?? ($r->entry_type === 'debit'  ? $r->amount : 0)), $txRows));

$pageTitle = $account ? 'Statement — ' . $account->bank_name : 'Bank Account Statement';
require_once dirname(__DIR__) . '/templates/header.php';
?>

<div class="w-full px-4 sm:px-6 lg:px-8 py-6">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center gap-3 mb-5">
        <a href="<?php echo url('bank/index.php'); ?>"
           class="text-gray-400 hover:text-gray-600 flex-shrink-0">
            <i class="fas fa-arrow-left text-lg"></i>
        </a>
        <div class="flex-1">
            <h1 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-file-invoice-dollar text-primary-600"></i>
                Bank Account Statement
            </h1>
            <?php if ($account): ?>
            <p class="text-sm text-gray-500 mt-0.5">
                <?php echo htmlspecialchars($account->bank_name . ' — ' . $account->account_name . ' (' . $account->account_number . ')'); ?>
            </p>
            <?php endif; ?>
        </div>
        <?php if ($account && !empty($txRows)): ?>
        <div class="flex gap-2 flex-wrap">
            <?php
            $exportBase = '?account_id=' . $accountId
                . '&account_type=' . urlencode($accountType)
                . '&date_from=' . urlencode($dateFrom)
                . '&date_to='   . urlencode($dateTo)
                . '&status='    . urlencode($statusFilter);
            ?>
            <a href="<?php echo $exportBase; ?>&export=excel"
               class="inline-flex items-center px-3 py-2 border border-green-300 bg-green-50 text-green-700 rounded-lg text-sm font-medium hover:bg-green-100 transition-colors">
                <i class="fas fa-file-excel mr-1.5"></i> Excel
            </a>
            <button onclick="exportPDF()"
                    class="inline-flex items-center px-3 py-2 border border-red-300 bg-red-50 text-red-700 rounded-lg text-sm font-medium hover:bg-red-100 transition-colors">
                <i class="fas fa-file-pdf mr-1.5"></i> PDF
            </button>
            <button onclick="window.print()"
                    class="inline-flex items-center px-3 py-2 border border-gray-300 bg-white text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-50 transition-colors">
                <i class="fas fa-print mr-1.5"></i> Print
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Filter Form ─────────────────────────────────────── -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-5">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">

            <div class="lg:col-span-1">
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Bank Account</label>
                <input type="hidden" name="account_type" id="acctTypeHidden" value="<?php echo htmlspecialchars($accountType); ?>">
                <select name="account_id" id="accountSelect" required
                        onchange="syncAcctType(this)"
                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                    <option value="">— Select Account —</option>
                    <?php if (!empty($btxAccounts)): ?>
                    <optgroup label="🏦 Bank TX Module Accounts">
                        <?php foreach ($btxAccounts as $a): ?>
                        <option value="<?php echo $a->id; ?>"
                                data-type="btx"
                                <?php echo ($a->id == $accountId && $accountType === 'btx') ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($a->bank_name . ' — ' . $a->account_name); ?>
                            <?php echo $a->status !== 'active' ? ' [' . strtoupper($a->status) . ']' : ''; ?>
                        </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                    <?php if (!empty($baAccounts)): ?>
                    <optgroup label="💳 CR / Expense / Purchase Accounts">
                        <?php foreach ($baAccounts as $a): ?>
                        <option value="<?php echo $a->id; ?>"
                                data-type="ba"
                                <?php echo ($a->id == $accountId && $accountType === 'ba') ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($a->bank_name . ' — ' . $a->account_name); ?>
                            <?php echo ($a->status ?? '') !== 'active' ? ' [INACTIVE]' : ''; ?>
                        </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">From Date</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>"
                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">To Date</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>"
                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Show</label>
                <select name="status"
                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                    <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Approved Only</option>
                    <option value="all"      <?php echo $statusFilter === 'all'      ? 'selected' : ''; ?>>All (excl. Unposted)</option>
                    <option value="pending"  <?php echo $statusFilter === 'pending'  ? 'selected' : ''; ?>>Pending Only</option>
                </select>
            </div>

            <div>
                <button type="submit"
                        class="w-full bg-primary-600 text-white py-2 rounded-lg text-sm font-semibold hover:bg-primary-700 transition-colors">
                    <i class="fas fa-search mr-1"></i> Generate
                </button>
            </div>
        </form>
    </div>

    <?php if ($accountId && !$account): ?>
    <div class="bg-red-50 text-red-600 rounded-xl p-4 text-sm">Account not found.</div>

    <?php elseif ($account): ?>

    <!-- ── Account Info + Summary Cards ───────────────────── -->
    <div class="grid grid-cols-2 <?php echo $isFullAdmin ? 'lg:grid-cols-4' : 'lg:grid-cols-2'; ?> gap-4 mb-5">

        <?php if ($isFullAdmin): ?>
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
            <p class="text-xs text-gray-500 uppercase tracking-wide font-semibold">Opening Balance</p>
            <p class="text-xl font-bold <?php echo $openingBal >= 0 ? 'text-gray-800' : 'text-red-600'; ?> mt-1">
                ৳<?php echo number_format($openingBal, 2); ?>
            </p>
            <p class="text-xs text-gray-400 mt-0.5"><?php echo $dateFrom ?: 'Account inception'; ?></p>
        </div>
        <?php endif; ?>

        <div class="bg-white rounded-xl border border-green-100 shadow-sm p-4">
            <p class="text-xs text-green-600 uppercase tracking-wide font-semibold">Total Credits</p>
            <p class="text-xl font-bold text-green-600 mt-1">৳<?php echo number_format($totalCredit, 2); ?></p>
            <p class="text-xs text-gray-400 mt-0.5"><?php echo count(array_filter($txRows, fn($r) => $r->entry_type === 'credit')); ?> transaction(s)</p>
        </div>

        <div class="bg-white rounded-xl border border-red-100 shadow-sm p-4">
            <p class="text-xs text-red-600 uppercase tracking-wide font-semibold">Total Debits</p>
            <p class="text-xl font-bold text-red-600 mt-1">৳<?php echo number_format($totalDebit, 2); ?></p>
            <p class="text-xs text-gray-400 mt-0.5"><?php echo count(array_filter($txRows, fn($r) => $r->entry_type === 'debit')); ?> transaction(s)</p>
        </div>

        <?php if ($isFullAdmin): ?>
        <div class="bg-white rounded-xl border border-primary-100 shadow-sm p-4">
            <p class="text-xs text-primary-600 uppercase tracking-wide font-semibold">Closing Balance</p>
            <p class="text-xl font-bold <?php echo $closingBal >= 0 ? 'text-primary-700' : 'text-red-600'; ?> mt-1">
                ৳<?php echo number_format($closingBal, 2); ?>
            </p>
            <p class="text-xs text-gray-400 mt-0.5"><?php echo $dateTo ?: date('Y-m-d'); ?></p>
        </div>
        <?php endif; ?>

    </div>

    <!-- ── Statement Table ────────────────────────────────── -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">

        <!-- Table Header -->
        <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
            <div>
                <p class="text-sm font-semibold text-gray-700">
                    <?php echo number_format(count($txRows)); ?> transaction(s)
                    <?php if ($dateFrom || $dateTo): ?>
                    <span class="text-xs text-gray-400 font-normal ml-2">
                        <?php echo $dateFrom ?: 'All dates'; ?> → <?php echo $dateTo ?: 'today'; ?>
                    </span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="flex items-center gap-3 text-xs text-gray-500">
                <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-green-400 inline-block"></span> Credit (In)</span>
                <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-red-400 inline-block"></span> Debit (Out)</span>
            </div>
        </div>

        <?php if (empty($txRows)): ?>
        <div class="px-5 py-12 text-center text-gray-400">
            <i class="fas fa-file-alt text-3xl mb-2 block"></i>
            No transactions found for the selected period and filters.
        </div>
        <?php else: ?>

        <div class="overflow-x-auto">
            <table class="w-full text-sm" id="statementTable">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">#</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Date</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Ref / Cheque</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Particulars</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Category</th>
                        <th class="text-right px-4 py-3 text-xs font-semibold text-green-600 uppercase tracking-wide">Credit (৳)</th>
                        <th class="text-right px-4 py-3 text-xs font-semibold text-red-600 uppercase tracking-wide">Debit (৳)</th>
                        <?php if ($isFullAdmin): ?>
                        <th class="text-right px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Balance (৳)</th>
                        <?php endif; ?>
                        <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
                        <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide no-print">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">

                    <?php if ($isFullAdmin): ?>
                    <!-- Opening Balance Row -->
                    <tr class="bg-blue-50">
                        <td colspan="5" class="px-4 py-2 text-xs font-semibold text-blue-700">
                            Opening Balance <?php echo $dateFrom ? '(as of ' . date('d M Y', strtotime($dateFrom)) . ')' : '(Account Inception)'; ?>
                        </td>
                        <td class="px-4 py-2 text-right text-xs text-blue-700 font-mono"></td>
                        <td class="px-4 py-2 text-right text-xs text-blue-700 font-mono"></td>
                        <td class="px-4 py-2 text-right text-xs font-bold text-blue-700 font-mono">
                            <?php echo number_format($openingBal, 2); ?>
                        </td>
                        <td colspan="2"></td>
                    </tr>
                    <?php endif; ?>

                    <?php
                    $sourceBadges = [
                        'cr_payment'       => ['CR Payment',      'bg-emerald-100 text-emerald-700'],
                        'expense'          => ['Expense',          'bg-red-100 text-red-700'],
                        'purchase_payment' => ['Purchase Payment', 'bg-orange-100 text-orange-700'],
                        'bank_tx'          => ['Bank TX',          'bg-blue-100 text-blue-700'],
                    ];
                    $sn = 1; foreach ($txRows as $row):
                        $isCredit = $isBaAcct
                            ? ((float)$row->credit_amount > 0)
                            : ($row->entry_type === 'credit');
                        $rowBg    = $isCredit ? 'hover:bg-green-50' : 'hover:bg-red-50';
                        $statusColors = [
                            'approved'  => 'bg-green-100 text-green-700',
                            'pending'   => 'bg-yellow-100 text-yellow-700',
                            'rejected'  => 'bg-red-100 text-red-700',
                            'cancelled' => 'bg-gray-100 text-gray-500',
                            'unposted'  => 'bg-gray-100 text-gray-400',
                        ];
                        $txDate = $isBaAcct ? ($row->transaction_date ?? '') : ($row->transaction_date ?? '');
                    ?>
                    <tr class="<?php echo $rowBg; ?> transition-colors data-row">

                        <td class="px-4 py-3 text-xs text-gray-400"><?php echo $sn++; ?></td>

                        <td class="px-4 py-3 text-gray-700 whitespace-nowrap text-xs">
                            <?php echo $txDate ? date('d M Y', strtotime($txDate)) : '—'; ?>
                        </td>

                        <td class="px-4 py-3">
                            <?php if ($isBaAcct):
                                $src = $row->source ?? '';
                                [$srcLabel, $srcBadge] = $sourceBadges[$src] ?? [ucfirst($src), 'bg-gray-100 text-gray-600'];
                            ?>
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold <?php echo $srcBadge; ?>">
                                <?php echo $srcLabel; ?>
                            </span>
                            <?php if (!empty($row->reference)): ?>
                            <p class="text-xs text-gray-400 mt-0.5 font-mono"><?php echo htmlspecialchars($row->reference); ?></p>
                            <?php endif; ?>
                            <?php else: ?>
                            <p class="font-mono text-xs text-primary-600"><?php echo htmlspecialchars($row->transaction_number ?? ''); ?></p>
                            <?php if (!empty($row->cheque_number)): ?>
                            <p class="text-xs text-gray-400">Chq: <?php echo htmlspecialchars($row->cheque_number); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($row->reference_number)): ?>
                            <p class="text-xs text-gray-400">Ref: <?php echo htmlspecialchars($row->reference_number); ?></p>
                            <?php endif; ?>
                            <?php endif; // end else (btx row ref cell) ?>
                        </td>

                        <td class="px-4 py-3 max-w-xs">
                            <?php if ($isBaAcct): ?>
                            <p class="text-xs text-gray-700"><?php echo htmlspecialchars(mb_substr($row->description ?? '—', 0, 80)); ?></p>
                            <?php else: ?>
                            <?php if (!empty($row->payee_payer_name)): ?>
                            <p class="text-xs font-medium text-gray-800"><?php echo htmlspecialchars($row->payee_payer_name); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($row->description)): ?>
                            <p class="text-xs text-gray-500 truncate"><?php echo htmlspecialchars(mb_substr($row->description, 0, 60)); ?></p>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>

                        <td class="px-4 py-3">
                            <?php if ($isBaAcct): ?>
                            <span class="text-xs text-gray-400"><?php echo htmlspecialchars($row->payment_method ?? '—'); ?></span>
                            <?php elseif (!empty($row->type_name)): ?>
                            <span class="text-xs text-gray-500"><?php echo htmlspecialchars($row->type_name); ?></span>
                            <?php endif; ?>
                        </td>

                        <td class="px-4 py-3 text-right font-mono text-sm">
                            <?php if ($isBaAcct): $amt = (float)$row->credit_amount; else: $amt = $isCredit ? (float)$row->amount : 0; endif; ?>
                            <?php if ($amt > 0): ?>
                            <span class="text-green-600 font-semibold"><?php echo number_format($amt, 2); ?></span>
                            <?php else: ?>
                            <span class="text-gray-300">—</span>
                            <?php endif; ?>
                        </td>

                        <td class="px-4 py-3 text-right font-mono text-sm">
                            <?php if ($isBaAcct): $amt = (float)$row->debit_amount; else: $amt = !$isCredit ? (float)$row->amount : 0; endif; ?>
                            <?php if ($amt > 0): ?>
                            <span class="text-red-600 font-semibold"><?php echo number_format($amt, 2); ?></span>
                            <?php else: ?>
                            <span class="text-gray-300">—</span>
                            <?php endif; ?>
                        </td>

                        <?php if ($isFullAdmin): ?>
                        <td class="px-4 py-3 text-right font-mono text-sm font-bold
                            <?php echo $row->running_balance >= 0 ? 'text-gray-800' : 'text-red-700'; ?>">
                            <?php echo number_format($row->running_balance, 2); ?>
                        </td>
                        <?php endif; ?>

                        <td class="px-4 py-3 text-center">
                            <?php if ($isBaAcct): ?>
                            <span class="px-2 py-0.5 text-xs rounded-full bg-green-100 text-green-700">Approved</span>
                            <?php else: ?>
                            <span class="px-2 py-0.5 text-xs rounded-full <?php echo $statusColors[$row->status] ?? 'bg-gray-100 text-gray-500'; ?>">
                                <?php echo ucfirst($row->status ?? ''); ?>
                            </span>
                            <?php endif; ?>
                        </td>

                        <td class="px-4 py-3 no-print">
                            <div class="flex items-center justify-center gap-1">
                                <?php if ($isBaAcct):
                                    $src   = $row->source ?? '';
                                    $srcId = (int)($row->source_id ?? 0);
                                    $srcLinks = [
                                        'cr_payment'       => url('cr/payment_history.php'),
                                        'expense'          => url('expense/expense_voucher_list.php'),
                                        'purchase_payment' => url('purchase/payments.php'),
                                        'bank_tx'          => url('bank/view_transaction.php?id=' . $srcId),
                                    ];
                                    $href = $srcLinks[$src] ?? '#';
                                ?>
                                <a href="<?php echo $href; ?>" title="View Source"
                                   class="p-1.5 text-gray-400 hover:text-primary-600 hover:bg-primary-50 rounded transition-colors">
                                    <i class="fas fa-external-link-alt text-xs"></i>
                                </a>
                                <?php else: ?>
                                <!-- View -->
                                <a href="<?php echo url('bank/view_transaction.php?id=' . $row->id); ?>"
                                   title="View Details"
                                   class="p-1.5 text-gray-400 hover:text-primary-600 hover:bg-primary-50 rounded transition-colors">
                                    <i class="fas fa-eye text-xs"></i>
                                </a>
                                <?php if ($isSuperadmin): ?>
                                <a href="<?php echo url('bank/create_transaction.php?edit=' . $row->id); ?>"
                                   title="Edit Transaction"
                                   class="p-1.5 text-gray-400 hover:text-yellow-600 hover:bg-yellow-50 rounded transition-colors">
                                    <i class="fas fa-pencil-alt text-xs"></i>
                                </a>
                                <?php if (($row->status ?? '') !== 'unposted'): ?>
                                <button onclick="unpostTx(<?php echo $row->id; ?>, '<?php echo htmlspecialchars($row->transaction_number ?? '', ENT_QUOTES); ?>', '<?php echo $row->status ?? ''; ?>')"
                                        title="<?php echo ($row->status ?? '') === 'approved' ? 'Unpost (Superadmin Override)' : 'Mark as Unposted'; ?>"
                                        class="p-1.5 rounded transition-colors <?php echo ($row->status ?? '') === 'approved' ? 'text-orange-500 hover:text-orange-700 hover:bg-orange-50' : 'text-gray-400 hover:text-gray-700 hover:bg-gray-100'; ?>">
                                    <i class="fas fa-ban text-xs"></i>
                                </button>
                                <?php endif; ?>
                                <?php endif; // isSuperadmin ?>
                                <?php endif; // isBaAcct ?>
                            </div>
                        </td>

                    </tr>
                    <?php endforeach; ?>

                    <!-- Closing Balance (admin) / Period Totals (non-admin) Row -->
                    <tr class="bg-primary-50 border-t-2 border-primary-200">
                        <td colspan="5" class="px-4 py-2.5 text-xs font-bold text-primary-700">
                            <?php if ($isFullAdmin): ?>
                            Closing Balance
                            <?php echo $dateTo ? '(as of ' . date('d M Y', strtotime($dateTo)) . ')' : ''; ?>
                            <?php else: ?>
                            Period Totals
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-2.5 text-right font-mono text-xs font-semibold text-green-600">
                            <?php echo number_format($totalCredit, 2); ?>
                        </td>
                        <td class="px-4 py-2.5 text-right font-mono text-xs font-semibold text-red-600">
                            <?php echo number_format($totalDebit, 2); ?>
                        </td>
                        <?php if ($isFullAdmin): ?>
                        <td class="px-4 py-2.5 text-right font-mono font-bold text-base
                            <?php echo $closingBal >= 0 ? 'text-primary-700' : 'text-red-700'; ?>">
                            <?php echo number_format($closingBal, 2); ?>
                        </td>
                        <?php endif; ?>
                        <td colspan="2"></td>
                    </tr>

                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php endif; // end if account ?>

</div>

<!-- jsPDF for PDF export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>

<script>
function exportPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });

    // Header info
    const showBalance = <?php echo json_encode($isFullAdmin); ?>;
    const accountName = <?php echo json_encode($account ? $account->bank_name . ' — ' . $account->account_name . ' (' . $account->account_number . ')' : ''); ?>;
    const period      = <?php echo json_encode(($dateFrom ?: 'All dates') . ' to ' . ($dateTo ?: date('Y-m-d'))); ?>;
    const openingBal  = <?php echo json_encode(number_format($openingBal, 2)); ?>;
    const closingBal  = <?php echo json_encode(number_format($closingBal, 2)); ?>;
    const totalCredit = <?php echo json_encode(number_format($totalCredit, 2)); ?>;
    const totalDebit  = <?php echo json_encode(number_format($totalDebit, 2)); ?>;

    // Title block
    doc.setFontSize(14);
    doc.setFont('helvetica', 'bold');
    doc.text('Bank Account Statement', 14, 16);

    doc.setFontSize(10);
    doc.setFont('helvetica', 'normal');
    doc.text('Account : ' + accountName, 14, 23);
    doc.text('Period  : ' + period, 14, 29);

    doc.setFontSize(9);
    if (showBalance) {
        doc.text('Opening Balance: BDT ' + openingBal, 14, 36);
        doc.text('Total Credits  : BDT ' + totalCredit, 80, 36);
        doc.text('Total Debits   : BDT ' + totalDebit, 155, 36);
        doc.text('Closing Balance: BDT ' + closingBal, 220, 36);
    } else {
        doc.text('Total Credits  : BDT ' + totalCredit, 14, 36);
        doc.text('Total Debits   : BDT ' + totalDebit, 90, 36);
    }

    doc.setDrawColor(200, 200, 200);
    doc.line(14, 39, 283, 39);

    // Build table rows from DOM (column count/order tracks the server-rendered
    // table, which already omits the Balance column for non-admin viewers).
    const tableEl = document.getElementById('statementTable');
    const rows = [];
    const dataCols = showBalance ? 8 : 7; // trailing cols after # (Date..Status), excludes Actions
    tableEl.querySelectorAll('tbody tr.data-row').forEach((tr, idx) => {
        const cells = tr.querySelectorAll('td');
        const row = [idx + 1];
        for (let i = 1; i <= dataCols; i++) row.push(cells[i]?.innerText?.trim() || '');
        rows.push(row);
    });

    const head = showBalance
        ? [['#', 'Date', 'Ref / Cheque', 'Particulars', 'Category', 'Credit (BDT)', 'Debit (BDT)', 'Balance (BDT)', 'Status']]
        : [['#', 'Date', 'Ref / Cheque', 'Particulars', 'Category', 'Credit (BDT)', 'Debit (BDT)', 'Status']];
    const columnStyles = showBalance ? {
            0: { cellWidth: 8,  halign: 'center' },
            1: { cellWidth: 22 },
            2: { cellWidth: 30 },
            3: { cellWidth: 55 },
            4: { cellWidth: 30 },
            5: { cellWidth: 28, halign: 'right' },
            6: { cellWidth: 28, halign: 'right' },
            7: { cellWidth: 28, halign: 'right', fontStyle: 'bold' },
            8: { cellWidth: 18, halign: 'center' },
        } : {
            0: { cellWidth: 8,  halign: 'center' },
            1: { cellWidth: 24 },
            2: { cellWidth: 34 },
            3: { cellWidth: 62 },
            4: { cellWidth: 34 },
            5: { cellWidth: 32, halign: 'right' },
            6: { cellWidth: 32, halign: 'right' },
            7: { cellWidth: 20, halign: 'center' },
        };
    const foot = showBalance
        ? [['', '', '', '', 'Totals', totalCredit, totalDebit, closingBal, '']]
        : [['', '', '', '', 'Totals', totalCredit, totalDebit, '']];

    doc.autoTable({
        startY: 42,
        head: head,
        body: rows,
        theme: 'striped',
        headStyles: { fillColor: [37, 99, 235], textColor: 255, fontSize: 8, fontStyle: 'bold' },
        bodyStyles: { fontSize: 7.5 },
        columnStyles: columnStyles,
        margin: { left: 14, right: 14 },
        didParseCell: function(data) {
            // Color credit column green, debit column red
            if (data.section === 'body') {
                if (data.column.index === 5 && data.cell.raw !== '') data.cell.styles.textColor = [16, 185, 129];
                if (data.column.index === 6 && data.cell.raw !== '') data.cell.styles.textColor = [239, 68, 68];
            }
        },
        foot: foot,
        footStyles: { fillColor: [243, 244, 246], textColor: [30, 30, 30], fontStyle: 'bold', fontSize: 8 },
    });

    // Footer on each page
    const pageCount = doc.internal.getNumberOfPages();
    for (let i = 1; i <= pageCount; i++) {
        doc.setPage(i);
        doc.setFontSize(7);
        doc.setTextColor(150);
        doc.text('Ujjal Flour Mills — Bank Statement | Generated: ' + new Date().toLocaleString(), 14, doc.internal.pageSize.height - 6);
        doc.text('Page ' + i + ' of ' + pageCount, doc.internal.pageSize.width - 25, doc.internal.pageSize.height - 6);
    }

    const safeName = accountName.replace(/[^a-z0-9]/gi, '_').substring(0, 40);
    doc.save('statement_' + safeName + '_' + new Date().toISOString().slice(0,10) + '.pdf');
}
</script>

<script>
// ── Sync account_type hidden field when dropdown changes ──────────────────
function syncAcctType(sel) {
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('acctTypeHidden').value = opt.dataset.type || 'btx';
}
// Set on page load in case of back-navigation
(function() {
    const sel = document.getElementById('accountSelect');
    if (sel && sel.value) syncAcctType(sel);
})();

const csrfToken = "<?php echo htmlspecialchars($csrfToken, ENT_QUOTES); ?>";

// ── Unpost (Superadmin only) ───────────────────────────────
function unpostTx(id, ref, status) {
    const isApproved = status === 'approved';
    const msg = isApproved
        ? '⚠️ SUPERADMIN OVERRIDE\n\nTransaction ' + ref + ' is currently APPROVED.\nUnposting will remove it from balance calculations and hide it from the default view.\n\nThis action is audit logged and a Telegram notification will be sent.\n\nProceed?'
        : 'Mark transaction ' + ref + ' as Unposted?\n\nIt will be hidden from the default view but can be found by filtering on "Unposted" status.\n\nProceed?';
    if (!confirm(msg)) return;

    fetch('ajax_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=unpost&id=' + id + '&csrf=' + csrfToken
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            showToast('Transaction ' + ref + ' marked as unposted.', 'success');
            setTimeout(() => location.reload(), 900);
        } else {
            showToast(d.message || 'Error unposting transaction.', 'error');
        }
    })
    .catch(() => showToast('Network error. Please try again.', 'error'));
}

// ── Toast ──────────────────────────────────────────────────
function showToast(msg, type = 'success') {
    const t = document.createElement('div');
    t.className = 'fixed bottom-5 right-5 z-[9999] px-4 py-3 rounded-xl shadow-lg text-sm font-medium transition-all '
        + (type === 'success' ? 'bg-green-600 text-white' : 'bg-red-600 text-white');
    t.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check' : 'exclamation-circle') + ' mr-2"></i>' + msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3500);
}
</script>

<style>
@media print {
    .no-print, nav, header, footer, form { display: none !important; }
    body { font-size: 11px; background: white; }
    table { font-size: 9px; }
    .shadow-sm, .shadow { box-shadow: none !important; }
    .rounded-xl, .rounded-lg { border-radius: 0 !important; }
    .border { border: 1px solid #ddd !important; }
}
</style>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>