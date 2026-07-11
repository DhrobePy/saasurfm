<?php
require_once dirname(__DIR__) . '/core/init.php';
restrict_access(['Superadmin', 'admin']);

header('Content-Type: text/plain; charset=utf-8');

echo "=== UFM Drive Diagnostics ===\n\n";

// 1. Service account JSON path
$saPath = defined('GOOGLE_SERVICE_ACCOUNT_JSON') ? GOOGLE_SERVICE_ACCOUNT_JSON : '(constant not defined)';
echo "1. SA JSON constant : " . (defined('GOOGLE_SERVICE_ACCOUNT_JSON') ? 'DEFINED' : 'NOT DEFINED') . "\n";
echo "   SA JSON path     : $saPath\n";
echo "   File exists      : " . (file_exists($saPath) ? 'YES' : 'NO') . "\n\n";

// 2. fromServiceAccount()
if (file_exists($saPath)) {
    try {
        $drive = GoogleDriveService::fromServiceAccount($saPath);
        echo "2. fromServiceAccount() : OK\n\n";

        // 3. Try fetching a token / listing files
        echo "3. Listing Drive folder (token fetch + API call)...\n";
        try {
            $files = $drive->listFiles(GOOGLE_DRIVE_BACKUP_FOLDER_ID, 1);
            echo "   listFiles() : OK — folder is accessible, " . count($files) . " file(s) returned\n\n";
        } catch (Throwable $e) {
            echo "   listFiles() ERROR : " . $e->getMessage() . "\n\n";
        }

    } catch (Throwable $e) {
        echo "2. fromServiceAccount() ERROR : " . $e->getMessage() . "\n\n";
    }
} else {
    echo "2. fromServiceAccount() SKIPPED — file not found\n\n";
}

// 4. OAuth2 fallback values
echo "4. OAuth2 fallback values:\n";
echo "   CLIENT_ID     : " . (GOOGLE_OAUTH_CLIENT_ID     ? substr(GOOGLE_OAUTH_CLIENT_ID, 0, 20) . '...' : '(empty)') . "\n";
echo "   CLIENT_SECRET : " . (GOOGLE_OAUTH_CLIENT_SECRET ? '(set)' : '(empty)') . "\n";
echo "   REFRESH_TOKEN : " . (GOOGLE_OAUTH_REFRESH_TOKEN ? '(set)' : '(empty)') . "\n\n";

// 5. Drive folder ID
echo "5. GOOGLE_DRIVE_BACKUP_FOLDER_ID : " . (GOOGLE_DRIVE_BACKUP_FOLDER_ID ?: '(empty!)') . "\n\n";

// 6. Telegram test
echo "6. Telegram notification test...\n";
echo "   BOT_TOKEN  : " . (TELEGRAM_BOT_TOKEN ? '(set)' : '(empty)') . "\n";
echo "   CHAT_ID    : " . (TELEGRAM_CHAT_ID   ?: '(empty)') . "\n";
echo "   ENABLED    : " . (TELEGRAM_NOTIFICATIONS_ENABLED ? 'true' : 'false') . "\n";
try {
    $notifier = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);
    $ok = $notifier->sendMessage("🔧 UFM ERP — Drive diagnostic test at " . date('Y-m-d H:i:s'));
    echo "   sendMessage() : " . ($ok ? 'OK — check Telegram now' : 'FAILED (returned false)') . "\n\n";
} catch (Throwable $e) {
    echo "   sendMessage() ERROR : " . $e->getMessage() . "\n\n";
}

echo "=== Done ===\n";