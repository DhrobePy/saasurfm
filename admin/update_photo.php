<?php
require_once __DIR__ . '/../core/init.php';

if (!is_logged_in() || !isset($_POST['update_photo'])) {
    header('Location: employees.php');
    exit();
}

$employee_id = (int)$_POST['employee_id'];

// Validate file upload
if (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
    header('Location: employee_profile.php?id=' . $employee_id . '&error=upload_failed');
    exit();
}

$file = $_FILES['profile_photo'];
$allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
$max_size = 2 * 1024 * 1024; // 2MB

// Get file extension
$file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

// Validate file type and extension
if (!in_array($file['type'], $allowed_types) || !in_array($file_extension, $allowed_extensions)) {
    header('Location: employee_profile.php?id=' . $employee_id . '&error=invalid_type');
    exit();
}

// Validate file size
if ($file['size'] > $max_size) {
    header('Location: employee_profile.php?id=' . $employee_id . '&error=file_too_large');
    exit();
}

// Validate image dimensions (optional)
$image_info = getimagesize($file['tmp_name']);
if ($image_info === false) {
    header('Location: employee_profile.php?id=' . $employee_id . '&error=invalid_image');
    exit();
}

// Create upload directory if it doesn't exist
$upload_dir = __DIR__ . '/../assets/img/profiles/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Get current profile picture to delete old one
$stmt = $pdo->prepare('SELECT profile_picture FROM employees WHERE id = ?');
$stmt->execute([$employee_id]);
$current_employee = $stmt->fetch();

// Generate unique filename
$filename = 'employee_' . $employee_id . '_' . time() . '.' . $file_extension;
$upload_path = $upload_dir . $filename;

// Move uploaded file
if (move_uploaded_file($file['tmp_name'], $upload_path)) {
    // Delete old profile picture if it exists
    if ($current_employee && $current_employee['profile_picture'] && 
        $current_employee['profile_picture'] !== 'default-avatar.png') {
        $old_file = $upload_dir . basename($current_employee['profile_picture']);
        if (file_exists($old_file)) {
            unlink($old_file);
        }
    }
    
    // Update database
    $stmt = $pdo->prepare('UPDATE employees SET profile_picture = ? WHERE id = ?');
    $success = $stmt->execute(['profiles/' . $filename, $employee_id]);
    
    if ($success) {
        // Log the activity
        $stmt = $pdo->prepare('
            INSERT INTO activity_log (user_id, action, description, created_at) 
            VALUES (?, ?, ?, NOW())
        ');
        $stmt->execute([
            $_SESSION['user_id'],
            'profile_photo_update',
            'Updated profile photo for employee ID: ' . $employee_id
        ]);
        
        header('Location: employee_profile.php?id=' . $employee_id . '&photo_updated=1');
    } else {
        // Delete uploaded file if database update failed
        unlink($upload_path);
        header('Location: employee_profile.php?id=' . $employee_id . '&error=db_update_failed');
    }
} else {
    header('Location: employee_profile.php?id=' . $employee_id . '&error=upload_failed');
}
exit();
?>
