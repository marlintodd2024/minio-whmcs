<?php
/**
 * ImpulseMinio — Custom Domain Status Polling Cron
 *
 * Polls Cloudflare for status updates on pending custom hostnames.
 * Run every 15 minutes via crontab.
 *
 * Usage: /opt/plesk/php/8.3/bin/php /var/www/vhosts/impulsehosting.com/crons/impulseminio_domains.php
 */
define('ROOTDIR', dirname(__DIR__) . '/httpdocs');
require_once ROOTDIR . '/init.php';
require_once ROOTDIR . '/modules/servers/impulseminio/lib/PublicAccess.php';

$checked = \WHMCS\Module\Server\ImpulseMinio\PublicAccess::cronPollPendingHostnames();

if ($checked > 0) {
    logActivity("ImpulseMinio: Polled {$checked} pending custom hostname(s).");
}
