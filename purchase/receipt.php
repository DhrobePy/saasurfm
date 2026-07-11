<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Receipt Debug</h1>";

echo "<h2>1. Checking init.php</h2>";
require_once '../core/init.php';
echo "✓ Init loaded<br>";

echo "<h2>2. Current User</h2>";
$user = getCurrentUser();
echo "<pre>" . print_r($user, true) . "</pre>";

echo "<h2>3. Session Data</h2>";
echo "<pre>" . print_r($_SESSION, true) . "</pre>";

echo "<h2>4. Role Check</h2>";
echo "Your role: " . ($user['role'] ?? 'NOT SET') . "<br>";

$allowed = ['Superadmin', 'admin', 'Accounts', 'accounts-demra', 'accounts-srg'];
echo "Allowed roles: " . implode(', ', $allowed) . "<br>";
echo "Role match: " . (in_array($user['role'] ?? '', $allowed) ? 'YES ✓' : 'NO ✗') . "<br>";

echo "<h2>5. Testing restrict_access</h2>";
try {
    restrict_access($allowed);
    echo "✓ PASSED restrict_access<br>";
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "<br>";
}

echo "<h2>6. Database Connection</h2>";
try {
    $db = Database::getInstance()->getPdo();
    echo "✓ Database connected<br>";
} catch (Exception $e) {
    echo "✗ Database failed: " . $e->getMessage() . "<br>";
}

?>