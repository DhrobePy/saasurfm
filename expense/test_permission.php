<?php
/**
 * SESSION DEBUG TOOL
 * Upload to /test_session.php
 * Access this directly to check session status
 */

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Session Debug Tool</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .box { background: white; padding: 20px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #333; }
        .success { border-color: #4CAF50; }
        .error { border-color: #f44336; }
        .warning { border-color: #ff9800; }
        .info { border-color: #2196F3; }
        pre { background: #f0f0f0; padding: 10px; overflow: auto; }
        .yes { color: #4CAF50; font-weight: bold; }
        .no { color: #f44336; font-weight: bold; }
        code { background: #f0f0f0; padding: 2px 5px; border-radius: 3px; }
        h2 { color: #333; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f0f0f0; }
    </style>
</head>
<body>

<h1>🔍 Session Debug Tool</h1>

<div class="box info">
    <h2>Session Status</h2>
    <table>
        <tr>
            <th>Check</th>
            <th>Status</th>
        </tr>
        <tr>
            <td>Session Started?</td>
            <td><?php echo (session_status() === PHP_SESSION_ACTIVE) ? '<span class="yes">✅ YES</span>' : '<span class="no">❌ NO</span>'; ?></td>
        </tr>
        <tr>
            <td>Session ID</td>
            <td><?php echo session_id() ? session_id() : '<span class="no">No Session ID</span>'; ?></td>
        </tr>
        <tr>
            <td>Session Name</td>
            <td><?php echo session_name(); ?></td>
        </tr>
        <tr>
            <td>Session Save Path</td>
            <td><?php echo session_save_path(); ?></td>
        </tr>
    </table>
</div>

<div class="box <?php echo !empty($_SESSION) ? 'success' : 'error'; ?>">
    <h2>Session Data</h2>
    <?php if (!empty($_SESSION)): ?>
        <p class="yes">✅ Session has data!</p>
        <pre><?php print_r($_SESSION); ?></pre>
    <?php else: ?>
        <p class="no">❌ Session is EMPTY!</p>
        <p>This means:</p>
        <ul>
            <li>User is not logged in, OR</li>
            <li>Session is not being maintained, OR</li>
            <li>Login process is not setting session variables</li>
        </ul>
    <?php endif; ?>
</div>

<div class="box info">
    <h2>Session Variables Expected</h2>
    <p>For a logged-in user, we expect these session variables:</p>
    <table>
        <tr>
            <th>Variable</th>
            <th>Status</th>
            <th>Value</th>
        </tr>
        <tr>
            <td><code>$_SESSION['user_id']</code></td>
            <td><?php echo isset($_SESSION['user_id']) ? '<span class="yes">✅ SET</span>' : '<span class="no">❌ NOT SET</span>'; ?></td>
            <td><?php echo $_SESSION['user_id'] ?? '<em>N/A</em>'; ?></td>
        </tr>
        <tr>
            <td><code>$_SESSION['user_role']</code></td>
            <td><?php echo isset($_SESSION['user_role']) ? '<span class="yes">✅ SET</span>' : '<span class="no">❌ NOT SET</span>'; ?></td>
            <td><?php echo $_SESSION['user_role'] ?? '<em>N/A</em>'; ?></td>
        </tr>
        <tr>
            <td><code>$_SESSION['user_display_name']</code></td>
            <td><?php echo isset($_SESSION['user_display_name']) ? '<span class="yes">✅ SET</span>' : '<span class="no">❌ NOT SET</span>'; ?></td>
            <td><?php echo $_SESSION['user_display_name'] ?? '<em>N/A</em>'; ?></td>
        </tr>
        <tr>
            <td><code>$_SESSION['user_email']</code></td>
            <td><?php echo isset($_SESSION['user_email']) ? '<span class="yes">✅ SET</span>' : '<span class="no">❌ NOT SET</span>'; ?></td>
            <td><?php echo $_SESSION['user_email'] ?? '<em>N/A</em>'; ?></td>
        </tr>
    </table>
</div>

<div class="box warning">
    <h2>📋 Testing Instructions</h2>
    <ol>
        <li><strong>Login First:</strong> Go to <code>auth/login.php</code> and login</li>
        <li><strong>Check Dashboard:</strong> Make sure you can see your dashboard</li>
        <li><strong>Refresh This Page:</strong> Come back here and refresh</li>
        <li><strong>Check Session:</strong> Session data should now appear above</li>
    </ol>
</div>

<div class="box info">
    <h2>🔗 Quick Links</h2>
    <ul>
        <li><a href="../auth/login.php">Login Page</a></li>
        <li><a href="../index.php">Dashboard</a></li>
        <li><a href="test_permissions.php">Permission Test</a></li>
        <li><a href="approve_expense.php">Approve Expense (will fail if not logged in)</a></li>
    </ul>
</div>

<div class="box <?php echo !empty($_SESSION) ? 'success' : 'error'; ?>">
    <h2>📊 Result</h2>
    <?php if (!empty($_SESSION) && isset($_SESSION['user_id'])): ?>
        <h3 class="yes">✅ SESSION IS WORKING!</h3>
        <p>User is logged in as: <strong><?php echo $_SESSION['user_display_name'] ?? 'Unknown'; ?></strong></p>
        <p>Role: <strong><?php echo $_SESSION['user_role'] ?? 'Unknown'; ?></strong></p>
        <p>Now you can test the expense approval page.</p>
        <p><a href="test_permissions.php" style="background: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 10px;">Test Permissions Now</a></p>
    <?php else: ?>
        <h3 class="no">❌ NOT LOGGED IN</h3>
        <p><strong>You must login first!</strong></p>
        <p><a href="../auth/login.php" style="background: #2196F3; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 10px;">Go to Login Page</a></p>
    <?php endif; ?>
</div>

<div class="box info">
    <h2>🐛 Still Not Working After Login?</h2>
    <p>If you're logged in but this page shows empty session:</p>
    <ol>
        <li><strong>Check init.php:</strong> Make sure it has <code>session_start()</code></li>
        <li><strong>Check login code:</strong> Make sure it sets <code>$_SESSION['user_id']</code>, etc.</li>
        <li><strong>Check cookies:</strong> Make sure browser accepts cookies</li>
        <li><strong>Check session path:</strong> Make sure PHP can write to session directory</li>
    </ol>
</div>

</body>
</html>