# ImpulseMinio — Installation Guide

## 1. Upload Module Files

Copy the module directory to your WHMCS installation:

```
modules/servers/impulseminio/  →  {whmcs}/modules/servers/impulseminio/
```

Then copy the hooks to the WHMCS hooks directory:

```
includes/hooks/impulseminio_hooks.php       →  {whmcs}/includes/hooks/impulseminio_hooks.php
includes/hooks/impulseminio_usage_sync.php  →  {whmcs}/includes/hooks/impulseminio_usage_sync.php
```

Then copy the cron wrapper:

```
crons/impulseminio_usage.php  →  {whmcs_root}/crons/impulseminio_usage.php
```

> **Note:** `{whmcs_root}` is the parent of the WHMCS web root. For example, if WHMCS is at `/var/www/whmcs/httpdocs/`, the crons directory is `/var/www/whmcs/crons/`.

**Why multiple hook files?** The module-level `hooks.php` handles addon storage recalculation (billing events). The `impulseminio_hooks.php` in `includes/hooks/` handles the suspension banner and sidebar cleanup — it must live there because Lagom and some WHMCS themes skip loading the module entirely for suspended services. The `impulseminio_usage_sync.php` handles hourly usage tracking and history snapshots for the Statistics tab.

Your final structure should look like:

```
whmcs_root/
├── crons/
│   └── impulseminio_usage.php              ← hourly usage sync runner
├── httpdocs/
│   ├── includes/
│   │   └── hooks/
│   │       ├── impulseminio_hooks.php      ← suspension banner + sidebar hiding
│   │       └── impulseminio_usage_sync.php ← hourly storage + bandwidth + history sync
│   └── modules/
│       └── servers/
│           └── impulseminio/
│               ├── impulseminio.php        ← main module (~1725 lines)
│               ├── hooks.php              ← addon storage recalculation
│               ├── lib/
│               │   └── MinioClient.php    ← mc CLI wrapper (~480 lines)
│               └── templates/
│                   └── clientarea.tpl     ← Smarty template
```

## 2. Install MinIO `mc` CLI

On the WHMCS server (or whichever server runs the module):

```bash
curl -O https://dl.min.io/client/mc/release/linux-amd64/mc
chmod +x mc
mv mc /usr/local/bin/mc
mc --version
```

Verify `exec()` is enabled in PHP (not in `disable_functions` in php.ini).

## 3. Configure MinIO Server in WHMCS

1. Go to **WHMCS Admin → Configuration → Servers → Add New Server**
2. Set:
   - **Name**: MinIO (or any label)
   - **Hostname**: your MinIO API endpoint (e.g., `s3.yourdomain.com`)
   - **Username**: MinIO root access key
   - **Password**: MinIO root secret key
   - **Secure**: Check if using HTTPS
   - **Type**: ImpulseMinio

3. Click **Test Connection** to verify.

## 4. Create Products

1. Go to **WHMCS Admin → Products/Services → Create New Product**
2. Set:
   - **Product Type**: Other
   - **Module**: ImpulseMinio
   - **Server Group**: Assign to your MinIO server
3. Configure the **Module Settings** tab:
   - Disk Quota (GB): e.g., `100` for 100 GB
   - Bandwidth Limit (GB): e.g., `1000` for 1 TB
   - Max Buckets: e.g., `10`
   - Max Access Keys: e.g., `10`
   - mc CLI Path: `/usr/local/bin/mc` (default)
   - S3 Endpoint URL: `https://s3.yourdomain.com` (if different from server hostname)
   - Console URL: `https://console.yourdomain.com` (optional, shows "Open Console" button)
   - Enable Versioning: Check to allow clients to toggle bucket versioning

## 5. Storage Addons (Optional)

Create addons for extra storage:

1. Go to **WHMCS Admin → Products/Services → Product Addons → Add New Addon**
2. Name format must be: `Extra {amount} {GB|TB} Storage`
   - Examples: `Extra 100 GB Storage`, `Extra 1 TB Storage`
3. Set **Applicable Products** to your ImpulseMinio products
4. Set billing cycle and pricing
5. Enable **Allow Multiple Quantities** if desired

The `hooks.php` addon hook automatically recalculates disk limits when addons are activated, suspended, or terminated.

## 6. Usage Tracking & Statistics Setup

The module tracks storage and bandwidth usage hourly and displays historical charts in the Statistics tab.

### 6.1 WHMCS Server Setup

1. **Create the mc config directory** with correct ownership:
   ```bash
   mkdir -p /tmp/.mc-impulse-usage
   chown {whmcs_user}:{whmcs_group} /tmp/.mc-impulse-usage
   chmod 700 /tmp/.mc-impulse-usage
   ```

2. **Create the log file**:
   ```bash
   touch {whmcs_logs}/impulseminio_usage_sync.log
   chown {whmcs_user}:{whmcs_group} {whmcs_logs}/impulseminio_usage_sync.log
   ```

3. **Edit `impulseminio_usage_sync.php`** — update the `$logFile` path (line 25) and `$bwStatsUrl` (line 29) to match your environment.

4. **Edit `impulseminio_usage.php`** (cron wrapper) — update the `ROOTDIR` path (line 2) to point to your WHMCS web root.

5. **Add hourly cron entry** (run as your WHMCS system user, not root):
   ```bash
   #@desc: ImpulseMinio Usage Sync (hourly)
   0  *  *  *  *  /path/to/php -f /path/to/crons/impulseminio_usage.php
   ```

6. **Test manually**:
   ```bash
   su - {whmcs_user} -s /bin/bash -c "/path/to/php -f /path/to/crons/impulseminio_usage.php"
   cat {whmcs_logs}/impulseminio_usage_sync.log
   ```

   You should see storage, bandwidth, and "History snapshot written" in the log.

### 6.2 MinIO Server Setup (Bandwidth + Prometheus Stats)

The MinIO server runs a Python script that parses Nginx bandwidth logs and fetches Prometheus per-bucket metrics. This provides all 6 metrics for the Statistics tab.

1. **Enable Prometheus metrics** in `/etc/default/minio`:
   ```
   MINIO_PROMETHEUS_AUTH_TYPE=public
   ```
   Restart MinIO after this change.

2. **Add Nginx log format** to `/etc/nginx/nginx.conf` (inside the `http {}` block):
   ```nginx
   log_format impulsedrive '$host $remote_addr [$time_local] '
                           '"$request_method $request_uri" '
                           '$status $body_bytes_sent';
   ```

3. **Add access logging** to your MinIO Nginx server blocks (S3 API and CDN):
   ```nginx
   access_log /var/log/nginx/impulsedrive_bandwidth.log impulsedrive;
   ```

4. **Deploy the stats script** — copy `infrastructure/impulsedrive_bandwidth_stats.py` to `/usr/local/bin/` on the MinIO server:
   ```bash
   chmod +x /usr/local/bin/impulsedrive_bandwidth_stats.py
   ```

5. **Create stats directory and add hourly cron**:
   ```bash
   mkdir -p /var/www/impulsedrive-stats
   # Add to root crontab:
   5 * * * * /usr/bin/python3 /usr/local/bin/impulsedrive_bandwidth_stats.py >> /var/log/impulsedrive_bandwidth_stats.log 2>&1
   ```

6. **Add Nginx location** for the stats endpoint — in your MinIO S3 API server block, add above `location / {`:
   ```nginx
   location = /___impulse_bw_stats_{YOUR_SECRET_KEY} {
       alias /var/www/impulsedrive-stats/bandwidth.json;
       default_type application/json;
   }
   ```

7. **Set up log rotation** — copy `infrastructure/logrotate-impulsedrive` to `/etc/logrotate.d/impulsedrive` on the MinIO server.

8. **Test the stats endpoint**:
   ```bash
   python3 /usr/local/bin/impulsedrive_bandwidth_stats.py
   curl -s https://your-minio-domain/___impulse_bw_stats_{YOUR_SECRET_KEY}
   ```
   You should see JSON with per-bucket `storage_bytes`, `traffic_sent_bytes`, `traffic_received_bytes`, `object_count`, and `replication_received_bytes`.

## 7. Hooks Verification

WHMCS auto-loads hooks from these locations:

- `includes/hooks/impulseminio_hooks.php` — loaded on every page (suspension banner + sidebar)
- `includes/hooks/impulseminio_usage_sync.php` — loaded on cron (hourly usage + history sync)
- `modules/servers/impulseminio/hooks.php` — loaded when module is active (addon storage)

To verify hooks are working:

1. Go to **WHMCS Admin → Utilities → Logs → Module Log**
2. Enable module logging temporarily
3. Test an addon activation — you should see `addonStorageRecalc` entries
4. Suspend a test service — verify the banner appears in the client area
5. Run the cron and check the log — should show storage, bandwidth, and history snapshot

## 8. Database Tables

The module auto-creates these tables on first use:

- `mod_impulseminio_buckets` — tracks buckets per service
- `mod_impulseminio_accesskeys` — tracks access keys per service
- `mod_impulseminio_usage_history` — hourly usage snapshots for Statistics tab (auto-pruned at 90 days)

No manual migration required.

## 9. Verify Installation

1. Create a test client and place a manual order
2. Mark the order as paid
3. Check:
   - MinIO user was created (`mc admin user list {alias}`)
   - Bucket was created (`mc ls {alias}`)
   - Client area shows the 6-tab dashboard with connection details
   - File Browser tab loads and lists objects
   - Run the hourly cron, then check the Statistics tab shows chart data

## Troubleshooting

**Module not appearing**: Verify file permissions and that `impulseminio.php` is in the correct path.

**mc CLI errors**: Check that the mc binary path in configoption5 is correct and executable by the web server user.

**"Failed to create user"**: Verify MinIO root credentials in WHMCS server configuration. Check `mc admin info {alias}` works from the command line.

**File Browser not loading**: Check browser console for JS errors. The AJAX endpoints require WHMCS CSRF token — ensure the client area is loading correctly.

**Addon storage not updating**: Verify addon names follow the `Extra {amount} {unit} Storage` pattern exactly.

**Usage shows 0**:
- Check the usage sync log for errors
- Verify the mc config directory (`/tmp/.mc-impulse-usage`) is writable by the WHMCS system user
- Run the cron wrapper manually and check the log output
- For bandwidth: verify the Nginx bandwidth log is being written and the stats endpoint returns JSON

**Statistics tab shows "No data available"**:
- Verify `mod_impulseminio_usage_history` table exists and has rows
- Run the hourly cron at least once to populate history data
- Check browser console for AJAX errors — the endpoint uses POST via `fbAjax`
- Verify `clientGetUsageHistory` is registered in `ClientAreaCustomButtonArray`

**mc alias permission denied**: Delete and recreate the mc config directory with correct ownership:
```bash
rm -rf /tmp/.mc-impulse-usage
mkdir -p /tmp/.mc-impulse-usage
chown {whmcs_user}:{whmcs_group} /tmp/.mc-impulse-usage
chmod 700 /tmp/.mc-impulse-usage
```

**Chart Y-axis shows scientific notation**: This was fixed in v2.4.0 with adaptive unit scaling. Ensure you're running the latest version.
