# ImpulseMinio — MinIO S3 Provisioning Module for WHMCS

Automatically provision and manage MinIO S3-compatible cloud storage accounts directly from WHMCS. Built for hosting providers who want to offer object storage as a service.

## Features

- **Automated provisioning** — Order placed, account created. MinIO user, bucket, and IAM policy set up automatically.
- **4-tab client dashboard** — Overview with connection details, Buckets manager, Access Keys manager, Quick Start guide with code samples.
- **Bucket management** — Clients create and delete buckets with namespace isolation and configurable limits.
- **Scoped access keys** — Create keys restricted to specific buckets or grant full access. Secret shown once.
- **Usage tracking** — Disk and bandwidth usage pulled from MinIO, displayed as progress bars, updated via cron.
- **Full lifecycle** — Suspend, unsuspend, terminate, change password, upgrade/downgrade.
- **Admin tools** — Check Usage, Reset Password, Rebuild Policy, Test Connection.
- **Lagom2 compatible** — Tested and working with RS Themes Lagom2 client theme. Also works with standard WHMCS themes.

## Screenshots

*Coming soon*

## Requirements

- WHMCS 8.x or later
- PHP 8.1+
- MinIO server (self-hosted or managed)
- `mc` (MinIO Client) binary installed on WHMCS server
- SSL certificate on MinIO endpoint (required for S3 clients)

## Quick Install

1. Download the latest release and extract to your WHMCS root:
   ```bash
   cd /path/to/whmcs
   unzip impulseminio-v2.1.0.zip
   ```

2. Add a server in WHMCS Admin → Setup → Servers:
   - Hostname: your MinIO S3 endpoint
   - Username: MinIO admin user
   - Password: MinIO admin password
   - Type: ImpulseMinio

3. Create a product with Module: ImpulseMinio and configure the 10 options.

4. Place a test order.

See [INSTALL.md](INSTALL.md) for the full setup guide including MinIO server installation, Nginx proxy configuration, and `mc` setup.

## Config Options

| # | Option | Description |
|---|--------|-------------|
| 1 | Disk Quota (GB) | Storage limit per account |
| 2 | Bandwidth Limit (GB) | Monthly transfer limit |
| 3 | Max Buckets | Maximum buckets per account (0 = unlimited) |
| 4 | Max Access Keys | Maximum access keys per account (0 = unlimited) |
| 5 | Overage Rate ($/GB) | Reserved for metered billing |
| 6 | S3 Endpoint URL | Client-facing S3 endpoint (e.g. https://s3.yourdomain.com) |
| 7 | Console URL | MinIO web console URL (optional) |
| 8 | mc Binary Path | Path to mc binary (default: /usr/local/bin/mc) |
| 9 | Bucket Prefix | Namespace prefix for buckets |
| 10 | Enable Versioning | Reserved for object versioning |

## File Structure

```
modules/servers/impulseminio/
├── impulseminio.php          # Main module (all WHMCS hooks + client area)
├── hooks.php                 # UsageUpdate cron integration
├── lib/
│   └── MinioClient.php       # MinIO S3/admin API wrapper
├── templates/
│   └── clientarea.tpl        # Smarty passthrough template
├── README.md
├── INSTALL.md
├── CHANGELOG.md
└── LICENSE
```

## How It Works

When a customer orders a storage product:

1. **CreateAccount** creates a MinIO user, primary bucket, read/write IAM policy, and sets quota
2. **Client dashboard** shows S3 credentials, usage stats, and management tools
3. **Bucket/key actions** POST to WHMCS via `ClientAreaCustomButtonArray` routing
4. **UsageUpdate** cron pulls disk/bandwidth metrics from MinIO daily
5. **Terminate** cleans up everything: buckets, keys, user, policies, and DB records

The client area renders HTML via PHP string concatenation (not Smarty heredocs) for maximum theme compatibility. A simple `{$moduleOutput nofilter}` template passes the output through.

## Contributing

Pull requests welcome. Please ensure:

- PHP syntax passes (`php -l impulseminio.php`)
- All functions have PHPDoc blocks
- No heredoc syntax (breaks in Lagom2)
- Test with both Lagom2 and standard WHMCS theme

## License

GPL-3.0 — see [LICENSE](LICENSE) for details.

## Credits

Built by [Impulse Hosting](https://impulsehosting.com).
