<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-rampura', 'accounts-srg', 'accounts-demra'];
restrict_access($allowed_roles);

global $db;

// 1. Get Parameters
$account_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : null;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

if (!$account_id) {
    die("Invalid Account ID");
}

// 2. Fetch Account Details
$account = $db->query("SELECT * FROM chart_of_accounts WHERE id = ?", [$account_id])->first();

if (!$account) {
    die("Account not found");
}

// 3. Calculate Opening Balance
$opening_sql = "
    SELECT 
        IFNULL(SUM(tl.debit_amount), 0) as total_debit,
        IFNULL(SUM(tl.credit_amount), 0) as total_credit
    FROM transaction_lines tl
    JOIN journal_entries je ON tl.journal_entry_id = je.id
    WHERE tl.account_id = ? AND je.transaction_date < ?
";
$opening_res = $db->query($opening_sql, [$account_id, $date_from])->first();

$opening_balance = 0;
$normal_balance_type = strtolower($account->normal_balance);

if ($normal_balance_type == 'debit') {
    $opening_balance = $opening_res->total_debit - $opening_res->total_credit;
} else {
    $opening_balance = $opening_res->total_credit - $opening_res->total_debit;
}

// 4. Fetch Transactions
$trans_sql = "
    SELECT 
        tl.*,
        je.transaction_date,
        je.description as journal_description,
        je.related_document_type,
        je.related_document_id
    FROM transaction_lines tl
    JOIN journal_entries je ON tl.journal_entry_id = je.id
    WHERE tl.account_id = ? 
      AND je.transaction_date BETWEEN ? AND ?
    ORDER BY je.transaction_date ASC, je.id ASC
";
$transactions = $db->query($trans_sql, [$account_id, $date_from, $date_to])->results();

// 5. Generate CSV
$filename = 'Statement_' . preg_replace('/[^a-zA-Z0-9]/', '_', $account->name) . '_' . $date_to . '.csv';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF"); // BOM

// Header Info
fputcsv($output, ['Account Statement']);
fputcsv($output, ['Account:', $account->name . ' (' . ($account->account_number ?? 'N/A') . ')']);
fputcsv($output, ['Period:', "From $date_from To $date_to"]);
fputcsv($output, ['Normal Balance:', ucfirst($account->normal_balance)]);
fputcsv($output, []);

// Table Headers
fputcsv($output, ['Date', 'Description', 'Line Note', 'Reference', 'Debit', 'Credit', 'Running Balance']);

// Opening Balance Row
fputcsv($output, [
    $date_from,
    'Balance Brought Forward',
    '',
    '',
    '-',
    '-',
    number_format($opening_balance, 2, '.', '')
]);

// Transaction Rows
$running_balance = $opening_balance;
$total_debit = 0;
$total_credit = 0;

foreach ($transactions as $entry) {
    // Update Totals
    $total_debit += $entry->debit_amount;
    $total_credit += $entry->credit_amount;

    // Update Running Balance
    if ($normal_balance_type == 'debit') {
        $running_balance += ($entry->debit_amount - $entry->credit_amount);
    } else {
        $running_balance += ($entry->credit_amount - $entry->debit_amount);
    }

    fputcsv($output, [
        $entry->transaction_date,
        $entry->journal_description,
        $entry->description, // This is the line item description
        $entry->related_document_type ? ($entry->related_document_type . ' #' . $entry->related_document_id) : '',
        $entry->debit_amount > 0 ? number_format($entry->debit_amount, 2, '.', '') : '-',
        $entry->credit_amount > 0 ? number_format($entry->credit_amount, 2, '.', '') : '-',
        number_format($running_balance, 2, '.', '')
    ]);
}

// Closing Row
fputcsv($output, [
    $date_to,
    'Closing Balance',
    '',
    '',
    number_format($total_debit, 2, '.', ''),
    number_format($total_credit, 2, '.', ''),
    number_format($running_balance, 2, '.', '')
]);

fclose($output);
exit();