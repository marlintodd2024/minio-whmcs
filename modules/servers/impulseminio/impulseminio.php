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
    if (!empty($_SESSION['impulseminio_new_key'])) {
        $nk = $_SESSION['impulseminio_new_key'];
        $eak = $esc($nk['accessKey'] ?? '');
        $esk = $esc($nk['secretKey'] ?? '');
        $newKeyFlash .= '<div class="alert alert-success alert-dismissible" id="newKeyAlert">';
        $newKeyFlash .= '<button type="button" class="close" data-dismiss="alert">&times;</button>';
        $newKeyFlash .= '<h4><i class="fas fa-key"></i> Access Key Created Successfully</h4>';
        $newKeyFlash .= '<p><strong>Save these credentials now — the secret key will not be shown again.</strong></p>';
        $newKeyFlash .= '<div class="row" style="margin-top:10px;">';
        $newKeyFlash .= '<div class="col-md-6"><label>Access Key</label><div class="input-group"><input type="text" class="form-control" id="newAccessKey" value="' . $eak . '" readonly><span class="input-group-btn"><button class="btn btn-default" onclick="idCopy(\'newAccessKey\')"><i class="fas fa-copy"></i></button></span></div></div>';
        $newKeyFlash .= '<div class="col-md-6"><label>Secret Key</label><div class="input-group"><input type="text" class="form-control" id="newSecretKey" value="' . $esk . '" readonly><span class="input-group-btn"><button class="btn btn-default" onclick="idCopy(\'newSecretKey\')"><i class="fas fa-copy"></i></button></span></div></div>';
        $newKeyFlash .= '</div></div>';
        unset($_SESSION['impulseminio_new_key']);
    }

    // Password reset flash — shown in Overview tab
    $passwordResetFlash = '';
    if (!empty($_SESSION['impulseminio_password_reset'])) {
        $newPw = $esc($_SESSION['impulseminio_password_reset']);
        $passwordResetFlash .= '<div class="alert alert-warning alert-dismissible" id="pwResetAlert">';
        $passwordResetFlash .= '<button type="button" class="close" data-dismiss="alert">&times;</button>';
        $passwordResetFlash .= '<h4><i class="fas fa-sync-alt"></i> Secret Key Reset Successfully</h4>';
        $passwordResetFlash .= '<p><strong>Your new secret key is shown below. Save it now — it will not be shown again.</strong></p>';
        $passwordResetFlash .= '<p>All applications using the previous key will need to be updated.</p>';
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
    $o .= '</ul>';
    $o .= '<div class="tab-content">';

    // === OVERVIEW TAB ===
    $o .= '<div role="tabpanel" class="tab-pane active" id="tab-overview">';
    $o .= $passwordResetFlash;

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
    $o .= '<div class="col-md-6"><label>Secret Key (Password)</label><div class="input-group"><input type="password" class="form-control" id="secretkey" value="' . $ep . '" readonly><span class="input-group-btn"><button class="btn btn-outline-secondary btn-default" onclick="togglePw(\'secretkey\')" title="Show/Hide"><i class="fas fa-eye" id="secretkey-eye"></i></button><button class="btn btn-default" onclick="idCopy(\'secretkey\')" title="Copy"><i class="fas fa-copy"></i></button></span></div></div>';
    $o .= '</div>';
    $o .= '<div class="row"><div class="col-md-6"><label>Region</label><input type="text" class="form-control" value="us-east-1" readonly></div>';
    $o .= '<div class="col-md-6"><label>&nbsp;</label><div>';
    if ($consoleUrl) {
        $o .= '<a href="' . $ec . '" target="_blank" class="btn btn-primary" style="margin-right:8px;"><i class="fas fa-external-link-alt"></i> Open Storage Console</a>';
    }
    $o .= '<button class="btn btn-warning" onclick="resetPassword()" title="Generate a new secret key"><i class="fas fa-sync-alt"></i> Reset Secret Key</button>';
    $o .= '</div></div>';
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
    // Fix 8: Activate correct tab from URL hash on page load
    $o .= '(function(){var h=window.location.hash;if(h){var map={"#buckets":"#tab-buckets","#accesskeys":"#tab-keys","#quickstart":"#tab-quickstart","#overview":"#tab-overview"};var target=map[h]||h;var tabLink=document.querySelector(\'.nav-tabs a[href="\'+target+\'"]\');if(tabLink){var evt=document.createEvent("HTMLEvents");evt.initEvent("click",true,true);tabLink.dispatchEvent(evt);if(typeof jQuery!=="undefined"){jQuery(tabLink).tab("show");}else{document.querySelectorAll(".nav-tabs li").forEach(function(li){li.classList.remove("active");});tabLink.parentElement.classList.add("active");document.querySelectorAll(".tab-pane").forEach(function(p){p.classList.remove("active");});var pane=document.querySelector(target);if(pane)pane.classList.add("active");}}}';
    // Fix 8: Handle tab clicks to update active indicator and URL hash
    $o .= 'document.querySelectorAll(".nav-tabs a[data-toggle=tab]").forEach(function(a){a.addEventListener("click",function(){document.querySelectorAll(".nav-tabs li").forEach(function(li){li.classList.remove("active");});this.parentElement.classList.add("active");var revMap={"#tab-overview":"#overview","#tab-buckets":"#buckets","#tab-keys":"#accesskeys","#tab-quickstart":"#quickstart"};var frag=revMap[this.getAttribute("href")]||this.getAttribute("href");history.replaceState(null,null,frag);});});})()';    $o .= '</script>';

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
    $o .= '</style>';
    // Fix 5: Clear flash messages when switching tabs
    $o .= '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(".nav-tabs a[data-toggle=tab]").forEach(function(a){a.addEventListener("click",function(){document.querySelectorAll(".impulsedrive-dashboard > .alert").forEach(function(el){el.style.display="none";});});});';
    $o .= 'document.querySelectorAll("a").forEach(function(a){var t=a.textContent.trim();if(t=="Create Bucket"||t=="Delete Bucket"||t=="Create Access Key"||t=="Delete Access Key"||t=="Toggle Versioning"||t=="Reset Password"){a.style.display="none";}});});</script>';

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
