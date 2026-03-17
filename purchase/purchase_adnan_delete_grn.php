<?php
/**
 * Delete GRN Handler with Journal Entry Reversal and Audit Trail
 * Only Superadmin can delete GRNs
 */

require_once '../core/init.php';
require_once '../core/classes/JournalEntryHelper.php';

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

$db = Database::getInstance();
$pdo = $db->getPdo();
$journalHelper = new JournalEntryHelper();

try {
    $pdo->beginTransaction();
    
    // Get GRN details for audit
    $stmt = $pdo->prepare("
        SELECT grn.*, po.po_number, po.supplier_name, po.unit_price_per_kg
        FROM goods_received_adnan grn
        LEFT JOIN purchase_orders_adnan po ON grn.purchase_order_id = po.id
        WHERE grn.id = ?
    ");
    $stmt->execute([$grn_id]);
    $grn = $stmt->fetch(PDO::FETCH_OBJ);
    
    if (!$grn) {
        throw new Exception("GRN not found");
    }
    
    if ($grn->grn_status === 'cancelled') {
        throw new Exception("GRN is already cancelled");
    }
    
    // Get current user info
    $currentUser = getCurrentUser();
    $user_name = $currentUser['display_name'] ?? $currentUser['username'] ?? 'System User';
    
    // Calculate values for audit with proper null handling
    $expected_qty = floatval($grn->expected_quantity ?? 0);
    $received_qty = floatval($grn->quantity_received_kg ?? 0);
    $unit_price = floatval($grn->unit_price_per_kg ?? 0);
    
    $variance = $expected_qty - $received_qty;
    $expected_value = $expected_qty * $unit_price;
    $received_value = $received_qty * $unit_price;
    
    // Find the journal entry for this GRN
    $stmt = $pdo->prepare("
        SELECT id FROM journal_entries 
        WHERE related_document_type = 'grn_adnan' 
        AND related_document_id = ?
        AND is_reversed = 0
        LIMIT 1
    ");
    $stmt->execute([$grn_id]);
    $journal = $stmt->fetch(PDO::FETCH_OBJ);
    $journal_reversed = false;
    
    // Reverse journal entry if exists
    if ($journal && $journalHelper->canReverse($journal->id)) {
        $reversal_id = $journalHelper->reverseJournalEntry(
            $journal->id,
            'grn_adnan_reversal',
            $grn_id,
            "Deletion of GRN {$grn->grn_number} by {$user_name}: {$reason}"
        );
        
        if (!$reversal_id) {
            throw new Exception("Failed to reverse journal entry");
        }
        
        $journal_reversed = true;
    }
    
    // Soft delete GRN (set status to cancelled)
    $stmt = $pdo->prepare("
        UPDATE goods_received_adnan 
        SET grn_status = 'cancelled',
            remarks = CONCAT(COALESCE(remarks, ''), '\n\n[DELETED: ', ?, ' on ', NOW(), ']\nReason: ', ?),
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $user_name,
        $reason,
        $grn_id
    ]);
    
    // Recalculate PO totals
    if (!$journalHelper->recalculatePOTotals($grn->purchase_order_id)) {
        throw new Exception("Failed to recalculate PO totals");
    }
    
    // Update delivery status
    $journalHelper->updateDeliveryStatus($grn->purchase_order_id);
    
    $pdo->commit();
    
    // ============================================
    // AUDIT TRAIL - GRN DELETED
    // ============================================
    if (function_exists('auditLog')) {
        $audit_message = "GRN {$grn->grn_number} deleted for PO #{$grn->po_number} ({$grn->supplier_name})";
        $audit_message .= " - Expected: " . number_format($expected_qty, 2) . " KG";
        $audit_message .= ", Received: " . number_format($received_qty, 2) . " KG";
        
        if ($variance != 0) {
            $audit_message .= ", Variance: " . number_format($variance, 2) . " KG";
            
            // Add variance percentage if expected quantity > 0
            if ($expected_qty > 0) {
                $variance_pct = ($variance / $expected_qty) * 100;
                $audit_message .= " (" . number_format($variance_pct, 2) . "%)";
            }
        }
        
        $audit_message .= ". Reason: {$reason}";
        
        $audit_data = [
            'record_type' => 'purchase_grn',
            'record_id' => $grn_id,
            'reference_number' => $grn->grn_number,
            'po_number' => $grn->po_number,
            'supplier_name' => $grn->supplier_name,
            'grn_date' => $grn->grn_date,
            'truck_number' => $grn->truck_number ?? null,  // Fixed: changed from vehicle_number
            'expected_quantity' => $expected_qty,
            'quantity_received_kg' => $received_qty,
            'variance' => $variance,
            'expected_value' => $expected_value,
            'received_value' => $received_value,
            'unit_price' => $unit_price,
            'journal_entry_id' => $journal->id ?? null,
            'journal_reversed' => $journal_reversed,
            'deletion_reason' => $reason,
            'deleted_by' => $user_name,
            'severity' => 'critical'
        ];
        
        // Add variance percentage if available
        if ($expected_qty > 0) {
            $audit_data['variance_percentage'] = ($variance / $expected_qty) * 100;
        }
        
        auditLog(
            'purchase',
            'deleted',
            $audit_message,
            $audit_data
        );
    }
    
    echo json_encode([
        'success' => true,
        'message' => "GRN {$grn->grn_number} deleted successfully. Journal entry reversed and PO totals recalculated."
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("GRN Deletion Failed: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}