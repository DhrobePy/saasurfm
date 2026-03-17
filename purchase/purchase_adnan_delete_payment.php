<?php
/**
 * Delete Payment Handler with Journal Entry Reversal and Audit Trail
 * Only Superadmin can delete Payments
 */

require_once '../core/init.php';
require_once '../core/classes/JournalEntryHelper.php';

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
    
    // Get Payment details for audit
    $stmt = $db->prepare("
        SELECT pmt.*, po.po_number, po.supplier_name
        FROM purchase_payments_adnan pmt
        LEFT JOIN purchase_orders_adnan po ON pmt.purchase_order_id = po.id
        WHERE pmt.id = ?
    ");
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
        
        $journalHelper->recalculatePOTotals($payment->purchase_order_id);
        $journalHelper->updatePaymentStatus($payment->purchase_order_id);
        
        // ============================================
        // AUDIT TRAIL - PAYMENT DELETED (UNPOSTED)
        // ============================================
        if (function_exists('auditLog')) {
            $currentUser = getCurrentUser();
            
            auditLog(
                'purchase',
                'deleted',
                "Unposted payment {$payment->payment_voucher_number} deleted (was not posted) - ৳" . number_format($payment->amount_paid, 2) . " for PO #{$payment->po_number}. Reason: {$reason}",
                [
                    'record_type' => 'purchase_payment',
                    'record_id' => $payment_id,
                    'reference_number' => $payment->payment_voucher_number,
                    'po_number' => $payment->po_number,
                    'supplier_name' => $payment->supplier_name,
                    'amount_paid' => $payment->amount_paid,
                    'payment_method' => $payment->payment_method,
                    'payment_date' => $payment->payment_date,
                    'deletion_reason' => $reason,
                    'was_posted' => false,
                    'deleted_by' => $currentUser['display_name'] ?? 'Unknown',
                    'severity' => 'critical'
                ]
            );
        }
        
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
            payment_status = 'cancelled',
            remarks = CONCAT(COALESCE(remarks, ''), '\n\n[DELETED: ', ?, ' on ', NOW(), ']\nReason: ', ?),
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        getCurrentUser()['display_name'] ?? 'System',
        $reason,
        $payment_id
    ]);
    
    // Recalculate PO totals
    if (!$journalHelper->recalculatePOTotals($payment->purchase_order_id)) {
        throw new Exception("Failed to recalculate PO totals");
    }
    
    // Update payment status
    $journalHelper->updatePaymentStatus($payment->purchase_order_id);
    
    // ============================================
    // AUDIT TRAIL - PAYMENT DELETED (POSTED)
    // ============================================
    if (function_exists('auditLog')) {
        $currentUser = getCurrentUser();
        
        auditLog(
            'purchase',
            'deleted',
            "Payment {$payment->payment_voucher_number} deleted - ৳" . number_format($payment->amount_paid, 2) . " for PO #{$payment->po_number} ({$payment->supplier_name}). Journal entry reversed. Reason: {$reason}",
            [
                'record_type' => 'purchase_payment',
                'record_id' => $payment_id,
                'reference_number' => $payment->payment_voucher_number,
                'po_number' => $payment->po_number,
                'supplier_name' => $payment->supplier_name,
                'amount_paid' => $payment->amount_paid,
                'payment_method' => $payment->payment_method,
                'payment_date' => $payment->payment_date,
                'payment_type' => $payment->payment_type,
                'journal_entry_id' => $journal->id ?? null,
                'journal_reversed' => isset($reversal_id),
                'deletion_reason' => $reason,
                'was_posted' => true,
                'deleted_by' => $currentUser['display_name'] ?? 'Unknown',
                'severity' => 'critical'
            ]
        );
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "Payment {$payment->payment_voucher_number} deleted successfully. Journal entry reversed and PO totals recalculated."
    ]);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}