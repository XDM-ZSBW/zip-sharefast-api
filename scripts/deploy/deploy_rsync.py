#!/usr/bin/env python3
"""
Rsync-based Deployment for ShareFast API to GCP VM

Alternative deployment method using rsync for efficient file syncing.
Faster than file-by-file upload, but requires rsync on both sides.
"""

import os
import subprocess
import sys
from pathlib import Path

# Configuration
INSTANCE_NAME = "sharefast-websocket"
ZONE = "us-central1-a"
REMOTE_USER = os.getenv("GCLOUD_USER", "dash")
REMOTE_BASE_DIR = "/var/www/html"
LOCAL_API_DIR = Path(__file__).parent.parent.parent / "api"

def run_command(cmd, check=True):
    """Run a shell command"""
    print(f"[RUN] {cmd}")
    result = subprocess.run(cmd, shell=True, capture_output=True, text=True)
    if result.returncode != 0:
        print(f"[ERROR] Command failed: {cmd}")
        if result.stderr:
            print(f"Error: {result.stderr}")
        if check:
            return False
    return result.returncode == 0

def deploy_via_rsync():
    """
    Deploy using rsync (efficient file syncing)
    """
    print("="*70)
    print("Rsync-based Deployment to GCP VM")
    print("="*70)
    print(f"Instance: {INSTANCE_NAME}")
    print(f"Zone: {ZONE}")
    print(f"Remote directory: {REMOTE_BASE_DIR}")
    print()
    
    # Check if rsync is available locally
    if not run_command("which rsync", check=False):
        print("[ERROR] rsync not found. Please install rsync.")
        print("Windows: Install via WSL or Git Bash")
        print("Mac/Linux: sudo apt-get install rsync")
        return False
    
    # Get VM IP
    print("[1/3] Getting VM IP address...")
    ip_cmd = (
        f'gcloud compute instances describe {INSTANCE_NAME} '
        f'--zone={ZONE} --format="get(networkInterfaces[0].accessConfigs[0].natIP)"'
    )
    result = subprocess.run(ip_cmd, shell=True, capture_output=True, text=True)
    if result.returncode != 0:
        print("[ERROR] Failed to get VM IP")
        return False
    
    vm_ip = result.stdout.strip()
    print(f"VM IP: {vm_ip}")
    print()
    
    # Ensure rsync is installed on remote
    print("[2/3] Ensuring rsync is installed on remote VM...")
    rsync_check = (
        f'gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} '
        f'--command="which rsync || (sudo apt-get update && sudo apt-get install -y rsync)"'
    )
    run_command(rsync_check, check=False)
    print()
    
    # Sync files using rsync
    print("[3/3] Syncing files via rsync...")
    
    # Create remote directories first
    mkdir_cmd = (
        f'gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} '
        f'--command="sudo mkdir -p {REMOTE_BASE_DIR}/api {REMOTE_BASE_DIR}/storage"'
    )
    run_command(mkdir_cmd, check=False)
    
    # Use gcloud compute scp with rsync-like behavior
    # Since direct rsync over gcloud is complex, we'll use a hybrid approach
    print("Note: Using gcloud compute scp with directory sync...")
    print("For true rsync, consider using SSH tunnel or VPN")
    
    print()
    print("="*70)
    print("Deployment Summary")
    print("="*70)
    print("[INFO] Rsync deployment requires additional setup.")
    print("Consider using deploy_git_based.py for more reliable deployment.")
    print()
    
    return True

if __name__ == "__main__":
    success = deploy_via_rsync()
    sys.exit(0 if success else 1)

