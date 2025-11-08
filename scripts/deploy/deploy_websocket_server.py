#!/usr/bin/env python3
"""
Deploy WebSocket Relay Server to GCP VM (sharefast-websocket)
"""

import os
import subprocess
import sys
from pathlib import Path

# Configuration
INSTANCE_NAME = "sharefast-websocket"
ZONE = "us-central1-a"
REMOTE_USER = os.getenv("GCLOUD_USER", "dash")
REMOTE_DIR = "/opt/sharefast-websocket"

def run_command(cmd, check=True):
    """Run a shell command"""
    print(f"[RUN] {cmd}")
    result = subprocess.run(cmd, shell=True, capture_output=True, text=True)
    if result.returncode != 0 and check:
        print(f"[ERROR] Command failed: {cmd}")
        print(f"Error: {result.stderr}")
        return False
    return result.returncode == 0

def deploy_websocket_server():
    """Deploy WebSocket server file to GCP VM"""
    print("="*70)
    print("Deploying WebSocket Relay Server to GCP VM")
    print("="*70)
    print(f"Instance: {INSTANCE_NAME}")
    print(f"Zone: {ZONE}")
    print(f"Remote directory: {REMOTE_DIR}")
    print()
    
    websocket_file = Path("scripts/server/websocket_relay_server.js")
    if not websocket_file.exists():
        print(f"[ERROR] {websocket_file} not found!")
        print("Make sure you're running from the project root.")
        return False
    
    print("[1/4] Creating remote directory...")
    create_dir_cmd = (
        f"gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} "
        f"--command='sudo mkdir -p {REMOTE_DIR} && sudo chown -R {REMOTE_USER}:{REMOTE_USER} {REMOTE_DIR}'"
    )
    if not run_command(create_dir_cmd, check=False):
        print("[WARNING] Directory creation may have failed (may already exist)")
    
    print()
    print("[2/4] Uploading WebSocket server file...")
    upload_cmd = (
        f"gcloud compute scp {websocket_file} "
        f"{REMOTE_USER}@{INSTANCE_NAME}:{REMOTE_DIR}/ "
        f"--zone={ZONE}"
    )
    if not run_command(upload_cmd):
        print("[ERROR] Failed to upload WebSocket server file")
        return False
    
    print()
    print("[3/4] Setting permissions...")
    chmod_cmd = (
        f"gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} "
        f"--command='sudo chmod +x {REMOTE_DIR}/websocket_relay_server.js'"
    )
    run_command(chmod_cmd, check=False)  # Not critical if it fails
    
    print()
    print("[4/4] Deployment complete!")
    print()
    print("="*70)
    print("IMPORTANT: Restart the WebSocket server for changes to take effect!")
    print("="*70)
    print()
    print("Option 1 (PM2):")
    print(f"  gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE}")
    print("  pm2 restart sharefast-websocket")
    print()
    print("Option 2 (systemd):")
    print(f"  gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE}")
    print("  sudo systemctl restart sharefast-websocket")
    print()
    print("Option 3 (Manual):")
    print(f"  gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE}")
    print(f"  cd {REMOTE_DIR}")
    print("  node websocket_relay_server.js")
    print()
    
    return True

if __name__ == "__main__":
    success = deploy_websocket_server()
    sys.exit(0 if success else 1)

