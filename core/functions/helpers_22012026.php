<?php
// new_ufmhrm/core/functions/helpers.php

/**
 * ==============================================================================
 * CORE LOGIN & SESSION HELPERS
 * ==============================================================================
 * These functions are updated to use the new session variables set in
 * User->login() (e.g., $_SESSION['user_id'], $_SESSION['user_role']).
 */

/**
 * Checks if a user is logged in.
 * This is the primary check used by most pages.
 *
 * @return bool True if the user is logged in, false otherwise.
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Legacy function alias.
 * It's better to use isLoggedIn() directly, but this provides backward compatibility.
 */
function is_admin_logged_in(){
    return isLoggedIn();
}

/**
 * Gets the current user's essential data from the session.
 *
 * @return array|null An array with user data or null if not logged in.
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
 * Restricts access to a page based on the user's role.
 * Call this at the top of any secure page.
 *
 * @param array $allowed_roles An array of role strings that are allowed.
 * If empty, it allows all *authenticated* users.
 * @return void
 */
function restrict_access(array $allowed_roles = []) {
    // 1. First, check if user is logged in at all.
    if (!isLoggedIn()) {
        $_SESSION['error_flash'] = 'You must be logged in to access that page.';
        header('Location: ' . url('auth/login.php'));
        exit();
    }

    // 2. If the allowed roles array is empty, it means all logged-in users are allowed.
    if (empty($allowed_roles)) {
        return; // User is logged in, and all logged-in users are allowed.
    }

    // 3. Get the current user's role from the session.
    $user_role = $_SESSION['user_role'] ?? null;

    // 4. Check if their role is in the allowed list.
    if (in_array($user_role, $allowed_roles)) {
        // User has permission. Do nothing and let the page load.
        return;
    }

    // 5. If we get here, the user is logged in, but their role is not allowed.
    $_SESSION['error_flash'] = 'You do not have permission to access that page.';
    
    // Send them to their default dashboard (the main index.php router)
    header('Location: ' . url('index.php')); 
    exit();
}


/**
 * ==============================================================================
 * URL & ASSET HELPERS
 * ==============================================================================
 * These use the APP_URL defined in core/config/config.php to build
 * correct, absolute URLs for links and assets.
 */

/**
 * Creates a full, absolute URL to a path within the application.
 *
 * @param string $path The internal path (e.g., 'admin/users.php').
 * @return string The full URL (e.g., 'http://saas.ujjalfm.com/admin/users.php').
 */
function url($path = '') {
    // rtrim removes trailing slash from APP_URL, ltrim removes leading slash from path
    return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
}

/**
 * Creates a full, absolute URL to an asset (CSS, JS, image).
 *
 * @param string $path The asset path (e.g., 'css/style.css').
 * @return string The full URL (e.g., 'http://saas.ujjalfm.com/assets/css/style.css').
 */
function asset($path) {
    return rtrim(APP_URL, '/') . '/assets/' . ltrim($path, '/');
}


/**
 * ==============================================================================
 * MESSAGE DISPLAY HELPER
 * ==============================================================================
 *
 * Displays one-time success or error messages (flash messages)
 * stored in the session.
 */

/**
 * Displays and clears 'success_flash' or 'error_flash' messages.
 *
 * @return string The HTML for the message box, or an empty string.
 */
function display_message(){
    $message = '';
    
    // Check for success message
    if(isset($_SESSION['success_flash'])){
        $message = '<div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded-r-lg" role="alert">
                        <p class="font-bold">Success</p>
                        <p>' . htmlspecialchars($_SESSION['success_flash']) . '</p>
                    </div>';
        unset($_SESSION['success_flash']);
    }

    // Check for error message
    if(isset($_SESSION['error_flash'])){
        $message = '<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded-r-lg" role="alert">
                        <p class="font-bold">Error</p>
                        <p>' . htmlspecialchars($_SESSION['error_flash']) . '</p>
                    </div>';
        unset($_SESSION['error_flash']);
    }
    
    return $message;
}


/**
 * ==============================================================================
 * UTILITY HELPERS
 * ==============================================================================
 */

/**
 * Escapes HTML entities
 * 
 * @param string $string The string to escape
 * @return string The escaped string
 */
if (!function_exists('e')) {
    function e($string) {
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Redirects to a path with optional flash message
 * 
 * @param string $path The path to redirect to
 * @param string $message Optional flash message
 * @param string $type Message type: 'success' or 'error'
 */
function redirect($path, $message = '', $type = 'success') {
    if (!empty($message)) {
        if ($type === 'success') {
            $_SESSION['success_flash'] = $message;
        } else {
            $_SESSION['error_flash'] = $message;
        }
    }
    
    // If path doesn't start with http, treat it as relative path
    if (strpos($path, 'http') !== 0) {
        $path = url($path);
    }
    
    header('Location: ' . $path);
    exit();
}

/**
 * Sanitizes a string by trimming whitespace and converting special characters to HTML entities
 * 
 * @param string|array $input The string or array to sanitize
 * @return string|array The sanitized string or array
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}


/**
 * ==============================================================================
 * EXPENSE MODULE PERMISSION HELPERS
 * ==============================================================================
 * Permission checks for Expense Management system
 * 
 * Role definitions:
 * - Superadmin: Full access to everything
 * - admin: Administrative access (can approve and view)
 * - Accounts: Accounting team (can approve and view)
 * - Expense Approver: Can approve/reject expense vouchers
 * - Expense Initiator: Can create expense vouchers only
 */

/**
 * Check if user can access Expense module at all
 * 
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
        'Expense Approver'
    ]);
}

/**
 * Check if user can create expense vouchers
 * 
 * @return bool
 */
function canCreateExpenseVoucher() {
    return canAccessExpense();
}

/**
 * Alias for canCreateExpenseVoucher
 * 
 * @return bool
 */
function canCreateExpense() {
    return canCreateExpenseVoucher();
}

/**
 * Check if user can access the Approve Expense page
 * (Different from actually approving - this is for page access)
 * 
 * @return bool
 */
function canAccessApproveExpense() {
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
 * Check if user can approve/reject expense vouchers
 * 
 * @return bool
 */
function canApproveExpense() {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    $role = $_SESSION['user_role'];
    
    return in_array($role, [
        'Superadmin',
        'Expense Approver'
    ]);
}

/**
 * Check if user can edit expense vouchers
 * 
 * @return bool
 */
function canEditExpense() {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    return $_SESSION['user_role'] === 'Superadmin';
}

/**
 * Check if user can delete expense vouchers
 * 
 * @return bool
 */
function canDeleteExpense() {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    return $_SESSION['user_role'] === 'Superadmin';
}

/**
 * Check if user can access Expense History page
 * 
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
        'Expense Initiator'
    ]);
}

/**
 * Check if user can see Smart Dashboard in Expense History
 * 
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
 * 
 * @return bool
 */
function canViewExpense() {
    return canAccessExpenseHistory();
}

/**
 * Check if user can manage expense categories and subcategories
 * 
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
 * 
 * @return bool
 */
function canPrintExpense() {
    return canAccessExpenseHistory();
}



/**
185 * Check if user can delete expenses (Superadmin only)
186 * @return bool
187 */


/**
194 * Check if user can edit expenses (Superadmin only)
195 * @return bool
196 */




/**
 * Audit Trail Helper Functions
 * Add these to /core/helpers.php
 */

/**
 * Check if user can access audit trail / user activity page
 * Only Superadmin can access
 */
function canAccessAuditTrail() {
    return ($_SESSION['user_role'] ?? '') === 'Superadmin';
}

/**
 * Check if user can view audit logs for specific user
 * Superadmin can view all, others can only view their own
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
 * Check if current action should be logged
 * Prevents logging of read-only actions unless specified
 */
function shouldLogAction($action) {
    // Don't log these by default
    $skipActions = ['viewed', 'listed', 'searched', 'filtered'];
    
    return !in_array($action, $skipActions);
}

/**
 * Get current user ID for logging
 */
function getAuditUserId() {
    return $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
}

/**
 * Get current user name for logging
 */
function getAuditUserName() {
    return $_SESSION['user_display_name'] ?? $_SESSION['display_name'] ?? 'Unknown User';
}

/**
 * Quick log function - wrapper for AuditLogger
 * For backward compatibility or quick logging
 */
function auditLog($module, $action, $description, $options = []) {
    if (!shouldLogAction($action)) {
        return false;
    }
    
    try {
        require_once __DIR__ . '/classes/AuditLogger.php';
        
        $options['description'] = $description;
        return AuditLogger::log($module, $action, $options);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Quick expense log function
 */
function auditLogExpense($action, $expenseId, $voucherNumber, $description, $data = null) {
    try {
        require_once __DIR__ . '/classes/AuditLogger.php';
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
 * Quick credit order log function
 */
function auditLogOrder($action, $orderId, $orderNumber, $description, $data = null) {
    try {
        require_once __DIR__ . '/classes/AuditLogger.php';
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
 * Quick payment log function
 */
function auditLogPayment($action, $paymentId, $reference, $description, $data = null) {
    try {
        require_once __DIR__ . '/classes/AuditLogger.php';
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
 * Quick auth log function (login/logout)
 */
function auditLogAuth($action, $description = null) {
    $userId = getAuditUserId();
    if (!$userId) return false;
    
    try {
        require_once __DIR__ . '/classes/AuditLogger.php';
        return AuditLogger::logAuth($action, $userId, [
            'description' => $description ?? ($action === 'logged_in' ? 'User logged in' : 'User logged out')
        ]);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
        return false;
    }
}



/**
 * ==============================================================================
 * END OF FILE
 * ==============================================================================
 */

?>