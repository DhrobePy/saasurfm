<?php
/**
 * AJAX Handler: Delete Expense
 * Deletes an expense voucher (Superadmin only)
 */

require_once '../core//init.php';
require_once '../core/functions/helpers.php';

header('Content-Type: application/json');

// Check permission
if (!canDeleteExpense()) {
    echo json_encode([
        'success' => false,
        'message' => 'You do not have permission to delete expenses'
    ]);
    exit();
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
    exit();
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$expenseId = $input['expense_id'] ?? null;

if (!$expenseId) {
    echo json_encode([
        'success' => false,
        'message' => 'Expense ID is required'
    ]);
    exit();
}

try {
    $db->beginTransaction();
    
    // Get expense details before deletion
    $stmt = $db->prepare("SELECT * FROM expense_vouchers WHERE id = :id");
    $stmt->execute(['id' => $expenseId]);
    $expense = $stmt->fetch(PDO::FETCH_OBJ);
    
    if (!$expense) {
        throw new Exception('Expense not found');
    }
    
    // Only allow deletion of pending expenses
    if ($expense->status !== 'pending') {
        throw new Exception('Only pending expenses can be deleted');
    }
    
    // Log deletion action
    $logStmt = $db->prepare("
        INSERT INTO expense_action_log (
            expense_voucher_id,
            action,
            action_by,
            old_status,
            new_status,
            remarks,
            ip_address,
            user_agent
        ) VALUES (
            :expense_id,
            'deleted',
            :action_by,
            :old_status,
            'deleted',
            'Expense voucher deleted by Superadmin',
            :ip,
            :user_agent
        )
    ");
    
    $logStmt->execute([
        'expense_id' => $expenseId,
        'action' => 'deleted',
        'action_by' => $_SESSION['user_id'],
        'old_status' => $expense->status,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
    
    // Delete expense (CASCADE will delete related records)
    $deleteStmt = $db->prepare("DELETE FROM expense_vouchers WHERE id = :id");
    $deleteStmt->execute(['id' => $expenseId]);
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Expense voucher deleted successfully'
    ]);
    
} catch (Exception $e) {
    $db->rollBack();
    error_log("Error deleting expense: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}