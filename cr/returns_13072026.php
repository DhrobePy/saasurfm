<?php
require_once '../core/init.php';

restrict_access();

global $db;
$currentUser = getCurrentUser();
$user_id     = $currentUser['id']   ?? null;
$user_role   = $currentUser['role'] ?? '';
$pageTitle   = 'Goods Returns';

$is_admin    = userCanPageAction('credit_sales', 'returns', 'can_approve');
$is_accounts = userCanPageAction('credit_sales', 'customer_payment', 'can_collect');

/* ─── Self-migrating schema ───────────────────────────────────────────────── */
$pdo = $db->getPdo();

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `credit_order_returns` (
      `id`                     bigint UNSIGNED NOT NULL AUTO_INCREMENT,
      `return_number`          varchar(50)  NOT NULL,
      `order_id`               bigint UNSIGNED NOT NULL,
      `customer_id`            bigint UNSIGNED NOT NULL,
      `return_date`            date NOT NULL,
      `return_type`            enum('full','partial') NOT NULL DEFAULT 'partial',
      `return_reason`          text DEFAULT NULL,
      `total_returned_amount`  decimal(12,2) NOT NULL DEFAULT 0.00,
      `total_returned_qty`     decimal(10,2) NOT NULL DEFAULT 0.00,
      `has_compensation`       tinyint(1)   NOT NULL DEFAULT 0,
      `status`                 enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
      `created_by_user_id`     bigint UNSIGNED NOT NULL,
      `approved_by_user_id`    bigint UNSIGNED DEFAULT NULL,
      `approved_at`            timestamp NULL DEFAULT NULL,
      `notes`                  text DEFAULT NULL,
      `created_at`             timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_return_number` (`return_number`),
      KEY `idx_order_id` (`order_id`),
      KEY `idx_customer_id` (`customer_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `credit_order_return_items` (
      `id`                  bigint UNSIGNED NOT NULL AUTO_INCREMENT,
      `return_id`           bigint UNSIGNED NOT NULL,
      `order_item_id`       bigint UNSIGNED NOT NULL,
      `product_id`          bigint UNSIGNED NOT NULL,
      `variant_id`          bigint UNSIGNED DEFAULT NULL,
      `original_qty`        decimal(10,2) NOT NULL DEFAULT 0.00,
      `returned_qty`        decimal(10,2) NOT NULL,
      `unit_price`          decimal(12,2) NOT NULL,
      `compensation_price`  decimal(12,2) DEFAULT NULL,
      `price_type`          enum('invoice','compensated') NOT NULL DEFAULT 'invoice',
      `returned_amount`     decimal(12,2) NOT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_return_id` (`return_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Migrate existing tables in case columns are missing
foreach ([
    "ALTER TABLE credit_order_returns     ADD COLUMN IF NOT EXISTS has_compensation tinyint(1) NOT NULL DEFAULT 0 AFTER total_returned_qty",
    "ALTER TABLE credit_order_return_items ADD COLUMN IF NOT EXISTS compensation_price decimal(12,2) DEFAULT NULL AFTER unit_price",
    "ALTER TABLE credit_order_return_items ADD COLUMN IF NOT EXISTS price_type enum('invoice','compensated') NOT NULL DEFAULT 'invoice' AFTER compensation_price",
] as $sql) {
    try { $pdo->exec($sql); } catch (Exception $e) {}
}

$error   = null;
$success = null;

/* ─── Load order for new return ────────────────────────────────────────────── */
$selected_order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$selected_order    = null;
$order_items       = [];

if ($selected_order_id) {
    $selected_order = $db->query(
        "SELECT co.*, c.name AS customer_name, c.phone_number, c.current_balance, c.initial_due
         FROM credit_orders co
         JOIN customers c ON co.customer_id = c.id
         WHERE co.id = ? AND co.status IN ('shipped','delivered')",
        [$selected_order_id]
    )->first();

    if ($selected_order) {
        $order_items = $db->query(
            "SELECT coi.*, p.base_name AS product_name,
                    pv.grade, pv.weight_variant, pv.unit_of_measure
             FROM credit_order_items coi
             JOIN products p ON coi.product_id = p.id
             LEFT JOIN product_variants pv ON coi.variant_id = pv.id
             WHERE coi.order_id = ?
             ORDER BY coi.id",
            [$selected_order_id]
        )->results();

        foreach ($order_items as $item) {
            $already = $db->query(
                "SELECT COALESCE(SUM(ri.returned_qty),0) AS qty
                 FROM credit_order_return_items ri
                 JOIN credit_order_returns r ON r.id = ri.return_id
                 WHERE ri.order_item_id = ? AND r.status != 'rejected'",
                [$item->id]
            )->first();
            $item->already_returned = (float)($already->qty ?? 0);
            $item->returnable_qty   = max(0, (float)$item->quantity - $item->already_returned);
        }
    }
}

/* ─── POST: Create new return ───────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_return') {
    try {
        $order_id    = (int)$_POST['order_id'];
        $return_date = $_POST['return_date'];
        $reason      = trim($_POST['return_reason'] ?? '');
        $notes       = trim($_POST['notes'] ?? '');
        $qtys        = $_POST['return_qty']    ?? [];
        $comp_prices = $_POST['comp_price']    ?? [];

        $order = $db->query(
            "SELECT co.*, c.current_balance, c.initial_due
             FROM credit_orders co JOIN customers c ON co.customer_id = c.id
             WHERE co.id = ? AND co.status IN ('shipped','delivered')",
            [$order_id]
        )->first();
        if (!$order) throw new Exception("Order not found or not eligible for returns.");

        $lines                 = [];
        $total_returned_amount = 0;
        $total_returned_qty    = 0;
        $has_compensation      = false;

        foreach ($qtys as $item_id => $qty) {
            $qty = (float)$qty;
            if ($qty <= 0) continue;

            $item = $db->query(
                "SELECT coi.*, p.base_name AS product_name
                 FROM credit_order_items coi JOIN products p ON coi.product_id = p.id
                 WHERE coi.id = ? AND coi.order_id = ?",
                [(int)$item_id, $order_id]
            )->first();
            if (!$item) continue;

            $already = (float)$db->query(
                "SELECT COALESCE(SUM(ri.returned_qty),0) AS qty
                 FROM credit_order_return_items ri
                 JOIN credit_order_returns r ON r.id = ri.return_id
                 WHERE ri.order_item_id = ? AND r.status != 'rejected'",
                [(int)$item_id]
            )->first()->qty;

            $returnable = (float)$item->quantity - $already;
            if ($qty > $returnable) {
                throw new Exception("Return qty ({$qty}) for '{$item->product_name}' exceeds returnable qty ({$returnable}).");
            }

            $invoice_price = (float)$item->unit_price;

            // Compensation price: admin can override; non-admins always get invoice price
            $raw_comp = isset($comp_prices[$item_id]) ? (float)$comp_prices[$item_id] : 0;
            if (!$is_admin || $raw_comp <= 0) {
                $eff_price  = $invoice_price;
                $comp_store = null;
                $price_type = 'invoice';
            } else {
                $diff = abs($raw_comp - $invoice_price);
                if ($diff < 0.001) {
                    $eff_price  = $invoice_price;
                    $comp_store = null;
                    $price_type = 'invoice';
                } else {
                    $eff_price  = $raw_comp;
                    $comp_store = $raw_comp;
                    $price_type = 'compensated';
                    $has_compensation = true;
                }
            }

            $line_amount = round($qty * $eff_price, 2);

            $lines[] = [
                'order_item_id'      => (int)$item_id,
                'product_id'         => $item->product_id,
                'variant_id'         => $item->variant_id,
                'original_qty'       => (float)$item->quantity,
                'returned_qty'       => $qty,
                'unit_price'         => $invoice_price,
                'compensation_price' => $comp_store,
                'price_type'         => $price_type,
                'returned_amount'    => $line_amount,
            ];
            $total_returned_amount += $line_amount;
            $total_returned_qty    += $qty;
        }

        if (empty($lines)) throw new Exception("No return quantities entered.");

        $total_order_qty = (float)$db->query(
            "SELECT SUM(quantity) AS total FROM credit_order_items WHERE order_id = ?", [$order_id]
        )->first()->total;
        $return_type = $total_returned_qty >= $total_order_qty ? 'full' : 'partial';

        // Generate return number
        $rn_date = date('Ymd', strtotime($return_date));
        $last    = $db->query(
            "SELECT return_number FROM credit_order_returns WHERE return_number LIKE ? ORDER BY id DESC LIMIT 1",
            ["RET-{$rn_date}-%"]
        )->first();
        $seq    = $last ? (int)substr($last->return_number, -4) + 1 : 1;
        $return_number = sprintf("RET-%s-%04d", $rn_date, $seq);

        $pdo->beginTransaction();

        // Feature #7: separation of duties — the creator can NEVER approve their own
        // return, even with approve rights. Every return is created 'pending' and must
        // be approved by a DIFFERENT user who holds can_approve.
        $auto_approve = false;

        $return_id = $db->insert('credit_order_returns', [
            'return_number'         => $return_number,
            'order_id'              => $order_id,
            'customer_id'           => $order->customer_id,
            'return_date'           => $return_date,
            'return_type'           => $return_type,
            'return_reason'         => $reason,
            'total_returned_amount' => $total_returned_amount,
            'total_returned_qty'    => $total_returned_qty,
            'has_compensation'      => $has_compensation ? 1 : 0,
            'status'                => $auto_approve ? 'approved' : 'pending',
            'created_by_user_id'    => $user_id,
            'approved_by_user_id'   => $auto_approve ? $user_id : null,
            'approved_at'           => $auto_approve ? date('Y-m-d H:i:s') : null,
            'notes'                 => $notes,
        ]);

        foreach ($lines as $l) {
            $db->insert('credit_order_return_items', array_merge(['return_id' => $return_id], $l));
        }

        if ($auto_approve) {
            _applyReturnFinancials($db, $order, $return_id, $return_number, $return_type, $total_returned_amount, $user_id);
        }

        $pdo->commit();

        auditLog('credit_order_returns', 'created', "Return {$return_number} created for order {$order->order_number} — ৳" . number_format($total_returned_amount, 2) . ($has_compensation ? ' [compensated pricing]' : ''), [
            'return_id'       => $return_id,
            'order_id'        => $order_id,
            'return_type'     => $return_type,
            'returned_amount' => $total_returned_amount,
            'has_compensation'=> $has_compensation,
        ]);

        try {
            if (defined('TELEGRAM_BOT_TOKEN') && defined('TELEGRAM_CHAT_ID') && defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
                require_once '../core/classes/TelegramNotifier.php';
                $notifier  = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);
                $autoNote  = $auto_approve ? ' ✅ Auto-approved' : ' ⏳ Pending approval';
                $compNote  = $has_compensation ? "\n<b>Pricing:</b> Compensated (custom prices)" : '';
                $msg = "<b>🔄 GOODS RETURN RECORDED</b>\n"
                     . "─────────────────────────\n\n"
                     . "<b>Return #:</b> <code>{$return_number}</code>{$autoNote}\n"
                     . "<b>Order:</b> {$order->order_number}\n"
                     . "<b>Type:</b> " . ucfirst($return_type) . " return\n"
                     . "<b>Amount:</b> ৳" . number_format($total_returned_amount, 2) . $compNote . "\n"
                     . "<b>Reason:</b> " . htmlspecialchars($reason ?: 'Not specified') . "\n"
                     . "<b>By:</b> " . ($currentUser['display_name'] ?? 'Unknown') . "\n\n"
                     . "<i>Ujjal Flour Mills ERP</i>";
                $notifier->sendMessage($msg);
            }
        } catch (Exception $te) {}

        $success = "Return #{$return_number} recorded — awaiting approval by another authorised user (you cannot approve your own).";
        $selected_order_id = 0;
        $selected_order    = null;
        $order_items       = [];

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

/* ─── POST: Approve / Reject ────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['approve_return','reject_return'])) {
    if (!$is_admin) {
        $error = "Permission denied.";
    } else {
        try {
            $ret_id = (int)$_POST['return_id'];
            $action = $_POST['action'];
            $ret    = $db->query("SELECT * FROM credit_order_returns WHERE id = ? AND status = 'pending'", [$ret_id])->first();
            if (!$ret) throw new Exception("Return not found or already processed.");

            // Feature #7: no self-approval — the creator cannot approve/reject their own.
            if ((int)$ret->created_by_user_id === (int)$user_id) {
                throw new Exception("You created this return — a different authorised user must approve or reject it.");
            }

            if ($action === 'approve_return') {
                $order = $db->query(
                    "SELECT co.*, c.current_balance FROM credit_orders co JOIN customers c ON co.customer_id = c.id WHERE co.id = ?",
                    [$ret->order_id]
                )->first();

                $pdo->beginTransaction();
                _applyReturnFinancials($db, $order, $ret_id, $ret->return_number, $ret->return_type, (float)$ret->total_returned_amount, $user_id);
                $db->query("UPDATE credit_order_returns SET status='approved', approved_by_user_id=?, approved_at=NOW() WHERE id=?", [$user_id, $ret_id]);
                $pdo->commit();

                auditLog('credit_order_returns', 'approved', "Return {$ret->return_number} approved", ['return_id' => $ret_id]);
                $success = "Return #{$ret->return_number} approved and ledger updated.";
            } else {
                $db->query("UPDATE credit_order_returns SET status='rejected', approved_by_user_id=?, approved_at=NOW() WHERE id=?", [$user_id, $ret_id]);
                auditLog('credit_order_returns', 'rejected', "Return {$ret->return_number} rejected", ['return_id' => $ret_id]);
                $success = "Return #{$ret->return_number} rejected.";
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

/* ─── POST: Delete return ───────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_return') {
    if (!$is_admin) {
        $error = "Permission denied.";
    } else {
        try {
            $ret_id = (int)$_POST['return_id'];
            $ret    = $db->query("SELECT * FROM credit_order_returns WHERE id = ?", [$ret_id])->first();
            if (!$ret) throw new Exception("Return record not found.");

            // Feature #3: recycle tables before the transaction (DDL implicit-commits).
            ensureRecycleBinTables();

            $pdo->beginTransaction();

            $batch = recycleBegin('return',
                "Return {$ret->return_number} — order #{$ret->order_id} · ৳" . number_format((float)$ret->total_returned_amount, 2),
                (int)$ret->customer_id);

            if ($ret->status === 'approved') {
                $amount = (float)$ret->total_returned_amount;

                // Snapshot before-images so a restore re-applies the reversal exactly
                recycleSnapshotBefore($batch, 'credit_orders', 'id', (int)$ret->order_id);
                recycleSnapshotBefore($batch, 'customers',     'id', (int)$ret->customer_id);

                // Restore invoice total and balance due
                $db->query(
                    "UPDATE credit_orders SET total_amount = total_amount + ?, balance_due = balance_due + ?, updated_at = NOW() WHERE id = ?",
                    [$amount, $amount, $ret->order_id]
                );

                // Restore customer balance
                $db->query(
                    "UPDATE customers SET current_balance = current_balance + ? WHERE id = ?",
                    [$amount, $ret->customer_id]
                );

                // Archive the ledger credit note entry (restorable)
                foreach ($db->query(
                    "SELECT id FROM customer_ledger WHERE reference_type = 'credit_order_returns' AND reference_id = ?",
                    [$ret_id]
                )->results() as $cle) {
                    recycleArchiveDelete($batch, 'customer_ledger', 'id', (int)$cle->id);
                }

                // If order was cancelled due to a full return, restore to delivered
                if ($ret->return_type === 'full') {
                    $db->query(
                        "UPDATE credit_orders SET status = 'delivered', updated_at = NOW() WHERE id = ? AND status = 'cancelled'",
                        [$ret->order_id]
                    );
                }
            }

            // Archive items then header
            recycleArchiveDelete($batch, 'credit_order_return_items', 'return_id', $ret_id);
            recycleArchiveDelete($batch, 'credit_order_returns',      'id',        $ret_id);

            recycleFinalize($batch);

            $pdo->commit();

            auditLog('credit_order_returns', 'soft_deleted',
                "Return {$ret->return_number} moved to Recycle Bin (batch #{$batch}) by admin" . ($ret->status === 'approved' ? " (financials reversed)" : ""),
                ['return_id' => $ret_id, 'was_approved' => $ret->status === 'approved', 'batch_id' => $batch]
            );

            $success = "Return #{$ret->return_number} moved to Recycle Bin (batch #{$batch})." . ($ret->status === 'approved' ? " Invoice and ledger have been restored." : "");

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

/* ─── Helper: apply financial impact ────────────────────────────────────────── */
function _applyReturnFinancials($db, $order, $return_id, $return_number, $return_type, $amount, $user_id): void {
    $order_id   = $order->id;
    $customer_id = $order->customer_id;

    $new_total   = max(0, (float)$order->total_amount - $amount);
    $new_balance = max(0, (float)$order->balance_due  - $amount);
    $db->query("UPDATE credit_orders SET total_amount = ?, balance_due = ?, updated_at = NOW() WHERE id = ?",
               [$new_total, $new_balance, $order_id]);

    $db->query(
        "UPDATE customers SET current_balance = GREATEST(0, current_balance - ?) WHERE id = ?",
        [$amount, $customer_id]
    );

    $prev = $db->query(
        "SELECT balance_after FROM customer_ledger WHERE customer_id = ? ORDER BY id DESC LIMIT 1",
        [$customer_id]
    )->first();
    $new_led_bal = ($prev ? (float)$prev->balance_after : 0) - $amount;

    $db->insert('customer_ledger', [
        'customer_id'        => $customer_id,
        'transaction_date'   => date('Y-m-d'),
        'transaction_type'   => 'credit_note',
        'reference_type'     => 'credit_order_returns',
        'reference_id'       => $return_id,
        'invoice_number'     => $return_number,
        'description'        => "Goods return {$return_number} — " . ucfirst($return_type) . " return against {$order->order_number}",
        'debit_amount'       => 0,
        'credit_amount'      => $amount,
        'balance_after'      => $new_led_bal,
        'created_by_user_id' => $user_id,
    ]);

    if ($return_type === 'full') {
        $db->query("UPDATE credit_orders SET status = 'cancelled', updated_at = NOW() WHERE id = ?", [$order_id]);
    }
}

/* ─── Load return list ───────────────────────────────────────────────────────── */
$date_from  = $_GET['date_from']  ?? date('Y-m-01');
$date_to    = $_GET['date_to']    ?? date('Y-m-d');
$ret_status = $_GET['ret_status'] ?? '';

$ret_conditions = ["r.return_date BETWEEN ? AND ?"];
$ret_params     = [$date_from, $date_to];
if (!empty($ret_status)) { $ret_conditions[] = "r.status = ?"; $ret_params[] = $ret_status; }

$return_list = $db->query(
    "SELECT r.*, c.name AS customer_name, co.order_number,
            u.display_name AS created_by_name
     FROM credit_order_returns r
     JOIN customers c  ON r.customer_id = c.id
     JOIN credit_orders co ON r.order_id = co.id
     LEFT JOIN users u ON r.created_by_user_id = u.id
     WHERE " . implode(' AND ', $ret_conditions) . "
     ORDER BY r.created_at DESC LIMIT 200",
    $ret_params
)->results();

/* ─── Inline items for expanded view ─────────────────────────────────────────── */
$return_ids = array_column($return_list, 'id');
$items_by_return = [];
if (!empty($return_ids)) {
    $placeholders = implode(',', array_fill(0, count($return_ids), '?'));
    $all_items = $db->query(
        "SELECT ri.*, p.base_name AS product_name,
                pv.grade, pv.weight_variant, pv.unit_of_measure
         FROM credit_order_return_items ri
         JOIN products p ON ri.product_id = p.id
         LEFT JOIN product_variants pv ON ri.variant_id = pv.id
         WHERE ri.return_id IN ($placeholders)
         ORDER BY ri.id",
        $return_ids
    )->results();
    foreach ($all_items as $ri) {
        $items_by_return[$ri->return_id][] = $ri;
    }
}

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<!-- Shared sub-tabs: Returns | Stock Adjustments (Feature #7 one-tab) -->
<div class="mb-5 flex items-center gap-2 border-b border-gray-200">
    <a href="returns.php" class="px-4 py-2 text-sm font-semibold text-orange-600 border-b-2 border-orange-500">
        <i class="fas fa-undo-alt mr-1"></i>Goods Returns
    </a>
    <a href="stock_adjustment.php" class="px-4 py-2 text-sm font-semibold text-gray-500 hover:text-gray-800 border-b-2 border-transparent">
        <i class="fas fa-sliders-h mr-1"></i>Stock Adjustments
    </a>
</div>

<!-- ── Header ──────────────────────────────────────────────────────────────── -->
<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">
            <i class="fas fa-undo-alt text-orange-500 mr-2"></i><?= $pageTitle ?>
        </h1>
        <p class="text-gray-500 mt-1">Record returned goods from delivered/shipped invoices. Adjusts invoice, receivables and ledger.</p>
    </div>
    <a href="index.php" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
        <i class="fas fa-arrow-left mr-2"></i>Dashboard
    </a>
</div>

<?php if ($error): ?>
<div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg">
    <p class="font-bold">Error</p><p><?= htmlspecialchars($error) ?></p>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-r-lg">
    <p class="font-bold">Success</p><p><?= htmlspecialchars($success) ?></p>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════
     HOW IT WORKS
════════════════════════════════════════════════════════════ -->
<div x-data="{ open: false }" class="mb-6">
    <button @click="open = !open"
            class="flex items-center gap-2 text-sm font-medium text-blue-700 bg-blue-50 border border-blue-200 px-4 py-2 rounded-lg hover:bg-blue-100 transition-colors">
        <i class="fas fa-info-circle"></i>
        <span x-text="open ? 'Hide Guide' : 'How Returns Work'"></span>
        <i class="fas fa-chevron-down transition-transform" :class="{ 'rotate-180': open }"></i>
    </button>
    <div x-show="open" x-collapse class="mt-3 bg-blue-50 border border-blue-200 rounded-lg p-5 text-sm text-blue-900">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            <div>
                <h4 class="font-semibold mb-2">📋 Process</h4>
                <ol class="list-decimal ml-4 space-y-1">
                    <li>Search for the delivered/shipped order.</li>
                    <li>Enter return quantity per item (partial allowed).</li>
                    <li>Admin can optionally set a <strong>compensation price</strong> per item — defaults to invoice price.</li>
                    <li>Submit → Pending (non-admin) or Auto-approved (admin).</li>
                </ol>
            </div>
            <div>
                <h4 class="font-semibold mb-2">⚙️ On Approval</h4>
                <ul class="list-disc ml-4 space-y-1">
                    <li>Invoice <strong>total & balance due</strong> reduced by return value.</li>
                    <li>Customer <strong>outstanding balance</strong> adjusted.</li>
                    <li><strong>Credit note</strong> added to customer ledger.</li>
                    <li>Full return → order marked <em>Cancelled</em>.</li>
                </ul>
            </div>
            <div>
                <h4 class="font-semibold mb-2">💰 Compensation Pricing</h4>
                <ul class="list-disc ml-4 space-y-1">
                    <li>Default: credit uses the <strong>original invoice price</strong>.</li>
                    <li>Admin can override per item — e.g., 50% credit for damaged goods.</li>
                    <li>Adjustment to invoice and receivables both use the <strong>compensation price</strong>.</li>
                    <li>Returns with custom pricing are flagged <span class="font-bold text-purple-700">★ Compensated</span>.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     NEW RETURN FORM
════════════════════════════════════════════════════════════ -->
<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-8">
    <h2 class="text-lg font-bold text-gray-800 mb-4">
        <i class="fas fa-plus-circle mr-2 text-orange-500"></i>Record New Return
    </h2>

    <!-- Order search -->
    <form method="GET" class="flex flex-wrap gap-3 mb-5">
        <div class="flex-1 min-w-[200px]">
            <label class="block text-xs font-medium text-gray-600 mb-1">Order Number / Customer Name</label>
            <input type="text" name="order_search" autocomplete="off"
                   value="<?= htmlspecialchars($_GET['order_search'] ?? '') ?>"
                   placeholder="Type order number or customer name..."
                   class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-orange-500">
        </div>
        <div class="flex items-end">
            <button type="submit" name="search_order" value="1"
                    class="px-4 py-2 bg-orange-600 text-white text-sm font-medium rounded-lg hover:bg-orange-700">
                <i class="fas fa-search mr-1"></i> Find Order
            </button>
        </div>
    </form>

    <?php
    $search_results = [];
    if (!empty($_GET['order_search']) && isset($_GET['search_order'])) {
        $sq = '%' . $_GET['order_search'] . '%';
        $search_results = $db->query(
            "SELECT co.id, co.order_number, co.total_amount, co.balance_due, co.status, co.order_date,
                    c.name AS customer_name
             FROM credit_orders co
             JOIN customers c ON co.customer_id = c.id
             WHERE co.status IN ('shipped','delivered')
               AND (co.order_number LIKE ? OR c.name LIKE ?)
             ORDER BY co.order_date DESC LIMIT 15",
            [$sq, $sq]
        )->results();
    }
    ?>

    <?php if (!empty($search_results)): ?>
    <div class="mb-5 border border-orange-200 rounded-lg overflow-hidden">
        <div class="bg-orange-50 px-4 py-2 text-sm font-semibold text-orange-800">Select an order:</div>
        <table class="min-w-full text-sm divide-y divide-gray-200">
            <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                <tr>
                    <th class="px-4 py-2 text-left">Order #</th>
                    <th class="px-4 py-2 text-left">Customer</th>
                    <th class="px-4 py-2 text-left">Date</th>
                    <th class="px-4 py-2 text-right">Invoice Total</th>
                    <th class="px-4 py-2 text-right">Balance Due</th>
                    <th class="px-4 py-2 text-center">Status</th>
                    <th class="px-4 py-2 text-center"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($search_results as $sr): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2 font-medium text-blue-700"><?= htmlspecialchars($sr->order_number) ?></td>
                    <td class="px-4 py-2"><?= htmlspecialchars($sr->customer_name) ?></td>
                    <td class="px-4 py-2 text-gray-500"><?= date('d-M-Y', strtotime($sr->order_date)) ?></td>
                    <td class="px-4 py-2 text-right">৳<?= number_format($sr->total_amount, 0) ?></td>
                    <td class="px-4 py-2 text-right text-red-600">৳<?= number_format($sr->balance_due, 0) ?></td>
                    <td class="px-4 py-2 text-center">
                        <span class="px-2 py-0.5 rounded text-xs font-semibold <?= $sr->status === 'delivered' ? 'bg-green-100 text-green-800' : 'bg-teal-100 text-teal-800' ?>">
                            <?= ucfirst($sr->status) ?>
                        </span>
                    </td>
                    <td class="px-4 py-2 text-center">
                        <a href="?order_id=<?= $sr->id ?>"
                           class="px-3 py-1 bg-orange-600 text-white text-xs rounded hover:bg-orange-700">
                            Return
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Return form for selected order -->
    <?php if ($selected_order): ?>
    <div class="border border-orange-200 rounded-xl overflow-hidden">

        <!-- Order header -->
        <div class="bg-orange-50 px-5 py-3 flex flex-wrap justify-between items-start gap-2 border-b border-orange-200">
            <div>
                <p class="font-bold text-orange-900 text-base"><?= htmlspecialchars($selected_order->order_number) ?></p>
                <p class="text-sm text-orange-700">
                    Customer: <strong><?= htmlspecialchars($selected_order->customer_name) ?></strong>
                </p>
            </div>
            <div class="flex gap-4 text-sm">
                <div class="text-center">
                    <p class="text-xs text-gray-500">Invoice Total</p>
                    <p class="font-bold text-gray-800">৳<?= number_format($selected_order->total_amount, 0) ?></p>
                </div>
                <div class="text-center">
                    <p class="text-xs text-gray-500">Balance Due</p>
                    <p class="font-bold text-red-600">৳<?= number_format($selected_order->balance_due, 0) ?></p>
                </div>
                <div class="text-center">
                    <p class="text-xs text-gray-500">Amount Paid</p>
                    <p class="font-bold text-green-600">৳<?= number_format((float)$selected_order->total_amount - (float)$selected_order->balance_due, 0) ?></p>
                </div>
            </div>
            <a href="returns.php" class="text-sm text-gray-500 hover:text-gray-800 self-start">✕ Clear</a>
        </div>

        <form method="POST" class="p-5">
            <input type="hidden" name="action" value="create_return">
            <input type="hidden" name="order_id" value="<?= $selected_order->id ?>">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Return Date <span class="text-red-500">*</span></label>
                    <input type="date" name="return_date" required value="<?= date('Y-m-d') ?>"
                           class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-orange-500 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Return Reason <span class="text-red-500">*</span></label>
                    <input type="text" name="return_reason" required
                           placeholder="e.g., Damaged goods, Wrong product, Quality issue..."
                           class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-orange-500 text-sm">
                </div>
            </div>

            <?php if ($is_admin): ?>
            <div class="mb-4 px-3 py-2.5 bg-purple-50 border border-purple-200 rounded-lg text-sm text-purple-800 flex items-center gap-2">
                <i class="fas fa-shield-alt text-purple-500"></i>
                <span>As admin, you can set a <strong>Compensation Price</strong> per item — leave blank or match invoice price to use invoice price.</span>
            </div>
            <?php endif; ?>

            <!-- Items table -->
            <div class="overflow-x-auto mb-5">
                <table class="min-w-full text-sm border border-gray-200 rounded-lg overflow-hidden">
                    <thead class="bg-gray-100 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="px-3 py-2.5 text-left">Product / Variant</th>
                            <th class="px-3 py-2.5 text-right">Ordered</th>
                            <th class="px-3 py-2.5 text-right">Returned</th>
                            <th class="px-3 py-2.5 text-right">Returnable</th>
                            <th class="px-3 py-2.5 text-right">Invoice Price</th>
                            <?php if ($is_admin): ?>
                            <th class="px-3 py-2.5 text-right text-purple-700">Compensation Price</th>
                            <?php endif; ?>
                            <th class="px-3 py-2.5 text-right">Return Qty</th>
                            <th class="px-3 py-2.5 text-right">Credit Value</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($order_items as $item):
                            $label = trim(($item->grade ?? '') . ' ' . ($item->weight_variant ?? ''));
                            $uom   = $item->unit_of_measure ?? '';
                        ?>
                        <tr class="hover:bg-gray-50" id="item-row-<?= $item->id ?>">
                            <td class="px-3 py-2.5">
                                <div class="font-medium text-gray-800"><?= htmlspecialchars($item->product_name) ?></div>
                                <?php if ($label): ?>
                                <div class="text-xs text-gray-400"><?= htmlspecialchars($label) ?><?= $uom ? " · $uom" : '' ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-3 py-2.5 text-right text-gray-600"><?= number_format($item->quantity, 1) ?></td>
                            <td class="px-3 py-2.5 text-right text-orange-600">
                                <?= $item->already_returned > 0 ? number_format($item->already_returned, 1) : '—' ?>
                            </td>
                            <td class="px-3 py-2.5 text-right font-semibold <?= $item->returnable_qty <= 0 ? 'text-red-500' : 'text-green-700' ?>">
                                <?= number_format($item->returnable_qty, 1) ?>
                            </td>
                            <td class="px-3 py-2.5 text-right text-gray-600 font-mono">
                                ৳<?= number_format($item->unit_price, 2) ?>
                            </td>
                            <?php if ($is_admin): ?>
                            <td class="px-3 py-2.5 text-right">
                                <?php if ($item->returnable_qty > 0): ?>
                                <input type="number"
                                       name="comp_price[<?= $item->id ?>]"
                                       id="comp-<?= $item->id ?>"
                                       min="0"
                                       step="0.01"
                                       placeholder="<?= number_format($item->unit_price, 2) ?>"
                                       class="w-28 px-2 py-1 border border-purple-300 rounded text-right text-sm focus:ring-2 focus:ring-purple-400 font-mono"
                                       oninput="calcCredit(<?= $item->id ?>, <?= (float)$item->unit_price ?>)">
                                <div class="text-[10px] text-gray-400 mt-0.5 text-right">blank = invoice price</div>
                                <?php else: ?>
                                <span class="text-xs text-gray-400">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td class="px-3 py-2.5 text-right">
                                <?php if ($item->returnable_qty > 0): ?>
                                <input type="number"
                                       name="return_qty[<?= $item->id ?>]"
                                       id="qty-<?= $item->id ?>"
                                       min="0" max="<?= $item->returnable_qty ?>" step="0.5"
                                       value="0"
                                       class="w-24 px-2 py-1 border rounded text-right focus:ring-2 focus:ring-orange-500 text-sm"
                                       oninput="calcCredit(<?= $item->id ?>, <?= (float)$item->unit_price ?>)">
                                <?php else: ?>
                                <span class="text-xs text-gray-400 italic">Fully returned</span>
                                <input type="hidden" name="return_qty[<?= $item->id ?>]" value="0">
                                <?php endif; ?>
                            </td>
                            <td class="px-3 py-2.5 text-right font-semibold text-orange-700 font-mono"
                                id="credit-<?= $item->id ?>">৳0.00</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-gray-50 border-t-2 border-gray-300">
                        <tr>
                            <td colspan="<?= $is_admin ? 7 : 6 ?>" class="px-3 py-2.5 text-right font-bold text-gray-700">
                                Total Credit Note:
                            </td>
                            <td class="px-3 py-2.5 text-right font-bold text-orange-700 text-lg font-mono" id="total-credit">
                                ৳0.00
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- After return preview -->
            <div id="preview-panel" class="hidden mb-5 px-4 py-3 bg-yellow-50 border border-yellow-200 rounded-lg text-sm">
                <p class="font-semibold text-yellow-800 mb-1">After Return Preview</p>
                <div class="flex flex-wrap gap-6 text-sm">
                    <div>
                        <span class="text-gray-500">Invoice Total:</span>
                        <span class="font-bold text-gray-800" id="preview-total">—</span>
                    </div>
                    <div>
                        <span class="text-gray-500">Balance Due:</span>
                        <span class="font-bold text-red-600" id="preview-balance">—</span>
                    </div>
                    <div>
                        <span class="text-gray-500">Credit Note:</span>
                        <span class="font-bold text-orange-700" id="preview-credit">—</span>
                    </div>
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
                <textarea name="notes" rows="2"
                          placeholder="Additional notes about this return..."
                          class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-orange-500 text-sm"></textarea>
            </div>

            <div class="flex justify-end gap-3">
                <a href="returns.php" class="px-5 py-2 border border-gray-300 text-sm rounded-lg text-gray-700 hover:bg-gray-50">Cancel</a>
                <button type="submit"
                        class="px-6 py-2 bg-orange-600 text-white font-bold rounded-lg hover:bg-orange-700 shadow transition-colors">
                    <i class="fas fa-undo-alt mr-2"></i>Submit Return
                </button>
            </div>
        </form>
    </div>

    <?php elseif (empty($search_results)): ?>
    <div class="text-center py-10 text-gray-400 border-2 border-dashed border-gray-200 rounded-xl">
        <i class="fas fa-search text-4xl mb-3 block text-gray-300"></i>
        <p class="font-medium">Search for a delivered or shipped order above.</p>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════
     RETURNS HISTORY
════════════════════════════════════════════════════════════ -->
<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-100 flex flex-wrap justify-between items-center gap-4 bg-gray-50">
        <h2 class="text-base font-bold text-gray-800">
            <i class="fas fa-history mr-2 text-gray-400"></i>Returns History
        </h2>
        <form method="GET" class="flex flex-wrap gap-2">
            <input type="date" name="date_from" value="<?= $date_from ?>"
                   class="px-2 py-1.5 border rounded text-sm focus:ring-2 focus:ring-orange-500">
            <span class="self-center text-gray-400 text-xs">to</span>
            <input type="date" name="date_to" value="<?= $date_to ?>"
                   class="px-2 py-1.5 border rounded text-sm focus:ring-2 focus:ring-orange-500">
            <select name="ret_status" class="px-2 py-1.5 border rounded text-sm focus:ring-2 focus:ring-orange-500">
                <option value="">All Statuses</option>
                <option value="pending"  <?= $ret_status === 'pending'  ? 'selected' : '' ?>>Pending</option>
                <option value="approved" <?= $ret_status === 'approved' ? 'selected' : '' ?>>Approved</option>
                <option value="rejected" <?= $ret_status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>
            <button type="submit" class="px-3 py-1.5 bg-gray-700 text-white text-sm rounded hover:bg-gray-800">
                <i class="fas fa-filter mr-1"></i> Filter
            </button>
        </form>
    </div>

    <?php if (empty($return_list)): ?>
    <div class="p-12 text-center text-gray-400">
        <i class="fas fa-box-open text-4xl mb-3 block text-gray-300"></i>
        <p>No returns found for the selected period.</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-100 text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 text-left w-6"></th>
                    <th class="px-4 py-3 text-left">Return #</th>
                    <th class="px-4 py-3 text-left">Invoice #</th>
                    <th class="px-4 py-3 text-left">Customer</th>
                    <th class="px-4 py-3 text-left">Date</th>
                    <th class="px-4 py-3 text-center">Type</th>
                    <th class="px-4 py-3 text-right">Credit Note</th>
                    <th class="px-4 py-3 text-center">Pricing</th>
                    <th class="px-4 py-3 text-center">Status</th>
                    <th class="px-4 py-3 text-center">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($return_list as $r):
                    $sc = ['pending'=>'bg-yellow-100 text-yellow-800','approved'=>'bg-green-100 text-green-800','rejected'=>'bg-red-100 text-red-800'][$r->status] ?? 'bg-gray-100 text-gray-700';
                    $ritems = $items_by_return[$r->id] ?? [];
                ?>
                <tr class="hover:bg-gray-50 cursor-pointer" onclick="toggleItems(<?= $r->id ?>)">
                    <td class="px-4 py-3 text-center text-gray-400">
                        <i class="fas fa-chevron-right text-[10px] transition-transform" id="chevron-<?= $r->id ?>"></i>
                    </td>
                    <td class="px-4 py-3 font-semibold text-orange-700"><?= htmlspecialchars($r->return_number) ?></td>
                    <td class="px-4 py-3">
                        <a href="credit_order_view.php?id=<?= $r->order_id ?>"
                           onclick="event.stopPropagation()"
                           class="text-blue-600 hover:underline">
                            <?= htmlspecialchars($r->order_number) ?>
                        </a>
                    </td>
                    <td class="px-4 py-3"><?= htmlspecialchars($r->customer_name) ?></td>
                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap"><?= date('d-M-Y', strtotime($r->return_date)) ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-0.5 text-xs rounded-full <?= $r->return_type === 'full' ? 'bg-red-100 text-red-700' : 'bg-orange-100 text-orange-700' ?>">
                            <?= ucfirst($r->return_type) ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-right font-bold text-orange-700 font-mono">
                        ৳<?= number_format($r->total_returned_amount, 2) ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($r->has_compensation): ?>
                        <span class="px-2 py-0.5 text-xs font-bold rounded-full bg-purple-100 text-purple-700" title="Compensation pricing applied">
                            ★ Compensated
                        </span>
                        <?php else: ?>
                        <span class="text-xs text-gray-400">Invoice</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-0.5 text-xs font-bold rounded-full <?= $sc ?>">
                            <?= ucfirst($r->status) ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center" onclick="event.stopPropagation()">
                        <a href="credit_order_view.php?id=<?= $r->order_id ?>" class="text-blue-500 hover:text-blue-700 mr-2" title="View Invoice">
                            <i class="fas fa-eye text-xs"></i>
                        </a>
                        <?php if ($is_admin): ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="return_id" value="<?= $r->id ?>">
                            <?php if ($r->status === 'pending'): ?>
                            <button name="action" value="approve_return"
                                    class="px-2 py-1 bg-green-600 text-white text-xs rounded hover:bg-green-700 mr-1"
                                    onclick="return confirm('Approve return <?= htmlspecialchars($r->return_number) ?>? This will update the invoice and customer ledger.')">
                                <i class="fas fa-check mr-1"></i>Approve
                            </button>
                            <button name="action" value="reject_return"
                                    class="px-2 py-1 bg-red-500 text-white text-xs rounded hover:bg-red-600 mr-1"
                                    onclick="return confirm('Reject this return?')">
                                <i class="fas fa-times mr-1"></i>Reject
                            </button>
                            <?php endif; ?>
                            <button name="action" value="delete_return"
                                    class="px-2 py-1 bg-gray-200 text-gray-700 text-xs rounded hover:bg-red-600 hover:text-white transition-colors"
                                    title="Delete this return<?= $r->status === 'approved' ? ' (will reverse financial impact)' : '' ?>"
                                    onclick="return confirm('Delete return <?= htmlspecialchars($r->return_number) ?>?<?= $r->status === 'approved' ? '\n\nThis return is APPROVED. Deleting it will:\n• Restore the invoice total & balance due\n• Reverse the customer ledger credit note\n• Restore customer balance\n\nAre you sure?' : '' ?>')">
                                <i class="fas fa-trash text-xs"></i>
                            </button>
                        </form>
                        <?php else: ?>
                        <span class="text-xs text-gray-400">—</span>
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- Expandable item detail -->
                <tr id="items-<?= $r->id ?>" class="hidden">
                    <td colspan="10" class="px-0 py-0 bg-orange-50 border-b border-orange-200">
                        <div class="px-8 py-3">
                            <p class="text-xs font-semibold text-orange-700 mb-2 uppercase tracking-wide">
                                Returned Items — <?= htmlspecialchars($r->return_reason ?? '') ?>
                            </p>
                            <?php if (empty($ritems)): ?>
                            <p class="text-xs text-gray-400">No item detail available.</p>
                            <?php else: ?>
                            <table class="text-xs w-full max-w-2xl">
                                <thead>
                                    <tr class="text-gray-500">
                                        <th class="text-left py-1 pr-4">Product</th>
                                        <th class="text-right pr-4">Qty Returned</th>
                                        <th class="text-right pr-4">Invoice Price</th>
                                        <?php if ($r->has_compensation): ?>
                                        <th class="text-right pr-4 text-purple-700">Comp. Price</th>
                                        <?php endif; ?>
                                        <th class="text-right">Credit</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-orange-100">
                                    <?php foreach ($ritems as $ri):
                                        $rl = trim(($ri->grade ?? '') . ' ' . ($ri->weight_variant ?? ''));
                                        $eff_price = $ri->compensation_price ?? $ri->unit_price;
                                    ?>
                                    <tr>
                                        <td class="py-1 pr-4 font-medium text-gray-700">
                                            <?= htmlspecialchars($ri->product_name) ?>
                                            <?php if ($rl): ?><span class="text-gray-400"> · <?= htmlspecialchars($rl) ?></span><?php endif; ?>
                                        </td>
                                        <td class="text-right pr-4"><?= number_format($ri->returned_qty, 1) ?></td>
                                        <td class="text-right pr-4 font-mono">৳<?= number_format($ri->unit_price, 2) ?></td>
                                        <?php if ($r->has_compensation): ?>
                                        <td class="text-right pr-4 font-mono <?= $ri->price_type === 'compensated' ? 'font-bold text-purple-700' : 'text-gray-400' ?>">
                                            <?= $ri->price_type === 'compensated'
                                                ? '৳' . number_format($ri->compensation_price, 2)
                                                : '(invoice)' ?>
                                        </td>
                                        <?php endif; ?>
                                        <td class="text-right font-bold text-orange-700 font-mono">৳<?= number_format($ri->returned_amount, 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (!empty($r->notes)): ?>
                            <p class="text-xs text-gray-500 mt-2"><i class="fas fa-sticky-note mr-1"></i><?= htmlspecialchars($r->notes) ?></p>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

</div><!-- end container -->

<script>
const ORDER_TOTAL   = <?= $selected_order ? (float)$selected_order->total_amount : 0 ?>;
const ORDER_BALANCE = <?= $selected_order ? (float)$selected_order->balance_due  : 0 ?>;

function calcCredit(itemId, invoicePrice) {
    const qty       = parseFloat(document.getElementById('qty-' + itemId)?.value) || 0;
    const compInput = document.getElementById('comp-' + itemId);
    let   price     = invoicePrice;
    if (compInput) {
        const cv = parseFloat(compInput.value);
        if (!isNaN(cv) && cv > 0) price = cv;
    }
    const credit = qty * price;
    document.getElementById('credit-' + itemId).textContent = '৳' + credit.toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});
    calcTotal();
}

function calcTotal() {
    let total = 0;
    document.querySelectorAll('[id^="credit-"]').forEach(el => {
        total += parseFloat(el.textContent.replace('৳','').replace(/,/g,'')) || 0;
    });
    document.getElementById('total-credit').textContent =
        '৳' + total.toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});

    const panel = document.getElementById('preview-panel');
    if (total > 0 && ORDER_TOTAL > 0) {
        panel.classList.remove('hidden');
        const newTotal   = Math.max(0, ORDER_TOTAL   - total);
        const newBalance = Math.max(0, ORDER_BALANCE - total);
        document.getElementById('preview-total').textContent   = '৳' + newTotal.toLocaleString('en-IN', {minimumFractionDigits:0});
        document.getElementById('preview-balance').textContent = '৳' + newBalance.toLocaleString('en-IN', {minimumFractionDigits:0});
        document.getElementById('preview-credit').textContent  = '৳' + total.toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});
    } else {
        panel.classList.add('hidden');
    }
}

function toggleItems(returnId) {
    const row     = document.getElementById('items-' + returnId);
    const chevron = document.getElementById('chevron-' + returnId);
    if (row) {
        row.classList.toggle('hidden');
        if (chevron) chevron.classList.toggle('rotate-90');
    }
}
</script>

<?php require_once '../templates/footer.php'; ?>
