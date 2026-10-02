<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra'];
restrict_access($allowed_roles);

global $db;

// Get parameters
$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : null;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

if (!$customer_id) {
    die("Invalid customer ID");
}

// Fetch Customer Data
$customer = $db->query(
    "SELECT name, phone_number, business_name, initial_due FROM customers WHERE id = ?",
    [$customer_id]
)->first();

if (!$customer) {
    die("Customer not found");
}
$initial_due = (float)($customer->initial_due ?? 0);

// Opening balance — computed live from raw debit/credit sums, NEVER from the
// stored balance_after column. The old query here was `MAX(balance_after)`,
// which grabs whatever the highest balance ever recorded before date_from
// happened to be (not the actual balance AT date_from), and additionally
// trusts a column already confirmed to drift after historical edits/deletes
// (a deletion recomputes balance_after going forward from that point, but
// MAX() can still latch onto a stale pre-recompute peak). This is the exact
// fix already applied to the main Customer Ledger page (cr/customer_ledger.php)
// — this export file was simply missed at the time.
// initial_due-type rows excluded from the aggregate (added explicitly instead)
// so it's never double-counted.
$agg_before = $db->query(
    "SELECT COALESCE(SUM(debit_amount), 0) AS td, COALESCE(SUM(credit_amount), 0) AS tc
     FROM customer_ledger
     WHERE customer_id = ? AND transaction_date < ? AND reference_type != 'initial_due'",
    [$customer_id, $date_from]
)->first();
$opening_balance = $initial_due + (float)($agg_before->td ?? 0) - (float)($agg_before->tc ?? 0);

// Fetch Transactions — initial_due rows excluded (already folded into the
// opening balance above), same convention as the main ledger page.
$entries = $db->query(
    "SELECT transaction_date, transaction_type, description, invoice_number, debit_amount, credit_amount, balance_after
     FROM customer_ledger
     WHERE customer_id = ?
     AND transaction_date BETWEEN ? AND ?
     AND reference_type != 'initial_due'
     ORDER BY transaction_date ASC, id ASC",
    [$customer_id, $date_from, $date_to]
)->results();

// Set CSV Headers
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="Ledger_' . preg_replace('/[^a-zA-Z0-9]/', '_', $customer->name) . '_' . $date_to . '.csv"');

$output = fopen('php://output', 'w');

// Add BOM for Excel UTF-8 compatibility
fwrite($output, "\xEF\xBB\xBF");

// Header Information
fputcsv($output, ['Customer Name:', $customer->name]);
fputcsv($output, ['Phone:', $customer->phone_number]);
fputcsv($output, ['Business:', $customer->business_name]);
fputcsv($output, ['Period:', "From $date_from To $date_to"]);
fputcsv($output, []); // Empty line

// Table Headers
fputcsv($output, ['Date', 'Type', 'Description', 'Ref/Invoice', 'Debit', 'Credit', 'Balance']);

// Opening Balance Row
fputcsv($output, [
    $date_from,
    'Opening Balance',
    'Balance Brought Forward',
    '',
    '-',
    '-',
    number_format($opening_balance, 2, '.', '')
]);

// Transactions — running balance computed live (opening_balance + cumulative
// debit/credit), same reason as above: never trust stored balance_after.
$total_debit = 0;
$total_credit = 0;
$running_balance = $opening_balance;

foreach ($entries as $entry) {
    $total_debit += $entry->debit_amount;
    $total_credit += $entry->credit_amount;
    $running_balance += (float)$entry->debit_amount - (float)$entry->credit_amount;

    fputcsv($output, [
        $entry->transaction_date,
        ucwords(str_replace('_', ' ', $entry->transaction_type)),
        $entry->description,
        $entry->invoice_number,
        $entry->debit_amount > 0 ? number_format($entry->debit_amount, 2, '.', '') : '-',
        $entry->credit_amount > 0 ? number_format($entry->credit_amount, 2, '.', '') : '-',
        number_format($running_balance, 2, '.', '')
    ]);
}
$closing_balance = $running_balance;

// Closing Balance Row
fputcsv($output, [
    $date_to,
    'Closing Balance',
    'Balance Carried Forward',
    '',
    number_format($total_debit, 2, '.', ''),
    number_format($total_credit, 2, '.', ''),
    number_format($closing_balance, 2, '.', '')
]);

fclose($output);
exit();
?>