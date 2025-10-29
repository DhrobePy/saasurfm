<?php
require_once __DIR__ . '/../core/init.php';

if (!is_logged_in()) {
    header('Location: ../auth/login.php');
    exit();
}

$type = $_GET['type'] ?? '';
$employee_id = $_GET['employee_id'] ?? null;

$pdf_handler = new PDF($pdo);

switch ($type) {
    case 'employee':
        $content = $pdf_handler->generate_employee_report('pdf');
        break;
    case 'attendance':
        $content = $pdf_handler->generate_attendance_report('pdf');
        break;
    case 'payroll':
        $content = $pdf_handler->generate_payroll_report('pdf');
        break;
    case 'salary_certificate':
        if ($employee_id) {
            $content = $pdf_handler->generate_salary_certificate($employee_id);
        } else {
            die('Employee ID required for salary certificate');
        }
        break;
    default:
        die('Invalid report type');
}

echo $content;
?>
