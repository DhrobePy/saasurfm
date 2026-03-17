<?php
/**
 * ai_agent.php  —  Conversational ERP Agent
 * ─────────────────────────────────────────────────────────────────────────────
 * Changes vs previous version:
 *  - AI fallback chain: Groq → Gemini → Anthropic (survives 429 rate limits)
 *  - Reference data (suppliers/customers/products) cached in session for 5 min
 *  - System prompt compressed — actions block ~60% smaller
 *  - history capped at 16 turns (32 messages) to control token creep
 *  - Per-call token budget: 600 max_tokens (was 1000)
 * Place at: /admin/ai_agent.php
 */

ob_start();
require_once '../core/init.php';
require_once __DIR__ . '/AgentActions.php';
header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    ob_end_clean(); http_response_code(401);
    echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit;
}
if (!in_array($_SESSION['user_role']??'', ['Superadmin','admin'])) {
    ob_end_clean(); http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Forbidden']); exit;
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$csrf  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? '');
if (empty($csrf) || !hash_equals($_SESSION['csrf_token']??'', $csrf)) {
    ob_end_clean(); http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Invalid CSRF token']); exit;
}

global $db;
$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? '';
$user_name = $_SESSION['user_display_name'] ?? 'Admin';
$today     = date('Y-m-d');

$sub = $input['sub_action'] ?? 'message';

// ── Reset ─────────────────────────────────────────────────────────────────────
if ($sub === 'reset') {
    $_SESSION['agent_history'] = [];
    $_SESSION['agent_ref']     = null;
    ob_end_clean();
    echo json_encode(['success'=>true]); exit;
}

// ── Get history ───────────────────────────────────────────────────────────────
if ($sub === 'get_history') {
    ob_end_clean();
    echo json_encode(['success'=>true,'history'=>$_SESSION['agent_history']??[]]); exit;
}

// ── New message ───────────────────────────────────────────────────────────────
$user_msg = trim($input['message'] ?? '');
if (!$user_msg) {
    ob_end_clean();
    echo json_encode(['success'=>false,'error'=>'Empty message']); exit;
}

// ── Conversation history (cap at 32 messages = 16 turns) ─────────────────────
if (!isset($_SESSION['agent_history'])) $_SESSION['agent_history'] = [];
$history = &$_SESSION['agent_history'];
if (count($history) > 32) $history = array_slice($history, -32);


// ═════════════════════════════════════════════════════════════════════════════
// REFERENCE DATA  —  cached in session for 5 minutes to save tokens
// ═════════════════════════════════════════════════════════════════════════════
$ref_cache_ttl = 300; // 5 minutes
if (empty($_SESSION['agent_ref']) || (time() - ($_SESSION['agent_ref_ts'] ?? 0)) > $ref_cache_ttl) {
    $ref = [];
    try {
        $sup = $db->query("SELECT id, company_name FROM suppliers WHERE status='active' ORDER BY company_name LIMIT 25")->results();
        $ref['suppliers'] = implode("\n", array_map(fn($s) => "  {$s->id}:{$s->company_name}", (array)$sup));

        $bra = $db->query("SELECT id, name FROM branches WHERE status='active'")->results();
        $ref['branches'] = implode("\n", array_map(fn($b) => "  {$b->id}:{$b->name}", (array)$bra));

        $cus = $db->query(
            "SELECT id, COALESCE(business_name,name) as n, current_balance, credit_limit
             FROM customers WHERE status='active' AND customer_type='Credit'
             ORDER BY current_balance DESC LIMIT 30"
        )->results();
        $ref['customers'] = implode("\n", array_map(fn($c) => "  {$c->id}:{$c->n} (bal:৳".number_format($c->current_balance,0)." lim:৳".number_format($c->credit_limit,0).")", (array)$cus));

        $pv = $db->query(
            "SELECT pv.id, p.id as pid, p.base_name, pv.grade, pv.weight_variant, pv.sku
             FROM product_variants pv JOIN products p ON pv.product_id=p.id
             WHERE pv.status='active' AND p.status='active' ORDER BY p.base_name LIMIT 25"
        )->results();
        $ref['products'] = implode("\n", array_map(fn($p) => "  v{$p->id}|p{$p->pid}:{$p->base_name} {$p->grade}Grade {$p->weight_variant}kg", (array)$pv));

        $ec = $db->query("SELECT id, category_name FROM expense_categories WHERE is_active=1 ORDER BY category_name")->results();
        $ref['expcat'] = implode("\n", array_map(fn($c) => "  {$c->id}:{$c->category_name}", (array)$ec));

        $rc = $db->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role'")->first();
        preg_match_all("/'([^']+)'/", $rc->COLUMN_TYPE??'', $rm);
        $ref['roles'] = implode(', ', $rm[1]??[]);

    } catch (Exception $e) {
        error_log("Agent ref error: ".$e->getMessage());
        $ref = ['suppliers'=>'(error)','branches'=>'(error)','customers'=>'(error)','products'=>'(error)','expcat'=>'(error)','roles'=>'(error)'];
    }
    $_SESSION['agent_ref']    = $ref;
    $_SESSION['agent_ref_ts'] = time();
} else {
    $ref = $_SESSION['agent_ref'];
}


// ═════════════════════════════════════════════════════════════════════════════
// SYSTEM PROMPT  (compact — ~800 tokens total)
// ═════════════════════════════════════════════════════════════════════════════
$actions_block = AgentActions::buildActionsPrompt();

$system = <<<SYS
You are an AI ERP Agent for Ujjal Flour Mills (Bangladesh, wheat flour). Today: {$today}.
User: {$user_name} (role: {$user_role}).

{$actions_block}

REFERENCE DATA (use these IDs in EXECUTE — NEVER invent IDs):
SUPPLIERS:
{$ref['suppliers']}
BRANCHES:
{$ref['branches']}
CREDIT CUSTOMERS (top 30):
{$ref['customers']}
PRODUCTS (v=variant_id p=product_id):
{$ref['products']}
EXPENSE CATEGORIES:
{$ref['expcat']}
CURRENT ROLES: {$ref['roles']}

RULES:
1. Detect intent → gather required fields (*=required ?=optional) conversationally.
2. Ask multiple related fields at once. Never re-ask already-given fields.
3. Use today's date as default for date fields when not specified.
4. Before executing, show confirm summary and ask "Shall I proceed? (yes/no)".
5. On user confirmation (yes/ok/confirm/proceed/ha/haan), output EXECUTE tag on its own line:
   <EXECUTE>{"action":"key","fields":{...}}</EXECUTE>
   For credit order items use: "items":[{"product_id":N,"variant_id":N,"quantity":N,"unit_price":N,"line_total":N,"weight_kg":N}]
6. PHP executes it and returns result — relay it naturally.
7. On cancel/no/stop → abort, offer to help with something else.
8. Use ৳ for money. Be concise and professional.
9. STRICT: Only use IDs from the reference data above. If something isn't listed, ask user to add it via the ERP module first.
SYS;


// ═════════════════════════════════════════════════════════════════════════════
// CALL AI  (Groq → Gemini → Anthropic fallback)
// ═════════════════════════════════════════════════════════════════════════════
$history[] = ['role'=>'user','content'=>$user_msg];

$ai_raw = '';
$provider_used = '';
try {
    [$ai_raw, $provider_used] = callAIWithFallback($system, $history, 600);
} catch (Exception $e) {
    array_pop($history); // remove user msg so it can be retried
    ob_end_clean();
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]); exit;
}


// ═════════════════════════════════════════════════════════════════════════════
// DETECT <EXECUTE> TAG
// ═════════════════════════════════════════════════════════════════════════════
$exec_result = null;
$display     = $ai_raw;

if (preg_match('/<EXECUTE>(.*?)<\/EXECUTE>/s', $ai_raw, $em)) {
    try {
        $exec_data  = json_decode(trim($em[1]), true);
        if (json_last_error() !== JSON_ERROR_NONE) throw new Exception('Invalid EXECUTE JSON.');

        $exec_result = AgentActions::execute(
            $exec_data['action'] ?? '',
            $exec_data['fields'] ?? [],
            $db,
            $user_id
        );

        // Ask AI to relay the result
        $inject = $exec_result['success']
            ? "[EXECUTED OK: ".(preg_replace('/[*_`]/','', $exec_result['message']??'Done'))."]"
            : "[EXECUTE FAILED: ".($exec_result['error']??'Unknown').". Tell the user clearly.]";

        $relay_h   = array_merge($history, [['role'=>'assistant','content'=>$ai_raw], ['role'=>'user','content'=>$inject]]);
        [$display] = callAIWithFallback($system, $relay_h, 400);

        // Save full exchange to history
        $history[] = ['role'=>'assistant','content'=>$ai_raw];
        $history[] = ['role'=>'user',     'content'=>$inject];
        $history[] = ['role'=>'assistant','content'=>$display];

    } catch (Exception $e) {
        error_log("Agent execute error: ".$e->getMessage());
        $display   = preg_replace('/<EXECUTE>.*?<\/EXECUTE>/s','', $ai_raw);
        $display  .= "\n\n⚠️ Execution failed: ".$e->getMessage();
        $history[] = ['role'=>'assistant','content'=>$display];
    }
} else {
    $history[] = ['role'=>'assistant','content'=>$ai_raw];
}

ob_end_clean();
echo json_encode([
    'success'       => true,
    'message'       => $display,
    'executed'      => $exec_result !== null,
    'exec_success'  => $exec_result['success'] ?? null,
    'provider'      => $provider_used,
    'history_turns' => (int)floor(count($history)/2),
]);
exit;


// ═════════════════════════════════════════════════════════════════════════════
// AI PROVIDER FALLBACK CHAIN
// Order: DeepSeek (primary) → Groq (5 models) → Gemini (2 models) → Anthropic
// ═════════════════════════════════════════════════════════════════════════════
function callAIWithFallback(string $system, array $messages, int $max_tokens): array
{
    $errors = [];

    // ── 1. DeepSeek  (PRIMARY — OpenAI-compatible, very generous free tier) ───
    // Get key free at: https://platform.deepseek.com/api_keys
    if (defined('DEEPSEEK_API_KEY') && DEEPSEEK_API_KEY) {
        foreach (['deepseek-chat', 'deepseek-reasoner'] as $model) {
            try {
                $res = httpPost('https://api.deepseek.com/v1/chat/completions', json_encode([
                    'model'       => $model,
                    'max_tokens'  => $max_tokens,
                    'temperature' => 0.2,
                    'messages'    => array_merge([['role'=>'system','content'=>$system]], $messages),
                ]), ['Authorization: Bearer '.DEEPSEEK_API_KEY, 'Content-Type: application/json']);
                $d = json_decode($res, true);
                if (!empty($d['choices'][0]['message']['content'])) {
                    return [$d['choices'][0]['message']['content'], "DeepSeek/{$model}"];
                }
            } catch (Exception $e) {
                $msg = $e->getMessage();
                if (strpos($msg,'429')!==false || strpos($msg,'402')!==false || strpos($msg,'quota')!==false) {
                    $errors[] = "DeepSeek/{$model}: skipped (".substr($msg,0,60).")"; continue;
                }
                $errors[] = "DeepSeek/{$model}: {$msg}"; break;
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
                    'temperature' => 0.2,
                    'messages'    => array_merge([['role'=>'system','content'=>$system]], $messages),
                ]), ['Authorization: Bearer '.GROQ_API_KEY, 'Content-Type: application/json']);
                $d = json_decode($res, true);
                if (!empty($d['choices'][0]['message']['content'])) {
                    return [$d['choices'][0]['message']['content'], "Groq/{$model}"];
                }
            } catch (Exception $e) {
                $msg = $e->getMessage();
                if (strpos($msg,'429')!==false || strpos($msg,'rate_limit')!==false
                    || strpos($msg,'decommissioned')!==false || strpos($msg,'400')!==false) {
                    $errors[] = "Groq/{$model}: skipped (".substr($msg,0,60).")"; continue;
                }
                $errors[] = "Groq/{$model}: {$msg}"; break;
            }
        }
    }

    // ── 3. Gemini — 2 live free-tier models ──────────────────────────────────
    if (defined('GEMINI_API_KEY') && GEMINI_API_KEY) {
        $first_user = "[SYSTEM]\n{$system}\n[/SYSTEM]\n\n".($messages[0]['content']??'');
        $turns = [['role'=>'user','parts'=>[['text'=>$first_user]]]];
        foreach (array_slice($messages, 1) as $m) {
            $turns[] = ['role'=>$m['role']==='assistant'?'model':'user','parts'=>[['text'=>$m['content']]]];
        }
        foreach (['gemini-2.0-flash','gemini-2.0-flash-lite'] as $model) {
            try {
                $res = httpPost(
                    "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=".GEMINI_API_KEY,
                    json_encode(['contents'=>$turns,'generationConfig'=>['maxOutputTokens'=>$max_tokens,'temperature'=>0.2]]),
                    ['Content-Type: application/json']
                );
                $d    = json_decode($res, true);
                $text = $d['candidates'][0]['content']['parts'][0]['text'] ?? '';
                if ($text) return [$text, "Gemini/{$model}"];
            } catch (Exception $e) {
                $errors[] = "Gemini/{$model}: ".substr($e->getMessage(),0,80); continue;
            }
        }
    }

    // ── 4. Anthropic Claude Haiku (paid fallback) ─────────────────────────────
    if (defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY) {
        try {
            $res = httpPost('https://api.anthropic.com/v1/messages', json_encode([
                'model'      => 'claude-haiku-4-5-20251001',
                'max_tokens' => $max_tokens,
                'system'     => $system,
                'messages'   => $messages,
            ]), ['x-api-key: '.ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01', 'Content-Type: application/json']);
            $d    = json_decode($res, true);
            $text = $d['content'][0]['text'] ?? '';
            if ($text) return [$text, 'Anthropic/Haiku'];
        } catch (Exception $e) {
            $errors[] = "Anthropic: ".$e->getMessage();
        }
    }

    throw new Exception("All AI providers failed:\n".implode("\n", $errors));
}

function httpPost(string $url, string $body, array $headers): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body, CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30, CURLOPT_SSL_VERIFYPEER=>true,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) { $e=curl_error($ch); curl_close($ch); throw new Exception("cURL: $e"); }
    curl_close($ch);
    if ($code >= 400) throw new Exception("HTTP {$code}: {$res}");
    return $res;
}
?>