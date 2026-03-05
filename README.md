# ImpulseMinio — WHMCS MinIO Provisioning Module

S3-compatible object storage provisioning module for WHMCS. Automates user creation, bucket management, quota enforcement, and client self-service via the WHMCS client area.

## Features

- **Automated provisioning** — creates MinIO users, buckets, and policies on order activation
- **Client dashboard** — 5-tab interface: Overview, Buckets, Access Keys, Quick Start, File Browser
- **S3 Connection Details** — endpoint, credentials, plan limits, one-click copy
- **Object Explorer** — browse, upload (drag-and-drop), download, and delete files directly from WHMCS
- **Bucket management** — create/delete buckets with per-plan limits and namespace isolation
- **Access key management** — create/revoke service accounts with optional bucket scoping
- **Versioning toggle** — enable/suspend S3 versioning per bucket (configurable per product)
- **Quota enforcement** — disk quotas per bucket via MinIO's built-in quota system
- **Hourly usage tracking** — storage via `mc du`, bandwidth via Nginx log parsing with per-bucket attribution
- **Suspension handling** — disables MinIO user on suspend, re-enables on unsuspend
- **Storage addons** — automatic disk limit adjustment when addons are purchased
- **Upgrade/downgrade** — quota updates on plan changes with prorated billing support

## Requirements

- WHMCS 8.x+
- MinIO server with `mc` CLI installed
- PHP 7.4+ with `exec()` enabled
- Python 3 on MinIO server (for bandwidth tracking)
- Lagom client theme (recommended, not required)

## Quick Start

See [INSTALL.md](INSTALL.md) for full setup instructions.

```
whmcs_root/
├── crons/
│   └── impulseminio_usage.php          # Hourly usage sync runner
├── httpdocs/
│   ├── includes/
│   │   └── hooks/
│   │       ├── impulseminio_hooks.php      # Suspension banner + sidebar
│   │       └── impulseminio_usage_sync.php # Hourly storage + bandwidth sync
│   └── modules/
│       └── servers/
│           └── impulseminio/
│               ├── impulseminio.php        # Main module
│               ├── hooks.php              # Addon storage hooks
│               ├── lib/
│               │   └── MinioClient.php    # mc CLI wrapper
│               └── templates/
│                   └── clientarea.tpl     # Smarty template

MinIO Server:
├── /usr/local/bin/impulsedrive_bandwidth_stats.py  # Nginx log parser
└── /var/www/impulsedrive-stats/bandwidth.json      # Stats output
```

## Product Configuration

| Option | Field | Description |
|--------|-------|-------------|
| configoption1 | Disk Quota (GB) | Storage limit. 0 = unlimited |
| configoption2 | Bandwidth Limit (GB) | Monthly transfer limit |
| configoption3 | Max Buckets | Bucket count limit. 0 = unlimited |
| configoption4 | Max Access Keys | Key count limit. 0 = unlimited |
| configoption5 | mc CLI Path | Default: `/usr/local/bin/mc` |
| configoption6 | S3 Endpoint URL | Override auto-detected endpoint |
| configoption7 | Console URL | Link to MinIO Console (optional) |
| configoption8 | Bucket Prefix | Custom prefix for bucket names |
| configoption9 | Reserved | — |
| configoption10 | Enable Versioning | Allow clients to toggle versioning |

## Usage Tracking

The module tracks both storage and bandwidth usage hourly:

- **Storage** — queries MinIO via `mc du --json` for each service's buckets, writes to `tblhosting.diskusage`
- **Bandwidth** — parses Nginx access logs on the MinIO server for per-bucket egress, writes to `tblhosting.bwusage`
- **Monthly reset** — bandwidth counters reset automatically at the start of each month

Usage data is displayed as progress bars on the client dashboard Overview tab.

See [INSTALL.md](INSTALL.md) Section 6 for setup instructions.

## License

Proprietary — Impulse Hosting. See [LICENSE](LICENSE).
