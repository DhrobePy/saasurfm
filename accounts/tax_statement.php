<?php
/**
 * NBR Corporate Income Tax Statement — DRAFT (Jul 2026).
 *
 * Pulls Profit & Loss + Balance Sheet directly from the real double-entry
 * ledger (journal_entries/transaction_lines), the same opening/period/closing
 * balance method as chart_account_statement.php (the one existing report
 * that's actually correct against the live schema — admin/balance_sheet.php
 * and admin/accounting*.php reference columns that don't exist in this DB
 * and were NOT used as a reference for this page).
 *
 * This is explicitly a DRAFT for a qualified tax consultant/chartered
 * accountant to review before filing — it does not compute a final tax
 * liability. Two things this system has no data for at all: a fixed-asset
 * register (so NBR Third Schedule depreciation cannot be computed) and any
 * classification of "disallowed"/inadmissible expenses under the Income Tax
 * Act. Both are left as explicit blanks, never guessed at.
 *
 * P&L accounts (Revenue/Other Income/COGS/Expense/Other Expense) are scoped
 * to the income-year period only. Balance Sheet accounts (Asset/Liability/
 * Equity types) use a cumulative opening+period balance, because this ledger
 * has no formal year-end closing entries — "Retained Earnings" on the
 * Balance Sheet is therefore the cumulative net profit since the ledger's
 * inception, not just this income year (both figures are shown, labelled).
 */
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra'];
restrict_access($allowed_roles);

global $db;
$pageTitle = 'Tax Statement (Draft)';

// ─── Company / fiscal settings ──────────────────────────────────────────
$settings_keys = ['company_legal_name', 'company_tin', 'company_bin', 'company_registered_address', 'fiscal_year_start_month'];
$settings = [];
foreach ($settings_keys as $k) {
    $row = $db->query("SELECT value FROM settings WHERE name = ?", [$k])->first();
    $settings[$k] = $row->value ?? '';
}
$fy_start_month = (int)($settings['fiscal_year_start_month'] ?: 7);

// ─── Income year selection ──────────────────────────────────────────────
// "fy_end_year" = the calendar year the income year ENDS in (e.g. 2026 for
// a Jul-2025..Jun-2026 year). Defaults to whichever income year today falls in.
$today = new DateTime();
$default_end_year = ($fy_start_month === 1)
    ? (int)$today->format('Y')
    : ((int)$today->format('n') >= $fy_start_month ? (int)$today->format('Y') + 1 : (int)$today->format('Y'));
$fy_end_year = isset($_GET['fy_end_year']) ? max(2000, (int)$_GET['fy_end_year']) : $default_end_year;

if ($fy_start_month === 1) {
    $date_from = "{$fy_end_year}-01-01";
    $date_to   = "{$fy_end_year}-12-31";
    $income_year_label = (string)$fy_end_year;
} else {
    $start_year = $fy_end_year - 1;
    $end_month  = $fy_start_month - 1 === 0 ? 12 : $fy_start_month - 1;
    $date_from  = sprintf('%04d-%02d-01', $start_year, $fy_start_month);
    $date_to    = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $fy_end_year, $end_month)));
    $income_year_label = "{$start_year}-{$fy_end_year}";
}
$assessment_year_label = ($fy_start_month === 1) ? ($fy_end_year + 1) : ($fy_end_year . '-' . ($fy_end_year + 1));

// ─── Pull opening (before date_from) + period (date_from..date_to) movement
//     for every account in one pass, mirroring chart_account_statement.php's
//     method but for the whole chart of accounts at once. ──────────────────
$rows = $db->query(
    "SELECT coa.id, coa.account_number, coa.name, coa.account_type, coa.normal_balance,
            IFNULL(SUM(CASE WHEN je.transaction_date < ? THEN tl.debit_amount ELSE 0 END), 0)  AS opening_debit,
            IFNULL(SUM(CASE WHEN je.transaction_date < ? THEN tl.credit_amount ELSE 0 END), 0) AS opening_credit,
            IFNULL(SUM(CASE WHEN je.transaction_date BETWEEN ? AND ? THEN tl.debit_amount ELSE 0 END), 0)  AS period_debit,
            IFNULL(SUM(CASE WHEN je.transaction_date BETWEEN ? AND ? THEN tl.credit_amount ELSE 0 END), 0) AS period_credit
     FROM chart_of_accounts coa
     LEFT JOIN transaction_lines tl ON tl.account_id = coa.id
     LEFT JOIN journal_entries je ON je.id = tl.journal_entry_id
     GROUP BY coa.id, coa.account_number, coa.name, coa.account_type, coa.normal_balance
     ORDER BY coa.account_type, coa.account_number",
    [$date_from, $date_from, $date_from, $date_to, $date_from, $date_to]
)->results();

// Same 5-group mapping used identically in accounts/new_transaction.php and accounts/chart_of_accounts.php.
$asset_types     = ['Bank', 'Petty Cash', 'Cash', 'Accounts Receivable', 'Other Current Asset', 'Fixed Asset'];
$liability_types = ['Accounts Payable', 'Credit Card', 'Loan', 'Other Liability'];
$equity_types     = ['Owner Equity'];
$revenue_types    = ['Revenue', 'Other Income'];
$expense_types     = ['Expense', 'Cost of Goods Sold', 'Other Expense'];

$pl_revenue = []; $pl_other_income = []; $pl_cogs = []; $pl_expense = [];
$bs_assets = []; $bs_liabilities = []; $bs_equity = [];
$cumulative_net_profit = 0.0; // for Retained Earnings — since ledger inception through date_to

foreach ($rows as $r) {
    $is_debit_normal = strtolower($r->normal_balance) === 'debit';

    // Cumulative closing balance through date_to (opening + period), used for
    // Balance Sheet accounts AND for the all-time Retained Earnings figure.
    $cum_debit  = (float)$r->opening_debit + (float)$r->period_debit;
    $cum_credit = (float)$r->opening_credit + (float)$r->period_credit;
    $cum_balance = $is_debit_normal ? ($cum_debit - $cum_credit) : ($cum_credit - $cum_debit);

    // Period-only movement, used for the P&L (this income year only).
    $period_balance = $is_debit_normal
        ? ((float)$r->period_debit - (float)$r->period_credit)
        : ((float)$r->period_credit - (float)$r->period_debit);

    if (in_array($r->account_type, $revenue_types, true)) {
        $cumulative_net_profit += $cum_balance; // revenue adds to profit
        if (abs($period_balance) > 0.009) {
            $target = $r->account_type === 'Other Income' ? 'pl_other_income' : 'pl_revenue';
            $$target[] = ['name' => $r->name, 'amount' => $period_balance];
        }
    } elseif (in_array($r->account_type, $expense_types, true)) {
        $cumulative_net_profit -= $cum_balance; // expense/COGS subtracts from profit
        if (abs($period_balance) > 0.009) {
            $target = $r->account_type === 'Cost of Goods Sold' ? 'pl_cogs' : 'pl_expense';
            $$target[] = ['name' => $r->name, 'amount' => $period_balance];
        }
    } elseif (in_array($r->account_type, $asset_types, true)) {
        if (abs($cum_balance) > 0.009) $bs_assets[] = ['name' => $r->name, 'type' => $r->account_type, 'amount' => $cum_balance];
    } elseif (in_array($r->account_type, $liability_types, true)) {
        if (abs($cum_balance) > 0.009) $bs_liabilities[] = ['name' => $r->name, 'type' => $r->account_type, 'amount' => $cum_balance];
    } elseif (in_array($r->account_type, $equity_types, true)) {
        if (abs($cum_balance) > 0.009) $bs_equity[] = ['name' => $r->name, 'amount' => $cum_balance];
    }
}

$sum = fn($arr) => array_sum(array_column($arr, 'amount'));
$total_revenue      = $sum($pl_revenue);
$total_other_income  = $sum($pl_other_income);
$total_income        = $total_revenue + $total_other_income;
$total_cogs          = $sum($pl_cogs);
$gross_profit        = $total_income - $total_cogs;
$total_opex          = $sum($pl_expense);
$net_profit_period    = $gross_profit - $total_opex;

$total_assets        = $sum($bs_assets);
$total_liabilities    = $sum($bs_liabilities);
$total_owner_equity  = $sum($bs_equity);
$retained_earnings   = $cumulative_net_profit; // all-time, through date_to
$total_equity        = $total_owner_equity + $retained_earnings;
$balance_check        = $total_assets - ($total_liabilities + $total_equity);

require_once '../templates/header.php';
?>
<div class="max-w-5xl mx-auto px-4 py-6">

    <div class="no-print flex items-center justify-between mb-4 flex-wrap gap-2">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Tax Statement — DRAFT</h1>
            <p class="text-sm text-gray-500 mt-1">For NBR corporate income tax return preparation. Review with your accountant before filing.</p>
        </div>
        <div class="flex gap-2">
            <a href="tax_settings.php" class="px-3 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm"><i class="fas fa-gear mr-1"></i>Company Settings</a>
            <a href="tax_statement_export.php?fy_end_year=<?php echo (int)$fy_end_year; ?>" class="px-3 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm"><i class="fas fa-file-excel mr-1"></i>Export CSV</a>
            <button onclick="window.print()" class="px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm"><i class="fas fa-print mr-1"></i>Print</button>
        </div>
    </div>

    <form method="GET" class="no-print bg-white rounded-xl shadow-sm p-4 mb-4 flex items-end gap-3">
        <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">Income Year (ending)</label>
            <select name="fy_end_year" class="px-3 py-2 border border-gray-300 rounded-lg text-sm" onchange="this.form.submit()">
                <?php for ($y = $default_end_year + 1; $y >= $default_end_year - 5; $y--): ?>
                <option value="<?php echo $y; ?>" <?php echo $y === $fy_end_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="text-xs text-gray-500 pb-2">Period: <strong><?php echo date('d M Y', strtotime($date_from)); ?></strong> to <strong><?php echo date('d M Y', strtotime($date_to)); ?></strong></div>
    </form>

    <div class="mb-4 p-4 rounded-xl bg-red-50 border-2 border-red-300 text-red-900 text-sm">
        <strong><i class="fas fa-triangle-exclamation mr-1"></i>DRAFT — NOT FOR FILING.</strong>
        Generated directly from this system's accounting ledger. It has not been reviewed by a tax professional.
        Two things this system has no data for at all and could NOT include: a fixed-asset register (so NBR Third Schedule
        depreciation is not computed here) and any classification of disallowed/inadmissible expenses under the Income Tax Act.
        Have your accountant/chartered accountant complete the Tax Computation Worksheet below and verify every figure before submission to NBR.
    </div>

    <div class="bg-white rounded-xl shadow-sm p-6 mb-4 text-center">
        <h2 class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($settings['company_legal_name'] ?: 'Company legal name not set'); ?></h2>
        <?php if (!$settings['company_legal_name'] || !$settings['company_tin'] || !$settings['company_bin']): ?>
        <p class="text-xs text-red-600 mt-1">Some registration details are missing — <a href="tax_settings.php" class="underline">set them here</a>.</p>
        <?php endif; ?>
        <p class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars($settings['company_registered_address']); ?></p>
        <p class="text-sm text-gray-600 mt-1">
            TIN: <strong><?php echo htmlspecialchars($settings['company_tin'] ?: '—'); ?></strong>
            &nbsp;|&nbsp; BIN: <strong><?php echo htmlspecialchars($settings['company_bin'] ?: '—'); ?></strong>
        </p>
        <p class="text-sm text-gray-800 mt-3 font-semibold">
            Income Year: <?php echo htmlspecialchars($income_year_label); ?>
            &nbsp;|&nbsp; Assessment Year: <?php echo htmlspecialchars($assessment_year_label); ?>
        </p>
    </div>

    <!-- ═══ Profit & Loss ═══ -->
    <div class="bg-white rounded-xl shadow-sm p-6 mb-4">
        <h2 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-chart-line mr-2 text-blue-600"></i>Profit &amp; Loss Statement — Income Year <?php echo htmlspecialchars($income_year_label); ?></h2>
        <table class="min-w-full text-sm">
            <?php
            function pl_section($title, $items, $bold_total = false) {
                echo "<tr><td colspan='2' class='pt-3 pb-1 font-bold text-gray-700'>{$title}</td></tr>";
                foreach ($items as $it) {
                    echo "<tr><td class='pl-4 py-0.5 text-gray-600'>" . htmlspecialchars($it['name']) . "</td>"
                       . "<td class='py-0.5 text-right'>৳" . number_format($it['amount'], 2) . "</td></tr>";
                }
            }
            pl_section('Revenue', $pl_revenue);
            pl_section('Other Income', $pl_other_income);
            ?>
            <tr class="border-t border-gray-200"><td class="py-1 font-bold">Total Income</td><td class="py-1 text-right font-bold">৳<?php echo number_format($total_income, 2); ?></td></tr>
            <?php pl_section('Cost of Goods Sold', $pl_cogs); ?>
            <tr class="border-t border-gray-200"><td class="py-1 font-bold">Total COGS</td><td class="py-1 text-right font-bold">(৳<?php echo number_format($total_cogs, 2); ?>)</td></tr>
            <tr class="border-t-2 border-gray-400"><td class="py-2 font-bold text-blue-800">Gross Profit</td><td class="py-2 text-right font-bold text-blue-800">৳<?php echo number_format($gross_profit, 2); ?></td></tr>
            <?php pl_section('Operating Expenses', $pl_expense); ?>
            <tr class="border-t border-gray-200"><td class="py-1 font-bold">Total Operating Expenses</td><td class="py-1 text-right font-bold">(৳<?php echo number_format($total_opex, 2); ?>)</td></tr>
            <tr class="border-t-2 border-gray-700"><td class="py-2 font-bold text-lg">Net Profit Before Tax</td><td class="py-2 text-right font-bold text-lg">৳<?php echo number_format($net_profit_period, 2); ?></td></tr>
        </table>
    </div>

    <!-- ═══ Balance Sheet ═══ -->
    <div class="bg-white rounded-xl shadow-sm p-6 mb-4">
        <h2 class="text-lg font-bold text-gray-800 mb-1"><i class="fas fa-scale-balanced mr-2 text-purple-600"></i>Balance Sheet — as at <?php echo date('d M Y', strtotime($date_to)); ?></h2>
        <p class="text-xs text-gray-500 mb-4">Cumulative balances since the ledger's inception — this system does not perform formal year-end closing entries, so Retained Earnings below reflects all-time results, not just this income year.</p>
        <div class="grid md:grid-cols-2 gap-6">
            <div>
                <table class="min-w-full text-sm">
                    <tr><td colspan="2" class="pb-1 font-bold text-gray-700">Assets</td></tr>
                    <?php foreach ($bs_assets as $it): ?>
                    <tr><td class="pl-4 py-0.5 text-gray-600"><?php echo htmlspecialchars($it['name']); ?></td><td class="py-0.5 text-right">৳<?php echo number_format($it['amount'], 2); ?></td></tr>
                    <?php endforeach; ?>
                    <tr class="border-t-2 border-gray-400"><td class="py-2 font-bold">Total Assets</td><td class="py-2 text-right font-bold">৳<?php echo number_format($total_assets, 2); ?></td></tr>
                </table>
            </div>
            <div>
                <table class="min-w-full text-sm">
                    <tr><td colspan="2" class="pb-1 font-bold text-gray-700">Liabilities</td></tr>
                    <?php foreach ($bs_liabilities as $it): ?>
                    <tr><td class="pl-4 py-0.5 text-gray-600"><?php echo htmlspecialchars($it['name']); ?></td><td class="py-0.5 text-right">৳<?php echo number_format($it['amount'], 2); ?></td></tr>
                    <?php endforeach; ?>
                    <tr class="border-t border-gray-200"><td class="py-1 font-bold">Total Liabilities</td><td class="py-1 text-right font-bold">৳<?php echo number_format($total_liabilities, 2); ?></td></tr>

                    <tr><td colspan="2" class="pt-3 pb-1 font-bold text-gray-700">Equity</td></tr>
                    <?php foreach ($bs_equity as $it): ?>
                    <tr><td class="pl-4 py-0.5 text-gray-600"><?php echo htmlspecialchars($it['name']); ?></td><td class="py-0.5 text-right">৳<?php echo number_format($it['amount'], 2); ?></td></tr>
                    <?php endforeach; ?>
                    <tr><td class="pl-4 py-0.5 text-gray-600">Retained Earnings (all-time)</td><td class="py-0.5 text-right">৳<?php echo number_format($retained_earnings, 2); ?></td></tr>
                    <tr class="border-t border-gray-200"><td class="py-1 font-bold">Total Equity</td><td class="py-1 text-right font-bold">৳<?php echo number_format($total_equity, 2); ?></td></tr>

                    <tr class="border-t-2 border-gray-400"><td class="py-2 font-bold">Total Liabilities + Equity</td><td class="py-2 text-right font-bold">৳<?php echo number_format($total_liabilities + $total_equity, 2); ?></td></tr>
                </table>
            </div>
        </div>
        <?php if (abs($balance_check) > 1): ?>
        <div class="mt-3 p-3 rounded-lg bg-amber-50 border border-amber-300 text-amber-800 text-xs">
            <i class="fas fa-triangle-exclamation mr-1"></i>Assets do not equal Liabilities + Equity by ৳<?php echo number_format(abs($balance_check), 2); ?> —
            this usually means an unbalanced or partially-reversed journal entry somewhere in the ledger. Worth checking before relying on these figures.
        </div>
        <?php endif; ?>
    </div>

    <!-- ═══ Tax Computation Worksheet ═══ -->
    <div class="bg-white rounded-xl shadow-sm p-6 mb-4 border-2 border-dashed border-amber-300">
        <h2 class="text-lg font-bold text-gray-800 mb-1"><i class="fas fa-calculator mr-2 text-amber-600"></i>Tax Computation Worksheet — TO BE COMPLETED BY ACCOUNTANT</h2>
        <p class="text-xs text-gray-500 mb-4">Starting point only. Every line below except "Net Accounting Profit" needs professional review — nothing here is computed by guessing.</p>
        <table class="min-w-full text-sm">
            <tr><td class="py-1">Net Accounting Profit (from P&amp;L above)</td><td class="py-1 text-right font-semibold">৳<?php echo number_format($net_profit_period, 2); ?></td></tr>
            <tr><td class="py-1 text-gray-500">Add: Disallowed / inadmissible expenses (Income Tax Act) — <em>to be reviewed</em></td><td class="py-1 text-right text-gray-400">________</td></tr>
            <tr><td class="py-1 text-gray-500">Less: Tax depreciation per Third Schedule — <em>no fixed-asset register in system; supply separately</em></td><td class="py-1 text-right text-gray-400">________</td></tr>
            <tr><td class="py-1 text-gray-500">Other adjustments — <em>to be reviewed</em></td><td class="py-1 text-right text-gray-400">________</td></tr>
            <tr class="border-t-2 border-gray-400"><td class="py-2 font-bold">Estimated Taxable Income</td><td class="py-2 text-right font-bold text-gray-400">________</td></tr>
        </table>
        <div class="mt-4 no-print flex items-center gap-2">
            <label class="text-sm text-gray-700">Illustrative only — tax rate:</label>
            <input type="number" id="taxRateInput" step="0.1" placeholder="e.g. 27.5" class="w-24 px-2 py-1 border border-gray-300 rounded text-sm">
            <span class="text-sm text-gray-700">% × Net Accounting Profit (no adjustments) =</span>
            <span id="taxRateResult" class="text-sm font-bold text-amber-700">—</span>
        </div>
        <p class="text-xs text-gray-400 mt-2">This illustrative figure ignores every adjustment above (depreciation, disallowed expenses) and is NOT the actual tax liability. Confirm the applicable corporate tax rate for this company's category with your tax consultant — rates are set annually by the Finance Act and vary by company type.</p>
    </div>

    <div class="text-center text-xs text-gray-400 mb-6">
        DRAFT — prepared from system ledger data on <?php echo date('d M Y, h:i A'); ?>. Not reviewed by a tax professional. Do not file without accountant sign-off.
    </div>
</div>

<style>
@media print {
    .no-print { display: none !important; }
}
</style>
<script>
document.getElementById('taxRateInput')?.addEventListener('input', function() {
    const rate = parseFloat(this.value);
    const result = document.getElementById('taxRateResult');
    if (isNaN(rate)) { result.textContent = '—'; return; }
    const amount = <?php echo (float)$net_profit_period; ?> * (rate / 100);
    result.textContent = '৳' + amount.toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2});
});
</script>
<?php require_once '../templates/footer.php'; ?>
