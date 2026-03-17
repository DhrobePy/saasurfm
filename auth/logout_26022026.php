<?php
// new_ufmhrm/auth/logout.php
require_once __DIR__ . '/../core/init.php';

// $db is created in init.php
// We MUST pass it to the User constructor
$user = new User($db);

// Call the new logout method
$user->logout();

// Redirect to login page
header('Location: login.php');
exit();
?>

