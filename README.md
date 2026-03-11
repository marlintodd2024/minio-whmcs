# ImpulseMinio — MinIO S3 Storage Module for WHMCS

Automated provisioning of MinIO object storage accounts through WHMCS. Provides multi-bucket management, scoped access keys, usage tracking, a file browser, multi-region support, and a 6-tab client dashboard.

## Features

**Core (Free)**
- Automated MinIO user + bucket provisioning via `mc` CLI
- 6-tab client dashboard: Overview, Quick Start, Buckets, Access Keys, File Browser, Statistics
- Multi-bucket management with per-bucket versioning toggle
- Scoped access keys with per-bucket permissions
- File browser with drag-and-drop upload, folder upload, and presigned downloads
- Usage statistics with Chart.js (storage, bandwidth, objects — 4 time ranges)
- Hourly usage sync via cron (storage via `mc du`, bandwidth via Nginx log parsing)
- Suspension banner via hooks (Lagom theme compatible)
- Client password reset
- Multi-region provisioning — customers select a region at order time

**Premium (Licensed Add-on)**
- Public bucket access (CDN) with per-bucket toggle
- CORS configuration panel
- Regional bucket replication with job management
- Cloud data migration from external S3 providers
- Custom domain support for public buckets (planned)

## Requirements

- WHMCS 8.x+
- PHP 8.1+
- MinIO server with `mc` CLI installed on the WHMCS server
- Nginx reverse proxy on MinIO server(s) with SSL

## Installation

1. Upload the `modules/servers/impulseminio/` directory to your WHMCS installation
2. Upload `includes/hooks/impulseminio_hooks.php` to your WHMCS includes/hooks directory
3. Add a MinIO server in WHMCS Admin → System Settings → Servers:
   - Hostname: your MinIO endpoint (e.g., `us-central-dallas.impulsedrive.io`)
   - Username: MinIO admin access key
   - Password: MinIO admin secret key
   - Tick "Secure" for HTTPS
4. Create a product using the `impulseminio` module

## Multi-Region Setup

ImpulseMinio supports provisioning across multiple MinIO instances in different regions.

### 1. Add Servers

Add each MinIO region as a separate server in WHMCS Admin → System Settings → Servers.

### 2. Seed Regions Table

The `mod_impulseminio_regions` table is auto-created on first load. Seed it with your regions:

```sql
INSERT INTO mod_impulseminio_regions (name, slug, flag, server_id, cdn_endpoint, stats_url, is_active, sort_order, created_at, updated_at) VALUES
('US Central — Dallas', 'us-central-dallas', '🇺🇸', 4, 'https://us-central-dallas.impulsedrive.io', 'https://us-central-dallas.impulsedrive.io/___impulse_bw_stats_KEY', 1, 1, NOW(), NOW()),
('US East — Newark', 'us-east-newark', '🇺🇸', 5, 'https://us-east-newark.impulsedrive.io', 'https://us-east-newark.impulsedrive.io/___impulse_bw_stats_KEY', 1, 2, NOW(), NOW());
```

### 3. Create Configurable Option

Create a configurable option group for region selection:

- Group name: "ImpulseDrive Region"
- Option: "Region" (Dropdown)
- Sub-options format: `slug|Display Label` (e.g., `us-central-dallas|US Central — Dallas`)
- Pricing: $0.00
- Link to your ImpulseDrive products

### 4. Backfill Existing Services

Assign existing services to their region:

```sql
INSERT INTO mod_impulseminio_service_regions (service_id, region_id, is_primary, created_at, updated_at)
SELECT h.id, 1, 1, NOW(), NOW()
FROM tblhosting h
JOIN tblproducts p ON h.packageid = p.id
WHERE p.servertype = 'impulseminio'
AND h.id NOT IN (SELECT service_id FROM mod_impulseminio_service_regions);
```

## Product Configuration (ConfigOptions)

| # | Option | Description |
|---|--------|-------------|
| 1 | Disk Quota (GB) | Storage limit. 0 = unlimited |
| 2 | Bandwidth Limit (GB/month) | Monthly egress limit. 0 = unlimited |
| 3 | Max Buckets | Bucket creation limit. 0 = unlimited |
| 4 | Max Access Keys | Access key limit. 0 = unlimited |
| 5 | Overage Rate ($/GB) | Bandwidth overage billing. 0 = disabled |
| 6 | S3 Endpoint URL | Public endpoint shown to customers |
| 7 | Console URL | MinIO Console URL. Blank = hidden |
| 8 | mc Binary Path | Path to mc CLI (default: `/usr/local/bin/mc`) |
| 9 | Bucket Name Prefix | Optional prefix for bucket names |
| 10 | Enable Versioning | Allow S3 object versioning |
| 11 | CDN Endpoint | Public CDN URL (premium) |
| 12 | Enable Public Access | Allow public buckets (premium) |
| 13 | Max Public Buckets | Public bucket limit (premium) |
| 14 | Premium License Key | ImpulseMinio Premium key |

## Database Tables

| Table | Purpose |
|-------|---------|
| `mod_impulseminio_buckets` | Tracks customer buckets per service |
| `mod_impulseminio_accesskeys` | Tracks customer access keys per service |
| `mod_impulseminio_regions` | Registry of MinIO server regions |
| `mod_impulseminio_service_regions` | Links services to their primary region |
| `mod_impulseminio_usage_history` | 90-day usage history for statistics charts |

All tables are auto-created on first module load via `ensureTables()`.

## File Structure

```
modules/servers/impulseminio/
├── impulseminio.php          # Core module (free)
├── MinioClient.php           # mc CLI wrapper
├── clientarea.tpl            # Smarty template passthrough
├── hooks.php                 # Module-level hooks
├── crons/
│   └── impulseminio_usage.php # Hourly usage sync cron
├── lib/                      # Premium (licensed)
│   ├── Premium.php           # License validator
│   ├── PublicAccess.php      # CDN / public bucket logic
│   └── Replication.php       # Multi-region replication
└── templates/                # Premium templates
    ├── public_access.html
    └── replication.html

includes/hooks/
└── impulseminio_hooks.php    # Global hooks (Lagom compatibility)
```

## Usage Sync

The module includes an hourly cron that syncs storage and bandwidth usage:

- **Storage**: `mc du` per bucket → `tblhosting.diskusage`
- **Bandwidth**: Nginx log parsing via Python script on MinIO server → JSON endpoint → `tblhosting.bwusage`

### MinIO Server Setup (per region)

1. Add custom Nginx log format for bandwidth tracking
2. Deploy `impulsedrive_bandwidth_stats.py` script
3. Add hourly cron for the stats script
4. Expose stats JSON at a secret URL endpoint

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history.

## License

GPL-3.0. Premium features require a separate license from Impulse Hosting.
