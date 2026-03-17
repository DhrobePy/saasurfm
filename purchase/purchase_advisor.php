<?php
/**
 * purchase_advisor.php  —  Purchase Module AI Advisor + Text-to-SQL
 * Place at: /purchase/purchase_advisor.php
 *
 * Actions:
 *   procurement_brief | payment_urgency | supplier_risk | origin_analysis | cash_planning
 *   custom   → free-form question with live context
 *   db_query → NL → SQL (AI) → execute → NL summary
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

$action   = $data['action']   ?? 'procurement_brief';
$question = trim($data['question'] ?? '');
$today    = date('Y-m-d');
$month_start = date('Y-m-01');


// =============================================================================
// DB-QUERY ACTION  (Text-to-SQL, two-step)
// =============================================================================
if ($action === 'db_query') {
    try {
        if (empty($question)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => 'Please enter a question.']); exit;
        }

        $schema = getPurchaseSchemaPrompt();

        // STEP 1: NL → SQL
        $sql_system = "You are a MariaDB 11.4 SQL expert for Ujjal Flour Mills ERP (Bangladesh). Today is {$today}.\n\nPURCHASE MODULE SCHEMA:\n{$schema}\n\nRULES:\n1. Output ONLY a single raw SQL SELECT. No markdown, no backticks, no explanation, no semicolon.\n2. NEVER use INSERT, UPDATE, DELETE, DROP, ALTER, CREATE, TRUNCATE, EXEC, CALL.\n3. Add LIMIT 200 unless the question asks for aggregates.\n4. For 'today': po_date = CURDATE() or payment_date = CURDATE().\n5. For 'this month': YEAR(col)=YEAR(CURDATE()) AND MONTH(col)=MONTH(CURDATE()).\n6. Payment amounts live in purchase_payments_adnan.amount_paid (filter is_posted=1 for posted).\n7. GRN quantities: goods_received_adnan.expected_quantity (basis for payment) vs quantity_received_kg (actual).\n8. Supplier name: suppliers.company_name. Join via purchase_orders_adnan.supplier_id=suppliers.id.\n9. Use table aliases.\n10. If unanswerable with SELECT, output exactly: CANNOT_QUERY";

        $sql_user = "Convert this question to a single SQL SELECT:\n\"{$question}\"";

        $generated_sql = trim(callAI($sql_system, $sql_user, 400));

        if ($generated_sql === 'CANNOT_QUERY' || empty($generated_sql)) {
            ob_end_clean();
            echo json_encode([
                'success'  => true, 'action' => 'db_query',
                'response' => "I couldn't translate that into a database query. Try rephrasing — e.g. *\"List all payments made this month\"* or *\"Show in-progress purchase orders with supplier names\"*.",
                'rows' => [], 'columns' => [], 'sql' => '', 'row_count' => 0,
            ]); exit;
        }

        $generated_sql = preg_replace('/```sql\s*|```\s*/i', '', $generated_sql);
        $generated_sql = trim($generated_sql, " \t\n\r;");

        $first_word = strtoupper(strtok($generated_sql, " \t\n\r("));
        if (!in_array($first_word, ['SELECT', 'WITH'])) {
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => 'AI generated a non-SELECT query — blocked for safety.']); exit;
        }

        foreach (['INSERT','UPDATE','DELETE','DROP','ALTER','CREATE','TRUNCATE','EXEC','CALL','GRANT','REVOKE'] as $kw) {
            if (preg_match('/\b'.$kw.'\b/i', $generated_sql)) {
                ob_end_clean();
                echo json_encode(['success' => false, 'error' => "Blocked: forbidden keyword '{$kw}'."]); exit;
            }
        }

        // STEP 2: Execute
        $raw_results = $db->query($generated_sql)->results();
        $rows        = array_map(fn($r) => (array)$r, (array)$raw_results);
        $columns     = !empty($rows) ? array_keys($rows[0]) : [];
        $row_count   = count($rows);

        // STEP 3: AI Summary
        $results_for_ai = $row_count > 0
            ? json_encode(array_slice($rows, 0, 50), JSON_UNESCAPED_UNICODE)
            : 'No rows returned.';

        $summary_system = "You are a procurement analyst for Ujjal Flour Mills ERP. Today is {$today}. Summarise query results in plain, actionable business language. Use ৳ for money. Be concise — 2 to 5 sentences. Highlight totals, supplier names, or anything urgent.";
        $summary_user   = "User asked: \"{$question}\"\n\nQuery returned {$row_count} rows:\n{$results_for_ai}\n\nWrite a brief natural-language summary.";
        $summary        = callAI($summary_system, $summary_user, 300);

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
        error_log("Purchase AI db_query error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Query error: ' . $e->getMessage()]);
    }
    exit;
}


// =============================================================================
// PRE-BUILT INSIGHT ACTIONS  (live procurement snapshot)
// =============================================================================
try {
    // ── In-progress POs ──
    $in_progress = $db->query(
        "SELECT COUNT(*) as cnt, COALESCE(SUM(total_order_value),0) as total_value
         FROM purchase_orders_adnan
         WHERE delivery_status NOT IN ('closed','completed') AND po_status != 'cancelled'"
    )->first();

    // ── Pending deliveries ──
    $pending_del = $db->query(
        "SELECT COUNT(*) as cnt FROM purchase_orders_adnan
         WHERE delivery_status IN ('pending','partial') AND po_status != 'cancelled'"
    )->first();

    // ── Payment summary ──
    $pay_total = $db->query(
        "SELECT COALESCE(SUM(amount_paid),0) as total FROM purchase_payments_adnan WHERE is_posted=1"
    )->first();

    $pay_month = $db->query(
        "SELECT COALESCE(SUM(amount_paid),0) as total, COUNT(*) as cnt
         FROM purchase_payments_adnan WHERE is_posted=1 AND payment_date >= ?", [$month_start]
    )->first();

    $pay_today = $db->query(
        "SELECT COALESCE(SUM(amount_paid),0) as total, COUNT(*) as cnt
         FROM purchase_payments_adnan WHERE is_posted=1 AND payment_date = ?", [$today]
    )->first();

    // ── Advance payments ──
    $advances = $db->query(
        "SELECT COALESCE(SUM(amount_paid),0) as total
         FROM purchase_payments_adnan WHERE payment_type='advance' AND is_posted=1"
    )->first();

    // ── Expected payable (GRN-based) ──
    $exp_payable = $db->query(
        "SELECT COALESCE(SUM(grn.expected_quantity * po.unit_price_per_kg),0) as total
         FROM goods_received_adnan grn
         JOIN purchase_orders_adnan po ON grn.purchase_order_id = po.id
         WHERE grn.grn_status != 'cancelled' AND po.po_status != 'cancelled'"
    )->first();

    $balance_due = max(0, $exp_payable->total - $pay_total->total);
    $pay_rate    = $exp_payable->total > 0
                   ? round($pay_total->total / $exp_payable->total * 100, 1) : 0;

    // ── Top supplier dues (GRN expected_quantity basis — correct payment basis) ──
    $supplier_dues = $db->query(
        "SELECT s.company_name,
                COUNT(DISTINCT po.id) as po_count,
                COALESCE(SUM(grn_exp.expected_payable),0) as expected_payable,
                COALESCE(po_pay.total_paid_supplier,0) as total_paid,
                GREATEST(0, COALESCE(SUM(grn_exp.expected_payable),0) - COALESCE(po_pay.total_paid_supplier,0)) as balance_due,
                COALESCE(SUM(CASE WHEN pmt.payment_type='advance' THEN pmt.amount_paid ELSE 0 END),0) as advance_paid
         FROM purchase_orders_adnan po
         JOIN suppliers s ON po.supplier_id = s.id
         -- Expected payable per PO = SUM(expected_quantity * unit_price_per_kg) from GRNs
         LEFT JOIN (
             SELECT grn.purchase_order_id,
                    SUM(grn.expected_quantity * po2.unit_price_per_kg) as expected_payable
             FROM goods_received_adnan grn
             JOIN purchase_orders_adnan po2 ON grn.purchase_order_id = po2.id
             WHERE grn.grn_status != 'cancelled'
             GROUP BY grn.purchase_order_id
         ) grn_exp ON grn_exp.purchase_order_id = po.id
         -- Total posted payments per supplier
         LEFT JOIN (
             SELECT supplier_id, SUM(amount_paid) as total_paid_supplier
             FROM purchase_payments_adnan
             WHERE is_posted = 1
             GROUP BY supplier_id
         ) po_pay ON po_pay.supplier_id = s.id
         LEFT JOIN purchase_payments_adnan pmt ON pmt.purchase_order_id = po.id AND pmt.is_posted = 1
         WHERE po.delivery_status NOT IN ('closed','completed') AND po.po_status != 'cancelled'
         GROUP BY s.id, s.company_name, po_pay.total_paid_supplier
         HAVING balance_due > 0
         ORDER BY balance_due DESC
         LIMIT 8"
    )->results();

    // ── Origin breakdown ──
    $origins = $db->query(
        "SELECT wheat_origin,
                COUNT(*) as order_count,
                SUM(quantity_kg) as total_qty,
                SUM(total_order_value) as total_value,
                AVG(unit_price_per_kg) as avg_price
         FROM purchase_orders_adnan
         WHERE po_status != 'cancelled' AND delivery_status != 'closed'
         GROUP BY wheat_origin ORDER BY total_value DESC"
    )->results();

    // ── Recent GRN activity ──
    $grn_month = $db->query(
        "SELECT COUNT(*) as cnt, COALESCE(SUM(quantity_received_kg),0) as total_kg
         FROM goods_received_adnan
         WHERE grn_date >= ? AND grn_status != 'cancelled'", [$month_start]
    )->first();

    // ── Draft POs ──
    $draft_pos = $db->query(
        "SELECT COUNT(*) as cnt FROM purchase_orders_adnan WHERE po_status='draft'"
    )->first();

    // Build text blocks for AI context
    $dues_text = implode("\n", array_map(fn($d) =>
        "  - {$d->company_name}: ৳".number_format($d->balance_due,0)." due | ".
        $d->po_count." PO(s) | ৳".number_format($d->advance_paid,0)." advance",
    (array)$supplier_dues)) ?: "  - None";

    $origins_text = implode("\n", array_map(fn($o) =>
        "  - {$o->wheat_origin}: {$o->order_count} POs | ".
        number_format($o->total_qty/1000, 1)."MT | ৳".round($o->avg_price,2)."/kg avg | ".
        "৳".number_format($o->total_value/1000000, 2)."M total",
    (array)$origins)) ?: "  - No data";

    $context = "You are the Procurement AI Advisor for Ujjal Flour Mills (wheat flour manufacturer, Bangladesh). Today: {$today}. Currency: ৳ BDT.\n\n=== LIVE PROCUREMENT SNAPSHOT ===\n[PURCHASE ORDERS]\n- In-progress POs: {$in_progress->cnt} | Total value: ৳".number_format($in_progress->total_value/1000000,2)."M\n- Pending/partial deliveries: {$pending_del->cnt}\n- Draft POs awaiting approval: {$draft_pos->cnt}\n- GRNs received this month: {$grn_month->cnt} | ".number_format($grn_month->total_kg/1000,1)."MT\n\n[PAYMENTS]\n- Expected payable (GRN-based): ৳".number_format($exp_payable->total/1000000,2)."M\n- Total paid to date: ৳".number_format($pay_total->total/1000000,2)."M ({$pay_rate}% of payable)\n- Outstanding balance: ৳".number_format($balance_due/1000000,2)."M\n- Advance payments outstanding: ৳".number_format($advances->total/1000000,2)."M\n- Paid this month: ৳".number_format($pay_month->total/1000000,2)."M ({$pay_month->cnt} transactions)\n- Paid today: ৳".number_format($pay_today->total/1000,0)."K ({$pay_today->cnt} transactions)\n\n[SUPPLIER DUES (in-progress)]\n{$dues_text}\n\n[WHEAT ORIGINS (active orders)]\n{$origins_text}";

    $prompts = [
        'procurement_brief' => "Generate a **Daily Procurement Brief** in markdown.\n### 📋 Today's Procurement Status\n### ⚠️ Urgent Actions (ranked)\n### 💰 Payment Obligations\n### 🚚 Delivery Pipeline\n### 💡 One Procurement Tip\nUnder 350 words. Specific ৳ amounts. Name suppliers where relevant.",

        'payment_urgency'   => "Analyse payment urgency:\n1. Rank suppliers by payment priority (name, amount, reason)\n2. Is ৳".number_format($balance_due/1000000,2)."M outstanding manageable — liquidity pressure assessment\n3. Advance payment risk: ৳".number_format($advances->total/1000000,2)."M locked — when does it convert?\n4. Recommended payment schedule for this week with specific amounts\n5. Any dangerous concentration (too much owed to one supplier)?\nBe specific with BDT amounts.",

        'supplier_risk'     => "Analyse supplier risk & concentration:\n1. Which supplier has the highest exposure? Is it dangerous?\n2. Advance payment risk per supplier — who has the most pre-delivery cash?\n3. Diversification health — is the company too dependent on one supplier?\n4. Recommend: which relationship needs immediate attention and why?\n5. One negotiation leverage point you see in the data\nFocus on Bangladesh wheat import context.",

        'origin_analysis'   => "Analyse wheat sourcing by country:\n1. Best-value origin (৳/kg) — which country is cheapest right now?\n2. Origin concentration risk — dangerously over-reliant on any one country?\n3. Price comparison table: rank all origins by avg ৳/kg\n4. Geopolitical/seasonal risk: flag any current risks for origins in use (Russia-Ukraine, Argentina drought, Canadian crop, etc.)\n5. Recommended sourcing mix adjustment for next 2-3 purchase cycles\nUse current global wheat market knowledge.",

        'cash_planning'     => "Build a cash flow plan for procurement:\n1. Estimated ৳ needed in next 7 days based on outstanding dues\n2. Estimated ৳ needed in next 30 days\n3. Advance recovery: ৳".number_format($advances->total/1000000,2)."M pre-paid — when does this convert to received wheat?\n4. Cash flow risk signals: any urgent shortfalls?\n5. Treasury recommendation: how to prioritise available cash across suppliers\nProvide specific BDT projections.",

        'custom'            => "Answer this using the live procurement data: \"{$question}\". Be concise and actionable. If data is insufficient, say so clearly.",
    ];

    if (!isset($prompts[$action])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'Invalid action']); exit;
    }

    $ai_response = callAI($context, $prompts[$action], 800);

    ob_end_clean();
    echo json_encode([
        'success'  => true,
        'action'   => $action,
        'response' => $ai_response,
        'snapshot' => [
            'in_progress_pos'  => (int)$in_progress->cnt,
            'pending_delivery' => (int)$pending_del->cnt,
            'balance_due'      => (float)$balance_due,
            'pay_rate_pct'     => (float)$pay_rate,
            'paid_today'       => (float)$pay_today->total,
            'paid_this_month'  => (float)$pay_month->total,
        ],
        'generated_at' => date('Y-m-d H:i:s'),
    ]);

} catch (Exception $e) {
    if (ob_get_level()) ob_end_clean();
    error_log("Purchase Advisor Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;


// =============================================================================
// HELPERS
// =============================================================================
function getPurchaseSchemaPrompt(): string
{
    return <<<'SCHEMA'
TABLES (name: columns):

purchase_orders_adnan: id, po_number, po_date(date), supplier_id, supplier_name, branch_id,
  wheat_origin, quantity_kg, unit_price_per_kg, total_order_value, total_received_qty,
  total_received_value, total_paid(auto-updated by trigger from posted payments),
  balance_payable(GENERATED = total_received_value - total_paid — based on ACTUAL received, NOT expected),
  po_status(draft|approved|partial|completed|cancelled),
  delivery_status(pending|partial|completed|closed), payment_status(unpaid|partial|paid|overpaid),
  expected_delivery_date, remarks, created_by_user_id, created_at

CRITICAL: balance_payable uses total_received_value (actual kg received).
The CORRECT payment basis is expected_quantity from goods_received_adnan.
For accurate "amount due" queries: SUM(grn.expected_quantity * po.unit_price_per_kg) - po.total_paid

purchase_payments_adnan: id, payment_voucher_number, payment_date(date), purchase_order_id,
  po_number, supplier_id, supplier_name, amount_paid, payment_method(bank|cash|cheque),
  bank_name, payment_type(advance|regular|final), is_posted(0|1), created_by_user_id, created_at

goods_received_adnan: id, grn_number, purchase_order_id, grn_date(date), supplier_id,
  supplier_name, quantity_received_kg, unit_price_per_kg, total_value, expected_quantity,
  variance_percentage, grn_status(draft|verified|posted|cancelled), unload_point_branch_id, created_at

suppliers: id, supplier_code, company_name, contact_person, phone, country,
  current_balance, credit_limit, status(active|inactive|blocked)

supplier_ledger: id, supplier_id, transaction_date(date), transaction_type(purchase|payment|debit_note|credit_note|opening_balance),
  reference_number, debit_amount, credit_amount, balance, description, created_at

branches: id, name, code, status
users: id, display_name, email, role, status

KEY JOINS:
purchase_orders_adnan.supplier_id → suppliers.id
purchase_payments_adnan.purchase_order_id → purchase_orders_adnan.id
purchase_payments_adnan.supplier_id → suppliers.id
goods_received_adnan.purchase_order_id → purchase_orders_adnan.id
supplier_ledger.supplier_id → suppliers.id

IMPORTANT NOTES:
- Payment basis = goods_received_adnan.expected_quantity × unit_price_per_kg (NOT quantity_received_kg)
- Only posted payments: is_posted = 1
- Active orders: po_status != 'cancelled' AND delivery_status != 'closed'
- In-progress: delivery_status IN ('pending','partial')
SCHEMA;
}


function callAI(string $system, string $user, int $max_tokens = 800): string
{
    $errors = [];

    // ── 1. DeepSeek (PRIMARY — free tier) ─────────────────────────────────────
    if (defined('DEEPSEEK_API_KEY') && DEEPSEEK_API_KEY) {
        foreach (['deepseek-chat', 'deepseek-reasoner'] as $model) {
            try {
                $res = httpPost('https://api.deepseek.com/v1/chat/completions', json_encode([
                    'model' => $model, 'max_tokens' => $max_tokens, 'temperature' => 0.1,
                    'messages' => [['role'=>'system','content'=>$system],['role'=>'user','content'=>$user]],
                ]), ['Authorization: Bearer '.DEEPSEEK_API_KEY, 'Content-Type: application/json']);
                $d = json_decode($res, true);
                if (!empty($d['choices'][0]['message']['content'])) return $d['choices'][0]['message']['content'];
            } catch (Exception $e) {
                $msg = $e->getMessage();
                if (strpos($msg,'429')!==false||strpos($msg,'402')!==false||strpos($msg,'quota')!==false) {
                    $errors[]="DeepSeek/{$model}: skipped"; continue;
                }
                $errors[]="DeepSeek/{$model}: {$msg}"; break;
            }
        }
    }

    // ── 2. Groq — 5 models, each own TPD pool ──────────────────────────────
    if (defined('GROQ_API_KEY') && GROQ_API_KEY) {
        foreach (['llama-3.3-70b-versatile','llama-3.1-8b-instant','llama3-8b-8192','gemma2-9b-it','mixtral-8x7b-32768'] as $model) {
            try {
                $res = httpPost('https://api.groq.com/openai/v1/chat/completions', json_encode([
                    'model' => $model, 'max_tokens' => $max_tokens, 'temperature' => 0.1,
                    'messages' => [['role'=>'system','content'=>$system],['role'=>'user','content'=>$user]],
                ]), ['Authorization: Bearer '.GROQ_API_KEY, 'Content-Type: application/json']);
                $d = json_decode($res, true);
                if (!empty($d['choices'][0]['message']['content'])) return $d['choices'][0]['message']['content'];
            } catch (Exception $e) {
                $msg = $e->getMessage();
                if (strpos($msg,'429')!==false||strpos($msg,'rate_limit')!==false||strpos($msg,'decommissioned')!==false||strpos($msg,'400')!==false) {
                    $errors[]="Groq/{$model}: skipped"; continue;
                }
                $errors[]="Groq/{$model}: {$msg}"; break;
            }
        }
    }

    // ── 3. Gemini — 2 models ─────────────────────────────────────────────────
    if (defined('GEMINI_API_KEY') && GEMINI_API_KEY) {
        foreach (['gemini-2.0-flash','gemini-2.0-flash-lite'] as $model) {
            try {
                $res = httpPost(
                    "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=".GEMINI_API_KEY,
                    json_encode(['contents'=>[['parts'=>[['text'=>$system."\n\n".$user]]]],
                                 'generationConfig'=>['maxOutputTokens'=>$max_tokens,'temperature'=>0.1]]),
                    ['Content-Type: application/json']
                );
                $d = json_decode($res, true);
                $text = $d['candidates'][0]['content']['parts'][0]['text'] ?? '';
                if ($text) return $text;
            } catch (Exception $e) { $errors[]="Gemini/{$model}: ".substr($e->getMessage(),0,60); continue; }
        }
    }

    // ── 4. Anthropic Haiku (paid fallback) ────────────────────────────────────
    if (defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY) {
        try {
            $res = httpPost('https://api.anthropic.com/v1/messages', json_encode([
                'model' => 'claude-haiku-4-5-20251001', 'max_tokens' => $max_tokens,
                'system' => $system,
                'messages' => [['role'=>'user','content'=>$user]],
            ]), ['x-api-key: '.ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01', 'Content-Type: application/json']);
            $d = json_decode($res, true);
            if (!empty($d['content'][0]['text'])) return $d['content'][0]['text'];
        } catch (Exception $e) { $errors[]="Anthropic: ".$e->getMessage(); }
    }

    throw new Exception("All AI providers failed. Check API keys in config.php.\n".implode("\n", $errors));
}

function httpPost(string $url, string $body, array $headers): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body, CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>35, CURLOPT_SSL_VERIFYPEER=>true]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) { $e=curl_error($ch); curl_close($ch); throw new Exception("cURL: $e"); }
    curl_close($ch);
    if ($code >= 400) throw new Exception("HTTP {$code}: {$res}");
    return $res;
}
?>