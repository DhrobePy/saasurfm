<?php
require_once '../core/init.php';

$allowed_roles = [
    'Superadmin', 'admin', 'Accounts',
    'accounts-srg', 'accounts-demra',
    'accountspos-demra', 'accountspos-srg',
    'sales-srg', 'sales-demra', 'sales-other', 'collector'
];
restrict_access($allowed_roles);
global $db;

if (!isset($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
    $_SESSION['error_flash'] = 'Invalid customer ID.';
    header('Location: index.php');
    exit();
}
$customer_id = (int)$_GET['id'];

$customer = $db->query("SELECT * FROM customers WHERE id = ?", [$customer_id])->first();
if (!$customer) {
    $_SESSION['error_flash'] = 'Customer not found.';
    header('Location: index.php');
    exit();
}

$current_role = $_SESSION['user_role'] ?? '';
$is_admin     = in_array($current_role, ['Superadmin', 'admin']);

// True current balance: initial_due + non-OB debits - credits.
// OB entries excluded so initial_due is never double-counted regardless of
// whether the OB row stored debit_amount=initial_due or debit_amount=0.
$bal_totals = $db->query(
    "SELECT COALESCE(SUM(debit_amount), 0) AS td,
            COALESCE(SUM(credit_amount), 0) AS tc
     FROM customer_ledger WHERE customer_id = ? AND reference_type != 'initial_due'",
    [$customer_id]
)->first();
$true_current_balance = (float)($customer->initial_due ?? 0)
                      + (float)($bal_totals->td ?? 0)
                      - (float)($bal_totals->tc ?? 0);

// Date filter (GET params; default = all time)
$date_from = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'])
    ? $_GET['from'] : null;
$date_to   = isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'])
    ? $_GET['to'] : null;

// Build ledger query
$ledger_sql = "SELECT cl.*,
    CASE
        WHEN cl.reference_type = 'credit_orders'      THEN (SELECT order_number   FROM credit_orders      WHERE id = cl.reference_id)
        WHEN cl.reference_type = 'customer_payments'  THEN (SELECT payment_number FROM customer_payments  WHERE id = cl.reference_id)
        ELSE NULL
    END AS reference_doc_number
    FROM customer_ledger cl
    WHERE cl.customer_id = ?";
$ledger_params = [$customer_id];

if ($date_from) { $ledger_sql .= " AND cl.transaction_date >= ?"; $ledger_params[] = $date_from; }
if ($date_to)   { $ledger_sql .= " AND cl.transaction_date <= ?"; $ledger_params[] = $date_to; }
$ledger_sql .= " ORDER BY cl.transaction_date ASC, cl.id ASC";

$ledger_entries = $db->query($ledger_sql, $ledger_params)->results();

// Check whether a real opening_balance ledger entry exists (suppresses the synthetic duplicate row)
$has_ob_entry = $db->query(
    "SELECT id FROM customer_ledger WHERE customer_id = ? AND transaction_type = 'opening_balance' LIMIT 1",
    [$customer_id]
)->first();

// Credit / financial figures — use true ledger balance, not stale cache
$available_credit   = 0;
$utilization_percent = 0;
if ($customer->customer_type === 'Credit') {
    $available_credit = (float)$customer->credit_limit - $true_current_balance;
    if ($customer->credit_limit > 0) {
        $utilization_percent = min(100, ($true_current_balance / $customer->credit_limit) * 100);
    } elseif ($true_current_balance > 0) {
        $utilization_percent = 100;
    }
}

// Ledger running totals for the filtered range
$total_debit  = array_sum(array_column((array)$ledger_entries, 'debit_amount'));
$total_credit = array_sum(array_column((array)$ledger_entries, 'credit_amount'));

// Compute per-row running balance from raw amounts (never reads stored balance_after).
// OB entries are skipped in the running total; initial_due is the permanent baseline.
// When a date filter is active, pre-compute the balance up to date_from so per-row
// balances reflect absolute position in the full account history.
if ($date_from) {
    $pre = $db->query(
        "SELECT COALESCE(SUM(debit_amount), 0) AS td, COALESCE(SUM(credit_amount), 0) AS tc
         FROM customer_ledger WHERE customer_id = ? AND transaction_date < ? AND reference_type != 'initial_due'",
        [$customer_id, $date_from]
    )->first();
    $row_running = (float)($customer->initial_due ?? 0) + (float)($pre->td ?? 0) - (float)($pre->tc ?? 0);
} else {
    $row_running = (float)($customer->initial_due ?? 0);
}
foreach ($ledger_entries as $entry) {
    if (($entry->reference_type ?? '') !== 'initial_due') {
        $row_running += (float)$entry->debit_amount - (float)$entry->credit_amount;
    }
    $entry->computed_balance = round($row_running, 2);
}
$closing_balance = !empty($ledger_entries) ? end($ledger_entries)->computed_balance : $row_running;

// Type → badge styling
$type_badge = [
    'opening_balance' => ['bg-amber-100 text-amber-800',  'Opening Balance'],
    'invoice'         => ['bg-blue-100 text-blue-800',    'Invoice'],
    'payment'         => ['bg-green-100 text-green-800',  'Payment'],
    'advance_payment' => ['bg-teal-100 text-teal-800',    'Advance'],
    'adjustment'      => ['bg-purple-100 text-purple-700','Adjustment'],
    'credit_note'     => ['bg-orange-100 text-orange-700','Credit Note'],
    'debit_note'      => ['bg-red-100 text-red-700',      'Debit Note'],
];

$pageTitle = htmlspecialchars($customer->name) . ' — Account Statement';
require_once '../templates/header.php';
?>

<!-- ── Page header ──────────────────────────────────────────────────────── -->
<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars($customer->name) ?></h1>
        <p class="text-sm text-gray-500">Account Statement · Customer #<?= $customer_id ?></p>
    </div>
    <div class="flex gap-2">
        <a href="manage.php?id=<?= $customer_id ?>"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-primary-600 text-white text-sm font-medium rounded-lg hover:bg-primary-700 shadow-sm">
            <i class="fas fa-edit text-xs"></i> Edit
        </a>
        <a href="index.php"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-gray-300 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-50">
            <i class="fas fa-arrow-left text-xs"></i> Back
        </a>
    </div>
</div>

<!-- ── Quick Actions ─────────────────────────────────────────────────────── -->
<div class="flex flex-wrap gap-2 mb-4">
    <a href="<?= url('cr/customer_payment.php?customer_id=' . $customer_id) ?>"
       class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-green-600 text-white text-sm font-medium rounded-lg hover:bg-green-700 shadow-sm transition-colors">
        <i class="fas fa-hand-holding-usd text-xs"></i> Collect Payment
    </a>
    <a href="<?= url('cr/partial_delivery.php') ?>"
       class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 shadow-sm transition-colors">
        <i class="fas fa-truck text-xs"></i> Partial Delivery
    </a>
    <a href="<?= url('cr/returns.php') ?>"
       class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-orange-500 text-white text-sm font-medium rounded-lg hover:bg-orange-600 shadow-sm transition-colors">
        <i class="fas fa-undo-alt text-xs"></i> Return Goods
    </a>
    <?php if ($is_admin): ?>
    <button onclick="openAdjModal()"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-purple-600 text-white text-sm font-medium rounded-lg hover:bg-purple-700 shadow-sm transition-colors">
        <i class="fas fa-sliders-h text-xs"></i> Adjustment
    </button>
    <?php endif; ?>
</div>

<!-- ── Compact profile + financials ─────────────────────────────────────── -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">

    <!-- Profile card -->
    <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex items-start gap-4">
        <!-- Avatar -->
        <div class="flex-shrink-0">
            <?php if ($customer->photo_url): ?>
                <img src="<?= url($customer->photo_url) ?>"
                     class="h-14 w-14 rounded-full object-cover shadow"
                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                     alt="">
                <span class="h-14 w-14 rounded-full bg-primary-100 text-primary-700 font-bold text-2xl items-center justify-center shadow"
                      style="display:none;">
                    <?= strtoupper(substr(trim($customer->name), 0, 1)) ?>
                </span>
            <?php else: ?>
                <span class="h-14 w-14 rounded-full bg-primary-100 text-primary-700 font-bold text-2xl flex items-center justify-center shadow">
                    <?= strtoupper(substr(trim($customer->name), 0, 1)) ?>
                </span>
            <?php endif; ?>
        </div>

        <!-- Details -->
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap mb-1">
                <span class="font-bold text-gray-900 text-base"><?= htmlspecialchars($customer->name) ?></span>
                <?php if ($customer->business_name): ?>
                    <span class="text-gray-400 text-sm">· <?= htmlspecialchars($customer->business_name) ?></span>
                <?php endif; ?>
            </div>
            <div class="flex flex-wrap gap-x-5 gap-y-1 text-xs text-gray-500 mb-2">
                <?php if ($customer->phone_number): ?>
                    <span><i class="fas fa-phone mr-1 opacity-60"></i><?= htmlspecialchars($customer->phone_number) ?></span>
                <?php endif; ?>
                <?php if ($customer->email): ?>
                    <span><i class="fas fa-envelope mr-1 opacity-60"></i><?= htmlspecialchars($customer->email) ?></span>
                <?php endif; ?>
                <?php if ($customer->business_address): ?>
                    <span><i class="fas fa-map-marker-alt mr-1 opacity-60"></i><?= htmlspecialchars($customer->business_address) ?></span>
                <?php endif; ?>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <?php if ($customer->customer_type === 'Credit'): ?>
                    <span class="px-2 py-0.5 bg-blue-100 text-blue-800 rounded-full text-xs font-semibold">
                        <i class="fas fa-credit-card mr-1 opacity-70"></i>Credit
                    </span>
                <?php else: ?>
                    <span class="px-2 py-0.5 bg-gray-100 text-gray-700 rounded-full text-xs font-semibold">POS</span>
                <?php endif; ?>
                <?php
                    $status_map = [
                        'active'      => 'bg-green-100 text-green-800',
                        'inactive'    => 'bg-yellow-100 text-yellow-800',
                        'blacklisted' => 'bg-red-100 text-red-800',
                    ];
                    $sc = $status_map[$customer->status] ?? 'bg-gray-100 text-gray-700';
                ?>
                <span class="px-2 py-0.5 <?= $sc ?> rounded-full text-xs font-semibold">
                    <?= ucfirst($customer->status) ?>
                </span>
                <?php if ($customer->initial_due > 0): ?>
                    <span class="px-2 py-0.5 bg-amber-50 text-amber-700 border border-amber-200 rounded-full text-xs">
                        Opening balance: ৳<?= number_format($customer->initial_due, 2) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Financial summary -->
    <?php if ($customer->customer_type === 'Credit'): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex flex-col justify-between">
        <div class="grid grid-cols-3 gap-2 text-center mb-3">
            <div>
                <p class="text-xs text-gray-400 uppercase tracking-wide">Limit</p>
                <p class="text-lg font-extrabold text-gray-700">৳<?= number_format($customer->credit_limit) ?></p>
            </div>
            <div>
                <p class="text-xs <?= $true_current_balance > 0 ? 'text-red-500' : 'text-green-500' ?> uppercase tracking-wide">Due</p>
                <p class="text-lg font-extrabold <?= $true_current_balance > 0 ? 'text-red-600' : 'text-green-600' ?>">
                    ৳<?= number_format(abs($true_current_balance)) ?>
                </p>
            </div>
            <div>
                <p class="text-xs text-gray-400 uppercase tracking-wide">Available</p>
                <p class="text-lg font-extrabold <?= $available_credit >= 0 ? 'text-green-600' : 'text-red-600' ?>">
                    <?= $available_credit < 0 ? '-' : '' ?>৳<?= number_format(abs($available_credit)) ?>
                </p>
            </div>
        </div>
        <div>
            <div class="flex justify-between text-xs text-gray-400 mb-1">
                <span>Credit Utilization</span>
                <span class="font-bold <?= $utilization_percent > 80 ? 'text-red-600' : 'text-gray-600' ?>">
                    <?= number_format($utilization_percent, 1) ?>%
                </span>
            </div>
            <?php
                $bar_color = $utilization_percent > 100 ? 'bg-red-600'
                           : ($utilization_percent > 85 ? 'bg-red-500'
                           : ($utilization_percent > 60 ? 'bg-yellow-500' : 'bg-primary-500'));
            ?>
            <div class="w-full bg-gray-200 rounded-full h-2.5">
                <div class="<?= $bar_color ?> h-2.5 rounded-full transition-all duration-500"
                     style="width:<?= min(100, $utilization_percent) ?>%"></div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex items-center justify-center text-gray-400 text-sm">
        POS customer — no credit account
    </div>
    <?php endif; ?>
</div>

<!-- ── Account Statement ─────────────────────────────────────────────────── -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">

    <!-- Section header + date filter -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-5 py-4 border-b border-gray-100 bg-gray-50">
        <div>
            <h2 class="font-bold text-gray-800 text-base">Account Statement</h2>
            <?php if ($date_from || $date_to): ?>
                <p class="text-xs text-gray-500 mt-0.5">
                    Filtered:
                    <?= $date_from ? date('d M Y', strtotime($date_from)) : 'start' ?>
                    →
                    <?= $date_to   ? date('d M Y', strtotime($date_to))   : 'today' ?>
                    · <a href="?id=<?= $customer_id ?>" class="text-primary-600 hover:underline">Clear filter</a>
                </p>
            <?php else: ?>
                <p class="text-xs text-gray-400 mt-0.5">All transactions</p>
            <?php endif; ?>
        </div>
        <form method="GET" action="" class="flex items-center gap-2 flex-wrap">
            <input type="hidden" name="id" value="<?= $customer_id ?>">
            <input type="date" name="from" value="<?= htmlspecialchars($date_from ?? '') ?>"
                   class="border border-gray-300 rounded-lg px-2 py-1 text-xs text-gray-700 focus:ring-1 focus:ring-primary-500">
            <span class="text-gray-400 text-xs">to</span>
            <input type="date" name="to" value="<?= htmlspecialchars($date_to ?? '') ?>"
                   class="border border-gray-300 rounded-lg px-2 py-1 text-xs text-gray-700 focus:ring-1 focus:ring-primary-500">
            <button type="submit"
                    class="px-3 py-1 bg-primary-600 text-white text-xs font-medium rounded-lg hover:bg-primary-700">
                Filter
            </button>
        </form>
    </div>

    <!-- Ledger table -->
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-100 text-xs text-gray-500 uppercase tracking-wide">
                    <th class="px-4 py-2.5 text-left w-24">Date</th>
                    <th class="px-4 py-2.5 text-left">Reference</th>
                    <th class="px-4 py-2.5 text-left w-28">Type</th>
                    <th class="px-4 py-2.5 text-left">Description</th>
                    <th class="px-4 py-2.5 text-right w-32">Debit (Due)</th>
                    <th class="px-4 py-2.5 text-right w-32">Credit (Paid)</th>
                    <th class="px-4 py-2.5 text-right w-32">Balance</th>
                    <th class="px-4 py-2.5 text-center w-48">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">

                <?php
                // Show synthetic opening balance ONLY when no real ledger entry exists for it.
                // After ledger_repair runs, opening_balance entries exist — suppress the duplicate.
                if (!$has_ob_entry && $customer->customer_type === 'Credit' && ($customer->initial_due ?? 0) > 0):
                ?>
                <tr class="bg-amber-50">
                    <td class="px-4 py-3 text-amber-600 text-xs">—</td>
                    <td class="px-4 py-3">
                        <span class="px-1.5 py-0.5 bg-amber-100 text-amber-700 rounded text-xs font-semibold">Opening Bal.</span>
                    </td>
                    <td class="px-4 py-3 text-amber-700 text-xs">Initial Due</td>
                    <td class="px-4 py-3 text-amber-700 text-xs">Previous balance brought forward</td>
                    <td class="px-4 py-3 text-right font-semibold text-amber-700">৳<?= number_format($customer->initial_due, 2) ?></td>
                    <td class="px-4 py-3 text-right text-gray-300">—</td>
                    <td class="px-4 py-3 text-right font-bold text-amber-700">৳<?= number_format($customer->initial_due, 2) ?></td>
                    <td class="px-4 py-3"></td>
                </tr>
                <?php endif; ?>

                <?php if (empty($ledger_entries)): ?>
                <tr>
                    <td colspan="8" class="px-4 py-10 text-center text-gray-400">No transactions found.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($ledger_entries as $entry):
                    [$badge_class, $badge_label] = $type_badge[$entry->transaction_type]
                        ?? ['bg-gray-100 text-gray-600', ucwords(str_replace('_', ' ', $entry->transaction_type))];
                    $is_invoice  = $entry->transaction_type === 'invoice';
                    $is_payment  = in_array($entry->transaction_type, ['payment', 'advance_payment']);
                    $ref_display = $entry->reference_doc_number ?? $entry->invoice_number ?? $entry->reference_id;
                    $has_link    = in_array($entry->reference_type, ['credit_orders', 'customer_payments'])
                                   && $entry->reference_id;
                ?>
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-4 py-2.5 whitespace-nowrap text-xs text-gray-500">
                        <?= date('d M Y', strtotime($entry->transaction_date)) ?>
                    </td>
                    <td class="px-4 py-2.5 whitespace-nowrap text-xs">
                        <?php if ($has_link): ?>
                            <?php if ($entry->reference_type === 'credit_orders'): ?>
                                <a href="<?= url('cr/credit_invoice_print.php?order_id=' . $entry->reference_id) ?>"
                                   target="_blank"
                                   class="text-primary-600 hover:underline font-medium open-transaction-modal"
                                   data-ref-id="<?= (int)$entry->reference_id ?>"
                                   data-ref-type="<?= htmlspecialchars($entry->reference_type) ?>"
                                   data-ref-number="<?= htmlspecialchars($ref_display ?? '') ?>">
                                    <?= htmlspecialchars($ref_display ?? '—') ?>
                                </a>
                            <?php else: ?>
                                <a href="#" class="text-primary-600 hover:underline font-medium open-transaction-modal"
                                   data-ref-id="<?= (int)$entry->reference_id ?>"
                                   data-ref-type="<?= htmlspecialchars($entry->reference_type) ?>"
                                   data-ref-number="<?= htmlspecialchars($ref_display ?? '') ?>">
                                    <?= htmlspecialchars($ref_display ?? '—') ?>
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-gray-500"><?= htmlspecialchars($ref_display ?? '—') ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-2.5 whitespace-nowrap">
                        <span class="px-1.5 py-0.5 rounded text-xs font-semibold <?= $badge_class ?>">
                            <?= $badge_label ?>
                        </span>
                    </td>
                    <td class="px-4 py-2.5 text-xs text-gray-600 max-w-xs truncate" title="<?= htmlspecialchars($entry->description) ?>">
                        <?= htmlspecialchars($entry->description) ?>
                    </td>
                    <td class="px-4 py-2.5 text-right text-xs <?= $entry->debit_amount > 0 ? 'font-semibold text-gray-800' : 'text-gray-300' ?>">
                        <?= $entry->debit_amount > 0 ? '৳' . number_format($entry->debit_amount, 2) : '—' ?>
                    </td>
                    <td class="px-4 py-2.5 text-right text-xs <?= $entry->credit_amount > 0 ? 'font-semibold text-green-600' : 'text-gray-300' ?>">
                        <?= $entry->credit_amount > 0 ? '৳' . number_format($entry->credit_amount, 2) : '—' ?>
                    </td>
                    <td class="px-4 py-2.5 text-right text-xs font-bold <?= $entry->computed_balance > 0 ? 'text-gray-900' : 'text-green-700' ?>">
                        ৳<?= number_format($entry->computed_balance, 2) ?>
                    </td>
                    <td class="px-4 py-2.5 text-center whitespace-nowrap">
                        <?php if ($is_invoice && $entry->reference_id): ?>
                        <div class="flex items-center justify-center gap-1">
                            <a href="<?= url('cr/customer_payment.php?customer_id=' . $customer_id) ?>"
                               title="Collect Payment"
                               class="px-1.5 py-1 rounded bg-green-100 text-green-700 hover:bg-green-600 hover:text-white transition-colors text-[11px] font-medium">
                                <i class="fas fa-hand-holding-usd"></i>
                            </a>
                            <a href="<?= url('cr/partial_delivery.php?order_id=' . (int)$entry->reference_id) ?>"
                               title="Partial Delivery"
                               class="px-1.5 py-1 rounded bg-blue-100 text-blue-700 hover:bg-blue-600 hover:text-white transition-colors text-[11px] font-medium">
                                <i class="fas fa-truck"></i>
                            </a>
                            <a href="<?= url('cr/returns.php?order_id=' . (int)$entry->reference_id) ?>"
                               title="Return Goods"
                               class="px-1.5 py-1 rounded bg-orange-100 text-orange-700 hover:bg-orange-600 hover:text-white transition-colors text-[11px] font-medium">
                                <i class="fas fa-undo-alt"></i>
                            </a>
                            <?php if ($is_admin): ?>
                            <button onclick="openAdjModal(<?= (int)$entry->reference_id ?>)"
                                    title="Adjustment"
                                    class="px-1.5 py-1 rounded bg-purple-100 text-purple-700 hover:bg-purple-600 hover:text-white transition-colors text-[11px] font-medium">
                                <i class="fas fa-sliders-h"></i>
                            </button>
                            <button onclick="deleteOrder(<?= (int)$entry->reference_id ?>, '<?= htmlspecialchars($ref_display ?? 'this order', ENT_QUOTES) ?>')"
                                    title="Delete Invoice"
                                    class="px-1.5 py-1 rounded bg-red-100 text-red-600 hover:bg-red-600 hover:text-white transition-colors text-[11px] font-medium">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <span class="text-gray-300 text-xs">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>

            <!-- Totals footer -->
            <?php if (!empty($ledger_entries)): ?>
            <tfoot>
                <tr class="bg-gray-50 border-t-2 border-gray-200 text-xs font-bold text-gray-700">
                    <td colspan="4" class="px-4 py-3 text-right text-gray-500 font-medium">
                        <?= count($ledger_entries) ?> transactions
                    </td>

                    <td class="px-4 py-3 text-right text-gray-800">৳<?= number_format($total_debit, 2) ?></td>
                    <td class="px-4 py-3 text-right text-green-600">৳<?= number_format($total_credit, 2) ?></td>
                    <td class="px-4 py-3 text-right <?= $closing_balance > 0 ? 'text-red-700' : 'text-green-700' ?>">
                        ৳<?= number_format($closing_balance, 2) ?>
                    </td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>

    <!-- Footer link to full statement page -->
    <div class="px-5 py-3 border-t border-gray-100 bg-gray-50 flex items-center justify-between">
        <span class="text-xs text-gray-400">
            Closing balance: <strong class="text-gray-700">৳<?= number_format($closing_balance, 2) ?></strong>
        </span>
        <a href="<?= url('cr/customer_ledger.php?customer_id=' . $customer_id) ?>"
           class="text-xs text-primary-600 hover:underline font-medium">
            Full Statement with Date Range <i class="fas fa-external-link-alt ml-1 text-[10px]"></i>
        </a>
    </div>
</div>

<!-- ── Adjustment modal ──────────────────────────────────────────────────── -->
<?php if ($is_admin): ?>
<div id="adjModal" class="fixed z-50 inset-0 overflow-y-auto hidden" role="dialog">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-gray-600 bg-opacity-60" onclick="closeAdjModal()"></div>
        <div class="relative bg-white rounded-xl shadow-xl w-full max-w-md z-10 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-base font-bold text-gray-800">
                    <i class="fas fa-sliders-h mr-2 text-purple-500"></i>Manual Adjustment
                </h3>
                <button onclick="closeAdjModal()" class="text-gray-400 hover:text-gray-600 text-xl leading-none">×</button>
            </div>
            <form id="adjForm" class="px-6 py-4 space-y-4">
                <input type="hidden" name="customer_id" value="<?= $customer_id ?>">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                    <select name="adj_type" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-purple-500">
                        <option value="credit_note">Credit Note (reduces balance)</option>
                        <option value="debit_note">Debit Note (increases balance)</option>
                        <option value="adjustment">General Adjustment</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount (৳)</label>
                    <input type="number" name="amount" min="0.01" step="0.01" required
                           class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-purple-500"
                           placeholder="0.00">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                    <input type="date" name="adj_date" value="<?= date('Y-m-d') ?>" required
                           class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-purple-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reason / Note</label>
                    <input type="text" name="note" required
                           class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-purple-500"
                           placeholder="e.g. Overcharge reversal, damage deduction...">
                </div>
                <div id="adjError" class="hidden text-sm text-red-600 bg-red-50 border border-red-200 rounded p-2"></div>
            </form>
            <div class="px-6 py-3 border-t border-gray-100 bg-gray-50 flex justify-end gap-2">
                <button onclick="closeAdjModal()" class="px-4 py-2 border rounded-lg text-sm text-gray-600 hover:bg-gray-100">Cancel</button>
                <button onclick="submitAdj()"
                        class="px-4 py-2 bg-purple-600 text-white text-sm font-medium rounded-lg hover:bg-purple-700">
                    <i class="fas fa-check mr-1"></i> Apply Adjustment
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Transaction detail modal ─────────────────────────────────────────── -->
<div id="transactionModal" class="fixed z-50 inset-0 overflow-y-auto hidden" role="dialog" aria-modal="true">
    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div id="modalOverlay" class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
        <div class="inline-block align-bottom bg-white rounded-xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-3xl sm:w-full">
            <div class="bg-white px-6 pt-5 pb-4">
                <div class="sm:flex sm:items-start">
                    <div class="mt-1 text-left w-full">
                        <h3 class="text-xl font-bold text-gray-900 mb-3" id="modalTitle">Loading…</h3>
                        <div id="modalContent">
                            <div class="flex justify-center items-center h-40">
                                <i class="fas fa-spinner fa-spin text-3xl text-primary-500"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-gray-50 px-6 py-3 flex justify-end border-t border-gray-100">
                <button id="closeModalButton"
                        class="px-4 py-1.5 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-100">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal        = document.getElementById('transactionModal');
    const modalTitle   = document.getElementById('modalTitle');
    const modalContent = document.getElementById('modalContent');
    const csrfToken    = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    function openModal(refId, refType, refNumber) {
        modalTitle.textContent = 'Details — ' + refNumber;
        modal.classList.remove('hidden');
        $.ajax({
            url: '../cr/ajax_handler.php',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ action: 'get_transaction_details', ref_id: refId, ref_type: refType, csrf_token: csrfToken }),
            headers: { 'X-CSRF-TOKEN': csrfToken },
            dataType: 'json',
            success: function (res) {
                if (res.success && res.html) {
                    modalContent.innerHTML = res.html;
                } else {
                    modalContent.innerHTML = '<p class="text-red-500 p-4 text-center">' + (res.error || 'Failed to load.') + '</p>';
                }
            },
            error: function (xhr) {
                const msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : xhr.statusText;
                modalContent.innerHTML = '<p class="text-red-500 p-4 text-center">Error: ' + msg + '</p>';
            }
        });
    }

    function closeModal() {
        modal.classList.add('hidden');
        modalTitle.textContent = 'Loading…';
        modalContent.innerHTML = '<div class="flex justify-center items-center h-40"><i class="fas fa-spinner fa-spin text-3xl text-primary-500"></i></div>';
    }

    document.querySelectorAll('.open-transaction-modal').forEach(el => {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            openModal(this.dataset.refId, this.dataset.refType, this.dataset.refNumber);
        });
    });

    document.getElementById('closeModalButton').addEventListener('click', closeModal);
    document.getElementById('modalOverlay').addEventListener('click', closeModal);
});
</script>

<script>
// ── Delete invoice (admin only) ───────────────────────────────────────────
function deleteOrder(orderId, label) {
    if (!confirm('Delete invoice ' + label + '?\n\nThis will reverse the invoice, update the customer balance, and remove all related ledger entries.\n\nThis action cannot be undone.')) return;
    const fd = new FormData();
    fd.append('order_id', orderId);
    fetch('<?= url('cr/delete_order.php') ?>', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                location.reload();
            } else {
                alert('Delete failed: ' + (res.message || 'Unknown error'));
            }
        })
        .catch(() => alert('Request failed. Please try again.'));
}

// ── Adjustment modal (admin only) ─────────────────────────────────────────
function openAdjModal(orderId) {
    <?php if ($is_admin): ?>
    document.getElementById('adjModal').classList.remove('hidden');
    if (orderId) {
        document.querySelector('#adjForm input[name="customer_id"]').dataset.orderId = orderId;
    }
    <?php endif; ?>
}
function closeAdjModal() {
    <?php if ($is_admin): ?>
    document.getElementById('adjModal').classList.add('hidden');
    document.getElementById('adjForm').reset();
    document.getElementById('adjForm').querySelector('input[name="adj_date"]').value = '<?= date('Y-m-d') ?>';
    document.getElementById('adjError').classList.add('hidden');
    <?php endif; ?>
}
function submitAdj() {
    <?php if ($is_admin): ?>
    const form   = document.getElementById('adjForm');
    const errBox = document.getElementById('adjError');
    const data   = Object.fromEntries(new FormData(form).entries());
    errBox.classList.add('hidden');

    if (!data.amount || parseFloat(data.amount) <= 0) {
        errBox.textContent = 'Please enter a valid amount.'; errBox.classList.remove('hidden'); return;
    }
    if (!data.note.trim()) {
        errBox.textContent = 'Please enter a reason.'; errBox.classList.remove('hidden'); return;
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    const payload = {
        action:      'create_adjustment',
        customer_id: parseInt(data.customer_id),
        adj_type:    data.adj_type,
        amount:      parseFloat(data.amount),
        adj_date:    data.adj_date,
        note:        data.note.trim(),
        csrf_token:  csrfToken,
    };

    fetch('<?= url('cr/ajax_handler.php') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            closeAdjModal();
            location.reload();
        } else {
            errBox.textContent = res.error || 'Failed to save adjustment.';
            errBox.classList.remove('hidden');
        }
    })
    .catch(() => { errBox.textContent = 'Request failed.'; errBox.classList.remove('hidden'); });
    <?php endif; ?>
}
</script>

<?php require_once '../templates/footer.php'; ?>