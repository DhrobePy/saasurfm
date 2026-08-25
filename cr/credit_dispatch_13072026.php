<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'Accounts', 'admin', 'dispatch-srg', 'dispatch-demra', 'dispatchpos-demra', 'dispatchpos-srg'];
// The privileges UI files this page under the PRODUCTION module, but older
// privilege rows granted it under credit_sales, and bare restrict_access()
// auto-detects credit_sales for every cr/ page. Accept a grant from either
// module; otherwise fall through to the normal gate (role fallback / deny).
if (!userHasPageGrant('production', 'credit_dispatch') && !userHasPageGrant('credit_sales', 'credit_dispatch')) {
    restrict_access($allowed_roles, 'production', 'credit_dispatch');
}

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$user_role = $currentUser['role'] ?? '';
$pageTitle = 'Dispatch Management';
$error = null;
$success = null;

// Feature #5: provision default dispatch holds so every order on the board
// reflects the global hold and appears in Payment Watch for clearance. Idempotent.
provisionDefaultDispatchHolds();

// ── Ensure adhoc log table exists ─────────────────────────────────────────────
try {
    $db->getPdo()->exec("
        CREATE TABLE IF NOT EXISTS `dispatch_adhoc_fleet` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `entry_type` ENUM('driver','vehicle') NOT NULL,
            `value`      VARCHAR(200) NOT NULL,
            `extra`      VARCHAR(200) NULL,
            `order_id`   INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (\Throwable $e) { /* already exists */ }

// ── AJAX: autocomplete endpoints ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fleet_action'])) {
    header('Content-Type: application/json');
    $q = '%' . trim($_GET['q'] ?? '') . '%';
    if ($_GET['fleet_action'] === 'drivers') {
        $rows = $db->query(
            "SELECT id, driver_name, phone_number, driver_type
             FROM drivers
             WHERE status = 'Active' AND (driver_name LIKE ? OR phone_number LIKE ?)
             ORDER BY driver_name ASC LIMIT 10",
            [$q, $q]
        )->results();
        echo json_encode(array_map(fn($r) => [
            'id'      => $r->id,
            'name'    => $r->driver_name,
            'phone'   => $r->phone_number ?? '',
            'type'    => $r->driver_type  ?? '',
        ], $rows));
    } elseif ($_GET['fleet_action'] === 'vehicles') {
        $rows = $db->query(
            "SELECT id, registration_number, vehicle_type, ownership
             FROM fleet_vehicles
             WHERE status = 'active' AND registration_number LIKE ?
             ORDER BY registration_number ASC LIMIT 10",
            [$q]
        )->results();
        $labels = ['mini_truck' => 'Mini Truck', 'boro_truck' => 'Boro Truck', 'car' => 'Car'];
        echo json_encode(array_map(fn($r) => [
            'id'    => $r->id,
            'reg'   => $r->registration_number,
            'type'  => $labels[$r->vehicle_type] ?? $r->vehicle_type,
            'own'   => ucfirst($r->ownership),
        ], $rows));
    } else {
        echo '[]';
    }
    exit;
}

$is_admin = in_array($user_role, ['Superadmin', 'admin']);

// Get user's branch
$user_branch = null;
if (!$is_admin && $user_id) {
    $emp = $db->query("SELECT branch_id FROM employees WHERE user_id = ?", [$user_id])->first();
    if ($emp && $emp->branch_id) {
        $user_branch = $emp->branch_id;
    } 
}

// Build branch filter
$branch_filter = "";
$branch_params = [];
if (!$is_admin && $user_branch) {
    $branch_filter = "AND co.assigned_branch_id = ?";
    $branch_params[] = $user_branch;
}

// Fetch necessary accounts
$ar_account_q = $db->query("SELECT id FROM chart_of_accounts WHERE account_type = 'Accounts Receivable' LIMIT 1");
$ar_account = $ar_account_q->first();
if (!$ar_account) {
    $error = "FATAL ERROR: 'Accounts Receivable' account not found in Chart of Accounts. Cannot proceed.";
}
$ar_account_id = $ar_account->id ?? null;

// Default credit-sales revenue account: prefer one explicitly named for credit sales,
// avoid POS accounts. branch_id IS NULL = company-wide (not branch-specific).
$default_sales_account_q = $db->query(
    "SELECT id FROM chart_of_accounts
     WHERE account_type = 'Revenue'
       AND branch_id IS NULL
       AND (LOWER(name) LIKE '%credit%' OR LOWER(description) LIKE '%credit%')
     ORDER BY id ASC LIMIT 1"
);
$default_sales_account = $default_sales_account_q->first();
// Hard fallback: any non-POS revenue account with no branch
if (!$default_sales_account) {
    $default_sales_account = $db->query(
        "SELECT id FROM chart_of_accounts
         WHERE account_type = 'Revenue' AND branch_id IS NULL
           AND LOWER(name) NOT LIKE '%pos%'
         ORDER BY id ASC LIMIT 1"
    )->first();
}
if (!$default_sales_account) {
     $error = "FATAL ERROR: No Credit Sales Revenue account found in Chart of Accounts. Cannot proceed.";
}
$default_sales_account_id = $default_sales_account->id ?? null;

/* -----------------------------
   DATE RANGE & STATUS FILTERING
----------------------------- */
// Get filter inputs - Default to TODAY
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$status_filter = isset($_GET['status_filter']) ? $_GET['status_filter'] : 'ready_to_ship'; // Default: show only not-yet-dispatched

// Build status condition
$status_condition = "";
if ($status_filter === 'ready_to_ship') {
    $status_condition = "AND co.status = 'ready_to_ship'";
} elseif ($status_filter === 'shipped') {
    $status_condition = "AND co.status = 'shipped'";
} elseif ($status_filter === 'delivered') {
    $status_condition = "AND co.status = 'delivered'";
} elseif ($status_filter === 'all') {
    $status_condition = "AND co.status IN ('ready_to_ship', 'shipped', 'delivered')";
}

// Get orders with date range filter
$orders = $db->query(
    "SELECT co.*, 
            c.name as customer_name,
            c.phone_number as customer_phone,
            b.name as branch_name,
            cos.truck_number,
            cos.driver_name,
            cos.driver_contact,
            cos.shipped_date,
            cos.delivered_date,
            cos.delivery_notes,
            cos.trip_id
     FROM credit_orders co
     JOIN customers c ON co.customer_id = c.id
     LEFT JOIN branches b ON co.assigned_branch_id = b.id
     LEFT JOIN credit_order_shipping cos ON co.id = cos.order_id
     WHERE co.assigned_branch_id IS NOT NULL
     AND co.updated_at >= ? AND co.updated_at < DATE_ADD(?, INTERVAL 1 DAY)
     $status_condition
     $branch_filter
     ORDER BY 
         CASE co.status 
             WHEN 'ready_to_ship' THEN 1
             WHEN 'shipped' THEN 2
             WHEN 'delivered' THEN 3
         END,
         co.required_date ASC",
    array_merge([$from_date . ' 00:00:00', $to_date], $branch_params)
)->results();

// Handle shipping action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'ship' && !$error) {
    $order_id         = (int)($_POST['order_id'] ?? 0);
    $truck_number     = trim($_POST['truck_number']     ?? '');
    $driver_name      = trim($_POST['driver_name']      ?? '');
    $driver_contact   = trim($_POST['driver_contact']   ?? '');
    $fleet_driver_id  = (int)($_POST['fleet_driver_id']  ?? 0);
    $fleet_vehicle_id = (int)($_POST['fleet_vehicle_id'] ?? 0);

    if ($truck_number === '' || $driver_name === '') {
        $error = "Driver name and truck number are required.";
    } elseif (!orderDispatchAllowed($order_id)) {
        // Server-side gate: admin placed a dispatch hold pending payment clearance.
        // Checked here (not only at render) so a direct POST cannot bypass it.
        $gate  = getOrderGateState($order_id);
        $error = "Dispatch is HELD for this order — awaiting payment clearance from Accounts.";
        if ($gate['shortfall'] !== null && $gate['shortfall'] > 0) {
            $error .= " Shortfall: ৳" . number_format($gate['shortfall'], 0) . ".";
        }
    } else {
        try {
            $pdo = $db->getPdo();
            $pdo->beginTransaction();

            $order = $db->query("SELECT * FROM credit_orders WHERE id = ?", [$order_id])->first();
            if (!$order) throw new Exception("Order not found");

            // 1. Shipping record
            $shipping_exists = $db->query("SELECT id FROM credit_order_shipping WHERE order_id = ?", [$order_id])->first();
            if ($shipping_exists) {
                $db->query(
                    "UPDATE credit_order_shipping
                     SET truck_number=?, driver_name=?, driver_contact=?, shipped_date=NOW(), shipped_by_user_id=?
                     WHERE order_id=?",
                    [$truck_number, $driver_name, $driver_contact ?: null, $user_id, $order_id]
                );
            } else {
                $db->insert('credit_order_shipping', [
                    'order_id'           => $order_id,
                    'truck_number'       => $truck_number,
                    'driver_name'        => $driver_name,
                    'driver_contact'     => $driver_contact ?: null,
                    'shipped_date'       => date('Y-m-d H:i:s'),
                    'shipped_by_user_id' => $user_id,
                ]);
            }

            // 2. Update order status
            $db->query("UPDATE credit_orders SET status = 'shipped' WHERE id = ?", [$order_id]);
            $verify_order = $db->query("SELECT status FROM credit_orders WHERE id = ?", [$order_id])->first();
            if (!$verify_order || $verify_order->status !== 'shipped') {
                throw new Exception("Failed to update order status to shipped");
            }

            // 3. Increment trip count for matched fleet driver
            if ($fleet_driver_id > 0) {
                $db->query(
                    "UPDATE drivers SET total_trips = COALESCE(total_trips, 0) + 1 WHERE id = ?",
                    [$fleet_driver_id]
                );
            }

            // 4. Log unmatched driver/vehicle to adhoc table
            if ($fleet_driver_id === 0) {
                try { $db->insert('dispatch_adhoc_fleet', ['entry_type' => 'driver',  'value' => $driver_name,  'extra' => $driver_contact ?: null, 'order_id' => $order_id]); } catch (\Throwable $e) {}
            }
            if ($fleet_vehicle_id === 0) {
                try { $db->insert('dispatch_adhoc_fleet', ['entry_type' => 'vehicle', 'value' => $truck_number, 'extra' => null,                   'order_id' => $order_id]); } catch (\Throwable $e) {}
            }

            $consolidation_note = "";
            $trip_id = null;
            
            // 12. Customer Ledger & Journal — Bug 7/16 fix
            // current_balance was already incremented atomically at order creation
            // (ajax_handler.php) to reserve credit.  Writing it again here (with an
            // absolute computed value) caused a double-debit AND a race condition.
            //
            // We now ONLY post the formal double-entry journal (Debit AR / Credit Revenue).
            // The customer_ledger subsidiary-ledger entry is also created here once — at
            // the point goods actually change hands — NOT at order creation.
            $customer_data = $db->query("SELECT initial_due, current_balance, name FROM customers WHERE id = ?", [$order->customer_id])->first();
            $customer_name = $customer_data ? $customer_data->name : 'Unknown Customer';

            $invoice_amount = (float)$order->total_amount;

            // Find the correct Credit Sales Revenue account (Accounts module, NOT bank module).
            // Priority: branch-specific credit-sales account → global credit-sales account.
            // Explicitly excludes POS accounts so branch-2 doesn't use "POS Sales Revenue - Demra".
            $sales_account_id = $default_sales_account_id;
            if ($order->assigned_branch_id) {
                $branch_acct = $db->query(
                    "SELECT id FROM chart_of_accounts
                     WHERE account_type = 'Revenue'
                       AND branch_id = ?
                       AND (LOWER(name) LIKE '%credit%' OR LOWER(description) LIKE '%credit%')
                       AND LOWER(name) NOT LIKE '%pos%'
                     ORDER BY id ASC LIMIT 1",
                    [$order->assigned_branch_id]
                )->first();
                if ($branch_acct) $sales_account_id = $branch_acct->id;
            }

            // Create Journal Entry Header
            $journal_desc = "Credit Sale Invoice #" . $order->order_number . " to " . $customer_name;
            $journal_id = $db->insert('journal_entries', [
                'transaction_date' => date('Y-m-d'),
                'description' => $journal_desc,
                'related_document_type' => 'credit_orders',
                'related_document_id' => $order_id,
                'created_by_user_id' => $user_id
            ]);
            
            if (!$journal_id) {
                throw new Exception("Failed to create journal entry header.");
            }

            // DEBIT "Accounts Receivable"
            $db->insert('transaction_lines', [
                'journal_entry_id' => $journal_id,
                'account_id' => $ar_account_id,
                'debit_amount' => $invoice_amount,
                'credit_amount' => 0.00,
                'description' => $journal_desc
            ]);

            // CREDIT "Sales Revenue"
            $db->insert('transaction_lines', [
                'journal_entry_id' => $journal_id,
                'account_id' => $sales_account_id,
                'debit_amount' => 0.00,
                'credit_amount' => $invoice_amount,
                'description' => $journal_desc
            ]);

            // 12b. Customer Ledger — subsidiary ledger entry at dispatch (goods change hands)
            // current_balance was already incremented at order creation for credit reservation.
            // We create the formal subsidiary ledger entry HERE (at dispatch) so the
            // customer_ledger reflects when the liability actually arose.
            // Build prev_balance from aggregate — immune to stored balance_after drift.
            // The OB ledger entry (reference_type='initial_due') already encodes initial_due
            // as a debit, so SUM(debit) - SUM(credit) over all prior entries is correct.
            // If NO entries exist yet, fall back to initial_due as the implicit baseline.
            $agg = $db->query(
                "SELECT COALESCE(SUM(debit_amount), 0)  AS td,
                        COALESCE(SUM(credit_amount), 0) AS tc
                 FROM customer_ledger WHERE customer_id = ?",
                [$order->customer_id]
            )->first();
            $agg_td = (float)($agg->td ?? 0);
            $agg_tc = (float)($agg->tc ?? 0);
            $prev_balance  = ($agg_td > 0 || $agg_tc > 0)
                ? $agg_td - $agg_tc
                : (float)($customer_data->initial_due ?? 0);
            $balance_after = $prev_balance + $invoice_amount;

            // Only insert if no entry exists yet (idempotent)
            $ledger_exists = $db->query(
                "SELECT id FROM customer_ledger
                 WHERE customer_id = ? AND reference_id = ?
                   AND reference_type IN ('credit_order','credit_orders')
                   AND transaction_type = 'invoice' LIMIT 1",
                [$order->customer_id, $order_id]
            )->first();

            if (!$ledger_exists) {
                $db->insert('customer_ledger', [
                    'customer_id'        => $order->customer_id,
                    'transaction_date'   => date('Y-m-d'),
                    'transaction_type'   => 'invoice',
                    'reference_type'     => 'credit_orders',
                    'reference_id'       => $order_id,
                    'invoice_number'     => $order->order_number,
                    'description'        => 'Credit sale — ' . $order->order_number,
                    'debit_amount'       => $invoice_amount,
                    'credit_amount'      => 0.00,
                    'balance_after'      => $balance_after,
                    'created_by_user_id' => $user_id,
                    'journal_entry_id'   => $journal_id,
                ]);
            }

            // 12c. Invoice Snapshot — frozen point-in-time record of this invoice
            $snap_exists = $db->query(
                "SELECT id FROM invoice_snapshots WHERE order_id = ? LIMIT 1",
                [$order_id]
            )->first();

            if (!$snap_exists) {
                $snap_items = $db->query(
                    "SELECT coi.product_id, coi.variant_id,
                            coi.quantity, coi.unit_price, coi.discount_amount, coi.tax_amount,
                            coi.line_total, p.base_name AS product_name,
                            pv.grade, pv.weight_variant, pv.unit_of_measure, pv.sku AS variant_sku
                     FROM credit_order_items coi
                     JOIN products p ON coi.product_id = p.id
                     LEFT JOIN product_variants pv ON coi.variant_id = pv.id
                     WHERE coi.order_id = ? ORDER BY coi.id ASC",
                    [$order_id]
                )->results();

                $items_arr = [];
                foreach ($snap_items as $si) {
                    $vd = [];
                    if ($si->grade)          $vd[] = 'Grade ' . $si->grade;
                    if ($si->weight_variant) $vd[] = $si->weight_variant;
                    $items_arr[] = [
                        'product_id'      => (int)$si->product_id,
                        'variant_id'      => $si->variant_id ? (int)$si->variant_id : null,
                        'product_name'    => $si->product_name,
                        'variant_detail'  => implode(' · ', $vd),
                        'sku'             => $si->variant_sku,
                        'unit'            => $si->unit_of_measure,
                        'quantity'        => (float)$si->quantity,
                        'unit_price'      => (float)$si->unit_price,
                        'discount_amount' => (float)$si->discount_amount,
                        'tax_amount'      => (float)$si->tax_amount,
                        'line_total'      => (float)$si->line_total,
                    ];
                }

                // Previous due = balance before this order's ledger entry
                $previous_due     = $prev_balance;
                $total_outstanding = $previous_due + max(0, (float)$order->balance_due);

                $branch_name_q = $db->query("SELECT name, address, phone_number FROM branches WHERE id = ? LIMIT 1", [$order->assigned_branch_id])->first();
                $customer_full = $db->query("SELECT phone_number, email, business_address FROM customers WHERE id = ?", [$order->customer_id])->first();

                $db->insert('invoice_snapshots', [
                    'order_id'             => $order_id,
                    'order_number'         => $order->order_number,
                    'snapshot_trigger'     => 'dispatch',
                    'snapshot_at'          => date('Y-m-d H:i:s'),
                    'customer_id'          => $order->customer_id,
                    'customer_name'        => $customer_name,
                    'customer_phone'       => $customer_full ? $customer_full->phone_number : null,
                    'customer_email'       => $customer_full ? $customer_full->email : null,
                    'customer_address'     => $customer_full ? $customer_full->business_address : null,
                    'previous_due'         => $previous_due,
                    'subtotal'             => $order->subtotal,
                    'discount_amount'      => $order->discount_amount,
                    'tax_amount'           => $order->tax_amount,
                    'total_amount'         => $order->total_amount,
                    'advance_paid'         => $order->advance_paid,
                    'balance_due'          => $order->balance_due,
                    'total_outstanding'    => $total_outstanding,
                    'company_name_bn'      => 'উজ্জল ফ্লাওয়ার মিলস',
                    'company_name_en'      => 'Ujjal Flour Mills',
                    'company_address'      => ($branch_name_q && !empty($branch_name_q->address)) ? $branch_name_q->address : '১৭, নুরাইবাগ, ডেমরা, ঢাকা',
                    'company_phone'        => ($branch_name_q && !empty($branch_name_q->phone_number)) ? $branch_name_q->phone_number : '+880-XXX-XXXXXX',
                    'company_email'        => 'info@ujjalfm.com',
                    'items_json'           => json_encode($items_arr, JSON_UNESCAPED_UNICODE),
                    'shipping_address'     => $order->shipping_address ?? null,
                    'branch_name'          => $branch_name_q ? $branch_name_q->name : null,
                    'truck_number'         => $truck_number,
                    'driver_name'          => $driver_name,
                    'driver_contact'       => $driver_contact ?? null,
                    'shipped_date'         => date('Y-m-d H:i:s'),
                    'order_date'           => $order->order_date,
                    'required_date'        => $order->required_date ?? null,
                    'invoice_date'         => date('Y-m-d'),
                    'order_type'           => $order->order_type,
                    'order_status'         => 'shipped',
                    'special_instructions' => $order->special_instructions ?? null,
                    'created_by_user_id'   => $user_id,
                ]);
            }

            // 5. Log workflow
            $db->insert('credit_order_workflow', [
                'order_id'             => $order_id,
                'from_status'          => 'ready_to_ship',
                'to_status'            => 'shipped',
                'action'               => 'ship',
                'performed_by_user_id' => $user_id,
                'comments'             => "Shipped with truck $truck_number, driver: $driver_name",
            ]);
            
            $pdo->commit();
            
            // Double-check the status after commit
            $final_check = $db->query("SELECT status FROM credit_orders WHERE id = ?", [$order_id])->first();
            error_log("Order $order_id final status after commit: " . ($final_check ? $final_check->status : 'NOT FOUND'));
            
            // ============================================
            // TELEGRAM NOTIFICATION - ORDER SHIPPED
            // ============================================
            if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
                try {
                    require_once '../core/classes/TelegramNotifier.php';
                    $telegram = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);
                    
                    // Get customer and branch details
                    $customer_info = $db->query("SELECT name, phone_number FROM customers WHERE id = ?", [$order->customer_id])->first();
                    $branch_info = $db->query("SELECT name FROM branches WHERE id = ?", [$order->assigned_branch_id])->first();
                    
                    // Get user name
                    $user_info = $db->query("SELECT display_name FROM users WHERE id = ?", [$user_id])->first();
                    
                    // Get trip type
                    $trip_info = $db->query("SELECT trip_type FROM trip_assignments WHERE id = ?", [$trip_id])->first();
                    
                    // Get order items
                    $items = $db->query(
                        "SELECT coi.*, p.base_name as product_name, pv.grade, pv.weight_variant, pv.unit_of_measure
                         FROM credit_order_items coi
                         JOIN products p ON coi.product_id = p.id
                         LEFT JOIN product_variants pv ON coi.variant_id = pv.id
                         WHERE coi.order_id = ?",
                        [$order_id]
                    )->results();
                    
                    $notification_items = [];
                    foreach ($items as $item) {
                        $variant_name = trim(($item->grade ?? '') . ' ' . ($item->weight_variant ?? ''));
                        $notification_items[] = [
                            'product_name' => $item->product_name,
                            'variant_name' => $variant_name,
                            'quantity' => floatval($item->quantity),
                            'unit' => $item->unit_of_measure ?? 'pcs'
                        ];
                    }
                    
                    $shipmentData = [
                        'order_number'    => $order->order_number,
                        'shipped_at'      => date('d M Y, h:i A'),
                        'customer_name'   => $customer_info ? $customer_info->name : 'Unknown',
                        'customer_phone'  => $customer_info ? $customer_info->phone_number : 'N/A',
                        'shipping_address'=> $order->shipping_address ?? '',
                        'truck_number'    => $truck_number,
                        'driver_name'     => $driver_name,
                        'driver_contact'  => $driver_contact,
                        'trip_id'         => null,
                        'trip_type'       => 'single',
                        'branch_name'     => $branch_info ? $branch_info->name : 'Unknown Branch',
                        'items'           => $notification_items,
                        'total_amount'    => floatval($order->total_amount),
                        'balance_due'     => floatval($order->balance_due),
                        'dispatched_by'   => $user_info ? $user_info->display_name : 'Unknown User',
                    ];
                    
                    $telegram->sendOrderShippedNotification($shipmentData);
                    
                } catch (Exception $e) {
                    error_log("Telegram order shipped notification failed: " . $e->getMessage());
                }
            }
            // END TELEGRAM NOTIFICATION
            
            $_SESSION['success_flash'] = "Order dispatched successfully! Truck: $truck_number, Driver: $driver_name. Ledger and journal entries posted.";
            header('Location: credit_dispatch.php');
            exit();
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Failed to ship order: " . $e->getMessage();
            error_log("Dispatch error for order $order_id: " . $e->getMessage());
        }
    }
}

// Handle delivery confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'delivered') {
    $order_id = (int)$_POST['order_id'];
    $delivery_notes = trim($_POST['delivery_notes'] ?? '');
    
    try {
        $db->getPdo()->beginTransaction();
        
        // Get trip_id from shipping
        $shipping = $db->query("SELECT trip_id FROM credit_order_shipping WHERE order_id = ?", [$order_id])->first();
        $trip_id = $shipping->trip_id ?? null;
        
        // Update shipping record
        $db->query("
            UPDATE credit_order_shipping 
            SET delivered_date = NOW(),
                delivered_by_user_id = ?,
                delivery_notes = ?
            WHERE order_id = ?
        ", [$user_id, $delivery_notes, $order_id]);
        
        // Update trip_order_assignments
        if ($trip_id) {
            $db->query("
                UPDATE trip_order_assignments 
                SET delivery_status = 'delivered',
                    actual_arrival = NOW(),
                    delivery_notes = ?
                WHERE trip_id = ? AND order_id = ?
            ", [$delivery_notes, $trip_id, $order_id]);
            
            // Check if all orders in trip are delivered
            $pending_orders = $db->query("
                SELECT COUNT(*) as cnt 
                FROM trip_order_assignments 
                WHERE trip_id = ? AND delivery_status != 'delivered'
            ", [$trip_id])->first();
            
            // If all delivered, mark trip as completed
            if ($pending_orders->cnt == 0) {
                $db->query("
                    UPDATE trip_assignments 
                    SET status = 'Completed',
                        actual_end_time = NOW()
                    WHERE id = ?
                ", [$trip_id]);
            }
        }
        
        // Update order status
        $db->query("UPDATE credit_orders SET status = 'delivered' WHERE id = ?", [$order_id]);
        
        // Log workflow
        $db->insert('credit_order_workflow', [
            'order_id' => $order_id,
            'from_status' => 'shipped',
            'to_status' => 'delivered',
            'action' => 'deliver',
            'performed_by_user_id' => $user_id,
            'comments' => 'Order delivered to customer' . ($delivery_notes ? ': ' . $delivery_notes : '')
        ]);
        
        $db->getPdo()->commit();
        
        // ============================================
        // TELEGRAM NOTIFICATION - ORDER DELIVERED
        // ============================================
        if (defined('TELEGRAM_NOTIFICATIONS_ENABLED') && TELEGRAM_NOTIFICATIONS_ENABLED) {
            try {
                require_once '../core/classes/TelegramNotifier.php';
                $telegram = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);
                
                // Get order details
                $order = $db->query("SELECT * FROM credit_orders WHERE id = ?", [$order_id])->first();
                
                // Get customer and branch details
                $customer_info = $db->query("SELECT name, phone_number FROM customers WHERE id = ?", [$order->customer_id])->first();
                $branch_info = $db->query("SELECT name FROM branches WHERE id = ?", [$order->assigned_branch_id])->first();
                
                // Get shipping details
                $shipping_info = $db->query("SELECT truck_number, driver_name, trip_id FROM credit_order_shipping WHERE order_id = ?", [$order_id])->first();
                
                // Get user name
                $user_info = $db->query("SELECT display_name FROM users WHERE id = ?", [$user_id])->first();
                
                // Get order items
                $items = $db->query(
                    "SELECT coi.*, p.base_name as product_name, pv.grade, pv.weight_variant, pv.unit_of_measure
                     FROM credit_order_items coi
                     JOIN products p ON coi.product_id = p.id
                     LEFT JOIN product_variants pv ON coi.variant_id = pv.id
                     WHERE coi.order_id = ?",
                    [$order_id]
                )->results();
                
                $notification_items = [];
                foreach ($items as $item) {
                    $variant_name = trim(($item->grade ?? '') . ' ' . ($item->weight_variant ?? ''));
                    $notification_items[] = [
                        'product_name' => $item->product_name,
                        'variant_name' => $variant_name,
                        'quantity' => floatval($item->quantity),
                        'unit' => $item->unit_of_measure ?? 'pcs'
                    ];
                }
                
                $deliveryData = [
                    'order_number' => $order->order_number,
                    'delivered_at' => date('d M Y, h:i A'),
                    'customer_name' => $customer_info ? $customer_info->name : 'Unknown',
                    'customer_phone' => $customer_info ? $customer_info->phone_number : 'N/A',
                    'shipping_address' => $order->shipping_address ?? '',
                    'truck_number' => $shipping_info ? $shipping_info->truck_number : 'N/A',
                    'driver_name' => $shipping_info ? $shipping_info->driver_name : 'N/A',
                    'trip_id' => $shipping_info ? $shipping_info->trip_id : 'N/A',
                    'branch_name' => $branch_info ? $branch_info->name : 'Unknown Branch',
                    'items' => $notification_items,
                    'total_amount' => floatval($order->total_amount),
                    'balance_due' => floatval($order->balance_due),
                    'delivery_notes' => $delivery_notes,
                    'confirmed_by' => $user_info ? $user_info->display_name : 'Unknown User'
                ];
                
                $telegram->sendOrderDeliveredNotification($deliveryData);
                
            } catch (Exception $e) {
                error_log("Telegram order delivered notification failed: " . $e->getMessage());
            }
        }
        // END TELEGRAM NOTIFICATION
        
        $_SESSION['success_flash'] = "Order marked as delivered";
        header('Location: credit_dispatch.php');
        exit();
        
    } catch (Exception $e) {
        if ($db->getPdo()->inTransaction()) {
            $db->getPdo()->rollBack();
        }
        $error = "Failed to confirm delivery: " . $e->getMessage();
        error_log("Delivery error for order $order_id: " . $e->getMessage());
    }
}

// Pre-fetch all items for displayed orders in one query
$all_items_by_order = [];
if (!empty($orders)) {
    $order_ids    = array_map(fn($o) => (int)$o->id, $orders);
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $all_items_flat = $db->query(
        "SELECT coi.order_id, coi.quantity, coi.unit_price, coi.line_total,
                p.base_name AS product_name,
                pv.grade, pv.weight_variant, pv.unit_of_measure, pv.sku AS variant_sku
         FROM credit_order_items coi
         JOIN products p ON coi.product_id = p.id
         LEFT JOIN product_variants pv ON coi.variant_id = pv.id
         WHERE coi.order_id IN ($placeholders)
         ORDER BY coi.order_id, coi.id",
        $order_ids
    )->results();
    foreach ($all_items_flat as $item) {
        $all_items_by_order[$item->order_id][] = $item;
    }
}

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-4">

<!-- Page Header + Stats -->
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
        <p class="text-xs text-gray-500 mt-0.5">Ship orders and track deliveries</p>
    </div>
    <div class="flex gap-2 flex-wrap">
        <?php
        try {
            $ready_result     = $db->query("SELECT COUNT(*) as c FROM credit_orders co WHERE co.status = 'ready_to_ship' $branch_filter", $branch_params)->first();
            $shipped_result   = $db->query("SELECT COUNT(*) as c FROM credit_orders co WHERE co.status = 'shipped' $branch_filter", $branch_params)->first();
            $delivered_result = $db->query("SELECT COUNT(*) as c FROM credit_orders co WHERE co.status = 'delivered' $branch_filter", $branch_params)->first();
            $stats = [
                'ready_to_ship' => ($ready_result   && isset($ready_result->c))   ? (int)$ready_result->c   : 0,
                'shipped'       => ($shipped_result  && isset($shipped_result->c))  ? (int)$shipped_result->c  : 0,
                'delivered'     => ($delivered_result && isset($delivered_result->c)) ? (int)$delivered_result->c : 0,
            ];
        } catch (Exception $e) {
            $stats = ['ready_to_ship' => 0, 'shipped' => 0, 'delivered' => 0];
        }
        ?>
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-orange-100 text-orange-800 text-xs font-semibold">
            <span class="w-1.5 h-1.5 rounded-full bg-orange-500"></span>
            <?php echo $stats['ready_to_ship']; ?> Ready
        </span>
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-blue-100 text-blue-800 text-xs font-semibold">
            <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>
            <?php echo $stats['shipped']; ?> Shipped
        </span>
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-green-100 text-green-800 text-xs font-semibold">
            <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
            <?php echo $stats['delivered']; ?> Delivered
        </span>
    </div>
</div>

<?php if ($error): ?>
<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 mb-4 rounded-lg text-sm">
    <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['success_flash'])): ?>
<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 mb-4 rounded-lg text-sm">
    <i class="fas fa-check-circle mr-1"></i> <?php echo htmlspecialchars($_SESSION['success_flash']); ?>
</div>
<?php unset($_SESSION['success_flash']); ?>
<?php endif; ?>

<!-- Compact Filter Bar -->
<form method="GET" action="credit_dispatch.php"
      class="flex flex-wrap items-end gap-2 mb-4 bg-white rounded-lg border border-gray-200 shadow-sm px-4 py-3">
    <div class="flex flex-col gap-0.5">
        <label class="text-xs text-gray-500 font-medium">From</label>
        <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>"
               class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
    </div>
    <div class="flex flex-col gap-0.5">
        <label class="text-xs text-gray-500 font-medium">To</label>
        <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>"
               class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
    </div>
    <div class="flex flex-col gap-0.5">
        <label class="text-xs text-gray-500 font-medium">Status</label>
        <select name="status_filter" class="text-xs px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500">
            <option value="ready_to_ship" <?php echo $status_filter === 'ready_to_ship' ? 'selected' : ''; ?>>Ready to Ship</option>
            <option value="shipped"       <?php echo $status_filter === 'shipped'       ? 'selected' : ''; ?>>Shipped</option>
            <option value="delivered"     <?php echo $status_filter === 'delivered'     ? 'selected' : ''; ?>>Delivered</option>
            <option value="all"           <?php echo $status_filter === 'all'           ? 'selected' : ''; ?>>All</option>
        </select>
    </div>
    <button type="submit"
            class="px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded hover:bg-blue-700 transition-colors cursor-pointer">
        <i class="fas fa-filter mr-1"></i>Filter
    </button>
    <a href="credit_dispatch.php"
       class="px-3 py-1.5 bg-gray-100 text-gray-600 text-xs rounded hover:bg-gray-200 transition-colors" title="Reset">
        <i class="fas fa-undo"></i>
    </a>
    <span class="ml-auto self-end text-xs text-gray-500 pb-1">
        <strong><?php echo count($orders); ?></strong> orders &middot;
        <?php echo date('M j', strtotime($from_date)); ?> – <?php echo date('M j, Y', strtotime($to_date)); ?>
    </span>
</form>

<?php if (!empty($orders)): ?>
<!-- Compact Table -->
<div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs">
        <thead>
            <tr class="bg-gray-50 border-b border-gray-200 text-left">
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Order</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Customer</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Branch</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Items</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap text-right">Amount</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Req. Date</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Status</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Transport</th>
                <th class="px-3 py-2 font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap text-center">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
        <?php foreach ($orders as $order):
            $items       = $all_items_by_order[$order->id] ?? [];
            $items_count = count($items);
            $first_item  = !empty($items) ? $items[0]->product_name : '';
            $is_overdue  = $order->required_date &&
                           strtotime($order->required_date) < strtotime('today') &&
                           $order->status === 'ready_to_ship';

            $status_labels = ['ready_to_ship' => 'Ready', 'shipped' => 'Shipped', 'delivered' => 'Delivered'];
            $status_cls    = [
                'ready_to_ship' => 'bg-orange-100 text-orange-800',
                'shipped'       => 'bg-blue-100 text-blue-800',
                'delivered'     => 'bg-green-100 text-green-800',
            ];
        ?>
        <tr class="hover:bg-blue-50/30 transition-colors" id="row_<?php echo $order->id; ?>">
            <!-- Order # -->
            <td class="px-3 py-2.5 whitespace-nowrap">
                <a href="credit_order_view.php?id=<?php echo $order->id; ?>"
                   class="font-mono font-bold text-blue-700 hover:underline">
                    <?php echo htmlspecialchars($order->order_number); ?>
                </a>
                <div class="text-gray-400 mt-0.5"><?php echo $order->order_date ? date('d M', strtotime($order->order_date)) : '—'; ?></div>
            </td>
            <!-- Customer -->
            <td class="px-3 py-2.5 max-w-[160px]">
                <div class="font-semibold text-gray-900 truncate"><?php echo htmlspecialchars($order->customer_name); ?></div>
                <div class="text-gray-400"><?php echo htmlspecialchars($order->customer_phone ?? ''); ?></div>
            </td>
            <!-- Branch -->
            <td class="px-3 py-2.5 whitespace-nowrap text-gray-600">
                <?php echo htmlspecialchars($order->branch_name ?? '—'); ?>
            </td>
            <!-- Items -->
            <td class="px-3 py-2.5">
                <button type="button"
                        onclick="openItemsModal(<?php echo $order->id; ?>)"
                        class="font-semibold text-blue-600 hover:text-blue-800 underline decoration-dashed cursor-pointer">
                    <?php echo $items_count; ?> item<?php echo $items_count !== 1 ? 's' : ''; ?>
                </button>
                <?php if ($first_item): ?>
                <div class="text-gray-400 truncate max-w-[120px] mt-0.5"><?php echo htmlspecialchars($first_item); ?></div>
                <?php endif; ?>
            </td>
            <!-- Amount -->
            <td class="px-3 py-2.5 text-right whitespace-nowrap">
                <div class="font-bold text-gray-900">৳<?php echo number_format($order->total_amount, 0); ?></div>
                <?php if ((float)($order->advance_paid ?? 0) > 0): ?>
                <div class="text-red-500">Due ৳<?php echo number_format($order->balance_due, 0); ?></div>
                <?php endif; ?>
            </td>
            <!-- Req. Date -->
            <td class="px-3 py-2.5 whitespace-nowrap <?php echo $is_overdue ? 'font-semibold text-red-600' : 'text-gray-600'; ?>">
                <?php if ($order->required_date): ?>
                    <?php echo date('d M', strtotime($order->required_date)); ?>
                    <?php if ($is_overdue): ?>
                        <i class="fas fa-exclamation-triangle text-red-400 ml-1" title="Overdue"></i>
                    <?php endif; ?>
                <?php else: ?>—<?php endif; ?>
            </td>
            <!-- Status -->
            <td class="px-3 py-2.5 whitespace-nowrap">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full font-semibold text-xs <?php echo $status_cls[$order->status] ?? 'bg-gray-100 text-gray-700'; ?>">
                    <?php echo $status_labels[$order->status] ?? ucfirst($order->status); ?>
                </span>
            </td>
            <!-- Transport -->
            <td class="px-3 py-2.5 max-w-[130px]">
                <?php if (!empty($order->truck_number)): ?>
                <div class="font-mono font-semibold text-gray-900 truncate"><?php echo htmlspecialchars($order->truck_number); ?></div>
                <div class="text-gray-400 truncate"><?php echo htmlspecialchars($order->driver_name ?? ''); ?></div>
                <?php else: ?>
                <span class="text-gray-300">—</span>
                <?php endif; ?>
            </td>
            <!-- Actions -->
            <td class="px-3 py-2.5 text-center whitespace-nowrap">
                <div class="flex items-center justify-center gap-1">
                <?php if ($order->status === 'ready_to_ship'): ?>
                    <?php $gate = getOrderGateState((int)$order->id); ?>
                    <?php if (!in_array($gate['dispatch'], ['open', 'cleared'])): ?>
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-red-100 text-red-700 rounded font-semibold"
                          title="<?php echo htmlspecialchars($gate['row']->accounts_note ?? 'Awaiting payment clearance from Accounts'); ?>">
                        <i class="fas fa-lock text-xs"></i> HELD
                        <?php if ($gate['shortfall'] !== null && $gate['shortfall'] > 0): ?>
                        <span class="font-normal">· ৳<?php echo number_format($gate['shortfall'], 0); ?> to go</span>
                        <?php elseif ($gate['dispatch'] === 'condition_met'): ?>
                        <span class="font-normal">· condition met, awaiting clearance</span>
                        <?php endif; ?>
                    </span>
                    <?php else: ?>
                    <button type="button"
                            data-action="ship"
                            data-id="<?php echo $order->id; ?>"
                            data-order="<?php echo htmlspecialchars($order->order_number, ENT_QUOTES); ?>"
                            data-customer="<?php echo htmlspecialchars($order->customer_name, ENT_QUOTES); ?>"
                            class="dispatch-btn inline-flex items-center gap-1 px-2.5 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 font-semibold transition-colors cursor-pointer">
                        <i class="fas fa-truck text-xs"></i> Ship
                    </button>
                    <?php endif; ?>
                    <a href="credit_order_view.php?id=<?php echo $order->id; ?>"
                       class="inline-flex items-center px-2 py-1 border border-gray-300 text-gray-600 rounded hover:bg-gray-100 transition-colors" title="View">
                        <i class="fas fa-eye text-xs"></i>
                    </a>
                <?php elseif ($order->status === 'shipped'): ?>
                    <button type="button"
                            data-action="deliver"
                            data-id="<?php echo $order->id; ?>"
                            data-order="<?php echo htmlspecialchars($order->order_number, ENT_QUOTES); ?>"
                            data-customer="<?php echo htmlspecialchars($order->customer_name, ENT_QUOTES); ?>"
                            data-truck="<?php echo htmlspecialchars($order->truck_number ?? '', ENT_QUOTES); ?>"
                            data-driver="<?php echo htmlspecialchars($order->driver_name ?? '', ENT_QUOTES); ?>"
                            class="dispatch-btn inline-flex items-center gap-1 px-2.5 py-1 bg-green-600 text-white rounded hover:bg-green-700 font-semibold transition-colors cursor-pointer">
                        <i class="fas fa-check text-xs"></i> Deliver
                    </button>
                    <a href="credit_invoice_print.php?id=<?php echo $order->id; ?>" target="_blank"
                       class="inline-flex items-center px-2 py-1 bg-purple-600 text-white rounded hover:bg-purple-700 transition-colors" title="Print Invoice">
                        <i class="fas fa-print text-xs"></i>
                    </a>
                    <a href="dispatch_slip.php?id=<?php echo $order->id; ?>" target="_blank"
                       class="inline-flex items-center px-2 py-1 bg-indigo-600 text-white rounded hover:bg-indigo-700 transition-colors" title="Dispatch Slip (QR)">
                        <i class="fas fa-qrcode text-xs"></i>
                    </a>
                    <a href="credit_order_view.php?id=<?php echo $order->id; ?>"
                       class="inline-flex items-center px-2 py-1 border border-gray-300 text-gray-600 rounded hover:bg-gray-100 transition-colors" title="View">
                        <i class="fas fa-eye text-xs"></i>
                    </a>
                <?php elseif ($order->status === 'delivered'): ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-green-100 text-green-700 rounded font-medium">
                        <i class="fas fa-check-circle text-xs"></i> Done
                    </span>
                    <a href="credit_invoice_print.php?id=<?php echo $order->id; ?>" target="_blank"
                       class="inline-flex items-center px-2 py-1 bg-purple-600 text-white rounded hover:bg-purple-700 transition-colors" title="Print Invoice">
                        <i class="fas fa-print text-xs"></i>
                    </a>
                    <a href="credit_order_view.php?id=<?php echo $order->id; ?>"
                       class="inline-flex items-center px-2 py-1 border border-gray-300 text-gray-600 rounded hover:bg-gray-100 transition-colors" title="View">
                        <i class="fas fa-eye text-xs"></i>
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
    <i class="fas fa-truck text-5xl text-gray-300 mb-4"></i>
    <h3 class="text-base font-semibold text-gray-600 mb-1">No Orders</h3>
    <p class="text-sm text-gray-400">No orders match the current filters.</p>
</div>
<?php endif; ?>

</div><!-- /container -->

<!-- ═══════════════════════════════════════
     SHIP MODAL
═══════════════════════════════════════ -->
<div id="shipModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md mx-4" onclick="event.stopPropagation()">
        <div class="px-5 py-4 border-b border-gray-200 flex items-start justify-between">
            <div>
                <h3 class="font-bold text-gray-900 text-sm">Assign Transport</h3>
                <p id="shipOrderInfo" class="text-xs text-gray-500 mt-0.5"></p>
            </div>
            <button type="button" onclick="closeShipModal()"
                    class="text-gray-400 hover:text-gray-600 cursor-pointer ml-4 flex-shrink-0">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" id="shipForm">
            <input type="hidden" name="action"          value="ship">
            <input type="hidden" name="order_id"         id="ship_order_id">
            <input type="hidden" name="fleet_driver_id"  id="ship_fleet_driver_id"  value="0">
            <input type="hidden" name="fleet_vehicle_id" id="ship_fleet_vehicle_id" value="0">
            <div class="px-5 py-4 space-y-4">
                <!-- Truck typeahead -->
                <div class="relative">
                    <label class="block text-xs font-medium text-gray-700 mb-1">
                        Truck / Vehicle Registration <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="truck_number" id="ship_truck_input"
                           required autocomplete="off"
                           placeholder="Type registration number…"
                           oninput="shipFleetSearch('vehicle', this.value)"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm font-mono
                                  focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <div id="ship_truck_dropdown"
                         class="absolute z-30 w-full bg-white border border-gray-200 rounded-lg shadow-lg mt-1
                                hidden text-sm max-h-40 overflow-y-auto"></div>
                    <p id="ship_truck_badge" class="text-xs mt-1 min-h-[1rem]"></p>
                </div>
                <!-- Driver typeahead -->
                <div class="relative">
                    <label class="block text-xs font-medium text-gray-700 mb-1">
                        Driver Name <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="driver_name" id="ship_driver_input"
                           required autocomplete="off"
                           placeholder="Type driver name…"
                           oninput="shipFleetSearch('driver', this.value)"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm
                                  focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    <div id="ship_driver_dropdown"
                         class="absolute z-30 w-full bg-white border border-gray-200 rounded-lg shadow-lg mt-1
                                hidden text-sm max-h-40 overflow-y-auto"></div>
                    <p id="ship_driver_badge" class="text-xs mt-1 min-h-[1rem]"></p>
                </div>
                <!-- Driver Contact -->
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">Driver Contact</label>
                    <input type="text" name="driver_contact" id="ship_driver_contact"
                           placeholder="Auto-fills from fleet list"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm
                                  focus:ring-2 focus:ring-green-500">
                </div>
            </div>
            <div class="px-5 py-3 border-t border-gray-200 bg-gray-50 rounded-b-xl flex gap-2">
                <button type="submit"
                        onclick="return confirm('Ship this order and create invoice in customer ledger?')"
                        class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700
                               text-sm font-semibold transition-colors cursor-pointer">
                    <i class="fas fa-truck mr-2"></i>Ship & Update Ledger
                </button>
                <button type="button" onclick="closeShipModal()"
                        class="px-4 py-2 border border-gray-300 text-gray-600 rounded-lg hover:bg-gray-100
                               text-sm cursor-pointer">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════
     DELIVER MODAL
═══════════════════════════════════════ -->
<div id="deliverModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm mx-4" onclick="event.stopPropagation()">
        <div class="px-5 py-4 border-b border-gray-200 flex items-start justify-between">
            <div>
                <h3 class="font-bold text-gray-900 text-sm">Confirm Delivery</h3>
                <p id="deliverOrderInfo" class="text-xs text-gray-500 mt-0.5"></p>
            </div>
            <button type="button" onclick="closeDeliverModal()"
                    class="text-gray-400 hover:text-gray-600 cursor-pointer ml-4 flex-shrink-0">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" id="deliverForm">
            <input type="hidden" name="action"    value="delivered">
            <input type="hidden" name="order_id"  id="deliver_order_id">
            <div class="px-5 py-4">
                <label class="block text-xs font-medium text-gray-700 mb-1">
                    Delivery Notes <span class="text-gray-400">(optional)</span>
                </label>
                <textarea name="delivery_notes" rows="3"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm
                                 focus:ring-2 focus:ring-green-500"
                          placeholder="Any notes about the delivery…"></textarea>
            </div>
            <div class="px-5 py-3 border-t border-gray-200 bg-gray-50 rounded-b-xl flex gap-2">
                <button type="submit"
                        onclick="return confirm('Confirm that this order has been delivered to customer?')"
                        class="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700
                               text-sm font-semibold transition-colors cursor-pointer">
                    <i class="fas fa-check-circle mr-2"></i>Confirm Delivered
                </button>
                <button type="button" onclick="closeDeliverModal()"
                        class="px-4 py-2 border border-gray-300 text-gray-600 rounded-lg hover:bg-gray-100
                               text-sm cursor-pointer">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════
     ITEMS MODAL
═══════════════════════════════════════ -->
<div id="itemsModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg mx-4 max-h-[88vh] flex flex-col"
         onclick="event.stopPropagation()">
        <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between flex-shrink-0">
            <h3 class="font-bold text-gray-900 text-sm" id="itemsModalTitle">Order Items</h3>
            <button type="button" onclick="closeItemsModal()"
                    class="text-gray-400 hover:text-gray-600 cursor-pointer">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div id="itemsModalBody" class="overflow-y-auto p-5 text-sm flex-1"></div>
    </div>
</div>

<!-- ═══════════════════════════════════════
     JAVASCRIPT
═══════════════════════════════════════ -->
<script>
// Order data store (items pre-fetched server-side)
const _ordersData = {
<?php foreach ($orders as $order):
    $oi = $all_items_by_order[$order->id] ?? [];
    $oi_js = json_encode(array_map(fn($i) => [
        'product' => $i->product_name,
        'variant' => trim(($i->grade ?? '') . ' ' . ($i->weight_variant ?? '')),
        'qty'     => (float)$i->quantity,
        'unit'    => $i->unit_of_measure ?? '',
        'price'   => (float)$i->unit_price,
        'total'   => (float)$i->line_total,
    ], $oi), JSON_UNESCAPED_UNICODE);
?>
    <?php echo (int)$order->id; ?>: {
        order_number:   <?php echo json_encode($order->order_number); ?>,
        customer:       <?php echo json_encode($order->customer_name); ?>,
        phone:          <?php echo json_encode($order->customer_phone ?? ''); ?>,
        branch:         <?php echo json_encode($order->branch_name ?? ''); ?>,
        address:        <?php echo json_encode($order->shipping_address ?? ''); ?>,
        instructions:   <?php echo json_encode($order->special_instructions ?? ''); ?>,
        status:         <?php echo json_encode($order->status); ?>,
        truck:          <?php echo json_encode($order->truck_number ?? ''); ?>,
        driver:         <?php echo json_encode($order->driver_name ?? ''); ?>,
        driver_contact: <?php echo json_encode($order->driver_contact ?? ''); ?>,
        shipped_date:   <?php echo json_encode($order->shipped_date   ? date('d M Y, g:i A', strtotime($order->shipped_date))   : ''); ?>,
        delivered_date: <?php echo json_encode($order->delivered_date ? date('d M Y, g:i A', strtotime($order->delivered_date)) : ''); ?>,
        total:          <?php echo (float)$order->total_amount; ?>,
        items:          <?php echo $oi_js; ?>
    },
<?php endforeach; ?>
};

// ── Dispatch button delegation (avoids inline onclick quoting issues) ──
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.dispatch-btn');
    if (!btn) return;
    const action   = btn.dataset.action;
    const id       = btn.dataset.id;
    const order    = btn.dataset.order;
    const customer = btn.dataset.customer;
    if (action === 'ship') {
        openShipModal(id, order, customer);
    } else if (action === 'deliver') {
        openDeliverModal(id, order, customer, btn.dataset.truck || '', btn.dataset.driver || '');
    }
});

// ── Ship Modal ─────────────────────────────────────────────────────────
function openShipModal(orderId, orderNumber, customer) {
    document.getElementById('ship_order_id').value = orderId;
    ['ship_truck_input','ship_driver_input','ship_driver_contact'].forEach(id => document.getElementById(id).value = '');
    ['ship_fleet_driver_id','ship_fleet_vehicle_id'].forEach(id => document.getElementById(id).value = '0');
    ['ship_truck_badge','ship_driver_badge'].forEach(id => document.getElementById(id).innerHTML = '');
    ['ship_truck_dropdown','ship_driver_dropdown'].forEach(id => document.getElementById(id).classList.add('hidden'));
    document.getElementById('shipOrderInfo').textContent = orderNumber + ' — ' + customer;
    document.getElementById('shipModal').classList.remove('hidden');
    setTimeout(() => document.getElementById('ship_truck_input').focus(), 80);
}
function closeShipModal() { document.getElementById('shipModal').classList.add('hidden'); }

// ── Deliver Modal ──────────────────────────────────────────────────────
function openDeliverModal(orderId, orderNumber, customer, truck, driver) {
    document.getElementById('deliver_order_id').value = orderId;
    document.getElementById('deliverForm').querySelector('textarea').value = '';
    document.getElementById('deliverOrderInfo').innerHTML =
        orderNumber + ' — ' + customer +
        (truck ? '<br><span class="font-mono text-gray-700">' + truck + '</span>' + (driver ? ' · ' + driver : '') : '');
    document.getElementById('deliverModal').classList.remove('hidden');
}
function closeDeliverModal() { document.getElementById('deliverModal').classList.add('hidden'); }

// ── Items Modal ────────────────────────────────────────────────────────
function openItemsModal(orderId) {
    const d = _ordersData[orderId];
    if (!d) return;
    document.getElementById('itemsModalTitle').textContent = d.order_number + ' — Items';

    let html = `<div class="flex items-start justify-between mb-3">
        <div>
            <div class="font-semibold text-gray-900">${d.customer}</div>
            <div class="text-xs text-gray-500">${d.branch}${d.phone ? ' · ' + d.phone : ''}</div>
        </div>
        <a href="credit_order_view.php?id=${orderId}" class="text-xs text-blue-600 hover:underline ml-4 flex-shrink-0">View Order</a>
    </div>`;
    if (d.address) {
        html += `<div class="mb-3 text-xs text-gray-500"><i class="fas fa-map-marker-alt mr-1 text-gray-400"></i>${d.address}</div>`;
    }
    if (d.instructions) {
        html += `<div class="mb-3 text-xs bg-blue-50 border border-blue-100 text-blue-800 px-3 py-2 rounded">
                     <i class="fas fa-info-circle mr-1"></i>${d.instructions}</div>`;
    }
    html += `<table class="w-full border-collapse mb-4 text-xs">
        <thead>
            <tr class="bg-gray-50 border-b border-gray-200">
                <th class="py-1.5 px-2 text-left font-semibold text-gray-500">Product</th>
                <th class="py-1.5 px-2 text-right font-semibold text-gray-500">Qty</th>
                <th class="py-1.5 px-2 text-right font-semibold text-gray-500">Price</th>
                <th class="py-1.5 px-2 text-right font-semibold text-gray-500">Total</th>
            </tr>
        </thead>
        <tbody>`;
    d.items.forEach(item => {
        html += `<tr class="border-b border-gray-100">
            <td class="py-2 px-2">
                <div class="font-medium text-gray-900">${item.product}</div>
                ${item.variant ? `<div class="text-gray-400">${item.variant}</div>` : ''}
            </td>
            <td class="py-2 px-2 text-right text-gray-700">${item.qty} <span class="text-gray-400">${item.unit}</span></td>
            <td class="py-2 px-2 text-right text-gray-700">৳${Number(item.price).toLocaleString()}</td>
            <td class="py-2 px-2 text-right font-semibold text-gray-900">৳${Number(item.total).toLocaleString()}</td>
        </tr>`;
    });
    html += `</tbody>
        <tfoot>
            <tr class="border-t-2 border-gray-300 bg-gray-50">
                <td colspan="3" class="py-2 px-2 text-right font-bold text-gray-700">Total</td>
                <td class="py-2 px-2 text-right font-bold text-blue-700">৳${Number(d.total).toLocaleString()}</td>
            </tr>
        </tfoot>
    </table>`;
    if (d.truck) {
        html += `<div class="bg-gray-50 rounded-lg p-3 text-xs border border-gray-200 space-y-1">
            <div class="flex flex-wrap items-center gap-2">
                <i class="fas fa-truck text-gray-400 w-4"></i>
                <span class="font-mono font-bold text-gray-800">${d.truck}</span>
                <span class="text-gray-400">·</span>
                <span class="text-gray-700">${d.driver}</span>
                ${d.driver_contact ? `<span class="text-gray-400">·</span><span class="text-gray-600">${d.driver_contact}</span>` : ''}
            </div>
            ${d.shipped_date   ? `<div><i class="fas fa-paper-plane text-blue-400 w-4 mr-1"></i>Shipped: ${d.shipped_date}</div>`     : ''}
            ${d.delivered_date ? `<div><i class="fas fa-check-circle text-green-500 w-4 mr-1"></i>Delivered: ${d.delivered_date}</div>` : ''}
        </div>`;
    }
    document.getElementById('itemsModalBody').innerHTML = html;
    document.getElementById('itemsModal').classList.remove('hidden');
}
function closeItemsModal() { document.getElementById('itemsModal').classList.add('hidden'); }

// ── Fleet Typeahead (Ship Modal) ───────────────────────────────────────
let _shipDeb = {};
function shipFleetSearch(type, val) {
    clearTimeout(_shipDeb[type]);
    const isDriver = type === 'driver';
    const drop = document.getElementById(isDriver ? 'ship_driver_dropdown' : 'ship_truck_dropdown');
    document.getElementById(isDriver ? 'ship_fleet_driver_id' : 'ship_fleet_vehicle_id').value = '0';
    if (!val.trim()) { drop.classList.add('hidden'); return; }
    _shipDeb[type] = setTimeout(() => {
        fetch(`credit_dispatch.php?fleet_action=${isDriver ? 'drivers' : 'vehicles'}&q=${encodeURIComponent(val)}`)
            .then(r => r.json())
            .then(rows => {
                if (!rows.length) { drop.classList.add('hidden'); return; }
                drop.innerHTML = '';
                rows.forEach(r => {
                    const el = document.createElement('div');
                    el.className = 'px-3 py-2 cursor-pointer flex items-center gap-2 border-b border-gray-100 last:border-0 ' +
                                   (isDriver ? 'hover:bg-indigo-50' : 'hover:bg-orange-50');
                    if (isDriver) {
                        el.innerHTML = `<i class="fas fa-user text-indigo-400 text-xs w-3"></i>
                            <span class="font-medium text-xs text-gray-900">${r.name}</span>
                            <span class="text-gray-400 text-xs">${r.type}</span>
                            ${r.phone ? `<span class="ml-auto text-gray-500 text-xs">${r.phone}</span>` : ''}`;
                        el.addEventListener('click', () => shipSelectDriver(r.id, r.name, r.phone, r.type));
                    } else {
                        const ownCls = r.own === 'Own' ? 'bg-green-100 text-green-700' : 'bg-purple-100 text-purple-700';
                        el.innerHTML = `<i class="fas fa-truck text-orange-400 text-xs w-3"></i>
                            <span class="font-mono font-bold text-xs text-gray-900">${r.reg}</span>
                            <span class="text-gray-400 text-xs">${r.type}</span>
                            <span class="ml-auto text-xs px-1.5 py-0.5 rounded ${ownCls}">${r.own}</span>`;
                        el.addEventListener('click', () => shipSelectVehicle(r.id, r.reg, r.type, r.own));
                    }
                    drop.appendChild(el);
                });
                drop.classList.remove('hidden');
            })
            .catch(() => drop.classList.add('hidden'));
    }, 220);
}

function shipSelectDriver(id, name, phone, type) {
    document.getElementById('ship_driver_input').value   = name;
    document.getElementById('ship_driver_contact').value = phone;
    document.getElementById('ship_fleet_driver_id').value = id;
    document.getElementById('ship_driver_badge').innerHTML =
        `<span class="inline-flex items-center gap-1 px-2 py-0.5 bg-indigo-100 text-indigo-700 rounded-full text-xs font-semibold">
             <i class="fas fa-check-circle"></i> Matched from fleet — ${type}
         </span>`;
    document.getElementById('ship_driver_dropdown').classList.add('hidden');
}
function shipSelectVehicle(id, reg, type, own) {
    document.getElementById('ship_truck_input').value      = reg;
    document.getElementById('ship_fleet_vehicle_id').value = id;
    document.getElementById('ship_truck_badge').innerHTML =
        `<span class="inline-flex items-center gap-1 px-2 py-0.5 bg-orange-100 text-orange-700 rounded-full text-xs font-semibold">
             <i class="fas fa-check-circle"></i> ${type} · ${own}
         </span>`;
    document.getElementById('ship_truck_dropdown').classList.add('hidden');
}

// Close dropdowns on outside click
document.addEventListener('click', function(e) {
    ['ship_driver_dropdown','ship_truck_dropdown'].forEach(id => {
        const el = document.getElementById(id);
        if (el && !el.closest('.relative')?.contains(e.target)) el.classList.add('hidden');
    });
});

// Backdrop click closes modals
document.getElementById('shipModal').addEventListener('click',    function(e) { if (e.target===this) closeShipModal(); });
document.getElementById('deliverModal').addEventListener('click', function(e) { if (e.target===this) closeDeliverModal(); });
document.getElementById('itemsModal').addEventListener('click',   function(e) { if (e.target===this) closeItemsModal(); });

// Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeShipModal(); closeDeliverModal(); closeItemsModal(); }
});
</script>

<?php require_once '../templates/footer.php'; ?>
