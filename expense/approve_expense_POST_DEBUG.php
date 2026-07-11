<?php
/**
 * DEBUG VERSION OF APPROVE_EXPENSE.PHP
 * This will show EXACTLY what happens when you click "Approve"
 * 
 * Upload to: /expense/approve_expense_POST_DEBUG.php
 */

require_once '../core/init.php';
require_once '../core/classes/ExpenseManager.php';

global $db;

// Log everything to a file
$logFile = __DIR__ . '/approval_debug.log';
function debugLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

debugLog("=== NEW REQUEST ===");
debugLog("REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
debugLog("Session user_id: " . ($_SESSION['user_id'] ?? 'NOT SET'));
debugLog("Session user_role: " . ($_SESSION['user_role'] ?? 'NOT SET'));

// =============================================
// PERMISSION CHECK (same as original)
// =============================================
echo "<pre style='background: #fff3cd; border: 2px solid #ffc107; padding: 20px; margin: 10px;'>";
echo "<h2>🔍 PERMISSION CHECK DEBUG</h2>\n";

echo "<h3>1. Before Permission Check:</h3>\n";
echo "Session user_id: " . ($_SESSION['user_id'] ?? 'NOT SET') . "\n";
echo "Session user_role: " . ($_SESSION['user_role'] ?? 'NOT SET') . "\n";
echo "Function exists: " . (function_exists('canApproveExpense') ? 'YES' : 'NO') . "\n";

if (function_exists('canApproveExpense')) {
    $canApprove = canApproveExpense();
    echo "canApproveExpense() returns: " . ($canApprove ? 'TRUE ✅' : 'FALSE ❌') . "\n";
    debugLog("canApproveExpense() returns: " . ($canApprove ? 'TRUE' : 'FALSE'));
    
    if (!$canApprove) {
        echo "\n<h3 style='color: red;'>⚠️ PERMISSION CHECK FAILED!</h3>\n";
        echo "This is where the redirect would happen.\n";
        debugLog("PERMISSION DENIED - Would redirect to unauthorized.php");
        echo "\n<strong>Redirecting to unauthorized.php...</strong>\n";
        echo "</pre>";
        
        // Show what would happen
        echo "<p style='background: #f8d7da; padding: 20px; margin: 10px; border: 2px solid #f5c6cb;'>";
        echo "🚫 <strong>BLOCKED:</strong> Permission check failed. Normally would redirect to unauthorized.php here.";
        echo "</p>";
        
        exit();
    }
} else {
    echo "\n<h3 style='color: red;'>⚠️ FUNCTION NOT FOUND!</h3>\n";
    echo "canApproveExpense() doesn't exist!\n";
    debugLog("ERROR: canApproveExpense() function not found");
    exit();
}

echo "\n<h3 style='color: green;'>✅ Permission Check PASSED!</h3>\n";
debugLog("Permission check PASSED - proceeding");

echo "</pre>";

// =============================================
// HANDLE POST REQUEST
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<pre style='background: #d1ecf1; border: 2px solid #0c5460; padding: 20px; margin: 10px;'>";
    echo "<h2>📤 POST REQUEST DEBUG</h2>\n";
    
    $action = $_POST['action'] ?? '';
    $voucher_id = (int)($_POST['voucher_id'] ?? 0);
    
    echo "Action: " . htmlspecialchars($action) . "\n";
    echo "Voucher ID: $voucher_id\n";
    
    debugLog("POST Request - Action: $action, Voucher: $voucher_id");
    
    if ($action === 'approve' && $voucher_id) {
        echo "\n<h3>Attempting to approve voucher #$voucher_id</h3>\n";
        
        // Check session again before approval
        echo "\nSession check before approval:\n";
        echo "- user_id: " . ($_SESSION['user_id'] ?? 'NOT SET') . "\n";
        echo "- user_role: " . ($_SESSION['user_role'] ?? 'NOT SET') . "\n";
        
        debugLog("Before approval - Session user_id: " . ($_SESSION['user_id'] ?? 'NOT SET'));
        
        try {
            // Get Database instance
            $dbInstance = Database::getInstance();
            $expenseManager = new ExpenseManager($dbInstance);
            
            echo "\n✅ ExpenseManager created successfully\n";
            debugLog("ExpenseManager instance created");
            
            // Get current user ID for approval
            $approver_id = $_SESSION['user_id'] ?? null;
            echo "Approver ID: " . ($approver_id ?? 'NOT SET') . "\n";
            
            if (!$approver_id) {
                echo "\n<strong style='color: red;'>❌ ERROR: No user_id in session!</strong>\n";
                debugLog("ERROR: No approver_id in session");
                echo "</pre>";
                exit();
            }
            
            echo "\nCalling approveExpenseVoucher($voucher_id, $approver_id)...\n";
            debugLog("Calling approveExpenseVoucher($voucher_id, $approver_id)");
            
            // Call the approval method
            $result = $expenseManager->approveExpenseVoucher($voucher_id, $approver_id);
            
            echo "\n<h3>Approval Result:</h3>\n";
            echo "Success: " . ($result['success'] ? 'TRUE ✅' : 'FALSE ❌') . "\n";
            echo "Message: " . htmlspecialchars($result['message']) . "\n";
            
            debugLog("Approval result - Success: " . ($result['success'] ? 'TRUE' : 'FALSE'));
            debugLog("Approval result - Message: " . $result['message']);
            
            if ($result['success']) {
                $_SESSION['success_flash'] = $result['message'];
                echo "\n<h3 style='color: green;'>✅ APPROVAL SUCCESSFUL!</h3>\n";
                echo "Flash message set: " . $result['message'] . "\n";
                debugLog("SUCCESS - Flash message set");
            } else {
                $_SESSION['error_flash'] = $result['message'];
                echo "\n<h3 style='color: red;'>❌ APPROVAL FAILED!</h3>\n";
                echo "Error message set: " . $result['message'] . "\n";
                debugLog("FAILURE - Error message set");
            }
            
            echo "\n<h3>Next Step: Redirect</h3>\n";
            echo "Would redirect to: " . url('expense/approve_expense.php') . "\n";
            debugLog("Would redirect to approve_expense.php");
            
            // Check session one more time before redirect
            echo "\nSession check before redirect:\n";
            echo "- user_id: " . ($_SESSION['user_id'] ?? 'NOT SET') . "\n";
            echo "- user_role: " . ($_SESSION['user_role'] ?? 'NOT SET') . "\n";
            debugLog("Before redirect - Session user_id: " . ($_SESSION['user_id'] ?? 'NOT SET'));
            
            echo "\n<hr>\n";
            echo "<h3>🔄 SIMULATED REDIRECT</h3>\n";
            echo "The page would now redirect back to approve_expense.php\n";
            echo "And go through the permission check AGAIN.\n";
            echo "\n";
            
            // Test permission again (simulating what happens after redirect)
            echo "Testing permission check again (as if we redirected):\n";
            $canApproveAfter = canApproveExpense();
            echo "canApproveExpense() returns: " . ($canApproveAfter ? 'TRUE ✅' : 'FALSE ❌') . "\n";
            debugLog("After approval - canApproveExpense: " . ($canApproveAfter ? 'TRUE' : 'FALSE'));
            
            if (!$canApproveAfter) {
                echo "\n<strong style='color: red;'>⚠️ PROBLEM FOUND!</strong>\n";
                echo "Permission check PASSES before approval but FAILS after!\n";
                echo "This would cause redirect to unauthorized.php!\n";
                debugLog("PROBLEM: Permission fails after approval!");
            } else {
                echo "\n<strong style='color: green;'>✅ ALL GOOD!</strong>\n";
                echo "Permission check still passes after approval.\n";
                echo "Should successfully load approve_expense.php\n";
                debugLog("SUCCESS: Permission still valid after approval");
            }
            
        } catch (Exception $e) {
            echo "\n<h3 style='color: red;'>💥 EXCEPTION CAUGHT!</h3>\n";
            echo "Error: " . htmlspecialchars($e->getMessage()) . "\n";
            echo "File: " . $e->getFile() . "\n";
            echo "Line: " . $e->getLine() . "\n";
            echo "\nStack Trace:\n" . htmlspecialchars($e->getTraceAsString()) . "\n";
            
            debugLog("EXCEPTION: " . $e->getMessage());
            debugLog("File: " . $e->getFile() . " Line: " . $e->getLine());
        }
        
        echo "</pre>";
        
    } elseif ($action === 'reject' && $voucher_id) {
        echo "\n<h3>Rejection request detected</h3>\n";
        echo "Voucher ID: $voucher_id\n";
        echo "This debug script only tests approval.\n";
        echo "</pre>";
    } else {
        echo "\n<h3 style='color: orange;'>⚠️ Invalid POST data</h3>\n";
        echo "Action: " . htmlspecialchars($action) . "\n";
        echo "Voucher ID: $voucher_id\n";
        echo "</pre>";
    }
    
    echo "<hr>";
    echo "<h3>Debug Log Contents:</h3>";
    echo "<pre style='background: #f8f9fa; padding: 10px;'>";
    echo file_get_contents($logFile);
    echo "</pre>";
    
    exit();
}

// =============================================
// IF GET REQUEST - SHOW TEST FORM
// =============================================
?>
<!DOCTYPE html>
<html>
<head>
    <title>Approval POST Debug</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .box { background: white; padding: 20px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #333; }
        .success { border-color: #4CAF50; }
        .warning { border-color: #ff9800; }
        h2 { color: #333; }
        button { padding: 10px 20px; margin: 5px; cursor: pointer; border: none; border-radius: 5px; }
        .approve-btn { background: #4CAF50; color: white; }
        .test-btn { background: #2196F3; color: white; }
    </style>
</head>
<body>

<h1>🧪 Approval POST Request Debugger</h1>

<div class="box success">
    <h2>✅ Permission Check Passed!</h2>
    <p>You have permission to access this page.</p>
    <p>Now test what happens when you submit an approval...</p>
</div>

<div class="box warning">
    <h2>Test Approval</h2>
    <p>Click the button below to simulate approving a voucher.</p>
    <p>This will show you EXACTLY what happens during the POST request.</p>
    
    <form method="POST">
        <input type="hidden" name="action" value="approve">
        <label>Voucher ID to test: 
            <input type="number" name="voucher_id" value="1" required>
        </label>
        <br><br>
        <button type="submit" class="approve-btn">
            🧪 Test Approval (Debug Mode)
        </button>
    </form>
</div>

<div class="box">
    <h2>What This Will Show:</h2>
    <ul>
        <li>✅ Permission check before approval</li>
        <li>✅ Session data during POST</li>
        <li>✅ Approval method execution</li>
        <li>✅ Result of approval</li>
        <li>✅ Permission check after approval</li>
        <li>✅ Whether redirect would work</li>
        <li>✅ Complete debug log</li>
    </ul>
</div>

<div class="box">
    <h2>Alternative Tests:</h2>
    <p><a href="test_permissions.php" class="test-btn" style="display: inline-block; padding: 10px; text-decoration: none;">Test Permissions</a></p>
    <p><a href="approve_expense.php" class="test-btn" style="display: inline-block; padding: 10px; text-decoration: none;">Go to Real Approval Page</a></p>
</div>

</body>
</html>