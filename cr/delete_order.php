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

// Feature #3: create recycle tables before the transaction (DDL implicit-commits).
ensureRecycleBinTables();

try {
    $pdo->beginTransaction();

    $order = $db->query(
        "SELECT id, status, created_by_user_id, amount_paid, order_number,
                customer_id, total_amount, balance_due
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

    // Feature #3: archive everything into one recycle batch instead of erasing.
    $batch = recycleBegin('credit_order',
        'Order ' . ($order->order_number ?: "#$order_id") . ' — ৳' . number_format((float)$order->total_amount, 2),
        (int)$order->customer_id);

    // ── Cascade: delete payments linked to this order (Superadmin only) ────────
    // For each customer_payment allocated to this order, delete the payment and
    // all its associated records (ledger, journal, bank bridge). If a payment was
    // also allocated to OTHER orders, restore those orders' amount_paid first.
    $paymentsCascaded = [];
    if ($is_superadmin) {
        $linkedPayments = $db->query(
            "SELECT DISTINCT pa.payment_id AS pid, cp.payment_number
             FROM payment_allocations pa
             JOIN customer_payments cp ON cp.id = pa.payment_id
             WHERE pa.order_id = ?",
            array($order_id)
        )->results();

        foreach ($linkedPayments as $pay) {
            $pid = (int)$pay->pid;

            // Restore amount_paid on any OTHER orders this payment was also allocated to
            $otherAllocs = $db->query(
                "SELECT order_id, allocated_amount FROM payment_allocations
                 WHERE payment_id = ? AND order_id != ?",
                array($pid, $order_id)
            )->results();
            foreach ($otherAllocs as $oa) {
                recycleSnapshotBefore($batch, 'credit_orders', 'id', (int)$oa->order_id);
                $db->query(
                    "UPDATE credit_orders
                     SET amount_paid = GREATEST(0, amount_paid - ?),
                         balance_due = total_amount - amount_paid
                     WHERE id = ?",
                    array((float)$oa->allocated_amount, (int)$oa->order_id)
                );
            }

            // Archive all payment_allocations for this payment (current + other orders)
            recycleArchiveDelete($batch, 'payment_allocations', 'payment_id', $pid);

            // Archive journal entry + transaction lines for this payment
            $payJournals = $db->query(
                "SELECT id FROM journal_entries
                 WHERE related_document_type = 'customer_payments' AND related_document_id = ?",
                array($pid)
            )->results();
            foreach ($payJournals as $je) {
                recycleArchiveDelete($batch, 'transaction_lines', 'journal_entry_id', (int)$je->id);
                recycleArchiveDelete($batch, 'journal_entries',   'id',               (int)$je->id);
            }

            // Archive pending bank_transaction bridged from this payment (by id, so
            // the status='pending' condition is honoured)
            foreach ($db->query(
                "SELECT id FROM bank_transactions WHERE reference_number = ? AND status = 'pending'",
                array($pay->payment_number)
            )->results() as $bt) {
                recycleArchiveDelete($batch, 'bank_transactions', 'id', (int)$bt->id);
            }

            // Archive customer_ledger entries for this payment
            foreach ($db->query(
                "SELECT id FROM customer_ledger WHERE reference_type = 'customer_payments' AND reference_id = ?",
                array($pid)
            )->results() as $ple) {
                recycleArchiveDelete($batch, 'customer_ledger', 'id', (int)$ple->id);
            }

            // Archive the payment record itself
            recycleArchiveDelete($batch, 'customer_payments', 'id', $pid);

            $paymentsCascaded[] = $pay->payment_number;
        }
    }
    // ── End payment cascade ──────────────────────────────────────────────────────

    // ── Accounting reversal ──────────────────────────────────────────────────
    // 1. Tentatively reverse the credit reservation; will be re-synced from
    //    ledger chain after deletions (avoids GREATEST(0,...) clamping bug).
    //    Snapshot the customer row first so a restore reverts current_balance.
    recycleSnapshotBefore($batch, 'customers', 'id', (int)$order->customer_id);
    $db->query(
        "UPDATE customers SET current_balance = current_balance - ? WHERE id = ?",
        array($order->total_amount, $order->customer_id)
    );

    // 2. Archive customer_ledger entries for this order and recalculate the chain
    $ledger_entries = $db->query(
        "SELECT id FROM customer_ledger
         WHERE reference_id = ?
           AND reference_type IN ('credit_order','credit_orders','order','dispatch','credit_sale')
         ORDER BY id ASC",
        array($order_id)
    )->results();

    if (!empty($ledger_entries)) {
        $first_le_id = $ledger_entries[0]->id;
        $last_le_id  = end($ledger_entries)->id;

        foreach ($ledger_entries as $le) {
            recycleArchiveDelete($batch, 'customer_ledger', 'id', (int)$le->id);
        }

        // Recalculate balance_after for all subsequent entries in this customer's ledger
        $prev = $db->query(
            "SELECT balance_after FROM customer_ledger WHERE customer_id = ? AND id < ? ORDER BY id DESC LIMIT 1",
            array($order->customer_id, $first_le_id)
        )->first();
        $running = $prev
            ? (float)$prev->balance_after
            : (float)($db->query("SELECT initial_due FROM customers WHERE id = ?", array($order->customer_id))->first()->initial_due ?? 0);

        $subsequent = $db->query(
            "SELECT id, debit_amount, credit_amount FROM customer_ledger
             WHERE customer_id = ? AND id > ?
             ORDER BY transaction_date ASC, id ASC",
            array($order->customer_id, $last_le_id)
        )->results();
        foreach ($subsequent as $sub) {
            recycleSnapshotBefore($batch, 'customer_ledger', 'id', (int)$sub->id);
            $running += (float)$sub->debit_amount - (float)$sub->credit_amount;
            $db->query("UPDATE customer_ledger SET balance_after = ? WHERE id = ?", array($running, $sub->id));
        }
    }

    // 3. Sync customers.current_balance to the true ledger balance so the cache
    //    never diverges (covers advance-payment and partial-dispatch edge cases).
    $last_le = $db->query(
        "SELECT balance_after FROM customer_ledger WHERE customer_id = ? ORDER BY id DESC LIMIT 1",
        array($order->customer_id)
    )->first();
    if ($last_le) {
        $db->query(
            "UPDATE customers SET current_balance = ? WHERE id = ?",
            array((float)$last_le->balance_after, $order->customer_id)
        );
    } else {
        // No ledger entries remain — fall back to initial_due
        $init = $db->query("SELECT initial_due FROM customers WHERE id = ?", array($order->customer_id))->first();
        $db->query(
            "UPDATE customers SET current_balance = ? WHERE id = ?",
            array((float)($init->initial_due ?? 0), $order->customer_id)
        );
    }

    // 3. Reverse journal entries created at dispatch (Debit AR / Credit Revenue)
    $journal_rows = $db->query(
        "SELECT id FROM journal_entries WHERE related_document_id = ? AND related_document_type = 'credit_orders'",
        array($order_id)
    )->results();
    foreach ($journal_rows as $je) {
        recycleArchiveDelete($batch, 'transaction_lines', 'journal_entry_id', (int)$je->id);
        recycleArchiveDelete($batch, 'journal_entries',   'id',               (int)$je->id);
    }
    // ── End accounting reversal ──────────────────────────────────────────────

    // Archive related records (child rows first, order last → restore brings the
    // parent order back first via reverse-capture ordering)
    recycleArchiveDelete($batch, 'credit_order_items',    'order_id', $order_id);
    recycleArchiveDelete($batch, 'credit_order_workflow', 'order_id', $order_id);
    recycleArchiveDelete($batch, 'production_schedule',   'order_id', $order_id);
    recycleArchiveDelete($batch, 'credit_order_shipping', 'order_id', $order_id);
    recycleArchiveDelete($batch, 'payment_allocations',   'order_id', $order_id);

    // Archive main order
    recycleArchiveDelete($batch, 'credit_orders', 'id', $order_id);

    recycleFinalize($batch);

    $pdo->commit();

    // ────────────────────────────────────────────────
    // Audit trail
    // ────────────────────────────────────────────────
    $orderNumDisplay = !empty($order->order_number) ? $order->order_number : "ID $order_id";
    $userDisplay = !empty($currentUser['display_name'])
                 ? $currentUser['display_name']
                 : (!empty($currentUser['username']) ? $currentUser['username'] : 'Unknown');
    $paymentsNote = !empty($paymentsCascaded)
        ? '; payments deleted: ' . implode(', ', $paymentsCascaded)
        : '';
    auditLog('credit_orders', 'soft_deleted', "Credit order {$orderNumDisplay} moved to Recycle Bin (batch #{$batch}) by {$userDisplay} (was: {$order->status}); accounting reversed: balance_due ৳{$order->balance_due}{$paymentsNote}", [
        'record_id'             => $order_id,
        'order_number'          => $orderNumDisplay,
        'old_status'            => $order->status,
        'total_amount'          => $order->total_amount,
        'amount_paid'           => $order->amount_paid,
        'balance_reversed'      => $order->total_amount,
        'deleted_by'            => $currentUser['id'],
        'superadmin_override'   => $is_superadmin,
        'payments_cascaded'     => $paymentsCascaded,
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
        'message' => 'Order moved to Recycle Bin (batch #' . $batch . ') — restorable from Admin → Recycle Bin',
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