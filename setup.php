<?php
// Database setup script - Run this once to initialize the system
require_once __DIR__ . '/core/config/config.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create database if it doesn't exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS " . DB_NAME);
    $pdo->exec("USE " . DB_NAME);
    
    // Read and execute SQL schema
    $sql = file_get_contents(__DIR__ . '/database_schema.sql');
    $pdo->exec($sql);
    
    // Create default super admin user
    $username = 'superadmin';
    $password = 'admin123'; // Change this in production!
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("INSERT IGNORE INTO users (username, password, role) VALUES (?, ?, ?)");
    $stmt->execute([$username, $hashed_password, 'superadmin']);
    
    echo "Database setup completed successfully!<br>";
    echo "Default login: superadmin / admin123<br>";
    echo "<strong>Please delete this file after setup and change the default password!</strong>";
    
} catch(PDOException $e) {
    die("Setup failed: " . $e->getMessage());
}
?>
