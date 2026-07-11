<?php
require_once '../core/init.php';

restrict_access();

global $db;
$currentUser = getCurrentUser();
$user_id     = $currentUser['id']   ?? null;
$user_role   = $currentUser['role'] ?? '';
$pageTitle   = 'Goods Returns';

// Superadmin/admin always true; others need 'can_approve' on returns page
$is_admin    = userCanPageAction('credit_sales', 'returns', 'can_approve');
$is_accounts = userCanPageAction('credit_sales', 'customer_payment', 'can_collect');

/* ─── Auto-create returns tables if not exist ─────────────── */
$db->getPdo()->exec("
    CREATE TABLE IF NOT EXISTS `credit_order_returns` (
      `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
      `return_number` varchar(50) NOT NULL,
      `order_id` bigint UNSIGNED NOT NULL,
      `customer_id` bigint UNSIGNED NOT NULL,
      `return_date` date NOT NULL,
      `return_type` enum('full','partial') NOT NULL DEFAULT 'partial',
      `return_reason` text DEFAULT NULL,
      `total_returned_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
      `total_returned_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
      `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
      `created_by_user_id` bigint UNSIGNED NOT NULL,
      `approved_by_user_id` bigint UNSIGNED DEFAULT NULL,
      `approved_at` timestamp NULL DEFAULT NULL,
      `notes` text DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_return_number` (`return_number`),
      KEY `idx_order_id` (`order_id`),
      KEY `idx_customer_id` (`customer_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$db->getPdo()->exec("
    CREATE TABLE IF NOT EXISTS `credit_order_return_items` (
      `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
      `return_id` bigint UNSIGNED NOT NULL,
      `order_item_id` bigint UNSIGNED NOT NULL,
      `product_id` bigint UNSIGNED NOT NULL,
      `variant_id` bigint UNSIGNED DEFAULT NULL,
      `original_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
      `returned_qty` decimal(10,2) NOT NULL,
      `unit_price` decimal(12,2) NOT NULL,
      `returned_amount` decimal(12,2) NOT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_return_id` (`return_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$error   = null;
$success = null;

/* ─── Load order for new return ───────────────────────────── */
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

        // Subtract already-returned quantities for each item
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

/* ─── Handle POST: Create new return ──────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_return') {
    try {
        $order_id     = (int)$_POST['order_id'];
        $return_date  = $_POST['return_date'];
        $reason       = trim($_POST['return_reason'] ?? '');
        $notes        = trim($_POST['notes'] ?? '');
        $qtys         = $_POST['return_qty'] ?? [];   // [item_id => qty]

        $order = $db->query(
            "SELECT co.*, c.current_balance, c.initial_due
             FROM credit_orders co JOIN customers c ON co.customer_id = c.id
             WHERE co.id = ? AND co.status IN ('shipped','delivered')",
            [$order_id]
        )->first();
        if (!$order) throw new Exception("Order not found or not eligible for returns.");

        // Build return lines
        $lines = [];
        $total_returned_amount = 0;
        $total_returned_qty    = 0;

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

            // Check max returnable
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

            $line_amount = $qty * (float)$item->unit_price;
            $lines[] = [
                'order_item_id'   => (int)$item_id,
                'product_id'      => $item->product_id,
                'variant_id'      => $item->variant_id,
                'original_qty'    => (float)$item->quantity,
                'returned_qty'    => $qty,
                'unit_price'      => (float)$item->unit_price,
                'returned_amount' => $line_amount,
            ];
            $total_returned_amount += $line_amount;
            $total_returned_qty    += $qty;
        }

        if (empty($lines)) throw new Exception("No return quantities entered.");

        $return_type = $total_returned_qty >= (float)$db->query(
            "SELECT SUM(quantity) AS total FROM credit_order_items WHERE order_id = ?", [$order_id]
        )->first()->total ? 'full' : 'partial';

        // Generate return number
        $rn_date = date('Ymd', strtotime($return_date));
        $last    = $db->query(
            "SELECT return_number FROM credit_order_returns WHERE return_number LIKE ? ORDER BY id DESC LIMIT 1",
            ["RET-{$rn_date}-%"]
        )->first();
        $seq    = $last ? (int)substr($last->return_number, -4) + 1 : 1;
        $return_number = sprintf("RET-%s-%04d", $rn_date, $seq);

        $pdo = $db->getPdo();
        $pdo->beginTransaction();

        // Insert return header (pending approval)
        $return_id = $db->insert('credit_order_returns', [
            'return_number'          => $return_number,
            'order_id'               => $order_id,
            'customer_id'            => $order->customer_id,
            'return_date'            => $return_date,
            'return_type'            => $return_type,
            'return_reason'          => $reason,
            'total_returned_amount'  => $total_returned_amount,
            'total_returned_qty'     => $total_returned_qty,
            'status'                 => $is_admin ? 'approved' : 'pending',
            'created_by_user_id'     => $user_id,
            'approved_by_user_id'    => $is_admin ? $user_id : null,
            'approved_at'            => $is_admin ? date('Y-m-d H:i:s') : null,
            'notes'                  => $notes,
        ]);

        foreach ($lines as $l) {
            $db->insert('credit_order_return_items', array_merge(['return_id' => $return_id], $l));
        }

        // If auto-approved (admin only), apply financial impact immediately
        if ($is_admin) {
            // Adjust credit_order balance_due and total_amount
            $new_total = max(0, (float)$order->total_amount - $total_returned_amount);
            $new_balance = max(0, (float)$order->balance_due - $total_returned_amount);
            $db->query(
                "UPDATE credit_orders SET total_amount = ?, balance_due = ?, updated_at = NOW() WHERE id = ?",
                [$new_total, $new_balance, $order_id]
            );

            // Reduce customer current_balance
            $new_cust_bal = (float)$order->current_balance - $total_returned_amount;
            $db->query("UPDATE customers SET current_balance = ? WHERE id = ?", [$new_cust_bal, $order->customer_id]);

            // Customer ledger credit note
            $prev = $db->query(
                "SELECT balance_after FROM customer_ledger WHERE customer_id = ? ORDER BY id DESC LIMIT 1",
                [$order->customer_id]
            )->first();
            $prev_bal = $prev ? (float)$prev->balance_after : 0;
            $new_led_bal = $prev_bal - $total_returned_amount;

            $db->insert('customer_ledger', [
                'customer_id'        => $order->customer_id,
                'transaction_date'   => $return_date,
                'transaction_type'   => 'credit_note',
                'reference_type'     => 'credit_order_returns',
                'reference_id'       => $return_id,
                'invoice_number'     => $return_number,
                'description'        => "Goods return {$return_number} against order {$order->order_number} — {$return_type} return",
                'debit_amount'       => 0,
                'credit_amount'      => $total_returned_amount,
                'balance_after'      => $new_led_bal,
                'created_by_user_id' => $user_id,
            ]);

            // Update order status if full return
            if ($return_type === 'full') {
                $db->query("UPDATE credit_orders SET status = 'cancelled', internal_notes = CONCAT(COALESCE(internal_notes,''), '\nFull return {$return_number} processed.') WHERE id = ?", [$order_id]);
            }
        }

        $pdo->commit();

        // Audit log
        auditLog('credit_order_returns', 'created', "Return {$return_number} created for order {$order->order_number} — ৳" . number_format($total_returned_amount, 2), [
            'return_id'       => $return_id,
            'order_id'        => $order_id,
            'return_type'     => $return_type,
            'returned_amount' => $total_returned_amount,
        ]);

        // Telegram notification
        try {
            if (defined('TELEGRAM_BOT_TOKEN') && defined('TELEGRAM_CHAT_ID') && defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
                require_once '../core/classes/TelegramNotifier.php';
                $notifier = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);
                $autoNote = $is_admin ? ' ✅ Auto-approved' : ' ⏳ Pending approval';
                $msg = "<b>🔄 GOODS RETURN RECORDED</b>\n"
                     . "─────────────────────────\n\n"
                     . "<b>Return #:</b> <code>{$return_number}</code>{$autoNote}\n"
                     . "<b>Order:</b> {$order->order_number}\n"
                     . "<b>Type:</b> " . ucfirst($return_type) . " return\n"
                     . "<b>Amount:</b> ৳" . number_format($total_returned_amount, 2) . "\n"
                     . "<b>Reason:</b> " . htmlspecialchars($reason ?: 'Not specified') . "\n"
                     . "<b>By:</b> " . ($currentUser['display_name'] ?? 'Unknown') . "\n\n"
                     . "<i>Ujjal Flour Mills ERP</i>";
                $notifier->sendMessage($msg);
            }
        } catch (Exception $te) {}

        $success = "Return #{$return_number} recorded successfully" . ($is_admin ? " and approved." : ". Awaiting admin approval.");
        $selected_order_id = 0;
        $selected_order    = null;
        $order_items       = [];

    } catch (Exception $e) {
        if ($db->getPdo()->inTransaction()) $db->getPdo()->rollBack();
        $error = $e->getMessage();
    }
}

/* ─── Handle POST: Approve / Reject return ─────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['approve_return','reject_return'])) {
    if (!$is_admin) {
        $error = "Permission denied. Only admin or Superadmin can approve/reject returns.";
    } else {
        try {
            $ret_id = (int)$_POST['return_id'];
            $action = $_POST['action'];

            $ret = $db->query("SELECT * FROM credit_order_returns WHERE id = ? AND status = 'pending'", [$ret_id])->first();
            if (!$ret) throw new Exception("Return not found or already processed.");

            if ($action === 'approve_return') {
                $pdo = $db->getPdo();
                $pdo->beginTransaction();

                $order = $db->query(
                    "SELECT co.*, c.current_balance FROM credit_orders co JOIN customers c ON co.customer_id = c.id WHERE co.id = ?",
                    [$ret->order_id]
                )->first();

                // Apply financial impact
                $new_total   = max(0, (float)$order->total_amount - (float)$ret->total_returned_amount);
                $new_balance = max(0, (float)$order->balance_due  - (float)$ret->total_returned_amount);
                $db->query("UPDATE credit_orders SET total_amount = ?, balance_due = ? WHERE id = ?",
                           [$new_total, $new_balance, $ret->order_id]);

                $new_cust_bal = (float)$order->current_balance - (float)$ret->total_returned_amount;
                $db->query("UPDATE customers SET current_balance = ? WHERE id = ?", [$new_cust_bal, $ret->customer_id]);

                $prev = $db->query(
                    "SELECT balance_after FROM customer_ledger WHERE customer_id = ? ORDER BY id DESC LIMIT 1",
                    [$ret->customer_id]
                )->first();
                $new_led_bal = ($prev ? (float)$prev->balance_after : 0) - (float)$ret->total_returned_amount;
                $db->insert('customer_ledger', [
                    'customer_id'        => $ret->customer_id,
                    'transaction_date'   => $ret->return_date,
                    'transaction_type'   => 'credit_note',
                    'reference_type'     => 'credit_order_returns',
                    'reference_id'       => $ret_id,
                    'invoice_number'     => $ret->return_number,
                    'description'        => "Goods return {$ret->return_number} approved — " . ucfirst($ret->return_type) . " return",
                    'debit_amount'       => 0,
                    'credit_amount'      => $ret->total_returned_amount,
                    'balance_after'      => $new_led_bal,
                    'created_by_user_id' => $user_id,
                ]);

                if ($ret->return_type === 'full') {
                    $db->query("UPDATE credit_orders SET status = 'cancelled' WHERE id = ?", [$ret->order_id]);
                }

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
            if ($db->getPdo()->inTransaction()) $db->getPdo()->rollBack();
            $error = $e->getMessage();
        }
    }
}

/* ─── Load return list ─────────────────────────────────────── */
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$ret_status= $_GET['ret_status']?? '';

$ret_conditions = ["r.return_date BETWEEN ? AND ?"];
$ret_params     = [$date_from, $date_to];
if (!empty($ret_status)) { $ret_conditions[] = "r.status = ?"; $ret_params[] = $ret_status; }

$return_list = $db->query(
    "SELECT r.*, c.name AS customer_name, co.order_number,
            u.display_name AS created_by_name
     FROM credit_order_returns r
     JOIN customers c ON r.customer_id = c.id
     JOIN credit_orders co ON r.order_id = co.id
     LEFT JOIN users u ON r.created_by_user_id = u.id
     WHERE " . implode(' AND ', $ret_conditions) . "
     ORDER BY r.created_at DESC LIMIT 100",
    $ret_params
)->results();

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<!-- ── Header ──────────────────────────────────────────────── -->
<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <div>
        <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
        <p class="text-gray-500 mt-1">Record and manage returned goods from delivered credit orders.</p>
    </div>
    <a href="index.php" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
        <i class="fas fa-arrow-left mr-2"></i>Dashboard
    </a>
</div>

<?php if ($error): ?>
<div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg">
    <p class="font-bold">Error</p><p><?php echo htmlspecialchars($error); ?></p>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-r-lg">
    <p class="font-bold">Success</p><p><?php echo htmlspecialchars($success); ?></p>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════
     USAGE GUIDE
════════════════════════════════════════════════════════════ -->
<div x-data="{ open: false }" class="mb-6">
    <button @click="open = !open"
            class="flex items-center gap-2 text-sm font-medium text-blue-700 bg-blue-50 border border-blue-200 px-4 py-2 rounded-lg hover:bg-blue-100 transition-colors">
        <i class="fas fa-info-circle"></i>
        <span x-text="open ? 'Hide Usage Guide' : 'Show Usage Guide'">Show Usage Guide</span>
        <i class="fas fa-chevron-down transition-transform" :class="{ 'rotate-180': open }"></i>
    </button>
    <div x-show="open" x-collapse class="mt-3 bg-blue-50 border border-blue-200 rounded-lg p-5 text-sm text-blue-900">
        <h3 class="font-bold text-base mb-3"><i class="fas fa-undo-alt mr-2 text-orange-500"></i>Goods Returns — How it Works</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <h4 class="font-semibold mb-2">📋 Step-by-Step Process</h4>
                <ol class="list-decimal ml-4 space-y-1">
                    <li>Search for the delivered / shipped order using order number or customer name.</li>
                    <li>Click <strong>Return</strong> next to the order to load its items.</li>
                    <li>Enter the quantity to return for each product (partial returns are allowed).</li>
                    <li>Set the return date and provide a reason.</li>
                    <li>Submit — the return is created as <strong>Pending</strong> (unless submitted by an admin).</li>
                    <li>An admin/Superadmin must <strong>Approve</strong> the return for ledger impact to apply.</li>
                </ol>
            </div>
            <div>
                <h4 class="font-semibold mb-2">⚙️ What Happens on Approval</h4>
                <ul class="list-disc ml-4 space-y-1">
                    <li><strong>Credit Note</strong> is created in the customer ledger for the returned amount.</li>
                    <li>Order's <strong>Balance Due</strong> is reduced by the returned goods value.</li>
                    <li>Customer's <strong>outstanding balance</strong> is adjusted accordingly.</li>
                    <li>A <strong>Full Return</strong> will mark the order as <em>Cancelled</em>.</li>
                    <li>Returns can be partial — multiple returns per order are supported.</li>
                </ul>
                <h4 class="font-semibold mt-3 mb-2">🔒 Permissions</h4>
                <ul class="list-disc ml-4 space-y-1">
                    <li><strong>All roles</strong> — can submit a return request (goes to Pending).</li>
                    <li><strong>Admin / Superadmin</strong> — submissions are auto-approved; can also approve, reject, and view all returns.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     NEW RETURN FORM
════════════════════════════════════════════════════════════ -->
<div class="bg-white rounded-lg shadow-lg p-6 mb-8">
    <h2 class="text-xl font-bold text-gray-800 mb-4"><i class="fas fa-undo-alt mr-2 text-orange-500"></i>Record New Return</h2>

    <!-- Step 1: Order search -->
    <form method="GET" class="flex flex-wrap gap-3 mb-6">
        <input type="hidden" name="tab" value="new">
        <div class="flex-1 min-w-[200px]">
            <label class="block text-xs font-medium text-gray-600 mb-1">Order Number / Customer</label>
            <input type="text" name="order_search" id="order_search_box" autocomplete="off"
                   value="<?php echo htmlspecialchars($_GET['order_search'] ?? ''); ?>"
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
    // Order search results
    $search_results = [];
    if (!empty($_GET['order_search']) && isset($_GET['search_order'])) {
        $sq = '%' . $_GET['order_search'] . '%';
        $search_results = $db->query(
            "SELECT co.id, co.order_number, co.total_amount, co.balance_due, co.status, co.order_date,
                    c.name AS customer_name
             FROM credit_orders co
             JOIN customers c ON co.customer_id = c.id
             WHERE co.status IN ('shipped','delivered') AND co.balance_due >= 0
               AND (co.order_number LIKE ? OR c.name LIKE ?)
             ORDER BY co.order_date DESC LIMIT 15",
            [$sq, $sq]
        )->results();
    }
    if (!empty($search_results)): ?>
    <div class="mb-6 border border-orange-200 rounded-lg overflow-hidden">
        <div class="bg-orange-50 px-4 py-2 text-sm font-medium text-orange-800">
            Select an order to process return:
        </div>
        <table class="min-w-full text-sm divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left">Order #</th>
                    <th class="px-4 py-2 text-left">Customer</th>
                    <th class="px-4 py-2 text-left">Date</th>
                    <th class="px-4 py-2 text-right">Total</th>
                    <th class="px-4 py-2 text-right">Balance Due</th>
                    <th class="px-4 py-2 text-center">Status</th>
                    <th class="px-4 py-2 text-center">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($search_results as $sr): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2 font-medium text-blue-700"><?php echo htmlspecialchars($sr->order_number); ?></td>
                    <td class="px-4 py-2"><?php echo htmlspecialchars($sr->customer_name); ?></td>
                    <td class="px-4 py-2 text-gray-500"><?php echo date('d-M-Y', strtotime($sr->order_date)); ?></td>
                    <td class="px-4 py-2 text-right">৳<?php echo number_format($sr->total_amount, 0); ?></td>
                    <td class="px-4 py-2 text-right text-red-600">৳<?php echo number_format($sr->balance_due, 0); ?></td>
                    <td class="px-4 py-2 text-center">
                        <span class="px-2 py-0.5 rounded text-xs font-semibold
                            <?php echo $sr->status === 'delivered' ? 'bg-green-100 text-green-800' : 'bg-teal-100 text-teal-800'; ?>">
                            <?php echo ucfirst($sr->status); ?>
                        </span>
                    </td>
                    <td class="px-4 py-2 text-center">
                        <a href="?order_id=<?php echo $sr->id; ?>"
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

    <!-- Return items form -->
    <?php if ($selected_order): ?>
    <div class="border border-orange-200 rounded-lg overflow-hidden">
        <div class="bg-orange-50 px-4 py-3 flex justify-between items-center">
            <div>
                <p class="font-bold text-orange-800">Order: <?php echo htmlspecialchars($selected_order->order_number); ?></p>
                <p class="text-sm text-orange-700">
                    Customer: <strong><?php echo htmlspecialchars($selected_order->customer_name); ?></strong>
                    &nbsp;|&nbsp; Total: ৳<?php echo number_format($selected_order->total_amount, 2); ?>
                    &nbsp;|&nbsp; Balance Due: ৳<?php echo number_format($selected_order->balance_due, 2); ?>
                </p>
            </div>
            <a href="returns.php" class="text-sm text-orange-600 hover:underline">✕ Clear</a>
        </div>

        <form method="POST" class="p-4">
            <input type="hidden" name="action" value="create_return">
            <input type="hidden" name="order_id" value="<?php echo $selected_order->id; ?>">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Return Date *</label>
                    <input type="date" name="return_date" required value="<?php echo date('Y-m-d'); ?>"
                           class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-orange-500">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Return Reason *</label>
                    <input type="text" name="return_reason" required
                           placeholder="e.g., Damaged goods, Wrong product, Quality issue..."
                           class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-orange-500">
                </div>
            </div>

            <!-- Items table -->
            <div class="overflow-x-auto mb-4">
                <table class="min-w-full text-sm border border-gray-200 rounded-lg overflow-hidden">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-gray-600">Product</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600">Ordered</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600">Already Returned</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600">Returnable</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600">Unit Price</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600">Return Qty *</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600">Credit Value</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($order_items as $item):
                            $label = trim(($item->grade ?? '') . ' ' . ($item->weight_variant ?? ''));
                        ?>
                        <tr class="hover:bg-gray-50" id="item-row-<?php echo $item->id; ?>">
                            <td class="px-3 py-2">
                                <div class="font-medium"><?php echo htmlspecialchars($item->product_name); ?></div>
                                <?php if ($label): ?><div class="text-xs text-gray-400"><?php echo htmlspecialchars($label); ?></div><?php endif; ?>
                            </td>
                            <td class="px-3 py-2 text-right"><?php echo number_format($item->quantity, 1); ?></td>
                            <td class="px-3 py-2 text-right text-orange-600"><?php echo $item->already_returned > 0 ? number_format($item->already_returned, 1) : '—'; ?></td>
                            <td class="px-3 py-2 text-right font-semibold <?php echo $item->returnable_qty <= 0 ? 'text-red-500' : 'text-green-700'; ?>">
                                <?php echo number_format($item->returnable_qty, 1); ?>
                            </td>
                            <td class="px-3 py-2 text-right">৳<?php echo number_format($item->unit_price, 2); ?></td>
                            <td class="px-3 py-2 text-right">
                                <?php if ($item->returnable_qty > 0): ?>
                                <input type="number" name="return_qty[<?php echo $item->id; ?>]"
                                       min="0" max="<?php echo $item->returnable_qty; ?>" step="0.5"
                                       value="0"
                                       class="w-24 px-2 py-1 border rounded text-right focus:ring-2 focus:ring-orange-500"
                                       oninput="calcCredit(<?php echo $item->id; ?>, <?php echo $item->unit_price; ?>)">
                                <?php else: ?>
                                <span class="text-xs text-gray-400 italic">Fully returned</span>
                                <input type="hidden" name="return_qty[<?php echo $item->id; ?>]" value="0">
                                <?php endif; ?>
                            </td>
                            <td class="px-3 py-2 text-right font-semibold text-orange-700" id="credit-<?php echo $item->id; ?>">
                                ৳0.00
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-gray-50 border-t-2 border-gray-300">
                        <tr>
                            <td colspan="6" class="px-3 py-2 text-right font-bold text-gray-700">Total Credit Note:</td>
                            <td class="px-3 py-2 text-right font-bold text-orange-700 text-lg" id="total-credit">৳0.00</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
                <textarea name="notes" rows="2"
                          placeholder="Any additional notes about this return..."
                          class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-orange-500"></textarea>
            </div>

            <div class="flex justify-end gap-3">
                <a href="returns.php" class="px-5 py-2 border border-gray-300 text-sm rounded-lg text-gray-700 hover:bg-gray-50">Cancel</a>
                <button type="submit"
                        class="px-6 py-2 bg-orange-600 text-white font-bold rounded-lg hover:bg-orange-700 shadow">
                    <i class="fas fa-undo-alt mr-2"></i>Submit Return
                </button>
            </div>
        </form>
    </div>
    <?php elseif (empty($search_results)): ?>
    <div class="text-center py-8 text-gray-400 border-2 border-dashed border-gray-300 rounded-lg">
        <i class="fas fa-search text-3xl mb-3"></i>
        <p class="font-medium">Search for a delivered or shipped order above to record a return.</p>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════
     RETURNS LIST
════════════════════════════════════════════════════════════ -->
<div class="bg-white rounded-lg shadow-lg overflow-hidden">
    <div class="p-4 border-b bg-gray-50 flex flex-wrap justify-between items-center gap-4">
        <h2 class="text-lg font-bold text-gray-800">Returns History</h2>
        <form method="GET" class="flex flex-wrap gap-2">
            <input type="date" name="date_from" value="<?php echo $date_from; ?>"
                   class="px-2 py-1.5 border rounded text-sm focus:ring-2 focus:ring-orange-500">
            <input type="date" name="date_to" value="<?php echo $date_to; ?>"
                   class="px-2 py-1.5 border rounded text-sm focus:ring-2 focus:ring-orange-500">
            <select name="ret_status" class="px-2 py-1.5 border rounded text-sm focus:ring-2 focus:ring-orange-500">
                <option value="">All Statuses</option>
                <option value="pending"  <?php echo $ret_status === 'pending'  ? 'selected' : ''; ?>>Pending</option>
                <option value="approved" <?php echo $ret_status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="rejected" <?php echo $ret_status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
            </select>
            <button type="submit" class="px-3 py-1.5 bg-gray-700 text-white text-sm rounded hover:bg-gray-800">
                <i class="fas fa-filter mr-1"></i> Filter
            </button>
        </form>
    </div>

    <?php if (empty($return_list)): ?>
    <div class="p-10 text-center text-gray-500">
        <i class="fas fa-box-open text-4xl mb-3 text-gray-300"></i>
        <p>No returns found for the selected period.</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-100">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Return #</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Order #</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Customer</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Date</th>
                    <th class="px-4 py-3 text-center font-medium text-gray-600">Type</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-600">Credit Note</th>
                    <th class="px-4 py-3 text-center font-medium text-gray-600">Status</th>
                    <th class="px-4 py-3 text-center font-medium text-gray-600">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($return_list as $r):
                    $sc = ['pending'=>'bg-yellow-100 text-yellow-800','approved'=>'bg-green-100 text-green-800','rejected'=>'bg-red-100 text-red-800'][$r->status] ?? 'bg-gray-100 text-gray-800';
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium text-orange-700"><?php echo htmlspecialchars($r->return_number); ?></td>
                    <td class="px-4 py-3">
                        <a href="credit_order_view.php?id=<?php echo $r->order_id; ?>" class="text-blue-600 hover:underline">
                            <?php echo htmlspecialchars($r->order_number); ?>
                        </a>
                    </td>
                    <td class="px-4 py-3"><?php echo htmlspecialchars($r->customer_name); ?></td>
                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap"><?php echo date('d-M-Y', strtotime($r->return_date)); ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-0.5 text-xs rounded-full <?php echo $r->return_type === 'full' ? 'bg-red-100 text-red-700' : 'bg-orange-100 text-orange-700'; ?>">
                            <?php echo ucfirst($r->return_type); ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-right font-bold text-orange-700">
                        ৳<?php echo number_format($r->total_returned_amount, 2); ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-0.5 text-xs font-bold rounded-full <?php echo $sc; ?>">
                            <?php echo ucfirst($r->status); ?>
                        </span>
                    </td>
                    <?php if ($is_admin): ?>
                    <td class="px-4 py-3 text-center">
                        <a href="credit_order_view.php?id=<?php echo $r->order_id; ?>" class="text-blue-500 hover:text-blue-700 mr-2" title="View Order">
                            <i class="fas fa-eye text-xs"></i>
                        </a>
                        <?php if ($r->status === 'pending'): ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="return_id" value="<?php echo $r->id; ?>">
                            <button name="action" value="approve_return"
                                    class="px-2 py-1 bg-green-600 text-white text-xs rounded hover:bg-green-700 mr-1"
                                    onclick="return confirm('Approve this return? This will update the customer ledger.')">
                                <i class="fas fa-check mr-1"></i>Approve
                            </button>
                            <button name="action" value="reject_return"
                                    class="px-2 py-1 bg-red-500 text-white text-xs rounded hover:bg-red-600"
                                    onclick="return confirm('Reject this return?')">
                                <i class="fas fa-times mr-1"></i>Reject
                            </button>
                        </form>
                        <?php else: ?>
                        <span class="text-xs text-gray-400">—</span>
                        <?php endif; ?>
                    </td>
                    <?php else: ?>
                    <td class="px-4 py-3 text-center">
                        <a href="credit_order_view.php?id=<?php echo $r->order_id; ?>" class="text-blue-500 hover:text-blue-700" title="View Order">
                            <i class="fas fa-eye text-xs"></i>
                        </a>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

</div>

<script>
const itemPrices = {};
<?php foreach ($order_items ?? [] as $item): ?>
itemPrices[<?php echo $item->id; ?>] = <?php echo (float)$item->unit_price; ?>;
<?php endforeach; ?>

function calcCredit(itemId, price) {
    const qty = parseFloat(document.querySelector(`input[name="return_qty[${itemId}]"]`)?.value) || 0;
    const credit = qty * price;
    document.getElementById('credit-' + itemId).textContent = '৳' + credit.toFixed(2);
    calcTotal();
}

function calcTotal() {
    let total = 0;
    document.querySelectorAll('[id^="credit-"]').forEach(el => {
        total += parseFloat(el.textContent.replace('৳','').replace(/,/g,'')) || 0;
    });
    document.getElementById('total-credit').textContent = '৳' + total.toFixed(2);
}
</script>

<?php require_once '../templates/footer.php'; ?>
