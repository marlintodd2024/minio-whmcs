<?php
/**
 * ImpulseMinio Settings - WHMCS Addon Module
 *
 * Centralized admin management for the ImpulseMinio S3 storage module.
 * Panels: Overview, Regions, Cloudflare, Bandwidth, Limits & Policy.
 *
 * @package    ImpulseMinio
 * @version    1.1.0
 * @author     Impulse Hosting
 * @license    GPL-3.0
 */
if (!defined('WHMCS')) die('Access denied.');

use WHMCS\Database\Capsule;

function impulseminio_addon_config(): array
{
    return [
        'name'        => 'ImpulseMinio Settings',
        'description' => 'Global settings and region management for the ImpulseMinio S3 storage module.',
        'version'     => '1.1.0',
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
                $t->string('flag', 10)->default('');
                $t->unsignedInteger('server_id');
                $t->string('cdn_endpoint', 255)->nullable();
                $t->string('stats_url', 255)->nullable();
                $t->boolean('is_active')->default(true);
                $t->unsignedInteger('sort_order')->default(0);
                $t->timestamps();
            });
        }
        $defaults = [
            'premium_license_key' => '',
            'cf_zone_id' => '', 'cf_api_token' => '', 'cf_fallback_origin' => '',
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
        if (in_array($key, ['cf_api_token']) && !empty($row->setting_value)) return decrypt($row->setting_value);
        return $row->setting_value;
    } catch (\Exception $e) { return $default; }
}

function impulseminio_setSetting(string $key, string $value): void
{
    $store = (in_array($key, ['cf_api_token']) && !empty($value)) ? encrypt($value) : $value;
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

    // Handle POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        if ($action === 'save_settings') {
            $fields = ['premium_license_key','cf_zone_id','cf_fallback_origin','custom_domain_limit','replication_job_limit',
                        'suspension_grace_days','suspension_warning_day','bw_stats_secret','bw_stats_path_prefix'];
            foreach ($fields as $key) { if (isset($_POST[$key])) impulseminio_setSetting($key, trim($_POST[$key])); }
            if (isset($_POST['cf_api_token']) && $_POST['cf_api_token'] !== '********')
                impulseminio_setSetting('cf_api_token', trim($_POST['cf_api_token']));
            $message = '<div class="successbox"><strong>Settings saved.</strong></div>';
        }
        if ($action === 'add_region') {
            try {
                Capsule::table('mod_impulseminio_regions')->insert([
                    'name' => trim($_POST['region_name']), 'slug' => trim($_POST['region_slug']),
                    'flag' => trim($_POST['region_flag'] ?? ''), 'server_id' => (int)$_POST['region_server_id'],
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
                    'flag' => trim($_POST['region_flag'] ?? ''), 'server_id' => (int)$_POST['region_server_id'],
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
    foreach (['premium_license_key','cf_zone_id','cf_api_token','cf_fallback_origin','custom_domain_limit','replication_job_limit',
              'suspension_grace_days','suspension_warning_day','bw_stats_secret','bw_stats_path_prefix'] as $k)
        $settings[$k] = impulseminio_getSetting($k, '');
    $tokenDisplay = !empty($settings['cf_api_token']) ? '********' : '';
    $regions = Capsule::table('mod_impulseminio_regions')->orderBy('sort_order')->get()->toArray();
    $servers = Capsule::table('tblservers')->where('type', 'impulseminio')->where('active', 1)->get()->toArray();
    $serviceCount = 0; $replCount = 0;
    try { $serviceCount = Capsule::table('mod_impulseminio_service_regions')->count(); } catch (\Exception $e) {}
    try { $replCount = Capsule::table('mod_impulseminio_replication_jobs')->count(); } catch (\Exception $e) {}
    $moduleUrl = $vars['modulelink'];

    // CSS
    echo '<style>
    .im-wrap{max-width:900px;font-family:-apple-system,BlinkMacSystemFont,sans-serif}
    .im-tabs{display:flex;gap:0;border-bottom:2px solid #1a1a2e;margin-bottom:20px}
    .im-tab{padding:10px 20px;cursor:pointer;font-weight:500;color:#666;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px}
    .im-tab:hover{color:#1a1a2e;text-decoration:none}.im-tab.active{color:#1a1a2e;font-weight:700;border-bottom-color:#1a1a2e}
    .im-panel{border:1px solid #ddd;border-radius:6px;margin-bottom:20px;overflow:hidden}
    .im-panel-head{background:#1a1a2e;color:#fff;padding:12px 16px;font-weight:600;font-size:14px}
    .im-panel-body{padding:16px}
    .im-fg{margin-bottom:14px}.im-fg label{display:block;font-weight:600;margin-bottom:4px;font-size:13px}
    .im-fg input[type=text],.im-fg input[type=password],.im-fg select{width:100%;max-width:500px;padding:7px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px}
    .im-help{font-size:11px;color:#888;margin-top:3px}
    .im-stats{display:flex;gap:15px;margin-bottom:20px}
    .im-stat{background:#f8f9fa;border:1px solid #e9ecef;border-radius:6px;padding:14px 22px;text-align:center;min-width:110px}
    .im-stat b{font-size:26px;display:block;color:#1a1a2e}.im-stat small{font-size:10px;color:#777;text-transform:uppercase}
    .im-badge{display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600}
    .im-ok{background:#d4edda;color:#155724}.im-no{background:#f8d7da;color:#721c24}
    .im-tbl{width:100%;border-collapse:collapse;font-size:13px}
    .im-tbl th{background:#f4f4f4;padding:8px 12px;text-align:left;font-weight:600;border-bottom:2px solid #ddd}
    .im-tbl td{padding:8px 12px;border-bottom:1px solid #eee;vertical-align:middle}.im-tbl tr:hover{background:#fafafa}
    .im-btn{display:inline-block;padding:6px 16px;border:none;border-radius:4px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;color:#fff}
    .im-btn:hover{text-decoration:none;color:#fff}.im-btn-p{background:#1a1a2e}.im-btn-p:hover{background:#2d2d50}
    .im-btn-s{background:#28a745}.im-btn-s:hover{background:#218838}.im-btn-w{background:#ffc107;color:#212529}.im-btn-w:hover{background:#e0a800}
    .im-btn-d{background:#dc3545}.im-btn-d:hover{background:#c82333}.im-btn-sm{padding:4px 10px;font-size:11px}
    .im-rf{background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:16px;margin-top:15px}
    .im-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    </style>';

    echo '<div class="im-wrap">';
    echo $message;
    echo '<h2 style="margin:0 0 5px;">ImpulseMinio Settings</h2>';
    echo '<p style="color:#666;margin:0 0 15px;">Manage regions, Cloudflare, bandwidth, and global configuration.</p>';

    // Stats
    echo '<div class="im-stats">';
    echo '<div class="im-stat"><b>' . count($regions) . '</b><small>Regions</small></div>';
    echo '<div class="im-stat"><b>' . count(array_filter($regions, fn($r) => $r->is_active)) . '</b><small>Active</small></div>';
    echo '<div class="im-stat"><b>' . $serviceCount . '</b><small>Assignments</small></div>';
    echo '<div class="im-stat"><b>' . $replCount . '</b><small>Repl Jobs</small></div>';
    echo '</div>';

    // Tabs
    $tabs = ['overview'=>'Overview','license'=>'License','regions'=>'Regions','cloudflare'=>'Cloudflare','bandwidth'=>'Bandwidth','limits'=>'Limits & Policy'];
    echo '<div class="im-tabs">';
    foreach ($tabs as $k => $l) { echo '<a href="'.$moduleUrl.'&tab='.$k.'" class="im-tab'.($tab===$k?' active':'').'">'.$l.'</a>'; }
    echo '</div>';

    // === OVERVIEW TAB ===
    if ($tab === 'overview') {
        echo '<div class="im-panel"><div class="im-panel-head">Deployed Regions</div><div class="im-panel-body">';
        if (empty($regions)) { echo '<p style="color:#999;">No regions. Go to Regions tab.</p>'; }
        else {
            echo '<table class="im-tbl"><tr><th>Region</th><th>Slug</th><th>Server</th><th>CDN</th><th>Status</th></tr>';
            foreach ($regions as $r) {
                $sn = ''; foreach ($servers as $s) { if ($s->id==$r->server_id) { $sn=$esc($s->name); break; } }
                $b = $r->is_active ? '<span class="im-badge im-ok">Active</span>' : '<span class="im-badge im-no">Inactive</span>';
                echo '<tr><td><strong>'.$esc($r->flag).' '.$esc($r->name).'</strong></td><td><code>'.$esc($r->slug).'</code></td><td>'.$sn.' #'.(int)$r->server_id.'</td><td style="font-size:11px;">'.$esc($r->cdn_endpoint?:'--').'</td><td>'.$b.'</td></tr>';
            }
            echo '</table>';
        }
        echo '</div></div>';
        // Config checks
        echo '<div class="im-panel"><div class="im-panel-head">Configuration Status</div><div class="im-panel-body">';
        $checks = [['Premium License Key',!empty($settings['premium_license_key'])],
                   ['Regions configured',count($regions)>0],['CF Zone ID',!empty($settings['cf_zone_id'])],
                   ['CF API Token',!empty($settings['cf_api_token'])],['Fallback Origin',!empty($settings['cf_fallback_origin'])],
                   ['BW Stats Secret',!empty($settings['bw_stats_secret'])]];
        foreach ($checks as [$l,$ok]) { $i=$ok?'<span style="color:#28a745;">&#10004;</span>':'<span style="color:#dc3545;">&#10008;</span>'; echo '<div style="padding:4px 0;">'.$i.' '.$esc($l).'</div>'; }
        echo '</div></div>';
    }

    // === LICENSE TAB ===
    if ($tab === 'license') {
        // Validate license if key exists
        $licenseKey = $settings['premium_license_key'];
        $licenseStatus = null;
        $licenseTier = '—';
        $licenseExpiry = '—';
        $licenseMessage = '';

        if (!empty($licenseKey)) {
            // Call WHMCS Software Licensing API to validate
            try {
                $licenseCheck = localAPI('GetClientsProducts', [], null);
                // For now, do a basic check — Premium.php handles the full validation
                // We just display the key and let the user know if it's set
                $licenseStatus = 'configured';
                $licenseMessage = 'License key is configured. The server module validates the key on each request via the WHMCS Software Licensing addon.';

                // Try to detect tier from key pattern or stored setting
                $tierSetting = impulseminio_getSetting('premium_tier', '');
                if (!empty($tierSetting)) $licenseTier = ucfirst($tierSetting);
            } catch (\Exception $e) {
                $licenseStatus = 'error';
                $licenseMessage = 'Could not validate license: ' . $e->getMessage();
            }
        }

        echo '<form method="post"><input type="hidden" name="action" value="save_settings">';
        echo '<div class="im-panel"><div class="im-panel-head">ImpulseMinio Premium License</div><div class="im-panel-body">';

        // License status card
        if (!empty($licenseKey)) {
            $statusColor = $licenseStatus === 'configured' ? '#28a745' : '#dc3545';
            $statusLabel = $licenseStatus === 'configured' ? 'Configured' : 'Error';
            echo '<div style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:6px;padding:16px;margin-bottom:16px;">';
            echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">';
            echo '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:'.$statusColor.';"></span>';
            echo '<strong style="font-size:15px;">License Status: '.$statusLabel.'</strong>';
            echo '</div>';
            echo '<div style="font-size:13px;color:#555;">'.$esc($licenseMessage).'</div>';
            echo '</div>';
        }

        echo '<div class="im-fg"><label>Premium License Key</label>';
        echo '<input type="text" name="premium_license_key" value="'.$esc($licenseKey).'" placeholder="Enter your ImpulseMinio Premium license key" style="font-family:monospace;">';
        echo '<div class="im-help">Purchase a license at impulsehosting.com. Unlocks public access (CDN), replication, migration, and custom domains based on tier.</div></div>';

        // Tier info table
        echo '<div style="margin-top:16px;">';
        echo '<table class="im-tbl"><tr><th>Tier</th><th>Price</th><th>Features</th></tr>';
        echo '<tr><td><strong>Static Hosting</strong></td><td>$99/yr</td><td>Public bucket access (CDN), static site hosting</td></tr>';
        echo '<tr><td><strong>Static + Replication</strong></td><td>$199/yr</td><td>All Static features + multi-region bucket replication</td></tr>';
        echo '<tr><td><strong>Everything</strong></td><td>$249/yr</td><td>All features: CDN, replication, migration, custom domains</td></tr>';
        echo '</table>';
        echo '</div>';

        echo '</div></div>';
        echo '<button type="submit" class="im-btn im-btn-p">Save License Key</button></form>';
    }

    // === REGIONS TAB ===
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
                echo '<tr><td>'.(int)$r->sort_order.'</td><td><strong>'.$esc($r->flag).' '.$esc($r->name).'</strong></td>';
                echo '<td><code>'.$esc($r->slug).'</code></td><td>'.$sn.'</td><td>'.$sc.'</td><td>'.$b.'</td>';
                echo '<td style="white-space:nowrap;"><a href="'.$moduleUrl.'&tab=regions&edit='.$r->id.'" class="im-btn im-btn-p im-btn-sm">Edit</a> ';
                echo '<form method="post" style="display:inline;"><input type="hidden" name="action" value="toggle_region"><input type="hidden" name="region_id" value="'.$r->id.'">';
                echo '<button type="submit" class="im-btn '.($r->is_active?'im-btn-w':'im-btn-s').' im-btn-sm">'.($r->is_active?'Deactivate':'Activate').'</button></form></td></tr>';
            }
            echo '</table>';
        }
        echo '</div></div>';

        // Add/Edit form
        $ie=(bool)$er; $ft=$ie?'Edit Region: '.$esc($er->name):'Add New Region'; $fa=$ie?'update_region':'add_region';
        echo '<div class="im-rf"><h4 style="margin:0 0 12px;">'.$ft.'</h4>';
        echo '<form method="post"><input type="hidden" name="action" value="'.$fa.'">';
        if ($ie) echo '<input type="hidden" name="region_id" value="'.$er->id.'">';
        echo '<div class="im-grid">';
        echo '<div class="im-fg"><label>Display Name</label><input type="text" name="region_name" value="'.$esc($ie?$er->name:'').'" placeholder="US Central -- Dallas" required></div>';
        echo '<div class="im-fg"><label>Slug</label><input type="text" name="region_slug" value="'.$esc($ie?$er->slug:'').'" placeholder="us-central-dallas" required><div class="im-help">Must match configurable option suboption value.</div></div>';
        echo '<div class="im-fg"><label>Flag</label><input type="text" name="region_flag" value="'.$esc($ie?$er->flag:'').'" placeholder="US"><div class="im-help">Country code or emoji.</div></div>';
        echo '<div class="im-fg"><label>WHMCS Server</label><select name="region_server_id" required><option value="">-- Select --</option>';
        foreach ($servers as $s) { $sel=($ie&&$er->server_id==$s->id)?' selected':''; echo '<option value="'.$s->id.'"'.$sel.'>'.$esc($s->name).' ('.$esc($s->hostname).')</option>'; }
        echo '</select></div>';
        echo '<div class="im-fg"><label>CDN Endpoint</label><input type="text" name="region_cdn_endpoint" value="'.$esc($ie?$er->cdn_endpoint:'').'" placeholder="https://us-central-dallas.impulsedrive.io"></div>';
        echo '<div class="im-fg"><label>Stats URL</label><input type="text" name="region_stats_url" value="'.$esc($ie?$er->stats_url:'').'" placeholder="Full stats URL or leave blank to auto-build"></div>';
        echo '<div class="im-fg"><label>Sort Order</label><input type="text" name="region_sort_order" value="'.$esc($ie?$er->sort_order:'0').'" style="max-width:80px;"></div>';
        $ck=$ie?($er->is_active?' checked':''):' checked';
        echo '<div class="im-fg"><label><input type="checkbox" name="region_active"'.$ck.'> Active</label></div>';
        echo '</div>';
        echo '<div style="margin-top:12px;"><button type="submit" class="im-btn im-btn-p">'.($ie?'Update Region':'Add Region').'</button>';
        if ($ie) echo ' <a href="'.$moduleUrl.'&tab=regions" class="im-btn im-btn-w">Cancel</a>';
        echo '</div></form></div>';
    }

    // === CLOUDFLARE TAB ===
    if ($tab === 'cloudflare') {
        echo '<form method="post"><input type="hidden" name="action" value="save_settings">';
        echo '<div class="im-panel"><div class="im-panel-head">Cloudflare for SaaS -- Custom Domains</div><div class="im-panel-body">';
        echo '<div class="im-fg"><label>Zone ID</label><input type="text" name="cf_zone_id" value="'.$esc($settings['cf_zone_id']).'" placeholder="a1b2c3d4..."><div class="im-help">impulsedrive.io zone ID from Cloudflare dashboard.</div></div>';
        echo '<div class="im-fg"><label>API Token</label><input type="password" name="cf_api_token" value="'.$esc($tokenDisplay).'" placeholder="Enter token"><div class="im-help">Zone:SSL + Certificates:Edit permissions. Stored encrypted.</div></div>';
        echo '<div class="im-fg"><label>Fallback Origin</label><input type="text" name="cf_fallback_origin" value="'.$esc($settings['cf_fallback_origin']).'" placeholder="cdn-fallback.impulsedrive.io"><div class="im-help">Proxied A record in Cloudflare for SaaS traffic.</div></div>';
        echo '</div></div>';
        echo '<button type="submit" class="im-btn im-btn-p">Save Cloudflare Settings</button></form>';
    }

    // === BANDWIDTH TAB ===
    if ($tab === 'bandwidth') {
        echo '<form method="post"><input type="hidden" name="action" value="save_settings">';
        echo '<div class="im-panel"><div class="im-panel-head">Bandwidth & Usage Tracking</div><div class="im-panel-body">';
        echo '<div class="im-fg"><label>Stats Secret Key</label><input type="text" name="bw_stats_secret" value="'.$esc($settings['bw_stats_secret']).'" placeholder="ImpulseBW2026StatsKey"><div class="im-help">Shared secret in the stats URL path on each MinIO server.</div></div>';
        echo '<div class="im-fg"><label>Stats URL Path Prefix</label><input type="text" name="bw_stats_path_prefix" value="'.$esc($settings['bw_stats_path_prefix']).'" placeholder="/___impulse_bw_stats_"><div class="im-help">Full URL = region CDN endpoint + prefix + secret.</div></div>';
        if (!empty($regions) && !empty($settings['bw_stats_secret'])) {
            echo '<div style="margin-top:12px;background:#f4f4f4;border-radius:4px;padding:12px;font-size:12px;"><strong>Constructed URLs:</strong>';
            foreach ($regions as $r) {
                if (!$r->is_active) continue;
                $base = $r->cdn_endpoint ?: 'https://'.$r->slug.'.impulsedrive.io';
                echo '<div style="margin-top:6px;">'.$esc($r->name).': <code>'.$esc($base.$settings['bw_stats_path_prefix'].$settings['bw_stats_secret']).'</code></div>';
            }
            echo '</div>';
        }
        echo '</div></div>';
        echo '<button type="submit" class="im-btn im-btn-p">Save Bandwidth Settings</button></form>';
    }

    // === LIMITS TAB ===
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
        echo '<button type="submit" class="im-btn im-btn-p">Save Limits & Policy</button></form>';
    }

    echo '</div>'; // im-wrap
}
