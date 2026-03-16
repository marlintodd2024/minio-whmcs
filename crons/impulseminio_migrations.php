<?php
/**
 * ImpulseMinio — Migration Watchdog Cron
 *
 * Detects interrupted migrations (PID no longer running) and updates status.
 * Run every 5 minutes via crontab.
 *
 * Usage: /opt/plesk/php/8.3/bin/php /var/www/vhosts/impulsehosting.com/crons/impulseminio_migrations.php
 */
define('ROOTDIR', dirname(__DIR__) . '/httpdocs');
require_once ROOTDIR . '/init.php';
require_once ROOTDIR . '/modules/servers/impulseminio/lib/Migration.php';

$interrupted = \WHMCS\Module\Server\ImpulseMinio\Migration::cronCheckInterrupted();

if ($interrupted > 0) {
    logActivity("ImpulseMinio: Detected {$interrupted} interrupted migration(s).");
}
