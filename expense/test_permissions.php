<?php
/**
 * PERMISSION TEST SCRIPT
 * Upload this to /expense/test_permissions.php
 * Access it directly to see what's happening
 */

require_once '../core/init.php';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Expense Permission Test</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .box { background: white; padding: 20px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #333; }
        .success { border-color: #4CAF50; }
        .error { border-color: #f44336; }
        .warning { border-color: #ff9800; }
        .info { border-color: #2196F3; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f0f0f0; font-weight: bold; }
        .yes { color: #4CAF50; font-weight: bold; }
        .no { color: #f44336; font-weight: bold; }
        code { background: #f0f0f0; padding: 2px 5px; border-radius: 3px; }
        h2 { color: #333; border-bottom: 2px solid #333; padding-bottom: 10px; }
    </style>
</head>
<body>

<h1>🔍 Expense Module Permission Test</h1>

<!-- TEST 1: SESSION DATA -->
<div class="box info">
    <h2>1. Session Data</h2>
    <table>
        <tr>
            <th>Key</th>
            <th>Value</th>
            <th>Status</th>
        </tr>
        <tr>
            <td><code>user_id</code></td>
            <td><?php echo $_SESSION['user_id'] ?? '<em>NOT SET</em>'; ?></td>
            <td><?php echo isset($_SESSION['user_id']) ? '<span class="yes">✅ SET</span>' : '<span class="no">❌ MISSING</span>'; ?></td>
        </tr>
        <tr>
            <td><code>user_role</code></td>
            <td><strong><?php echo $_SESSION['user_role'] ?? '<em>NOT SET</em>'; ?></strong></td>
            <td><?php echo isset($_SESSION['user_role']) ? '<span class="yes">✅ SET</span>' : '<span class="no">❌ MISSING</span>'; ?></td>
        </tr>
        <tr>
            <td><code>user_display_name</code></td>
            <td><?php echo $_SESSION['user_display_name'] ?? '<em>NOT SET</em>'; ?></td>
            <td><?php echo isset($_SESSION['user_display_name']) ? '<span class="yes">✅ SET</span>' : '<span class="no">❌ MISSING</span>'; ?></td>
        </tr>
        <tr>
            <td><code>user_email</code></td>
            <td><?php echo $_SESSION['user_email'] ?? '<em>NOT SET</em>'; ?></td>
            <td><?php echo isset($_SESSION['user_email']) ? '<span class="yes">✅ SET</span>' : '<span class="no">❌ MISSING</span>'; ?></td>
        </tr>
    </table>
</div>

<!-- TEST 2: DATABASE VERIFICATION -->
<div class="box <?php echo isset($_SESSION['user_id']) ? 'info' : 'error'; ?>">
    <h2>2. Database Verification</h2>
    <?php
    global $db;
    if (isset($_SESSION['user_id'])) {
        try {
            $stmt = $db->query("SELECT id, display_name, email, role FROM users WHERE id = ?", [$_SESSION['user_id']]);
            $user = $stmt->first();
            
            if ($user) {
                echo "<table>";
                echo "<tr><th>Field</th><th>Database Value</th><th>Session Value</th><th>Match?</th></tr>";
                
                echo "<tr>";
                echo "<td>ID</td>";
                echo "<td>{$user->id}</td>";
                echo "<td>" . ($_SESSION['user_id'] ?? 'N/A') . "</td>";
                echo "<td>" . ($user->id == ($_SESSION['user_id'] ?? null) ? '<span class="yes">✅</span>' : '<span class="no">❌</span>') . "</td>";
                echo "</tr>";
                
                echo "<tr>";
                echo "<td>Name</td>";
                echo "<td>{$user->display_name}</td>";
                echo "<td>" . ($_SESSION['user_display_name'] ?? 'N/A') . "</td>";
                echo "<td>" . ($user->display_name == ($_SESSION['user_display_name'] ?? null) ? '<span class="yes">✅</span>' : '<span class="no">❌</span>') . "</td>";
                echo "</tr>";
                
                echo "<tr>";
                echo "<td><strong>Role</strong></td>";
                echo "<td><strong>{$user->role}</strong></td>";
                echo "<td><strong>" . ($_SESSION['user_role'] ?? 'N/A') . "</strong></td>";
                echo "<td>" . ($user->role == ($_SESSION['user_role'] ?? null) ? '<span class="yes">✅</span>' : '<span class="no">❌ MISMATCH!</span>') . "</td>";
                echo "</tr>";
                
                echo "</table>";
                
                // Role details
                echo "<h3>Role Details:</h3>";
                echo "<ul>";
                echo "<li>Database role: <code>" . htmlspecialchars($user->role) . "</code></li>";
                echo "<li>Length: " . strlen($user->role) . " characters</li>";
                echo "<li>Trimmed: <code>" . htmlspecialchars(trim($user->role)) . "</code></li>";
                echo "<li>Lowercase: <code>" . htmlspecialchars(strtolower($user->role)) . "</code></li>";
                echo "</ul>";
                
            } else {
                echo "<p class='no'>❌ User not found in database!</p>";
            }
        } catch (Exception $e) {
            echo "<p class='no'>❌ Database Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    } else {
        echo "<p class='no'>❌ No user_id in session. User not logged in?</p>";
    }
    ?>
</div>

<!-- TEST 3: FUNCTION AVAILABILITY -->
<div class="box info">
    <h2>3. Permission Functions</h2>
    <table>
        <tr>
            <th>Function</th>
            <th>Exists?</th>
            <th>Returns</th>
        </tr>
        <?php
        $functions = [
            'canAccessExpense',
            'canCreateExpense',
            'canCreateExpenseVoucher',
            'canApproveExpense',
            'canAccessApproveExpense',
            'canViewExpense',
            'canEditExpense',
            'canDeleteExpense'
        ];
        
        foreach ($functions as $func) {
            echo "<tr>";
            echo "<td><code>{$func}()</code></td>";
            
            if (function_exists($func)) {
                echo "<td><span class='yes'>✅ EXISTS</span></td>";
                try {
                    $result = call_user_func($func);
                    echo "<td>" . ($result ? '<span class="yes">TRUE ✅</span>' : '<span class="no">FALSE ❌</span>') . "</td>";
                } catch (Exception $e) {
                    echo "<td><span class='no'>ERROR: " . htmlspecialchars($e->getMessage()) . "</span></td>";
                }
            } else {
                echo "<td><span class='no'>❌ MISSING</span></td>";
                echo "<td><em>N/A</em></td>";
            }
            
            echo "</tr>";
        }
        ?>
    </table>
</div>

<!-- TEST 4: ROLE MATCHING -->
<div class="box <?php echo function_exists('canApproveExpense') ? 'info' : 'error'; ?>">
    <h2>4. Role Matching Test</h2>
    <?php
    $currentRole = $_SESSION['user_role'] ?? 'NOT SET';
    $allowedRoles = ['Superadmin', 'Expense Approver'];
    
    echo "<p><strong>Current Role:</strong> <code>" . htmlspecialchars($currentRole) . "</code></p>";
    echo "<p><strong>Allowed Roles for Approval:</strong></p>";
    echo "<ul>";
    foreach ($allowedRoles as $role) {
        echo "<li><code>" . htmlspecialchars($role) . "</code></li>";
    }
    echo "</ul>";
    
    echo "<h3>Matching Tests:</h3>";
    echo "<table>";
    echo "<tr><th>Test Type</th><th>Result</th></tr>";
    
    // Exact match
    $exactMatch = in_array($currentRole, $allowedRoles);
    echo "<tr>";
    echo "<td>Exact Match (<code>in_array</code>)</td>";
    echo "<td>" . ($exactMatch ? '<span class="yes">✅ MATCH</span>' : '<span class="no">❌ NO MATCH</span>') . "</td>";
    echo "</tr>";
    
    // Case insensitive
    $caseInsensitive = in_array(strtolower($currentRole), array_map('strtolower', $allowedRoles));
    echo "<tr>";
    echo "<td>Case-Insensitive Match</td>";
    echo "<td>" . ($caseInsensitive ? '<span class="yes">✅ MATCH</span>' : '<span class="no">❌ NO MATCH</span>') . "</td>";
    echo "</tr>";
    
    // Trimmed match
    $trimmedMatch = in_array(trim($currentRole), array_map('trim', $allowedRoles));
    echo "<tr>";
    echo "<td>Trimmed Match</td>";
    echo "<td>" . ($trimmedMatch ? '<span class="yes">✅ MATCH</span>' : '<span class="no">❌ NO MATCH</span>') . "</td>";
    echo "</tr>";
    
    echo "</table>";
    
    if (!$exactMatch && !$caseInsensitive && !$trimmedMatch) {
        echo "<div class='box error' style='margin-top: 20px;'>";
        echo "<h3>⚠️ PROBLEM IDENTIFIED:</h3>";
        echo "<p>Your role <code>" . htmlspecialchars($currentRole) . "</code> doesn't match any allowed role!</p>";
        echo "<h4>Possible Issues:</h4>";
        echo "<ul>";
        echo "<li>Spelling mistake in database role</li>";
        echo "<li>Extra spaces in database role</li>";
        echo "<li>Different capitalization</li>";
        echo "<li>Role not in allowed list</li>";
        echo "</ul>";
        echo "<h4>Solutions:</h4>";
        echo "<ol>";
        echo "<li>Check your database: <code>SELECT role FROM users WHERE id = " . ($_SESSION['user_id'] ?? 'NULL') . "</code></li>";
        echo "<li>Make sure role is exactly: <code>Expense Approver</code> (with capital E and A)</li>";
        echo "<li>Or update helpers.php to include your actual role</li>";
        echo "</ol>";
        echo "</div>";
    }
    ?>
</div>

<!-- TEST 5: LOADED FILES -->
<div class="box info">
    <h2>5. Loaded Files</h2>
    <p>Checking if helpers.php is loaded...</p>
    <?php
    $loaded = get_included_files();
    $helpersFound = false;
    
    echo "<ul>";
    foreach ($loaded as $file) {
        if (strpos($file, 'helpers') !== false) {
            echo "<li class='yes'>✅ " . htmlspecialchars($file) . "</li>";
            $helpersFound = true;
        } elseif (strpos($file, 'init') !== false) {
            echo "<li>📄 " . htmlspecialchars($file) . "</li>";
        }
    }
    echo "</ul>";
    
    if (!$helpersFound) {
        echo "<p class='no'>⚠️ helpers.php not found in loaded files!</p>";
    }
    ?>
</div>

<!-- SUMMARY -->
<div class="box <?php 
    if (function_exists('canApproveExpense') && canApproveExpense()) {
        echo 'success';
    } else {
        echo 'error';
    }
?>">
    <h2>📊 Summary</h2>
    <?php
    if (function_exists('canApproveExpense') && canApproveExpense()) {
        echo "<h3 class='yes'>✅ ALL CHECKS PASSED!</h3>";
        echo "<p>You should be able to access the approval page.</p>";
        echo "<p><a href='approve_expense.php' style='background: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 10px;'>Go to Approval Page</a></p>";
    } else {
        echo "<h3 class='no'>❌ PERMISSION DENIED</h3>";
        echo "<p><strong>Reason:</strong> ";
        
        if (!function_exists('canApproveExpense')) {
            echo "Function <code>canApproveExpense()</code> doesn't exist.";
        } elseif (!isset($_SESSION['user_role'])) {
            echo "No role in session. User may not be logged in properly.";
        } else {
            $currentRole = $_SESSION['user_role'];
            echo "Your role '<code>" . htmlspecialchars($currentRole) . "</code>' is not authorized for approval.";
        }
        
        echo "</p>";
    }
    ?>
</div>

</body>
</html>