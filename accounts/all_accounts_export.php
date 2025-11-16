<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-rampura', 'accounts-srg', 'accounts-demra'];
restrict_access($allowed_roles);

global $db;

// 1. Fetch Data
$query = "
    SELECT 
        coa.account_number AS account_code, 
        coa.name AS account_name,
        coa.account_type,                   
        coa.normal_balance,                 
        IFNULL(SUM(tl.debit_amount), 0) AS total_debit, 
        IFNULL(SUM(tl.credit_amount), 0) AS total_credit
    FROM 
        chart_of_accounts coa
    LEFT JOIN 
        transaction_lines tl ON coa.id = tl.account_id
    WHERE 
        coa.account_type != 'Bank'
    GROUP BY 
        coa.id, coa.account_number, coa.name, coa.account_type, coa.normal_balance
    ORDER BY 
        coa.account_number ASC
";

$accounts = $db->query($query)->results();

// 2. Set Headers for CSV Download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="Chart_of_Accounts_Summary_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');

// Add BOM for Excel
fwrite($output, "\xEF\xBB\xBF");

// 3. Output CSV Content
fputcsv($output, ['Account Summary Report (Non-Bank)']);
fputcsv($output, ['Generated on:', date('d M Y H:i')]);
fputcsv($output, []); // Spacer

// Column Headers
fputcsv($output, ['Account Code', 'Account Name', 'Type', 'Normal Balance', 'Total Debit', 'Total Credit', 'Net Balance']);

// Data Rows
foreach ($accounts as $account) {
    // Calculate Balance
    $balance = 0;
    if (strtolower($account->normal_balance) == 'debit') {
        $balance = $account->total_debit - $account->total_credit;
    } else {
        $balance = $account->total_credit - $account->total_debit;
    }

    fputcsv($output, [
        $account->account_code,
        $account->account_name,
        $account->account_type,
        $account->normal_balance,
        number_format($account->total_debit, 2, '.', ''),
        number_format($account->total_credit, 2, '.', ''),
        number_format($balance, 2, '.', '')
    ]);
}

fclose($output);
exit();