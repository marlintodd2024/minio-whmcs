# ImpulseMinio — WHMCS MinIO Provisioning Module

S3-compatible object storage provisioning module for WHMCS. Automates user creation, bucket management, quota enforcement, and client self-service via the WHMCS client area.

## Features

- **Automated provisioning** — creates MinIO users, buckets, and policies on order activation
- **Client dashboard** — 6-tab interface: Overview, Quick Start, Buckets, Access Keys, File Browser, Statistics
- **S3 Connection Details** — endpoint, credentials, plan limits, one-click copy
- **Object Explorer** — browse, upload (drag-and-drop), download, and delete files directly from WHMCS
- **Storage Statistics** — interactive Chart.js graphs with 6 metrics, 4 time ranges, and adaptive unit scaling
- **Bucket management** — create/delete buckets with per-plan limits and namespace isolation
- **Access key management** — create/revoke service accounts with optional bucket scoping
- **Versioning toggle** — enable/suspend S3 versioning per bucket (configurable per product)
- **Quota enforcement** — disk quotas per bucket via MinIO's built-in quota system
- **Hourly usage tracking** — storage via `mc du`, bandwidth via Nginx log parsing, all 6 metrics via Prometheus
- **Suspension handling** — disables MinIO user on suspend, re-enables on unsuspend
- **Storage addons** — automatic disk limit adjustment when addons are purchased
- **Upgrade/downgrade** — quota updates on plan changes with prorated billing support

### Premium Features (licensed add-on)

- **Public bucket access** — one-click toggle to make buckets publicly accessible via CDN
- **CDN delivery** — objects served via virtual-hosted style URLs (`bucket.region.domain/object`)
- **CORS configuration** — per-bucket allowed origins panel with validation
- **Regional replication** — per-bucket replication jobs between MinIO regions *(planned)*
- **Cloud data migration** — guided migration from AWS S3, Backblaze B2, Wasabi *(planned)*

Premium features require the [ImpulseMinio Premium](https://www.impulsehosting.com) licensed add-on.

## Requirements

- WHMCS 8.x+
- MinIO server with `mc` CLI installed
- PHP 7.4+ with `exec()` enabled
- Python 3 on MinIO server (for bandwidth and Prometheus stats)
- Lagom client theme (recommended, not required)

## Quick Start

See [INSTALL.md](INSTALL.md) for full setup instructions.

```
whmcs_root/
├── crons/
│   └── impulseminio_usage.php              # Hourly usage sync runner
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
│               │   ├── MinioClient.php    # mc CLI wrapper
│               │   ├── Premium.php        # (premium add-on) License validator
│               │   └── PublicAccess.php   # (premium add-on) Static hosting
│               └── templates/
│                   ├── clientarea.tpl     # Smarty template
│                   └── public_access.html # (premium add-on) CORS panel
```

## Product Configuration

| Option | Field | Description |
|--------|-------|-------------|
| configoption1 | Disk Quota (GB) | Storage limit. 0 = unlimited |
| configoption2 | Bandwidth Limit (GB) | Monthly egress limit. 0 = unlimited |
| configoption3 | Max Buckets | Bucket count limit. 0 = unlimited |
| configoption4 | Max Access Keys | Key count limit. 0 = unlimited |
| configoption5 | Overage Rate ($/GB) | Bandwidth overage rate. 0 = disabled |
| configoption6 | S3 Endpoint URL | Override auto-detected endpoint |
| configoption7 | Console URL | Link to MinIO Console (optional) |
| configoption8 | mc Binary Path | Default: `/usr/local/bin/mc` |
| configoption9 | Bucket Name Prefix | Custom prefix for bucket names |
| configoption10 | Enable Versioning | Allow clients to toggle versioning |
| configoption11 | CDN Endpoint | Public CDN base URL (premium) |
| configoption12 | Enable Public Access | Allow public bucket toggle (premium) |
| configoption13 | Max Public Buckets | Public bucket limit. 0 = unlimited (premium) |
| configoption14 | Premium License Key | ImpulseMinio Premium license key |

## Database Tables

Auto-created on first use:

| Table | Purpose |
|-------|---------|
| `mod_impulseminio_buckets` | Tracks buckets per service |
| `mod_impulseminio_accesskeys` | Tracks access keys per service |
| `mod_impulseminio_usage_history` | Hourly usage snapshots for Statistics tab |
| `mod_impulseminio_public_buckets` | Public bucket state and CORS config (premium) |
| `mod_impulseminio_license` | License validation cache (premium) |

## License

Proprietary — Impulse Hosting. See [LICENSE](LICENSE).
