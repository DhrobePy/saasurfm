<?php
/**
 * Ledger Repair & Invoice Snapshot Backfill Tool v2
 *
 * STEP 1  DDL — create invoice_snapshots table (outside transaction).
 * STEP 2  Insert missing customer_ledger invoice rows, then rebalance every
 *         affected customer's full ledger in chronological order.
 * STEP 3  Fix customers.current_balance where it diverges from the (now-repaired)
 *         ledger. Re-queried inside the transaction so STEP 2 changes are visible.
 * STEP 4  Backfill invoice_snapshots for dispatched orders that lack one.
 * STEP 5  Fix wrong revenue account_id in transaction_lines: POS Revenue account
 *         → correct Credit Sales Revenue account (chart_of_accounts only — never
 *         bank_accounts or bank_transactions).
 *
 * Steps 2–5 run inside one PDO transaction.
 * dbq()/dbi() check ->error() after every Database call and throw on failure.
 * On any error: full rollback + on-screen log showing what was attempted.
 * On success: commit + full on-screen log of every committed operation.
 *
 * Access: Superadmin only.
 */

require_once '../core/init.php';
restrict_access(['Superadmin']);
global $db;

// ── helpers ───────────────────────────────────────────────────────────────────

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt($n) { return '৳' . number_format((float)$n, 2); }

/**
 * Run a SELECT through the Database wrapper; throw on error.
 * Database::query() silently catches PDOExceptions and sets ->error() = true
 * without throwing — so we must check after every call.
 */
function dbq($db, string $sql, array $params = []) {
    $result = $db->query($sql, $params);
    if ($db->error()) {
        $short = preg_replace('/\s+/', ' ', trim(substr($sql, 0, 140)));
        throw new RuntimeException("DB query failed: {$short}");
    }
    return $result;
}

/**
 * Run an INSERT through the Database wrapper; throw on error.
 * Returns the new row's auto-increment ID.
 */
function dbi($db, string $table, array $data): int {
    $id = $db->insert($table, $data);
    if ($id === false || $db->error()) {
        throw new RuntimeException("DB insert failed into `{$table}`.");
    }
    return (int)$id;
}

$pdo       = $db->getPdo();
$run       = isset($_POST['run_repair']);
$ops       = [];    // ['step'=>N, 'detail'=>'...'] — built up during repair
$err_msg   = null;  // non-null if repair failed
$committed = false;

// ─────────────────────────────────────────────────────────────────────────────
// STEP 1 — DDL: create invoice_snapshots (outside transaction)
// MySQL issues an implicit commit before/after DDL, so this cannot be rolled
// back regardless. Run it unconditionally every page load (IF NOT EXISTS).
// ─────────────────────────────────────────────────────────────────────────────
$step1_ok  = true;
$step1_msg = '';
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `invoice_snapshots` (
      `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `order_id`             BIGINT UNSIGNED NOT NULL,
      `order_number`         VARCHAR(50)     NOT NULL,
      `snapshot_trigger`     ENUM('dispatch','manual','backfill') NOT NULL DEFAULT 'dispatch',
      `snapshot_at`          DATETIME        NOT NULL,
      `customer_id`          BIGINT UNSIGNED NOT NULL,
      `customer_name`        VARCHAR(255)    NOT NULL,
      `customer_phone`       VARCHAR(50)     DEFAULT NULL,
      `customer_email`       VARCHAR(255)    DEFAULT NULL,
      `customer_address`     TEXT            DEFAULT NULL,
      `previous_due`         DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
      `subtotal`             DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
      `discount_amount`      DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
      `tax_amount`           DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
      `total_amount`         DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
      `advance_paid`         DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
      `balance_due`          DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
      `total_outstanding`    DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
      `company_name_bn`      VARCHAR(255)    DEFAULT NULL,
      `company_name_en`      VARCHAR(100)    DEFAULT NULL,
      `company_address`      VARCHAR(500)    DEFAULT NULL,
      `company_phone`        VARCHAR(100)    DEFAULT NULL,
      `company_email`        VARCHAR(100)    DEFAULT NULL,
      `items_json`           LONGTEXT        NOT NULL,
      `shipping_address`     TEXT            DEFAULT NULL,
      `branch_name`          VARCHAR(100)    DEFAULT NULL,
      `truck_number`         VARCHAR(50)     DEFAULT NULL,
      `driver_name`          VARCHAR(100)    DEFAULT NULL,
      `driver_contact`       VARCHAR(50)     DEFAULT NULL,
      `shipped_date`         DATETIME        DEFAULT NULL,
      `delivered_date`       DATETIME        DEFAULT NULL,
      `order_date`           DATE            NOT NULL,
      `required_date`        DATE            DEFAULT NULL,
      `invoice_date`         DATE            DEFAULT NULL,
      `order_type`           VARCHAR(50)     DEFAULT NULL,
      `order_status`         VARCHAR(50)     DEFAULT NULL,
      `special_instructions` TEXT            DEFAULT NULL,
      `created_by_user_id`   BIGINT UNSIGNED DEFAULT NULL,
      `created_at`           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uix_order_id`   (`order_id`),
      KEY       `idx_customer_id` (`customer_id`),
      KEY       `idx_snapshot_at` (`snapshot_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT='Frozen invoice snapshots — one row per dispatched order, never mutated after creation.'");
    $step1_msg = 'invoice_snapshots table is ready.';
} catch (PDOException $e) {
    $step1_ok  = false;
    $step1_msg = 'STEP 1 — DDL failed: ' . $e->getMessage();
}

// ─────────────────────────────────────────────────────────────────────────────
// DIAGNOSIS — always run so the page shows current state before and after repair
// Uses plain $db->query() (not dbq) so a query failure shows a graceful message
// instead of crashing the page.
// ─────────────────────────────────────────────────────────────────────────────

// All dispatched credit orders — excludes opening-balance orders (OB- and INV-INITIAL-)
// and orphan rows where customer_id=0.
$dispatched_orders = $db->query(
    "SELECT co.id, co.order_number, co.customer_id, co.order_date, co.required_date,
            co.subtotal, co.discount_amount, co.tax_amount, co.total_amount,
            co.advance_paid, co.balance_due, co.status, co.order_type,
            co.shipping_address, co.special_instructions, co.created_by_user_id,
            co.assigned_branch_id,
            c.name AS customer_name, c.phone_number AS customer_phone,
            c.email AS customer_email, c.business_address AS customer_address,
            cos.truck_number, cos.driver_name, cos.driver_contact,
            cos.shipped_date, cos.delivered_date,
            b.name AS branch_name
     FROM credit_orders co
     JOIN customers c ON co.customer_id = c.id
     LEFT JOIN credit_order_shipping cos ON co.id = cos.order_id
     LEFT JOIN branches b ON co.assigned_branch_id = b.id
     WHERE co.status IN ('shipped','delivered')
       AND co.customer_id > 0
       AND co.order_number NOT LIKE 'OB-%'
       AND co.order_number NOT LIKE 'INV-INITIAL-%'
     ORDER BY co.customer_id ASC,
              COALESCE(cos.shipped_date, co.order_date) ASC,
              co.id ASC"
)->results();

// Orders missing a customer_ledger invoice entry
$missing_ledger = [];
foreach ($dispatched_orders as $o) {
    $ex = $db->query(
        "SELECT id FROM customer_ledger
         WHERE customer_id = ? AND reference_id = ?
           AND reference_type IN ('credit_order','credit_orders')
           AND transaction_type = 'invoice' LIMIT 1",
        [$o->customer_id, $o->id]
    )->first();
    if (!$ex) $missing_ledger[] = $o;
}

// Orders missing an invoice snapshot
$missing_snapshot = [];
foreach ($dispatched_orders as $o) {
    $ex = $db->query("SELECT id FROM invoice_snapshots WHERE order_id = ? LIMIT 1", [$o->id])->first();
    if (!$ex) $missing_snapshot[] = $o;
}

// Customers with current_balance that doesn't match their latest ledger entry
$wrong_balance = [];
foreach ($db->query("SELECT id, name, current_balance FROM customers WHERE status='active' AND id>0")->results() as $c) {
    $last = $db->query(
        "SELECT balance_after FROM customer_ledger
         WHERE customer_id = ? ORDER BY transaction_date DESC, id DESC LIMIT 1",
        [$c->id]
    )->first();
    if (!$last) continue;
    if (abs((float)$last->balance_after - (float)$c->current_balance) > 0.01) {
        $wrong_balance[] = [
            'id'      => $c->id,
            'name'    => $c->name,
            'db_bal'  => $c->current_balance,
            'correct' => $last->balance_after,
        ];
    }
}

// POS Revenue accounts (wrong accounts used for credit-order journal lines)
$pos_account_ids = [];
$pos_accounts    = $db->query(
    "SELECT id, name FROM chart_of_accounts
     WHERE account_type = 'Revenue' AND LOWER(name) LIKE '%pos%'"
)->results();
foreach ($pos_accounts as $pa) {
    $pos_account_ids[] = (int)$pa->id;
}

// Transaction lines in credit-order journals that use a POS account on the credit side
$wrong_journal_lines = [];
if (!empty($pos_account_ids)) {
    $in_ph = implode(',', array_fill(0, count($pos_account_ids), '?'));
    $wrong_journal_lines = $db->query(
        "SELECT tl.id AS tl_id, tl.account_id, tl.credit_amount,
                je.id AS je_id, je.related_document_id AS order_id,
                co.order_number, co.assigned_branch_id,
                coa.name AS pos_account_name
         FROM transaction_lines tl
         JOIN journal_entries je ON tl.journal_entry_id = je.id
         JOIN credit_orders co ON je.related_document_id = co.id
              AND je.related_document_type = 'credit_orders'
         JOIN chart_of_accounts coa ON tl.account_id = coa.id
         WHERE tl.credit_amount > 0
           AND tl.account_id IN ({$in_ph})
         ORDER BY co.id ASC",
        $pos_account_ids
    )->results();
}

// Global Credit Sales Revenue account — fallback when no branch-specific one exists
$global_credit_sales_acct = $db->query(
    "SELECT id, name FROM chart_of_accounts
     WHERE account_type = 'Revenue'
       AND branch_id IS NULL
       AND (LOWER(name) LIKE '%credit%' OR LOWER(description) LIKE '%credit%')
       AND LOWER(name) NOT LIKE '%pos%'
     ORDER BY id ASC LIMIT 1"
)->first();
if (!$global_credit_sales_acct) {
    $global_credit_sales_acct = $db->query(
        "SELECT id, name FROM chart_of_accounts
         WHERE account_type = 'Revenue' AND branch_id IS NULL
           AND LOWER(name) NOT LIKE '%pos%'
         ORDER BY id ASC LIMIT 1"
    )->first();
}

// ─────────────────────────────────────────────────────────────────────────────
// RUN REPAIR — only on POST, only if STEP 1 succeeded
// ─────────────────────────────────────────────────────────────────────────────
if ($run && $step1_ok) {
    try {
        $pdo->beginTransaction();

        // ── STEP 2: Insert missing customer_ledger invoice entries ───────────
        //
        // Strategy:
        //   a) Insert every missing invoice row (balance_after = 0 placeholder).
        //   b) After all inserts for a customer, re-walk their FULL ledger in
        //      chronological order and recompute every balance_after from scratch.
        //      This is safer than partial recalculation because it handles cases
        //      where new entries interleave with existing ones by date.

        $by_customer = [];
        foreach ($missing_ledger as $o) {
            $by_customer[$o->customer_id][] = $o;
        }

        foreach ($by_customer as $cid => $orders) {
            // Insert each missing entry (placeholder balance — fixed in rebalance)
            foreach ($orders as $o) {
                $tx_date = $o->shipped_date
                    ? date('Y-m-d', strtotime($o->shipped_date))
                    : $o->order_date;

                // Link to the existing journal entry for this order if one exists
                $je = dbq($db,
                    "SELECT id FROM journal_entries
                     WHERE related_document_type = 'credit_orders'
                       AND related_document_id = ? LIMIT 1",
                    [$o->id]
                )->first();

                dbi($db, 'customer_ledger', [
                    'customer_id'        => $cid,
                    'transaction_date'   => $tx_date,
                    'transaction_type'   => 'invoice',
                    'reference_type'     => 'credit_orders',
                    'reference_id'       => $o->id,
                    'invoice_number'     => $o->order_number,
                    'description'        => 'Credit sale invoice (backfill) — ' . $o->order_number,
                    'debit_amount'       => $o->total_amount,
                    'credit_amount'      => 0.00,
                    'balance_after'      => 0.00,
                    'created_by_user_id' => $o->created_by_user_id,
                    'journal_entry_id'   => $je ? (int)$je->id : null,
                    'created_at'         => $o->shipped_date ?? ($o->order_date . ' 00:00:00'),
                ]);

                $ops[] = [
                    'step'   => 2,
                    'detail' => "INSERT customer_ledger: invoice for {$o->order_number}"
                              . " ({$o->customer_name}) " . fmt($o->total_amount),
                ];
            }

            // Full chronological rebalance for this customer
            $all_entries = dbq($db,
                "SELECT id, debit_amount, credit_amount FROM customer_ledger
                 WHERE customer_id = ?
                 ORDER BY transaction_date ASC, id ASC",
                [$cid]
            )->results();

            $running = 0.0;
            foreach ($all_entries as $entry) {
                $running = round($running + (float)$entry->debit_amount - (float)$entry->credit_amount, 2);
                dbq($db,
                    "UPDATE customer_ledger SET balance_after = ? WHERE id = ?",
                    [$running, $entry->id]
                );
            }

            $ops[] = [
                'step'   => 2,
                'detail' => "REBALANCE customer #{$cid} — "
                          . count($all_entries) . " entries, final balance " . fmt($running),
            ];
        }

        // ── STEP 3: Fix customers.current_balance ─────────────────────────────
        //
        // Re-query inside the transaction so STEP 2's rebalanced ledger is visible.
        // The correlated subquery picks the last ledger row per customer by date+id.

        $wrong_now = dbq($db,
            "SELECT c.id, c.name, c.current_balance,
                    (SELECT cl.balance_after
                     FROM customer_ledger cl
                     WHERE cl.customer_id = c.id
                     ORDER BY cl.transaction_date DESC, cl.id DESC
                     LIMIT 1) AS correct
             FROM customers c
             WHERE c.status = 'active' AND c.id > 0
             HAVING correct IS NOT NULL
               AND ABS(correct - current_balance) > 0.01"
        )->results();

        foreach ($wrong_now as $wb) {
            dbq($db,
                "UPDATE customers SET current_balance = ? WHERE id = ?",
                [$wb->correct, $wb->id]
            );
            $ops[] = [
                'step'   => 3,
                'detail' => "UPDATE customers: {$wb->name} (#{$wb->id})"
                          . " balance " . fmt($wb->current_balance) . " → " . fmt($wb->correct),
            ];
        }

        // ── STEP 4: Backfill invoice_snapshots ───────────────────────────────

        $company = [
            'name_bn' => 'উজ্জল ফ্লাওয়ার মিলস',
            'name_en' => 'Ujjal Flour Mills',
            'address' => '১৭, নুরাইবাগ, ডেমরা, ঢাকা',
            'phone'   => '+880-XXX-XXXXXX',
            'email'   => 'info@ujjalfm.com',
        ];

        foreach ($dispatched_orders as $o) {
            // Check inside the transaction (idempotent)
            $ex = dbq($db, "SELECT id FROM invoice_snapshots WHERE order_id = ? LIMIT 1", [$o->id])->first();
            if ($ex) continue;

            // Fetch line items — financial columns come from credit_order_items (safe: stored at
            // order creation, independent of future product table changes). product_name and
            // variant fields come from the current products/product_variants JOIN and are frozen
            // here so that post-repair product fixes don't alter historical invoice display.
            // product_id and variant_id are included as traceable references after product fix.
            $items = dbq($db,
                "SELECT coi.id AS item_id, coi.product_id, coi.variant_id,
                        coi.quantity, coi.unit_price, coi.discount_amount, coi.tax_amount,
                        coi.line_total, p.base_name AS product_name,
                        pv.grade, pv.weight_variant, pv.unit_of_measure, pv.sku AS variant_sku
                 FROM credit_order_items coi
                 JOIN products p ON coi.product_id = p.id
                 LEFT JOIN product_variants pv ON coi.variant_id = pv.id
                 WHERE coi.order_id = ? ORDER BY coi.id ASC",
                [$o->id]
            )->results();

            $items_arr = [];
            foreach ($items as $item) {
                $vd = [];
                if ($item->grade)          $vd[] = 'Grade ' . $item->grade;
                if ($item->weight_variant) $vd[] = $item->weight_variant;
                $items_arr[] = [
                    'product_id'      => (int)$item->product_id,
                    'variant_id'      => $item->variant_id ? (int)$item->variant_id : null,
                    'product_name'    => $item->product_name,
                    'variant_detail'  => implode(' · ', $vd),
                    'sku'             => $item->variant_sku,
                    'unit'            => $item->unit_of_measure,
                    'quantity'        => (float)$item->quantity,
                    'unit_price'      => (float)$item->unit_price,
                    'discount_amount' => (float)$item->discount_amount,
                    'tax_amount'      => (float)$item->tax_amount,
                    'line_total'      => (float)$item->line_total,
                ];
            }

            // previous_due = balance just before this invoice was added to the ledger
            $le = dbq($db,
                "SELECT balance_after, debit_amount, credit_amount FROM customer_ledger
                 WHERE customer_id = ? AND reference_id = ?
                   AND reference_type IN ('credit_order','credit_orders')
                   AND transaction_type = 'invoice'
                 ORDER BY id ASC LIMIT 1",
                [$o->customer_id, $o->id]
            )->first();

            if ($le) {
                $previous_due = (float)$le->balance_after
                              - (float)$le->debit_amount
                              + (float)$le->credit_amount;
            } else {
                // No ledger entry exists even after STEP 2 — fall back
                $cust_base = dbq($db, "SELECT initial_due FROM customers WHERE id = ? LIMIT 1", [$o->customer_id])->first();
                $older = dbq($db,
                    "SELECT COALESCE(SUM(balance_due), 0) AS s FROM credit_orders
                     WHERE customer_id = ? AND id < ?
                       AND status NOT IN ('cancelled','rejected')
                       AND order_number NOT LIKE 'INV-INITIAL-%'",
                    [$o->customer_id, $o->id]
                )->first();
                $previous_due = (float)($cust_base->initial_due ?? 0) + (float)($older->s ?? 0);
            }

            $previous_due      = round($previous_due, 2);
            $total_outstanding = round($previous_due + max(0.0, (float)$o->balance_due), 2);
            $invoice_date      = $o->shipped_date
                ? date('Y-m-d', strtotime($o->shipped_date))
                : $o->order_date;

            dbi($db, 'invoice_snapshots', [
                'order_id'             => $o->id,
                'order_number'         => $o->order_number,
                'snapshot_trigger'     => 'backfill',
                'snapshot_at'          => $o->shipped_date ?? ($o->order_date . ' 00:00:00'),
                'customer_id'          => $o->customer_id,
                'customer_name'        => $o->customer_name,
                'customer_phone'       => $o->customer_phone,
                'customer_email'       => $o->customer_email,
                'customer_address'     => $o->customer_address,
                'previous_due'         => $previous_due,
                'subtotal'             => $o->subtotal,
                'discount_amount'      => $o->discount_amount,
                'tax_amount'           => $o->tax_amount,
                'total_amount'         => $o->total_amount,
                'advance_paid'         => $o->advance_paid,
                'balance_due'          => $o->balance_due,
                'total_outstanding'    => $total_outstanding,
                'company_name_bn'      => $company['name_bn'],
                'company_name_en'      => $company['name_en'],
                'company_address'      => $company['address'],
                'company_phone'        => $company['phone'],
                'company_email'        => $company['email'],
                'items_json'           => json_encode($items_arr, JSON_UNESCAPED_UNICODE),
                'shipping_address'     => $o->shipping_address,
                'branch_name'          => $o->branch_name,
                'truck_number'         => $o->truck_number,
                'driver_name'          => $o->driver_name,
                'driver_contact'       => $o->driver_contact,
                'shipped_date'         => $o->shipped_date,
                'delivered_date'       => $o->delivered_date,
                'order_date'           => $o->order_date,
                'required_date'        => $o->required_date,
                'invoice_date'         => $invoice_date,
                'order_type'           => $o->order_type,
                'order_status'         => $o->status,
                'special_instructions' => $o->special_instructions,
                'created_by_user_id'   => $o->created_by_user_id,
            ]);

            $ops[] = [
                'step'   => 4,
                'detail' => "INSERT invoice_snapshots: {$o->order_number} ({$o->customer_name})"
                          . " prev=" . fmt($previous_due)
                          . " outstanding=" . fmt($total_outstanding),
            ];
        }

        // ── STEP 5: Fix wrong revenue account_id in transaction_lines ─────────
        //
        // credit_orders journal entries previously used a POS Revenue account on
        // the credit (revenue) side. Replace with the correct Credit Sales Revenue
        // account from chart_of_accounts (Accounts module only — never bank_accounts
        // or bank_transactions).

        if (!empty($pos_account_ids) && !empty($wrong_journal_lines)) {
            $branch_acct_cache = [];

            foreach ($wrong_journal_lines as $wl) {
                $bid = $wl->assigned_branch_id;

                if (!array_key_exists($bid, $branch_acct_cache)) {
                    $correct = null;
                    if ($bid) {
                        $correct = dbq($db,
                            "SELECT id, name FROM chart_of_accounts
                             WHERE account_type = 'Revenue'
                               AND branch_id = ?
                               AND (LOWER(name) LIKE '%credit%' OR LOWER(description) LIKE '%credit%')
                               AND LOWER(name) NOT LIKE '%pos%'
                             ORDER BY id ASC LIMIT 1",
                            [$bid]
                        )->first();
                    }
                    $branch_acct_cache[$bid] = $correct ?: $global_credit_sales_acct;
                }

                $correct_acct = $branch_acct_cache[$bid];
                if (!$correct_acct) {
                    throw new RuntimeException(
                        "STEP 5: No Credit Sales Revenue account found in chart_of_accounts"
                        . " for branch_id=" . ($bid ?: 'NULL') . "."
                        . " Add a non-POS Revenue account to chart_of_accounts first."
                    );
                }

                dbq($db,
                    "UPDATE transaction_lines SET account_id = ? WHERE id = ?",
                    [$correct_acct->id, $wl->tl_id]
                );

                $ops[] = [
                    'step'   => 5,
                    'detail' => "UPDATE transaction_lines #{$wl->tl_id} [order {$wl->order_number}]:"
                              . " account_id {$wl->account_id} ({$wl->pos_account_name})"
                              . " → {$correct_acct->id} ({$correct_acct->name})",
                ];
            }
        }

        $pdo->commit();
        $committed = true;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err_msg = $e->getMessage();
    }

    // Re-run diagnosis so summary cards show post-repair state
    $missing_ledger   = [];
    $missing_snapshot = [];
    $wrong_balance    = [];
    foreach ($dispatched_orders as $o) {
        $ex = $db->query(
            "SELECT id FROM customer_ledger WHERE customer_id=? AND reference_id=?
             AND reference_type IN ('credit_order','credit_orders')
             AND transaction_type='invoice' LIMIT 1",
            [$o->customer_id, $o->id]
        )->first();
        if (!$ex) $missing_ledger[] = $o;

        $ex2 = $db->query("SELECT id FROM invoice_snapshots WHERE order_id=? LIMIT 1", [$o->id])->first();
        if (!$ex2) $missing_snapshot[] = $o;
    }
    foreach ($db->query("SELECT id, name, current_balance FROM customers WHERE status='active' AND id>0")->results() as $c) {
        $last = $db->query(
            "SELECT balance_after FROM customer_ledger WHERE customer_id=? ORDER BY transaction_date DESC, id DESC LIMIT 1",
            [$c->id]
        )->first();
        if (!$last) continue;
        if (abs((float)$last->balance_after - (float)$c->current_balance) > 0.01) {
            $wrong_balance[] = ['id' => $c->id, 'name' => $c->name, 'db_bal' => $c->current_balance, 'correct' => $last->balance_after];
        }
    }
    if (!empty($pos_account_ids)) {
        $in_ph = implode(',', array_fill(0, count($pos_account_ids), '?'));
        $wrong_journal_lines = $db->query(
            "SELECT tl.id AS tl_id, tl.account_id, tl.credit_amount,
                    je.id AS je_id, je.related_document_id AS order_id,
                    co.order_number, co.assigned_branch_id,
                    coa.name AS pos_account_name
             FROM transaction_lines tl
             JOIN journal_entries je ON tl.journal_entry_id = je.id
             JOIN credit_orders co ON je.related_document_id = co.id
                  AND je.related_document_type = 'credit_orders'
             JOIN chart_of_accounts coa ON tl.account_id = coa.id
             WHERE tl.credit_amount > 0 AND tl.account_id IN ({$in_ph})
             ORDER BY co.id ASC",
            $pos_account_ids
        )->results();
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Post-bulk-upload orders for manual verification
// ─────────────────────────────────────────────────────────────────────────────
$bulk_ts      = '2026-06-20 06:18:59';
$bulk_max_id  = 153;
$post_bulk_orders = $db->query(
    "SELECT co.id, co.order_number, co.customer_id, c.name AS customer_name,
            co.total_amount, co.status, co.created_at
     FROM credit_orders co
     JOIN customers c ON co.customer_id = c.id
     WHERE co.customer_id BETWEEN 1 AND ?
       AND co.created_at > ?
       AND co.order_number NOT LIKE 'OB-%'
       AND co.order_number NOT LIKE 'INV-INITIAL-%'
     ORDER BY co.created_at ASC",
    [$bulk_max_id, $bulk_ts]
)->results();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Ledger Repair Tool</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 text-sm font-sans">

<div class="max-w-5xl mx-auto p-6 space-y-6">

  <!-- Header -->
  <div class="bg-white rounded-xl shadow p-6 border-l-4 border-blue-600">
    <h1 class="text-xl font-bold text-gray-800">Ledger Repair & Invoice Snapshot Tool</h1>
    <p class="text-gray-500 mt-1 text-xs">
      All steps 2–5 run inside one transaction. On any error, everything is rolled back and
      the full operation log is shown on screen. Safe to re-run — all operations are idempotent.
    </p>
  </div>

  <!-- STEP 1 status -->
  <div class="rounded-xl px-5 py-3 text-sm font-medium
    <?= $step1_ok ? 'bg-green-50 border border-green-300 text-green-800'
                  : 'bg-red-50 border border-red-300 text-red-800' ?>">
    STEP 1 — DDL: <?= h($step1_msg) ?>
  </div>

  <!-- ── Operation Log (shown after run) ── -->
  <?php if (!empty($ops)): ?>
  <?php
    $log_is_ok     = $committed;
    $log_header    = $committed ? 'Committed — all operations applied' : 'Rolled back — no changes were saved';
    $hdr_class     = $committed ? 'bg-green-700' : 'bg-red-700';
    $row_class_ok  = $committed ? 'text-green-800' : 'text-red-800 line-through opacity-60';
    $badge_colors  = [
        1 => 'bg-purple-100 text-purple-700',
        2 => 'bg-blue-100 text-blue-700',
        3 => 'bg-amber-100 text-amber-700',
        4 => 'bg-teal-100 text-teal-700',
        5 => 'bg-red-100 text-red-700',
    ];
  ?>
  <div class="bg-white rounded-xl shadow overflow-hidden">
    <div class="<?= $hdr_class ?> text-white px-5 py-3 font-bold flex items-center gap-3">
      <span><?= $log_is_ok ? '✔' : '✘' ?> <?= $log_header ?></span>
      <span class="ml-auto text-xs opacity-80"><?= count($ops) ?> operations</span>
    </div>
    <?php if ($err_msg): ?>
    <div class="bg-red-50 border-b border-red-200 px-5 py-3">
      <p class="font-bold text-red-700 text-xs">Error:</p>
      <p class="font-mono text-red-600 text-xs mt-1 whitespace-pre-wrap"><?= h($err_msg) ?></p>
    </div>
    <?php endif; ?>
    <div class="overflow-auto max-h-96 divide-y divide-gray-100">
    <?php foreach ($ops as $op): ?>
    <div class="flex items-start gap-3 px-5 py-2">
      <span class="inline-block shrink-0 px-1.5 py-0.5 rounded text-[10px] font-bold mt-0.5
        <?= $badge_colors[$op['step']] ?? 'bg-gray-100 text-gray-600' ?>">
        S<?= $op['step'] ?>
      </span>
      <span class="font-mono text-xs <?= $row_class_ok ?>"><?= h($op['detail']) ?></span>
    </div>
    <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Diagnosis Summary Cards ── -->
  <?php
    $n_ledger   = count($missing_ledger);
    $n_balance  = count($wrong_balance);
    $n_snapshot = count($missing_snapshot);
    $n_journal  = count($wrong_journal_lines);
    $total_issues = $n_ledger + $n_balance + $n_snapshot + $n_journal;
  ?>
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
    <?php
      $cards = [
        ['label' => 'Missing ledger entries',    'n' => $n_ledger,   'sev' => 'red'],
        ['label' => 'Wrong customer balance',     'n' => $n_balance,  'sev' => 'amber'],
        ['label' => 'Missing invoice snapshots',  'n' => $n_snapshot, 'sev' => 'orange'],
        ['label' => 'Wrong revenue account (TL)', 'n' => $n_journal,  'sev' => 'rose'],
      ];
      $sev_top   = ['red'=>'border-red-500','amber'=>'border-amber-500','orange'=>'border-orange-500','rose'=>'border-rose-500'];
      $sev_text  = ['red'=>'text-red-600','amber'=>'text-amber-600','orange'=>'text-orange-600','rose'=>'text-rose-600'];
      foreach ($cards as $card):
        $top  = $card['n'] > 0 ? $sev_top[$card['sev']]  : 'border-green-500';
        $txt  = $card['n'] > 0 ? $sev_text[$card['sev']] : 'text-green-600';
    ?>
    <div class="bg-white rounded-xl shadow p-4 text-center border-t-4 <?= $top ?>">
      <div class="text-3xl font-black <?= $txt ?>"><?= $card['n'] ?></div>
      <div class="text-gray-500 text-xs mt-1"><?= $card['label'] ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── Run Repair Button ── -->
  <?php if ($total_issues > 0 && $step1_ok): ?>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
    <button type="submit" name="run_repair" value="1"
      class="w-full bg-blue-700 hover:bg-blue-800 text-white font-bold py-3 px-6 rounded-xl text-base transition">
      Run Full Repair
      (<?= $n_ledger ?> ledger · <?= $n_balance ?> balances · <?= $n_snapshot ?> snapshots · <?= $n_journal ?> journal lines)
    </button>
  </form>
  <?php elseif ($step1_ok): ?>
  <div class="bg-green-50 border border-green-400 rounded-xl p-4 text-center text-green-800 font-bold">
    All data is clean — nothing to repair.
  </div>
  <?php endif; ?>

  <!-- ── Detail: Missing ledger entries ── -->
  <?php if ($n_ledger > 0): ?>
  <div class="bg-white rounded-xl shadow overflow-hidden">
    <div class="bg-red-600 text-white px-5 py-3 font-bold text-sm">
      Orders Missing customer_ledger Invoice Entry (<?= $n_ledger ?>)
    </div>
    <div class="overflow-auto max-h-72">
    <table class="w-full text-xs">
      <thead class="bg-gray-50 text-gray-500 uppercase text-[10px]">
        <tr>
          <th class="px-3 py-2 text-left">Order</th>
          <th class="px-3 py-2 text-left">Customer</th>
          <th class="px-3 py-2 text-right">Total</th>
          <th class="px-3 py-2 text-left">Status</th>
          <th class="px-3 py-2 text-left">Shipped</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
      <?php foreach ($missing_ledger as $o): ?>
        <tr class="hover:bg-red-50">
          <td class="px-3 py-2 font-mono font-semibold"><?= h($o->order_number) ?></td>
          <td class="px-3 py-2"><?= h($o->customer_name) ?></td>
          <td class="px-3 py-2 text-right"><?= fmt($o->total_amount) ?></td>
          <td class="px-3 py-2"><?= h($o->status) ?></td>
          <td class="px-3 py-2 text-gray-500">
            <?= $o->shipped_date ? h(date('d M Y', strtotime($o->shipped_date))) : '—' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Detail: Wrong balances ── -->
  <?php if ($n_balance > 0): ?>
  <div class="bg-white rounded-xl shadow overflow-hidden">
    <div class="bg-amber-600 text-white px-5 py-3 font-bold text-sm">
      Customers with Incorrect current_balance (<?= $n_balance ?>)
    </div>
    <div class="overflow-auto max-h-56">
    <table class="w-full text-xs">
      <thead class="bg-gray-50 text-gray-500 uppercase text-[10px]">
        <tr>
          <th class="px-3 py-2 text-left">ID</th>
          <th class="px-3 py-2 text-left">Customer</th>
          <th class="px-3 py-2 text-right">In DB</th>
          <th class="px-3 py-2 text-right">Correct</th>
          <th class="px-3 py-2 text-right">Diff</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
      <?php foreach ($wrong_balance as $wb): ?>
        <?php $diff = (float)$wb['correct'] - (float)$wb['db_bal']; ?>
        <tr class="hover:bg-amber-50">
          <td class="px-3 py-2 text-gray-400"><?= (int)$wb['id'] ?></td>
          <td class="px-3 py-2 font-semibold"><?= h($wb['name']) ?></td>
          <td class="px-3 py-2 text-right text-red-600"><?= fmt($wb['db_bal']) ?></td>
          <td class="px-3 py-2 text-right text-green-700"><?= fmt($wb['correct']) ?></td>
          <td class="px-3 py-2 text-right font-bold <?= $diff > 0 ? 'text-red-700' : 'text-blue-700' ?>">
            <?= ($diff > 0 ? '+' : '') . fmt($diff) ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Detail: Wrong journal revenue accounts ── -->
  <?php if ($n_journal > 0): ?>
  <div class="bg-white rounded-xl shadow overflow-hidden">
    <div class="bg-rose-600 text-white px-5 py-3 font-bold text-sm">
      transaction_lines with Wrong Revenue Account (<?= $n_journal ?>)
      <?php if ($global_credit_sales_acct): ?>
      <span class="ml-2 text-rose-200 font-normal text-xs">
        Will fix → acct #<?= (int)$global_credit_sales_acct->id ?> <?= h($global_credit_sales_acct->name) ?>
      </span>
      <?php else: ?>
      <span class="ml-2 text-rose-200 font-normal text-xs">⚠ No credit sales account found!</span>
      <?php endif; ?>
    </div>
    <div class="overflow-auto max-h-56">
    <table class="w-full text-xs">
      <thead class="bg-gray-50 text-gray-500 uppercase text-[10px]">
        <tr>
          <th class="px-3 py-2 text-left">Order</th>
          <th class="px-3 py-2 text-left">TL #</th>
          <th class="px-3 py-2 text-left">Current (wrong) Account</th>
          <th class="px-3 py-2 text-right">Credit Amount</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
      <?php foreach ($wrong_journal_lines as $wl): ?>
        <tr class="hover:bg-rose-50">
          <td class="px-3 py-2 font-mono font-semibold"><?= h($wl->order_number) ?></td>
          <td class="px-3 py-2 text-gray-400">#<?= (int)$wl->tl_id ?></td>
          <td class="px-3 py-2 text-red-600"><?= h($wl->pos_account_name) ?> (#<?= (int)$wl->account_id ?>)</td>
          <td class="px-3 py-2 text-right"><?= fmt($wl->credit_amount) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Post-bulk-upload orders for verification ── -->
  <div class="bg-white rounded-xl shadow overflow-hidden">
    <div class="bg-indigo-700 text-white px-5 py-3">
      <div class="font-bold text-sm">Orders After Bulk Customer Upload</div>
      <div class="text-indigo-200 text-xs mt-0.5">
        Upload timestamp: <?= h($bulk_ts) ?> · Customers 1–<?= $bulk_max_id ?>
        · <?= count($post_bulk_orders) ?> orders found
      </div>
    </div>
    <?php if (empty($post_bulk_orders)): ?>
    <p class="text-center text-gray-400 py-6 text-sm">No orders found after bulk upload timestamp.</p>
    <?php else: ?>
    <div class="overflow-auto max-h-72">
    <table class="w-full text-xs">
      <thead class="bg-gray-50 text-gray-500 uppercase text-[10px]">
        <tr>
          <th class="px-3 py-2 text-left">Order</th>
          <th class="px-3 py-2 text-left">Customer</th>
          <th class="px-3 py-2 text-right">Total</th>
          <th class="px-3 py-2 text-left">Status</th>
          <th class="px-3 py-2 text-left">Created</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
      <?php foreach ($post_bulk_orders as $o): ?>
        <tr class="hover:bg-indigo-50">
          <td class="px-3 py-2 font-mono font-semibold"><?= h($o->order_number) ?></td>
          <td class="px-3 py-2"><?= h($o->customer_name) ?> <span class="text-gray-400">#<?= (int)$o->customer_id ?></span></td>
          <td class="px-3 py-2 text-right"><?= fmt($o->total_amount) ?></td>
          <td class="px-3 py-2">
            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold
              <?= $o->status === 'delivered' ? 'bg-green-100 text-green-700'
                : ($o->status === 'shipped'   ? 'bg-blue-100  text-blue-700'
                :                               'bg-gray-100  text-gray-600') ?>">
              <?= h($o->status) ?>
            </span>
          </td>
          <td class="px-3 py-2 text-gray-400"><?= h(date('d M Y H:i', strtotime($o->created_at))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <p class="text-center text-gray-400 text-[10px] pb-4">
    Repair tool v2.0 · <?= date('d M Y H:i:s') ?> · Superadmin only
  </p>

</div>
</body>
</html>