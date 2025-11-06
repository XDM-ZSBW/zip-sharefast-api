# SSL/HTTPS Configuration Guide for ShareFast

This guide explains how to configure SSL/HTTPS for ShareFast on your GCP VM.

## Overview

ShareFast now uses **HTTPS only** for all connections:
- PHP API: `https://sharefast.zip`
- WebSocket Relay: `wss://sharefast.zip:8767`

## Prerequisites

1. MySQL database configured with credentials from `config.php`
2. SSL certificate bundle for `*.sharefast.zip`
3. GCP VM running with Apache2 and Node.js

## Step 1: MySQL Configuration

MySQL is already configured with the same credentials as `config.php`:
- Database: `lwavhbte_sharefast`
- User: `lwavhbte_sharefast`
- Password: `YOUR_DATABASE_PASSWORD` (check `config.php` or use environment variable)

To verify:
```bash
gcloud compute ssh sharefast-websocket --zone=us-central1-a --command="mysql -u lwavhbte_sharefast -p lwavhbte_sharefast -e 'SHOW TABLES;'"
```

## Step 2: Upload SSL Certificates

You have an SSL bundle for `*.sharefast.zip`. Upload it to the GCP VM:

### Option A: Using the upload script (recommended)

```bash
# Place your certificate files in the current directory:
# - *.sharefast.zip.crt (or .pem)
# - *.sharefast.zip.key
# - *.sharefast.zip.chain.crt (optional)

# Run the upload script
bash upload_ssl_certs.sh
```

### Option B: Manual upload

```bash
# Upload certificate files
gcloud compute scp your-cert.crt sharefast-websocket:/tmp/sharefast.zip.crt --zone=us-central1-a
gcloud compute scp your-key.key sharefast-websocket:/tmp/sharefast.zip.key --zone=us-central1-a

# Move to SSL directory and set permissions
gcloud compute ssh sharefast-websocket --zone=us-central1-a --command="sudo mkdir -p /etc/apache2/ssl && sudo mv /tmp/sharefast.zip.crt /etc/apache2/ssl/ && sudo mv /tmp/sharefast.zip.key /etc/apache2/ssl/ && sudo chmod 644 /etc/apache2/ssl/sharefast.zip.crt && sudo chmod 600 /etc/apache2/ssl/sharefast.zip.key"
```

## Step 3: Configure Apache for HTTPS

The SSL configuration has already been created. After uploading certificates:

```bash
# Enable SSL site and restart Apache
gcloud compute ssh sharefast-websocket --zone=us-central1-a --command="sudo a2ensite sharefast-ssl && sudo a2dissite 000-default && sudo apache2ctl configtest && sudo systemctl restart apache2"
```

Apache will:
- Listen on port 443 (HTTPS) for `sharefast.zip`
- Redirect HTTP (port 80) to HTTPS automatically
- Use your SSL certificate for encryption

## Step 4: Configure WebSocket Server for WSS

The WebSocket server supports SSL/WSS. After uploading certificates:

```bash
# Upload the updated WebSocket server
gcloud compute scp websocket_relay_server.js sharefast-websocket:/home/dash/sharefast-websocket/ --zone=us-central1-a

# Configure and restart with SSL enabled
gcloud compute ssh sharefast-websocket --zone=us-central1-a --command="bash configure_websocket_ssl.sh"
```

Or manually:
```bash
gcloud compute ssh sharefast-websocket --zone=us-central1-a --command="cd ~/sharefast-websocket && pm2 delete sharefast-websocket && pm2 start websocket_relay_server.js --name sharefast-websocket --update-env --env USE_SSL=true --env SSL_PORT=8767 --env SSL_CERT_PATH=/etc/apache2/ssl/sharefast.zip.crt --env SSL_KEY_PATH=/etc/apache2/ssl/sharefast.zip.key --env PHP_API_URL=https://sharefast.zip/api/ && pm2 save"
```

## Step 5: Update Firewall Rules

Ensure ports 443 (HTTPS) and 8767 (WSS) are open:

```bash
# HTTPS
gcloud compute firewall-rules create allow-https --allow tcp:443 --source-ranges 0.0.0.0/0 --description "Allow HTTPS"

# WSS (WebSocket Secure)
gcloud compute firewall-rules create allow-wss --allow tcp:8767 --source-ranges 0.0.0.0/0 --description "Allow WSS WebSocket"
```

## Step 6: Update Python Client

The `main.py` file has already been updated to use:
- `https://sharefast.zip` for the API
- `wss://sharefast.zip:8767` for WebSocket

No changes needed unless you want to test without SSL first.

## Step 7: Verify Configuration

### Test HTTPS API:
```bash
curl https://sharefast.zip/api/register.php
```

### Test WebSocket (WSS):
```bash
# Using wscat (install: npm install -g wscat)
wscat -c wss://sharefast.zip:8767/?session_id=test&code=test-code&mode=client
```

### Check Apache SSL:
```bash
gcloud compute ssh sharefast-websocket --zone=us-central1-a --command="sudo systemctl status apache2"
```

### Check WebSocket Server:
```bash
gcloud compute ssh sharefast-websocket --zone=us-central1-a --command="pm2 logs sharefast-websocket"
```

## Troubleshooting

### Apache SSL errors:
- Check certificate files exist: `ls -la /etc/apache2/ssl/`
- Verify file permissions: `sudo chmod 644 /etc/apache2/ssl/*.crt && sudo chmod 600 /etc/apache2/ssl/*.key`
- Test Apache config: `sudo apache2ctl configtest`
- Check Apache error log: `sudo tail -f /var/log/apache2/error.log`

### WebSocket SSL errors:
- Verify certificates are readable by Node.js user
- Check PM2 logs: `pm2 logs sharefast-websocket`
- Ensure `USE_SSL=true` is set in PM2 environment

### Connection refused:
- Verify firewall rules are created
- Check ports are listening: `sudo netstat -tlnp | grep -E '443|8767'`
- Test from VM: `curl https://localhost/api/register.php`

## Summary

After completing these steps:
- ✅ MySQL configured with `config.php` credentials
- ✅ SSL certificates uploaded and configured
- ✅ Apache serving HTTPS on port 443
- ✅ WebSocket server serving WSS on port 8767
- ✅ Python client using HTTPS/WSS URLs
- ✅ All HTTP traffic redirected to HTTPS

Your ShareFast installation is now fully secured with SSL!

