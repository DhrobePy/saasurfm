<?php
/**
 * AJAX Handler: Approve Expense
 * Approves a pending expense voucher
 */

require_once '../core/init.php';
require_once '../core/functions/helpers.php';
require_once 'ExpenseManager.php';

header('Content-Type: application/json');

// Check permission
if (!canApproveExpense()) {
    echo json_encode([
        'success' => false,
        'message' => 'You do not have permission to approve expenses'
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
$remarks = $input['remarks'] ?? null;

if (!$expenseId) {
    echo json_encode([
        'success' => false,
        'message' => 'Expense ID is required'
    ]);
    exit();
}

// Approve expense
$expenseManager = new ExpenseManager($db);
$result = $expenseManager->approveExpense($expenseId, $remarks);

echo json_encode($result);