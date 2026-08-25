<?php
// CRITICAL: Prevent any output before JSON
ob_start();

require_once '../core/init.php';

// Set JSON header
header('Content-Type: application/json');

// Security check: User must be logged in
if (!isset($_SESSION['user_id'])) {
    ob_end_clean(); http_response_code(401); echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
}
$user_id = $_SESSION['user_id'];


global $db;
$user_role = $_SESSION['user_role'] ?? '';
$data = [];

try {
    // Read request body
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw   = file_get_contents('php://input');
        $data  = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Fall back to POST form data
            $data = $_POST;
        }
    } else {
        $data = $_GET;
    }

    // CSRF check (skip for GET; for POST verify header or body token)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $session_token  = $_SESSION['csrf_token'] ?? '';
        $received_token = $_SERVER['HTTP_X_CSRF_TOKEN']
                       ?? $data['csrf_token']
                       ?? '';
        // Require both tokens to be present and to match.
        // Previous logic was inverted: it only blocked mismatched tokens but allowed requests
        // with NO token at all (a CSRF bypass). Now every POST must carry a valid token.
        if (!$session_token || !$received_token || !hash_equals($session_token, $received_token)) {
            ob_end_clean();
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'CSRF validation failed']);
            exit;
        }
    }

    $action = $data['action'] ?? null;

    switch ($action) {
        
        case 'search_credit_customers':
            $term = trim($data['term'] ?? '');
            if (strlen($term) < 2) {
                echo json_encode(['success' => true, 'customers' => []]); exit;
            }
            $searchTerm = "%{$term}%";
            // Find CREDIT customers only
            $customers = $db->query(
                "SELECT id, name, business_name, phone_number, credit_limit, current_balance
                 FROM customers
                 WHERE customer_type = 'Credit' AND status = 'active'
                   AND (name LIKE ? OR business_name LIKE ? OR phone_number LIKE ?)
                 ORDER BY name ASC LIMIT 10",
                [$searchTerm, $searchTerm, $searchTerm]
            )->results();
            $result = ['success' => true, 'customers' => $customers];
            break;

        case 'search_products_for_branch':
            $term = trim($data['term'] ?? '');
            $branch_id = (int)($data['branch_id'] ?? 0);
            if (strlen($term) < 2 || $branch_id === 0) {
                echo json_encode(['success' => true, 'products' => []]); exit;
            }
            $searchTerm = "%{$term}%";

            // Find products that have a price AND stock at the selected branch
            $products = $db->query(
                "SELECT
                    pv.id as variant_id, pv.sku, pv.weight_variant, pv.grade, pv.unit_of_measure, p.base_name,
                    pp.unit_price,
                    COALESCE(inv.quantity, 0) as stock_quantity
                 FROM product_variants pv
                 JOIN products p ON pv.product_id = p.id
                 -- Join price for this branch
                 JOIN product_prices pp ON pp.variant_id = pv.id AND pp.branch_id = ? AND pp.is_active = 1
                 -- Join inventory for this branch
                 LEFT JOIN inventory inv ON inv.variant_id = pv.id AND inv.branch_id = ?
                 WHERE p.status = 'active' AND pv.status = 'active'
                   AND (p.base_name LIKE ? OR pv.sku LIKE ?)
                 ORDER BY p.base_name ASC LIMIT 10",
                [$branch_id, $branch_id, $searchTerm, $searchTerm]
            )->results();
            
            $result = ['success' => true, 'products' => $products];
            break;

        case 'place_credit_order':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');

            $pdo = $db->getPdo();
            $pdo->beginTransaction();

            try {
                $customer_id           = (int)$data['customer_id'];
                $fulfillment_branch_id = (int)($data['fulfillment_branch_id'] ?? 0);
                $cart                  = $data['cart'] ?? [];
                $subtotal              = (float)($data['subtotal']       ?? 0);
                $discount_amount       = (float)($data['discount_amount'] ?? 0);
                $total_amount          = (float)($data['total_amount']   ?? 0);
                $advance_paid          = (float)($data['advance_paid']   ?? 0);

                if ($total_amount <= 0) throw new Exception('Order total must be greater than zero.');
                if (empty($cart))       throw new Exception('Cart is empty.');

                // Server-side credit check
                $customer = $db->query(
                    "SELECT credit_limit, current_balance FROM customers WHERE id = ?",
                    [$customer_id]
                )->first();
                if (!$customer) throw new Exception('Customer not found.');

                $available_credit = (float)$customer->credit_limit - (float)$customer->current_balance;

                if ($customer->credit_limit > 0 && $total_amount > $available_credit) {
                    throw new Exception(
                        "Order total (৳{$total_amount}) exceeds available credit (৳{$available_credit})."
                    );
                }

                // Sequence-based order number (no rand())
                $date_prefix = date('Ymd');
                $branch_code = $fulfillment_branch_id
                    ? ($db->query("SELECT code FROM branches WHERE id = ?", [$fulfillment_branch_id])->first()->code ?? 'CR')
                    : 'CR';
                $last = $db->query(
                    "SELECT order_number FROM credit_orders WHERE order_number LIKE ? ORDER BY id DESC LIMIT 1",
                    ["CR-{$branch_code}-{$date_prefix}-%"]
                )->first();
                $seq          = $last ? (int)substr($last->order_number, -4) + 1 : 1;
                $order_number = sprintf("CR-%s-%s-%04d", $branch_code, $date_prefix, $seq);

                $balance_due = max(0, $total_amount - $advance_paid);

                // Insert into correct credit_orders table
                $order_id = $db->insert('credit_orders', [
                    'customer_id'        => $customer_id,
                    'order_number'       => $order_number,
                    'order_date'         => date('Y-m-d'),
                    'order_type'         => 'credit',
                    'status'             => 'pending_approval',
                    'priority'           => 'normal',
                    'assigned_branch_id' => $fulfillment_branch_id ?: null,
                    'subtotal'           => $subtotal,
                    'discount_amount'    => $discount_amount,
                    'tax_amount'         => 0,
                    'total_amount'       => $total_amount,
                    'advance_paid'       => $advance_paid,
                    'amount_paid'        => 0,
                    'balance_due'        => $balance_due,
                    'created_by_user_id' => $user_id,
                ]);
                if (!$order_id) throw new Exception('Failed to create credit order record.');

                // Insert items into credit_order_items
                // BUG FIX: was using 'subtotal' (not a real column). Correct column is
                // 'line_total'. Also added missing 'discount_amount' and 'tax_amount'.
                foreach ($cart as $item) {
                    $qty             = (float)($item['quantity'] ?? 0);
                    $unit_price      = (float)($item['unit_price'] ?? 0);
                    $discount_amount = (float)($item['discount_amount'] ?? $item['discount'] ?? 0);
                    $tax_amount      = (float)($item['tax_amount'] ?? $item['tax'] ?? 0);
                    $line_total      = ($qty * $unit_price) - $discount_amount + $tax_amount;
                    $db->insert('credit_order_items', [
                        'order_id'        => $order_id,
                        'product_id'      => (int)($item['product_id'] ?? 0),
                        'variant_id'      => (int)($item['variant_id'] ?? 0) ?: null,
                        'quantity'        => $qty,
                        'unit_price'      => $unit_price,
                        'discount_amount' => $discount_amount,
                        'tax_amount'      => $tax_amount,
                        'line_total'      => $line_total,
                    ]);
                }

                // Bug 7 fix: The customer_ledger entry and balance update were written AGAIN
                // at dispatch time in credit_dispatch.php, causing a double-debit on both
                // current_balance and the ledger.  The formal accounting recognition
                // (Debit AR / Credit Revenue) happens at dispatch; we must NOT create a
                // parallel ledger entry here.
                //
                // We still update current_balance here so that the credit-limit check on
                // the NEXT order reflects the credit already reserved for this order.
                $db->query(
                    "UPDATE customers SET current_balance = current_balance + ? WHERE id = ?",
                    [$total_amount, $customer_id]
                );

                $pdo->commit();
                $result = ['success' => true, 'order_number' => $order_number, 'order_id' => $order_id];

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            break;
        
       case 'get_outstanding_orders':
            if (!isset($data['customer_id'])) {
                throw new Exception('Customer ID is required.');
            }
            $customer_id = (int)$data['customer_id'];

            // Feature #1 — EDIT mode: when correcting an existing receipt, that
            // receipt is still posted at page-load, so the invoices it paid show a
            // reduced/zero balance. Add its allocations back so those invoices
            // reappear at their PRE-payment balance and stay editable.
            $edit_payment_id = isset($data['edit_payment_id']) ? (int)$data['edit_payment_id'] : 0;
            $addback = [];
            if ($edit_payment_id) {
                foreach ($db->query("SELECT order_id, allocated_amount FROM payment_allocations WHERE payment_id = ?", [$edit_payment_id])->results() as $a) {
                    $addback[(int)$a->order_id] = (float)$a->allocated_amount;
                }
                if (empty($addback)) {
                    $ep = $db->query("SELECT allocated_to_invoices FROM customer_payments WHERE id = ?", [$edit_payment_id])->first();
                    if ($ep && !empty($ep->allocated_to_invoices)) {
                        foreach ((json_decode($ep->allocated_to_invoices, true) ?: []) as $oid => $amt) {
                            if ((int)$oid > 0 && (float)$amt > 0) $addback[(int)$oid] = (float)$amt;
                        }
                    }
                }
            }

            // Fetches ALL live orders that still have a balance due — including
            // undispatched invoices (approved / in production / ready), so a payment
            // can be allocated to a just-created order even when the customer has no
            // delivered invoices yet. Safe accounting-wise: dispatch posts the FULL
            // invoice amount to the ledger, and payments post their own credit, so a
            // pre-shipment allocation never double-counts.
            // Uses (total_amount - amount_paid) as a self-healing fallback in case
            // balance_due drifted out of sync with the actual paid amount.
            $params    = [$customer_id];
            $extra_sql = '';
            if (!empty($addback)) {
                $extra_ids = array_map('intval', array_keys($addback));
                $extra_sql = ' OR id IN (' . implode(',', array_fill(0, count($extra_ids), '?')) . ')';
                $params    = array_merge($params, $extra_ids);
            }
            $orders = $db->query(
                "SELECT id, order_number, order_date, status, total_amount, amount_paid,
                        GREATEST(0, total_amount - amount_paid) AS balance_due
                 FROM credit_orders
                 WHERE customer_id = ?
                   AND status NOT IN ('draft', 'rejected', 'cancelled')
                   AND ((total_amount - amount_paid) > 0.01{$extra_sql})
                 ORDER BY
                    FIELD(status, 'delivered', 'shipped', 'ready_to_ship', 'produced',
                                  'in_production', 'approved', 'escalated', 'pending_approval'),
                    order_date ASC",
                $params
            )->results();

            // Restore pre-payment balances/paid-so-far for the receipt being edited,
            // so the invoice line shows what was true BEFORE this payment existed.
            foreach ($orders as $o) {
                if (isset($addback[(int)$o->id])) {
                    $o->balance_due  = (float)$o->balance_due + $addback[(int)$o->id];
                    $o->amount_paid  = max(0, (float)$o->amount_paid - $addback[(int)$o->id]);
                }
            }

            $result = ['success' => true, 'orders' => $orders];
            break;

        // ── Backfill: manually allocate an existing, previously-unallocated
        //    payment to one or more outstanding orders (Superadmin/admin only).
        //    Mirrors the exact allocation-application logic used when a NEW
        //    payment is created (see the main POST handler above this file's
        //    ajax counterpart in customer_payment.php), just applied to a
        //    payment that already exists in the system. ─────────────────────
        case 'allocate_payment':
            if (!in_array($user_role, ['Superadmin', 'admin'], true)) {
                throw new Exception('Only Superadmin/admin may allocate an existing payment.');
            }
            $payment_id  = (int)($data['payment_id'] ?? 0);
            $allocations = $data['allocations'] ?? [];
            if (!$payment_id) throw new Exception('Payment ID is required.');
            if (!is_array($allocations) || empty($allocations)) throw new Exception('Select at least one order to allocate to.');

            $pdo = $db->getPdo();
            $pdo->beginTransaction();
            try {
                $pay = $db->query("SELECT * FROM customer_payments WHERE id = ? FOR UPDATE", [$payment_id])->first();
                if (!$pay) throw new Exception("Payment #{$payment_id} not found.");

                $already = $db->query("SELECT COALESCE(SUM(allocated_amount),0) AS s FROM payment_allocations WHERE payment_id = ?", [$payment_id])->first();
                if ((float)($already->s ?? 0) > 0.01) {
                    throw new Exception('This payment already has allocations — use the payment edit flow to adjust it instead.');
                }

                $sum_new = array_sum(array_map('floatval', $allocations));
                if ($sum_new <= 0) throw new Exception('Allocation amounts must be greater than zero.');
                if ($sum_new > (float)$pay->amount + 0.01) {
                    throw new Exception('Total allocated (৳' . number_format($sum_new, 2) . ') cannot exceed the payment amount (৳' . number_format((float)$pay->amount, 2) . ').');
                }

                $applied = [];
                foreach ($allocations as $order_id => $amount) {
                    $order_id = (int)$order_id;
                    $amount   = (float)$amount;
                    if ($amount <= 0) continue;

                    $order = $db->query(
                        "SELECT id, order_number, customer_id, total_amount, amount_paid,
                                GREATEST(0, total_amount - amount_paid) AS balance_due
                         FROM credit_orders WHERE id = ? FOR UPDATE",
                        [$order_id]
                    )->first();
                    if (!$order) throw new Exception("Order #{$order_id} not found.");
                    if ((int)$order->customer_id !== (int)$pay->customer_id) {
                        throw new Exception("Order {$order->order_number} does not belong to this payment's customer.");
                    }
                    if ($amount > (float)$order->balance_due + 0.01) {
                        throw new Exception("Amount for order {$order->order_number} (৳" . number_format($amount, 2) . ") exceeds its balance due (৳" . number_format((float)$order->balance_due, 2) . ").");
                    }

                    $alloc_id = $db->insert('payment_allocations', [
                        'payment_id'           => $payment_id,
                        'order_id'             => $order_id,
                        'allocated_amount'     => $amount,
                        'allocation_date'      => date('Y-m-d'),
                        'allocated_by_user_id' => $user_id,
                    ]);
                    if (!$alloc_id) throw new Exception("Failed to record the allocation for order {$order->order_number}.");

                    $db->query(
                        "UPDATE credit_orders
                         SET amount_paid = amount_paid + ?,
                             balance_due = total_amount - amount_paid
                         WHERE id = ?",
                        [$amount, $order_id]
                    );
                    if ($db->error()) throw new Exception("Failed to update order {$order->order_number}'s paid amount/balance.");

                    $applied[$order->order_number] = $amount;
                }

                if (empty($applied)) throw new Exception('No valid allocation amounts were provided.');

                // Keep the JSON snapshot column (used elsewhere as a fallback source)
                // consistent with the real payment_allocations rows just created.
                $db->query("UPDATE customer_payments SET allocated_to_invoices = ? WHERE id = ?",
                    [json_encode($allocations), $payment_id]);

                $pdo->commit();

                if (function_exists('auditLog')) {
                    auditLog('customer_payments', 'backfilled_allocation',
                        "Payment {$pay->payment_number} (৳" . number_format($sum_new, 2) . ") manually allocated to " . count($applied) . " order(s) by " . (getCurrentUser()['display_name'] ?? 'Unknown') . " — was previously unallocated",
                        ['record_id' => $payment_id, 'reference' => $pay->payment_number,
                         'data' => ['customer_id' => $pay->customer_id, 'allocations' => $applied]]
                    );
                }

                $result = ['success' => true, 'message' => 'Payment allocated to ' . count($applied) . ' order(s).', 'applied' => $applied];
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            break;

        // ==========================================================
        // ===== START: CODE FOR CUSTOMER VIEW MODAL =====
        // ==========================================================
        case 'get_transaction_details':
            // Note: Security/Auth is already handled above
            if (!isset($data['ref_id']) || !isset($data['ref_type'])) {
                throw new Exception('Missing reference ID or type.');
            }
            
            $ref_id = (int)$data['ref_id'];
            $ref_type = $data['ref_type'];
            $html = '';
            
            // Start output buffering to capture HTML
            ob_start();

            // --- Handle Credit Orders ---
            if ($ref_type == 'credit_orders') {
                $order = $db->query("SELECT * FROM credit_orders WHERE id = ?", [$ref_id])->first();
                $items = $db->query(
                    "SELECT ci.*, p.base_name, pv.grade, pv.weight_variant
                     FROM credit_order_items ci
                     JOIN product_variants pv ON ci.variant_id = pv.id
                     JOIN products p ON pv.product_id = p.id
                     WHERE ci.order_id = ?",
                    [$ref_id]
                )->results();
                
                if ($order) {
                    $status_color = 'blue';
                    if ($order->status == 'delivered') $status_color = 'green';
                    if ($order->status == 'cancelled' || $order->status == 'rejected') $status_color = 'red';
                    
                    $status_html = '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-'.$status_color.'-100 text-'.$status_color.'-800">' . htmlspecialchars(ucwords(str_replace('_', ' ', $order->status))) . '</span>';
                    
                    ?>
                    <div class="space-y-4">
                        <div class="grid grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-500">Status</label>
                                <?php echo $status_html; ?>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-500">Order Date</label>
                                <p class="text-md font-semibold text-gray-900">
                                    <?php echo date('d-M-Y', strtotime($order->order_date)); ?>
                                </p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-500">Order Total</label>
                                <p class="text-xl font-bold text-gray-900">
                                    <?php echo number_format($order->total_amount, 2); ?> BDT
                                </p>
                            </div>
                        </div>
                        
                        <h4 class="text-lg font-medium text-gray-800 border-t pt-4">Order Items</h4>
                        <table class="min-w-full divide-y divide-gray-200 mt-2">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Unit Price</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Line Total</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($items as $item): 
                                    $product_name = htmlspecialchars($item->base_name . ' (' . $item->weight_variant . 'kg - ' . $item->grade . ')');
                                ?>
                                <tr>
                                    <td class="px-3 py-3 text-sm text-gray-800"><?php echo $product_name; ?></td>
                                    <td class="px-3 py-3 text-sm text-gray-700 text-right"><?php echo $item->quantity; ?></td>
                                    <td class="px-3 py-3 text-sm text-gray-700 text-right"><?php echo number_format($item->unit_price, 2); ?></td>
                                    <td class="px-3 py-3 text-sm text-gray-900 text-right font-medium"><?php echo number_format($item->line_total, 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                } else {
                    echo '<p class="text-red-500 text-center p-4">Error: Order not found.</p>';
                }
            } 
            
            // --- Handle Customer Payments ---
            elseif ($ref_type == 'customer_payments') {
                $payment = $db->query("SELECT * FROM customer_payments WHERE id = ?", [$ref_id])->first();
                if ($payment) {
                    ?>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-500">Payment Date</label>
                            <p class="text-md font-semibold text-gray-900"><?php echo date('d-M-Y', strtotime($payment->payment_date)); ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-500">Payment Amount</label>
                            <p class="text-2xl font-bold text-green-600"><?php echo number_format($payment->payment_amount, 2); ?> BDT</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-500">Payment Method</label>
                            <p class="text-md font-semibold text-gray-900">
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800"><?php echo htmlspecialchars($payment->payment_method); ?></span>
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-500">Reference / Cheque No.</label>
                            <p class="text-md font-semibold text-gray-900"><?php echo htmlspecialchars($payment->reference_number ?? 'N/A'); ?></p>
                        </div>
                        <div class="border-t pt-4">
                            <label class="block text-sm font-medium text-gray-500">Notes</label>
                            <p class="text-md text-gray-800"><?php echo nl2br(htmlspecialchars($payment->notes ?? 'No notes provided.')); ?></p>
                        </div>
                    </div>
                    <?php
                } else {
                    echo '<p class="text-red-500 text-center p-4">Error: Payment not found.</p>';
                }
            } 
            
            // --- Handle Unknown Types ---
            else {
                 echo '<p class="text-red-500 text-center p-4">Error: Unknown reference type provided.</p>';
            }

            // Get the buffered HTML
            $html = ob_get_clean();
            // Set the result to be JSON-encoded
            $result = ['success' => true, 'html' => $html];
            break;
        // ==========================================================
        // ===== END: CODE FOR CUSTOMER VIEW MODAL =====
        // ==========================================================


        case 'create_customer_quick':
            $name         = trim($data['name'] ?? '');
            $phone        = trim($data['phone'] ?? '');
            $email        = trim($data['email'] ?? '');
            $address      = trim($data['address'] ?? '');
            $credit_limit = max(0, (float)($data['credit_limit'] ?? 0));
            $initial_due  = max(0, (float)($data['initial_due'] ?? 0));

            if ($name === '')  throw new Exception('Customer name is required.');
            if ($phone === '') throw new Exception('Phone number is required.');

            $existing = $db->query("SELECT id FROM customers WHERE phone_number = ? LIMIT 1", [$phone])->first();
            if ($existing) throw new Exception("A customer with phone {$phone} already exists (ID #{$existing->id}).");

            $nc_pdo = $db->getPdo();
            $nc_pdo->beginTransaction();
            try {
                $new_id = $db->insert('customers', [
                    'name'             => $name,
                    'phone_number'     => $phone,
                    'email'            => $email ?: null,
                    'business_address' => $address ?: null,
                    'credit_limit'     => $credit_limit,
                    'initial_due'      => $initial_due,
                    'current_balance'  => $initial_due,
                    'customer_type'    => 'Credit',
                    'status'           => 'active',
                ]);
                if (!$new_id) throw new Exception('Database insert failed.');

                // Opening balance ledger entry when prior dues exist
                if ($initial_due > 0) {
                    $db->insert('customer_ledger', [
                        'customer_id'        => $new_id,
                        'transaction_date'   => date('Y-m-d'),
                        'transaction_type'   => 'adjustment',
                        'description'        => 'Opening balance carried forward',
                        'debit_amount'       => $initial_due,
                        'credit_amount'      => 0,
                        'balance_after'      => $initial_due,
                        'reference_type'     => 'opening_balance',
                        'reference_id'       => $new_id,
                        'created_by_user_id' => $user_id,
                    ]);
                }

                $nc_pdo->commit();
            } catch (Exception $e) {
                if ($nc_pdo->inTransaction()) $nc_pdo->rollBack();
                throw $e;
            }

            auditLog('customers', 'created_quick',
                "Quick customer creation: {$name} ({$phone}), credit_limit ৳{$credit_limit}, initial_due ৳{$initial_due}",
                ['record_id' => $new_id, 'name' => $name, 'phone' => $phone]
            );

            $result = [
                'success'  => true,
                'customer' => [
                    'id'          => (int)$new_id,     // must be int — JS uses parseInt() for lookup
                    'name'        => $name,
                    'phone'       => $phone,
                    'creditLimit' => (float)$credit_limit,
                    'initialDue'  => (float)$initial_due,
                    'balance'     => (float)$initial_due,
                ],
            ];
            break;

        case 'create_adjustment': {
            $role = $_SESSION['user_role'] ?? '';
            if (!in_array($role, ['Superadmin', 'admin'])) throw new Exception('Permission denied.');

            $cust_id  = (int)($data['customer_id'] ?? 0);
            $adj_type = in_array($data['adj_type'] ?? '', ['credit_note','debit_note','adjustment'])
                        ? $data['adj_type'] : 'adjustment';
            $amount   = round((float)($data['amount'] ?? 0), 2);
            $adj_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['adj_date'] ?? '') ? $data['adj_date'] : date('Y-m-d');
            $note     = trim($data['note'] ?? '');

            if ($cust_id <= 0)  throw new Exception('Invalid customer.');
            if ($amount <= 0)   throw new Exception('Amount must be greater than zero.');
            if ($note === '')   throw new Exception('Reason is required.');

            $cust = $db->query("SELECT id, initial_due, current_balance FROM customers WHERE id = ?", [$cust_id])->first();
            if (!$cust) throw new Exception('Customer not found.');

            // credit_note reduces balance (credit entry), debit_note/adjustment increases (debit entry)
            $is_credit = ($adj_type === 'credit_note');

            $pdo = $db->getPdo();
            $pdo->beginTransaction();

            $prev = $db->query(
                "SELECT balance_after FROM customer_ledger WHERE customer_id = ? ORDER BY id DESC LIMIT 1",
                [$cust_id]
            )->first();
            $prev_bal = $prev ? (float)$prev->balance_after
                              : (float)($cust->initial_due ?? 0);
            $new_bal  = $is_credit ? $prev_bal - $amount : $prev_bal + $amount;

            $uid = getCurrentUser()['id'] ?? null;
            $db->insert('customer_ledger', [
                'customer_id'        => $cust_id,
                'transaction_date'   => $adj_date,
                'transaction_type'   => $adj_type,
                'reference_type'     => 'manual_adjustment',
                'reference_id'       => null,
                'invoice_number'     => 'ADJ-' . strtoupper(date('ymdHi')),
                'description'        => ucfirst(str_replace('_', ' ', $adj_type)) . ': ' . $note,
                'debit_amount'       => $is_credit ? 0 : $amount,
                'credit_amount'      => $is_credit ? $amount : 0,
                'balance_after'      => $new_bal,
                'created_by_user_id' => $uid,
            ]);

            if ($is_credit) {
                $db->query("UPDATE customers SET current_balance = GREATEST(0, current_balance - ?) WHERE id = ?", [$amount, $cust_id]);
            } else {
                $db->query("UPDATE customers SET current_balance = current_balance + ? WHERE id = ?", [$amount, $cust_id]);
            }

            $pdo->commit();

            auditLog('customer_ledger', 'adjustment', "Manual {$adj_type} ৳{$amount} for customer #{$cust_id}: {$note}", [
                'customer_id' => $cust_id, 'type' => $adj_type, 'amount' => $amount,
            ]);

            $result = ['success' => true, 'message' => 'Adjustment recorded.'];
            break;
        }

        case 'delivery_schedule_edit_order': {
            $role = $_SESSION['user_role'] ?? '';
            if (!in_array($role, ['Superadmin', 'admin'])) throw new Exception('Permission denied.');

            $order_id      = (int)($data['order_id'] ?? 0);
            $required_date = trim($data['required_date'] ?? '');
            $delivery_type = in_array($data['delivery_type'] ?? '', ['big_truck', 'mini_truck'], true) ? $data['delivery_type'] : '';
            $branch_id     = (int)($data['branch_id'] ?? 0);

            if ($order_id <= 0) throw new Exception('Invalid order.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $required_date) || !strtotime($required_date)) throw new Exception('Invalid required date.');
            if ($delivery_type === '') throw new Exception('Invalid truck type.');
            if ($branch_id <= 0) throw new Exception('Invalid branch.');

            $order = $db->query(
                "SELECT id, order_number, required_date, delivery_type, assigned_branch_id, status FROM credit_orders WHERE id = ?",
                [$order_id]
            )->first();
            if (!$order) throw new Exception('Order not found.');
            if (!in_array($order->status, ['approved', 'in_production', 'produced', 'ready_to_ship', 'shipped'])) {
                throw new Exception('This order is no longer on the active delivery schedule.');
            }

            $branch = $db->query("SELECT id, name FROM branches WHERE id = ? AND status = 'active'", [$branch_id])->first();
            if (!$branch) throw new Exception('Branch not found or inactive.');

            $changes = [];
            if ($order->required_date !== $required_date) {
                $changes[] = 'Required Date: ' . date('d M Y', strtotime($order->required_date)) . ' → ' . date('d M Y', strtotime($required_date));
            }
            if ($order->delivery_type !== $delivery_type) {
                $changes[] = 'Truck: ' . ucwords(str_replace('_', ' ', $order->delivery_type)) . ' → ' . ucwords(str_replace('_', ' ', $delivery_type));
            }
            if ((int)$order->assigned_branch_id !== $branch_id) {
                $old_branch = $db->query("SELECT name FROM branches WHERE id = ?", [$order->assigned_branch_id])->first();
                $changes[] = 'Branch: ' . ($old_branch->name ?? '—') . ' → ' . $branch->name;
            }

            if (empty($changes)) {
                $result = ['success' => true, 'message' => 'No changes to save.'];
                break;
            }

            $pdo = $db->getPdo();
            $pdo->beginTransaction();
            try {
                $db->query(
                    "UPDATE credit_orders SET required_date = ?, delivery_type = ?, assigned_branch_id = ? WHERE id = ?",
                    [$required_date, $delivery_type, $branch_id, $order_id]
                );
                if ($db->error()) throw new Exception('Failed to update the order.');

                // Keep production_schedule.branch_id consistent if a schedule row exists
                $sched = $db->query("SELECT id FROM production_schedule WHERE order_id = ?", [$order_id])->first();
                if ($sched) {
                    $db->query("UPDATE production_schedule SET branch_id = ? WHERE order_id = ?", [$branch_id, $order_id]);
                    if ($db->error()) throw new Exception('Failed to update the production schedule branch.');
                }

                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            auditLogOrder('delivery_schedule_edit', $order_id, $order->order_number,
                "Delivery schedule updated for {$order->order_number}: " . implode('; ', $changes),
                ['changes' => $changes]
            );

            if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
                try {
                    require_once '../core/classes/TelegramNotifier.php';
                    $admin_name = getCurrentUser()['display_name'] ?? getCurrentUser()['name'] ?? 'Admin';
                    $msg = "📋 <b>Delivery Schedule Updated</b>\n"
                         . "Order <b>" . htmlspecialchars($order->order_number) . "</b> — by " . htmlspecialchars($admin_name) . "\n"
                         . implode("\n", array_map(fn($c) => '• ' . htmlspecialchars($c), $changes));
                    (new TelegramNotifier(TELEGRAM_BOT_TOKEN, getTelegramChatId('production')))->sendMessage($msg);
                } catch (\Throwable $e) { error_log('Telegram delivery_schedule_edit: ' . $e->getMessage()); }
            }

            $result = ['success' => true, 'message' => 'Delivery details updated.'];
            break;
        }

        case 'delivery_schedule_reorder': {
            $role = $_SESSION['user_role'] ?? '';
            if (!in_array($role, ['Superadmin', 'admin'])) throw new Exception('Permission denied.');

            $items = $data['items'] ?? [];
            if (!is_array($items) || empty($items)) throw new Exception('Nothing to reorder.');

            $clean = [];
            foreach ($items as $it) {
                $oid   = (int)($it['order_id'] ?? 0);
                $rdate = trim($it['required_date'] ?? '');
                if ($oid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $rdate) || !strtotime($rdate)) {
                    throw new Exception('Invalid reorder payload.');
                }
                $clean[] = ['order_id' => $oid, 'required_date' => $rdate];
            }

            $order_ids = array_column($clean, 'order_id');
            $ph = implode(',', array_fill(0, count($order_ids), '?'));
            $existing = $db->query(
                "SELECT id, order_number, required_date, delivery_priority, status FROM credit_orders WHERE id IN ($ph)",
                $order_ids
            )->results();
            $existing_by_id = [];
            foreach ($existing as $ex) $existing_by_id[(int)$ex->id] = $ex;

            // New priority = position within each contiguous run sharing the same
            // required_date, in submitted (post-drag) order — diffed against the DB
            // so only rows that actually moved get written.
            $to_update = [];
            $running   = 0;
            $prev_date = null;
            foreach ($clean as $it) {
                $running = ($it['required_date'] === $prev_date) ? $running + 1 : 1;
                $prev_date = $it['required_date'];

                $ex = $existing_by_id[$it['order_id']] ?? null;
                if (!$ex) continue;   // stale/unknown id — skip rather than fail the whole batch

                $date_changed = $ex->required_date !== $it['required_date'];
                $prio_changed = (int)$ex->delivery_priority !== $running;
                if ($date_changed || $prio_changed) {
                    $to_update[$it['order_id']] = ['required_date' => $it['required_date'], 'priority' => $running];
                }
            }

            if (empty($to_update)) {
                $result = ['success' => true, 'message' => 'No changes to save.'];
                break;
            }

            $pdo = $db->getPdo();
            $pdo->beginTransaction();
            try {
                foreach ($to_update as $oid => $vals) {
                    $db->query(
                        "UPDATE credit_orders SET required_date = ?, delivery_priority = ? WHERE id = ?",
                        [$vals['required_date'], $vals['priority'], $oid]
                    );
                    if ($db->error()) throw new Exception('Failed to save the new order for one or more rows.');
                }
                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            $rescheduled = [];
            foreach ($to_update as $oid => $vals) {
                $ex = $existing_by_id[$oid];
                if ($ex->required_date !== $vals['required_date']) {
                    $rescheduled[] = $ex->order_number . ' — ' . date('d M', strtotime($ex->required_date)) . ' → ' . date('d M', strtotime($vals['required_date']));
                    auditLogOrder('delivery_reschedule', $oid, $ex->order_number,
                        "Delivery date changed for {$ex->order_number}: " . date('d M Y', strtotime($ex->required_date)) . ' → ' . date('d M Y', strtotime($vals['required_date'])),
                        ['from_date' => $ex->required_date, 'to_date' => $vals['required_date']]
                    );
                }
            }

            if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
                try {
                    require_once '../core/classes/TelegramNotifier.php';
                    $admin_name = getCurrentUser()['display_name'] ?? getCurrentUser()['name'] ?? 'Admin';
                    $msg = "📋 <b>Delivery Schedule Reordered</b> — by " . htmlspecialchars($admin_name) . "\n";
                    if (!empty($rescheduled)) {
                        $msg .= implode("\n", array_map(fn($r) => '• ' . htmlspecialchars($r), array_slice($rescheduled, 0, 15)));
                        if (count($rescheduled) > 15) $msg .= "\n…and " . (count($rescheduled) - 15) . ' more';
                    } else {
                        $msg .= 'Priority order updated for ' . count($to_update) . ' order(s).';
                    }
                    (new TelegramNotifier(TELEGRAM_BOT_TOKEN, getTelegramChatId('production')))->sendMessage($msg);
                } catch (\Throwable $e) { error_log('Telegram delivery_schedule_reorder: ' . $e->getMessage()); }
            }

            $result = ['success' => true, 'message' => 'Schedule updated.', 'changed' => count($to_update)];
            break;
        }

        default:
            throw new Exception('Invalid sales action');
    }
    
    // Clean buffer (which now contains the HTML for the modal) and send response
    // ** We removed the ob_end_clean() from here in the previous step **
    echo json_encode($result);
    exit;

} catch (Exception $e) {
    if (ob_get_level()) ob_end_clean();
    error_log("Sales AJAX Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

?>