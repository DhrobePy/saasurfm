<?php
/**
 * One-time Google Drive OAuth2 authorization script.
 *
 * Visit this page in your browser ONCE to get a refresh token.
 * After copying the token into config.php, delete this file.
 *
 * Prerequisites:
 *   1. Google Cloud Console → Credentials → OAuth 2.0 Client ID (Desktop App)
 *   2. Add this page's URL as an Authorized Redirect URI:
 *      https://saas.ujjalfm.com/scripts/get_drive_token.php
 *   3. Set GOOGLE_OAUTH_CLIENT_ID and GOOGLE_OAUTH_CLIENT_SECRET in config.php
 */

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/core/config/config.php';

$clientId     = defined('GOOGLE_OAUTH_CLIENT_ID')     ? GOOGLE_OAUTH_CLIENT_ID     : '';
$clientSecret = defined('GOOGLE_OAUTH_CLIENT_SECRET') ? GOOGLE_OAUTH_CLIENT_SECRET : '';
$redirectUri  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
              . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
$scope        = 'https://www.googleapis.com/auth/drive';

// ── Step 2: Exchange authorization code for tokens ────────────────────────────
if (isset($_GET['code'])) {
    if (empty($clientId) || empty($clientSecret)) {
        die(page('Error', '<p class="err">Set GOOGLE_OAUTH_CLIENT_ID and GOOGLE_OAUTH_CLIENT_SECRET in config.php first.</p>'));
    }

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'code'          => $_GET['code'],
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (!empty($data['refresh_token'])) {
        $token = htmlspecialchars($data['refresh_token']);
        echo page('Authorization Successful', <<<HTML
            <div class="success">
                <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg>
                Authorization successful!
            </div>
            <p>Copy the refresh token below and paste it into <code>config.php</code> as <code>GOOGLE_OAUTH_REFRESH_TOKEN</code>:</p>
            <textarea id="token" onclick="this.select()">{$token}</textarea>
            <button onclick="navigator.clipboard.writeText(document.getElementById('token').value);this.textContent='Copied!'">Copy to clipboard</button>
            <div class="note">
                <strong>Next steps:</strong><br>
                1. Open <code>core/config/config.php</code><br>
                2. Set <code>GOOGLE_OAUTH_REFRESH_TOKEN</code> to the token above<br>
                3. Delete or restrict access to this file (<code>scripts/get_drive_token.php</code>)
            </div>
        HTML);
    } elseif (isset($_GET['error'])) {
        $err = htmlspecialchars($_GET['error_description'] ?? $_GET['error']);
        echo page('Authorization Denied', "<p class=\"err\">Google returned an error: <strong>{$err}</strong></p><p><a href=\"{$redirectUri}\">Try again</a></p>");
    } else {
        $detail = htmlspecialchars($data['error_description'] ?? $response);
        echo page('Token Exchange Failed', "<p class=\"err\">{$detail}</p><pre>" . htmlspecialchars($response) . "</pre><p><a href=\"{$redirectUri}\">Try again</a></p>");
    }
    exit;
}

// ── Step 1: Show authorization button ────────────────────────────────────────
if (empty($clientId) || empty($clientSecret)) {
    echo page('Setup Required', '<p class="err">Set <code>GOOGLE_OAUTH_CLIENT_ID</code> and <code>GOOGLE_OAUTH_CLIENT_SECRET</code> in <code>config.php</code> before running this script.</p>');
    exit;
}

$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => $scope,
    'access_type'   => 'offline',
    'prompt'        => 'consent',  // required to always return a refresh_token
]);

$authUrlEsc  = htmlspecialchars($authUrl);
$redirectEsc = htmlspecialchars($redirectUri);

echo page('Authorize Google Drive', <<<HTML
    <p>Click the button below to authorize this app to upload files to your Google Drive.</p>
    <p>You only need to do this <strong>once</strong>.</p>
    <a href="{$authUrlEsc}" class="btn">Authorize with Google</a>
    <div class="note">
        <strong>Before clicking:</strong> make sure this exact redirect URI is added in Google Cloud Console
        under your OAuth2 client → Authorized Redirect URIs:<br><br>
        <code>{$redirectEsc}</code>
    </div>
HTML);

// ── Helper: render a simple page ─────────────────────────────────────────────
function page(string $title, string $body): string {
    return <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>{$title} — UFM Drive Setup</title>
        <style>
            body  { font-family: -apple-system, sans-serif; max-width: 600px; margin: 60px auto; padding: 0 20px; color: #1f2937; }
            h1    { font-size: 1.4rem; margin-bottom: 1rem; }
            code  { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; font-size: .85em; }
            pre   { background: #f3f4f6; padding: 12px; border-radius: 6px; overflow-x: auto; font-size: .8em; }
            textarea { width: 100%; height: 90px; padding: 10px; font-family: monospace; font-size: .8em; border: 1px solid #d1d5db; border-radius: 6px; resize: vertical; margin: 8px 0; }
            button { background: #2563eb; color: #fff; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: .9em; }
            button:hover { background: #1d4ed8; }
            .btn  { display: inline-block; background: #2563eb; color: #fff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-size: 1rem; font-weight: 600; margin: 12px 0; }
            .btn:hover { background: #1d4ed8; }
            .success { display: flex; align-items: center; gap: 8px; color: #15803d; font-weight: 600; font-size: 1.1rem; margin-bottom: 12px; }
            .err  { color: #dc2626; background: #fef2f2; padding: 12px; border-radius: 6px; border: 1px solid #fecaca; }
            .note { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 14px; margin-top: 16px; font-size: .88em; line-height: 1.6; }
        </style>
    </head>
    <body>
        <h1>{$title}</h1>
        {$body}
    </body>
    </html>
    HTML;
}
