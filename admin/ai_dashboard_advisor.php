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
require_once __DIR__ . '/../core/functions/ai_query_shared.php';
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
// The engine itself — matchCannedQuery/getSchemaPrompt/callAI/httpPost and the
// full NL→SQL→execute→summary pipeline — now lives in the shared
// answerNaturalLanguageQuery() (core/functions/ai_query_shared.php), required
// at the top of this file, so the Telegram /ask webhook can reuse the exact
// same safety-checked pipeline instead of a second copy of it.
// =============================================================================
if ($action === 'db_query') {
    $result = answerNaturalLanguageQuery($question);
    $result['action'] = 'db_query';
    ob_end_clean();
    if (empty($result['success'])) http_response_code(500);
    echo json_encode($result);
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
        "SELECT pv.sku,
                CONCAT(p.base_name, ' (', pv.grade, ' ', pv.weight_variant, ')') AS variant_name,
                SUM(i.quantity) as total_qty, b.name as branch_name
         FROM inventory i
         JOIN product_variants pv ON i.variant_id = pv.id
         JOIN products p ON pv.product_id = p.id
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

    // Never leave the dashboard showing a bare error — if every AI provider is
    // down, fall back to a rule-based local analysis computed from the same
    // snapshot numbers (mirrors bank/index.php's generateLocalAnalysis() design).
    // 'custom' free-form questions can't be answered this way (no live model to
    // interpret arbitrary text), so those get an honest "try again" message
    // instead of a fabricated answer.
    $source = 'ai';
    try {
        $ai_response = callAI($erp_context, $prompts[$action], 800);
    } catch (Exception $e) {
        error_log("AI Advisor: all providers failed for action={$action}: " . $e->getMessage());
        $local_vars = [
            'sales_today' => $sales_today, 'sales_yesterday' => $sales_yesterday, 'sales_month' => $sales_month,
            'pos_today' => $pos_today, 'pending_orders' => $pending_orders, 'overdue_orders' => $overdue_orders,
            'total_receivable' => $total_receivable, 'payments_today' => $payments_today, 'payments_month' => $payments_month,
            'expenses_month' => $expenses_month, 'expenses_today' => $expenses_today, 'top_debtors' => $top_debtors,
            'over_limit_customers' => $over_limit_customers, 'new_customers_month' => $new_customers_month,
            'inventory_summary' => $inventory_summary, 'pending_pos' => $pending_pos,
            'purchase_paid_month' => $purchase_paid_month, 'in_production' => $in_production, 'ready_to_ship' => $ready_to_ship,
        ];
        $ai_response = generateLocalInsight($action, $local_vars);
        $source = 'local';
    }

    ob_end_clean();
    echo json_encode([
        'success'  => true,
        'action'   => $action,
        'response' => $ai_response,
        'source'   => $source,
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
/**
 * Rule-based, zero-AI substitute for the 5 preset insight types — computed
 * directly from the same live snapshot numbers the AI prompt would have used.
 * Mirrors bank/index.php's generateLocalAnalysis() JS function: same section
 * structure as the AI would produce, so the fallback reads consistently with
 * what a real answer looks like, just derived from thresholds/arithmetic
 * instead of a model. Used whenever callAI() throws (all providers down).
 */
function generateLocalInsight(string $action, array $v): string
{
    $fmt = fn($n) => '৳' . number_format((float)$n, 0);
    $pct = fn($a, $b) => $b > 0 ? round((($a - $b) / $b) * 100, 1) : ($a > 0 ? 100 : 0);

    $sales_today_total = (float)$v['sales_today']->total_amount + (float)$v['pos_today']->total_amount;
    $sales_today_count = (int)$v['sales_today']->order_count + (int)$v['pos_today']->order_count;
    $sales_yesterday_total = (float)$v['sales_yesterday']->total_amount;
    $net_month = (float)$v['payments_month']->total - (float)$v['expenses_month']->total;
    $collection_rate = ((float)$v['sales_month']->total_amount > 0)
        ? round(((float)$v['payments_month']->total / (float)$v['sales_month']->total_amount) * 100, 1)
        : 0;

    switch ($action) {
        case 'daily_brief':
            $urgent = [];
            if ((int)$v['overdue_orders']->cnt > 0) $urgent[] = "**{$v['overdue_orders']->cnt} overdue order(s)** past their required delivery date — needs dispatch follow-up.";
            if ((int)$v['over_limit_customers']->cnt > 0) $urgent[] = "**{$v['over_limit_customers']->cnt} customer(s) over their credit limit** — hold further credit until collected.";
            if ((int)$v['pending_orders']->cnt > 5) $urgent[] = "**{$v['pending_orders']->cnt} orders still in draft/pending approval** — a backlog is building.";
            if (!$urgent) $urgent[] = "No urgent flags — pipeline looks clean.";

            return "## 🌅 Today's Highlights\n"
                . "Sales today: **{$fmt($sales_today_total)}** across **{$sales_today_count} orders**. "
                . "Payments collected: **{$fmt($v['payments_today']->total)}** ({$v['payments_today']->cnt} receipts). "
                . "Production: **{$v['in_production']->cnt}** in progress, **{$v['ready_to_ship']->cnt}** ready to ship.\n\n"
                . "## ⚠️ Urgent Actions\n- " . implode("\n- ", $urgent) . "\n\n"
                . "## 💰 Cash & Collections\n"
                . "Total receivable: **{$fmt($v['total_receivable']->total)}**. This month collected **{$fmt($v['payments_month']->total)}** against **{$fmt($v['expenses_month']->total)}** spent — net **" . ($net_month >= 0 ? '+' : '-') . "{$fmt(abs($net_month))}**.\n\n"
                . "## 📦 Operations Pulse\n"
                . "Open purchase orders: **{$v['pending_pos']->cnt}** (**{$fmt($v['pending_pos']->total)}**). Purchases paid this month: **{$fmt($v['purchase_paid_month']->total)}**.\n\n"
                . "## 💡 One Strategic Tip\n"
                . ((int)$v['over_limit_customers']->cnt > 0
                    ? "Prioritise collecting from the top debtor" . (count($v['top_debtors']) ? " (**" . ($v['top_debtors'][0]->business_name ?: $v['top_debtors'][0]->name) . "**, **{$fmt($v['top_debtors'][0]->current_balance)}**)" : '') . " before extending any new credit."
                    : "Receivables are within limits — a good window to review idle inventory or negotiate better procurement terms.");

        case 'cash_flow':
            $recs = [];
            $recs[] = $collection_rate < 70
                ? "Collection efficiency is **{$collection_rate}%** of this month's sales — tighten follow-up on outstanding invoices."
                : "Collection efficiency is healthy at **{$collection_rate}%** of this month's sales.";
            $recs[] = (float)$v['expenses_month']->total > (float)$v['payments_month']->total
                ? "Expenses (**{$fmt($v['expenses_month']->total)}**) currently exceed collections (**{$fmt($v['payments_month']->total)}**) this month — review discretionary spend."
                : "Collections (**{$fmt($v['payments_month']->total)}**) are covering this month's expenses (**{$fmt($v['expenses_month']->total)}**).";
            $recs[] = ((float)$v['pending_pos']->total > 0)
                ? "**{$fmt($v['pending_pos']->total)}** in open purchase obligations across **{$v['pending_pos']->cnt}** POs — plan cash for these before committing to new large orders."
                : "No large open purchase obligations pending right now.";
            return "## Cash Flow Analysis\n- " . implode("\n- ", $recs)
                . "\n\n**Liquidity note**: total A/R outstanding is **{$fmt($v['total_receivable']->total)}** — treat this as the main lever for near-term cash, not new financing.";

        case 'credit_risk':
            $lines = [];
            foreach ($v['top_debtors'] as $d) {
                $over = $d->credit_limit > 0 && $d->current_balance > $d->credit_limit;
                $lines[] = ($over ? '🔴' : '🟡') . " **" . ($d->business_name ?: $d->name) . "**: {$fmt($d->current_balance)} due"
                    . ($d->credit_limit > 0 ? " (limit {$fmt($d->credit_limit)})" : '')
                    . ($over ? ' — **OVER LIMIT, hold further credit**' : '');
            }
            $score = max(1, min(10, 10 - (int)$v['over_limit_customers']->cnt - (int)floor(count($v['top_debtors']) / 3)));
            return "## Credit Risk Report\n"
                . "Customers over limit: **{$v['over_limit_customers']->cnt}**. New customers this month: **{$v['new_customers_month']->cnt}**.\n\n"
                . "**Top debtors**:\n" . ($lines ? implode("\n", $lines) : "- None outstanding")
                . "\n\n**Credit health score: {$score}/10** — " . ($score >= 7 ? 'receivables under control.' : ($score >= 4 ? 'watch closely, several accounts need follow-up.' : 'multiple over-limit accounts — pause new credit approvals until collected.'));

        case 'operations':
            $inv_lines = array_map(fn($i) => "- {$i->variant_name} @ {$i->branch_name}: **{$i->total_qty} bags**" . ((float)$i->total_qty < 50 ? ' ⚠️ low' : ''), $v['inventory_summary']);
            return "## Operations Briefing\n"
                . "Production pipeline: **{$v['in_production']->cnt}** in production, **{$v['ready_to_ship']->cnt}** ready to ship, **{$v['overdue_orders']->cnt}** overdue.\n\n"
                . "**Lowest inventory levels**:\n" . ($inv_lines ? implode("\n", $inv_lines) : "- No data")
                . "\n\n**Procurement**: **{$v['pending_pos']->cnt}** open POs worth **{$fmt($v['pending_pos']->total)}**."
                . (((int)$v['overdue_orders']->cnt > 0) ? "\n\n⚠️ Overdue orders need dispatch priority — check `logistics/trips` for unscheduled deliveries." : '');

        case 'sales_analysis':
            $change = $pct($sales_today_total, $sales_yesterday_total);
            $days_elapsed = max(1, (int)date('j'));
            $days_in_month = (int)date('t');
            $projection = $days_elapsed > 0 ? ((float)$v['sales_month']->total_amount / $days_elapsed) * $days_in_month : 0;
            return "## Sales Analysis\n"
                . "Today vs yesterday: **" . ($change >= 0 ? '+' : '') . "{$change}%** ({$fmt($sales_today_total)} vs {$fmt($sales_yesterday_total)}).\n"
                . "Month-to-date: **{$fmt($v['sales_month']->total_amount)}** across **{$v['sales_month']->order_count} orders**.\n"
                . "Projected month-end (at current daily pace): **~{$fmt($projection)}**.\n\n"
                . "**Pending orders**: {$v['pending_orders']->cnt} — " . ((int)$v['pending_orders']->cnt > 5 ? "a growing backlog, review approval turnaround." : "within normal range.")
                . "\n\n**Suggestions**:\n- Follow up on the {$v['pending_orders']->cnt} pending order(s) to convert to confirmed sales this week.\n- " . ($change < 0 ? "Today is trailing yesterday — check if any branch had a slow start." : "Momentum is positive — keep the same dispatch pace through week-end.");

        default:
            return "AI is temporarily unavailable and this question needs live interpretation, which the local fallback can't do. Try one of the preset buttons above (Daily Brief, Cash Flow, Credit Risk, Operations, Sales) — those work without AI. Please try your custom question again in a few minutes.";
    }
}

?>