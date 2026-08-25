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
require_once '../core/classes/Goodsreceivedadnanmanager.php';

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

$db = Database::getInstance();
$pdo = $db->getPdo();
$journalHelper = new JournalEntryHelper();

try {
    $pdo->beginTransaction();
    
    // Get existing GRN data for audit log
    $stmt = $pdo->prepare("SELECT * FROM goods_received_adnan WHERE id = ?");
    $stmt->execute([$grn_id]);
    $old_grn = $stmt->fetch(PDO::FETCH_OBJ);
    
    if (!$old_grn) {
        throw new Exception("GRN not found");
    }
    
    if ($old_grn->grn_status === 'cancelled') {
        throw new Exception("Cannot edit cancelled GRN");
    }
    
    // Get branch name for unload point
    $unload_branch_id = !empty($_POST['unload_point_branch_id']) ? (int)$_POST['unload_point_branch_id'] : null;
    $unload_point_name = null;
    
    if ($unload_branch_id) {
        $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
        $stmt->execute([$unload_branch_id]);
        $branch = $stmt->fetch(PDO::FETCH_OBJ);
        $unload_point_name = $branch ? $branch->name : null;
    }
    
    // If no branch selected, use the manually entered location
    if (!$unload_point_name) {
        $unload_point_name = $_POST['unload_point_name'] ?? null;
        if (!$unload_point_name) {
            throw new Exception("Unload location is required");
        }
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
    
    // Track changes for audit log
    $changes = [];
    $old_values = [];
    $new_values = [];
    
    // Check each field for changes
    $fields_to_check = [
        'grn_date' => $_POST['grn_date'],
        'truck_number' => $_POST['truck_number'] ?? null,
        'quantity_received_kg' => $quantity_received,
        'expected_quantity' => $expected_quantity,
        'unload_point_branch_id' => $unload_branch_id,
        'unload_point_name' => $unload_point_name,
        'remarks' => $_POST['remarks'] ?? null,
        'variance_remarks' => $_POST['variance_remarks'] ?? null
    ];
    
    foreach ($fields_to_check as $field => $new_value) {
        $old_value = $old_grn->$field ?? null;
        
        // Handle null/empty string comparison
        if (is_null($old_value) && $new_value === '') {
            $new_value = null;
        }
        
        if ($old_value != $new_value) {  // Using != for loose comparison to handle type differences
            $changes[] = "$field changed";
            $old_values[$field] = $old_value;
            $new_values[$field] = $new_value;
        }
    }
    
    // Find the existing journal entry for this GRN — goods_received_adnan.journal_entry_id
    // is NEVER actually populated anywhere in this codebase (verified 8 Aug 2026), so the
    // old `if ($old_grn->journal_entry_id)` check here was permanently dead: every edited
    // GRN silently skipped reversal AND never got a replacement journal entry either,
    // leaving inventory value permanently desynced from the GL. Look it up the same
    // correct way purchase_adnan_delete_grn.php already does.
    $journal_stmt = $pdo->prepare(
        "SELECT id FROM journal_entries WHERE related_document_type = 'grn_adnan' AND related_document_id = ? AND is_reversed = 0 LIMIT 1"
    );
    $journal_stmt->execute([$grn_id]);
    $existing_journal = $journal_stmt->fetch(PDO::FETCH_OBJ);

    if ($existing_journal && $journalHelper->canReverse($existing_journal->id)) {
        $reversal_id = $journalHelper->reverseJournalEntry(
            $existing_journal->id,
            'grn_adnan_edit_reversal',
            $grn_id,
            "GRN Edit: {$old_grn->grn_number} - Updated by " . (getCurrentUser()['display_name'] ?? 'System User')
        );

        if (!$reversal_id) {
            throw new Exception("Failed to reverse old journal entry");
        }

        $changes[] = "journal entry reversed (ID: {$reversal_id})";

        // Re-post a fresh journal entry for the corrected quantity/value — the
        // old code stopped at reversal, never recreating this, which is exactly
        // why inventory value drifted from the GL on every edit.
        $po_stmt = $pdo->prepare("SELECT po_number, unit_price_per_kg, wheat_origin FROM purchase_orders_adnan WHERE id = ?");
        $po_stmt->execute([$purchase_order_id]);
        $po_row = $po_stmt->fetch(PDO::FETCH_OBJ);
        if ($po_row) {
            $grnManager = new GoodsReceivedAdnanManager();
            $grnManager->createInventoryJournalEntry($grn_id, $po_row, $quantity_received);
            $changes[] = "journal entry re-posted for corrected quantity ({$quantity_received} KG)";
        }
    }
    
    // Update GRN record
    $stmt = $pdo->prepare("
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
        $_POST['truck_number'] ?? null,
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
    
    // Recalculate PO totals
    $journalHelper->recalculatePOTotals($purchase_order_id);
    
    // Update delivery status
    $journalHelper->updateDeliveryStatus($purchase_order_id);
    
    // Log to audit_log if table exists and there are changes
    if (!empty($changes)) {
        try {
            if (function_exists('auditLog')) {
                $currentUser = getCurrentUser();
                $user_name = $currentUser['display_name'] ?? 'System User';
                
                auditLog(
                    'purchase',
                    'updated',
                    "GRN {$old_grn->grn_number} updated for PO #{$old_grn->po_number}. Changes: " . implode(', ', $changes),
                    [
                        'record_type' => 'purchase_grn',
                        'record_id' => $grn_id,
                        'reference_number' => $old_grn->grn_number,
                        'po_number' => $old_grn->po_number,
                        'supplier_name' => $old_grn->supplier_name,
                        'changes' => $changes,
                        'old_values' => $old_values,
                        'new_values' => $new_values,
                        'updated_by' => $user_name,
                        'severity' => 'warning'
                    ]
                );
            }
        } catch (Exception $e) {
            error_log("✗ Audit log error: " . $e->getMessage());
        }
    }
    
    $pdo->commit();
    
    $_SESSION['success_message'] = "GRN {$old_grn->grn_number} updated successfully!";
    header("Location: purchase_adnan_view_po.php?id={$purchase_order_id}");
    exit;
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("GRN Update Failed: " . $e->getMessage());
    
    $_SESSION['error_message'] = "Failed to update GRN: " . $e->getMessage();
    header("Location: purchase_adnan_edit_grn.php?id={$grn_id}");
    exit;
}