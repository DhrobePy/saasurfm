<?php
/**
 * Delete (Cancel) Purchase Order
 * Soft delete with audit trail
 */

require_once '../core/init.php';

// Restrict to Superadmin only
restrict_access(['Superadmin']);

// Set JSON header
header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get POST data
$po_id = $_POST['po_id'] ?? null;
$reason = $_POST['reason'] ?? 'No reason provided';

if (!$po_id) {
    echo json_encode(['success' => false, 'message' => 'PO ID is required']);
    exit;
}

try {
    $db = Database::getInstance()->getPdo();
    $db->beginTransaction();
    
    // Get current user
    $currentUser = getCurrentUser();
    $user_id = $currentUser['id'] ?? null;
    $user_name = $currentUser['display_name'] ?? 'System';
    
    // Check if PO exists and get details
    $stmt = $db->prepare("
        SELECT po_number, po_status, supplier_name, wheat_origin, 
               quantity_kg, unit_price_per_kg, total_order_value,
               total_received_qty, total_paid
        FROM purchase_orders_adnan 
        WHERE id = ?
    ");
    $stmt->execute([$po_id]);
    $po = $stmt->fetch(PDO::FETCH_OBJ);
    
    if (!$po) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Purchase order not found']);
        exit;
    }
    
    if ($po->po_status === 'cancelled') {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'PO is already deleted (cancelled)']);
        exit;
    }
    
    // Check for related records
    $check_grn = $db->prepare("SELECT COUNT(*) as count FROM goods_received_adnan WHERE purchase_order_id = ? AND grn_status != 'cancelled'");
    $check_grn->execute([$po_id]);
    $grn_count = $check_grn->fetch(PDO::FETCH_OBJ)->count;
    
    $check_payments = $db->prepare("SELECT COUNT(*) as count FROM purchase_payments_adnan WHERE purchase_order_id = ?");
    $check_payments->execute([$po_id]);
    $payment_count = $check_payments->fetch(PDO::FETCH_OBJ)->count;
    
    // Soft delete - mark as cancelled
    $stmt = $db->prepare("
        UPDATE purchase_orders_adnan 
        SET po_status = 'cancelled',
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$po_id]);
    
    // Audit trail
    if (function_exists('auditLog')) {
        auditLog(
            'purchase',
            'deleted',
            "Purchase Order {$po->po_number} cancelled - {$po->quantity_kg} KG {$po->wheat_origin} @ ৳{$po->unit_price_per_kg}/KG. Reason: {$reason}",
            [
                'record_type' => 'purchase_order',
                'record_id' => $po_id,
                'reference_number' => $po->po_number,
                'po_number' => $po->po_number,
                'supplier_name' => $po->supplier_name,
                'total_value' => $po->total_order_value,
                'had_grns' => $grn_count,
                'had_payments' => $payment_count,
                'deletion_reason' => $reason,
                'deleted_by' => $user_name
            ]
        );
    }
    
    $db->commit();
    
    $message = "PO #{$po->po_number} has been deleted (cancelled).";
    if ($grn_count > 0 || $payment_count > 0) {
        $message .= " Related records preserved: {$grn_count} GRN(s), {$payment_count} payment(s).";
    }
    
    echo json_encode([
        'success' => true,
        'message' => $message
    ]);
    
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Error deleting PO: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Error deleting PO: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Error: ' . $e->getMessage()
    ]);
}