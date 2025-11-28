<?php
// new_ufmhrm/core/functions/helpers.php

/**
 * ==============================================================================
 * CORE LOGIN & SESSION HELPERS
 * ==============================================================================
 * * These functions are updated to use the new session variables set in
 * User->login() (e.g., $_SESSION['user_id'], $_SESSION['user_role']).
 */

/**
 * Checks if a user is logged in.
 * This is the primary check used by most pages.
 *
 * @return bool True if the user is logged in, false otherwise.
 */
function isLoggedIn() {
    // This now checks for 'user_id' which is set on successful login.
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
    // This is the fix for the "products.php" page.
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
 * * These use the APP_URL defined in core/config/config.php to build
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

if (!function_exists('e')) {
    function e($string) {
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }
}

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
 * @param string $input The string to sanitize
 * @return string The sanitized string
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}


// You can add other global helper functions here as needed.

?>

