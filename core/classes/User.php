<?php
// new_ufmhrm/core/classes/User.php

class User
{
    private $_db;

    // This is correct. It expects the database *object* from init.php
    public function __construct($db)
    {
        // This assumes $db is your Database::getInstance() object
        // and that it has methods like query(), bind(), single()
        $this->_db = $db;
    }

    /**
     * ====================================================================
     * UPDATED LOGIN FUNCTION WITH AUDIT TRAIL
     * ====================================================================
     *
     * Attempts to log in a user using the 'users' table.
     * On success, sets session variables and returns true.
     * On failure, returns false.
     *
     * @param string $email The user's email
     * @param string $password The user's plain-text password
     * @return bool True on success, false on failure
     */
    public function login($email, $password)
    {
        try {
            // Get client information for audit
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

            // 1. Find the user in the 'users' table using 'email'
            $sql = "SELECT id, uuid, display_name, email, role, password_hash, status 
                    FROM users 
                    WHERE email = ?"; 
            
            $stmt = $this->_db->query($sql, [$email]);

            // Check if user was found
            if ($stmt && $stmt->count()) {
                $user = $stmt->first(); // Get the user data

                // 2. Check if the user is 'active'
                if ($user->status !== 'active') {
                    // AUDIT: Failed login - inactive account
                    if (class_exists('AuditLogger')) {
                        AuditLogger::logAuth('login_failed', null, [
                            'email' => $email,
                            'reason' => 'inactive_account',
                            'ip_address' => $ip_address,
                            'user_agent' => $user_agent,
                            'description' => "Failed login attempt for {$email} - Account is inactive"
                        ]);
                    }
                    return false; // Account is not active
                }

                // 3. Verify the hashed password
                $hashed_password = $user->password_hash;

                if (password_verify($password, $hashed_password)) {
                    // Password is correct!
                    
                    // 4. Regenerate session ID (security best practice)
                    session_regenerate_id(true);

                    // 5. Store user data in the session
                    $_SESSION['user_id'] = $user->id;
                    $_SESSION['user_uuid'] = $user->uuid;
                    $_SESSION['user_display_name'] = $user->display_name; 
                    $_SESSION['user_email'] = $user->email;
                    $_SESSION['user_role'] = $user->role;
                    $_SESSION['logged_in'] = true;
                    $_SESSION['login_time'] = time(); // Add login timestamp for session duration tracking

                    // 6. AUDIT: Successful login
                    if (class_exists('AuditLogger')) {
                        AuditLogger::logAuth('logged_in', $user->id, [
                            'user_name' => $user->display_name,
                            'user_email' => $user->email,
                            'user_role' => $user->role,
                            'ip_address' => $ip_address,
                            'user_agent' => $user_agent,
                            'session_id' => session_id(),
                            'login_time' => date('Y-m-d H:i:s'),
                            'description' => "User {$user->display_name} logged in successfully"
                        ]);
                    }

                    // 7. Update last login timestamp (optional)
                    $this->updateLastLogin($user->id, $ip_address);

                    // 8. Return true for success
                    return true;
                } else {
                    // AUDIT: Failed login - wrong password
                    if (class_exists('AuditLogger')) {
                        AuditLogger::logAuth('login_failed', $user->id, [
                            'email' => $email,
                            'user_name' => $user->display_name,
                            'reason' => 'invalid_password',
                            'ip_address' => $ip_address,
                            'user_agent' => $user_agent,
                            'description' => "Failed login attempt for {$email} - Incorrect password"
                        ]);
                    }
                }
            } else {
                // AUDIT: Failed login - user not found
                if (class_exists('AuditLogger')) {
                    AuditLogger::logAuth('login_failed', null, [
                        'email' => $email,
                        'reason' => 'user_not_found',
                        'ip_address' => $ip_address,
                        'user_agent' => $user_agent,
                        'description' => "Failed login attempt for {$email} - User not found"
                    ]);
                }
            }

            // If user not found or password incorrect
            return false;

        } catch (Exception $e) {
            // Handle database errors
            error_log('Login error: ' . $e->getMessage());
            
            // AUDIT: Login error
            if (class_exists('AuditLogger')) {
                AuditLogger::logAuth('login_error', null, [
                    'email' => $email,
                    'error' => $e->getMessage(),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                    'description' => "Login error for {$email}: " . $e->getMessage()
                ]);
            }
            
            return false;
        }
    }

    /**
     * ====================================================================
     * UPDATED LOGOUT FUNCTION WITH AUDIT TRAIL
     * ====================================================================
     *
     * Logs out the current user by destroying the session.
     */
    public function logout() {
        try {
            // Capture user data before destroying session (for audit)
            $user_id = $_SESSION['user_id'] ?? null;
            $user_name = $_SESSION['user_display_name'] ?? 'Unknown';
            $user_role = $_SESSION['user_role'] ?? 'Unknown';
            $login_time = $_SESSION['login_time'] ?? null;
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            
            // Calculate session duration if login time exists
            $session_duration = null;
            if ($login_time) {
                $duration_seconds = time() - $login_time;
                $hours = floor($duration_seconds / 3600);
                $minutes = floor(($duration_seconds % 3600) / 60);
                $seconds = $duration_seconds % 60;
                $session_duration = sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
            }
            
            // AUDIT: Logout (only if user was logged in)
            if ($user_id && class_exists('AuditLogger')) {
                AuditLogger::logAuth('logged_out', $user_id, [
                    'user_name' => $user_name,
                    'user_role' => $user_role,
                    'ip_address' => $ip_address,
                    'login_time' => $login_time ? date('Y-m-d H:i:s', $login_time) : null,
                    'logout_time' => date('Y-m-d H:i:s'),
                    'session_duration' => $session_duration,
                    'session_id' => session_id(),
                    'description' => "User {$user_name} logged out" . ($session_duration ? " (Session: {$session_duration})" : "")
                ]);
            }
            
            // Unset all session variables
            $_SESSION = array();

            // If it's desired to kill the session, also delete the session cookie.
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }

            // Finally, destroy the session.
            session_destroy();
            
        } catch (Exception $e) {
            error_log('Logout error: ' . $e->getMessage());
        }
    }

    /**
     * ====================================================================
     * NEW HELPER METHOD: Update last login information
     * ====================================================================
     */
    private function updateLastLogin($user_id, $ip_address) {
        try {
            // Check if the users table has last_login and last_login_ip columns
            // This is optional - if columns don't exist, just silently fail
            $sql = "UPDATE users SET 
                    last_login = NOW(), 
                    last_login_ip = ? 
                    WHERE id = ?";
            $this->_db->query($sql, [$ip_address, $user_id]);
        } catch (Exception $e) {
            // Silently fail - this is an optional feature
            error_log('Update last login error (optional): ' . $e->getMessage());
        }
    }

    /**
     * ====================================================================
     * THIS IS THE CORRECTED isLoggedIn FUNCTION
     * ====================================================================
     */
    public function isLoggedIn()
    {
        // We now check for 'user_id' which is set upon successful login
        return isset($_SESSION['user_id']);
    }
}