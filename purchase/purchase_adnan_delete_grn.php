<?php
/**
 * Delete GRN Handler with Journal Entry Reversal - CORRECTED VERSION
 * Only Superadmin can delete GRNs
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

$grn_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';

if ($grn_id === 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid GRN ID']);
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
    
    // Get GRN details
    $stmt = $db->prepare("SELECT * FROM goods_received_adnan WHERE id = ?");
    $stmt->execute([$grn_id]);
    $grn = $stmt->fetch(PDO::FETCH_OBJ);
    
    if (!$grn) {
        throw new Exception("GRN not found");
    }
    
    if ($grn->grn_status === 'cancelled') {
        throw new Exception("GRN is already cancelled");
    }
    
    // Find the journal entry for this GRN using actual column names
    $stmt = $db->prepare("
        SELECT id FROM journal_entries 
        WHERE related_document_type = 'grn_adnan' 
        AND related_document_id = ?
        AND is_reversed = 0
        LIMIT 1
    ");
    $stmt->execute([$grn_id]);
    $journal = $stmt->fetch(PDO::FETCH_OBJ);
    
    // Reverse journal entry if exists
    if ($journal && $journalHelper->canReverse($journal->id)) {
        $reversal_id = $journalHelper->reverseJournalEntry(
            $journal->id,
            'grn_adnan_reversal',
            $grn_id,
            "Deletion of GRN {$grn->grn_number}: {$reason}"
        );
        
        if (!$reversal_id) {
            throw new Exception("Failed to reverse journal entry");
        }
    }
    
    // Soft delete GRN (set status to cancelled)
    $stmt = $db->prepare("
        UPDATE goods_received_adnan 
        SET grn_status = 'cancelled',
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$grn_id]);
    
    // Recalculate PO totals
    if (!$journalHelper->recalculatePOTotals($grn->purchase_order_id)) {
        throw new Exception("Failed to recalculate PO totals");
    }
    
    // Update delivery status
    $journalHelper->updateDeliveryStatus($grn->purchase_order_id);
    
    // Log the deletion (if audit_log table exists)
    try {
        $stmt = $db->prepare("
            INSERT INTO audit_log (
                table_name, record_id, action, 
                old_values, reason, user_id, created_at
            ) VALUES (
                'goods_received_adnan', ?, 'delete',
                ?, ?, ?, NOW()
            )
        ");
        
        $stmt->execute([
            $grn_id,
            json_encode($grn),
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
        'message' => "GRN {$grn->grn_number} deleted successfully. Journal entry reversed and PO totals recalculated."
    ]);
    
} catch (Exception $e) {
    $db->rollBack();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}