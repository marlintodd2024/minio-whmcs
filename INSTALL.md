# ImpulseMinio — Installation Guide

## Prerequisites

- A MinIO server accessible via HTTPS
- `mc` (MinIO Client) binary on the WHMCS server
- WHMCS 8.x+ with PHP 8.1+
- SSL certificates for your MinIO endpoint

---

## 1. MinIO Server Setup

You can run MinIO on any Linux VPS. A dedicated server or a VPS with sufficient storage is recommended.

### Install MinIO

```bash
wget https://dl.min.io/server/minio/release/linux-amd64/minio
chmod +x minio
mv minio /usr/local/bin/

# Create a dedicated user
useradd -r -s /sbin/nologin minio-user
mkdir -p /data/minio
chown minio-user:minio-user /data/minio
```

### Create Systemd Service

```bash
cat > /etc/systemd/system/minio.service << 'EOF'
[Unit]
Description=MinIO Object Storage
After=network.target

[Service]
User=minio-user
Group=minio-user
Environment="MINIO_ROOT_USER=YOUR_ADMIN_USER"
Environment="MINIO_ROOT_PASSWORD=YOUR_STRONG_PASSWORD"
Environment="MINIO_BROWSER_REDIRECT_URL=https://console.yourdomain.com"
ExecStart=/usr/local/bin/minio server /data/minio --console-address ":9001"
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now minio
```

Replace `YOUR_ADMIN_USER` and `YOUR_STRONG_PASSWORD` with secure credentials. These are the credentials you will enter in WHMCS server configuration.

### Verify MinIO is Running

```bash
curl -s http://localhost:9000/minio/health/live
```

Should return `HTTP 200`.

---

## 2. Nginx Reverse Proxy with SSL

MinIO needs two endpoints: the S3 API and (optionally) the web console.

### S3 API Endpoint

```nginx
server {
    listen 443 ssl http2;
    server_name s3.yourdomain.com;

    ssl_certificate /etc/letsencrypt/live/s3.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/s3.yourdomain.com/privkey.pem;

    # Allow large uploads
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
        proxy_send_timeout 300;
    }
}
```

### Console Endpoint (Optional)

```nginx
server {
    listen 443 ssl http2;
    server_name console.yourdomain.com;

    ssl_certificate /etc/letsencrypt/live/console.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/console.yourdomain.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:9001;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        # WebSocket support for console
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}
```

### Obtain SSL Certificates

```bash
certbot --nginx -d s3.yourdomain.com -d console.yourdomain.com
```

---

## 3. Install mc on WHMCS Server

The `mc` (MinIO Client) binary must be installed on the server where WHMCS runs. The module uses it for admin operations (user management, policy creation, etc.).

```bash
wget https://dl.min.io/client/mc/release/linux-amd64/mc
chmod +x mc
mv mc /usr/local/bin/
```

### Configure mc Alias

Run this as the web server user (the user PHP runs as):

```bash
# For Plesk:
sudo -u <webserver-user> mc alias set myminio https://s3.yourdomain.com ADMIN_USER ADMIN_PASSWORD

# For cPanel/DirectAdmin:
sudo -u nobody mc alias set myminio https://s3.yourdomain.com ADMIN_USER ADMIN_PASSWORD
```

The alias name (`myminio`) is what you enter as the server hostname in WHMCS. The module constructs `mc` commands using this alias.

**Important**: The mc config is stored in `~/.mc/config.json` of the web server user. Ensure this user has a writable home directory.

### Verify mc Connection

```bash
sudo -u <webserver-user> mc admin info myminio
```

Should display server information.

---

## 4. Install the Module

Extract the module into your WHMCS installation:

```bash
cd /path/to/whmcs
unzip impulseminio-v2.1.0.zip
chown -R <webserver-user>:<webserver-group> modules/servers/impulseminio/
```

The module auto-creates its database tables (`mod_impulseminio_buckets`, `mod_impulseminio_accesskeys`) on first use.

---

## 5. Configure WHMCS Server

1. Go to **WHMCS Admin → Setup → Products/Services → Servers**
2. Click **Add New Server**
3. Fill in:
   - **Name**: A friendly name (e.g. "MinIO Dallas")
   - **Hostname**: Your MinIO S3 endpoint hostname (e.g. `s3.yourdomain.com`)
   - **Username**: MinIO admin username (from MINIO_ROOT_USER)
   - **Password**: MinIO admin password (from MINIO_ROOT_PASSWORD)
   - **Server Type**: Select **ImpulseMinio**
4. Click **Test Connection** to verify
5. Save

---

## 6. Create a Product

1. Go to **WHMCS Admin → Setup → Products/Services → Products/Services**
2. Click **Create a New Product**
3. Set:
   - **Product Type**: Other
   - **Product Group**: Create or select a group (e.g. "Cloud Storage")
   - **Product Name**: e.g. "ImpulseDrive Starter"
4. On the **Module Settings** tab:
   - **Module Name**: ImpulseMinio
   - **Server**: Select the server you created
   - Configure the 10 options:

| Option | Example Value | Notes |
|--------|--------------|-------|
| Disk Quota (GB) | 50 | Storage limit |
| Bandwidth Limit (GB) | 500 | Monthly transfer |
| Max Buckets | 5 | 0 = unlimited |
| Max Access Keys | 10 | 0 = unlimited |
| Overage Rate | 0.05 | $/GB over quota (future) |
| S3 Endpoint | https://s3.yourdomain.com | Client-facing URL |
| Console URL | https://console.yourdomain.com | Leave blank to hide button |
| mc Binary Path | /usr/local/bin/mc | Full path to mc |
| Bucket Prefix | impulse | Prepended to bucket names |
| Enable Versioning | No | Future feature |

5. Set pricing on the **Pricing** tab
6. Save

---

## 7. Test

1. Create a test client in WHMCS Admin
2. Place a manual order for your storage product
3. Accept the order and mark it paid
4. Verify:
   - MinIO user was created (`mc admin user list myminio`)
   - Primary bucket was created (`mc ls myminio`)
   - Client area shows the 4-tab dashboard with credentials
5. Test from the client dashboard:
   - Create a bucket
   - Create an access key (save the secret!)
   - Delete the access key
   - Delete the bucket

---

## 8. Cron Setup

Usage tracking runs automatically via the WHMCS daily cron. Ensure your WHMCS cron is active:

```bash
# Typical WHMCS cron (every 5 minutes)
*/5 * * * * php /path/to/whmcs/crons/cron.php
```

The `hooks.php` file hooks into `DailyCronJob` and calls `UsageUpdate` for all active ImpulseMinio services.

---

## Troubleshooting

### Test Connection fails

- Verify `mc` is installed and accessible by the web server user
- Check that the mc alias is configured for the correct user: `sudo -u <webserver-user> mc alias list`
- Ensure the MinIO server is reachable from the WHMCS server
- Check firewall rules (port 9000 for API, 9001 for console)

### Client area is blank

- Clear WHMCS template cache: `rm -rf templates_c/*`
- Check PHP error log for syntax errors in impulseminio.php
- Verify the template file exists: `modules/servers/impulseminio/templates/clientarea.tpl`

### Bucket/key creation does nothing

- Ensure `ClientAreaCustomButtonArray` returns the 4 button mappings
- Check that form `a` values match function names (e.g. `clientCreateBucket` not `Create Bucket`)
- Clear template cache after any module file changes

### "Action Failed" errors

- Enable Module Debug Mode: WHMCS Admin → Utilities → Logs → Module Log
- Check the module log for detailed error messages
- Verify MinIO user has admin privileges

### Usage shows 0

- Run the WHMCS cron manually: `php /path/to/whmcs/crons/cron.php`
- Check that the service status is "Active" in WHMCS
- Verify `mc` can query the server: `sudo -u <webserver-user> mc admin info myminio`
