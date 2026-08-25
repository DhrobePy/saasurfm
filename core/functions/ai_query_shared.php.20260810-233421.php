<?php
/**
 * Shared natural-language → SQL → answer engine.
 *
 * Extracted 10 Aug 2026 from admin/ai_dashboard_advisor.php's inline "db_query"
 * action so the exact same, already-safety-checked pipeline can be reused by a
 * second consumer (the Telegram /ask webhook) without duplicating the AI
 * fallback chain or the SQL safety guardrails in two places. Both entry
 * points call the one function at the bottom of this file,
 * answerNaturalLanguageQuery() — admin/ai_dashboard_advisor.php's own
 * definitions of these functions were removed in favor of require'ing this
 * file, so there is exactly one copy of this logic in the codebase.
 *
 * function_exists() guards make this safe to require_once from multiple
 * entry points in the same request without a redeclaration fatal.
 */

if (!function_exists('matchCannedQuery')) {
/**
 * Zero-AI fallback for the NL→SQL step — keyword-matches the question against
 * the same 5 example queries the UI already suggests (qExamples in
 * admin/index.php), so the most common lookups still work with every AI
 * provider down. Returns null (not a guess) if nothing matches confidently —
 * a wrong canned query would be worse than an honest "couldn't answer".
 */
function matchCannedQuery(string $q): ?array
{
    $q = strtolower($q);
    $has = fn(...$words) => array_reduce($words, fn($carry, $w) => $carry && strpos($q, $w) !== false, true);
    $any = fn(...$words) => array_reduce($words, fn($carry, $w) => $carry || strpos($q, $w) !== false, false);

    if ($any('transaction') && $any('today')) {
        return ['label' => 'Transactions made today', 'sql' =>
            "SELECT 'Payment' AS type, payment_date AS date, amount, payment_number AS reference FROM customer_payments WHERE payment_date = CURDATE()
             UNION ALL
             SELECT 'Expense' AS type, expense_date AS date, total_amount AS amount, voucher_number AS reference FROM expense_vouchers WHERE expense_date = CURDATE() AND status='approved'
             UNION ALL
             SELECT 'Purchase Payment' AS type, payment_date AS date, amount_paid AS amount, payment_voucher_number AS reference FROM purchase_payments_adnan WHERE payment_date = CURDATE() AND is_posted=1
             ORDER BY date DESC LIMIT 200"];
    }
    if ($any('payment') && $any('collect') && $any('month')) {
        return ['label' => 'Payments collected this month', 'sql' =>
            "SELECT payment_number, payment_date, amount, payment_method, reference_number FROM customer_payments WHERE payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') ORDER BY payment_date DESC LIMIT 200"];
    }
    if ($has('credit', 'limit') || ($any('over') && $any('limit'))) {
        return ['label' => 'Customers over their credit limit', 'sql' =>
            "SELECT name, business_name, current_balance, credit_limit FROM customers WHERE customer_type='Credit' AND credit_limit > 0 AND current_balance > credit_limit ORDER BY current_balance DESC LIMIT 200"];
    }
    if ($any('overdue') && $any('order')) {
        return ['label' => 'Overdue credit orders with customer names', 'sql' =>
            "SELECT co.order_number, c.name AS customer_name, co.required_date, co.total_amount, co.status
             FROM credit_orders co JOIN customers c ON co.customer_id = c.id
             WHERE co.required_date < CURDATE() AND co.status NOT IN ('delivered','cancelled','rejected')
             ORDER BY co.required_date ASC LIMIT 200"];
    }
    if ($any('pending') && $any('purchase')) {
        return ['label' => 'Pending purchase orders', 'sql' =>
            "SELECT po_number, supplier_name, po_date, total_order_value, po_status FROM purchase_orders_adnan WHERE po_status IN ('draft','approved') ORDER BY po_date DESC LIMIT 200"];
    }
    return null;
}
}

if (!function_exists('getSchemaPrompt')) {
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
product_variants: id, product_id, grade, weight_variant, sku, unit_of_measure, status, weight_kg
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
}

if (!function_exists('httpPost')) {
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
}

if (!function_exists('callAI')) {
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

    // ── 2. OpenRouter — aggregates many providers, several $0 ":free" models ──
    $openrouter_models = [
        'meta-llama/llama-3.3-70b-instruct:free',
        'deepseek/deepseek-chat-v3.1:free',
        'qwen/qwen-2.5-72b-instruct:free',
        'google/gemini-2.0-flash-exp:free',
    ];
    if (defined('OPENROUTER_API_KEY') && OPENROUTER_API_KEY) {
        foreach ($openrouter_models as $model) {
            try {
                $res = httpPost('https://openrouter.ai/api/v1/chat/completions', json_encode([
                    'model'       => $model,
                    'max_tokens'  => $max_tokens,
                    'temperature' => 0.1,
                    'messages'    => [['role'=>'system','content'=>$system],['role'=>'user','content'=>$user]],
                ]), ['Authorization: Bearer '.OPENROUTER_API_KEY, 'Content-Type: application/json', 'HTTP-Referer: https://saas.ujjalfm.com', 'X-Title: Ujjal ERP AI Advisor']);
                $d = json_decode($res, true);
                if (!empty($d['choices'][0]['message']['content'])) return $d['choices'][0]['message']['content'];
            } catch (Exception $e) {
                $msg = $e->getMessage();
                if (strpos($msg,'429')!==false || strpos($msg,'402')!==false || strpos($msg,'404')!==false
                    || strpos($msg,'rate')!==false || strpos($msg,'quota')!==false) {
                    $errors[]="OpenRouter/{$model}: skipped"; continue;
                }
                $errors[]="OpenRouter/{$model}: {$msg}"; break;
            }
        }
    }

    // ── 3. Groq — 5 live models, each with own TPD pool ──────────────────────
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

    // ── 4. Cerebras — free tier, OpenAI-compatible, very fast inference ───────
    $cerebras_models = ['llama-3.3-70b', 'llama3.1-8b'];
    if (defined('CEREBRAS_API_KEY') && CEREBRAS_API_KEY) {
        foreach ($cerebras_models as $model) {
            try {
                $res = httpPost('https://api.cerebras.ai/v1/chat/completions', json_encode([
                    'model'       => $model,
                    'max_tokens'  => $max_tokens,
                    'temperature' => 0.1,
                    'messages'    => [['role'=>'system','content'=>$system],['role'=>'user','content'=>$user]],
                ]), ['Authorization: Bearer '.CEREBRAS_API_KEY, 'Content-Type: application/json']);
                $d = json_decode($res, true);
                if (!empty($d['choices'][0]['message']['content'])) return $d['choices'][0]['message']['content'];
            } catch (Exception $e) {
                $msg = $e->getMessage();
                if (strpos($msg,'429')!==false || strpos($msg,'quota')!==false || strpos($msg,'404')!==false) {
                    $errors[]="Cerebras/{$model}: skipped"; continue;
                }
                $errors[]="Cerebras/{$model}: {$msg}"; break;
            }
        }
    }

    // ── 5. Gemini — 3 live models ─────────────────────────────────────────────
    if (defined('GEMINI_API_KEY') && GEMINI_API_KEY) {
        foreach (['gemini-2.0-flash','gemini-2.0-flash-001','gemini-2.0-flash-lite'] as $model) {
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

    // ── 6. Anthropic (paid fallback) ──────────────────────────────────────────
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
}

if (!function_exists('answerNaturalLanguageQuery')) {
/**
 * The full NL → SQL → execute → summary pipeline, as a plain function instead
 * of an inline action handler that echoes+exits — so it can be called from
 * anywhere (the admin dashboard's AJAX handler, the Telegram /ask webhook)
 * and just get a result back. Same safety guarantees as before: SELECT/WITH
 * only, forbidden-keyword block, hard row cap. Never throws — a hard failure
 * comes back as success=false with a message, so callers don't need their own
 * try/catch around AI/DB flakiness.
 */
function answerNaturalLanguageQuery(string $question): array
{
    global $db;
    $question = trim($question);
    $today = date('Y-m-d');

    if ($question === '') {
        return ['success' => false, 'error' => 'Please enter a question.'];
    }

    try {
        $schema = getSchemaPrompt();

        $sql_system = "You are a MariaDB 11.4 SQL expert for Ujjal Flour Mills ERP (Bangladesh). Today is {$today}.\n\nDATABASE SCHEMA:\n{$schema}\n\nRULES — follow every one:\n1. Output ONLY a single raw SQL SELECT statement. No markdown, no backticks, no explanation, no semicolon.\n2. NEVER use INSERT, UPDATE, DELETE, DROP, ALTER, CREATE, TRUNCATE, EXEC, CALL, GRANT, REVOKE.\n3. Always add LIMIT 200 unless the question asks for aggregates (COUNT, SUM, AVG, etc.).\n4. For 'today': use DATE(created_at) = CURDATE() or order_date = CURDATE() as appropriate.\n5. For 'this month': YEAR(col) = YEAR(CURDATE()) AND MONTH(col) = MONTH(CURDATE()).\n6. POS sales are in `orders` (order_type='POS'). Credit sales are in `credit_orders`.\n7. 'All transactions today' = query customer_ledger, customer_payments, expense_vouchers, branch_petty_cash_transactions, purchase_payments_adnan — use UNION ALL.\n8. Use table aliases. JOIN correctly.\n9. If unanswerable with SELECT, output exactly: CANNOT_QUERY";

        $sql_user = "Convert this question to a single SQL SELECT:\n\"{$question}\"";

        $sql_source = 'ai';
        try {
            $generated_sql = trim(callAI($sql_system, $sql_user, 400));
        } catch (Exception $e) {
            error_log("answerNaturalLanguageQuery (NL→SQL) failed, trying canned match: " . $e->getMessage());
            $canned = matchCannedQuery($question);
            if ($canned) {
                $generated_sql = $canned['sql'];
                $sql_source = 'local';
            } else {
                return [
                    'success' => true, 'response' => "AI translation is temporarily unavailable and I couldn't match this to a known query pattern. Try one of the example questions, or a preset Insight — those work without AI right now.",
                    'rows' => [], 'columns' => [], 'sql' => '', 'row_count' => 0, 'source' => 'local',
                ];
            }
        }

        if ($generated_sql === 'CANNOT_QUERY' || empty($generated_sql)) {
            return [
                'success' => true, 'response' => "I couldn't translate that into a database query. Try rephrasing — for example: \"List all payments received today\" or \"Show overdue credit orders with customer names\".",
                'rows' => [], 'columns' => [], 'sql' => '', 'row_count' => 0, 'source' => $sql_source,
            ];
        }

        $generated_sql = preg_replace('/```sql\s*|```\s*/i', '', $generated_sql);
        $generated_sql = trim($generated_sql, " \t\n\r;");

        // Hard safety: only allow SELECT / WITH (CTE)
        $first_word = strtoupper(strtok($generated_sql, " \t\n\r("));
        if (!in_array($first_word, ['SELECT', 'WITH'])) {
            return ['success' => false, 'error' => 'AI generated a non-SELECT query — blocked for safety.'];
        }

        // Block dangerous keywords
        foreach (['INSERT','UPDATE','DELETE','DROP','ALTER','CREATE','TRUNCATE','EXEC','CALL','GRANT','REVOKE'] as $kw) {
            if (preg_match('/\b' . $kw . '\b/i', $generated_sql)) {
                return ['success' => false, 'error' => "Blocked: forbidden keyword '{$kw}'."];
            }
        }

        // Hard row cap regardless of what the AI/canned SQL specified — a second,
        // server-side guarantee on top of the prompt-requested LIMIT 200, since a
        // Telegram-triggered query is a less-trusted context than the logged-in
        // admin dashboard this pipeline was originally built for.
        if (!preg_match('/\bLIMIT\s+\d+/i', $generated_sql)) {
            $generated_sql .= ' LIMIT 200';
        }

        $raw_results = $db->query($generated_sql)->results();
        $rows        = array_map(fn($r) => (array)$r, (array)$raw_results);
        $columns     = !empty($rows) ? array_keys($rows[0]) : [];
        $row_count   = count($rows);

        $results_for_ai = $row_count > 0
            ? json_encode(array_slice($rows, 0, 50), JSON_UNESCAPED_UNICODE)
            : 'No rows returned.';

        $summary_system = "You are a business analyst for Ujjal Flour Mills ERP. Today is {$today}. Summarize database query results in plain, actionable business language. Use ৳ for money. Be concise — 2 to 5 sentences max. Highlight totals, patterns, or anything urgent.";
        $summary_user = "User asked: \"{$question}\"\n\nQuery returned {$row_count} rows:\n{$results_for_ai}\n\nWrite a brief natural-language summary.";

        $summary_source = $sql_source;
        try {
            $summary = callAI($summary_system, $summary_user, 300);
        } catch (Exception $e) {
            $summary = $row_count > 0
                ? "Found {$row_count} matching row(s) — see the table below for details."
                : "No matching rows found.";
            $summary_source = 'local';
        }

        return [
            'success'      => true,
            'response'     => $summary,
            'rows'         => $rows,
            'columns'      => $columns,
            'sql'          => $generated_sql,
            'row_count'    => $row_count,
            'source'       => $summary_source,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    } catch (Exception $e) {
        error_log("answerNaturalLanguageQuery error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Query error: ' . $e->getMessage()];
    }
}
}
