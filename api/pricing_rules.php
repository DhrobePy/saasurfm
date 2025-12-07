<?php
/**
 * Pricing Rules API Endpoint
 * Handles AJAX requests for pricing rules management
 * 
 * Ujjal Flour Mills - SaaS Platform
 */

header('Content-Type: application/json');
session_start();
require_once '../init.php';
require_once '../classes/PricingRuleManager.php';
require_once '../classes/PricingRulesEngine.php';

// Check super admin access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'superadmin') {
    echo json_encode(array('success' => false, 'message' => 'Unauthorized'));
    exit();
}

$ruleManager = new PricingRuleManager($db, $_SESSION['user_id']);
$engine = new PricingRulesEngine($db, $_SESSION['user_id']);

// Get action from request
$action = null;
if (isset($_GET['action'])) {
    $action = $_GET['action'];
} elseif (isset($_POST['action'])) {
    $action = $_POST['action'];
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    if (isset($input['action'])) {
        $action = $input['action'];
    }
}

try {
    switch ($action) {
        case 'create':
            $data = json_decode(file_get_contents('php://input'), true);
            $result = $ruleManager->createRule($data['data']);
            echo json_encode($result);
            break;
            
        case 'update':
            $data = json_decode(file_get_contents('php://input'), true);
            $result = $ruleManager->updateRule($data['rule_id'], $data['data']);
            echo json_encode($result);
            break;
            
        case 'delete':
            $data = json_decode(file_get_contents('php://input'), true);
            $result = $ruleManager->deleteRule($data['rule_id']);
            echo json_encode($result);
            break;
            
        case 'get':
            $ruleId = $_GET['id'];
            $rule = $ruleManager->getRule($ruleId);
            echo json_encode(array('success' => true, 'data' => $rule));
            break;
            
        case 'list':
            $filters = isset($_GET['filters']) ? $_GET['filters'] : array();
            $rules = $ruleManager->getAllRules($filters);
            echo json_encode(array('success' => true, 'data' => $rules));
            break;
            
        case 'test':
            $data = json_decode(file_get_contents('php://input'), true);
            $result = $ruleManager->testRule($data['data']['rule_id'], $data['data']);
            echo json_encode($result);
            break;
            
        case 'calculate_price':
            $data = json_decode(file_get_contents('php://input'), true);
            $result = $engine->calculatePrice(
                $data['variant_id'],
                $data['branch_id'],
                $data['base_price'],
                isset($data['context']) ? $data['context'] : array()
            );
            echo json_encode($result);
            break;
            
        case 'activate':
            $data = json_decode(file_get_contents('php://input'), true);
            $pdo = $db->getPdo();
            $stmt = $pdo->prepare("UPDATE pricing_rules SET is_active = 1 WHERE id = ?");
            $stmt->execute(array($data['rule_id']));
            echo json_encode(array('success' => true, 'message' => 'Rule activated'));
            break;
            
        case 'deactivate':
            $data = json_decode(file_get_contents('php://input'), true);
            $result = $ruleManager->deleteRule($data['rule_id']);
            echo json_encode($result);
            break;
            
        case 'clone':
            $data = json_decode(file_get_contents('php://input'), true);
            $result = $ruleManager->cloneRule(
                $data['rule_id'],
                $data['new_code'],
                $data['new_name']
            );
            echo json_encode($result);
            break;
            
        case 'get_variants':
            // Get all active variants
            $sql = "SELECT pv.id, pv.sku, pv.grade, pv.weight_variant, p.base_name
                    FROM product_variants pv
                    JOIN products p ON pv.product_id = p.id
                    WHERE pv.status = 'active' AND p.status = 'active'
                    ORDER BY p.base_name, pv.grade, pv.weight_variant";
            $db->query($sql, array());
            $variants = $db->results();
            echo json_encode(array('success' => true, 'data' => $variants));
            break;
            
        default:
            echo json_encode(array('success' => false, 'message' => 'Invalid action'));
    }
    
} catch (Exception $e) {
    echo json_encode(array(
        'success' => false,
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ));
}
?>