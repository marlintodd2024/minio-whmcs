# ImpulseMinio — WHMCS MinIO Provisioning Module

S3-compatible object storage provisioning module for WHMCS. Automates user creation, bucket management, quota enforcement, and client self-service via the WHMCS client area.

## Features

- **Automated provisioning** — creates MinIO users, buckets, and policies on order activation
- **Client dashboard** — 6-tab interface: Overview, Buckets, Access Keys, Quick Start, File Browser, Statistics
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
│               ├── impulseminio.php        # Main module (~1725 lines)
│               ├── hooks.php              # Addon storage hooks
│               ├── lib/
│               │   └── MinioClient.php    # mc CLI wrapper (~480 lines)
│               └── templates/
│                   └── clientarea.tpl     # Smarty template

MinIO Server:
├── /usr/local/bin/impulsedrive_bandwidth_stats.py  # Nginx + Prometheus stats
└── /var/www/impulsedrive-stats/bandwidth.json      # Stats output (JSON)
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

## Usage Tracking & Statistics

The module tracks 6 metrics hourly and displays them in the Statistics tab:

| Metric | Source | Description |
|--------|--------|-------------|
| Storage Usage | `mc du` | Total bytes stored across all buckets |
| Downloads | Nginx logs | Egress bytes served to clients |
| Uploads | Prometheus | Ingress bytes received from clients |
| Object Count | `mc du` | Total number of objects stored |
| Inbound Replication | Prometheus | Bytes received via bucket replication |
| Outbound Replication | Prometheus | Bytes sent via bucket replication |

Usage data is stored in `mod_impulseminio_usage_history` and automatically pruned after 90 days. The Statistics tab supports 4 time ranges (24h, 7d, 30d, 90d) with adaptive Y-axis scaling (B/KB/MB/GB).

See [INSTALL.md](INSTALL.md) Section 6 for setup instructions.

## Database Tables

Auto-created on first use:

| Table | Purpose |
|-------|---------|
| `mod_impulseminio_buckets` | Tracks buckets per service |
| `mod_impulseminio_accesskeys` | Tracks access keys per service |
| `mod_impulseminio_usage_history` | Hourly usage snapshots for Statistics tab |

## License

Proprietary — Impulse Hosting. See [LICENSE](LICENSE).
