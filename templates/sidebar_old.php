<div class="bg-light border-right" id="sidebar-wrapper">
    <div class="sidebar-heading">HRM System</div>
    <div class="list-group list-group-flush">
        <a href="index.php" class="list-group-item list-group-item-action bg-light">Dashboard</a>
        <a href="employees.php" class="list-group-item list-group-item-action bg-light">Employees</a>
        <a href="attendance.php" class="list-group-item list-group-item-action bg-light">Attendance</a>
        <a href="leave.php" class="list-group-item list-group-item-action bg-light">Leave</a>
        <a href="payroll.php" class="list-group-item list-group-item-action bg-light">Payroll</a>
        <a href="salary_advance.php" class="list-group-item list-group-item-action bg-light">Salary Advance</a>
        <a href="loan.php" class="list-group-item list-group-item-action bg-light">Loan Management</a>
        <a href="bulk_disbursement.php" class="list-group-item list-group-item-action bg-light">Bulk Disbursement</a>
        <a href="salary_structure.php" class="list-group-item list-group-item-action bg-light">Salary Structure</a>
        <a href="payslip.php" class="list-group-item list-group-item-action bg-light">Payslip Generation</a>
        <a href="salary_advance_enhanced.php" class="list-group-item list-group-item-action bg-light">Salary Advance</a>
        <a href="loan_enhanced.php" class="list-group-item list-group-item-action bg-light">Loan Management</a>
        <a href="monthly_salary_report.php" class="list-group-item list-group-item-action bg-light">Monthly Salary Report</a>
        <?php if (is_superadmin()): ?>
        <a href="holidays.php" class="list-group-item list-group-item-action bg-light">Holiday Management</a>
        <a href="manual_attendance.php" class="list-group-item list-group-item-action bg-light">Manual Attendance</a>
        <a href="attendance_history.php" class="list-group-item list-group-item-action bg-light">Attendance History</a>
        <?php endif; ?>
        <a href="accounting_complete.php" class="list-group-item list-group-item-action bg-light">Accounting</a>
        <a href="reports.php" class="list-group-item list-group-item-action bg-light">Reports</a>
        <?php if (is_superadmin()): ?>
            <a href="settings.php" class="list-group-item list-group-item-action bg-light">Settings</a>
        <?php endif; ?>
        <a href="../auth/logout.php" class="list-group-item list-group-item-action bg-light">Logout</a>
    </div>
</div>
<div id="page-content-wrapper">
    <nav class="navbar navbar-expand-lg navbar-light bg-light border-bottom">
        <div class="container-fluid">
            <button class="btn btn-primary" id="menu-toggle">Toggle Menu</button>
        </div>
    </nav>
    <div class="container-fluid">
