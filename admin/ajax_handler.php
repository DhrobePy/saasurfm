<?php
// CRITICAL: Prevent any output before JSON
ob_start();

require_once '../core/init.php';

// Set JSON header
header('Content-Type: application/json');

// Security check: User must be logged in
if (!isset($_SESSION['user_id'])) {
    ob_end_clean(); http_response_code(401); echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
}
$user_id = $_SESSION['user_id'];

// CSRF Token validation for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
         ob_end_clean(); http_response_code(400); echo json_encode(['success' => false, 'error' => 'Invalid JSON']); exit;
    }
    $csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($data['csrf_token'] ?? '');
    if (empty($csrf_token) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf_token)) {
        ob_end_clean(); http_response_code(403); echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']); exit;
    }
} else { $data = $_GET; }

global $db;
$user_role = $_SESSION['user_role'] ?? '';
$result = ['success' => false, 'error' => 'Invalid action']; // Default response

try {
    $action = $data['action'] ?? null;

    switch ($action) {
        
        // *** ADD THIS NEW CASE ***
        case 'save_dashboard_settings':
            if (!in_array($user_role, ['Superadmin', 'admin'])) {
                throw new Exception('You do not have permission to change settings.');
            }
            
            $preferences = $data['preferences'] ?? [];
            if (!is_array($preferences)) {
                throw new Exception('Invalid preferences format. Expected an array.');
            }
            
            // Validate keys (optional but good)
            // ... (You could check keys against the master list here) ...
            
            $json_prefs = json_encode($preferences);
            
            // Save to the user's row in the `users` table
            $updated = $db->update('users', $user_id, [
                'dashboard_preferences' => $json_prefs
            ]);
            
            if (!$updated) {
                 // Note: $db->update might return false if no rows changed, not necessarily an error
                 // We'll assume success if no exception was thrown
            }
            
            $result = ['success' => true, 'message' => 'Settings saved.'];
            break;
            
        // ... (you can add other admin ajax actions here later) ...

        default:
            throw new Exception('Invalid action specified');
    }
    
    ob_end_clean();
    echo json_encode($result);
    exit;

} catch (Exception $e) {
    if (ob_get_level()) ob_end_clean();
    error_log("Admin AJAX Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
?>
