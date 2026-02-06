<?php
require_once __DIR__ . '/core/init.php';

$pageTitle = "Access Denied";

// Get user info if logged in
$isLoggedIn = isset($_SESSION['user_id']);
$userName = $_SESSION['user_display_name'] ?? 'User';
$userRole = $_SESSION['user_role'] ?? 'Unknown';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Ujjal Flour Mills</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        .float-animation {
            animation: float 3s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% {
                transform: translateY(0px);
            }
            50% {
                transform: translateY(-20px);
            }
        }
        
        .bounce-animation {
            animation: bounce 2s infinite;
        }
        
        @keyframes bounce {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-10px);
            }
        }
        
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .card-shadow {
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
    </style>
</head>
<body class="gradient-bg min-h-screen flex items-center justify-center p-4">

    <div class="max-w-2xl w-full">
        
        <!-- Main Card -->
        <div class="bg-white rounded-2xl card-shadow overflow-hidden">
            
            <!-- Icon Section -->
            <div class="bg-gradient-to-r from-red-500 to-pink-500 p-8 text-center">
                <div class="float-animation inline-block">
                    <div class="bg-white rounded-full w-32 h-32 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-lock text-6xl text-red-500"></i>
                    </div>
                </div>
                <h1 class="text-4xl font-bold text-white mb-2">Access Denied</h1>
                <p class="text-red-100 text-lg">Oops! You don't have permission to access this page</p>
            </div>
            
            <!-- Content Section -->
            <div class="p-8">
                
                <?php if ($isLoggedIn): ?>
                <!-- Logged in user -->
                <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6 rounded">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-info-circle text-blue-500 text-2xl"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-blue-700">
                                <strong>Logged in as:</strong> <?php echo htmlspecialchars($userName); ?>
                                <span class="ml-2 text-xs bg-blue-200 px-2 py-1 rounded"><?php echo htmlspecialchars($userRole); ?></span>
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="space-y-4 mb-6">
                    <div class="flex items-start">
                        <i class="fas fa-shield-alt text-gray-400 mt-1 mr-3"></i>
                        <p class="text-gray-600">This page requires specific permissions that your current role doesn't have.</p>
                    </div>
                    
                    <div class="flex items-start">
                        <i class="fas fa-user-lock text-gray-400 mt-1 mr-3"></i>
                        <p class="text-gray-600">If you believe you should have access, please contact your system administrator.</p>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <a href="<?php echo url('index.php'); ?>" 
                       class="flex items-center justify-center px-6 py-3 bg-gradient-to-r from-blue-500 to-blue-600 text-white rounded-lg hover:from-blue-600 hover:to-blue-700 transition shadow-md">
                        <i class="fas fa-home mr-2"></i>
                        Go to Dashboard
                    </a>
                    
                    <button onclick="window.history.back()" 
                            class="flex items-center justify-center px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                        <i class="fas fa-arrow-left mr-2"></i>
                        Go Back
                    </button>
                </div>
                
                <?php else: ?>
                <!-- Not logged in -->
                <div class="bg-yellow-50 border-l-4 border-yellow-500 p-4 mb-6 rounded">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-triangle text-yellow-500 text-2xl"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-yellow-700">
                                <strong>Not logged in</strong> - You need to log in to access this page.
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="text-center">
                    <a href="<?php echo url('auth/login.php'); ?>" 
                       class="inline-flex items-center px-8 py-3 bg-gradient-to-r from-green-500 to-green-600 text-white rounded-lg hover:from-green-600 hover:to-green-700 transition shadow-md text-lg font-semibold">
                        <i class="fas fa-sign-in-alt mr-2"></i>
                        Log In Now
                    </a>
                </div>
                <?php endif; ?>
                
            </div>
            
            <!-- Footer -->
            <div class="bg-gray-50 px-8 py-4 border-t">
                <div class="flex items-center justify-between text-sm text-gray-600">
                    <div class="flex items-center">
                        <i class="fas fa-question-circle mr-2"></i>
                        Need help?
                    </div>
                    <a href="#" onclick="alert('Contact your system administrator for assistance.'); return false;" 
                       class="text-blue-600 hover:text-blue-700 font-medium">
                        Contact Support
                    </a>
                </div>
            </div>
            
        </div>
        
        <!-- Additional Info -->
        <div class="mt-6 text-center">
            <p class="text-white text-sm opacity-75">
                <i class="fas fa-shield-alt mr-1"></i>
                Your activity is logged for security purposes
            </p>
        </div>
        
    </div>

    <!-- Cute floating elements -->
    <div class="fixed top-10 left-10 opacity-20 bounce-animation hidden md:block">
        <i class="fas fa-ban text-white text-6xl"></i>
    </div>
    
    <div class="fixed bottom-10 right-10 opacity-20 float-animation hidden md:block">
        <i class="fas fa-user-slash text-white text-6xl"></i>
    </div>

</body>
</html>