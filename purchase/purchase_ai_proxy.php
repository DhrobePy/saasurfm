<?php
/**
 * purchase/purchase_ai_proxy.php
 * Server-side AI proxy for Purchase (Adnan) Module
 *
 * Add to config.php (priority order):
 *   define('GROQ_API_KEY',      'gsk_...');       // FREE — recommended
 *   define('GEMINI_API_KEY',    'AIzaSy...');      // Free tier
 *   define('ANTHROPIC_API_KEY', 'sk-ant-...');     // Paid fallback
 */

require_once '../core/init.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$user = getCurrentUser();
if (!in_array($user['role'], ['Superadmin', 'admin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

// Quick connectivity test — visit purchase_ai_proxy.php?ping=1 in browser
if (isset($_GET['ping'])) {
    $key_status = [];
    if (defined('GROQ_API_KEY')      && GROQ_API_KEY)      $key_status[] = 'GROQ ✓';
    if (defined('GEMINI_API_KEY')    && GEMINI_API_KEY)     $key_status[] = 'GEMINI ✓';
    if (defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY)  $key_status[] = 'ANTHROPIC ✓';
    echo json_encode([
        'status'  => 'ok',
        'proxy'   => 'purchase_ai_proxy.php reachable',
        'keys'    => empty($key_status) ? ['⚠ No API keys found in config.php'] : $key_status,
        'user'    => $user['role'] ?? 'unknown',
        'time'    => date('Y-m-d H:i:s'),
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input['prompt'])) {
    echo json_encode(['error' => 'No prompt provided']);
    exit;
}

$prompt = $input['prompt'];

function aiResponse($text) { echo json_encode(['content' => [['type' => 'text', 'text' => $text]]]); exit; }
function aiError($msg)     { echo json_encode(['error' => $msg]); exit; }

function curlPost($url, $payload, $headers, $timeout = 40) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    return [$response, $httpCode, $curlErr];
}

// ── Option 1: Groq (FREE) ──────────────────────────────────
if (defined('GROQ_API_KEY') && GROQ_API_KEY) {
    $payload = json_encode([
        'model'       => 'llama-3.3-70b-versatile',
        'messages'    => [['role' => 'user', 'content' => $prompt]],
        'max_tokens'  => 1500,
        'temperature' => 0.6,
    ]);
    [$response, $httpCode, $curlErr] = curlPost(
        'https://api.groq.com/openai/v1/chat/completions',
        $payload,
        ['Content-Type: application/json', 'Authorization: Bearer ' . GROQ_API_KEY]
    );
    if ($curlErr) aiError('cURL error: ' . $curlErr);
    $data = json_decode($response, true);
    if ($httpCode !== 200 || empty($data['choices'][0]['message']['content']))
        aiError($data['error']['message'] ?? 'Groq API error (HTTP ' . $httpCode . ')');
    aiResponse($data['choices'][0]['message']['content']);
}

// ── Option 2: Gemini ───────────────────────────────────────
if (defined('GEMINI_API_KEY') && GEMINI_API_KEY) {
    $model   = 'gemini-2.0-flash';
    $url     = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . GEMINI_API_KEY;
    $payload = json_encode([
        'contents'         => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['maxOutputTokens' => 1500, 'temperature' => 0.6],
    ]);
    [$response, $httpCode, $curlErr] = curlPost($url, $payload, ['Content-Type: application/json']);
    if ($curlErr) aiError('cURL error: ' . $curlErr);
    $data = json_decode($response, true);
    if ($httpCode !== 200 || empty($data['candidates'][0]['content']['parts'][0]['text']))
        aiError($data['error']['message'] ?? 'Gemini error (HTTP ' . $httpCode . ')');
    aiResponse($data['candidates'][0]['content']['parts'][0]['text']);
}

// ── Option 3: Anthropic ────────────────────────────────────
if (defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY) {
    $payload = json_encode([
        'model'      => 'claude-sonnet-4-20250514',
        'max_tokens' => 1500,
        'messages'   => [['role' => 'user', 'content' => $prompt]],
    ]);
    [$response, $httpCode, $curlErr] = curlPost(
        'https://api.anthropic.com/v1/messages',
        $payload,
        ['Content-Type: application/json', 'x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01']
    );
    if ($curlErr) aiError('cURL error: ' . $curlErr);
    http_response_code($httpCode);
    echo $response;
    exit;
}

aiError('No AI API key configured. Add GROQ_API_KEY (free) from console.groq.com to config.php');