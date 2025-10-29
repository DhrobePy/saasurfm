<?php
// new_ufmhrm/index.php
// This is the main router for all logged-in users.

require_once __DIR__ . '/core/init.php';

// 1. Check if the user is logged in.
// If not, send them to the login page.
if (!is_admin_logged_in()) { // <-- We will update this function in helpers.php
    header('Location: auth/login.php');
    exit();
}

// 2. Get the user's role from the session.
$role = $_SESSION['user_role'] ?? null;

// 3. Route the user based on their role.
switch ($role) {
    // --- Admin Roles ---
    case 'Superadmin':
    case 'admin':
        header('Location: admin/index.php');
        exit();

    // --- Accounts Roles ---
    case 'Accounts':
    case 'accounts-rampura':
    case 'accounts-srg':
    case 'accounts-demra':
    case 'accountspos-demra':
    case 'accountspos-srg':
        header('Location: accounts/index.php');
        exit();

    // --- Production Roles ---
    case 'production manager-srg':
    case 'production manager-demra':
        header('Location: production/index.php');
        exit();

    // --- Dispatch Roles ---
    case 'dispatch-srg':
    case 'dispatch-demra':
    case 'dispatchpos-demra':
    case 'dispatchpos-srg':
        header('Location: dispatch/index.php');
        exit();
    
    // --- Other Roles ---
    case 'sales-srg':
    case 'sales-demra':
    case 'sales-other':
        header('Location: sales/index.php');
        exit();

    case 'collector':
        header('Location: collector/index.php');
        exit();

    // --- Employee Role (if you add one) ---
    // case 'employee':
    //     header('Location: employee/index.php');
    //     exit();

    // --- Default / Fallback ---
    default:
        // If the role is unknown or not set, log them out for safety.
        $_SESSION['error_flash'] = 'Invalid user role assigned. Please contact support.';
        header('Location: auth/logout.php');
        exit();
}

