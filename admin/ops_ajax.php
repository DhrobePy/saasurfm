<?php
/**
 * Operations Center AJAX Handler
 * Handles all mutations from operations_center.php
 */
require_once '../core/init.php';
restrict_access(['Superadmin', 'admin']);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit;
}

$pdo      = Database::getInstance()->getPdo();
$action   = trim($_POST['action'] ?? '');
$order_id = (int)($_POST['order_id'] ?? 0);

function jres(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(['success' => $ok, 'message' => $msg] + $extra); exit;
}

switch ($action) {

    case 'set_priority':
        $p = $_POST['priority'] ?? '';
        if (!in_array($p, ['low','normal','high','urgent'])) jres(false, 'Invalid priority');
        if (!$order_id) jres(false, 'Invalid order');
        $pdo->prepare("UPDATE credit_orders SET priority=?, updated_at=NOW() WHERE id=?")->execute([$p, $order_id]);
        jres(true, 'Priority set to ' . ucfirst($p), ['priority' => $p]);

    case 'escalate':
        if (!$order_id) jres(false, 'Invalid order');
        $pdo->prepare("UPDATE credit_orders SET status='escalated', priority='urgent', updated_at=NOW() WHERE id=?")->execute([$order_id]);
        if (function_exists('auditLog'))
            auditLog('credit_sales', 'escalated', "Order #$order_id escalated via Operations Center", ['record_id' => $order_id]);
        jres(true, 'Order escalated to urgent');

    case 'pause':
        if (!$order_id) jres(false, 'Invalid order');
        $note = "\n[PAUSED " . date('d M Y H:i') . " — Operations Center]";
        $pdo->prepare("UPDATE credit_orders SET priority='low', special_instructions=CONCAT(IFNULL(special_instructions,''),?), updated_at=NOW() WHERE id=?")->execute([$note, $order_id]);
        jres(true, 'Order paused (priority set to low)');

    case 'reschedule':
        $nd = trim($_POST['new_date'] ?? '');
        if (!$order_id)              jres(false, 'Invalid order');
        if (!$nd || !strtotime($nd)) jres(false, 'Invalid date');
        $pdo->prepare("UPDATE credit_orders SET required_date=?, updated_at=NOW() WHERE id=?")->execute([$nd, $order_id]);
        jres(true, 'Rescheduled to ' . date('d M Y', strtotime($nd)), ['new_date' => $nd]);

    case 'order_production':
        $product_id  = (int)($_POST['product_id']      ?? 0);
        $branch_id   = (int)($_POST['branch_id']       ?? 0);
        $sched_date  = trim($_POST['scheduled_date']   ?? date('Y-m-d'));
        if (!$product_id || !$branch_id) jres(false, 'Product and branch are required');
        if (!strtotime($sched_date))     jres(false, 'Invalid scheduled date');
        $stmt = $pdo->prepare("
            SELECT co.id FROM credit_orders co
            JOIN credit_order_items coi ON coi.order_id = co.id AND coi.product_id = ?
            WHERE co.status IN ('approved','pending_approval')
            ORDER BY co.sort_order ASC, co.created_at ASC LIMIT 1
        ");
        $stmt->execute([$product_id]);
        $ord = $stmt->fetch(PDO::FETCH_OBJ);
        if (!$ord) jres(false, 'No approved order found for this product');
        $pdo->prepare("
            INSERT INTO production_schedule (order_id, branch_id, scheduled_date, status, notes, created_at, updated_at)
            VALUES (?, ?, ?, 'pending', 'Triggered via Operations Center', NOW(), NOW())
        ")->execute([$ord->id, $branch_id, $sched_date]);
        jres(true, 'Production scheduled successfully');

    default:
        jres(false, 'Unknown action');
}
