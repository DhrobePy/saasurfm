<?php
require_once '../core/init.php';

header('Content-Type: application/json');

// Only Superadmin and admin can reopen EOD
$allowed_roles = ['Superadmin', 'admin'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$user_role = $currentUser['role'] ?? '';

// Get POST data
$post_data = json_decode(file_get_contents('php://input'), true);
$eod_id = $post_data['eod_id'] ?? null;
$branch_id = $post_data['branch_id'] ?? null;
$reason = $post_data['reason'] ?? '';

try {
    if (!$eod_id) {
        throw new Exception("EOD ID is required.");
    }
    
    if (!$branch_id) {
        throw new Exception("Branch ID is required.");
    }
    
    if (empty(trim($reason))) {
        throw new Exception("Reason for reopening is required.");
    }
    
    // Verify EOD exists
    $eod = $db->query("SELECT * FROM eod_summary WHERE id = ? AND branch_id = ?", [$eod_id, $branch_id])->first();
    if (!$eod) {
        throw new Exception("EOD record not found.");
    }
    
    // Check if there's a newer EOD (can't reopen if there are subsequent EODs)
    $newer_eod = $db->query("SELECT id FROM eod_summary WHERE branch_id = ? AND eod_date > ? LIMIT 1", 
                            [$branch_id, $eod->eod_date])->first();
    if ($newer_eod) {
        throw new Exception("Cannot reopen EOD. There are subsequent EODs for this branch. Please reopen those first.");
    }
    
    // Create audit trail entry
    $db->query("INSERT INTO eod_audit_trail (
                    eod_id, 
                    branch_id, 
                    eod_date, 
                    action, 
                    reason, 
                    performed_by_user_id, 
                    performed_at,
                    old_data
                ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)",
                [
                    $eod_id,
                    $branch_id,
                    $eod->eod_date,
                    'reopen',
                    $reason,
                    $user_id,
                    json_encode($eod)
                ]);
    
    // Delete the EOD record (this "reopens" the day)
    $db->query("DELETE FROM eod_summary WHERE id = ?", [$eod_id]);
    
    // Log the action
    error_log("EOD Reopened - ID: {$eod_id}, Branch: {$branch_id}, Date: {$eod->eod_date}, By: {$user_id}, Reason: {$reason}");
    
    echo json_encode([
        'success' => true, 
        'message' => 'EOD reopened successfully. Day is now unlocked for modifications.',
        'eod_date' => $eod->eod_date,
        'branch_id' => $branch_id
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}