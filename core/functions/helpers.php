<?php
/**
 * ============================================================================
 * CORE HELPER FUNCTIONS
 * ============================================================================
 * Helper functions for Ujjal Flour Mills ERP System
 * 
 * @package Ujjal Flour Mills
 * @version 2.0
 */

// Prevent direct access
if (!defined('APP_URL')) {
    die('Direct access not permitted');
}

/**
 * ============================================================================
 * CORE LOGIN & SESSION HELPERS
 * ============================================================================
 */

/**
 * Check if a user is logged in
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Legacy function alias for backward compatibility
 * @return bool
 */
function is_admin_logged_in() {
    return isLoggedIn();
}

/**
 * Get current user's essential data from session
 * @return array|null
 */
function getCurrentUser() {
    if (isLoggedIn()) {
        return [
            'id'           => $_SESSION['user_id'] ?? null,
            'display_name' => $_SESSION['user_display_name'] ?? 'User',
            'role'         => $_SESSION['user_role'] ?? null,
            'email'        => $_SESSION['user_email'] ?? null,
            'branch_id'    => $_SESSION['user_branch_id'] ?? null,
        ];
    }
    return null;
}

/**
 * Restrict access to a page based on user role
 * @param array $allowed_roles
 * @return void
 */
function restrict_access(array $allowed_roles = []) {
    // Check if user is logged in
    if (!isLoggedIn()) {
        $_SESSION['error_flash'] = 'You must be logged in to access that page.';
        header('Location: ' . url('auth/login.php'));
        exit();
    }

    // If allowed roles is empty, allow all authenticated users
    if (empty($allowed_roles)) {
        return;
    }

    // Get current user role
    $user_role = $_SESSION['user_role'] ?? null;

    // Check if role is allowed
    if (in_array($user_role, $allowed_roles)) {
        return;
    }

    // User doesn't have permission
    $_SESSION['error_flash'] = 'You do not have permission to access that page.';
    header('Location: ' . url('index.php'));
    exit();
}

/**
 * ============================================================================
 * URL & ASSET HELPERS
 * ============================================================================
 */

/**
 * Create full URL to a path within the application
 * @param string $path
 * @return string
 */
function url($path = '') {
    return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
}

/**
 * Create full URL to an asset (CSS, JS, image)
 * @param string $path
 * @return string
 */
function asset($path) {
    return rtrim(APP_URL, '/') . '/assets/' . ltrim($path, '/');
}

/**
 * ============================================================================
 * MESSAGE DISPLAY HELPERS
 * ============================================================================
 */

/**
 * Display and clear flash messages
 * @return string
 */
function display_message() {
    $message = '';
    
    // Success message
    if (isset($_SESSION['success_flash'])) {
        $message = '<div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded-r-lg" role="alert">
                        <p class="font-bold">Success</p>
                        <p>' . htmlspecialchars($_SESSION['success_flash']) . '</p>
                    </div>';
        unset($_SESSION['success_flash']);
    }

    // Error message
    if (isset($_SESSION['error_flash'])) {
        $message = '<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded-r-lg" role="alert">
                        <p class="font-bold">Error</p>
                        <p>' . htmlspecialchars($_SESSION['error_flash']) . '</p>
                    </div>';
        unset($_SESSION['error_flash']);
    }
    
    return $message;
}

/**
 * Alias for display_message
 * @return string
 */
function displayFlashMessage() {
    return display_message();
}

/**
 * ============================================================================
 * UTILITY HELPERS
 * ============================================================================
 */

/**
 * Escape HTML entities
 * @param string $string
 * @return string
 */
if (!function_exists('e')) {
    function e($string) {
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Redirect to a path with optional flash message
 * @param string $path
 * @param string $message
 * @param string $type
 */
function redirect($path, $message = '', $type = 'success') {
    if (!empty($message)) {
        if ($type === 'success') {
            $_SESSION['success_flash'] = $message;
        } else {
            $_SESSION['error_flash'] = $message;
        }
    }
    
    // If path doesn't start with http, treat as relative
    if (strpos($path, 'http') !== 0) {
        $path = url($path);
    }
    
    header('Location: ' . $path);
    exit();
}

/**
 * Sanitize input
 * @param string|array $input
 * @return string|array
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * ============================================================================
 * EXPENSE MODULE PERMISSION FUNCTIONS
 * ============================================================================
 */

/**
 * Check if user can access Expense module
 * @return bool
 */
function canAccessExpense() {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    $role = $_SESSION['user_role'];
    
    return in_array($role, [
        'Superadmin',
        'admin',
        'Accounts',
        'Expense Initiator',
        'Expense Approver',
        'accounts-demra',
        'accounts-srg'
    ]);
}

/**
 * Check if user can create expense vouchers
 * @return bool
 */
function canCreateExpense() {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    $role = $_SESSION['user_role'];
    
    return in_array($role, [
        'Superadmin',
        'admin',
        'Accounts',
        'Expense Initiator',
        'accounts-demra',
        'accounts-srg'
    ]);
}

/**
 * Alias for canCreateExpense
 * @return bool
 */
function canCreateExpenseVoucher() {
    return canCreateExpense();
}

/**
 * Check if user can approve expense vouchers
 * @return bool
 */
function canApproveExpense() {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    $role = $_SESSION['user_role'];
    
    return in_array($role, [
        'Superadmin',
        'admin',
        'Accounts',
        'Expense Approver'
    ]);
}

/**
 * Check if user can access approve expense page
 * @return bool
 */
function canAccessApproveExpense() {
    return canApproveExpense();
}

/**
 * Check if user can edit expense vouchers (Superadmin only)
 * @return bool
 */
function canEditExpense() {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    return $_SESSION['user_role'] === 'Superadmin';
}

/**
 * Check if user can delete expense vouchers (Superadmin only)
 * @return bool
 */
function canDeleteExpense() {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    return $_SESSION['user_role'] === 'Superadmin';
}

/**
 * Check if user can access expense history page
 * @return bool
 */
function canAccessExpenseHistory() {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    $role = $_SESSION['user_role'];
    
    return in_array($role, [
        'Superadmin',
        'admin',
        'Accounts',
        'Expense Approver',
        'Expense Initiator',
        'accounts-demra',
        'accounts-srg'
    ]);
}

/**
 * Check if user can see expense dashboard/statistics
 * @return bool
 */
function canSeeExpenseDashboard() {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    $role = $_SESSION['user_role'];
    
    return in_array($role, [
        'Superadmin',
        'admin',
        'Accounts'
    ]);
}

/**
 * Check if user can view expense vouchers
 * @return bool
 */
function canViewExpense() {
    return canAccessExpenseHistory();
}

/**
 * Check if user can manage expense categories
 * @return bool
 */
function canManageExpenseCategories() {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    return $_SESSION['user_role'] === 'Superadmin';
}

/**
 * Check if user can print expense vouchers
 * @return bool
 */
function canPrintExpense() {
    return canAccessExpenseHistory();
}

/**
 * ============================================================================
 * AUDIT TRAIL HELPER FUNCTIONS
 * ============================================================================
 */

/**
 * Check if user can access audit trail
 * @return bool
 */
function canAccessAuditTrail() {
    return ($_SESSION['user_role'] ?? '') === 'Superadmin';
}

/**
 * Check if user can view audit logs for specific user
 * @param int $userId
 * @return bool
 */
function canViewUserAudit($userId) {
    $currentUserId = $_SESSION['user_id'] ?? 0;
    $userRole = $_SESSION['user_role'] ?? '';
    
    // Superadmin can view all
    if ($userRole === 'Superadmin') {
        return true;
    }
    
    // Others can only view their own
    return $currentUserId == $userId;
}

/**
 * Check if action should be logged
 * @param string $action
 * @return bool
 */
function shouldLogAction($action) {
    $skipActions = ['viewed', 'listed', 'searched', 'filtered'];
    return !in_array($action, $skipActions);
}

/**
 * Get current user ID for logging
 * @return int|null
 */
function getAuditUserId() {
    return $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
}

/**
 * Get current user name for logging
 * @return string
 */
function getAuditUserName() {
    return $_SESSION['user_display_name'] ?? $_SESSION['display_name'] ?? 'Unknown User';
}

/**
 * Quick audit log function
 * @param string $module
 * @param string $action
 * @param string $description
 * @param array $options
 * @return bool
 */
function auditLog($module, $action, $description, $options = []) {
    if (!shouldLogAction($action)) {
        return false;
    }
    
    try {
        require_once __DIR__ . '/../classes/AuditLogger.php';
        $options['description'] = $description;
        return AuditLogger::log($module, $action, $options);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Log expense action
 * @param string $action
 * @param int $expenseId
 * @param string $voucherNumber
 * @param string $description
 * @param mixed $data
 * @return bool
 */
function auditLogExpense($action, $expenseId, $voucherNumber, $description, $data = null) {
    try {
        require_once __DIR__ . '/../classes/AuditLogger.php';
        return AuditLogger::logExpense($action, $expenseId, $voucherNumber, [
            'description' => $description,
            'data' => $data
        ]);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Log credit order action
 * @param string $action
 * @param int $orderId
 * @param string $orderNumber
 * @param string $description
 * @param mixed $data
 * @return bool
 */
function auditLogOrder($action, $orderId, $orderNumber, $description, $data = null) {
    try {
        require_once __DIR__ . '/../classes/AuditLogger.php';
        return AuditLogger::logCreditOrder($action, $orderId, $orderNumber, [
            'description' => $description,
            'data' => $data
        ]);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Log payment action
 * @param string $action
 * @param int $paymentId
 * @param string $reference
 * @param string $description
 * @param mixed $data
 * @return bool
 */
function auditLogPayment($action, $paymentId, $reference, $description, $data = null) {
    try {
        require_once __DIR__ . '/../classes/AuditLogger.php';
        return AuditLogger::logPayment($action, $paymentId, $reference, [
            'description' => $description,
            'data' => $data
        ]);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Log authentication action
 * @param string $action
 * @param string|null $description
 * @return bool
 */
function auditLogAuth($action, $description = null) {
    $userId = getAuditUserId();
    if (!$userId) return false;
    
    try {
        require_once __DIR__ . '/../classes/AuditLogger.php';
        return AuditLogger::logAuth($action, $userId, [
            'description' => $description ?? ($action === 'logged_in' ? 'User logged in' : 'User logged out')
        ]);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
        return false;
    }
}

/**
 * ============================================================================
 * END OF HELPERS
 * ============================================================================
 */