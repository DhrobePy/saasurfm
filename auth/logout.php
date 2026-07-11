<?php
// new_ufmhrm/auth/logout.php
require_once __DIR__ . '/../core/init.php';

// Capture pre-logout data for audit (before any session destruction)
$user_id = $_SESSION['user_id'] ?? null;
$user_name = $_SESSION['user_display_name'] ?? 'Unknown';
$user_role = $_SESSION['user_role'] ?? 'Unknown';
$login_time = $_SESSION['login_time'] ?? null;
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// Check if this is a timeout logout or manual logout
$logout_type = isset($_GET['timeout']) ? 'timeout' : 'manual';

// Calculate session duration if login time exists
$session_duration = null;
if ($login_time) {
    $duration_seconds = time() - $login_time;
    $hours = floor($duration_seconds / 3600);
    $minutes = floor(($duration_seconds % 3600) / 60);
    $seconds = $duration_seconds % 60;
    $session_duration = sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
}

// AUDIT: Log timeout event before logout if this is a timeout
if ($logout_type === 'timeout' && $user_id && class_exists('AuditLogger')) {
    AuditLogger::logAuth('session_timeout', $user_id, [
        'user_name' => $user_name,
        'user_role' => $user_role,
        'ip_address' => $ip_address,
        'login_time' => $login_time ? date('Y-m-d H:i:s', $login_time) : null,
        'timeout_time' => date('Y-m-d H:i:s'),
        'session_duration' => $session_duration,
        'reason' => 'session_timeout',
        'description' => "Session timed out for user {$user_name}" . ($session_duration ? " after {$session_duration}" : "")
    ]);
}

// $db is created in init.php
// We MUST pass it to the User constructor
$user = new User($db);

// Call the logout method (which now has its own audit trail)
$user->logout();

// Set appropriate flash message based on logout type
if ($logout_type === 'timeout') {
    $_SESSION['info_flash'] = 'Your session has expired. Please login again.';
} else {
    $_SESSION['success_flash'] = 'You have been successfully logged out.';
}

// Redirect to login page
header('Location: login.php');
exit();
?>