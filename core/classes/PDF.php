<?php
// new_ufmhrm/core/classes/PDF.php

/**
 * Class PDF
 * Generates HTML-based reports for printing.
 * This class builds an HTML page and uses JavaScript (window.print())
 * to open the browser's print-to-PDF dialog.
 */
class PDF
{
    private $pdo; // Should be the $db object instance

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function generate_employee_report($format = 'html') {
        $employee_handler = new Employee($this->pdo);
        $employees = $employee_handler->get_all();
        
        if ($format === 'pdf') {
            return $this->generate_html_to_pdf($this->build_employee_html($employees), 'Employee_Report');
        }
        
        return $this->build_employee_html($employees);
    }

    public function generate_attendance_report($format = 'html') {
        $attendance_handler = new Attendance($this->pdo);
        $attendance = $attendance_handler->get_today_attendance();
        
        if ($format === 'pdf') {
            return $this->generate_html_to_pdf($this->build_attendance_html($attendance), 'Attendance_Report');
        }
        
        return $this->build_attendance_html($attendance);
    }

    public function generate_payroll_report($format = 'html') {
        $payroll_handler = new Payroll($this->pdo);
        $payrolls = $payroll_handler->get_payroll_history();
        
        if ($format === 'pdf') {
            return $this->generate_html_to_pdf($this->build_payroll_html($payrolls), 'Payroll_Report');
        }
        
        return $this->build_payroll_html($payrolls);
    }

    

    private function build_attendance_html($attendance) {
        $html = '<h2>Attendance Report - ' . date('M d, Y') . '</h2>';
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width:100%; border-collapse: collapse;">';
        $html .= '<thead><tr style="background-color: #f8f9fa;">';
        $html .= '<th>Employee</th><th>Clock In</th><th>Clock Out</th><th>Status</th>';
        $html .= '</tr></thead><tbody>';
        
        foreach ($attendance as $record) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($record['first_name'] . ' ' . $record['last_name']) . '</td>';
            $html .= '<td>' . ($record['clock_in'] ? date('H:i:s', strtotime($record['clock_in'])) : 'N/A') . '</td>';
            $html .= '<td>' . ($record['clock_out'] ? date('H:i:s', strtotime($record['clock_out'])) : 'N/A') . '</td>';
            $html .= '<td>' . ucfirst($record['status']) . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>';
        return $html;
    }

    private function build_payroll_html($payrolls) {
        $html = '<h2>Payroll Report</h2>';
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width:100%; border-collapse: collapse;">';
        $html .= '<thead><tr style="background-color: #f8f9fa;">';
        $html .= '<th>Employee</th><th>Pay Period</th><th>Gross Salary</th><th>Deductions</th><th>Net Salary</th>';
        $html .= '</tr></thead><tbody>';
        
        foreach ($payrolls as $payroll) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($payroll['first_name'] . ' ' . $payroll['last_name']) . '</td>';
            $html .= '<td>' . date('M d', strtotime($payroll['pay_period_start'])) . ' - ' . date('M d, Y', strtotime($payroll['pay_period_end'])) . '</td>';
            $html .= '<td>$' . number_format($payroll['gross_salary'], 2) . '</td>';
            $html .= '<td>$' . number_format($payroll['deductions'], 2) . '</td>';
            $html .= '<td>$' . number_format($payroll['net_salary'], 2) . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>';
        return $html;
    }

    private function generate_html_to_pdf($html, $filename) {
        // Simple HTML to PDF conversion using browser print functionality
        $full_html = '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>' . $filename . '</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { padding: 8px; text-align: left; border: 1px solid #ddd; }
                th { background-color: #f8f9fa; font-weight: bold; }
                h2 { color: #333; margin-bottom: 20px; }
                @media print {
                    body { margin: 0; }
                    .no-print { display: none; }
                }
            </style>
        </head>
        <body>
            <div class="no-print" style="margin-bottom: 20px;">
                <button onclick="window.print()">Print PDF</button>
                <button onclick="window.close()">Close</button>
            </div>
            ' . $html . '
            <script>
                // Auto-print when page loads
                window.onload = function() {
                    setTimeout(function() {
                        window.print();
                    }, 500);
                };
            </script>
        </body>
        </html>';
        
        return $full_html;
    }

    public function generate_salary_certificate($employee_id) {
        $employee_handler = new Employee($this->pdo);
        $employee = $employee_handler->get_by_id($employee_id);
        
        if (!$employee) {
            return false;
        }
        
        $html = '<div style="text-align: center; margin-bottom: 30px;">';
        $html .= '<h1>SALARY CERTIFICATE</h1>';
        $html .= '</div>';
        
        $html .= '<p>Date: ' . date('F d, Y') . '</p>';
        $html .= '<p><strong>TO WHOM IT MAY CONCERN</strong></p>';
        
        $html .= '<p>This is to certify that <strong>' . htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) . '</strong> ';
        $html .= 'is employed with our organization as <strong>' . htmlspecialchars($employee['position_name'] ?? 'Employee') . '</strong> ';
        $html .= 'since D' . date('F d, Y', strtotime($employee['hire_date'])) . '</strong>.</p>';
        
        $html .= '<p>His/Her current monthly salary is <strong>$' . number_format($employee['base_salary'], 2) . '</strong>.</p>';
        
        $html .= '<p>This certificate is issued upon his/her request for official purposes.</p>';
        
        $html .= '<div style="margin-top: 50px;">';
        $html .= '<p>Sincerely,</p>';
        $html .= '<p><strong>HR Department</strong></p>';
        $html .= '<p>Company Name</p>';
        $html .= '</div>';
        
        return $this->generate_html_to_pdf($html, 'Salary_Certificate_' . $employee['first_name'] . '_' . $employee['last_name']);
    }

    // =================================================================
    // === NEW FUNCTIONS ADDED HERE ===
    // =================================================================

    /**
     * PUBLIC method to generate a product report.
     * This is called by product/products.php
     *
     * @param array $products The data from fetch_export_data().
     * @return string The full, printable HTML page.
     */
    public function generate_product_report($products) {
        // Build the product HTML table
        $html = $this->build_product_html($products);
        
        // Pass the HTML to your existing private PDF generator
        return $this->generate_html_to_pdf($html, 'Product_Price_List_Report');
    }

    /**
     * PRIVATE method to build the HTML table for products.
     *
     * @param array $products The product data.
     * @return string The HTML table.
     */
    private function build_product_html($products) {
        $html = '<h2>Product Price List Report - ' . date('M d, Y') . '</h2>';
        $html .= '<p>This report shows all currently active prices. Stock quantities are not included.</p>';
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width:100%; border-collapse: collapse;">';
        $html .= '<thead><tr style="background-color: #f8f9fa;">';
        $html .= '<th>Product Name</th>';
        $html .= '<th>SKU</th>';
        $html .= '<th>Variant</th>';
        $html .= '<th>Grade</th>';
        $html .= '<th>Unit</th>';
        $html .= '<th>Factory/Branch</th>';
        $html .= '<th>Active Price (BDT)</th>';
        $html .= '<th>Effective Date</th>';
        $html .= '</tr></thead><tbody>';
        
        foreach ($products as $product) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($product->base_name) . '</td>';
            $html .= '<td>' . htmlspecialchars($product->sku) . '</td>';
            $html .= '<td>' . htmlspecialchars($product->weight_variant) . '</td>';
            $html .= '<td>' . htmlspecialchars($product->grade) . '</td>';
            $html .= '<td>' . htmlspecialchars($product->unit_of_measure) . '</td>';
            $html .= '<td>' . htmlspecialchars($product->branch_name) . '</td>';
            $html .= '<td style="text-align: right;">' . number_format($product->unit_price, 2) . '</td>';
            $html .= '<td>' . date('M d, Y', strtotime($product->effective_date)) . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>';
        return $html;
    }

} // <-- This is the final closing brace for the class

