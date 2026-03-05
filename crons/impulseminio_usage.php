<?php
/**
 * ImpulseMinio Usage Sync — Cron Runner
 *
 * Standalone cron entry point for hourly usage sync.
 * Add to crontab: 0 * * * * /path/to/php -f /path/to/crons/impulseminio_usage.php
 *
 * @package ImpulseMinio
 * @version 1.0.0
 */
define('ROOTDIR', dirname(__DIR__) . '/httpdocs');
require_once ROOTDIR . '/init.php';
require_once ROOTDIR . '/includes/hooks/impulseminio_usage_sync.php';
run_hook('CronJob', []);
