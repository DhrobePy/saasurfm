<?php
/**
 * Daily Owner Digest — cron entry point.
 * Add a cPanel cron job (once each morning, e.g. 7:00):
 *   php /home/ujjalfmc/public_html/saas.ujjalfm.com/scripts/daily_digest.php
 *
 * Also fires automatically (once/day, after 6am) when an admin opens the
 * dashboard — so it works even without a cron job configured.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../core/init.php';

$sent = sendDailyOwnerDigest(true);   // force: cron always sends today's digest
fwrite(STDOUT, ($sent ? 'Digest sent.' : 'Digest NOT sent (Telegram disabled or error).') . "\n");
exit($sent ? 0 : 1);
