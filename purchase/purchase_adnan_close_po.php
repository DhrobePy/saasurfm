<?php
require_once '../core/init.php';
restrict_access(['Superadmin']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$po_id = $_POST['po_id'] ?? null;

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
    
    if ($po->delivery_status === 'closed') {
        echo json_encode(['success' => false, 'message' => 'PO is already closed']);
        exit;
    }
    
    // Close the PO - update delivery status to 'closed'
    $stmt = $db->prepare("UPDATE purchase_orders_adnan SET delivery_status = 'closed' WHERE id = ?");
    $stmt->execute([$po_id]);
    
    echo json_encode([
        'success' => true,
        'message' => "PO #{$po->po_number} has been closed successfully. No further goods can be received."
    ]);
    
} catch (Exception $e) {
    error_log("Error closing PO: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error closing PO: ' . $e->getMessage()]);
}