<?php
/**
 * ============================================================================
 * AJAX Handler for Payment Edit/Delete Operations
 * ============================================================================
 * Save as: public/purchase/ajax_payment_actions.php
 * 
 * OR wherever your purchase module pages are located
 * Adjust the paths in require_once based on your actual structure
 * ============================================================================
 */

// Adjust these paths based on your actual directory structure
require_once __DIR__ . '/../../core/init.php';
require_once __DIR__ . '/../../managers/Purchasepaymentadnanmanager.php';

// Only Superadmin can access
restrict_access(['Superadmin']);

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$manager = new Purchasepaymentadnanmanager();

try {
    switch ($action) {
        
        case 'get_payment_for_edit':
            $payment_id = isset($_GET['payment_id']) ? intval($_GET['payment_id']) : 0;
            
            if (!$payment_id) {
                throw new Exception('Payment ID is required');
            }
            
            $payment = $manager->getPaymentForEdit($payment_id);
            
            if (!$payment) {
                throw new Exception('Payment not found');
            }
            
            echo json_encode([
                'success' => true,
                'payment' => $payment
            ]);
            break;
            
        case 'update_payment':
            $payment_id = isset($_POST['payment_id']) ? intval($_POST['payment_id']) : 0;
            
            if (!$payment_id) {
                throw new Exception('Payment ID is required');
            }
            
            $data = [
                'payment_date' => $_POST['payment_date'] ?? null,
                'amount_paid' => isset($_POST['amount_paid']) ? floatval($_POST['amount_paid']) : 0,
                'payment_method' => $_POST['payment_method'] ?? null,
                'reference_number' => $_POST['reference_number'] ?? null,
                'payment_type' => $_POST['payment_type'] ?? 'regular',
                'remarks' => $_POST['remarks'] ?? null
            ];
            
            // Validate required fields
            if (empty($data['payment_date'])) {
                throw new Exception('Payment date is required');
            }
            
            if ($data['amount_paid'] <= 0) {
                throw new Exception('Amount must be greater than zero');
            }
            
            if (empty($data['payment_method'])) {
                throw new Exception('Payment method is required');
            }
            
            $result = $manager->updatePayment($payment_id, $data);
            
            echo json_encode($result);
            break;
            
        case 'delete_payment':
            $payment_id = isset($_POST['payment_id']) ? intval($_POST['payment_id']) : 0;
            $reason = $_POST['reason'] ?? 'No reason provided';
            
            if (!$payment_id) {
                throw new Exception('Payment ID is required');
            }
            
            // Check if can delete
            $can_delete = $manager->canDeletePayment($payment_id);
            
            if (!$can_delete['can_delete']) {
                throw new Exception($can_delete['reason']);
            }
            
            $result = $manager->deletePayment($payment_id, $reason);
            
            echo json_encode($result);
            break;
            
        case 'check_can_delete':
            $payment_id = isset($_GET['payment_id']) ? intval($_GET['payment_id']) : 0;
            
            if (!$payment_id) {
                throw new Exception('Payment ID is required');
            }
            
            $can_delete = $manager->canDeletePayment($payment_id);
            
            echo json_encode([
                'success' => true,
                'can_delete' => $can_delete['can_delete'],
                'reason' => $can_delete['reason']
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}