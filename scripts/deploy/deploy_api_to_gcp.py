#!/usr/bin/env python3
"""
Deploy ShareFast PHP API files to GCP VM (sharefast-websocket)
Uses gcloud compute scp to upload files
"""

import os
import subprocess
import sys
from pathlib import Path

# Configuration
INSTANCE_NAME = "sharefast-websocket"
ZONE = "us-central1-a"
REMOTE_USER = os.getenv("GCLOUD_USER", "dash")  # Default user
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

def deploy_files():
    """Deploy all server files to GCP VM"""
    print("="*70)
    print("Deploying ShareFast API to GCP VM")
    print("="*70)
    print(f"Instance: {INSTANCE_NAME}")
    print(f"Zone: {ZONE}")
    print(f"Remote directory: {REMOTE_BASE_DIR}")
    print()
    
    server_dir = Path("server")
    if not server_dir.exists():
        print("[ERROR] server/ directory not found!")
        print("Make sure you're running from the project root.")
        return False
    
    # Files to deploy
    files_to_deploy = [
        # Root files
        ("server/config.php", "config.php"),
        ("server/database.php", "database.php"),
        ("server/.htaccess", ".htaccess"),
        
        # API directory files
        ("server/api/register.php", "api/register.php"),
        ("server/api/validate.php", "api/validate.php"),
        ("server/api/signal.php", "api/signal.php"),
        ("server/api/poll.php", "api/poll.php"),
        ("server/api/disconnect.php", "api/disconnect.php"),
        ("server/api/relay.php", "api/relay.php"),  # OPTIMIZED VERSION
        ("server/api/relay_hybrid.php", "api/relay_hybrid.php"),
        ("server/api/keepalive.php", "api/keepalive.php"),
        ("server/api/list_clients.php", "api/list_clients.php"),
        ("server/api/admin_auth.php", "api/admin_auth.php"),
        ("server/api/admin_codes.php", "api/admin_codes.php"),
        ("server/api/admin_manage.php", "api/admin_manage.php"),
        ("server/api/reconnect.php", "api/reconnect.php"),
        ("server/api/status.php", "api/status.php"),
        ("server/api/version.php", "api/version.php"),
        ("server/api/rate_limit.php", "api/rate_limit.php"),
        ("server/api/ssl_error_handler.php", "api/ssl_error_handler.php"),
        
        # Migration script for database optimizations
        ("server/apply_relay_optimization.php", "apply_relay_optimization.php"),
        
        # Other root files
        ("server/index.html", "index.html"),
        ("server/init_admin.php", "init_admin.php"),
    ]
    
    print(f"[1/{len(files_to_deploy)+3}] Creating remote directories...")
    
    # Create directories on remote VM
    commands = [
        f"gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} --command='sudo mkdir -p {REMOTE_BASE_DIR}/api'",
        f"gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} --command='sudo mkdir -p {REMOTE_BASE_DIR}/storage'",
        f"gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} --command='sudo chown -R www-data:www-data {REMOTE_BASE_DIR}'",
    ]
    
    for cmd in commands:
        if not run_command(cmd, check=False):
            print(f"[WARNING] Directory creation command may have failed (may already exist)")
    
    print()
    
    # Upload files
    uploaded = 0
    failed = 0
    
    for idx, (local_path, remote_path) in enumerate(files_to_deploy, start=2):
        local_file = Path(local_path)
        if not local_file.exists():
            print(f"[{idx}/{len(files_to_deploy)+3}] [SKIP] {local_path} (not found)")
            failed += 1
            continue
        
        # Upload via gcloud scp
        remote_full_path = f"{REMOTE_BASE_DIR}/{remote_path}"
        upload_cmd = (
            f"gcloud compute scp {local_file} "
            f"{REMOTE_USER}@{INSTANCE_NAME}:{remote_full_path} "
            f"--zone={ZONE}"
        )
        
        if run_command(upload_cmd, check=False):
            print(f"[{idx}/{len(files_to_deploy)+3}] [OK] {remote_path}")
            uploaded += 1
        else:
            print(f"[{idx}/{len(files_to_deploy)+3}] [FAILED] {remote_path}")
            failed += 1
        
        # Set ownership and permissions
        chown_cmd = (
            f"gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} "
            f"--command='sudo chown www-data:www-data {remote_full_path} && sudo chmod 644 {remote_full_path}'"
        )
        run_command(chown_cmd, check=False)
    
    print()
    print(f"[{len(files_to_deploy)+3}/{len(files_to_deploy)+3}] Setting storage permissions...")
    storage_cmd = (
        f"gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} "
        f"--command='sudo chown -R www-data:www-data {REMOTE_BASE_DIR}/storage && sudo chmod -R 755 {REMOTE_BASE_DIR}/storage'"
    )
    run_command(storage_cmd, check=False)
    
    print()
    print("="*70)
    print("Deployment Summary")
    print("="*70)
    print(f"Uploaded: {uploaded} files")
    print(f"Failed: {failed} files")
    print()
    
    if failed == 0:
        print("[SUCCESS] All files deployed successfully!")
        print()
        print("Next steps:")
        print("1. Verify Apache is running: sudo systemctl status apache2")
        print("2. Test API: curl https://sharefast.zip/api/status.php")
        print("3. Check Apache logs if issues: sudo tail -f /var/log/apache2/error.log")
        return True
    else:
        print("[WARNING] Some files failed to upload. Check errors above.")
        return False

if __name__ == "__main__":
    success = deploy_files()
    sys.exit(0 if success else 1)


