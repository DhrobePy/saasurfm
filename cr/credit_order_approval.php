<?php
require_once '../core/init.php';

// Bug 5 fix: restrict_access was called with no role args — any logged-in user could access
$allowed_roles = ['Superadmin', 'admin', 'Accounts', 'accounts-srg', 'accounts-demra'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$user_role = $currentUser['role'] ?? '';
$pageTitle = 'Credit Order Approval';
$error = null;
$success = null;

// Role admins vs. privilege-granted override are now DISTINCT:
// the escalate-override toggle used to make a user full admin on this page,
// which silently bypassed personal approval limits.
$is_role_admin         = in_array($user_role, ['Superadmin', 'admin']);
$has_escalate_override = userCanPageAction('credit_sales', 'credit_order_approval', 'can_escalate_override');

// $is_admin governs queue visibility (escalated orders) and stats — unchanged
$is_admin = $is_role_admin || $has_escalate_override;

// Feature #9: over-credit-limit orders are Superadmin-only to approve.
$is_superadmin = ($currentUser['role'] ?? '') === 'Superadmin';

// Gate table must exist before any POST transaction (DDL would implicitly commit)
ensureApprovalGateTables();
ensureApprovalLimitTable();

// Personal approval cap. Applies to EVERY non-role-admin — including users with
// the escalate-override privilege. An explicit limit set by admin always wins.
$my_approval_limit = $is_role_admin ? null : getUserActionLimit((int)$user_id, 'approve_order', true);

// Get branches for assignment
$branches = $db->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name")->results();

// Get pending and escalated orders
$status_filter = $is_admin ? "('pending_approval', 'escalated')" : "('pending_approval')";

// True balance = initial_due + net ledger (customers.current_balance is unreliable —
// same formula as create_order.php / customer_ledger.php).
// credit_usage_percent: 999 when the customer is already AT/OVER limit so the UI
// escalates instead of treating a negative available-credit as "safe".
$orders = $db->query(
    "SELECT co.*,
           c.name as customer_name,
           c.phone_number as customer_phone,
           c.credit_limit,
           c.initial_due,
           (COALESCE(c.initial_due,0) + COALESCE(tb.d,0) - COALESCE(tb.cr,0)) as current_balance,
           (c.credit_limit - (COALESCE(c.initial_due,0) + COALESCE(tb.d,0) - COALESCE(tb.cr,0))) as available_credit,
           u.display_name as created_by_name,
           CASE
               WHEN c.credit_limit <= 0 THEN 0
               WHEN (c.credit_limit - (COALESCE(c.initial_due,0) + COALESCE(tb.d,0) - COALESCE(tb.cr,0))) <= 0 THEN 999
               ELSE ROUND((co.total_amount /
                    (c.credit_limit - (COALESCE(c.initial_due,0) + COALESCE(tb.d,0) - COALESCE(tb.cr,0)))) * 100, 2)
           END as credit_usage_percent
     FROM credit_orders co
     JOIN customers c ON co.customer_id = c.id
     LEFT JOIN (
         SELECT customer_id, SUM(debit_amount) d, SUM(credit_amount) cr
         FROM customer_ledger
         WHERE reference_type != 'initial_due'
         GROUP BY customer_id
     ) tb ON tb.customer_id = c.id
     LEFT JOIN users u ON co.created_by_user_id = u.id
     WHERE co.status IN $status_filter
     ORDER BY
        CASE co.status
            WHEN 'escalated' THEN 1
            WHEN 'pending_approval' THEN 2
        END,
        co.order_date ASC"
)->results();

// Bug 6 fix: CSRF verification for all POST actions (approve/reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sess_tok = $_SESSION['csrf_token'] ?? '';
    $recv_tok = $_POST['csrf_token']    ?? '';
    if (!$sess_tok || !$recv_tok || !hash_equals($sess_tok, $recv_tok)) {
        $_SESSION['error_flash'] = 'Invalid security token. Please refresh the page.';
        header('Location: credit_order_approval.php');
        exit();
    }
}

// Handle approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve') {
    $order_id = (int)($_POST['order_id'] ?? 0);
    $comments = trim($_POST['comments'] ?? '');
    $branch_id = (int)($_POST['branch_id'] ?? 0);

    if (!$branch_id) {
        $error = "Please select a branch for production";
    } else {
        try {
            $db->getPdo()->beginTransaction();

            $order = $db->query("SELECT * FROM credit_orders WHERE id = ?", [$order_id])->first();
            if (!$order) throw new Exception("Order not found");

            // Guard against double-submit / stale tabs: only these states are approvable.
            // Escalated orders are admin territory — non-admins act on pending only.
            $approvable = $is_admin ? ['pending_approval', 'escalated'] : ['pending_approval'];
            if (!in_array($order->status, $approvable)) {
                throw new Exception("Order is no longer awaiting approval (current status: "
                    . str_replace('_', ' ', $order->status) . ")");
            }

            $customer = $db->query("SELECT credit_limit FROM customers WHERE id = ?", [$order->customer_id])->first();
            if (!$customer) throw new Exception("Customer not found");

            // True outstanding from ledger (customers.current_balance is unreliable)
            $true_outstanding = getCustomerOutstanding((int)$order->customer_id);
            $available_credit = (float)$customer->credit_limit - $true_outstanding;

            // Use total_amount (not balance_due) for credit check — balance_due may be 0 if advance paid
            if ((float)$customer->credit_limit <= 0) {
                $usage_percent = 0;   // no limit configured → no escalation
            } elseif ($available_credit <= 0) {
                $usage_percent = 999; // already AT/OVER limit → must escalate for non-admin
            } else {
                $usage_percent = ($order->total_amount / $available_credit) * 100;
            }

            // Feature #9: an order that breaches the customer's CREDIT LIMIT can only
            // be approved by a Superadmin — not a regular admin, not a delegated ৳
            // limit, not the escalate-override privilege. It stays escalated until then.
            $is_superadmin_role = ($currentUser['role'] ?? '') === 'Superadmin';
            $over_credit_limit  = (float)$customer->credit_limit > 0
                                  && (float)$order->balance_due > $available_credit;
            if ($over_credit_limit && !$is_superadmin_role) {
                $over_by = (float)$order->balance_due - $available_credit;
                throw new Exception("This order exceeds the customer's credit limit by ৳"
                    . number_format(max(0, $over_by), 0)
                    . " — only a Superadmin can approve it. It remains escalated for Superadmin review.");
            }

            // Personal approval limit = DELEGATED ADMIN AUTHORITY (admin/privileges.php).
            // Strict precedence — an explicit limit ALWAYS wins over other privileges:
            //   1. Role admin (Superadmin/admin)      → unlimited
            //   2. Personal limit set                 → the limit decides everything,
            //      EVEN IF the user also has the escalate-override privilege
            //   3. Escalate-override privilege        → unlimited (legacy behavior)
            //   4. Nothing                            → standard 80% credit-usage rule
            $my_limit      = $is_role_admin ? null : getUserActionLimit((int)$user_id, 'approve_order', true);
            $over_my_limit = $my_limit !== null && (float)$order->total_amount > $my_limit;

            if ($is_role_admin) {
                $must_escalate = false;
            } elseif ($my_limit !== null) {
                $must_escalate = $over_my_limit;
            } elseif ($has_escalate_override) {
                $must_escalate = false;
            } else {
                $must_escalate = $usage_percent >= 80;
            }

            if ($must_escalate) {
                $new_status = 'escalated';
                $action = 'escalated to admin';
                if ($over_my_limit) {
                    $comments = trim(($comments ? $comments . ' | ' : '')
                        . sprintf('Auto-escalated: order ৳%s exceeds approver\'s delegated limit of ৳%s',
                                  number_format($order->total_amount, 0), number_format($my_limit, 0)));
                }
            } else {
                $new_status = 'approved';
                $action = 'approved';
                // Audit trail: any non-role-admin approval of a high-usage/over-limit
                // customer is stamped into workflow history for admin review.
                if (!$is_role_admin && $usage_percent >= 80) {
                    $authority = $my_limit !== null
                        ? 'delegated limit ৳' . number_format($my_limit, 0)
                        : 'escalation-override privilege';
                    $comments = trim(($comments ? $comments . ' | ' : '')
                        . sprintf('⚠ Approved under %s — customer credit usage %s (outstanding ৳%s of ৳%s limit)',
                                  $authority,
                                  $usage_percent >= 999 ? 'OVER LIMIT' : round($usage_percent) . '%',
                                  number_format($true_outstanding, 0),
                                  number_format((float)$customer->credit_limit, 0)));
                }
            }

            // Update order status, assign branch, and set required date
            $required_date = trim($_POST['required_date'] ?? '');
            if ($required_date === '' || !strtotime($required_date)) {
                throw new Exception("Please select a valid required delivery date");
            }
            $db->query(
                "UPDATE credit_orders SET status = ?, assigned_branch_id = ?, required_date = ? WHERE id = ?", 
                [$new_status, $branch_id, $required_date, $order_id]
            );
            
            // Log workflow
            $db->insert('credit_order_workflow', [
                'order_id' => $order_id,
                'from_status' => $order->status,
                'to_status' => $new_status,
                'action' => $action,
                'performed_by_user_id' => $user_id,
                'comments' => $comments ?: "Order $action"
            ]);

            // ── Approval conditions (production hold / dispatch clearance) ──
            $prod_hold = !empty($_POST['production_hold']) ? 1 : 0;
            $disp_hold = !empty($_POST['dispatch_hold'])   ? 1 : 0;
            // Feature #5: with the global dispatch hold ON, every approved order is
            // held for dispatch until Accounts/Admin clears it — the approver's
            // dispatch-hold checkbox can only ADD a condition, never remove the hold.
            if (dispatchGlobalHoldEnabled() && $new_status === 'approved') {
                $disp_hold = 1;
            }
            $conditions_summary = '';
            $prod_note = $acc_note = $disp_desc = '';
            if (($prod_hold || $disp_hold) && $new_status === 'approved') {
                $cond_type = in_array($_POST['condition_type'] ?? '', ['manual', 'outstanding_below', 'outstanding_after_ship', 'amount_received'])
                           ? $_POST['condition_type'] : 'manual';
                $cond_amount_raw = trim($_POST['condition_amount'] ?? '');
                $cond_amount = ($disp_hold && $cond_type !== 'manual' && $cond_amount_raw !== '')
                             ? (float)$cond_amount_raw : null;
                if ($disp_hold && $cond_type !== 'manual' && $cond_amount === null) {
                    throw new Exception('Please enter the amount for the payment condition.');
                }
                $auto_release = (!empty($_POST['auto_release']) && $cond_type !== 'manual') ? 1 : 0;
                $prod_note    = trim($_POST['production_note'] ?? '');
                $acc_note     = trim($_POST['accounts_note'] ?? '');

                // Replace any previous conditions row for this order (re-approval)
                $db->query("DELETE FROM order_approval_conditions WHERE order_id = ?", [$order_id]);
                $db->insert('order_approval_conditions', [
                    'order_id'            => $order_id,
                    'approved_by_user_id' => $user_id,
                    'production_hold'     => $prod_hold,
                    'production_note'     => $prod_note ?: null,
                    'dispatch_hold'       => $disp_hold,
                    'condition_type'      => $cond_type,
                    'condition_amount'    => $cond_amount,
                    'auto_release'        => $auto_release,
                    'accounts_note'       => $acc_note ?: null,
                ]);

                $gate_bits = [];
                if ($prod_hold) $gate_bits[] = 'PRODUCTION HOLD';
                $disp_desc = '';
                if ($disp_hold) {
                    $disp_desc = 'DISPATCH HOLD';
                    if ($cond_type === 'outstanding_below')      $disp_desc .= ' until outstanding ≤ ৳' . number_format($cond_amount, 0);
                    if ($cond_type === 'outstanding_after_ship') $disp_desc .= ' until total incl. this invoice ≤ ৳' . number_format($cond_amount, 0);
                    if ($cond_type === 'amount_received')        $disp_desc .= ' until ৳' . number_format($cond_amount, 0) . ' received';
                    if ($cond_type === 'manual')                 $disp_desc .= ' until Accounts clears manually';
                    if ($auto_release) $disp_desc .= ' (auto-release)';
                    $gate_bits[] = $disp_desc;
                }
                $conditions_summary = implode(' | ', $gate_bits);
                $db->insert('credit_order_workflow', [
                    'order_id'             => $order_id,
                    'from_status'          => $new_status,
                    'to_status'            => $new_status,
                    'action'               => 'conditions_set',
                    'performed_by_user_id' => $user_id,
                    'comments'             => 'Approval conditions: ' . $conditions_summary,
                ]);
            }

            $db->getPdo()->commit();
            
            // ============================================
            // TELEGRAM NOTIFICATION - APPROVAL
            // ============================================
            if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
                try {
                    require_once '../core/classes/TelegramNotifier.php';
                    $telegram = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);
                    
                    // Get customer details
                    $customer_info = $db->query("SELECT name, phone_number FROM customers WHERE id = ?", [$order->customer_id])->first();
                    
                    // Get branch name
                    $branch_info = $db->query("SELECT name FROM branches WHERE id = ?", [$branch_id])->first();
                    $branch_name = $branch_info ? $branch_info->name : 'Unknown Branch';
                    
                    // Get approver name
                    $approver_info = $db->query("SELECT display_name FROM users WHERE id = ?", [$user_id])->first();
                    $approver_name = $approver_info ? $approver_info->display_name : 'Unknown User';
                    
                    // Get order items with product details
                    $items = $db->query(
                        "SELECT coi.*, 
                                p.base_name as product_name,
                                pv.grade,
                                pv.weight_variant,
                                pv.unit_of_measure,
                                pv.sku as variant_sku
                         FROM credit_order_items coi
                         JOIN products p ON coi.product_id = p.id
                         LEFT JOIN product_variants pv ON coi.variant_id = pv.id
                         WHERE coi.order_id = ?",
                        [$order_id]
                    )->results();
                    
                    // Format items for notification
                    $notification_items = [];
                    foreach ($items as $item) {
                        $variant_name = trim(($item->grade ?? '') . ' ' . ($item->weight_variant ?? ''));
                        $notification_items[] = [
                            'product_name' => $item->product_name,
                            'variant_name' => $variant_name,
                            'quantity' => floatval($item->quantity),
                            'unit' => $item->unit_of_measure ?? 'pcs',
                            'unit_price' => number_format($item->unit_price, 2),
                            'subtotal' => number_format($item->line_total, 2)
                        ];
                    }
                    
                    // Prepare approval data
                    $approvalData = [
                        'order_number' => $order->order_number,
                        'approval_date' => date('d M Y, h:i A'),
                        'customer_name' => $customer_info ? $customer_info->name : 'Unknown',
                        'customer_phone' => $customer_info ? $customer_info->phone_number : 'N/A',
                        'assigned_branch' => $branch_name,
                        'required_date' => date('d M Y', strtotime($required_date)),
                        'items' => $notification_items,
                        'subtotal' => floatval($order->subtotal),
                        'discount_amount' => floatval($order->discount_amount),
                        'total_amount' => floatval($order->total_amount),
                        'advance_paid' => floatval($order->advance_paid),
                        'balance_due' => floatval($order->balance_due),
                        'comments' => ($comments ?: 'Order approved for production')
                                    . ($conditions_summary !== '' ? ' | ⚠ ' . $conditions_summary : ''),
                        'approved_by' => $approver_name
                    ];

                    // Send notification
                    $telegram->sendOrderApprovalNotification($approvalData);

                    // Dedicated SPECIAL INSTRUCTIONS message so production &
                    // accounts teams cannot miss attached holds/conditions
                    if ($conditions_summary !== '') {
                        $ci = "<b>🖐 SPECIAL INSTRUCTIONS ATTACHED</b>\n"
                            . "─────────────────────────\n\n"
                            . "<b>Order:</b> <code>{$order->order_number}</code>\n"
                            . "<b>Customer:</b> " . htmlspecialchars($customer_info->name ?? 'Unknown') . "\n"
                            . "<b>Branch:</b> " . htmlspecialchars($branch_name) . "\n\n";
                        if ($prod_hold) {
                            $ci .= "🏭 <b>PRODUCTION: HOLD</b> — do NOT start until released by admin\n";
                            if ($prod_note !== '') $ci .= "   <i>Note: " . htmlspecialchars($prod_note) . "</i>\n";
                        }
                        if ($disp_hold) {
                            $ci .= "🚚 <b>" . htmlspecialchars($disp_desc) . "</b>\n";
                            if ($acc_note !== '') $ci .= "   <i>Note to Accounts: " . htmlspecialchars($acc_note) . "</i>\n";
                            $ci .= "   Track &amp; clear in Payment Watch\n";
                        }
                        $ci .= "\n<b>Set by:</b> " . htmlspecialchars($approver_name) . "\n\n"
                             . "<i>Ujjal Flour Mills ERP</i>";
                        $telegram->sendMessage($ci);
                    }

                } catch (Exception $e) {
                    error_log("Telegram approval notification failed: " . $e->getMessage());
                }
            }
            // END TELEGRAM NOTIFICATION
            
            auditLogOrder('approved', $order_id, $order->order_number,
                "Order {$order->order_number} approved for production — ৳" . number_format($order->total_amount, 2),
                ['branch_id' => $branch_id, 'comments' => $comments]
            );

            $_SESSION['success_flash'] = "Order $action successfully";
            header('Location: credit_order_approval.php');
            exit();

        } catch (Exception $e) {
            if ($db->getPdo()->inTransaction()) {
                $db->getPdo()->rollBack();
            }
            $error = "Failed to approve order: " . $e->getMessage();
        }
    }
}

// Handle rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject') {
    $order_id = (int)($_POST['order_id'] ?? 0);
    $comments = trim($_POST['reject_reason'] ?? '');

    try {
        $db->getPdo()->beginTransaction();

        $order = $db->query("SELECT * FROM credit_orders WHERE id = ?", [$order_id])->first();
        if (!$order) throw new Exception("Order not found");

        // Guard against double-submit / stale tabs
        if (!in_array($order->status, ['pending_approval', 'escalated'])) {
            throw new Exception("Order is no longer awaiting approval (current status: "
                . str_replace('_', ' ', $order->status) . ")");
        }

        // Update order status
        $db->query("UPDATE credit_orders SET status = 'rejected' WHERE id = ?", [$order_id]);
        
        // Log workflow
        $db->insert('credit_order_workflow', [
            'order_id' => $order_id,
            'from_status' => $order->status,
            'to_status' => 'rejected',
            'action' => 'reject',
            'performed_by_user_id' => $user_id,
            'comments' => $comments ?: 'Order rejected'
        ]);
        
        $db->getPdo()->commit();
        
        // ============================================
        // TELEGRAM NOTIFICATION - REJECTION
        // ============================================
        if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
            try {
                require_once '../core/classes/TelegramNotifier.php';
                $telegram = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);
                
                // Get customer details
                $customer_info = $db->query("SELECT name, phone_number FROM customers WHERE id = ?", [$order->customer_id])->first();
                
                // Get rejector name
                $rejector_info = $db->query("SELECT display_name FROM users WHERE id = ?", [$user_id])->first();
                $rejector_name = $rejector_info ? $rejector_info->display_name : 'Unknown User';
                
                // Prepare rejection data
                $rejectionData = [
                    'order_number' => $order->order_number,
                    'rejection_date' => date('d M Y, h:i A'),
                    'customer_name' => $customer_info ? $customer_info->name : 'Unknown',
                    'customer_phone' => $customer_info ? $customer_info->phone_number : 'N/A',
                    'total_amount' => floatval($order->total_amount),
                    'balance_due' => floatval($order->balance_due),
                    'rejection_reason' => $comments ?: 'Order rejected',
                    'rejected_by' => $rejector_name
                ];
                
                // Send notification
                $telegram->sendOrderRejectionNotification($rejectionData);
                
            } catch (Exception $e) {
                error_log("Telegram rejection notification failed: " . $e->getMessage());
            }
        }
        // END TELEGRAM NOTIFICATION
        
        auditLogOrder('rejected', $order_id, $order->order_number,
            "Order {$order->order_number} rejected — " . ($comments ?: 'No reason given'),
            ['comments' => $comments]
        );

        $_SESSION['success_flash'] = "Order rejected";
        header('Location: credit_order_approval.php');
        exit();

    } catch (Exception $e) {
        if ($db->getPdo()->inTransaction()) {
            $db->getPdo()->rollBack();
        }
        $error = "Failed to reject order: " . $e->getMessage();
    }
}

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
        <p class="text-gray-500 mt-1">
            Review and approve pending credit orders &mdash;
            <span class="font-medium text-orange-600"><?php echo count($orders); ?> order<?php echo count($orders) !== 1 ? 's' : ''; ?> awaiting action</span>
            <?php if ($my_approval_limit !== null): ?>
            &mdash; <span class="font-medium text-amber-700"><i class="fas fa-gavel mr-1"></i>Your approval limit: ৳<?php echo number_format($my_approval_limit, 0); ?></span>
            <?php endif; ?>
        </p>
    </div>
    <a href="<?php echo url('cr/sales_dashboard.php'); ?>"
       class="inline-flex items-center gap-2 px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 cursor-pointer transition-colors">
        <i class="fas fa-arrow-left"></i> Dashboard
    </a>
</div>

<?php
$flash_success = $_SESSION['success_flash'] ?? null;
$flash_error   = $_SESSION['error_flash']   ?? null;
unset($_SESSION['success_flash'], $_SESSION['error_flash']);
?>
<?php if ($flash_success): ?>
<div class="bg-green-50 border-l-4 border-green-500 text-green-800 p-4 mb-6 rounded-r-lg flex items-center gap-3">
    <i class="fas fa-check-circle text-green-500 flex-shrink-0"></i>
    <p><?php echo htmlspecialchars($flash_success); ?></p>
</div>
<?php endif; ?>
<?php if ($flash_error || $error): ?>
<div class="bg-red-50 border-l-4 border-red-500 text-red-800 p-4 mb-6 rounded-r-lg flex items-center gap-3">
    <i class="fas fa-exclamation-circle text-red-500 flex-shrink-0"></i>
    <p><?php echo htmlspecialchars($flash_error ?? $error); ?></p>
</div>
<?php endif; ?>

<!-- Statistics — counts use same JOIN so numbers match what's in the list below -->
<?php
$stat_pending = $db->query(
    "SELECT COUNT(*) AS c FROM credit_orders co
     INNER JOIN customers c ON co.customer_id = c.id
     WHERE co.status = 'pending_approval'"
)->first()->c;

$stat_escalated = $is_admin ? $db->query(
    "SELECT COUNT(*) AS c FROM credit_orders co
     INNER JOIN customers c ON co.customer_id = c.id
     WHERE co.status = 'escalated'"
)->first()->c : 0;

$stat_approved_today = $db->query(
    "SELECT COUNT(*) AS c FROM credit_orders co
     INNER JOIN customers c ON co.customer_id = c.id
     WHERE co.status = 'approved' AND DATE(co.updated_at) = CURDATE()"
)->first()->c;
?>
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <div class="bg-orange-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Pending Approval</p>
        <p class="text-3xl font-bold mt-2"><?php echo $stat_pending; ?></p>
        <p class="text-xs opacity-75 mt-1">Awaiting your decision</p>
    </div>
    <?php if ($is_admin): ?>
    <div class="bg-red-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Escalated (&ge;80% credit)</p>
        <p class="text-3xl font-bold mt-2"><?php echo $stat_escalated; ?></p>
        <p class="text-xs opacity-75 mt-1">Requires admin override</p>
    </div>
    <?php else: ?>
    <div class="bg-gray-400 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Escalated (&ge;80% credit)</p>
        <p class="text-3xl font-bold mt-2">—</p>
        <p class="text-xs opacity-75 mt-1">Handled by admin</p>
    </div>
    <?php endif; ?>
    <div class="bg-green-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Approved Today</p>
        <p class="text-3xl font-bold mt-2"><?php echo $stat_approved_today; ?></p>
        <p class="text-xs opacity-75 mt-1">Orders sent to production</p>
    </div>
</div>

<!-- Orders List -->
<?php if (!empty($orders)): ?>
<?php foreach ($orders as $order): 
    $status_colors = [
        'pending_approval' => 'orange',
        'escalated' => 'red'
    ];
    $color = $status_colors[$order->status] ?? 'gray';
    
    $usage_color = 'blue';
    $credit_usage_pct = $order->credit_usage_percent ?? 0;
    if ($credit_usage_pct >= 80) $usage_color = 'red';
    elseif ($credit_usage_pct >= 60) $usage_color = 'yellow';
?>
<div class="bg-white rounded-lg shadow-md mb-3 overflow-hidden">
    <!-- ── Clickable summary row (folded view) ─────────────────────────── -->
    <div class="ord-toggle px-5 py-3.5 bg-gray-50 hover:bg-gray-100 cursor-pointer select-none transition-colors border-b border-gray-200"
         data-target="ordBody<?php echo $order->id; ?>">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5">
            <i class="fas fa-chevron-right ord-chev text-gray-400 text-sm transition-transform duration-200"></i>
            <div class="min-w-[170px]">
                <span class="font-bold text-gray-900"><?php echo htmlspecialchars($order->order_number); ?></span>
                <div class="text-[11px] text-gray-400"><?php echo date('M j, Y g:i A', strtotime($order->order_date)); ?></div>
            </div>
            <div class="min-w-[160px] max-w-[220px]">
                <div class="font-semibold text-gray-800 text-sm truncate"><?php echo htmlspecialchars($order->customer_name); ?></div>
                <div class="text-[11px] text-gray-400"><?php echo htmlspecialchars($order->customer_phone); ?></div>
            </div>
            <span class="px-2.5 py-0.5 text-xs font-semibold rounded-full bg-<?php echo $color; ?>-100 text-<?php echo $color; ?>-800 whitespace-nowrap">
                <?php echo ucwords(str_replace('_', ' ', $order->status)); ?>
            </span>
            <?php if ($order->status === 'escalated'): ?>
            <span class="px-2.5 py-0.5 text-xs font-semibold rounded-full bg-purple-100 text-purple-800 whitespace-nowrap">
                <i class="fas fa-exclamation-triangle"></i> Admin
            </span>
            <?php endif; ?>
            <span class="px-2.5 py-0.5 text-xs font-bold rounded-full bg-<?php echo $usage_color; ?>-100 text-<?php echo $usage_color; ?>-700 whitespace-nowrap"
                  title="Customer credit usage">
                <?php echo $credit_usage_pct >= 999 ? 'OVER LIMIT' : number_format($credit_usage_pct, 0) . '% credit'; ?>
            </span>
            <div class="ml-auto text-right">
                <p class="text-lg font-bold text-blue-600 leading-tight">৳<?php echo number_format($order->total_amount, 0); ?></p>
                <p class="text-[11px] text-gray-500">Due: ৳<?php echo number_format($order->balance_due, 0); ?></p>
            </div>
        </div>
    </div>

    <!-- ── Foldable body ────────────────────────────────────────────────── -->
    <div id="ordBody<?php echo $order->id; ?>" class="hidden">

    <!-- Customer & Credit Info -->
    <div class="p-6 border-b border-gray-200">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <h4 class="font-semibold text-gray-800 mb-2">Customer Information</h4>
                <p class="text-sm"><strong>Name:</strong> <?php echo htmlspecialchars($order->customer_name); ?></p>
                <p class="text-sm"><strong>Phone:</strong> <?php echo htmlspecialchars($order->customer_phone); ?></p>
                <p class="text-sm"><strong>Delivery Address:</strong></p>
                <p class="text-sm text-gray-700 ml-2"><?php echo htmlspecialchars($order->shipping_address ?? ''); ?></p>
                <?php if ($order->special_instructions): ?>
                <p class="text-sm mt-2 p-2 bg-blue-50 border border-blue-200 rounded">
                    <i class="fas fa-info-circle text-blue-600 mr-1"></i>
                    <?php echo htmlspecialchars($order->special_instructions); ?>
                </p>
                <?php endif; ?>
            </div>
            <div>
                <h4 class="font-semibold text-gray-800 mb-2">Credit Analysis</h4>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Credit Limit:</span>
                        <span class="font-bold">৳<?php echo number_format($order->credit_limit, 0); ?></span>
                    </div>
                    
                    <div class="flex justify-between">
                        <span class="text-gray-600">Previous Due (Carried):</span>
                        <span class="font-bold text-gray-500">৳<?php echo number_format($order->initial_due ?? 0, 0); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">New Orders Balance:</span>
                        <span class="font-bold text-orange-600">৳<?php echo number_format($order->current_balance - $order->initial_due, 0); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Available Credit:</span>
                        <span class="font-bold text-green-600">৳<?php echo number_format($order->available_credit, 0); ?></span>
                    </div>
                    
                    
                    
                    <div class="flex justify-between pt-2 border-t">
                        <span class="text-gray-600">Order Amount:</span>
                        <span class="font-bold text-blue-600">৳<?php echo number_format($order->balance_due, 0); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Credit Usage:</span>
                        <span class="font-bold text-<?php echo $usage_color; ?>-600">
                            <?php echo $credit_usage_pct >= 999
                                ? 'OVER LIMIT'
                                : number_format($credit_usage_pct, 1) . '%'; ?>
                        </span>
                    </div>
                </div>

                <?php
                // Delegated officers approve within their own cap even for risky customers
                // ($my_approval_limit is null for role admins, so this is non-admin only)
                $has_delegation = $my_approval_limit !== null;
                ?>
                <?php if ($credit_usage_pct >= 999): ?>
                <div class="mt-3 p-3 bg-red-50 border border-red-200 rounded">
                    <p class="text-sm text-red-700 font-semibold">
                        <i class="fas fa-ban mr-1"></i>
                        Customer is OVER credit limit —
                        <span class="text-red-800">Superadmin approval required</span>
                        <?php if (!$is_superadmin): ?>
                        <span class="block text-xs font-normal mt-0.5">A delegated limit or admin role cannot approve an over-credit-limit order.</span>
                        <?php endif; ?>
                    </p>
                </div>
                <?php elseif ($credit_usage_pct >= 80): ?>
                <div class="mt-3 p-3 bg-red-50 border border-red-200 rounded">
                    <p class="text-sm text-red-700 font-semibold">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        High Credit Usage
                        <?php if ($has_delegation && (float)$order->total_amount <= $my_approval_limit): ?>
                        — <span class="text-amber-700">you may still approve under your delegated limit</span>
                        <?php else: ?>
                        - Requires Admin Approval
                        <?php endif; ?>
                    </p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Order Items -->
    <div class="p-6 border-b border-gray-200">
        <h4 class="font-semibold text-gray-800 mb-3">Order Items</h4>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Variant</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Price</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php
                    // Get order items
                    $items = $db->query(
                        "SELECT coi.*, 
                                p.base_name as product_name,
                                pv.grade,
                                pv.weight_variant,
                                pv.unit_of_measure,
                                pv.sku as variant_sku
                         FROM credit_order_items coi
                         JOIN products p ON coi.product_id = p.id
                         LEFT JOIN product_variants pv ON coi.variant_id = pv.id
                         WHERE coi.order_id = ?",
                        [$order->id]
                    )->results();
                    
                    foreach ($items as $item):
                        $variant_display = [];
                        if ($item->grade) $variant_display[] = $item->grade;
                        if ($item->weight_variant) $variant_display[] = $item->weight_variant;
                    ?>
                    <tr>
                        <td class="px-4 py-2"><?php echo htmlspecialchars($item->product_name); ?></td>
                        <td class="px-4 py-2">
                            <?php echo htmlspecialchars(implode(' - ', $variant_display)); ?>
                            <?php if ($item->variant_sku): ?>
                                <span class="text-xs text-gray-500">(<?php echo htmlspecialchars($item->variant_sku); ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <?php echo $item->quantity; ?> <?php echo $item->unit_of_measure; ?>
                        </td>
                        <td class="px-4 py-2 text-right">৳<?php echo number_format($item->unit_price, 2); ?></td>
                        <td class="px-4 py-2 text-right font-medium">৳<?php echo number_format($item->line_total, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-gray-50">
                    <tr>
                        <td colspan="4" class="px-4 py-2 text-right font-semibold">Subtotal:</td>
                        <td class="px-4 py-2 text-right font-bold">৳<?php echo number_format($order->subtotal, 2); ?></td>
                    </tr>
                    <?php if ($order->discount_amount > 0): ?>
                    <tr>
                        <td colspan="4" class="px-4 py-2 text-right font-semibold">Discount:</td>
                        <td class="px-4 py-2 text-right font-bold text-red-600">-৳<?php echo number_format($order->discount_amount, 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($order->advance_paid > 0): ?>
                    <tr>
                        <td colspan="4" class="px-4 py-2 text-right font-semibold">Advance Paid:</td>
                        <td class="px-4 py-2 text-right font-bold text-green-600">-৳<?php echo number_format($order->advance_paid, 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="bg-blue-50">
                        <td colspan="4" class="px-4 py-2 text-right font-semibold text-lg">Balance Due:</td>
                        <td class="px-4 py-2 text-right font-bold text-blue-600 text-lg">৳<?php echo number_format($order->balance_due, 2); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    
    <!-- Actions -->
    <div class="p-6 bg-gray-50">
        <?php
        // Can approve if: admin, OR delegated officer (their own limit decides),
        // OR non-delegated accounts with usage < 80%
        $can_approve = $is_admin
                    || $my_approval_limit !== null
                    || ($order->credit_usage_percent < 80);
        ?>
        
        <?php if ($can_approve): ?>
        <h4 class="font-semibold text-gray-800 mb-4">Approval Decision</h4>

        <?php $exceeds_my_limit = $my_approval_limit !== null && (float)$order->total_amount > $my_approval_limit; ?>
        <?php if ($exceeds_my_limit): ?>
        <div class="mb-4 p-3 bg-amber-50 border border-amber-300 rounded-lg text-sm text-amber-800">
            <i class="fas fa-gavel mr-1"></i>
            This order (৳<?php echo number_format($order->total_amount, 0); ?>) exceeds your personal approval limit
            of <strong>৳<?php echo number_format($my_approval_limit, 0); ?></strong> —
            submitting will <strong>escalate it to admin</strong> instead of approving.
        </div>
        <?php endif; ?>
        
        <?php // Feature #9: over-credit-limit orders are Superadmin-only — hide the approve form for others.
        if ($credit_usage_pct >= 999 && !$is_superadmin): ?>
        <div class="p-4 bg-red-50 border-2 border-red-300 rounded-lg text-red-800 text-sm">
            <i class="fas fa-user-shield mr-1"></i>
            <strong>Superadmin approval required.</strong> This order is over the customer's credit limit and has been
            escalated. Only a Superadmin can approve it — you can still <strong>reject</strong> it below if needed.
        </div>
        <?php else: ?>
        <!-- Approve Form -->
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="order_id" value="<?php echo $order->id; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

            <!-- Branch Assignment -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Assign to Branch for Production *</label>
                <select name="branch_id" required class="w-full px-4 py-2 border rounded-lg">
                    <option value="">-- Select Branch --</option>
                    <?php foreach ($branches as $branch): ?>
                    <option value="<?php echo $branch->id; ?>"><?php echo htmlspecialchars($branch->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Required Delivery Date *</label>
                <?php
                $req_date     = $order->required_date && $order->required_date !== '0000-00-00'
                                ? $order->required_date
                                : date('Y-m-d', strtotime('+1 day'));
                $req_date_lbl = date('M j, Y', strtotime($req_date));
                ?>
                <input type="date" name="required_date" required class="w-full px-4 py-2 border rounded-lg"
                       value="<?php echo $req_date; ?>"
                       min="<?php echo date('Y-m-d'); ?>">
                <p class="text-xs text-gray-500 mt-1">Delivery by: <?php echo $req_date_lbl; ?> — adjust if needed</p>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Comments (Optional)</label>
                <textarea name="comments" rows="2" class="w-full px-4 py-2 border rounded-lg"
                          placeholder="Add any comments about this approval..."></textarea>
            </div>

            <!-- ── Special Instructions / Holds ─────────────────────────── -->
            <div class="border border-amber-200 rounded-lg overflow-hidden">
                <label class="flex items-center gap-2 px-4 py-3 bg-amber-50 cursor-pointer select-none">
                    <input type="checkbox" class="accent-amber-600 gate-toggle" data-target="gatePanel<?php echo $order->id; ?>">
                    <span class="text-sm font-semibold text-amber-800">
                        <i class="fas fa-hand-paper mr-1"></i>Attach special instructions (production hold / payment condition)
                    </span>
                </label>
                <div id="gatePanel<?php echo $order->id; ?>" class="hidden p-4 space-y-4 bg-white border-t border-amber-100">

                    <!-- Production gate -->
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 cursor-pointer text-sm">
                            <input type="checkbox" name="production_hold" value="1" class="accent-purple-600">
                            <span class="font-medium text-gray-800">Hold production until released</span>
                        </label>
                        <input type="text" name="production_note" maxlength="500"
                               class="w-full px-3 py-2 border rounded-lg text-sm"
                               placeholder="Note to production team (optional)...">
                    </div>

                    <hr class="border-gray-100">

                    <!-- Dispatch gate -->
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 cursor-pointer text-sm">
                            <input type="checkbox" name="dispatch_hold" value="1" class="accent-orange-600 disp-hold-toggle"
                                   data-target="dispCond<?php echo $order->id; ?>">
                            <span class="font-medium text-gray-800">Hold dispatch until payment clearance</span>
                        </label>
                        <div id="dispCond<?php echo $order->id; ?>" class="hidden pl-6 space-y-3">

                            <!-- Decision support: what the customer will owe once this ships -->
                            <?php
                            $ca_out = (float)$order->current_balance;   // true outstanding (ledger)
                            $ca_inv = (float)$order->total_amount;      // posts to ledger at dispatch
                            ?>
                            <div class="p-3 bg-blue-50 border border-blue-200 rounded-lg text-xs text-blue-900 space-y-1">
                                <div class="flex justify-between"><span>Previous due (outstanding now):</span>
                                    <strong>৳<?php echo number_format($ca_out, 0); ?></strong></div>
                                <div class="flex justify-between"><span>This invoice (posts at dispatch):</span>
                                    <strong>+ ৳<?php echo number_format($ca_inv, 0); ?></strong></div>
                                <div class="flex justify-between border-t border-blue-300 pt-1">
                                    <span class="font-semibold">Will owe after this shipment (if nothing paid):</span>
                                    <strong class="text-red-700">৳<?php echo number_format($ca_out + $ca_inv, 0); ?></strong></div>
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Clearance condition</label>
                                <select name="condition_type" class="w-full px-3 py-2 border rounded-lg text-sm cond-type-select"
                                        data-target="condAmt<?php echo $order->id; ?>">
                                    <option value="manual">Accounts clears manually (no fixed amount)</option>
                                    <option value="outstanding_below">Current outstanding drops to ≤ amount (excl. this invoice)</option>
                                    <option value="outstanding_after_ship">Outstanding INCL. this invoice drops to ≤ amount (0 = pay everything)</option>
                                    <option value="amount_received">Payments received since approval ≥ amount</option>
                                </select>
                            </div>
                            <div id="condAmt<?php echo $order->id; ?>" class="hidden space-y-2 cond-amt-box"
                                 data-out="<?php echo $ca_out; ?>" data-inv="<?php echo $ca_inv; ?>">
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Amount (৳)</label>
                                    <input type="number" name="condition_amount" min="0" step="0.01"
                                           class="w-full px-3 py-2 border rounded-lg text-sm cond-amount-input"
                                           placeholder="e.g. 3000000 for ৳30 Lac">
                                </div>
                                <div class="cond-hint hidden p-2 bg-amber-50 border border-amber-200 rounded text-xs font-semibold text-amber-800"></div>
                                <label class="flex items-center gap-2 cursor-pointer text-xs text-gray-600">
                                    <input type="checkbox" name="auto_release" value="1" class="accent-green-600">
                                    Auto-release dispatch when condition met (skip manual clearance — cheque risk!)
                                </label>
                            </div>
                            <input type="text" name="accounts_note" maxlength="500"
                                   class="w-full px-3 py-2 border rounded-lg text-sm"
                                   placeholder="Note to accounts team (optional)...">
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex gap-3">
                <button type="submit" 
                        onclick="return confirm('Approve this order and assign to production?');"
                        class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    <i class="fas fa-check mr-2"></i>
                    <?php
                    if ($exceeds_my_limit)                       echo 'Submit (Escalates to Admin)';
                    elseif ($order->credit_usage_percent >= 80) {
                        if ($is_role_admin)                          echo 'Approve (Admin Override)';
                        elseif ($my_approval_limit !== null)         echo 'Approve (Delegated Authority)';
                        elseif ($has_escalate_override)              echo 'Approve (Admin Override)';
                        else                                         echo 'Submit (Escalates to Admin)';
                    }
                    else                                         echo 'Approve Order';
                    ?>
                </button>
                <button type="button" 
                        onclick="showRejectForm(<?php echo $order->id; ?>)"
                        class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                    <i class="fas fa-times mr-2"></i>Reject Order
                </button>
            </div>
        </form>
        <?php endif; /* Feature #9 over-limit approve-form gate */ ?>

        <!-- Reject Form (Hidden) -->
        <form method="POST" id="rejectForm<?php echo $order->id; ?>" class="hidden mt-4 p-4 bg-red-50 border border-red-200 rounded">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="order_id" value="<?php echo $order->id; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
            
            <label class="block text-sm font-medium text-gray-700 mb-2">Rejection Reason *</label>
            <textarea name="reject_reason" rows="3" required class="w-full px-4 py-2 border rounded-lg" 
                      placeholder="Please provide reason for rejection..."></textarea>
            
            <div class="flex gap-3 mt-3">
                <button type="submit" 
                        onclick="return confirm('Are you sure you want to reject this order?');"
                        class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                    Confirm Rejection
                </button>
                <button type="button" 
                        onclick="hideRejectForm(<?php echo $order->id; ?>)"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
            </div>
        </form>
        
        <?php else: ?>
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
            <p class="text-yellow-800">
                <i class="fas fa-info-circle mr-2"></i>
                This order requires admin approval due to high credit usage (≥80%). It has been escalated automatically.
            </p>
        </div>
        <?php endif; ?>
    </div>
    </div><!-- /foldable body -->
</div>
<?php endforeach; ?>

<?php else: ?>
<div class="bg-white rounded-lg shadow-md p-12 text-center">
    <i class="fas fa-check-circle text-6xl text-green-400 mb-4"></i>
    <h3 class="text-xl font-semibold text-gray-700 mb-2">All Caught Up!</h3>
    <p class="text-gray-600">No orders pending approval at this time.</p>
</div>
<?php endif; ?>

</div>

<script>
function showRejectForm(orderId) {
    document.getElementById('rejectForm' + orderId).classList.remove('hidden');
}

function hideRejectForm(orderId) {
    document.getElementById('rejectForm' + orderId).classList.add('hidden');
}

// ── Foldable order cards ──
document.querySelectorAll('.ord-toggle').forEach(row => {
    row.addEventListener('click', function () {
        const body = document.getElementById(this.dataset.target);
        const chev = this.querySelector('.ord-chev');
        if (!body) return;
        const opening = body.classList.contains('hidden');
        body.classList.toggle('hidden', !opening);
        if (chev) chev.style.transform = opening ? 'rotate(90deg)' : '';
    });
});
// Single order in the queue → open it automatically
(function () {
    const rows = document.querySelectorAll('.ord-toggle');
    if (rows.length === 1) rows[0].click();
})();

// Deep link: ?focus=<order_id> expands that card and scrolls to it
// (used by the order view's action modal)
(function () {
    const id = new URLSearchParams(location.search).get('focus');
    if (!id) return;
    const row = document.querySelector('.ord-toggle[data-target="ordBody' + id + '"]');
    if (row) {
        const body = document.getElementById('ordBody' + id);
        if (body && body.classList.contains('hidden')) row.click();
        setTimeout(() => row.scrollIntoView({ behavior: 'smooth', block: 'start' }), 150);
    }
})();

// ── Approval conditions panel toggles ──
document.querySelectorAll('.gate-toggle').forEach(cb => {
    cb.addEventListener('change', function() {
        const panel = document.getElementById(this.dataset.target);
        if (panel) panel.classList.toggle('hidden', !this.checked);
        // Unchecking the master toggle clears all inputs inside so nothing stale submits
        if (!this.checked && panel) {
            panel.querySelectorAll('input[type=checkbox]').forEach(c => { c.checked = false; c.dispatchEvent(new Event('change')); });
            panel.querySelectorAll('input[type=text], input[type=number]').forEach(i => i.value = '');
            panel.querySelectorAll('select').forEach(s => { s.selectedIndex = 0; s.dispatchEvent(new Event('change')); });
        }
    });
});
document.querySelectorAll('.disp-hold-toggle').forEach(cb => {
    cb.addEventListener('change', function() {
        const panel = document.getElementById(this.dataset.target);
        if (panel) panel.classList.toggle('hidden', !this.checked);
    });
});
document.querySelectorAll('.cond-type-select').forEach(sel => {
    sel.addEventListener('change', function() {
        const amtBox = document.getElementById(this.dataset.target);
        if (amtBox) {
            amtBox.classList.toggle('hidden', this.value === 'manual');
            refreshCondHint(amtBox);
        }
    });
});

// ── Live repayment hint: shows what the customer must deposit and what
//    they will owe after this shipment, as admin types the amount ──
function refreshCondHint(box) {
    const hint  = box.querySelector('.cond-hint');
    const input = box.querySelector('.cond-amount-input');
    if (!hint || !input) return;
    const out  = parseFloat(box.dataset.out) || 0;   // outstanding now
    const inv  = parseFloat(box.dataset.inv) || 0;   // this invoice
    const amt  = parseFloat(input.value)     || 0;
    const wrap = box.closest('div[id^="dispCond"]');
    const type = wrap ? (wrap.querySelector('.cond-type-select') || {}).value : null;
    const fmt  = n => '৳' + Math.max(0, Math.round(n)).toLocaleString();

    if (type === 'manual' || !type || input.value === '') { hint.classList.add('hidden'); return; }
    let txt = '';
    if (type === 'outstanding_below') {
        const deposit = Math.max(0, out - amt);
        txt = 'Customer must deposit ≥ ' + fmt(deposit) + ' before dispatch → will owe ≤ '
            + fmt(amt + inv) + ' after this shipment.';
    } else if (type === 'outstanding_after_ship') {
        const deposit = Math.max(0, out + inv - amt);
        txt = 'Customer must deposit ≥ ' + fmt(deposit) + ' before dispatch → will owe ≤ '
            + fmt(amt) + ' after this shipment' + (amt === 0 ? ' (fully settled)' : '') + '.';
    } else if (type === 'amount_received') {
        txt = 'After ' + fmt(amt) + ' is received → will owe about '
            + fmt(out - amt + inv) + ' after this shipment.';
    }
    hint.textContent = txt;
    hint.classList.remove('hidden');
}
document.querySelectorAll('.cond-amount-input').forEach(inp => {
    inp.addEventListener('input', () => refreshCondHint(inp.closest('.cond-amt-box')));
});
</script>

<?php require_once '../templates/footer.php'; ?>