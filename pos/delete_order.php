<?php
/**
 * Recycle-Bin delete for a POS sale (Jul 2026). Superadmin/admin only.
 * Reverses any pos_customer_ledger credit entry (stock tracking removed from
 * POS, Jul 2026 — no inventory to reverse), archives (not reversal-entries)
 * the original journal — Option A convention, matching
 * every other Recycle-Bin-backed delete in this codebase — then archives the
 * order and every row keyed to it. FK check done via information_schema
 * before writing this cascade: the only real foreign key on orders.id is
 * order_items.order_id: everything else here (pos_exit_verifications,
 * pos_qr_scan_log, pos_customer_ledger, pos_sale_edits) is soft-linked, but
 * still needs archiving for a complete, restorable batch.
 */
require_once '../core/init.php';

header('Content-Type: application/json');
restrict_access(['Superadmin', 'admin']);

global $db;
$currentUser = getCurrentUser();
$user_id = (int)($currentUser['id'] ?? 0);
$user_name = $currentUser['display_name'] ?? 'Admin';

$order_id = (int)($_POST['order_id'] ?? 0);
if (!$order_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'order_id required']);
    exit;
}

$order = $db->query("SELECT * FROM orders WHERE id = ? AND order_type = 'POS'", [$order_id])->first();
if (!$order) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'POS order not found']);
    exit;
}

ensurePosLedgerTable();
ensurePosExitTables();
ensurePosSaleEditsTable();
ensureRecycleBinTables();

try {
    $db->beginTransaction();

    // Reverse the credit-ledger effect, if any.
    if ((float)$order->credit_amount > 0.009 && $order->customer_id) {
        postPosLedgerEntry((int)$order->customer_id, 'adjustment', 0, (float)$order->credit_amount, [
            'branch_id' => $order->branch_id, 'reference_type' => 'pos_sale_deleted', 'reference_id' => $order_id,
            'order_number' => $order->order_number, 'description' => "Reversal — order {$order->order_number} deleted",
        ]);
    }

    // Reverse the cash-in petty cash effect, if any — only literal Cash ever
    // touched the physical drawer (same rule as the sale/edit journal split).
    if ((float)$order->cash_paid > 0.009 && $order->payment_method === 'Cash') {
        postBranchPettyCashTransaction((int)$order->branch_id, 'cash_out', (float)$order->cash_paid, [
            'reference_type' => 'pos_sale_deleted', 'reference_id' => $order_id,
            'description' => "Reversal — order {$order->order_number} deleted", 'payment_method' => 'Adjustment',
        ]);
    }

    // Archive the original journal entry (Option A — archive, not reversal-entry).
    if ($order->journal_entry_id) {
        $db->query("UPDATE journal_entries SET description = CONCAT(description, ' [DELETED]') WHERE id = ?", [$order->journal_entry_id]);
        if ($db->error()) throw new Exception('Failed to label the archived journal entry.');
    }

    $batch = recycleBegin('pos_sale', "POS Sale {$order->order_number} — ৳" . number_format($order->total_amount, 2), $order->customer_id ?: null);

    // Precise reference match for the ledger row (reference_id alone could
    // collide with an unrelated pos_payment sharing the same numeric id).
    $ledger_rows = $db->query("SELECT * FROM pos_customer_ledger WHERE reference_type IN ('pos_sale', 'pos_sale_deleted') AND reference_id = ?", [$order_id])->results();
    foreach ($ledger_rows as $lr) {
        // Previously unchecked, while the DELETE right below it already was — same
        // gap already fixed in the shared recycleArchiveDelete() helper: a silently
        // swallowed archive-insert failure would let the guarded DELETE proceed
        // anyway, removing the ledger row with no restorable backup.
        $archive_id = $db->insert('cr_recycle_bin_rows', [
            'batch_id' => $batch, 'op' => 'delete', 'source_table' => 'pos_customer_ledger',
            'source_pk' => (string)$lr->id, 'pk_col' => 'id', 'row_json' => json_encode((array)$lr, JSON_UNESCAPED_UNICODE),
        ]);
        if (!$archive_id) throw new Exception('Failed to archive a POS ledger row before deleting it.');
    }
    if ($ledger_rows) {
        $db->query("DELETE FROM pos_customer_ledger WHERE reference_type IN ('pos_sale', 'pos_sale_deleted') AND reference_id = ?", [$order_id]);
        if ($db->error()) throw new Exception('Failed to archive ledger rows: ' . ($db->errorInfo()[2] ?? ''));
    }

    recycleArchiveDelete($batch, 'pos_exit_verifications', 'order_id', $order_id);
    recycleArchiveDelete($batch, 'pos_qr_scan_log', 'order_id', $order_id);
    recycleArchiveDelete($batch, 'pos_sale_edits', 'order_id', $order_id);
    recycleArchiveDelete($batch, 'order_items', 'order_id', $order_id);
    recycleArchiveDelete($batch, 'orders', 'id', $order_id);

    recycleFinalize($batch);

    $db->commit();

    if (function_exists('auditLog')) {
        auditLog('pos', 'deleted', "POS Sale {$order->order_number} deleted by {$user_name} (Recycle Bin batch #{$batch})",
            ['record_type' => 'pos_sale', 'record_id' => $order_id, 'reference_number' => $order->order_number]);
    }

    if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED && defined('TELEGRAM_BOT_TOKEN')) {
        try {
            require_once '../core/classes/TelegramNotifier.php';
            $msg = "<b>🗑️ POS SALE DELETED</b>\n───────────────────────────────\n\n"
                 . "• Order: <code>{$order->order_number}</code>\n• Amount: ৳" . number_format($order->total_amount, 2) . "\n"
                 . "• By: <b>{$user_name}</b>\n• Recycle Bin batch #{$batch} — restorable.";
            (new TelegramNotifier(TELEGRAM_BOT_TOKEN, getTelegramChatId('orders')))->sendMessage($msg);
        } catch (\Throwable $e) { error_log('POS delete Telegram: ' . $e->getMessage()); }
    }

    echo json_encode(['success' => true, 'message' => "Order {$order->order_number} deleted.", 'batch_id' => $batch]);

} catch (Exception $e) {
    if ($db->getPdo()->inTransaction()) $db->rollback();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
