<?php
// new_ufmhrm/admin/export_handler.php

require_once '../core/init.php';

if (!is_admin_logged_in()) {
    http_response_code(403);
    exit('Access Denied');
}

$reportType = $_GET['report_type'] ?? '';
$month = $_GET['month'] ?? ''; // Expects 'YYYY-MM' format

if (empty($reportType) || empty($month)) {
    http_response_code(400);
    exit('Invalid report parameters.');
}

// Set headers to force a CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="Payroll_Report_' . $reportType . '_' . $month . '.csv"');

// Create a file pointer connected to the output stream
$output = fopen('php://output', 'w');

// --- REPORT LOGIC ---

switch ($reportType) {
    case 'monthly_detailed':
        // 1. Month-wise total list of salary disbursed
        fputcsv($output, [
            'Employee ID', 'Employee Name', 'Department', 'Position', 'Pay Period', 'Status',
            'Basic Salary', 'House Allowance', 'Transport Allowance', 'Medical Allowance', 'Other Allowances', 'Gross Salary',
            'Absent Days', 'Absence Deduction', 'Salary Advance', 'Loan Installment',
            'Provident Fund', 'Tax Deduction', 'Other Deductions', 'Total Deductions', 'Net Salary'
        ]);

        $sql = "
            SELECT e.id as emp_id, CONCAT(e.first_name, ' ', e.last_name) as emp_name, d.name as dept_name, pos.name as pos_name, 
                   DATE_FORMAT(p.pay_period_end, '%Y-%m') as pay_period, p.status, pd.*
            FROM payrolls p
            JOIN employees e ON p.employee_id = e.id
            JOIN payroll_details pd ON p.id = pd.payroll_id
            LEFT JOIN positions pos ON e.position_id = pos.id
            LEFT JOIN departments d ON pos.department_id = d.id
            WHERE p.status = 'paid' AND DATE_FORMAT(p.pay_period_end, '%Y-%m') = ?
            ORDER BY emp_name
        ";
        $data = $db->query($sql, [$month])->results();

        foreach ($data as $row) {
            fputcsv($output, [
                $row->emp_id, $row->emp_name, $row->dept_name, $row->pos_name, $row->pay_period, $row->status,
                $row->basic_salary, $row->house_allowance, $row->transport_allowance, $row->medical_allowance, $row->other_allowances, $row->gross_salary,
                $row->absent_days, $row->absence_deduction, $row->salary_advance_deduction, $row->loan_installment_deduction,
                $row->provident_fund, $row->tax_deduction, $row->other_deductions, $row->total_deductions, $row->net_salary
            ]);
        }
        break;

    case 'address_wise':
        // 2. Address wise Reports
        fputcsv($output, ['Address', 'Number of Employees', 'Total Net Salary Disbursed']);
        
        $sql = "
            SELECT 
                e.address,
                COUNT(p.id) as employee_count,
                SUM(p.net_salary) as total_net
            FROM payrolls p
            JOIN employees e ON p.employee_id = e.id
            WHERE p.status = 'paid' AND DATE_FORMAT(p.pay_period_end, '%Y-%m') = ?
            GROUP BY e.address
            HAVING e.address IS NOT NULL AND e.address != ''
            ORDER BY e.address
        ";
        $data = $db->query($sql, [$month])->results();

        foreach ($data as $row) {
            fputcsv($output, [$row->address, $row->employee_count, $row->total_net]);
        }
        break;

    // You can add more report types here in the future
    // case 'department_wise': ... break;
}

fclose($output);
exit();