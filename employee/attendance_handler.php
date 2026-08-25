<?php
require_once __DIR__ . '/../core/init.php';

if (!is_employee_logged_in()) {
    header('Location: ../auth/employee_login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = new User($pdo);
    $employee_id = $user->get_employee_id_from_session();
    $attendance_handler = new Attendance($pdo);
    $action = $_POST['action'];

    if ($action == 'clock_in') {
        $attendance_handler->clock_in($employee_id);
    } elseif ($action == 'clock_out') {
        $attendance_handler->clock_out($employee_id);
    }
}

header('Location: index.php');
exit();
?>
