<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra',
                  'sales-srg', 'sales-demra', 'sales-other'];
restrict_access($allowed_roles, 'credit_sales', 'order_amendment');

global $db;
$currentUser = getCurrentUser();
$user_id   = $currentUser['id']   ?? null;
$user_role = $currentUser['role'] ?? '';
$pageTitle = 'Order Amendment';

$is_admin    = in_array($user_role, ['Superadmin', 'admin']);
$can_request = $is_admin || userCanPageAction('credit_sales', 'order_amendment', 'can_request');
$has_approve_toggle = userCanPageAction('credit_sales', 'order_amendment', 'can_approve');
$my_limit    = $is_admin ? null : getUserActionLimit((int)$user_id, 'amend_order', true);

// Product/quantity correction — master toggle (admin/settings.php) AND
// per-user delegation both required; admin/Superadmin still need the
// toggle ON (same "toggle applies to everyone" convention as
// dispatchGlobalHoldEnabled()), matching the explicit ask for a
// Superadmin-activated, then-delegable capability.
$can_change_products = orderFullAmendmentEnabled()
    && ($is_admin || userCanPageAction('credit_sales', 'order_amendment', 'can_change_products'));

/* ─── Self-migrating schema (CREATE TABLE IF NOT EXISTS only) ─────────────── */
$pdo = $db->getPdo();
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `order_amendments` (
      `id`                  bigint UNSIGNED NOT NULL AUTO_INCREMENT,
      `amd_number`          varchar(50) NOT NULL,
      `order_id`            bigint UNSIGNED NOT NULL,
      `mode`                enum('pre','post') NOT NULL COMMENT 'pre=before dispatch (edit order), post=after (ledger note)',
      `amendment_type`      enum('transport_change','price_revision','qty_change','freight_charge','rebate','correction') NOT NULL,
      `reason`              text NOT NULL,
      `delta_amount`        decimal(15,2) NOT NULL DEFAULT 0.00,
      `old_values`          text DEFAULT NULL,
      `new_values`          text DEFAULT NULL,
      `status`              enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
      `requested_by_user_id` bigint UNSIGNED NOT NULL,
      `approved_by_user_id`  bigint UNSIGNED DEFAULT NULL,
      `approved_at`          timestamp NULL DEFAULT NULL,
      `created_at`           timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_amd_number` (`amd_number`),
      KEY `idx_amd_order` (`order_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
// 8 Sep 2026: customer-consent attestation, required for post-dispatch
// (already-delivered/shipped) amendments — see the mandatory-checkbox +
// note in the form below. Nullable so it never affects pre-mode rows.
try { $pdo->exec("ALTER TABLE `order_amendments` ADD COLUMN `customer_consent_note` TEXT NULL AFTER `reason`"); } catch (\Throwable $e) {}
// 12 Sep 2026: `amendment_type` is a STRICT MySQL ENUM — a bare INSERT with a
// value outside it is caught and silently swallowed by Database::query()
// (this codebase's well-documented ENUM-swallow bug class), so the new
// 'product_correction' type MUST be added to the enum itself, not just to
// $type_labels in PHP. Probe first (information_schema), never a bare ALTER.
try {
    $col = $db->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_amendments' AND COLUMN_NAME = 'amendment_type'"
    )->first();
    if ($col && strpos((string)$col->COLUMN_TYPE, 'product_correction') === false) {
        $pdo->exec("ALTER TABLE `order_amendments` MODIFY COLUMN `amendment_type`
            ENUM('transport_change','price_revision','qty_change','freight_charge','rebate','correction','product_correction') NOT NULL");
    }
} catch (\Throwable $e) {}

$error   = null;
$success = null;

$type_labels = [
    'transport_change' => 'Transport Change (e.g. Big Truck → Mini Trucks)',
    'price_revision'   => 'Price Revision',
    'qty_change'       => 'Quantity Change',
    'freight_charge'   => 'Freight / Delivery Charge',
    'rebate'           => 'Rebate / Discount (no goods returned)',
    'correction'       => 'Entry Correction',
];
// Only offered when $can_change_products is true (master toggle + delegated
// action) — kept out of $type_labels' base list so it never appears in the
// dropdown for anyone without the capability.
$product_correction_label = 'Product/Quantity Correction (wrong product entered)';
if ($can_change_products) {
    $type_labels['product_correction'] = $product_correction_label;
}

/* ─── Authority: who may approve an amendment right now? ──────────────────
   Admin: anything. Delegated officer (approval limit set + can_approve toggle):
   decreases freely; increases only while the NEW order total stays within
   their personal limit. Others: request only (goes pending → admin). */
function amendmentAuthority(float $delta, float $new_total): bool {
    global $is_admin, $has_approve_toggle, $my_limit;
    if ($is_admin) return true;
    if (!$has_approve_toggle) return false;
    if ($delta <= 0) return true;
    return $my_limit !== null && $new_total <= $my_limit;
}

/* ─── Apply an approved amendment ─────────────────────────────────────────── */
function _applyAmendment($db, $order, $amd, int $user_id): void {
    $delta = (float)$amd->delta_amount;
    $newv  = json_decode($amd->new_values ?? 'null', true) ?: [];
    $has_item_changes = !empty($newv['items']);

    // Item-level change (qty/price grid) — now available for BOTH modes
    // (8 Sep 2026). Updating the rows themselves is identical either way;
    // what differs is how the resulting total gets reconciled below.
    // Product/variant correction (12 Sep 2026): when a line's entry also
    // carries a product_id (only present for amendment_type
    // 'product_correction'), also rewrite WHICH product/variant the line
    // refers to — same plain UPDATE-by-row-id shape, no schema change
    // needed (credit_order_items.product_id has no unique constraint).
    if ($has_item_changes) {
        foreach ($newv['items'] as $it) {
            if (isset($it['product_id'])) {
                $db->query(
                    "UPDATE credit_order_items
                     SET product_id = ?, variant_id = ?, quantity = ?, unit_price = ?, line_total = ?
                     WHERE id = ? AND order_id = ?",
                    [(int)$it['product_id'], $it['variant_id'] !== null ? (int)$it['variant_id'] : null,
                     $it['qty'], $it['price'], $it['line_total'], (int)$it['item_id'], $order->id]
                );
            } else {
                $db->query(
                    "UPDATE credit_order_items
                     SET quantity = ?, unit_price = ?, line_total = ?
                     WHERE id = ? AND order_id = ?",
                    [$it['qty'], $it['price'], $it['line_total'], (int)$it['item_id'], $order->id]
                );
            }
        }
    }

    if ($amd->mode === 'pre') {
        // Invoice not posted yet — reshape the order itself.
        // Dispatch will later post the corrected total to the ledger.
        // Balance invariant: balance_due = total − advance_paid − amount_paid
        // (advance is NOT part of amount_paid — see create_order / advance page)
        if (isset($newv['new_subtotal'])) {
            $db->query(
                "UPDATE credit_orders
                 SET subtotal = ?, total_amount = ?,
                     balance_due = GREATEST(0, ? - advance_paid - amount_paid),
                     updated_at = NOW()
                 WHERE id = ?",
                [$newv['new_subtotal'], $newv['new_total'], $newv['new_total'], $order->id]
            );
        } else {
            // Flat delta (freight/rebate without item edits). MySQL evaluates SET
            // left-to-right, so balance_due sees the already-updated total_amount.
            $db->query(
                "UPDATE credit_orders
                 SET total_amount = total_amount + ?,
                     balance_due  = GREATEST(0, total_amount - advance_paid - amount_paid),
                     updated_at   = NOW()
                 WHERE id = ?",
                [$delta, $order->id]
            );
        }
    } else {
        // Post-dispatch — the invoice is posted. Adjust via ledger note,
        // exactly like returns (credit) / over-delivery billing (debit).
        // Ledger-safe regardless of whether $delta came from a flat entry
        // (freight/rebate/correction) or from real per-line qty/price edits
        // (items already updated above, 8 Sep 2026) — the ledger doesn't care
        // which; $delta already correctly reflects the net money effect either way.
        $prev = $db->query(
            "SELECT balance_after FROM customer_ledger WHERE customer_id = ? ORDER BY id DESC LIMIT 1",
            [$order->customer_id]
        )->first();
        $new_led_bal = ($prev ? (float)$prev->balance_after : 0) + $delta;

        $db->insert('customer_ledger', [
            'customer_id'        => $order->customer_id,
            'transaction_date'   => date('Y-m-d'),
            'transaction_type'   => $delta >= 0 ? 'debit_note' : 'credit_note',
            'reference_type'     => 'order_amendments',
            'reference_id'       => $amd->id,
            'invoice_number'     => $amd->amd_number,
            'description'        => "Amendment {$amd->amd_number} on {$order->order_number} — " . $amd->reason,
            'debit_amount'       => $delta >= 0 ? $delta : 0,
            'credit_amount'      => $delta < 0 ? abs($delta) : 0,
            'balance_after'      => $new_led_bal,
            'created_by_user_id' => $user_id,
        ]);
        $db->query(
            "UPDATE customers SET current_balance = GREATEST(0, current_balance + ?) WHERE id = ?",
            [$delta, $order->customer_id]
        );
        // Self-healing balance honoring the full invariant:
        // balance_due = total − advance_paid − amount_paid
        if (isset($newv['new_subtotal'])) {
            // Item-level change: subtotal is the (already-computed) absolute
            // new figure — sum of the now-updated line totals — mirroring
            // exactly how the 'pre' branch above handles its own subtotal.
            $db->query(
                "UPDATE credit_orders
                 SET subtotal = ?, total_amount = total_amount + ?,
                     balance_due = GREATEST(0, total_amount - advance_paid - amount_paid),
                     updated_at = NOW()
                 WHERE id = ?",
                [$newv['new_subtotal'], $delta, $order->id]
            );
        } else {
            // Flat delta only (no line-item change) — subtotal untouched, exactly as before.
            $db->query(
                "UPDATE credit_orders
                 SET total_amount = total_amount + ?,
                     balance_due  = GREATEST(0, total_amount - advance_paid - amount_paid),
                     updated_at   = NOW()
                 WHERE id = ?",
                [$delta, $order->id]
            );
        }

        if ($has_item_changes) {
            // Keep the frozen invoice snapshot (if the invoice has already
            // been printed/posted once) in sync so a reprint shows the
            // corrected items — invoice_date/order_date are never touched.
            refreshInvoiceSnapshotItems((int)$order->id);
        }
    }

    // Timeline / audit trail
    $db->insert('credit_order_workflow', [
        'order_id'             => $order->id,
        'from_status'          => $order->status,
        'to_status'            => $order->status,
        'action'               => 'amended',
        'performed_by_user_id' => $user_id,
        'comments'             => sprintf('Amendment %s APPROVED (%s): %s | %s৳%s → new total ৳%s',
            $amd->amd_number,
            $amd->amendment_type,
            $amd->reason,
            $delta >= 0 ? '+' : '−', number_format(abs($delta), 2),
            number_format((float)$order->total_amount + $delta, 2)),
    ]);
}

/* ─── Load order ──────────────────────────────────────────────────────────── */
$order_id = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$order    = null;
$items    = [];
if ($order_id) {
    $order = $db->query(
        "SELECT co.*, c.name AS customer_name, c.phone_number
         FROM credit_orders co JOIN customers c ON co.customer_id = c.id
         WHERE co.id = ?",
        [$order_id]
    )->first();
    if ($order) {
        $items = $db->query(
            "SELECT coi.*, p.base_name AS product_name, pv.grade, pv.weight_variant, pv.unit_of_measure
             FROM credit_order_items coi
             JOIN products p ON coi.product_id = p.id
             LEFT JOIN product_variants pv ON coi.variant_id = pv.id
             WHERE coi.order_id = ? ORDER BY coi.id",
            [$order_id]
        )->results();
    }
}

$amendable_pre  = $order && in_array($order->status, ['approved', 'in_production', 'produced', 'ready_to_ship']);
$amendable_post = $order && in_array($order->status, ['shipped', 'delivered']);
$mode           = $amendable_pre ? 'pre' : ($amendable_post ? 'post' : null);

// Product/variant catalog for the Product Correction picker — only loaded
// when the capability is actually usable, same list shape create_order.php
// uses (product_id/variant_id/grade/weight_variant/unit_of_measure).
$product_catalog = [];
if ($can_change_products && $order) {
    $product_catalog = $db->query(
        "SELECT p.id AS product_id, p.base_name, pv.id AS variant_id, pv.grade, pv.weight_variant, pv.unit_of_measure
         FROM products p
         LEFT JOIN product_variants pv ON pv.product_id = p.id AND pv.status = 'active'
         WHERE p.status = 'active'
         ORDER BY p.base_name ASC, pv.grade ASC, pv.weight_variant ASC"
    )->results();
}

/* ─── POST: create amendment ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_amendment') {
    if (!$can_request) {
        $error = 'You do not have permission to request amendments.';
    } elseif (!$order || !$mode) {
        $error = 'Order not found or not in an amendable state.';
    } else {
        try {
            $atype  = $_POST['amendment_type'] ?? '';
            if (!isset($type_labels[$atype])) throw new Exception('Select a valid amendment type.');
            if ($atype === 'product_correction' && !$can_change_products) {
                throw new Exception('You do not have permission to correct products on an order — ask Superadmin to enable and delegate this capability.');
            }
            $reason = trim($_POST['reason'] ?? '');
            if ($reason === '') throw new Exception('A reason is required for every amendment.');

            // 8 Sep 2026: customer-consent attestation, required for any
            // post-dispatch (already shipped/delivered) amendment — the
            // customer's invoice is already posted, so changing it needs a
            // recorded confirmation that they agreed, not just an internal reason.
            $consent_confirmed = !empty($_POST['consent_confirmed']);
            $consent_note      = trim($_POST['consent_note'] ?? '');
            if ($mode === 'post') {
                if (!$consent_confirmed) throw new Exception('Please confirm the customer has consented to this change before submitting.');
                if ($consent_note === '') throw new Exception("Please note how/when the customer's consent was obtained.");
            }

            $old_total = (float)$order->total_amount;
            $old_vals  = ['old_subtotal' => (float)$order->subtotal, 'old_total' => $old_total, 'items' => []];
            $new_vals  = [];
            $delta     = 0.0;

            $use_grid = in_array($mode, ['pre', 'post'], true) && in_array($atype, ['transport_change', 'price_revision', 'qty_change', 'correction', 'product_correction']);
            if ($use_grid) {
                // Per-item new qty / unit price grid (+ product/variant swap
                // when amendment_type === 'product_correction')
                $is_product_correction = $atype === 'product_correction';
                $any_product_changed = false;
                $new_items = [];
                $new_subtotal = 0.0;
                foreach ($items as $it) {
                    $q = (float)($_POST['new_qty'][$it->id]   ?? $it->quantity);
                    $p = (float)($_POST['new_price'][$it->id] ?? $it->unit_price);
                    if ($q < 0 || $p < 0) throw new Exception('Quantity and price cannot be negative.');

                    $old_item = ['item_id' => $it->id, 'qty' => (float)$it->quantity, 'price' => (float)$it->unit_price, 'line_total' => (float)$it->line_total];
                    $new_item = ['item_id' => $it->id, 'qty' => $q, 'price' => $p];

                    if ($is_product_correction) {
                        // Single composite dropdown per row, value "pid|vid" (vid blank
                        // when the product has no variant) — see the amd-product-pick
                        // <select> in the form below.
                        $posted_combo = trim((string)($_POST['new_product_variant'][$it->id] ?? ''));
                        if ($posted_combo === '' || strpos($posted_combo, '|') === false) {
                            throw new Exception('Select a product for every line when correcting products.');
                        }
                        [$posted_pid_raw, $posted_vid_raw] = explode('|', $posted_combo, 2);
                        $posted_pid = (int)$posted_pid_raw;
                        $posted_vid = $posted_vid_raw === '' ? null : (int)$posted_vid_raw;
                        if ($posted_pid <= 0) throw new Exception('Select a product for every line when correcting products.');
                        $prod_row = $db->query("SELECT id, base_name FROM products WHERE id = ?", [$posted_pid])->first();
                        if (!$prod_row) throw new Exception('Selected product not found.');
                        if ($posted_vid !== null) {
                            $var_row = $db->query("SELECT id FROM product_variants WHERE id = ? AND product_id = ?", [$posted_vid, $posted_pid])->first();
                            if (!$var_row) throw new Exception('Selected variant does not belong to the selected product.');
                        }
                        // Guard: don't let a product swap silently orphan a
                        // return/over-delivery/delivery record that already
                        // references this specific line by id (all three
                        // child tables have a hard order_item_id column).
                        if ((int)$posted_pid !== (int)$it->product_id || $posted_vid !== ($it->variant_id !== null ? (int)$it->variant_id : null)) {
                            $any_product_changed = true;
                            foreach (['credit_order_return_items', 'credit_order_over_delivery_items', 'credit_order_delivery_items'] as $child_table) {
                                $ref = $db->query("SELECT id FROM {$child_table} WHERE order_item_id = ? LIMIT 1", [$it->id])->first();
                                if ($ref) {
                                    throw new Exception("Line \"{$it->product_name}\" already has a return/delivery/over-delivery record against it — cannot change its product. Use a different line, or resolve that record first.");
                                }
                            }
                            $old_item['product_id']   = (int)$it->product_id;
                            $old_item['variant_id']   = $it->variant_id !== null ? (int)$it->variant_id : null;
                            $old_item['product_name'] = $it->product_name . (trim(($it->grade ?? '') . ' ' . ($it->weight_variant ?? '')) !== '' ? ' — ' . trim(($it->grade ?? '') . ' ' . ($it->weight_variant ?? '')) : '');
                            $new_item['product_id']   = $posted_pid;
                            $new_item['variant_id']   = $posted_vid;
                            $new_item['product_name'] = $prod_row->base_name;
                        }
                    }

                    $lt = round($q * $p, 2);
                    $new_item['line_total'] = $lt;
                    $old_vals['items'][] = $old_item;
                    $new_items[]         = $new_item;
                    $new_subtotal += $lt;
                }
                $new_total = $new_subtotal - (float)$order->discount_amount + (float)$order->tax_amount;
                // A same-price product swap is a real, meaningful correction even
                // though the invoice total doesn't move — only reject "no change"
                // when NEITHER the total NOR any line's product actually changed.
                if (abs($new_total - $old_total) < 0.01 && !$any_product_changed) {
                    throw new Exception('No change detected — totals are identical.');
                }
                $delta     = round($new_total - $old_total, 2);
                $new_vals  = ['new_subtotal' => round($new_subtotal, 2), 'new_total' => round($new_total, 2), 'items' => $new_items];
            } else {
                // Flat signed adjustment (freight / rebate / correction, and all post-dispatch)
                $delta_raw = trim($_POST['delta_amount'] ?? '');
                $direction = ($_POST['direction'] ?? 'add') === 'subtract' ? -1 : 1;
                $delta     = round(abs((float)$delta_raw) * $direction, 2);
                if (abs($delta) < 0.01) throw new Exception('Enter the adjustment amount.');
                if ($atype === 'rebate' && $delta > 0) $delta = -$delta;   // rebates always reduce
                $new_vals = ['new_total' => round($old_total + $delta, 2)];
            }
            $new_total_val = $new_vals['new_total'];

            // Number
            $ad = date('Ymd');
            $last = $db->query(
                "SELECT amd_number FROM order_amendments WHERE amd_number LIKE ? ORDER BY id DESC LIMIT 1",
                ["AMD-{$ad}-%"]
            )->first();
            $seqn = $last ? (int)substr($last->amd_number, -4) + 1 : 1;
            $amd_number = sprintf("AMD-%s-%04d", $ad, $seqn);

            $auto = amendmentAuthority($delta, $new_total_val);

            $pdo->beginTransaction();
            $amd_id = $db->insert('order_amendments', [
                'amd_number'           => $amd_number,
                'order_id'             => $order_id,
                'mode'                 => $mode,
                'amendment_type'       => $atype,
                'reason'               => $reason,
                'customer_consent_note' => $mode === 'post' ? $consent_note : null,
                'delta_amount'         => $delta,
                'old_values'           => json_encode($old_vals),
                'new_values'           => json_encode($new_vals),
                'status'               => $auto ? 'approved' : 'pending',
                'requested_by_user_id' => $user_id,
                'approved_by_user_id'  => $auto ? $user_id : null,
                'approved_at'          => $auto ? date('Y-m-d H:i:s') : null,
            ]);

            if (!$amd_id) {
                throw new Exception('Failed to record the amendment (database write did not return an id) — nothing was applied.');
            }

            if ($auto) {
                $amd = $db->query("SELECT * FROM order_amendments WHERE id = ?", [$amd_id])->first();
                if (!$amd) {
                    throw new Exception('Failed to reload the amendment after saving it — nothing was applied.');
                }
                _applyAmendment($db, $order, $amd, (int)$user_id);
            } else {
                $db->insert('credit_order_workflow', [
                    'order_id'             => $order_id,
                    'from_status'          => $order->status,
                    'to_status'            => $order->status,
                    'action'               => 'amendment_requested',
                    'performed_by_user_id' => $user_id,
                    'comments'             => sprintf('Amendment %s REQUESTED (%s): %s | %s৳%s — awaiting admin approval',
                        $amd_number, $atype, $reason,
                        $delta >= 0 ? '+' : '−', number_format(abs($delta), 2)),
                ]);
            }
            $pdo->commit();

            auditLog('order_amendments', $auto ? 'approved' : 'created',
                "Amendment {$amd_number} on {$order->order_number} — " . ($delta >= 0 ? '+' : '−') . '৳' . number_format(abs($delta), 2)
                . " ({$atype})" . ($auto ? ' [auto-approved]' : ' [pending]'),
                ['amendment_id' => $amd_id, 'order_id' => $order_id, 'delta' => $delta]);

            try {
                if (defined('TELEGRAM_BOT_TOKEN') && defined('TELEGRAM_CHAT_ID') && defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
                    require_once '../core/classes/TelegramNotifier.php';
                    $notifier = new TelegramNotifier(TELEGRAM_BOT_TOKEN, getTelegramChatId('orders'));
                    $msg = "<b>✏️ ORDER AMENDMENT " . ($auto ? "APPLIED" : "REQUESTED") . "</b>\n"
                         . "─────────────────────────\n\n"
                         . "<b>AMD #:</b> <code>{$amd_number}</code>\n"
                         . "<b>Order:</b> {$order->order_number}\n"
                         . "<b>Customer:</b> " . htmlspecialchars($order->customer_name) . "\n"
                         . "<b>Type:</b> " . $type_labels[$atype] . "\n"
                         . "<b>Change:</b> " . ($delta >= 0 ? '+' : '−') . "৳" . number_format(abs($delta), 2)
                         . " → new total ৳" . number_format($new_total_val, 2) . "\n"
                         . "<b>Reason:</b> " . htmlspecialchars($reason) . "\n"
                         . "<b>By:</b> " . ($currentUser['display_name'] ?? 'Unknown') . "\n\n"
                         . "<i>Ujjal Flour Mills ERP</i>";
                    $notifier->sendMessage($msg);
                }
            } catch (Exception $te) {}

            $success = "Amendment #{$amd_number} " . ($auto ? 'applied.' : 'submitted — awaiting admin approval.');
            // Refresh order + items after change
            $order = $db->query(
                "SELECT co.*, c.name AS customer_name, c.phone_number
                 FROM credit_orders co JOIN customers c ON co.customer_id = c.id WHERE co.id = ?",
                [$order_id])->first();
            $items = $db->query(
                "SELECT coi.*, p.base_name AS product_name, pv.grade, pv.weight_variant, pv.unit_of_measure
                 FROM credit_order_items coi JOIN products p ON coi.product_id = p.id
                 LEFT JOIN product_variants pv ON coi.variant_id = pv.id
                 WHERE coi.order_id = ? ORDER BY coi.id", [$order_id])->results();
            $amendable_pre  = $order && in_array($order->status, ['approved', 'in_production', 'produced', 'ready_to_ship']);
            $amendable_post = $order && in_array($order->status, ['shipped', 'delivered']);
            $mode           = $amendable_pre ? 'pre' : ($amendable_post ? 'post' : null);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

/* ─── POST: approve / reject pending amendment ────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['approve_amd', 'reject_amd'])) {
    try {
        $amd_id = (int)$_POST['amd_id'];
        $amd = $db->query("SELECT * FROM order_amendments WHERE id = ? AND status = 'pending'", [$amd_id])->first();
        if (!$amd) throw new Exception('Amendment not found or already processed.');
        $amd_order = $db->query(
            "SELECT co.*, c.name AS customer_name FROM credit_orders co
             JOIN customers c ON co.customer_id = c.id WHERE co.id = ?", [$amd->order_id])->first();
        if (!$amd_order) throw new Exception('Order not found.');

        $newv = json_decode($amd->new_values ?? 'null', true) ?: [];
        if (($_POST['action'] === 'approve_amd')) {
            if (!amendmentAuthority((float)$amd->delta_amount, (float)($newv['new_total'] ?? 0))) {
                throw new Exception('You do not have authority to approve this amendment.');
            }
            // Pre-dispatch amendments must still be pre-dispatch at approval time
            if ($amd->mode === 'pre' && !in_array($amd_order->status, ['approved', 'in_production', 'produced', 'ready_to_ship'])) {
                throw new Exception('Order has since been dispatched — reject this and raise a post-dispatch amendment instead.');
            }
            $pdo->beginTransaction();
            $db->query("UPDATE order_amendments SET status='approved', approved_by_user_id=?, approved_at=NOW() WHERE id=?",
                       [$user_id, $amd_id]);
            _applyAmendment($db, $amd_order, $amd, (int)$user_id);
            $pdo->commit();
            auditLog('order_amendments', 'approved', "Amendment {$amd->amd_number} approved", ['amendment_id' => $amd_id]);
            $success = "Amendment #{$amd->amd_number} approved and applied.";
        } else {
            if (!$is_admin && !$has_approve_toggle) throw new Exception('Permission denied.');
            $db->query("UPDATE order_amendments SET status='rejected', approved_by_user_id=?, approved_at=NOW() WHERE id=?",
                       [$user_id, $amd_id]);
            auditLog('order_amendments', 'rejected', "Amendment {$amd->amd_number} rejected", ['amendment_id' => $amd_id]);
            $success = "Amendment #{$amd->amd_number} rejected.";
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

/* ─── Amendment history for this order ────────────────────────────────────── */
$amendments = [];
if ($order_id) {
    $amendments = $db->query(
        "SELECT a.*, ru.display_name AS requested_by_name, au.display_name AS approved_by_name
         FROM order_amendments a
         LEFT JOIN users ru ON a.requested_by_user_id = ru.id
         LEFT JOIN users au ON a.approved_by_user_id  = au.id
         WHERE a.order_id = ?
         ORDER BY a.created_at DESC",
        [$order_id]
    )->results();
}

require_once '../templates/header.php';
$st_meta = ['pending' => 'bg-yellow-100 text-yellow-800', 'approved' => 'bg-green-100 text-green-800', 'rejected' => 'bg-red-100 text-red-800'];
?>

<div class="max-w-screen-lg mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold text-gray-900"><i class="fas fa-file-signature mr-2 text-cyan-600"></i><?php echo $pageTitle; ?></h1>
        <p class="text-sm text-gray-500 mt-1">Adjust an order's transport, pricing, quantity or charges — with approval and full audit trail</p>
    </div>
    <?php if ($order): ?>
    <a href="credit_order_view.php?id=<?php echo $order->id; ?>" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
        <i class="fas fa-arrow-left mr-1"></i><?php echo htmlspecialchars($order->order_number); ?>
    </a>
    <?php endif; ?>
</div>

<?php if ($error): ?>
<div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-5 rounded-r-lg"><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 mb-5 rounded-r-lg"><i class="fas fa-check-circle mr-1"></i><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<?php if (!$order): ?>
<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-10 text-center text-gray-400">
    <i class="fas fa-file-signature text-4xl mb-3"></i>
    <p class="text-sm">Open this page from an order (Order View → Amend Order).</p>
</div>
<?php else: ?>

<!-- Order summary strip -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm px-5 py-4 mb-5 flex flex-wrap items-center gap-x-8 gap-y-2 text-sm">
    <div><span class="text-gray-400 text-xs block">Order</span><strong class="font-mono text-blue-700"><?php echo htmlspecialchars($order->order_number); ?></strong></div>
    <div><span class="text-gray-400 text-xs block">Customer</span><strong><?php echo htmlspecialchars($order->customer_name); ?></strong></div>
    <div><span class="text-gray-400 text-xs block">Status</span>
        <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-800"><?php echo ucwords(str_replace('_', ' ', $order->status)); ?></span>
    </div>
    <div><span class="text-gray-400 text-xs block">Current Total</span><strong>৳<?php echo number_format($order->total_amount, 2); ?></strong></div>
    <div><span class="text-gray-400 text-xs block">Balance Due</span><strong class="text-red-600">৳<?php echo number_format($order->balance_due, 2); ?></strong></div>
    <div class="ml-auto">
        <?php if ($mode === 'pre'): ?>
        <span class="px-3 py-1 rounded-full text-xs font-bold bg-cyan-100 text-cyan-800"><i class="fas fa-pen mr-1"></i>PRE-DISPATCH — order will be edited directly</span>
        <?php elseif ($mode === 'post'): ?>
        <span class="px-3 py-1 rounded-full text-xs font-bold bg-purple-100 text-purple-800"><i class="fas fa-file-invoice mr-1"></i>POST-DISPATCH — adjusts via debit/credit note</span>
        <?php else: ?>
        <span class="px-3 py-1 rounded-full text-xs font-bold bg-gray-200 text-gray-600">Not amendable in status "<?php echo $order->status; ?>"</span>
        <?php endif; ?>
    </div>
</div>

<?php if ($mode && $can_request): ?>
<!-- ═══ New Amendment ═══ -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 mb-6">
    <h2 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-plus-circle mr-2 text-cyan-600"></i>New Amendment</h2>

    <?php if ($mode === 'post'): ?>
    <div class="mb-4 p-3 bg-purple-50 border border-purple-200 rounded-lg text-sm text-purple-900">
        <i class="fas fa-info-circle mr-1"></i>
        <strong>This order is already dispatched</strong> — its invoice is already posted to the customer
        ledger. You can still correct a specific line's quantity/price below (Transport Change, Price
        Revision, Quantity Change, Entry Correction<?php echo $can_change_products ? ', Product/Quantity Correction' : ''; ?>), or enter a flat ৳ amount (Freight, Rebate). Either
        way it posts as a debit note (+) or credit note (−) to the ledger, the corrected line items are
        saved and reflected on the invoice, and — per the ledger integrity rule — the order's original
        placing/invoice date never changes. <strong>Customer consent is required</strong> below.
    </div>
    <?php endif; ?>

    <form method="POST" id="amdForm">
        <input type="hidden" name="action" value="create_amendment">
        <input type="hidden" name="order_id" value="<?php echo $order->id; ?>">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Amendment Type *</label>
                <select name="amendment_type" id="amdType" class="w-full px-3 py-2 border rounded-lg text-sm" onchange="amdModeSwitch()">
                    <?php foreach ($type_labels as $tv => $tl): ?>
                    <option value="<?php echo $tv; ?>"><?php echo $tl; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Reason * (goes to ledger &amp; timeline)</label>
                <input type="text" name="reason" required maxlength="500" class="w-full px-3 py-2 border rounded-lg text-sm"
                       placeholder="e.g. Shipped via 3 mini trucks instead of big truck — rate +৳50/bag">
            </div>
        </div>

        <?php $grid_allowed = in_array($mode, ['pre', 'post'], true); ?>
        <!-- Item grid (transport/price/qty types — pre AND post-dispatch) -->
        <?php if ($grid_allowed): ?>
        <div id="amdGrid" class="mb-4">
            <p class="text-xs text-gray-500 mb-2">Adjust quantity and/or unit price per line — totals recalculate live.</p>
            <table class="min-w-full text-sm border border-gray-200 rounded-lg overflow-hidden">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-3 py-2 text-left">Product</th>
                        <?php if ($can_change_products): ?>
                        <th class="px-3 py-2 text-left amd-product-col" style="display:none;">New Product</th>
                        <?php endif; ?>
                        <th class="px-3 py-2 text-right">Current Qty</th>
                        <th class="px-3 py-2 text-right">Current Price</th>
                        <th class="px-3 py-2 text-right w-28">New Qty</th>
                        <th class="px-3 py-2 text-right w-32">New Unit Price</th>
                        <th class="px-3 py-2 text-right">New Line Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($items as $it):
                        $variant = trim(($it->grade ?? '') . ' ' . ($it->weight_variant ?? '')); ?>
                    <tr>
                        <td class="px-3 py-2">
                            <div class="font-medium text-gray-900"><?php echo htmlspecialchars($it->product_name); ?></div>
                            <?php if ($variant): ?><div class="text-xs text-gray-400"><?php echo htmlspecialchars($variant); ?></div><?php endif; ?>
                        </td>
                        <?php if ($can_change_products):
                            $current_combo = (int)$it->product_id . '|' . ($it->variant_id !== null ? (int)$it->variant_id : ''); ?>
                        <td class="px-3 py-2 amd-product-col" style="display:none;">
                            <select name="new_product_variant[<?php echo $it->id; ?>]" class="amd-product-pick w-48 px-2 py-1 border rounded text-sm">
                                <?php $cur_product_id = null; foreach ($product_catalog as $pc):
                                    if ($cur_product_id !== (int)$pc->product_id) {
                                        if ($cur_product_id !== null) echo '</optgroup>';
                                        echo '<optgroup label="' . htmlspecialchars($pc->base_name) . '">';
                                        $cur_product_id = (int)$pc->product_id;
                                    }
                                    $combo = (int)$pc->product_id . '|' . ($pc->variant_id !== null ? (int)$pc->variant_id : '');
                                    $label = $pc->variant_id !== null ? trim(($pc->grade ?? '') . ' ' . ($pc->weight_variant ?? '')) : $pc->base_name;
                                ?>
                                <option value="<?php echo htmlspecialchars($combo); ?>" <?php echo $combo === $current_combo ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; if ($cur_product_id !== null) echo '</optgroup>'; ?>
                            </select>
                        </td>
                        <?php endif; ?>
                        <td class="px-3 py-2 text-right text-gray-500"><?php echo number_format($it->quantity, 0); ?></td>
                        <td class="px-3 py-2 text-right text-gray-500">৳<?php echo number_format($it->unit_price, 2); ?></td>
                        <td class="px-3 py-2 text-right">
                            <input type="number" name="new_qty[<?php echo $it->id; ?>]" value="<?php echo (float)$it->quantity; ?>"
                                   min="0" step="0.01" class="amd-q w-24 px-2 py-1 border rounded text-right text-sm" data-id="<?php echo $it->id; ?>">
                        </td>
                        <td class="px-3 py-2 text-right">
                            <input type="number" name="new_price[<?php echo $it->id; ?>]" value="<?php echo (float)$it->unit_price; ?>"
                                   min="0" step="0.01" class="amd-p w-28 px-2 py-1 border rounded text-right text-sm" data-id="<?php echo $it->id; ?>">
                        </td>
                        <td class="px-3 py-2 text-right font-semibold text-gray-800 amd-lt" data-id="<?php echo $it->id; ?>">
                            ৳<?php echo number_format($it->line_total, 2); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-gray-50 border-t-2 border-gray-300 text-sm">
                    <tr>
                        <td colspan="<?php echo $can_change_products ? 6 : 5; ?>" class="px-3 py-2 text-right font-bold text-gray-700">
                            New Total (after discount ৳<?php echo number_format($order->discount_amount, 0); ?>)
                        </td>
                        <td class="px-3 py-2 text-right font-bold text-cyan-700" id="amdNewTotal">৳<?php echo number_format($order->total_amount, 2); ?></td>
                    </tr>
                    <tr>
                        <td colspan="<?php echo $can_change_products ? 6 : 5; ?>" class="px-3 py-2 text-right font-bold text-gray-700">Change vs current</td>
                        <td class="px-3 py-2 text-right font-bold" id="amdDelta">৳0.00</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>

        <!-- Flat adjustment (freight/rebate/correction, and everything post-dispatch) -->
        <div id="amdFlat" class="mb-4 <?php echo $grid_allowed ? 'hidden' : ''; ?>">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Direction</label>
                    <select name="direction" class="px-3 py-2 border rounded-lg text-sm">
                        <option value="add">Add to invoice (+)</option>
                        <option value="subtract">Reduce invoice (−)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Amount (৳) *</label>
                    <input type="number" name="delta_amount" min="0" step="0.01" class="px-3 py-2 border rounded-lg text-sm w-40" placeholder="0.00">
                </div>
                <p class="text-xs text-gray-400 pb-2">
                    <?php echo $mode === 'post'
                        ? 'Posts a debit note (+) or credit note (−) to the customer ledger.'
                        : 'Applied straight to the order total (invoice not posted yet). Rebates always reduce.'; ?>
                </p>
            </div>
        </div>

        <?php if ($mode === 'post'): ?>
        <div class="mb-4 p-3 bg-amber-50 border border-amber-200 rounded-lg">
            <label class="flex items-start gap-2 cursor-pointer mb-2">
                <input type="checkbox" name="consent_confirmed" value="1" required
                       class="mt-0.5 w-4 h-4 accent-amber-600">
                <span class="text-sm font-medium text-amber-900">
                    Customer has consented to this change *
                </span>
            </label>
            <label class="block text-xs font-medium text-gray-600 mb-1">How / when was consent obtained? *</label>
            <input type="text" name="consent_note" required maxlength="255" class="w-full px-3 py-2 border rounded-lg text-sm"
                   placeholder="e.g. Phone call with customer on 8 Sep 2026, agreed to revised quantity">
        </div>
        <?php endif; ?>

        <div class="flex items-center gap-3">
            <button type="submit" onclick="return confirm('Submit this amendment?');"
                    class="px-6 py-2.5 bg-cyan-600 text-white rounded-lg font-semibold hover:bg-cyan-700 cursor-pointer">
                <i class="fas fa-file-signature mr-2"></i>Submit Amendment
            </button>
            <p class="text-xs text-gray-400">
                <?php if ($is_admin): ?>Applied immediately (admin).
                <?php elseif ($has_approve_toggle && $my_limit !== null): ?>Auto-applies for decreases and increases up to your limit (৳<?php echo number_format($my_limit, 0); ?>); larger increases go to admin.
                <?php elseif ($has_approve_toggle): ?>Auto-applies for decreases; increases go to admin.
                <?php else: ?>All amendments go to admin for approval.<?php endif; ?>
            </p>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- ═══ Amendment history ═══ -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100"><h2 class="text-sm font-bold text-gray-800"><i class="fas fa-list mr-2 text-gray-400"></i>Amendment History</h2></div>
    <?php if (empty($amendments)): ?>
    <div class="p-8 text-center text-sm text-gray-400">No amendments recorded for this order.</div>
    <?php else: ?>
    <table class="min-w-full text-sm divide-y divide-gray-200">
        <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
            <tr>
                <th class="px-4 py-2 text-left">AMD #</th>
                <th class="px-4 py-2 text-left">Type</th>
                <th class="px-4 py-2 text-left">Reason</th>
                <th class="px-4 py-2 text-right">Change</th>
                <th class="px-4 py-2 text-center">Mode</th>
                <th class="px-4 py-2 text-center">Status</th>
                <th class="px-4 py-2 text-left">By</th>
                <th class="px-4 py-2 text-center">Action</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
        <?php foreach ($amendments as $a): $d = (float)$a->delta_amount; ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-2.5 font-mono font-semibold text-cyan-700"><?php echo htmlspecialchars($a->amd_number); ?></td>
                <td class="px-4 py-2.5 text-xs text-gray-600"><?php echo str_replace('_', ' ', $a->amendment_type); ?></td>
                <td class="px-4 py-2.5 text-xs text-gray-600 max-w-[220px]">
                    <?php echo htmlspecialchars($a->reason); ?>
                    <?php
                        // Product-correction detail: pull item-level product changes
                        // out of new_values (only present when this amendment actually
                        // swapped a product/variant) and show "Old → New" per line.
                        $a_newv = json_decode($a->new_values ?? 'null', true) ?: [];
                        $a_oldv = json_decode($a->old_values ?? 'null', true) ?: [];
                        $a_old_items_by_id = [];
                        foreach (($a_oldv['items'] ?? []) as $oi) { $a_old_items_by_id[$oi['item_id']] = $oi; }
                        $product_changes = [];
                        foreach (($a_newv['items'] ?? []) as $ni) {
                            if (isset($ni['product_name']) && isset($a_old_items_by_id[$ni['item_id']]['product_name'])) {
                                $product_changes[] = $a_old_items_by_id[$ni['item_id']]['product_name'] . ' → ' . $ni['product_name'];
                            }
                        }
                    ?>
                    <?php foreach ($product_changes as $pc): ?>
                    <div class="text-[10px] text-cyan-700 mt-0.5"><i class="fas fa-right-left mr-0.5"></i><?php echo htmlspecialchars($pc); ?></div>
                    <?php endforeach; ?>
                    <?php if (!empty($a->customer_consent_note)): ?>
                    <div class="text-[10px] text-amber-700 mt-0.5"><i class="fas fa-user-check mr-0.5"></i>Consent: <?php echo htmlspecialchars($a->customer_consent_note); ?></div>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-2.5 text-right font-bold <?php echo $d >= 0 ? 'text-red-600' : 'text-green-700'; ?>">
                    <?php echo ($d >= 0 ? '+' : '−') . '৳' . number_format(abs($d), 2); ?>
                </td>
                <td class="px-4 py-2.5 text-center text-[10px] font-bold text-gray-400 uppercase"><?php echo $a->mode; ?></td>
                <td class="px-4 py-2.5 text-center">
                    <span class="px-2 py-0.5 rounded text-xs font-semibold <?php echo $st_meta[$a->status] ?? ''; ?>"><?php echo ucfirst($a->status); ?></span>
                </td>
                <td class="px-4 py-2.5 text-xs text-gray-500">
                    <?php echo htmlspecialchars($a->requested_by_name ?? '—'); ?>
                    <?php if ($a->status !== 'pending' && $a->approved_by_name): ?>
                    <div class="text-[10px] text-gray-400"><?php echo $a->status === 'approved' ? '✓' : '✗'; ?> <?php echo htmlspecialchars($a->approved_by_name); ?></div>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-2.5 text-center whitespace-nowrap">
                    <?php if ($a->status === 'pending' && ($is_admin || $has_approve_toggle)): ?>
                    <form method="POST" class="inline"><input type="hidden" name="amd_id" value="<?php echo $a->id; ?>"><input type="hidden" name="order_id" value="<?php echo $order->id; ?>">
                        <button type="submit" name="action" value="approve_amd"
                                onclick="return confirm('Approve amendment <?php echo addslashes($a->amd_number); ?>?')"
                                class="px-2.5 py-1 bg-green-600 text-white text-xs rounded hover:bg-green-700 cursor-pointer">Approve</button>
                    </form>
                    <form method="POST" class="inline"><input type="hidden" name="amd_id" value="<?php echo $a->id; ?>"><input type="hidden" name="order_id" value="<?php echo $order->id; ?>">
                        <button type="submit" name="action" value="reject_amd"
                                onclick="return confirm('Reject <?php echo addslashes($a->amd_number); ?>?')"
                                class="px-2.5 py-1 bg-red-600 text-white text-xs rounded hover:bg-red-700 cursor-pointer">Reject</button>
                    </form>
                    <?php else: ?><span class="text-gray-300">—</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php endif; ?>
</div>

<script>
const AMD_DISCOUNT = <?php echo (float)($order->discount_amount ?? 0); ?>;
const AMD_TAX      = <?php echo (float)($order->tax_amount ?? 0); ?>;
const AMD_OLDTOTAL = <?php echo (float)($order->total_amount ?? 0); ?>;
const AMD_GRID_TYPES = ['transport_change', 'price_revision', 'qty_change', 'correction', 'product_correction'];
const AMD_HAS_GRID = <?php echo in_array($mode ?? '', ['pre', 'post'], true) ? 'true' : 'false'; ?>;

function amdModeSwitch() {
    if (!AMD_HAS_GRID) return;
    const t    = document.getElementById('amdType').value;
    const grid = document.getElementById('amdGrid');
    const flat = document.getElementById('amdFlat');
    const useGrid = AMD_GRID_TYPES.includes(t);
    if (grid) grid.classList.toggle('hidden', !useGrid);
    if (flat) flat.classList.toggle('hidden', useGrid);
    const showProductCol = t === 'product_correction';
    document.querySelectorAll('.amd-product-col').forEach(el => { el.style.display = showProductCol ? '' : 'none'; });
}

function amdRecalc() {
    let subtotal = 0;
    document.querySelectorAll('.amd-q').forEach(q => {
        const id = q.dataset.id;
        const p  = document.querySelector('.amd-p[data-id="' + id + '"]');
        const lt = (parseFloat(q.value) || 0) * (parseFloat(p ? p.value : 0) || 0);
        subtotal += lt;
        const cell = document.querySelector('.amd-lt[data-id="' + id + '"]');
        if (cell) cell.textContent = '৳' + lt.toLocaleString(undefined, {minimumFractionDigits: 2});
    });
    const newTotal = subtotal - AMD_DISCOUNT + AMD_TAX;
    const delta    = newTotal - AMD_OLDTOTAL;
    const tEl = document.getElementById('amdNewTotal');
    const dEl = document.getElementById('amdDelta');
    if (tEl) tEl.textContent = '৳' + newTotal.toLocaleString(undefined, {minimumFractionDigits: 2});
    if (dEl) {
        dEl.textContent = (delta >= 0 ? '+৳' : '−৳') + Math.abs(delta).toLocaleString(undefined, {minimumFractionDigits: 2});
        dEl.className = 'px-3 py-2 text-right font-bold ' + (Math.abs(delta) < 0.005 ? 'text-gray-400' : (delta > 0 ? 'text-red-600' : 'text-green-700'));
    }
}
document.querySelectorAll('.amd-q, .amd-p').forEach(i => i.addEventListener('input', amdRecalc));
amdModeSwitch();
</script>

<?php require_once '../templates/footer.php'; ?>
