<?php
/**
 * ImpulseMinio — MinIO S3 Cloud Storage Provisioning Module for WHMCS
 *
 * Provides automated provisioning of MinIO storage accounts with multi-bucket
 * management, scoped access keys, usage tracking, and a 4-tab client dashboard.
 *
 * @package    ImpulseMinio
 * @version    2.1.1
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
            $t->boolean('versioning')->default(false);
            $t->timestamps();
        });
    } elseif (!Capsule::schema()->hasColumn('mod_impulseminio_buckets', 'versioning')) {
        Capsule::schema()->table('mod_impulseminio_buckets', function ($t) {
            $t->boolean('versioning')->default(false)->after('is_primary');
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
 * @return array<string, array<string, mixed>>
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
        $bwLimitGB = (int)$params['configoption2'];

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
        Capsule::table('tblhosting')->where('id', $params['serviceid'])->update([
            'username' => $username, 'password' => encrypt($password),
            'diskusage' => 0,
            'disklimit' => $quotaGB > 0 ? $quotaGB * 1024 : 0,
            'bwusage' => 0,
            'bwlimit' => $bwLimitGB > 0 ? $bwLimitGB * 1024 : 0,
            'lastupdate' => Capsule::raw('NOW()'),
        ]);

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
        // Policy attach may fail on a disabled user — that's okay, user is already locked out
        try {
            $client->applySuspendedPolicy($username, $buckets);
        } catch (\Exception $pe) {
            logModuleCall('impulseminio', 'SuspendAccount:policyFallback', ['user' => $username], 'Policy attach failed after disable (non-critical): ' . $pe->getMessage());
        }
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
        $bwLimitGB = (int)$params['configoption2'];
        $buckets = impulseminio_getAllBuckets((int)$params['serviceid']) ?: [impulseminio_getBucket($params)];
        foreach ($buckets as $b) {
            if ($quotaGB > 0) $client->setBucketQuota($b, $quotaGB . 'GiB');
            else $client->clearBucketQuota($b);
        }
        $params['model']->serviceProperties->save(['Disk Quota (GB)' => $quotaGB > 0 ? (string)$quotaGB : 'Unlimited']);
        // Update limits in tblhosting so dashboard reflects new plan immediately
        Capsule::table('tblhosting')->where('id', $params['serviceid'])->update([
            'disklimit' => $quotaGB > 0 ? $quotaGB * 1024 : 0,
            'bwlimit' => $bwLimitGB > 0 ? $bwLimitGB * 1024 : 0,
        ]);
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
    $versioningAllowed = false;

    if (isset($params['model'])) {
        $username = $params['model']->serviceProperties->get('MinIO Username') ?: '';
        $password = $params['model']->serviceProperties->get('MinIO Password') ?: '';
        $s3Endpoint = $params['model']->serviceProperties->get('S3 Endpoint') ?: '';
        $consoleUrl = $params['configoption7'] ?? '';
        $maxBuckets = (int)($params['configoption3'] ?: 5);
        $maxKeys = (int)($params['configoption4'] ?: 10);
        $versioningAllowed = !empty($params['configoption10']);
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
                $versioningAllowed = !empty($product->configoption10);
            }
        }
    }

    $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();

    // Fix 7: Show suspension notice if service is suspended
    if ($service && strtolower($service->domainstatus) === 'suspended') {
        $o = '<div class="impulsedrive-dashboard">';
        $o .= '<div class="alert alert-danger" style="padding:30px;text-align:center;margin-top:20px;">';
        $o .= '<h3 style="margin-top:0;"><i class="fas fa-exclamation-triangle"></i> Service Suspended</h3>';
        $o .= '<p style="font-size:16px;">Your cloud storage service is currently suspended. Please contact support or settle any outstanding invoices to restore access.</p>';
        $o .= '<a href="submitticket.php" class="btn btn-primary" style="margin-top:10px;"><i class="fas fa-envelope"></i> Contact Support</a>';
        $o .= '</div></div>';
        $o .= '<style>.impulsedrive-dashboard .alert-danger{background:#f8d7da;border-color:#f5c6cb;color:#721c24;border-radius:6px;}</style>';
        return $o;
    }

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

    // Flash message — stored for injection into Access Keys tab
    $o = '';
    $newKeyFlash = '';
    $hasNewKeyFlash = false;
    if (!empty($_SESSION['impulseminio_new_key'])) {
        $hasNewKeyFlash = true;
        $nk = $_SESSION['impulseminio_new_key'];
        $eak = $esc($nk['accessKey'] ?? '');
        $esk = $esc($nk['secretKey'] ?? '');
        $ekLabel = $esc($nk['name'] ?? '');
        $newKeyFlash .= '<div class="alert alert-success alert-dismissible" id="newKeyAlert">';
        $newKeyFlash .= '<button type="button" class="close" data-dismiss="alert">&times;</button>';
        $newKeyFlash .= '<h4><i class="fas fa-key"></i> Access Key Created Successfully</h4>';
        $newKeyFlash .= '<p><strong>Save these credentials now — the secret key will not be shown again.</strong></p>';
        $newKeyFlash .= '<div class="row" style="margin-top:10px;">';
        $newKeyFlash .= '<div class="col-md-6"><label>Access Key</label><div class="input-group"><input type="text" class="form-control" id="newAccessKey" value="' . $eak . '" readonly><span class="input-group-btn"><button class="btn btn-default" onclick="idCopy(\'newAccessKey\')"><i class="fas fa-copy"></i></button></span></div></div>';
        $newKeyFlash .= '<div class="col-md-6"><label>Secret Key</label><div class="input-group"><input type="text" class="form-control" id="newSecretKey" value="' . $esk . '" readonly><span class="input-group-btn"><button class="btn btn-default" onclick="idCopy(\'newSecretKey\')"><i class="fas fa-copy"></i></button></span></div></div>';
        $newKeyFlash .= '</div>';
        // Hidden fields for downloadCredentials — avoids inline JS escaping issues with special chars
        $newKeyFlash .= '<input type="hidden" id="dlCredAk" value="' . $eak . '">';
        $newKeyFlash .= '<input type="hidden" id="dlCredSk" value="' . $esk . '">';
        $newKeyFlash .= '<input type="hidden" id="dlCredLabel" value="' . $ekLabel . '">';
        $newKeyFlash .= '<div style="margin-top:12px;"><button class="btn btn-primary btn-sm" onclick="downloadCredentials()"><i class="fas fa-download"></i> Download Credentials (.txt)</button></div>';
        $newKeyFlash .= '</div>';
        unset($_SESSION['impulseminio_new_key']);
    }

    // Password reset flash — shown in Overview tab
    $passwordResetFlash = '';
    if (!empty($_SESSION['impulseminio_password_reset'])) {
        $newPw = $esc($_SESSION['impulseminio_password_reset']);
        $passwordResetFlash .= '<div class="alert alert-warning alert-dismissible" id="pwResetAlert">';
        $passwordResetFlash .= '<button type="button" class="close" data-dismiss="alert">&times;</button>';
        $passwordResetFlash .= '<h4><i class="fas fa-sync-alt"></i> Secret Key Reset Successfully</h4>';
        $passwordResetFlash .= '<p><strong>Your secret key has been updated. The previous key is no longer valid.</strong></p>';
        $passwordResetFlash .= '<p>All applications using the previous key will need to be updated with the new one shown below.</p>';
        $passwordResetFlash .= '<div class="row" style="margin-top:10px;">';
        $passwordResetFlash .= '<div class="col-md-8"><label>New Secret Key</label><div class="input-group"><input type="text" class="form-control" id="newPwField" value="' . $newPw . '" readonly style="font-family:monospace;"><span class="input-group-btn"><button class="btn btn-default" onclick="idCopy(\'newPwField\')"><i class="fas fa-copy"></i></button></span></div></div>';
        $passwordResetFlash .= '</div></div>';
        // Update the password field on the page
        $password = $_SESSION['impulseminio_password_reset'];
        $ep = $esc($password);
        unset($_SESSION['impulseminio_password_reset']);
    }

    // Tabs
    $o .= '<div class="impulsedrive-dashboard">';
    $o .= '<ul class="nav nav-tabs" role="tablist" style="margin-bottom:20px;">';
    $o .= '<li role="presentation" class="active"><a href="#tab-overview" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> Overview</a></li>';
    $o .= '<li role="presentation"><a href="#tab-buckets" data-toggle="tab"><i class="fas fa-archive"></i> Buckets <span class="badge">' . $bucketCount . '</span></a></li>';
    $o .= '<li role="presentation"><a href="#tab-keys" data-toggle="tab"><i class="fas fa-key"></i> Access Keys <span class="badge">' . $keyCount . '</span></a></li>';
    $o .= '<li role="presentation"><a href="#tab-quickstart" data-toggle="tab"><i class="fas fa-rocket"></i> Quick Start</a></li>';
    $o .= '<li role="presentation"><a href="#tab-files" data-toggle="tab"><i class="fas fa-folder-open"></i> File Browser</a></li>';
    $o .= '</ul>';
    $o .= '<div class="tab-content">';

    // === OVERVIEW TAB ===
    $o .= '<div role="tabpanel" class="tab-pane active" id="tab-overview">';
    $o .= $passwordResetFlash;

    // Connection Details — clean table layout with plan info
    $o .= '<div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title"><i class="fas fa-plug"></i> S3 Connection Details</h3></div>';
    $o .= '<div class="panel-body">';

    // Plan summary row
    $mbDisplay = $maxBuckets > 0 ? $maxBuckets : '&infin;';
    $mkDisplay2 = $maxKeys > 0 ? $maxKeys : '&infin;';
    $o .= '<div class="row" style="margin-bottom:20px;">';
    $o .= '<div class="col-xs-6 col-md-3 text-center"><div style="padding:12px;background:#f8f9fa;border-radius:6px;"><div style="font-size:22px;font-weight:700;color:#1a1a2e;">' . $diskLimit . '</div><div class="text-muted" style="font-size:12px;">Storage</div></div></div>';
    $o .= '<div class="col-xs-6 col-md-3 text-center"><div style="padding:12px;background:#f8f9fa;border-radius:6px;"><div style="font-size:22px;font-weight:700;color:#1a1a2e;">' . $bwLimit . '</div><div class="text-muted" style="font-size:12px;">Bandwidth</div></div></div>';
    $o .= '<div class="col-xs-6 col-md-3 text-center"><div style="padding:12px;background:#f8f9fa;border-radius:6px;"><div style="font-size:22px;font-weight:700;color:#1a1a2e;">' . $mbDisplay . '</div><div class="text-muted" style="font-size:12px;">Buckets</div></div></div>';
    $o .= '<div class="col-xs-6 col-md-3 text-center"><div style="padding:12px;background:#f8f9fa;border-radius:6px;"><div style="font-size:22px;font-weight:700;color:#1a1a2e;">' . $mkDisplay2 . '</div><div class="text-muted" style="font-size:12px;">Access Keys</div></div></div>';
    $o .= '</div>';

    // Credentials table
    $o .= '<table class="table table-condensed" style="margin-bottom:15px;">';
    $o .= '<tbody>';
    $o .= '<tr><td style="width:160px;font-weight:600;vertical-align:middle;padding:10px;">S3 Endpoint</td><td><div class="input-group"><input type="text" class="form-control input-sm" id="s3endpoint" value="' . $ee . '" readonly style="font-family:monospace;"><span class="input-group-btn"><button class="btn btn-default btn-sm" onclick="idCopy(\'s3endpoint\')" title="Copy"><i class="fas fa-copy"></i></button></span></div></td></tr>';
    $o .= '<tr><td style="font-weight:600;vertical-align:middle;padding:10px;">Region</td><td><input type="text" class="form-control input-sm" value="us-east-1" readonly style="font-family:monospace;max-width:200px;"></td></tr>';
    $o .= '<tr><td style="font-weight:600;vertical-align:middle;padding:10px;">Access Key</td><td><div class="input-group"><input type="text" class="form-control input-sm" id="accesskey" value="' . $eu . '" readonly style="font-family:monospace;"><span class="input-group-btn"><button class="btn btn-default btn-sm" onclick="idCopy(\'accesskey\')" title="Copy"><i class="fas fa-copy"></i></button></span></div></td></tr>';
    $o .= '<tr><td style="font-weight:600;vertical-align:middle;padding:10px;">Secret Key</td><td><div class="input-group"><input type="password" class="form-control input-sm" id="secretkey" value="' . $ep . '" readonly style="font-family:monospace;"><span class="input-group-btn"><button class="btn btn-default btn-sm" onclick="togglePw(\'secretkey\')" title="Show/Hide"><i class="fas fa-eye" id="secretkey-eye"></i></button><button class="btn btn-default btn-sm" onclick="idCopy(\'secretkey\')" title="Copy"><i class="fas fa-copy"></i></button></span></div></td></tr>';
    $o .= '<tr><td style="font-weight:600;vertical-align:middle;padding:10px;">Default Bucket</td><td><div class="input-group"><input type="text" class="form-control input-sm" id="bucketname" value="' . $defaultBucket . '" readonly style="font-family:monospace;"><span class="input-group-btn"><button class="btn btn-default btn-sm" onclick="idCopy(\'bucketname\')" title="Copy"><i class="fas fa-copy"></i></button></span></div></td></tr>';
    $o .= '</tbody></table>';

    // Action buttons
    $o .= '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">';
    if ($consoleUrl) {
        $o .= '<a href="' . $ec . '" target="_blank" class="btn btn-primary btn-sm"><i class="fas fa-external-link-alt"></i> Open Console</a>';
    }
    $o .= '<button class="btn btn-warning btn-sm" onclick="resetPassword()"><i class="fas fa-sync-alt"></i> Reset Secret Key</button>';
    $o .= '<button class="btn btn-default btn-sm" onclick="copyAllCreds()" title="Copy all connection details to clipboard"><i class="fas fa-clipboard-list"></i> Copy All</button>';
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
    $versioningHeader = $versioningAllowed ? '<th>Versioning</th>' : '';
    $o .= '<table class="table table-striped table-hover"><thead><tr><th>Bucket Name</th><th>Label</th>' . $versioningHeader . '<th>Created</th><th></th></tr></thead><tbody>';
    foreach ($buckets as $b) {
        $bn = $esc($b->bucket_name);
        $bl = $esc($b->label ?: '-');
        $bc = $esc($b->created_at);
        $primary = $b->is_primary ? ' <span class="label label-primary">Primary</span>' : '';
        $del = !$b->is_primary ? '<button class="btn btn-xs btn-danger" onclick="deleteBucket(\'' . $bn . '\')" title="Delete bucket"><i class="fas fa-trash"></i></button>' : '';
        $versioningCell = '';
        if ($versioningAllowed) {
            $isOn = !empty($b->versioning);
            $btnClass = $isOn ? 'btn-success' : 'btn-default';
            $icon = $isOn ? 'fa-check-circle' : 'fa-circle';
            $label = $isOn ? 'On' : 'Off';
            $title = $isOn ? 'Click to suspend versioning' : 'Click to enable versioning';
            $versioningCell = '<td><button class="btn btn-xs ' . $btnClass . '" onclick="toggleVersioning(\'' . $bn . '\')" title="' . $title . '"><i class="fas ' . $icon . '"></i> ' . $label . '</button></td>';
        }
        $o .= '<tr><td><code>' . $bn . '</code>' . $primary . '</td><td>' . $bl . '</td>' . $versioningCell . '<td>' . $bc . '</td><td>' . $del . '</td></tr>';
    }
    $o .= '</tbody></table>';
    if ($maxBuckets == 0 || $bucketCount < $maxBuckets) {
        $o .= '<form method="post" action="clientarea.php?action=productdetails&id=' . $serviceId . '#buckets" class="form-inline" style="margin-top:15px;"><input type="hidden" name="modop" value="custom"><input type="hidden" name="a" value="clientCreateBucket"><input type="hidden" name="id" value="' . $serviceId . '">';
        $o .= '<div class="input-group"><input type="text" name="bucket_name" class="form-control" placeholder="e.g. backups, photos, project-files" required pattern="[a-z0-9][a-z0-9\\-]{1,61}[a-z0-9]" title="3-63 characters: lowercase letters, numbers, and hyphens. Must start and end with a letter or number." style="width:250px;"><span class="input-group-btn"><button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> Create Bucket</button></span></div><p class="help-block text-muted" style="margin-top:5px;font-size:12px;">Lowercase letters, numbers, and hyphens only. This becomes your bucket label.</p></form>';
    }
    $o .= '</div></div></div>';

    // === ACCESS KEYS TAB ===
    $o .= '<div role="tabpanel" class="tab-pane" id="tab-keys">';
    $o .= $newKeyFlash;
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
        $o .= '<form method="post" action="clientarea.php?action=productdetails&id=' . $serviceId . '#accesskeys" class="form-inline" style="margin-top:15px;"><input type="hidden" name="modop" value="custom"><input type="hidden" name="a" value="clientCreateAccessKey"><input type="hidden" name="id" value="' . $serviceId . '">';
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

    // === FILE BROWSER TAB ===
    $o .= '<div role="tabpanel" class="tab-pane" id="tab-files">';
    $o .= '<div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title"><i class="fas fa-folder-open"></i> Object Explorer</h3></div>';
    $o .= '<div class="panel-body">';

    // Toolbar: bucket select + breadcrumb + actions
    $o .= '<div class="fb-toolbar" style="display:flex;align-items:center;gap:10px;margin-bottom:15px;flex-wrap:wrap;">';
    $o .= '<select id="fb-bucket" class="form-control input-sm" style="width:auto;min-width:180px;" onchange="fbNavigate(this.value,\'\')">';
    foreach ($buckets as $b) {
        $sel = ($b->bucket_name === ($buckets[0]->bucket_name ?? '')) ? ' selected' : '';
        $o .= '<option value="' . $esc($b->bucket_name) . '"' . $sel . '>' . $esc($b->label ?: $b->bucket_name) . '</option>';
    }
    $o .= '</select>';
    $o .= '<nav id="fb-breadcrumb" style="flex:1;font-size:13px;"><a href="#" onclick="fbNavigate(null,\'\');return false;" style="font-weight:600;"><i class="fas fa-home"></i></a></nav>';
    $o .= '<button class="btn btn-success btn-sm" onclick="fbShowUpload()" title="Upload files"><i class="fas fa-upload"></i> Upload</button>';
    $o .= '<button class="btn btn-default btn-sm" onclick="fbCreateFolder()" title="New folder"><i class="fas fa-folder-plus"></i> New Folder</button>';
    $o .= '<button class="btn btn-default btn-sm" onclick="fbRefresh()" title="Refresh"><i class="fas fa-sync-alt"></i></button>';
    $o .= '</div>';

    // Upload drop zone (hidden by default)
    $o .= '<div id="fb-upload-zone" style="display:none;margin-bottom:15px;padding:30px;border:2px dashed #ccc;border-radius:8px;text-align:center;background:#fafafa;cursor:pointer;" onclick="document.getElementById(\'fb-upload-input\').click()" ondrop="fbHandleDrop(event)" ondragover="event.preventDefault();this.style.borderColor=\'#1a1a2e\';this.style.background=\'#f0f0ff\'" ondragleave="this.style.borderColor=\'#ccc\';this.style.background=\'#fafafa\'">';
    $o .= '<i class="fas fa-cloud-upload-alt" style="font-size:32px;color:#999;margin-bottom:8px;display:block;"></i>';
    $o .= '<p style="margin:0;color:#666;">Drag and drop files here, or click to select</p>';
    $o .= '<input type="file" id="fb-upload-input" multiple style="display:none;" onchange="fbUploadFiles(this.files)">';
    $o .= '<div id="fb-upload-progress" style="margin-top:10px;"></div>';
    $o .= '</div>';

    // File listing table
    $o .= '<div id="fb-loading" style="display:none;text-align:center;padding:30px;"><i class="fas fa-spinner fa-spin fa-2x" style="color:#999;"></i></div>';
    $o .= '<table class="table table-hover table-condensed" id="fb-table" style="margin-bottom:0;">';
    $o .= '<thead><tr><th style="width:30px;"></th><th>Name</th><th style="width:100px;">Size</th><th style="width:170px;">Last Modified</th><th style="width:80px;"></th></tr></thead>';
    $o .= '<tbody id="fb-body"><tr><td colspan="5" class="text-center text-muted" style="padding:30px;">Select a bucket to browse files</td></tr></tbody>';
    $o .= '</table>';

    $o .= '<div id="fb-empty" style="display:none;text-align:center;padding:30px;color:#999;"><i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:8px;"></i>This folder is empty</div>';

    $o .= '</div></div></div>';

    $o .= '</div>'; // tab-content
    $o .= '</div>'; // impulsedrive-dashboard

    // JavaScript
    $o .= '<script>';
    $o .= 'function idCopy(id){var i=document.getElementById(id),ot=i.type;i.type="text";i.select();i.setSelectionRange(0,99999);navigator.clipboard?navigator.clipboard.writeText(i.value):document.execCommand("copy");i.type=ot;var b=i.closest(".input-group").querySelector("[title=Copy]");if(b){var oh=b.innerHTML;b.innerHTML=\'<i class="fas fa-check text-success"></i>\';setTimeout(function(){b.innerHTML=oh;},1500);}}';
    $o .= 'function togglePw(id){var i=document.getElementById(id),ic=document.getElementById(id+"-eye");if(i.type==="password"){i.type="text";ic.className="fas fa-eye-slash";}else{i.type="password";ic.className="fas fa-eye";}}';
    $o .= 'var csrfToken=(document.querySelector("input[name=token]")||{}).value||"";function deleteBucket(n){if(!confirm("Delete bucket \\""+n+"\\"? All files will be permanently deleted.")){return;}var f=document.createElement("form");f.method="post";f.action="clientarea.php?action=productdetails&id=' . $serviceId . '#buckets";f.innerHTML=\'<input type="hidden" name="token" value="\'+ csrfToken+\'"><input type="hidden" name="modop" value="custom"><input type="hidden" name="a" value="clientDeleteBucket"><input type="hidden" name="id" value="' . $serviceId . '"><input type="hidden" name="bucket_name" value="\'+n+\'">\';document.body.appendChild(f);f.submit();}';
    $o .= 'function deleteKey(k){if(!confirm("Revoke access key \\""+k+"\\"?")){return;}var f=document.createElement("form");f.method="post";f.action="clientarea.php?action=productdetails&id=' . $serviceId . '#accesskeys";f.innerHTML=\'<input type="hidden" name="token" value="\'+ csrfToken+\'"><input type="hidden" name="modop" value="custom"><input type="hidden" name="a" value="clientDeleteAccessKey"><input type="hidden" name="id" value="' . $serviceId . '"><input type="hidden" name="access_key_id" value="\'+k+\'">\';document.body.appendChild(f);f.submit();}';
    $o .= 'function toggleVersioning(n){var msg=confirm("Toggle versioning on bucket \\""+n+"\\"?")?true:false;if(!msg)return;var f=document.createElement("form");f.method="post";f.action="clientarea.php?action=productdetails&id=' . $serviceId . '#buckets";f.innerHTML=\'<input type="hidden" name="token" value="\'+ csrfToken+\'"><input type="hidden" name="modop" value="custom"><input type="hidden" name="a" value="clientToggleVersioning"><input type="hidden" name="id" value="' . $serviceId . '"><input type="hidden" name="bucket_name" value="\'+n+\'">\';document.body.appendChild(f);f.submit();}';
    $o .= 'function resetPassword(){if(!confirm("Reset your secret key? Your current key will stop working immediately. You will need to update all applications using the current key.")){return;}var f=document.createElement("form");f.method="post";f.action="clientarea.php?action=productdetails&id=' . $serviceId . '#overview";f.innerHTML=\'<input type="hidden" name="token" value="\'+ csrfToken+\'"><input type="hidden" name="modop" value="custom"><input type="hidden" name="a" value="clientResetPassword"><input type="hidden" name="id" value="' . $serviceId . '">\';document.body.appendChild(f);f.submit();}';
    // Copy All credentials to clipboard
    $o .= 'function copyAllCreds(){var t="S3 Endpoint: "+document.getElementById("s3endpoint").value+"\\nRegion: us-east-1\\nAccess Key: "+document.getElementById("accesskey").value+"\\nSecret Key: "+document.getElementById("secretkey").value+"\\nDefault Bucket: "+document.getElementById("bucketname").value;navigator.clipboard?navigator.clipboard.writeText(t).then(function(){alert("Connection details copied to clipboard.")}):alert("Could not copy — use individual copy buttons instead.");}';
    // Download credentials as .txt file with full connection info — reads from hidden fields
    $o .= <<<'DLCRED'
function downloadCredentials(){
var ak=document.getElementById("dlCredAk").value;
var sk=document.getElementById("dlCredSk").value;
var label=document.getElementById("dlCredLabel").value;
var ep=document.getElementById("s3endpoint").value;
var bkt=document.getElementById("bucketname").value;
var L=[];
L.push("================================================");
L.push("  ImpulseDrive - S3 Access Key Credentials");
L.push("================================================");
L.push("");
L.push("Created:      "+new Date().toISOString());
if(label)L.push("Label:        "+label);
L.push("");
L.push("--- Connection Details ---");
L.push("S3 Endpoint:  "+ep);
L.push("Region:       us-east-1");
L.push("Access Key:   "+ak);
L.push("Secret Key:   "+sk);
L.push("");
L.push("--- Default Bucket ---");
L.push("Bucket:       "+bkt);
L.push("");
L.push("--- AWS CLI Quick Start ---");
L.push("aws configure set aws_access_key_id "+ak);
L.push("aws configure set aws_secret_access_key "+sk);
L.push("aws --endpoint-url "+ep+" s3 ls");
L.push("");
L.push("--- rclone Config ---");
L.push("[impulsedrive]");
L.push("type = s3");
L.push("provider = Minio");
L.push("access_key_id = "+ak);
L.push("secret_access_key = "+sk);
L.push("endpoint = "+ep);
L.push("acl = private");
L.push("");
L.push("--- boto3 (Python) ---");
L.push("import boto3");
L.push("s3 = boto3.client(\"s3\",");
L.push("    endpoint_url=\""+ep+"\",");
L.push("    aws_access_key_id=\""+ak+"\",");
L.push("    aws_secret_access_key=\""+sk+"\",");
L.push("    region_name=\"us-east-1\"");
L.push(")");
L.push("");
L.push("================================================");
L.push("  KEEP THIS FILE SECURE - DO NOT SHARE");
L.push("  The secret key cannot be recovered if lost.");
L.push("================================================");
var blob=new Blob([L.join("\n")],{type:"text/plain"});
var url=URL.createObjectURL(blob);
var a=document.createElement("a");
a.href=url;a.download="impulsedrive-credentials-"+ak.substring(0,8)+".txt";
document.body.appendChild(a);a.click();
document.body.removeChild(a);URL.revokeObjectURL(url);
}
DLCRED;
    // File Browser JS
    $o .= 'var fbServiceId=' . $serviceId . ',fbCurrentBucket="' . $defaultBucket . '",fbCurrentPrefix="",fbCsrf=csrfToken;';
    $o .= 'function fbAjax(action,data,cb){data.modop="custom";data.a=action;data.id=fbServiceId;data.token=fbCsrf;var x=new XMLHttpRequest();x.open("POST","clientarea.php?action=productdetails&id="+fbServiceId,true);x.setRequestHeader("Content-Type","application/x-www-form-urlencoded");x.setRequestHeader("X-Requested-With","XMLHttpRequest");x.onload=function(){try{cb(JSON.parse(x.responseText));}catch(e){cb({success:false,error:"Invalid response"});}};x.onerror=function(){cb({success:false,error:"Network error"});};var q=[];for(var k in data)q.push(encodeURIComponent(k)+"="+encodeURIComponent(data[k]));x.send(q.join("&"));}';

    $o .= 'function fbNavigate(bucket,prefix){if(bucket!==null)fbCurrentBucket=bucket;fbCurrentPrefix=prefix||"";fbRefresh();}';

    $o .= 'function fbRefresh(){var body=document.getElementById("fb-body"),loading=document.getElementById("fb-loading"),empty=document.getElementById("fb-empty"),tbl=document.getElementById("fb-table");body.innerHTML="";loading.style.display="block";tbl.style.display="none";empty.style.display="none";fbUpdateBreadcrumb();fbAjax("clientListObjects",{bucket_name:fbCurrentBucket,prefix:fbCurrentPrefix},function(r){loading.style.display="none";if(!r.success){body.innerHTML=\'<tr><td colspan="5" class="text-center text-danger">\'+r.error+\'</td></tr>\';tbl.style.display="table";return;}if(!r.objects||r.objects.length===0){empty.style.display="block";tbl.style.display="none";return;}tbl.style.display="table";r.objects.forEach(function(obj){var tr=document.createElement("tr");if(obj.type==="folder"){tr.innerHTML=\'<td><i class="fas fa-folder" style="color:#f0c040;font-size:16px;"></i></td><td><a href="#" onclick="fbNavigate(null,\\\'\'+obj.key+\'\\\');return false;" style="font-weight:500;">\'+fbDisplayName(obj.key)+\'</a></td><td class="text-muted">&mdash;</td><td class="text-muted">&mdash;</td><td><button class="btn btn-xs btn-danger" onclick="fbDeleteObject(\\\'\'+obj.key+\'\\\',true)" title="Delete folder"><i class="fas fa-trash"></i></button></td>\';}else{tr.innerHTML=\'<td><i class="fas fa-file" style="color:#5c7cfa;font-size:16px;"></i></td><td>\'+fbDisplayName(obj.key)+\'</td><td class="text-muted">\'+fbFormatSize(obj.size)+\'</td><td class="text-muted">\'+fbFormatDate(obj.lastModified)+\'</td><td style="white-space:nowrap;"><button class="btn btn-xs btn-primary" onclick="fbDownload(\\\'\'+obj.key+\'\\\')" title="Download"><i class="fas fa-download"></i></button> <button class="btn btn-xs btn-danger" onclick="fbDeleteObject(\\\'\'+obj.key+\'\\\',false)" title="Delete"><i class="fas fa-trash"></i></button></td>\';}body.appendChild(tr);});});}';

    $o .= 'function fbDisplayName(key){var parts=key.replace(fbCurrentPrefix,"").split("/");return parts.filter(function(p){return p.length>0;})[0]||key;}';

    $o .= 'function fbFormatSize(b){if(b===0)return"0 B";var u=["B","KB","MB","GB","TB"];var i=Math.floor(Math.log(b)/Math.log(1024));return(b/Math.pow(1024,i)).toFixed(i>0?1:0)+" "+u[i];}';

    $o .= 'function fbFormatDate(d){if(!d)return"";try{var dt=new Date(d);return dt.toLocaleDateString()+" "+dt.toLocaleTimeString([],{hour:"2-digit",minute:"2-digit"});}catch(e){return d;}}';

    $o .= 'function fbUpdateBreadcrumb(){var nav=document.getElementById("fb-breadcrumb");var html=\'<a href="#" onclick="fbNavigate(null,\\\'\\\');return false;" style="font-weight:600;"><i class="fas fa-home"></i> \'+fbCurrentBucket+\'</a>\';if(fbCurrentPrefix){var parts=fbCurrentPrefix.split("/").filter(function(p){return p.length>0;});var path="";parts.forEach(function(p){path+=p+"/";html+=\' <i class="fas fa-chevron-right" style="font-size:10px;color:#999;margin:0 4px;"></i> <a href="#" onclick="fbNavigate(null,\\\'\'+path+\'\\\');return false;">\'+p+\'</a>\';});}nav.innerHTML=html;}';

    $o .= 'function fbDownload(key){fbAjax("clientDownloadObject",{bucket_name:fbCurrentBucket,object_key:key},function(r){if(r.success&&r.url){window.open(r.url,"_blank");}else{alert("Download failed: "+(r.error||"Unknown error"));}});}';

    $o .= 'function fbDeleteObject(key,isFolder){var msg=isFolder?"Delete folder \\""+fbDisplayName(key)+"\\" and all contents?":"Delete \\""+fbDisplayName(key)+"\\"?";if(!confirm(msg))return;fbAjax("clientDeleteObject",{bucket_name:fbCurrentBucket,object_key:key},function(r){if(r.success){fbRefresh();}else{alert("Delete failed: "+(r.error||"Unknown error"));}});}';

    $o .= 'function fbCreateFolder(){var name=prompt("Enter folder name:");if(!name)return;name=name.replace(/[^a-zA-Z0-9._-]/g,"-").replace(/^-+|-+$/g,"");if(!name){alert("Invalid folder name.");return;}var path=fbCurrentPrefix+name+"/";fbAjax("clientCreateFolder",{bucket_name:fbCurrentBucket,folder_path:path},function(r){if(r.success){fbRefresh();}else{alert("Failed: "+(r.error||"Unknown error"));}});}';

    $o .= 'function fbShowUpload(){var z=document.getElementById("fb-upload-zone");z.style.display=z.style.display==="none"?"block":"none";}';

    $o .= 'function fbHandleDrop(e){e.preventDefault();e.currentTarget.style.borderColor="#ccc";e.currentTarget.style.background="#fafafa";if(e.dataTransfer.files.length>0)fbUploadFiles(e.dataTransfer.files);}';

    $o .= 'function fbUploadFiles(files){var prog=document.getElementById("fb-upload-progress");var total=files.length,done=0,failed=0;prog.innerHTML=\'<div class="progress" style="height:20px;"><div class="progress-bar progress-bar-striped active" style="width:0%;">0/\'+total+\'</div></div>\';for(var i=0;i<total;i++){(function(file){var objectKey=fbCurrentPrefix+file.name;fbAjax("clientGetUploadUrl",{bucket_name:fbCurrentBucket,object_key:objectKey},function(r){if(!r.success||!r.url){failed++;checkDone();return;}var fd=new FormData();if(r.fields){for(var k in r.fields){fd.append(k,r.fields[k]);}}fd.append("file",file);var xhr=new XMLHttpRequest();xhr.open("POST",r.url,true);xhr.onload=function(){if(xhr.status>=200&&xhr.status<300)done++;else failed++;checkDone();};xhr.onerror=function(){failed++;checkDone();};xhr.send(fd);});})(files[i]);}function checkDone(){var pct=Math.round(((done+failed)/total)*100);prog.querySelector(".progress-bar").style.width=pct+"%";prog.querySelector(".progress-bar").textContent=(done+failed)+"/"+total;if(done+failed>=total){setTimeout(function(){prog.innerHTML=\'<p class="text-success"><i class="fas fa-check"></i> \'+done+\' uploaded\'+( failed?\', <span class="text-danger">\'+failed+\' failed</span>\':\'\')+\'</p>\';document.getElementById("fb-upload-zone").style.display="none";fbRefresh();},500);}}}';

    // Auto-load files when switching to file browser tab
    $o .= 'document.querySelectorAll(".nav-tabs a[href=\'#tab-files\']").forEach(function(a){a.addEventListener("click",function(){setTimeout(function(){if(document.getElementById("fb-body").children.length<=1)fbRefresh();},100);});});';
    // Fix 8: Activate correct tab from URL hash on page load
    $o .= '(function(){var h=window.location.hash;';
    // Force Keys tab when new key flash is present (WHMCS strips hash on redirect)
    if ($hasNewKeyFlash) {
        $o .= 'h="#accesskeys";';
    }
    $o .= 'if(h){var map={"#buckets":"#tab-buckets","#accesskeys":"#tab-keys","#quickstart":"#tab-quickstart","#overview":"#tab-overview","#files":"#tab-files"};var target=map[h]||h;var tabLink=document.querySelector(\'.nav-tabs a[href="\'+target+\'"]\');if(tabLink){var evt=document.createEvent("HTMLEvents");evt.initEvent("click",true,true);tabLink.dispatchEvent(evt);if(typeof jQuery!=="undefined"){jQuery(tabLink).tab("show");}else{document.querySelectorAll(".nav-tabs li").forEach(function(li){li.classList.remove("active");});tabLink.parentElement.classList.add("active");document.querySelectorAll(".tab-pane").forEach(function(p){p.classList.remove("active");});var pane=document.querySelector(target);if(pane)pane.classList.add("active");}}}';
    // Fix 8: Handle tab clicks to update active indicator and URL hash
    $o .= 'document.querySelectorAll(".nav-tabs a[data-toggle=tab]").forEach(function(a){a.addEventListener("click",function(){document.querySelectorAll(".nav-tabs li").forEach(function(li){li.classList.remove("active");});this.parentElement.classList.add("active");var revMap={"#tab-overview":"#overview","#tab-buckets":"#buckets","#tab-keys":"#accesskeys","#tab-quickstart":"#quickstart","#tab-files":"#files"};var frag=revMap[this.getAttribute("href")]||this.getAttribute("href");history.replaceState(null,null,frag);});});})()';    $o .= '</script>';

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
    $o .= '.impulsedrive-dashboard .btn-outline-secondary{background:transparent;border-color:#6c757d;color:#6c757d}';
    $o .= '.impulsedrive-dashboard .btn-outline-secondary:hover{background:#6c757d;color:#fff}';
    // File browser styles
    $o .= '#fb-table tbody tr:hover{background:#f8f9fa;cursor:default}';
    $o .= '#fb-table tbody tr td{vertical-align:middle;padding:8px}';
    $o .= '#fb-breadcrumb a{color:#1a1a2e;text-decoration:none}#fb-breadcrumb a:hover{text-decoration:underline}';
    $o .= '#fb-upload-zone.drag-over{border-color:#1a1a2e !important;background:#f0f0ff !important}';
    $o .= '.fb-toolbar .btn{white-space:nowrap}';
    $o .= '</style>';
    // Fix 5: Clear flash messages when switching tabs
    $o .= '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(".nav-tabs a[data-toggle=tab]").forEach(function(a){a.addEventListener("click",function(){document.querySelectorAll(".impulsedrive-dashboard > .alert").forEach(function(el){el.style.display="none";});});});';
    $o .= 'document.querySelectorAll("a").forEach(function(a){var t=a.textContent.trim();if(t=="Create Bucket"||t=="Delete Bucket"||t=="Create Access Key"||t=="Delete Access Key"||t=="Toggle Versioning"||t=="Reset Password"||t=="List Objects"||t=="Download Object"||t=="Delete Object"||t=="Create Folder"||t=="Get Upload URL"){a.style.display="none";}});});</script>';

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
        'Toggle Versioning' => 'clientToggleVersioning',
        'Reset Password' => 'clientResetPassword',
        'List Objects' => 'clientListObjects',
        'Download Object' => 'clientDownloadObject',
        'Delete Object' => 'clientDeleteObject',
        'Create Folder' => 'clientCreateFolder',
        'Get Upload URL' => 'clientGetUploadUrl',
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

        $rawName = isset($_POST['bucket_name']) ? strtolower(trim($_POST['bucket_name'])) : '';
        if (empty($rawName)) return 'Please provide a bucket name.';
        if (strlen($rawName) < 3) return 'Bucket name must be at least 3 characters.';
        if (strlen($rawName) > 63) return 'Bucket name must be 63 characters or fewer.';
        if (!preg_match('/^[a-z0-9][a-z0-9\-]*[a-z0-9]$/', $rawName)) {
            return 'Bucket name can only contain lowercase letters, numbers, and hyphens. Must start and end with a letter or number.';
        }

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

        // Auto-revoke access keys scoped to this bucket
        $scopedKeys = Capsule::table('mod_impulseminio_accesskeys')
            ->where('service_id', $serviceId)
            ->whereNotNull('bucket_scope')
            ->get();
        foreach ($scopedKeys as $sk) {
            $scopes = json_decode($sk->bucket_scope, true);
            if (is_array($scopes) && in_array($bucketName, $scopes)) {
                $client->deleteAccessKey($sk->access_key);
                Capsule::table('mod_impulseminio_accesskeys')->where('id', $sk->id)->delete();
                logModuleCall('impulseminio', 'clientDeleteBucket:revokeOrphanKey', ['key' => $sk->access_key, 'bucket' => $bucketName], 'Revoked scoped key');
            }
        }

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

/**
 * Client action: toggle versioning on a bucket.
 *
 * Enables or suspends S3 versioning depending on current state.
 * Requires configoption10 (Enable Versioning) to be enabled on the product.
 *
 * @param  array  $params WHMCS module parameters
 * @return string "success" or error message
 */
function impulseminio_clientToggleVersioning(array $params): string
{
    try {
        impulseminio_ensureTables();
        $serviceId = (int)$params['serviceid'];

        // Check if versioning feature is enabled on this product
        $versioningAllowed = !empty($params['configoption10']);
        if (!$versioningAllowed) {
            return 'Versioning is not available on your current plan.';
        }

        $bucketName = isset($_POST['bucket_name']) ? trim($_POST['bucket_name']) : '';
        if (empty($bucketName)) return 'No bucket specified.';

        // Verify ownership
        $bucket = Capsule::table('mod_impulseminio_buckets')
            ->where('service_id', $serviceId)->where('bucket_name', $bucketName)->first();
        if (!$bucket) return 'Bucket not found or not owned by this service.';

        $client = impulseminio_getClient($params);
        $currentlyEnabled = !empty($bucket->versioning);

        if ($currentlyEnabled) {
            $r = $client->suspendVersioning($bucketName);
            if (!$r['success']) return 'Failed to suspend versioning: ' . $r['error'];
            Capsule::table('mod_impulseminio_buckets')->where('id', $bucket->id)->update([
                'versioning' => false, 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $r = $client->enableVersioning($bucketName);
            if (!$r['success']) return 'Failed to enable versioning: ' . $r['error'];
            Capsule::table('mod_impulseminio_buckets')->where('id', $bucket->id)->update([
                'versioning' => true, 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return 'success';
    } catch (\Exception $e) {
        logModuleCall('impulseminio', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Client action: reset the MinIO secret key (password).
 *
 * Generates a new password, updates MinIO user, stores in WHMCS,
 * and flashes the new credentials to session for display.
 *
 * @param  array  $params WHMCS module parameters
 * @return string "success" or error message
 */
function impulseminio_clientResetPassword(array $params): string
{
    try {
        $client = impulseminio_getClient($params);
        $username = impulseminio_getUsername($params);
        $newPassword = MinioClient::generatePassword(24);

        $r = $client->createUser($username, $newPassword);
        if (!$r['success']) return 'Failed to reset password: ' . $r['error'];

        $params['model']->serviceProperties->save(['MinIO Password' => $newPassword]);
        Capsule::table('tblhosting')->where('id', $params['serviceid'])->update([
            'password' => encrypt($newPassword),
        ]);

        // Flash the new password so it's shown once
        $_SESSION['impulseminio_password_reset'] = $newPassword;

        return 'success';
    } catch (\Exception $e) {
        logModuleCall('impulseminio', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

// =============================================================================
// FILE BROWSER AJAX HANDLERS
// =============================================================================

/**
 * Helper: send JSON response for AJAX calls and exit.
 * Only sends JSON if the request is XHR; otherwise returns 'success' for
 * standard WHMCS form submission compatibility.
 */
function impulseminio_jsonResponse(array $data): string
{
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
    return $data['success'] ? 'success' : ($data['error'] ?? 'Error');
}

/**
 * Client AJAX: list objects in a bucket, optionally at a prefix.
 */
function impulseminio_clientListObjects(array $params): string
{
    try {
        $serviceId = (int)$params['serviceid'];
        $bucketName = isset($_POST['bucket_name']) ? trim($_POST['bucket_name']) : '';
        $prefix = isset($_POST['prefix']) ? trim($_POST['prefix']) : '';

        if (empty($bucketName)) return impulseminio_jsonResponse(['success' => false, 'error' => 'No bucket specified.']);

        // Verify bucket ownership
        impulseminio_ensureTables();
        $bucket = Capsule::table('mod_impulseminio_buckets')
            ->where('service_id', $serviceId)->where('bucket_name', $bucketName)->first();
        if (!$bucket) return impulseminio_jsonResponse(['success' => false, 'error' => 'Bucket not found.']);

        $client = impulseminio_getClient($params);
        $r = $client->listObjects($bucketName, $prefix);

        return impulseminio_jsonResponse([
            'success' => $r['success'],
            'objects' => $r['objects'] ?? [],
            'error'   => $r['error'] ?? null,
        ]);
    } catch (\Exception $e) {
        logModuleCall('impulseminio', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return impulseminio_jsonResponse(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
    }
}

/**
 * Client AJAX: generate a presigned download URL for an object.
 */
function impulseminio_clientDownloadObject(array $params): string
{
    try {
        $serviceId = (int)$params['serviceid'];
        $bucketName = isset($_POST['bucket_name']) ? trim($_POST['bucket_name']) : '';
        $objectKey = isset($_POST['object_key']) ? trim($_POST['object_key']) : '';

        if (empty($bucketName) || empty($objectKey))
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Missing bucket or object key.']);

        impulseminio_ensureTables();
        $bucket = Capsule::table('mod_impulseminio_buckets')
            ->where('service_id', $serviceId)->where('bucket_name', $bucketName)->first();
        if (!$bucket) return impulseminio_jsonResponse(['success' => false, 'error' => 'Bucket not found.']);

        $client = impulseminio_getClient($params);
        $r = $client->getPresignedDownloadUrl($bucketName, $objectKey, 3600);

        return impulseminio_jsonResponse([
            'success' => $r['success'],
            'url'     => $r['url'] ?? '',
            'error'   => $r['error'] ?? null,
        ]);
    } catch (\Exception $e) {
        logModuleCall('impulseminio', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return impulseminio_jsonResponse(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
    }
}

/**
 * Client AJAX: generate a presigned upload URL for an object.
 * The client-side JS will PUT the file directly to this URL.
 */
function impulseminio_clientGetUploadUrl(array $params): string
{
    try {
        $serviceId = (int)$params['serviceid'];
        $bucketName = isset($_POST['bucket_name']) ? trim($_POST['bucket_name']) : '';
        $objectKey = isset($_POST['object_key']) ? trim($_POST['object_key']) : '';

        if (empty($bucketName) || empty($objectKey))
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Missing bucket or object key.']);

        impulseminio_ensureTables();
        $bucket = Capsule::table('mod_impulseminio_buckets')
            ->where('service_id', $serviceId)->where('bucket_name', $bucketName)->first();
        if (!$bucket) return impulseminio_jsonResponse(['success' => false, 'error' => 'Bucket not found.']);

        $client = impulseminio_getClient($params);
        $r = $client->getPresignedUploadUrl($bucketName, $objectKey, 3600);

        return impulseminio_jsonResponse([
            'success' => $r['success'],
            'url'     => $r['url'] ?? '',
            'fields'  => $r['fields'] ?? [],
            'error'   => $r['error'] ?? null,
        ]);
    } catch (\Exception $e) {
        logModuleCall('impulseminio', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return impulseminio_jsonResponse(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
    }
}

/**
 * Client AJAX: delete an object from a bucket.
 */
function impulseminio_clientDeleteObject(array $params): string
{
    try {
        $serviceId = (int)$params['serviceid'];
        $bucketName = isset($_POST['bucket_name']) ? trim($_POST['bucket_name']) : '';
        $objectKey = isset($_POST['object_key']) ? trim($_POST['object_key']) : '';

        if (empty($bucketName) || empty($objectKey))
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Missing bucket or object key.']);

        impulseminio_ensureTables();
        $bucket = Capsule::table('mod_impulseminio_buckets')
            ->where('service_id', $serviceId)->where('bucket_name', $bucketName)->first();
        if (!$bucket) return impulseminio_jsonResponse(['success' => false, 'error' => 'Bucket not found.']);

        $client = impulseminio_getClient($params);
        $r = $client->deleteObject($bucketName, $objectKey);

        return impulseminio_jsonResponse([
            'success' => $r['success'],
            'error'   => $r['success'] ? null : ($r['output'] ?? 'Delete failed'),
        ]);
    } catch (\Exception $e) {
        logModuleCall('impulseminio', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return impulseminio_jsonResponse(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
    }
}

/**
 * Client AJAX: create a folder (empty object with trailing /) in a bucket.
 */
function impulseminio_clientCreateFolder(array $params): string
{
    try {
        $serviceId = (int)$params['serviceid'];
        $bucketName = isset($_POST['bucket_name']) ? trim($_POST['bucket_name']) : '';
        $folderPath = isset($_POST['folder_path']) ? trim($_POST['folder_path']) : '';

        if (empty($bucketName) || empty($folderPath))
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Missing bucket or folder path.']);

        // Sanitize folder path — allow alphanumeric, dashes, dots, underscores, slashes
        if (!preg_match('/^[a-zA-Z0-9._\/-]+\/$/', $folderPath))
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Invalid folder name.']);

        impulseminio_ensureTables();
        $bucket = Capsule::table('mod_impulseminio_buckets')
            ->where('service_id', $serviceId)->where('bucket_name', $bucketName)->first();
        if (!$bucket) return impulseminio_jsonResponse(['success' => false, 'error' => 'Bucket not found.']);

        $client = impulseminio_getClient($params);
        $r = $client->createFolder($bucketName, $folderPath);

        return impulseminio_jsonResponse([
            'success' => $r['success'],
            'error'   => $r['success'] ? null : ($r['output'] ?? 'Create folder failed'),
        ]);
    } catch (\Exception $e) {
        logModuleCall('impulseminio', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return impulseminio_jsonResponse(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
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
