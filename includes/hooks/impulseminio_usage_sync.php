<?php
/**
 * ImpulseMinio Usage Sync Hook
 *
 * Runs hourly via dedicated cron to update storage AND bandwidth usage
 * for all active ImpulseDrive services.
 *
 * Storage: queries MinIO via `mc du --json` for each service's buckets
 * Bandwidth: fetches cumulative monthly stats from MinIO server's Nginx log parser
 * History: writes snapshot rows to mod_impulseminio_usage_history for Statistics tab
 *
 * Writes to tblhosting.diskusage (storage MB) and tblhosting.bwusage (bandwidth MB)
 *
 * Place in: /includes/hooks/impulseminio_usage_sync.php
 * Cron wrapper: /crons/impulseminio_usage.php
 *
 * @package ImpulseMinio
 * @version 3.0.0
 */

use WHMCS\Database\Capsule;

add_hook('CronJob', 1, function () {

    $logFile = '/var/www/vhosts/impulsehosting.com/logs/impulseminio_usage_sync.log';
    $lockFile = '/tmp/impulseminio_usage_sync.lock';
    $mcPath = '/usr/local/bin/mc';
    $mcConfigDir = '/tmp/.mc-impulse-usage';
    $mcAlias = 'impulse_usage';
    $bwStatsUrl = 'https://us-central-dallas.impulsedrive.io/___impulse_bw_stats_ImpulseBW2026StatsKey';

    // ─── Hourly gate: skip if last run was < 55 minutes ago ───
    if (file_exists($lockFile)) {
        $lastRun = (int) file_get_contents($lockFile);
        if ((time() - $lastRun) < 3300) {
            return;
        }
    }
    file_put_contents($lockFile, time());

    $log = function ($msg) use ($logFile) {
        $ts = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$ts}] {$msg}\n", FILE_APPEND);
    };

    $log('=== ImpulseMinio Usage Sync Started ===');

    // ─── Get MinIO server credentials ───
    try {
        $server = Capsule::table('tblservers')
            ->where('type', 'impulseminio')
            ->where('disabled', 0)
            ->first();

        if (!$server) {
            $log('ERROR: No active ImpulseMinio server found in tblservers');
            return;
        }

        $endpoint = ($server->secure ? 'https://' : 'http://') . $server->hostname;
        $accessKey = $server->username;
        /** @phpstan-ignore function.notFound */
        $secretKey = decrypt($server->password);

        if (empty($accessKey) || empty($secretKey)) {
            $log('ERROR: Server credentials empty after decryption');
            return;
        }
    } catch (\Exception $e) {
        $log('ERROR: Failed to load server config: ' . $e->getMessage());
        return;
    }

    // ─── Set up mc alias ───
    $env = "MC_CONFIG_DIR={$mcConfigDir} HOME=/tmp";
    $aliasCmd = "{$env} {$mcPath} alias set {$mcAlias} "
        . escapeshellarg($endpoint) . ' '
        . escapeshellarg($accessKey) . ' '
        . escapeshellarg($secretKey) . ' 2>&1';

    exec($aliasCmd, $aliasOutput, $aliasExit);
    if ($aliasExit !== 0) {
        $log('ERROR: Failed to set mc alias: ' . implode("\n", $aliasOutput));
        return;
    }
    $log('mc alias configured for ' . $server->hostname);

    // ─── Fetch stats from MinIO server (bandwidth + Prometheus metrics) ───
    $bwData = [];
    try {
        $ctx = stream_context_create([
            'http' => ['timeout' => 10],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
        ]);
        $bwJson = @file_get_contents($bwStatsUrl, false, $ctx);
        if ($bwJson !== false) {
            $bwParsed = json_decode($bwJson, true);
            if ($bwParsed && isset($bwParsed['buckets'])) {
                $bwData = $bwParsed['buckets'];
                $log('Stats fetched: ' . count($bwData) . ' bucket(s) for month ' . ($bwParsed['month'] ?? 'unknown'));
            } else {
                $log('WARNING: Stats JSON invalid or missing buckets key');
            }
        } else {
            $log('WARNING: Failed to fetch stats from endpoint');
        }
    } catch (\Exception $e) {
        $log('WARNING: Stats fetch error: ' . $e->getMessage());
    }

    // ─── Get all active ImpulseMinio services ───
    try {
        $services = Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'h.packageid', '=', 'p.id')
            ->where('p.servertype', 'impulseminio')
            ->where('h.domainstatus', 'Active')
            ->select('h.id', 'h.disklimit', 'h.bwlimit', 'p.name')
            ->get();
    } catch (\Exception $e) {
        $log('ERROR: Failed to query services: ' . $e->getMessage());
        return;
    }

    if ($services->isEmpty()) {
        $log('No active ImpulseMinio services found. Done.');
        return;
    }

    $log('Found ' . $services->count() . ' active service(s)');
    $updatedCount = 0;
    $errorCount = 0;

    foreach ($services as $service) {
        $serviceId = $service->id;

        // Get all buckets for this service
        try {
            $buckets = Capsule::table('mod_impulseminio_buckets')
                ->where('service_id', $serviceId)
                ->pluck('bucket_name');
        } catch (\Exception $e) {
            $log("  ERROR [service {$serviceId}]: Failed to query buckets: " . $e->getMessage());
            $errorCount++;
            continue;
        }

        if ($buckets->isEmpty()) {
            Capsule::table('tblhosting')->where('id', $serviceId)->update([
                'diskusage' => 0,
                'bwusage' => 0
            ]);
            $log("  [service {$serviceId}] No buckets, usage set to 0");
            $updatedCount++;
            continue;
        }

        // ─── Storage + Objects: mc du per bucket ───
        $totalBytes = 0;
        $totalObjects = 0;
        $bucketErrors = false;

        foreach ($buckets as $bucketName) {
            $duCmd = "{$env} {$mcPath} du --json {$mcAlias}/{$bucketName} 2>&1";
            $duOutput = [];
            $duExit = 0;
            exec($duCmd, $duOutput, $duExit);

            $jsonStr = implode('', $duOutput);
            $data = json_decode($jsonStr, true);

            if ($duExit !== 0 || !$data || ($data['status'] ?? '') !== 'success') {
                $log("  WARNING [service {$serviceId}]: mc du failed for bucket '{$bucketName}': {$jsonStr}");
                $bucketErrors = true;
                continue;
            }

            $totalBytes += (int) ($data['size'] ?? 0);
            $totalObjects += (int) ($data['objects'] ?? 0);
        }

        // ─── Aggregate stats from endpoint (bandwidth + Prometheus) ───
        $totalBwSent = 0;
        $totalBwReceived = 0;
        $totalBwRequests = 0;
        $totalReplicationRx = 0;
        $totalReplicationTx = 0;

        foreach ($buckets as $bucketName) {
            if (isset($bwData[$bucketName])) {
                $bd = $bwData[$bucketName];
                // Nginx-tracked egress (cumulative monthly)
                $totalBwSent += (int) ($bd['bytes_sent'] ?? 0);
                $totalBwRequests += (int) ($bd['requests'] ?? 0);
                // Prometheus metrics (since MinIO start)
                $totalBwReceived += (int) ($bd['traffic_received_bytes'] ?? 0);
                $totalReplicationRx += (int) ($bd['replication_received_bytes'] ?? 0);
                // Note: replication_sent not available per-bucket in Prometheus
                // Use traffic_sent from Prometheus as fallback for downloads
                // but prefer Nginx bytes_sent for accuracy
            }
        }

        // Convert to MB (WHMCS convention)
        $diskUsageMB = (int) ceil($totalBytes / 1048576);
        $bwUsageMB = (int) ceil($totalBwSent / 1048576);

        try {
            // Update tblhosting usage fields
            Capsule::table('tblhosting')
                ->where('id', $serviceId)
                ->update([
                    'diskusage' => $diskUsageMB,
                    'bwusage' => $bwUsageMB,
                    'lastupdate' => Capsule::raw('NOW()'),
                ]);

            // Write history snapshot for Statistics tab
            Capsule::table('mod_impulseminio_usage_history')->insert([
                'service_id' => $serviceId,
                'storage_bytes' => $totalBytes,
                'bandwidth_sent_bytes' => $totalBwSent,
                'bandwidth_received_bytes' => $totalBwReceived,
                'object_count' => $totalObjects,
                'replication_received_bytes' => $totalReplicationRx,
                'replication_sent_bytes' => $totalReplicationTx,
                'recorded_at' => date('Y-m-d H:i:s'),
            ]);

            $diskGB = round($totalBytes / 1073741824, 2);
            $diskLimitGB = round($service->disklimit / 1024, 1);
            $diskPct = $service->disklimit > 0 ? round(($diskUsageMB / $service->disklimit) * 100, 1) : 0;

            $bwGB = round($totalBwSent / 1073741824, 2);
            $bwLimitGB = round($service->bwlimit / 1024, 1);
            $bwPct = $service->bwlimit > 0 ? round(($bwUsageMB / $service->bwlimit) * 100, 1) : 0;

            $log("  [service {$serviceId}] Storage: {$diskGB} GB / {$diskLimitGB} GB ({$diskPct}%) — {$totalObjects} objects across " . $buckets->count() . " bucket(s)" . ($bucketErrors ? ' [some bucket errors]' : ''));
            $log("  [service {$serviceId}] Bandwidth: {$bwGB} GB / {$bwLimitGB} GB ({$bwPct}%) — {$totalBwRequests} requests this month");
            $log("  [service {$serviceId}] History snapshot written (uploads: " . round($totalBwReceived / 1048576) . " MB, repl_rx: " . round($totalReplicationRx / 1048576) . " MB)");
            $updatedCount++;
        } catch (\Exception $e) {
            $log("  ERROR [service {$serviceId}]: Failed to update usage: " . $e->getMessage());
            $errorCount++;
        }
    }

    // ─── Prune old history (keep 90 days) ───
    try {
        $pruneDate = date('Y-m-d H:i:s', strtotime('-90 days'));
        $deleted = Capsule::table('mod_impulseminio_usage_history')
            ->where('recorded_at', '<', $pruneDate)
            ->delete();
        if ($deleted > 0) {
            $log("Pruned {$deleted} history rows older than 90 days");
        }
    } catch (\Exception $e) {
        $log('WARNING: History prune failed: ' . $e->getMessage());
    }

    $log("=== Usage Sync Complete: {$updatedCount} updated, {$errorCount} errors ===");
    $log('');
});
