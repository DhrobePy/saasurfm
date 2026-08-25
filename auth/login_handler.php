<?php
// new_ufmhrm/auth/login_handler.php

// This file gets the $db variable from init.php
require_once __DIR__ . '/../core/init.php';

// We must check if the 'login' POST variable and 'email'/'password' are set
if (isset($_POST['login']) && isset($_POST['email']) && isset($_POST['password'])) {
    
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    // Pass the globally available $db object to the User class
    // $db is created in core/init.php
    $user = new User($db);  
    $login = $user->login($email, $password);

    if ($login) {
        // ==========================================================
        // THIS IS THE UPDATE:
        // Instead of sending to /admin, send to the root index.php,
        // which will act as our central router.
        // ==========================================================
        $_SESSION['success_flash'] = 'Login successful! Redirecting...';
        // Return the user to the page they were headed for (e.g. the delivery QR
        // confirm page) if we saved one, otherwise the central router.
        $dest = function_exists('safe_return_to') ? safe_return_to() : null;
        header('Location: ' . ($dest ?? '../index.php'));
        exit();
    
    } else {
        // Failure: Set an error and redirect back to the login page
        // The User->login() method handles invalid/inactive accounts.
        $_SESSION['error_flash'] = 'Invalid credentials or inactive account. Please try again.';
        header('Location: login.php');
        exit();
    }
} else {
    // Redirect if someone tries to access this page directly
    // or with incomplete POST data
    header('Location: login.php');
    exit();
}
?>