<?php
/**
 * Records a one-time popup announcement as permanently dismissed for the
 * current user. Used by templates/session_announcement.php's Close button —
 * once a user closes their popup, this endpoint marks it so it never shows
 * again for them, on any device/browser (server-side, not localStorage).
 */
require_once __DIR__ . '/../core/init.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

$sess_tok = $_SESSION['csrf_token'] ?? '';
$recv_tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
if (!$sess_tok || !$recv_tok || !hash_equals($sess_tok, $recv_tok)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit();
}

// Whitelist — never let the client dismiss an arbitrary key.
$allowed_keys = ['session_update_2026_09_08'];
$key = $_POST['announcement_key'] ?? '';
if (!in_array($key, $allowed_keys, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid announcement key']);
    exit();
}

global $db;
$user_id = (int)($_SESSION['user_id'] ?? 0);

try {
    // Self-migrating, defensive — in case this endpoint is ever hit before
    // any page has included templates/session_announcement.php.
    $db->getPdo()->exec("
        CREATE TABLE IF NOT EXISTS `user_dismissed_announcements` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `user_id` BIGINT UNSIGNED NOT NULL,
          `announcement_key` VARCHAR(100) NOT NULL,
          `dismissed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uk_user_announcement` (`user_id`, `announcement_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->query(
        "INSERT IGNORE INTO user_dismissed_announcements (user_id, announcement_key) VALUES (?, ?)",
        [$user_id, $key]
    );
    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    error_log('dismiss_announcement failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
