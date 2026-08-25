<?php
/**
 * Telegram webhook — receives messages from the dedicated "AI Query" group
 * and answers /ask <question> using the same NL→SQL engine that powers the
 * admin dashboard's "Ask DB" box (answerNaturalLanguageQuery(), shared via
 * core/functions/ai_query_shared.php — one engine, two front doors).
 *
 * One-time setup, once TELEGRAM_CHAT_ID_AI_QUERY + TELEGRAM_WEBHOOK_SECRET
 * are set in config.php:
 *   curl -F "url=https://saas.ujjalfm.com/api/telegram_ai_webhook.php" \
 *        -F "secret_token=<TELEGRAM_WEBHOOK_SECRET>" \
 *        https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/setWebhook
 *
 * Security layers (all fail closed — anything unexpected is silently ignored,
 * never falls back to a broader/general chat):
 *  1. X-Telegram-Bot-Api-Secret-Token header must match TELEGRAM_WEBHOOK_SECRET
 *     — blocks anyone who isn't Telegram itself from POSTing here.
 *  2. Only messages FROM the configured TELEGRAM_CHAT_ID_AI_QUERY chat are
 *     processed. No fallback to the general TELEGRAM_CHAT_ID — deliberately
 *     bypasses getTelegramChatId()'s normal fallback behavior for this one
 *     comparison, since a silent fallback here would defeat the whole point
 *     of a dedicated, access-controlled group.
 *  3. Only /ask and /whoami are handled — the bot never reacts to ordinary
 *     chat (also means no Telegram "privacy mode" changes were needed; bots
 *     always receive slash-commands regardless of that setting).
 *  4. /ask additionally requires the sender's Telegram user id to be on
 *     telegram_ai_authorized_users — the group being invite-only is layer
 *     one, this allow-list is an explicit second layer per the user's
 *     "admin/superadmin/allowed persons only" requirement.
 */

require_once __DIR__ . '/../core/init.php';
require_once __DIR__ . '/../core/functions/ai_query_shared.php';

header('Content-Type: application/json');

function tg_reply(string $chat_id, string $text): void {
    if (!defined('TELEGRAM_BOT_TOKEN')) return;
    try {
        require_once __DIR__ . '/../core/classes/TelegramNotifier.php';
        (new TelegramNotifier(TELEGRAM_BOT_TOKEN, $chat_id))->sendMessage($text);
    } catch (\Throwable $e) { error_log('telegram_ai_webhook reply: ' . $e->getMessage()); }
}

// ── Layer 1: verify this request actually came from Telegram ────────────────
$secret_header = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!defined('TELEGRAM_WEBHOOK_SECRET') || TELEGRAM_WEBHOOK_SECRET === '' || !hash_equals((string)TELEGRAM_WEBHOOK_SECRET, $secret_header)) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

$update  = json_decode(file_get_contents('php://input'), true);
$message = $update['message'] ?? null;
if (!$message || empty($message['text'])) { http_response_code(200); echo json_encode(['ok' => true]); exit; }

$chat_id       = (string)($message['chat']['id'] ?? '');
$from_id       = (int)($message['from']['id'] ?? 0);
$from_username = $message['from']['username'] ?? null;
$from_name     = trim(($message['from']['first_name'] ?? '') . ' ' . ($message['from']['last_name'] ?? ''));
$text          = trim($message['text']);

// ── Layer 2: only the dedicated AI Query group — no fallback to any other chat
if (!defined('TELEGRAM_CHAT_ID_AI_QUERY') || TELEGRAM_CHAT_ID_AI_QUERY === '' || $chat_id !== (string)TELEGRAM_CHAT_ID_AI_QUERY) {
    http_response_code(200); echo json_encode(['ok' => true]); exit;
}

// ── /whoami — onboarding helper: no auth required, no DB access ─────────────
if (preg_match('/^\/whoami\b/i', $text)) {
    $uname = $from_username ? '@' . $from_username : '(no username set)';
    tg_reply($chat_id, "Your Telegram ID: <code>{$from_id}</code>\n{$uname}\n\nAsk an admin to add this ID under Admin → AI Query Access to use /ask.");
    http_response_code(200); echo json_encode(['ok' => true]); exit;
}

// ── /ask <question> ──────────────────────────────────────────────────────────
if (preg_match('/^\/ask(?:@\w+)?\s*(.*)$/is', $text, $m)) {
    $question = trim($m[1]);

    ensureTelegramAiAuthorizedUsersTable();
    ensureTelegramAiQueryLogTable();

    $db = Database::getInstance();

    if (!isTelegramAiQueryAuthorized($from_id)) {
        tg_reply($chat_id, "🔒 " . ($from_name ?: 'You') . ", you're not authorized to use /ask yet. Send <code>/whoami</code> to get your Telegram ID, then ask an admin to add it under Admin → AI Query Access.");
        $db->insert('telegram_ai_query_log', [
            'telegram_user_id' => $from_id, 'telegram_username' => $from_username,
            'question' => $question, 'success' => 0, 'authorized' => 0,
            'error_message' => 'Not on allow-list',
        ]);
        http_response_code(200); echo json_encode(['ok' => true]); exit;
    }

    if ($question === '') {
        tg_reply($chat_id, "Usage: <code>/ask &lt;your question&gt;</code>\nExample: <code>/ask which customers are over their credit limit?</code>");
        http_response_code(200); echo json_encode(['ok' => true]); exit;
    }

    $result = answerNaturalLanguageQuery($question);

    $db->insert('telegram_ai_query_log', [
        'telegram_user_id'  => $from_id,
        'telegram_username' => $from_username,
        'question'          => $question,
        'generated_sql'     => $result['sql'] ?? null,
        'row_count'         => $result['row_count'] ?? null,
        'success'           => !empty($result['success']) ? 1 : 0,
        'error_message'     => $result['error'] ?? null,
        'authorized'        => 1,
    ]);

    if (empty($result['success'])) {
        tg_reply($chat_id, "⚠️ " . ($result['error'] ?? 'Something went wrong answering that.'));
        http_response_code(200); echo json_encode(['ok' => true]); exit;
    }

    $reply = "💬 <b>" . htmlspecialchars($question) . "</b>\n\n" . htmlspecialchars($result['response']);

    if (!empty($result['row_count']) && !empty($result['rows'])) {
        $preview = array_slice($result['rows'], 0, 10);
        $lines = [];
        foreach ($preview as $row) {
            $vals = array_map(fn($v) => $v === null ? '—' : mb_strimwidth((string)$v, 0, 24, '…'), array_values($row));
            $lines[] = implode(' | ', $vals);
        }
        $table = implode("\n", $lines);
        if ($result['row_count'] > 10) $table .= "\n… +" . ($result['row_count'] - 10) . " more";
        $reply .= "\n\n📊 {$result['row_count']} row(s)\n<pre>" . htmlspecialchars($table) . "</pre>";
    }

    if (($result['source'] ?? '') === 'local') {
        $reply .= "\n\n🧮 <i>answered without live AI (fallback mode)</i>";
    }

    // Telegram hard-caps messages at 4096 chars.
    if (mb_strlen($reply) > 4000) $reply = mb_substr($reply, 0, 3970) . "\n… (truncated)";

    tg_reply($chat_id, $reply);
    http_response_code(200); echo json_encode(['ok' => true]); exit;
}

// Anything else in the group — not a recognized command — is ignored
// entirely. The bot never reacts to, logs, or reads ambient conversation.
http_response_code(200);
echo json_encode(['ok' => true]);
