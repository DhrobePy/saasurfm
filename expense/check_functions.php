<?php
/**
 * QUICK CHECK - Test if permission functions exist
 * Upload to /expense/check_functions.php
 */

// Start session
session_start();

echo "<h1>Permission Functions Check</h1>";

// Check if functions exist
$functions = [
    'canAccessExpenseHistory',
    'canDeleteExpense',
    'canEditExpense',
    'canSeeExpenseDashboard'
];

echo "<h2>Function Existence:</h2>";
foreach ($functions as $func) {
    if (function_exists($func)) {
        echo "✅ $func EXISTS<br>";
    } else {
        echo "❌ $func MISSING!<br>";
    }
}

// Check session
echo "<h2>Session Data:</h2>";
echo "User Role: " . ($_SESSION['user_role'] ?? 'NOT SET') . "<br>";
echo "User ID: " . ($_SESSION['user_id'] ?? 'NOT SET') . "<br>";

// Try to load helpers manually
echo "<h2>Try Loading helpers.php:</h2>";
$helpersPath = __DIR__ . '/../core/functions/helpers.php';
if (file_exists($helpersPath)) {
    echo "helpers.php exists at: $helpersPath<br>";
    require_once $helpersPath;
    
    echo "<h3>After loading helpers.php:</h3>";
    foreach ($functions as $func) {
        if (function_exists($func)) {
            echo "✅ $func NOW EXISTS<br>";
        } else {
            echo "❌ $func STILL MISSING!<br>";
        }
    }
} else {
    echo "❌ helpers.php NOT FOUND!<br>";
}

echo "<hr>";
echo "<h2>SOLUTION:</h2>";
if (!function_exists('canAccessExpenseHistory')) {
    echo "<p style='color: red; font-size: 18px;'><strong>PROBLEM: helpers.php on your server does NOT have the expense permission functions!</strong></p>";
    echo "<p><strong>You MUST upload the updated helpers-COMPLETE.php file and replace /core/functions/helpers.php</strong></p>";
} else {
    echo "<p style='color: green;'><strong>Functions exist! Check session data above.</strong></p>";
}
?>