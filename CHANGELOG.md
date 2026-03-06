# Changelog

## v2.5.0 — 2026-03-06

### Added
- **Premium module support** — runtime detection of premium add-on files (`lib/Premium.php`) with license validation
- **Public bucket access** — "Public" column in Buckets tab with one-click toggle (premium feature, gated by configoption12+14)
- **CDN URL copy button** — link icon next to public buckets copies the CDN URL to clipboard, full URL shown as tooltip
- **CORS configuration panel** — expandable per-bucket CORS origins editor with examples, validation, and save via AJAX
- **ConfigOptions 11-14** — CDN Endpoint, Enable Public Access, Max Public Buckets, Premium License Key
- **Premium detection helpers** — `impulseminio_hasPremium()`, `impulseminio_hasPublicAccess()`, `impulseminio_hasCors()`
- **MinioClient methods** — `setAnonymousPolicy()` and `getAnonymousPolicy()` for `mc anonymous set/get`
- **Client actions** — `clientTogglePublic` and `clientUpdateCors` registered in button array
- **Client-side CORS validation** — validates origins start with `http://` or `https://` before saving

### Changed
- **Sidebar cleanup** — added "Toggle Public", "Update CORS", "Usage History" to hidden labels
- **Bandwidth label** — changed from "This Month" to "Egress" on Overview tab
- **Tab order** — Quick Start moved to 2nd position (after Overview)
- **Copy All** — removed Region from clipboard output
- **Region row** — hidden from Overview credentials table

## v2.4.0 — 2026-03-05

### Added
- **Statistics tab** — new 6th dashboard tab with interactive Chart.js graphs for storage usage, downloads, uploads, object count, inbound and outbound replication
- **Usage history table** — `mod_impulseminio_usage_history` stores hourly snapshots of all 6 metrics per service, auto-created on first use
- **Adaptive chart units** — Y-axis automatically scales between B, KB, MB, GB based on data magnitude
- **Time range selector** — view statistics for last 24 hours, 7 days, 30 days, or 90 days
- **Metric color coding** — each metric type has a distinct color theme
- **History auto-pruning** — records older than 90 days are automatically cleaned up by the hourly cron
- **Prometheus integration** — MinIO server stats script now fetches per-bucket metrics from Prometheus endpoint

## v2.3.0 — 2026-03-05

### Added
- **Hourly usage sync** — dedicated cron hook updates storage and bandwidth every hour
- **Nginx bandwidth tracking** — per-bucket egress tracking via Nginx access log parsing
- **Bandwidth stats endpoint** — Python script on MinIO server generates per-bucket bandwidth JSON
- **Cron wrapper** — `crons/impulseminio_usage.php` for reliable hourly execution
- **Log rotation config** — logrotate template for Nginx bandwidth logs

## v2.2.1 — 2026-03-04

### Fixed
- File browser download URL generation for files with special characters
- Object listing pagination for buckets with >1000 objects

## v2.2.0 — 2026-03-04

### Added
- **Object Explorer** — File Browser tab with folder navigation, drag-and-drop upload, download, delete
- **Storage addon hook** — automatic disk limit recalculation on addon events
- **Copy All button** — one-click copy of all S3 connection details
- **Plan summary cards** — storage, bandwidth, bucket, and key limits on Overview tab

## v2.1.1 — 2026-03-03

### Added
- Bucket versioning toggle, client password reset, bucket name validation, suspension banner

## v2.1.0 — 2026-03-03

### Added
- Multi-bucket support, access key management, 4-tab dashboard, usage tracking cron

## v2.0.0 — 2026-03-02

### Added
- Initial release — MinIO user provisioning, bucket creation, S3 connection details, lifecycle management
