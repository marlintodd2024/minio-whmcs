# ImpulseMinio — WHMCS MinIO Provisioning Module

## Product Roadmap and Commercialization Plan

---

## Current State (v2.0.3)

### What's Working

- Automated provisioning: Create Account creates MinIO user + bucket + policy
- 4-tab client dashboard: Overview, Buckets, Access Keys, Quick Start
- Bucket management: Create/delete with namespace isolation (username-prefixed)
- Access key management: Create scoped keys (all or single bucket), revoke, secret shown once
- Usage tracking: Disk and bandwidth bars with percentage from MinIO metrics
- Suspend/Unsuspend/Terminate: Full lifecycle with policy disable/enable/cleanup
- Change Password, Quick Start code samples, Admin tools
- CSRF protection on all forms including JS-generated deletes
- Sidebar custom buttons hidden via DOM JS, all CRUD in dashboard tabs
- Lagom2 theme fully compatible

### Architecture

```
impulseminio/
  impulseminio.php          # Main module - all WHMCS hooks + client area HTML
  hooks.php                 # UsageUpdate cron hook
  lib/MinioClient.php       # MinIO S3/admin API wrapper (mc binary + REST)
  templates/clientarea.tpl  # Passthrough: {$moduleOutput nofilter}
  README.md
```

### Config Options (10 fields)

1. Disk Quota (GB) - Storage limit per service
2. Bandwidth Limit (GB) - Monthly transfer limit
3. Max Buckets - 0 = unlimited
4. Max Access Keys - 0 = unlimited
5. Overage Rate ($/GB) - Future: metered billing
6. S3 Endpoint URL - Client-facing endpoint
7. Console URL - MinIO web console link
8. mc Binary Path - Path to MinIO client binary
9. Bucket Prefix - Namespace prefix
10. Enable Versioning - Future: object versioning

### Key Technical Decisions

- PHP string concatenation for all HTML (heredocs break in Lagom2)
- {$moduleOutput nofilter} template passthrough for theme compatibility
- ClientAreaCustomButtonArray kept populated for routing, hidden via DOM JS
- Form "a" values must be function names (array values), not display labels
- CSRF tokens grabbed from DOM for JS-generated forms

---

## Roadmap

### Phase 1: GitHub Release (v2.1.0)

**Goal**: Clean open-source release.

#### Code Cleanup
- Remove debug logModuleCall statements
- Add PHPDoc blocks to all 29 functions
- Add version constant
- Input validation hardening (bucket names, key labels)

#### Documentation
- README.md with feature overview and screenshots
- INSTALL.md: MinIO setup, mc binary, WHMCS server config, product creation, SSL/proxy, cron
- CHANGELOG.md
- LICENSE (GPL v3 for open-source core)

#### Testing
- Full E2E: order, provision, bucket, key, upload, delete, terminate
- Test with standard WHMCS theme (non-Lagom2)
- Test suspend/unsuspend and upgrade/downgrade cycles

### Phase 2: Additional MinIO Features (v2.2.0)

**Goal**: Feature parity with commercial S3 panels.

#### Object Browser
- In-dashboard file browser with list/upload/download/delete
- Presigned URL upload/download
- Create folders (prefix-based)
- File preview for images/PDFs

#### Bucket Features
- Object versioning toggle per bucket
- Lifecycle rules (auto-expire after N days)
- Static website hosting config
- CORS configuration
- Bucket access policy editor (public read, private, etc.)

#### Usage and Billing
- Per-bucket usage breakdown
- Bandwidth metering with overage billing
- Usage history charts (daily snapshots + Chart.js)
- Email alerts at 80/90/100% quota
- WHMCS billable items integration

#### Access Keys
- Permission levels (read-only, read-write, list-only)
- Key expiration dates
- Per-key usage stats

### Phase 3: Commercial Product (v3.0.0)

**Goal**: Sellable WHMCS marketplace module.

#### Licensing System

Options:
- Custom license server (PHP + MySQL)
- Use WHMCS itself as license manager
- Third-party: LicensePal, Starter License

License mechanics:
- Tied to WHMCS domain + IP with grace period for changes
- Tiers: Starter (1 server), Pro (3 servers), Enterprise (unlimited)
- Check on module load, fail gracefully with admin notice
- Annual renewal model

#### ionCube Encoding

Encode:
- impulseminio.php (business logic)
- lib/MinioClient.php (API implementation)
- hooks.php

Leave readable:
- templates/clientarea.tpl (theme customization)
- README.md, INSTALL.md

Requirements:
- ionCube Encoder (commercial license ~$399 for Cerberus edition)
- Target PHP 8.1+ with ionCube Loader
- Alternatives: SourceGuardian, Zend Guard

#### WHMCS Marketplace Submission
- Module documentation, screenshots, support URL
- Pricing: one-time purchase + annual support, or subscription
- Demo/trial: 14-day license
- Support: ticket system or dedicated email

#### Packaging
- Auto-installer hook for DB table creation
- Migration scripts for version upgrades
- Clean uninstaller

### Phase 4: Enterprise Features (v3.x)

- Multi-server provisioning (region-based cluster selection)
- Cross-region bucket replication
- White-label dashboard branding
- Reseller API for sub-provisioning
- Audit logging for all client actions
- Webhook notifications on provision/terminate
- Two-factor auth for access keys

---

## MinIO Features Available to Implement

Currently used: User management, bucket management, access keys, per-user policies, bucket quotas, usage metrics.

Available: Object locking (WORM), bucket versioning, lifecycle management, bucket notifications (webhooks), server-side encryption (SSE-S3/SSE-KMS), site-to-site replication, object tagging, retention policies, Lambda compute triggers, batch operations, storage tiering.

---

## Install and Setup Guide (Draft)

### Prerequisites

1. MinIO Server deployed and accessible via HTTPS
2. mc (MinIO Client) installed on the WHMCS server
3. WHMCS 8.x+ with PHP 8.1+
4. SSL certificate on MinIO endpoint

### Step 1: MinIO Server

```bash
wget https://dl.min.io/server/minio/release/linux-amd64/minio
chmod +x minio && mv minio /usr/local/bin/
mkdir -p /data/minio

# Systemd service
cat > /etc/systemd/system/minio.service << 'SVC'
[Unit]
Description=MinIO Object Storage
After=network.target
[Service]
User=minio-user
Group=minio-user
Environment="MINIO_ROOT_USER=admin"
Environment="MINIO_ROOT_PASSWORD=CHANGE_THIS"
Environment="MINIO_BROWSER_REDIRECT_URL=https://console.yourdomain.com"
ExecStart=/usr/local/bin/minio server /data/minio --console-address ":9001"
Restart=always
[Install]
WantedBy=multi-user.target
SVC

systemctl daemon-reload && systemctl enable --now minio
```

### Step 2: Nginx Reverse Proxy + SSL

```nginx
server {
    listen 443 ssl http2;
    server_name s3.yourdomain.com;
    ssl_certificate /etc/letsencrypt/live/s3.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/s3.yourdomain.com/privkey.pem;
    client_max_body_size 5G;
    proxy_buffering off;
    location / {
        proxy_pass http://127.0.0.1:9000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_connect_timeout 300;
        proxy_read_timeout 300;
    }
}
```

### Step 3: Install mc on WHMCS Server

```bash
wget https://dl.min.io/client/mc/release/linux-amd64/mc
chmod +x mc && mv mc /usr/local/bin/
sudo -u www-data mc alias set myminio https://s3.yourdomain.com admin PASSWORD
```

### Step 4: Install Module

```bash
cd /path/to/whmcs
unzip impulseminio-v2.x.x.zip
chown -R www-data:www-data modules/servers/impulseminio/
```

### Step 5: WHMCS Server Configuration

1. Admin > Setup > Servers > Add New
2. Hostname: s3.yourdomain.com
3. Username: MinIO admin user
4. Password: MinIO admin password
5. Type: ImpulseMinio
6. Test Connection

### Step 6: Create Product

1. Admin > Setup > Products > Create New
2. Type: Other, Module: ImpulseMinio
3. Configure 10 config options on Module Settings tab
4. Set pricing, place test order

### Step 7: Cron

Usage updates run via WHMCS daily cron (hooks.php). Ensure cron is active:
```bash
*/5 * * * * php /path/to/whmcs/crons/cron.php
```

---

## Licensing Strategy Recommendation

**Freemium Open Source (Recommended)**

- Free tier: Core provisioning, bucket/key management, usage tracking
- Pro tier ($99 one-time or $49/year): Object browser, advanced policies, metered billing, priority support
- ionCube encode Pro features, leave core open source
- Builds community, open core serves as marketing
- Hosting providers discover it free, upgrade when they need advanced features

Alternative: Commercial with trial ($79 one-time + $29/year updates, 14-day trial) or WHMCS Marketplace subscription ($9.99/month).
