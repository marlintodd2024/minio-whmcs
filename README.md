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
- **Usage tracking** — storage and bandwidth stats via WHMCS cron
- **Suspension handling** — disables MinIO user on suspend, re-enables on unsuspend
- **Storage addons** — automatic disk limit adjustment when addons are purchased
- **Upgrade/downgrade** — quota updates on plan changes with prorated billing support

## Requirements

- WHMCS 8.x+
- MinIO server with `mc` CLI installed
- PHP 7.4+ with `exec()` enabled
- Lagom client theme (recommended, not required)

## Quick Start

See [INSTALL.md](INSTALL.md) for full setup instructions.

```
modules/servers/impulseminio/
├── impulseminio.php      # Main module
├── hooks.php             # Client area + addon hooks
├── lib/
│   └── MinioClient.php   # mc CLI wrapper
└── templates/
    └── clientarea.tpl    # Smarty template
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

## License

Proprietary — Impulse Hosting. See [LICENSE](LICENSE).
