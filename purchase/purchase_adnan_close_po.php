<?php
require_once '../core/init.php';
restrict_access(['Superadmin']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$po_id  = $_POST['po_id']  ?? null;
$action = $_POST['action'] ?? 'close'; // 'close' or 'reopen'

if (!$po_id) {
    echo json_encode(['success' => false, 'message' => 'PO ID is required']);
    exit;
}

try {
    $db = Database::getInstance()->getPdo();

    // Check if PO exists
    $stmt = $db->prepare("SELECT po_number, delivery_status FROM purchase_orders_adnan WHERE id = ?");
    $stmt->execute([$po_id]);
    $po = $stmt->fetch(PDO::FETCH_OBJ);

    if (!$po) {
        echo json_encode(['success' => false, 'message' => 'Purchase order not found']);
        exit;
    }

    if ($action === 'reopen') {
        if ($po->delivery_status !== 'closed') {
            echo json_encode(['success' => false, 'message' => 'PO is not closed — cannot reopen']);
            exit;
        }
        // Reopen to 'partial' if any goods have been received, otherwise back to 'pending'.
        // 'in_progress' is not a valid delivery_status ENUM value.
        $qty_stmt = $db->prepare("SELECT COALESCE(SUM(expected_quantity),0) AS received FROM goods_received_adnan WHERE purchase_order_id = ? AND grn_status != 'cancelled'");
        $qty_stmt->execute([$po_id]);
        $received_qty = $qty_stmt->fetchColumn();
        $new_status = $received_qty > 0 ? 'partial' : 'pending';

        $stmt = $db->prepare("UPDATE purchase_orders_adnan SET delivery_status = ?, is_delivery_locked = 0, delivery_lock_reason = NULL, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$new_status, $po_id]);
        echo json_encode([
            'success' => true,
            'message' => "PO #{$po->po_number} has been reopened (status: {$new_status}). Goods receipt is now allowed again.",
        ]);
    } else {
        // Default: close
        if ($po->delivery_status === 'closed') {
            echo json_encode(['success' => false, 'message' => 'PO is already closed']);
            exit;
        }
        $stmt = $db->prepare("UPDATE purchase_orders_adnan SET delivery_status = 'closed' WHERE id = ?");
        $stmt->execute([$po_id]);
        echo json_encode([
            'success' => true,
            'message' => "PO #{$po->po_number} has been closed successfully. No further goods can be received.",
        ]);
    }

} catch (Exception $e) {
    error_log("Error in close/reopen PO: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}