<?php
require_once '../core/init.php';

restrict_access(['Superadmin', 'admin', 'sales-srg', 'sales-demra', 'sales-other', 'Accounts', 'accounts-srg', 'accounts-demra']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(array('success' => false, 'message' => 'Method not allowed')));
}

$order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
if ($order_id <= 0) {
    exit(json_encode(array('success' => false, 'message' => 'Invalid order ID')));
}

global $db;
$currentUser = getCurrentUser();

$pdo = $db->getPdo();

try {
    $pdo->beginTransaction();

    $order = $db->query(
        "SELECT id, status, created_by_user_id, amount_paid, order_number 
         FROM credit_orders 
         WHERE id = ? 
         FOR UPDATE",
        array($order_id)
    )->first();

    if (!$order) {
        throw new Exception("Order not found");
    }

    $is_creator    = (int)$order->created_by_user_id === (int)$currentUser['id'];
    $is_superadmin = ($currentUser['role'] === 'Superadmin');
    $is_admin      = in_array($currentUser['role'], array('Superadmin', 'admin'));

    // Superadmin can delete any order regardless of status or payment
    if (!$is_superadmin) {
        $allowed_statuses = array('pending_approval', 'escalated');
        if (!in_array($order->status, $allowed_statuses)) {
            throw new Exception("Cannot delete — order is already '{$order->status}'");
        }
        if ($order->amount_paid > 0) {
            throw new Exception("Cannot delete — partial/full payment already received");
        }
        if (!$is_creator && !$is_admin) {
            throw new Exception("Permission denied — only creator or admin can delete");
        }
    }

    // Delete related records
    $db->query("DELETE FROM credit_order_items   WHERE order_id = ?", array($order_id));
    $db->query("DELETE FROM credit_order_workflow WHERE order_id = ?", array($order_id));
    $db->query("DELETE FROM production_schedule   WHERE order_id = ?", array($order_id));
    $db->query("DELETE FROM credit_order_shipping  WHERE order_id = ?", array($order_id));
    $db->query("DELETE FROM payment_allocations    WHERE order_id = ?", array($order_id));

    // Delete main order
    $db->query("DELETE FROM credit_orders WHERE id = ?", array($order_id));

    $pdo->commit();

    // ────────────────────────────────────────────────
    // Audit trail
    // ────────────────────────────────────────────────
    $orderNumDisplay = !empty($order->order_number) ? $order->order_number : "ID $order_id";
    $userDisplay = !empty($currentUser['display_name'])
                 ? $currentUser['display_name']
                 : (!empty($currentUser['username']) ? $currentUser['username'] : 'Unknown');
    auditLog('credit_orders', 'deleted', "Credit order {$orderNumDisplay} deleted by {$userDisplay} (was: {$order->status})", [
        'record_id'    => $order_id,
        'order_number' => $orderNumDisplay,
        'old_status'   => $order->status,
        'amount_paid'  => $order->amount_paid,
        'deleted_by'   => $currentUser['id'],
        'superadmin_override' => $is_superadmin,
    ]);

    // ────────────────────────────────────────────────
    // Telegram notification (optional)
    // ────────────────────────────────────────────────
    if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
        if (defined('TELEGRAM_BOT_TOKEN') && defined('TELEGRAM_CHAT_ID')) {
            try {
                $notifier = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);

                $overrideNote = $is_superadmin ? "\n<b>⚠️ Superadmin override</b>" : '';
                $paidNote = $order->amount_paid > 0 ? "\n<b>Payments reversed:</b> ৳" . number_format($order->amount_paid, 2) : '';

                $message = "<b>🗑️ CREDIT ORDER DELETED</b>\n"
                         . "───────────────────────────────\n\n"
                         . "<b>Order:</b> <code>{$orderNumDisplay}</code>\n"
                         . "<b>Deleted by:</b> {$userDisplay}\n"
                         . "<b>Status was:</b> {$order->status}"
                         . $paidNote
                         . $overrideNote
                         . "\n\n<i>Ujjal Flour Mills ERP System</i>";

                $notifier->sendMessage($message);

            } catch (Exception $te) {
                error_log("Telegram notification failed after order delete (ID $order_id): " . $te->getMessage());
                // Do NOT throw — deletion already succeeded
            }
        } else {
            error_log("Telegram constants (TELEGRAM_BOT_TOKEN or TELEGRAM_CHAT_ID) not defined — skipping delete notification for order ID $order_id");
        }
    }

    exit(json_encode(array(
        'success' => true,
        'message' => 'Order deleted successfully',
        'order_number' => !empty($order->order_number) ? $order->order_number : "ID $order_id"
    )));

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    exit(json_encode(array(
        'success' => false,
        'message' => $e->getMessage()
    )));
}