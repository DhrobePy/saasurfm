<?php
/**
 * AI Dashboard Advisor + Text-to-SQL Engine
 * Place at: /admin/ai_dashboard_advisor.php
 *
 * Actions:
 *   daily_brief | cash_flow | credit_risk | operations | sales_analysis  → pre-built insight
 *   custom    → free-form question answered with live snapshot context
 *   db_query  → TWO-STEP: NL → SQL (Groq) → execute → NL summary (Groq)
 */

ob_start();
require_once '../core/init.php';
header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    ob_end_clean(); http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
}
if (!in_array($_SESSION['user_role'] ?? '', ['Superadmin', 'admin'])) {
    ob_end_clean(); http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']); exit;
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
$data = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($data['csrf_token'] ?? '');
    if (empty($csrf) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        ob_end_clean(); http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']); exit;
    }
} else {
    $data = $_GET;
}

global $db;

$action   = $data['action']   ?? 'daily_brief';
$question = trim($data['question'] ?? '');
$today    = date('Y-m-d');
$month_start = date('Y-m-01');
$yesterday   = date('Y-m-d', strtotime('-1 day'));

// =============================================================================
// DB-QUERY ACTION  (Text-to-SQL, two-step pipeline)
// =============================================================================
if ($action === 'db_query') {
    try {
        if (empty($question)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => 'Please enter a question.']); exit;
        }

        $schema = getSchemaPrompt();

        // ── STEP 1: NL → SQL ─────────────────────────────────────────────────
        $sql_system = "You are a MariaDB 11.4 SQL expert for Ujjal Flour Mills ERP (Bangladesh). Today is {$today}.\n\nDATABASE SCHEMA:\n{$schema}\n\nRULES — follow every one:\n1. Output ONLY a single raw SQL SELECT statement. No markdown, no backticks, no explanation, no semicolon.\n2. NEVER use INSERT, UPDATE, DELETE, DROP, ALTER, CREATE, TRUNCATE, EXEC, CALL, GRANT, REVOKE.\n3. Always add LIMIT 200 unless the question asks for aggregates (COUNT, SUM, AVG, etc.).\n4. For 'today': use DATE(created_at) = CURDATE() or order_date = CURDATE() as appropriate.\n5. For 'this month': YEAR(col) = YEAR(CURDATE()) AND MONTH(col) = MONTH(CURDATE()).\n6. POS sales are in `orders` (order_type='POS'). Credit sales are in `credit_orders`.\n7. 'All transactions today' = query customer_ledger, customer_payments, expense_vouchers, branch_petty_cash_transactions, purchase_payments_adnan — use UNION ALL.\n8. Use table aliases. JOIN correctly.\n9. If unanswerable with SELECT, output exactly: CANNOT_QUERY";

        $sql_user = "Convert this question to a single SQL SELECT:\n\"{$question}\"";

        $generated_sql = trim(callAI($sql_system, $sql_user, 400));

        // Safety: handle CANNOT_QUERY
        if ($generated_sql === 'CANNOT_QUERY' || empty($generated_sql)) {
            ob_end_clean();
            echo json_encode([
                'success'   => true,
                'action'    => 'db_query',
                'response'  => "I couldn't translate that into a database query. Try rephrasing — for example: *\"List all payments received today\"* or *\"Show overdue credit orders with customer names\"*.",
                'rows'      => [], 'columns' => [], 'sql' => '', 'row_count' => 0,
            ]); exit;
        }

        // Strip any markdown fences the model might have included
        $generated_sql = preg_replace('/```sql\s*|```\s*/i', '', $generated_sql);
        $generated_sql = trim($generated_sql, " \t\n\r;");

        // Hard safety: only allow SELECT / WITH (CTE)
        $first_word = strtoupper(strtok($generated_sql, " \t\n\r("));
        if (!in_array($first_word, ['SELECT', 'WITH'])) {
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => 'AI generated a non-SELECT query — blocked for safety.']); exit;
        }

        // Block dangerous keywords
        foreach (['INSERT','UPDATE','DELETE','DROP','ALTER','CREATE','TRUNCATE','EXEC','CALL','GRANT','REVOKE'] as $kw) {
            if (preg_match('/\b' . $kw . '\b/i', $generated_sql)) {
                ob_end_clean();
                echo json_encode(['success' => false, 'error' => "Blocked: forbidden keyword '{$kw}'."]); exit;
            }
        }

        // ── STEP 2: Execute query ─────────────────────────────────────────────
        $raw_results = $db->query($generated_sql)->results();
        $rows        = array_map(fn($r) => (array)$r, (array)$raw_results);
        $columns     = !empty($rows) ? array_keys($rows[0]) : [];
        $row_count   = count($rows);

        // ── STEP 3: AI Summary ────────────────────────────────────────────────
        $results_for_ai = $row_count > 0
            ? json_encode(array_slice($rows, 0, 50), JSON_UNESCAPED_UNICODE)
            : 'No rows returned.';

        $summary_system = "You are a business analyst for Ujjal Flour Mills ERP. Today is {$today}. Summarize database query results in plain, actionable business language. Use ৳ for money. Be concise — 2 to 5 sentences max. Highlight totals, patterns, or anything urgent.";

        $summary_user = "User asked: \"{$question}\"\n\nQuery returned {$row_count} rows:\n{$results_for_ai}\n\nWrite a brief natural-language summary.";

        $summary = callAI($summary_system, $summary_user, 300);

        ob_end_clean();
        echo json_encode([
            'success'      => true,
            'action'       => 'db_query',
            'response'     => $summary,
            'rows'         => $rows,
            'columns'      => $columns,
            'sql'          => $generated_sql,
            'row_count'    => $row_count,
            'generated_at' => date('Y-m-d H:i:s'),
        ]);

    } catch (Exception $e) {
        if (ob_get_level()) ob_end_clean();
        error_log("AI db_query error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Query error: ' . $e->getMessage()]);
    }
    exit;
}


// =============================================================================
// PRE-BUILT INSIGHT ACTIONS  (uses live ERP snapshot)
// =============================================================================
try {
    $sales_today = $db->query(
        "SELECT COUNT(*) as order_count, COALESCE(SUM(total_amount),0) as total_amount
         FROM credit_orders WHERE order_date = ? AND status NOT IN ('cancelled','rejected')",
        [$today]
    )->first();

    $sales_yesterday = $db->query(
        "SELECT COUNT(*) as order_count, COALESCE(SUM(total_amount),0) as total_amount
         FROM credit_orders WHERE order_date = ? AND status NOT IN ('cancelled','rejected')",
        [$yesterday]
    )->first();

    $sales_month = $db->query(
        "SELECT COALESCE(SUM(total_amount),0) as total_amount, COUNT(*) as order_count
         FROM credit_orders WHERE order_date >= ? AND status NOT IN ('cancelled','rejected')",
        [$month_start]
    )->first();

    $pos_today = $db->query(
        "SELECT COUNT(*) as order_count, COALESCE(SUM(total_amount),0) as total_amount
         FROM orders WHERE DATE(order_date) = ? AND order_status != 'Cancelled'",
        [$today]
    )->first();

    $pending_orders = $db->query(
        "SELECT COUNT(*) as cnt FROM credit_orders WHERE status IN ('pending_approval','draft')"
    )->first();

    $overdue_orders = $db->query(
        "SELECT COUNT(*) as cnt FROM credit_orders
         WHERE required_date < ? AND status NOT IN ('delivered','cancelled','rejected')",
        [$today]
    )->first();

    $total_receivable = $db->query(
        "SELECT COALESCE(SUM(current_balance),0) as total FROM customers WHERE status='active' AND customer_type='Credit'"
    )->first();

    $payments_today = $db->query(
        "SELECT COUNT(*) as cnt, COALESCE(SUM(amount),0) as total
         FROM customer_payments WHERE DATE(payment_date) = ?",
        [$today]
    )->first();

    $payments_month = $db->query(
        "SELECT COALESCE(SUM(amount),0) as total FROM customer_payments WHERE payment_date >= ?",
        [$month_start]
    )->first();

    $expenses_month = $db->query(
        "SELECT COALESCE(SUM(total_amount),0) as total FROM expense_vouchers
         WHERE expense_date >= ? AND status='approved'",
        [$month_start]
    )->first();

    $expenses_today = $db->query(
        "SELECT COALESCE(SUM(total_amount),0) as total FROM expense_vouchers
         WHERE expense_date = ? AND status='approved'",
        [$today]
    )->first();

    $top_debtors = $db->query(
        "SELECT name, business_name, current_balance, credit_limit
         FROM customers WHERE current_balance > 0 AND customer_type='Credit'
         ORDER BY current_balance DESC LIMIT 5"
    )->results();

    $over_limit_customers = $db->query(
        "SELECT COUNT(*) as cnt FROM customers
         WHERE customer_type='Credit' AND current_balance > credit_limit AND credit_limit > 0"
    )->first();

    $new_customers_month = $db->query(
        "SELECT COUNT(*) as cnt FROM customers WHERE created_at >= ?", [$month_start]
    )->first();

    $inventory_summary = $db->query(
        "SELECT pv.sku, pv.variant_name, SUM(i.quantity) as total_qty, b.name as branch_name
         FROM inventory i
         JOIN product_variants pv ON i.variant_id = pv.id
         JOIN branches b ON i.branch_id = b.id
         GROUP BY pv.id, b.id ORDER BY total_qty ASC LIMIT 8"
    )->results();

    $pending_pos = $db->query(
        "SELECT COUNT(*) as cnt, COALESCE(SUM(total_order_value),0) as total
         FROM purchase_orders_adnan WHERE po_status IN ('draft','approved')"
    )->first();

    $purchase_paid_month = $db->query(
        "SELECT COALESCE(SUM(amount_paid),0) as total
         FROM purchase_payments_adnan WHERE payment_date >= ? AND is_posted=1",
        [$month_start]
    )->first();

    $in_production  = $db->query("SELECT COUNT(*) as cnt FROM credit_orders WHERE status='in_production'")->first();
    $ready_to_ship  = $db->query("SELECT COUNT(*) as cnt FROM credit_orders WHERE status='ready_to_ship'")->first();
    $branches       = $db->query("SELECT name FROM branches WHERE status='active'")->results();
    $branch_names   = implode(', ', array_map(fn($b) => $b->name, (array)$branches));

    $debtors_text = implode("\n", array_map(function($d) {
        return "  - " . ($d->business_name ?: $d->name) . ": ৳" . number_format($d->current_balance, 0) . " (limit: ৳" . number_format($d->credit_limit, 0) . ")";
    }, (array)$top_debtors)) ?: "  - None";

    $inv_text = implode("\n", array_map(function($inv) {
        return "  - {$inv->variant_name} @ {$inv->branch_name}: {$inv->total_qty} bags";
    }, (array)$inventory_summary)) ?: "  - No data";

    $erp_context = "You are the AI Business Advisor for Ujjal Flour Mills ERP (multi-branch wheat flour manufacturer, Bangladesh).\nToday is {$today}. Currency BDT (৳). Branches: {$branch_names}.\n\n=== LIVE ERP SNAPSHOT ===\n[SALES]\n- Credit today: {$sales_today->order_count} orders | ৳{$sales_today->total_amount}\n- POS today: {$pos_today->order_count} orders | ৳{$pos_today->total_amount}\n- Yesterday credit: {$sales_yesterday->order_count} | ৳{$sales_yesterday->total_amount}\n- This month: {$sales_month->order_count} orders | ৳{$sales_month->total_amount}\n- Pending/draft: {$pending_orders->cnt} | Overdue: {$overdue_orders->cnt}\n- In production: {$in_production->cnt} | Ready to ship: {$ready_to_ship->cnt}\n[FINANCE]\n- Total A/R: ৳{$total_receivable->total}\n- Payments today: {$payments_today->cnt} | ৳{$payments_today->total}\n- Payments this month: ৳{$payments_month->total}\n- Expenses this month: ৳{$expenses_month->total}\n- Expenses today: ৳{$expenses_today->total}\n[CUSTOMERS]\n- Over credit limit: {$over_limit_customers->cnt} | New this month: {$new_customers_month->cnt}\n- Top debtors:\n{$debtors_text}\n[INVENTORY]\n{$inv_text}\n[PROCUREMENT]\n- Open POs: {$pending_pos->cnt} | ৳{$pending_pos->total}\n- Purchase paid this month: ৳{$purchase_paid_month->total}";

    $prompts = [
        'daily_brief'    => "Generate a **Daily Business Brief** in markdown.\n### 🌅 Today's Highlights\n### ⚠️ Urgent Actions\n### 💰 Cash & Collections\n### 📦 Operations Pulse\n### 💡 One Strategic Tip\nUnder 350 words. Specific numbers. Name customers where relevant.",
        'cash_flow'      => "Analyze cash flow: collection efficiency, expense trends, which customers to prioritise, procurement obligations, liquidity assessment. 3 concrete recommendations with exact numbers.",
        'credit_risk'    => "Credit risk report: name over-limit customers ({$over_limit_customers->cnt}), A/R health, flags from debtors list, recommended action per customer, credit health score 1-10 with justification.",
        'operations'     => "Operations briefing: production pipeline ({$in_production->cnt} producing, {$ready_to_ship->cnt} ready, {$overdue_orders->cnt} overdue), inventory gaps, procurement, factory priorities. Speak as an ops director.",
        'sales_analysis' => "Sales analysis: today vs yesterday % change, MTD trend, month-end projection, causes of {$pending_orders->cnt} pending orders, 2 practical suggestions to boost this week.",
        'custom'         => "Answer this using the live ERP data: \"{$question}\". Be concise and actionable. If data is insufficient, say so clearly.",
    ];

    if (!isset($prompts[$action])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'Invalid action']); exit;
    }

    $ai_response = callAI($erp_context, $prompts[$action], 800);

    ob_end_clean();
    echo json_encode([
        'success'  => true,
        'action'   => $action,
        'response' => $ai_response,
        'snapshot' => [
            'sales_today'          => (float)$sales_today->total_amount + (float)$pos_today->total_amount,
            'orders_today'         => (int)$sales_today->order_count + (int)$pos_today->order_count,
            'receivables'          => (float)$total_receivable->total,
            'payments_today'       => (float)$payments_today->total,
            'pending_orders'       => (int)$pending_orders->cnt,
            'overdue_orders'       => (int)$overdue_orders->cnt,
            'in_production'        => (int)$in_production->cnt,
            'ready_to_ship'        => (int)$ready_to_ship->cnt,
            'over_limit_customers' => (int)$over_limit_customers->cnt,
        ],
        'generated_at' => date('Y-m-d H:i:s'),
    ]);

} catch (Exception $e) {
    if (ob_get_level()) ob_end_clean();
    error_log("AI Advisor Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;


// =============================================================================
// HELPERS
// =============================================================================
function getSchemaPrompt(): string
{
    return <<<'SCHEMA'
TABLES (name: columns):

bank_accounts: id, bank_name, account_name, account_number, current_balance, status
branches: id, name, code, status
branch_petty_cash_transactions: id, branch_id, account_id, transaction_date(datetime), transaction_type(cash_in|cash_out|transfer_in|transfer_out|adjustment|opening_balance), amount, balance_after, reference_type, reference_id, description, created_by_user_id
chart_of_accounts: id, account_number, name, account_type, branch_id, normal_balance(Debit|Credit), is_active
credit_orders: id, order_number, customer_id, order_date(date), required_date(date), order_type(credit|advance_payment), subtotal, discount_amount, total_amount, advance_paid, balance_due, amount_paid, status(draft|pending_approval|approved|escalated|rejected|in_production|produced|ready_to_ship|shipped|delivered|cancelled), assigned_branch_id, priority(low|normal|high|urgent), created_by_user_id, approved_by_user_id, approved_at, created_at, total_weight_kg
credit_order_items: id, order_id, product_id, variant_id, quantity, unit_price, line_total
credit_order_workflow: id, order_id, from_status, to_status, performed_by_user_id, comments, performed_at
customers: id, customer_type(Credit|POS), name, business_name, phone_number, credit_limit, initial_due, current_balance, status(active|inactive|blacklisted), created_at
customer_ledger: id, customer_id, transaction_date(date), transaction_type(invoice|payment|advance_payment|adjustment|opening_balance), reference_type, reference_id, invoice_number, description, debit_amount, credit_amount, balance_after, created_by_user_id, created_at
customer_payments: id, payment_number, receipt_number, customer_id, payment_date(date), amount, payment_method(Cash|Bank|Mobile Banking|Cheque), payment_type(advance|invoice_payment|partial_payment), bank_account_id, cash_account_id, allocation_status, allocated_amount, reference_number, notes, created_by_user_id, branch_id, created_at
debit_vouchers: id, voucher_number, voucher_date(date), amount, paid_to, description, branch_id, status(draft|approved|cancelled), created_at
employees: id, user_id, first_name, last_name, position_id, hire_date, base_salary, status(active|on_leave|terminated), branch_id
expense_categories: id, category_name, is_active
expense_vouchers: id, voucher_number, expense_date(date), category_id, subcategory_id, handled_by_person, total_amount, remarks, payment_method(bank|cash), branch_id, status(draft|approved|rejected|cancelled), approved_by_user_id, created_by_user_id, created_at
goods_received_adnan: id, grn_number, purchase_order_id, grn_date(date), supplier_id, supplier_name, quantity_received_kg, unit_price_per_kg, total_value, expected_quantity, variance_percentage, grn_status(draft|verified|posted|cancelled), unload_point_branch_id, created_at
inventory: id, variant_id, branch_id, quantity
journal_entries: id, transaction_date(date), description, related_document_id, related_document_type, created_by_user_id, created_at
orders: id, order_number, branch_id, customer_id, order_date(datetime), order_type(POS|Credit|Delivery), subtotal, total_amount, payment_method, payment_status(Paid|Partial|Unpaid|Refunded), order_status(Completed|Pending|Cancelled|Refunded), created_by_user_id, created_at
order_items: id, order_id, variant_id, quantity, unit_price, total_amount
payment_allocations: id, payment_id, order_id, allocated_amount, allocation_date
production_schedule: id, order_id, branch_id, scheduled_date, production_started_at, production_completed_at, status(pending|in_progress|completed|delayed), priority_order
products: id, base_name, base_sku, category, status
product_variants: id, product_id, grade, weight_variant, sku, variant_name, status, weight_kg
purchase_orders_adnan: id, po_number, po_date(date), supplier_id, supplier_name, branch_id, wheat_origin, quantity_kg, unit_price_per_kg, total_order_value, total_received_qty, total_paid, balance_payable, po_status(draft|approved|partial|completed|cancelled), payment_status(unpaid|partial|paid|overpaid), created_by_user_id, created_at
purchase_payments_adnan: id, payment_voucher_number, payment_date(date), purchase_order_id, po_number, supplier_id, supplier_name, amount_paid, payment_method(bank|cash|cheque), bank_name, payment_type(advance|regular|final), is_posted(0|1), created_by_user_id, created_at
suppliers: id, supplier_code, company_name, contact_person, phone, country, current_balance, status(active|inactive|blocked)
supplier_ledger: id, supplier_id, transaction_date(date), transaction_type(purchase|payment|debit_note|credit_note|opening_balance), reference_number, debit_amount, credit_amount, balance, description, created_at
supplier_payments: id, payment_number, supplier_id, payment_date(date), payment_method, amount, status(pending|cleared|bounced|cancelled), created_at
transaction_lines: id, journal_entry_id, account_id, debit_amount, credit_amount, description
users: id, display_name, email, role, status, last_login, created_at
vehicles: id, vehicle_number, vehicle_type, category, status, assigned_branch_id
trip_assignments: id, vehicle_id, driver_id, trip_date(date), trip_type, total_orders, total_weight_kg, status, created_at
fuel_logs: id, vehicle_id, fuel_date(date), fuel_type, quantity_liters, total_cost, station_name, created_at
drivers: id, driver_name, phone_number, status, assigned_branch_id
wheat_shipments: id, shipment_number, vessel_name, origin_country, quantity_tons, wheat_type, supplier_name, departure_date, expected_arrival, actual_arrival, status, total_cost, payment_status
eod_summary: id, branch_id, eod_date(date), total_orders, gross_sales, net_sales, cash_sales, actual_cash, created_at

KEY JOINS:
credit_orders.customer_id → customers.id
credit_order_items.order_id → credit_orders.id  |  credit_order_items.variant_id → product_variants.id
product_variants.product_id → products.id
customer_payments.customer_id → customers.id
customer_ledger.customer_id → customers.id
orders.branch_id → branches.id
expense_vouchers.category_id → expense_categories.id
purchase_orders_adnan.supplier_id → suppliers.id
purchase_payments_adnan.purchase_order_id → purchase_orders_adnan.id
inventory.variant_id → product_variants.id  |  inventory.branch_id → branches.id
transaction_lines.journal_entry_id → journal_entries.id
production_schedule.order_id → credit_orders.id
SCHEMA;
}

function callAI(string $system, string $user, int $max_tokens = 800): string
{
    $errors = [];

    // ── 1. DeepSeek  (PRIMARY — OpenAI-compatible, very generous free tier) ───
    if (defined('DEEPSEEK_API_KEY') && DEEPSEEK_API_KEY) {
        foreach (['deepseek-chat', 'deepseek-reasoner'] as $model) {
            try {
                $res = httpPost('https://api.deepseek.com/v1/chat/completions', json_encode([
                    'model'       => $model,
                    'max_tokens'  => $max_tokens,
                    'temperature' => 0.1,
                    'messages'    => [['role'=>'system','content'=>$system],['role'=>'user','content'=>$user]],
                ]), ['Authorization: Bearer '.DEEPSEEK_API_KEY, 'Content-Type: application/json']);
                $d = json_decode($res, true);
                if (!empty($d['choices'][0]['message']['content'])) return $d['choices'][0]['message']['content'];
            } catch (Exception $e) {
                $msg = $e->getMessage();
                if (strpos($msg,'429')!==false || strpos($msg,'402')!==false || strpos($msg,'quota')!==false) {
                    $errors[]="DeepSeek/{$model}: skipped"; continue;
                }
                $errors[]="DeepSeek/{$model}: {$msg}"; break;
            }
        }
    }

    // ── 2. Groq — 5 live models, each with own TPD pool ──────────────────────
    $groq_models = [
        'llama-3.3-70b-versatile',
        'llama-3.1-8b-instant',
        'llama3-8b-8192',
        'gemma2-9b-it',
        'mixtral-8x7b-32768',
    ];
    if (defined('GROQ_API_KEY') && GROQ_API_KEY) {
        foreach ($groq_models as $model) {
            try {
                $res = httpPost('https://api.groq.com/openai/v1/chat/completions', json_encode([
                    'model'       => $model,
                    'max_tokens'  => $max_tokens,
                    'temperature' => 0.1,
                    'messages'    => [['role'=>'system','content'=>$system],['role'=>'user','content'=>$user]],
                ]), ['Authorization: Bearer '.GROQ_API_KEY, 'Content-Type: application/json']);
                $d = json_decode($res, true);
                if (!empty($d['choices'][0]['message']['content'])) return $d['choices'][0]['message']['content'];
            } catch (Exception $e) {
                $msg = $e->getMessage();
                if (strpos($msg,'429')!==false || strpos($msg,'rate_limit')!==false
                    || strpos($msg,'decommissioned')!==false || strpos($msg,'400')!==false) {
                    $errors[]="Groq/{$model}: skipped"; continue;
                }
                $errors[]="Groq/{$model}: {$msg}"; break;
            }
        }
    }

    // ── 3. Gemini — 2 live models ─────────────────────────────────────────────
    if (defined('GEMINI_API_KEY') && GEMINI_API_KEY) {
        foreach (['gemini-2.0-flash','gemini-2.0-flash-lite'] as $model) {
            try {
                $res = httpPost(
                    "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=".GEMINI_API_KEY,
                    json_encode(['contents'=>[['parts'=>[['text'=>$system."\n\n".$user]]]],'generationConfig'=>['maxOutputTokens'=>$max_tokens,'temperature'=>0.1]]),
                    ['Content-Type: application/json']
                );
                $d = json_decode($res, true);
                $text = $d['candidates'][0]['content']['parts'][0]['text'] ?? '';
                if ($text) return $text;
            } catch (Exception $e) { $errors[]="Gemini/{$model}: ".substr($e->getMessage(),0,60); continue; }
        }
    }

    // ── 4. Anthropic (paid fallback) ──────────────────────────────────────────
    if (defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY) {
        try {
            $res = httpPost('https://api.anthropic.com/v1/messages', json_encode([
                'model'      => 'claude-haiku-4-5-20251001',
                'max_tokens' => $max_tokens,
                'system'     => $system,
                'messages'   => [['role'=>'user','content'=>$user]],
            ]), ['x-api-key: '.ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01', 'Content-Type: application/json']);
            $d = json_decode($res, true);
            if (!empty($d['content'][0]['text'])) return $d['content'][0]['text'];
        } catch (Exception $e) { $errors[]="Anthropic: ".$e->getMessage(); }
    }

    throw new Exception("All AI providers failed. Please check API keys in config.php.\n".implode("\n",$errors));
}

function httpPost(string $url, string $body, array $headers): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) { $e = curl_error($ch); curl_close($ch); throw new Exception("cURL: $e"); }
    curl_close($ch);
    if ($code >= 400) throw new Exception("HTTP {$code}: {$res}");
    return $res;
}
?>