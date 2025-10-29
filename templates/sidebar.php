<div class="bg-light border-right" id="sidebar-wrapper">
    <div class="sidebar-heading">UFM-HRMS System</div>
    <div class="list-group list-group-flush">
        <a href="index.php" class="list-group-item list-group-item-action bg-light">Dashboard</a>
        <a href="employees.php" class="list-group-item list-group-item-action bg-light">Employees</a>
        <a href="attendance.php" class="list-group-item list-group-item-action bg-light">Attendance</a>
        <a href="leave.php" class="list-group-item list-group-item-action bg-light">Leave</a>
        
        <div class="list-group-item bg-light text-muted pt-3" style="font-size: 0.8rem; font-weight: bold;">PAYROLL MANAGEMENT</div>
        <a href="prepare_payroll.php" class="list-group-item list-group-item-action bg-light">Prepare Payroll</a>
        
        <?php // This link will only be visible to superadmins ?>
        <?php if (is_superadmin()): ?>
            <a href="approve_payroll.php" class="list-group-item list-group-item-action bg-light">Approve Payroll</a>
        <?php endif; ?>

        <a href="disburse_payroll.php" class="list-group-item list-group-item-action bg-light">Disburse Payroll</a>
        <a href="payslip.php" class="list-group-item list-group-item-action bg-light">Generate Payslip</a>
        <a href="payroll_history.php" class="list-group-item list-group-item-action bg-light">Payroll History</a>
        <a href="monthly_salary_report.php" class="list-group-item list-group-item-action bg-light">Monthly Salary Report</a>
        
        <div class="list-group-item bg-light text-muted pt-3" style="font-size: 0.8rem; font-weight: bold;">FINANCE & LOANS</div>
        <a href="salary_structure.php" class="list-group-item list-group-item-action bg-light">Salary Structure</a>
        <a href="salary_advance_enhanced.php" class="list-group-item list-group-item-action bg-light">Salary Advance</a>
        <a href="loan_enhanced.php" class="list-group-item list-group-item-action bg-light">Loan Management</a>
        <a href="accounting_complete.php" class="list-group-item list-group-item-action bg-light">Accounting</a>

        <?php if (is_superadmin()): ?>
            <div class="list-group-item bg-light text-muted pt-3" style="font-size: 0.8rem; font-weight: bold;">ADMINISTRATION</div>
            <a href="users.php" class="list-group-item list-group-item-action bg-light">User Management</a>
            <a href="holidays.php" class="list-group-item list-group-item-action bg-light">Holiday Management</a>
            <a href="manual_attendance.php" class="list-group-item list-group-item-action bg-light">Manual Attendance</a>
            <a href="attendance_history.php" class="list-group-item list-group-item-action bg-light">Attendance History</a>
            <a href="reports.php" class="list-group-item list-group-item-action bg-light">Reports</a>
            <a href="settings.php" class="list-group-item list-group-item-action bg-light">Settings</a>
        <?php endif; ?>
        
        <a href="../auth/logout.php" class="list-group-item list-group-item-action bg-light mt-3">Logout</a>
    </div>
</div>
<div id="page-content-wrapper">
    <nav class="navbar navbar-expand-lg navbar-light bg-light border-bottom">
        <div class="container-fluid">
            <button class="btn btn-primary" id="menu-toggle">Toggle Menu</button>
        </div>
    </nav>
    <div class="container-fluid">