# Google Cloud Platform Setup Guide

## Prerequisites

1. **Install Google Cloud SDK:**
   - Download: https://cloud.google.com/sdk/docs/install
   - Or use: `curl https://sdk.cloud.google.com | bash`

2. **Authenticate:**
   ```bash
   gcloud auth login
   gcloud auth application-default login
   ```

3. **Create or select project:**
   ```bash
   # Create new project (optional)
   gcloud projects create sharefast-websocket --name="ShareFast WebSocket"
   
   # Set project
   gcloud config set project YOUR_PROJECT_ID
   
   # Enable billing (required for free tier, but you won't be charged for always-free resources)
   # Go to: https://console.cloud.google.com/billing
   ```

## Option 1: Automated Setup (Recommended)

### Step 1: Create VM Instance

```bash
# Run the automated script
bash create_gcp_vm.sh

# Or manually:
gcloud compute instances create sharefast-websocket \
    --zone=us-central1-a \
    --machine-type=e2-micro \
    --image-family=ubuntu-2204-lts \
    --image-project=ubuntu-os-cloud \
    --boot-disk-size=10GB \
    --tags=websocket-server
```

### Step 2: Configure Firewall

```bash
# Create firewall rule to allow WebSocket connections
gcloud compute firewall-rules create allow-websocket-8766 \
    --allow tcp:8766 \
    --source-ranges 0.0.0.0/0 \
    --target-tags websocket-server \
    --description "Allow WebSocket connections for ShareFast"
```

### Step 3: Upload Files

```bash
# From your local machine (Windows)
upload_to_gcp_vm.bat sharefast-websocket us-central1-a

# Or manually:
gcloud compute scp websocket_relay_server.js package.json sharefast-websocket:~/sharefast-websocket/ --zone=us-central1-a
gcloud compute scp setup_websocket_server_gcp.sh sharefast-websocket:~/sharefast-websocket/ --zone=us-central1-a
```

### Step 4: SSH and Setup

```bash
# SSH into VM
gcloud compute ssh sharefast-websocket --zone=us-central1-a

# Run setup script
cd ~/sharefast-websocket
bash setup_websocket_server_gcp.sh
```

## Option 2: Manual CLI Setup

### Step 1: Create VM

```bash
gcloud compute instances create sharefast-websocket \
    --zone=us-central1-a \
    --machine-type=e2-micro \
    --image-family=ubuntu-2204-lts \
    --image-project=ubuntu-os-cloud \
    --boot-disk-size=10GB \
    --tags=websocket-server
```

### Step 2: Configure Firewall

```bash
gcloud compute firewall-rules create allow-websocket-8766 \
    --allow tcp:8766 \
    --source-ranges 0.0.0.0/0 \
    --target-tags websocket-server \
    --description "Allow WebSocket connections"
```

### Step 3: Get VM IP

```bash
VM_IP=$(gcloud compute instances describe sharefast-websocket \
    --zone=us-central1-a \
    --format='get(networkInterfaces[0].accessConfigs[0].natIP)')
echo "VM IP: $VM_IP"
```

### Step 4: SSH and Install

```bash
# SSH into VM
gcloud compute ssh sharefast-websocket --zone=us-central1-a

# On the VM, run:
sudo apt-get update
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt-get install -y nodejs
sudo npm install -g pm2
mkdir -p ~/sharefast-websocket
cd ~/sharefast-websocket

# Upload files from local machine (in new terminal):
# gcloud compute scp websocket_relay_server.js package.json sharefast-websocket:~/sharefast-websocket/ --zone=us-central1-a

# Back on VM:
npm install
mkdir -p storage/relay
PORT=8766 RELAY_STORAGE_PATH=./storage/relay/ PHP_API_URL=https://connect.futurelink.zip/api/ pm2 start websocket_relay_server.js --name sharefast-websocket
pm2 save
pm2 startup | tail -1 | sudo bash
```

## Option 3: One-Line Setup (After Uploading Files)

```bash
# SSH into VM first:
gcloud compute ssh sharefast-websocket --zone=us-central1-a

# Then run:
cd ~/sharefast-websocket && npm install && mkdir -p storage/relay && PORT=8766 RELAY_STORAGE_PATH=./storage/relay/ PHP_API_URL=https://connect.futurelink.zip/api/ pm2 start websocket_relay_server.js --name sharefast-websocket && pm2 save && pm2 startup | tail -1 | sudo bash
```

## Get VM IP Address

```bash
# From local machine:
gcloud compute instances describe sharefast-websocket \
    --zone=us-central1-a \
    --format='get(networkInterfaces[0].accessConfigs[0].natIP)'

# Or from VM itself:
curl -s -H "Metadata-Flavor: Google" http://metadata.google.internal/computeMetadata/v1/instance/network-interfaces/0/access-configs/0/external-ip
```

## Update Python Client

In `main.py`, uncomment and set:
```python
self.websocket_url = "ws://<your-gcp-vm-ip>:8766"
```

## GCP Free Tier Limits

**Always Free (e2-micro):**
- 1 VM instance per month (744 hours)
- 30 GB egress per month
- 1 GB RAM
- Shared CPU

**Cost:** $0/month (within free tier limits)

## Useful Commands

```bash
# View VM status
gcloud compute instances list

# View firewall rules
gcloud compute firewall-rules list

# SSH into VM
gcloud compute ssh sharefast-websocket --zone=us-central1-a

# Stop VM (to save free tier hours)
gcloud compute instances stop sharefast-websocket --zone=us-central1-a

# Start VM
gcloud compute instances start sharefast-websocket --zone=us-central1-a

# Delete VM (when done)
gcloud compute instances delete sharefast-websocket --zone=us-central1-a
```

## Troubleshooting

### Firewall not working:
```bash
# Check firewall rules
gcloud compute firewall-rules list

# Check VM tags
gcloud compute instances describe sharefast-websocket --zone=us-central1-a --format='get(tags.items)'

# Update VM tags if needed
gcloud compute instances add-tags sharefast-websocket --tags=websocket-server --zone=us-central1-a
```

### Can't connect:
```bash
# Check if server is running
gcloud compute ssh sharefast-websocket --zone=us-central1-a --command="pm2 status"

# Check logs
gcloud compute ssh sharefast-websocket --zone=us-central1-a --command="pm2 logs sharefast-websocket"
```

### Port not accessible:
```bash
# Verify firewall rule exists
gcloud compute firewall-rules describe allow-websocket-8766

# Check if port is open from VM
gcloud compute ssh sharefast-websocket --zone=us-central1-a --command="sudo netstat -tlnp | grep 8766"
```

## DNS Configuration (Optional)

After setup, you can configure DNS to point a subdomain to your GCP VM IP:

1. Get VM IP: `gcloud compute instances describe sharefast-websocket --zone=us-central1-a --format='get(networkInterfaces[0].accessConfigs[0].natIP)'`

2. Add DNS A record:
   - Host: `websocket`
   - Type: `A`
   - Value: `<your-gcp-vm-ip>`

3. Update `main.py`:
   ```python
   self.websocket_url = "ws://websocket.connect.futurelink.zip:8766"
   ```

