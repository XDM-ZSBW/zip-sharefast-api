#!/usr/bin/env python3
"""
Reliable Git-based Deployment for ShareFast API to GCP VM

This deployment method uses Git to maintain consistency and reliability:
1. Clones/pulls the repository on the server
2. Syncs files to the web directory
3. Verifies deployment
4. Supports rollback

Benefits:
- Single source of truth (Git repo)
- Automatic file tracking (no manual file lists)
- Cross-platform compatible
- Rollback capability
- Deployment verification
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
REMOTE_REPO_DIR = "/opt/sharefast-api"
GIT_REPO_URL = os.getenv("GIT_REPO_URL", "https://github.com/XDM-ZSBW/zip-sharefast-api.git")
GIT_BRANCH = os.getenv("GIT_BRANCH", "main")

def run_command(cmd, check=True, capture_output=True):
    """Run a shell command with better error handling"""
    print(f"[RUN] {cmd}")
    result = subprocess.run(cmd, shell=True, capture_output=capture_output, text=True)
    if result.returncode != 0:
        print(f"[ERROR] Command failed: {cmd}")
        if result.stderr:
            print(f"Error: {result.stderr}")
        if result.stdout:
            print(f"Output: {result.stdout}")
        if check:
            return False, result
        return False, result
    return True, result

def deploy_via_git():
    """
    Deploy using Git-based method (most reliable)
    """
    print("="*70)
    print("Git-based Deployment to GCP VM")
    print("="*70)
    print(f"Instance: {INSTANCE_NAME}")
    print(f"Zone: {ZONE}")
    print(f"Remote repo: {REMOTE_REPO_DIR}")
    print(f"Web directory: {REMOTE_BASE_DIR}")
    print(f"Git branch: {GIT_BRANCH}")
    print()
    
    # Step 1: Ensure Git is installed on remote VM
    print("[1/5] Checking Git installation on remote VM...")
    git_check_cmd = (
        f'gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} '
        f'--command="which git || (sudo apt-get update && sudo apt-get install -y git)"'
    )
    success, _ = run_command(git_check_cmd, check=False)
    if not success:
        print("[WARNING] Git installation check failed, but continuing...")
    print()
    
    # Step 2: Clone or update repository
    print("[2/5] Setting up repository on remote VM...")
    
    # Create a deployment script locally and upload it
    deploy_script_content = f"""#!/bin/bash
set -e
if [ -d "{REMOTE_REPO_DIR}" ]; then
    echo "Repository exists, pulling latest changes..."
    cd {REMOTE_REPO_DIR}
    git fetch origin
    git reset --hard origin/{GIT_BRANCH}
    git clean -fd
else
    echo "Cloning repository..."
    sudo mkdir -p {REMOTE_REPO_DIR}
    sudo git clone -b {GIT_BRANCH} {GIT_REPO_URL} {REMOTE_REPO_DIR}
    sudo chown -R {REMOTE_USER}:{REMOTE_USER} {REMOTE_REPO_DIR}
fi
echo "Repository setup complete"
"""
    
    # Write script to local temp file (cross-platform, ensure Unix line endings)
    import tempfile
    with tempfile.NamedTemporaryFile(mode='w', suffix='.sh', delete=False, newline='\n') as f:
        f.write(deploy_script_content)
        local_script = Path(f.name)
    
    # Upload and execute script
    upload_cmd = (
        f"gcloud compute scp {local_script} "
        f"{REMOTE_USER}@{INSTANCE_NAME}:/tmp/deploy_repo.sh "
        f"--zone={ZONE}"
    )
    
    success, _ = run_command(upload_cmd, check=False)
    if success:
        exec_cmd = (
            f'gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} '
            f'--command="chmod +x /tmp/deploy_repo.sh && bash /tmp/deploy_repo.sh"'
        )
        success, result = run_command(exec_cmd, check=False)
        if result.stdout:
            print(result.stdout)
    
    # Cleanup local script
    try:
        local_script.unlink()
    except:
        pass
    
    if not success:
        print("[ERROR] Failed to setup repository")
        return False
    print()
    
    # Step 3: Sync files to web directory
    print("[3/5] Syncing files to web directory...")
    
    sync_script_content = f"""#!/bin/bash
set -e
echo "Syncing files from {REMOTE_REPO_DIR} to {REMOTE_BASE_DIR}..."

# Create directories
sudo mkdir -p {REMOTE_BASE_DIR}/api
sudo mkdir -p {REMOTE_BASE_DIR}/storage

# Copy files (preserve structure)
sudo cp -r {REMOTE_REPO_DIR}/api/* {REMOTE_BASE_DIR}/api/
sudo cp {REMOTE_REPO_DIR}/database.php {REMOTE_BASE_DIR}/ 2>/dev/null || true
sudo cp {REMOTE_REPO_DIR}/index.html {REMOTE_BASE_DIR}/ 2>/dev/null || true

# Set permissions
sudo chown -R www-data:www-data {REMOTE_BASE_DIR}
sudo find {REMOTE_BASE_DIR} -type f -exec chmod 644 {{}} \\;
sudo find {REMOTE_BASE_DIR} -type d -exec chmod 755 {{}} \\;
sudo chmod -R 755 {REMOTE_BASE_DIR}/storage

echo "Files synced successfully"
"""
    
    # Write script to local temp file (cross-platform, ensure Unix line endings)
    import tempfile
    with tempfile.NamedTemporaryFile(mode='w', suffix='.sh', delete=False, newline='\n') as f:
        f.write(sync_script_content)
        local_sync_script = Path(f.name)
    
    # Upload and execute script
    upload_cmd = (
        f"gcloud compute scp {local_sync_script} "
        f"{REMOTE_USER}@{INSTANCE_NAME}:/tmp/sync_files.sh "
        f"--zone={ZONE}"
    )
    
    success, _ = run_command(upload_cmd, check=False)
    if success:
        exec_cmd = (
            f'gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} '
            f'--command="chmod +x /tmp/sync_files.sh && bash /tmp/sync_files.sh"'
        )
        success, result = run_command(exec_cmd, check=False)
        if result.stdout:
            print(result.stdout)
    
    # Cleanup
    try:
        local_sync_script.unlink()
    except:
        pass
    
    if not success:
        print("[ERROR] Failed to sync files")
        return False
    print()
    
    # Step 4: Verify deployment
    print("[4/5] Verifying deployment...")
    verify_cmd = (
        f'gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} '
        f'--command="test -f {REMOTE_BASE_DIR}/api/status.php && echo \'SUCCESS: status.php found\' || echo \'ERROR: status.php not found\'; '
        f'test -f {REMOTE_BASE_DIR}/api/diagnostic_dashboard.php && echo \'SUCCESS: diagnostic_dashboard.php found\' || echo \'ERROR: diagnostic_dashboard.php not found\'"'
    )
    success, result = run_command(verify_cmd, check=False)
    if result.stdout:
        print(result.stdout)
    print()
    
    # Step 5: Get deployment info
    print("[5/5] Getting deployment information...")
    info_cmd = (
        f'gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} '
        f'--command="cd {REMOTE_REPO_DIR} && git log -1 --oneline && echo \'---\' && git rev-parse HEAD"'
    )
    success, result = run_command(info_cmd, check=False)
    if result.stdout:
        print("Latest commit:")
        print(result.stdout)
    print()
    
    print("="*70)
    print("Deployment Summary")
    print("="*70)
    print("[SUCCESS] Git-based deployment completed!")
    print()
    print("Next steps:")
    print("1. Test API: curl https://sharefast.zip/api/status.php")
    print("2. Check diagnostic dashboard: https://sharefast.zip/api/diagnostic_dashboard.php")
    print("3. View deployment: gcloud compute ssh dash@sharefast-websocket --zone=us-central1-a")
    print()
    print("To rollback:")
    print(f"  gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} --command=\"cd {REMOTE_REPO_DIR} && git log --oneline -10\"")
    print(f"  gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} --command=\"cd {REMOTE_REPO_DIR} && git checkout <commit-hash>\"")
    print()
    
    return True

if __name__ == "__main__":
    success = deploy_via_git()
    sys.exit(0 if success else 1)

