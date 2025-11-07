#!/usr/bin/env python3
"""
Deploy ShareFast website files (index.html, admin.html, version.php) to GCP VM
Quick deployment script for website updates
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
    if result.returncode != 0:
        print(f"[ERROR] Command failed with exit code {result.returncode}")
        if result.stderr:
            print(f"Error output: {result.stderr}")
        if result.stdout:
            print(f"Standard output: {result.stdout}")
        if check:
            return False
    return result.returncode == 0

def deploy_website_files():
    """Deploy website files to GCP VM"""
    print("="*70)
    print("Deploying ShareFast Website Files to GCP VM")
    print("="*70)
    print(f"Instance: {INSTANCE_NAME}")
    print(f"Zone: {ZONE}")
    print(f"Remote directory: {REMOTE_BASE_DIR}")
    print()
    
    # Files to deploy (from API repo root)
    files_to_deploy = [
        ("index.html", "index.html"),
        ("admin.html", "admin.html"),
        ("api/version.php", "api/version.php"),
    ]
    
    uploaded = 0
    failed = 0
    
    for idx, (local_path, remote_path) in enumerate(files_to_deploy, start=1):
        local_file = Path(local_path)
        if not local_file.exists():
            print(f"[{idx}/{len(files_to_deploy)}] [SKIP] {local_path} (not found)")
            failed += 1
            continue
        
        # Upload to /tmp first (no permission issues)
        temp_remote = f"/tmp/{Path(remote_path).name}"
        remote_full_path = f"{REMOTE_BASE_DIR}/{remote_path}"
        
        print(f"[{idx}/{len(files_to_deploy)}] Uploading {local_path}...")
        upload_cmd = (
            f"gcloud compute scp {local_file} "
            f"{REMOTE_USER}@{INSTANCE_NAME}:{temp_remote} "
            f"--zone={ZONE}"
        )
        
        if not run_command(upload_cmd, check=False):
            print(f"[{idx}/{len(files_to_deploy)}] [FAILED] {remote_path} (upload failed)")
            failed += 1
            continue
        
        # Move to final location and set permissions with sudo
        move_cmd = (
            f"gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} "
            f'--command="sudo mkdir -p {REMOTE_BASE_DIR}/{Path(remote_path).parent} && sudo mv {temp_remote} {remote_full_path} && sudo chown www-data:www-data {remote_full_path} && sudo chmod 644 {remote_full_path}"'
        )
        
        if run_command(move_cmd, check=False):
            print(f"[{idx}/{len(files_to_deploy)}] [OK] {remote_path}")
            uploaded += 1
        else:
            print(f"[{idx}/{len(files_to_deploy)}] [FAILED] {remote_path} (move/set permissions failed)")
            failed += 1
    
    print()
    print("="*70)
    print("Deployment Summary")
    print("="*70)
    print(f"Uploaded: {uploaded} files")
    print(f"Failed: {failed} files")
    print()
    
    if failed == 0:
        print("[SUCCESS] Website files deployed successfully!")
        print()
        print("Verify deployment:")
        print(f"  curl https://sharefast.zip/api/version.php")
        print(f"  Visit: https://sharefast.zip")
        return True
    else:
        print("[WARNING] Some files failed to upload. Check errors above.")
        return False

if __name__ == "__main__":
    success = deploy_website_files()
    sys.exit(0 if success else 1)

