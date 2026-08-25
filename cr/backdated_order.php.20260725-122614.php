<?php
/**
 * Backdated Order (Reconciliation) — Superadmin/admin only.
 *
 * For orders that ALREADY happened in real life (already delivered) before this
 * system recorded them. Skips the entire approval → production → dispatch
 * pipeline: the order is created directly as 'delivered', the invoice posts to
 * the ledger/journal immediately (reusing the exact same accounting logic as the
 * live "Goods on Board" step in credit_dispatch.php), and the customer's balance
 * is correct right away so Collect Payment can be used against it.
 *
 * Prices are entered MANUALLY per line — product_prices only holds today's price
 * table, not a historical one, so auto-pricing would be wrong for old orders.
 */
require_once '../core/init.php';

restrict_access(['Superadmin', 'admin'], 'credit_sales', 'backdated_order');

global $db;
$currentUser = getCurrentUser();
$user_id     = $currentUser['id'] ?? null;
$pageTitle   = 'Backdated Order (Reconciliation)';
$error       = null;

// ── Customers (true balance = initial_due + net ledger, same formula as create_order.php) ──
$customers = $db->query(
    "SELECT c.id, c.name, c.business_name, c.phone_number, c.business_address, c.credit_limit, c.initial_due,
            COALESCE(c.initial_due, 0)
                + COALESCE(tb.total_debit,  0)
                - COALESCE(tb.total_credit, 0) AS true_balance
     FROM customers c
     LEFT JOIN (
         SELECT customer_id, SUM(debit_amount) AS total_debit, SUM(credit_amount) AS total_credit
         FROM customer_ledger WHERE reference_type != 'initial_due'
         GROUP BY customer_id
     ) tb ON tb.customer_id = c.id
     WHERE c.status = 'active' AND c.customer_type = 'Credit'
     ORDER BY c.name ASC"
)->results();

// ── Products + variants, with today's price as an informational reference only ──
$products = $db->query("SELECT id, base_name FROM products WHERE status = 'active' ORDER BY base_name ASC")->results();
$variants = $db->query(
    "SELECT pv.id, pv.product_id, pv.grade, pv.weight_variant, pv.unit_of_measure, pv.sku,
            (SELECT pp.unit_price FROM product_prices pp
             WHERE pp.variant_id = pv.id AND pp.status = 'active' AND pp.is_active = 1
             ORDER BY pp.effective_date DESC, pp.id DESC LIMIT 1) AS reference_price
     FROM product_variants pv
     WHERE pv.status = 'active'
     ORDER BY pv.id ASC"
)->results();
$product_variants = [];
foreach ($variants as $v) { $product_variants[$v->product_id][] = $v; }

$branches = $db->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name ASC")->results();

// ── Handle submission ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_backdated_order') {
    try {
        $session_csrf = $_SESSION['csrf_token'] ?? '';
        $post_csrf    = $_POST['csrf_token']    ?? '';
        if (!$session_csrf || !$post_csrf || !hash_equals($session_csrf, $post_csrf)) {
            throw new Exception('Invalid or missing security token. Please refresh the page and try again.');
        }

        $today       = date('Y-m-d');
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        $branch_id   = (int)($_POST['branch_id'] ?? 0);
        $order_date  = trim($_POST['order_date'] ?? '');
        $delivery_date = trim($_POST['delivery_date'] ?? '');
        $reconciliation_note = trim($_POST['reconciliation_note'] ?? '');
        $advance_paid = floatval($_POST['advance_paid'] ?? 0);
        $shipping_address = trim($_POST['shipping_address'] ?? '');

        if ($customer_id <= 0)                throw new Exception('Please select a customer.');
        if ($branch_id <= 0)                  throw new Exception('Please select the branch this sale came from.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $order_date) || strtotime($order_date) === false || $order_date > $today) {
            throw new Exception('Order date must be a valid date, not in the future.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivery_date) || strtotime($delivery_date) === false || $delivery_date > $today) {
            throw new Exception('Delivery date must be a valid date, not in the future.');
        }
        if ($reconciliation_note === '') throw new Exception('Please note why this backdated order is being recorded (for the audit trail).');

        $items = json_decode($_POST['items_json'] ?? '[]', true);
        if (!is_array($items) || empty($items)) throw new Exception('Add at least one item.');

        $subtotal = 0; $discount_total = 0; $tax_total = 0;
        foreach ($items as $it) {
            $qty   = (float)($it['quantity']   ?? 0);
            $price = (float)($it['unit_price'] ?? 0);
            if ($qty <= 0)   throw new Exception('Every item needs a quantity greater than zero.');
            if ($price < 0)  throw new Exception('Unit price cannot be negative.');
            $subtotal       += $qty * $price;
            $discount_total += (float)($it['discount'] ?? 0);
            $tax_total      += (float)($it['tax'] ?? 0);
        }
        $total_amount = $subtotal - $discount_total + $tax_total;
        $balance_due  = max(0, $total_amount - $advance_paid);

        $customer = $db->query("SELECT name, initial_due FROM customers WHERE id = ?", [$customer_id])->first();
        if (!$customer) throw new Exception('Customer not found.');

        $ar_account = $db->query("SELECT id FROM chart_of_accounts WHERE account_type = 'Accounts Receivable' LIMIT 1")->first();
        if (!$ar_account) throw new Exception("Chart of Accounts is missing an 'Accounts Receivable' account — cannot post the invoice.");
        $ar_account_id = $ar_account->id;

        $sales_account = $db->query(
            "SELECT id FROM chart_of_accounts
             WHERE account_type = 'Revenue' AND branch_id = ?
               AND (LOWER(name) LIKE '%credit%' OR LOWER(description) LIKE '%credit%')
               AND LOWER(name) NOT LIKE '%pos%'
             ORDER BY id ASC LIMIT 1",
            [$branch_id]
        )->first();
        if (!$sales_account) {
            $sales_account = $db->query(
                "SELECT id FROM chart_of_accounts
                 WHERE account_type = 'Revenue' AND branch_id IS NULL
                   AND (LOWER(name) LIKE '%credit%' OR LOWER(description) LIKE '%credit%')
                 ORDER BY id ASC LIMIT 1"
            )->first();
        }
        if (!$sales_account) throw new Exception('No Credit Sales Revenue account found in Chart of Accounts — cannot post the invoice.');
        $sales_account_id = $sales_account->id;

        $pdo = $db->getPdo();
        $pdo->beginTransaction();

        // Order number scoped to the backdated order_date, not today (Bug 4 fix pattern).
        $date_prefix = date('Ymd', strtotime($order_date));
        $last_order  = $db->query(
            "SELECT order_number FROM credit_orders WHERE order_number LIKE ? ORDER BY id DESC LIMIT 1",
            ["CR-{$date_prefix}-%"]
        )->first();
        $seq          = $last_order ? ((int)substr($last_order->order_number, -4) + 1) : 1;
        $order_number = sprintf("CR-%s-%04d", $date_prefix, $seq);

        $order_id = $db->insert('credit_orders', [
            'order_number'         => $order_number,
            'customer_id'          => $customer_id,
            'order_date'           => $order_date,
            'required_date'        => $delivery_date,
            'order_type'           => 'credit', // credit_orders.order_type ENUM('credit','advance_payment')
            'subtotal'             => $subtotal,
            'discount_amount'      => $discount_total,
            'tax_amount'           => $tax_total,
            'total_amount'         => $total_amount,
            'advance_paid'         => $advance_paid,
            'balance_due'          => $balance_due,
            'status'               => 'delivered',
            'assigned_branch_id'   => $branch_id,
            'shipping_address'     => $shipping_address,
            'special_instructions' => 'Backdated reconciliation entry: ' . $reconciliation_note,
            'approved_by_user_id'  => $user_id,
            'approved_at'          => date('Y-m-d H:i:s'),
            'created_by_user_id'   => $user_id,
        ]);
        if (!$order_id) throw new Exception('Failed to create the order.');

        foreach ($items as $it) {
            $qty   = (float)$it['quantity'];
            $price = (float)$it['unit_price'];
            $disc  = (float)($it['discount'] ?? 0);
            $tax   = (float)($it['tax'] ?? 0);
            $db->insert('credit_order_items', [
                'order_id'        => $order_id,
                'product_id'      => (int)$it['product_id'],
                'variant_id'      => !empty($it['variant_id']) ? (int)$it['variant_id'] : null,
                'quantity'        => $qty,
                'unit_price'      => $price,
                'discount_amount' => $disc,
                'tax_amount'      => $tax,
                'line_total'      => ($qty * $price) - $disc + $tax,
            ]);
        }

        // Minimal shipping record — no real truck/driver for a historical entry.
        $db->insert('credit_order_shipping', [
            'order_id'             => $order_id,
            'shipped_date'         => $delivery_date . ' 00:00:00',
            'delivered_date'       => $delivery_date . ' 00:00:00',
            'shipped_by_user_id'   => $user_id,
            'delivered_by_user_id' => $user_id,
            'delivery_notes'       => 'Backdated reconciliation entry — ' . $reconciliation_note,
        ]);

        // ── Ledger + journal, reusing the exact accounting logic from the live
        // "Goods on Board" step (credit_dispatch.php) — same account resolution,
        // same aggregate-based running balance — except transaction_date is the
        // BACKDATED delivery date, not today, so aging/statements are accurate.
        $journal_desc = "Credit Sale Invoice #{$order_number} to {$customer->name} (backdated reconciliation)";
        $journal_id = $db->insert('journal_entries', [
            'transaction_date'       => $delivery_date,
            'description'            => $journal_desc,
            'related_document_type'  => 'credit_orders',
            'related_document_id'    => $order_id,
            'created_by_user_id'     => $user_id,
        ]);
        if (!$journal_id) throw new Exception('Failed to create the journal entry.');

        $db->insert('transaction_lines', [
            'journal_entry_id' => $journal_id, 'account_id' => $ar_account_id,
            'debit_amount' => $total_amount, 'credit_amount' => 0.00, 'description' => $journal_desc,
        ]);
        $db->insert('transaction_lines', [
            'journal_entry_id' => $journal_id, 'account_id' => $sales_account_id,
            'debit_amount' => 0.00, 'credit_amount' => $total_amount, 'description' => $journal_desc,
        ]);

        $agg = $db->query(
            "SELECT COALESCE(SUM(debit_amount),0) AS td, COALESCE(SUM(credit_amount),0) AS tc
             FROM customer_ledger WHERE customer_id = ?", [$customer_id]
        )->first();
        $agg_td = (float)($agg->td ?? 0);
        $agg_tc = (float)($agg->tc ?? 0);
        $prev_balance = ($agg_td > 0 || $agg_tc > 0) ? ($agg_td - $agg_tc) : (float)($customer->initial_due ?? 0);
        $balance_after = $prev_balance + $total_amount;

        $db->insert('customer_ledger', [
            'customer_id'        => $customer_id,
            'transaction_date'   => $delivery_date,
            'transaction_type'   => 'invoice',
            'reference_type'     => 'credit_orders',
            'reference_id'       => $order_id,
            'invoice_number'     => $order_number,
            'description'        => 'Credit sale — ' . $order_number . ' (backdated reconciliation)',
            'debit_amount'       => $total_amount,
            'credit_amount'      => 0.00,
            'balance_after'      => $balance_after,
            'created_by_user_id' => $user_id,
            'journal_entry_id'   => $journal_id,
        ]);

        // Invoice snapshot (frozen record), mirroring credit_dispatch.php's shape.
        $branch_row   = $db->query("SELECT name, address, phone_number FROM branches WHERE id = ?", [$branch_id])->first();
        $customer_full = $db->query("SELECT phone_number, email, business_address FROM customers WHERE id = ?", [$customer_id])->first();
        $items_arr = [];
        foreach ($items as $it) {
            $qty = (float)$it['quantity']; $price = (float)$it['unit_price'];
            $disc = (float)($it['discount'] ?? 0); $tax = (float)($it['tax'] ?? 0);
            $items_arr[] = [
                'product_id'      => (int)$it['product_id'],
                'variant_id'      => !empty($it['variant_id']) ? (int)$it['variant_id'] : null,
                'product_name'    => $it['product_name']  ?? '',
                'variant_detail'  => $it['variant_detail'] ?? '',
                'quantity'        => $qty, 'unit_price' => $price,
                'discount_amount' => $disc, 'tax_amount' => $tax,
                'line_total'      => ($qty * $price) - $disc + $tax,
            ];
        }
        $db->insert('invoice_snapshots', [
            'order_id'          => $order_id,
            'order_number'      => $order_number,
            'snapshot_trigger'  => 'backdated_reconciliation',
            'snapshot_at'       => date('Y-m-d H:i:s'),
            'customer_id'       => $customer_id,
            'customer_name'     => $customer->name,
            'customer_phone'    => $customer_full->phone_number ?? null,
            'customer_email'    => $customer_full->email ?? null,
            'customer_address'  => $customer_full->business_address ?? null,
            'previous_due'      => $prev_balance,
            'subtotal'          => $subtotal,
            'discount_amount'   => $discount_total,
            'tax_amount'        => $tax_total,
            'total_amount'      => $total_amount,
            'advance_paid'      => $advance_paid,
            'balance_due'       => $balance_due,
            'total_outstanding' => $prev_balance + $balance_due,
            'company_name_bn'   => 'উজ্জল ফ্লাওয়ার মিলস',
            'company_name_en'   => 'Ujjal Flour Mills',
            'company_address'   => ($branch_row && !empty($branch_row->address)) ? $branch_row->address : '১৭, নুরাইবাগ, ডেমরা, ঢাকা',
            'company_phone'     => ($branch_row && !empty($branch_row->phone_number)) ? $branch_row->phone_number : '+880-XXX-XXXXXX',
            'company_email'     => 'info@ujjalfm.com',
            'items_json'        => json_encode($items_arr, JSON_UNESCAPED_UNICODE),
            'shipping_address'  => $shipping_address ?: null,
            'branch_name'       => $branch_row->name ?? null,
            'order_date'        => $order_date,
            'required_date'     => $delivery_date,
            'invoice_date'      => $delivery_date,
            'order_type'        => 'standard',
            'order_status'      => 'delivered',
            'special_instructions' => 'Backdated reconciliation entry: ' . $reconciliation_note,
            'created_by_user_id'   => $user_id,
        ]);

        // Single, honest workflow entry — no fabricated approve/produce/ship steps.
        $db->insert('credit_order_workflow', [
            'order_id'             => $order_id,
            'from_status'          => 'draft',
            'to_status'            => 'delivered',
            'action'               => 'backdated_reconciliation',
            'performed_by_user_id' => $user_id,
            'comments'             => "Backdated order recorded directly as Delivered (order date {$order_date}, "
                                     . "delivery date {$delivery_date}) by " . ($currentUser['display_name'] ?? 'admin')
                                     . " — reconciliation note: {$reconciliation_note}",
        ]);

        $pdo->commit();

        if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED
            && defined('TELEGRAM_BOT_TOKEN') && defined('TELEGRAM_CHAT_ID')) {
            try {
                require_once '../core/classes/TelegramNotifier.php';
                (new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID))->sendMessage(
                    "<b>📋 BACKDATED ORDER RECORDED (Reconciliation)</b>\n"
                    . "───────────────────────────────\n\n"
                    . "• Order: <code>{$order_number}</code>\n"
                    . "• Customer: <b>" . htmlspecialchars($customer->name) . "</b>\n"
                    . "• Order date: {$order_date} · Delivery date: {$delivery_date}\n"
                    . "• Amount: ৳" . number_format($total_amount, 0) . " · Due: ৳" . number_format($balance_due, 0) . "\n"
                    . "• Recorded by: " . ($currentUser['display_name'] ?? 'admin') . "\n"
                    . "• Note: " . htmlspecialchars($reconciliation_note) . "\n\n"
                    . "<i>Created directly as Delivered — pipeline steps bypassed for reconciliation.</i>"
                );
            } catch (\Throwable $te) { error_log('backdated-order Telegram: ' . $te->getMessage()); }
        }

        // module/action MUST be valid system_audit_log ENUM values — AuditLogger
        // catches PDOException and silently drops the row on an invalid value.
        auditLog('credit_order', 'created',
            "BACKDATED order {$order_number} recorded directly as Delivered by " . ($currentUser['display_name'] ?? 'admin')
            . " — ৳" . number_format($total_amount, 2) . " — {$reconciliation_note}",
            ['record_id' => $order_id, 'reference_number' => $order_number, 'severity' => 'warning',
             'metadata' => ['order_date' => $order_date, 'delivery_date' => $delivery_date, 'backdated' => true]]);

        $_SESSION['success_flash'] = "Backdated order {$order_number} created and marked Delivered — invoice posted to the ledger. "
                                    . "You can now collect payment against it.";
        header('Location: customer_payment.php?order_id=' . $order_id);
        exit();

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

require_once '../templates/header.php';
?>
<div class="max-w-screen-lg mx-auto px-4 sm:px-6 py-6">

    <div class="mb-4">
        <h1 class="text-2xl font-bold text-gray-900"><i class="fas fa-clock-rotate-left text-amber-600 mr-2"></i>Backdated Order (Reconciliation)</h1>
        <p class="text-gray-600 mt-1 text-sm">For sales that already happened before this system recorded them. Skips approval/production/dispatch — the order is created directly as <strong>Delivered</strong>, and the invoice posts to the ledger immediately so you can collect payment against it.</p>
    </div>

    <div class="mb-5 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <i class="fas fa-triangle-exclamation mr-1"></i>
        This is a Superadmin/admin-only reconciliation tool. Submitting this form <strong>is</strong> the approval — there is no separate approval step, and no production/dispatch tracking. Prices are <strong>entered manually</strong> below since the price tables only hold today's prices, not historical ones.
    </div>

    <?php if ($error): ?>
    <div class="mb-5 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
        <i class="fas fa-circle-exclamation mr-1"></i><?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="backdatedForm" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 space-y-6">
        <input type="hidden" name="action" value="create_backdated_order">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
        <input type="hidden" name="customer_id" id="customer_id" required>
        <input type="hidden" name="items_json" id="items_json">

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Customer <span class="text-red-500">*</span></label>
            <div class="relative">
                <input type="text" id="customer_search" autocomplete="off"
                       placeholder="Search by name, business, phone or address..." required
                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                       oninput="boSearchCustomers(this.value)" onfocus="boSearchCustomers(this.value)">
                <div id="customer_dropdown" class="absolute z-50 left-0 right-0 mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-64 overflow-y-auto hidden"></div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Branch <span class="text-red-500">*</span></label>
                <select name="branch_id" required class="w-full px-4 py-2 border rounded-lg">
                    <option value="">Select branch</option>
                    <?php foreach ($branches as $b): ?>
                    <option value="<?php echo (int)$b->id; ?>"><?php echo htmlspecialchars($b->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Order Date <span class="text-red-500">*</span></label>
                <input type="date" name="order_date" id="order_date_field" required
                       class="w-full px-4 py-2 border rounded-lg" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Delivery Date <span class="text-red-500">*</span></label>
                <input type="date" name="delivery_date" id="delivery_date_field" required
                       class="w-full px-4 py-2 border rounded-lg" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>">
                <p class="mt-1 text-xs text-gray-500">When the goods actually changed hands — this is what posts to the ledger.</p>
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Reason / Reconciliation Note <span class="text-red-500">*</span></label>
            <textarea name="reconciliation_note" required rows="2" class="w-full px-4 py-2 border rounded-lg"
                      placeholder="Why is this being recorded now, e.g. &quot;Paper invoice from before system go-live, entered for reconciliation&quot;"></textarea>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Shipping Address</label>
            <input type="text" name="shipping_address" class="w-full px-4 py-2 border rounded-lg" placeholder="Optional">
        </div>

        <!-- Items -->
        <div>
            <div class="flex items-center justify-between mb-2">
                <label class="block text-sm font-medium text-gray-700">Items <span class="text-red-500">*</span></label>
                <button type="button" onclick="boAddItem()" class="px-3 py-1.5 bg-green-600 text-white text-xs font-semibold rounded-lg hover:bg-green-700">
                    <i class="fas fa-plus mr-1"></i>Add Item
                </button>
            </div>
            <div class="overflow-x-auto border rounded-lg">
                <table class="min-w-full text-xs">
                    <thead class="bg-gray-50 border-b">
                        <tr>
                            <th class="px-2 py-2 text-left font-semibold text-gray-500 uppercase">Product</th>
                            <th class="px-2 py-2 text-left font-semibold text-gray-500 uppercase">Variant</th>
                            <th class="px-2 py-2 text-right font-semibold text-gray-500 uppercase w-24">Qty</th>
                            <th class="px-2 py-2 text-right font-semibold text-gray-500 uppercase w-32">Unit Price (৳)</th>
                            <th class="px-2 py-2 text-right font-semibold text-gray-500 uppercase w-24">Discount</th>
                            <th class="px-2 py-2 text-right font-semibold text-gray-500 uppercase w-24">Tax</th>
                            <th class="px-2 py-2 text-right font-semibold text-gray-500 uppercase w-28">Line Total</th>
                            <th class="px-2 py-2 w-8"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody"></tbody>
                </table>
            </div>
        </div>

        <!-- Totals -->
        <div class="bg-gray-50 rounded-lg p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div><span class="text-gray-500">Subtotal</span><div class="font-bold text-gray-800" id="sumSubtotal">৳0.00</div></div>
            <div><span class="text-gray-500">Discount + Tax</span><div class="font-bold text-gray-800" id="sumAdj">৳0.00</div></div>
            <div><span class="text-gray-500">Total Amount</span><div class="font-bold text-blue-700" id="sumTotal">৳0.00</div></div>
            <div>
                <label class="text-gray-500">Advance Paid</label>
                <input type="number" name="advance_paid" id="advance_paid" step="0.01" value="0.00"
                       class="w-full px-2 py-1 border rounded-lg" oninput="boUpdateTotals()">
            </div>
        </div>
        <div class="text-right text-sm">Balance Due: <strong class="text-red-600" id="sumDue">৳0.00</strong></div>

        <div class="flex justify-end gap-3 pt-2 border-t">
            <a href="index.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 text-sm">Cancel</a>
            <button type="submit" class="px-5 py-2 bg-amber-600 text-white font-semibold rounded-lg hover:bg-amber-700 text-sm">
                <i class="fas fa-check mr-1"></i>Create Backdated Order — Directly Delivered
            </button>
        </div>
    </form>
</div>

<script>
const boCustomerData = <?php echo json_encode(array_map(function($c) {
    return [
        'id' => $c->id, 'name' => $c->name, 'business' => $c->business_name ?? '',
        'phone' => $c->phone_number ?? '', 'address' => $c->business_address ?? '',
        'balance' => (float)($c->true_balance ?? 0),
    ];
}, $customers)); ?>;

function boSearchCustomers(query) {
    const dd = document.getElementById('customer_dropdown');
    const q = query.toLowerCase().trim();
    const matches = q.length === 0 ? boCustomerData.slice(0, 20) : boCustomerData.filter(c =>
        c.name.toLowerCase().includes(q) || c.business.toLowerCase().includes(q) ||
        c.phone.includes(q) || c.address.toLowerCase().includes(q)
    ).slice(0, 20);

    dd.innerHTML = matches.length === 0
        ? '<div class="px-4 py-3 text-sm text-gray-500">No customers found</div>'
        : matches.map(c => `<div class="px-4 py-2 hover:bg-blue-50 cursor-pointer text-sm border-b border-gray-100" onclick="boSelectCustomer(${c.id})">
            <span class="font-medium text-gray-900">${c.name}</span>
            ${c.business ? `<span class="text-gray-400 text-xs ml-1">(${c.business})</span>` : ''}
            <span class="text-gray-400 text-xs ml-2">${c.phone}</span>
            ${c.balance > 0 ? `<span class="text-red-600 text-xs ml-2">Due: ৳${c.balance.toFixed(0)}</span>` : ''}
          </div>`).join('');
    dd.classList.remove('hidden');
}
function boSelectCustomer(id) {
    const c = boCustomerData.find(x => x.id === id);
    if (!c) return;
    document.getElementById('customer_id').value = c.id;
    document.getElementById('customer_search').value = c.name + (c.business ? ' (' + c.business + ')' : '');
    document.getElementById('customer_dropdown').classList.add('hidden');
}
document.addEventListener('click', e => {
    if (!e.target.closest('#customer_search') && !e.target.closest('#customer_dropdown')) {
        document.getElementById('customer_dropdown').classList.add('hidden');
    }
});

// order_date defaults delivery_date to match unless user already changed it
document.getElementById('order_date_field').addEventListener('change', function() {
    const del = document.getElementById('delivery_date_field');
    if (!del.dataset.touched) del.value = this.value;
});
document.getElementById('delivery_date_field').addEventListener('change', function() { this.dataset.touched = '1'; });

const boProducts = <?php echo json_encode($products); ?>;
const boVariants  = <?php echo json_encode($product_variants); ?>;
let boItemSeq = 0;

function boAddItem() {
    const rid = 'row_' + (boItemSeq++);
    const tr = document.createElement('tr');
    tr.id = rid;
    tr.className = 'border-b';
    const productOpts = boProducts.map(p => `<option value="${p.id}">${p.base_name}</option>`).join('');
    tr.innerHTML = `
        <td class="px-2 py-1"><select class="w-full px-2 py-1 border rounded text-xs bo-product" onchange="boProductChanged('${rid}')">
            <option value="">Select...</option>${productOpts}</select></td>
        <td class="px-2 py-1"><select class="w-full px-2 py-1 border rounded text-xs bo-variant" onchange="boVariantChanged('${rid}')"><option value="">—</option></select>
            <div class="text-[10px] text-gray-400 bo-ref-price"></div></td>
        <td class="px-2 py-1"><input type="number" min="0.01" step="0.01" class="w-full px-2 py-1 border rounded text-xs text-right bo-qty" oninput="boCalcRow('${rid}')"></td>
        <td class="px-2 py-1"><input type="number" min="0" step="0.01" class="w-full px-2 py-1 border rounded text-xs text-right bo-price" oninput="boCalcRow('${rid}')"></td>
        <td class="px-2 py-1"><input type="number" min="0" step="0.01" value="0" class="w-full px-2 py-1 border rounded text-xs text-right bo-discount" oninput="boCalcRow('${rid}')"></td>
        <td class="px-2 py-1"><input type="number" min="0" step="0.01" value="0" class="w-full px-2 py-1 border rounded text-xs text-right bo-tax" oninput="boCalcRow('${rid}')"></td>
        <td class="px-2 py-1 text-right font-semibold bo-line-total">৳0.00</td>
        <td class="px-2 py-1 text-center"><button type="button" onclick="document.getElementById('${rid}').remove(); boUpdateTotals();" class="text-red-400 hover:text-red-600"><i class="fas fa-times"></i></button></td>
    `;
    document.getElementById('itemsBody').appendChild(tr);
}

function boProductChanged(rid) {
    const tr = document.getElementById(rid);
    const productId = tr.querySelector('.bo-product').value;
    const variantSel = tr.querySelector('.bo-variant');
    const list = boVariants[productId] || [];
    variantSel.innerHTML = '<option value="">—</option>' + list.map(v => {
        const label = [v.grade, v.weight_variant].filter(Boolean).join(' - ') || v.sku;
        return `<option value="${v.id}" data-ref="${v.reference_price || ''}">${label}</option>`;
    }).join('');
    tr.querySelector('.bo-ref-price').textContent = '';
}
function boVariantChanged(rid) {
    const tr = document.getElementById(rid);
    const opt = tr.querySelector('.bo-variant').selectedOptions[0];
    const ref = opt ? opt.dataset.ref : '';
    tr.querySelector('.bo-ref-price').textContent = ref ? `current price: ৳${parseFloat(ref).toFixed(2)}` : '';
}
function boCalcRow(rid) {
    const tr = document.getElementById(rid);
    const qty = parseFloat(tr.querySelector('.bo-qty').value) || 0;
    const price = parseFloat(tr.querySelector('.bo-price').value) || 0;
    const disc = parseFloat(tr.querySelector('.bo-discount').value) || 0;
    const tax = parseFloat(tr.querySelector('.bo-tax').value) || 0;
    const total = (qty * price) - disc + tax;
    tr.querySelector('.bo-line-total').textContent = '৳' + total.toFixed(2);
    boUpdateTotals();
}

function boUpdateTotals() {
    let subtotal = 0, discount = 0, tax = 0;
    document.querySelectorAll('#itemsBody tr').forEach(tr => {
        const qty = parseFloat(tr.querySelector('.bo-qty')?.value) || 0;
        const price = parseFloat(tr.querySelector('.bo-price')?.value) || 0;
        subtotal += qty * price;
        discount += parseFloat(tr.querySelector('.bo-discount')?.value) || 0;
        tax      += parseFloat(tr.querySelector('.bo-tax')?.value) || 0;
    });
    const total = subtotal - discount + tax;
    const advance = parseFloat(document.getElementById('advance_paid').value) || 0;
    document.getElementById('sumSubtotal').textContent = '৳' + subtotal.toFixed(2);
    document.getElementById('sumAdj').textContent = '৳' + (tax - discount).toFixed(2);
    document.getElementById('sumTotal').textContent = '৳' + total.toFixed(2);
    document.getElementById('sumDue').textContent = '৳' + Math.max(0, total - advance).toFixed(2);
}

boAddItem();

document.getElementById('backdatedForm').addEventListener('submit', function(e) {
    const items = [];
    document.querySelectorAll('#itemsBody tr').forEach(tr => {
        const productSel = tr.querySelector('.bo-product');
        const variantSel  = tr.querySelector('.bo-variant');
        const qty = parseFloat(tr.querySelector('.bo-qty').value) || 0;
        if (!productSel.value || qty <= 0) return;
        items.push({
            product_id:     productSel.value,
            product_name:   productSel.selectedOptions[0]?.textContent || '',
            variant_id:     variantSel.value || null,
            variant_detail: variantSel.selectedOptions[0]?.textContent || '',
            quantity:       qty,
            unit_price:     parseFloat(tr.querySelector('.bo-price').value) || 0,
            discount:       parseFloat(tr.querySelector('.bo-discount').value) || 0,
            tax:            parseFloat(tr.querySelector('.bo-tax').value) || 0,
        });
    });
    if (items.length === 0) {
        e.preventDefault();
        alert('Add at least one item with a product and quantity.');
        return;
    }
    if (!document.getElementById('customer_id').value) {
        e.preventDefault();
        alert('Please select a customer.');
        return;
    }
    document.getElementById('items_json').value = JSON.stringify(items);
});
</script>

<?php require_once '../templates/footer.php'; ?>