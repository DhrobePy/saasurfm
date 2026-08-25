<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg'];
restrict_access($allowed_roles);
global $db;

$account      = null;
$transactions = [];
$error        = null;

// ── No UUID → show bank picker ───────────────────────────────────────────────
$show_picker = empty($_GET['uuid']);
$bank_list   = [];

if ($show_picker) {
    $bank_list = $db->query(
        "SELECT ba.uuid, ba.bank_name, ba.account_name, ba.account_number, ba.account_type,
                coa.normal_balance,
                (SELECT COUNT(*) FROM transaction_lines tl
                 JOIN journal_entries je ON tl.journal_entry_id = je.id
                 WHERE tl.account_id = coa.id
                   AND je.related_document_type = 'purchase_payment_adnan') AS purchase_tx_count
         FROM bank_accounts ba
         JOIN chart_of_accounts coa ON ba.chart_of_account_id = coa.id
         ORDER BY ba.bank_name ASC, ba.account_name ASC"
    )->results();

    $pageTitle = 'Bank Statement — Select Account';
    include '../templates/header.php';
    ?>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">
            <i class="fas fa-university mr-2 text-gray-400"></i>Bank Statement
        </h1>
        <p class="text-sm text-gray-500 mt-1">Select a bank account to view its purchase payment statement.</p>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($bank_list as $b): ?>
        <a href="?uuid=<?= urlencode($b->uuid) ?>"
           class="block bg-white rounded-xl border border-gray-100 shadow-sm p-4 hover:shadow-md hover:border-orange-200 transition-all cursor-pointer group">
            <div class="flex items-start justify-between mb-2">
                <div class="w-10 h-10 rounded-lg bg-orange-50 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-university text-orange-500"></i>
                </div>
                <?php if ($b->purchase_tx_count > 0): ?>
                <span class="text-xs font-semibold bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full">
                    <?= (int)$b->purchase_tx_count ?> payments
                </span>
                <?php else: ?>
                <span class="text-xs text-gray-300">no purchases</span>
                <?php endif; ?>
            </div>
            <p class="font-semibold text-gray-800 group-hover:text-orange-700 transition-colors">
                <?= htmlspecialchars($b->account_name) ?>
            </p>
            <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars($b->bank_name) ?></p>
            <p class="font-mono text-xs text-gray-400 mt-1"><?= htmlspecialchars($b->account_number) ?></p>
        </a>
        <?php endforeach; ?>
        <?php if (empty($bank_list)): ?>
        <div class="col-span-3 py-12 text-center text-gray-400">
            <i class="fas fa-university text-4xl mb-3 block opacity-20"></i>
            No bank accounts configured yet.
        </div>
        <?php endif; ?>
    </div>
    <?php
    include '../templates/footer.php';
    exit;
}

// ── Load account by UUID ─────────────────────────────────────────────────────
$uuid    = $_GET['uuid'];
$account = $db->query(
    "SELECT ba.id, ba.uuid, ba.bank_name, ba.branch_name,
            ba.account_name, ba.account_number, ba.account_type,
            coa.id   AS coa_id,
            coa.name AS coa_name,
            coa.normal_balance
     FROM bank_accounts ba
     JOIN chart_of_accounts coa ON ba.chart_of_account_id = coa.id
     WHERE ba.uuid = ?
     LIMIT 1",
    [$uuid]
)->first();

if (!$account) {
    $error = 'Bank account not found.';
}

// ── Date filter ──────────────────────────────────────────────────────────────
$date_from = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'])
    ? $_GET['from'] : null;
$date_to   = isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'])
    ? $_GET['to'] : null;

// ── Transaction type filter ──────────────────────────────────────────────────
$type_filter = in_array($_GET['type'] ?? '', ['purchase', 'transfer', 'all'])
    ? $_GET['type'] : 'all';

// ── Fetch transactions ───────────────────────────────────────────────────────
if ($account && !$error) {
    $sql = "SELECT
                tl.debit_amount,
                tl.credit_amount,
                je.id               AS je_id,
                je.transaction_date,
                je.description      AS je_description,
                je.related_document_type,
                je.related_document_id,
                je.is_reversed,
                -- Purchase payment fields (NULL for non-purchase rows)
                ppa.payment_voucher_number,
                ppa.po_number,
                ppa.purchase_order_id,
                ppa.supplier_name,
                ppa.reference_number AS bank_ref,
                ppa.payment_method,
                ppa.id              AS payment_id
            FROM transaction_lines tl
            JOIN journal_entries je ON tl.journal_entry_id = je.id
            LEFT JOIN purchase_payments_adnan ppa
                ON je.related_document_type = 'purchase_payment_adnan'
               AND je.related_document_id   = ppa.id
            WHERE tl.account_id = ?";

    $params = [$account->coa_id];

    if ($date_from) { $sql .= " AND je.transaction_date >= ?"; $params[] = $date_from; }
    if ($date_to)   { $sql .= " AND je.transaction_date <= ?"; $params[] = $date_to;   }

    if ($type_filter === 'purchase') {
        $sql .= " AND je.related_document_type = 'purchase_payment_adnan'";
    } elseif ($type_filter === 'transfer') {
        $sql .= " AND je.related_document_type = 'InternalTransfer'";
    }

    $sql .= " ORDER BY je.transaction_date ASC, je.id ASC";
    $transactions = $db->query($sql, $params)->results();
}

// ── Totals + running balance ─────────────────────────────────────────────────
$total_debit  = 0;
$total_credit = 0;
$is_debit_normal = $account && $account->normal_balance === 'Debit';

foreach ($transactions as $t) {
    $total_debit  += (float)$t->debit_amount;
    $total_credit += (float)$t->credit_amount;
}
$ending_balance = $is_debit_normal
    ? $total_debit - $total_credit
    : $total_credit - $total_debit;

// ── Type → badge ─────────────────────────────────────────────────────────────
$type_meta = [
    'purchase_payment_adnan' => ['bg-purple-100 text-purple-800', 'Purchase Payment', 'fa-money-bill-wave'],
    'InternalTransfer'       => ['bg-sky-100 text-sky-800',       'Internal Transfer', 'fa-exchange-alt'],
    'BankAccount'            => ['bg-amber-100 text-amber-800',   'Opening Balance', 'fa-landmark'],
    'GeneralTransaction'     => ['bg-gray-100 text-gray-700',     'General', 'fa-file-alt'],
    'credit_orders'          => ['bg-green-100 text-green-800',   'Sales Receipt', 'fa-receipt'],
    'debit_voucher'          => ['bg-orange-100 text-orange-800', 'Expense', 'fa-file-invoice'],
];

$pageTitle = $account ? 'Bank Statement — ' . htmlspecialchars($account->account_name) : 'Bank Statement';
include '../templates/header.php';
?>

<!-- ── Page header ──────────────────────────────────────────────────────────── -->
<div class="flex items-start justify-between mb-4">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">
            <i class="fas fa-university mr-2 text-gray-400"></i>Bank Statement
        </h1>
        <?php if ($account): ?>
        <p class="text-sm text-gray-500 mt-0.5">
            <?= htmlspecialchars($account->bank_name) ?>
            · <?= htmlspecialchars($account->account_name) ?>
            · <span class="font-mono text-xs"><?= htmlspecialchars($account->account_number) ?></span>
        </p>
        <?php endif; ?>
    </div>
    <div class="flex gap-2">
        <a href="purchase_adnan_index.php"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-gray-300 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-50">
            <i class="fas fa-arrow-left text-xs"></i> Back
        </a>
        <?php if ($account): ?>
        <a href="<?= url('accounts/account_statement.php?uuid=' . urlencode($account->uuid)) ?>"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-gray-200 text-gray-500 text-xs font-medium rounded-lg hover:bg-gray-50"
           title="Full accounting view">
            <i class="fas fa-external-link-alt text-xs"></i> Accounts View
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($error): ?>
<div class="bg-red-50 border border-red-300 rounded-xl p-4 mb-4 text-red-700 text-sm">
    <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<?php if ($account): ?>

<!-- ── Summary cards ──────────────────────────────────────────────────────── -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
        <p class="text-xs text-gray-400 uppercase tracking-wide mb-1">Total In (Debits)</p>
        <p class="text-lg font-bold text-gray-800">৳<?= number_format($total_debit, 2) ?></p>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
        <p class="text-xs text-gray-400 uppercase tracking-wide mb-1">Total Out (Credits)</p>
        <p class="text-lg font-bold text-red-600">৳<?= number_format($total_credit, 2) ?></p>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
        <p class="text-xs text-gray-400 uppercase tracking-wide mb-1">Net Balance</p>
        <p class="text-lg font-bold <?= $ending_balance >= 0 ? 'text-gray-900' : 'text-red-600' ?>">
            ৳<?= number_format($ending_balance, 2) ?>
        </p>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
        <p class="text-xs text-gray-400 uppercase tracking-wide mb-1">Transactions</p>
        <p class="text-lg font-bold text-gray-700"><?= count($transactions) ?></p>
    </div>
</div>

<!-- ── Filter bar ─────────────────────────────────────────────────────────── -->
<div class="bg-white rounded-xl border border-gray-100 shadow-sm px-4 py-3 mb-4">
    <form method="GET" class="flex flex-wrap items-end gap-3">
        <input type="hidden" name="uuid" value="<?= htmlspecialchars($uuid) ?>">

        <!-- Date range -->
        <div class="flex items-center gap-2">
            <label class="text-xs text-gray-500 whitespace-nowrap">From</label>
            <input type="date" name="from" value="<?= htmlspecialchars($date_from ?? '') ?>"
                   class="border border-gray-300 rounded-lg px-2 py-1.5 text-xs text-gray-700 focus:ring-1 focus:ring-primary-500">
        </div>
        <div class="flex items-center gap-2">
            <label class="text-xs text-gray-500 whitespace-nowrap">To</label>
            <input type="date" name="to" value="<?= htmlspecialchars($date_to ?? '') ?>"
                   class="border border-gray-300 rounded-lg px-2 py-1.5 text-xs text-gray-700 focus:ring-1 focus:ring-primary-500">
        </div>

        <!-- Type filter -->
        <div class="flex items-center gap-1.5">
            <label class="text-xs text-gray-500">Type</label>
            <select name="type" class="border border-gray-300 rounded-lg px-2 py-1.5 text-xs text-gray-700 focus:ring-1 focus:ring-primary-500">
                <option value="all"      <?= $type_filter === 'all'      ? 'selected' : '' ?>>All</option>
                <option value="purchase" <?= $type_filter === 'purchase' ? 'selected' : '' ?>>Purchase Payments</option>
                <option value="transfer" <?= $type_filter === 'transfer' ? 'selected' : '' ?>>Internal Transfers</option>
            </select>
        </div>

        <button type="submit"
                class="px-3 py-1.5 bg-primary-600 text-white text-xs font-medium rounded-lg hover:bg-primary-700">
            <i class="fas fa-filter mr-1"></i>Apply
        </button>
        <?php if ($date_from || $date_to || $type_filter !== 'all'): ?>
        <a href="?uuid=<?= urlencode($uuid) ?>"
           class="px-3 py-1.5 border border-gray-300 text-gray-500 text-xs font-medium rounded-lg hover:bg-gray-50">
            Clear
        </a>
        <?php endif; ?>

        <?php if ($date_from || $date_to): ?>
        <span class="text-xs text-gray-400 ml-2">
            <?= $date_from ? date('d M Y', strtotime($date_from)) : '—' ?>
            →
            <?= $date_to   ? date('d M Y', strtotime($date_to))   : 'today' ?>
        </span>
        <?php endif; ?>
    </form>
</div>

<!-- ── Statement table ────────────────────────────────────────────────────── -->
<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full text-xs">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-100 text-gray-500 uppercase tracking-wide">
                    <th class="px-4 py-2.5 text-left w-24">Date</th>
                    <th class="px-4 py-2.5 text-left w-36">Voucher / Ref</th>
                    <th class="px-4 py-2.5 text-left">Supplier / Description</th>
                    <th class="px-4 py-2.5 text-left w-28">PO #</th>
                    <th class="px-4 py-2.5 text-left w-24">Type</th>
                    <th class="px-4 py-2.5 text-right w-28">In (৳)</th>
                    <th class="px-4 py-2.5 text-right w-28">Out (৳)</th>
                    <th class="px-4 py-2.5 text-right w-28">Balance (৳)</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
            <?php if (empty($transactions)): ?>
                <tr>
                    <td colspan="8" class="px-4 py-10 text-center text-gray-400">
                        <i class="fas fa-file-invoice-dollar text-3xl mb-2 block opacity-30"></i>
                        No transactions found<?= ($date_from || $date_to) ? ' for the selected period' : '' ?>.
                    </td>
                </tr>
            <?php else: ?>
            <?php
                $running = 0.0;
                foreach ($transactions as $txn):
                    $debit  = (float)$txn->debit_amount;
                    $credit = (float)$txn->credit_amount;
                    $change = $is_debit_normal ? ($debit - $credit) : ($credit - $debit);
                    $running = round($running + $change, 2);

                    $doc_type = $txn->related_document_type ?? '';
                    [$badge_class, $badge_label, $badge_icon] = $type_meta[$doc_type]
                        ?? ['bg-gray-100 text-gray-600', ucwords(str_replace('_', ' ', $doc_type)), 'fa-circle'];

                    $is_purchase  = $doc_type === 'purchase_payment_adnan';
                    $is_reversed  = (bool)($txn->is_reversed ?? false);
                    $row_bg       = $is_purchase ? 'hover:bg-purple-50' : 'hover:bg-gray-50';
                    if ($is_reversed) $row_bg .= ' opacity-60';
            ?>
                <tr class="<?= $row_bg ?> transition-colors">
                    <!-- Date -->
                    <td class="px-4 py-2.5 whitespace-nowrap text-gray-500">
                        <?= date('d M Y', strtotime($txn->transaction_date)) ?>
                    </td>

                    <!-- Voucher / reference -->
                    <td class="px-4 py-2.5 whitespace-nowrap font-mono">
                        <?php if ($is_purchase && $txn->payment_voucher_number): ?>
                            <a href="purchase_adnan_payment_receipt.php?id=<?= (int)$txn->payment_id ?>"
                               target="_blank"
                               class="text-purple-700 font-semibold hover:underline">
                                <?= htmlspecialchars($txn->payment_voucher_number) ?>
                            </a>
                            <?php if ($txn->bank_ref): ?>
                                <div class="text-gray-400 text-[10px] font-normal mt-0.5"><?= htmlspecialchars($txn->bank_ref) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-gray-500">JE-<?= (int)$txn->je_id ?></span>
                        <?php endif; ?>
                        <?php if ($is_reversed): ?>
                            <span class="ml-1 px-1 py-0.5 bg-red-100 text-red-600 rounded text-[9px] font-bold">REVERSED</span>
                        <?php endif; ?>
                    </td>

                    <!-- Supplier / description -->
                    <td class="px-4 py-2.5">
                        <?php if ($is_purchase && $txn->supplier_name): ?>
                            <span class="font-semibold text-gray-800"><?= htmlspecialchars($txn->supplier_name) ?></span>
                            <?php if ($txn->payment_method): ?>
                                <span class="ml-1.5 px-1 py-0.5 bg-gray-100 text-gray-500 rounded text-[9px]">
                                    <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $txn->payment_method))) ?>
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-gray-600"><?= htmlspecialchars($txn->je_description ?? '—') ?></span>
                        <?php endif; ?>
                    </td>

                    <!-- PO # -->
                    <td class="px-4 py-2.5 whitespace-nowrap">
                        <?php if ($is_purchase && $txn->po_number && $txn->purchase_order_id): ?>
                            <a href="purchase_adnan_view_po.php?id=<?= (int)$txn->purchase_order_id ?>"
                               class="text-sky-600 hover:underline font-mono font-medium">
                                <?= htmlspecialchars($txn->po_number) ?>
                            </a>
                        <?php elseif ($is_purchase && $txn->po_number): ?>
                            <span class="font-mono text-gray-600"><?= htmlspecialchars($txn->po_number) ?></span>
                        <?php else: ?>
                            <span class="text-gray-300">—</span>
                        <?php endif; ?>
                    </td>

                    <!-- Type badge -->
                    <td class="px-4 py-2.5 whitespace-nowrap">
                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold <?= $badge_class ?>">
                            <i class="fas <?= $badge_icon ?> text-[8px]"></i>
                            <?= $badge_label ?>
                        </span>
                    </td>

                    <!-- Debit (in) -->
                    <td class="px-4 py-2.5 text-right font-mono <?= $debit > 0 ? 'text-gray-800 font-medium' : 'text-gray-200' ?>">
                        <?= $debit > 0 ? number_format($debit, 2) : '—' ?>
                    </td>

                    <!-- Credit (out) -->
                    <td class="px-4 py-2.5 text-right font-mono <?= $credit > 0 ? 'text-red-600 font-medium' : 'text-gray-200' ?>">
                        <?= $credit > 0 ? number_format($credit, 2) : '—' ?>
                    </td>

                    <!-- Running balance -->
                    <td class="px-4 py-2.5 text-right font-mono font-bold <?= $running < 0 ? 'text-red-600' : 'text-gray-800' ?>">
                        <?= number_format($running, 2) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>

            <?php if (!empty($transactions)): ?>
            <tfoot>
                <tr class="bg-gray-50 border-t-2 border-gray-200 text-xs font-bold text-gray-700">
                    <td colspan="5" class="px-4 py-3 text-right text-gray-500 font-medium">
                        <?= count($transactions) ?> transactions
                        <?= ($date_from || $date_to) ? '· filtered period' : '· all time' ?>
                    </td>
                    <td class="px-4 py-3 text-right font-mono text-gray-800">৳<?= number_format($total_debit, 2) ?></td>
                    <td class="px-4 py-3 text-right font-mono text-red-600">৳<?= number_format($total_credit, 2) ?></td>
                    <td class="px-4 py-3 text-right font-mono <?= $ending_balance < 0 ? 'text-red-600' : 'text-gray-900' ?>">
                        ৳<?= number_format($ending_balance, 2) ?>
                    </td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php endif; // end if ($account) ?>

<?php include '../templates/footer.php'; ?>