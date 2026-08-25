<?php
/**
 * Delete/reverse a single commodity-sale payment — Recycle-Bin backed,
 * restorable. Uses commodity_sale_payments.customer_ledger_id to find the
 * EXACT ledger row to archive (reference_type+reference_id alone is ambiguous
 * here: the sale's own invoice row AND every payment collected against it all
 * share the same reference_type='commodity_sales'/reference_id=sale_id pair —
 * see collect_commodity_payment.php's insert).
 */
require_once '../core/init.php';

$currentUser = getCurrentUser();
$__is_admin_dcp = in_array(($currentUser['role'] ?? ''), ['Superadmin', 'admin'], true);
if (!$__is_admin_dcp && !userCanPageAction('trading', 'commodity_sale', 'can_delete')) {
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

$payment_id = isset($_POST['payment_id']) ? (int)$_POST['payment_id'] : 0;
$reason     = trim($_POST['reason'] ?? '');

if ($payment_id <= 0) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Invalid payment ID']));
}

global $db;
$pdo = $db->getPdo();

ensureRecycleBinTables();

try {
    $pdo->beginTransaction();

    $pay = $db->query(
        "SELECT csp.*, c.name AS customer_name, cs.sale_number, cs.id AS sale_id
         FROM commodity_sale_payments csp
         JOIN customers c ON c.id = csp.customer_id
         JOIN commodity_sales cs ON cs.id = csp.sale_id
         WHERE csp.id = ? FOR UPDATE",
        [$payment_id]
    )->first();

    if (!$pay) {
        throw new Exception("Payment #{$payment_id} not found");
    }

    $customer_id = (int)$pay->customer_id;
    $sale_id     = (int)$pay->sale_id;
    $amount      = (float)$pay->amount;
    $payment_number = $pay->payment_number;

    $batch = recycleBegin(
        'commodity_payment',
        "Commodity Payment {$payment_number} — {$pay->customer_name} · ৳" . number_format($amount, 2),
        $customer_id
    );

    // ── 1. Restore the sale's amount_paid / balance_due (advance-aware) ───────
    recycleSnapshotBefore($batch, 'commodity_sales', 'id', $sale_id);
    $db->query(
        "UPDATE commodity_sales
         SET amount_paid = GREATEST(0, amount_paid - ?),
             balance_due = total_amount - advance_paid - amount_paid
         WHERE id = ?",
        [$amount, $sale_id]
    );

    // ── 2. Archive the EXACT customer_ledger row this payment created ─────────
    if (!empty($pay->customer_ledger_id)) {
        $le_id = (int)$pay->customer_ledger_id;
        recycleArchiveDelete($batch, 'customer_ledger', 'id', $le_id);

        $agg = $db->query(
            "SELECT COALESCE(SUM(debit_amount), 0) AS td, COALESCE(SUM(credit_amount), 0) AS tc
             FROM customer_ledger WHERE customer_id = ? AND id < ?",
            [$customer_id, $le_id]
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
            [$customer_id, $le_id]
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

    // ── 4. Archive this payment's journal entry + lines ────────────────────────
    $journals = $db->query(
        "SELECT id FROM journal_entries WHERE related_document_type = 'commodity_sale_payments' AND related_document_id = ?",
        [$payment_id]
    )->results();
    foreach ($journals as $je) {
        recycleArchiveDelete($batch, 'transaction_lines', 'journal_entry_id', (int)$je->id);
        recycleArchiveDelete($batch, 'journal_entries', 'id', (int)$je->id);
    }

    // ── 5. Void a pending bank_transactions bridge row, if any ────────────────
    foreach ($db->query(
        "SELECT id FROM bank_transactions WHERE reference_number = ? AND status = 'pending'",
        [$payment_number]
    )->results() as $bt) {
        recycleArchiveDelete($batch, 'bank_transactions', 'id', (int)$bt->id);
    }

    // ── 6. Archive the payment row itself ──────────────────────────────────
    recycleArchiveDelete($batch, 'commodity_sale_payments', 'id', $payment_id);

    recycleFinalize($batch);
    $pdo->commit();

    $deletedBy = $currentUser['display_name'] ?? ($currentUser['username'] ?? 'Unknown');
    $reasonNote = $reason ? " — Reason: {$reason}" : '';

    auditLog('other', 'deleted',
        "Commodity payment {$payment_number} (৳" . number_format($amount, 2) . ") against {$pay->sale_number} for {$pay->customer_name} "
        . "moved to Recycle Bin (batch #{$batch}) by {$deletedBy}{$reasonNote}",
        [
            'severity' => 'critical', 'record_id' => $payment_id, 'payment_number' => $payment_number,
            'amount' => $amount, 'customer_id' => $customer_id, 'sale_id' => $sale_id, 'sale_number' => $pay->sale_number,
            'deleted_by_id' => $currentUser['id'] ?? null, 'reason' => $reason ?: null,
        ]);

    if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED
        && defined('TELEGRAM_BOT_TOKEN') && defined('TELEGRAM_CHAT_ID')) {
        try {
            require_once '../core/classes/TelegramNotifier.php';
            $reason_note = $reason ? "\n• Reason: <i>{$reason}</i>" : '';
            (new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID))->sendMessage(
                "<b>🗑️ COMMODITY PAYMENT DELETED</b>\n───────────────────────────────\n\n"
                . "• Receipt: <code>{$payment_number}</code>\n• Sale: {$pay->sale_number}\n"
                . "• Customer: <b>" . htmlspecialchars($pay->customer_name) . "</b>\n• Amount: ৳" . number_format($amount, 2) . "\n"
                . "• Deleted by: <b>{$deletedBy}</b>" . $reason_note
                . "\n\n<i>Ledger and sale balance have been reversed. Restorable from the Recycle Bin.</i>"
            );
        } catch (\Throwable $te) { error_log('delete_commodity_payment Telegram: ' . $te->getMessage()); }
    }

    exit(json_encode([
        'success' => true,
        'message' => "Payment {$payment_number} reversed and moved to Recycle Bin (batch #{$batch}) — restorable by Superadmin.",
    ]));

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    error_log('delete_commodity_payment.php error: ' . $e->getMessage());
    exit(json_encode(['success' => false, 'message' => $e->getMessage()]));
}