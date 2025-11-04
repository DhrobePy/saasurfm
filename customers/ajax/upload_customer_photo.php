<?php
/**
 * AJAX Endpoint - Upload Customer Photo
 * Handles ONLY photo uploads to avoid security false positives
 */

require_once '../../core/init.php';

// Security check
$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-rampura', 'accounts-srg', 'accounts-demra'];
restrict_access($allowed_roles);

global $db;

header('Content-Type: application/json');

try {
    // Validate request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    if (!isset($_POST['customer_id']) || !isset($_FILES['photo'])) {
        throw new Exception('Missing required data');
    }
    
    $customer_id = (int)$_POST['customer_id'];
    
    // Verify customer exists
    $customer = $db->query("SELECT id, photo_url FROM customers WHERE id = ?", [$customer_id])->first();
    if (!$customer) {
        throw new Exception('Customer not found');
    }
    
    // Validate file upload
    if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload error: ' . $_FILES['photo']['error']);
    }
    
    // Validate file type
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $file_type = $_FILES['photo']['type'];
    if (!in_array($file_type, $allowed_types)) {
        throw new Exception('Invalid file type. Only JPG, PNG, GIF, WEBP allowed.');
    }
    
    // Validate file size (2MB max)
    $max_size = 2 * 1024 * 1024; // 2MB in bytes
    if ($_FILES['photo']['size'] > $max_size) {
        throw new Exception('File too large. Maximum size is 2MB.');
    }
    
    // Create upload directory if needed
    $upload_dir = '../../uploads/profiles/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Delete old photo if exists
    if ($customer->photo_url && file_exists('../../' . $customer->photo_url)) {
        unlink('../../' . $customer->photo_url);
    }
    
    // Generate unique filename
    $extension = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
    $filename = 'customer_' . $customer_id . '_' . time() . '.' . $extension;
    $photo_path = 'uploads/profiles/' . $filename;
    $full_path = '../../' . $photo_path;
    
    // Move uploaded file
    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $full_path)) {
        throw new Exception('Failed to save photo');
    }
    
    // Update database
    $db->query("UPDATE customers SET photo_url = ? WHERE id = ?", [$photo_path, $customer_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Photo uploaded successfully',
        'photo_url' => $photo_path
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>