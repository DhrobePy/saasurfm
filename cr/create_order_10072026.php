<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin', 'Accounts','sales-srg', 'sales-demra', 'sales-other'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$pageTitle = 'Create Credit Order';
$error = null;
$success = null;

// Get customers with TRUE balance = initial_due + net ledger transactions.
// Reading balance_after from the last ledger row is wrong because the chain
// often starts from 0 rather than initial_due, producing an understated balance.
// This formula matches exactly what customer_ledger.php uses for its display.
$customers = $db->query(
    "SELECT c.id, c.name, c.phone_number, c.business_address, c.credit_limit, c.initial_due, c.status,
            COALESCE(c.initial_due, 0)
                + COALESCE(tb.total_debit,  0)
                - COALESCE(tb.total_credit, 0) AS true_balance
     FROM customers c
     LEFT JOIN (
         SELECT customer_id,
                SUM(debit_amount)  AS total_debit,
                SUM(credit_amount) AS total_credit
         FROM customer_ledger
         WHERE reference_type != 'initial_due'
         GROUP BY customer_id
     ) tb ON tb.customer_id = c.id
     WHERE c.status = 'active' AND c.customer_type = 'Credit'
     ORDER BY c.name ASC"
)->results();

// Get all active products
$products = $db->query(
    "SELECT id, base_name, base_sku, status
     FROM products
     WHERE status = 'active'
     ORDER BY base_name ASC"
)->results();

// Get variants with prices BY BRANCH and available stock
$variants = $db->query(
    "SELECT pv.id as variant_id, 
            pv.product_id, 
            pv.grade, 
            pv.weight_variant,
            pv.unit_of_measure,
            pv.sku,
            pp.id as price_id,
            pp.branch_id,
            pp.unit_price,
            b.name as branch_name,
            b.code as branch_code,
            COALESCE(inv.quantity, 0) as stock_usable
     FROM product_variants pv
     JOIN product_prices pp ON pv.id = pp.variant_id
     JOIN branches b ON pp.branch_id = b.id
     LEFT JOIN inventory inv ON pv.id = inv.variant_id AND pp.branch_id = inv.branch_id
     WHERE pv.status = 'active' 
       AND pp.status = 'active' 
       AND pp.is_active = 1
       AND b.status = 'active'
     ORDER BY pv.product_id, pv.grade, pv.weight_variant, b.name"
)->results();

// Load mini-truck surcharges — DB is source of truth, JSON file is fallback
try {
    $db->getPdo()->exec("CREATE TABLE IF NOT EXISTS `app_settings` (
        `setting_key` VARCHAR(100) PRIMARY KEY,
        `setting_value` MEDIUMTEXT NOT NULL,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {}

$mt_row = $db->query("SELECT setting_value FROM app_settings WHERE setting_key = 'mini_truck_surcharges'")->first();
$mini_truck_surcharges = $mt_row ? (json_decode($mt_row->setting_value, true) ?? []) : [];

if (empty($mini_truck_surcharges)) {
    $engine_config_path = __DIR__ . '/../product/pricing_engine_config.json';
    if (file_exists($engine_config_path)) {
        $engine_config = json_decode(file_get_contents($engine_config_path), true) ?? [];
        $mini_truck_surcharges = $engine_config['mini_truck_surcharges'] ?? [];
    }
}
$engine_weight_map = $engine_config['weight_map'] ?? [
    '50 KG' => '50', '74 KG' => '74', '50KG' => '50', '74KG' => '74',
    '50kg'  => '50', '74kg'  => '74', '50'    => '50', '74'    => '74',
];

// Ensure delivery_type column exists (DDL outside any transaction)
try { $db->getPdo()->exec("ALTER TABLE `credit_orders` ADD COLUMN `delivery_type` VARCHAR(20) NOT NULL DEFAULT 'big_truck'"); } catch (\Throwable $e) {}

// Enrich each variant with weight_class so JS can look up the mini-truck surcharge
foreach ($variants as $v) {
    $wc = $engine_weight_map[$v->weight_variant] ?? null;
    if (!$wc) {
        foreach ($engine_weight_map as $pat => $cls) {
            if (stripos($v->weight_variant, (string)(int)$pat) !== false) { $wc = $cls; break; }
        }
    }
    $v->weight_class = $wc ?? 'custom';
}

// Group variants by product for dropdown
$product_variants = [];
foreach ($variants as $v) {
    if (!isset($product_variants[$v->product_id])) {
        $product_variants[$v->product_id] = [];
    }
    $product_variants[$v->product_id][] = $v;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_order') {
    try {
        // Bug 3 fix: CSRF verification on order creation POST
        $session_csrf = $_SESSION['csrf_token'] ?? '';
        $post_csrf    = $_POST['csrf_token']    ?? '';
        if (!$session_csrf || !$post_csrf || !hash_equals($session_csrf, $post_csrf)) {
            throw new Exception('Invalid or missing security token. Please refresh the page and try again.');
        }

        $db->getPdo()->beginTransaction();

        $customer_id = (int)$_POST['customer_id'];
        if ($customer_id <= 0) throw new Exception('Invalid customer selected.');

        $order_type    = $_POST['order_type'];
        $delivery_type = in_array($_POST['delivery_type'] ?? '', ['big_truck', 'mini_truck'])
                         ? $_POST['delivery_type'] : 'big_truck';
        $required_date = $_POST['required_date'];
        $shipping_address = trim($_POST['shipping_address']);
        $special_instructions = trim($_POST['special_instructions']);
        $advance_paid = floatval($_POST['advance_paid'] ?? 0);

        $subtotal = floatval($_POST['subtotal']);
        $discount = floatval($_POST['discount_amount']);
        $tax = floatval($_POST['tax_amount']);
        $total = $subtotal - $discount + $tax;
        $balance_due = $total - $advance_paid;

        // ── Credit limit enforcement ─────────────────────────────────────────
        // Same formula as the account statement: initial_due + net ledger transactions.
        $cust_rec = $db->query(
            "SELECT c.credit_limit, c.name, c.initial_due,
                    COALESCE(c.initial_due, 0) + COALESCE(
                        (SELECT SUM(debit_amount) - SUM(credit_amount)
                         FROM customer_ledger
                         WHERE customer_id = c.id AND reference_type != 'initial_due'), 0
                    ) AS true_balance
             FROM customers c
             WHERE c.id = ?",
            [$customer_id]
        )->first();

        if (!$cust_rec) throw new Exception('Customer not found.');

        $credit_limit     = (float)$cust_rec->credit_limit;
        $current_due      = (float)$cust_rec->true_balance;
        $available_credit = $credit_limit - $current_due;

        // Flag credit limit breach — order still proceeds to pending_approval for admin review
        $user_role = $_SESSION['user_role'] ?? '';
        $is_admin  = in_array($user_role, ['Superadmin', 'admin']);

        $credit_limit_note = '';
        if (!$is_admin && $credit_limit > 0 && $balance_due > $available_credit) {
            $over_by = $balance_due - $available_credit;
            $credit_limit_note = sprintf(
                ' | ⚠ CREDIT LIMIT EXCEEDED — Limit: ৳%s | Current Outstanding: ৳%s | This Order: ৳%s | Over By: ৳%s',
                number_format($credit_limit, 0),
                number_format($current_due, 0),
                number_format($balance_due, 0),
                number_format($over_by, 0)
            );
        }
        
        // Bug 4 fix: Sequence-based order number — no rand() / collision risk
        $date_prefix = date('Ymd');
        $last_order  = $db->query(
            "SELECT order_number FROM credit_orders WHERE order_number LIKE ? ORDER BY id DESC LIMIT 1",
            ["CR-{$date_prefix}-%"]
        )->first();
        $seq          = $last_order ? ((int)substr($last_order->order_number, -4) + 1) : 1;
        $order_number = sprintf("CR-%s-%04d", $date_prefix, $seq);
        
        $order_id = $db->insert('credit_orders', [
            'order_number'         => $order_number,
            'customer_id'          => $customer_id,
            'order_date'           => date('Y-m-d'),
            'required_date'        => $required_date,
            'order_type'           => $order_type,
            'delivery_type'        => $delivery_type,
            'subtotal'             => $subtotal,
            'discount_amount'      => $discount,
            'tax_amount'           => $tax,
            'total_amount'         => $total,
            'advance_paid'         => $advance_paid,
            'balance_due'          => $balance_due,
            'status'               => 'pending_approval',
            'shipping_address'     => $shipping_address,
            'special_instructions' => $special_instructions,
            'created_by_user_id'   => $user_id
        ]);
        
        if (!$order_id) {
            throw new Exception("Failed to create order");
        }
        
        $items = json_decode($_POST['items_json'], true);
        foreach ($items as $item) {
            $db->insert('credit_order_items', [
                'order_id' => $order_id,
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'] ?? null,
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'discount_amount' => $item['discount'] ?? 0,
                'tax_amount' => $item['tax'] ?? 0,
                'line_total' => $item['line_total']
            ]);
        }
        
        $db->insert('credit_order_workflow', [
            'order_id' => $order_id,
            'from_status' => 'draft',
            'to_status' => 'pending_approval',
            'action' => 'submit',
            'performed_by_user_id' => $user_id,
            'comments' => 'Order created and submitted for approval' . $credit_limit_note
        ]);
        
        $db->getPdo()->commit();
        
        // ============================================
        // TELEGRAM NOTIFICATION
        // ============================================
        if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
            try {
                require_once '../core/classes/TelegramNotifier.php';
                
                $telegram = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);
                
                // Get customer details
                $customer = $db->query("SELECT name, phone_number, business_address FROM customers WHERE id = ?", [$customer_id])->first();

                // Collect unique source branches from the order items (DB lookup — not role-based guessing).
                // Each item has branch_id from the price selection; this is the factual source of goods.
                $item_branch_ids = array_values(array_unique(array_filter(array_column($items, 'branch_id'))));
                $source_branch_names = [];
                if (!empty($item_branch_ids)) {
                    $ph = implode(',', array_fill(0, count($item_branch_ids), '?'));
                    $branch_rows = $db->query(
                        "SELECT id, name, is_factory FROM branches WHERE id IN ($ph) ORDER BY name ASC",
                        $item_branch_ids
                    )->results();
                    foreach ($branch_rows as $br) {
                        $source_branch_names[] = $br->name;
                    }
                }
                $branch_name = !empty($source_branch_names)
                    ? implode(' & ', $source_branch_names)
                    : 'Not specified';

                // Also get the sales officer's region (from users.branch_id if set, else role label)
                $sales_region = null;
                $user_branch_row = $db->query(
                    "SELECT b.name FROM users u LEFT JOIN branches b ON u.branch_id = b.id WHERE u.id = ?",
                    [$user_id]
                )->first();
                if ($user_branch_row && !empty($user_branch_row->name)) {
                    $sales_region = $user_branch_row->name;
                }

                // Prepare items array with product details — single JOIN query for all items
                $notification_items = [];
                foreach ($items as $item) {
                    $product = $db->query("SELECT base_name FROM products WHERE id = ?", [$item['product_id']])->first();
                    $variant_details = $db->query(
                        "SELECT pv.grade, pv.weight_variant, pv.unit_of_measure, pv.sku, b.name AS branch_name
                         FROM product_variants pv
                         LEFT JOIN branches b ON b.id = ?
                         WHERE pv.id = ?",
                        [$item['branch_id'] ?? 0, $item['variant_id']]
                    )->first();

                    $product_name = $product ? $product->base_name : 'Unknown Product';
                    $variant_name = '';
                    if ($variant_details) {
                        $variant_name = trim($variant_details->grade . ' ' . $variant_details->weight_variant);
                    }

                    $notification_items[] = [
                        'product_name' => $product_name,
                        'variant_name' => $variant_name,
                        'branch_name'  => $variant_details->branch_name ?? '',
                        'quantity'     => floatval($item['quantity']),
                        'unit'         => $variant_details ? $variant_details->unit_of_measure : 'pcs',
                        'unit_price'   => number_format($item['unit_price'], 2),
                        'subtotal'     => number_format($item['line_total'], 2),
                    ];
                }

                // Get the actual user name from database (most reliable)
                $user_info = $db->query("SELECT display_name FROM users WHERE id = ?", [$user_id])->first();
                $created_by_name = $user_info ? $user_info->display_name : 'Unknown User';

                // Prepare order data for notification
                $orderData = [
                    'order_id'         => $order_number,
                    'order_date'       => date('d M Y, h:i A'),
                    'status'           => 'Pending Approval',
                    'delivery_type'    => $delivery_type,
                    'customer_name'    => $customer ? $customer->name : 'Unknown',
                    'customer_phone'   => $customer ? $customer->phone_number : 'N/A',
                    'customer_address' => $customer && $customer->business_address ? $customer->business_address : 'N/A',
                    'branch_name'      => $branch_name,       // item source branch(es)
                    'sales_region'     => $sales_region,      // order-creator's region (null if unknown)
                    'items'            => $notification_items,
                    'subtotal'         => floatval($subtotal),
                    'discount_amount'  => floatval($discount),
                    'total_amount'     => floatval($total),
                    'paid_amount'      => floatval($advance_paid),
                    'due_amount'       => floatval($balance_due),
                    'created_by'       => $created_by_name
                ];
                
                // Send notification
                $result = $telegram->sendCreditOrderNotification($orderData);
                
                // Optional: Log success
                if ($result['success']) {
                }
            } catch (Exception $e) {
                // Telegram error — don't stop the order process
            }
        }
        // ============================================
        // END TELEGRAM NOTIFICATION
        // ============================================
        
        auditLog('credit_sales', 'created',
            "Credit order {$order_number} created — Total: ৳" . number_format($total, 2),
            ['record_id' => $order_id, 'customer_id' => $customer_id, 'total' => $total, 'items_count' => count($items)]
        );

        $_SESSION['success_flash'] = "Order $order_number created successfully! Awaiting approval.";
        header('Location: index.php');
        exit();
        
    } catch (Exception $e) {
        if ($db->getPdo()->inTransaction()) {
            $db->getPdo()->rollBack();
        }
        $error = "Failed to create order: " . $e->getMessage();
    }
}

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
    <p class="text-lg text-gray-600 mt-1">Create a new credit sale order</p>
</div>

<?php if ($error): ?>

<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg">
    <p class="font-bold">Error</p>
    <p><?php echo htmlspecialchars($error); ?></p>
</div>
<?php endif; ?>

<form method="POST" id="creditOrderForm" class="space-y-6">
    <input type="hidden" name="action" value="create_order">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <input type="hidden" name="subtotal" id="subtotal">
    <input type="hidden" name="discount_amount" id="discount_amount">
    <input type="hidden" name="tax_amount" id="tax_amount">
    <input type="hidden" name="items_json" id="items_json">
<!-- Customer & Order Info -->
<div class="bg-white rounded-lg shadow-md p-6">
    <h2 class="text-xl font-bold text-gray-800 mb-4">Customer Information</h2>
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Customer *</label>
            <!-- Hidden actual field submitted with form -->
            <input type="hidden" name="customer_id" id="customer_id" required>
            <!-- Visible search input -->
            <div class="relative">
                <input type="text" id="customer_search" autocomplete="off"
                       placeholder="Type customer name or phone..." required
                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                       oninput="searchCustomers(this.value)"
                       onfocus="searchCustomers(this.value)">
                <div id="customer_dropdown"
                     class="absolute z-50 left-0 right-0 mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-64 overflow-y-auto hidden">
                </div>
            </div>
            <!-- Customer info card — shown after selection -->
            <div id="selected_customer_card" class="hidden mt-2 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="space-y-0.5 min-w-0">
                        <p class="font-bold text-gray-900 text-sm" id="sc_name"></p>
                        <p class="text-xs text-gray-600"><i class="fas fa-phone text-blue-400 mr-1 w-3"></i><span id="sc_phone"></span></p>
                        <p class="text-xs text-gray-600 truncate"><i class="fas fa-map-marker-alt text-blue-400 mr-1 w-3"></i><span id="sc_address" class="italic"></span></p>
                    </div>
                    <div class="text-right shrink-0">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wide leading-none">Outstanding</p>
                        <p class="font-bold text-sm" id="sc_balance"></p>
                        <p class="text-[10px] text-gray-500 uppercase tracking-wide leading-none mt-1">Avail. Credit</p>
                        <p class="font-bold text-sm" id="sc_avail"></p>
                    </div>
                </div>
            </div>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Order Type *</label>
            <select name="order_type" required class="w-full px-4 py-2 border rounded-lg">
                <option value="credit">Credit Sale</option>
                <option value="advance_payment">Advance Payment</option>
            </select>
        </div>

        <!-- Delivery Type -->
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Delivery Type *</label>
            <div class="flex rounded-lg border border-gray-300 overflow-hidden">
                <label class="flex-1 flex items-center justify-center gap-2 px-4 py-2 cursor-pointer transition-colors bg-blue-600 text-white" id="lbl_big_truck">
                    <input type="radio" name="delivery_type" value="big_truck" checked class="sr-only" onchange="onDeliveryTypeChange()">
                    <i class="fas fa-truck text-sm"></i>
                    <span class="text-sm font-semibold">Big Truck <span class="font-normal opacity-80 text-xs">(25MT)</span></span>
                </label>
                <label class="flex-1 flex items-center justify-center gap-2 px-4 py-2 cursor-pointer transition-colors bg-white text-gray-600 border-l border-gray-300" id="lbl_mini_truck">
                    <input type="radio" name="delivery_type" value="mini_truck" class="sr-only" onchange="onDeliveryTypeChange()">
                    <i class="fas fa-truck-pickup text-sm"></i>
                    <span class="text-sm font-semibold">Mini Truck</span>
                </label>
            </div>
            <p id="mini_truck_note" class="hidden mt-1 text-xs text-orange-600 font-medium">
                <i class="fas fa-info-circle mr-1"></i>Mini Truck surcharge added to item prices below.
            </p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Required Date *</label>
            <input type="date" name="required_date" required class="w-full px-4 py-2 border rounded-lg"
                value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Advance Payment</label>
            <input type="number" name="advance_paid" step="0.01" class="w-full px-4 py-2 border rounded-lg" 
                   value="0.00" onchange="calculateTotals()">
        </div>
    </div>
    
    <div class="mt-4">
        <label class="block text-sm font-medium text-gray-700 mb-2">Shipping Address *</label>
        <div class="flex gap-5 mb-2">
            <label class="flex items-center gap-2 cursor-pointer text-sm select-none">
                <input type="radio" name="shipping_address_type" value="customer" id="ship_use_customer"
                       checked class="accent-blue-600" onchange="onShipTypeChange()">
                <span class="text-gray-700">Use customer address</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer text-sm select-none">
                <input type="radio" name="shipping_address_type" value="manual" id="ship_manual"
                       class="accent-blue-600" onchange="onShipTypeChange()">
                <span class="text-gray-700">Enter manually</span>
            </label>
        </div>
        <textarea name="shipping_address" id="shipping_address_field" rows="2"
                  class="w-full px-4 py-2 border rounded-lg bg-gray-50 text-gray-700"
                  readonly placeholder="Select a customer above — address will auto-fill"></textarea>
        <p class="text-xs text-gray-400 mt-1" id="ship_hint">Auto-filled from customer record. Choose "Enter manually" to type a different address.</p>
    </div>
    
    <div class="mt-4">
        <label class="block text-sm font-medium text-gray-700 mb-2">Special Instructions</label>
        <textarea name="special_instructions" rows="2" class="w-full px-4 py-2 border rounded-lg"></textarea>
    </div>
    
    <!-- Inline credit status badge (compact) -->
    <div id="creditInfo" class="mt-3 hidden">
        <div id="creditInfoPanel" class="flex items-center gap-3 px-3 py-2 rounded-lg border text-sm bg-blue-50 border-blue-200">
            <span class="text-gray-600 font-medium">Credit Limit:</span>
            <span class="font-bold text-gray-900" id="creditLimit">৳0</span>
            <span class="text-gray-400">|</span>
            <span class="text-gray-600 font-medium">Available:</span>
            <span class="font-bold" id="availableCredit">৳0</span>
            <span class="text-gray-400">|</span>
            <span class="text-gray-600 font-medium">Used:</span>
            <span class="font-bold" id="creditUsage">0%</span>
        </div>
    </div>
</div>

<!-- Order Items -->
<div class="bg-white rounded-lg shadow-md p-6">
    <h2 class="text-xl font-bold text-gray-800 mb-4">Order Items</h2>
    
    <!-- Add Item Row -->
    <div class="grid grid-cols-12 gap-3 mb-4 p-4 bg-gray-50 rounded-lg">
        <div class="col-span-3">
            <label class="block text-sm font-medium text-gray-700 mb-2">Product *</label>
            <select id="add_product" class="w-full px-3 py-2 border rounded-lg text-sm">
                <option value="">-- Select Product --</option>
                <?php foreach ($products as $product): ?>
                <option value="<?php echo $product->id; ?>">
                    <?php echo htmlspecialchars($product->base_name); ?>
                    <?php if ($product->base_sku): ?>
                        (<?php echo htmlspecialchars($product->base_sku); ?>)
                    <?php endif; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="col-span-3">
            <label class="block text-sm font-medium text-gray-700 mb-2">Variant & Source *</label>
            <select id="add_variant" class="w-full px-3 py-2 border rounded-lg text-xs" disabled>
                <option value="">-- Select Variant & Source --</option>
            </select>
        </div>
        
        <div class="col-span-1">
            <label class="block text-sm font-medium text-gray-700 mb-2">Unit</label>
            <input type="text" id="add_unit" class="w-full px-2 py-2 border rounded-lg bg-gray-100 text-center text-sm" readonly>
        </div>
        
        <div class="col-span-1">
            <label class="block text-sm font-medium text-gray-700 mb-2">Stock</label>
            <input type="text" id="add_stock" class="w-full px-2 py-2 border rounded-lg bg-gray-100 text-center font-bold text-sm" readonly>
        </div>
        
        <div class="col-span-1">
            <label class="block text-sm font-medium text-gray-700 mb-2">Qty *</label>
            <input type="number" id="add_quantity" step="1" min="1" class="w-full px-2 py-2 border rounded-lg text-sm" value="1">
        </div>
        
        <div class="col-span-1">
            <label class="block text-sm font-medium text-gray-700 mb-2">Price</label>
            <input type="number" id="add_price" step="0.01" class="w-full px-2 py-2 border rounded-lg bg-gray-100 text-sm" readonly>
        </div>
        
        <div class="col-span-1">
            <label class="block text-sm font-medium text-gray-700 mb-2">Disc</label>
            <div class="flex gap-1">
                <select id="add_discount_type" class="w-10 px-1 py-2 border rounded-l-lg text-xs">
                    <option value="percent">%</option>
                    <option value="fixed">৳</option>
                </select>
                <input type="number" id="add_discount" step="0.01" min="0" class="w-14 px-1 py-2 border rounded-r-lg text-xs" value="0">
            </div>
        </div>
        
        <div class="col-span-1 flex items-end">
            <button type="button" onclick="addItem()" class="w-full px-3 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium text-sm">
                <i class="fas fa-cart-plus"></i> Add
            </button>
        </div>
    </div>
    
    <!-- Items Table -->
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Variant</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Source</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">SKU</th>
                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Price</th>
                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Discount</th>
                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                    <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                </tr>
            </thead>
            <tbody id="itemsBody" class="bg-white divide-y divide-gray-200">
                <tr>
                    <td colspan="9" class="px-4 py-8 text-center text-gray-400">
                        <i class="fas fa-shopping-cart text-5xl mb-3 opacity-50"></i>
                        <p class="text-lg">Cart is empty - Add products above</p>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <!-- Totals -->
    <div class="mt-6 border-t pt-6">
        <div class="flex justify-end">
            <div class="w-full md:w-1/2 lg:w-1/3 space-y-3">
                <div class="flex justify-between text-base">
                    <span class="text-gray-600">Subtotal:</span>
                    <span class="font-bold" id="display_subtotal">৳0.00</span>
                </div>
                
                <div class="flex justify-between text-base">
                    <span class="text-gray-600">Item Discounts:</span>
                    <span class="font-bold text-red-600" id="display_item_discount">৳0.00</span>
                </div>
                
                <!-- Cart Discount Section -->
                <div class="border-t pt-3">
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-sm font-medium text-gray-700">Cart Discount:</label>
                        <div class="flex gap-2 items-center">
                            <select id="cart_discount_type" class="px-2 py-1 border rounded text-xs" onchange="calculateTotals()">
                                <option value="percent">%</option>
                                <option value="fixed">৳</option>
                            </select>
                            <input type="number" id="cart_discount_value" step="0.01" min="0" 
                                   class="w-24 px-2 py-1 border rounded text-sm" 
                                   value="0" 
                                   onchange="calculateTotals()"
                                   placeholder="0.00">
                            <button type="button" onclick="applyCartDiscount()" 
                                    class="px-3 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">
                                Apply
                            </button>
                        </div>
                    </div>
                    <div class="flex justify-between text-base">
                        <span class="text-gray-600">Cart Discount Applied:</span>
                        <span class="font-bold text-orange-600" id="display_cart_discount">৳0.00</span>
                    </div>
                </div>
                
                <div class="flex justify-between text-base">
                    <span class="text-gray-600">Total Discount:</span>
                    <span class="font-bold text-red-600" id="display_total_discount">৳0.00</span>
                </div>
                
                <div class="flex justify-between text-base">
                    <span class="text-gray-600">Tax:</span>
                    <span class="font-bold" id="display_tax">৳0.00</span>
                </div>
                
                <div class="flex justify-between text-lg border-t pt-3 mt-3">
                    <span class="font-bold text-gray-900">Total:</span>
                    <span class="font-bold text-blue-600" id="display_total">৳0.00</span>
                </div>
                
                <div class="flex justify-between text-lg">
                    <span class="font-bold text-gray-900">Balance Due:</span>
                    <span class="font-bold text-green-600" id="display_balance">৳0.00</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Combined Financial Summary -->
<div id="financialSummary" class="hidden bg-white rounded-lg shadow-md p-6">
    <h2 class="text-lg font-bold text-gray-800 mb-4">
        <i class="fas fa-calculator mr-2 text-blue-600"></i>Financial Summary
    </h2>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <!-- Order Total -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Order Total</p>
            <p class="text-xl font-bold text-blue-700" id="fs_order_total">৳0</p>
            <p class="text-xs text-gray-500 mt-1">Advance: <span id="fs_advance">৳0</span></p>
            <p class="text-xs text-gray-500">Balance Due: <span class="font-semibold text-gray-700" id="fs_balance_due">৳0</span></p>
        </div>
        <!-- Current Outstanding -->
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Current Outstanding</p>
            <p class="text-xl font-bold text-yellow-700" id="fs_current_due">৳0</p>
            <p class="text-xs text-gray-500 mt-1">Before this order</p>
        </div>
        <!-- Total After Order -->
        <div id="fs_after_panel" class="bg-gray-50 border border-gray-200 rounded-lg p-4">
            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Total After Order</p>
            <p class="text-xl font-bold text-gray-700" id="fs_after_order">৳0</p>
            <p class="text-xs text-gray-500 mt-1">Combined outstanding</p>
        </div>
        <!-- Credit Status -->
        <div id="fs_credit_panel" class="bg-green-50 border border-green-200 rounded-lg p-4">
            <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Credit Limit</p>
            <p class="text-xl font-bold text-green-700" id="fs_credit_limit">৳0</p>
            <div class="mt-2">
                <div class="flex justify-between text-xs mb-1">
                    <span class="text-gray-500">Used after order</span>
                    <span class="font-semibold" id="fs_usage_pct">0%</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div id="fs_usage_bar" class="h-2 rounded-full bg-green-500 transition-all duration-300" style="width:0%"></div>
                </div>
                <p class="text-xs mt-1"><span id="fs_avail_label" class="text-gray-500">Available:</span> <span class="font-semibold" id="fs_available">৳0</span></p>
            </div>
        </div>
    </div>
    <!-- Warning banner if over limit -->
    <div id="fs_warning" class="hidden mt-3 flex items-center gap-2 px-4 py-3 bg-red-50 border border-red-300 rounded-lg text-sm text-red-700">
        <i class="fas fa-exclamation-triangle text-red-500"></i>
        <span id="fs_warning_text">Credit limit will be exceeded.</span>
    </div>
</div>

<!-- Submit -->
<div class="flex justify-end gap-4">
    <a href="index.php" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
        Cancel
    </a>
    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
        <i class="fas fa-check mr-2"></i>Submit for Approval
    </button>
</div>

</form>

</div>

<script>
const productVariants     = <?php echo json_encode($product_variants); ?>;
const miniTruckSurcharges = <?php echo json_encode($mini_truck_surcharges); ?>;

let orderItems = [];
let cartDiscount = { type: 'percent', value: 0, amount: 0 };

// ── Delivery type ──────────────────────────────────────────────────────────
function getDeliveryType() {
    return document.querySelector('input[name="delivery_type"]:checked')?.value || 'big_truck';
}

function getMiniSurcharge(branchId, weightClass) {
    if (getDeliveryType() !== 'mini_truck') return 0;
    const s = miniTruckSurcharges[String(branchId)] || {};
    if (weightClass === '50') return parseFloat(s.surcharge_50 || 0);
    if (weightClass === '74') return parseFloat(s.surcharge_74 || 0);
    return 0; // custom weights — no surcharge defined
}

function onDeliveryTypeChange() {
    const isMini = getDeliveryType() === 'mini_truck';

    // Toggle button styles
    document.getElementById('lbl_big_truck').className =
        'flex-1 flex items-center justify-center gap-2 px-4 py-2 cursor-pointer transition-colors ' +
        (isMini ? 'bg-white text-gray-600 border-r border-gray-300' : 'bg-blue-600 text-white');
    document.getElementById('lbl_mini_truck').className =
        'flex-1 flex items-center justify-center gap-2 px-4 py-2 cursor-pointer transition-colors border-l border-gray-300 ' +
        (isMini ? 'bg-orange-500 text-white' : 'bg-white text-gray-600');

    document.getElementById('mini_truck_note').classList.toggle('hidden', !isMini);

    // Recalculate effective price for every item already in the cart
    orderItems.forEach(item => {
        const surcharge = getMiniSurcharge(item.branch_id, item.weight_class);
        item.effective_price   = item.base_price + surcharge;
        item.mini_surcharge    = surcharge;
        // Recompute line total with new effective_price
        item.line_total = item.quantity * (item.effective_price - item.discount_per_unit);
        item.discount   = item.discount_per_unit * item.quantity;
    });

    renderItems();
    calculateTotals();
}

// Product selection handler
document.getElementById('add_product').addEventListener('change', function() {
    loadVariants();
});

function loadVariants() {
    const productSelect = document.getElementById('add_product');
    const productId = productSelect.value;
    const variantSelect = document.getElementById('add_variant');
    const priceInput = document.getElementById('add_price');
    const unitInput = document.getElementById('add_unit');
    const stockInput = document.getElementById('add_stock');
    
    variantSelect.innerHTML = '<option value="">-- Select Variant & Source --</option>';
    priceInput.value = '';
    unitInput.value = '';
    stockInput.value = '';
    
    if (!productId) {
        variantSelect.disabled = true;
        return;
    }

    if (!productVariants[productId]) {
        variantSelect.disabled = true;
        return;
    }

    variantSelect.disabled = false;
    const variants = productVariants[productId];

    variants.forEach((v, index) => {
        const opt = document.createElement('option');
        
        // Create unique identifier using price_id
        opt.value = v.price_id;
        
        // Build variant display name
        let variantName = [];
        if (v.grade) variantName.push(v.grade);
        if (v.weight_variant) variantName.push(v.weight_variant);
        
        // Build display text with source and stock info
        const stockStatus = v.stock_usable > 0 ? 
            `Stock: ${parseFloat(v.stock_usable).toFixed(0)}` : 
            'Out of Stock';
        
        const stockClass = v.stock_usable > 0 ? '' : ' [OUT]';
        
        opt.text = `${variantName.join(' - ')} | ${v.branch_name} | ৳${parseFloat(v.unit_price).toFixed(0)}`;
        
        // Store all data in dataset
        opt.dataset.variantId   = v.variant_id;
        opt.dataset.priceId     = v.price_id;
        opt.dataset.branchId    = v.branch_id;
        opt.dataset.branchName  = v.branch_name;
        opt.dataset.branchCode  = v.branch_code;
        opt.dataset.price       = v.unit_price;
        opt.dataset.sku         = v.sku;
        opt.dataset.unit        = v.unit_of_measure;
        opt.dataset.grade       = v.grade || '';
        opt.dataset.weight      = v.weight_variant || '';
        opt.dataset.weightClass = v.weight_class || 'custom';
        opt.dataset.stock       = v.stock_usable;
        
        // Disable if out of stock
        if (v.stock_usable <= 0) {
            opt.disabled = false;
            opt.style.color = '#999';
        }
        
        variantSelect.appendChild(opt);
    });
}

// Variant selection handler
document.getElementById('add_variant').addEventListener('change', function() {
    const option = this.options[this.selectedIndex];
    if (option.value) {
        document.getElementById('add_price').value = option.dataset.price || '';
        document.getElementById('add_unit').value = option.dataset.unit || '';
        
        const stock = parseFloat(option.dataset.stock) || 0;
        const stockInput = document.getElementById('add_stock');
        stockInput.value = stock.toFixed(0);
        
        // Color code based on stock level
        if (stock <= 0) {
            stockInput.style.color = '#dc2626';
            stockInput.style.fontWeight = 'bold';
        } else if (stock < 10) {
            stockInput.style.color = '#ea580c';
            stockInput.style.fontWeight = 'bold';
        } else {
            stockInput.style.color = '#16a34a';
            stockInput.style.fontWeight = 'bold';
        }
    } else {
        document.getElementById('add_price').value = '';
        document.getElementById('add_unit').value = '';
        document.getElementById('add_stock').value = '';
    }
});

function addItem() {
    const productSelect = document.getElementById('add_product');
    const variantSelect = document.getElementById('add_variant');
    const quantity = parseFloat(document.getElementById('add_quantity').value) || 0;
    const price = parseFloat(document.getElementById('add_price').value) || 0;
    const discountValue = parseFloat(document.getElementById('add_discount').value) || 0;
    const discountType = document.getElementById('add_discount_type').value;
    
    if (!productSelect.value) {
        alert('Please select a product');
        return;
    }
    
    if (!variantSelect.value) {
        alert('Please select a variant and source');
        return;
    }
    
    if (quantity <= 0) {
        alert('Please enter a valid quantity');
        return;
    }
    
    if (price <= 0) {
        alert('Invalid price');
        return;
    }
    
    const variantOption  = variantSelect.options[variantSelect.selectedIndex];
    const availableStock = parseFloat(variantOption.dataset.stock) || 0;

    // Check stock availability
    if (quantity > availableStock) {
        if (!confirm(`Warning: Requested quantity (${quantity}) exceeds available stock (${availableStock}). Continue anyway?`)) {
            return;
        }
    }

    const basePrice     = parseFloat(variantOption.dataset.price) || 0;
    const weightClass   = variantOption.dataset.weightClass || 'custom';
    const branchId      = variantOption.dataset.branchId;
    const miniSurcharge = getMiniSurcharge(branchId, weightClass);
    const effectivePrice = basePrice + miniSurcharge;

    // Calculate discount amount per unit (applied on effective price)
    let discountPerUnit = 0;
    if (discountType === 'percent') {
        discountPerUnit = (effectivePrice * discountValue) / 100;
    } else {
        discountPerUnit = discountValue;
    }

    const totalDiscountAmount = discountPerUnit * quantity;
    const lineTotal = quantity * (effectivePrice - discountPerUnit);

    let variantDisplay = [];
    if (variantOption.dataset.grade)  variantDisplay.push(variantOption.dataset.grade);
    if (variantOption.dataset.weight) variantDisplay.push(variantOption.dataset.weight);

    const item = {
        product_id:      productSelect.value,
        product_name:    productSelect.options[productSelect.selectedIndex].text.split('(')[0].trim(),
        variant_id:      variantOption.dataset.variantId,
        price_id:        variantOption.dataset.priceId,
        branch_id:       branchId,
        branch_name:     variantOption.dataset.branchName,
        branch_code:     variantOption.dataset.branchCode,
        variant_name:    variantDisplay.join(' - '),
        variant_sku:     variantOption.dataset.sku,
        unit_of_measure: variantOption.dataset.unit,
        weight_class:    weightClass,
        quantity:        quantity,
        base_price:      basePrice,
        mini_surcharge:  miniSurcharge,
        effective_price: effectivePrice,
        unit_price:      effectivePrice,   // what gets saved to DB
        discount_type:   discountType,
        discount_value:  discountValue,
        discount_per_unit: discountPerUnit,
        discount:        totalDiscountAmount,
        tax:             0,
        line_total:      lineTotal,
        available_stock: availableStock
    };
    
    orderItems.push(item);
    renderItems();
    calculateTotals();
    
    // Reset form
    document.getElementById('add_product').value = '';
    document.getElementById('add_variant').innerHTML = '<option value="">-- Select Variant & Source --</option>';
    document.getElementById('add_variant').disabled = true;
    document.getElementById('add_quantity').value = '1';
    document.getElementById('add_price').value = '';
    document.getElementById('add_discount').value = '0';
    document.getElementById('add_discount_type').value = 'percent';
    document.getElementById('add_unit').value = '';
    document.getElementById('add_stock').value = '';
}

function renderItems() {
    const tbody = document.getElementById('itemsBody');
    tbody.innerHTML = '';
    
    if (orderItems.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9" class="px-4 py-8 text-center text-gray-400">
                    <i class="fas fa-shopping-cart text-5xl mb-3 opacity-50"></i>
                    <p class="text-lg">Cart is empty - Add products above</p>
                </td>
            </tr>
        `;
        return;
    }
    
    orderItems.forEach((item, index) => {
        const discountDisplay = item.discount_type === 'percent' 
            ? `${item.discount_value}%` 
            : `৳${item.discount_value.toFixed(2)}`;
        
        const stockWarning = item.quantity > item.available_stock ? 
            `<span class="text-red-600 text-xs block mt-1">⚠ Exceeds stock!</span>` : '';
        
        const row = document.createElement('tr');
        row.className = 'hover:bg-gray-50';
        row.innerHTML = `
            <td class="px-3 py-3 text-sm">${item.product_name}</td>
            <td class="px-3 py-3 text-sm">${item.variant_name || '-'}</td>
            <td class="px-3 py-3 text-sm">
                <span class="inline-block px-2 py-1 bg-blue-100 text-blue-800 rounded text-xs font-semibold">
                    ${item.branch_code}
                </span>
            </td>
            <td class="px-3 py-3 text-xs text-gray-600">${item.variant_sku}</td>
            <td class="px-3 py-3 text-sm text-right">
                <input type="number" value="${item.quantity}" min="1" step="1"
                       onchange="updateQuantity(${index}, this.value)"
                       class="w-16 px-2 py-1 border rounded text-right text-sm">
                <span class="text-xs text-gray-500 ml-1">${item.unit_of_measure}</span>
                ${stockWarning}
            </td>
            <td class="px-3 py-3 text-sm text-right">
                ৳${item.effective_price.toFixed(0)}
                ${item.mini_surcharge > 0
                    ? `<span class="block text-[10px] text-orange-500">৳${item.base_price.toFixed(0)} + ৳${item.mini_surcharge.toFixed(0)}</span>`
                    : ''}
            </td>
            <td class="px-3 py-3 text-sm text-right text-red-600">
                <span class="text-xs text-gray-500 block">${discountDisplay}/unit</span>
                ৳${item.discount.toFixed(2)}
            </td>
            <td class="px-3 py-3 text-sm text-right font-bold text-green-600">৳${item.line_total.toFixed(2)}</td>
            <td class="px-3 py-3 text-center">
                <button type="button" onclick="removeItem(${index})" class="text-red-600 hover:text-red-900">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        `;
        tbody.appendChild(row);
    });
}

function updateQuantity(index, newQty) {
    const qty  = parseFloat(newQty) || 1;
    const item = orderItems[index];
    item.quantity    = qty;
    item.discount    = item.discount_per_unit * qty;
    item.line_total  = qty * (item.effective_price - item.discount_per_unit);
    renderItems();
    calculateTotals();
}

function removeItem(index) {
    orderItems.splice(index, 1);
    renderItems();
    calculateTotals();
}

function applyCartDiscount() {
    cartDiscount.type = document.getElementById('cart_discount_type').value;
    cartDiscount.value = parseFloat(document.getElementById('cart_discount_value').value) || 0;
    calculateTotals();
}

function calculateTotals() {
    // Calculate subtotal (before any discounts) using effective price (includes mini-truck surcharge)
    const subtotal = orderItems.reduce((sum, item) => sum + (item.effective_price * item.quantity), 0);
    
    // Calculate item-level discounts
    const itemDiscount = orderItems.reduce((sum, item) => sum + item.discount, 0);
    
    // Calculate subtotal after item discounts
    const subtotalAfterItemDiscount = subtotal - itemDiscount;
    
    // Calculate cart discount
    let cartDiscountAmount = 0;
    if (cartDiscount.value > 0) {
        if (cartDiscount.type === 'percent') {
            cartDiscountAmount = (subtotalAfterItemDiscount * cartDiscount.value) / 100;
        } else {
            cartDiscountAmount = cartDiscount.value;
            // Don't allow cart discount to exceed subtotal after item discounts
            if (cartDiscountAmount > subtotalAfterItemDiscount) {
                cartDiscountAmount = subtotalAfterItemDiscount;
            }
        }
    }
    cartDiscount.amount = cartDiscountAmount;
    
    // Calculate total discount (item + cart)
    const totalDiscount = itemDiscount + cartDiscountAmount;
    
    // Calculate tax (currently 0, but structure is ready)
    const tax = 0;
    
    // Calculate final total
    const total = subtotal - totalDiscount + tax;
    
    // Calculate balance due
    const advance = parseFloat(document.querySelector('[name="advance_paid"]').value) || 0;
    const balance = total - advance;
    
    // Update hidden form fields
    document.getElementById('subtotal').value = subtotal.toFixed(2);
    document.getElementById('discount_amount').value = totalDiscount.toFixed(2);
    document.getElementById('tax_amount').value = tax.toFixed(2);
    document.getElementById('items_json').value = JSON.stringify(orderItems);
    
    // Update display fields
    document.getElementById('display_subtotal').textContent = '৳' + subtotal.toFixed(2);
    document.getElementById('display_item_discount').textContent = '৳' + itemDiscount.toFixed(2);
    document.getElementById('display_cart_discount').textContent = '৳' + cartDiscountAmount.toFixed(2);
    document.getElementById('display_total_discount').textContent = '৳' + totalDiscount.toFixed(2);
    document.getElementById('display_tax').textContent = '৳' + tax.toFixed(2);
    document.getElementById('display_total').textContent = '৳' + total.toFixed(2);
    document.getElementById('display_balance').textContent = '৳' + balance.toFixed(2);
    
    updateCreditUsage(total);
}

// Customer live-search data — balance comes from ledger (true_balance), not stale current_balance
const customerData = <?php echo json_encode(array_map(function($c) {
    return [
        'id'          => $c->id,
        'name'        => $c->name,
        'phone'       => $c->phone_number ?? '',
        'address'     => $c->business_address ?? '',
        'creditLimit' => (float)($c->credit_limit ?? 0),
        'initialDue'  => (float)($c->initial_due ?? 0),
        'balance'     => (float)($c->true_balance ?? $c->initial_due ?? 0),
    ];
}, $customers)); ?>;

function searchCustomers(query) {
    const dropdown = document.getElementById('customer_dropdown');
    const q = query.toLowerCase().trim();
    const matches = q.length === 0
        ? customerData.slice(0, 20)
        : customerData.filter(c =>
            c.name.toLowerCase().includes(q) || c.phone.includes(q)
          ).slice(0, 20);

    const newCustBtn = `<div class="px-4 py-2 bg-green-50 hover:bg-green-100 cursor-pointer text-sm border-t border-green-200 flex items-center gap-2"
                              onclick="openNewCustomerModal()">
        <i class="fas fa-user-plus text-green-600"></i>
        <span class="font-semibold text-green-700">Add New Customer...</span>
    </div>`;

    if (matches.length === 0) {
        dropdown.innerHTML = '<div class="px-4 py-3 text-sm text-gray-500">No customers found</div>' + newCustBtn;
    } else {
        dropdown.innerHTML = matches.map(c => {
            const avail = c.creditLimit - c.balance;
            let limitStr = '', limitCls = 'text-blue-600';
            if (c.creditLimit > 0) {
                if (avail <= 0) {
                    limitStr = ` — ⚠ Over limit by ৳${Math.abs(avail).toFixed(0)}`;
                    limitCls = 'text-red-600 font-semibold';
                } else {
                    limitStr = ` — Avail: ৳${avail.toFixed(0)}`;
                }
            }
            return `<div class="px-4 py-2 hover:bg-blue-50 cursor-pointer text-sm border-b border-gray-100"
                         onclick="selectCustomer(${c.id})"
                         data-id="${c.id}"
                         data-name="${c.name.replace(/"/g,'&quot;')}"
                         data-credit-limit="${c.creditLimit}"
                         data-initial-due="${c.initialDue}"
                         data-balance="${c.balance}">
                <span class="font-medium text-gray-900">${c.name}</span>
                <span class="text-gray-400 text-xs ml-2">${c.phone}</span>
                <span class="${limitCls} text-xs">${limitStr}</span>
            </div>`;
        }).join('') + newCustBtn;
    }
    dropdown.classList.remove('hidden');
}

function selectCustomer(id) {
    const c = customerData.find(x => x.id === id);
    if (!c) return;
    document.getElementById('customer_id').value = c.id;
    document.getElementById('customer_search').value = c.name;
    document.getElementById('customer_dropdown').classList.add('hidden');

    // Populate info card
    const avail     = c.creditLimit - c.balance;
    const overLimit = c.creditLimit > 0 && avail <= 0;
    document.getElementById('sc_name').textContent = c.name;
    document.getElementById('sc_phone').textContent = c.phone || '—';
    document.getElementById('sc_address').textContent = c.address || 'No address on file';
    const balEl = document.getElementById('sc_balance');
    balEl.textContent = '৳' + fmtNum(c.balance);
    balEl.className = 'font-bold text-sm ' + (overLimit ? 'text-red-600' : 'text-gray-900');
    const availEl = document.getElementById('sc_avail');
    if (c.creditLimit <= 0) {
        availEl.textContent = 'No limit set';
        availEl.className = 'font-bold text-sm text-gray-500';
    } else if (overLimit) {
        availEl.textContent = '⚠ -৳' + fmtNum(Math.abs(avail));
        availEl.className = 'font-bold text-sm text-red-600';
    } else {
        availEl.textContent = '৳' + fmtNum(avail);
        availEl.className = 'font-bold text-sm text-green-600';
    }
    document.getElementById('selected_customer_card').classList.remove('hidden');

    // Auto-fill shipping address if "Use customer address" mode is active
    if (document.getElementById('ship_use_customer').checked) {
        document.getElementById('shipping_address_field').value = c.address || '';
    }

    updateCustomerInfo(c);
}

// Hide dropdown on outside click
document.addEventListener('click', function(e) {
    if (!e.target.closest('#customer_search') && !e.target.closest('#customer_dropdown')) {
        document.getElementById('customer_dropdown').classList.add('hidden');
    }
});

function updateCustomerInfo(c) {
    if (!c) {
        document.getElementById('creditInfo').classList.add('hidden');
        document.getElementById('financialSummary').classList.add('hidden');
        return;
    }
    const creditLimit = c.creditLimit || 0;
    const balance     = c.balance     || 0;
    const available   = creditLimit - balance;
    const overLimit   = creditLimit > 0 && available <= 0;

    // Compact top badge
    document.getElementById('creditLimit').textContent = '৳' + fmtNum(creditLimit);
    const availEl = document.getElementById('availableCredit');
    if (overLimit) {
        availEl.textContent = '⚠ Over by ৳' + fmtNum(Math.abs(available));
        availEl.className = 'font-bold text-red-600';
    } else {
        availEl.textContent = '৳' + fmtNum(available);
        availEl.className = 'font-bold text-green-600';
    }
    const panel = document.getElementById('creditInfoPanel');
    if (overLimit) {
        panel.className = 'flex flex-wrap items-center gap-3 px-3 py-2 rounded-lg border text-sm bg-red-50 border-red-300';
    } else {
        panel.className = 'flex flex-wrap items-center gap-3 px-3 py-2 rounded-lg border text-sm bg-blue-50 border-blue-200';
    }
    document.getElementById('creditInfo').classList.remove('hidden');

    const orderTotal = parseFloat(document.getElementById('subtotal').value) || 0;
    updateFinancialSummary(c, orderTotal);
}

function fmtNum(n) {
    return Number(n).toLocaleString('en-IN', {minimumFractionDigits: 0, maximumFractionDigits: 0});
}

function updateFinancialSummary(c, orderTotal) {
    if (!c) return;
    const creditLimit  = c.creditLimit || 0;
    const currentDue   = c.balance     || 0;
    const advance      = parseFloat(document.querySelector('[name="advance_paid"]').value) || 0;
    const balanceDue   = orderTotal - advance;
    const afterOrder   = currentDue + balanceDue;
    const available    = creditLimit - currentDue;
    const afterAvail   = creditLimit - afterOrder;
    const usagePct     = creditLimit > 0 ? Math.min((afterOrder / creditLimit) * 100, 150) : 0;
    const overLimit    = creditLimit > 0 && afterOrder > creditLimit;

    document.getElementById('financialSummary').classList.remove('hidden');

    document.getElementById('fs_order_total').textContent  = '৳' + fmtNum(orderTotal);
    document.getElementById('fs_advance').textContent      = '৳' + fmtNum(advance);
    document.getElementById('fs_balance_due').textContent  = '৳' + fmtNum(balanceDue);
    document.getElementById('fs_current_due').textContent  = '৳' + fmtNum(currentDue);
    document.getElementById('fs_after_order').textContent  = '৳' + fmtNum(afterOrder);
    document.getElementById('fs_credit_limit').textContent = '৳' + fmtNum(creditLimit);

    // Credit usage bar and label
    const usageEl  = document.getElementById('fs_usage_pct');
    const barEl    = document.getElementById('fs_usage_bar');
    const availEl2 = document.getElementById('fs_available');
    const labelEl  = document.getElementById('fs_avail_label');

    if (creditLimit <= 0) {
        usageEl.textContent  = 'No limit';
        barEl.style.width    = '0%';
        barEl.className      = 'h-2 rounded-full bg-gray-400 transition-all duration-300';
        availEl2.textContent = 'Unlimited';
        labelEl.textContent  = '';
    } else {
        const displayPct = Math.min(usagePct, 100);
        barEl.style.width = displayPct + '%';
        usageEl.textContent = usagePct.toFixed(1) + '%' + (usagePct > 100 ? ' (exceeded)' : '');

        if (overLimit) {
            barEl.className  = 'h-2 rounded-full bg-red-500 transition-all duration-300';
            usageEl.className = 'font-semibold text-red-600';
            availEl2.textContent = '-৳' + fmtNum(Math.abs(afterAvail));
            availEl2.className = 'font-semibold text-red-600';
            labelEl.textContent = 'Over by:';
        } else if (usagePct > 80) {
            barEl.className  = 'h-2 rounded-full bg-orange-400 transition-all duration-300';
            usageEl.className = 'font-semibold text-orange-600';
            availEl2.textContent = '৳' + fmtNum(afterAvail);
            availEl2.className = 'font-semibold text-orange-600';
            labelEl.textContent = 'Remaining:';
        } else {
            barEl.className  = 'h-2 rounded-full bg-green-500 transition-all duration-300';
            usageEl.className = 'font-semibold text-green-600';
            availEl2.textContent = '৳' + fmtNum(afterAvail);
            availEl2.className = 'font-semibold text-green-600';
            labelEl.textContent = 'Remaining:';
        }
    }

    // After-order panel colour
    const afterPanel = document.getElementById('fs_after_panel');
    if (overLimit) {
        afterPanel.className = 'bg-red-50 border border-red-300 rounded-lg p-4';
        document.getElementById('fs_after_order').className = 'text-xl font-bold text-red-700';
    } else {
        afterPanel.className = 'bg-gray-50 border border-gray-200 rounded-lg p-4';
        document.getElementById('fs_after_order').className = 'text-xl font-bold text-gray-700';
    }

    // Credit limit panel colour
    const creditPanel = document.getElementById('fs_credit_panel');
    if (overLimit) {
        creditPanel.className = 'bg-red-50 border border-red-300 rounded-lg p-4';
    } else if (usagePct > 80) {
        creditPanel.className = 'bg-orange-50 border border-orange-200 rounded-lg p-4';
    } else {
        creditPanel.className = 'bg-green-50 border border-green-200 rounded-lg p-4';
    }

    // compact top badge usage text
    const usageBadge = document.getElementById('creditUsage');
    if (usageBadge) {
        usageBadge.textContent = usagePct.toFixed(1) + '%';
        usageBadge.className = 'font-bold ' + (overLimit ? 'text-red-600' : usagePct > 80 ? 'text-orange-500' : 'text-blue-600');
    }

    // Warning banner
    const warnEl = document.getElementById('fs_warning');
    if (overLimit && creditLimit > 0) {
        document.getElementById('fs_warning_text').textContent =
            'Credit limit exceeded after this order. ' +
            'Limit: ৳' + fmtNum(creditLimit) + ' | ' +
            'Total outstanding will be: ৳' + fmtNum(afterOrder) + ' | ' +
            'Over by: ৳' + fmtNum(Math.abs(afterAvail));
        warnEl.classList.remove('hidden');
    } else {
        warnEl.classList.add('hidden');
    }
}

function updateCreditUsage(orderTotal) {
    const cid = parseInt(document.getElementById('customer_id').value);
    if (!cid) return;
    const c = customerData.find(x => x.id === cid);
    if (!c) return;
    updateFinancialSummary(c, orderTotal);
}

function onShipTypeChange() {
    const isCustomer = document.getElementById('ship_use_customer').checked;
    const field = document.getElementById('shipping_address_field');
    const hint  = document.getElementById('ship_hint');
    if (isCustomer) {
        const cid = parseInt(document.getElementById('customer_id').value);
        const c   = customerData.find(x => x.id === cid);
        field.value    = c ? (c.address || '') : '';
        field.readOnly = true;
        field.className = 'w-full px-4 py-2 border rounded-lg bg-gray-50 text-gray-700';
        hint.textContent = 'Auto-filled from customer record. Choose "Enter manually" to use a different address.';
    } else {
        field.value    = '';
        field.readOnly = false;
        field.className = 'w-full px-4 py-2 border rounded-lg';
        hint.textContent = 'Type the delivery / shipping address.';
        field.focus();
    }
}

document.getElementById('creditOrderForm').addEventListener('submit', function(e) {
    if (orderItems.length === 0) {
        e.preventDefault();
        alert('Please add at least one item to the order');
        return false;
    }
    const ship = document.getElementById('shipping_address_field').value.trim();
    if (!ship) {
        e.preventDefault();
        alert('Shipping address is required. If the customer has no address on file, select "Enter manually" and type one.');
        document.getElementById('ship_manual').checked = true;
        onShipTypeChange();
        return false;
    }
});

// ── New Customer Quick-Add ───────────────────────────────────────────────────
function openNewCustomerModal() {
    document.getElementById('customer_dropdown').classList.add('hidden');
    document.getElementById('newCustomerModal').classList.remove('hidden');
    document.getElementById('nc_name').focus();
}
function closeNewCustomerModal() {
    document.getElementById('newCustomerModal').classList.add('hidden');
    document.getElementById('newCustomerForm').reset();
    document.getElementById('nc_error').textContent = '';
}

// Use event delegation — the modal HTML is injected after this <script> block
// so getElementById('newCustomerForm') would be null here. Listening on document
// works regardless of when the element is added to the DOM.
document.addEventListener('submit', async function(e) {
    if (e.target.id !== 'newCustomerForm') return;
    e.preventDefault();

    const btn   = document.getElementById('nc_submit');
    const errEl = document.getElementById('nc_error');
    errEl.textContent = '';
    btn.disabled = true;
    btn.textContent = 'Saving...';

    const csrfInput = document.querySelector('[name="csrf_token"]');
    const csrfToken = csrfInput ? csrfInput.value : '';

    const payload = {
        action:       'create_customer_quick',
        csrf_token:   csrfToken,
        name:         document.getElementById('nc_name').value.trim(),
        phone:        document.getElementById('nc_phone').value.trim(),
        email:        document.getElementById('nc_email').value.trim(),
        address:      document.getElementById('nc_address').value.trim(),
        credit_limit: document.getElementById('nc_credit_limit').value,
        initial_due:  document.getElementById('nc_initial_due').value,
    };

    try {
        const resp = await fetch('ajax_handler.php', {
            method: 'POST',
            headers: {
                'Content-Type':  'application/json',
                'X-CSRF-Token':  csrfToken,
            },
            body: JSON.stringify(payload)
        });
        const resData = await resp.json();
        if (!resData.success) throw new Error(resData.error || 'Failed to create customer');

        // Inject into live customerData and auto-select
        customerData.push(resData.customer);
        selectCustomer(resData.customer.id);
        closeNewCustomerModal();
    } catch (err) {
        errEl.textContent = err.message;
    } finally {
        btn.disabled = false;
        btn.textContent = 'Save Customer';
    }
});
</script>

<!-- New Customer Modal -->
<div id="newCustomerModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-40">
  <div class="bg-white rounded-xl shadow-2xl w-full max-w-md mx-4 p-6" onclick="event.stopPropagation()">
    <div class="flex justify-between items-center mb-5">
      <h3 class="text-xl font-bold text-gray-900">
        <i class="fas fa-user-plus text-green-600 mr-2"></i>New Customer
      </h3>
      <button onclick="closeNewCustomerModal()" class="text-gray-400 hover:text-gray-700 text-xl font-bold">&times;</button>
    </div>
    <p id="nc_error" class="text-red-600 text-sm mb-3 font-medium"></p>
    <form id="newCustomerForm" class="space-y-4">
      <div class="grid grid-cols-2 gap-4">
        <div class="col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Customer Name <span class="text-red-500">*</span></label>
          <input id="nc_name" type="text" required
                 class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 text-sm"
                 placeholder="Full name or business name">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Phone <span class="text-red-500">*</span></label>
          <input id="nc_phone" type="text" required
                 class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 text-sm"
                 placeholder="01XXXXXXXXX">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
          <input id="nc_email" type="email"
                 class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 text-sm"
                 placeholder="Optional">
        </div>
        <div class="col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
          <input id="nc_address" type="text"
                 class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 text-sm"
                 placeholder="Business / delivery address">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Credit Limit (৳)</label>
          <input id="nc_credit_limit" type="number" step="1" min="0" value="0"
                 class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 text-sm">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Opening Due (৳)</label>
          <input id="nc_initial_due" type="number" step="0.01" min="0" value="0"
                 class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 text-sm"
                 placeholder="Prior outstanding amount">
        </div>
      </div>
      <div class="flex gap-3 pt-2">
        <button id="nc_submit" type="submit"
                class="flex-1 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-semibold text-sm">
          Save Customer
        </button>
        <button type="button" onclick="closeNewCustomerModal()"
                class="flex-1 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 font-semibold text-sm">
          Cancel
        </button>
      </div>
    </form>
  </div>
</div>

<?php require_once '../templates/footer.php'; ?>