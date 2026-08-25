<?php
// CRITICAL: Prevent any output before JSON
ob_start();

/**
 * POS AJAX Handler — sale placement with full accounting integration.
 *
 * Rewritten Jul 2026 to close the money-integrity gaps found in review:
 * real transactions (beginTransaction/commit/rollback, not raw SQL strings),
 * every write checked via $db->error() (Database::query() swallows
 * PDOException silently — never trust an unchecked query() call here),
 * atomic stock locking (SELECT ... FOR UPDATE), split cash+credit payment,
 * a standalone POS credit-limit gate that queues over-limit sales for admin
 * approval instead of silently trusting customers.current_balance, real
 * pos_customer_ledger + branch petty-cash posting, a QR exit-verification
 * token, audit logging, and a Telegram ping.
 */

require_once '../core/init.php';

// Clear any existing output buffers
while (ob_get_level() > 1) {
    ob_end_clean();
}

header('Content-Type: application/json');

// Security check
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$pos_allowed_roles = ['Superadmin', 'admin', 'accountspos-demra', 'accountspos-srg', 'dispatchpos-demra', 'dispatchpos-srg'];
if (!in_array($_SESSION['user_role'] ?? '', $pos_allowed_roles)) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You do not have access to the POS module']);
    exit;
}

// CSRF Token validation for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrf_token) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf_token)) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

global $db;
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? '';
$is_admin = in_array($user_role, ['Superadmin', 'admin']);

ensurePosLedgerTable();
ensurePosSaleColumns();
ensurePosExitTables();
ensurePendingRequestsTable();

/** Throw if the last $db->query() failed — Database::query() never rethrows PDOException itself. */
function pos_check_db(Database $db, string $context): void {
    if ($db->error()) {
        $info = $db->errorInfo();
        throw new Exception("Database error ({$context}): " . ($info[2] ?? 'unknown error'));
    }
}

/**
 * Find required GL accounts by name pattern (Revenue / AR / Undeposited only —
 * the Cash leg uses getBranchPettyCashAccountId(), not fuzzy name matching).
 */
function find_account_id($db, $name_pattern, $account_types, $branch_code = null) {
    if ($branch_code) {
        $account = $db->query(
            "SELECT id FROM chart_of_accounts
             WHERE name LIKE ?
             AND account_type IN (" . implode(',', array_fill(0, count($account_types), '?')) . ")
             AND is_active = 1
             LIMIT 1",
            array_merge(["%{$name_pattern}%{$branch_code}%"], $account_types)
        )->first();
        if ($account) return $account->id;
    }
    $account = $db->query(
        "SELECT id FROM chart_of_accounts
         WHERE name LIKE ?
         AND account_type IN (" . implode(',', array_fill(0, count($account_types), '?')) . ")
         AND is_active = 1
         LIMIT 1",
        array_merge(["%{$name_pattern}%"], $account_types)
    )->first();
    return $account ? $account->id : null;
}

/**
 * Journal entry for a POS sale. Debits are split across Cash-in-hand (the
 * branch's own petty cash account) and Accounts Receivable (the credit
 * portion), matching however the sale was actually paid for.
 */
function create_pos_sale_journal_entry($db, $order_id, $order_number, $branch_id, $branch_code, $cash_paid, $credit_amount, $subtotal, $discount_amount, $user_id) {
    $revenue_account_id = find_account_id($db, 'Sales Revenue', ['Revenue'], $branch_code);
    $discount_account_id = find_account_id($db, 'Sales Discount', ['Expense'], null);
    $cash_account_id = $cash_paid > 0.009 ? getBranchPettyCashAccountId($branch_id) : null;
    $ar_account_id = $credit_amount > 0.009 ? find_account_id($db, 'Accounts Receivable', ['Accounts Receivable'], null) : null;

    if (!$revenue_account_id) throw new Exception("Chart of Accounts: no active Revenue account found for branch code {$branch_code}.");
    if ($cash_paid > 0.009 && !$cash_account_id) throw new Exception('No active petty cash account configured for this branch — set one up under Branch Petty Cash before taking cash sales.');
    if ($credit_amount > 0.009 && !$ar_account_id) throw new Exception('Chart of Accounts: no active Accounts Receivable account found.');

    $db->query(
        "INSERT INTO journal_entries (transaction_date, description, related_document_id, related_document_type, created_by_user_id)
         VALUES (CURDATE(), ?, ?, 'Order', ?)",
        ["POS Sale - Order #{$order_number}", $order_id, $user_id]
    );
    pos_check_db($db, 'create journal entry');
    $journal_id = $db->getPdo()->lastInsertId();
    if (!$journal_id) throw new Exception('Failed to create journal entry for POS sale.');

    $total_debits = 0;
    $total_credits = 0;

    if ($cash_paid > 0.009) {
        $db->query(
            "INSERT INTO transaction_lines (journal_entry_id, account_id, debit_amount, credit_amount, description) VALUES (?, ?, ?, 0, ?)",
            [$journal_id, $cash_account_id, $cash_paid, "POS Cash - Order #{$order_number}"]
        );
        pos_check_db($db, 'journal line: cash debit');
        $total_debits += $cash_paid;
    }

    if ($credit_amount > 0.009) {
        $db->query(
            "INSERT INTO transaction_lines (journal_entry_id, account_id, debit_amount, credit_amount, description) VALUES (?, ?, ?, 0, ?)",
            [$journal_id, $ar_account_id, $credit_amount, "POS Credit Sale - Order #{$order_number}"]
        );
        pos_check_db($db, 'journal line: AR debit');
        $total_debits += $credit_amount;
    }

    if ($discount_amount > 0.009 && $discount_account_id) {
        $db->query(
            "INSERT INTO transaction_lines (journal_entry_id, account_id, debit_amount, credit_amount, description) VALUES (?, ?, ?, 0, ?)",
            [$journal_id, $discount_account_id, $discount_amount, "Discount given - Order #{$order_number}"]
        );
        pos_check_db($db, 'journal line: discount debit');
        $total_debits += $discount_amount;
    }

    $db->query(
        "INSERT INTO transaction_lines (journal_entry_id, account_id, debit_amount, credit_amount, description) VALUES (?, ?, 0, ?, ?)",
        [$journal_id, $revenue_account_id, $subtotal, "Sales Revenue - Order #{$order_number}"]
    );
    pos_check_db($db, 'journal line: revenue credit');
    $total_credits += $subtotal;

    if (abs($total_debits - $total_credits) > 0.01) {
        throw new Exception("POS journal entry unbalanced — debits {$total_debits} vs credits {$total_credits}.");
    }

    $db->query("UPDATE orders SET journal_entry_id = ? WHERE id = ?", [$journal_id, $order_id]);
    pos_check_db($db, 'link journal to order');

    return $journal_id;
}

/**
 * The actual sale-creation logic — shared by a direct place_order and a
 * checker executing an approved pos_credit_sale request, so both paths post
 * through the exact same code (no accounting duplication).
 */
function execute_pos_sale(Database $db, array $o, int $acting_user_id, ?int $pending_req_id = null): array {
    $branch_id = (int)$o['branch_id'];
    $customer_id = $o['customer_id'] ?: null;
    $cart = $o['cart'];
    $subtotal = (float)$o['subtotal'];
    $total = (float)$o['total'];
    $cash_paid = (float)$o['cash_paid'];
    $credit_amount = (float)$o['credit_amount'];
    $payment_method = $o['payment_method'];
    $payment_reference = $o['payment_reference'] ?? null;
    $bank_name = $o['bank_name'] ?? null;
    $total_discount = $subtotal - $total;

    $db->beginTransaction();
    try {
        $branch_info = $db->query("SELECT code, name FROM branches WHERE id = ?", [$branch_id])->first();
        pos_check_db($db, 'load branch');
        if (!$branch_info) throw new Exception('Branch not found');
        $branch_code = $branch_info->code ?? 'UNKNOWN';

        $date_prefix = date('Ymd');
        $last_order = $db->query(
            "SELECT order_number FROM orders WHERE order_number LIKE ? ORDER BY id DESC LIMIT 1 FOR UPDATE",
            ["ORD-{$date_prefix}-%"]
        )->first();
        pos_check_db($db, 'lock order-number sequence');
        $sequence = 1;
        if ($last_order) {
            preg_match('/ORD-\d{8}-(\d{4})/', $last_order->order_number, $matches);
            $sequence = isset($matches[1]) ? intval($matches[1]) + 1 : 1;
        }
        $order_number = sprintf("ORD-%s-%04d", $date_prefix, $sequence);

        $payment_status = 'Paid';
        if ($credit_amount > 0.009 && $cash_paid > 0.009) $payment_status = 'Partial';
        elseif ($credit_amount > 0.009) $payment_status = 'Unpaid';

        $db->query(
            "INSERT INTO orders (
                order_number, branch_id, customer_id, order_date, order_type,
                subtotal, tax_amount, discount_amount, cart_discount_type, cart_discount_value,
                total_amount, cash_paid, credit_amount, payment_method, payment_reference, bank_name,
                payment_status, order_status, created_by_user_id
            ) VALUES (?, ?, ?, NOW(), 'POS', ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Completed', ?)",
            [
                $order_number, $branch_id, $customer_id, $subtotal, $total_discount,
                $o['cart_discount_type'] ?? 'none', $o['cart_discount_value'] ?? 0,
                $total, $cash_paid, $credit_amount, $payment_method, $payment_reference, $bank_name,
                $payment_status, $acting_user_id,
            ]
        );
        pos_check_db($db, 'insert order');
        $order_id = $db->getPdo()->lastInsertId();
        if (!$order_id) throw new Exception('Failed to create order record');

        foreach ($cart as $item) {
            $variant_id = (int)$item['variant_id'];
            $quantity = (int)$item['quantity'];
            $unit_price = (float)$item['unit_price'];
            $item_discount_type = $item['item_discount_type'] ?? 'none';
            $item_discount_value = floatval($item['item_discount_value'] ?? 0);

            $item_subtotal = $quantity * $unit_price;
            $item_discount_amount = 0;
            if ($item_discount_type === 'percentage') $item_discount_amount = ($item_subtotal * $item_discount_value) / 100;
            elseif ($item_discount_type === 'fixed') $item_discount_amount = $item_discount_value;
            $item_total = $item_subtotal - $item_discount_amount;

            // Stock tracking removed from POS (Jul 2026, explicit decision) —
            // no inventory check, no decrement. Branch stock counts weren't
            // being kept current, so gating/decrementing against them was
            // blocking real sales rather than protecting anything.
            $db->query(
                "INSERT INTO order_items (
                    order_id, variant_id, quantity, unit_price, item_discount_type, item_discount_value,
                    item_discount_amount, subtotal, discount_amount, tax_amount, total_amount
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)",
                [$order_id, $variant_id, $quantity, $unit_price, $item_discount_type, $item_discount_value,
                 $item_discount_amount, $item_subtotal, $item_discount_amount, $item_total]
            );
            pos_check_db($db, 'insert order item');
        }

        if ($cash_paid > 0.009) {
            postBranchPettyCashTransaction($branch_id, 'cash_in', $cash_paid, [
                'reference_type' => 'pos_sale', 'reference_id' => $order_id,
                'description' => "POS sale #{$order_number}", 'payment_method' => $payment_method,
            ]);
        }

        if ($credit_amount > 0.009) {
            if (!$customer_id) throw new Exception('A customer must be selected for a credit sale.');
            postPosLedgerEntry((int)$customer_id, 'sale', $credit_amount, 0, [
                'branch_id' => $branch_id, 'reference_type' => 'pos_sale', 'reference_id' => $order_id,
                'order_number' => $order_number, 'description' => "POS credit sale #{$order_number}",
            ]);
        }

        $journal_id = create_pos_sale_journal_entry(
            $db, $order_id, $order_number, $branch_id, $branch_code, $cash_paid, $credit_amount, $subtotal, $total_discount, $acting_user_id
        );

        // Placeholder exit-verification row so reports can show "pending" immediately.
        $db->query(
            "INSERT INTO pos_exit_verifications (order_id, order_number) VALUES (?, ?)",
            [$order_id, $order_number]
        );
        pos_check_db($db, 'create exit verification placeholder');

        if ($pending_req_id) {
            decidePendingRequest($pending_req_id, 'approved', null, $order_number);
        }

        $db->commit();

        // Audit logging happens AFTER commit, not inside the transaction — a
        // DDL-implicit-commit inside AuditLogger::ensureTable() would otherwise
        // silently end this transaction early (the exact bug class this app has
        // been bitten by before; see credit_dispatch.php's post-commit auditLogOrder()).
        if (function_exists('auditLog')) {
            auditLog('pos', 'created',
                "POS Sale {$order_number} — ৳" . number_format($total, 2) . ($credit_amount > 0.009 ? ' (partly on credit)' : ''),
                ['record_type' => 'pos_sale', 'record_id' => $order_id, 'reference_number' => $order_number,
                 'metadata' => ['branch_id' => $branch_id, 'customer_id' => $customer_id, 'cash_paid' => $cash_paid, 'credit_amount' => $credit_amount, 'pending_req_id' => $pending_req_id]]
            );
        }

        return ['order_id' => (int)$order_id, 'order_number' => $order_number, 'journal_entry_id' => $journal_id];

    } catch (Exception $e) {
        if ($db->getPdo()->inTransaction()) $db->rollback();
        throw $e;
    }
}

function notify_pos_sale(string $order_number, float $total, float $credit_amount, string $branch_name, string $user_name): void {
    if (!defined('TELEGRAM_NOTIFICATIONS_ENABLED') || !TELEGRAM_NOTIFICATIONS_ENABLED) return;
    if (!defined('TELEGRAM_BOT_TOKEN') || !defined('TELEGRAM_CHAT_ID')) return;
    try {
        require_once '../core/classes/TelegramNotifier.php';
        $msg = "<b>🏪 POS SALE — {$order_number}</b>\n"
             . "───────────────────────────────\n\n"
             . "• Branch: <b>{$branch_name}</b>\n"
             . "• Total: <b>৳" . number_format($total, 2) . "</b>"
             . ($credit_amount > 0.009 ? " (৳" . number_format($credit_amount, 2) . " on credit)" : '') . "\n"
             . "• By: <b>{$user_name}</b>";
        (new TelegramNotifier(TELEGRAM_BOT_TOKEN, getTelegramChatId('orders')))->sendMessage($msg);
    } catch (\Throwable $e) { error_log('notify_pos_sale: ' . $e->getMessage()); }
}

try {
    $action = $_POST['action'] ?? $_GET['action'] ?? ($data['action'] ?? '');

    switch ($action) {

        case 'get_products':
            // Stock tracking removed from POS (Jul 2026) — no inventory join.
            // branch_id is still required: product_prices is branch-wise
            // (product/pricing.php maintains one row per branch per variant),
            // so it drives which price this terminal actually shows.
            $branch_id = $_GET['branch_id'] ?? null;
            if (!$branch_id) throw new Exception('Branch ID is required');

            ensureProductOriginColumn();
            $sql = "SELECT
                    pv.id as variant_id, pv.sku, pv.weight_variant, pv.grade, pv.unit_of_measure,
                    p.base_name, ob.name AS origin_name,
                    (SELECT pp.unit_price FROM product_prices pp WHERE pp.variant_id = pv.id AND pp.is_active = 1
                     ORDER BY (pp.branch_id = :branch_id_price) DESC, pp.effective_date DESC, pp.created_at DESC LIMIT 1) as unit_price
                FROM product_variants pv
                JOIN products p ON pv.product_id = p.id
                LEFT JOIN branches ob ON ob.id = pv.origin_branch_id
                WHERE p.status = 'active' AND pv.status = 'active'
                ORDER BY p.base_name, pv.sku";

            $products = $db->query($sql, ['branch_id_price' => $branch_id])->results();
            $valid_products = [];
            foreach ($products as $product) {
                if ($product->unit_price !== null && $product->unit_price > 0) {
                    $valid_products[] = [
                        'variant_id' => (int)$product->variant_id, 'sku' => $product->sku, 'base_name' => $product->base_name,
                        'weight_variant' => $product->weight_variant, 'grade' => $product->grade,
                        'unit_of_measure' => $product->unit_of_measure, 'unit_price' => (float)$product->unit_price,
                        'origin_name' => $product->origin_name,
                    ];
                }
            }
            $buffer = ob_get_clean();
            if (!empty(trim($buffer))) error_log("Unexpected output in get_products: " . $buffer);
            echo json_encode(['success' => true, 'products' => $valid_products]);
            exit;

        case 'place_order':
            $branch_id = $data['branch_id'] ?? null;
            $customer_id = $data['customer_id'] ?? null;
            $cart = $data['cart'] ?? [];
            $subtotal = (float)($data['subtotal'] ?? 0);
            $total = (float)($data['total'] ?? 0);
            $payment_method = $data['payment_method'] ?? 'Cash';
            $payment_reference = $data['payment_reference'] ?? null;
            $bank_name = $data['bank_name'] ?? null;

            // Split payment: prefer explicit cash_paid/credit_amount from the client;
            // fall back to the old all-or-nothing behaviour for older callers.
            if (isset($data['cash_paid']) || isset($data['credit_amount'])) {
                $cash_paid = (float)($data['cash_paid'] ?? 0);
                $credit_amount = (float)($data['credit_amount'] ?? 0);
            } else {
                $cash_paid = $payment_method === 'Credit' ? 0 : $total;
                $credit_amount = $payment_method === 'Credit' ? $total : 0;
            }

            if (!$branch_id) throw new Exception('Branch ID is required');
            if (empty($cart)) throw new Exception('Cart is empty');
            if ($total <= 0) throw new Exception('Invalid order total');
            if (abs(($cash_paid + $credit_amount) - $total) > 0.02) {
                throw new Exception('Cash paid + credit amount must add up to the order total');
            }
            if ($credit_amount > 0.009 && !$customer_id) {
                throw new Exception('A customer must be selected to sell any portion on credit');
            }

            $order_payload = [
                'branch_id' => $branch_id, 'customer_id' => $customer_id, 'cart' => $cart,
                'subtotal' => $subtotal, 'total' => $total,
                'cart_discount_type' => $data['cart_discount_type'] ?? 'none',
                'cart_discount_value' => $data['cart_discount_value'] ?? 0,
                'cash_paid' => $cash_paid, 'credit_amount' => $credit_amount,
                'payment_method' => $payment_method, 'payment_reference' => $payment_reference, 'bank_name' => $bank_name,
            ];

            // Standalone POS credit-limit gate (not the Credit Sales delegated-limit
            // engine — a simple per-customer cap, block-or-queue-to-any-admin).
            if ($credit_amount > 0.009) {
                $customer = $db->query("SELECT id, name, credit_limit FROM customers WHERE id = ?", [$customer_id])->first();
                if (!$customer) throw new Exception('Customer not found');
                $existing_outstanding = getPosCustomerOutstanding((int)$customer_id);
                $limit = (float)$customer->credit_limit;
                if (($existing_outstanding + $credit_amount) > $limit) {
                    if ($is_admin) {
                        // Admin/Superadmin posting it themselves IS the approval — proceed.
                    } else {
                        $req_id = submitPendingRequest('pos_credit_sale', $total, $order_payload, [
                            'customer_id' => $customer_id,
                            'summary' => "POS credit sale for {$customer->name} — ৳" . number_format($credit_amount, 2)
                                       . " would push balance to ৳" . number_format($existing_outstanding + $credit_amount, 2)
                                       . " against a ৳" . number_format($limit, 2) . " limit",
                        ]);
                        $buffer = ob_get_clean();
                        if (!empty(trim($buffer))) error_log("Unexpected output queuing pos_credit_sale: " . $buffer);
                        echo json_encode([
                            'success' => true, 'queued' => true, 'request_id' => $req_id,
                            'message' => 'This sale exceeds the customer\'s credit limit and has been sent to an admin for approval. Do not release the goods yet.',
                        ]);
                        exit;
                    }
                }
            }

            $result = execute_pos_sale($db, $order_payload, $user_id);

            $branch_info = $db->query("SELECT name FROM branches WHERE id = ?", [$branch_id])->first();
            notify_pos_sale($result['order_number'], $total, $credit_amount, $branch_info->name ?? '', $_SESSION['user_display_name'] ?? 'Staff');

            $buffer = ob_get_clean();
            if (!empty(trim($buffer))) error_log("Unexpected output in place_order: " . $buffer);
            echo json_encode([
                'success' => true, 'order_id' => $result['order_id'], 'order_number' => $result['order_number'],
                'journal_entry_id' => $result['journal_entry_id'],
                'qr_url' => (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/pos/verify_exit.php?order=' . urlencode($result['order_number']) . '&sig=' . posExitQrSignature($result['order_number']),
                'message' => 'Order placed successfully',
            ]);
            exit;

        case 'execute_pending_sale':
            // Any admin approving a queued over-limit POS credit sale.
            if (!$is_admin) throw new Exception('Only an admin can approve a queued POS credit sale');
            $pending_req_id = (int)($data['pending_req_id'] ?? 0);
            if (!$pending_req_id) throw new Exception('Missing pending_req_id');

            $req = $db->query("SELECT * FROM cr_pending_requests WHERE id = ? AND request_type = 'pos_credit_sale' AND status = 'pending'", [$pending_req_id])->first();
            if (!$req) throw new Exception('Request not found or already decided');
            $payload = json_decode($req->payload, true);
            if (!$payload) throw new Exception('Corrupt request payload');

            $result = execute_pos_sale($db, $payload, $user_id, $pending_req_id);

            $branch_info = $db->query("SELECT name FROM branches WHERE id = ?", [$payload['branch_id']])->first();
            notify_pos_sale($result['order_number'], (float)$payload['total'], (float)$payload['credit_amount'], $branch_info->name ?? '', $_SESSION['user_display_name'] ?? 'Staff');

            $buffer = ob_get_clean();
            if (!empty(trim($buffer))) error_log("Unexpected output in execute_pending_sale: " . $buffer);
            echo json_encode([
                'success' => true, 'order_id' => $result['order_id'], 'order_number' => $result['order_number'],
                'message' => 'Sale approved and posted',
            ]);
            exit;

        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    if (ob_get_level()) ob_end_clean();
    error_log("POS AJAX Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
