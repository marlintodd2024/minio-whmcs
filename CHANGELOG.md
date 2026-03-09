# Changelog

All notable changes to the ImpulseMinio WHMCS module.

## [2.6.0] - 2026-03-07

### Added
- **Multi-region provisioning** — customers select a datacenter region at order time via configurable option dropdown. Module resolves the region to the correct WHMCS server and provisions on the appropriate MinIO instance.
- New database tables: `mod_impulseminio_regions` (region registry with server mapping, CDN endpoint, stats URL) and `mod_impulseminio_service_regions` (links services to their primary region).
- `impulseminio_getServiceClient()` — region-aware MinIO client resolver. Checks `service_regions` table for region assignment, falls back to product's default server for legacy services.
- `impulseminio_getClientForServer()` — builds a MinIO client from any `tblservers` record by ID, with proper password decryption.
- `impulseminio_getPrimaryRegion()` — resolves a service's primary region from the regions table.
- `CreateAccount` now reads the "Region" configurable option (supports slug text, `slug|label` format, or numeric suboption ID), provisions on the correct regional MinIO server, records the region assignment, and updates `tblhosting.server`.
- Debug logging for configoptions resolution in `CreateAccount`.

### Changed
- **MinioClient v1.2** — alias names are now unique per endpoint (`impulse-{md5hash}`) instead of hardcoded `impulse`. Prevents alias collisions when the WHMCS server connects to multiple MinIO instances in the same `mc` config directory.
- All `impulseminio_getClient($params)` calls in service-context functions replaced with `impulseminio_getServiceClient($params)` for region-aware routing. Affects: Suspend, Unsuspend, Terminate, ChangePackage, all client AJAX handlers (bucket ops, access keys, file browser, public access, CORS).
- `impulseminio_rebuildFullPolicy()` now uses `getServiceClient()`.

### Fixed
- **File browser upload state reset** — file input values are cleared after successful upload so `onchange` fires correctly on subsequent uploads. Success message displays for 2 seconds before the upload zone auto-hides.
- **Bucket change resets upload state** — switching buckets via the dropdown now hides the upload zone, clears progress, and resets both file inputs.

### Added (File Browser)
- **Folder upload** — new "Upload Folder" button in the toolbar using `<input webkitdirectory multiple>`. Files are uploaded with their `webkitRelativePath` preserved as S3 key prefixes, maintaining folder structure in the bucket. Supported in Chrome, Edge, and Firefox.

## [2.5.0] - 2026-03-05

### Added
- 6-tab client dashboard: Overview, Quick Start, Buckets (with public toggle + CORS panel), Access Keys, File Browser, Statistics.
- Premium module v1.0.0: `Premium.php` (license validator via WHMCS Software Licensing Addon) + `PublicAccess.php` (CDN toggle + CORS).
- Three premium license tiers: Static $99/yr, Static+Replication $199/yr, Everything $249/yr.
- License key stored in configoption14.

## [2.4.0] - 2026-03-04

### Added
- Statistics tab with Chart.js — 6 metrics, 4 time ranges, adaptive Y-axis.
- Usage history stored in `mod_impulseminio_usage_history` with 90-day auto-prune.
- Prometheus per-bucket metrics integration.
- Pro and Business product tiers created with upgrade paths configured.

### Changed
- Quick Start tab moved to 2nd position.
- Bandwidth label changed to "Egress".
- Region row hidden in dashboard.

## [2.3.0] - 2026-03-03

### Added
- Hourly usage sync cron: storage via `mc du` → `tblhosting.diskusage`, bandwidth via Nginx log parsing on MinIO server.
- Python bandwidth stats script with Prometheus metrics aggregation.
- Cron file: `crons/impulseminio_usage.php`.

## [2.2.0] - 2026-03-02

### Added
- File browser tab with presigned upload/download URLs.
- Drag-and-drop file upload zone.
- Object listing with folder navigation and breadcrumbs.
- File/folder deletion with versioning-aware confirmation.
- Folder creation.

## [2.1.1] - 2026-03-01

### Added
- 4-tab client dashboard: Overview, Buckets, Access Keys, Quick Start.
- Per-bucket versioning toggle.
- Client password reset.
- Bucket name validation (3-63 chars, lowercase alphanumeric + hyphens).
- Suspension banner via hooks.php (Lagom theme compatible).

### Fixed
- E2E test suite: 18/18 passing.

## [2.0.0] - 2026-02-28

### Added
- Multi-bucket management with namespace isolation.
- Scoped access keys with per-bucket IAM policies.
- Addon storage hooks for quota management.
- Three product tiers: Starter, Pro, Business.

## [1.0.0] - 2026-02-25

### Added
- Initial release.
- MinIO user provisioning via `mc` CLI.
- Single bucket per service.
- Basic suspend/unsuspend/terminate lifecycle.
