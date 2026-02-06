<?php
/**
 * Update GRN Handler - Superadmin Only
 * File: /purchase/purchase_adnan_update_grn.php
 * 
 * Table: goods_received_adnan
 * Handles GRN updates with journal entry reversal
 */

require_once '../core/init.php';
require_once '../core/classes/JournalEntryHelper.php';

// Superadmin only
restrict_access(['Superadmin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = "Invalid request method";
    header('Location: purchase_adnan_list_po.php');
    exit;
}

$grn_id = isset($_POST['grn_id']) ? (int)$_POST['grn_id'] : 0;
$purchase_order_id = isset($_POST['purchase_order_id']) ? (int)$_POST['purchase_order_id'] : 0;

if ($grn_id === 0 || $purchase_order_id === 0) {
    $_SESSION['error_message'] = "Invalid GRN or PO ID";
    header('Location: purchase_adnan_list_po.php');
    exit;
}

$db = Database::getInstance()->getPdo();
$journalHelper = new JournalEntryHelper();

try {
    $db->beginTransaction();
    
    // Get existing GRN data for audit log
    $stmt = $db->prepare("SELECT * FROM goods_received_adnan WHERE id = ?");
    $stmt->execute([$grn_id]);
    $old_grn = $stmt->fetch(PDO::FETCH_OBJ);
    
    if (!$old_grn) {
        throw new Exception("GRN not found");
    }
    
    if ($old_grn->grn_status === 'cancelled') {
        throw new Exception("Cannot edit cancelled GRN");
    }
    
    // Get branch name for unload point
    $unload_branch_id = (int)$_POST['unload_point_branch_id'];
    $stmt = $db->prepare("SELECT name FROM branches WHERE id = ?");
    $stmt->execute([$unload_branch_id]);
    $branch = $stmt->fetch(PDO::FETCH_OBJ);
    $unload_point_name = $branch ? $branch->name : null;
    
    if (!$unload_point_name) {
        throw new Exception("Invalid branch selected");
    }
    
    // Calculate new values
    $quantity_received = (float)$_POST['quantity_received_kg'];
    $expected_quantity = !empty($_POST['expected_quantity']) ? (float)$_POST['expected_quantity'] : null;
    $total_value = $quantity_received * $old_grn->unit_price_per_kg;
    
    // Calculate variance percentage
    $variance_pct = null;
    if ($expected_quantity && $expected_quantity > 0) {
        $variance_pct = (($quantity_received - $expected_quantity) / $expected_quantity) * 100;
        $variance_pct = round($variance_pct, 2);
    }
    
    // If GRN has journal entry, reverse it
    if ($old_grn->journal_entry_id) {
        if ($journalHelper->canReverse($old_grn->journal_entry_id)) {
            $reversal_id = $journalHelper->reverseJournalEntry(
                $old_grn->journal_entry_id,
                'grn_adnan_edit_reversal',
                $grn_id,
                "GRN Edit: {$old_grn->grn_number} - Updated by " . getCurrentUser()['name']
            );
            
            if (!$reversal_id) {
                throw new Exception("Failed to reverse old journal entry");
            }
        }
    }
    
    // Update GRN record
    $stmt = $db->prepare("
        UPDATE goods_received_adnan 
        SET 
            grn_date = ?,
            truck_number = ?,
            quantity_received_kg = ?,
            total_value = ?,
            expected_quantity = ?,
            variance_percentage = ?,
            variance_remarks = ?,
            unload_point_branch_id = ?,
            unload_point_name = ?,
            remarks = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    
    $stmt->execute([
        $_POST['grn_date'],
        $_POST['truck_number'],
        $quantity_received,
        $total_value,
        $expected_quantity,
        $variance_pct,
        !empty($_POST['variance_remarks']) ? $_POST['variance_remarks'] : null,
        $unload_branch_id,
        $unload_point_name,
        !empty($_POST['remarks']) ? $_POST['remarks'] : null,
        $grn_id
    ]);
    
    // Recalculate PO totals (skips generated columns)
    $journalHelper->recalculatePOTotals($purchase_order_id);
    
    // Update delivery status
    $journalHelper->updateDeliveryStatus($purchase_order_id);
    
    // Log to audit_log if table exists
    try {
        $stmt = $db->prepare("
            INSERT INTO audit_log (
                table_name, record_id, action,
                old_values, new_values, user_id, created_at
            ) VALUES (
                'goods_received_adnan', ?, 'update',
                ?, ?, ?, NOW()
            )
        ");
        
        $new_values = [
            'grn_date' => $_POST['grn_date'],
            'truck_number' => $_POST['truck_number'],
            'quantity_received_kg' => $quantity_received,
            'total_value' => $total_value,
            'expected_quantity' => $expected_quantity,
            'variance_percentage' => $variance_pct,
            'unload_point_branch_id' => $unload_branch_id
        ];
        
        $stmt->execute([
            $grn_id,
            json_encode($old_grn),
            json_encode($new_values),
            getCurrentUser()['id']
        ]);
    } catch (Exception $e) {
        // Audit log is optional - don't fail if table doesn't exist
        error_log("Audit log failed: " . $e->getMessage());
    }
    
    $db->commit();
    
    $_SESSION['success_message'] = "GRN {$old_grn->grn_number} updated successfully!";
    header("Location: purchase_adnan_view_po.php?id={$purchase_order_id}");
    exit;
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    error_log("GRN Update Failed: " . $e->getMessage());
    
    $_SESSION['error_message'] = "Failed to update GRN: " . $e->getMessage();
    header("Location: purchase_adnan_edit_grn.php?id={$grn_id}");
    exit;
}