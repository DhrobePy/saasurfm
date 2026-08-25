<?php
require_once '../core/init.php';

// Filed under PRODUCTION in the privileges UI, but older rows granted it under
// credit_sales (auto-detect maps all cr/ pages there). Accept either grant.
if (!userHasPageGrant('production', 'partial_delivery') && !userHasPageGrant('credit_sales', 'partial_delivery')) {
    restrict_access([], 'production', 'partial_delivery');
}

global $db;
$currentUser = getCurrentUser();
$user_id     = $currentUser['id']   ?? null;
$user_role   = $currentUser['role'] ?? '';
$pageTitle   = 'Partial Delivery';

$is_admin    = in_array($user_role, ['Superadmin', 'admin']);
// Privilege-based: replaces hardcoded role list
$is_accounts = userCanPageAction('credit_sales', 'customer_payment', 'can_collect');
$is_dispatch = in_array($user_role, ['dispatch-srg', 'dispatch-demra', 'dispatchpos-srg', 'dispatchpos-demra']);

/* ─── Auto-create delivery tables ──────────────────────────── */
$db->getPdo()->exec("
    CREATE TABLE IF NOT EXISTS `credit_order_deliveries` (
      `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
      `delivery_number` varchar(50) NOT NULL,
      `order_id` bigint UNSIGNED NOT NULL,
      `customer_id` bigint UNSIGNED NOT NULL,
      `delivery_date` date NOT NULL,
      `truck_number` varchar(100) DEFAULT NULL,
      `driver_name` varchar(150) DEFAULT NULL,
      `driver_contact` varchar(50) DEFAULT NULL,
      `total_qty_delivered` decimal(10,2) NOT NULL DEFAULT 0.00,
      `total_amount_delivered` decimal(12,2) NOT NULL DEFAULT 0.00,
      `is_final` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = final delivery, closes the order',
      `notes` text DEFAULT NULL,
      `created_by_user_id` bigint UNSIGNED NOT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_delivery_number` (`delivery_number`),
      KEY `idx_order_id` (`order_id`),
      KEY `idx_customer_id` (`customer_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$db->getPdo()->exec("
    CREATE TABLE IF NOT EXISTS `credit_order_delivery_items` (
      `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
      `delivery_id` bigint UNSIGNED NOT NULL,
      `order_item_id` bigint UNSIGNED NOT NULL,
      `product_id` bigint UNSIGNED NOT NULL,
      `variant_id` bigint UNSIGNED DEFAULT NULL,
      `qty_delivered` decimal(10,2) NOT NULL,
      `unit_price` decimal(12,2) NOT NULL,
      `line_amount` decimal(12,2) NOT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_delivery_id` (`delivery_id`),
      KEY `idx_order_item_id` (`order_item_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$error   = null;
$success = null;

/* ─── Load order for new delivery ─────────────────────────── */
$selected_order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$selected_order    = null;
$order_items       = [];

$eligible_statuses = ['approved', 'in_production', 'produced', 'ready_to_ship', 'goods_on_board', 'shipped'];

if ($selected_order_id) {
    $status_placeholders = implode(',', array_fill(0, count($eligible_statuses), '?'));
    $selected_order = $db->query(
        "SELECT co.*, c.name AS customer_name, c.phone_number, c.current_balance, c.initial_due,
                b.name AS branch_name
         FROM credit_orders co
         JOIN customers c ON co.customer_id = c.id
         LEFT JOIN branches b ON co.assigned_branch_id = b.id
         WHERE co.id = ? AND co.status IN ({$status_placeholders})",
        array_merge([$selected_order_id], $eligible_statuses)
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

        // Calculate already-delivered quantities per item
        foreach ($order_items as $item) {
            $delivered = $db->query(
                "SELECT COALESCE(SUM(di.qty_delivered), 0) AS qty
                 FROM credit_order_delivery_items di
                 JOIN credit_order_deliveries d ON d.id = di.delivery_id
                 WHERE di.order_item_id = ?",
                [$item->id]
            )->first();
            $item->already_delivered = (float)($delivered->qty ?? 0);
            $item->deliverable_qty   = max(0, (float)$item->quantity - $item->already_delivered);
        }

        // Get past deliveries for this order
        $selected_order->past_deliveries = $db->query(
            "SELECT d.*, u.display_name AS delivered_by_name
             FROM credit_order_deliveries d
             LEFT JOIN users u ON d.created_by_user_id = u.id
             WHERE d.order_id = ?
             ORDER BY d.delivery_date ASC, d.id ASC",
            [$selected_order_id]
        )->results();

        // Cumulative delivered amount so far
        $cum = $db->query(
            "SELECT COALESCE(SUM(total_amount_delivered), 0) AS total
             FROM credit_order_deliveries WHERE order_id = ?",
            [$selected_order_id]
        )->first();
        $selected_order->cumulative_delivered = (float)($cum->total ?? 0);
    }
}

/* ─── Pending request review (maker/checker): ?pending_req= prefills the form ── */
$preq       = null;
$preq_error = null;
$preq_maker = null;
$preq_id_get = isset($_GET['pending_req']) ? (int)$_GET['pending_req'] : 0;
if ($preq_id_get) {
    $preq = getPendingRequest($preq_id_get, 'delivery');
    if (!$preq) {
        $preq_error = "Request #{$preq_id_get} is not open — it may already be approved, rejected, or cancelled.";
    } elseif ((int)$preq->order_id !== $selected_order_id) {
        // Always review against the order the request was made for
        header('Location: partial_delivery.php?order_id=' . (int)$preq->order_id . '&pending_req=' . $preq_id_get);
        exit();
    } else {
        $my_delivery_cap = $is_admin ? null : getUserActionLimit((int)$user_id, 'partial_delivery');
        if (!$is_admin && $my_delivery_cap !== null && (float)$preq->amount > $my_delivery_cap) {
            $preq_error = "Request #{$preq_id_get} (৳" . number_format((float)$preq->amount, 0)
                        . ") is above your own delivery limit of ৳" . number_format($my_delivery_cap, 0) . ".";
            $preq = null;
        } else {
            $preq_maker = $db->query("SELECT display_name FROM users WHERE id = ?", [(int)$preq->maker_user_id])->first();
        }
    }
}

/* ─── Handle POST: Record delivery ────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_delivery') {
    try {
        $order_id      = (int)$_POST['order_id'];

        // Server-side dispatch gate: partial delivery is a dispatch too.
        // Checked before the transaction so a direct POST cannot bypass the hold.
        if (!orderDispatchAllowed($order_id)) {
            $g = getOrderGateState($order_id);
            $msg = "Delivery is HELD for this order — awaiting payment clearance from Accounts.";
            if ($g['shortfall'] !== null && $g['shortfall'] > 0) {
                $msg .= " Shortfall: ৳" . number_format($g['shortfall'], 0) . ".";
            }
            throw new Exception($msg);
        }
        $delivery_date = $_POST['delivery_date'] ?? date('Y-m-d');
        $truck         = trim($_POST['truck_number'] ?? '');
        $driver        = trim($_POST['driver_name'] ?? '');
        $contact       = trim($_POST['driver_contact'] ?? '');
        $is_final      = (int)(($_POST['is_final'] ?? '0') === '1');
        $notes         = trim($_POST['notes'] ?? '');
        $del_qtys      = $_POST['delivery_qty'] ?? [];   // [item_id => qty]

        // Re-fetch order with write-lock approach
        $status_placeholders = implode(',', array_fill(0, count($eligible_statuses), '?'));
        $order = $db->query(
            "SELECT co.*, c.current_balance, c.initial_due
             FROM credit_orders co JOIN customers c ON co.customer_id = c.id
             WHERE co.id = ? AND co.status IN ({$status_placeholders})",
            array_merge([$order_id], $eligible_statuses)
        )->first();
        if (!$order) throw new Exception("Order not found or not eligible for delivery recording.");

        // Build delivery lines
        $lines = [];
        $this_delivery_amount = 0;
        $this_delivery_qty    = 0;

        foreach ($del_qtys as $item_id => $qty) {
            $qty = (float)$qty;
            if ($qty <= 0) continue;

            $item = $db->query(
                "SELECT coi.*, p.base_name AS product_name
                 FROM credit_order_items coi JOIN products p ON coi.product_id = p.id
                 WHERE coi.id = ? AND coi.order_id = ?",
                [(int)$item_id, $order_id]
            )->first();
            if (!$item) continue;

            // Check max deliverable
            $already_del = (float)$db->query(
                "SELECT COALESCE(SUM(di.qty_delivered), 0) AS qty
                 FROM credit_order_delivery_items di
                 WHERE di.order_item_id = ?",
                [(int)$item_id]
            )->first()->qty;

            $deliverable = (float)$item->quantity - $already_del;
            if ($qty > $deliverable + 0.001) {
                throw new Exception("Delivery qty ({$qty}) for '{$item->product_name}' exceeds remaining qty ({$deliverable}).");
            }

            $line_amount = round($qty * (float)$item->unit_price, 2);
            $lines[] = [
                'order_item_id' => (int)$item_id,
                'product_id'    => $item->product_id,
                'variant_id'    => $item->variant_id,
                'qty_delivered' => $qty,
                'unit_price'    => (float)$item->unit_price,
                'line_amount'   => $line_amount,
            ];
            $this_delivery_amount += $line_amount;
            $this_delivery_qty    += $qty;
        }

        if (empty($lines)) throw new Exception("No delivery quantities entered.");

        // Per-user delivery ceiling (admin/privileges.php → Action Limits).
        // No legacy fallback: only an explicit partial_delivery limit applies.
        // Over the cap → parked as a pending request (maker/checker); nothing is
        // recorded until a checker whose limit covers it reviews and posts.
        $exec_pending_req_id = (int)($_POST['pending_req_id'] ?? 0);
        if (!$is_admin) {
            $delivery_cap = getUserActionLimit((int)$user_id, 'partial_delivery');
            if ($delivery_cap !== null && $this_delivery_amount > $delivery_cap) {
                if ($exec_pending_req_id) {
                    throw new Exception('Your delivery limit (৳' . number_format($delivery_cap, 0)
                        . ') does not cover this ৳' . number_format($this_delivery_amount, 0)
                        . ' request — a more senior officer must post it.');
                }
                $req_id = submitPendingRequest('delivery', $this_delivery_amount, [
                    'order_id'       => $order_id,
                    'delivery_date'  => $delivery_date,
                    'truck_number'   => $truck,
                    'driver_name'    => $driver,
                    'driver_contact' => $contact,
                    'is_final'       => $is_final,
                    'notes'          => $notes,
                    'delivery_qty'   => $del_qtys,
                ], [
                    'order_id'    => $order_id,
                    'customer_id' => (int)$order->customer_id,
                    'summary'     => 'Delivery ৳' . number_format($this_delivery_amount, 0) . ' ('
                                   . number_format($this_delivery_qty, 2) . ' units) for order ' . $order->order_number
                                   . ($is_final ? ' [FINAL]' : ''),
                    'maker_limit' => $delivery_cap,
                ]);
                if (!$req_id) throw new Exception('Could not queue this delivery for approval. Please try again.');
                auditLogOrder('delivery_queued', $order_id, $order->order_number,
                    'Delivery of ৳' . number_format($this_delivery_amount, 2) . ' queued for approval (over ৳'
                    . number_format($delivery_cap, 0) . ' limit) by ' . ($currentUser['display_name'] ?? 'user'));
                $_SESSION['success_flash'] = 'This delivery (৳' . number_format($this_delivery_amount, 0)
                    . ') is above your delivery limit, so it was sent for approval as request #' . $req_id
                    . '. It will be recorded once a senior officer approves it.';
                header('Location: partial_delivery.php?order_id=' . $order_id);
                exit();
            }
        }

        // Generate delivery number: DEL-{YYYYMMDD}-{seq}
        $dn_date = date('Ymd', strtotime($delivery_date));
        $last    = $db->query(
            "SELECT delivery_number FROM credit_order_deliveries WHERE delivery_number LIKE ? ORDER BY id DESC LIMIT 1",
            ["DEL-{$dn_date}-%"]
        )->first();
        $seq    = $last ? (int)substr($last->delivery_number, -4) + 1 : 1;
        $delivery_number = sprintf("DEL-%s-%04d", $dn_date, $seq);

        $pdo = $db->getPdo();
        $pdo->beginTransaction();

        // Insert delivery header
        $delivery_id = $db->insert('credit_order_deliveries', [
            'delivery_number'        => $delivery_number,
            'order_id'               => $order_id,
            'customer_id'            => $order->customer_id,
            'delivery_date'          => $delivery_date,
            'truck_number'           => $truck,
            'driver_name'            => $driver,
            'driver_contact'         => $contact,
            'total_qty_delivered'    => $this_delivery_qty,
            'total_amount_delivered' => $this_delivery_amount,
            'is_final'               => $is_final,
            'notes'                  => $notes,
            'created_by_user_id'     => $user_id,
        ]);

        foreach ($lines as $l) {
            $db->insert('credit_order_delivery_items', array_merge(['delivery_id' => $delivery_id], $l));
        }

        // Pro-rate balance_due: cumulative delivered value minus amount_paid
        $cumulative = $db->query(
            "SELECT COALESCE(SUM(total_amount_delivered), 0) AS total
             FROM credit_order_deliveries WHERE order_id = ?",
            [$order_id]
        )->first()->total;

        $old_balance_due = (float)$order->balance_due;
        $new_balance_due = max(0, (float)$cumulative - (float)$order->amount_paid);

        // Determine new order status
        $new_status = 'shipped'; // partial by default
        if ($is_final) {
            $new_status = 'delivered';
        }

        // Check if all items are now fully delivered (regardless of is_final flag)
        $total_ordered_qty = (float)$db->query(
            "SELECT COALESCE(SUM(quantity), 0) AS total FROM credit_order_items WHERE order_id = ?",
            [$order_id]
        )->first()->total;

        $total_delivered_qty = (float)$db->query(
            "SELECT COALESCE(SUM(total_qty_delivered), 0) AS total
             FROM credit_order_deliveries WHERE order_id = ?",
            [$order_id]
        )->first()->total;

        if ($total_delivered_qty >= $total_ordered_qty - 0.001) {
            $new_status = 'delivered';
            $is_final   = 1;
        }

        // Update credit_order
        if ($new_status === 'delivered') {
            $db->query(
                "UPDATE credit_orders SET status = 'delivered', balance_due = ?, updated_at = NOW() WHERE id = ?",
                [$new_balance_due, $order_id]
            );
        } else {
            $db->query(
                "UPDATE credit_orders SET status = 'shipped', balance_due = ?, updated_at = NOW() WHERE id = ?",
                [$new_balance_due, $order_id]
            );
        }

        // Update shipping record (if exists, update; else insert)
        $shipping_exists = $db->query(
            "SELECT id FROM credit_order_shipping WHERE order_id = ?", [$order_id]
        )->first();

        if ($shipping_exists) {
            $update_fields = "truck_number = ?, driver_name = ?, driver_contact = ?";
            $update_params = [$truck, $driver, $contact];
            if ($new_status === 'delivered') {
                $update_fields .= ", delivered_date = NOW()";
            }
            if ($order->status !== 'shipped' && $order->status !== 'delivered') {
                $update_fields .= ", shipped_date = NOW()";
            }
            $db->query(
                "UPDATE credit_order_shipping SET {$update_fields} WHERE order_id = ?",
                array_merge($update_params, [$order_id])
            );
        } else {
            $db->query(
                "INSERT INTO credit_order_shipping (order_id, truck_number, driver_name, driver_contact, shipped_date" .
                ($new_status === 'delivered' ? ", delivered_date" : "") .
                ") VALUES (?, ?, ?, ?, NOW()" . ($new_status === 'delivered' ? ", NOW()" : "") . ")",
                [$order_id, $truck, $driver, $contact]
            );
        }

        // Adjust customer current_balance by the delta in balance_due
        $balance_delta = $new_balance_due - $old_balance_due;
        if (abs($balance_delta) > 0.001) {
            $db->query(
                "UPDATE customers SET current_balance = GREATEST(0, current_balance + ?) WHERE id = ?",
                [$balance_delta, $order->customer_id]
            );

            // Add ledger entry only for the incremental delivered amount (if increasing)
            if ($balance_delta > 0) {
                // Compute prev_bal from aggregate — immune to balance_after chain drift.
                // OB ledger entry already encodes initial_due as a debit, so SUM(debit)-SUM(credit)
                // is correct when entries exist. Fallback to initial_due when no entries exist yet.
                $cust_init = $db->query("SELECT initial_due FROM customers WHERE id = ?", [$order->customer_id])->first();
                $agg_pd = $db->query(
                    "SELECT COALESCE(SUM(debit_amount), 0)  AS td,
                            COALESCE(SUM(credit_amount), 0) AS tc
                     FROM customer_ledger WHERE customer_id = ?",
                    [$order->customer_id]
                )->first();
                $agg_pd_td = (float)($agg_pd->td ?? 0);
                $agg_pd_tc = (float)($agg_pd->tc ?? 0);
                $prev_bal = ($agg_pd_td > 0 || $agg_pd_tc > 0)
                    ? $agg_pd_td - $agg_pd_tc
                    : (float)($cust_init->initial_due ?? 0);

                $db->insert('customer_ledger', [
                    'customer_id'        => $order->customer_id,
                    'transaction_date'   => $delivery_date,
                    'transaction_type'   => 'sale',
                    'reference_type'     => 'credit_order_deliveries',
                    'reference_id'       => $delivery_id,
                    'invoice_number'     => $delivery_number,
                    'description'        => "Partial delivery {$delivery_number} — order {$order->order_number} (" . number_format($this_delivery_qty, 2) . " units)",
                    'debit_amount'       => $balance_delta,
                    'credit_amount'      => 0,
                    'balance_after'      => $prev_bal + $balance_delta,
                    'created_by_user_id' => $user_id,
                ]);
            }
        }

        // Workflow log
        $wf_from = $order->status;
        $wf_comment = "Delivery {$delivery_number}: " . number_format($this_delivery_qty, 2) . " units / ৳" . number_format($this_delivery_amount, 2) . ($is_final ? " (Final delivery)" : " (Partial)");
        // NOTE: the table's timestamp column is `performed_at` with a NOW() default —
        // naming a nonexistent created_at column here made the whole delivery
        // transaction roll back on production.
        $db->query(
            "INSERT INTO credit_order_workflow (order_id, action, from_status, to_status, performed_by_user_id, comments)
             VALUES (?, 'deliver', ?, ?, ?, ?)",
            [$order_id, $wf_from, $new_status, $user_id, $wf_comment]
        );

        $pdo->commit();

        // Audit log
        auditLog('credit_order_deliveries', 'created',
            "Delivery {$delivery_number} recorded for order {$order->order_number} — ৳" . number_format($this_delivery_amount, 2) . ($is_final ? " [FINAL]" : ""), [
            'delivery_id'     => $delivery_id,
            'order_id'        => $order_id,
            'delivered_amount'=> $this_delivery_amount,
            'is_final'        => $is_final,
            'new_status'      => $new_status,
        ]);

        // Telegram notification
        try {
            if (defined('TELEGRAM_BOT_TOKEN') && defined('TELEGRAM_CHAT_ID') && defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
                require_once '../core/classes/TelegramNotifier.php';
                $notifier = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);
                $finalTag = $is_final ? ' 🏁 <b>FINAL DELIVERY</b>' : ' 📦 Partial delivery';
                $msg = "<b>🚚 DELIVERY RECORDED</b>\n"
                     . "─────────────────────────\n\n"
                     . "<b>Delivery #:</b> <code>{$delivery_number}</code>{$finalTag}\n"
                     . "<b>Order:</b> {$order->order_number}\n"
                     . "<b>Amount:</b> ৳" . number_format($this_delivery_amount, 2) . "\n"
                     . "<b>Qty:</b> " . number_format($this_delivery_qty, 2) . " units\n"
                     . "<b>Truck:</b> " . htmlspecialchars($truck ?: '—') . "\n"
                     . "<b>Driver:</b> " . htmlspecialchars($driver ?: '—') . "\n"
                     . "<b>Balance Due:</b> ৳" . number_format($new_balance_due, 2) . "\n"
                     . "<b>By:</b> " . ($currentUser['display_name'] ?? 'Unknown') . "\n\n"
                     . "<i>Ujjal Flour Mills ERP</i>";
                $notifier->sendMessage($msg);
            }
        } catch (Exception $te) {}

        // Close out the pending request this delivery fulfilled (maker/checker flow)
        if ($exec_pending_req_id) {
            if (decidePendingRequest($exec_pending_req_id, 'approved', null, $delivery_number)) {
                auditLogOrder('request_approved', $order_id, $order->order_number,
                    'Pending request #' . $exec_pending_req_id . ' approved & recorded as ' . $delivery_number
                    . ' by ' . ($currentUser['display_name'] ?? 'user'));
            }
        }

        $success_msg = "Delivery #{$delivery_number} recorded." . ($is_final ? " Order marked as Delivered." : " Order status: Shipped (partial).")
            . ($exec_pending_req_id ? " (approved request #{$exec_pending_req_id})" : '');
        $_SESSION['success_flash'] = $success_msg;
        header("Location: partial_delivery.php?order_id={$order_id}&delivery_ok=1");
        exit();

    } catch (Exception $e) {
        if ($db->getPdo()->inTransaction()) $db->getPdo()->rollBack();
        $error = $e->getMessage();
    }
}

/* Flash message after redirect */
if (!empty($_SESSION['success_flash'])) {
    $success = $_SESSION['success_flash'];
    unset($_SESSION['success_flash']);
}

/* ─── Order search ─────────────────────────────────────────── */
$search_query   = trim($_GET['q'] ?? '');
$search_results = [];

if (!$selected_order_id) {
    if ($search_query) {
        // Search: ALL statuses — show all results matching the query
        $search_results = $db->query(
            "SELECT co.id, co.order_number, co.total_amount, co.balance_due, co.amount_paid,
                    co.order_date, co.status, c.name AS customer_name
             FROM credit_orders co
             JOIN customers c ON co.customer_id = c.id
             WHERE (co.order_number LIKE ? OR c.name LIKE ? OR c.phone_number LIKE ?)
             ORDER BY co.order_date DESC LIMIT 30",
            ["%{$search_query}%", "%{$search_query}%", "%{$search_query}%"]
        )->results();
    } else {
        // No search: show last 20 eligible (deliverable) orders
        $status_placeholders = implode(',', array_fill(0, count($eligible_statuses), '?'));
        $search_results = $db->query(
            "SELECT co.id, co.order_number, co.total_amount, co.balance_due, co.amount_paid,
                    co.order_date, co.status, c.name AS customer_name
             FROM credit_orders co
             JOIN customers c ON co.customer_id = c.id
             WHERE co.status IN ({$status_placeholders})
             ORDER BY co.order_date DESC, co.id DESC LIMIT 20",
            $eligible_statuses
        )->results();
    }
}

/* ─── Recent deliveries list ───────────────────────────────── */
$del_date_from = $_GET['del_from'] ?? date('Y-m-01');
$del_date_to   = $_GET['del_to']   ?? date('Y-m-d');

$recent_deliveries = $db->query(
    "SELECT d.*, co.order_number, c.name AS customer_name, u.display_name AS delivered_by_name,
            b.name AS branch_name
     FROM credit_order_deliveries d
     JOIN credit_orders co ON d.order_id = co.id
     JOIN customers c ON d.customer_id = c.id
     LEFT JOIN users u ON d.created_by_user_id = u.id
     LEFT JOIN branches b ON co.assigned_branch_id = b.id
     WHERE d.delivery_date BETWEEN ? AND ?
     ORDER BY d.created_at DESC LIMIT 100",
    [$del_date_from, $del_date_to]
)->results();

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<!-- Header -->
<div class="mb-6 flex flex-wrap justify-between items-center gap-3">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">
            <i class="fas fa-truck text-indigo-600 mr-2"></i>Partial Delivery
        </h1>
        <p class="text-gray-500 mt-1">Split a credit order into multiple shipments and track deliveries</p>
    </div>
    <a href="index.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 text-sm">
        <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
    </a>
</div>

<!-- Flash messages -->
<?php if ($error): ?>
<div class="mb-4 p-4 bg-red-50 border border-red-300 rounded-lg text-red-800 flex items-start gap-2">
    <i class="fas fa-exclamation-circle mt-0.5 flex-shrink-0"></i>
    <span><?php echo htmlspecialchars($error); ?></span>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="mb-4 p-4 bg-green-50 border border-green-300 rounded-lg text-green-800 flex items-start gap-2">
    <i class="fas fa-check-circle mt-0.5 flex-shrink-0"></i>
    <span><?php echo htmlspecialchars($success); ?></span>
</div>
<?php endif; ?>

<!-- Order Search -->
<?php if (!$selected_order): ?>
<div class="bg-white rounded-xl shadow-md p-6 mb-6">
    <h2 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-search mr-2 text-indigo-500"></i>Find Order</h2>
    <form method="GET" class="flex gap-3">
        <input type="text" name="q" value="<?php echo htmlspecialchars($search_query); ?>"
               placeholder="Search by order number or customer name…"
               class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
        <button type="submit" class="px-5 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-medium">
            <i class="fas fa-search mr-2"></i>Search
        </button>
    </form>

    <?php if ($search_results): ?>
    <div class="mt-4">
        <p class="text-sm text-gray-500 mb-2">
            <?php if ($search_query): ?>
                <i class="fas fa-search mr-1"></i> Showing <strong><?php echo count($search_results); ?></strong> result(s) for "<strong><?php echo htmlspecialchars($search_query); ?></strong>"
                <span class="text-xs text-gray-400 ml-2">(all statuses shown — click "Record Delivery" only for eligible orders)</span>
            <?php else: ?>
                <i class="fas fa-clock mr-1 text-indigo-400"></i> <strong>Recent 20 eligible orders</strong> ready for delivery
            <?php endif; ?>
        </p>
        <div class="overflow-x-auto rounded-lg border border-gray-200">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order #</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance Due</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
                <?php foreach ($search_results as $r):
                    $all_status_colors = [
                        'approved'       => 'blue',
                        'in_production'  => 'purple',
                        'produced'       => 'indigo',
                        'ready_to_ship'  => 'teal',
                        'shipped'        => 'cyan',
                        'delivered'      => 'green',
                        'pending_approval'=> 'yellow',
                        'cancelled'      => 'gray',
                    ];
                    $col = $all_status_colors[$r->status] ?? 'gray';
                    $is_eligible = in_array($r->status, $eligible_statuses);
                ?>
                <tr class="hover:bg-indigo-50 <?php echo !$is_eligible ? 'opacity-60' : ''; ?>">
                    <td class="px-4 py-3 font-mono font-semibold text-blue-700">
                        <a href="credit_order_view.php?id=<?php echo $r->id; ?>" class="hover:underline" title="View order">
                            <?php echo htmlspecialchars($r->order_number); ?>
                        </a>
                    </td>
                    <td class="px-4 py-3"><?php echo htmlspecialchars($r->customer_name); ?></td>
                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap"><?php echo date('d-M-Y', strtotime($r->order_date)); ?></td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-0.5 text-xs rounded-full bg-<?php echo $col; ?>-100 text-<?php echo $col; ?>-800 font-semibold">
                            <?php echo ucwords(str_replace('_', ' ', $r->status)); ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-right">৳<?php echo number_format($r->total_amount, 2); ?></td>
                    <td class="px-4 py-3 text-right font-semibold <?php echo $r->balance_due > 0 ? 'text-orange-600' : 'text-green-600'; ?>">
                        ৳<?php echo number_format($r->balance_due, 2); ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($is_eligible): ?>
                        <a href="?order_id=<?php echo $r->id; ?>"
                           class="px-3 py-1 bg-indigo-600 text-white rounded-md text-xs font-medium hover:bg-indigo-700 whitespace-nowrap">
                            <i class="fas fa-truck mr-1"></i>Record Delivery
                        </a>
                        <?php else: ?>
                        <span class="text-xs text-gray-400 italic">Not eligible</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php elseif ($search_query): ?>
    <div class="mt-4 p-4 text-center text-gray-500 bg-gray-50 rounded-lg">
        <i class="fas fa-search text-2xl mb-2 text-gray-300"></i>
        <p>No orders found for "<strong><?php echo htmlspecialchars($search_query); ?></strong>".</p>
    </div>
    <?php endif; ?>
</div>

<?php else: /* ─── Delivery Form ─── */ ?>

<!-- Order Summary Banner -->
<div class="bg-indigo-50 border border-indigo-200 rounded-xl p-5 mb-6 flex flex-wrap justify-between items-start gap-4">
    <div>
        <p class="text-xs text-indigo-500 font-semibold uppercase tracking-wide mb-1">Recording delivery for</p>
        <h2 class="text-2xl font-bold text-indigo-900"><?php echo htmlspecialchars($selected_order->order_number); ?></h2>
        <p class="text-indigo-700"><?php echo htmlspecialchars($selected_order->customer_name); ?></p>
    </div>
    <div class="flex flex-wrap gap-4 text-sm">
        <div class="text-center">
            <p class="text-gray-500">Order Total</p>
            <p class="font-bold text-gray-900">৳<?php echo number_format($selected_order->total_amount, 2); ?></p>
        </div>
        <div class="text-center">
            <p class="text-gray-500">Delivered So Far</p>
            <p class="font-bold text-blue-600">৳<?php echo number_format($selected_order->cumulative_delivered, 2); ?></p>
        </div>
        <div class="text-center">
            <p class="text-gray-500">Balance Due</p>
            <p class="font-bold text-orange-600">৳<?php echo number_format($selected_order->balance_due, 2); ?></p>
        </div>
        <div class="text-center">
            <p class="text-gray-500">Status</p>
            <p class="font-bold text-indigo-700"><?php echo ucwords(str_replace('_', ' ', $selected_order->status)); ?></p>
        </div>
    </div>
    <a href="partial_delivery.php" class="text-xs text-indigo-600 hover:underline self-end">← Search different order</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- ── Delivery Form ── -->
    <div class="lg:col-span-2 space-y-6">

        <!-- Past Deliveries -->
        <?php if (!empty($selected_order->past_deliveries)): ?>
        <div class="bg-white rounded-xl shadow-md p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-history mr-2 text-gray-400"></i>Delivery History</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Delivery #</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Truck / Driver</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Type</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($selected_order->past_deliveries as $pd): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 py-2 font-mono text-xs"><?php echo htmlspecialchars($pd->delivery_number); ?></td>
                            <td class="px-3 py-2"><?php echo date('M j, Y', strtotime($pd->delivery_date)); ?></td>
                            <td class="px-3 py-2 text-right"><?php echo number_format($pd->total_qty_delivered, 2); ?></td>
                            <td class="px-3 py-2 text-right font-semibold">৳<?php echo number_format($pd->total_amount_delivered, 2); ?></td>
                            <td class="px-3 py-2 text-gray-600">
                                <?php echo htmlspecialchars($pd->truck_number ?: '—'); ?>
                                <?php if ($pd->driver_name): ?>
                                    / <?php echo htmlspecialchars($pd->driver_name); ?>
                                <?php endif; ?>
                            </td>
                            <td class="px-3 py-2 text-center">
                                <?php if ($pd->is_final): ?>
                                    <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded text-xs font-bold">Final</span>
                                <?php else: ?>
                                    <span class="px-2 py-0.5 bg-blue-100 text-blue-700 rounded text-xs">Partial</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- New Delivery Form -->
        <?php if ($selected_order->status !== 'delivered'): ?>
        <?php $pd_gate = getOrderGateState((int)$selected_order->id); ?>
        <?php if (!in_array($pd_gate['dispatch'], ['open', 'cleared'])): ?>
        <div class="bg-red-50 border-2 border-red-300 rounded-xl p-6">
            <div class="flex items-start gap-3">
                <i class="fas fa-lock text-2xl text-red-500 mt-1"></i>
                <div>
                    <h3 class="text-lg font-bold text-red-800">Delivery Held — Awaiting Payment Clearance</h3>
                    <p class="text-sm text-red-700 mt-1">
                        Admin has placed a dispatch hold on this order pending clearance from Accounts.
                        <?php if ($pd_gate['threshold'] !== null && $pd_gate['current'] !== null): ?>
                            <?php if (in_array($pd_gate['row']->condition_type ?? '', ['outstanding_below', 'outstanding_after_ship'])): ?>
                            Customer outstanding<?php echo ($pd_gate['row']->condition_type === 'outstanding_after_ship') ? ' (incl. this invoice)' : ''; ?> is
                            <strong>৳<?php echo number_format($pd_gate['current'], 0); ?></strong>,
                            must drop to <strong>৳<?php echo number_format($pd_gate['threshold'], 0); ?></strong>
                            <?php else: ?>
                            <strong>৳<?php echo number_format($pd_gate['current'], 0); ?></strong> received of
                            <strong>৳<?php echo number_format($pd_gate['threshold'], 0); ?></strong> required
                            <?php endif; ?>
                            <?php if ($pd_gate['shortfall'] > 0): ?>
                            — <strong class="text-red-800">৳<?php echo number_format($pd_gate['shortfall'], 0); ?> to go</strong>.
                            <?php else: ?>
                            — <strong class="text-green-700">condition met, awaiting clearance from Accounts</strong>.
                            <?php endif; ?>
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($pd_gate['row']->accounts_note)): ?>
                    <p class="text-xs text-red-600 mt-2 italic"><i class="fas fa-sticky-note mr-1"></i><?php echo htmlspecialchars($pd_gate['row']->accounts_note); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="bg-white rounded-xl shadow-md p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-plus-circle mr-2 text-indigo-500"></i>Record New Delivery</h3>

            <?php if ($preq_error): ?>
            <div class="mb-4 px-4 py-2.5 bg-red-50 border border-red-200 rounded-lg text-red-700 text-xs">
                <i class="fas fa-ban mr-1"></i><?php echo htmlspecialchars($preq_error); ?>
                <a href="approval_requests.php" class="ml-2 underline">Back to requests</a>
            </div>
            <?php elseif ($preq): ?>
            <div class="mb-4 px-4 py-3 bg-purple-50 border-2 border-purple-300 rounded-lg text-purple-900 text-xs">
                <p class="font-bold text-sm mb-1">
                    <i class="fas fa-user-check mr-1"></i>Reviewing pending request #<?php echo (int)$preq->id; ?>
                </p>
                <p>
                    Prepared by <strong><?php echo htmlspecialchars($preq_maker->display_name ?? 'staff'); ?></strong>
                    on <?php echo date('d M Y, g:i A', strtotime($preq->created_at)); ?>
                    (their limit: ৳<?php echo number_format((float)($preq->maker_limit ?? 0), 0); ?>) —
                    <?php echo htmlspecialchars($preq->summary ?? ''); ?>
                </p>
                <p class="mt-1 text-purple-700">
                    The form below is prefilled from their request. Verify quantities, adjust if needed, and submit
                    to record it — or <a href="approval_requests.php" class="underline font-semibold">reject it from the requests page</a>.
                </p>
            </div>
            <?php endif; ?>

            <form method="POST" id="deliveryForm">
                <input type="hidden" name="action" value="record_delivery">
                <input type="hidden" name="order_id" value="<?php echo $selected_order->id; ?>">
                <?php if ($preq): ?><input type="hidden" name="pending_req_id" value="<?php echo (int)$preq->id; ?>"><?php endif; ?>

                <!-- Delivery Info -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Delivery Date <span class="text-red-500">*</span></label>
                        <input type="date" name="delivery_date" value="<?php echo date('Y-m-d'); ?>" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Truck / Vehicle Number</label>
                        <input type="text" name="truck_number" placeholder="e.g. DHA-BA-1234"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Driver Name</label>
                        <input type="text" name="driver_name" placeholder="Driver's full name"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Driver Contact</label>
                        <input type="text" name="driver_contact" placeholder="+880…"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                        <textarea name="notes" rows="2" placeholder="Any notes about this delivery…"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500"></textarea>
                    </div>
                </div>

                <!-- Items Table -->
                <h4 class="font-semibold text-gray-700 mb-3">Delivery Quantities</h4>
                <div class="overflow-x-auto mb-4">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Ordered</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Delivered</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Remaining</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Unit Price</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">This Delivery</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Line Value</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200" id="deliveryItemsBody">
                            <?php foreach ($order_items as $item):
                                $variant_display = [];
                                if ($item->grade) $variant_display[] = $item->grade;
                                if ($item->weight_variant) $variant_display[] = $item->weight_variant;
                                $variant_str = implode(' - ', $variant_display);
                                $is_fully_delivered = $item->deliverable_qty <= 0;
                            ?>
                            <tr class="<?php echo $is_fully_delivered ? 'bg-gray-50 opacity-60' : 'hover:bg-indigo-50'; ?>"
                                data-item-id="<?php echo $item->id; ?>"
                                data-unit-price="<?php echo $item->unit_price; ?>"
                                data-max="<?php echo $item->deliverable_qty; ?>">
                                <td class="px-3 py-3">
                                    <p class="font-medium"><?php echo htmlspecialchars($item->product_name); ?></p>
                                    <?php if ($variant_str): ?>
                                    <p class="text-xs text-gray-500"><?php echo htmlspecialchars($variant_str); ?></p>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-3 text-right">
                                    <?php echo number_format($item->quantity, 2); ?>
                                    <span class="text-xs text-gray-400"><?php echo $item->unit_of_measure; ?></span>
                                </td>
                                <td class="px-3 py-3 text-right text-blue-600 font-semibold">
                                    <?php echo number_format($item->already_delivered, 2); ?>
                                </td>
                                <td class="px-3 py-3 text-right <?php echo $is_fully_delivered ? 'text-gray-400' : 'text-orange-600 font-semibold'; ?>">
                                    <?php echo number_format($item->deliverable_qty, 2); ?>
                                </td>
                                <td class="px-3 py-3 text-right">৳<?php echo number_format($item->unit_price, 2); ?></td>
                                <td class="px-3 py-3 text-right">
                                    <?php if (!$is_fully_delivered): ?>
                                    <input type="number"
                                           name="delivery_qty[<?php echo $item->id; ?>]"
                                           class="delivery-qty-input w-24 px-2 py-1 border border-gray-300 rounded text-right focus:ring-2 focus:ring-indigo-500"
                                           min="0"
                                           max="<?php echo $item->deliverable_qty; ?>"
                                           step="0.01"
                                           value="0"
                                           oninput="calcDeliveryLine(this)">
                                    <?php else: ?>
                                    <span class="text-xs text-green-600 font-semibold">✓ Fully delivered</span>
                                    <input type="hidden" name="delivery_qty[<?php echo $item->id; ?>]" value="0">
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-3 text-right font-semibold line-value">৳0.00</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-gray-100 font-bold">
                            <tr>
                                <td colspan="5" class="px-3 py-3 text-right">This Delivery Total:</td>
                                <td class="px-3 py-3 text-right text-indigo-700" id="thisTotalQty">0.00</td>
                                <td class="px-3 py-3 text-right text-indigo-700 text-base" id="thisTotalAmount">৳0.00</td>
                            </tr>
                            <tr class="bg-indigo-50">
                                <td colspan="5" class="px-3 py-3 text-right text-indigo-800">Projected Balance Due after delivery:</td>
                                <td colspan="2" class="px-3 py-3 text-right text-indigo-900 text-base" id="projectedBalanceDue">
                                    ৳<?php echo number_format($selected_order->balance_due, 2); ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Final Delivery Toggle -->
                <div class="flex items-center gap-3 mb-6 p-4 bg-amber-50 border border-amber-200 rounded-lg">
                    <input type="checkbox" id="isFinal" name="is_final" value="1"
                           class="w-4 h-4 text-amber-600 rounded"
                           onchange="updateFinalLabel(this)">
                    <label for="isFinal" class="font-medium text-amber-800">
                        <i class="fas fa-flag-checkered mr-1"></i>
                        Mark as <strong>Final Delivery</strong> — closes the order as Delivered
                    </label>
                </div>

                <div class="flex gap-3">
                    <button type="submit" id="submitDelivery"
                            class="flex-1 py-3 bg-indigo-600 text-white rounded-lg font-semibold hover:bg-indigo-700 transition-colors">
                        <i class="fas fa-truck mr-2"></i><span id="submitLabel">Record Partial Delivery</span>
                    </button>
                    <a href="?order_id=<?php echo $selected_order->id; ?>"
                       class="px-5 py-3 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
                        Reset
                    </a>
                </div>
            </form>
        </div>
        <?php endif; // dispatch gate ?>
        <?php else: ?>
        <div class="bg-green-50 border border-green-300 rounded-xl p-6 text-center">
            <i class="fas fa-check-circle text-4xl text-green-500 mb-3"></i>
            <h3 class="text-xl font-bold text-green-800">Order Fully Delivered</h3>
            <p class="text-green-700 mt-1">All items have been delivered. No further deliveries can be recorded.</p>
            <a href="credit_order_view.php?id=<?php echo $selected_order->id; ?>"
               class="mt-4 inline-block px-5 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm">
                View Order Details
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Sidebar ── -->
    <div class="space-y-6">

        <!-- Order Info -->
        <div class="bg-white rounded-xl shadow-md p-5">
            <h3 class="font-bold text-gray-800 mb-3"><i class="fas fa-info-circle mr-2 text-gray-400"></i>Order Info</h3>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-500">Order Date</span>
                    <span class="font-medium"><?php echo date('M j, Y', strtotime($selected_order->order_date)); ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Required Date</span>
                    <span class="font-medium"><?php echo date('M j, Y', strtotime($selected_order->required_date)); ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Branch</span>
                    <span class="font-medium"><?php echo htmlspecialchars($selected_order->branch_name ?? '—'); ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Address</span>
                    <span class="font-medium text-right max-w-[60%]"><?php echo htmlspecialchars($selected_order->shipping_address ?? '—'); ?></span>
                </div>
            </div>
        </div>

        <!-- Delivery Progress -->
        <div class="bg-white rounded-xl shadow-md p-5">
            <h3 class="font-bold text-gray-800 mb-3"><i class="fas fa-chart-pie mr-2 text-gray-400"></i>Delivery Progress</h3>
            <?php
            $pct = $selected_order->total_amount > 0
                ? min(100, ($selected_order->cumulative_delivered / $selected_order->total_amount) * 100)
                : 0;
            $pct_display = number_format($pct, 1);
            $bar_color = $pct >= 100 ? 'green' : ($pct >= 50 ? 'blue' : 'orange');
            ?>
            <div class="relative pt-1">
                <div class="flex mb-1 justify-between text-xs">
                    <span class="text-gray-500">Delivered</span>
                    <span class="font-bold text-<?php echo $bar_color; ?>-700"><?php echo $pct_display; ?>%</span>
                </div>
                <div class="overflow-hidden h-3 rounded-full bg-gray-200">
                    <div class="h-3 rounded-full bg-<?php echo $bar_color; ?>-500 transition-all"
                         style="width: <?php echo $pct_display; ?>%"></div>
                </div>
                <div class="flex justify-between mt-1 text-xs text-gray-500">
                    <span>৳<?php echo number_format($selected_order->cumulative_delivered, 0); ?> delivered</span>
                    <span>৳<?php echo number_format($selected_order->total_amount, 0); ?> total</span>
                </div>
            </div>

            <div class="mt-4 space-y-1 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-500">Deliveries Made</span>
                    <span class="font-semibold"><?php echo count($selected_order->past_deliveries); ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Remaining</span>
                    <span class="font-semibold text-orange-600">
                        ৳<?php echo number_format(max(0, $selected_order->total_amount - $selected_order->cumulative_delivered), 2); ?>
                    </span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Amount Paid</span>
                    <span class="font-semibold text-green-600">৳<?php echo number_format($selected_order->amount_paid, 2); ?></span>
                </div>
            </div>
        </div>

        <!-- Quick Links -->
        <div class="bg-white rounded-xl shadow-md p-5">
            <h3 class="font-bold text-gray-800 mb-3">Quick Links</h3>
            <div class="space-y-2">
                <a href="credit_order_view.php?id=<?php echo $selected_order->id; ?>"
                   class="block px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 text-sm text-center">
                    <i class="fas fa-eye mr-2"></i>View Order Details
                </a>
                <a href="customer_ledger.php?customer_id=<?php echo $selected_order->customer_id; ?>"
                   class="block px-4 py-2 bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 text-sm text-center">
                    <i class="fas fa-book mr-2"></i>Customer Ledger
                </a>
                <a href="returns.php?order_id=<?php echo $selected_order->id; ?>"
                   class="block px-4 py-2 bg-red-50 text-red-700 rounded-lg hover:bg-red-100 text-sm text-center">
                    <i class="fas fa-undo mr-2"></i>Record Return
                </a>
            </div>
        </div>
    </div>
</div>

<?php endif; /* end if $selected_order */ ?>

<!-- ─── Recent Deliveries List ──────────────────────────────── -->
<div class="mt-8 bg-white rounded-xl shadow-md p-6">
    <div class="flex flex-wrap justify-between items-center gap-3 mb-4">
        <h2 class="text-lg font-bold text-gray-800"><i class="fas fa-list mr-2 text-gray-400"></i>Delivery History</h2>
        <form method="GET" class="flex gap-2 items-center text-sm" <?php echo $selected_order_id ? 'style="display:none"' : ''; ?>>
            <input type="date" name="del_from" value="<?php echo htmlspecialchars($del_date_from); ?>"
                   class="px-3 py-1.5 border border-gray-300 rounded-lg">
            <span class="text-gray-400">to</span>
            <input type="date" name="del_to" value="<?php echo htmlspecialchars($del_date_to); ?>"
                   class="px-3 py-1.5 border border-gray-300 rounded-lg">
            <button type="submit" class="px-3 py-1.5 bg-gray-600 text-white rounded-lg hover:bg-gray-700">Filter</button>
        </form>
    </div>

    <?php if ($recent_deliveries): ?>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Delivery #</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order #</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Truck / Driver</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($recent_deliveries as $d): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-mono text-xs font-semibold"><?php echo htmlspecialchars($d->delivery_number); ?></td>
                    <td class="px-4 py-3 font-mono text-xs">
                        <a href="credit_order_view.php?id=<?php echo $d->order_id; ?>" class="text-blue-600 hover:underline">
                            <?php echo htmlspecialchars($d->order_number); ?>
                        </a>
                    </td>
                    <td class="px-4 py-3"><?php echo htmlspecialchars($d->customer_name); ?></td>
                    <td class="px-4 py-3"><?php echo date('M j, Y', strtotime($d->delivery_date)); ?></td>
                    <td class="px-4 py-3 text-right"><?php echo number_format($d->total_qty_delivered, 2); ?></td>
                    <td class="px-4 py-3 text-right font-semibold">৳<?php echo number_format($d->total_amount_delivered, 2); ?></td>
                    <td class="px-4 py-3 text-gray-600 text-xs">
                        <?php echo htmlspecialchars($d->truck_number ?: '—'); ?>
                        <?php if ($d->driver_name): ?> / <?php echo htmlspecialchars($d->driver_name); ?><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($d->is_final): ?>
                            <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded text-xs font-bold">Final</span>
                        <?php else: ?>
                            <span class="px-2 py-0.5 bg-blue-100 text-blue-700 rounded text-xs">Partial</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <a href="?order_id=<?php echo $d->order_id; ?>"
                           class="text-xs text-indigo-600 hover:underline">+ Add delivery</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="text-center text-gray-400 py-8">
        <i class="fas fa-truck text-4xl mb-3 opacity-30"></i>
        <p>No deliveries found for the selected date range.</p>
    </div>
    <?php endif; ?>
</div>

</div>

<script>
/* ─── Real-time delivery total calculation ─── */
const orderTotal     = <?php echo json_encode((float)($selected_order->total_amount ?? 0)); ?>;
const alreadyDeliv   = <?php echo json_encode((float)($selected_order->cumulative_delivered ?? 0)); ?>;
const amountPaid     = <?php echo json_encode((float)($selected_order->amount_paid ?? 0)); ?>;

function calcDeliveryLine(input) {
    const row       = input.closest('tr');
    const unitPrice = parseFloat(row.dataset.unitPrice) || 0;
    const maxQty    = parseFloat(row.dataset.max) || 0;
    let   qty       = parseFloat(input.value) || 0;

    // Clamp to max
    if (qty > maxQty) { qty = maxQty; input.value = maxQty; }
    if (qty < 0)      { qty = 0;      input.value = 0; }

    const lineVal = qty * unitPrice;
    row.querySelector('.line-value').textContent = '৳' + lineVal.toFixed(2);
    updateDeliveryTotals();
}

function updateDeliveryTotals() {
    let totalQty = 0;
    let totalAmt = 0;

    document.querySelectorAll('.delivery-qty-input').forEach(inp => {
        const row       = inp.closest('tr');
        const unitPrice = parseFloat(row.dataset.unitPrice) || 0;
        const qty       = parseFloat(inp.value) || 0;
        totalQty += qty;
        totalAmt += qty * unitPrice;
    });

    document.getElementById('thisTotalQty').textContent    = totalQty.toFixed(2);
    document.getElementById('thisTotalAmount').textContent = '৳' + totalAmt.toFixed(2);

    // Projected balance_due = (already delivered + this delivery) - amount_paid
    const cumulative   = alreadyDeliv + totalAmt;
    const projBalance  = Math.max(0, cumulative - amountPaid);
    document.getElementById('projectedBalanceDue').textContent = '৳' + projBalance.toFixed(2);
}

function updateFinalLabel(cb) {
    const label = document.getElementById('submitLabel');
    label.textContent = cb.checked ? 'Record Final Delivery (Close Order)' : 'Record Partial Delivery';
}

/* Quick-fill: set all items to their remaining deliverable qty */
function fillAllRemaining() {
    document.querySelectorAll('.delivery-qty-input').forEach(inp => {
        const row = inp.closest('tr');
        inp.value = row.dataset.max;
        const lineVal = (parseFloat(row.dataset.max) || 0) * (parseFloat(row.dataset.unitPrice) || 0);
        row.querySelector('.line-value').textContent = '৳' + lineVal.toFixed(2);
    });
    updateDeliveryTotals();
}

<?php if ($preq): $pp = $preq->payload_arr; ?>
/* Maker/checker review: prefill the delivery form from request #<?php echo (int)$preq->id; ?> */
document.addEventListener('DOMContentLoaded', () => {
    const set = (sel, val) => { const el = document.querySelector(sel); if (el) el.value = val; };
    set('input[name="delivery_date"]',   <?php echo json_encode($pp['delivery_date'] ?? date('Y-m-d')); ?>);
    set('input[name="truck_number"]',    <?php echo json_encode($pp['truck_number'] ?? ''); ?>);
    set('input[name="driver_name"]',     <?php echo json_encode($pp['driver_name'] ?? ''); ?>);
    set('input[name="driver_contact"]',  <?php echo json_encode($pp['driver_contact'] ?? ''); ?>);
    set('textarea[name="notes"]',        <?php echo json_encode($pp['notes'] ?? ''); ?>);
    <?php if (!empty($pp['is_final'])): ?>
    const fin = document.getElementById('isFinal'); if (fin) fin.checked = true;
    <?php endif; ?>
    const qtys = <?php echo json_encode((object)($pp['delivery_qty'] ?? [])); ?>;
    Object.entries(qtys).forEach(([itemId, q]) => {
        if (!(parseFloat(q) > 0)) return;
        const el = document.querySelector('input.delivery-qty-input[name="delivery_qty[' + itemId + ']"]');
        if (el) { el.value = q; el.dispatchEvent(new Event('input')); }
    });
    if (typeof updateDeliveryTotals === 'function') updateDeliveryTotals();
});
<?php endif; ?>
</script>

<?php require_once '../templates/footer.php'; ?>
