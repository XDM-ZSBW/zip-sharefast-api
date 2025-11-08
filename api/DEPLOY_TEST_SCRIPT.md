# Deploy test_frame_flow.php to Server

## Option 1: Manual Upload via SCP

```bash
# From your local machine
scp api/test_frame_flow.php dash@sharefast-websocket:/var/www/html/api/

# Then SSH and set permissions
ssh dash@sharefast-websocket
sudo chown www-data:www-data /var/www/html/api/test_frame_flow.php
sudo chmod 644 /var/www/html/api/test_frame_flow.php
```

## Option 2: Copy File Content Directly

1. SSH into the server:
```bash
ssh dash@sharefast-websocket
```

2. Create the file:
```bash
sudo nano /var/www/html/api/test_frame_flow.php
```

3. Copy the entire content from `api/test_frame_flow.php` and paste it

4. Set permissions:
```bash
sudo chown www-data:www-data /var/www/html/api/test_frame_flow.php
sudo chmod 644 /var/www/html/api/test_frame_flow.php
```

## Option 3: Use gcloud compute scp

```bash
gcloud compute scp api/test_frame_flow.php \
  dash@sharefast-websocket:/var/www/html/api/test_frame_flow.php \
  --zone=us-central1-a

gcloud compute ssh dash@sharefast-websocket --zone=us-central1-a \
  --command="sudo chown www-data:www-data /var/www/html/api/test_frame_flow.php && sudo chmod 644 /var/www/html/api/test_frame_flow.php"
```

## Option 4: Create on Server Directly

If you have access to the server, you can create the file directly:

```bash
ssh dash@sharefast-websocket
cd /var/www/html/api
sudo nano test_frame_flow.php
# Paste the file content
sudo chown www-data:www-data test_frame_flow.php
sudo chmod 644 test_frame_flow.php
```

## Verify Deployment

After deploying, test it:
```bash
curl "https://sharefast.zip/api/test_frame_flow.php?code=eagle-hill"
```

Or open in browser:
```
https://sharefast.zip/api/test_frame_flow.php?code=eagle-hill
```

