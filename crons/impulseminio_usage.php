<?php
define('ROOTDIR', dirname(__DIR__) . '/httpdocs');
require_once ROOTDIR . '/init.php';
require_once ROOTDIR . '/includes/hooks/impulseminio_usage_sync.php';
run_hook('CronJob', []);
