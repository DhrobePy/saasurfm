<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'Accounts', 'admin', 'production manager-srg', 'production manager-demra'];
restrict_access($allowed_roles, 'production', 'credit_production');

global $db;
$currentUser = getCurrentUser();
$user_id   = $currentUser['id']   ?? null;
$user_role = $currentUser['role'] ?? '';
$pageTitle = 'Production Management';

$is_admin = in_array($user_role, ['Superadmin', 'admin']);

// Gate table must exist before the POST transaction (DDL would implicitly commit)
ensureApprovalGateTables();

// ── Per-action capability flags ──────────────────────────────────────────────
$can_update_status     = $is_admin || userCanPageAction('production', 'credit_production', 'can_update_status');
$can_view_orders       = $is_admin || userCanPageAction('production', 'credit_production', 'can_view_orders');
$can_collect_payment   = $is_admin || userCanPageAction('production', 'credit_production', 'can_collect_payment');
$can_view_payments     = $is_admin || userCanPageAction('production', 'credit_production', 'can_view_payments');
$can_edit_orders       = $is_admin || userCanPageAction('production', 'credit_production', 'can_edit_orders');
$can_partial_delivery  = $is_admin || userCanPageAction('production', 'credit_production', 'can_partial_delivery');
$can_return            = $is_admin || userCanPageAction('production', 'credit_production', 'can_return');
$can_production_report = $is_admin || userCanPageAction('production', 'credit_production', 'can_production_report');

// ── Branch detection ─────────────────────────────────────────────────────────
$user_branch = null;
if (!$is_admin) {
    $emp = $db->query("SELECT branch_id FROM employees WHERE user_id = ?", [$user_id])->first();
    if ($emp && $emp->branch_id) {
        $user_branch = $emp->branch_id;
    } else {
        $ur = $db->query("SELECT branch_id FROM users WHERE id = ?", [$user_id])->first();
        if ($ur && isset($ur->branch_id)) $user_branch = $ur->branch_id;
    }
}

$branch_filter = '';
$branch_params = [];
if (!$is_admin && $user_branch) {
    $branch_filter = 'AND co.assigned_branch_id = ?';
    $branch_params[] = $user_branch;
}

// Admin branch picker
$filter_branch_id   = 0;
$filter_branch_name = '';
$all_branches       = [];
if ($is_admin) {
    $all_branches     = $db->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name")->results();
    $filter_branch_id = (int)($_GET['branch_id'] ?? 0);
    if ($filter_branch_id > 0) {
        $branch_filter = 'AND co.assigned_branch_id = ?';
        $branch_params = [$filter_branch_id];
        foreach ($all_branches as $br) {
            if ((int)$br->id === $filter_branch_id) { $filter_branch_name = $br->name; break; }
        }
    }
}

// ── Production queue query ───────────────────────────────────────────────────
$orders = $db->query(
    "SELECT co.*,
            c.name as customer_name,
            c.phone_number as customer_phone,
            b.name as branch_name,
            u.display_name as created_by_name,
            ps.scheduled_date,
            ps.production_started_at,
            ps.production_completed_at,
            ps.priority_order
     FROM credit_orders co
     JOIN customers c ON co.customer_id = c.id
     LEFT JOIN branches b ON co.assigned_branch_id = b.id
     LEFT JOIN users u ON co.created_by_user_id = u.id
     LEFT JOIN production_schedule ps ON co.id = ps.order_id
     WHERE co.status IN ('approved','in_production','produced','ready_to_ship')
       AND co.assigned_branch_id IS NOT NULL
       $branch_filter
     ORDER BY
        CASE co.status
            WHEN 'approved'      THEN 1
            WHEN 'in_production' THEN 2
            WHEN 'produced'      THEN 3
            WHEN 'ready_to_ship' THEN 4
        END,
        ps.priority_order ASC,
        co.required_date ASC",
    $branch_params
)->results();

// ── Handle POST actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $order_id = (int)($_POST['order_id'] ?? 0);

    try {
        $db->getPdo()->beginTransaction();
        $order = $db->query("SELECT * FROM credit_orders WHERE id = ?", [$order_id])->first();
        if (!$order) throw new Exception('Order not found');

        $old_status      = $order->status;
        $new_status      = $old_status;
        $workflow_action = '';

        if ($action === 'start' && $can_update_status) {
            // Server-side production gate — admin may have held production at approval
            $gate = getOrderGateState($order_id);
            if ($gate['production'] === 'held') {
                $note = $gate['row']->production_note ?? '';
                throw new Exception('Production is HELD for this order by admin instruction.'
                    . ($note !== '' ? ' Note: ' . $note : '') . ' Contact admin to release the hold.');
            }
            $new_status      = 'in_production';
            $workflow_action = 'start_production';
            $db->query("UPDATE credit_orders SET status = ? WHERE id = ?", [$new_status, $order_id]);
            if ($db->error()) throw new Exception('Failed to update the order status.');
            $sched = $db->query("SELECT id FROM production_schedule WHERE order_id = ?", [$order_id])->first();
            if ($sched) {
                $db->query("UPDATE production_schedule SET production_started_at = NOW(), status = 'in_progress' WHERE order_id = ?", [$order_id]);
                if ($db->error()) throw new Exception('Failed to update the production schedule.');
            } else {
                // branch_id and scheduled_date are NOT NULL with no default (see
                // production_schedule's schema) — this insert omitted both, so it
                // has always silently failed via Database::insert()'s "return false,
                // never throw" behavior. Newly surfaced by the error-check just added
                // above; fixed at the root by supplying both, matching how
                // admin/ops_ajax.php correctly populates this same table elsewhere.
                if (!$db->insert('production_schedule', [
                    'order_id' => $order_id, 'branch_id' => $order->assigned_branch_id,
                    'scheduled_date' => date('Y-m-d'), 'production_started_at' => date('Y-m-d H:i:s'), 'status' => 'in_progress',
                ])) {
                    throw new Exception('Failed to create the production schedule.');
                }
            }
            $_SESSION['success_flash'] = 'Production started for ' . $order->order_number;

            if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
                try {
                    require_once '../core/classes/TelegramNotifier.php';
                    $telegram    = new TelegramNotifier(TELEGRAM_BOT_TOKEN, getTelegramChatId('production'));
                    $cust_info   = $db->query("SELECT name, phone_number FROM customers WHERE id = ?", [$order->customer_id])->first();
                    $branch_info = $db->query("SELECT name FROM branches WHERE id = ?", [$order->assigned_branch_id])->first();
                    $user_info   = $db->query("SELECT display_name FROM users WHERE id = ?", [$user_id])->first();
                    $items       = $db->query(
                        "SELECT coi.*, p.base_name as product_name, pv.grade, pv.weight_variant, pv.unit_of_measure
                         FROM credit_order_items coi JOIN products p ON coi.product_id = p.id
                         LEFT JOIN product_variants pv ON coi.variant_id = pv.id
                         WHERE coi.order_id = ?", [$order_id]
                    )->results();
                    $notification_items = [];
                    foreach ($items as $item) {
                        $notification_items[] = [
                            'product_name' => $item->product_name,
                            'variant_name' => trim(($item->grade ?? '') . ' ' . ($item->weight_variant ?? '')),
                            'quantity'     => floatval($item->quantity),
                            'unit'         => $item->unit_of_measure ?? 'pcs',
                        ];
                    }
                    $telegram->sendProductionStartedNotification([
                        'order_number'   => $order->order_number,
                        'started_at'     => date('d M Y, h:i A'),
                        'customer_name'  => $cust_info ? $cust_info->name : 'Unknown',
                        'customer_phone' => $cust_info ? $cust_info->phone_number : 'N/A',
                        'branch_name'    => $branch_info ? $branch_info->name : 'Unknown',
                        'required_date'  => date('d M Y', strtotime($order->required_date)),
                        'items'          => $notification_items,
                        'total_amount'   => floatval($order->total_amount),
                        'started_by'     => $user_info ? $user_info->display_name : 'Unknown',
                    ]);
                } catch (Exception $e) { error_log('Telegram prod started: ' . $e->getMessage()); }
            }

        } elseif ($action === 'complete' && $can_update_status) {
            $new_status      = 'produced';
            $workflow_action = 'complete_production';
            $db->query("UPDATE credit_orders SET status = ? WHERE id = ?", [$new_status, $order_id]);
            if ($db->error()) throw new Exception('Failed to update the order status.');
            $db->query("UPDATE production_schedule SET production_completed_at = NOW(), status = 'completed' WHERE order_id = ?", [$order_id]);
            if ($db->error()) throw new Exception('Failed to update the production schedule.');
            $_SESSION['success_flash'] = 'Production completed for ' . $order->order_number;

            if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
                try {
                    require_once '../core/classes/TelegramNotifier.php';
                    $telegram    = new TelegramNotifier(TELEGRAM_BOT_TOKEN, getTelegramChatId('production'));
                    $cust_info   = $db->query("SELECT name, phone_number FROM customers WHERE id = ?", [$order->customer_id])->first();
                    $branch_info = $db->query("SELECT name FROM branches WHERE id = ?", [$order->assigned_branch_id])->first();
                    $user_info   = $db->query("SELECT display_name FROM users WHERE id = ?", [$user_id])->first();
                    $sched       = $db->query("SELECT production_started_at FROM production_schedule WHERE order_id = ?", [$order_id])->first();
                    $duration    = '';
                    if ($sched && $sched->production_started_at) {
                        $interval = (new DateTime($sched->production_started_at))->diff(new DateTime());
                        $duration = $interval->d > 0
                            ? $interval->d . ' day(s) ' . $interval->h . ' hour(s)'
                            : ($interval->h > 0 ? $interval->h . ' hour(s) ' . $interval->i . ' min(s)' : $interval->i . ' minute(s)');
                    }
                    $items = $db->query(
                        "SELECT coi.*, p.base_name as product_name, pv.grade, pv.weight_variant, pv.unit_of_measure
                         FROM credit_order_items coi JOIN products p ON coi.product_id = p.id
                         LEFT JOIN product_variants pv ON coi.variant_id = pv.id
                         WHERE coi.order_id = ?", [$order_id]
                    )->results();
                    $notification_items = [];
                    foreach ($items as $item) {
                        $notification_items[] = [
                            'product_name' => $item->product_name,
                            'variant_name' => trim(($item->grade ?? '') . ' ' . ($item->weight_variant ?? '')),
                            'quantity'     => floatval($item->quantity),
                            'unit'         => $item->unit_of_measure ?? 'pcs',
                        ];
                    }
                    $telegram->sendProductionCompletedNotification([
                        'order_number'   => $order->order_number,
                        'completed_at'   => date('d M Y, h:i A'),
                        'customer_name'  => $cust_info ? $cust_info->name : 'Unknown',
                        'customer_phone' => $cust_info ? $cust_info->phone_number : 'N/A',
                        'branch_name'    => $branch_info ? $branch_info->name : 'Unknown',
                        'required_date'  => date('d M Y', strtotime($order->required_date)),
                        'items'          => $notification_items,
                        'total_amount'   => floatval($order->total_amount),
                        'duration'       => $duration,
                        'completed_by'   => $user_info ? $user_info->display_name : 'Unknown',
                    ]);
                } catch (Exception $e) { error_log('Telegram prod completed: ' . $e->getMessage()); }
            }

        } elseif ($action === 'ready' && $can_update_status) {
            $new_status      = 'ready_to_ship';
            $workflow_action = 'mark_ready_to_ship';
            try { $db->query("CALL sp_calculate_order_weight(?)", [$order_id]); } catch (Exception $e) { error_log("Weight calc: " . $e->getMessage()); }
            $db->query("UPDATE credit_orders SET status = ? WHERE id = ?", [$new_status, $order_id]);
            if ($db->error()) throw new Exception('Failed to update the order status.');
            try { $db->query("CALL sp_find_consolidation_opportunities(?)", [$order_id]); } catch (Exception $e) { error_log("Consolidation: " . $e->getMessage()); }
            $_SESSION['success_flash'] = 'Order marked ready to ship: ' . $order->order_number;

            if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
                try {
                    require_once '../core/classes/TelegramNotifier.php';
                    $telegram    = new TelegramNotifier(TELEGRAM_BOT_TOKEN, getTelegramChatId('production'));
                    $cust_info   = $db->query("SELECT name, phone_number FROM customers WHERE id = ?", [$order->customer_id])->first();
                    $branch_info = $db->query("SELECT name FROM branches WHERE id = ?", [$order->assigned_branch_id])->first();
                    $user_info   = $db->query("SELECT display_name FROM users WHERE id = ?", [$user_id])->first();
                    $items       = $db->query(
                        "SELECT coi.*, p.base_name as product_name, pv.grade, pv.weight_variant, pv.unit_of_measure
                         FROM credit_order_items coi JOIN products p ON coi.product_id = p.id
                         LEFT JOIN product_variants pv ON coi.variant_id = pv.id
                         WHERE coi.order_id = ?", [$order_id]
                    )->results();
                    $notification_items = [];
                    foreach ($items as $item) {
                        $notification_items[] = [
                            'product_name' => $item->product_name,
                            'variant_name' => trim(($item->grade ?? '') . ' ' . ($item->weight_variant ?? '')),
                            'quantity'     => floatval($item->quantity),
                            'unit'         => $item->unit_of_measure ?? 'pcs',
                        ];
                    }
                    $telegram->sendReadyToShipNotification([
                        'order_number'     => $order->order_number,
                        'ready_at'         => date('d M Y, h:i A'),
                        'customer_name'    => $cust_info ? $cust_info->name : 'Unknown',
                        'customer_phone'   => $cust_info ? $cust_info->phone_number : 'N/A',
                        'shipping_address' => $order->shipping_address ?? '',
                        'branch_name'      => $branch_info ? $branch_info->name : 'Unknown',
                        'items'            => $notification_items,
                        'total_amount'     => floatval($order->total_amount),
                        'balance_due'      => floatval($order->balance_due),
                        'marked_by'        => $user_info ? $user_info->display_name : 'Unknown',
                    ]);
                } catch (Exception $e) { error_log('Telegram ready to ship: ' . $e->getMessage()); }
            }

        } elseif ($action === 'update_priority' && $is_admin) {
            $priority = (int)$_POST['priority'];
            $sched = $db->query("SELECT id FROM production_schedule WHERE order_id = ?", [$order_id])->first();
            if ($sched) {
                $db->query("UPDATE production_schedule SET priority_order = ? WHERE order_id = ?", [$priority, $order_id]);
                if ($db->error()) throw new Exception('Failed to update the production schedule priority.');
            } else {
                // Same missing-NOT-NULL-columns bug as the 'start' action above.
                if (!$db->insert('production_schedule', [
                    'order_id' => $order_id, 'branch_id' => $order->assigned_branch_id,
                    'scheduled_date' => date('Y-m-d'), 'priority_order' => $priority,
                ])) {
                    throw new Exception('Failed to create the production schedule.');
                }
            }
            $_SESSION['success_flash'] = 'Priority updated';

            if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
                try {
                    require_once '../core/classes/TelegramNotifier.php';
                    $telegram    = new TelegramNotifier(TELEGRAM_BOT_TOKEN, getTelegramChatId('production'));
                    $cust_info   = $db->query("SELECT name FROM customers WHERE id = ?", [$order->customer_id])->first();
                    $branch_info = $db->query("SELECT name FROM branches WHERE id = ?", [$order->assigned_branch_id])->first();
                    $user_info   = $db->query("SELECT display_name FROM users WHERE id = ?", [$user_id])->first();
                    $telegram->sendPriorityUpdateNotification([
                        'order_number'  => $order->order_number,
                        'new_priority'  => $priority,
                        'updated_at'    => date('d M Y, h:i A'),
                        'customer_name' => $cust_info ? $cust_info->name : 'Unknown',
                        'branch_name'   => $branch_info ? $branch_info->name : 'Unknown',
                        'updated_by'    => $user_info ? $user_info->display_name : 'Unknown',
                    ]);
                } catch (Exception $e) { error_log('Telegram priority: ' . $e->getMessage()); }
            }
        } else {
            throw new Exception('Invalid action or insufficient permission');
        }

        if ($workflow_action) {
            $db->insert('credit_order_workflow', [
                'order_id'             => $order_id,
                'from_status'          => $old_status,
                'to_status'            => $new_status,
                'action'               => $workflow_action,
                'performed_by_user_id' => $user_id,
                'comments'             => 'Production status updated',
            ]);
        }

        $db->getPdo()->commit();

        // Audit trail — after commit, matching this codebase's DDL-before-
        // transaction rule (AuditLogger's own table-ensure runs on first use
        // each request and must never land inside an open transaction).
        if ($workflow_action) {
            auditLogOrder($workflow_action, $order_id, $order->order_number,
                "Order {$order->order_number}: {$old_status} → {$new_status} ({$workflow_action})",
                ['from_status' => $old_status, 'to_status' => $new_status]
            );
        }

        header('Location: credit_production.php' . ($filter_branch_id ? '?branch_id=' . $filter_branch_id : ''));
        exit();

    } catch (Exception $e) {
        if ($db->getPdo()->inTransaction()) $db->getPdo()->rollBack();
        $_SESSION['error_flash'] = $e->getMessage();
        header('Location: credit_production.php');
        exit();
    }
}

// ── Bulk-fetch production holds for displayed orders ─────────────────────────
$prod_holds = [];   // order_id => production_note
if (!empty($orders)) {
    try {
        $hold_ids = array_map(fn($o) => (int)$o->id, $orders);
        $ph_ph    = implode(',', array_fill(0, count($hold_ids), '?'));
        $hold_rows = $db->query(
            "SELECT order_id, production_note FROM order_approval_conditions
             WHERE order_id IN ($ph_ph) AND production_hold = 1 AND production_released_at IS NULL",
            $hold_ids
        )->results();
        foreach ($hold_rows as $hr) {
            $prod_holds[(int)$hr->order_id] = $hr->production_note ?? '';
        }
    } catch (Exception $e) {
        error_log('Production holds fetch: ' . $e->getMessage());
    }
}

// ── Pre-fetch items for production queue ─────────────────────────────────────
$all_items_by_order = [];
if (!empty($orders)) {
    $order_ids    = array_map(fn($o) => (int)$o->id, $orders);
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $flat = $db->query(
        "SELECT coi.order_id, coi.quantity, coi.unit_price, coi.line_total,
                p.base_name AS product_name, pv.grade, pv.weight_variant, pv.unit_of_measure
         FROM credit_order_items coi
         JOIN products p ON coi.product_id = p.id
         LEFT JOIN product_variants pv ON coi.variant_id = pv.id
         WHERE coi.order_id IN ($placeholders)
         ORDER BY coi.order_id, coi.id",
        $order_ids
    )->results();
    foreach ($flat as $item) {
        $all_items_by_order[$item->order_id][] = $item;
    }
}

// ── "All Orders" tab data ────────────────────────────────────────────────────
$all_orders  = [];
$ao_status   = trim($_GET['ao_status']   ?? '');
$ao_from     = trim($_GET['ao_from']     ?? '');
$ao_to       = trim($_GET['ao_to']       ?? '');
$ao_product  = trim($_GET['ao_product']  ?? '');
$ao_customer = trim($_GET['ao_customer'] ?? '');

if ($can_view_orders) {
    $ao_where  = ['co.assigned_branch_id IS NOT NULL'];
    $ao_params = [];

    if (!$is_admin && $user_branch) {
        $ao_where[]  = 'co.assigned_branch_id = ?';
        $ao_params[] = $user_branch;
    } elseif ($is_admin && $filter_branch_id > 0) {
        $ao_where[]  = 'co.assigned_branch_id = ?';
        $ao_params[] = $filter_branch_id;
    }

    if ($ao_status   !== '') { $ao_where[] = 'co.status = ?';      $ao_params[] = $ao_status; }
    if ($ao_from     !== '') { $ao_where[] = 'co.order_date >= ?'; $ao_params[] = $ao_from; }
    if ($ao_to       !== '') { $ao_where[] = 'co.order_date <= ?'; $ao_params[] = $ao_to; }
    if ($ao_customer !== '') { $ao_where[] = 'c.name LIKE ?';      $ao_params[] = '%' . $ao_customer . '%'; }

    $ao_join = '';
    if ($ao_product !== '') {
        $ao_join = "JOIN credit_order_items _coi ON _coi.order_id = co.id
                    JOIN products _p ON _coi.product_id = _p.id AND _p.base_name LIKE ?";
        array_unshift($ao_params, '%' . $ao_product . '%');
    }

    try {
        $all_orders = $db->query(
            "SELECT co.id, co.order_number, co.order_date, co.status, co.total_amount, co.balance_due,
                    c.name AS customer_name, c.phone_number AS customer_phone, b.name AS branch_name
             FROM credit_orders co
             JOIN customers c ON co.customer_id = c.id
             LEFT JOIN branches b ON co.assigned_branch_id = b.id
             $ao_join
             WHERE " . implode(' AND ', $ao_where) . "
             GROUP BY co.id
             ORDER BY co.order_date DESC, co.id DESC
             LIMIT 200",
            $ao_params
        )->results();
    } catch (Exception $e) {
        error_log('All orders query: ' . $e->getMessage());
        $all_orders = [];
    }
}

// ── Production Totals ────────────────────────────────────────────────────────
$prod_totals_date    = [];
$prod_totals_product = [];
$pt_from = trim($_GET['pt_from'] ?? date('Y-m-01'));
$pt_to   = trim($_GET['pt_to']   ?? date('Y-m-d'));

if ($can_production_report) {
    $pt_params = [$pt_from, $pt_to];
    $pt_branch = '';
    if (!$is_admin && $user_branch) { $pt_branch = 'AND co.assigned_branch_id = ?'; $pt_params[] = $user_branch; }
    elseif ($is_admin && $filter_branch_id > 0) { $pt_branch = 'AND co.assigned_branch_id = ?'; $pt_params[] = $filter_branch_id; }

    // Query by order_date for orders that have reached production-complete or beyond
    try {
        $prod_totals_date = $db->query(
            "SELECT DATE(co.order_date) AS prod_date,
                    COUNT(DISTINCT co.id) AS total_orders,
                    SUM(coi.quantity) AS total_qty,
                    SUM(coi.line_total) AS total_value
             FROM credit_orders co
             JOIN credit_order_items coi ON coi.order_id = co.id
             WHERE co.status IN ('produced','ready_to_ship','dispatched','delivered')
               AND co.order_date BETWEEN ? AND ?
               $pt_branch
             GROUP BY DATE(co.order_date)
             ORDER BY prod_date DESC",
            $pt_params
        )->results();

        $prod_totals_product = $db->query(
            "SELECT p.base_name AS product_name,
                    SUM(coi.quantity) AS total_qty,
                    SUM(coi.line_total) AS total_value,
                    COUNT(DISTINCT co.id) AS total_orders
             FROM credit_orders co
             JOIN credit_order_items coi ON coi.order_id = co.id
             JOIN products p ON coi.product_id = p.id
             WHERE co.status IN ('produced','ready_to_ship','dispatched','delivered')
               AND co.order_date BETWEEN ? AND ?
               $pt_branch
             GROUP BY p.id, p.base_name
             ORDER BY total_qty DESC",
            $pt_params
        )->results();
    } catch (Exception $e) {
        error_log('Production totals: ' . $e->getMessage());
    }
}

// ── Payment History ──────────────────────────────────────────────────────────
$ph_records = [];
$ph_from = trim($_GET['ph_from'] ?? date('Y-m-01'));
$ph_to   = trim($_GET['ph_to']   ?? date('Y-m-d'));

if ($can_view_payments) {
    $ph_params = [$ph_from, $ph_to];

    // customer_payments is keyed by customer_id; orders linked via payment_allocations
    // Branch filter is not directly available on customer_payments — skip it here
    try {
        $ph_records = $db->query(
            "SELECT cp.id, cp.payment_date, cp.amount, cp.payment_method, cp.reference_number, cp.notes,
                    c.name AS customer_name, c.phone_number AS customer_phone,
                    u.display_name AS collected_by,
                    (SELECT GROUP_CONCAT(co.order_number ORDER BY co.id SEPARATOR ', ')
                     FROM payment_allocations pa
                     JOIN credit_orders co ON pa.order_id = co.id
                     WHERE pa.payment_id = cp.id) AS order_numbers
             FROM customer_payments cp
             JOIN customers c ON cp.customer_id = c.id
             LEFT JOIN users u ON cp.created_by_user_id = u.id
             WHERE cp.payment_date BETWEEN ? AND ?
             ORDER BY cp.payment_date DESC, cp.id DESC
             LIMIT 200",
            $ph_params
        )->results();
    } catch (Exception $e) {
        error_log('Payment history: ' . $e->getMessage());
    }
}

// ── Stats ────────────────────────────────────────────────────────────────────
try {
    $stats = [
        'approved'      => (int)($db->query("SELECT COUNT(*) as c FROM credit_orders co WHERE co.status='approved'      $branch_filter", $branch_params)->first()->c ?? 0),
        'in_production' => (int)($db->query("SELECT COUNT(*) as c FROM credit_orders co WHERE co.status='in_production' $branch_filter", $branch_params)->first()->c ?? 0),
        'produced'      => (int)($db->query("SELECT COUNT(*) as c FROM credit_orders co WHERE co.status='produced'      $branch_filter", $branch_params)->first()->c ?? 0),
        'ready_to_ship' => (int)($db->query("SELECT COUNT(*) as c FROM credit_orders co WHERE co.status='ready_to_ship' $branch_filter", $branch_params)->first()->c ?? 0),
    ];
} catch (Exception $e) {
    $stats = ['approved' => 0, 'in_production' => 0, 'produced' => 0, 'ready_to_ship' => 0];
}

// Active tab
$active_tab = $_GET['tab'] ?? 'queue';
if (!in_array($active_tab, ['queue', 'orders', 'report', 'payments'])) $active_tab = 'queue';
if ($active_tab === 'orders'   && !$can_view_orders)       $active_tab = 'queue';
if ($active_tab === 'report'   && !$can_production_report) $active_tab = 'queue';
if ($active_tab === 'payments' && !$can_view_payments)     $active_tab = 'queue';

require_once '../templates/header.php';

$status_cls = [
    'pending_approval' => 'bg-yellow-100 text-yellow-800',
    'approved'         => 'bg-blue-100 text-blue-800',
    'in_production'    => 'bg-purple-100 text-purple-800',
    'produced'         => 'bg-green-100 text-green-800',
    'ready_to_ship'    => 'bg-orange-100 text-orange-800',
    'dispatched'       => 'bg-teal-100 text-teal-800',
    'delivered'        => 'bg-emerald-100 text-emerald-800',
    'cancelled'        => 'bg-red-100 text-red-800',
];
$status_labels = [
    'pending_approval' => 'Pending Approval',
    'approved'         => 'Approved',
    'in_production'    => 'In Production',
    'produced'         => 'Produced',
    'ready_to_ship'    => 'Ready to Ship',
    'dispatched'       => 'Dispatched',
    'delivered'        => 'Delivered',
    'cancelled'        => 'Cancelled',
];
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-4">

<!-- ── Header ───────────────────────────────────────────────────────────────── -->
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">
            <?php echo $pageTitle; ?>
            <?php if ($filter_branch_name): ?>
            <span class="ml-2 text-sm font-normal text-blue-600">— <?php echo htmlspecialchars($filter_branch_name); ?></span>
            <?php endif; ?>
        </h1>
        <p class="text-xs text-gray-500 mt-0.5">Prioritized by status → priority # → required date</p>
    </div>
    <div class="flex gap-2 flex-wrap items-center">
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-blue-100 text-blue-800 text-xs font-semibold">
            <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span><?php echo $stats['approved']; ?> Pending
        </span>
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-purple-100 text-purple-800 text-xs font-semibold">
            <span class="w-1.5 h-1.5 rounded-full bg-purple-500"></span><?php echo $stats['in_production']; ?> In Production
        </span>
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-green-100 text-green-800 text-xs font-semibold">
            <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span><?php echo $stats['produced']; ?> Produced
        </span>
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-orange-100 text-orange-800 text-xs font-semibold">
            <span class="w-1.5 h-1.5 rounded-full bg-orange-500"></span><?php echo $stats['ready_to_ship']; ?> Ready
        </span>
    </div>
</div>

<!-- Alerts -->
<?php if (isset($_SESSION['error_flash'])): ?>
<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 mb-4 rounded-lg text-sm">
    <strong>Error:</strong> <?php echo htmlspecialchars($_SESSION['error_flash']); unset($_SESSION['error_flash']); ?>
</div>
<?php endif; ?>
<?php if (isset($_SESSION['success_flash'])): ?>
<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 mb-4 rounded-lg text-sm">
    <i class="fas fa-check-circle mr-1"></i> <?php echo htmlspecialchars($_SESSION['success_flash']); unset($_SESSION['success_flash']); ?>
</div>
<?php endif; ?>

<!-- Branch filter (admin only) -->
<?php if ($is_admin && !empty($all_branches)): ?>
<form method="GET" class="flex flex-wrap items-end gap-2 mb-4 bg-white rounded-lg border border-gray-200 shadow-sm px-4 py-3">
    <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
    <div class="flex flex-col gap-0.5">
        <label class="text-xs text-gray-500 font-medium">Factory / Branch</label>
        <select name="branch_id" class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500 min-w-[180px]">
            <option value="0">All Factories</option>
            <?php foreach ($all_branches as $br): ?>
            <option value="<?php echo $br->id; ?>" <?php echo $filter_branch_id === (int)$br->id ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($br->name); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded hover:bg-blue-700 transition-colors cursor-pointer">
        <i class="fas fa-filter mr-1"></i>Apply
    </button>
    <?php if ($filter_branch_id > 0): ?>
    <a href="credit_production.php?tab=<?php echo $active_tab; ?>"
       class="px-3 py-1.5 bg-gray-100 text-gray-600 text-xs rounded hover:bg-gray-200 transition-colors cursor-pointer">
        <i class="fas fa-times mr-1"></i>All
    </a>
    <?php endif; ?>
</form>
<?php endif; ?>

<!-- ── Tab Bar ───────────────────────────────────────────────────────────────── -->
<?php
$tabs = [['key' => 'queue', 'label' => 'Production Queue', 'icon' => 'fa-industry']];
if ($can_view_orders)       $tabs[] = ['key' => 'orders',   'label' => 'All Orders',        'icon' => 'fa-list-alt'];
if ($can_production_report) $tabs[] = ['key' => 'report',   'label' => 'Production Totals', 'icon' => 'fa-chart-bar'];
if ($can_view_payments)     $tabs[] = ['key' => 'payments', 'label' => 'Payment History',   'icon' => 'fa-money-bill'];
?>
<?php if (count($tabs) > 1): ?>
<div class="flex gap-1 mb-4 border-b border-gray-200">
    <?php foreach ($tabs as $tab): ?>
    <a href="?tab=<?php echo $tab['key']; ?><?php echo $filter_branch_id ? '&branch_id=' . $filter_branch_id : ''; ?>"
       class="flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium rounded-t-lg border border-b-0 -mb-px transition-colors cursor-pointer
              <?php echo $active_tab === $tab['key']
                    ? 'bg-white border-gray-200 text-blue-700 border-b-white'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50 mb-0'; ?>">
        <i class="fas <?php echo $tab['icon']; ?> text-xs"></i>
        <?php echo $tab['label']; ?>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════════
     TAB: PRODUCTION QUEUE
══════════════════════════════════════════════════════════ -->
<div class="<?php echo $active_tab !== 'queue' ? 'hidden' : ''; ?>">

<?php if (!empty($orders)): ?>
<div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs">
        <thead>
            <tr class="bg-gray-50 border-b border-gray-200 text-left">
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap text-center">#</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Order</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Customer · Branch</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Items</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap text-right">Amount</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Req. Date</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Status</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Timeline</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap text-center">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
        <?php foreach ($orders as $idx => $order):
            $items        = $all_items_by_order[$order->id] ?? [];
            $items_count  = count($items);
            $first_item   = $items[0]->product_name ?? '';
            $is_overdue   = $order->required_date &&
                            strtotime($order->required_date) < strtotime('today') &&
                            in_array($order->status, ['approved', 'in_production']);
            $priority_val = $order->priority_order ?? ($idx + 1);
        ?>
        <tr class="hover:bg-blue-50/30 transition-colors">
            <!-- Priority # -->
            <td class="px-3 py-2.5 text-center whitespace-nowrap">
                <?php if ($is_admin): ?>
                <form method="POST" class="inline-flex items-center gap-1">
                    <input type="hidden" name="order_id" value="<?php echo $order->id; ?>">
                    <input type="number" name="priority" value="<?php echo $priority_val; ?>" min="1"
                           class="w-10 px-1 py-0.5 border border-gray-300 rounded text-xs text-center focus:ring-1 focus:ring-blue-500">
                    <button type="submit" name="action" value="update_priority"
                            class="text-blue-600 hover:text-blue-800 cursor-pointer" title="Save priority">
                        <i class="fas fa-save text-xs"></i>
                    </button>
                </form>
                <?php else: ?>
                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-gray-200 text-gray-700 font-bold text-xs">
                    <?php echo $priority_val; ?>
                </span>
                <?php endif; ?>
            </td>
            <!-- Order # -->
            <td class="px-3 py-2.5 whitespace-nowrap">
                <a href="credit_order_view.php?id=<?php echo $order->id; ?>"
                   class="font-mono font-bold text-blue-700 hover:underline">
                    <?php echo htmlspecialchars($order->order_number); ?>
                </a>
                <div class="text-gray-400 mt-0.5"><?php echo $order->order_date ? date('d M', strtotime($order->order_date)) : '—'; ?></div>
            </td>
            <!-- Customer + Branch -->
            <td class="px-3 py-2.5 max-w-[180px]">
                <div class="font-semibold text-gray-900 truncate"><?php echo htmlspecialchars($order->customer_name); ?></div>
                <div class="text-gray-400 truncate"><?php echo htmlspecialchars($order->customer_phone ?? ''); ?></div>
                <?php if ($order->branch_name): ?>
                <div class="text-gray-400 truncate"><i class="fas fa-building mr-1 text-gray-300"></i><?php echo htmlspecialchars($order->branch_name); ?></div>
                <?php endif; ?>
            </td>
            <!-- Items -->
            <td class="px-3 py-2.5">
                <button type="button" onclick="openItemsModal(<?php echo $order->id; ?>)"
                        class="font-semibold text-blue-600 hover:text-blue-800 underline decoration-dashed cursor-pointer">
                    <?php echo $items_count; ?> item<?php echo $items_count !== 1 ? 's' : ''; ?>
                </button>
                <?php if ($first_item): ?>
                <div class="text-gray-400 truncate max-w-[120px] mt-0.5"><?php echo htmlspecialchars($first_item); ?></div>
                <?php endif; ?>
            </td>
            <!-- Amount -->
            <td class="px-3 py-2.5 text-right whitespace-nowrap font-bold text-gray-900">
                ৳<?php echo number_format($order->total_amount, 0); ?>
                <?php if (floatval($order->balance_due ?? 0) > 0): ?>
                <div class="text-red-500 font-normal text-[10px]">Due: ৳<?php echo number_format($order->balance_due, 0); ?></div>
                <?php endif; ?>
            </td>
            <!-- Required Date -->
            <td class="px-3 py-2.5 whitespace-nowrap <?php echo $is_overdue ? 'font-semibold text-red-600' : 'text-gray-600'; ?>">
                <?php if ($order->required_date): ?>
                    <?php echo date('d M', strtotime($order->required_date)); ?>
                    <?php if ($is_overdue): ?><i class="fas fa-exclamation-triangle text-red-400 ml-1" title="Overdue"></i><?php endif; ?>
                <?php else: ?>—<?php endif; ?>
            </td>
            <!-- Status -->
            <td class="px-3 py-2.5 whitespace-nowrap">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full font-semibold text-xs
                             <?php echo $status_cls[$order->status] ?? 'bg-gray-100 text-gray-700'; ?>">
                    <?php echo $status_labels[$order->status] ?? ucfirst($order->status); ?>
                </span>
            </td>
            <!-- Timeline -->
            <td class="px-3 py-2.5 whitespace-nowrap text-gray-500 space-y-0.5">
                <?php if ($order->production_started_at): ?>
                <div><i class="fas fa-play-circle text-purple-400 w-3 mr-1"></i><?php echo date('d M g:ia', strtotime($order->production_started_at)); ?></div>
                <?php endif; ?>
                <?php if ($order->production_completed_at): ?>
                <div><i class="fas fa-check-circle text-green-400 w-3 mr-1"></i><?php echo date('d M g:ia', strtotime($order->production_completed_at)); ?></div>
                <?php elseif ($order->scheduled_date && !$order->production_started_at): ?>
                <div><i class="fas fa-calendar text-gray-300 w-3 mr-1"></i><?php echo date('d M', strtotime($order->scheduled_date)); ?></div>
                <?php endif; ?>
                <?php if (!$order->production_started_at && !$order->scheduled_date): ?>
                <span class="text-gray-300">—</span>
                <?php endif; ?>
            </td>
            <!-- Actions -->
            <td class="px-3 py-2.5 text-center whitespace-nowrap">
                <div class="inline-flex items-center gap-1 flex-wrap justify-center">

                    <?php if ($can_update_status): ?>
                    <form method="POST" class="inline-flex">
                        <input type="hidden" name="order_id" value="<?php echo $order->id; ?>">
                        <?php if ($order->status === 'approved'): ?>
                            <?php if (isset($prod_holds[(int)$order->id])): ?>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-red-100 text-red-700 rounded font-semibold"
                              title="<?php echo htmlspecialchars($prod_holds[(int)$order->id] ?: 'Production held by admin instruction'); ?>">
                            <i class="fas fa-lock text-xs"></i> HOLD
                        </span>
                            <?php else: ?>
                        <button type="submit" name="action" value="start"
                                onclick="return confirm('Start production for <?php echo addslashes($order->order_number); ?>?')"
                                class="inline-flex items-center gap-1 px-2.5 py-1 bg-purple-600 text-white rounded hover:bg-purple-700 font-semibold transition-colors cursor-pointer">
                            <i class="fas fa-play text-xs"></i> Start
                        </button>
                            <?php endif; ?>
                        <?php elseif ($order->status === 'in_production'): ?>
                        <button type="submit" name="action" value="complete"
                                onclick="return confirm('Mark <?php echo addslashes($order->order_number); ?> as produced?')"
                                class="inline-flex items-center gap-1 px-2.5 py-1 bg-green-600 text-white rounded hover:bg-green-700 font-semibold transition-colors cursor-pointer">
                            <i class="fas fa-check text-xs"></i> Complete
                        </button>
                        <?php elseif ($order->status === 'produced'): ?>
                        <button type="submit" name="action" value="ready"
                                onclick="return confirm('Mark <?php echo addslashes($order->order_number); ?> as ready to ship?')"
                                class="inline-flex items-center gap-1 px-2.5 py-1 bg-orange-600 text-white rounded hover:bg-orange-700 font-semibold transition-colors cursor-pointer">
                            <i class="fas fa-truck text-xs"></i> Ready
                        </button>
                        <?php elseif ($order->status === 'ready_to_ship'): ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-orange-100 text-orange-700 rounded font-medium">
                            <i class="fas fa-check-circle text-xs"></i> Ready
                        </span>
                        <?php endif; ?>
                    </form>
                    <?php endif; ?>

                    <a href="credit_order_view.php?id=<?php echo $order->id; ?>"
                       class="inline-flex items-center px-2 py-1 border border-gray-300 text-gray-600 rounded hover:bg-gray-100 transition-colors cursor-pointer" title="View">
                        <i class="fas fa-eye text-xs"></i>
                    </a>

                    <?php if ($can_edit_orders): ?>
                    <a href="credit_order_view.php?id=<?php echo $order->id; ?>&edit=1"
                       class="inline-flex items-center px-2 py-1 border border-blue-300 text-blue-600 rounded hover:bg-blue-50 transition-colors cursor-pointer" title="Edit Order">
                        <i class="fas fa-edit text-xs"></i>
                    </a>
                    <?php endif; ?>

                    <?php if ($can_collect_payment): ?>
                    <a href="credit_payment_collect.php?order_id=<?php echo $order->id; ?>"
                       class="inline-flex items-center px-2 py-1 border border-green-300 text-green-700 rounded hover:bg-green-50 transition-colors cursor-pointer" title="Collect Payment">
                        <i class="fas fa-money-bill-wave text-xs"></i>
                    </a>
                    <?php endif; ?>

                    <?php if ($can_partial_delivery && in_array($order->status, ['produced', 'ready_to_ship'])): ?>
                    <a href="partial_delivery.php?order_id=<?php echo $order->id; ?>"
                       class="inline-flex items-center px-2 py-1 border border-teal-300 text-teal-700 rounded hover:bg-teal-50 transition-colors cursor-pointer" title="Partial Delivery">
                        <i class="fas fa-dolly text-xs"></i>
                    </a>
                    <?php endif; ?>

                    <?php if ($can_return): ?>
                    <a href="returns.php?order_id=<?php echo $order->id; ?>"
                       class="inline-flex items-center px-2 py-1 border border-red-300 text-red-600 rounded hover:bg-red-50 transition-colors cursor-pointer" title="Process Return">
                        <i class="fas fa-undo text-xs"></i>
                    </a>
                    <?php endif; ?>

                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php else: ?>
<div class="bg-white rounded-lg border border-gray-200 shadow-sm p-12 text-center">
    <i class="fas fa-industry text-5xl text-gray-300 mb-4"></i>
    <h3 class="text-base font-semibold text-gray-600 mb-1">No Orders in Production Queue</h3>
    <p class="text-sm text-gray-400">All orders have been completed or no new orders assigned yet.</p>
</div>
<?php endif; ?>
</div><!-- /tab queue -->


<!-- ══════════════════════════════════════════════════════════
     TAB: ALL ORDERS
══════════════════════════════════════════════════════════ -->
<?php if ($can_view_orders): ?>
<div class="<?php echo $active_tab !== 'orders' ? 'hidden' : ''; ?>">

<form method="GET" class="bg-white rounded-lg border border-gray-200 shadow-sm px-4 py-3 mb-4">
    <input type="hidden" name="tab" value="orders">
    <?php if ($filter_branch_id > 0): ?><input type="hidden" name="branch_id" value="<?php echo $filter_branch_id; ?>"><?php endif; ?>
    <div class="flex flex-wrap gap-3 items-end">
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">Status</label>
            <select name="ao_status" class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500 min-w-[160px]">
                <option value="">All Statuses</option>
                <?php foreach ($status_labels as $val => $lbl): ?>
                <option value="<?php echo $val; ?>" <?php echo $ao_status === $val ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">From Date</label>
            <input type="date" name="ao_from" value="<?php echo htmlspecialchars($ao_from); ?>"
                   class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500">
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">To Date</label>
            <input type="date" name="ao_to" value="<?php echo htmlspecialchars($ao_to); ?>"
                   class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500">
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">Product</label>
            <input type="text" name="ao_product" value="<?php echo htmlspecialchars($ao_product); ?>"
                   placeholder="Product name..." class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500 w-36">
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">Customer</label>
            <input type="text" name="ao_customer" value="<?php echo htmlspecialchars($ao_customer); ?>"
                   placeholder="Customer name..." class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500 w-36">
        </div>
        <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded hover:bg-blue-700 transition-colors cursor-pointer">
            <i class="fas fa-search mr-1"></i>Search
        </button>
        <a href="?tab=orders<?php echo $filter_branch_id ? '&branch_id=' . $filter_branch_id : ''; ?>"
           class="px-3 py-1.5 bg-gray-100 text-gray-600 text-xs rounded hover:bg-gray-200 transition-colors cursor-pointer">
            <i class="fas fa-times mr-1"></i>Reset
        </a>
        <span class="ml-auto self-end text-xs text-gray-400 pb-1"><strong><?php echo count($all_orders); ?></strong> orders</span>
    </div>
</form>

<?php if (!empty($all_orders)):
    $ao_total_amt = array_sum(array_map(fn($o) => floatval($o->total_amount), $all_orders));
    $ao_total_bal = array_sum(array_map(fn($o) => floatval($o->balance_due), $all_orders));
?>
<div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs">
        <thead>
            <tr class="bg-gray-50 border-b border-gray-200 text-left">
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Order</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Date</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Customer</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Branch</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Status</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap text-right">Amount</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap text-right">Balance</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap text-center">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
        <?php foreach ($all_orders as $o): ?>
        <tr class="hover:bg-gray-50 transition-colors">
            <td class="px-3 py-2.5 whitespace-nowrap">
                <a href="credit_order_view.php?id=<?php echo $o->id; ?>"
                   class="font-mono font-bold text-blue-700 hover:underline"><?php echo htmlspecialchars($o->order_number); ?></a>
            </td>
            <td class="px-3 py-2.5 whitespace-nowrap text-gray-600">
                <?php echo $o->order_date ? date('d M Y', strtotime($o->order_date)) : '—'; ?>
            </td>
            <td class="px-3 py-2.5 max-w-[160px]">
                <div class="font-medium text-gray-900 truncate"><?php echo htmlspecialchars($o->customer_name); ?></div>
                <div class="text-gray-400 truncate"><?php echo htmlspecialchars($o->customer_phone ?? ''); ?></div>
            </td>
            <td class="px-3 py-2.5 whitespace-nowrap text-gray-500"><?php echo htmlspecialchars($o->branch_name ?? '—'); ?></td>
            <td class="px-3 py-2.5 whitespace-nowrap">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full font-semibold text-xs <?php echo $status_cls[$o->status] ?? 'bg-gray-100 text-gray-700'; ?>">
                    <?php echo $status_labels[$o->status] ?? ucfirst($o->status); ?>
                </span>
            </td>
            <td class="px-3 py-2.5 text-right font-semibold text-gray-900 whitespace-nowrap">৳<?php echo number_format($o->total_amount, 0); ?></td>
            <td class="px-3 py-2.5 text-right whitespace-nowrap <?php echo floatval($o->balance_due) > 0 ? 'text-red-600 font-semibold' : 'text-gray-400'; ?>">
                <?php echo floatval($o->balance_due) > 0 ? '৳' . number_format($o->balance_due, 0) : '—'; ?>
            </td>
            <td class="px-3 py-2.5 text-center whitespace-nowrap">
                <div class="inline-flex items-center gap-1">
                    <a href="credit_order_view.php?id=<?php echo $o->id; ?>"
                       class="inline-flex items-center px-2 py-1 border border-gray-300 text-gray-600 rounded hover:bg-gray-100 cursor-pointer" title="View">
                        <i class="fas fa-eye text-xs"></i>
                    </a>
                    <?php if ($can_edit_orders): ?>
                    <a href="credit_order_view.php?id=<?php echo $o->id; ?>&edit=1"
                       class="inline-flex items-center px-2 py-1 border border-blue-300 text-blue-600 rounded hover:bg-blue-50 cursor-pointer" title="Edit">
                        <i class="fas fa-edit text-xs"></i>
                    </a>
                    <?php endif; ?>
                    <?php if ($can_collect_payment && floatval($o->balance_due) > 0): ?>
                    <a href="credit_payment_collect.php?order_id=<?php echo $o->id; ?>"
                       class="inline-flex items-center px-2 py-1 border border-green-300 text-green-700 rounded hover:bg-green-50 cursor-pointer" title="Collect Payment">
                        <i class="fas fa-money-bill-wave text-xs"></i>
                    </a>
                    <?php endif; ?>
                    <?php if ($can_partial_delivery && in_array($o->status, ['produced', 'ready_to_ship'])): ?>
                    <a href="partial_delivery.php?order_id=<?php echo $o->id; ?>"
                       class="inline-flex items-center px-2 py-1 border border-teal-300 text-teal-700 rounded hover:bg-teal-50 cursor-pointer" title="Partial Delivery">
                        <i class="fas fa-dolly text-xs"></i>
                    </a>
                    <?php endif; ?>
                    <?php if ($can_return): ?>
                    <a href="returns.php?order_id=<?php echo $o->id; ?>"
                       class="inline-flex items-center px-2 py-1 border border-red-300 text-red-600 rounded hover:bg-red-50 cursor-pointer" title="Return">
                        <i class="fas fa-undo text-xs"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="border-t-2 border-gray-300 bg-gray-50">
                <td colspan="5" class="px-3 py-2 text-right text-xs font-semibold text-gray-600">Total (<?php echo count($all_orders); ?> orders)</td>
                <td class="px-3 py-2 text-right text-xs font-bold text-gray-900">৳<?php echo number_format($ao_total_amt, 0); ?></td>
                <td class="px-3 py-2 text-right text-xs font-bold text-red-600">৳<?php echo number_format($ao_total_bal, 0); ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    </div>
</div>
<?php else: ?>
<div class="bg-white rounded-lg border border-gray-200 shadow-sm p-10 text-center">
    <i class="fas fa-search text-4xl text-gray-300 mb-3"></i>
    <p class="text-sm text-gray-500">No orders found. Use the filters above to search.</p>
</div>
<?php endif; ?>
</div><!-- /tab orders -->
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════════
     TAB: PRODUCTION TOTALS
══════════════════════════════════════════════════════════ -->
<?php if ($can_production_report): ?>
<div class="<?php echo $active_tab !== 'report' ? 'hidden' : ''; ?>">

<form method="GET" class="bg-white rounded-lg border border-gray-200 shadow-sm px-4 py-3 mb-4">
    <input type="hidden" name="tab" value="report">
    <?php if ($filter_branch_id > 0): ?><input type="hidden" name="branch_id" value="<?php echo $filter_branch_id; ?>"><?php endif; ?>
    <div class="flex flex-wrap gap-3 items-end">
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">From</label>
            <input type="date" name="pt_from" value="<?php echo htmlspecialchars($pt_from); ?>"
                   class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500">
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">To</label>
            <input type="date" name="pt_to" value="<?php echo htmlspecialchars($pt_to); ?>"
                   class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500">
        </div>
        <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded hover:bg-blue-700 transition-colors cursor-pointer">
            <i class="fas fa-search mr-1"></i>Apply
        </button>
    </div>
</form>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

    <!-- By Date -->
    <div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 bg-gray-50">
            <h3 class="text-sm font-semibold text-gray-800"><i class="fas fa-calendar-alt mr-2 text-blue-500"></i>By Date</h3>
        </div>
        <?php if (!empty($prod_totals_date)):
            $pt_d_qty = array_sum(array_map(fn($r) => floatval($r->total_qty), $prod_totals_date));
            $pt_d_val = array_sum(array_map(fn($r) => floatval($r->total_value), $prod_totals_date));
        ?>
        <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-200">
                    <th class="px-3 py-2 text-left font-semibold text-gray-500 uppercase tracking-wide">Date</th>
                    <th class="px-3 py-2 text-right font-semibold text-gray-500 uppercase tracking-wide">Orders</th>
                    <th class="px-3 py-2 text-right font-semibold text-gray-500 uppercase tracking-wide">Total Qty</th>
                    <th class="px-3 py-2 text-right font-semibold text-gray-500 uppercase tracking-wide">Value</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
            <?php foreach ($prod_totals_date as $row): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-3 py-2 font-medium text-gray-800">
                    <?php echo $row->prod_date ? date('d M Y', strtotime($row->prod_date)) : '—'; ?>
                </td>
                <td class="px-3 py-2 text-right text-gray-700"><?php echo number_format($row->total_orders); ?></td>
                <td class="px-3 py-2 text-right text-gray-700"><?php echo number_format(floatval($row->total_qty), 0); ?></td>
                <td class="px-3 py-2 text-right font-semibold text-gray-900">৳<?php echo number_format(floatval($row->total_value), 0); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-gray-300 bg-gray-50">
                    <td colspan="2" class="px-3 py-2 font-bold text-gray-700">Total</td>
                    <td class="px-3 py-2 text-right font-bold text-gray-900"><?php echo number_format($pt_d_qty, 0); ?></td>
                    <td class="px-3 py-2 text-right font-bold text-blue-700">৳<?php echo number_format($pt_d_val, 0); ?></td>
                </tr>
            </tfoot>
        </table>
        </div>
        <?php else: ?>
        <div class="p-8 text-center text-sm text-gray-400">No completed production in this period.</div>
        <?php endif; ?>
    </div>

    <!-- By Product -->
    <div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 bg-gray-50">
            <h3 class="text-sm font-semibold text-gray-800"><i class="fas fa-box mr-2 text-amber-500"></i>By Product</h3>
        </div>
        <?php if (!empty($prod_totals_product)):
            $pt_p_qty = array_sum(array_map(fn($r) => floatval($r->total_qty), $prod_totals_product));
            $pt_p_val = array_sum(array_map(fn($r) => floatval($r->total_value), $prod_totals_product));
        ?>
        <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-200">
                    <th class="px-3 py-2 text-left font-semibold text-gray-500 uppercase tracking-wide">Product</th>
                    <th class="px-3 py-2 text-right font-semibold text-gray-500 uppercase tracking-wide">Orders</th>
                    <th class="px-3 py-2 text-right font-semibold text-gray-500 uppercase tracking-wide">Total Qty</th>
                    <th class="px-3 py-2 text-right font-semibold text-gray-500 uppercase tracking-wide">Value</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
            <?php foreach ($prod_totals_product as $row): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-3 py-2 font-medium text-gray-800"><?php echo htmlspecialchars($row->product_name); ?></td>
                <td class="px-3 py-2 text-right text-gray-700"><?php echo number_format($row->total_orders); ?></td>
                <td class="px-3 py-2 text-right text-gray-700"><?php echo number_format(floatval($row->total_qty), 0); ?></td>
                <td class="px-3 py-2 text-right font-semibold text-gray-900">৳<?php echo number_format(floatval($row->total_value), 0); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-gray-300 bg-gray-50">
                    <td colspan="2" class="px-3 py-2 font-bold text-gray-700">Total</td>
                    <td class="px-3 py-2 text-right font-bold text-gray-900"><?php echo number_format($pt_p_qty, 0); ?></td>
                    <td class="px-3 py-2 text-right font-bold text-blue-700">৳<?php echo number_format($pt_p_val, 0); ?></td>
                </tr>
            </tfoot>
        </table>
        </div>
        <?php else: ?>
        <div class="p-8 text-center text-sm text-gray-400">No completed production in this period.</div>
        <?php endif; ?>
    </div>

</div>
</div><!-- /tab report -->
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════════
     TAB: PAYMENT HISTORY
══════════════════════════════════════════════════════════ -->
<?php if ($can_view_payments): ?>
<div class="<?php echo $active_tab !== 'payments' ? 'hidden' : ''; ?>">

<form method="GET" class="bg-white rounded-lg border border-gray-200 shadow-sm px-4 py-3 mb-4">
    <input type="hidden" name="tab" value="payments">
    <?php if ($filter_branch_id > 0): ?><input type="hidden" name="branch_id" value="<?php echo $filter_branch_id; ?>"><?php endif; ?>
    <div class="flex flex-wrap gap-3 items-end">
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">From</label>
            <input type="date" name="ph_from" value="<?php echo htmlspecialchars($ph_from); ?>"
                   class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500">
        </div>
        <div class="flex flex-col gap-0.5">
            <label class="text-xs text-gray-500 font-medium">To</label>
            <input type="date" name="ph_to" value="<?php echo htmlspecialchars($ph_to); ?>"
                   class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500">
        </div>
        <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded hover:bg-blue-700 transition-colors cursor-pointer">
            <i class="fas fa-search mr-1"></i>Apply
        </button>
        <span class="ml-auto self-end text-xs text-gray-400 pb-1"><strong><?php echo count($ph_records); ?></strong> payments</span>
    </div>
</form>

<?php if (!empty($ph_records)):
    $ph_total = array_sum(array_map(fn($p) => floatval($p->amount), $ph_records));
?>
<div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs">
        <thead>
            <tr class="bg-gray-50 border-b border-gray-200 text-left">
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Date</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Customer</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Order(s)</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Method</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Ref #</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap text-right">Amount</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Collected By</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
        <?php foreach ($ph_records as $p): ?>
        <tr class="hover:bg-gray-50 transition-colors">
            <td class="px-3 py-2.5 whitespace-nowrap text-gray-600"><?php echo date('d M Y', strtotime($p->payment_date)); ?></td>
            <td class="px-3 py-2.5 max-w-[160px]">
                <div class="font-medium text-gray-900 truncate"><?php echo htmlspecialchars($p->customer_name); ?></div>
                <div class="text-gray-400 truncate"><?php echo htmlspecialchars($p->customer_phone ?? ''); ?></div>
            </td>
            <td class="px-3 py-2.5 text-gray-500 text-xs"><?php echo htmlspecialchars($p->order_numbers ?? '—'); ?></td>
            <td class="px-3 py-2.5 whitespace-nowrap text-gray-600 capitalize"><?php echo htmlspecialchars($p->payment_method ?? '—'); ?></td>
            <td class="px-3 py-2.5 whitespace-nowrap text-gray-500 font-mono"><?php echo htmlspecialchars($p->reference_number ?? '—'); ?></td>
            <td class="px-3 py-2.5 text-right font-bold text-green-700 whitespace-nowrap">৳<?php echo number_format(floatval($p->amount), 0); ?></td>
            <td class="px-3 py-2.5 whitespace-nowrap text-gray-500"><?php echo htmlspecialchars($p->collected_by ?? '—'); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="border-t-2 border-gray-300 bg-gray-50">
                <td colspan="5" class="px-3 py-2 text-right text-xs font-semibold text-gray-600">Total Collected (<?php echo count($ph_records); ?> payments)</td>
                <td class="px-3 py-2 text-right text-xs font-bold text-green-700">৳<?php echo number_format($ph_total, 0); ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    </div>
</div>
<?php else: ?>
<div class="bg-white rounded-lg border border-gray-200 shadow-sm p-10 text-center">
    <i class="fas fa-money-bill text-4xl text-gray-300 mb-3"></i>
    <p class="text-sm text-gray-500">No payments found in this period.</p>
</div>
<?php endif; ?>
</div><!-- /tab payments -->
<?php endif; ?>

</div><!-- /container -->


<!-- Items Modal -->
<div id="itemsModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg mx-4 max-h-[88vh] flex flex-col" onclick="event.stopPropagation()">
        <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between flex-shrink-0">
            <h3 class="font-bold text-gray-900 text-sm" id="itemsModalTitle">Order Items</h3>
            <button type="button" onclick="closeItemsModal()" class="text-gray-400 hover:text-gray-600 cursor-pointer">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div id="itemsModalBody" class="overflow-y-auto p-5 text-sm flex-1"></div>
    </div>
</div>

<script>
const _prodData = {
<?php foreach ($orders as $o):
    $oi    = $all_items_by_order[$o->id] ?? [];
    $oi_js = json_encode(array_map(fn($i) => [
        'product' => $i->product_name,
        'variant' => trim(($i->grade ?? '') . ' ' . ($i->weight_variant ?? '')),
        'qty'     => (float)$i->quantity,
        'unit'    => $i->unit_of_measure ?? '',
        'price'   => (float)$i->unit_price,
        'total'   => (float)$i->line_total,
    ], $oi), JSON_UNESCAPED_UNICODE);
?>
    <?php echo (int)$o->id; ?>: {
        order_number: <?php echo json_encode($o->order_number); ?>,
        customer:     <?php echo json_encode($o->customer_name); ?>,
        phone:        <?php echo json_encode($o->customer_phone ?? ''); ?>,
        branch:       <?php echo json_encode($o->branch_name ?? ''); ?>,
        address:      <?php echo json_encode($o->shipping_address ?? ''); ?>,
        instructions: <?php echo json_encode($o->special_instructions ?? ''); ?>,
        required_date:<?php echo json_encode($o->required_date ? date('d M Y', strtotime($o->required_date)) : ''); ?>,
        started_at:   <?php echo json_encode($o->production_started_at   ? date('d M Y, g:i A', strtotime($o->production_started_at))   : ''); ?>,
        completed_at: <?php echo json_encode($o->production_completed_at ? date('d M Y, g:i A', strtotime($o->production_completed_at)) : ''); ?>,
        total:        <?php echo (float)$o->total_amount; ?>,
        items:        <?php echo $oi_js; ?>,
    },
<?php endforeach; ?>
};

function openItemsModal(orderId) {
    const d = _prodData[orderId];
    if (!d) return;
    document.getElementById('itemsModalTitle').textContent = d.order_number + ' — Production Items';
    let html = `<div class="flex items-start justify-between mb-3">
        <div>
            <div class="font-semibold text-gray-900">${d.customer}</div>
            <div class="text-xs text-gray-500">${d.branch}${d.phone ? ' · ' + d.phone : ''}</div>
        </div>
        <a href="credit_order_view.php?id=${orderId}" class="text-xs text-blue-600 hover:underline ml-4 flex-shrink-0">View Order</a>
    </div>`;
    if (d.required_date) html += `<div class="mb-3 text-xs text-gray-500"><i class="fas fa-calendar mr-1 text-gray-400"></i>Required by: <strong>${d.required_date}</strong></div>`;
    if (d.instructions)  html += `<div class="mb-3 text-xs bg-blue-50 border border-blue-100 text-blue-800 px-3 py-2 rounded"><i class="fas fa-info-circle mr-1"></i>${d.instructions}</div>`;
    html += `<table class="w-full border-collapse mb-4 text-xs">
        <thead><tr class="bg-gray-50 border-b border-gray-200">
            <th class="py-1.5 px-2 text-left font-semibold text-gray-500">Product</th>
            <th class="py-1.5 px-2 text-right font-semibold text-gray-500">Qty</th>
            <th class="py-1.5 px-2 text-right font-semibold text-gray-500">Price</th>
            <th class="py-1.5 px-2 text-right font-semibold text-gray-500">Total</th>
        </tr></thead><tbody>`;
    d.items.forEach(item => {
        html += `<tr class="border-b border-gray-100">
            <td class="py-2 px-2">
                <div class="font-medium text-gray-900">${item.product}</div>
                ${item.variant ? `<div class="text-gray-400">${item.variant}</div>` : ''}
            </td>
            <td class="py-2 px-2 text-right text-gray-700 font-semibold">${item.qty} <span class="text-gray-400 font-normal">${item.unit}</span></td>
            <td class="py-2 px-2 text-right text-gray-600">৳${Number(item.price).toLocaleString()}</td>
            <td class="py-2 px-2 text-right font-semibold text-gray-900">৳${Number(item.total).toLocaleString()}</td>
        </tr>`;
    });
    html += `</tbody><tfoot><tr class="border-t-2 border-gray-300 bg-gray-50">
        <td colspan="3" class="py-2 px-2 text-right font-bold text-gray-700">Total</td>
        <td class="py-2 px-2 text-right font-bold text-blue-700">৳${Number(d.total).toLocaleString()}</td>
    </tr></tfoot></table>`;
    if (d.started_at || d.completed_at) {
        html += `<div class="bg-gray-50 rounded-lg p-3 text-xs border border-gray-200 space-y-1">`;
        if (d.started_at)   html += `<div><i class="fas fa-play-circle text-purple-400 w-4 mr-1"></i>Started: ${d.started_at}</div>`;
        if (d.completed_at) html += `<div><i class="fas fa-check-circle text-green-500 w-4 mr-1"></i>Completed: ${d.completed_at}</div>`;
        html += `</div>`;
    }
    document.getElementById('itemsModalBody').innerHTML = html;
    document.getElementById('itemsModal').classList.remove('hidden');
}
function closeItemsModal() { document.getElementById('itemsModal').classList.add('hidden'); }
document.getElementById('itemsModal').addEventListener('click', function(e) { if (e.target === this) closeItemsModal(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeItemsModal(); });
</script>

<?php require_once '../templates/footer.php'; ?>
