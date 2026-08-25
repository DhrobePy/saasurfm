<?php
require_once __DIR__ . '/../core/init.php';

if (!is_employee_logged_in()) {
    header('Location: ../auth/employee_login.php');
    exit();
}

$user = new User($pdo);
$employee_id = $user->get_employee_id_from_session();
$attendance_handler = new Attendance($pdo);
$today_attendance = $attendance_handler->get_today_attendance_by_employee($employee_id);

$page_title = 'Employee Dashboard';
include __DIR__ . '/../templates/header.php';
?>

<div class="container">
    <h1 class="mt-4">Employee Dashboard</h1>
    <div class="card">
        <div class="card-header">Your Attendance</div>
        <div class="card-body text-center">
            <?php if (!$today_attendance): ?>
                <form action="attendance_handler.php" method="POST">
                    <input type="hidden" name="action" value="clock_in">
                    <button type="submit" class="btn btn-success btn-lg">Clock In</button>
                </form>
            <?php elseif (!$today_attendance['clock_out']): ?>
                <p>You clocked in at: <?php echo format_date($today_attendance['clock_in']); ?></p>
                <form action="attendance_handler.php" method="POST">
                    <input type="hidden" name="action" value="clock_out">
                    <button type="submit" class="btn btn-danger btn-lg">Clock Out</button>
                </form>
            <?php else: ?>
                <p>You have completed your attendance for today.</p>
                <p>Clock In: <?php echo format_date($today_attendance['clock_in']); ?></p>
                <p>Clock Out: <?php echo format_date($today_attendance['clock_out']); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <a href="../auth/logout.php" class="btn btn-secondary mt-3">Logout</a>
</div>

<?php
include __DIR__ . '/../templates/footer.php';
?>
