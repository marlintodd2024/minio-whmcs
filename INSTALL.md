# ImpulseMinio — Installation Guide

## 1. Upload Module Files

Copy the module directory to your WHMCS installation:

```
modules/servers/impulseminio/  →  {whmcs}/modules/servers/impulseminio/
```

Then copy the display hook to the WHMCS hooks directory:

```
includes/hooks/impulseminio_hooks.php  →  {whmcs}/includes/hooks/impulseminio_hooks.php
```

**Why two hook files?** The module-level `hooks.php` handles addon storage recalculation (billing events). The `includes/hooks/` file handles the suspension banner and sidebar cleanup. It must live there because Lagom and some WHMCS themes skip loading the module entirely for suspended services — a module-level hook would never fire when it's needed most.

Your final structure should look like:

```
whmcs/
├── includes/
│   └── hooks/
│       └── impulseminio_hooks.php      ← suspension banner + sidebar hiding
└── modules/
    └── servers/
        └── impulseminio/
            ├── impulseminio.php         ← main module
            ├── hooks.php               ← addon storage recalculation
            ├── lib/
            │   └── MinioClient.php     ← mc CLI wrapper
            └── templates/
                └── clientarea.tpl      ← Smarty template
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
   - **Hostname**: your MinIO API endpoint (e.g., `s3.impulsehosting.com`)
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

## 6. Hooks Verification

WHMCS auto-loads hooks from both locations:

- `includes/hooks/impulseminio_hooks.php` — loaded on every page (handles suspension banner)
- `modules/servers/impulseminio/hooks.php` — loaded when module is active (handles addon storage)

The display hook has a duplicate guard (`IMPULSEMINIO_DISPLAY_HOOK_LOADED`) so it won't fire twice if accidentally loaded from both locations.

To verify hooks are working:

1. Go to **WHMCS Admin → Utilities → Logs → Module Log**
2. Enable module logging temporarily
3. Test an addon activation — you should see `addonStorageRecalc` entries
4. Suspend a test service — verify the banner appears in the client area

## 7. Database Tables

The module auto-creates these tables on first use:

- `mod_impulseminio_buckets` — tracks buckets per service
- `mod_impulseminio_accesskeys` — tracks access keys per service

No manual migration required.

## 8. Verify Installation

1. Create a test client and place a manual order
2. Mark the order as paid
3. Check:
   - MinIO user was created (`mc admin user list impulse`)
   - Bucket was created (`mc ls impulse`)
   - Client area shows the dashboard with connection details
   - File Browser tab loads and lists objects

## Troubleshooting

**Module not appearing**: Verify file permissions and that `impulseminio.php` is in the correct path.

**mc CLI errors**: Check that the mc binary path in configoption5 is correct and executable by the web server user.

**"Failed to create user"**: Verify MinIO root credentials in WHMCS server configuration. Check `mc admin info impulse` works from the command line.

**File Browser not loading**: Check browser console for JS errors. The AJAX endpoints require WHMCS CSRF token — ensure the client area is loading correctly.

**Addon storage not updating**: Verify addon names follow the `Extra {amount} {unit} Storage` pattern exactly.
