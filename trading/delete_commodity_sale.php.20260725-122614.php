<?php
/**
 * Delete/reverse a commodity sale — Recycle-Bin backed, restorable, mirrors
 * cr/delete_payment.php's pattern exactly (archive+delete, reindex the ledger
 * chain, sync cached balance). Blocked if any payment has been collected
 * against it (amount_paid > 0) — same precedent as cr/delete_order.php;
 * reverse the payment(s) first via collect_commodity_payment.php.
 */
require_once '../core/init.php';

$currentUser = getCurrentUser();
$__is_admin_dcs = in_array(($currentUser['role'] ?? ''), ['Superadmin', 'admin'], true);
if (!$__is_admin_dcs && !userCanPageAction('trading', 'commodity_sale', 'can_delete')) {
    restrict_access(['Superadmin', 'admin']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed']));
}

header('Content-Type: application/json');

$session_token  = $_SESSION['csrf_token'] ?? '';
$received_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
if (!$session_token || !$received_token || !hash_equals($session_token, $received_token)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'CSRF validation failed']));
}

$sale_id = isset($_POST['sale_id']) ? (int)$_POST['sale_id'] : 0;
$reason  = trim($_POST['reason'] ?? '');

if ($sale_id <= 0) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Invalid sale ID']));
}

global $db;
$pdo = $db->getPdo();

ensureRecycleBinTables();
ensureCommodityInventoryTable();

try {
    $pdo->beginTransaction();

    $sale = $db->query(
        "SELECT cs.*, c.name AS customer_name, pc.name AS commodity_name, pc.unit
         FROM commodity_sales cs
         JOIN customers c ON c.id = cs.customer_id
         JOIN purchase_commodities pc ON pc.id = cs.commodity_id
         WHERE cs.id = ? FOR UPDATE",
        [$sale_id]
    )->first();

    if (!$sale) {
        throw new Exception("Commodity sale #{$sale_id} not found");
    }
    if ((float)$sale->amount_paid > 0) {
        throw new Exception("Cannot delete — ৳" . number_format((float)$sale->amount_paid, 2)
            . " has already been collected against {$sale->sale_number}. Reverse the payment(s) first, then delete the sale.");
    }

    $customer_id = (int)$sale->customer_id;

    $batch = recycleBegin(
        'commodity_sale',
        "Commodity Sale {$sale->sale_number} — {$sale->customer_name} · ৳" . number_format((float)$sale->total_amount, 2),
        $customer_id
    );

    // ── 1. Restore inventory (add the sold quantity back) ─────────────────────
    $inv = $db->query(
        "SELECT id FROM commodity_inventory WHERE commodity_id = ? AND branch_id = ? AND origin = ?",
        [(int)$sale->commodity_id, (int)$sale->branch_id, (string)($sale->origin ?? '')]
    )->first();
    if ($inv) {
        recycleSnapshotBefore($batch, 'commodity_inventory', 'id', (int)$inv->id);
        $db->query(
            "UPDATE commodity_inventory SET quantity_on_hand = quantity_on_hand + ? WHERE id = ?",
            [(float)$sale->quantity, (int)$inv->id]
        );
    }

    // ── 2. Archive this sale's customer_ledger entry + reindex subsequent ─────
    $ledger_entries = $db->query(
        "SELECT id FROM customer_ledger
         WHERE reference_type = 'commodity_sales' AND reference_id = ? AND transaction_type = 'invoice'
         ORDER BY id ASC",
        [$sale_id]
    )->results();

    if (!empty($ledger_entries)) {
        $first_le_id = $ledger_entries[0]->id;
        $last_le_id  = end($ledger_entries)->id;

        foreach ($ledger_entries as $le) {
            recycleArchiveDelete($batch, 'customer_ledger', 'id', (int)$le->id);
        }

        $agg = $db->query(
            "SELECT COALESCE(SUM(debit_amount), 0) AS td, COALESCE(SUM(credit_amount), 0) AS tc
             FROM customer_ledger WHERE customer_id = ? AND id < ?",
            [$customer_id, $first_le_id]
        )->first();
        $agg_td = (float)($agg->td ?? 0);
        $agg_tc = (float)($agg->tc ?? 0);
        if ($agg_td > 0 || $agg_tc > 0) {
            $ob_chk = $db->query(
                "SELECT 1 FROM customer_ledger WHERE customer_id = ? AND reference_type = 'initial_due' LIMIT 1",
                [$customer_id]
            )->first();
            $running = ($ob_chk ? 0.0 : (float)($db->query("SELECT initial_due FROM customers WHERE id = ?", [$customer_id])->first()->initial_due ?? 0))
                     + $agg_td - $agg_tc;
        } else {
            $running = (float)($db->query("SELECT initial_due FROM customers WHERE id = ?", [$customer_id])->first()->initial_due ?? 0);
        }

        $subsequent = $db->query(
            "SELECT id, debit_amount, credit_amount FROM customer_ledger
             WHERE customer_id = ? AND id > ? ORDER BY transaction_date ASC, id ASC",
            [$customer_id, $last_le_id]
        )->results();
        foreach ($subsequent as $sub) {
            recycleSnapshotBefore($batch, 'customer_ledger', 'id', (int)$sub->id);
            $running += (float)$sub->debit_amount - (float)$sub->credit_amount;
            $db->query("UPDATE customer_ledger SET balance_after = ? WHERE id = ?", [$running, $sub->id]);
        }
    }

    // ── 3. Sync customers.current_balance from ledger truth ───────────────────
    recycleSnapshotBefore($batch, 'customers', 'id', $customer_id);
    $last_le = $db->query(
        "SELECT balance_after FROM customer_ledger WHERE customer_id = ? ORDER BY id DESC LIMIT 1",
        [$customer_id]
    )->first();
    if ($last_le) {
        $db->query("UPDATE customers SET current_balance = ? WHERE id = ?", [(float)$last_le->balance_after, $customer_id]);
    } else {
        $init = $db->query("SELECT initial_due FROM customers WHERE id = ?", [$customer_id])->first();
        $db->query("UPDATE customers SET current_balance = ? WHERE id = ?", [(float)($init->initial_due ?? 0), $customer_id]);
    }

    // ── 4. Archive the sale's journal entry + lines ────────────────────────────
    $journals = $db->query(
        "SELECT id FROM journal_entries WHERE related_document_type = 'commodity_sales' AND related_document_id = ?",
        [$sale_id]
    )->results();
    foreach ($journals as $je) {
        recycleArchiveDelete($batch, 'transaction_lines', 'journal_entry_id', (int)$je->id);
        recycleArchiveDelete($batch, 'journal_entries', 'id', (int)$je->id);
    }

    // ── 5. Archive the sale row itself ──────────────────────────────────────
    recycleArchiveDelete($batch, 'commodity_sales', 'id', $sale_id);

    recycleFinalize($batch);
    $pdo->commit();

    $deletedBy = $currentUser['display_name'] ?? ($currentUser['username'] ?? 'Unknown');
    $reasonNote = $reason ? " — Reason: {$reason}" : '';

    auditLog('other', 'deleted',
        "Commodity sale {$sale->sale_number} (৳" . number_format((float)$sale->total_amount, 2)
        . ") for {$sale->customer_name} moved to Recycle Bin (batch #{$batch}) by {$deletedBy}{$reasonNote}",
        [
            'severity' => 'critical', 'record_id' => $sale_id, 'sale_number' => $sale->sale_number,
            'amount' => (float)$sale->total_amount, 'customer_id' => $customer_id, 'customer_name' => $sale->customer_name,
            'commodity' => $sale->commodity_name, 'quantity' => (float)$sale->quantity,
            'deleted_by_id' => $currentUser['id'] ?? null, 'reason' => $reason ?: null,
        ]);

    if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED
        && defined('TELEGRAM_BOT_TOKEN') && defined('TELEGRAM_CHAT_ID')) {
        try {
            require_once '../core/classes/TelegramNotifier.php';
            $reason_note = $reason ? "\n• Reason: <i>{$reason}</i>" : '';
            (new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID))->sendMessage(
                "<b>🗑️ COMMODITY SALE DELETED</b>\n───────────────────────────────\n\n"
                . "• Sale: <code>{$sale->sale_number}</code>\n• Customer: <b>" . htmlspecialchars($sale->customer_name) . "</b>\n"
                . "• {$sale->quantity} {$sale->unit} {$sale->commodity_name} · ৳" . number_format((float)$sale->total_amount, 2) . "\n"
                . "• Deleted by: <b>{$deletedBy}</b>" . $reason_note
                . "\n\n<i>Inventory and ledger have been reversed. Restorable from the Recycle Bin.</i>"
            );
        } catch (\Throwable $te) { error_log('delete_commodity_sale Telegram: ' . $te->getMessage()); }
    }

    exit(json_encode([
        'success' => true,
        'message' => "Commodity sale {$sale->sale_number} reversed and moved to Recycle Bin (batch #{$batch}) — restorable by Superadmin.",
    ]));

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    error_log('delete_commodity_sale.php error: ' . $e->getMessage());
    exit(json_encode(['success' => false, 'message' => $e->getMessage()]));
}
