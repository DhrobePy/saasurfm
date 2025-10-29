<?php
require_once __DIR__ . '/../core/init.php';

if (!is_logged_in()) {
    header('Location: ../auth/login.php');
    exit();
}

$report_handler = new Report($pdo);
$employee_handler = new Employee($pdo);
$employees = $employee_handler->get_all();

$report_data = [];
$report_type = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST["download_pdf"])) {
        $report_type = $_POST["report_type"];
        $start_date = $_POST["start_date"] ?? null;
        $end_date = $_POST["end_date"] ?? null;

        $report_handler = new Report($pdo);
        $data = [];
        $header = [];

        switch ($report_type) {
            case 'employee':
                $data = $report_handler->get_employee_report();
                $header = array('Name', 'Email', 'Department', 'Position');
                break;
            case 'attendance':
                $data = $report_handler->get_attendance_report($start_date, $end_date);
                $header = array('Employee', 'Clock In', 'Clock Out', 'Status');
                break;
            case 'payroll':
                $data = $report_handler->get_payroll_report($start_date, $end_date);
                $header = array('Employee', 'Pay Period', 'Gross Salary', 'Net Salary');
                break;
        }

        $pdf = new PDF();
        $pdf->SetFont('Arial','',14);
        $pdf->AddPage();
        $pdf->FancyTable($header, $data);
        $pdf->Output();
        exit;
    }

    $report_type = $_POST['report_type'];
    $start_date = $_POST['start_date'] ?? null;
    $end_date = $_POST['end_date'] ?? null;

    switch ($report_type) {
        case 'employee':
            $report_data = $report_handler->get_employee_report();
            break;
        case 'attendance':
            $report_data = $report_handler->get_attendance_report($start_date, $end_date);
            break;
        case 'payroll':
            $report_data = $report_handler->get_payroll_report($start_date, $end_date);
            break;
    }
}

$page_title = 'Reports';

include __DIR__ . '/../templates/header.php';
include __DIR__ . '/../templates/sidebar.php';
?>

<h1 class="mt-4">Reports</h1>

<div class="card mb-4">
    <div class="card-header">Generate Report</div>
    <div class="card-body">
        <form action="reports.php" method="POST" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="report_type" class="form-label">Report Type</label>
                <select name="report_type" id="report_type" class="form-select" required>
                    <option value="">Select a report</option>
                    <option value="employee">Employee List</option>
                    <option value="attendance">Attendance Report</option>
                    <option value="payroll">Payroll Report</option>
                </select>
            </div>
            <div class="col-md-3">
                <label for="start_date" class="form-label">Start Date</label>
                <input type="date" name="start_date" id="start_date" class="form-control">
            </div>
            <div class="col-md-3">
                <label for="end_date" class="form-label">End Date</label>
                <input type="date" name="end_date" id="end_date" class="form-control">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">Generate</button>
            </div>
        </form>
        
        <!-- PDF Download Buttons -->
        <div class="mt-3">
            <a href="pdf_handler.php?type=employee" target="_blank" class="btn btn-secondary">Employee Report PDF</a>
            <a href="pdf_handler.php?type=attendance" target="_blank" class="btn btn-secondary">Attendance Report PDF</a>
            <a href="pdf_handler.php?type=payroll" target="_blank" class="btn btn-secondary">Payroll Report PDF</a>
        </div>
    </div>
</div>

<?php if (!empty($report_data)): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Report Results: <?php echo ucfirst($report_type); ?></span>
        <a href="pdf_handler.php?type=<?php echo $report_type; ?>" target="_blank" class="btn btn-sm btn-secondary">Download PDF</a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead>
                    <?php if ($report_type == 'employee'): ?>
                        <tr><th>Name</th><th>Email</th><th>Department</th><th>Position</th><th>Hire Date</th></tr>
                    <?php elseif ($report_type == 'attendance'): ?>
                        <tr><th>Employee</th><th>Clock In</th><th>Clock Out</th><th>Status</th></tr>
                    <?php elseif ($report_type == 'payroll'): ?>
                        <tr><th>Employee</th><th>Pay Period</th><th>Gross Salary</th><th>Deductions</th><th>Net Salary</th></tr>
                    <?php endif; ?>
                </thead>
                <tbody>
                    <?php foreach ($report_data as $row): ?>
                        <tr>
                        <?php if ($report_type == 'employee'): ?>
                            <td><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['email']); ?></td>
                            <td><?php echo htmlspecialchars($row['department_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['position_name']); ?></td>
                            <td><?php echo format_date($row['hire_date'], 'M d, Y'); ?></td>
                        <?php elseif ($report_type == 'attendance'): ?>
                            <td><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                            <td><?php echo format_date($row['clock_in']); ?></td>
                            <td><?php echo $row['clock_out'] ? format_date($row['clock_out']) : 'N/A'; ?></td>
                            <td><span class="badge bg-success"><?php echo ucfirst($row['status']); ?></span></td>
                        <?php elseif ($report_type == 'payroll'): ?>
                            <td><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                            <td><?php echo format_date($row['pay_period_start'], 'M d, Y') . ' - ' . format_date($row['pay_period_end'], 'M d, Y'); ?></td>
                            <td><?php echo htmlspecialchars($row['gross_salary']); ?></td>
                            <td><?php echo htmlspecialchars($row['deductions']); ?></td>
                            <td><?php echo htmlspecialchars($row['net_salary']); ?></td>
                        <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header">Generate Salary Certificate</div>
    <div class="card-body">
        <form action="reports.php" method="POST" class="row g-3 align-items-end">
            <div class="col-md-8">
                <label for="employee_id_cert" class="form-label">Employee</label>
                <select name="employee_id_cert" id="employee_id_cert" class="form-select" required>
                    <option value="">Select an employee</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?php echo $employee['id']; ?>"><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <button type="button" class="btn btn-secondary w-100" onclick="generateCertificate()">Generate Certificate PDF</button>
            </div>
        </form>
    </div>
</div>

<?php
if (isset($_POST['generate_certificate'])) {
    $employee_id = (int)$_POST['employee_id_cert'];
    $cert_data = $report_handler->get_salary_certificate_data($employee_id);
    if ($cert_data) {
?>
<div class="card">
    <div class="card-header">Salary Certificate</div>
    <div class="card-body">
        <div id="certificate" style="border: 2px solid #000; padding: 20px; text-align: center;">
            <h2>SALARY CERTIFICATE</h2>
            <p>This is to certify that <strong><?php echo htmlspecialchars($cert_data['first_name'] . ' ' . $cert_data['last_name']); ?></strong> is working with our company since <strong><?php echo format_date($cert_data['hire_date'], 'M d, Y'); ?></strong> as a <strong><?php echo htmlspecialchars($cert_data['position_name']); ?></strong> in the <strong><?php echo htmlspecialchars($cert_data['department_name']); ?></strong> department.</p>
            <p>Their current gross monthly salary is <strong>$<?php echo htmlspecialchars(number_format($cert_data['base_salary'], 2)); ?></strong>.</p>
            <p>This certificate is issued upon the request of the employee for whatever legal purpose it may serve.</p>
            <div style="margin-top: 50px; text-align: right;">
                <p>_________________________</p>
                <p>HR Manager</p>
            </div>
        </div>
        <button class="btn btn-primary mt-3" onclick="window.print();">Print Certificate</button>
    </div>
</div>
<?php
    }
}
?>

<script>
function generateCertificate() {
    const employeeSelect = document.getElementById('employee_id_cert');
    const employeeId = employeeSelect.value;
    
    if (!employeeId) {
        alert('Please select an employee first.');
        return;
    }
    
    window.open('pdf_handler.php?type=salary_certificate&employee_id=' + employeeId, '_blank');
}
</script>

<?php
include __DIR__ . '/../templates/footer.php';
?>
