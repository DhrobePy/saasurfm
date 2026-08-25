<?php
/**
 * Delivery Schedule Alert — cron entry point, twice daily.
 * cPanel Cron Jobs:
 *   0 11 * * * /usr/local/bin/php /home/ujjalfmc/public_html/saas.ujjalfm.com/scripts/delivery_schedule_alert.php
 *   0 18 * * * /usr/local/bin/php /home/ujjalfmc/public_html/saas.ujjalfm.com/scripts/delivery_schedule_alert.php
 *
 * Posts orders due for delivery today + overdue deliveries (all branches) to
 * the production Telegram group. See sendDeliveryScheduleAlert() in
 * core/functions/helpers.php for the query/message logic.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../core/init.php';

$sent = sendDeliveryScheduleAlert();
fwrite(STDOUT, ($sent ? 'Delivery schedule alert sent.' : 'Not sent (Telegram disabled or error).') . "\n");
exit($sent ? 0 : 1);
