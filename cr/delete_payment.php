<?php
require_once '../core/init.php';

// Feature #4: a posted payment may only be edited/reversed by Superadmin.
// Accounts users, collectors and plain admins must not reverse posted receipts.
restrict_access(['Superadmin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed']));
}

header('Content-Type: application/json');

// CSRF
$session_token  = $_SESSION['csrf_token'] ?? '';
$received_token = $_SERVER['HTTP_X_CSRF_TOKEN']
               ?? $_POST['csrf_token']
               ?? '';
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
$currentUser = getCurrentUser();
$pdo = $db->getPdo();

// Feature #3: recycle tables must exist before the transaction (DDL implicit-commits).
ensureRecycleBinTables();

try {
    $pdo->beginTransaction();

    // ── 1. Lock and fetch the payment ─────────────────────────────────────────
    $pay = $db->query(
        "SELECT cp.*, c.name AS customer_name, c.initial_due
         FROM customer_payments cp
         JOIN customers c ON cp.customer_id = c.id
         WHERE cp.id = ?
         FOR UPDATE",
        [$payment_id]
    )->first();

    if (!$pay) {
        throw new Exception("Payment #$payment_id not found");
    }

    $customer_id    = (int)$pay->customer_id;
    $payment_number = $pay->payment_number;
    $pay_amount     = (float)$pay->amount;

    // Feature #3: archive into a recycle batch so this reversal is restorable.
    $batch = recycleBegin('payment',
        "Payment {$payment_number} — {$pay->customer_name} · ৳" . number_format($pay_amount, 2),
        $customer_id);

    // ── 2. Reverse payment_allocations → restore order balances ───────────────
    $allocations = $db->query(
        "SELECT order_id, allocated_amount FROM payment_allocations WHERE payment_id = ?",
        [$payment_id]
    )->results();

    // Fallback: if payment_allocations is empty, parse the stored JSON field.
    // This covers payments recorded before the allocations table was populated,
    // or edge cases where the insert was skipped (zero-amount rows, etc.).
    if (empty($allocations) && !empty($pay->allocated_to_invoices)) {
        $json_allocs = json_decode($pay->allocated_to_invoices, true) ?: [];
        $allocations = [];
        foreach ($json_allocs as $oid => $amt) {
            $a = new stdClass();
            $a->order_id         = (int)$oid;
            $a->allocated_amount = (float)$amt;
            if ($a->order_id > 0 && $a->allocated_amount > 0) {
                $allocations[] = $a;
            }
        }
    }

    foreach ($allocations as $alloc) {
        // Restore amount_paid, then recompute balance_due from first principles.
        // Snapshot the order's before-image so a restore re-applies amount_paid.
        recycleSnapshotBefore($batch, 'credit_orders', 'id', (int)$alloc->order_id);
        $db->query(
            "UPDATE credit_orders
             SET amount_paid = GREATEST(0, amount_paid - ?),
                 balance_due = total_amount - amount_paid
             WHERE id = ?",
            [(float)$alloc->allocated_amount, (int)$alloc->order_id]
        );
    }
    recycleArchiveDelete($batch, 'payment_allocations', 'payment_id', $payment_id);

    // ── 3. Delete customer_ledger entry and recalculate chain ─────────────────
    $ledger_entries = $db->query(
        "SELECT id FROM customer_ledger
         WHERE reference_type = 'customer_payments' AND reference_id = ?
         ORDER BY id ASC",
        [$payment_id]
    )->results();

    if (!empty($ledger_entries)) {
        $first_le_id = $ledger_entries[0]->id;
        $last_le_id  = end($ledger_entries)->id;

        foreach ($ledger_entries as $le) {
            recycleArchiveDelete($batch, 'customer_ledger', 'id', (int)$le->id);
        }

        // Recompute balance_after for subsequent entries using the aggregate baseline
        // (immune to stored balance_after drift from the OB-offset bug).
        $agg_dp = $db->query(
            "SELECT COALESCE(SUM(debit_amount), 0)  AS td,
                    COALESCE(SUM(credit_amount), 0) AS tc
             FROM customer_ledger
             WHERE customer_id = ? AND id < ?",
            [$customer_id, $first_le_id]
        )->first();
        $agg_dp_td = (float)($agg_dp->td ?? 0);
        $agg_dp_tc = (float)($agg_dp->tc ?? 0);
        if ($agg_dp_td > 0 || $agg_dp_tc > 0) {
            $ob_chk = $db->query(
                "SELECT 1 FROM customer_ledger WHERE customer_id = ? AND reference_type = 'initial_due' LIMIT 1",
                [$customer_id]
            )->first();
            $running = ($ob_chk ? 0.0 : (float)($db->query("SELECT initial_due FROM customers WHERE id = ?", [$customer_id])->first()->initial_due ?? 0))
                     + $agg_dp_td - $agg_dp_tc;
        } else {
            $running = (float)($db->query("SELECT initial_due FROM customers WHERE id = ?", [$customer_id])->first()->initial_due ?? 0);
        }

        $subsequent = $db->query(
            "SELECT id, debit_amount, credit_amount FROM customer_ledger
             WHERE customer_id = ? AND id > ?
             ORDER BY transaction_date ASC, id ASC",
            [$customer_id, $last_le_id]
        )->results();
        foreach ($subsequent as $sub) {
            recycleSnapshotBefore($batch, 'customer_ledger', 'id', (int)$sub->id);
            $running += (float)$sub->debit_amount - (float)$sub->credit_amount;
            $db->query("UPDATE customer_ledger SET balance_after = ? WHERE id = ?", [$running, $sub->id]);
        }
    }

    // ── 4. Sync customers.current_balance from ledger truth ───────────────────
    recycleSnapshotBefore($batch, 'customers', 'id', $customer_id);
    $last_le = $db->query(
        "SELECT balance_after FROM customer_ledger WHERE customer_id = ? ORDER BY id DESC LIMIT 1",
        [$customer_id]
    )->first();
    if ($last_le) {
        $db->query("UPDATE customers SET current_balance = ? WHERE id = ?",
            [(float)$last_le->balance_after, $customer_id]);
    } else {
        $init = $db->query("SELECT initial_due FROM customers WHERE id = ?", [$customer_id])->first();
        $db->query("UPDATE customers SET current_balance = ? WHERE id = ?",
            [(float)($init->initial_due ?? 0), $customer_id]);
    }

    // ── 5. Delete journal entry + lines ───────────────────────────────────────
    $journals = $db->query(
        "SELECT id FROM journal_entries
         WHERE related_document_type = 'customer_payments' AND related_document_id = ?",
        [$payment_id]
    )->results();
    foreach ($journals as $je) {
        recycleArchiveDelete($batch, 'transaction_lines', 'journal_entry_id', (int)$je->id);
        recycleArchiveDelete($batch, 'journal_entries',   'id',               (int)$je->id);
    }

    // ── 6. Void pending bank_transactions entry created by bridge ─────────────
    //    Match by reference_number = payment_number; archive by id so the
    //    status='pending' condition is preserved and it's restorable.
    foreach ($db->query(
        "SELECT id FROM bank_transactions WHERE reference_number = ? AND status = 'pending'",
        [$payment_number]
    )->results() as $bt) {
        recycleArchiveDelete($batch, 'bank_transactions', 'id', (int)$bt->id);
    }

    // ── 7. Archive the payment record itself ──────────────────────────────────
    recycleArchiveDelete($batch, 'customer_payments', 'id', $payment_id);

    recycleFinalize($batch);

    $pdo->commit();

    // ── Audit trail ───────────────────────────────────────────────────────────
    $deletedBy = $currentUser['display_name'] ?? ($currentUser['username'] ?? 'Unknown');
    $reasonNote = $reason ? " — Reason: $reason" : '';

    auditLog(
        'customer_payments',
        'soft_deleted',
        "Payment {$payment_number} (৳" . number_format($pay_amount, 2) . ") for {$pay->customer_name} moved to Recycle Bin (batch #{$batch}) by {$deletedBy}{$reasonNote}",
        [
            'severity'          => 'critical',
            'reference_number'  => $payment_number,
            'record_id'         => $payment_id,
            'payment_id'        => $payment_id,
            'payment_number'    => $payment_number,
            'amount'            => $pay_amount,
            'customer_id'       => $customer_id,
            'customer_name'     => $pay->customer_name,
            'payment_method'    => $pay->payment_method,
            'payment_type'      => $pay->payment_type,
            'allocations_count' => count($allocations),
            'deleted_by_id'     => $currentUser['id'],
            'reason'            => $reason ?: null,
        ]
    );

    // ── Telegram notification ─────────────────────────────────────────────────
    if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
        try {
            require_once '../core/classes/TelegramNotifier.php';
            $notifier = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);

            $alloc_orders = array_map(fn($a) => '#' . $a->order_id, $allocations);
            $alloc_note = !empty($alloc_orders)
                ? "\n• Was allocated to: " . implode(', ', $alloc_orders)
                : "\n• Was an advance payment (not allocated to any invoice)";

            $reason_note = $reason ? "\n• Reason: <i>{$reason}</i>" : '';

            $msg = "<b>🗑️ PAYMENT DELETED</b>\n"
                 . "───────────────────────────────\n\n"
                 . "• Receipt: <code>{$payment_number}</code>\n"
                 . "• Customer: <b>{$pay->customer_name}</b>\n"
                 . "• Amount: <b>৳" . number_format($pay_amount, 2) . "</b>\n"
                 . "• Method: {$pay->payment_method}\n"
                 . $alloc_note
                 . "\n• Deleted by: <b>{$deletedBy}</b>"
                 . $reason_note
                 . "\n\n<i>Ledger balance and order dues have been reversed.</i>"
                 . "\n\n<i>Ujjal Flour Mills ERP</i>";

            $notifier->sendMessage($msg);
        } catch (\Throwable $tg) {
            error_log("Telegram notification failed for payment delete #{$payment_id}: " . $tg->getMessage());
        }
    }

    exit(json_encode([
        'success'        => true,
        'message'        => "Payment {$payment_number} reversed and moved to Recycle Bin (batch #{$batch}) — restorable by Superadmin.",
        'payment_number' => $payment_number,
    ]));

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    error_log("delete_payment.php error: " . $e->getMessage());
    exit(json_encode(['success' => false, 'message' => $e->getMessage()]));
}
