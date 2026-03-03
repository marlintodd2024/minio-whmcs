# Changelog

All notable changes to ImpulseMinio will be documented in this file.

## [2.1.0] - 2026-03-03

### Added
- PHPDoc blocks on all 29 module functions
- README.md with feature overview, config reference, and architecture docs
- INSTALL.md with step-by-step setup guide (MinIO, Nginx, mc, WHMCS)
- CHANGELOG.md
- GPL-3.0 LICENSE
- Version constant in module header

### Changed
- Version header updated to 2.1.0 with proper @package, @author, @license tags
- Cleaned up duplicate comment blocks in client area section

### Removed
- Debug `logModuleCall` statements added during development

## [2.0.3] - 2026-03-03

### Fixed
- Form field name mismatch: `bucket_label` renamed to `bucket_name` to match handler
- Form field name mismatch: `key_label` renamed to `key_name` to match handler
- Form `a` values now use function names (`clientCreateBucket`) instead of display labels (`Create Bucket`)
- CSRF token injection for JS-generated delete forms (bucket delete, key revoke)
- Explicit `action` URLs on all POST forms for Lagom2 compatibility
- Sidebar custom buttons hidden via DOM JavaScript (functionality in dashboard tabs)

### Changed
- `ClientAreaCustomButtonArray` restored with all 4 button mappings (required for WHMCS routing)
- Bucket name input placeholder changed from "Bucket label (optional)" to "my-bucket-name" with required attribute

## [2.0.2] - 2026-03-03

### Fixed
- Complete rewrite of `renderClientArea` using PHP string concatenation instead of heredoc syntax
- Heredoc syntax with `{$e()}` function calls caused silent PHP fatal errors in Lagom2
- Template changed to simple passthrough: `{$moduleOutput nofilter}`

## [2.0.0] - 2026-03-02

### Added
- 4-tab client dashboard: Overview, Buckets, Access Keys, Quick Start
- Multi-bucket management with create/delete from client area
- Scoped access key management with per-bucket policy support
- Quick Start tab with AWS CLI, rclone, and boto3 code samples
- 10 configurable options (Disk Quota, Bandwidth, Max Buckets, Max Keys, etc.)
- Usage progress bars with percentage display
- Admin tools: Check Usage, Reset Password, Rebuild Policy

### Changed
- Reorganized config options from v1.1 layout (positions shifted)
- Client area rendering moved from Smarty template to PHP-generated HTML

## [1.1.0] - 2026-02-28

### Added
- Multi-bucket support with database tracking (mod_impulseminio_buckets)
- Access key management with database tracking (mod_impulseminio_accesskeys)
- Client area custom buttons for bucket/key CRUD
- Namespace isolation (username-prefixed bucket names)
- Per-user IAM policy rebuild on bucket changes

## [1.0.0] - 2026-02-25

### Added
- Initial release
- MinIO user provisioning (create, suspend, unsuspend, terminate)
- Single primary bucket per account
- Basic usage tracking via WHMCS cron
- MinioClient wrapper using mc binary
- Change password support
- Test connection from admin
