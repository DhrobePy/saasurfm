<?php
/**
 * AJAX Handler for GRN Edit/Delete Operations
 * Location: /home/ujjalfmc/public_html/saas.ujjalfm.com/purchase/ajax_grn_actions.php
 */

require_once '../core/init.php';

// Only Superadmin can access
restrict_access(['Superadmin']);

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$manager = new GoodsReceivedAdnanManager();

try {
    switch ($action) {
        
        case 'get_grn_for_edit':
            $grn_id = isset($_GET['grn_id']) ? intval($_GET['grn_id']) : 0;
            
            if (!$grn_id) {
                throw new Exception('GRN ID is required');
            }
            
            $grn = $manager->getGRNForEdit($grn_id);
            
            if (!$grn) {
                throw new Exception('GRN not found');
            }
            
            echo json_encode([
                'success' => true,
                'grn' => $grn
            ]);
            break;
            
        case 'update_grn':
            $grn_id = isset($_POST['grn_id']) ? intval($_POST['grn_id']) : 0;
            
            if (!$grn_id) {
                throw new Exception('GRN ID is required');
            }
            
            $data = [
                'grn_date' => $_POST['grn_date'] ?? null,
                'truck_number' => $_POST['truck_number'] ?? null,
                'quantity_received_kg' => isset($_POST['quantity_received_kg']) ? floatval($_POST['quantity_received_kg']) : 0,
                'expected_quantity' => !empty($_POST['expected_quantity']) ? floatval($_POST['expected_quantity']) : null,
                'variance_remarks' => $_POST['variance_remarks'] ?? null,
                'unload_point_branch_id' => !empty($_POST['unload_point_branch_id']) ? intval($_POST['unload_point_branch_id']) : null,
                'unload_point_name' => $_POST['unload_point_name'] ?? null,
                'remarks' => $_POST['remarks'] ?? null
            ];
            
            if (empty($data['grn_date'])) {
                throw new Exception('GRN date is required');
            }
            
            if ($data['quantity_received_kg'] <= 0) {
                throw new Exception('Quantity must be greater than zero');
            }
            
            $result = $manager->updateGRN($grn_id, $data);
            
            echo json_encode($result);
            break;
            
        case 'delete_grn':
            $grn_id = isset($_POST['grn_id']) ? intval($_POST['grn_id']) : 0;
            $reason = $_POST['reason'] ?? 'No reason provided';
            
            if (!$grn_id) {
                throw new Exception('GRN ID is required');
            }
            
            $can_delete = $manager->canDeleteGRN($grn_id);
            
            if (!$can_delete['can_delete']) {
                throw new Exception($can_delete['reason']);
            }
            
            $result = $manager->deleteGRN($grn_id, $reason);
            
            echo json_encode($result);
            break;
            
        case 'check_can_delete':
            $grn_id = isset($_GET['grn_id']) ? intval($_GET['grn_id']) : 0;
            
            if (!$grn_id) {
                throw new Exception('GRN ID is required');
            }
            
            $can_delete = $manager->canDeleteGRN($grn_id);
            
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