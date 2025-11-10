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
    if result.returncode != 0:
        print(f"[ERROR] Command failed: {cmd}")
        if result.stderr:
            print(f"Error: {result.stderr}")
        if result.stdout:
            print(f"Output: {result.stdout}")
        if check:
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
    
    # Check if we're in the right directory
    api_dir = Path("api")
    if not api_dir.exists():
        print("[ERROR] api/ directory not found!")
        print("Make sure you're running from the project root (zip-sharefast-api).")
        return False
    
    # Files to deploy
    files_to_deploy = [
        # Root files (if they exist)
        ("config.php", "config.php"),
        ("database.php", "database.php"),
        (".htaccess", ".htaccess"),
        
        # API directory files
        ("api/register.php", "api/register.php"),
        ("api/validate.php", "api/validate.php"),
        ("api/signal.php", "api/signal.php"),
        ("api/poll.php", "api/poll.php"),
        ("api/disconnect.php", "api/disconnect.php"),
        ("api/relay.php", "api/relay.php"),  # OPTIMIZED VERSION
        ("api/relay_hybrid.php", "api/relay_hybrid.php"),
        ("api/keepalive.php", "api/keepalive.php"),
        ("api/list_clients.php", "api/list_clients.php"),
        ("api/admin_auth.php", "api/admin_auth.php"),
        ("api/admin_codes.php", "api/admin_codes.php"),
        ("api/admin_manage.php", "api/admin_manage.php"),
        ("api/reconnect.php", "api/reconnect.php"),
        ("api/status.php", "api/status.php"),
        ("api/version.php", "api/version.php"),
        ("api/rate_limit.php", "api/rate_limit.php"),
        ("api/ssl_error_handler.php", "api/ssl_error_handler.php"),
        ("api/test_frame_flow.php", "api/test_frame_flow.php"),
        ("api/test_signals.php", "api/test_signals.php"),
        ("api/test_signal_routing.php", "api/test_signal_routing.php"),
        ("api/debug_signals.php", "api/debug_signals.php"),
        ("api/diagnostic_dashboard.php", "api/diagnostic_dashboard.php"),
        ("api/generate_test_session.php", "api/generate_test_session.php"),
        ("api/terminate_session.php", "api/terminate_session.php"),
        
        # Other root files
        ("index.html", "index.html"),
    ]
    
    print(f"[1/{len(files_to_deploy)+3}] Creating remote directories...")
    
    # Create directories on remote VM
    # Use double quotes for Windows PowerShell compatibility
    commands = [
        f'gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} --command="sudo mkdir -p {REMOTE_BASE_DIR}/api"',
        f'gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} --command="sudo mkdir -p {REMOTE_BASE_DIR}/storage"',
        f'gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} --command="sudo chown -R www-data:www-data {REMOTE_BASE_DIR}"',
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
        
        # Upload via gcloud scp to /tmp first (user has write permissions there)
        # Then move to final location with sudo
        # Convert Windows paths to forward slashes for gcloud
        local_file_str = str(local_file).replace('\\', '/')
        remote_full_path = f"{REMOTE_BASE_DIR}/{remote_path}"
        temp_path = f"/tmp/{Path(remote_path).name}"
        
        # Step 1: Upload to /tmp
        upload_cmd = (
            f"gcloud compute scp {local_file_str} "
            f"{REMOTE_USER}@{INSTANCE_NAME}:{temp_path} "
            f"--zone={ZONE}"
        )
        
        if run_command(upload_cmd, check=False):
            # Step 2: Move from /tmp to final location with sudo and set permissions
            # Create parent directory if needed
            parent_dir = str(Path(remote_path).parent)
            if parent_dir and parent_dir != '.':
                mkdir_cmd = (
                    f'gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} '
                    f'--command="sudo mkdir -p {REMOTE_BASE_DIR}/{parent_dir}"'
                )
                run_command(mkdir_cmd, check=False)
            
            # Move file and set permissions
            move_cmd = (
                f'gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} '
                f'--command="sudo mv {temp_path} {remote_full_path} && sudo chown www-data:www-data {remote_full_path} && sudo chmod 644 {remote_full_path}"'
            )
            if run_command(move_cmd, check=False):
                print(f"[{idx}/{len(files_to_deploy)+3}] [OK] {remote_path}")
                uploaded += 1
            else:
                print(f"[{idx}/{len(files_to_deploy)+3}] [FAILED] Move {remote_path}")
                failed += 1
        else:
            print(f"[{idx}/{len(files_to_deploy)+3}] [FAILED] Upload {remote_path}")
            failed += 1
    
    print()
    print(f"[{len(files_to_deploy)+3}/{len(files_to_deploy)+3}] Setting storage permissions...")
    # Use double quotes for Windows PowerShell compatibility
    storage_cmd = (
        f'gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} '
        f'--command="sudo chown -R www-data:www-data {REMOTE_BASE_DIR}/storage && sudo chmod -R 755 {REMOTE_BASE_DIR}/storage"'
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


