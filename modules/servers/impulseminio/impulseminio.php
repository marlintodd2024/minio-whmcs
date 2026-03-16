<?php
/**
 * ImpulseMinio — MinIO S3 Cloud Storage Provisioning Module for WHMCS
 *
 * Provides automated provisioning of MinIO storage accounts with multi-bucket
 * management, scoped access keys, usage tracking, multi-region support,
 * and a 6-tab client dashboard.
 *
 * @package    ImpulseMinio
 * @version    2.6.0
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
    // Region registry — each row is a MinIO instance in a datacenter
    if (!Capsule::schema()->hasTable('mod_impulseminio_regions')) {
        Capsule::schema()->create('mod_impulseminio_regions', function ($t) {
            $t->increments('id');
            $t->string('name', 100);               // Display name: "US Central — Dallas"
            $t->string('slug', 50)->unique();       // URL-safe: "us-central-dallas"
            $t->string('flag', 10)->default('');     // Emoji flag: "🇺🇸"
            $t->unsignedInteger('server_id');         // FK → tblservers.id
            $t->string('cdn_endpoint', 255)->nullable(); // Public CDN base URL
            $t->string('stats_url', 255)->nullable();    // Bandwidth stats JSON endpoint
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });
    }
    // Links services to their primary (and future replication) regions
    if (!Capsule::schema()->hasTable('mod_impulseminio_service_regions')) {
        Capsule::schema()->create('mod_impulseminio_service_regions', function ($t) {
            $t->increments('id');
            $t->unsignedInteger('service_id')->index(); // FK → tblhosting.id
            $t->unsignedInteger('region_id');            // FK → mod_impulseminio_regions.id
            $t->boolean('is_primary')->default(true);
            $t->timestamps();
        });
    }
    // Replication jobs — tracks cross-region bucket replication
    if (!Capsule::schema()->hasTable('mod_impulseminio_replication_jobs')) {
        Capsule::schema()->create('mod_impulseminio_replication_jobs', function ($t) {
            $t->increments('id');
            $t->unsignedInteger('service_id')->index();
            $t->unsignedInteger('source_region_id');
            $t->string('source_bucket', 255);
            $t->unsignedInteger('dest_region_id');
            $t->string('dest_bucket', 255);
            $t->string('description', 500)->nullable();
            $t->string('remote_arn', 500)->nullable();
            $t->string('rule_id', 100)->nullable();
            $t->tinyInteger('sync_existing')->default(1);
            $t->tinyInteger('sync_deletes')->default(1);
            $t->tinyInteger('sync_locking')->default(0);
            $t->tinyInteger('sync_metadata_date')->default(1);
            $t->tinyInteger('sync_tags')->default(1);
            $t->string('status', 20)->default('active');
            $t->dateTime('suspended_at')->nullable();
            $t->dateTime('purge_after')->nullable();
            $t->tinyInteger('warning_sent')->default(0);
            $t->dateTime('purge_notified_at')->nullable();
            $t->dateTime('last_sync_at')->nullable();
            $t->text('error_message')->nullable();
            $t->timestamps();
        });
    }
}

// =============================================================================
// PREMIUM DETECTION
// =============================================================================
/**
 * Check if premium module files are present and licensed.
 *
 * @return bool
 */
function impulseminio_hasPremium(): bool
{
    static $result = null;
    if ($result === null) {
        if (!file_exists(__DIR__ . '/lib/Premium.php')) {
            $result = false;
        } else {
            require_once __DIR__ . '/lib/Premium.php';
            $result = \WHMCS\Module\Server\ImpulseMinio\Premium::isLicensed();
        }
    }
    return $result;
}

/**
 * Check if public access feature is available.
 *
 * @return bool
 */
function impulseminio_hasPublicAccess(): bool
{
    if (!impulseminio_hasPremium()) return false;
    require_once __DIR__ . '/lib/Premium.php';
    return \WHMCS\Module\Server\ImpulseMinio\Premium::hasFeature(
        \WHMCS\Module\Server\ImpulseMinio\Premium::FEATURE_PUBLIC_ACCESS
    );
}

/**
 * Check if CORS feature is available.
 *
 * @return bool
 */
function impulseminio_hasCors(): bool
{
    if (!impulseminio_hasPremium()) return false;
    require_once __DIR__ . '/lib/Premium.php';
    return \WHMCS\Module\Server\ImpulseMinio\Premium::hasFeature(
        \WHMCS\Module\Server\ImpulseMinio\Premium::FEATURE_CORS
    );
}

/**
 * Check if replication feature is available.
 *
 * @return bool
 */
function impulseminio_hasReplication(): bool
{
    if (!impulseminio_hasPremium()) return false;
    if (!file_exists(__DIR__ . '/lib/Replication.php')) return false;
    require_once __DIR__ . '/lib/Premium.php';
    require_once __DIR__ . '/lib/Replication.php';
    return \WHMCS\Module\Server\ImpulseMinio\Premium::hasFeature('replication');
}

function impulseminio_hasCustomDomains(): bool
{
    if (!impulseminio_hasPremium()) return false;
    require_once __DIR__ . '/lib/Premium.php';
    return \WHMCS\Module\Server\ImpulseMinio\Premium::hasFeature('custom_domain');
}
function impulseminio_hasMigration(): bool
{
    if (!impulseminio_hasPremium()) return false;
    require_once __DIR__ . '/lib/Premium.php';
    return \WHMCS\Module\Server\ImpulseMinio\Premium::hasFeature('migration');
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
        // === PREMIUM FEATURES (requires ImpulseMinio Premium license) ===
        // configoption11
        'CDN Endpoint' => [
            'Type'        => 'text',
            'Size'        => '50',
            'Default'     => '',
            'Description' => 'Public CDN base URL for static hosting (e.g. https://us-central-dallas.impulsedrive.io). Required for public bucket access.',
        ],
        // configoption12
        'Enable Public Access' => [
            'Type'        => 'yesno',
            'Description' => 'Allow customers to make buckets publicly accessible via CDN. Requires Premium license.',
        ],
        // configoption13
        'Max Public Buckets' => [
            'Type'        => 'text',
            'Size'        => '5',
            'Default'     => '0',
            'Description' => 'Maximum public buckets per customer. Set to 0 for unlimited.',
        ],
        // configoption14
        'Premium License Key' => [
            'Type'        => 'text',
            'Size'        => '50',
            'Default'     => '',
            'Description' => 'ImpulseMinio Premium license key. Purchase at impulsehosting.com. Leave blank for free module only.',
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
 * Build a MinioClient for a specific region using its server record.
 *
 * Falls back to impulseminio_getClient() if region has no server_id
 * or if the server record is missing.
 *
 * @param  int   $serverId WHMCS server ID from mod_impulseminio_regions
 * @param  array $params   Module params (for mc path fallback)
 * @return MinioClient
 */
function impulseminio_getClientForServer(int $serverId, array $params): MinioClient
{
    $server = Capsule::table('tblservers')->where('id', $serverId)->first();
    if (!$server) {
        throw new \Exception("Server ID {$serverId} not found in tblservers.");
    }
    $hostname = $server->hostname ?: $server->ipaddress;
    $secure = (bool)$server->secure;
    $port = $server->port ?: ($secure ? '443' : '9000');
    $protocol = $secure ? 'https' : 'http';
    $endpoint = ($secure && $port === '443') ? $protocol . '://' . $hostname : $protocol . '://' . $hostname . ':' . $port;
    $password = decrypt($server->password);
    return new MinioClient($endpoint, $server->username, $password, $params['configoption8'] ?: '/usr/local/bin/mc', $secure);
}

/**
 * Resolve the primary region for a service.
 *
 * Checks mod_impulseminio_service_regions first. If no row exists
 * (legacy service), returns null — callers fall back to the product's
 * assigned WHMCS server via impulseminio_getClient().
 *
 * @param  int $serviceId
 * @return object|null Region row from mod_impulseminio_regions
 */
function impulseminio_getPrimaryRegion(int $serviceId): ?object
{
    if (!Capsule::schema()->hasTable('mod_impulseminio_service_regions')) return null;
    $link = Capsule::table('mod_impulseminio_service_regions')
        ->where('service_id', $serviceId)
        ->where('is_primary', true)
        ->first();
    if (!$link) return null;
    return Capsule::table('mod_impulseminio_regions')
        ->where('id', $link->region_id)
        ->where('is_active', true)
        ->first();
}

/**
 * Get the correct MinioClient for a service — region-aware with legacy fallback.
 *
 * If the service has a primary region in mod_impulseminio_service_regions,
 * connects to that region's server. Otherwise falls back to the product's
 * default WHMCS server (legacy behavior).
 *
 * @param  array $params WHMCS module parameters
 * @return MinioClient
 */
function impulseminio_getServiceClient(array $params): MinioClient
{
    $region = impulseminio_getPrimaryRegion((int)$params['serviceid']);
    if ($region && !empty($region->server_id)) {
        return impulseminio_getClientForServer((int)$region->server_id, $params);
    }
    return impulseminio_getClient($params);
}

/**
 * Get a MinioClient for a request — handles optional region_server_id for replica bucket browsing.
 * If region_server_id is provided in POST, connects to that server instead of the primary.
 *
 * @param  array $params WHMCS module parameters
 * @return MinioClient
 */
function impulseminio_getRequestClient(array $params): MinioClient
{
    $regionServerId = isset($_POST['region_server_id']) ? (int)$_POST['region_server_id'] : 0;
    if ($regionServerId > 0) {
        // Verify the server belongs to a region this service has access to
        $serviceId = (int)$params['serviceid'];
        $hasAccess = Capsule::table('mod_impulseminio_service_regions as sr')
            ->join('mod_impulseminio_regions as r', 'sr.region_id', '=', 'r.id')
            ->where('sr.service_id', $serviceId)
            ->where('r.server_id', $regionServerId)
            ->exists();
        if ($hasAccess) {
            return impulseminio_getClientForServer($regionServerId, $params);
        }
    }
    return impulseminio_getServiceClient($params);
}

/**
 * Validate bucket ownership — checks primary buckets AND replica destination buckets.
 *
 * @param  int    $serviceId
 * @param  string $bucketName
 * @return bool
 */
function impulseminio_validateBucketAccess(int $serviceId, string $bucketName): bool
{
    // Check primary buckets
    if (Capsule::table('mod_impulseminio_buckets')
        ->where('service_id', $serviceId)->where('bucket_name', $bucketName)->exists()) {
        return true;
    }
    // Check replica destination buckets
    if (Capsule::table('mod_impulseminio_replication_jobs')
        ->where('service_id', $serviceId)->where('dest_bucket', $bucketName)
        ->whereNotIn('status', ['removing', 'deleted'])->exists()) {
        return true;
    }
    return false;
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
    $client = impulseminio_getServiceClient($params);
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

        // Resolve region: check for region configurable option (customer's selection)
        $regionId = null;
        $client = null;
        $s3Endpoint = '';

        // Log configoptions for debugging
        logModuleCall('impulseminio', 'CreateAccount:configoptions', $params['configoptions'] ?? 'EMPTY', '', '');

        // Look for a "Region" configurable option in the order
        if (!empty($params['configoptions'])) {
            foreach ($params['configoptions'] as $optName => $optValue) {
                if (stripos($optName, 'region') !== false) {
                    // WHMCS may pass: the slug text ("us-east-newark"),
                    // the full optionname ("us-east-newark|US East — Newark"),
                    // or a numeric suboption ID (2050).
                    $slug = $optValue;

                    // If numeric, resolve suboption ID → optionname → slug
                    if (is_numeric($optValue)) {
                        $sub = Capsule::table('tblproductconfigoptionssub')
                            ->where('id', (int)$optValue)->first();
                        if ($sub) {
                            $slug = explode('|', $sub->optionname)[0];
                        }
                    } else {
                        // Extract slug from "slug|Label" format if present
                        $slug = explode('|', (string)$optValue)[0];
                    }

                    $region = Capsule::table('mod_impulseminio_regions')
                        ->where('is_active', true)
                        ->where('slug', $slug)
                        ->first();
                    if ($region) {
                        $regionId = $region->id;
                        $client = impulseminio_getClientForServer((int)$region->server_id, $params);
                        $server = Capsule::table('tblservers')->where('id', $region->server_id)->first();
                        if ($server) {
                            $hostname = $server->hostname ?: $server->ipaddress;
                            $s3Endpoint = ($server->secure ? 'https' : 'http') . '://' . $hostname;
                        }
                    }
                    break;
                }
            }
        }

        // Fallback to product's assigned server (legacy / no region selected)
        if (!$client) {
            $client = impulseminio_getServiceClient($params);
            $s3Endpoint = $params['configoption6'] ?: ($params['serversecure'] ? 'https' : 'http') . '://' . ($params['serverhostname'] ?: $params['serverip']);
        }

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

        // Record the region assignment
        if ($regionId) {
            Capsule::table('mod_impulseminio_service_regions')->insert([
                'service_id' => $params['serviceid'],
                'region_id' => $regionId,
                'is_primary' => true,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            // Also update the WHMCS service to point at the correct server
            $region = Capsule::table('mod_impulseminio_regions')->where('id', $regionId)->first();
            if ($region) {
                Capsule::table('tblhosting')->where('id', $params['serviceid'])->update([
                    'server' => $region->server_id,
                ]);
            }
        }

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
        $client = impulseminio_getServiceClient($params);
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
        $client = impulseminio_getServiceClient($params);
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
        $client = impulseminio_getServiceClient($params);
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
        $client = impulseminio_getServiceClient($params);
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
        $client = impulseminio_getServiceClient($params);
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

    foreach ($services as $service) {
        try {
            $totalDiskBytes = 0;
            $totalBwRx = 0;
            $totalBwTx = 0;

            // Get all regions this service has (primary + replication destinations)
            $serviceRegions = [];
            try {
                $serviceRegions = Capsule::table('mod_impulseminio_service_regions as sr')
                    ->join('mod_impulseminio_regions as r', 'sr.region_id', '=', 'r.id')
                    ->where('sr.service_id', $service->id)
                    ->select('r.server_id', 'sr.is_primary')
                    ->get()->toArray();
            } catch (\Exception $e) {
                // Table may not exist — fall back to single-region
            }

            if (empty($serviceRegions)) {
                // Legacy: single region, use the product's assigned server
                $serviceRegions = [(object)['server_id' => $serverId, 'is_primary' => true]];
            }

            foreach ($serviceRegions as $sr) {
                try {
                    $regionClient = impulseminio_getClientForServer((int)$sr->server_id, $params);

                    // Get buckets on this region
                    $regionBuckets = [];
                    if ($sr->is_primary) {
                        $regionBuckets = impulseminio_getAllBuckets($service->id);
                        if (empty($regionBuckets)) {
                            $bn = Capsule::table('tblcustomfieldsvalues')
                                ->join('tblcustomfields', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
                                ->where('tblcustomfields.fieldname', 'Bucket Name')
                                ->where('tblcustomfieldsvalues.relid', $service->id)
                                ->value('tblcustomfieldsvalues.value');
                            if ($bn) $regionBuckets = [$bn];
                        }
                    } else {
                        // Replication destination: get dest_bucket from replication jobs
                        $regionBuckets = Capsule::table('mod_impulseminio_replication_jobs')
                            ->where('service_id', $service->id)
                            ->join('mod_impulseminio_regions as r', 'mod_impulseminio_replication_jobs.dest_region_id', '=', 'r.id')
                            ->where('r.server_id', $sr->server_id)
                            ->whereNotIn('mod_impulseminio_replication_jobs.status', ['removing', 'deleted'])
                            ->pluck('mod_impulseminio_replication_jobs.dest_bucket')
                            ->toArray();
                    }

                    foreach ($regionBuckets as $b) {
                        $du = $regionClient->getBucketUsage($b);
                        $totalDiskBytes += $du['sizeBytes'];
                        $bw = $regionClient->getBucketBandwidth($b);
                        $totalBwRx += $bw['bytesReceived'];
                        $totalBwTx += $bw['bytesSent'];
                    }
                } catch (\Exception $e) {
                    logModuleCall(
                        'impulseminio',
                        'UsageUpdate:region',
                        ['serviceId' => $service->id, 'serverId' => $sr->server_id],
                        $e->getMessage()
                    );
                }
            }

            $product = Capsule::table('tblproducts')->where('id', $service->packageid)->first();
            $diskLimitGB = (int)($product->configoption1 ?? 0);
            $bwLimitGB = (int)($product->configoption2 ?? 0);

            Capsule::table('tblhosting')->where('id', $service->id)->update([
                'diskusage' => round($totalDiskBytes / (1024 * 1024), 2),
                'disklimit' => $diskLimitGB * 1024,
                'bwusage' => round(($totalBwRx + $totalBwTx) / (1024 * 1024), 2),
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

    // Load replica buckets from replication jobs
    $replicaBuckets = [];
    $replJobs = [];
    try {
        $replJobs = Capsule::table('mod_impulseminio_replication_jobs as j')
            ->leftJoin('mod_impulseminio_regions as sr', 'j.source_region_id', '=', 'sr.id')
            ->leftJoin('mod_impulseminio_regions as dr', 'j.dest_region_id', '=', 'dr.id')
            ->where('j.service_id', $serviceId)
            ->whereNotIn('j.status', ['removing', 'deleted'])
            ->select(
                'j.*',
                'sr.name as src_region_name',
                'sr.cdn_endpoint as src_cdn',
                'dr.name as dest_region_name',
                'dr.cdn_endpoint as dest_cdn',
                'dr.server_id as dest_server_id'
            )
            ->get()->toArray();
        foreach ($replJobs as $rj) {
            $replicaBuckets[] = (object)[
                'bucket_name' => $rj->dest_bucket,
                'label' => $rj->description ?: 'Replica',
                'is_primary' => false,
                'is_replica' => true,
                'region_name' => $rj->dest_region_name,
                'region_cdn' => $rj->dest_cdn,
                'source_bucket' => $rj->source_bucket,
                'source_region' => $rj->src_region_name,
                'server_id' => $rj->dest_server_id,
                'job_status' => $rj->status,
                'created_at' => $rj->created_at,
            ];
        }
    } catch (\Exception $e) {}

    // Get primary region info
    $primaryRegion = null;
    try {
        $prLink = Capsule::table('mod_impulseminio_service_regions')
            ->where('service_id', $serviceId)->where('is_primary', true)->first();
        if ($prLink) {
            $primaryRegion = Capsule::table('mod_impulseminio_regions')
                ->where('id', $prLink->region_id)->first();
        }
    } catch (\Exception $e) {}
    $primaryRegionName = $primaryRegion ? $primaryRegion->name : '';
    $primaryCdn = $primaryRegion ? ($primaryRegion->cdn_endpoint ?: '') : '';

    // Build a map of bucket_name => server_id for file browser region routing
    $bucketServerMap = [];
    foreach ($buckets as $b) {
        $bucketServerMap[$b->bucket_name] = $primaryRegion ? (int)$primaryRegion->server_id : 0;
    }
    foreach ($replicaBuckets as $rb) {
        $bucketServerMap[$rb->bucket_name . ':replica:' . $rb->server_id] = (int)$rb->server_id;
    }

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
    $o .= '<li role="presentation"><a href="#tab-quickstart" data-toggle="tab"><i class="fas fa-rocket"></i> Quick Start</a></li>';
    $o .= '<li role="presentation"><a href="#tab-buckets" data-toggle="tab"><i class="fas fa-archive"></i> Buckets <span class="badge">' . $bucketCount . '</span></a></li>';
    $o .= '<li role="presentation"><a href="#tab-keys" data-toggle="tab"><i class="fas fa-key"></i> Access Keys <span class="badge">' . $keyCount . '</span></a></li>';
    $o .= '<li role="presentation"><a href="#tab-files" data-toggle="tab"><i class="fas fa-folder-open"></i> File Browser</a></li>';
    $o .= '<li role="presentation"><a href="#tab-stats" data-toggle="tab"><i class="fas fa-chart-area"></i> Statistics</a></li>';
    if (impulseminio_hasReplication()) {
        $replJobCount = Capsule::table('mod_impulseminio_replication_jobs')
            ->where('service_id', $serviceId)->whereNotIn('status', ['removing', 'deleted'])->count();
        $o .= '<li role="presentation"><a href="#tab-replication" data-toggle="tab"><i class="fas fa-sync-alt"></i> Replication' . ($replJobCount > 0 ? ' <span class="badge">' . $replJobCount . '</span>' : '') . '</a></li>';
    }
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
    //     $o .= '<tr><td style="font-weight:600;vertical-align:middle;padding:10px;">Region</td><td><input type="text" class="form-control input-sm" value="us-east-1" readonly style="font-family:monospace;max-width:200px;"></td></tr>';
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
    $o .= '<div class="col-md-6"><h4>Bandwidth (Egress)</h4><div class="progress" style="height:25px;margin-bottom:5px;"><div class="progress-bar ' . $bwColor . '" role="progressbar" style="width:' . $bwPercent . '%;min-width:2em;line-height:25px;">' . $bwPercent . '%</div></div><p class="text-muted">' . $bwUsage . ' of ' . $bwLimit . ' transferred</p></div>';
    $o .= '</div>';
    $o .= '<small class="text-muted"><i class="fas fa-clock"></i> Last updated: ' . $lastUpdate . '</small>';
    $o .= '</div></div>';


    // === MIGRATION CARDS (Overview tab) ===
    if (impulseminio_hasMigration()) {
        require_once __DIR__ . '/lib/Migration.php';
        $migrations = \WHMCS\Module\Server\ImpulseMinio\Migration::getServiceMigrations($serviceId);
        $activeMigrations = array_filter($migrations, function($m) { return in_array($m->status, ['pending', 'scanning', 'running']); });
        $completedMigrations = array_filter($migrations, function($m) { return in_array($m->status, ['complete', 'error', 'cancelled', 'interrupted']); });

        // Active migration progress cards
        foreach ($activeMigrations as $mig) {
            $pct = ($mig->total_bytes > 0) ? min(100, round(($mig->migrated_bytes / $mig->total_bytes) * 100, 1)) : 0;
            $providerLabel = \WHMCS\Module\Server\ImpulseMinio\Migration::PROVIDERS[$mig->provider]['label'] ?? ucfirst($mig->provider);
            $migBytes = \WHMCS\Module\Server\ImpulseMinio\Migration::formatBytes((int)$mig->migrated_bytes);
            $totalBytes = \WHMCS\Module\Server\ImpulseMinio\Migration::formatBytes((int)$mig->total_bytes);
            $o .= '<div class="panel panel-info" id="mig-card-' . (int)$mig->id . '"><div class="panel-heading"><h3 class="panel-title"><i class="fas fa-sync-alt fa-spin"></i> Migration: ' . $esc($providerLabel) . ' &rarr; ' . $esc($mig->dest_bucket) . '</h3></div>';
            $o .= '<div class="panel-body">';
            $o .= '<div class="progress" style="height:22px;margin-bottom:8px;"><div class="progress-bar progress-bar-info progress-bar-striped active" id="mig-bar-' . (int)$mig->id . '" style="width:' . $pct . '%;min-width:2em;line-height:22px;">' . $pct . '%</div></div>';
            $o .= '<p class="text-muted" id="mig-detail-' . (int)$mig->id . '">' . (int)$mig->migrated_objects . ' / ' . (int)$mig->total_objects . ' objects &middot; ' . $migBytes . ' of ' . $totalBytes . '</p>';
            $o .= '<p class="text-muted" style="font-size:12px;">Started: ' . $esc($mig->started_at ?? '') . '</p>';
            $o .= '<button class="btn btn-danger btn-xs" onclick="migCancel(' . (int)$mig->id . ')"><i class="fas fa-times"></i> Cancel Migration</button>';
            $o .= '</div></div>';
        }

        // Completed migration cards
        foreach ($completedMigrations as $mig) {
            $providerLabel = \WHMCS\Module\Server\ImpulseMinio\Migration::PROVIDERS[$mig->provider]['label'] ?? ucfirst($mig->provider);
            $icon = $mig->status === 'complete' ? '<i class="fas fa-check-circle text-success"></i>' : '<i class="fas fa-exclamation-circle text-danger"></i>';
            $statusLabel = ucfirst($mig->status);
            $totalBytes = \WHMCS\Module\Server\ImpulseMinio\Migration::formatBytes((int)$mig->total_bytes);
            $o .= '<div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title">' . $icon . ' ' . $statusLabel . ': ' . $esc($providerLabel) . ' &rarr; ' . $esc($mig->dest_bucket) . '</h3></div>';
            $o .= '<div class="panel-body">';
            $o .= '<p>' . (int)$mig->migrated_objects . ' objects &middot; ' . $totalBytes . ' &middot; ' . $statusLabel . '</p>';
            if (!empty($mig->error_message)) $o .= '<p class="text-danger" style="font-size:13px;">' . $esc($mig->error_message) . '</p>';
            if (!empty($mig->completed_at)) $o .= '<p class="text-muted" style="font-size:12px;">Completed: ' . $esc($mig->completed_at) . '</p>';
            $o .= '<button class="btn btn-default btn-xs" onclick="migDismiss(' . (int)$mig->id . ')"><i class="fas fa-times"></i> Dismiss</button>';
            $o .= '</div></div>';
        }

        // Migrate Data button + wizard modal trigger
        if (empty($activeMigrations)) {
            $o .= '<div style="margin-top:15px;"><button class="btn btn-primary btn-sm" onclick="document.getElementById(\'migWizard\').style.display=\'block\';migStep(1);"><i class="fas fa-cloud-download-alt"></i> Migrate Data</button></div>';
        }

        // Migration wizard (hidden by default)
        $providers = \WHMCS\Module\Server\ImpulseMinio\Migration::getProviders();
        $providerOptions = '';
        foreach ($providers as $pk => $pv) {
            $providerOptions .= '<option value="' . $esc($pk) . '" data-regions="' . $esc(json_encode($pv['regions'])) . '" data-region-required="' . ($pv['region_required'] ? '1' : '0') . '" data-custom-endpoint="' . (($pv['custom_endpoint'] ?? false) ? '1' : '0') . '">' . $esc($pv['label']) . '</option>';
        }
        $destBucketOptions = '';
        foreach ($buckets as $b) {
            $destBucketOptions .= '<option value="' . $esc($b->bucket_name) . '">' . $esc($b->bucket_name) . '</option>';
        }

        $o .= '<div id="migWizard" style="display:none;margin-top:20px;">';
        $o .= '<div class="panel panel-primary"><div class="panel-heading"><h3 class="panel-title"><i class="fas fa-cloud-download-alt"></i> Migrate Data from Another Provider</h3></div>';
        $o .= '<div class="panel-body">';

        // Step 1: Configure Source
        $o .= '<div id="migStep1">';
        $o .= '<h4>Step 1: Configure Source</h4>';
        $o .= '<div class="form-group"><label>Provider</label><select id="migProvider" class="form-control" onchange="migProviderChanged()">' . $providerOptions . '</select></div>';
        $o .= '<div class="form-group" id="migRegionGroup" style="display:none;"><label>Region</label><select id="migRegion" class="form-control"></select></div>';
        $o .= '<div class="form-group" id="migCustomEndpointGroup" style="display:none;"><label>S3 Endpoint URL</label><input type="text" id="migCustomEndpoint" class="form-control" placeholder="https://s3.example.com"></div>';
        $o .= '<div class="form-group"><label>Access Key ID</label><input type="text" id="migAccessKey" class="form-control" placeholder="Your source provider access key"></div>';
        $o .= '<div class="form-group"><label>Secret Access Key</label><input type="password" id="migSecretKey" class="form-control" placeholder="Your source provider secret key"></div>';
        $o .= '<p class="text-muted" style="font-size:12px;"><i class="fas fa-shield-alt"></i> Your credentials are used only during the migration and are never stored permanently.</p>';
        $o .= '<div id="migStep1Status"></div>';
        $o .= '<button class="btn btn-primary" onclick="migValidateSource()"><i class="fas fa-plug"></i> Connect &amp; List Buckets</button>';
        $o .= ' <button class="btn btn-default" onclick="document.getElementById(\'migWizard\').style.display=\'none\';">Cancel</button>';
        $o .= '</div>';

        // Step 2: Select Source Bucket
        $o .= '<div id="migStep2" style="display:none;">';
        $o .= '<h4>Step 2: Select Source Bucket</h4>';
        $o .= '<div class="form-group"><label>Source Bucket</label><select id="migSourceBucket" class="form-control"></select></div>';
        $o .= '<div id="migStep2Status"></div>';
        $o .= '<button class="btn btn-primary" onclick="migScanBucket()"><i class="fas fa-search"></i> Scan Bucket</button>';
        $o .= ' <button class="btn btn-default" onclick="migStep(1)">Back</button>';
        $o .= '</div>';

        // Step 3: Map Destination
        $o .= '<div id="migStep3" style="display:none;">';
        $o .= '<h4>Step 3: Choose Destination</h4>';
        $o .= '<div class="well" id="migScanResult"></div>';
        $o .= '<div class="form-group"><label>Destination Bucket</label><select id="migDestBucket" class="form-control">' . $destBucketOptions . '</select></div>';
        $o .= '<div id="migStep3Status"></div>';
        $o .= '<button class="btn btn-success" onclick="migStart()"><i class="fas fa-play"></i> Start Migration</button>';
        $o .= ' <button class="btn btn-default" onclick="migStep(2)">Back</button>';
        $o .= '</div>';

        // Step 4: In Progress (replaces wizard)
        $o .= '<div id="migStep4" style="display:none;">';
        $o .= '<h4><i class="fas fa-sync-alt fa-spin"></i> Migration In Progress</h4>';
        $o .= '<div class="progress" style="height:22px;"><div class="progress-bar progress-bar-info progress-bar-striped active" id="migWizardBar" style="width:0%;min-width:2em;line-height:22px;">0%</div></div>';
        $o .= '<p class="text-muted" id="migWizardDetail">Starting...</p>';
        $o .= '<p class="text-muted" style="font-size:12px;">You can close this panel. Migration continues in the background.</p>';
        $o .= '<button class="btn btn-default btn-sm" onclick="location.reload();">Close</button>';
        $o .= '</div>';

        $o .= '</div></div></div>'; // end wizard panel
    }

    $o .= '</div>'; // end overview tab

    // === BUCKETS TAB ===
    $o .= '<div role="tabpanel" class="tab-pane" id="tab-buckets">';
    $mbDisplay = $maxBuckets > 0 ? $maxBuckets : 'Unlimited';
    $o .= '<div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title"><i class="fas fa-archive"></i> Your Buckets <span class="pull-right" style="font-size:13px;font-weight:normal;">' . $bucketCount . ' / ' . $mbDisplay . '</span></h3></div>';
    $o .= '<div class="panel-body">';
    $regionCol = (!empty($primaryRegionName) || !empty($replicaBuckets)) ? '<th>Region</th>' : '';
    $versioningHeader = $versioningAllowed ? '<th>Versioning</th>' : '';
    $o .= '<table class="table table-striped table-hover"><thead><tr><th>Bucket Name</th><th>Label</th>' . $regionCol . $versioningHeader . (impulseminio_hasPublicAccess() ? '<th style="width:100px;">Public</th>' : '') . '<th>Created</th><th></th></tr></thead><tbody>';
    // Primary buckets
    foreach ($buckets as $b) {
        $bn = $esc($b->bucket_name);
        $bl = $esc($b->label ?: '-');
        $bc = $esc($b->created_at);
        $publicCell = '';
        $corsBtn = '';
        $corsValue = '';
        if (impulseminio_hasPublicAccess()) {
            require_once __DIR__ . '/lib/PublicAccess.php';
            $isPub = \WHMCS\Module\Server\ImpulseMinio\PublicAccess::isPublic($serviceId, $b->bucket_name);
            $cdnEndpoint = $params['configoption11'] ?? '';
            $pubUrl = $isPub && $cdnEndpoint ? \WHMCS\Module\Server\ImpulseMinio\PublicAccess::getPublicUrl($cdnEndpoint, $b->bucket_name) : '';
            $pubCopyBtn = $isPub && $pubUrl ? ' <button class="btn btn-xs btn-default" onclick="event.stopPropagation();navigator.clipboard.writeText(\'' . $esc($pubUrl) . '\').then(function(){alert(\'CDN URL copied to clipboard\')});" title="' . $esc($pubUrl) . '"><i class="fas fa-link"></i></button>' : '';
            $corsBtn = '';
            if ($isPub && impulseminio_hasCors()) {
                $corsOrigins = \WHMCS\Module\Server\ImpulseMinio\PublicAccess::getCorsOrigins($serviceId, $b->bucket_name);
                $corsValue = $esc(implode("\n", $corsOrigins));
                $corsBtn = ' <button class="btn btn-xs btn-default" onclick="event.stopPropagation();toggleCorsPanel(\'' . $bn . '\')" title="CORS Settings"><i class="fas fa-cog"></i></button>';
            }
            $pubLabel = $isPub ? '<span class="label label-success" style="cursor:pointer;" onclick="togglePublic(\'' . $bn . '\')"><i class="fas fa-globe"></i> On</span>' . $pubCopyBtn . $corsBtn : '<span class="label label-default" style="cursor:pointer;" onclick="togglePublic(\'' . $bn . '\')">Off</span>';
            $publicCell = '<td style="text-align:center;white-space:nowrap;">' . $pubLabel . '</td>';
        }
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
        $regionCell = '';
        if (!empty($regionCol)) {
            $regionCell = '<td><span style="font-size:12px;">' . $esc($primaryRegionName) . '</span></td>';
        }
        $o .= '<tr><td><code>' . $bn . '</code>' . $primary . '</td><td>' . $bl . '</td>' . $regionCell . $versioningCell . $publicCell . '<td>' . $bc . '</td><td>' . $del . '</td></tr>';
        if (!empty($corsBtn)) {
            $colSpan = 5 + (!empty($regionCol) ? 1 : 0) + ($versioningAllowed ? 1 : 0) + (impulseminio_hasPublicAccess() ? 1 : 0);
            $o .= '<tr id="cors-panel-' . $bn . '" style="display:none;"><td colspan="' . $colSpan . '" style="background:#f8f9fa;padding:15px;">';
            $o .= '<div style="max-width:500px;"><label style="font-weight:600;font-size:13px;margin-bottom:8px;"><i class="fas fa-globe"></i> Allowed CORS Origins</label>';
            $o .= '<textarea id="cors-origins-' . $bn . '" class="form-control input-sm" rows="4" style="font-family:monospace;font-size:12px;margin-bottom:8px;" placeholder="*&#10;https://example.com&#10;https://app.example.com">' . $corsValue . '</textarea>';
            $o .= '<div class="text-muted" style="font-size:11px;margin-bottom:10px;">';
            $o .= '<strong>Examples:</strong><br>';
            $o .= '<code>*</code> — allow all origins (default)<br>';
            $o .= '<code>https://example.com</code> — allow a specific domain<br>';
            $o .= '<code>https://app.example.com</code> — allow a subdomain<br>';
            $o .= 'One origin per line. Must start with <code>http://</code> or <code>https://</code> (unless using <code>*</code>).';
            $o .= '</div>';
            $o .= '<div class="alert alert-info" style="margin-top:10px;margin-bottom:10px;padding:10px 12px;font-size:12px;"><i class="fas fa-globe" style="margin-right:6px;"></i><strong>Static Website Hosting:</strong> Upload an <code>index.html</code> file to your public bucket and it will automatically be served as the homepage when visitors access your public URL.</div>';

            // Custom Domain section (Everything tier only)
            if (impulseminio_hasCustomDomains()) {
                $cdInfo = \WHMCS\Module\Server\ImpulseMinio\PublicAccess::getCustomDomain($serviceId, $b->bucket_name);
                $hasDomain = !empty($cdInfo['domain']);
                $o .= '<div style="border-top:1px solid #e0e0e0;padding-top:12px;margin-top:12px;">';
                $o .= '<label style="font-weight:600;font-size:13px;margin-bottom:8px;"><i class="fas fa-link"></i> Custom Domain</label>';
                if ($hasDomain) {
                    $statusColors = ['active' => '#28a745', 'pending' => '#ffc107', 'pending_validation' => '#ffc107', 'moved' => '#dc3545'];
                    $statusLabels = ['active' => 'Active', 'pending' => 'Waiting for DNS', 'pending_validation' => 'Issuing Certificate', 'moved' => 'DNS Changed'];
                    $sc = $statusColors[$cdInfo['cf_status']] ?? '#999';
                    $sl = $statusLabels[$cdInfo['cf_status']] ?? ucfirst($cdInfo['cf_status'] ?? 'Unknown');
                    $o .= '<div style="background:#f0f8ff;border:1px solid #d0e8ff;border-radius:4px;padding:10px 12px;margin-bottom:8px;">';
                    $o .= '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">';
                    $o .= '<code style="font-size:13px;">' . $esc($cdInfo['domain']) . '</code>';
                    $o .= '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' . $sc . ';"></span>';
                    $o .= '<span style="font-size:12px;color:' . $sc . ';">' . $sl . '</span>';
                    $o .= '</div>';
                    if ($cdInfo['cf_status'] === 'pending' || $cdInfo['cf_status'] === 'pending_validation') {
                        // Show CNAME instructions
                        $cdnHost = '';
                        if (!empty($primaryCdn)) {
                            $cdnHost = $esc($b->bucket_name) . '.' . str_replace('https://', '', $esc($primaryCdn));
                        }
                        $o .= '<div style="margin-top:8px;font-size:12px;color:#555;">';
                        if ($cdInfo['cf_status'] === 'pending') {
                            $o .= '<strong>Step 1:</strong> Add this DNS record at your domain provider:<br>';
                            $o .= '<code style="display:inline-block;margin:4px 0;padding:3px 8px;background:#f4f4f4;border-radius:3px;">CNAME ' . $esc($cdInfo['domain']) . ' &rarr; ' . $cdnHost . '</code><br>';
                            $o .= '<span style="color:#888;">DNS changes can take a few minutes to propagate. Click "Check Status" after adding the record.</span>';
                        } else {
                            $o .= '<strong>Step 2:</strong> DNS verified. SSL certificate is being issued automatically.<br>';
                            $o .= '<span style="color:#888;">This typically takes 2&ndash;15 minutes. No action needed &mdash; check back shortly.</span>';
                        }
                        $o .= '</div>';
                        $o .= '<button class="btn btn-xs btn-default" style="margin-top:6px;" onclick="cdPollStatus(\'' . $bn . '\')"><i class="fas fa-sync-alt"></i> Check Status</button>';
                    }
                    if ($cdInfo['cf_status'] === 'active') {
                        $o .= '<div style="margin-top:6px;font-size:12px;color:#28a745;"><i class="fas fa-check-circle"></i> Your custom domain is live. SSL certificate active.</div>';
                    }
                    $o .= '</div>';
                    $o .= '<button class="btn btn-xs btn-danger" onclick="cdRemoveDomain(\'' . $bn . '\')"><i class="fas fa-times"></i> Remove Custom Domain</button>';
                    $o .= ' <span id="cd-status-' . $bn . '" style="font-size:12px;"></span>';
                } else {
                    $o .= '<div style="margin-bottom:8px;">';
                    $o .= '<div class="input-group" style="max-width:400px;"><input type="text" id="cd-input-' . $bn . '" class="form-control input-sm" placeholder="assets.yourdomain.com"><span class="input-group-btn"><button class="btn btn-primary btn-sm" onclick="cdAddDomain(\'' . $bn . '\')"><i class="fas fa-plus"></i> Add</button></span></div>';
                    $o .= '<div class="text-muted" style="font-size:11px;margin-top:4px;">Enter your custom domain (e.g., assets.yourdomain.com). After adding, you\'ll need to create a CNAME record at your DNS provider. SSL is provisioned automatically &mdash; the full process typically takes 5&ndash;15 minutes.</div>';
                    $o .= '</div>';
                    $o .= '<span id="cd-status-' . $bn . '" style="font-size:12px;"></span>';
                }
                $o .= '</div>';
            }

            $o .= '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;">';
            $o .= '<button class="btn btn-default btn-xs" onclick="toggleCorsPanel(\'' . $bn . '\')"><i class="fas fa-times"></i> Cancel</button>';
            $o .= '<button class="btn btn-primary btn-xs" onclick="saveCors(\'' . $bn . '\')"><i class="fas fa-save"></i> Save CORS</button>';
            $o .= '</div></div></td></tr>';
        }
    }
    // Replica buckets
    foreach ($replicaBuckets as $rb) {
        $bn = $esc($rb->bucket_name);
        $statusBadge = $rb->job_status === 'active' ? '<span class="label label-info">Replica</span>' : '<span class="label label-warning">Replica (' . $esc($rb->job_status) . ')</span>';
        $regionCell = '<td><span style="font-size:12px;">' . $esc($rb->region_name) . '</span></td>';
        $vCell = $versioningAllowed ? '<td><span class="text-muted" style="font-size:11px;">Auto</span></td>' : '';
        $pubCell = impulseminio_hasPublicAccess() ? '<td style="text-align:center;"><span class="text-muted" style="font-size:11px;">—</span></td>' : '';
        $emptyColCount = 1; // actions column
        $o .= '<tr style="background:#f8f9fa;"><td><code>' . $bn . '</code> ' . $statusBadge . '</td><td>' . $esc($rb->label) . '</td>' . $regionCell . $vCell . $pubCell . '<td style="font-size:12px;">' . $esc($rb->created_at) . '</td><td><span class="text-muted" style="font-size:11px;">Read Only</span></td></tr>';
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
    $o .= '<select id="fb-bucket" class="form-control input-sm" style="width:auto;min-width:220px;" onchange="fbBucketChanged(this)">';
    // Primary buckets
    if (!empty($primaryRegionName) && !empty($replicaBuckets)) {
        $o .= '<optgroup label="' . $esc($primaryRegionName) . ' (Primary)">';
    }
    foreach ($buckets as $b) {
        $sel = ($b->bucket_name === ($buckets[0]->bucket_name ?? '')) ? ' selected' : '';
        $o .= '<option value="' . $esc($b->bucket_name) . '" data-readonly="0"' . $sel . '>' . $esc($b->label ?: $b->bucket_name) . '</option>';
    }
    if (!empty($primaryRegionName) && !empty($replicaBuckets)) {
        $o .= '</optgroup>';
    }
    // Replica buckets
    if (!empty($replicaBuckets)) {
        $seenReplRegions = [];
        foreach ($replicaBuckets as $rb) {
            if (!in_array($rb->region_name, $seenReplRegions)) {
                if (!empty($seenReplRegions)) $o .= '</optgroup>';
                $o .= '<optgroup label="' . $esc($rb->region_name) . ' (Replica)">';
                $seenReplRegions[] = $rb->region_name;
            }
            $o .= '<option value="' . $esc($rb->bucket_name) . '" data-readonly="1" data-server="' . (int)$rb->server_id . '">' . $esc($rb->bucket_name) . ' [Read Only]</option>';
        }
        $o .= '</optgroup>';
    }
    $o .= '</select>';
    $o .= '<nav id="fb-breadcrumb" style="flex:1;font-size:13px;"><a href="#" onclick="fbNavigate(null,\'\');return false;" style="font-weight:600;"><i class="fas fa-home"></i></a></nav>';
    $o .= '<span id="fb-readonly-badge" style="display:none;"><span class="label label-default" style="font-size:11px;"><i class="fas fa-lock"></i> Read Only</span></span>';
    $o .= '<button class="btn btn-success btn-sm" id="fb-upload-btn" onclick="fbShowUpload()" title="Upload files"><i class="fas fa-upload"></i> Upload</button>';
    $o .= '<button class="btn btn-success btn-sm" id="fb-folder-upload-btn" onclick="document.getElementById(\'fb-folder-input\').click()" title="Upload folder"><i class="fas fa-folder"></i> Upload Folder</button>';
    $o .= '<button class="btn btn-default btn-sm" id="fb-newfolder-btn" onclick="fbCreateFolder()" title="New folder"><i class="fas fa-folder-plus"></i> New Folder</button>';
    $o .= '<button class="btn btn-default btn-sm" onclick="fbRefresh()" title="Refresh"><i class="fas fa-sync-alt"></i></button>';
    $o .= '</div>';

    // Upload drop zone (hidden by default)
    $o .= '<div id="fb-upload-zone" style="display:none;margin-bottom:15px;padding:30px;border:2px dashed #ccc;border-radius:8px;text-align:center;background:#fafafa;cursor:pointer;" onclick="document.getElementById(\'fb-upload-input\').click()" ondrop="fbHandleDrop(event)" ondragover="event.preventDefault();this.style.borderColor=\'#1a1a2e\';this.style.background=\'#f0f0ff\'" ondragleave="this.style.borderColor=\'#ccc\';this.style.background=\'#fafafa\'">';
    $o .= '<i class="fas fa-cloud-upload-alt" style="font-size:32px;color:#999;margin-bottom:8px;display:block;"></i>';
    $o .= '<p style="margin:0;color:#666;">Drag and drop files here, or click to select</p>';
    $o .= '<input type="file" id="fb-upload-input" multiple style="display:none;" onchange="fbUploadFiles(this.files)">';
    $o .= '<input type="file" id="fb-folder-input" webkitdirectory multiple style="display:none;" onchange="fbUploadFiles(this.files,true)">';
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

    // ─── Statistics Tab ───
    $o .= '<div role="tabpanel" class="tab-pane" id="tab-stats">';
    $o .= '<div class="panel panel-default"><div class="panel-heading" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">';
    $o .= '<h3 class="panel-title" style="margin:0;"><i class="fas fa-chart-area"></i> Storage Statistics</h3>';
    $o .= '<div style="display:flex;align-items:center;gap:10px;">';
    $o .= '<select id="stats-metric" class="form-control input-sm" style="width:auto;" onchange="statsRefresh()">';
    $o .= '<option value="storage" selected>Storage Usage</option>';
    $o .= '<option value="downloads">Downloads</option>';
    $o .= '<option value="uploads">Uploads</option>';
    $o .= '<option value="objects">Object Count</option>';
    $o .= '<option value="replication_in">Inbound Replication</option>';
    $o .= '<option value="replication_out">Outbound Replication</option>';
    $o .= '</select>';
    $o .= '<select id="stats-range" class="form-control input-sm" style="width:auto;" onchange="statsRefresh()">';
    $o .= '<option value="24h">Last 24 Hours</option>';
    $o .= '<option value="7d" selected>Last 7 Days</option>';
    $o .= '<option value="30d">Last 30 Days</option>';
    $o .= '<option value="90d">Last 90 Days</option>';
    $o .= '</select>';
    $o .= '</div></div>';
    $o .= '<div class="panel-body">';
    $o .= '<div id="stats-loading" style="text-align:center;padding:40px;"><i class="fas fa-spinner fa-spin fa-2x" style="color:#999;"></i></div>';
    $o .= '<div id="stats-total" style="display:none;text-align:right;font-size:14px;color:#555;margin-bottom:10px;font-weight:600;"></div>';
    $o .= '<div style="position:relative;height:350px;"><canvas id="stats-chart" style="display:none;"></canvas></div>';
    $o .= '<div id="stats-empty" style="display:none;text-align:center;padding:40px;color:#999;"><i class="fas fa-chart-line" style="font-size:32px;display:block;margin-bottom:8px;"></i>No data available for this period</div>';
    $o .= '<p style="margin-top:10px;font-size:12px;color:#999;">Statistics are collected hourly and may take up to 1 hour to reflect recent changes.</p>';
    $o .= '</div></div></div>';

    // ─── Replication Tab ───
    if (impulseminio_hasReplication()) {
        $o .= '<div role="tabpanel" class="tab-pane" id="tab-replication">';
        $o .= '<div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title"><i class="fas fa-sync-alt"></i> Bucket Replication</h3></div>';
        $o .= '<div class="panel-body">';

        // Get replication jobs
        $o .= '<div id="repl-jobs-loading" style="text-align:center;padding:20px;"><i class="fas fa-spinner fa-spin fa-2x" style="color:#999;"></i></div>';
        $o .= '<div id="repl-jobs-empty" style="display:none;text-align:center;padding:30px;color:#999;">';
        $o .= '<i class="fas fa-sync-alt" style="font-size:32px;display:block;margin-bottom:8px;"></i>';
        $o .= '<p>No replication jobs configured.</p>';
        $o .= '<p style="font-size:13px;">Replicate your buckets across regions for redundancy and low-latency access.</p>';
        $o .= '</div>';

        // Jobs table
        $o .= '<table class="table table-hover" id="repl-jobs-table" style="display:none;">';
        $o .= '<thead><tr><th>Description</th><th>Source</th><th>Destination</th><th>Status</th><th></th></tr></thead>';
        $o .= '<tbody id="repl-jobs-body"></tbody>';
        $o .= '</table>';

        // Create job button
        $o .= '<div style="margin-top:15px;">';
        $o .= '<button class="btn btn-success btn-sm" onclick="replShowCreate()" id="repl-create-btn"><i class="fas fa-plus"></i> Create Replication Job</button>';
        $o .= '</div>';

        // Create job form (hidden by default)
        $o .= '<div id="repl-create-form" style="display:none;margin-top:15px;background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:16px;">';
        $o .= '<h4 style="margin:0 0 12px;">New Replication Job</h4>';

        $o .= '<div class="form-group"><label>Source Bucket</label>';
        $o .= '<select id="repl-src-bucket" class="form-control" style="max-width:400px;">';
        foreach ($buckets as $b) {
            $o .= '<option value="' . $esc($b->bucket_name) . '">' . $esc($b->label ?: $b->bucket_name) . '</option>';
        }
        $o .= '</select></div>';

        // Get available destination regions
        $primaryRegion = Capsule::table('mod_impulseminio_service_regions')
            ->where('service_id', $serviceId)->where('is_primary', true)->first();
        $primaryRegionId = $primaryRegion ? $primaryRegion->region_id : 0;
        $destRegions = Capsule::table('mod_impulseminio_regions')
            ->where('is_active', true)->where('id', '!=', $primaryRegionId)->orderBy('sort_order')->get();

        $o .= '<div class="form-group"><label>Destination Region</label>';
        $o .= '<select id="repl-dest-region" class="form-control" style="max-width:400px;">';
        foreach ($destRegions as $dr) {
            $o .= '<option value="' . (int)$dr->id . '">' . $esc($dr->flag) . ' ' . $esc($dr->name) . '</option>';
        }
        $o .= '</select></div>';

        $o .= '<div class="form-group"><label>Description <small class="text-muted">(optional)</small></label>';
        $o .= '<input type="text" id="repl-description" class="form-control" style="max-width:400px;" placeholder="e.g., Photos DR to Newark"></div>';

        // Advanced options
        $o .= '<div style="margin-bottom:12px;"><a href="#" onclick="document.getElementById(\'repl-advanced\').style.display=document.getElementById(\'repl-advanced\').style.display===\'none\'?\'block\':\'none\';return false;" style="font-size:13px;"><i class="fas fa-cog"></i> Advanced Options</a></div>';
        $o .= '<div id="repl-advanced" style="display:none;padding:10px;background:#f0f0f0;border-radius:4px;margin-bottom:12px;">';
        $o .= '<div class="checkbox"><label><input type="checkbox" id="repl-sync-existing" checked> Replicate existing objects</label></div>';
        $o .= '<div class="checkbox"><label><input type="checkbox" id="repl-sync-deletes" checked> Sync deleted objects</label></div>';
        $o .= '<div class="checkbox"><label><input type="checkbox" id="repl-sync-locking"> Sync object locking</label></div>';
        $o .= '<div class="checkbox"><label><input type="checkbox" id="repl-sync-tags" checked> Sync object tags</label></div>';
        $o .= '</div>';

        $o .= '<div id="repl-create-progress" style="margin-bottom:10px;"></div>';

        $o .= '<button class="btn btn-primary" onclick="replCreateJob()"><i class="fas fa-check"></i> Create Job</button>';
        $o .= ' <button class="btn btn-default" onclick="replHideCreate()">Cancel</button>';
        $o .= '</div>'; // create form

        $o .= '</div></div>'; // close panel-body and panel

        // Replication Endpoints panel (inside tab-replication)
        if (!empty($replJobs)) {
            $o .= '<div class="panel panel-default" style="margin-top:15px;"><div class="panel-heading"><h3 class="panel-title"><i class="fas fa-link"></i> Replication Endpoints</h3></div>';
            $o .= '<div class="panel-body">';
            $o .= '<p style="font-size:13px;color:#666;margin-bottom:12px;">Use the <strong>Primary</strong> endpoint for uploads and writes. <strong>Replica</strong> endpoints serve read-only copies for low-latency access or disaster recovery.</p>';
            $o .= '<table class="table table-bordered" style="font-size:13px;"><thead><tr><th></th><th>Region</th><th>S3 Endpoint</th><th>CDN / Custom Domain</th><th>Access</th></tr></thead><tbody>';

            // Primary region
            $primaryS3 = $s3Endpoint ?: ($primaryCdn ?: '—');
            // Check for custom domain on primary
            $primaryCustomDomain = '';
            try {
                $pubBucket = Capsule::table('mod_impulseminio_public_buckets')
                    ->where('service_id', $serviceId)
                    ->whereNotNull('custom_domain')
                    ->where('custom_domain', '!=', '')
                    ->first();
                if ($pubBucket) $primaryCustomDomain = $pubBucket->custom_domain;
            } catch (\Exception $e) {}

            $primaryCdnDisplay = '';
            if (!empty($primaryCustomDomain)) {
                $primaryCdnDisplay = 'https://' . $esc($primaryCustomDomain);
            } elseif (!empty($primaryCdn) && !empty($defaultBucket)) {
                $primaryCdnDisplay = 'https://' . $esc($defaultBucket) . '.' . str_replace('https://', '', $esc($primaryCdn));
            }

            $o .= '<tr>';
            $o .= '<td><span class="label label-primary">Primary</span></td>';
            $o .= '<td>' . $esc($primaryRegionName) . '</td>';
            $o .= '<td><code style="font-size:11px;">' . $esc($primaryS3) . '</code> <button class="btn btn-xs btn-default" onclick="navigator.clipboard.writeText(\'' . $esc($primaryS3) . '\');this.innerHTML=\'<i class=\\\'fas fa-check\\\'></i>\';var b=this;setTimeout(function(){b.innerHTML=\'<i class=\\\'fas fa-copy\\\'></i>\';},1000);" title="Copy"><i class="fas fa-copy"></i></button></td>';
            $o .= '<td>' . (!empty($primaryCdnDisplay) ? '<code style="font-size:11px;">' . $esc($primaryCdnDisplay) . '</code>' : '<span class="text-muted">—</span>') . '</td>';
            $o .= '<td><span class="label label-success">Read / Write</span></td>';
            $o .= '</tr>';

            // Replica regions (deduplicated)
            $seenRegions = [];
            foreach ($replJobs as $rj) {
                if (in_array($rj->dest_region_id, $seenRegions)) continue;
                $seenRegions[] = $rj->dest_region_id;
                $destS3 = $rj->dest_cdn ?: '—';
                $destCdnDisplay = '';
                if (!empty($rj->dest_cdn) && !empty($rj->dest_bucket)) {
                    $destCdnDisplay = 'https://' . $esc($rj->dest_bucket) . '.' . str_replace('https://', '', $esc($rj->dest_cdn));
                }
                $o .= '<tr style="background:#f8f9fa;">';
                $o .= '<td><span class="label label-info">Replica</span></td>';
                $o .= '<td>' . $esc($rj->dest_region_name) . '</td>';
                $o .= '<td><code style="font-size:11px;">' . $esc($destS3) . '</code> <button class="btn btn-xs btn-default" onclick="navigator.clipboard.writeText(\'' . $esc($destS3) . '\');this.innerHTML=\'<i class=\\\'fas fa-check\\\'></i>\';var b=this;setTimeout(function(){b.innerHTML=\'<i class=\\\'fas fa-copy\\\'></i>\';},1000);" title="Copy"><i class="fas fa-copy"></i></button></td>';
                $o .= '<td>' . (!empty($destCdnDisplay) ? '<code style="font-size:11px;">' . $esc($destCdnDisplay) . '</code>' : '<span class="text-muted">—</span>') . '</td>';
                $o .= '<td><span class="label label-default">Read Only</span></td>';
                $o .= '</tr>';
            }

            $o .= '</tbody></table>';
            $o .= '</div></div>';
        }

        // Suspended jobs alert
        $suspendedJobs = Capsule::table('mod_impulseminio_replication_jobs')
            ->where('service_id', $serviceId)->where('status', 'suspended')->count();
        if ($suspendedJobs > 0) {
            $o .= '<div class="alert alert-warning" style="margin-top:10px;"><i class="fas fa-exclamation-triangle"></i> <strong>' . $suspendedJobs . ' replication job(s) were paused during suspension.</strong> Review and re-enable them in the Replication tab.</div>';
        }

        $o .= '</div>'; // close tab-replication
    }

    $o .= '</div>'; // tab-content
    $o .= '</div>'; // impulsedrive-dashboard

    // JavaScript
    $o .= '<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>';
    $o .= '<script>';
    // Statistics chart functions
    $o .= 'var statsChart=null;var statsChartCtx=null;';
    $o .= 'function statsFormatBytes(b){if(b===0)return"0 B";var k=1024,s=["B","KB","MB","GB","TB"],i=Math.floor(Math.log(b)/Math.log(k));return parseFloat((b/Math.pow(k,i)).toFixed(2))+" "+s[i];}';
    $o .= 'function statsFormatNum(n){return n.toLocaleString();}';
    $o .= 'function statsRefresh(){var m=document.getElementById("stats-metric").value;var r=document.getElementById("stats-range").value;var el=document.getElementById("stats-loading");var ch=document.getElementById("stats-chart");var em=document.getElementById("stats-empty");var to=document.getElementById("stats-total");el.style.display="block";ch.style.display="none";em.style.display="none";to.style.display="none";';
    $o .= 'fbAjax("clientGetUsageHistory",{metric:m,range:r},function(d){el.style.display="none";try{if(!d.success||d.labels.length===0){em.style.display="block";return;}';
    $o .= 'ch.style.display="block";to.style.display="block";';
    $o .= 'var fmt=d.isBytes?statsFormatBytes:statsFormatNum;to.textContent="Total: "+fmt(d.total);';
    $o .= 'var labels=d.labels.map(function(l){var dt=new Date(l.replace(" ","T")+"Z");if(d.range==="24h")return dt.toLocaleTimeString([],{hour:"2-digit",minute:"2-digit"});return dt.toLocaleDateString([],{month:"short",day:"numeric"})+(d.range==="7d"?" "+dt.toLocaleTimeString([],{hour:"2-digit",minute:"2-digit"}):"");});';
    $o .= 'var maxVal=d.isBytes?Math.max.apply(null,d.values):0;var divisor=1;var yUnit="B";if(d.isBytes){if(maxVal>=1073741824){divisor=1073741824;yUnit="GB";}else if(maxVal>=1048576){divisor=1048576;yUnit="MB";}else if(maxVal>=1024){divisor=1024;yUnit="KB";}else{divisor=1;yUnit="B";}}var values=d.isBytes?d.values.map(function(v){return v/divisor;}):d.values;var _statsDivisor=divisor;';
    $o .= 'var yLabel=d.isBytes?yUnit:(d.metric==="objects"?"Objects":"Count");';
    $o .= 'var colors={"storage":["rgba(26,26,46,0.15)","rgba(26,26,46,0.8)"],"downloads":["rgba(52,152,219,0.15)","rgba(52,152,219,0.8)"],"uploads":["rgba(46,204,113,0.15)","rgba(46,204,113,0.8)"],"objects":["rgba(155,89,182,0.15)","rgba(155,89,182,0.8)"],"replication_in":["rgba(230,126,34,0.15)","rgba(230,126,34,0.8)"],"replication_out":["rgba(231,76,60,0.15)","rgba(231,76,60,0.8)"]};';
    $o .= 'var c=colors[d.metric]||colors.storage;';
    $o .= 'if(statsChart){statsChart.destroy();}';
    $o .= 'if(!statsChartCtx)statsChartCtx=document.getElementById("stats-chart").getContext("2d");';
    $o .= 'statsChart=new Chart(statsChartCtx,{type:"line",data:{labels:labels,datasets:[{label:document.getElementById("stats-metric").options[document.getElementById("stats-metric").selectedIndex].text,data:values,backgroundColor:c[0],borderColor:c[1],borderWidth:2,fill:true,tension:0.3,pointRadius:d.labels.length>100?0:3,pointHoverRadius:5}]},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:"index",intersect:false},scales:{y:{beginAtZero:true,title:{display:true,text:yLabel}},x:{ticks:{maxTicksToShow:12,maxRotation:45}}},plugins:{tooltip:{callbacks:{label:function(ctx){return d.isBytes?statsFormatBytes(ctx.raw*_statsDivisor):statsFormatNum(ctx.raw);}}}}}});';
    $o .= '}catch(e){em.style.display="block";console.error("Stats error:",e);}});}';
    $o .= 'document.querySelectorAll(".nav-tabs a[href=\'#tab-stats\']").forEach(function(a){a.addEventListener("click",function(){setTimeout(function(){if(!statsChart)statsRefresh();},150);});});';
    $o .= 'function idCopy(id){var i=document.getElementById(id),ot=i.type;i.type="text";i.select();i.setSelectionRange(0,99999);navigator.clipboard?navigator.clipboard.writeText(i.value):document.execCommand("copy");i.type=ot;var b=i.closest(".input-group").querySelector("[title=Copy]");if(b){var oh=b.innerHTML;b.innerHTML=\'<i class="fas fa-check text-success"></i>\';setTimeout(function(){b.innerHTML=oh;},1500);}}';
    $o .= 'function togglePw(id){var i=document.getElementById(id),ic=document.getElementById(id+"-eye");if(i.type==="password"){i.type="text";ic.className="fas fa-eye-slash";}else{i.type="password";ic.className="fas fa-eye";}}';
    $o .= 'var csrfToken=(document.querySelector("input[name=token]")||{}).value||"";function deleteBucket(n){if(!confirm("Delete bucket \\""+n+"\\"? All files will be permanently deleted.")){return;}var f=document.createElement("form");f.method="post";f.action="clientarea.php?action=productdetails&id=' . $serviceId . '#buckets";f.innerHTML=\'<input type="hidden" name="token" value="\'+ csrfToken+\'"><input type="hidden" name="modop" value="custom"><input type="hidden" name="a" value="clientDeleteBucket"><input type="hidden" name="id" value="' . $serviceId . '"><input type="hidden" name="bucket_name" value="\'+n+\'">\';document.body.appendChild(f);f.submit();}';
    $o .= 'function deleteKey(k){if(!confirm("Revoke access key \\""+k+"\\"?")){return;}var f=document.createElement("form");f.method="post";f.action="clientarea.php?action=productdetails&id=' . $serviceId . '#accesskeys";f.innerHTML=\'<input type="hidden" name="token" value="\'+ csrfToken+\'"><input type="hidden" name="modop" value="custom"><input type="hidden" name="a" value="clientDeleteAccessKey"><input type="hidden" name="id" value="' . $serviceId . '"><input type="hidden" name="access_key_id" value="\'+k+\'">\';document.body.appendChild(f);f.submit();}';
    $o .= 'function toggleCorsPanel(n){var p=document.getElementById("cors-panel-"+n);if(p)p.style.display=p.style.display==="none"?"table-row":"none";}';
    $o .= 'function saveCors(n){var t=document.getElementById("cors-origins-"+n);if(!t)return;var lines=t.value.trim().split("\n").filter(function(l){return l.trim().length>0;});var valid=true;var bad="";for(var i=0;i<lines.length;i++){var l=lines[i].trim();if(l==="*")continue;if(!/^https?:\/\//.test(l)){valid=false;bad=l;break;}if(/\s/.test(l)){valid=false;bad=l;break;}}if(!valid){alert("Invalid origin: \""+bad+"\"\n\nOrigins must start with http:// or https:// (or use * for all).");return;}fbAjax("clientUpdateCors",{bucket_name:n,cors_origins:t.value},function(r){if(r.success){alert("CORS settings saved successfully.");toggleCorsPanel(n);}else{alert("Error: "+(r.error||"Unknown"));}});}';
    $o .= 'function togglePublic(n){var f=document.createElement("form");f.method="post";f.action="clientarea.php?action=productdetails&id=' . $serviceId . '#buckets";f.innerHTML=\'<input type="hidden" name="token" value="\'+csrfToken+\'"><input type="hidden" name="modop" value="custom"><input type="hidden" name="a" value="clientTogglePublic"><input type="hidden" name="id" value="' . $serviceId . '"><input type="hidden" name="bucket_name" value="\'+n+\'">\';document.body.appendChild(f);f.submit();}';
    $o .= 'function toggleVersioning(n){var msg=confirm("Toggle versioning on bucket \\""+n+"\\"?")?true:false;if(!msg)return;var f=document.createElement("form");f.method="post";f.action="clientarea.php?action=productdetails&id=' . $serviceId . '#buckets";f.innerHTML=\'<input type="hidden" name="token" value="\'+ csrfToken+\'"><input type="hidden" name="modop" value="custom"><input type="hidden" name="a" value="clientToggleVersioning"><input type="hidden" name="id" value="' . $serviceId . '"><input type="hidden" name="bucket_name" value="\'+n+\'">\';document.body.appendChild(f);f.submit();}';
    $o .= 'function resetPassword(){if(!confirm("Reset your secret key? Your current key will stop working immediately. You will need to update all applications using the current key.")){return;}var f=document.createElement("form");f.method="post";f.action="clientarea.php?action=productdetails&id=' . $serviceId . '#overview";f.innerHTML=\'<input type="hidden" name="token" value="\'+ csrfToken+\'"><input type="hidden" name="modop" value="custom"><input type="hidden" name="a" value="clientResetPassword"><input type="hidden" name="id" value="' . $serviceId . '">\';document.body.appendChild(f);f.submit();}';
    // Copy All credentials to clipboard
    $o .= 'function copyAllCreds(){var t="S3 Endpoint: "+document.getElementById("s3endpoint").value+"\\nAccess Key: "+document.getElementById("accesskey").value+"\\nSecret Key: "+document.getElementById("secretkey").value+"\\nDefault Bucket: "+document.getElementById("bucketname").value;navigator.clipboard?navigator.clipboard.writeText(t).then(function(){alert("Connection details copied to clipboard.")}):alert("Could not copy — use individual copy buttons instead.");}';
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
    $o .= 'var fbServiceId=' . $serviceId . ',fbCurrentBucket="' . $defaultBucket . '",fbCurrentPrefix="",fbCsrf=csrfToken,fbVersioning=false,fbReadOnly=false,fbRegionServerId=0;';
    $o .= 'function fbBucketChanged(sel){var opt=sel.options[sel.selectedIndex];fbReadOnly=opt.getAttribute("data-readonly")==="1";fbRegionServerId=parseInt(opt.getAttribute("data-server")||"0");fbNavigate(opt.value,"");var badge=document.getElementById("fb-readonly-badge");var uploadBtn=document.getElementById("fb-upload-btn");var folderBtn=document.getElementById("fb-folder-upload-btn");var newfolderBtn=document.getElementById("fb-newfolder-btn");if(fbReadOnly){badge.style.display="inline";uploadBtn.style.display="none";if(folderBtn)folderBtn.style.display="none";newfolderBtn.style.display="none";document.getElementById("fb-upload-zone").style.display="none";}else{badge.style.display="none";uploadBtn.style.display="inline-block";if(folderBtn)folderBtn.style.display="inline-block";newfolderBtn.style.display="inline-block";fbRegionServerId=0;}}';
    $o .= 'function fbAjax(action,data,cb){data.modop="custom";data.a=action;data.id=fbServiceId;data.token=fbCsrf;if(fbRegionServerId>0)data.region_server_id=fbRegionServerId;var x=new XMLHttpRequest();x.open("POST","clientarea.php?action=productdetails&id="+fbServiceId,true);x.setRequestHeader("Content-Type","application/x-www-form-urlencoded");x.setRequestHeader("X-Requested-With","XMLHttpRequest");x.onload=function(){try{cb(JSON.parse(x.responseText));}catch(e){cb({success:false,error:"Invalid response"});}};x.onerror=function(){cb({success:false,error:"Network error"});};var q=[];for(var k in data)q.push(encodeURIComponent(k)+"="+encodeURIComponent(data[k]));x.send(q.join("&"));}';

    $o .= 'function fbNavigate(bucket,prefix){if(bucket!==null)fbCurrentBucket=bucket;fbCurrentPrefix=prefix||"";fbRefresh();}';

    $o .= 'function fbRefresh(){var body=document.getElementById("fb-body"),loading=document.getElementById("fb-loading"),empty=document.getElementById("fb-empty"),tbl=document.getElementById("fb-table");body.innerHTML="";loading.style.display="block";tbl.style.display="none";empty.style.display="none";fbUpdateBreadcrumb();fbAjax("clientListObjects",{bucket_name:fbCurrentBucket,prefix:fbCurrentPrefix},function(r){loading.style.display="none";if(typeof r.versioning!=="undefined")fbVersioning=r.versioning;if(!r.success){body.innerHTML=\'<tr><td colspan="5" class="text-center text-danger">\'+r.error+\'</td></tr>\';tbl.style.display="table";return;}if(!r.objects||r.objects.length===0){empty.style.display="block";tbl.style.display="none";return;}tbl.style.display="table";r.objects.forEach(function(obj){var tr=document.createElement("tr");var delBtn=fbReadOnly?"":"<button class=\"btn btn-xs btn-danger\" onclick=\"fbDeleteObject(\'"+obj.key+"\',"+( obj.type==="folder"?"true":"false")+")\" title=\"Delete\"><i class=\"fas fa-trash\"></i></button>";if(obj.type==="folder"){tr.innerHTML=\'<td><i class="fas fa-folder" style="color:#f0c040;font-size:16px;"></i></td><td><a href="#" onclick="fbNavigate(null,\\\'\'+obj.key+\'\\\');return false;" style="font-weight:500;">\'+fbDisplayName(obj.key)+\'</a></td><td class="text-muted">&mdash;</td><td class="text-muted">&mdash;</td><td>\'+delBtn+\'</td>\';}else{tr.innerHTML=\'<td><i class="fas fa-file" style="color:#5c7cfa;font-size:16px;"></i></td><td>\'+fbDisplayName(obj.key)+\'</td><td class="text-muted">\'+fbFormatSize(obj.size)+\'</td><td class="text-muted">\'+fbFormatDate(obj.lastModified)+\'</td><td style="white-space:nowrap;"><button class="btn btn-xs btn-primary" onclick="fbDownload(\\\'\'+obj.key+\'\\\')" title="Download"><i class="fas fa-download"></i></button> \'+delBtn+\'</td>\';}body.appendChild(tr);});});}';

    $o .= 'function fbDisplayName(key){var parts=key.replace(fbCurrentPrefix,"").split("/");return parts.filter(function(p){return p.length>0;})[0]||key;}';

    $o .= 'function fbFormatSize(b){if(b===0)return"0 B";var u=["B","KB","MB","GB","TB"];var i=Math.floor(Math.log(b)/Math.log(1024));return(b/Math.pow(1024,i)).toFixed(i>0?1:0)+" "+u[i];}';

    $o .= 'function fbFormatDate(d){if(!d)return"";try{var dt=new Date(d);return dt.toLocaleDateString()+" "+dt.toLocaleTimeString([],{hour:"2-digit",minute:"2-digit"});}catch(e){return d;}}';

    $o .= 'function fbUpdateBreadcrumb(){var nav=document.getElementById("fb-breadcrumb");var html=\'<a href="#" onclick="fbNavigate(null,\\\'\\\');return false;" style="font-weight:600;"><i class="fas fa-home"></i> \'+fbCurrentBucket+\'</a>\';if(fbCurrentPrefix){var parts=fbCurrentPrefix.split("/").filter(function(p){return p.length>0;});var path="";parts.forEach(function(p){path+=p+"/";html+=\' <i class="fas fa-chevron-right" style="font-size:10px;color:#999;margin:0 4px;"></i> <a href="#" onclick="fbNavigate(null,\\\'\'+path+\'\\\');return false;">\'+p+\'</a>\';});}nav.innerHTML=html;}';

    $o .= 'function fbDownload(key){fbAjax("clientDownloadObject",{bucket_name:fbCurrentBucket,object_key:key},function(r){if(r.success&&r.url){window.open(r.url,"_blank");}else{alert("Download failed: "+(r.error||"Unknown error"));}});}';

    $o .= 'function fbDeleteObject(key,isFolder){var name=fbDisplayName(key);var msg;if(fbVersioning){msg=isFolder?"Delete folder \\""+name+"\\" and all contents?\\n\\nVersioning is ON — all versions of all objects in this folder will be permanently deleted.":"Delete \\""+name+"\\"?\\n\\nVersioning is ON — all versions of this file will be permanently deleted.";}else{msg=isFolder?"Permanently delete folder \\""+name+"\\" and all contents?\\n\\nThis cannot be undone.":"Permanently delete \\""+name+"\\"?\\n\\nThis cannot be undone.";}if(!confirm(msg))return;fbAjax("clientDeleteObject",{bucket_name:fbCurrentBucket,object_key:key},function(r){if(r.success){fbRefresh();}else{alert("Delete failed: "+(r.error||"Unknown error"));}});}';

    $o .= 'function fbCreateFolder(){var name=prompt("Enter folder name:");if(!name)return;name=name.replace(/[^a-zA-Z0-9._-]/g,"-").replace(/^-+|-+$/g,"");if(!name){alert("Invalid folder name.");return;}var path=fbCurrentPrefix+name+"/";fbAjax("clientCreateFolder",{bucket_name:fbCurrentBucket,folder_path:path},function(r){if(r.success){fbRefresh();}else{alert("Failed: "+(r.error||"Unknown error"));}});}';

    $o .= 'function fbShowUpload(){var z=document.getElementById("fb-upload-zone");z.style.display=z.style.display==="none"?"block":"none";}';

    $o .= 'function fbHandleDrop(e){e.preventDefault();e.currentTarget.style.borderColor="#ccc";e.currentTarget.style.background="#fafafa";if(e.dataTransfer.files.length>0)fbUploadFiles(e.dataTransfer.files);}';

    $o .= 'function fbUploadFiles(files,isFolder){var prog=document.getElementById("fb-upload-progress");var total=files.length,done=0,failed=0;if(total===0)return;prog.innerHTML=\'<div class="progress" style="height:20px;"><div class="progress-bar progress-bar-striped active" style="width:0%;">0/\'+total+\'</div></div>\';for(var i=0;i<total;i++){(function(file){var objectKey;if(isFolder&&file.webkitRelativePath){objectKey=fbCurrentPrefix+file.webkitRelativePath;}else{objectKey=fbCurrentPrefix+file.name;}fbAjax("clientGetUploadUrl",{bucket_name:fbCurrentBucket,object_key:objectKey},function(r){if(!r.success||!r.url){failed++;checkDone();return;}var fd=new FormData();if(r.fields){for(var k in r.fields){fd.append(k,r.fields[k]);}}fd.append("file",file);var xhr=new XMLHttpRequest();xhr.open("POST",r.url,true);xhr.onload=function(){if(xhr.status>=200&&xhr.status<300)done++;else failed++;checkDone();};xhr.onerror=function(){failed++;checkDone();};xhr.send(fd);});})(files[i]);}function checkDone(){var pct=Math.round(((done+failed)/total)*100);prog.querySelector(".progress-bar").style.width=pct+"%";prog.querySelector(".progress-bar").textContent=(done+failed)+"/"+total;if(done+failed>=total){setTimeout(function(){prog.innerHTML=\'<p class="text-success"><i class="fas fa-check"></i> \'+done+\' uploaded\'+( failed?\', <span class="text-danger">\'+failed+\' failed</span>\':\'\')+\'</p>\';document.getElementById("fb-upload-input").value="";if(document.getElementById("fb-folder-input"))document.getElementById("fb-folder-input").value="";setTimeout(function(){document.getElementById("fb-upload-progress").innerHTML="";document.getElementById("fb-upload-zone").style.display="none";},2000);fbRefresh();},500);}}}';

    // Auto-load files when switching to file browser tab
    $o .= 'document.querySelectorAll(".nav-tabs a[href=\'#tab-files\']").forEach(function(a){a.addEventListener("click",function(){setTimeout(function(){if(document.getElementById("fb-body").children.length<=1)fbRefresh();},100);});});';
    // Fix 8: Activate correct tab from URL hash on page load
    $o .= '(function(){var h=window.location.hash;';
    // Force Keys tab when new key flash is present (WHMCS strips hash on redirect)
    if ($hasNewKeyFlash) {
        $o .= 'h="#accesskeys";';
    }
    $o .= 'if(h){var map={"#buckets":"#tab-buckets","#accesskeys":"#tab-keys","#quickstart":"#tab-quickstart","#overview":"#tab-overview","#files":"#tab-files","#statistics":"#tab-stats","#replication":"#tab-replication"};var target=map[h]||h;var tabLink=document.querySelector(\'.nav-tabs a[href="\'+target+\'"]\');if(tabLink){var evt=document.createEvent("HTMLEvents");evt.initEvent("click",true,true);tabLink.dispatchEvent(evt);if(typeof jQuery!=="undefined"){jQuery(tabLink).tab("show");}else{document.querySelectorAll(".nav-tabs li").forEach(function(li){li.classList.remove("active");});tabLink.parentElement.classList.add("active");document.querySelectorAll(".tab-pane").forEach(function(p){p.classList.remove("active");});var pane=document.querySelector(target);if(pane)pane.classList.add("active");}}}';
    // Fix 8: Handle tab clicks to update active indicator and URL hash
    $o .= 'document.querySelectorAll(".nav-tabs a[data-toggle=tab]").forEach(function(a){a.addEventListener("click",function(){document.querySelectorAll(".nav-tabs li").forEach(function(li){li.classList.remove("active");});this.parentElement.classList.add("active");var revMap={"#tab-overview":"#overview","#tab-buckets":"#buckets","#tab-keys":"#accesskeys","#tab-quickstart":"#quickstart","#tab-files":"#files","#tab-stats":"#statistics","#tab-replication":"#replication"};var frag=revMap[this.getAttribute("href")]||this.getAttribute("href");history.replaceState(null,null,frag);});});';
    $o .= '})();';

    // Replication JS — global scope (outside IIFE so onclick handlers can access)
    $o .= 'var replServiceId=' . $serviceId . ';';
    $o .= 'function replAjax(action,data,cb){data.modop="custom";data.a=action;data.id=replServiceId;data.token=csrfToken;var x=new XMLHttpRequest();x.open("POST","clientarea.php?action=productdetails&id="+replServiceId,true);x.setRequestHeader("Content-Type","application/x-www-form-urlencoded");x.setRequestHeader("X-Requested-With","XMLHttpRequest");x.onload=function(){try{cb(JSON.parse(x.responseText));}catch(e){cb({success:false,error:"Invalid response"});}};x.onerror=function(){cb({success:false,error:"Network error"});};var q=[];for(var k in data)q.push(encodeURIComponent(k)+"="+encodeURIComponent(data[k]));x.send(q.join("&"));}';

    $o .= 'function replLoadJobs(){var tbl=document.getElementById("repl-jobs-table");var body=document.getElementById("repl-jobs-body");var loading=document.getElementById("repl-jobs-loading");var empty=document.getElementById("repl-jobs-empty");loading.style.display="block";tbl.style.display="none";empty.style.display="none";replAjax("clientListReplicationJobs",{},function(r){loading.style.display="none";if(!r.success||!r.jobs||r.jobs.length===0){empty.style.display="block";return;}tbl.style.display="table";body.innerHTML="";r.jobs.forEach(function(j){var statusMap={"active":["label-success","Active"],"paused":["label-warning","Paused"],"suspended":["label-danger","Suspended"],"error":["label-danger","Error"]};var st=statusMap[j.status]||["label-default",j.status];var desc=j.description||"Untitled";var srcFlag=j.source_region_flag||"";var destFlag=j.dest_region_flag||"";var tr=document.createElement("tr");var html="";html+=\'<td style="vertical-align:middle;"><strong>\'+desc+\'</strong></td>\';html+=\'<td style="vertical-align:middle;"><span style="font-size:12px;">\'+srcFlag+\' \'+j.source_region_name+\'</span><br><code style="font-size:11px;">\'+j.source_bucket+\'</code></td>\';html+=\'<td style="vertical-align:middle;"><span style="font-size:12px;">\'+destFlag+\' \'+j.dest_region_name+\'</span><br><code style="font-size:11px;">\'+j.dest_bucket+\'</code></td>\';html+=\'<td style="vertical-align:middle;"><span class="label \'+st[0]+\'" style="font-size:11px;padding:3px 8px;">\'+st[1]+\'</span><div id="repl-stats-\'+j.id+\'" style="font-size:11px;color:#888;margin-top:4px;"></div></td>\';html+=\'<td style="vertical-align:middle;white-space:nowrap;">\';html+=\'<div class="btn-group btn-group-xs">\';if(j.status==="active"){html+=\'<button class="btn btn-warning" onclick="replPauseJob(\'+j.id+\')" title="Pause"><i class="fas fa-pause"></i></button>\';}if(j.status==="paused"){html+=\'<button class="btn btn-success" onclick="replResumeJob(\'+j.id+\')" title="Resume"><i class="fas fa-play"></i></button>\';}html+=\'<button class="btn btn-default" onclick="replEditJob(\'+j.id+\')" title="Settings"><i class="fas fa-cog"></i></button>\';html+=\'<button class="btn btn-danger" onclick="replDeleteJob(\'+j.id+\')" title="Delete"><i class="fas fa-trash"></i></button>\';html+=\'</div></td>\';tr.innerHTML=html;body.appendChild(tr);if(j.status==="active")replFetchStatus(j.id);});});}';

    // Fetch live replication stats for a job
    $o .= 'function replFormatBytes(b){if(b===0)return"0 B";var k=1024,s=["B","KB","MB","GB","TB"],i=Math.floor(Math.log(b)/Math.log(k));return parseFloat((b/Math.pow(k,i)).toFixed(1))+" "+s[i];}';

    $o .= 'function replFetchStatus(jobId){replAjax("clientReplicationStatus",{job_id:jobId},function(r){var el=document.getElementById("repl-stats-"+jobId);if(!el)return;if(!r.success||!r.stats){el.innerHTML="";return;}var s=r.stats;var parts=[];parts.push("<strong>"+s.replicated_count+"</strong> objects ("+replFormatBytes(s.replicated_bytes)+")");if(s.queued_count>0){parts.push(\'<span style="color:#e67e22;">\'+s.queued_count+" queued</span>");}if(s.failed_count>0){parts.push(\'<span style="color:#e74c3c;">\'+s.failed_count+" failed</span>");}var online=s.target_online?\'<span style="color:#28a745;">&#9679;</span> Online\':\'<span style="color:#dc3545;">&#9679;</span> Offline\';if(s.target_latency_ms>0){online+=" ("+s.target_latency_ms+"ms)";}el.innerHTML=parts.join(" &middot; ")+"<br>"+online;});}';

    $o .= 'function replShowCreate(){document.getElementById("repl-create-form").style.display="block";document.getElementById("repl-create-btn").style.display="none";}';
    $o .= 'function replHideCreate(){document.getElementById("repl-create-form").style.display="none";document.getElementById("repl-create-btn").style.display="inline-block";document.getElementById("repl-create-progress").innerHTML="";}';

    $o .= 'function replCreateJob(){var prog=document.getElementById("repl-create-progress");prog.innerHTML=\'<div class="progress"><div class="progress-bar progress-bar-striped active" style="width:100%;">Creating...</div></div>\';replAjax("clientCreateReplicationJob",{source_bucket:document.getElementById("repl-src-bucket").value,dest_region_id:document.getElementById("repl-dest-region").value,description:document.getElementById("repl-description").value,sync_existing:document.getElementById("repl-sync-existing").checked?1:0,sync_deletes:document.getElementById("repl-sync-deletes").checked?1:0,sync_locking:document.getElementById("repl-sync-locking").checked?1:0,sync_tags:document.getElementById("repl-sync-tags").checked?1:0},function(r){if(r.success){prog.innerHTML=\'<div class="alert alert-success"><i class="fas fa-check"></i> Replication job created successfully.</div>\';setTimeout(function(){replHideCreate();replLoadJobs();},1500);}else{prog.innerHTML=\'<div class="alert alert-danger"><i class="fas fa-times"></i> \'+r.error+\'</div>\';}});}';

    $o .= 'function replPauseJob(id){if(!confirm("Pause this replication job? New writes will not be replicated until resumed."))return;replAjax("clientPauseReplicationJob",{job_id:id},function(r){if(r.success){replLoadJobs();}else{alert("Failed: "+(r.error||"Unknown error"));}});}';
    $o .= 'function replResumeJob(id){replAjax("clientResumeReplicationJob",{job_id:id},function(r){if(r.success){replLoadJobs();}else{alert("Failed: "+(r.error||"Unknown error"));}});}';
    $o .= 'function replDeleteJob(id){if(!confirm("Delete this replication job?\\n\\nThis will stop replication. Existing data on the destination will be preserved."))return;replAjax("clientDeleteReplicationJob",{job_id:id},function(r){if(r.success){replLoadJobs();}else{alert("Failed: "+(r.error||"Unknown error"));}});}';

    // Edit job — shows a modal-style panel with current settings
    $o .= 'function replEditJob(id){replAjax("clientGetReplicationJob",{job_id:id},function(r){if(!r.success){alert("Error: "+(r.error||"Unknown"));return;}var j=r.job;var editHtml=\'<div id="repl-edit-panel" style="background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:16px;margin-top:15px;">\';editHtml+=\'<h4 style="margin:0 0 12px;">Edit Replication Job</h4>\';editHtml+=\'<input type="hidden" id="repl-edit-id" value="\'+j.id+\'">\';editHtml+=\'<div class="form-group"><label>Description</label><input type="text" id="repl-edit-desc" class="form-control" style="max-width:400px;" value="\'+( j.description||"")+\'"></div>\';editHtml+=\'<div style="padding:10px;background:#f0f0f0;border-radius:4px;margin-bottom:12px;">\';editHtml+=\'<div class="checkbox"><label><input type="checkbox" id="repl-edit-sync-existing" \'+(j.sync_existing==1?"checked":"")+\'> Replicate existing objects</label></div>\';editHtml+=\'<div class="checkbox"><label><input type="checkbox" id="repl-edit-sync-deletes" \'+(j.sync_deletes==1?"checked":"")+\'> Sync deleted objects</label></div>\';editHtml+=\'<div class="checkbox"><label><input type="checkbox" id="repl-edit-sync-locking" \'+(j.sync_locking==1?"checked":"")+\'> Sync object locking</label></div>\';editHtml+=\'<div class="checkbox"><label><input type="checkbox" id="repl-edit-sync-tags" \'+(j.sync_tags==1?"checked":"")+\'> Sync object tags</label></div>\';editHtml+=\'</div>\';editHtml+=\'<div id="repl-edit-progress" style="margin-bottom:10px;"></div>\';editHtml+=\'<button class="btn btn-primary btn-sm" onclick="replSaveEdit()"><i class="fas fa-save"></i> Save Changes</button> \';editHtml+=\'<button class="btn btn-default btn-sm" onclick="replCloseEdit()">Cancel</button>\';editHtml+=\'</div>\';var existing=document.getElementById("repl-edit-panel");if(existing)existing.remove();document.getElementById("repl-jobs-table").insertAdjacentHTML("afterend",editHtml);});}';

    $o .= 'function replCloseEdit(){var p=document.getElementById("repl-edit-panel");if(p)p.remove();}';

    $o .= 'function replSaveEdit(){var id=document.getElementById("repl-edit-id").value;var prog=document.getElementById("repl-edit-progress");prog.innerHTML=\'<div class="progress"><div class="progress-bar progress-bar-striped active" style="width:100%;">Saving...</div></div>\';replAjax("clientUpdateReplicationJob",{job_id:id,description:document.getElementById("repl-edit-desc").value,sync_existing:document.getElementById("repl-edit-sync-existing").checked?1:0,sync_deletes:document.getElementById("repl-edit-sync-deletes").checked?1:0,sync_locking:document.getElementById("repl-edit-sync-locking").checked?1:0,sync_tags:document.getElementById("repl-edit-sync-tags").checked?1:0},function(r){if(r.success){prog.innerHTML=\'<div class="alert alert-success"><i class="fas fa-check"></i> Settings saved.</div>\';setTimeout(function(){replCloseEdit();replLoadJobs();},1000);}else{prog.innerHTML=\'<div class="alert alert-danger"><i class="fas fa-times"></i> \'+r.error+\'</div>\';}});}';

    // Auto-load replication jobs when switching to tab
    $o .= 'document.querySelectorAll(".nav-tabs a[href=\'#tab-replication\']").forEach(function(a){a.addEventListener("click",function(){setTimeout(replLoadJobs,100);});});';

    // Custom Domain JS
    $o .= 'function cdAddDomain(bucket){var input=document.getElementById("cd-input-"+bucket);var domain=input?input.value.trim():"";if(!domain){alert("Enter a domain.");return;}var el=document.getElementById("cd-status-"+bucket);el.innerHTML=\'<span style="color:#888;"><i class="fas fa-spinner fa-spin"></i> Adding...</span>\';fbAjax("clientAddCustomDomain",{bucket_name:bucket,domain:domain},function(r){if(r.success){el.innerHTML=\'<span style="color:#28a745;"><i class="fas fa-check"></i> Domain added. Reload to see status.</span>\';setTimeout(function(){location.reload();},1500);}else{el.innerHTML=\'<span style="color:#dc3545;"><i class="fas fa-times"></i> \'+r.error+\'</span>\';}});}';
    $o .= 'function cdRemoveDomain(bucket){if(!confirm("Remove custom domain from this bucket?"))return;var el=document.getElementById("cd-status-"+bucket);el.innerHTML=\'<span style="color:#888;"><i class="fas fa-spinner fa-spin"></i> Removing...</span>\';fbAjax("clientRemoveCustomDomain",{bucket_name:bucket},function(r){if(r.success){el.innerHTML=\'<span style="color:#28a745;"><i class="fas fa-check"></i> Removed.</span>\';setTimeout(function(){location.reload();},1500);}else{el.innerHTML=\'<span style="color:#dc3545;"><i class="fas fa-times"></i> \'+r.error+\'</span>\';}});}';
    $o .= 'function cdPollStatus(bucket){var el=document.getElementById("cd-status-"+bucket);el.innerHTML=\'<span style="color:#888;"><i class="fas fa-spinner fa-spin"></i> Checking...</span>\';fbAjax("clientPollCustomDomain",{bucket_name:bucket},function(r){if(r.success){if(r.status==="active"){el.innerHTML=\'<span style="color:#28a745;"><i class="fas fa-check"></i> Active! Reloading...</span>\';setTimeout(function(){location.reload();},1500);}else{el.innerHTML=\'<span style="color:#e67e22;">Status: \'+r.status+\'</span>\';}}else{el.innerHTML=\'<span style="color:#dc3545;">\'+r.error+\'</span>\';}});}';

    // Migration wizard JS
    $o .= 'var migJobId=0;var migCredentials={};';
    $o .= 'function migStep(n){document.getElementById("migStep1").style.display=n===1?"block":"none";document.getElementById("migStep2").style.display=n===2?"block":"none";document.getElementById("migStep3").style.display=n===3?"block":"none";document.getElementById("migStep4").style.display=n===4?"block":"none";}';
    $o .= 'function migProviderChanged(){var sel=document.getElementById("migProvider");var opt=sel.options[sel.selectedIndex];var regions=JSON.parse(opt.getAttribute("data-regions")||"{}");var reqRegion=opt.getAttribute("data-region-required")==="1";var customEp=opt.getAttribute("data-custom-endpoint")==="1";document.getElementById("migRegionGroup").style.display=reqRegion?"block":"none";document.getElementById("migCustomEndpointGroup").style.display=customEp?"block":"none";var rSel=document.getElementById("migRegion");rSel.innerHTML="";for(var k in regions){var o=document.createElement("option");o.value=k;o.textContent=regions[k]+" ("+k+")";rSel.appendChild(o);}}';
    $o .= 'migProviderChanged();'; // init on load
    $o .= 'function migValidateSource(){var provider=document.getElementById("migProvider").value;var region=document.getElementById("migRegion").value;var customEp=document.getElementById("migCustomEndpoint").value;var ak=document.getElementById("migAccessKey").value;var sk=document.getElementById("migSecretKey").value;if(!ak||!sk){alert("Enter your access key and secret key.");return;}var el=document.getElementById("migStep1Status");el.innerHTML=\'<span style="color:#888;"><i class="fas fa-spinner fa-spin"></i> Connecting...</span>\';migCredentials={provider:provider,region:region,custom_endpoint:customEp,access_key:ak,secret_key:sk};fbAjax("clientValidateMigrationSource",{provider:provider,region:region,custom_endpoint:customEp,access_key:ak,secret_key:sk},function(r){if(r.success){el.innerHTML=\'<span style="color:#28a745;"><i class="fas fa-check"></i> Connected. \'+r.buckets.length+\' bucket(s) found.</span>\';var bSel=document.getElementById("migSourceBucket");bSel.innerHTML="";r.buckets.forEach(function(b){var o=document.createElement("option");o.value=b;o.textContent=b;bSel.appendChild(o);});setTimeout(function(){migStep(2);},500);}else{el.innerHTML=\'<span style="color:#dc3545;"><i class="fas fa-times"></i> \'+r.error+\'</span>\';}});}';
    $o .= 'function migScanBucket(){var bucket=document.getElementById("migSourceBucket").value;if(!bucket){alert("Select a bucket.");return;}var el=document.getElementById("migStep2Status");el.innerHTML=\'<span style="color:#888;"><i class="fas fa-spinner fa-spin"></i> Scanning bucket size...</span>\';var p=Object.assign({},migCredentials,{source_bucket:bucket});fbAjax("clientScanMigrationBucket",p,function(r){if(r.success){el.innerHTML="";document.getElementById("migScanResult").innerHTML="<strong>"+bucket+"</strong>: "+r.total_objects+" objects, "+r.total_bytes_display;migCredentials.source_bucket=bucket;migCredentials.total_objects=r.total_objects;migCredentials.total_bytes=r.total_bytes;migStep(3);}else{el.innerHTML=\'<span style="color:#dc3545;">\'+r.error+\'</span>\';}});}';
    $o .= 'function migStart(){var dest=document.getElementById("migDestBucket").value;if(!dest){alert("Select a destination bucket.");return;}var el=document.getElementById("migStep3Status");el.innerHTML=\'<span style="color:#888;"><i class="fas fa-spinner fa-spin"></i> Starting migration...</span>\';var p=Object.assign({},migCredentials,{dest_bucket:dest});fbAjax("clientStartMigration",p,function(r){if(r.success){migJobId=r.job_id;migStep(4);migPollProgress();}else{el.innerHTML=\'<span style="color:#dc3545;">\'+r.error+\'</span>\';}});}';
    $o .= 'function migPollProgress(){if(!migJobId)return;fbAjax("clientMigrationProgress",{job_id:migJobId},function(r){if(r.success){var bar=document.getElementById("migWizardBar");var det=document.getElementById("migWizardDetail");if(bar){bar.style.width=r.percent+"%";bar.textContent=r.percent+"%";}if(det){det.textContent=r.migrated_objects+"/"+r.total_objects+" objects \u00b7 "+migFormatBytes(r.migrated_bytes)+" of "+migFormatBytes(r.total_bytes);}if(r.status==="running"){setTimeout(migPollProgress,5000);}else if(r.status==="complete"){bar.className="progress-bar progress-bar-success";bar.style.width="100%";bar.textContent="100%";det.textContent="Migration complete! "+r.migrated_objects+" objects transferred.";setTimeout(function(){location.reload();},3000);}else{bar.className="progress-bar progress-bar-danger";det.textContent="Status: "+r.status+(r.error_message?" - "+r.error_message:"");}}});}';
    $o .= 'function migCancel(id){if(!confirm("Cancel this migration? Data already copied will remain."))return;fbAjax("clientCancelMigration",{job_id:id},function(r){if(r.success)location.reload();else alert(r.error||"Failed to cancel.");});}';
    $o .= 'function migDismiss(id){fbAjax("clientDismissMigration",{job_id:id},function(r){if(r.success)location.reload();});}';
    $o .= 'function migFormatBytes(b){if(b>=1099511627776)return(b/1099511627776).toFixed(2)+" TB";if(b>=1073741824)return(b/1073741824).toFixed(2)+" GB";if(b>=1048576)return(b/1048576).toFixed(2)+" MB";if(b>=1024)return(b/1024).toFixed(2)+" KB";return b+" B";}';
    // Auto-poll active migrations on page load
    $o .= 'document.addEventListener("DOMContentLoaded",function(){';
    if (impulseminio_hasMigration()) {
        require_once __DIR__ . '/lib/Migration.php';
        $activeMigs = \WHMCS\Module\Server\ImpulseMinio\Migration::getServiceMigrations($serviceId);
        foreach ($activeMigs as $am) {
            if (in_array($am->status, ['running', 'scanning'])) {
                $o .= '(function(id){setInterval(function(){fbAjax("clientMigrationProgress",{job_id:id},function(r){if(!r.success)return;var bar=document.getElementById("mig-bar-"+id);var det=document.getElementById("mig-detail-"+id);if(bar){bar.style.width=r.percent+"%";bar.textContent=r.percent+"%";}if(det){det.textContent=r.migrated_objects+"/"+r.total_objects+" objects \u00b7 "+migFormatBytes(r.migrated_bytes)+" of "+migFormatBytes(r.total_bytes);}if(r.status!=="running"&&r.status!=="scanning"){location.reload();}});},8000);})(' . (int)$am->id . ');';
            }
        }
    }
    $o .= '});';

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
    $o .= 'document.querySelectorAll("a").forEach(function(a){var t=a.textContent.trim();if(t=="Create Bucket"||t=="Delete Bucket"||t=="Create Access Key"||t=="Delete Access Key"||t=="Toggle Versioning"||t=="Reset Password"||t=="List Objects"||t=="Download Object"||t=="Delete Object"||t=="Create Folder"||t=="Get Upload URL"||t=="List Replication Jobs"||t=="Create Replication Job"||t=="Pause Replication Job"||t=="Resume Replication Job"||t=="Delete Replication Job"||t=="Get Replication Job"||t=="Update Replication Job"||t=="Replication Status"||t=="Add Custom Domain"||t=="Remove Custom Domain"||t=="Poll Custom Domain"||t=="Validate Migration Source"||t=="Scan Migration Bucket"||t=="Start Migration"||t=="Migration Progress"||t=="Cancel Migration"||t=="Dismiss Migration"){a.style.display="none";}});});</script>';

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
        'Usage History' => 'clientGetUsageHistory',
        'Toggle Public' => 'clientTogglePublic',
        'Update CORS' => 'clientUpdateCors',
        'List Replication Jobs' => 'clientListReplicationJobs',
        'Create Replication Job' => 'clientCreateReplicationJob',
        'Pause Replication Job' => 'clientPauseReplicationJob',
        'Resume Replication Job' => 'clientResumeReplicationJob',
        'Delete Replication Job' => 'clientDeleteReplicationJob',
        'Get Replication Job' => 'clientGetReplicationJob',
        'Update Replication Job' => 'clientUpdateReplicationJob',
        'Replication Status' => 'clientReplicationStatus',
        'Add Custom Domain' => 'clientAddCustomDomain',
        'Remove Custom Domain' => 'clientRemoveCustomDomain',
        'Poll Custom Domain' => 'clientPollCustomDomain',
        'Validate Migration Source' => 'clientValidateMigrationSource',
        'Scan Migration Bucket' => 'clientScanMigrationBucket',
        'Start Migration' => 'clientStartMigration',
        'Migration Progress' => 'clientMigrationProgress',
        'Cancel Migration' => 'clientCancelMigration',
        'Dismiss Migration' => 'clientDismissMigration',
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

        $client = impulseminio_getServiceClient($params);
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

        $client = impulseminio_getServiceClient($params);
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
        $client = impulseminio_getServiceClient($params);

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

        $client = impulseminio_getServiceClient($params);
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

        $client = impulseminio_getServiceClient($params);
        $currentlyEnabled = !empty($bucket->versioning);

        if ($currentlyEnabled) {
            // Check replication lock — cannot disable versioning while replication is active
            if (file_exists(__DIR__ . '/lib/Replication.php')) {
                require_once __DIR__ . '/lib/Replication.php';
                if (\WHMCS\Module\Server\ImpulseMinio\Replication::bucketHasReplication($serviceId, $bucketName)) {
                    return impulseminio_jsonResponse(['success' => false, 'error' => 'Versioning cannot be disabled while replication is active on this bucket. Remove the replication job first.']);
                }
            }
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
        $client = impulseminio_getServiceClient($params);
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

        impulseminio_ensureTables();
        if (!impulseminio_validateBucketAccess($serviceId, $bucketName)) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Bucket not found.']);
        }

        $client = impulseminio_getRequestClient($params);
        $r = $client->listObjects($bucketName, $prefix);

        // Check versioning from primary buckets table
        $bucket = Capsule::table('mod_impulseminio_buckets')
            ->where('service_id', $serviceId)->where('bucket_name', $bucketName)->first();

        return impulseminio_jsonResponse([
            'success'    => $r['success'],
            'objects'    => $r['objects'] ?? [],
            'versioning' => $bucket ? !empty($bucket->versioning) : false,
            'error'      => $r['error'] ?? null,
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
        if (!impulseminio_validateBucketAccess($serviceId, $bucketName)) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Bucket not found.']);
        }

        $client = impulseminio_getRequestClient($params);
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

        $client = impulseminio_getServiceClient($params);
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

        $client = impulseminio_getServiceClient($params);
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

        $client = impulseminio_getServiceClient($params);
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

/**
 * AJAX: Return usage history data for the Statistics chart.
 *
 * @param  array $params WHMCS module parameters
 * @return string JSON response
 */
function impulseminio_clientGetUsageHistory(array $params): string
{
    try {
        $serviceId = (int)$params['serviceid'];
        $metric = isset($_REQUEST['metric']) ? trim($_REQUEST['metric']) : 'storage';
        $range = isset($_REQUEST['range']) ? trim($_REQUEST['range']) : '7d';

        $validMetrics = ['storage', 'downloads', 'uploads', 'objects', 'replication_in', 'replication_out'];
        if (!in_array($metric, $validMetrics)) $metric = 'storage';

        $rangeMap = ['24h' => 24, '7d' => 168, '30d' => 720, '90d' => 2160];
        $hours = $rangeMap[$range] ?? 168;

        impulseminio_ensureTables();

        $since = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));

        $columnMap = [
            'storage' => 'storage_bytes',
            'downloads' => 'bandwidth_sent_bytes',
            'uploads' => 'bandwidth_received_bytes',
            'objects' => 'object_count',
            'replication_in' => 'replication_received_bytes',
            'replication_out' => 'replication_sent_bytes',
        ];
        $column = $columnMap[$metric];

        $rows = Capsule::table('mod_impulseminio_usage_history')
            ->where('service_id', $serviceId)
            ->where('recorded_at', '>=', $since)
            ->orderBy('recorded_at')
            ->select('recorded_at', $column . ' as value')
            ->get();

        $labels = [];
        $values = [];
        $total = 0;
        foreach ($rows as $row) {
            $labels[] = $row->recorded_at;
            $val = (float)$row->value;
            $values[] = $val;
        }

        // Total is the latest value for cumulative metrics
        if (!empty($values)) {
            $total = end($values);
        }

        $isBytes = in_array($metric, ['storage', 'downloads', 'uploads', 'replication_in', 'replication_out']);

        return impulseminio_jsonResponse([
            'success' => true,
            'labels' => $labels,
            'values' => $values,
            'total' => $total,
            'metric' => $metric,
            'isBytes' => $isBytes,
            'range' => $range,
        ]);
    } catch (\Exception $e) {
        logModuleCall('impulseminio', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return impulseminio_jsonResponse(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
    }
}
/**
 * Client action: toggle public access on a bucket.
 */
function impulseminio_clientTogglePublic(array $params): string
{
    try {
        if (!impulseminio_hasPublicAccess()) {
            header('Location: clientarea.php?action=productdetails&id=' . $params['serviceid'] . '#buckets');
            exit;
        }
        $serviceId = (int)$params['serviceid'];
        $bucketName = isset($_POST['bucket_name']) ? trim($_POST['bucket_name']) : '';
        if (empty($bucketName)) {
            header('Location: clientarea.php?action=productdetails&id=' . $serviceId . '#buckets');
            exit;
        }
        require_once __DIR__ . '/lib/PublicAccess.php';
        $client = impulseminio_getServiceClient($params);
        if (\WHMCS\Module\Server\ImpulseMinio\PublicAccess::isPublic($serviceId, $bucketName)) {
            \WHMCS\Module\Server\ImpulseMinio\PublicAccess::disablePublic($client, $serviceId, $bucketName);
        } else {
            \WHMCS\Module\Server\ImpulseMinio\PublicAccess::enablePublic($client, $serviceId, $bucketName);
        }
        header('Location: clientarea.php?action=productdetails&id=' . $serviceId . '#buckets');
        exit;
    } catch (\Exception $e) {
        logModuleCall('impulseminio', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        header('Location: clientarea.php?action=productdetails&id=' . $params['serviceid'] . '#buckets');
        exit;
    }
}

/**
 * Client action: update CORS origins for a public bucket.
 */
function impulseminio_clientUpdateCors(array $params): string
{
    try {
        if (!impulseminio_hasCors()) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'CORS configuration is not available.']);
        }
        $serviceId = (int)$params['serviceid'];
        $bucketName = isset($_POST['bucket_name']) ? trim($_POST['bucket_name']) : '';
        $originsRaw = isset($_POST['cors_origins']) ? trim($_POST['cors_origins']) : '*';
        if (empty($bucketName)) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'No bucket specified.']);
        }
        require_once __DIR__ . '/lib/PublicAccess.php';
        $origins = array_filter(array_map('trim', explode("\n", $originsRaw)));
        if (empty($origins)) $origins = ['*'];
        $r = \WHMCS\Module\Server\ImpulseMinio\PublicAccess::setCorsOrigins($serviceId, $bucketName, $origins);
        return impulseminio_jsonResponse($r);
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
        $client = impulseminio_getServiceClient($params);
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
        $client = impulseminio_getServiceClient($params);
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
// REPLICATION AJAX HANDLERS
// =============================================================================

/**
 * Client AJAX: list replication jobs for this service.
 */
function impulseminio_clientListReplicationJobs(array $params): string
{
    try {
        impulseminio_ensureTables();
        if (!impulseminio_hasReplication()) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Replication is not available on your plan.']);
        }
        $jobs = \WHMCS\Module\Server\ImpulseMinio\Replication::getJobsForService((int)$params['serviceid']);
        return impulseminio_jsonResponse(['success' => true, 'jobs' => $jobs]);
    } catch (\Exception $e) {
        return impulseminio_jsonResponse(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Client AJAX: create a new replication job.
 */
function impulseminio_clientCreateReplicationJob(array $params): string
{
    try {
        impulseminio_ensureTables();
        if (!impulseminio_hasReplication()) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Replication is not available on your plan.']);
        }

        $serviceId = (int)$params['serviceid'];
        $srcBucket = isset($_POST['source_bucket']) ? trim($_POST['source_bucket']) : '';
        $destRegionId = isset($_POST['dest_region_id']) ? (int)$_POST['dest_region_id'] : 0;
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';

        if (empty($srcBucket) || $destRegionId === 0) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Source bucket and destination region are required.']);
        }

        // Verify bucket ownership
        $bucket = Capsule::table('mod_impulseminio_buckets')
            ->where('service_id', $serviceId)->where('bucket_name', $srcBucket)->first();
        if (!$bucket) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Bucket not found.']);
        }

        // Get source region
        $srcRegionLink = Capsule::table('mod_impulseminio_service_regions')
            ->where('service_id', $serviceId)->where('is_primary', true)->first();
        if (!$srcRegionLink) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'No primary region found for this service.']);
        }

        $options = [
            'sync_existing' => (int)($_POST['sync_existing'] ?? 1),
            'sync_deletes' => (int)($_POST['sync_deletes'] ?? 1),
            'sync_locking' => (int)($_POST['sync_locking'] ?? 0),
            'sync_metadata_date' => 1,
            'sync_tags' => (int)($_POST['sync_tags'] ?? 1),
        ];

        $result = \WHMCS\Module\Server\ImpulseMinio\Replication::createJob(
            $params,
            $serviceId,
            $srcBucket,
            $srcRegionLink->region_id,
            $destRegionId,
            $description,
            $options
        );

        return impulseminio_jsonResponse($result);
    } catch (\Exception $e) {
        logModuleCall('impulseminio', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return impulseminio_jsonResponse(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Client AJAX: pause a replication job.
 */
function impulseminio_clientPauseReplicationJob(array $params): string
{
    try {
        if (!impulseminio_hasReplication()) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Replication not available.']);
        }
        $jobId = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
        if (!$jobId) return impulseminio_jsonResponse(['success' => false, 'error' => 'No job specified.']);

        // Verify job belongs to this service
        $job = Capsule::table('mod_impulseminio_replication_jobs')
            ->where('id', $jobId)->where('service_id', (int)$params['serviceid'])->first();
        if (!$job) return impulseminio_jsonResponse(['success' => false, 'error' => 'Job not found.']);

        $result = \WHMCS\Module\Server\ImpulseMinio\Replication::pauseJob($params, $jobId);
        return impulseminio_jsonResponse($result);
    } catch (\Exception $e) {
        return impulseminio_jsonResponse(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Client AJAX: resume a paused replication job.
 */
function impulseminio_clientResumeReplicationJob(array $params): string
{
    try {
        if (!impulseminio_hasReplication()) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Replication not available.']);
        }
        $jobId = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
        if (!$jobId) return impulseminio_jsonResponse(['success' => false, 'error' => 'No job specified.']);

        $job = Capsule::table('mod_impulseminio_replication_jobs')
            ->where('id', $jobId)->where('service_id', (int)$params['serviceid'])->first();
        if (!$job) return impulseminio_jsonResponse(['success' => false, 'error' => 'Job not found.']);

        $result = \WHMCS\Module\Server\ImpulseMinio\Replication::resumeJob($params, $jobId);
        return impulseminio_jsonResponse($result);
    } catch (\Exception $e) {
        return impulseminio_jsonResponse(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Client AJAX: delete a replication job.
 */
function impulseminio_clientDeleteReplicationJob(array $params): string
{
    try {
        if (!impulseminio_hasReplication()) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Replication not available.']);
        }
        $jobId = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
        if (!$jobId) return impulseminio_jsonResponse(['success' => false, 'error' => 'No job specified.']);

        $job = Capsule::table('mod_impulseminio_replication_jobs')
            ->where('id', $jobId)->where('service_id', (int)$params['serviceid'])->first();
        if (!$job) return impulseminio_jsonResponse(['success' => false, 'error' => 'Job not found.']);

        $result = \WHMCS\Module\Server\ImpulseMinio\Replication::removeJob($params, $jobId, false);
        return impulseminio_jsonResponse($result);
    } catch (\Exception $e) {
        return impulseminio_jsonResponse(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Client AJAX: get a single replication job for editing.
 */
function impulseminio_clientGetReplicationJob(array $params): string
{
    try {
        if (!impulseminio_hasReplication()) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Replication not available.']);
        }
        $jobId = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
        if (!$jobId) return impulseminio_jsonResponse(['success' => false, 'error' => 'No job specified.']);

        $job = Capsule::table('mod_impulseminio_replication_jobs')
            ->where('id', $jobId)->where('service_id', (int)$params['serviceid'])->first();
        if (!$job) return impulseminio_jsonResponse(['success' => false, 'error' => 'Job not found.']);

        return impulseminio_jsonResponse(['success' => true, 'job' => $job]);
    } catch (\Exception $e) {
        return impulseminio_jsonResponse(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Client AJAX: update a replication job's description and sync options.
 */
function impulseminio_clientUpdateReplicationJob(array $params): string
{
    try {
        if (!impulseminio_hasReplication()) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Replication not available.']);
        }
        $jobId = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
        if (!$jobId) return impulseminio_jsonResponse(['success' => false, 'error' => 'No job specified.']);

        $job = Capsule::table('mod_impulseminio_replication_jobs')
            ->where('id', $jobId)->where('service_id', (int)$params['serviceid'])->first();
        if (!$job) return impulseminio_jsonResponse(['success' => false, 'error' => 'Job not found.']);

        // Update description and sync options in database
        $updates = [
            'description'        => isset($_POST['description']) ? trim($_POST['description']) : $job->description,
            'sync_existing'      => isset($_POST['sync_existing']) ? (int)$_POST['sync_existing'] : $job->sync_existing,
            'sync_deletes'       => isset($_POST['sync_deletes']) ? (int)$_POST['sync_deletes'] : $job->sync_deletes,
            'sync_locking'       => isset($_POST['sync_locking']) ? (int)$_POST['sync_locking'] : $job->sync_locking,
            'sync_tags'          => isset($_POST['sync_tags']) ? (int)$_POST['sync_tags'] : $job->sync_tags,
            'updated_at'         => date('Y-m-d H:i:s'),
        ];

        Capsule::table('mod_impulseminio_replication_jobs')->where('id', $jobId)->update($updates);

        // If the job is active, update the replication rule on MinIO with new flags
        if ($job->status === 'active' && !empty($job->rule_id)) {
            $syncFlags = [];
            if ($updates['sync_deletes']) { $syncFlags[] = 'delete'; $syncFlags[] = 'delete-marker'; }
            if ($updates['sync_existing']) { $syncFlags[] = 'existing-objects'; }
            if ($updates['sync_locking']) { $syncFlags[] = 'replica-metadata-sync'; }
            $flagStr = !empty($syncFlags) ? implode(',', $syncFlags) : 'delete,delete-marker,existing-objects';

            $srcRegion = Capsule::table('mod_impulseminio_regions')->where('id', $job->source_region_id)->first();
            if ($srcRegion) {
                $client = impulseminio_getServiceClient($params);
                // mc replicate update ALIAS/BUCKET --id RULE_ID --replicate FLAGS
                // Note: this updates the replicate flags on the existing rule
            }
        }

        return impulseminio_jsonResponse(['success' => true]);
    } catch (\Exception $e) {
        return impulseminio_jsonResponse(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Client AJAX: get live replication status for a bucket.
 */
function impulseminio_clientReplicationStatus(array $params): string
{
    try {
        if (!impulseminio_hasReplication()) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Replication not available.']);
        }
        $jobId = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
        if (!$jobId) return impulseminio_jsonResponse(['success' => false, 'error' => 'No job specified.']);

        $job = Capsule::table('mod_impulseminio_replication_jobs')
            ->where('id', $jobId)->where('service_id', (int)$params['serviceid'])->first();
        if (!$job) return impulseminio_jsonResponse(['success' => false, 'error' => 'Job not found.']);

        $srcRegion = Capsule::table('mod_impulseminio_regions')->where('id', $job->source_region_id)->first();
        if (!$srcRegion) return impulseminio_jsonResponse(['success' => false, 'error' => 'Source region not found.']);

        $client = impulseminio_getServiceClient($params);
        $r = $client->getReplicationStatus($job->source_bucket);

        if (!$r['success']) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Could not fetch status.']);
        }

        // Parse the JSON output
        $statusData = [];
        $lines = explode("\n", trim($r['output']));
        foreach ($lines as $line) {
            $json = @json_decode($line, true);
            if ($json && isset($json['replicationstats'])) {
                $stats = $json['replicationstats']['currStats'] ?? [];
                $queued = $stats['queued']['curr'] ?? ['count' => 0, 'bytes' => 0];
                $failed = $stats['failed']['totals'] ?? ['count' => 0, 'bytes' => 0];

                $statusData = [
                    'replicated_count' => (int)($stats['replicationCount'] ?? 0),
                    'replicated_bytes' => (int)($stats['completedReplicationSize'] ?? 0),
                    'queued_count' => (int)($queued['count'] ?? 0),
                    'queued_bytes' => (int)($queued['bytes'] ?? 0),
                    'failed_count' => (int)($failed['count'] ?? 0),
                    'failed_bytes' => (int)($failed['bytes'] ?? 0),
                ];

                // Remote target info
                $targets = $json['remoteTargets'] ?? [];
                if (!empty($targets)) {
                    $t = $targets[0];
                    $statusData['target_online'] = (bool)($t['isOnline'] ?? false);
                    $statusData['target_latency_ms'] = round(($t['latency']['curr'] ?? 0) / 1000000, 1);
                    $statusData['target_endpoint'] = $t['endpoint'] ?? '';
                }
                break;
            }
        }

        return impulseminio_jsonResponse(['success' => true, 'stats' => $statusData]);
    } catch (\Exception $e) {
        return impulseminio_jsonResponse(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Client AJAX: add a custom domain to a public bucket.
 */
function impulseminio_clientAddCustomDomain(array $params): string
{
    try {
        if (!impulseminio_hasCustomDomains()) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Custom domains not available. Upgrade to Everything tier.']);
        }
        $serviceId = (int)$params['serviceid'];
        $bucketName = isset($_POST['bucket_name']) ? trim($_POST['bucket_name']) : '';
        $domain = isset($_POST['domain']) ? strtolower(trim($_POST['domain'])) : '';

        if (empty($bucketName) || empty($domain)) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Bucket and domain are required.']);
        }

        require_once __DIR__ . '/lib/PublicAccess.php';

        // Get the region origin for multi-region routing
        $originServer = \WHMCS\Module\Server\ImpulseMinio\PublicAccess::getRegionOrigin($serviceId);

        $result = \WHMCS\Module\Server\ImpulseMinio\PublicAccess::addCustomDomain(
            $serviceId,
            $bucketName,
            $domain,
            $originServer
        );

        return impulseminio_jsonResponse($result);
    } catch (\Exception $e) {
        return impulseminio_jsonResponse(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Client AJAX: remove a custom domain from a bucket.
 */
function impulseminio_clientRemoveCustomDomain(array $params): string
{
    try {
        if (!impulseminio_hasCustomDomains()) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Custom domains not available.']);
        }
        $serviceId = (int)$params['serviceid'];
        $bucketName = isset($_POST['bucket_name']) ? trim($_POST['bucket_name']) : '';

        if (empty($bucketName)) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Bucket name required.']);
        }

        require_once __DIR__ . '/lib/PublicAccess.php';
        $result = \WHMCS\Module\Server\ImpulseMinio\PublicAccess::removeCustomDomain($serviceId, $bucketName);
        return impulseminio_jsonResponse($result);
    } catch (\Exception $e) {
        return impulseminio_jsonResponse(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Client AJAX: poll custom domain status from Cloudflare.
 */
function impulseminio_clientPollCustomDomain(array $params): string
{
    try {
        if (!impulseminio_hasCustomDomains()) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Custom domains not available.']);
        }
        $serviceId = (int)$params['serviceid'];
        $bucketName = isset($_POST['bucket_name']) ? trim($_POST['bucket_name']) : '';

        if (empty($bucketName)) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Bucket name required.']);
        }

        require_once __DIR__ . '/lib/PublicAccess.php';
        $result = \WHMCS\Module\Server\ImpulseMinio\PublicAccess::pollCustomDomainStatus($serviceId, $bucketName);
        return impulseminio_jsonResponse($result);
    } catch (\Exception $e) {
        return impulseminio_jsonResponse(['success' => false, 'error' => $e->getMessage()]);
    }
}

// =============================================================================
// MIGRATION AJAX HANDLERS
// =============================================================================
/**
 * Client AJAX: validate migration source credentials and list buckets.
 */
function impulseminio_clientValidateMigrationSource(array $params): string
{
    try {
        if (!impulseminio_hasMigration()) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Migration feature not available.']);
        }
        $serviceId = (int)$params['serviceid'];
        $provider = $_POST['provider'] ?? '';
        $region = $_POST['region'] ?? '';
        $customEndpoint = $_POST['custom_endpoint'] ?? '';
        $accessKey = $_POST['access_key'] ?? '';
        $secretKey = $_POST['secret_key'] ?? '';
        if (empty($accessKey) || empty($secretKey)) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Access key and secret key are required.']);
        }
        require_once __DIR__ . '/lib/Migration.php';
        $endpoint = \WHMCS\Module\Server\ImpulseMinio\Migration::resolveEndpoint($provider, $region, $customEndpoint);
        if (empty($endpoint)) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Could not resolve endpoint for this provider.']);
        }
        $client = impulseminio_getServiceClient($params);
        $result = \WHMCS\Module\Server\ImpulseMinio\Migration::validateSource($client, $endpoint, $accessKey, $secretKey);
        return impulseminio_jsonResponse($result);
    } catch (\Exception $e) {
        logModuleCall('impulseminio', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return impulseminio_jsonResponse(['success' => false, 'error' => $e->getMessage()]);
    }
}
/**
 * Client AJAX: scan a source bucket for size and object count.
 */
function impulseminio_clientScanMigrationBucket(array $params): string
{
    try {
        if (!impulseminio_hasMigration()) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Migration feature not available.']);
        }
        $provider = $_POST['provider'] ?? '';
        $region = $_POST['region'] ?? '';
        $customEndpoint = $_POST['custom_endpoint'] ?? '';
        $accessKey = $_POST['access_key'] ?? '';
        $secretKey = $_POST['secret_key'] ?? '';
        $sourceBucket = $_POST['source_bucket'] ?? '';
        if (empty($sourceBucket)) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Source bucket is required.']);
        }
        require_once __DIR__ . '/lib/Migration.php';
        $endpoint = \WHMCS\Module\Server\ImpulseMinio\Migration::resolveEndpoint($provider, $region, $customEndpoint);
        $result = \WHMCS\Module\Server\ImpulseMinio\Migration::scanSourceBucket($endpoint, $accessKey, $secretKey, $sourceBucket);
        if ($result['success']) {
            $result['total_bytes_display'] = \WHMCS\Module\Server\ImpulseMinio\Migration::formatBytes((int)($result['total_bytes'] ?? 0));
        }
        return impulseminio_jsonResponse($result);
    } catch (\Exception $e) {
        logModuleCall('impulseminio', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return impulseminio_jsonResponse(['success' => false, 'error' => $e->getMessage()]);
    }
}
/**
 * Client AJAX: start a migration job.
 */
function impulseminio_clientStartMigration(array $params): string
{
    try {
        if (!impulseminio_hasMigration()) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Migration feature not available.']);
        }
        $serviceId = (int)$params['serviceid'];
        $provider = $_POST['provider'] ?? '';
        $region = $_POST['region'] ?? '';
        $customEndpoint = $_POST['custom_endpoint'] ?? '';
        $accessKey = $_POST['access_key'] ?? '';
        $secretKey = $_POST['secret_key'] ?? '';
        $sourceBucket = $_POST['source_bucket'] ?? '';
        $destBucket = $_POST['dest_bucket'] ?? '';
        $totalObjects = (int)($_POST['total_objects'] ?? 0);
        $totalBytes = (int)($_POST['total_bytes'] ?? 0);
        if (empty($sourceBucket) || empty($destBucket)) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Source and destination buckets are required.']);
        }
        if (empty($accessKey) || empty($secretKey)) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Source credentials are required.']);
        }
        require_once __DIR__ . '/lib/Migration.php';
        $endpoint = \WHMCS\Module\Server\ImpulseMinio\Migration::resolveEndpoint($provider, $region, $customEndpoint);
        $result = \WHMCS\Module\Server\ImpulseMinio\Migration::startMigration(
            $serviceId,
            $provider,
            $endpoint,
            $region,
            $accessKey,
            $secretKey,
            $sourceBucket,
            $destBucket,
            $totalObjects,
            $totalBytes
        );
        return impulseminio_jsonResponse($result);
    } catch (\Exception $e) {
        logModuleCall('impulseminio', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return impulseminio_jsonResponse(['success' => false, 'error' => $e->getMessage()]);
    }
}
/**
 * Client AJAX: get migration progress.
 */
function impulseminio_clientMigrationProgress(array $params): string
{
    try {
        if (!impulseminio_hasMigration()) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Migration feature not available.']);
        }
        $jobId = (int)($_POST['job_id'] ?? 0);
        if (!$jobId) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Job ID required.']);
        }
        require_once __DIR__ . '/lib/Migration.php';
        $result = \WHMCS\Module\Server\ImpulseMinio\Migration::getProgress($jobId);
        return impulseminio_jsonResponse($result);
    } catch (\Exception $e) {
        return impulseminio_jsonResponse(['success' => false, 'error' => $e->getMessage()]);
    }
}
/**
 * Client AJAX: cancel a running migration.
 */
function impulseminio_clientCancelMigration(array $params): string
{
    try {
        if (!impulseminio_hasMigration()) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Migration feature not available.']);
        }
        $serviceId = (int)$params['serviceid'];
        $jobId = (int)($_POST['job_id'] ?? 0);
        if (!$jobId) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Job ID required.']);
        }
        require_once __DIR__ . '/lib/Migration.php';
        $result = \WHMCS\Module\Server\ImpulseMinio\Migration::cancelMigration($jobId, $serviceId);
        return impulseminio_jsonResponse($result);
    } catch (\Exception $e) {
        return impulseminio_jsonResponse(['success' => false, 'error' => $e->getMessage()]);
    }
}
/**
 * Client AJAX: dismiss a completed migration card.
 */
function impulseminio_clientDismissMigration(array $params): string
{
    try {
        $serviceId = (int)$params['serviceid'];
        $jobId = (int)($_POST['job_id'] ?? 0);
        if (!$jobId) {
            return impulseminio_jsonResponse(['success' => false, 'error' => 'Job ID required.']);
        }
        require_once __DIR__ . '/lib/Migration.php';
        $result = \WHMCS\Module\Server\ImpulseMinio\Migration::dismissMigration($jobId, $serviceId);
        return impulseminio_jsonResponse($result);
    } catch (\Exception $e) {
        return impulseminio_jsonResponse(['success' => false, 'error' => $e->getMessage()]);
    }
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
        $client = impulseminio_getServiceClient($params);
        $r = $client->testConnection();
        return ['success' => $r['success'], 'error' => $r['success'] ? '' : $r['message']];
    } catch (\Exception $e) { return ['success' => false, 'error' => $e->getMessage()]; }
}
