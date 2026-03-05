# Changelog

## v2.4.0 — 2026-03-05

### Added
- **Statistics tab** — new 6th dashboard tab with interactive Chart.js graphs for storage usage, downloads, uploads, object count, inbound and outbound replication
- **Usage history table** — `mod_impulseminio_usage_history` stores hourly snapshots of all 6 metrics per service, auto-created on first use
- **Adaptive chart units** — Y-axis automatically scales between B, KB, MB, GB based on data magnitude
- **Time range selector** — view statistics for last 24 hours, 7 days, 30 days, or 90 days
- **Metric color coding** — each metric type has a distinct color theme (navy for storage, blue for downloads, green for uploads, purple for objects, orange/red for replication)
- **History auto-pruning** — records older than 90 days are automatically cleaned up by the hourly cron
- **Prometheus integration** — MinIO server stats script now fetches per-bucket metrics from Prometheus endpoint (uploads, object count, replication) in addition to Nginx bandwidth logs

### Changed
- **Region row hidden** — removed the non-editable "us-east-1" region field from the Overview credentials table
- **Usage sync cron (v3)** — now writes history snapshots to `mod_impulseminio_usage_history` each run, fetches expanded stats from MinIO server including Prometheus data
- **MinIO stats endpoint (v2)** — Python script now includes `storage_bytes`, `object_count`, `traffic_sent_bytes`, `traffic_received_bytes`, `replication_received_bytes` per bucket from Prometheus
- **Custom button array** — added `clientGetUsageHistory` AJAX endpoint registration

### Fixed
- **Chart.js Y-axis** — no longer shows scientific notation for small byte values; uses adaptive B/KB/MB/GB scaling
- **AJAX routing** — Statistics chart uses POST via `fbAjax` instead of GET for WHMCS compatibility

## v2.3.0 — 2026-03-05

### Added
- **Hourly usage sync** — new dedicated cron hook (`impulseminio_usage_sync.php`) updates storage and bandwidth every hour instead of relying on WHMCS daily cron
- **Nginx bandwidth tracking** — per-bucket egress tracking via Nginx access log parsing on the MinIO server, with cumulative monthly totals
- **Bandwidth stats endpoint** — Python script on MinIO server generates per-bucket bandwidth JSON, served at a secret URL for WHMCS consumption
- **Cron wrapper** — `crons/impulseminio_usage.php` for reliable hourly execution independent of WHMCS hook system
- **Log rotation config** — logrotate template for Nginx bandwidth logs

### Changed
- **Usage reporting** — `tblhosting.diskusage` and `tblhosting.bwusage` now update hourly (previously only on daily WHMCS cron via `UsageUpdate`)

### Infrastructure (MinIO Server)
- Added `MINIO_PROMETHEUS_AUTH_TYPE=public` to `/etc/default/minio`
- Added `log_format impulsedrive` to Nginx for per-bucket bandwidth logging
- Added `access_log` directive to S3 API and CDN server blocks
- Added bandwidth stats location block to Nginx S3 API server block
- Deployed `impulsedrive_bandwidth_stats.py` hourly cron for log parsing

## v2.2.1 — 2026-03-04

### Fixed
- File browser download URL generation for files with special characters
- Object listing pagination for buckets with >1000 objects

## v2.2.0 — 2026-03-04

### Added
- **Object Explorer** — new File Browser tab with folder navigation, breadcrumb trail, file listing with icons, size, and date
- **File upload** — drag-and-drop upload zone with progress bar, uses presigned PUT URLs for direct-to-MinIO transfer
- **File download** — presigned download URLs (1hr expiry) via the Object Explorer
- **File/folder delete** — delete objects and folders from the browser with confirmation
- **Create folder** — create new folders within buckets from the Object Explorer
- **Storage addon hook** — automatic disk limit recalculation on addon activation, suspension, unsuspension, termination, and cancellation
- **Copy All button** — one-click copy of all S3 connection details to clipboard
- **Plan summary cards** — storage, bandwidth, bucket, and key limits displayed at top of Overview tab

### Changed
- **S3 Connection Details** — redesigned from row-based layout to compact table with plan summary cards
- **Sidebar labels** — file browser action labels hidden from Lagom/WHMCS sidebar

### MinioClient additions
- `listObjects()` — list objects in a bucket with prefix support
- `getPresignedDownloadUrl()` — generate time-limited download URLs
- `getPresignedUploadUrl()` — generate time-limited upload URLs
- `deleteObject()` — delete a single object
- `createFolder()` — create an empty folder marker object

## v2.1.1 — 2026-03-03

### Added
- Bucket versioning toggle (per-product configoption10)
- Client password (secret key) reset
- Bucket name validation (S3-compliant: 3-63 chars, lowercase, hyphens)
- Suspension banner via hooks.php (works with Lagom theme)
- Bandwidth limit tracking in tblhosting (bwlimit/bwusage)

### Fixed
- Sidebar action links hidden via JS (Create Bucket, Delete Bucket, etc.)
- Tab hash routing (URL #buckets, #accesskeys, etc.)
- Flash message cleanup on tab switch

## v2.1.0 — 2026-03-03

### Added
- Multi-bucket support with namespace isolation (username-prefixed)
- Access key management with optional bucket scoping
- 4-tab client dashboard (Overview, Buckets, Access Keys, Quick Start)
- Usage tracking cron via UsageUpdate hook
- Admin area service tab fields
- Admin custom buttons (Check Usage, Reset Password, Rebuild Policy)

## v2.0.0 — 2026-03-02

### Added
- Initial release
- MinIO user provisioning via mc CLI
- Single bucket creation with quota
- S3 connection details display
- Suspend/unsuspend/terminate lifecycle
- Quick Start tab with AWS CLI, rclone, Python examples
