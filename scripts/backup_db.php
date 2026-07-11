#!/usr/bin/env php
<?php
/**
 * UFM ERP — Database Backup Cron Script
 *
 * TWO-LAYER backup strategy:
 *   Layer 1: Local disk  (always runs — safe even if Drive is broken)
 *   Layer 2: Google Drive (optional — skipped gracefully if not configured)
 *
 * Add to cPanel Cron Jobs (every 30 minutes):
 *   Command:  /usr/local/bin/php /home/<user>/public_html/scripts/backup_db.php
 *   Minute:   * /30   Hour: *   Day: *   Month: *   Weekday: *
 *
 * Or Linux crontab -e:
 *   * /30 * * * * /usr/bin/php /home/<user>/public_html/scripts/backup_db.php >> /var/log/ufm_backup.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script must be run from the command line.');
}

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/core/init.php';

$log = fn(string $msg) => fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL);
$err = fn(string $msg) => fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $msg . PHP_EOL);

// ── Local backup directory ────────────────────────────────────────────────────
// Default: a "db_backups" folder one level above public_html (outside web root)
$localDir = defined('DB_LOCAL_BACKUP_DIR') && DB_LOCAL_BACKUP_DIR
    ? DB_LOCAL_BACKUP_DIR
    : dirname(ROOT_PATH) . '/db_backups';

$localKeepDays = defined('DB_LOCAL_BACKUP_KEEP_DAYS') ? (int)DB_LOCAL_BACKUP_KEEP_DAYS : 7;

$log("Starting DB backup — local dir: $localDir  |  keep: {$localKeepDays} days");

// ── Google Drive (optional) ──────────────────────────────────────────────────
// Two auth modes, tried in order of durability:
//   1. Service account — tokens NEVER expire, no user consent. Only works when
//      the backup folder lives on a Shared Drive (a service account has no
//      storage quota of its own, so it cannot own files in a personal My Drive).
//   2. OAuth2 refresh token — works with a personal Drive, but the token expires
//      after 7 days while the OAuth consent screen is in "Testing" mode. Publish
//      the app to "Production" in Google Cloud Console to stop the expiry.
$drive = null;

if (defined('GOOGLE_DRIVE_ENABLED') && GOOGLE_DRIVE_ENABLED) {
    $cid    = defined('GOOGLE_OAUTH_CLIENT_ID')     ? GOOGLE_OAUTH_CLIENT_ID     : '';
    $sec    = defined('GOOGLE_OAUTH_CLIENT_SECRET') ? GOOGLE_OAUTH_CLIENT_SECRET : '';
    $ref    = defined('GOOGLE_OAUTH_REFRESH_TOKEN') ? GOOGLE_OAUTH_REFRESH_TOKEN : '';
    $saJson = defined('GOOGLE_SERVICE_ACCOUNT_JSON') ? GOOGLE_SERVICE_ACCOUNT_JSON : '';

    // 1) Use OAuth2 when a refresh token is configured. This is the only mode
    //    that works with a personal My Drive folder, so it stays the primary
    //    path and is never overridden by the service account below.
    if ($cid && $sec && $ref) {
        try {
            $drive = new GoogleDriveService($cid, $sec, $ref);
            $log("Drive auth: OAuth2 (refresh token — expires in 7 days unless the OAuth app is Published)");
        } catch (Throwable $e) {
            $log("Drive: OAuth2 init failed ({$e->getMessage()}).");
        }
    }

    // 2) Fall back to the service account ONLY when no OAuth token is set.
    //    Never expires — but requires the backup folder to be a Shared Drive.
    //    If OAuth is present-but-expired, leave it as-is: re-auth or publish the
    //    app instead, so a personal-Drive setup is not silently broken.
    if ($drive === null && $saJson && is_readable($saJson)) {
        try {
            $drive = GoogleDriveService::fromServiceAccount($saJson);
            $log("Drive auth: service account (no-expiry — requires Shared Drive folder)");
        } catch (Throwable $e) {
            $log("Drive: service account unavailable ({$e->getMessage()}).");
        }
    }

    if ($drive === null) {
        $log("Drive: no usable credentials — Drive upload skipped.");
    }
}

if ($drive === null) {
    $log("Drive: not configured — running LOCAL BACKUP ONLY.");
}

// ── Run Backup ────────────────────────────────────────────────────────────────
$folderId = defined('GOOGLE_DRIVE_BACKUP_FOLDER_ID') ? GOOGLE_DRIVE_BACKUP_FOLDER_ID : '';

try {
    $backup = new DatabaseBackupService(
        drive:         $drive,
        folderId:      $folderId,
        keepCount:     defined('DB_BACKUP_KEEP_COUNT') ? (int)DB_BACKUP_KEEP_COUNT : 500,
        localDir:      $localDir,
        localKeepDays: $localKeepDays
    );

    $result = $backup->runBackup();

    $log($result['ok'] ? "SUCCESS: {$result['message']}" : "FAILED: {$result['message']}");

    if (!empty($result['local_path'])) {
        $log("Local file: {$result['local_path']} (" . round($result['size_bytes'] / 1024, 1) . " KB)");
    }
    if (!empty($result['drive_id'])) {
        $log("Drive file ID: {$result['drive_id']}");
    }
    if (!empty($result['warnings'])) {
        foreach ($result['warnings'] as $w) $log("WARN: $w");
    }

    $log("Duration: " . (strtotime($result['finished_at']) - strtotime($result['started_at'])) . "s");

    exit($result['ok'] ? 0 : 1);

} catch (Throwable $e) {
    $err("Exception: " . $e->getMessage());
    exit(1);
}