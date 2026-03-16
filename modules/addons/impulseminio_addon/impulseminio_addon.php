<?php
/**
 * ImpulseMinio Settings — WHMCS Addon Module
 *
 * Centralized admin management for the ImpulseMinio S3 storage module.
 * Tabs: Overview, License, Regions, Cloudflare, Bandwidth, Replication Jobs, Assignments, Limits & Policy.
 *
 * @package    ImpulseMinio
 * @version    1.2.0
 * @author     Impulse Hosting
 * @license    GPL-3.0
 */
if (!defined('WHMCS')) die('Access denied.');

use WHMCS\Database\Capsule;

function impulseminio_addon_config(): array
{
    return [
        'name'        => 'ImpulseMinio Settings',
        'description' => 'Global settings, region management, and monitoring for ImpulseMinio S3 storage.',
        'version'     => '1.2.0',
        'author'      => 'Impulse Hosting',
        'language'    => 'english',
        'fields'      => [],
    ];
}

function impulseminio_addon_activate(): array
{
    try {
        if (!Capsule::schema()->hasTable('mod_impulseminio_settings')) {
            Capsule::schema()->create('mod_impulseminio_settings', function ($table) {
                $table->string('setting_key', 100)->primary();
                $table->text('setting_value')->nullable();
                $table->timestamps();
            });
        }
        if (!Capsule::schema()->hasTable('mod_impulseminio_regions')) {
            Capsule::schema()->create('mod_impulseminio_regions', function ($t) {
                $t->increments('id');
                $t->string('name', 100);
                $t->string('slug', 50)->unique();
                $t->unsignedInteger('server_id');
                $t->string('cdn_endpoint', 255)->nullable();
                $t->string('stats_url', 255)->nullable();
                $t->boolean('is_active')->default(true);
                $t->unsignedInteger('sort_order')->default(0);
                $t->timestamps();
            });
        }
        // Remove flag column if exists (deprecated)
        if (Capsule::schema()->hasColumn('mod_impulseminio_regions', 'flag')) {
            try {
                Capsule::schema()->table('mod_impulseminio_regions', function ($t) {
                    $t->dropColumn('flag');
                });
            } catch (\Exception $e) {
                // Non-critical
            }
        }
        $defaults = [
            'premium_license_key' => '',
            'cf_zone_id' => '', 'cf_api_token' => '', 'cf_fallback_origin' => '',
            'cf_account_id' => '',
            'domain_map_secret' => '',
            'custom_domain_limit' => '3', 'replication_job_limit' => '5',
            'suspension_grace_days' => '7', 'suspension_warning_day' => '5',
            'bw_stats_secret' => '', 'bw_stats_path_prefix' => '/___impulse_bw_stats_',
        ];
        foreach ($defaults as $key => $value) {
            if (!Capsule::table('mod_impulseminio_settings')->where('setting_key', $key)->exists()) {
                Capsule::table('mod_impulseminio_settings')->insert([
                    'setting_key' => $key, 'setting_value' => $value,
                    'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
        return ['status' => 'success', 'description' => 'ImpulseMinio Settings activated.'];
    } catch (\Exception $e) {
        return ['status' => 'error', 'description' => 'Failed: ' . $e->getMessage()];
    }
}

function impulseminio_addon_deactivate(): array
{
    return ['status' => 'success', 'description' => 'Deactivated. Data preserved.'];
}

function impulseminio_getSetting(string $key, ?string $default = null): ?string
{
    try {
        $row = Capsule::table('mod_impulseminio_settings')->where('setting_key', $key)->first();
        if (!$row) return $default;
        if (in_array($key, ['cf_api_token', 'domain_map_secret']) && !empty($row->setting_value)) return decrypt($row->setting_value);
        return $row->setting_value;
    } catch (\Exception $e) { return $default; }
}

function impulseminio_setSetting(string $key, string $value): void
{
    $store = (in_array($key, ['cf_api_token', 'domain_map_secret']) && !empty($value)) ? encrypt($value) : $value;
    Capsule::table('mod_impulseminio_settings')->updateOrInsert(
        ['setting_key' => $key],
        ['setting_value' => $store, 'updated_at' => date('Y-m-d H:i:s')]
    );
}

function impulseminio_addon_output(array $vars): void
{
    $esc = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $tab = $_GET['tab'] ?? 'overview';
    $message = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        if ($action === 'save_settings') {
            $fields = ['premium_license_key','cf_zone_id','cf_fallback_origin','cf_account_id','custom_domain_limit','replication_job_limit',
                        'suspension_grace_days','suspension_warning_day','bw_stats_secret','bw_stats_path_prefix'];
            foreach ($fields as $key) { if (isset($_POST[$key])) impulseminio_setSetting($key, trim($_POST[$key])); }
            if (isset($_POST['cf_api_token']) && $_POST['cf_api_token'] !== '********')
                impulseminio_setSetting('cf_api_token', trim($_POST['cf_api_token']));
            if (isset($_POST['domain_map_secret']) && $_POST['domain_map_secret'] !== '********')
                impulseminio_setSetting('domain_map_secret', trim($_POST['domain_map_secret']));
            $message = '<div class="successbox"><strong>Settings saved.</strong></div>';
        }
        if ($action === 'verify_license') {
            $premiumFile = dirname(dirname(__DIR__)) . '/servers/impulseminio/lib/Premium.php';
            if (file_exists($premiumFile)) {
                require_once $premiumFile;
                \WHMCS\Module\Server\ImpulseMinio\Premium::clearCache();
                $message = '<div class="successbox"><strong>License re-validated.</strong></div>';
            }
            $tab = 'license';
        }
        if ($action === 'add_region') {
            try {
                Capsule::table('mod_impulseminio_regions')->insert([
                    'name' => trim($_POST['region_name']), 'slug' => trim($_POST['region_slug']),
                    'server_id' => (int)$_POST['region_server_id'],
                    'cdn_endpoint' => trim($_POST['region_cdn_endpoint'] ?? ''),
                    'stats_url' => trim($_POST['region_stats_url'] ?? ''),
                    'is_active' => isset($_POST['region_active']) ? 1 : 0,
                    'sort_order' => (int)($_POST['region_sort_order'] ?? 0),
                    'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $message = '<div class="successbox"><strong>Region added.</strong></div>';
            } catch (\Exception $e) { $message = '<div class="errorbox"><strong>' . $esc($e->getMessage()) . '</strong></div>'; }
            $tab = 'regions';
        }
        if ($action === 'update_region') {
            try {
                Capsule::table('mod_impulseminio_regions')->where('id', (int)$_POST['region_id'])->update([
                    'name' => trim($_POST['region_name']), 'slug' => trim($_POST['region_slug']),
                    'server_id' => (int)$_POST['region_server_id'],
                    'cdn_endpoint' => trim($_POST['region_cdn_endpoint'] ?? ''),
                    'stats_url' => trim($_POST['region_stats_url'] ?? ''),
                    'is_active' => isset($_POST['region_active']) ? 1 : 0,
                    'sort_order' => (int)($_POST['region_sort_order'] ?? 0),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $message = '<div class="successbox"><strong>Region updated.</strong></div>';
            } catch (\Exception $e) { $message = '<div class="errorbox"><strong>' . $esc($e->getMessage()) . '</strong></div>'; }
            $tab = 'regions';
        }
        if ($action === 'toggle_region') {
            $r = Capsule::table('mod_impulseminio_regions')->where('id', (int)$_POST['region_id'])->first();
            if ($r) {
                Capsule::table('mod_impulseminio_regions')->where('id', $r->id)->update(['is_active' => $r->is_active ? 0 : 1, 'updated_at' => date('Y-m-d H:i:s')]);
                $message = '<div class="successbox"><strong>Region ' . ($r->is_active ? 'deactivated' : 'activated') . '.</strong></div>';
            }
            $tab = 'regions';
        }
    }

    // Load data
    $settings = [];
    foreach (['premium_license_key','cf_zone_id','cf_api_token','cf_fallback_origin','cf_account_id','domain_map_secret','custom_domain_limit','replication_job_limit',
              'suspension_grace_days','suspension_warning_day','bw_stats_secret','bw_stats_path_prefix'] as $k)
        $settings[$k] = impulseminio_getSetting($k, '');
    $tokenDisplay = !empty($settings['cf_api_token']) ? '********' : '';
    $secretDisplay = !empty($settings['domain_map_secret']) ? '********' : '';
    $regions = Capsule::table('mod_impulseminio_regions')->orderBy('sort_order')->get()->toArray();
    $servers = Capsule::table('tblservers')->where('type', 'impulseminio')->where('active', 1)->get()->toArray();
    $serviceCount = 0; $replCount = 0;
    try { $serviceCount = Capsule::table('mod_impulseminio_service_regions')->count(); } catch (\Exception $e) {}
    try { $replCount = Capsule::table('mod_impulseminio_replication_jobs')->count(); } catch (\Exception $e) {}
    $moduleUrl = $vars['modulelink'];

    echo '<style>
    .im-wrap{max-width:960px;font-family:-apple-system,BlinkMacSystemFont,sans-serif}
    .im-tabs{display:flex;gap:0;border-bottom:2px solid #1a1a2e;margin-bottom:20px;flex-wrap:wrap}
    .im-tab{padding:10px 16px;cursor:pointer;font-weight:500;color:#666;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;font-size:13px}
    .im-tab:hover{color:#1a1a2e;text-decoration:none}.im-tab.active{color:#1a1a2e;font-weight:700;border-bottom-color:#1a1a2e}
    .im-panel{border:1px solid #ddd;border-radius:6px;margin-bottom:20px;overflow:hidden}
    .im-panel-head{background:#1a1a2e;color:#fff;padding:12px 16px;font-weight:600;font-size:14px}
    .im-panel-body{padding:16px}
    .im-fg{margin-bottom:14px}.im-fg label{display:block;font-weight:600;margin-bottom:4px;font-size:13px}
    .im-fg input[type=text],.im-fg input[type=password],.im-fg select{width:100%;max-width:500px;padding:7px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px}
    .im-help{font-size:11px;color:#888;margin-top:3px}
    .im-stats{display:flex;gap:15px;margin-bottom:20px;flex-wrap:wrap}
    .im-stat{background:#f8f9fa;border:1px solid #e9ecef;border-radius:6px;padding:14px 22px;text-align:center;min-width:110px}
    .im-stat b{font-size:26px;display:block;color:#1a1a2e}.im-stat small{font-size:10px;color:#777;text-transform:uppercase}
    .im-badge{display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600}
    .im-ok{background:#d4edda;color:#155724}.im-no{background:#f8d7da;color:#721c24}.im-warn{background:#fff3cd;color:#856404}
    .im-tbl{width:100%;border-collapse:collapse;font-size:13px}
    .im-tbl th{background:#f4f4f4;padding:8px 12px;text-align:left;font-weight:600;border-bottom:2px solid #ddd}
    .im-tbl td{padding:8px 12px;border-bottom:1px solid #eee;vertical-align:middle}.im-tbl tr:hover{background:#fafafa}
    .im-btn{display:inline-block;padding:6px 16px;border:none;border-radius:4px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;color:#fff}
    .im-btn:hover{text-decoration:none;color:#fff}.im-btn-p{background:#1a1a2e}.im-btn-p:hover{background:#2d2d50}
    .im-btn-s{background:#28a745}.im-btn-s:hover{background:#218838}.im-btn-w{background:#ffc107;color:#212529}.im-btn-w:hover{background:#e0a800}
    .im-btn-d{background:#dc3545}.im-btn-d:hover{background:#c82333}.im-btn-sm{padding:4px 10px;font-size:11px}
    .im-rf{background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:16px;margin-top:15px}
    .im-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .im-info{background:#e8f4fd;border:1px solid #bee5eb;border-radius:4px;padding:10px 14px;font-size:12px;color:#0c5460;margin-bottom:14px}
    .im-info a{color:#0c5460;font-weight:600}
    </style>';

    echo '<div class="im-wrap">';
    echo $message;
    echo '<h2 style="margin:0 0 5px;">ImpulseMinio Settings</h2>';
    echo '<p style="color:#666;margin:0 0 15px;">Manage regions, Cloudflare, bandwidth, and global configuration.</p>';

    echo '<div class="im-stats">';
    echo '<div class="im-stat"><b>' . count($regions) . '</b><small>Regions</small></div>';
    echo '<div class="im-stat"><b>' . count(array_filter($regions, fn($r) => $r->is_active)) . '</b><small>Active</small></div>';
    echo '<div class="im-stat"><b>' . $serviceCount . '</b><small>Assignments</small></div>';
    echo '<div class="im-stat"><b>' . $replCount . '</b><small>Repl Jobs</small></div>';
    echo '</div>';

    $tabs = ['overview'=>'Overview','license'=>'License','regions'=>'Regions','cloudflare'=>'Cloudflare',
             'bandwidth'=>'Bandwidth','repljobs'=>'Replication Jobs','assignments'=>'Assignments','limits'=>'Limits & Policy'];
    echo '<div class="im-tabs">';
    foreach ($tabs as $k => $l) { echo '<a href="'.$moduleUrl.'&tab='.$k.'" class="im-tab'.($tab===$k?' active':'').'">'.$l.'</a>'; }
    echo '</div>';

    if ($tab === 'overview') {
        echo '<div class="im-panel"><div class="im-panel-head">Deployed Regions</div><div class="im-panel-body">';
        if (empty($regions)) { echo '<p style="color:#999;">No regions. Go to Regions tab.</p>'; }
        else {
            echo '<table class="im-tbl"><tr><th>Region</th><th>Slug</th><th>Server</th><th>CDN</th><th>Status</th></tr>';
            foreach ($regions as $r) {
                $sn = ''; foreach ($servers as $s) { if ($s->id==$r->server_id) { $sn=$esc($s->name); break; } }
                $b = $r->is_active ? '<span class="im-badge im-ok">Active</span>' : '<span class="im-badge im-no">Inactive</span>';
                echo '<tr><td><strong>'.$esc($r->name).'</strong></td><td><code>'.$esc($r->slug).'</code></td><td>'.$sn.' #'.(int)$r->server_id.'</td><td style="font-size:11px;">'.$esc($r->cdn_endpoint?:'--').'</td><td>'.$b.'</td></tr>';
            }
            echo '</table>';
        }
        echo '</div></div>';
        echo '<div class="im-panel"><div class="im-panel-head">Configuration Status</div><div class="im-panel-body">';
        $checks = [['Premium License Key',!empty($settings['premium_license_key'])],['Regions configured',count($regions)>0],
                   ['CF Zone ID',!empty($settings['cf_zone_id'])],['CF API Token',!empty($settings['cf_api_token'])],
                   ['Fallback Origin',!empty($settings['cf_fallback_origin'])],['CF Account ID',!empty($settings['cf_account_id'] ?? '')],
                   ['Domain Map Secret',!empty($settings['domain_map_secret'] ?? '')],['BW Stats Secret',!empty($settings['bw_stats_secret'])]];
        foreach ($checks as [$l,$ok]) { $i=$ok?'<span style="color:#28a745;">&#10004;</span>':'<span style="color:#dc3545;">&#10008;</span>'; echo '<div style="padding:4px 0;">'.$i.' '.$esc($l).'</div>'; }
        echo '</div></div>';
    }

    if ($tab === 'license') {
        $licenseKey = $settings['premium_license_key'];
        $licenseStatus = 'none'; $licenseTier = ''; $licenseFeatures = []; $licenseMessage = ''; $lastCheck = '';
        if (!empty($licenseKey)) {
            $premiumFile = dirname(dirname(__DIR__)) . '/servers/impulseminio/lib/Premium.php';
            if (file_exists($premiumFile)) {
                require_once $premiumFile;
                try {
                    $result = \WHMCS\Module\Server\ImpulseMinio\Premium::validate();
                    if (($result['status'] ?? '') === 'Active') {
                        $licenseStatus = 'active';
                        $licenseTier = \WHMCS\Module\Server\ImpulseMinio\Premium::getTier();
                        $allFeatures = ['public_access'=>'Public Bucket Access (CDN)','cors'=>'CORS Configuration',
                            'replication'=>'Multi-Region Replication','migration'=>'Cloud Data Migration','custom_domain'=>'Custom Domains'];
                        $tierFeatures = \WHMCS\Module\Server\ImpulseMinio\Premium::TIERS[$licenseTier] ?? [];
                        foreach ($allFeatures as $fKey => $fLabel) { $licenseFeatures[$fLabel] = in_array($fKey, $tierFeatures); }
                        $checkDate = $result['checkdate'] ?? '';
                        if (!empty($checkDate) && strlen($checkDate) === 8) {
                            $lastCheck = substr($checkDate,4,2).'-'.substr($checkDate,6,2).'-'.substr($checkDate,0,4).' UTC';
                        } else { $lastCheck = date('m-d-Y H:i') . ' UTC'; }
                        $licenseMessage = 'License validated successfully.';
                    } else {
                        $licenseStatus = 'invalid';
                        $licenseMessage = 'Validation failed: ' . ($result['description'] ?? $result['status'] ?? 'Unknown');
                    }
                } catch (\Exception $e) { $licenseStatus = 'error'; $licenseMessage = 'Error: ' . $e->getMessage(); }
            } else { $licenseStatus = 'missing'; $licenseMessage = 'Premium module files not installed. Upload Premium.php to modules/servers/impulseminio/lib/'; }
        }

        echo '<form method="post"><input type="hidden" name="action" value="save_settings">';
        echo '<div class="im-panel"><div class="im-panel-head">ImpulseMinio Premium License</div><div class="im-panel-body">';
        if (!empty($licenseKey)) {
            $statusColors = ['active'=>'#28a745','invalid'=>'#dc3545','error'=>'#dc3545','missing'=>'#ffc107'];
            $statusLabels = ['active'=>'Active','invalid'=>'Invalid','error'=>'Error','missing'=>'Files Missing'];
            $sc = array_key_exists($licenseStatus, $statusColors) ? $statusColors[$licenseStatus] : '#999';
            $sl = array_key_exists($licenseStatus, $statusLabels) ? $statusLabels[$licenseStatus] : 'Unknown';
            echo '<div style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:6px;padding:16px;margin-bottom:16px;">';
            echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;flex-wrap:wrap;">';
            echo '<span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:'.$sc.';"></span>';
            echo '<strong style="font-size:15px;">License Status: '.$sl.'</strong>';
            if (!empty($licenseTier)) {
                $tierNames = ['static'=>'Static Hosting','replication'=>'Static + Replication','everything'=>'Everything'];
                echo ' <span class="im-badge im-ok">'.($tierNames[$licenseTier] ?? ucfirst($licenseTier)).'</span>';
            }
            echo '</div>';
            echo '<div style="font-size:13px;color:#555;">'.$esc($licenseMessage).'</div>';
            if (!empty($lastCheck)) echo '<div style="font-size:12px;color:#888;margin-top:4px;">Last check: '.$esc($lastCheck).'</div>';
            if (!empty($licenseFeatures)) {
                echo '<div style="margin-top:12px;display:grid;grid-template-columns:1fr 1fr;gap:4px;">';
                foreach ($licenseFeatures as $fLabel => $fEnabled) {
                    $icon = $fEnabled ? '<span style="color:#28a745;">&#10004;</span>' : '<span style="color:#ccc;">&#10008;</span>';
                    $style = $fEnabled ? '' : 'color:#999;';
                    echo '<div style="padding:3px 0;font-size:13px;'.$style.'">'.$icon.' '.$esc($fLabel).'</div>';
                }
                echo '</div>';
            }
            if ($licenseStatus === 'active' && $licenseTier !== 'everything') {
                echo '<div style="margin-top:12px;padding:10px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;font-size:13px;">';
                echo '<i class="fas fa-arrow-up" style="color:#856404;"></i> Upgrade to unlock more features. ';
                echo '<a href="https://impulsehosting.com/store/impulseminio-premium" target="_blank" style="font-weight:600;">Upgrade License</a>';
                echo '</div>';
            }
            echo '</div>';
        } else {
            echo '<div style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:6px;padding:20px;margin-bottom:16px;text-align:center;">';
            echo '<i class="fas fa-lock" style="font-size:28px;color:#999;display:block;margin-bottom:8px;"></i>';
            echo '<p style="font-size:14px;color:#555;margin-bottom:12px;">No premium license configured. Unlock CDN, replication, and custom domains.</p>';
            echo '<a href="https://impulsehosting.com/store/impulseminio-premium" target="_blank" class="im-btn im-btn-s">Purchase License</a>';
            echo '</div>';
        }
        echo '<div class="im-fg"><label>Premium License Key</label>';
        echo '<input type="text" name="premium_license_key" value="'.$esc($licenseKey).'" placeholder="Enter your ImpulseMinio Premium license key" style="font-family:monospace;">';
        echo '<div class="im-help">Enter your ImpulseMinio Premium license key. Tier is determined by the associated product on the licensing server.</div></div>';
        echo '<div style="margin-top:8px;display:flex;gap:8px;">';
        echo '<button type="submit" class="im-btn im-btn-p"><i class="fas fa-save"></i> Save License Key</button>';
        echo '</form>';
        echo '<form method="post" style="display:inline;"><input type="hidden" name="action" value="verify_license">';
        echo '<button type="submit" class="im-btn im-btn-w"><i class="fas fa-sync-alt"></i> Verify License</button></form>';
        echo '</div>';
        echo '<div style="margin-top:16px;"><h4 style="margin:0 0 10px;font-size:14px;">License Tiers</h4>';
        echo '<table class="im-tbl"><tr><th>Tier</th><th>Price</th><th>Features</th><th></th></tr>';
        $cb = ' <span class="im-badge im-ok">Current</span>';
        echo '<tr><td><strong>Static Hosting</strong></td><td>$99/yr</td><td>Public bucket access (CDN), CORS, static site hosting</td><td>'.($licenseTier==='static'?$cb:'').'</td></tr>';
        echo '<tr><td><strong>Static + Replication</strong></td><td>$199/yr</td><td>All Static + multi-region bucket replication</td><td>'.($licenseTier==='replication'?$cb:'').'</td></tr>';
        echo '<tr><td><strong>Everything</strong></td><td>$249/yr</td><td>All features: CDN, replication, migration, custom domains</td><td>'.($licenseTier==='everything'?$cb:'').'</td></tr>';
        echo '</table></div>';
        echo '</div></div>';
    }

    if ($tab === 'regions') {
        $editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
        $er = $editId ? Capsule::table('mod_impulseminio_regions')->where('id', $editId)->first() : null;

        echo '<div class="im-panel"><div class="im-panel-head">ImpulseDrive Regions</div><div class="im-panel-body">';
        if (empty($regions)) { echo '<p style="color:#999;">No regions yet.</p>'; }
        else {
            echo '<table class="im-tbl"><tr><th>#</th><th>Region</th><th>Slug</th><th>Server</th><th>Services</th><th>Status</th><th>Actions</th></tr>';
            foreach ($regions as $r) {
                $sn=''; foreach ($servers as $s) { if ($s->id==$r->server_id) {$sn=$esc($s->name); break;} }
                $sc=0; try{$sc=Capsule::table('mod_impulseminio_service_regions')->where('region_id',$r->id)->count();}catch(\Exception $e){}
                $b=$r->is_active?'<span class="im-badge im-ok">Active</span>':'<span class="im-badge im-no">Off</span>';
                echo '<tr><td>'.(int)$r->sort_order.'</td><td><strong>'.$esc($r->name).'</strong></td>';
                echo '<td><code>'.$esc($r->slug).'</code></td><td>'.$sn.'</td><td>'.$sc.'</td><td>'.$b.'</td>';
                echo '<td style="white-space:nowrap;"><a href="'.$moduleUrl.'&tab=regions&edit='.$r->id.'" class="im-btn im-btn-p im-btn-sm">Edit</a> ';
                echo '<form method="post" style="display:inline;"><input type="hidden" name="action" value="toggle_region"><input type="hidden" name="region_id" value="'.$r->id.'">';
                echo '<button type="submit" class="im-btn '.($r->is_active?'im-btn-w':'im-btn-s').' im-btn-sm">'.($r->is_active?'Deactivate':'Activate').'</button></form></td></tr>';
            }
            echo '</table>';
        }
        echo '</div></div>';

        $ie=(bool)$er; $ft=$ie?'Edit Region: '.$esc($er->name):'Add New Region'; $fa=$ie?'update_region':'add_region';
        echo '<div class="im-rf"><h4 style="margin:0 0 12px;">'.$ft.'</h4>';
        echo '<form method="post"><input type="hidden" name="action" value="'.$fa.'">';
        if ($ie) echo '<input type="hidden" name="region_id" value="'.$er->id.'">';
        echo '<div class="im-grid">';
        echo '<div class="im-fg"><label>Display Name</label><input type="text" name="region_name" value="'.$esc($ie?$er->name:'').'" placeholder="US Central — Dallas" required></div>';
        echo '<div class="im-fg"><label>Slug</label><input type="text" name="region_slug" value="'.$esc($ie?$er->slug:'').'" placeholder="us-central-dallas" required><div class="im-help">URL-safe identifier. Must match configurable option suboption value.</div></div>';
        echo '<div class="im-fg"><label>WHMCS Server</label><select name="region_server_id" required><option value="">-- Select --</option>';
        foreach ($servers as $s) { $sel=($ie&&$er->server_id==$s->id)?' selected':''; echo '<option value="'.$s->id.'"'.$sel.'>'.$esc($s->name).' ('.$esc($s->hostname).')</option>'; }
        echo '</select></div>';
        echo '<div class="im-fg"><label>CDN Endpoint</label><input type="text" name="region_cdn_endpoint" value="'.$esc($ie?$er->cdn_endpoint:'').'" placeholder="https://us-central-dallas.impulsedrive.io"></div>';
        echo '<div class="im-fg"><label>Stats URL</label><input type="text" name="region_stats_url" value="'.$esc($ie?$er->stats_url:'').'" placeholder="https://us-central-dallas.impulsedrive.io/___impulse_bw_stats_YourSecretKey"><div class="im-help">Full bandwidth stats JSON endpoint URL for this region. Built from: CDN endpoint + path prefix + secret key.</div></div>';
        echo '<div class="im-fg"><label>Sort Order</label><input type="text" name="region_sort_order" value="'.$esc($ie?$er->sort_order:'0').'" style="max-width:80px;"></div>';
        $ck=$ie?($er->is_active?' checked':''):' checked';
        echo '<div class="im-fg"><label><input type="checkbox" name="region_active"'.$ck.'> Active</label></div>';
        echo '</div>';
        echo '<div style="margin-top:12px;"><button type="submit" class="im-btn im-btn-p">'.($ie?'Update Region':'Add Region').'</button>';
        if ($ie) echo ' <a href="'.$moduleUrl.'&tab=regions" class="im-btn im-btn-w">Cancel</a>';
        echo '</div></form></div>';
    }

    if ($tab === 'cloudflare') {
        echo '<form method="post"><input type="hidden" name="action" value="save_settings">';
        echo '<div class="im-panel"><div class="im-panel-head">Cloudflare for SaaS — Custom Domains</div><div class="im-panel-body">';
        echo '<div class="im-info"><strong>Multi-Tenant Custom Domains:</strong> Cloudflare for SaaS enables your customers to use their own domains with ImpulseDrive public buckets. Configure the Cloudflare zone that manages your MinIO namespace domain (e.g., impulsedrive.io). <a href="https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/start/getting-started/" target="_blank">Cloudflare Docs &rarr;</a></div>';
        echo '<div class="im-fg"><label>Cloudflare Zone ID</label>';
        echo '<input type="text" name="cf_zone_id" value="'.$esc($settings['cf_zone_id']).'" placeholder="e.g., a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6">';
        echo '<div class="im-help">Zone ID for your MinIO namespace domain (e.g., impulsedrive.io). Found in Cloudflare Dashboard &rarr; your zone &rarr; Overview (right sidebar).</div></div>';
        echo '<div class="im-fg"><label>Cloudflare API Token</label>';
        echo '<input type="password" name="cf_api_token" value="'.$esc($tokenDisplay).'" placeholder="Enter API token">';
        echo '<div class="im-help">Create at <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank">Cloudflare &rarr; My Profile &rarr; API Tokens</a>. Use the "Custom" template with permissions: <strong>Zone &rarr; SSL and Certificates &rarr; Edit</strong> + <strong>Zone &rarr; DNS &rarr; Read</strong>, scoped to your namespace zone.</div></div>';
        echo '<div class="im-fg"><label>Fallback Origin</label>';
        echo '<input type="text" name="cf_fallback_origin" value="'.$esc($settings['cf_fallback_origin']).'" placeholder="cdn-fallback.yourdomain.com">';
        echo '<div class="im-help">A proxied (orange-clouded) A/AAAA record in Cloudflare pointing to your primary MinIO server. This is the default origin for custom hostname traffic. Must be created in Cloudflare DNS before setting here.</div></div>';
        echo '<div class="im-fg"><label>Account ID</label>';
        echo '<input type="text" name="cf_account_id" value="'.$esc($settings['cf_account_id'] ?? '').'" placeholder="e.g., 1a2b3c4d5e6f7g8h9i0j">';
        echo '<div class="im-help">Found in Cloudflare Dashboard &rarr; any zone &rarr; Overview (right sidebar, below Zone ID).</div></div>';
        echo '</div></div>';
        echo '<div class="im-panel"><div class="im-panel-head">Domain Map Service</div><div class="im-panel-body">';
        echo '<div class="im-info"><strong>Domain Map Updater:</strong> A lightweight Python service runs on each MinIO server (port 9099). When a customer adds or removes a custom domain, WHMCS calls this service to update the Nginx routing map. The shared secret authenticates requests between WHMCS and the MinIO servers.</div>';
        echo '<div class="im-fg"><label>Domain Map Secret</label>';
        echo '<input type="password" name="domain_map_secret" value="'.$esc($secretDisplay).'" placeholder="Enter shared secret from /etc/impulsedrive/domain-map.secret">';
        echo '<div class="im-help">The shared secret generated on your MinIO servers at <code>/etc/impulsedrive/domain-map.secret</code>. Must match across all regions and this WHMCS instance.</div></div>';
        echo '</div></div>';
        echo '<button type="submit" class="im-btn im-btn-p"><i class="fas fa-save"></i> Save Cloudflare Settings</button></form>';
    }

    if ($tab === 'bandwidth') {
        echo '<form method="post"><input type="hidden" name="action" value="save_settings">';
        echo '<div class="im-panel"><div class="im-panel-head">Bandwidth & Usage Tracking</div><div class="im-panel-body">';
        echo '<div class="im-info"><strong>How it works:</strong> Each MinIO server runs a Python script (hourly cron) that parses Nginx access logs and queries Prometheus metrics. The script writes a JSON file served at a secret URL. The WHMCS usage cron fetches this JSON to update customer bandwidth and storage stats.<br><br><strong>Setup per region:</strong> (1) Deploy <code>impulsedrive_bandwidth_stats.py</code> to the MinIO server. (2) Add Nginx <code>location</code> block to serve the stats JSON at the secret path. (3) Add hourly cron. See deployment docs for full instructions.</div>';
        echo '<div class="im-fg"><label>Stats Secret Key</label>';
        echo '<input type="text" name="bw_stats_secret" value="'.$esc($settings['bw_stats_secret']).'" placeholder="e.g., ImpulseBW2026StatsKey">';
        echo '<div class="im-help">A shared secret string appended to the URL path. Same value used across all regions. This prevents unauthorized access to bandwidth data.</div></div>';
        echo '<div class="im-fg"><label>Stats URL Path Prefix</label>';
        echo '<input type="text" name="bw_stats_path_prefix" value="'.$esc($settings['bw_stats_path_prefix']).'" placeholder="/___impulse_bw_stats_">';
        echo '<div class="im-help">URL path prefix before the secret key. Full stats URL = CDN endpoint + prefix + secret. Example: <code>https://us-central-dallas.impulsedrive.io/___impulse_bw_stats_YourSecret</code></div></div>';
        if (!empty($regions)) {
            echo '<div style="margin-top:12px;background:#f4f4f4;border-radius:4px;padding:12px;font-size:12px;"><strong>Constructed Stats URLs per Region:</strong>';
            foreach ($regions as $r) {
                if (!$r->is_active) continue;
                $base = $r->cdn_endpoint ?: 'https://'.$r->slug.'.impulsedrive.io';
                $secret = $settings['bw_stats_secret'] ?: '<em>secret not set</em>';
                $url = $base . $settings['bw_stats_path_prefix'] . $settings['bw_stats_secret'];
                echo '<div style="margin-top:6px;">'.$esc($r->name).': <code>'.$esc($url).'</code></div>';
            }
            echo '</div>';
        }
        echo '</div></div>';
        echo '<button type="submit" class="im-btn im-btn-p"><i class="fas fa-save"></i> Save Bandwidth Settings</button></form>';
    }

    if ($tab === 'repljobs') {
        echo '<div class="im-panel"><div class="im-panel-head">Replication Jobs — All Customers</div><div class="im-panel-body">';
        $jobs = [];
        try {
            $jobs = Capsule::table('mod_impulseminio_replication_jobs as j')
                ->leftJoin('mod_impulseminio_regions as sr', 'j.source_region_id', '=', 'sr.id')
                ->leftJoin('mod_impulseminio_regions as dr', 'j.dest_region_id', '=', 'dr.id')
                ->leftJoin('tblhosting as h', 'j.service_id', '=', 'h.id')
                ->leftJoin('tblclients as c', 'h.userid', '=', 'c.id')
                ->whereNotIn('j.status', ['removing', 'deleted'])
                ->select('j.*', 'sr.name as src_region', 'dr.name as dest_region',
                    'c.firstname', 'c.lastname', 'c.companyname', 'h.userid')
                ->orderBy('j.created_at', 'desc')
                ->get()->toArray();
        } catch (\Exception $e) {}

        if (empty($jobs)) {
            echo '<p style="color:#999;text-align:center;padding:20px;">No replication jobs found.</p>';
        } else {
            echo '<table class="im-tbl"><tr><th>ID</th><th>Customer</th><th>Description</th><th>Source</th><th>Destination</th><th>Status</th><th>Created</th></tr>';
            foreach ($jobs as $j) {
                $custName = trim(($j->firstname ?? '').' '.($j->lastname ?? ''));
                if (!empty($j->companyname)) $custName .= ' ('.$esc($j->companyname).')';
                $statusMap = ['active'=>'im-ok','paused'=>'im-warn','suspended'=>'im-no','error'=>'im-no'];
                $cls = $statusMap[$j->status] ?? '';
                echo '<tr>';
                echo '<td>'.(int)$j->id.'</td>';
                echo '<td><a href="clientssummary.php?userid='.(int)$j->userid.'">'.$esc($custName).'</a><br><small class="text-muted">Service #'.(int)$j->service_id.'</small></td>';
                echo '<td>'.$esc($j->description ?: 'Untitled').'</td>';
                echo '<td>'.$esc($j->src_region ?: '--').'<br><code style="font-size:11px;">'.$esc($j->source_bucket).'</code></td>';
                echo '<td>'.$esc($j->dest_region ?: '--').'<br><code style="font-size:11px;">'.$esc($j->dest_bucket).'</code></td>';
                echo '<td><span class="im-badge '.$cls.'">'.ucfirst($j->status).'</span></td>';
                echo '<td style="font-size:12px;">'.date('m-d-Y', strtotime($j->created_at)).'</td>';
                echo '</tr>';
            }
            echo '</table>';
        }
        echo '</div></div>';
    }

    if ($tab === 'assignments') {
        echo '<div class="im-panel"><div class="im-panel-head">Service Region Assignments</div><div class="im-panel-body">';
        $assignments = [];
        try {
            $assignments = Capsule::table('mod_impulseminio_service_regions as sr')
                ->leftJoin('mod_impulseminio_regions as r', 'sr.region_id', '=', 'r.id')
                ->leftJoin('tblhosting as h', 'sr.service_id', '=', 'h.id')
                ->leftJoin('tblclients as c', 'h.userid', '=', 'c.id')
                ->leftJoin('tblproducts as p', 'h.packageid', '=', 'p.id')
                ->select('sr.*', 'r.name as region_name', 'r.slug as region_slug',
                    'h.domainstatus', 'h.userid', 'p.name as product_name',
                    'c.firstname', 'c.lastname', 'c.companyname')
                ->orderBy('sr.service_id')
                ->get()->toArray();
        } catch (\Exception $e) {}

        if (empty($assignments)) {
            echo '<p style="color:#999;text-align:center;padding:20px;">No assignments found.</p>';
        } else {
            echo '<table class="im-tbl"><tr><th>Service</th><th>Customer</th><th>Product</th><th>Region</th><th>Type</th><th>Status</th></tr>';
            foreach ($assignments as $a) {
                $custName = trim(($a->firstname ?? '').' '.($a->lastname ?? ''));
                if (!empty($a->companyname)) $custName .= ' ('.$esc($a->companyname).')';
                $type = $a->is_primary ? '<span class="im-badge im-ok">Primary</span>' : '<span class="im-badge im-warn">Replica</span>';
                $statusMap = ['Active'=>'im-ok','Suspended'=>'im-no','Terminated'=>'im-no','Pending'=>'im-warn'];
                $sCls = $statusMap[$a->domainstatus ?? ''] ?? '';
                echo '<tr>';
                echo '<td>#'.(int)$a->service_id.'</td>';
                echo '<td><a href="clientssummary.php?userid='.(int)$a->userid.'">'.$esc($custName).'</a></td>';
                echo '<td>'.$esc($a->product_name ?: '--').'</td>';
                echo '<td>'.$esc($a->region_name ?: '--').' <code style="font-size:10px;">'.$esc($a->region_slug ?: '').'</code></td>';
                echo '<td>'.$type.'</td>';
                echo '<td><span class="im-badge '.$sCls.'">'.($a->domainstatus ?? '--').'</span></td>';
                echo '</tr>';
            }
            echo '</table>';
        }
        echo '</div></div>';
    }

    if ($tab === 'limits') {
        echo '<form method="post"><input type="hidden" name="action" value="save_settings">';
        echo '<div class="im-panel"><div class="im-panel-head">Default Limits</div><div class="im-panel-body">';
        echo '<div class="im-fg"><label>Custom Domain Limit</label><input type="text" name="custom_domain_limit" value="'.$esc($settings['custom_domain_limit']).'" style="max-width:100px;"><div class="im-help">Per customer. 0 = unlimited. Everything tier only.</div></div>';
        echo '<div class="im-fg"><label>Replication Job Limit</label><input type="text" name="replication_job_limit" value="'.$esc($settings['replication_job_limit']).'" style="max-width:100px;"><div class="im-help">Per customer. 0 = unlimited.</div></div>';
        echo '</div></div>';
        echo '<div class="im-panel"><div class="im-panel-head">Suspension Policy</div><div class="im-panel-body">';
        echo '<div class="im-fg"><label>Grace Period (days)</label><input type="text" name="suspension_grace_days" value="'.$esc($settings['suspension_grace_days']).'" style="max-width:100px;"><div class="im-help">Days before replicated data is purged.</div></div>';
        echo '<div class="im-fg"><label>Warning Email Day</label><input type="text" name="suspension_warning_day" value="'.$esc($settings['suspension_warning_day']).'" style="max-width:100px;"><div class="im-help">Day after suspension to send purge warning.</div></div>';
        echo '</div></div>';
        echo '<button type="submit" class="im-btn im-btn-p"><i class="fas fa-save"></i> Save Limits & Policy</button></form>';
    }

    echo '</div>';
}
