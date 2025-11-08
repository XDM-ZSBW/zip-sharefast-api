#!/usr/bin/env python3
"""
Quick deploy script for test_frame_flow.php
Deploys just the test script to GCP VM
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

def run_command(cmd, check=True):
    """Run a shell command"""
    print(f"[RUN] {cmd}")
    result = subprocess.run(cmd, shell=True, capture_output=True, text=True)
    if result.returncode != 0 and check:
        print(f"[ERROR] Command failed: {cmd}")
        print(f"Error: {result.stderr}")
        return False
    return result.returncode == 0

def deploy_test_script():
    """Deploy test_frame_flow.php to GCP VM"""
    print("="*70)
    print("Deploying test_frame_flow.php to GCP VM")
    print("="*70)
    
    # Find the file - check both api/ and server/api/ paths
    script_dir = Path(__file__).parent.parent.parent
    possible_paths = [
        script_dir / "api" / "test_frame_flow.php",
        script_dir / "server" / "api" / "test_frame_flow.php",
    ]
    
    local_file = None
    for path in possible_paths:
        if path.exists():
            local_file = path
            break
    
    if not local_file:
        print("[ERROR] test_frame_flow.php not found!")
        print("Searched in:")
        for path in possible_paths:
            print(f"  - {path}")
        return False
    
    print(f"Found: {local_file}")
    print(f"Instance: {INSTANCE_NAME}")
    print(f"Zone: {ZONE}")
    print()
    
    # Upload file
    remote_path = f"{REMOTE_BASE_DIR}/api/test_frame_flow.php"
    upload_cmd = (
        f"gcloud compute scp {local_file} "
        f"{REMOTE_USER}@{INSTANCE_NAME}:{remote_path} "
        f"--zone={ZONE}"
    )
    
    if not run_command(upload_cmd, check=False):
        print("[ERROR] Failed to upload file")
        return False
    
    # Set permissions
    chown_cmd = (
        f"gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} "
        f"--command='sudo chown www-data:www-data {remote_path} && sudo chmod 644 {remote_path}'"
    )
    run_command(chown_cmd, check=False)
    
    print()
    print("="*70)
    print("[SUCCESS] test_frame_flow.php deployed successfully!")
    print()
    print("Test it at:")
    print("  https://sharefast.zip/api/test_frame_flow.php?code=eagle-hill")
    print()
    return True

if __name__ == "__main__":
    success = deploy_test_script()
    sys.exit(0 if success else 1)

