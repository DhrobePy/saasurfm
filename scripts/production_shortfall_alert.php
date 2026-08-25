<?php
/**
 * Hourly Production Shortfall Check — cron entry point.
 * cPanel Cron Job (every hour, 3am through 11am):
 *   0 3-11 * * * /usr/local/bin/php /home/ujjalfmc/public_html/saas.ujjalfm.com/scripts/production_shortfall_alert.php
 *
 * Also fires as a fallback (rate-limited to once/hour, 3am-11am only) when
 * anyone opens cr/production_requirement.php — see sendProductionShortfallAlert()
 * in core/functions/helpers.php for the shared logic and dedup rules.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../core/init.php';

$sent = sendProductionShortfallAlert(true);   // force: cron always checks/sends
fwrite(STDOUT, ($sent ? 'Shortfall alert sent.' : 'Not sent (nothing due today, Telegram disabled, or error).') . "\n");
exit(0);
