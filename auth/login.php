<?php
// new_ufmhrm/auth/login.php

// *** THIS PATH IS NOW CORRECTED ***
require_once '../core/init.php';

// Redirect if already logged in — honor a saved return-to (e.g. the delivery QR
// page) so a logged-in scan lands where it should, else the central router.
if (is_admin_logged_in()) {
    $dest = safe_return_to();
    header('Location: ' . ($dest ?? url('index.php')));
    exit();
}

// Contextual hint when arriving from a QR / deep link (peek, don't consume).
$login_context = null;
$rt_peek = $_SESSION['return_to'] ?? '';
if (is_string($rt_peek) && stripos($rt_peek, 'verify_delivery.php') !== false) {
    parse_str((string) parse_url($rt_peek, PHP_URL_QUERY), $qp);
    $inv = trim($qp['inv'] ?? '');
    $login_context = $inv !== ''
        ? 'Sign in to confirm delivery of order ' . htmlspecialchars($inv, ENT_QUOTES)
        : 'Sign in to confirm the delivery.';
}

$pageTitle = 'Login - ' . APP_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#f0f9ff', 100: '#e0f2fe', 200: '#bae6fd', 300: '#7dd3fc', 400: '#38bdf8',
                            500: '#0ea5e9', 600: '#0284c7', 700: '#0369a1', 800: '#075985', 900: '#0c4a6e',
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gradient-to-br from-primary-50 to-primary-100 min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    
    <div class="max-w-md w-full space-y-8">
        <div class="text-center">
            <div class="mx-auto h-20 w-20 bg-primary-600 rounded-full flex items-center justify-center mb-6">
                <i class="fas fa-users text-white text-3xl"></i>
            </div>
            <h2 class="text-3xl font-bold text-gray-900 mb-2">Welcome Back</h2>
            <p class="text-gray-600">Sign in to your ERP account</p>
        </div>
        
        <div class="bg-white rounded-xl shadow-lg p-8">
            <?php if (!empty($login_context)): ?>
            <div class="mb-4 p-3 rounded-lg bg-indigo-50 border border-indigo-200 text-indigo-800 text-sm flex items-center gap-2">
                <i class="fas fa-truck-fast"></i><span><?php echo $login_context; ?></span>
            </div>
            <?php endif; ?>
            <?php echo display_message(); ?>
            
            <form action="login_handler.php" method="POST" class="space-y-6">
                <input type="hidden" name="login" value="1">
                
                <!-- === MODIFIED BLOCK === -->
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-envelope mr-2"></i>Email Address
                    </label>
                    <input type="email" id="email" name="email" required 
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors duration-200"
                           placeholder="Enter your email address">
                </div>
                <!-- === END MODIFIED BLOCK === -->
                
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-lock mr-2"></i>Password
                    </label>
                    <input type="password" id="password" name="password" required 
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors duration-200"
                           placeholder="Enter your password">
                </div>
                
                <button type="submit" 
                        class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-all duration-200 btn-animate">
                    <i class="fas fa-sign-in-alt mr-2"></i>
                    Sign In
                </button>
            </form>
        </div>
        
        <div class="text-center text-sm text-gray-500">
            <p>© <?php echo date('Y'); ?> ERP-UFM</p>
            <p class="mt-1">Comprehensive ERP</p>
        </div>
    </div>
    
</body>
</html>