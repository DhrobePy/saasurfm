<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'Accounts', 'admin', 'dispatch-srg', 'dispatch-demra', 'dispatchpos-demra', 'dispatchpos-srg'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id = $currentUser['id'] ?? null;
$user_role = $currentUser['role'] ?? '';
$pageTitle = 'Dispatch Management';
$error = null;
$success = null;

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
            $prev_ledger = $db->query(
                "SELECT balance_after FROM customer_ledger
                 WHERE customer_id = ? ORDER BY transaction_date DESC, id DESC LIMIT 1",
                [$order->customer_id]
            )->first();
            $prev_balance   = $prev_ledger ? (float)$prev_ledger->balance_after : (float)($customer_data->current_balance ?? 0);
            $balance_after  = $prev_balance + $invoice_amount;

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

require_once '../templates/header.php';
?>

<div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900"><?php echo $pageTitle; ?></h1>
    <p class="text-lg text-gray-600 mt-1">Ship orders and track deliveries</p>
</div>

<?php if ($error): ?>
<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg">
    <p class="font-bold">Error</p>
    <p><?php echo htmlspecialchars($error); ?></p>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['success_flash'])): ?>
<div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-r-lg shadow-md">
    <p class="font-bold">Success</p>
    <p><?php echo htmlspecialchars($_SESSION['success_flash']); ?></p>
</div>
<?php unset($_SESSION['success_flash']); ?>
<?php endif; ?>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <?php
    try {
        $ready_result = $db->query("SELECT COUNT(*) as c FROM credit_orders co WHERE co.status = 'ready_to_ship' $branch_filter", $branch_params)->first();
        $shipped_result = $db->query("SELECT COUNT(*) as c FROM credit_orders co WHERE co.status = 'shipped' $branch_filter", $branch_params)->first();
        $delivered_result = $db->query("SELECT COUNT(*) as c FROM credit_orders co WHERE co.status = 'delivered' $branch_filter", $branch_params)->first();

        $stats = [
            'ready_to_ship' => ($ready_result && isset($ready_result->c)) ? $ready_result->c : 0,
            'shipped' => ($shipped_result && isset($shipped_result->c)) ? $shipped_result->c : 0,
            'delivered' => ($delivered_result && isset($delivered_result->c)) ? $delivered_result->c : 0
        ];
    } catch (Exception $e) {
        $stats = ['ready_to_ship' => 0, 'shipped' => 0, 'delivered' => 0];
        error_log("Stats query error: " . $e->getMessage());
    }
    ?>
    <div class="bg-orange-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Ready to Ship</p>
        <p class="text-3xl font-bold mt-2"><?php echo $stats['ready_to_ship']; ?></p>
    </div>
    <div class="bg-blue-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Shipped</p>
        <p class="text-3xl font-bold mt-2"><?php echo $stats['shipped']; ?></p>
    </div>
    <div class="bg-green-600 rounded-lg shadow-lg p-6 text-white">
        <p class="text-sm opacity-90">Delivered</p>
        <p class="text-3xl font-bold mt-2"><?php echo $stats['delivered']; ?></p>
    </div>
</div>

<!-- Filter Section -->
<div class="bg-white rounded-lg shadow-md p-6 mb-6">
    <h3 class="text-lg font-semibold text-gray-800 mb-4">
        <i class="fas fa-filter mr-2"></i>Filter Orders
    </h3>
    <form method="GET" action="credit_dispatch.php" class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <!-- From Date -->
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">From Date</label>
            <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" 
                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
        </div>
        
        <!-- To Date -->
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">To Date</label>
            <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>" 
                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
        </div>
        
        <!-- Status Filter -->
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
            <select name="status_filter" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                <option value="ready_to_ship" <?php echo $status_filter === 'ready_to_ship' ? 'selected' : ''; ?>>Ready to Ship Only</option>
                <option value="shipped" <?php echo $status_filter === 'shipped' ? 'selected' : ''; ?>>Shipped Only</option>
                <option value="delivered" <?php echo $status_filter === 'delivered' ? 'selected' : ''; ?>>Delivered Only</option>
                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
            </select>
        </div>
        
        <!-- Filter Buttons -->
        <div class="flex items-end gap-2">
            <button type="submit" class="flex-1 bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 transition-colors shadow-sm font-medium">
                <i class="fas fa-filter mr-1"></i>Filter
            </button>
            <a href="credit_dispatch.php" class="bg-gray-200 text-gray-700 px-3 py-2 rounded-md hover:bg-gray-300 transition-colors shadow-sm" title="Reset">
                <i class="fas fa-undo"></i>
            </a>
        </div>
    </form>
    
    <div class="mt-3 pt-3 border-t border-gray-200 text-sm text-gray-600">
        <i class="fas fa-info-circle mr-1"></i>
        Showing <strong><?php echo count($orders); ?></strong> orders 
        <?php if ($status_filter === 'ready_to_ship'): ?>
            <span class="text-orange-600 font-semibold">ready to ship</span>
        <?php elseif ($status_filter === 'shipped'): ?>
            <span class="text-blue-600 font-semibold">shipped</span>
        <?php elseif ($status_filter === 'delivered'): ?>
            <span class="text-green-600 font-semibold">delivered</span>
        <?php else: ?>
            <span class="text-gray-600 font-semibold">in all statuses</span>
        <?php endif; ?>
        from <strong><?php echo date('M j, Y', strtotime($from_date)); ?></strong> 
        to <strong><?php echo date('M j, Y', strtotime($to_date)); ?></strong>
    </div>
</div>

<?php if (!empty($orders)): ?>
<?php foreach ($orders as $order): 
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
    
    $items_count = count($items);
    
    $status_colors = [
        'ready_to_ship' => 'orange',
        'shipped' => 'blue',
        'delivered' => 'green'
    ];
    $color = $status_colors[$order->status] ?? 'gray';
?>

<div class="bg-white rounded-lg shadow-md mb-6 overflow-hidden">
    <div class="p-6 border-b border-gray-200 bg-gray-50">
        <div class="flex justify-between items-start">
            <div>
                <h3 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($order->order_number); ?></h3>
                <span class="inline-block mt-2 px-3 py-1 text-xs font-semibold rounded-full bg-<?php echo $color; ?>-100 text-<?php echo $color; ?>-800">
                    <?php echo ucwords(str_replace('_', ' ', $order->status)); ?>
                </span>
                <?php if ($order->trip_id): ?>
                    <span class="inline-block mt-2 ml-2 px-3 py-1 text-xs font-semibold rounded-full bg-purple-100 text-purple-800">
                        <i class="fas fa-truck"></i> Trip #<?php echo $order->trip_id; ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="text-right">
                <p class="text-2xl font-bold text-blue-600">৳<?php echo number_format($order->total_amount, 2); ?></p>
                <p class="text-sm text-gray-600"><?php echo $items_count; ?> items</p>
            </div>
        </div>
    </div>

    <div class="p-6 border-b border-gray-200">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <h4 class="font-semibold text-gray-800 mb-2">Customer</h4>
                <p class="text-sm"><strong><?php echo htmlspecialchars($order->customer_name); ?></strong></p>
                <p class="text-sm text-gray-600"><?php echo htmlspecialchars($order->customer_phone); ?></p>
            </div>
            <div>
                <h4 class="font-semibold text-gray-800 mb-2">Delivery</h4>
                <p class="text-sm"><strong>Address:</strong></p>
                <p class="text-sm text-gray-700"><?php echo htmlspecialchars($order->shipping_address); ?></p>
                <p class="text-sm mt-2"><strong>Branch:</strong> <?php echo htmlspecialchars($order->branch_name); ?></p>
                <?php if ($order->special_instructions): ?>
                <p class="text-sm mt-2 p-2 bg-blue-50 border border-blue-200 rounded">
                    <i class="fas fa-info-circle text-blue-600 mr-1"></i>
                    <?php echo htmlspecialchars($order->special_instructions); ?>
                </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

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
                    <?php foreach ($items as $item): 
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
                        <td colspan="4" class="px-4 py-2 text-right font-semibold">Total:</td>
                        <td class="px-4 py-2 text-right font-bold text-blue-600">৳<?php echo number_format($order->total_amount, 2); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="p-6 bg-gray-50">
        <?php if ($order->status === 'ready_to_ship'): ?>
        <h4 class="font-semibold text-gray-800 mb-4">Assign Transport</h4>
        <form method="POST" class="space-y-4" id="shipForm_<?php echo $order->id; ?>">
            <input type="hidden" name="action" value="ship">
            <input type="hidden" name="order_id" value="<?php echo $order->id; ?>">
            <input type="hidden" name="fleet_driver_id"  id="fleet_driver_id_<?php echo $order->id; ?>"  value="0">
            <input type="hidden" name="fleet_vehicle_id" id="fleet_vehicle_id_<?php echo $order->id; ?>" value="0">

            <!-- Truck Number typeahead -->
            <div class="relative">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Truck / Vehicle Registration <span class="text-red-500">*</span>
                </label>
                <input type="text" name="truck_number" id="truck_input_<?php echo $order->id; ?>"
                       required autocomplete="off"
                       placeholder="Type registration number…"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm font-mono"
                       oninput="fleetSearch('vehicle',<?php echo $order->id; ?>,this.value)">
                <div id="truck_dropdown_<?php echo $order->id; ?>"
                     class="absolute z-20 w-full bg-white border border-gray-200 rounded-lg shadow-lg mt-1 hidden text-sm"></div>
                <p id="truck_badge_<?php echo $order->id; ?>" class="text-xs mt-1 text-gray-500"></p>
            </div>

            <!-- Driver typeahead -->
            <div class="relative">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Driver Name <span class="text-red-500">*</span>
                </label>
                <input type="text" name="driver_name" id="driver_input_<?php echo $order->id; ?>"
                       required autocomplete="off"
                       placeholder="Type driver name…"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 text-sm"
                       oninput="fleetSearch('driver',<?php echo $order->id; ?>,this.value)">
                <div id="driver_dropdown_<?php echo $order->id; ?>"
                     class="absolute z-20 w-full bg-white border border-gray-200 rounded-lg shadow-lg mt-1 hidden text-sm"></div>
                <p id="driver_badge_<?php echo $order->id; ?>" class="text-xs mt-1 text-gray-500"></p>
            </div>

            <!-- Driver contact (auto-filled) -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Driver Contact</label>
                <input type="text" name="driver_contact" id="driver_contact_<?php echo $order->id; ?>"
                       placeholder="Phone number (auto-fills from fleet list)"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 text-sm">
            </div>

            <!-- hidden compat — kept so old column ref doesn't break any other JS -->
            <div class="hidden">
                <select name="vehicle_id_compat" id="vehicle_select_<?php echo $order->id; ?>"></select>
                <select name="driver_id_compat"  id="driver_select_<?php echo $order->id; ?>"></select>
            </div>
            <div class="hidden" id="driversSection_<?php echo $order->id; ?>"></div>
            <div id="vehicle_info_<?php echo $order->id; ?>"></div>
            <div id="driver_info_<?php echo $order->id; ?>"></div>

            
            <div class="flex gap-3 pt-4">
                <button type="submit" 
                        onclick="return confirm('Ship this order and create invoice in customer ledger?');"
                        class="flex-1 px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium transition-colors">
                    <i class="fas fa-truck mr-2"></i>Ship Order & Update Ledger
                </button>
                <a href="./credit_order_view.php?id=<?php echo $order->id; ?>" 
                   class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
                    <i class="fas fa-eye mr-2"></i>View Details
                </a>
            </div>
        </form>
        
        <script>
        (function() {
            const orderId = <?php echo $order->id; ?>;
            let debounceTimer = {};

            window.fleetSearch = function(type, oid, val) {
                if (oid !== orderId) return;
                clearTimeout(debounceTimer[type]);
                const dropId  = type === 'driver' ? 'driver_dropdown_' : 'truck_dropdown_';
                const drop    = document.getElementById(dropId + oid);
                const fidKey  = type === 'driver' ? 'fleet_driver_id_' : 'fleet_vehicle_id_';
                const fid     = document.getElementById(fidKey + oid);

                // Reset fleet id when user types manually
                fid.value = '0';

                if (!val.trim()) { drop.classList.add('hidden'); return; }

                debounceTimer[type] = setTimeout(() => {
                    const action = type === 'driver' ? 'drivers' : 'vehicles';
                    fetch(`credit_dispatch.php?fleet_action=${action}&q=${encodeURIComponent(val)}`)
                        .then(r => r.json())
                        .then(rows => {
                            if (!rows.length) { drop.classList.add('hidden'); return; }
                            drop.innerHTML = rows.map(r => {
                                if (type === 'driver') {
                                    return `<div class="px-4 py-2 hover:bg-indigo-50 cursor-pointer flex items-center gap-3 border-b border-gray-100 last:border-0"
                                               onclick="selectDriver(${oid},${r.id},'${r.name.replace(/'/g,"\\'")}','${r.phone.replace(/'/g,"\\'")}','${r.type.replace(/'/g,"\\'")}')">
                                        <i class="fas fa-user text-indigo-400 text-xs"></i>
                                        <span class="font-medium text-gray-900">${r.name}</span>
                                        <span class="text-gray-400 text-xs">${r.type}</span>
                                        ${r.phone ? `<span class="ml-auto text-gray-500 text-xs">${r.phone}</span>` : ''}
                                    </div>`;
                                } else {
                                    return `<div class="px-4 py-2 hover:bg-orange-50 cursor-pointer flex items-center gap-3 border-b border-gray-100 last:border-0"
                                               onclick="selectVehicle(${oid},${r.id},'${r.reg.replace(/'/g,"\\'")}','${r.type}','${r.own}')">
                                        <i class="fas fa-truck text-orange-400 text-xs"></i>
                                        <span class="font-mono font-bold text-gray-900">${r.reg}</span>
                                        <span class="text-gray-400 text-xs">${r.type}</span>
                                        <span class="ml-auto text-xs px-1.5 py-0.5 rounded ${r.own==='Own' ? 'bg-green-100 text-green-700' : 'bg-purple-100 text-purple-700'}">${r.own}</span>
                                    </div>`;
                                }
                            }).join('');
                            drop.classList.remove('hidden');
                        })
                        .catch(() => drop.classList.add('hidden'));
                }, 220);
            };

            window.selectDriver = function(oid, id, name, phone, type) {
                if (oid !== orderId) return;
                document.getElementById('driver_input_'   + oid).value = name;
                document.getElementById('driver_contact_' + oid).value = phone;
                document.getElementById('fleet_driver_id_'+ oid).value = id;
                const badge = document.getElementById('driver_badge_' + oid);
                badge.innerHTML = `<span class="inline-flex items-center gap-1 px-2 py-0.5 bg-indigo-100 text-indigo-700 rounded-full text-xs font-semibold"><i class="fas fa-check-circle"></i> Matched from fleet — ${type}</span>`;
                document.getElementById('driver_dropdown_' + oid).classList.add('hidden');
            };

            window.selectVehicle = function(oid, id, reg, type, own) {
                if (oid !== orderId) return;
                document.getElementById('truck_input_'     + oid).value = reg;
                document.getElementById('fleet_vehicle_id_'+ oid).value = id;
                const badge = document.getElementById('truck_badge_' + oid);
                badge.innerHTML = `<span class="inline-flex items-center gap-1 px-2 py-0.5 bg-orange-100 text-orange-700 rounded-full text-xs font-semibold"><i class="fas fa-check-circle"></i> Matched from fleet — ${type} · ${own}</span>`;
                document.getElementById('truck_dropdown_' + oid).classList.add('hidden');
            };

            // Close dropdowns on outside click
            document.addEventListener('click', function(e) {
                ['driver_dropdown_','truck_dropdown_'].forEach(pfx => {
                    const el = document.getElementById(pfx + orderId);
                    if (el && !el.contains(e.target) &&
                        !document.getElementById(pfx === 'driver_dropdown_' ? 'driver_input_' : 'truck_input_' + orderId)?.contains(e.target)) {
                        el.classList.add('hidden');
                    }
                });
            });
        })();
        </script>
        
        <?php elseif ($order->status === 'shipped'): ?>
        <div class="space-y-4">
            <h4 class="font-semibold text-gray-800">Shipping Details</h4>
            <div class="grid grid-cols-3 gap-4 text-sm">
                <div>
                    <p class="text-gray-600">Truck Number:</p>
                    <p class="font-medium"><?php echo htmlspecialchars($order->truck_number ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Driver Name:</p>
                    <p class="font-medium"><?php echo htmlspecialchars($order->driver_name ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Driver Contact:</p>
                    <p class="font-medium"><?php echo htmlspecialchars($order->driver_contact ?? '—'); ?></p>
                </div>
            </div>
            <p class="text-sm text-gray-600">
                <i class="fas fa-calendar mr-1"></i>Shipped: <?php echo $order->shipped_date ? date('M j, Y g:i A', strtotime($order->shipped_date)) : '—'; ?>
            </p>
            
            <div class="pt-4 border-t border-gray-200 flex gap-3">
                <a href="credit_invoice_print.php?id=<?php echo $order->id; ?>" target="_blank"
                   class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                    <i class="fas fa-print mr-2"></i>Print Invoice
                </a>
                <a href="./credit_order_view.php?id=<?php echo $order->id; ?>" 
                   class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100">
                    <i class="fas fa-eye mr-2"></i>View Details
                </a>
            </div>
            
            <form method="POST" class="pt-4 border-t border-gray-200">
                <input type="hidden" name="action" value="delivered">
                <input type="hidden" name="order_id" value="<?php echo $order->id; ?>">
                
                <h4 class="font-semibold text-gray-800 mb-2">Mark as Delivered</h4>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Delivery Notes (Optional)</label>
                    <textarea name="delivery_notes" rows="2" 
                              class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500" 
                              placeholder="Any notes about the delivery..."></textarea>
                </div>
                <button type="submit" 
                        onclick="return confirm('Confirm that this order has been delivered to customer?');"
                        class="mt-3 px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                    <i class="fas fa-check-circle mr-2"></i>Confirm Delivery
                </button>
            </form>
        </div>
        
        <?php elseif ($order->status === 'delivered'): ?>
        <div class="space-y-4">
            <div class="flex items-center gap-2 text-green-600">
                <i class="fas fa-check-circle text-2xl"></i>
                <h4 class="font-semibold text-lg">Order Delivered</h4>
            </div>
            
            <div class="grid grid-cols-3 gap-4 text-sm">
                <div>
                    <p class="text-gray-600">Truck Number:</p>
                    <p class="font-medium"><?php echo htmlspecialchars($order->truck_number ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Driver Name:</p>
                    <p class="font-medium"><?php echo htmlspecialchars($order->driver_name ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Driver Contact:</p>
                    <p class="font-medium"><?php echo htmlspecialchars($order->driver_contact ?? '—'); ?></p>
                </div>
            </div>

            <div class="text-sm">
                <p><i class="fas fa-calendar mr-1 text-gray-500"></i>Shipped: <?php echo $order->shipped_date ? date('M j, Y g:i A', strtotime($order->shipped_date)) : '—'; ?></p>
                <p><i class="fas fa-check mr-1 text-green-500"></i>Delivered: <?php echo $order->delivered_date ? date('M j, Y g:i A', strtotime($order->delivered_date)) : '—'; ?></p>
            </div>
            
            <?php if ($order->delivery_notes): ?>
            <div class="p-3 bg-gray-100 rounded">
                <p class="text-sm text-gray-600"><strong>Delivery Notes:</strong></p>
                <p class="text-sm"><?php echo htmlspecialchars($order->delivery_notes); ?></p>
            </div>
            <?php endif; ?>
            
            <div class="pt-4 border-t border-gray-200 flex gap-3">
                <a href="credit_invoice_print.php?id=<?php echo $order->id; ?>" target="_blank"
                   class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                    <i class="fas fa-print mr-2"></i>Print Invoice
                </a>
                <a href="./credit_order_view.php?id=<?php echo $order->id; ?>" 
                   class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100">
                    <i class="fas fa-eye mr-2"></i>View Details
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php else: ?>
<div class="bg-white rounded-lg shadow-md p-12 text-center">
    <i class="fas fa-truck text-6xl text-gray-400 mb-4"></i>
    <h3 class="text-xl font-semibold text-gray-700 mb-2">No Orders for Dispatch</h3>
    <p class="text-gray-600">No orders are ready to ship at this time.</p>
</div>
<?php endif; ?>

</div>

<?php require_once '../templates/footer.php'; ?>