<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-rampura', 'accounts-srg', 'accounts-demra'];
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
    "SELECT name, phone_number, business_name FROM customers WHERE id = ?",
    [$customer_id]
)->first();

if (!$customer) {
    die("Customer not found");
}

// Fetch Opening Balance
$opening = $db->query(
    "SELECT COALESCE(MAX(balance_after), 0) as balance
     FROM customer_ledger
     WHERE customer_id = ? AND transaction_date < ?",
    [$customer_id, $date_from]
)->first();

$opening_balance = $opening ? (float)$opening->balance : 0.00;

// Fetch Transactions
$entries = $db->query(
    "SELECT transaction_date, transaction_type, description, invoice_number, debit_amount, credit_amount, balance_after 
     FROM customer_ledger 
     WHERE customer_id = ? 
     AND transaction_date BETWEEN ? AND ?
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

// Transactions
$total_debit = 0;
$total_credit = 0;
$closing_balance = $opening_balance;

foreach ($entries as $entry) {
    $total_debit += $entry->debit_amount;
    $total_credit += $entry->credit_amount;
    $closing_balance = $entry->balance_after; // Ledger stores running balance, so use it

    fputcsv($output, [
        $entry->transaction_date,
        ucwords(str_replace('_', ' ', $entry->transaction_type)),
        $entry->description,
        $entry->invoice_number,
        $entry->debit_amount > 0 ? number_format($entry->debit_amount, 2, '.', '') : '-',
        $entry->credit_amount > 0 ? number_format($entry->credit_amount, 2, '.', '') : '-',
        number_format($entry->balance_after, 2, '.', '')
    ]);
}

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