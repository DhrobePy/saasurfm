<?php
/**
 * Delete Payment Handler with Journal Entry Reversal - CORRECTED VERSION
 * Only Superadmin can delete Payments
 * Uses actual column names: related_document_type, related_document_id
 */

require_once '../core/init.php';
require_once '../core/classes/JournalEntryHelper.php';

// Superadmin only
restrict_access(['Superadmin']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$payment_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';

if ($payment_id === 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid Payment ID']);
    exit;
}

if (empty($reason)) {
    echo json_encode(['success' => false, 'message' => 'Reason is required for deletion']);
    exit;
}

$db = Database::getInstance()->getPdo();
$journalHelper = new JournalEntryHelper();

try {
    $db->beginTransaction();
    
    // Get Payment details
    $stmt = $db->prepare("SELECT * FROM purchase_payments_adnan WHERE id = ?");
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch(PDO::FETCH_OBJ);
    
    if (!$payment) {
        throw new Exception("Payment not found");
    }
    
    // Check if payment is posted
    if ($payment->is_posted == 0) {
        // Not posted yet, safe to hard delete
        $stmt = $db->prepare("DELETE FROM purchase_payments_adnan WHERE id = ?");
        $stmt->execute([$payment_id]);
        
        // Recalculate PO totals even for unpaid
        $journalHelper->recalculatePOTotals($payment->purchase_order_id);
        $journalHelper->updatePaymentStatus($payment->purchase_order_id);
        
        $db->commit();
        echo json_encode([
            'success' => true,
            'message' => "Pending payment {$payment->payment_voucher_number} deleted successfully (was not posted)."
        ]);
        exit;
    }
    
    // For posted payments, find and reverse the journal entry
    $stmt = $db->prepare("
        SELECT id FROM journal_entries 
        WHERE related_document_type = 'payment_adnan' 
        AND related_document_id = ?
        AND is_reversed = 0
        LIMIT 1
    ");
    $stmt->execute([$payment_id]);
    $journal = $stmt->fetch(PDO::FETCH_OBJ);
    
    if ($journal && $journalHelper->canReverse($journal->id)) {
        $reversal_id = $journalHelper->reverseJournalEntry(
            $journal->id,
            'payment_adnan_reversal',
            $payment_id,
            "Deletion of Payment {$payment->payment_voucher_number}: {$reason}"
        );
        
        if (!$reversal_id) {
            throw new Exception("Failed to reverse journal entry");
        }
    }
    
    // Unpost the payment (soft delete)
    $stmt = $db->prepare("
        UPDATE purchase_payments_adnan 
        SET is_posted = 0,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$payment_id]);
    
    // Recalculate PO totals
    if (!$journalHelper->recalculatePOTotals($payment->purchase_order_id)) {
        throw new Exception("Failed to recalculate PO totals");
    }
    
    // Update payment status
    $journalHelper->updatePaymentStatus($payment->purchase_order_id);
    
    // Log the deletion (if audit_log table exists)
    try {
        $stmt = $db->prepare("
            INSERT INTO audit_log (
                table_name, record_id, action, 
                old_values, reason, user_id, created_at
            ) VALUES (
                'purchase_payments_adnan', ?, 'delete',
                ?, ?, ?, NOW()
            )
        ");
        
        $stmt->execute([
            $payment_id,
            json_encode($payment),
            $reason,
            getCurrentUser()['id']
        ]);
    } catch (Exception $e) {
        // Audit log is optional, don't fail if table doesn't exist
        error_log("Audit log failed (table may not exist): " . $e->getMessage());
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "Payment {$payment->payment_voucher_number} unposted successfully. Journal entry reversed and PO totals recalculated."
    ]);
    
} catch (Exception $e) {
    $db->rollBack();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}