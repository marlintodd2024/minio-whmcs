# ImpulseMinio — Product Roadmap

## Current Release: v2.2.0

### Core Module (Free)
- [x] MinIO user provisioning via mc CLI
- [x] Multi-bucket support with namespace isolation
- [x] Access key management with bucket scoping
- [x] Bucket versioning toggle
- [x] Client password reset
- [x] Quota enforcement per bucket
- [x] Usage tracking (storage + bandwidth)
- [x] Suspension/unsuspension lifecycle
- [x] 5-tab client dashboard (Overview, Buckets, Keys, Quick Start, File Browser)
- [x] S3 Connection Details with plan summary + Copy All
- [x] Object Explorer (browse, upload, download, delete, create folders)
- [x] Storage addon hook (auto-adjust limits)
- [x] Upgrade/downgrade support with quota updates
- [x] Admin area service tab + custom buttons
- [x] Lagom theme compatibility (sidebar hiding, suspension banner)

### Product Tiers
- [x] Starter: $4.99/mo — 100 GB storage, 1 TB bandwidth, 10 buckets, 10 keys
- [x] Pro: $20.99/mo — 500 GB storage, 5 TB bandwidth, 50 buckets, 50 keys
- [x] Business: $41.99/mo — 1 TB storage, 10 TB bandwidth, unlimited buckets/keys
- [x] Storage addons: +100 GB ($4.99), +250 GB ($10.99), +1 TB ($41.99)
- [x] 33% discount on quarterly/semi-annual/annual billing
- [x] Upgrade-only paths (no downgrades)

## Planned: Premium Module (ionCube Protected)

### Phase 1 — Static HTTP Bucket Hosting
- [ ] Wildcard DNS configuration for bucket subdomains
- [ ] Nginx reverse proxy with automatic bucket routing
- [ ] CORS configuration per bucket
- [ ] Custom domain support with SSL (Let's Encrypt)
- [ ] Static website hosting toggle in client dashboard

### Phase 2 — Regional Bucket Replication
- [ ] MinIO site replication configuration
- [ ] Region selector in client area
- [ ] Cross-region bucket replication toggle
- [ ] Regional endpoint display in connection details

### Phase 3 — Enhanced File Browser
- [ ] Presigned S3 uploads (replace mc share with AWS SDK)
- [ ] Drag-and-drop with chunked multipart upload for large files
- [ ] File preview (images, text, PDF)
- [ ] Bulk operations (multi-select delete, download as zip)
- [ ] File sharing with expiring links
- [ ] Storage analytics dashboard

## Future Considerations
- Bandwidth overage billing via WHMCS usage billing
- S3 lifecycle policies (auto-expiry, transition to cold storage)
- Bucket access logs
- Webhook notifications for storage events
- API rate limiting per access key
- Terraform/Pulumi provider integration
