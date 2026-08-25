<?php
/**
 * Edit Commodity Sale — corrects a posted sale, with maker/checker approval
 * for non-admin editors and a durable diff trail.
 *
 * Implementation: NOT an in-place field edit (a posted journal entry should
 * never be silently mutated). Saving is an atomic REVERSE-OLD + RECREATE-NEW:
 * the old commodity_sales row is reversed (stock/ledger restored) and
 * archived to the Recycle Bin, and a brand-new row with its own sale number
 * is posted with the corrected values. Every edit — applied or merely
 * requested — leaves one `commodity_sale_edits` row: the field-by-field diff,
 * who asked, who decided, and the old_sale_id -> new_sale_id link so
 * view_commodity_sale.php can show the full history even after replacement.
 *
 * Approval: Superadmin/admin always apply directly. A non-admin (delegated
 * 'can_edit') applies directly ONLY if their 'commodity_sale' ৳ limit covers
 * the new total AND the global commodity-sale approval policy is off —
 * otherwise the edit queues in the shared Approval Requests inbox exactly
 * like a new sale would. A user with no limit configured always queues.
 */
require_once '../core/init.php';

$currentUser = getCurrentUser();
$user_id     = $currentUser['id'] ?? null;
$is_admin    = in_array($currentUser['role'] ?? '', ['Superadmin', 'admin'], true);
if (!$is_admin && !userCanPageAction('trading', 'commodity_sale', 'can_edit')) {
    // Explicit module/page_key: without these, restrict_access() auto-detects
    // page_key='edit_commodity_sale' (this file's own basename) instead of the
    // 'commodity_sale' key that nav aliases it to (like its sibling detail pages
    // collect_commodity_payment.php / view_commodity_sale.php / commodity_invoice.php
    // / commodity_gate_pass.php / commodity_verify_delivery.php already do) — so a
    // user without the can_edit action could still slip through via an unrelated
    // 'edit_commodity_sale' page grant that has nothing to do with edit rights.
    restrict_access(['Superadmin', 'admin'], 'trading', 'commodity_sale');
}

global $db;
$pageTitle = 'Edit Commodity Sale';

ensureBusinessPartnersTable();
ensureCommodityIsSellableColumn();
ensureCommodityInventoryTable();
ensureCommoditySalesTable();
ensureRecycleBinTables();
ensureCommoditySaleEditsTable();

$csrf = $_SESSION['csrf_token'] ?? '';
$sale_id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['sale_id'] ?? 0);

$sale = $db->query(
    "SELECT cs.*, c.name AS customer_name FROM commodity_sales cs JOIN customers c ON c.id = cs.customer_id WHERE cs.id = ?",
    [$sale_id]
)->first();

if (!$sale) {
    require_once '../templates/header.php';
    echo '<div class="max-w-screen-md mx-auto px-4 py-10 text-center"><p class="text-gray-500">Commodity sale not found.</p><a href="commodity_sale.php" class="text-rose-600 hover:underline">&larr; Back to Commodity Sale</a></div>';
    require_once '../templates/footer.php';
    exit();
}

$locked = (float)$sale->amount_paid > 0.01;
$error  = null;

// ── Checker executing an approved edit request? ────────────────────────────
$preq        = null;
$preq_error  = null;
$edit_row    = null;
$preq_id_get = isset($_GET['pending_req']) ? (int)$_GET['pending_req'] : 0;
if ($preq_id_get) {
    $preq = getPendingRequest($preq_id_get, 'commodity_sale_edit');
    if (!$preq) {
        $preq_error = "Request #{$preq_id_get} is not open — it may already be approved, rejected, or cancelled.";
    } elseif (!$is_admin) {
        $my_cap = getUserActionLimit((int)$user_id, 'commodity_sale');
        if ($my_cap !== null && (float)$preq->amount > $my_cap) {
            $preq_error = "Request #{$preq_id_get} (৳" . number_format((float)$preq->amount, 0) . ") exceeds your delegated limit (৳" . number_format($my_cap, 0) . ") — a more senior officer must post it.";
            $preq = null;
        }
    }
    if ($preq) {
        $edit_row = $db->query("SELECT * FROM commodity_sale_edits WHERE id = ?", [(int)($preq->payload_arr['edit_row_id'] ?? 0)])->first();
    }
}

/**
 * Reverse the OLD sale's effects into the given recycle batch — verbatim
 * subset of delete_commodity_sale.php, kept local to this file.
 */
function __reverseOldCommoditySale($db, int $batch, object $old): void {
    $inv = $db->query("SELECT id FROM commodity_inventory WHERE commodity_id = ? AND branch_id = ? AND origin = ?", [(int)$old->commodity_id, (int)$old->branch_id, (string)($old->origin ?? '')])->first();
    if ($inv) {
        recycleSnapshotBefore($batch, 'commodity_inventory', 'id', (int)$inv->id);
        $db->query("UPDATE commodity_inventory SET quantity_on_hand = quantity_on_hand + ? WHERE id = ?", [(float)$old->quantity, (int)$inv->id]);
        if ($db->error()) throw new Exception('Failed to restore inventory quantity while reversing the old sale.');
    }

    $old_customer_id = (int)$old->customer_id;
    $ledger_entries = $db->query(
        "SELECT id FROM customer_ledger WHERE reference_type = 'commodity_sales' AND reference_id = ? AND transaction_type = 'invoice' ORDER BY id ASC",
        [(int)$old->id]
    )->results();
    if (!empty($ledger_entries)) {
        $first_le_id = $ledger_entries[0]->id;
        $last_le_id  = end($ledger_entries)->id;
        foreach ($ledger_entries as $le) recycleArchiveDelete($batch, 'customer_ledger', 'id', (int)$le->id);

        $agg = $db->query("SELECT COALESCE(SUM(debit_amount),0) td, COALESCE(SUM(credit_amount),0) tc FROM customer_ledger WHERE customer_id = ? AND id < ?", [$old_customer_id, $first_le_id])->first();
        $agg_td = (float)($agg->td ?? 0); $agg_tc = (float)($agg->tc ?? 0);
        if ($agg_td > 0 || $agg_tc > 0) {
            $ob_chk = $db->query("SELECT 1 FROM customer_ledger WHERE customer_id = ? AND reference_type = 'initial_due' LIMIT 1", [$old_customer_id])->first();
            $running = ($ob_chk ? 0.0 : (float)($db->query("SELECT initial_due FROM customers WHERE id = ?", [$old_customer_id])->first()->initial_due ?? 0)) + $agg_td - $agg_tc;
        } else {
            $running = (float)($db->query("SELECT initial_due FROM customers WHERE id = ?", [$old_customer_id])->first()->initial_due ?? 0);
        }
        // Previously unchecked — a mid-loop failure would leave every balance_after
        // from that point forward permanently stale (same class already fixed in
        // delete_payment.php / delete_commodity_sale.php).
        $subsequent = $db->query("SELECT id, debit_amount, credit_amount FROM customer_ledger WHERE customer_id = ? AND id > ? ORDER BY transaction_date ASC, id ASC", [$old_customer_id, $last_le_id])->results();
        foreach ($subsequent as $sub) {
            recycleSnapshotBefore($batch, 'customer_ledger', 'id', (int)$sub->id);
            $running += (float)$sub->debit_amount - (float)$sub->credit_amount;
            $db->query("UPDATE customer_ledger SET balance_after = ? WHERE id = ?", [$running, $sub->id]);
            if ($db->error()) throw new Exception("Failed to recompute balance_after for ledger entry #{$sub->id}.");
        }
    }

    $old_journals = $db->query("SELECT id FROM journal_entries WHERE related_document_type = 'commodity_sales' AND related_document_id = ?", [(int)$old->id])->results();
    foreach ($old_journals as $je) {
        recycleArchiveDelete($batch, 'transaction_lines', 'journal_entry_id', (int)$je->id);
        recycleArchiveDelete($batch, 'journal_entries', 'id', (int)$je->id);
    }
    recycleArchiveDelete($batch, 'commodity_sales', 'id', (int)$old->id);
}

/** Post the corrected sale — verbatim subset of commodity_sale.php's direct-post path. */
function __recreateCommoditySale($db, array $v, object $commodity, object $customer, int $created_by_user_id, int $posted_by_user_id, string $edit_note): int {
    $total_amount = round($v['quantity'] * $v['unit_price'], 2);
    $balance_due  = max(0, $total_amount - $v['advance_paid']);

    $date_prefix = date('Ymd', strtotime($v['sale_date']));
    $last = $db->query("SELECT sale_number FROM commodity_sales WHERE sale_number LIKE ? ORDER BY id DESC LIMIT 1", ["TR-{$date_prefix}-%"])->first();
    $seq  = $last ? ((int)substr($last->sale_number, -4) + 1) : 1;
    $sale_number = sprintf("TR-%s-%04d", $date_prefix, $seq);

    $cogs_amount = postCommoditySaleCost($v['commodity_id'], $v['branch_id'], $v['quantity'], $v['origin']);

    $new_sale_id = $db->insert('commodity_sales', [
        'sale_number' => $sale_number, 'customer_id' => $v['customer_id'], 'commodity_id' => $v['commodity_id'],
        'origin' => $v['origin'], 'source_purchase_order_id' => $v['source_purchase_order_id'],
        'branch_id' => $v['branch_id'], 'sale_date' => $v['sale_date'], 'quantity' => $v['quantity'],
        'unit_price' => $v['unit_price'], 'total_amount' => $total_amount, 'cogs_amount' => $cogs_amount,
        'advance_paid' => $v['advance_paid'], 'amount_paid' => 0, 'balance_due' => $balance_due,
        'stock_overridden' => $v['stock_override'] ? 1 : 0, 'status' => 'approved', 'notes' => $edit_note,
        'created_by_user_id' => $created_by_user_id, 'approved_by_user_id' => $posted_by_user_id,
    ]);
    if (!$new_sale_id) throw new Exception('Failed to create the corrected sale record.');

    $ar_account = $db->query("SELECT id FROM chart_of_accounts WHERE account_type = 'Accounts Receivable' LIMIT 1")->first();
    if (!$ar_account) throw new Exception("Chart of Accounts is missing an 'Accounts Receivable' account.");
    $gl = ensureCommodityTradingAccounts();

    $journal_desc = "Commodity sale {$sale_number}: {$v['quantity']} {$commodity->unit} {$commodity->name} to {$customer->name}";
    $journal_id = $db->insert('journal_entries', [
        'transaction_date' => $v['sale_date'], 'description' => $journal_desc,
        'related_document_type' => 'commodity_sales', 'related_document_id' => $new_sale_id, 'created_by_user_id' => $posted_by_user_id,
    ]);
    if (!$journal_id) throw new Exception('Failed to create the journal entry.');

    // Previously unchecked — same class fixed in commodity_sale.php: a silently
    // swallowed insert failure here could post an unbalanced/incomplete journal,
    // or leave the corrected sale with no AR ledger trail, while this edit still
    // commits and reports success.
    $dr1 = $db->insert('transaction_lines', ['journal_entry_id' => $journal_id, 'account_id' => $ar_account->id, 'debit_amount' => $total_amount, 'credit_amount' => 0, 'description' => $journal_desc]);
    if (!$dr1) throw new Exception('Failed to post the AR debit line of the corrected sale journal entry.');
    $cr1 = $db->insert('transaction_lines', ['journal_entry_id' => $journal_id, 'account_id' => $gl['revenue_account_id'], 'debit_amount' => 0, 'credit_amount' => $total_amount, 'description' => $journal_desc]);
    if (!$cr1) throw new Exception('Failed to post the revenue credit line of the corrected sale journal entry — would leave the journal unbalanced.');
    if ($cogs_amount > 0) {
        $dr2 = $db->insert('transaction_lines', ['journal_entry_id' => $journal_id, 'account_id' => $gl['cogs_account_id'], 'debit_amount' => $cogs_amount, 'credit_amount' => 0, 'description' => $journal_desc . ' (COGS)']);
        if (!$dr2) throw new Exception('Failed to post the COGS debit line of the corrected sale journal entry.');
        $cr2 = $db->insert('transaction_lines', ['journal_entry_id' => $journal_id, 'account_id' => $commodity->inventory_account_id, 'debit_amount' => 0, 'credit_amount' => $cogs_amount, 'description' => $journal_desc . ' (inventory drawdown)']);
        if (!$cr2) throw new Exception('Failed to post the inventory credit line of the corrected sale journal entry — would leave the journal unbalanced.');
    }
    $db->query("UPDATE commodity_sales SET journal_entry_id = ? WHERE id = ?", [$journal_id, $new_sale_id]);
    if ($db->error()) throw new Exception('Failed to link the journal entry to the corrected sale.');

    $agg2 = $db->query("SELECT COALESCE(SUM(debit_amount),0) td, COALESCE(SUM(credit_amount),0) tc FROM customer_ledger WHERE customer_id = ?", [$v['customer_id']])->first();
    $cust_init = $db->query("SELECT initial_due FROM customers WHERE id = ?", [$v['customer_id']])->first();
    $prev_balance = ((float)$agg2->td > 0 || (float)$agg2->tc > 0) ? ((float)$agg2->td - (float)$agg2->tc) : (float)($cust_init->initial_due ?? 0);
    $balance_after = $prev_balance + $total_amount;

    $cl_id = $db->insert('customer_ledger', [
        'customer_id' => $v['customer_id'], 'transaction_date' => $v['sale_date'], 'transaction_type' => 'invoice',
        'reference_type' => 'commodity_sales', 'reference_id' => $new_sale_id, 'invoice_number' => $sale_number,
        'description' => "Commodity sale — {$sale_number} ({$v['quantity']} {$commodity->unit} {$commodity->name})",
        'debit_amount' => $total_amount, 'credit_amount' => 0, 'balance_after' => $balance_after,
        'created_by_user_id' => $posted_by_user_id, 'journal_entry_id' => $journal_id,
    ]);
    if (!$cl_id) throw new Exception('Failed to post the customer ledger invoice entry for the corrected sale.');

    return $new_sale_id;
}

function __syncCustomerBalance($db, int $batch, int $cid): void {
    recycleSnapshotBefore($batch, 'customers', 'id', $cid);
    $last_le = $db->query("SELECT balance_after FROM customer_ledger WHERE customer_id = ? ORDER BY id DESC LIMIT 1", [$cid])->first();
    if ($last_le) {
        $db->query("UPDATE customers SET current_balance = ? WHERE id = ?", [(float)$last_le->balance_after, $cid]);
    } else {
        $init = $db->query("SELECT initial_due FROM customers WHERE id = ?", [$cid])->first();
        $db->query("UPDATE customers SET current_balance = ? WHERE id = ?", [(float)($init->initial_due ?? 0), $cid]);
    }
    if ($db->error()) throw new Exception('Failed to sync the customer cached balance.');
}

// ── POST: submit the correction (apply now, or queue for approval) ────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_commodity_sale' && !$locked) {
    try {
        if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token — refresh the page and try again.');
        }

        $exec_pending_req_id = (int)($_POST['pending_req_id'] ?? 0);

        $today = date('Y-m-d');
        $new_sale_date = $sale->sale_date;
        if ($is_admin && !empty($_POST['sale_date'])) {
            $posted_date = trim($_POST['sale_date']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $posted_date) && strtotime($posted_date) !== false) {
                if ($posted_date <= $today) { $new_sale_date = $posted_date; }
                else { throw new Exception('Sale date cannot be in the future.'); }
            }
        }

        $v = [
            'customer_id'  => (int)($_POST['customer_id'] ?? 0),
            'commodity_id' => (int)($_POST['commodity_id'] ?? 0),
            'branch_id'    => (int)($_POST['branch_id'] ?? 0),
            'origin'       => trim($_POST['origin'] ?? ''),
            'source_purchase_order_id' => !empty($_POST['source_purchase_order_id']) ? (int)$_POST['source_purchase_order_id'] : null,
            'sale_date'    => $new_sale_date,
            'quantity'     => (float)($_POST['quantity'] ?? 0),
            'unit_price'   => (float)($_POST['unit_price'] ?? 0),
            'advance_paid' => (float)($_POST['advance_paid'] ?? 0),
            'stock_override' => !empty($_POST['stock_override']),
        ];
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') throw new Exception('A reason is required to correct a commodity sale.');

        if (!$v['customer_id'])  throw new Exception('Please select a customer.');
        if (!$v['commodity_id']) throw new Exception('Please select a commodity.');
        if (!$v['branch_id'])    throw new Exception('Please select a branch.');
        if ($v['quantity'] <= 0) throw new Exception('Quantity must be greater than zero.');
        if ($v['unit_price'] < 0) throw new Exception('Unit price cannot be negative.');

        $commodity = $db->query("SELECT * FROM purchase_commodities WHERE id = ? AND is_sellable = 1", [$v['commodity_id']])->first();
        if (!$commodity) throw new Exception('Commodity not found or not marked sellable.');
        if (empty($commodity->inventory_account_id)) {
            throw new Exception("\"{$commodity->name}\" has no Inventory account configured — set one in Purchase → Procurement Catalog before selling it.");
        }
        $customer = $db->query("SELECT id, name FROM customers WHERE id = ?", [$v['customer_id']])->first();
        if (!$customer) throw new Exception('Customer not found.');

        $new_total_amount = round($v['quantity'] * $v['unit_price'], 2);

        if ($exec_pending_req_id) {
            // ═══ Checker posting an already-approved edit request ═══
            if (!$is_admin) {
                $my_limit = getUserActionLimit((int)$user_id, 'commodity_sale');
                if ($my_limit !== null && $new_total_amount > $my_limit) {
                    throw new Exception('Your commodity-sale limit (৳' . number_format($my_limit, 0) . ') does not cover this ৳' . number_format($new_total_amount, 0) . ' correction — a more senior officer must post it.');
                }
            }
            $edit_row_id = (int)($_POST['edit_row_id'] ?? 0);
        } else {
            // ═══ Fresh submission — decide whether to apply now or queue ═══
            $old_sale_locked_check = $db->query("SELECT amount_paid FROM commodity_sales WHERE id = ?", [$sale_id])->first();
            if (!$old_sale_locked_check) throw new Exception('Sale not found.');
            if ((float)$old_sale_locked_check->amount_paid > 0.01) throw new Exception('A payment was just collected against this sale — reverse it first, then edit.');

            $diff = diffCommoditySaleFields($sale, $v);
            if (empty($diff)) throw new Exception('No changes were made.');

            if (!$is_admin) {
                $my_limit = getUserActionLimit((int)$user_id, 'commodity_sale');
                $over_limit = $my_limit !== null && $new_total_amount > $my_limit;
                $no_limit_configured = $my_limit === null;
                $needs_approval = commoditySaleApprovalRequiredForAll() || $over_limit || $no_limit_configured;

                if ($needs_approval) {
                    $edit_row_id = $db->insert('commodity_sale_edits', [
                        'old_sale_id' => $sale_id, 'old_sale_number' => $sale->sale_number,
                        'change_summary' => json_encode($diff, JSON_UNESCAPED_UNICODE), 'reason' => $reason ?: null,
                        'status' => 'pending_approval', 'requested_by_user_id' => $user_id,
                    ]);
                    if (!$edit_row_id) throw new Exception('Could not record this edit request. Please try again.');

                    $payload = $v; $payload['edit_row_id'] = $edit_row_id;
                    $req_id = submitPendingRequest('commodity_sale_edit', $new_total_amount, $payload, [
                        'customer_id' => $v['customer_id'],
                        'summary'     => "Edit {$sale->sale_number}: " . implode(', ', array_map(fn($d) => "{$d['label']} {$d['old']} → {$d['new']}", $diff)),
                        'maker_limit' => $my_limit,
                    ]);
                    if (!$req_id) throw new Exception('Could not queue this edit for approval. Please try again.');

                    $reason_note = $over_limit ? 'over ৳' . number_format($my_limit, 0) . ' limit' : ($no_limit_configured ? 'no commodity-sale limit configured for this user' : 'commodity sale approval policy');
                    auditLog('other', 'updated', "Edit to commodity sale {$sale->sale_number} queued for approval ({$reason_note}) by " . ($currentUser['display_name'] ?? 'user'));
                    $_SESSION['success_flash'] = "This correction was sent for approval. It will apply once a senior officer approves it.";
                    header('Location: view_commodity_sale.php?id=' . $sale_id);
                    exit();
                }
            }
            // Admin, or non-admin within limit and policy allows direct apply.
            $diff_for_record = $diff;
            $edit_row_id = null; // filled in after apply, below
        }

        // ═══ Apply: reverse old + recreate new, atomically ═══
        $pdo = $db->getPdo();
        $pdo->beginTransaction();

        $old = $db->query(
            "SELECT cs.*, c.name AS customer_name FROM commodity_sales cs JOIN customers c ON c.id = cs.customer_id WHERE cs.id = ? FOR UPDATE",
            [$sale_id]
        )->first();
        if (!$old) throw new Exception('Sale not found.');
        if ((float)$old->amount_paid > 0.01) throw new Exception('A payment was just collected against this sale — reverse it first, then edit.');

        $old_customer_id = (int)$old->customer_id;

        $batch = recycleBegin(
            'commodity_sale',
            "Commodity Sale EDITED — {$old->sale_number} ({$old->customer_name}, ৳" . number_format((float)$old->total_amount, 2) . ") replaced",
            $old_customer_id
        );

        __reverseOldCommoditySale($db, $batch, $old);

        $stock = getCommodityInventory($v['commodity_id'], $v['branch_id'], $v['origin']);
        if ($v['quantity'] > $stock['quantity_on_hand'] && !$v['stock_override']) {
            throw new Exception(sprintf(
                'This exceeds current stock (on hand: %s %s). Tick "sell anyway" to override if this is against incoming stock.',
                number_format($stock['quantity_on_hand'], 3), $commodity->unit
            ));
        }

        $edit_note = "Corrected from {$old->sale_number} by " . ($currentUser['display_name'] ?? 'user') . ($reason !== '' ? " — {$reason}" : '');
        $new_sale_id = __recreateCommoditySale($db, $v, $commodity, $customer, (int)$old->created_by_user_id, (int)$user_id, $edit_note);

        foreach (array_unique([$old_customer_id, $v['customer_id']]) as $cid) {
            __syncCustomerBalance($db, $batch, $cid);
        }

        $new_sale_number = $db->query("SELECT sale_number FROM commodity_sales WHERE id = ?", [$new_sale_id])->first()->sale_number;

        // Record / finalize the commodity_sale_edits row.
        if ($exec_pending_req_id) {
            $db->query(
                "UPDATE commodity_sale_edits SET status = 'approved', new_sale_id = ?, new_sale_number = ?, decided_by_user_id = ?, decided_at = NOW() WHERE id = ?",
                [$new_sale_id, $new_sale_number, $user_id, $edit_row_id]
            );
            decidePendingRequest($exec_pending_req_id, 'approved', 'Posted by ' . ($currentUser['display_name'] ?? 'checker'), $new_sale_number);
        } else {
            $db->insert('commodity_sale_edits', [
                'old_sale_id' => $sale_id, 'old_sale_number' => $old->sale_number,
                'new_sale_id' => $new_sale_id, 'new_sale_number' => $new_sale_number,
                'change_summary' => json_encode($diff_for_record, JSON_UNESCAPED_UNICODE), 'reason' => $reason ?: null,
                'status' => 'approved', 'requested_by_user_id' => $user_id,
                'decided_by_user_id' => $user_id, 'decided_at' => date('Y-m-d H:i:s'),
            ]);
        }

        recycleFinalize($batch);
        $pdo->commit();

        auditLog('other', 'updated',
            "Commodity sale {$old->sale_number} edited → {$new_sale_number} (৳" . number_format($new_total_amount, 2) . ") by " . ($currentUser['display_name'] ?? 'user'),
            ['severity' => 'critical', 'old_sale_id' => $sale_id, 'new_sale_id' => $new_sale_id, 'old_sale_number' => $old->sale_number, 'new_sale_number' => $new_sale_number]);

        if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED && defined('TELEGRAM_BOT_TOKEN') && defined('TELEGRAM_CHAT_ID')) {
            try {
                require_once '../core/classes/TelegramNotifier.php';
                (new TelegramNotifier(TELEGRAM_BOT_TOKEN, getTelegramChatId('orders')))->sendMessage(
                    "<b>✏️ COMMODITY SALE EDITED</b>\n───────────────────────────────\n\n"
                    . "• {$old->sale_number} → <code>{$new_sale_number}</code>\n• Customer: <b>" . htmlspecialchars($customer->name) . "</b>\n"
                    . "• New total: ৳" . number_format($new_total_amount, 2) . "\n• Edited by: " . ($currentUser['display_name'] ?? 'user')
                    . "\n\n<i>Old entry reversed and archived to the Recycle Bin.</i>"
                );
            } catch (\Throwable $te) { error_log('edit_commodity_sale Telegram: ' . $te->getMessage()); }
        }

        $_SESSION['success_flash'] = "Sale {$old->sale_number} corrected — new sale {$new_sale_number} posted (৳" . number_format($new_total_amount, 2) . ").";
        header('Location: view_commodity_sale.php?id=' . $new_sale_id);
        exit();

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// ── Data for render ─────────────────────────────────────────────────────
$customers = $db->query(
    "SELECT c.id, c.name, c.business_name, c.phone_number, c.business_partner_id,
            COALESCE(c.initial_due,0) + COALESCE(cl.d,0) - COALESCE(cl.c,0) AS true_balance
     FROM customers c
     LEFT JOIN (SELECT customer_id, SUM(debit_amount) d, SUM(credit_amount) c FROM customer_ledger WHERE reference_type != 'initial_due' GROUP BY customer_id) cl ON cl.customer_id = c.id
     WHERE c.status = 'active' ORDER BY c.name ASC"
)->results();
$commodities = $db->query("SELECT id, name, unit, inventory_account_id FROM purchase_commodities WHERE is_sellable = 1 AND status = 'active' ORDER BY name ASC")->results();
$branches = $db->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name ASC")->results();
$origins_by_commodity = [];
foreach ($db->query("SELECT commodity_id, origin_name FROM purchase_commodity_origins WHERE status = 'active' ORDER BY origin_name ASC")->results() as $o) {
    $origins_by_commodity[(int)$o->commodity_id][] = $o->origin_name;
}
$inventory_rows = $db->query("SELECT commodity_id, branch_id, origin, quantity_on_hand, weighted_avg_cost FROM commodity_inventory")->results();
$pos_by_commodity = [];
foreach ($db->query(
    "SELECT id, commodity_id, po_number, supplier_name, wheat_origin, po_date
     FROM purchase_orders_adnan WHERE commodity_id IS NOT NULL AND po_status != 'cancelled'
     ORDER BY po_date DESC LIMIT 500"
)->results() as $po) {
    $pos_by_commodity[(int)$po->commodity_id][] = [
        'id' => (int)$po->id, 'label' => "{$po->po_number} — {$po->supplier_name} ({$po->wheat_origin}, " . date('d M Y', strtotime($po->po_date)) . ')',
    ];
}

// Prefill values: proposed (from a pending request) or current live values.
$pp = $preq ? $preq->payload_arr : null;
$prefill = [
    'customer_id'  => $pp['customer_id']  ?? (int)$sale->customer_id,
    'commodity_id' => $pp['commodity_id'] ?? (int)$sale->commodity_id,
    'branch_id'    => $pp['branch_id']    ?? (int)$sale->branch_id,
    'origin'       => $pp['origin']       ?? (string)($sale->origin ?? ''),
    'source_purchase_order_id' => $pp['source_purchase_order_id'] ?? $sale->source_purchase_order_id,
    'sale_date'    => $pp['sale_date']    ?? $sale->sale_date,
    'quantity'     => $pp['quantity']     ?? $sale->quantity,
    'unit_price'   => $pp['unit_price']   ?? $sale->unit_price,
    'advance_paid' => $pp['advance_paid'] ?? $sale->advance_paid,
];
$prefill_customer_name = $sale->customer_name;
if ($pp && (int)$pp['customer_id'] !== (int)$sale->customer_id) {
    $pc = $db->query("SELECT name FROM customers WHERE id = ?", [(int)$pp['customer_id']])->first();
    if ($pc) $prefill_customer_name = $pc->name;
}

require_once '../templates/header.php';
?>
<div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    <div class="mb-4">
        <a href="view_commodity_sale.php?id=<?php echo (int)$sale->id; ?>" class="text-xs text-gray-500 hover:text-rose-600"><i class="fas fa-arrow-left mr-1"></i>Back to Sale</a>
        <h1 class="text-2xl font-bold text-gray-900 mt-1"><i class="fas fa-pen text-rose-600 mr-2"></i>Edit <?php echo htmlspecialchars($sale->sale_number); ?></h1>
        <p class="text-gray-600 mt-1 text-sm">Saving replaces this sale: the old entry is reversed (stock and ledger restored) and archived to the Recycle Bin; a new corrected entry is posted with its own sale number. Every correction is recorded and shown on the sale's timeline.</p>
    </div>

    <?php if ($locked): ?>
    <div class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
        <i class="fas fa-lock mr-1"></i>This sale already has ৳<?php echo number_format((float)$sale->amount_paid, 2); ?> collected against it — it cannot be edited.
        Open <a href="collect_commodity_payment.php?sale_id=<?php echo (int)$sale->id; ?>" class="underline font-semibold">Payment History</a> and reverse the payment(s) first.
    </div>
    <?php else: ?>

    <?php if ($error): ?><div class="mb-4 rounded-lg border border-red-300 bg-red-50 px-4 py-2.5 text-sm text-red-800"><i class="fas fa-triangle-exclamation mr-1"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($preq_error): ?><div class="mb-4 rounded-lg border border-red-300 bg-red-50 px-4 py-2.5 text-sm text-red-800"><i class="fas fa-ban mr-1"></i><?php echo htmlspecialchars($preq_error); ?></div><?php endif; ?>

    <?php if ($preq && $edit_row): $diff = json_decode($edit_row->change_summary, true) ?: []; ?>
    <div class="mb-4 rounded-lg border border-blue-300 bg-blue-50 px-4 py-3 text-sm text-blue-900">
        <p class="font-semibold mb-2"><i class="fas fa-user-check mr-1"></i>Reviewing pending edit request #<?php echo (int)$preq->id; ?> — proposed changes:</p>
        <table class="w-full text-xs bg-white rounded-lg overflow-hidden border border-blue-200">
            <thead class="bg-blue-100"><tr><th class="px-3 py-1.5 text-left">Field</th><th class="px-3 py-1.5 text-left">From</th><th class="px-3 py-1.5 text-left">To</th></tr></thead>
            <tbody class="divide-y divide-blue-100">
                <?php foreach ($diff as $d): ?>
                <tr><td class="px-3 py-1.5 font-medium"><?php echo htmlspecialchars($d['label']); ?></td><td class="px-3 py-1.5 text-red-600"><?php echo htmlspecialchars((string)$d['old']); ?></td><td class="px-3 py-1.5 text-green-700"><?php echo htmlspecialchars((string)$d['new']); ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($edit_row->reason): ?><p class="mt-2"><strong>Reason:</strong> <?php echo htmlspecialchars($edit_row->reason); ?></p><?php endif; ?>
        <p class="mt-2 text-xs">Submit below to apply this correction under your own authority.</p>
    </div>
    <?php endif; ?>

    <form method="POST" id="ecsForm" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 space-y-5">
        <input type="hidden" name="action" value="update_commodity_sale">
        <input type="hidden" name="sale_id" value="<?php echo (int)$sale->id; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
        <?php if ($preq): ?>
        <input type="hidden" name="pending_req_id" value="<?php echo (int)$preq->id; ?>">
        <input type="hidden" name="edit_row_id" value="<?php echo (int)$edit_row->id; ?>">
        <?php endif; ?>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Customer <span class="text-red-500">*</span></label>
            <div class="relative">
                <input type="text" id="ecs_customer_search" autocomplete="off" required value="<?php echo htmlspecialchars($prefill_customer_name); ?>"
                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-rose-500"
                       oninput="ecsSearchCustomers(this.value)" onfocus="ecsSearchCustomers(this.value)">
                <div id="ecs_customer_dropdown" class="absolute z-50 left-0 right-0 mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-64 overflow-y-auto hidden"></div>
            </div>
            <input type="hidden" name="customer_id" id="ecs_customer_id" value="<?php echo (int)$prefill['customer_id']; ?>" required>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Commodity <span class="text-red-500">*</span></label>
                <select name="commodity_id" id="ecs_commodity" required class="w-full px-4 py-2 border rounded-lg" onchange="ecsCommodityChanged()">
                    <?php foreach ($commodities as $c): ?>
                    <option value="<?php echo (int)$c->id; ?>" data-unit="<?php echo htmlspecialchars($c->unit); ?>" <?php echo (int)$c->id === (int)$prefill['commodity_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c->name); ?> (<?php echo htmlspecialchars($c->unit); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Branch / Warehouse / Dock <span class="text-red-500">*</span></label>
                <select name="branch_id" id="ecs_branch" required class="w-full px-4 py-2 border rounded-lg" onchange="ecsUpdateStock()">
                    <?php foreach ($branches as $b): ?>
                    <option value="<?php echo (int)$b->id; ?>" <?php echo (int)$b->id === (int)$prefill['branch_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Origin</label>
                <select name="origin" id="ecs_origin" class="w-full px-4 py-2 border rounded-lg" onchange="ecsUpdateStock()">
                    <option value="">Not tracked / mixed</option>
                    <?php foreach (($origins_by_commodity[(int)$prefill['commodity_id']] ?? []) as $o): ?>
                    <option value="<?php echo htmlspecialchars($o); ?>" <?php echo $o === $prefill['origin'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($o); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Sale Date
                    <?php if ($is_admin): ?><span class="ml-1 text-[10px] font-semibold uppercase tracking-wide text-amber-600 bg-amber-50 border border-amber-200 rounded-full px-2 py-0.5 align-middle">Reconciliation</span><?php endif; ?>
                </label>
                <?php if ($is_admin): ?>
                <input type="date" name="sale_date" value="<?php echo htmlspecialchars($prefill['sale_date']); ?>" max="<?php echo date('Y-m-d'); ?>" class="w-full px-4 py-2 border rounded-lg">
                <?php else: ?>
                <input type="text" value="<?php echo date('d M Y', strtotime($prefill['sale_date'])); ?> (unchanged)" disabled class="w-full px-4 py-2 border rounded-lg bg-gray-50 text-gray-500">
                <?php endif; ?>
            </div>
        </div>

        <div id="ecs_stock_info" class="hidden rounded-lg border p-3 text-sm"></div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Link to Purchase Order <span class="text-gray-400 text-xs font-normal">(optional)</span></label>
            <select name="source_purchase_order_id" id="ecs_source_po" class="w-full px-4 py-2 border rounded-lg">
                <option value="">Not linked</option>
                <?php foreach (($pos_by_commodity[(int)$prefill['commodity_id']] ?? []) as $po): ?>
                <option value="<?php echo (int)$po['id']; ?>" <?php echo (int)$po['id'] === (int)$prefill['source_purchase_order_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($po['label']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Quantity <span class="text-red-500">*</span> <span id="ecs_qty_unit" class="text-gray-400 text-xs"></span></label>
                <input type="number" name="quantity" id="ecs_quantity" step="0.001" min="0.001" required value="<?php echo htmlspecialchars((string)$prefill['quantity']); ?>" class="w-full px-4 py-2 border rounded-lg" oninput="ecsUpdateStock(); ecsCalcTotal();">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Unit Price (৳) <span class="text-red-500">*</span></label>
                <input type="number" name="unit_price" id="ecs_price" step="0.0001" min="0" required value="<?php echo htmlspecialchars((string)$prefill['unit_price']); ?>" class="w-full px-4 py-2 border rounded-lg" oninput="ecsCalcTotal()">
            </div>
        </div>

        <div id="ecs_override_box" class="hidden rounded-lg border border-amber-300 bg-amber-50 p-3">
            <label class="inline-flex items-start gap-2 text-sm text-amber-900 cursor-pointer">
                <input type="checkbox" name="stock_override" value="1" id="ecs_override_chk" class="mt-0.5">
                <span>This exceeds current stock on hand. I understand and want to sell anyway.</span>
            </label>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Advance Paid</label>
                <input type="number" name="advance_paid" id="ecs_advance" step="0.01" min="0" value="<?php echo htmlspecialchars((string)$prefill['advance_paid']); ?>" class="w-full px-4 py-2 border rounded-lg" oninput="ecsCalcTotal()">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Reason for Correction <span class="text-red-500">*</span></label>
                <input type="text" name="reason" required class="w-full px-4 py-2 border rounded-lg" placeholder="Why is this being corrected?" value="<?php echo htmlspecialchars($edit_row->reason ?? ''); ?>">
            </div>
        </div>

        <div class="bg-gray-50 rounded-lg p-4 flex flex-wrap gap-6 text-sm">
            <div><span class="text-gray-500">Total Amount</span><div class="font-bold text-blue-700 text-lg" id="ecs_total">৳0.00</div></div>
            <div><span class="text-gray-500">Balance Due</span><div class="font-bold text-red-600 text-lg" id="ecs_due">৳0.00</div></div>
        </div>

        <div class="flex justify-end gap-3 pt-2 border-t">
            <a href="view_commodity_sale.php?id=<?php echo (int)$sale->id; ?>" class="px-5 py-2 border border-gray-300 rounded-lg text-gray-700 text-sm hover:bg-gray-50">Cancel</a>
            <button type="submit" class="px-5 py-2 bg-rose-600 text-white font-semibold rounded-lg hover:bg-rose-700 text-sm"><i class="fas fa-check mr-1"></i><?php echo $preq ? 'Approve & Post Correction' : 'Save Correction'; ?></button>
        </div>
    </form>
    <?php endif; ?>
</div>

<?php if (!$locked): ?>
<script>
const ecsCustomers = <?php echo json_encode(array_map(function($c) {
    return ['id' => $c->id, 'name' => $c->name, 'business' => $c->business_name ?? '', 'phone' => $c->phone_number ?? '', 'isPartner' => !empty($c->business_partner_id)];
}, $customers)); ?>;
const ecsInventory = <?php echo json_encode(array_map(function($r) {
    return ['commodity_id' => (int)$r->commodity_id, 'branch_id' => (int)$r->branch_id, 'origin' => $r->origin, 'qty' => (float)$r->quantity_on_hand, 'avgCost' => (float)$r->weighted_avg_cost];
}, $inventory_rows)); ?>;
const ecsOriginsByCommodity = <?php echo json_encode($origins_by_commodity); ?>;
const ecsPosByCommodity = <?php echo json_encode($pos_by_commodity); ?>;

function ecsSearchCustomers(query) {
    const dd = document.getElementById('ecs_customer_dropdown');
    const q = query.toLowerCase().trim();
    const matches = q.length === 0 ? ecsCustomers.slice(0, 20) : ecsCustomers.filter(c =>
        c.name.toLowerCase().includes(q) || c.business.toLowerCase().includes(q) || c.phone.includes(q)
    ).slice(0, 20);
    dd.innerHTML = matches.length === 0 ? '<div class="px-4 py-3 text-sm text-gray-500">No customers found</div>' :
        matches.map(c => `<div class="px-4 py-2 hover:bg-rose-50 cursor-pointer text-sm border-b border-gray-100" onclick="ecsSelectCustomer(${c.id})">
            <span class="font-medium text-gray-900">${c.name}</span>${c.business ? `<span class="text-gray-400 text-xs ml-1">(${c.business})</span>` : ''}
            <span class="text-gray-400 text-xs ml-2">${c.phone}</span></div>`).join('');
    dd.classList.remove('hidden');
}
function ecsSelectCustomer(id) {
    const c = ecsCustomers.find(x => x.id === id);
    if (!c) return;
    document.getElementById('ecs_customer_id').value = c.id;
    document.getElementById('ecs_customer_search').value = c.name;
    document.getElementById('ecs_customer_dropdown').classList.add('hidden');
}
document.addEventListener('click', e => {
    if (!e.target.closest('#ecs_customer_search') && !e.target.closest('#ecs_customer_dropdown')) {
        document.getElementById('ecs_customer_dropdown').classList.add('hidden');
    }
});

function ecsCommodityChanged() {
    const commodityId = parseInt(document.getElementById('ecs_commodity').value) || 0;
    const originSel = document.getElementById('ecs_origin');
    const currentOrigin = originSel.value;
    const origins = ecsOriginsByCommodity[commodityId] || [];
    originSel.innerHTML = '<option value="">Not tracked / mixed</option>' + origins.map(o => `<option value="${o}">${o}</option>`).join('');
    if (origins.includes(currentOrigin)) originSel.value = currentOrigin;

    const poSel = document.getElementById('ecs_source_po');
    const currentPo = poSel.value;
    const pos = ecsPosByCommodity[commodityId] || [];
    poSel.innerHTML = '<option value="">Not linked</option>' + pos.map(p => `<option value="${p.id}">${p.label}</option>`).join('');
    if (pos.some(p => String(p.id) === currentPo)) poSel.value = currentPo;

    ecsUpdateStock();
}

function ecsUpdateStock() {
    const commodityId = parseInt(document.getElementById('ecs_commodity').value) || 0;
    const branchId = parseInt(document.getElementById('ecs_branch').value) || 0;
    const origin = document.getElementById('ecs_origin').value;
    const opt = document.getElementById('ecs_commodity').selectedOptions[0];
    document.getElementById('ecs_qty_unit').textContent = opt && opt.dataset.unit ? '(' + opt.dataset.unit + ')' : '';
    const infoBox = document.getElementById('ecs_stock_info');
    const overrideBox = document.getElementById('ecs_override_box');
    if (!commodityId || !branchId) { infoBox.classList.add('hidden'); overrideBox.classList.add('hidden'); return; }
    const inv = ecsInventory.find(i => i.commodity_id === commodityId && i.branch_id === branchId && i.origin === origin);
    const qtyOnHand = inv ? inv.qty : 0;
    const unit = opt && opt.dataset.unit ? opt.dataset.unit : '';
    infoBox.className = 'rounded-lg border p-3 text-sm ' + (qtyOnHand > 0 ? 'border-blue-200 bg-blue-50 text-blue-900' : 'border-gray-200 bg-gray-50 text-gray-600');
    infoBox.textContent = `On hand at this source: ${qtyOnHand.toFixed(3)} ${unit} (before this correction's own quantity is re-applied)`;
    infoBox.classList.remove('hidden');
    const qty = parseFloat(document.getElementById('ecs_quantity').value) || 0;
    if (qty > qtyOnHand) { overrideBox.classList.remove('hidden'); } else { overrideBox.classList.add('hidden'); document.getElementById('ecs_override_chk').checked = false; }
}
function ecsCalcTotal() {
    const qty = parseFloat(document.getElementById('ecs_quantity').value) || 0;
    const price = parseFloat(document.getElementById('ecs_price').value) || 0;
    const advance = parseFloat(document.getElementById('ecs_advance').value) || 0;
    const total = qty * price;
    document.getElementById('ecs_total').textContent = '৳' + total.toFixed(2);
    document.getElementById('ecs_due').textContent = '৳' + Math.max(0, total - advance).toFixed(2);
}
document.getElementById('ecsForm').addEventListener('submit', function(e) {
    if (!document.getElementById('ecs_customer_id').value) { e.preventDefault(); alert('Please select a customer.'); return; }
    const overrideBox = document.getElementById('ecs_override_box');
    if (!overrideBox.classList.contains('hidden') && !document.getElementById('ecs_override_chk').checked) {
        e.preventDefault();
        alert('This exceeds current stock on hand. Tick the override checkbox to confirm, or reduce the quantity.');
        return;
    }
    if (!confirm('Save this correction? The original sale will be reversed and archived, and a new corrected sale will be posted.')) {
        e.preventDefault();
    }
});
document.addEventListener('DOMContentLoaded', () => { ecsUpdateStock(); ecsCalcTotal(); });
</script>
<?php endif; ?>
<?php require_once '../templates/footer.php'; ?>
