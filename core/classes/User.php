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
     * THIS IS THE CLEAN, PRODUCTION-READY LOGIN FUNCTION
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
                    $_SESSION['user_name'] = $user->display_name; 
                    $_SESSION['user_email'] = $user->email;
                    $_SESSION['user_role'] = $user->role;
                    $_SESSION['logged_in'] = true;

                    // 6. Return true for success
                    return true;
                }
            }

            // If user not found or password incorrect
            return false;

        } catch (Exception $e) {
            // Handle database errors
            error_log('Login error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Logs out the current user by destroying the session.
     */
    public function logout() {
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

