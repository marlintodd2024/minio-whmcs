<?php
/**
 * ImpulseMinio — MinIO S3 Cloud Storage Provisioning Module for WHMCS
 *
 * Provides automated provisioning of MinIO storage accounts with multi-bucket
 * management, scoped access keys, usage tracking, and a 4-tab client dashboard.
 *
 * @package    ImpulseMinio
 * @version    2.1.0
 * @author     Impulse Hosting
 * @license    GPL-3.0
 * @link       https://github.com/ImpulseHosting/impulseminio
 */
if (!defined('WHMCS')) die('Access denied.');

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\ImpulseMinio\MinioClient;

spl_autoload_register(function ($class) {
    $prefix = 'WHMCS\\Module\\Server\\ImpulseMinio\\';
    $baseDir = __DIR__ . '/lib/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $file = $baseDir . str_replace('\\', '/', substr($class, $len)) . '.php';
    if (file_exists($file)) require $file;
});

// =============================================================================
// DB TABLE SETUP — creates custom tables on first use
// =============================================================================
/**
 * Create module database tables if they do not exist.
 *
 * Tables: mod_impulseminio_buckets, mod_impulseminio_accesskeys
 *
 * @return void
 */
function impulseminio_ensureTables(): void
{
    if (!Capsule::schema()->hasTable('mod_impulseminio_buckets')) {
        Capsule::schema()->create('mod_impulseminio_buckets', function ($t) {
            $t->increments('id');
            $t->unsignedInteger('service_id')->index();
            $t->string('bucket_name', 63)->unique();
            $t->string('label', 100)->default('');
            $t->boolean('is_primary')->default(false);
            $t->timestamps();
        });
    }
    if (!Capsule::schema()->hasTable('mod_impulseminio_accesskeys')) {
        Capsule::schema()->create('mod_impulseminio_accesskeys', function ($t) {
            $t->increments('id');
            $t->unsignedInteger('service_id')->index();
            $t->string('access_key', 128);
            $t->string('name', 100)->default('');
            $t->text('bucket_scope')->nullable(); // JSON array of bucket names, null = all
            $t->timestamps();
        });
    }
}

// =============================================================================
// METADATA & CONFIG
// =============================================================================
/**
 * Module metadata for WHMCS.
 *
 * @return array{DisplayName: string, APIVersion: string}
 */
function impulseminio_MetaData(): array
{
    return ['DisplayName' => 'ImpulseDrive Cloud Storage', 'APIVersion' => '1.1', 'RequiresServer' => true, 'DefaultNonSSLPort' => '9000', 'DefaultSSLPort' => '443'];
}

/**
 * Define configurable options for product setup.
 *
 * Options: Disk Quota, Bandwidth Limit, Max Buckets, Max Access Keys,
 * Overage Rate, S3 Endpoint, Console URL, mc Binary Path, Bucket Prefix,
 * Enable Versioning.
 *
 * @return array<int, array{FriendlyName: string, Type: string, Size?: int, Default?: string, Description?: string}>
 */
function impulseminio_ConfigOptions(): array
{
    return [
        // === PER-PLAN SETTINGS (what differentiates products) ===
        // configoption1
        'Disk Quota (GB)' => [
            'Type'        => 'text',
            'Size'        => '10',
            'Default'     => '50',
            'Description' => 'Storage quota in GB. Set to 0 for unlimited.',
        ],
        // configoption2
        'Bandwidth Limit (GB/month)' => [
            'Type'        => 'text',
            'Size'        => '10',
            'Default'     => '500',
            'Description' => 'Monthly egress bandwidth limit in GB. Set to 0 for unlimited.',
        ],
        // configoption3
        'Max Buckets' => [
            'Type'        => 'text',
            'Size'        => '5',
            'Default'     => '5',
            'Description' => 'Maximum buckets a customer can create. Set to 0 for unlimited.',
        ],
        // configoption4
        'Max Access Keys' => [
            'Type'        => 'text',
            'Size'        => '5',
            'Default'     => '10',
            'Description' => 'Maximum access keys a customer can create. Set to 0 for unlimited.',
        ],
        // configoption5
        'Overage Rate ($/GB)' => [
            'Type'        => 'text',
            'Size'        => '10',
            'Default'     => '0.00',
            'Description' => 'Bandwidth overage rate per GB beyond the limit. Set to 0 to disable overage billing.',
        ],
        // === INFRASTRUCTURE SETTINGS (same across all products typically) ===
        // configoption6
        'S3 Endpoint URL' => [
            'Type'        => 'text',
            'Size'        => '50',
            'Default'     => '',
            'Description' => 'Public S3 endpoint URL shown to customers (e.g. https://us-central-dallas.impulsedrive.io). Leave blank to use server hostname.',
        ],
        // configoption7
        'Console URL' => [
            'Type'        => 'text',
            'Size'        => '50',
            'Default'     => '',
            'Description' => 'MinIO Console URL for customer access (e.g. https://console.impulsedrive.io). Leave blank to hide.',
        ],
        // configoption8
        'mc Binary Path' => [
            'Type'        => 'text',
            'Size'        => '50',
            'Default'     => '/usr/local/bin/mc',
            'Description' => 'Full path to MinIO mc CLI binary on the WHMCS server.',
        ],
        // configoption9
        'Bucket Name Prefix' => [
            'Type'        => 'text',
            'Size'        => '25',
            'Default'     => '',
            'Description' => 'Optional prefix for auto-generated bucket names (e.g. "drive-"). Leave blank for default.',
        ],
        // configoption10
        'Enable Versioning' => [
            'Type'        => 'yesno',
            'Description' => 'Enable S3 object versioning on customer buckets.',
        ],
    ];
}

// =============================================================================
// HELPERS
// =============================================================================
/**
 * Build a MinioClient instance from module params.
 *
 * @param  array $params WHMCS module parameters
 * @return MinioClient
 */
function impulseminio_getClient(array $params): MinioClient
{
    $hostname = $params['serverhostname'] ?: $params['serverip'];
    $port = $params['serverport'] ?: ($params['serversecure'] ? '443' : '9000');
    $protocol = $params['serversecure'] ? 'https' : 'http';
    $endpoint = ($params['serversecure'] && $port === '443') ? $protocol . '://' . $hostname : $protocol . '://' . $hostname . ':' . $port;
    return new MinioClient($endpoint, $params['serverusername'], $params['serverpassword'], $params['configoption8'] ?: '/usr/local/bin/mc', (bool)$params['serversecure']);
}

/**
 * Derive the MinIO username for a service.
 *
 * Format: {prefix}-{serviceid}-{clientname} (lowercased, sanitised)
 *
 * @param  array  $params WHMCS module parameters
 * @return string MinIO username
 */
function impulseminio_getUsername(array $params): string
{
    $stored = $params['model']->serviceProperties->get('MinIO Username');
    return $stored ?: MinioClient::generateUsername((int)$params['serviceid'], $params['clientsdetails']['email']);
}

/**
 * Derive the primary bucket name for a service.
 *
 * @param  array  $params WHMCS module parameters
 * @return string Bucket name
 */
function impulseminio_getBucket(array $params): string
{
    $stored = $params['model']->serviceProperties->get('Bucket Name');
    if ($stored) return $stored;
    $prefix = trim($params['configoption9']);
    $username = impulseminio_getUsername($params);
    return $prefix ? MinioClient::bucketName($prefix . $username) : MinioClient::bucketName($username);
}

/** Get all bucket names for a service from our tracking table */
/**
 * Get all tracked buckets for a service from the database.
 *
 * @param  int   $serviceId WHMCS service (hosting) ID
 * @return array Database rows from mod_impulseminio_buckets
 */
function impulseminio_getAllBuckets(int $serviceId): array
{
    return Capsule::table('mod_impulseminio_buckets')
        ->where('service_id', $serviceId)
        ->pluck('bucket_name')
        ->toArray();
}

/** Rebuild the user policy to include all current buckets */
/**
 * Rebuild the IAM policy for a user to cover all their buckets.
 *
 * @param  array $params WHMCS module parameters
 * @return array{success: bool, error?: string}
 */
function impulseminio_rebuildFullPolicy(array $params): array
{
    $client = impulseminio_getClient($params);
    $username = impulseminio_getUsername($params);
    $buckets = impulseminio_getAllBuckets((int)$params['serviceid']);
    if (empty($buckets)) {
        $buckets = [impulseminio_getBucket($params)];
    }
    return $client->createUserPolicy($username, $buckets);
}

// =============================================================================
// CORE MODULE FUNCTIONS
// =============================================================================
/**
 * Provision a new MinIO storage account.
 *
 * Creates: MinIO user, primary bucket, IAM policy, quota, and DB records.
 *
 * @param  array  $params WHMCS module parameters
 * @return string "success" or error message
 */
function impulseminio_CreateAccount(array $params): string
{
    try {
        impulseminio_ensureTables();
        $client = impulseminio_getClient($params);
        $username = impulseminio_getUsername($params);
        $bucket = impulseminio_getBucket($params);
        $password = MinioClient::generatePassword(24);
        $quotaGB = (int)$params['configoption1'];

        $r = $client->createUser($username, $password);
        if (!$r['success']) return 'Failed to create user: ' . $r['error'];

        $r = $client->createBucket($bucket);
        if (!$r['success']) return 'User created but bucket failed: ' . $r['error'];

        // Track the primary bucket
        Capsule::table('mod_impulseminio_buckets')->insert([
            'service_id' => $params['serviceid'], 'bucket_name' => $bucket,
            'label' => 'Default', 'is_primary' => true,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $r = $client->createUserPolicy($username, [$bucket]);
        if (!$r['success']) return 'User+bucket created but policy failed: ' . $r['error'];

        if ($quotaGB > 0) $client->setBucketQuota($bucket, $quotaGB . 'GiB');

        $s3Endpoint = $params['configoption6'] ?: ($params['serversecure'] ? 'https' : 'http') . '://' . ($params['serverhostname'] ?: $params['serverip']);
        $params['model']->serviceProperties->save([
            'MinIO Username' => $username, 'MinIO Password' => $password,
            'Bucket Name' => $bucket, 'S3 Endpoint' => $s3Endpoint,
            'Disk Quota (GB)' => $quotaGB > 0 ? (string)$quotaGB : 'Unlimited',
        ]);
        Capsule::table('tblhosting')->where('id', $params['serviceid'])->update(['username' => $username, 'password' => encrypt($password)]);

        return 'success';
    } catch (\Exception $e) {
        logModuleCall('impulseminio', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Suspend a MinIO account by disabling the user.
 *
 * @param  array  $params WHMCS module parameters
 * @return string "success" or error message
 */
function impulseminio_SuspendAccount(array $params): string
{
    try {
        $client = impulseminio_getClient($params);
        $username = impulseminio_getUsername($params);
        $buckets = impulseminio_getAllBuckets((int)$params['serviceid']) ?: [impulseminio_getBucket($params)];
        $r = $client->disableUser($username);
        if (!$r['success']) return 'Failed to suspend: ' . $r['error'];
        $client->applySuspendedPolicy($username, $buckets);
        return 'success';
    } catch (\Exception $e) {
        logModuleCall('impulseminio', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Unsuspend a MinIO account by re-enabling the user.
 *
 * @param  array  $params WHMCS module parameters
 * @return string "success" or error message
 */
function impulseminio_UnsuspendAccount(array $params): string
{
    try {
        $client = impulseminio_getClient($params);
        $username = impulseminio_getUsername($params);
        $r = $client->enableUser($username);
        if (!$r['success']) return 'Failed to unsuspend: ' . $r['error'];
        $client->removeSuspendedPolicy($username);
        return 'success';
    } catch (\Exception $e) {
        logModuleCall('impulseminio', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Terminate a MinIO account: delete all buckets, keys, user, and DB records.
 *
 * @param  array  $params WHMCS module parameters
 * @return string "success" or error message
 */
function impulseminio_TerminateAccount(array $params): string
{
    try {
        $client = impulseminio_getClient($params);
        $username = impulseminio_getUsername($params);
        $serviceId = (int)$params['serviceid'];

        // Delete all access keys tracked for this service
        $keys = Capsule::table('mod_impulseminio_accesskeys')->where('service_id', $serviceId)->get();
        foreach ($keys as $key) { $client->deleteAccessKey($key->access_key); }
        Capsule::table('mod_impulseminio_accesskeys')->where('service_id', $serviceId)->delete();

        // Delete policies
        $client->deleteUserPolicy($username);
        $client->removeSuspendedPolicy($username);

        // Delete user
        $client->deleteUser($username);

        // Delete all buckets
        $buckets = Capsule::table('mod_impulseminio_buckets')->where('service_id', $serviceId)->get();
        foreach ($buckets as $b) { $client->deleteBucket($b->bucket_name, true); }
        Capsule::table('mod_impulseminio_buckets')->where('service_id', $serviceId)->delete();

        return 'success';
    } catch (\Exception $e) {
        logModuleCall('impulseminio', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Change the MinIO user password/secret key.
 *
 * @param  array  $params WHMCS module parameters
 * @return string "success" or error message
 */
function impulseminio_ChangePassword(array $params): string
{
    try {
        $client = impulseminio_getClient($params);
        $username = impulseminio_getUsername($params);
        $r = $client->createUser($username, $params['password']);
        if (!$r['success']) return 'Failed: ' . $r['error'];
        $params['model']->serviceProperties->save(['MinIO Password' => $params['password']]);
        return 'success';
    } catch (\Exception $e) { return 'Error: ' . $e->getMessage(); }
}

/**
 * Handle plan upgrades/downgrades by updating bucket quotas.
 *
 * @param  array  $params WHMCS module parameters
 * @return string "success" or error message
 */
function impulseminio_ChangePackage(array $params): string
{
    try {
        $client = impulseminio_getClient($params);
        $quotaGB = (int)$params['configoption1'];
        $buckets = impulseminio_getAllBuckets((int)$params['serviceid']) ?: [impulseminio_getBucket($params)];
        foreach ($buckets as $b) {
            if ($quotaGB > 0) $client->setBucketQuota($b, $quotaGB . 'GiB');
            else $client->clearBucketQuota($b);
        }
        $params['model']->serviceProperties->save(['Disk Quota (GB)' => $quotaGB > 0 ? (string)$quotaGB : 'Unlimited']);
        return 'success';
    } catch (\Exception $e) { return 'Error: ' . $e->getMessage(); }
}

// =============================================================================
// USAGE UPDATE
// =============================================================================
/**
 * Update disk and bandwidth usage for all active services.
 *
 * Called by WHMCS daily cron. Writes to tblhosting disk/bandwidth fields.
 *
 * @param  array $params WHMCS module parameters
 * @return void
 */
function impulseminio_UsageUpdate(array $params): void
{
    $serverId = $params['serverid'];
    $services = Capsule::table('tblhosting')->where('server', $serverId)->where('domainstatus', 'Active')->get();
    if ($services->isEmpty()) return;
    $client = impulseminio_getClient($params);

    foreach ($services as $service) {
        try {
            $buckets = impulseminio_getAllBuckets($service->id);
            if (empty($buckets)) {
                $bn = Capsule::table('tblcustomfieldsvalues')->join('tblcustomfields','tblcustomfields.id','=','tblcustomfieldsvalues.fieldid')
                    ->where('tblcustomfields.fieldname','Bucket Name')->where('tblcustomfieldsvalues.relid',$service->id)->value('tblcustomfieldsvalues.value');
                if ($bn) $buckets = [$bn];
            }
            if (empty($buckets)) continue;

            $totalDiskBytes = 0; $totalBwRx = 0; $totalBwTx = 0;
            foreach ($buckets as $b) {
                $du = $client->getBucketUsage($b);
                $totalDiskBytes += $du['sizeBytes'];
                $bw = $client->getBucketBandwidth($b);
                $totalBwRx += $bw['bytesReceived']; $totalBwTx += $bw['bytesSent'];
            }

            $product = Capsule::table('tblproducts')->where('id', $service->packageid)->first();
            $diskLimitGB = (int)($product->configoption1 ?? 0);
            $bwLimitGB = (int)($product->configoption2 ?? 0);

            Capsule::table('tblhosting')->where('id', $service->id)->update([
                'diskusage' => round($totalDiskBytes / (1024*1024), 2),
                'disklimit' => $diskLimitGB * 1024,
                'bwusage' => round(($totalBwRx + $totalBwTx) / (1024*1024), 2),
                'bwlimit' => $bwLimitGB * 1024,
                'lastupdate' => Capsule::raw('NOW()'),
            ]);
        } catch (\Exception $e) {
            logModuleCall('impulseminio', 'UsageUpdate', ['serviceId' => $service->id], $e->getMessage());
        }
    }
}

// =============================================================================
// CLIENT AREA — Renders HTML via string concatenation for Lagom2 compatibility
// =============================================================================
/**
 * Render the client area product detail page.
 *
 * Returns a templatefile + vars array. The template is a simple passthrough
 * that outputs {$moduleOutput nofilter}. All HTML is built in renderClientArea().
 *
 * @param  array $params WHMCS module parameters
 * @return array{templatefile: string, vars: array{moduleOutput: string}}
 */
function impulseminio_ClientArea(array $params): array
{
    impulseminio_ensureTables();
    $html = impulseminio_renderClientArea($params);

    return [
        'templatefile' => 'templates/clientarea',
        'vars' => [
            'moduleOutput' => $html,
        ],
    ];
}

/**
 * Build the client area HTML. Uses string concatenation (not heredoc) for reliability.
 */
/**
 * Build the complete client dashboard HTML.
 *
 * Renders a 4-tab interface (Overview, Buckets, Access Keys, Quick Start)
 * using PHP string concatenation for Lagom2 theme compatibility.
 *
 * @param  array  $params WHMCS module parameters
 * @return string Complete HTML output
 */
function impulseminio_renderClientArea(array $params = []): string
{
    $serviceId = 0;
    if (!empty($params['serviceid'])) {
        $serviceId = (int) $params['serviceid'];
    }
    if (!$serviceId) {
        return '';
    }

    impulseminio_ensureTables();
    $esc = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

    $username = '';
    $password = '';
    $s3Endpoint = '';
    $consoleUrl = '';
    $maxBuckets = 5;
    $maxKeys = 10;

    if (isset($params['model'])) {
        $username = $params['model']->serviceProperties->get('MinIO Username') ?: '';
        $password = $params['model']->serviceProperties->get('MinIO Password') ?: '';
        $s3Endpoint = $params['model']->serviceProperties->get('S3 Endpoint') ?: '';
        $consoleUrl = $params['configoption7'] ?? '';
        $maxBuckets = (int)($params['configoption3'] ?: 5);
        $maxKeys = (int)($params['configoption4'] ?: 10);
    } else {
        $fields = Capsule::table('tblcustomfieldsvalues')
            ->join('tblcustomfields', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
            ->where('tblcustomfieldsvalues.relid', $serviceId)
            ->pluck('tblcustomfieldsvalues.value', 'tblcustomfields.fieldname');
        $username = $fields['MinIO Username'] ?? '';
        $password = $fields['MinIO Password'] ?? '';
        $s3Endpoint = $fields['S3 Endpoint'] ?? '';
        $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
        if ($service) {
            $product = Capsule::table('tblproducts')->where('id', $service->packageid)->first();
            if ($product) {
                $consoleUrl = $product->configoption7 ?? '';
                $maxBuckets = (int)($product->configoption3 ?: 5);
                $maxKeys = (int)($product->configoption4 ?: 10);
            }
        }
    }

    $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
    $diskUsageMB = $service->diskusage ?? 0;
    $diskLimitMB = $service->disklimit ?? 0;
    $bwUsageMB = $service->bwusage ?? 0;
    $bwLimitMB = $service->bwlimit ?? 0;
    $diskPercent = ($diskLimitMB > 0) ? min(100, round(($diskUsageMB / $diskLimitMB) * 100, 1)) : 0;
    $bwPercent = ($bwLimitMB > 0) ? min(100, round(($bwUsageMB / $bwLimitMB) * 100, 1)) : 0;
    $diskUsage = MinioClient::formatBytes((int)($diskUsageMB * 1024 * 1024));
    $diskLimit = $diskLimitMB > 0 ? MinioClient::formatBytes((int)($diskLimitMB * 1024 * 1024)) : 'Unlimited';
    $bwUsage = MinioClient::formatBytes((int)($bwUsageMB * 1024 * 1024));
    $bwLimit = $bwLimitMB > 0 ? MinioClient::formatBytes((int)($bwLimitMB * 1024 * 1024)) : 'Unlimited';
    $lastUpdate = $esc($service->lastupdate ?? 'Never');

    $buckets = Capsule::table('mod_impulseminio_buckets')
        ->where('service_id', $serviceId)->orderBy('is_primary', 'desc')->orderBy('created_at')->get();
    $bucketCount = count($buckets);
    $defaultBucket = $bucketCount > 0 ? $esc($buckets[0]->bucket_name) : '';

    $accessKeys = Capsule::table('mod_impulseminio_accesskeys')
        ->where('service_id', $serviceId)->orderBy('created_at')->get();
    $keyCount = count($accessKeys);

    $eu = $esc($username);
    $ep = $esc($password);
    $ee = $esc($s3Endpoint);
    $ec = $esc($consoleUrl);

    // Flash message
    $o = '';
    if (!empty($_SESSION['impulseminio_new_key'])) {
        $nk = $_SESSION['impulseminio_new_key'];
        $eak = $esc($nk['accessKey'] ?? '');
        $esk = $esc($nk['secretKey'] ?? '');
        $o .= '<div class="alert alert-success alert-dismissible" id="newKeyAlert">';
        $o .= '<button type="button" class="close" data-dismiss="alert">&times;</button>';
        $o .= '<h4><i class="fas fa-key"></i> Access Key Created Successfully</h4>';
        $o .= '<p><strong>Save these credentials now — the secret key will not be shown again.</strong></p>';
        $o .= '<div class="row" style="margin-top:10px;">';
        $o .= '<div class="col-md-6"><label>Access Key</label><div class="input-group"><input type="text" class="form-control" id="newAccessKey" value="' . $eak . '" readonly><span class="input-group-btn"><button class="btn btn-default" onclick="idCopy(\'newAccessKey\')"><i class="fas fa-copy"></i></button></span></div></div>';
        $o .= '<div class="col-md-6"><label>Secret Key</label><div class="input-group"><input type="text" class="form-control" id="newSecretKey" value="' . $esk . '" readonly><span class="input-group-btn"><button class="btn btn-default" onclick="idCopy(\'newSecretKey\')"><i class="fas fa-copy"></i></button></span></div></div>';
        $o .= '</div></div>';
        unset($_SESSION['impulseminio_new_key']);
    }

    // Tabs
    $o .= '<div class="impulsedrive-dashboard">';
    $o .= '<ul class="nav nav-tabs" role="tablist" style="margin-bottom:20px;">';
    $o .= '<li role="presentation" class="active"><a href="#tab-overview" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> Overview</a></li>';
    $o .= '<li role="presentation"><a href="#tab-buckets" data-toggle="tab"><i class="fas fa-archive"></i> Buckets <span class="badge">' . $bucketCount . '</span></a></li>';
    $o .= '<li role="presentation"><a href="#tab-keys" data-toggle="tab"><i class="fas fa-key"></i> Access Keys <span class="badge">' . $keyCount . '</span></a></li>';
    $o .= '<li role="presentation"><a href="#tab-quickstart" data-toggle="tab"><i class="fas fa-rocket"></i> Quick Start</a></li>';
    $o .= '</ul>';
    $o .= '<div class="tab-content">';

    // === OVERVIEW TAB ===
    $o .= '<div role="tabpanel" class="tab-pane active" id="tab-overview">';

    // Connection Details
    $o .= '<div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title"><i class="fas fa-cloud"></i> S3 Connection Details</h3></div>';
    $o .= '<div class="panel-body">';
    $o .= '<p class="text-muted">Use these credentials with any S3-compatible client.</p>';
    $o .= '<div class="row" style="margin-bottom:15px;">';
    $o .= '<div class="col-md-6"><label>S3 Endpoint</label><div class="input-group"><input type="text" class="form-control" id="s3endpoint" value="' . $ee . '" readonly><span class="input-group-btn"><button class="btn btn-default" onclick="idCopy(\'s3endpoint\')" title="Copy"><i class="fas fa-copy"></i></button></span></div></div>';
    $o .= '<div class="col-md-6"><label>Default Bucket</label><div class="input-group"><input type="text" class="form-control" id="bucketname" value="' . $defaultBucket . '" readonly><span class="input-group-btn"><button class="btn btn-default" onclick="idCopy(\'bucketname\')" title="Copy"><i class="fas fa-copy"></i></button></span></div></div>';
    $o .= '</div>';
    $o .= '<div class="row" style="margin-bottom:15px;">';
    $o .= '<div class="col-md-6"><label>Access Key (Username)</label><div class="input-group"><input type="text" class="form-control" id="accesskey" value="' . $eu . '" readonly><span class="input-group-btn"><button class="btn btn-default" onclick="idCopy(\'accesskey\')" title="Copy"><i class="fas fa-copy"></i></button></span></div></div>';
    $o .= '<div class="col-md-6"><label>Secret Key (Password)</label><div class="input-group"><input type="password" class="form-control" id="secretkey" value="' . $ep . '" readonly><span class="input-group-btn"><button class="btn btn-default" onclick="togglePw(\'secretkey\')" title="Show/Hide"><i class="fas fa-eye" id="secretkey-eye"></i></button><button class="btn btn-default" onclick="idCopy(\'secretkey\')" title="Copy"><i class="fas fa-copy"></i></button></span></div></div>';
    $o .= '</div>';
    $o .= '<div class="row"><div class="col-md-6"><label>Region</label><input type="text" class="form-control" value="us-east-1" readonly></div>';
    if ($consoleUrl) {
        $o .= '<div class="col-md-6"><label>&nbsp;</label><div><a href="' . $ec . '" target="_blank" class="btn btn-primary"><i class="fas fa-external-link-alt"></i> Open Storage Console</a></div></div>';
    }
    $o .= '</div>';
    $o .= '</div></div>';

    // Usage Stats
    $diskColor = $diskPercent > 90 ? 'progress-bar-danger' : ($diskPercent > 75 ? 'progress-bar-warning' : 'progress-bar-success');
    $bwColor = $bwPercent > 90 ? 'progress-bar-danger' : ($bwPercent > 75 ? 'progress-bar-warning' : 'progress-bar-info');
    $o .= '<div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title"><i class="fas fa-chart-bar"></i> Usage Statistics</h3></div>';
    $o .= '<div class="panel-body"><div class="row">';
    $o .= '<div class="col-md-6"><h4>Storage Used</h4><div class="progress" style="height:25px;margin-bottom:5px;"><div class="progress-bar ' . $diskColor . '" role="progressbar" style="width:' . $diskPercent . '%;min-width:2em;line-height:25px;">' . $diskPercent . '%</div></div><p class="text-muted">' . $diskUsage . ' of ' . $diskLimit . ' used</p></div>';
    $o .= '<div class="col-md-6"><h4>Bandwidth (This Month)</h4><div class="progress" style="height:25px;margin-bottom:5px;"><div class="progress-bar ' . $bwColor . '" role="progressbar" style="width:' . $bwPercent . '%;min-width:2em;line-height:25px;">' . $bwPercent . '%</div></div><p class="text-muted">' . $bwUsage . ' of ' . $bwLimit . ' transferred</p></div>';
    $o .= '</div>';
    $o .= '<small class="text-muted"><i class="fas fa-clock"></i> Last updated: ' . $lastUpdate . '</small>';
    $o .= '</div></div>';

    $o .= '</div>'; // end overview tab

    // === BUCKETS TAB ===
    $o .= '<div role="tabpanel" class="tab-pane" id="tab-buckets">';
    $mbDisplay = $maxBuckets > 0 ? $maxBuckets : 'Unlimited';
    $o .= '<div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title"><i class="fas fa-archive"></i> Your Buckets <span class="pull-right" style="font-size:13px;font-weight:normal;">' . $bucketCount . ' / ' . $mbDisplay . '</span></h3></div>';
    $o .= '<div class="panel-body">';
    $o .= '<table class="table table-striped table-hover"><thead><tr><th>Bucket Name</th><th>Label</th><th>Created</th><th></th></tr></thead><tbody>';
    foreach ($buckets as $b) {
        $bn = $esc($b->bucket_name);
        $bl = $esc($b->label ?: '-');
        $bc = $esc($b->created_at);
        $primary = $b->is_primary ? ' <span class="label label-primary">Primary</span>' : '';
        $del = !$b->is_primary ? '<button class="btn btn-xs btn-danger" onclick="deleteBucket(\'' . $bn . '\')"><i class="fas fa-trash"></i></button>' : '';
        $o .= '<tr><td><code>' . $bn . '</code>' . $primary . '</td><td>' . $bl . '</td><td>' . $bc . '</td><td>' . $del . '</td></tr>';
    }
    $o .= '</tbody></table>';
    if ($maxBuckets == 0 || $bucketCount < $maxBuckets) {
        $o .= '<form method="post" action="clientarea.php?action=productdetails&id=' . $serviceId . '" class="form-inline" style="margin-top:15px;"><input type="hidden" name="modop" value="custom"><input type="hidden" name="a" value="clientCreateBucket"><input type="hidden" name="id" value="' . $serviceId . '">';
        $o .= '<div class="input-group"><input type="text" name="bucket_name" class="form-control" placeholder="my-bucket-name" required style="width:200px;"><span class="input-group-btn"><button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> Create Bucket</button></span></div></form>';
    }
    $o .= '</div></div></div>';

    // === ACCESS KEYS TAB ===
    $o .= '<div role="tabpanel" class="tab-pane" id="tab-keys">';
    $mkDisplay = $maxKeys > 0 ? $maxKeys : 'Unlimited';
    $o .= '<div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title"><i class="fas fa-key"></i> Access Keys <span class="pull-right" style="font-size:13px;font-weight:normal;">' . $keyCount . ' / ' . $mkDisplay . '</span></h3></div>';
    $o .= '<div class="panel-body">';
    $o .= '<table class="table table-striped table-hover"><thead><tr><th>Access Key</th><th>Label</th><th>Scope</th><th>Created</th><th></th></tr></thead><tbody>';
    foreach ($accessKeys as $k) {
        $ka = $esc($k->access_key);
        $kl = $esc($k->label ?: '-');
        $ks = $k->bucket_scope ? $esc(implode(', ', json_decode($k->bucket_scope, true))) : 'All Buckets';
        $kc = $esc($k->created_at);
        $o .= '<tr><td><code>' . $ka . '</code></td><td>' . $kl . '</td><td>' . $ks . '</td><td>' . $kc . '</td>';
        $o .= '<td><button class="btn btn-xs btn-danger" onclick="deleteKey(\'' . $ka . '\')"><i class="fas fa-trash"></i></button></td></tr>';
    }
    $o .= '</tbody></table>';
    if ($maxKeys == 0 || $keyCount < $maxKeys) {
        $scopeOpts = '<option value="">All Buckets</option>';
        foreach ($buckets as $b) {
            $bn = $esc($b->bucket_name);
            $scopeOpts .= '<option value="' . $bn . '">' . $bn . '</option>';
        }
        $o .= '<form method="post" action="clientarea.php?action=productdetails&id=' . $serviceId . '" class="form-inline" style="margin-top:15px;"><input type="hidden" name="modop" value="custom"><input type="hidden" name="a" value="clientCreateAccessKey"><input type="hidden" name="id" value="' . $serviceId . '">';
        $o .= '<div class="form-group" style="margin-right:10px;"><input type="text" name="key_name" class="form-control" placeholder="Label (optional)" style="width:150px;"></div>';
        $o .= '<div class="form-group" style="margin-right:10px;"><select name="bucket_scope" class="form-control">' . $scopeOpts . '</select></div>';
        $o .= '<button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> Create Access Key</button></form>';
    }
    $o .= '</div></div></div>';

    // === QUICK START TAB ===
    $o .= '<div role="tabpanel" class="tab-pane" id="tab-quickstart">';
    $o .= '<div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title"><i class="fas fa-rocket"></i> Quick Start Guide</h3></div>';
    $o .= '<div class="panel-body">';

    $o .= '<h4>AWS CLI</h4>';
    $o .= '<pre><code>aws configure' . "\n" . '# Access Key ID: ' . $eu . "\n" . '# Secret Access Key: (your secret key)' . "\n" . '# Default region: us-east-1' . "\n\n";
    $o .= 'aws --endpoint-url ' . $ee . ' s3 cp myfile.txt s3://' . $defaultBucket . '/' . "\n";
    $o .= 'aws --endpoint-url ' . $ee . ' s3 ls s3://' . $defaultBucket . '/</code></pre>';

    $o .= '<h4>rclone</h4>';
    $o .= '<pre><code>rclone config' . "\n" . '# Choose: s3 / Provider: Minio' . "\n" . '# access_key_id: ' . $eu . "\n" . '# secret_access_key: (your secret key)' . "\n" . '# endpoint: ' . $ee . "\n\n";
    $o .= 'rclone sync /path/to/local impulsedrive:' . $defaultBucket . '/backup/</code></pre>';

    $o .= '<h4>Python (boto3)</h4>';
    $o .= '<pre><code>import boto3' . "\n\n" . 's3 = boto3.client(\'s3\',' . "\n";
    $o .= '    endpoint_url=\'' . $ee . '\',' . "\n";
    $o .= '    aws_access_key_id=\'' . $eu . '\',' . "\n";
    $o .= '    aws_secret_access_key=\'YOUR_SECRET_KEY\',' . "\n";
    $o .= '    region_name=\'us-east-1\'' . "\n" . ')' . "\n";
    $o .= 's3.upload_file(\'local.txt\', \'' . $defaultBucket . '\', \'remote.txt\')</code></pre>';

    $o .= '<h4>Compatible Apps</h4>';
    $o .= '<ul><li><strong>Cyberduck</strong> &mdash; Free desktop client (Mac/Windows)</li>';
    $o .= '<li><strong>Mountain Duck</strong> &mdash; Mount as network drive</li>';
    $o .= '<li><strong>S3 Browser</strong> &mdash; Windows S3 client</li>';
    $o .= '<li><strong>rclone</strong> &mdash; Command-line sync tool</li>';
    $o .= '<li><strong>Duplicati</strong> &mdash; Backup to S3-compatible storage</li></ul>';

    $o .= '</div></div></div>';

    $o .= '</div>'; // tab-content
    $o .= '</div>'; // impulsedrive-dashboard

    // JavaScript
    $o .= '<script>';
    $o .= 'function idCopy(id){var i=document.getElementById(id),ot=i.type;i.type="text";i.select();i.setSelectionRange(0,99999);navigator.clipboard?navigator.clipboard.writeText(i.value):document.execCommand("copy");i.type=ot;var b=i.closest(".input-group").querySelector("[title=Copy]");if(b){var oh=b.innerHTML;b.innerHTML=\'<i class="fas fa-check text-success"></i>\';setTimeout(function(){b.innerHTML=oh;},1500);}}';
    $o .= 'function togglePw(id){var i=document.getElementById(id),ic=document.getElementById(id+"-eye");if(i.type==="password"){i.type="text";ic.className="fas fa-eye-slash";}else{i.type="password";ic.className="fas fa-eye";}}';
    $o .= 'var csrfToken=(document.querySelector("input[name=token]")||{}).value||"";function deleteBucket(n){if(!confirm("Delete bucket \\""+n+"\\"? All files will be permanently deleted.")){return;}var f=document.createElement("form");f.method="post";f.innerHTML=\'<input type="hidden" name="token" value="\'+ csrfToken+\'"><input type="hidden" name="modop" value="custom"><input type="hidden" name="a" value="clientDeleteBucket"><input type="hidden" name="id" value="' . $serviceId . '"><input type="hidden" name="bucket_name" value="\'+n+\'">\';document.body.appendChild(f);f.submit();}';
    $o .= 'function deleteKey(k){if(!confirm("Revoke access key \\""+k+"\\"?")){return;}var f=document.createElement("form");f.method="post";f.innerHTML=\'<input type="hidden" name="token" value="\'+ csrfToken+\'"><input type="hidden" name="modop" value="custom"><input type="hidden" name="a" value="clientDeleteAccessKey"><input type="hidden" name="id" value="' . $serviceId . '"><input type="hidden" name="access_key_id" value="\'+k+\'">\';document.body.appendChild(f);f.submit();}';
    $o .= '</script>';

    // CSS
    $o .= '<style>';
    $o .= '.impulsedrive-dashboard .panel-heading{background:#1a1a2e;color:#fff;border-color:#16213e}';
    $o .= '.impulsedrive-dashboard .panel-heading .panel-title{color:#fff}';
    $o .= '.impulsedrive-dashboard .panel-heading .panel-title i{margin-right:8px}';
    $o .= '.impulsedrive-dashboard pre{background:#1a1a2e;color:#e0e0e0;border:1px solid #16213e;border-radius:4px;padding:12px;font-size:13px;overflow-x:auto}';
    $o .= '.impulsedrive-dashboard .progress{border-radius:4px;background:#e9ecef}';
    $o .= '.impulsedrive-dashboard label{font-weight:600;margin-bottom:4px;display:block}';
    $o .= '.impulsedrive-dashboard .nav-tabs>li>a{color:#555;font-weight:500}';
    $o .= '.impulsedrive-dashboard .nav-tabs>li.active>a{color:#1a1a2e;font-weight:600}';
    $o .= '.impulsedrive-dashboard .nav-tabs>li>a .badge{background:#1a1a2e;margin-left:5px}';
    $o .= '.impulsedrive-dashboard table code{background:#f4f4f4;padding:2px 6px;border-radius:3px;font-size:12px}';
    $o .= '#newKeyAlert{background:#d4edda;border-color:#c3e6cb}';
    $o .= '#newKeyAlert input.form-control{font-family:monospace;font-size:13px;background:#fff}';
    $o .= '</style>';
    $o .= '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("a").forEach(function(a){var t=a.textContent.trim();if(t=="Create Bucket"||t=="Delete Bucket"||t=="Create Access Key"||t=="Delete Access Key"){a.style.display="none";}});});</script>';

    return $o;
}

/**
 * Register custom client area action buttons.
 *
 * These map form submissions (modop=custom, a=functionName) to handler
 * functions. Sidebar display is hidden via JS in renderClientArea().
 *
 * @return array<string, string> Display label => function name
 */
function impulseminio_ClientAreaCustomButtonArray(): array
{
    return [
        'Create Bucket' => 'clientCreateBucket',
        'Delete Bucket' => 'clientDeleteBucket',
        'Create Access Key' => 'clientCreateAccessKey',
        'Delete Access Key' => 'clientDeleteAccessKey',
    ];
}

/**
 * Client action: create a new bucket.
 *
 * Reads bucket_name from POST, prefixes with username, creates on MinIO,
 * tracks in database, and rebuilds user policy.
 *
 * @param  array  $params WHMCS module parameters
 * @return string "success" or error message
 */
function impulseminio_clientCreateBucket(array $params): string
{
    try {
        impulseminio_ensureTables();
        $serviceId = (int)$params['serviceid'];
        $maxBuckets = (int)($params['configoption3'] ?: 5);
        $currentCount = Capsule::table('mod_impulseminio_buckets')->where('service_id', $serviceId)->count();

        if ($maxBuckets > 0 && $currentCount >= $maxBuckets) {
            return 'Bucket limit reached (' . $maxBuckets . '). Upgrade your plan for more buckets.';
        }

        $rawName = isset($_POST['bucket_name']) ? trim($_POST['bucket_name']) : '';
        if (empty($rawName)) return 'Please provide a bucket name.';

        // Prefix with username for namespace isolation
        $username = impulseminio_getUsername($params);
        $bucketName = MinioClient::bucketName($username . '-' . $rawName);

        // Check if already exists in our table
        if (Capsule::table('mod_impulseminio_buckets')->where('bucket_name', $bucketName)->exists()) {
            return 'A bucket with that name already exists.';
        }

        $client = impulseminio_getClient($params);
        $r = $client->createBucket($bucketName);
        if (!$r['success']) return 'Failed to create bucket: ' . $r['error'];

        // Set quota if configured
        $quotaGB = (int)$params['configoption1'];
        if ($quotaGB > 0) $client->setBucketQuota($bucketName, $quotaGB . 'GiB');

        // Track it
        Capsule::table('mod_impulseminio_buckets')->insert([
            'service_id' => $serviceId, 'bucket_name' => $bucketName,
            'label' => $rawName, 'is_primary' => false,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Rebuild policy to include new bucket
        impulseminio_rebuildFullPolicy($params);

        return 'success';
    } catch (\Exception $e) {
        logModuleCall('impulseminio', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Client action: delete a non-primary bucket.
 *
 * Removes bucket from MinIO (with force), deletes DB record, rebuilds policy.
 *
 * @param  array  $params WHMCS module parameters
 * @return string "success" or error message
 */
function impulseminio_clientDeleteBucket(array $params): string
{
    try {
        impulseminio_ensureTables();
        $serviceId = (int)$params['serviceid'];
        $bucketName = isset($_POST['bucket_name']) ? trim($_POST['bucket_name']) : '';
        if (empty($bucketName)) return 'No bucket specified.';

        // Verify ownership
        $bucket = Capsule::table('mod_impulseminio_buckets')
            ->where('service_id', $serviceId)->where('bucket_name', $bucketName)->first();
        if (!$bucket) return 'Bucket not found or not owned by this service.';
        if ($bucket->is_primary) return 'Cannot delete the primary bucket. Contact support if needed.';

        $client = impulseminio_getClient($params);
        $r = $client->deleteBucket($bucketName, true);
        if (!$r['success']) return 'Failed to delete bucket: ' . $r['error'];

        Capsule::table('mod_impulseminio_buckets')->where('id', $bucket->id)->delete();

        // Rebuild policy without the deleted bucket
        impulseminio_rebuildFullPolicy($params);

        return 'success';
    } catch (\Exception $e) {
        logModuleCall('impulseminio', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Client action: create a scoped access key.
 *
 * Creates a MinIO service account with optional bucket-level policy scoping.
 * Stores key in database and flashes secret to session (shown once).
 *
 * @param  array  $params WHMCS module parameters
 * @return string "success" or error message
 */
function impulseminio_clientCreateAccessKey(array $params): string
{
    try {
        impulseminio_ensureTables();
        $serviceId = (int)$params['serviceid'];
        $maxKeys = (int)($params['configoption4'] ?: 10);
        $currentCount = Capsule::table('mod_impulseminio_accesskeys')->where('service_id', $serviceId)->count();

        if ($maxKeys > 0 && $currentCount >= $maxKeys) {
            return 'Access key limit reached (' . $maxKeys . ').';
        }

        $keyName = isset($_POST['key_name']) ? trim($_POST['key_name']) : 'Key ' . ($currentCount + 1);
        $scopeInput = isset($_POST['bucket_scope']) ? trim($_POST['bucket_scope']) : '';

        $username = impulseminio_getUsername($params);
        $client = impulseminio_getClient($params);

        // Determine bucket scope
        $bucketScope = null;
        if (!empty($scopeInput) && $scopeInput !== 'all') {
            $requestedBuckets = array_map('trim', explode(',', $scopeInput));
            $ownedBuckets = impulseminio_getAllBuckets($serviceId);
            $bucketScope = array_intersect($requestedBuckets, $ownedBuckets);
            if (empty($bucketScope)) return 'Invalid bucket scope - you do not own those buckets.';
            $bucketScope = array_values($bucketScope);
        }

        $r = $client->createAccessKey($username, $keyName, $bucketScope);
        if (!$r['success']) return 'Failed to create access key: ' . $r['error'];

        // Track it (we don't store the secret - it's shown once)
        Capsule::table('mod_impulseminio_accesskeys')->insert([
            'service_id' => $serviceId, 'access_key' => $r['accessKey'],
            'name' => $keyName, 'bucket_scope' => $bucketScope ? json_encode($bucketScope) : null,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Return the secret key via a flash-style mechanism
        // The template will check for this in $_SESSION
        $_SESSION['impulseminio_new_key'] = [
            'accessKey' => $r['accessKey'],
            'secretKey' => $r['secretKey'],
            'name' => $keyName,
        ];

        return 'success';
    } catch (\Exception $e) {
        logModuleCall('impulseminio', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Client action: revoke and delete an access key.
 *
 * @param  array  $params WHMCS module parameters
 * @return string "success" or error message
 */
function impulseminio_clientDeleteAccessKey(array $params): string
{
    try {
        impulseminio_ensureTables();
        $serviceId = (int)$params['serviceid'];
        $accessKeyId = isset($_POST['access_key_id']) ? trim($_POST['access_key_id']) : '';
        if (empty($accessKeyId)) return 'No access key specified.';

        // Verify ownership
        $key = Capsule::table('mod_impulseminio_accesskeys')
            ->where('service_id', $serviceId)->where('access_key', $accessKeyId)->first();
        if (!$key) return 'Access key not found or not owned by this service.';

        $client = impulseminio_getClient($params);
        $r = $client->deleteAccessKey($accessKeyId);
        if (!$r['success']) return 'Failed to delete access key: ' . $r['error'];

        Capsule::table('mod_impulseminio_accesskeys')->where('id', $key->id)->delete();
        return 'success';
    } catch (\Exception $e) {
        logModuleCall('impulseminio', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

// =============================================================================
// ADMIN AREA
// =============================================================================
/**
 * Display fields in the admin service detail tab.
 *
 * @param  array $params WHMCS module parameters
 * @return array<string, string> Field label => value
 */
function impulseminio_AdminServicesTabFields(array $params): array
{
    return [
        'MinIO Username' => $params['model']->serviceProperties->get('MinIO Username') ?: 'Not provisioned',
        'Bucket Name' => $params['model']->serviceProperties->get('Bucket Name') ?: 'Not provisioned',
        'S3 Endpoint' => $params['model']->serviceProperties->get('S3 Endpoint') ?: 'Not set',
    ];
}

/**
 * Provide a link to the MinIO console in admin area.
 *
 * @param  array  $params WHMCS module parameters
 * @return string HTML link
 */
function impulseminio_AdminLink(array $params): string
{
    $h = $params['serverhostname'] ?: $params['serverip'];
    $p = $params['serversecure'] ? 'https' : 'http';
    return '<a href="' . $p . '://' . htmlspecialchars($h) . ':9001" target="_blank">MinIO Console</a>';
}

/**
 * Register admin-side custom action buttons.
 *
 * @return array<string, string> Display label => function name
 */
function impulseminio_AdminCustomButtonArray(): array
{
    return ['Check Usage' => 'checkUsage', 'Reset Password' => 'resetPassword', 'Rebuild Policy' => 'rebuildPolicy'];
}

/**
 * Admin action: manually refresh usage stats for a service.
 *
 * @param  array  $params WHMCS module parameters
 * @return string "success" or error message
 */
function impulseminio_checkUsage(array $params): string
{
    try {
        $client = impulseminio_getClient($params);
        $buckets = impulseminio_getAllBuckets((int)$params['serviceid']) ?: [impulseminio_getBucket($params)];
        foreach ($buckets as $b) { $client->getBucketUsage($b); }
        return 'success';
    } catch (\Exception $e) { return 'Error: ' . $e->getMessage(); }
}

/**
 * Admin action: reset MinIO user password and update WHMCS.
 *
 * @param  array  $params WHMCS module parameters
 * @return string "success" or error message
 */
function impulseminio_resetPassword(array $params): string
{
    try {
        $client = impulseminio_getClient($params);
        $username = impulseminio_getUsername($params);
        $np = MinioClient::generatePassword(24);
        $r = $client->createUser($username, $np);
        if (!$r['success']) return 'Failed: ' . $r['error'];
        $params['model']->serviceProperties->save(['MinIO Password' => $np]);
        Capsule::table('tblhosting')->where('id', $params['serviceid'])->update(['password' => encrypt($np)]);
        return 'success';
    } catch (\Exception $e) { return 'Error: ' . $e->getMessage(); }
}

/**
 * Admin action: rebuild the IAM policy for a user.
 *
 * @param  array  $params WHMCS module parameters
 * @return string "success" or error message
 */
function impulseminio_rebuildPolicy(array $params): string
{
    try {
        $r = impulseminio_rebuildFullPolicy($params);
        return $r['success'] ? 'success' : 'Failed: ' . $r['error'];
    } catch (\Exception $e) { return 'Error: ' . $e->getMessage(); }
}

// =============================================================================
// TEST CONNECTION
// =============================================================================
/**
 * Test connectivity to the MinIO server.
 *
 * @param  array $params WHMCS module parameters
 * @return array{success: bool, error?: string}
 */
function impulseminio_TestConnection(array $params): array
{
    try {
        $client = impulseminio_getClient($params);
        $r = $client->testConnection();
        return ['success' => $r['success'], 'error' => $r['success'] ? '' : $r['message']];
    } catch (\Exception $e) { return ['success' => false, 'error' => $e->getMessage()]; }
}
