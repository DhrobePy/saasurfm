<?php
/** CSV export sibling of tax_statement.php — same computation, same convention as all_accounts_export.php / chart_account_statement_export.php (separate export file, not shared code). */
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra'];
restrict_access($allowed_roles);

global $db;

$settings_keys = ['company_legal_name', 'company_tin', 'company_bin', 'fiscal_year_start_month'];
$settings = [];
foreach ($settings_keys as $k) {
    $row = $db->query("SELECT value FROM settings WHERE name = ?", [$k])->first();
    $settings[$k] = $row->value ?? '';
}
$fy_start_month = (int)($settings['fiscal_year_start_month'] ?: 7);

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

$asset_types     = ['Bank', 'Petty Cash', 'Cash', 'Accounts Receivable', 'Other Current Asset', 'Fixed Asset'];
$liability_types = ['Accounts Payable', 'Credit Card', 'Loan', 'Other Liability'];
$equity_types     = ['Owner Equity'];
$revenue_types    = ['Revenue', 'Other Income'];
$expense_types     = ['Expense', 'Cost of Goods Sold', 'Other Expense'];

$pl_lines = []; $bs_lines = [];
$cumulative_net_profit = 0.0;
$total_income = 0.0; $total_cogs = 0.0; $total_opex = 0.0; $total_other_income = 0.0; $total_revenue = 0.0;
$total_assets = 0.0; $total_liabilities = 0.0; $total_owner_equity = 0.0;

foreach ($rows as $r) {
    $is_debit_normal = strtolower($r->normal_balance) === 'debit';
    $cum_debit  = (float)$r->opening_debit + (float)$r->period_debit;
    $cum_credit = (float)$r->opening_credit + (float)$r->period_credit;
    $cum_balance = $is_debit_normal ? ($cum_debit - $cum_credit) : ($cum_credit - $cum_debit);
    $period_balance = $is_debit_normal
        ? ((float)$r->period_debit - (float)$r->period_credit)
        : ((float)$r->period_credit - (float)$r->period_debit);

    if (in_array($r->account_type, $revenue_types, true)) {
        $cumulative_net_profit += $cum_balance;
        if (abs($period_balance) > 0.009) { $pl_lines[] = [$r->account_type, $r->name, $period_balance]; $total_income += $period_balance; if ($r->account_type === 'Revenue') $total_revenue += $period_balance; else $total_other_income += $period_balance; }
    } elseif (in_array($r->account_type, $expense_types, true)) {
        $cumulative_net_profit -= $cum_balance;
        if (abs($period_balance) > 0.009) {
            $pl_lines[] = [$r->account_type, $r->name, -$period_balance];
            if ($r->account_type === 'Cost of Goods Sold') $total_cogs += $period_balance; else $total_opex += $period_balance;
        }
    } elseif (in_array($r->account_type, $asset_types, true)) {
        if (abs($cum_balance) > 0.009) { $bs_lines[] = [$r->account_type, $r->name, $cum_balance]; $total_assets += $cum_balance; }
    } elseif (in_array($r->account_type, $liability_types, true)) {
        if (abs($cum_balance) > 0.009) { $bs_lines[] = [$r->account_type, $r->name, $cum_balance]; $total_liabilities += $cum_balance; }
    } elseif (in_array($r->account_type, $equity_types, true)) {
        if (abs($cum_balance) > 0.009) { $bs_lines[] = [$r->account_type, $r->name, $cum_balance]; $total_owner_equity += $cum_balance; }
    }
}
$gross_profit = $total_income - $total_cogs;
$net_profit_period = $gross_profit - $total_opex;
$total_equity = $total_owner_equity + $cumulative_net_profit;

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="Tax_Statement_Draft_' . $income_year_label . '.csv"');
$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF");

fputcsv($output, ['NBR Tax Statement - DRAFT - NOT FOR FILING']);
fputcsv($output, ['Company:', $settings['company_legal_name']]);
fputcsv($output, ['TIN:', $settings['company_tin'], 'BIN:', $settings['company_bin']]);
fputcsv($output, ['Income Year:', $income_year_label, 'Period:', $date_from . ' to ' . $date_to]);
fputcsv($output, ['Generated:', date('d M Y H:i')]);
fputcsv($output, []);

fputcsv($output, ['PROFIT & LOSS STATEMENT']);
fputcsv($output, ['Type', 'Account', 'Amount']);
foreach ($pl_lines as $l) { fputcsv($output, [$l[0], $l[1], number_format($l[2], 2, '.', '')]); }
fputcsv($output, []);
fputcsv($output, ['', 'Total Revenue + Other Income', number_format($total_income, 2, '.', '')]);
fputcsv($output, ['', 'Total COGS', number_format(-$total_cogs, 2, '.', '')]);
fputcsv($output, ['', 'Gross Profit', number_format($gross_profit, 2, '.', '')]);
fputcsv($output, ['', 'Total Operating Expenses', number_format(-$total_opex, 2, '.', '')]);
fputcsv($output, ['', 'NET PROFIT BEFORE TAX', number_format($net_profit_period, 2, '.', '')]);
fputcsv($output, []);

fputcsv($output, ['BALANCE SHEET as at ' . $date_to . ' (cumulative since ledger inception)']);
fputcsv($output, ['Type', 'Account', 'Amount']);
foreach ($bs_lines as $l) { fputcsv($output, [$l[0], $l[1], number_format($l[2], 2, '.', '')]); }
fputcsv($output, []);
fputcsv($output, ['', 'Total Assets', number_format($total_assets, 2, '.', '')]);
fputcsv($output, ['', 'Total Liabilities', number_format($total_liabilities, 2, '.', '')]);
fputcsv($output, ['', 'Owner Equity', number_format($total_owner_equity, 2, '.', '')]);
fputcsv($output, ['', 'Retained Earnings (all-time)', number_format($cumulative_net_profit, 2, '.', '')]);
fputcsv($output, ['', 'Total Equity', number_format($total_equity, 2, '.', '')]);
fputcsv($output, ['', 'Total Liabilities + Equity', number_format($total_liabilities + $total_equity, 2, '.', '')]);
fputcsv($output, []);

fputcsv($output, ['TAX COMPUTATION WORKSHEET - TO BE COMPLETED BY ACCOUNTANT']);
fputcsv($output, ['Net Accounting Profit', number_format($net_profit_period, 2, '.', '')]);
fputcsv($output, ['Add: Disallowed/inadmissible expenses', 'TO BE REVIEWED']);
fputcsv($output, ['Less: Tax depreciation (Third Schedule)', 'NO FIXED-ASSET REGISTER - SUPPLY SEPARATELY']);
fputcsv($output, ['Other adjustments', 'TO BE REVIEWED']);
fputcsv($output, ['Estimated Taxable Income', 'TO BE COMPLETED']);

fclose($output);
exit();
