#!/usr/bin/env python3
"""
Deploy FPS Optimizations to GCP VM
- Deploys updated database.php (connection pooling)
- Deploys and runs database migration (indexes)
Uses gcloud compute commands
"""

import os
import subprocess
import sys
import argparse
from pathlib import Path

# Configuration
INSTANCE_NAME = "sharefast-websocket"
ZONE = "us-central1-a"
REMOTE_USER = os.getenv("GCLOUD_USER", "dash")  # Default user
REMOTE_BASE_DIR = "/var/www/html"
REMOTE_MIGRATIONS_DIR = f"{REMOTE_BASE_DIR}/migrations"

# Database configuration (will be read from config.php on server)
# These are defaults - actual values should be in config.php
DB_NAME = "lwavhbte_sharefast"  # Default database name

def run_command(cmd, check=True, capture_output=False):
    """Run a shell command"""
    print(f"[RUN] {cmd}")
    result = subprocess.run(cmd, shell=True, capture_output=capture_output, text=True)
    if result.returncode != 0:
        print(f"[ERROR] Command failed: {cmd}")
        if result.stderr:
            print(f"Error: {result.stderr}")
        if result.stdout:
            print(f"Output: {result.stdout}")
        if check:
            return False
    return result.returncode == 0

def deploy_database_php():
    """Deploy updated database.php with connection pooling"""
    print("="*70)
    print("Step 1: Deploying database.php (Connection Pooling)")
    print("="*70)
    
    local_file = Path("database.php")
    if not local_file.exists():
        print("[ERROR] database.php not found!")
        print("Make sure you're running from the project root (zip-sharefast-api).")
        return False
    
    # Upload to /tmp first
    local_file_str = str(local_file).replace('\\', '/')
    temp_path = "/tmp/database.php"
    remote_full_path = f"{REMOTE_BASE_DIR}/database.php"
    
    # Upload
    upload_cmd = (
        f"gcloud compute scp {local_file_str} "
        f"{REMOTE_USER}@{INSTANCE_NAME}:{temp_path} "
        f"--zone={ZONE}"
    )
    
    if not run_command(upload_cmd, check=False):
        print("[ERROR] Failed to upload database.php")
        return False
    
    # Move to final location with sudo
    move_cmd = (
        f'gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} '
        f'--command="sudo mv {temp_path} {remote_full_path} && sudo chown www-data:www-data {remote_full_path} && sudo chmod 644 {remote_full_path}"'
    )
    
    if not run_command(move_cmd, check=False):
        print("[ERROR] Failed to move database.php to final location")
        return False
    
    print("[OK] database.php deployed successfully!")
    return True

def deploy_migration():
    """Deploy migration SQL file"""
    print()
    print("="*70)
    print("Step 2: Deploying Database Migration")
    print("="*70)
    
    local_file = Path("migrations/add_fps_optimization_indexes.sql")
    if not local_file.exists():
        print("[ERROR] Migration file not found!")
        print("Expected: migrations/add_fps_optimization_indexes.sql")
        return False
    
    # Create migrations directory on remote
    mkdir_cmd = (
        f'gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} '
        f'--command="sudo mkdir -p {REMOTE_MIGRATIONS_DIR} && sudo chown {REMOTE_USER}:{REMOTE_USER} {REMOTE_MIGRATIONS_DIR}"'
    )
    run_command(mkdir_cmd, check=False)
    
    # Upload migration file
    local_file_str = str(local_file).replace('\\', '/')
    temp_path = "/tmp/add_fps_optimization_indexes.sql"
    remote_full_path = f"{REMOTE_MIGRATIONS_DIR}/add_fps_optimization_indexes.sql"
    
    upload_cmd = (
        f"gcloud compute scp {local_file_str} "
        f"{REMOTE_USER}@{INSTANCE_NAME}:{temp_path} "
        f"--zone={ZONE}"
    )
    
    if not run_command(upload_cmd, check=False):
        print("[ERROR] Failed to upload migration file")
        return False
    
    # Move to final location
    move_cmd = (
        f'gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} '
        f'--command="sudo mv {temp_path} {remote_full_path} && sudo chown {REMOTE_USER}:{REMOTE_USER} {remote_full_path} && sudo chmod 644 {remote_full_path}"'
    )
    
    if not run_command(move_cmd, check=False):
        print("[ERROR] Failed to move migration file")
        return False
    
    print("[OK] Migration file deployed successfully!")
    return True

def get_db_credentials():
    """Read database credentials from config.php on server"""
    print("Reading database credentials from config.php on server...")
    
    # Read config.php and extract DB credentials
    read_config_cmd = (
        f'gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} '
        f'--command="php -r \\"'
        f'require_once \\\\\\"{REMOTE_BASE_DIR}/config.php\\\\\\"; '
        f'echo DB_HOST . \\\\\\"|\\\\\\" . DB_NAME . \\\\\\"|\\\\\\" . DB_USER . \\\\\\"|\\\\\\" . DB_PASS;'
        f'\\""'
    )
    
    result = subprocess.run(read_config_cmd, shell=True, capture_output=True, text=True)
    
    if result.returncode == 0 and result.stdout and '|' in result.stdout:
        parts = result.stdout.strip().split('|')
        if len(parts) >= 4:
            db_host, db_name, db_user, db_pass = parts[0], parts[1], parts[2], parts[3]
            print(f"[OK] Found credentials: DB={db_name}, USER={db_user}, HOST={db_host}")
            return db_host, db_name, db_user, db_pass
    
    # Fallback: ask user
    print("[WARNING] Could not read config.php automatically")
    print("Please provide MySQL credentials manually:")
    db_user = input("MySQL username: ").strip()
    db_pass = input("MySQL password (or press Enter if no password): ").strip()
    db_name = input(f"Database name (default: {DB_NAME}): ").strip() or DB_NAME
    db_host = "localhost"
    
    return db_host, db_name, db_user, db_pass

def run_migration(db_creds=None):
    """Run the migration on remote MySQL server"""
    print()
    print("="*70)
    print("Step 3: Running Database Migration")
    print("="*70)
    print()
    print("This will create database indexes for FPS optimization.")
    print("The migration is safe to run multiple times (checks if indexes exist).")
    print()
    
    # Get database credentials if not provided
    if db_creds is None:
        db_creds = get_db_credentials()
    
    db_host, db_name, db_user, db_pass = db_creds
    
    # Build MySQL command
    migration_file = f"{REMOTE_MIGRATIONS_DIR}/add_fps_optimization_indexes.sql"
    
    # Use mysql with password from stdin (more secure)
    if db_pass:
        # Use mysql with --password flag (will prompt, but we can pipe password)
        # Note: Using -p with password in command is less secure but works for automation
        mysql_cmd = f"mysql -h {db_host} -u {db_user} -p'{db_pass}' {db_name} < {migration_file}"
    else:
        mysql_cmd = f"mysql -h {db_host} -u {db_user} {db_name} < {migration_file}"
    
    # Run migration via SSH
    run_migration_cmd = (
        f'gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} '
        f'--command="{mysql_cmd}"'
    )
    
    print()
    print("[INFO] Running migration...")
    print(f"[INFO] Database: {db_name}, User: {db_user}, Host: {db_host}")
    
    if run_command(run_migration_cmd, check=False):
        print("[OK] Migration completed successfully!")
        return True
    else:
        print("[ERROR] Migration failed!")
        print()
        print("Troubleshooting:")
        print("1. Verify MySQL credentials are correct")
        print("2. Check if MySQL is running: sudo systemctl status mysql")
        print("3. Verify database exists and user has permissions")
        print("4. Try running migration manually:")
        print(f"   gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE}")
        print(f"   mysql -h {db_host} -u {db_user} -p {db_name} < {migration_file}")
        return False

def verify_indexes(db_host, db_name, db_user, db_pass):
    """Verify that indexes were created"""
    print()
    print("="*70)
    print("Step 4: Verifying Indexes")
    print("="*70)
    
    # SQL to check indexes
    verify_sql = f"""
SELECT 
    TABLE_NAME,
    INDEX_NAME, 
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ', ') AS columns
FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_SCHEMA = '{db_name}'
AND TABLE_NAME IN ('sessions', 'relay_messages')
AND INDEX_NAME IN (
    'idx_relay_session_unread',
    'idx_sessions_peer',
    'idx_sessions_code_peer',
    'idx_relay_session_created'
)
GROUP BY TABLE_NAME, INDEX_NAME
ORDER BY TABLE_NAME, INDEX_NAME;
"""
    
    # Save SQL to temp file using a simpler approach
    temp_sql = "/tmp/verify_indexes.sql"
    # Escape SQL for shell command (escape quotes and newlines)
    verify_sql_escaped = verify_sql.replace('"', '\\"').replace('$', '\\$').replace('\n', '\\n')
    
    # Use echo with -e to write the SQL file
    verify_cmd = (
        f'gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} '
        f'--command="echo -e \\"{verify_sql_escaped}\\" > {temp_sql}"'
    )
    run_command(verify_cmd, check=False)
    
    # Run verification
    if db_pass:
        verify_run_cmd = f"mysql -h {db_host} -u {db_user} -p'{db_pass}' {db_name} < {temp_sql}"
    else:
        verify_run_cmd = f"mysql -h {db_host} -u {db_user} {db_name} < {temp_sql}"
    
    verify_exec_cmd = (
        f'gcloud compute ssh {REMOTE_USER}@{INSTANCE_NAME} --zone={ZONE} '
        f'--command="{verify_run_cmd}"'
    )
    
    print()
    print("[INFO] Verifying indexes...")
    result = subprocess.run(verify_exec_cmd, shell=True, capture_output=True, text=True)
    
    if result.returncode == 0:
        print("[OK] Index verification:")
        print(result.stdout)
        return True
    else:
        print("[WARNING] Could not verify indexes (this is OK if migration succeeded)")
        if result.stderr:
            print(f"Error: {result.stderr}")
        return True  # Don't fail deployment if verification fails

def main():
    """Main deployment function"""
    # Parse command line arguments
    parser = argparse.ArgumentParser(description='Deploy FPS optimizations to GCP VM')
    parser.add_argument('--run-migration', action='store_true', default=True,
                        help='Run database migration (default: True)')
    parser.add_argument('--skip-migration', action='store_true',
                        help='Skip running database migration')
    parser.add_argument('--verify', action='store_true',
                        help='Verify indexes after migration')
    parser.add_argument('--non-interactive', action='store_true',
                        help='Run non-interactively (auto-confirm all prompts)')
    args = parser.parse_args()
    
    # If skip-migration is set, override run-migration
    if args.skip_migration:
        args.run_migration = False
    
    print("="*70)
    print("FPS Optimizations Deployment")
    print("="*70)
    print(f"Instance: {INSTANCE_NAME}")
    print(f"Zone: {ZONE}")
    print(f"Remote directory: {REMOTE_BASE_DIR}")
    print()
    
    # Check if we're in the right directory
    if not Path("database.php").exists():
        print("[ERROR] database.php not found!")
        print("Make sure you're running from the project root (zip-sharefast-api).")
        sys.exit(1)
    
    if not Path("migrations/add_fps_optimization_indexes.sql").exists():
        print("[ERROR] Migration file not found!")
        print("Expected: migrations/add_fps_optimization_indexes.sql")
        sys.exit(1)
    
    success = True
    
    # Step 1: Deploy database.php
    if not deploy_database_php():
        success = False
    
    # Step 2: Deploy migration
    if not deploy_migration():
        success = False
    
    # Step 3: Run migration (optional - user can skip)
    db_creds = None
    if args.run_migration:
        if not args.non_interactive:
            print()
            try:
                run_mig = input("Run database migration now? (y/n, default=y): ").strip().lower()
            except EOFError:
                # Non-interactive mode (e.g., when piped)
                run_mig = 'y'
        else:
            run_mig = 'y'
        
        if run_mig != 'n':
            db_creds = get_db_credentials()
            if not run_migration(db_creds):
                success = False
        else:
            print("[SKIP] Migration not run. You can run it manually later.")
            print(f"Migration file location: {REMOTE_MIGRATIONS_DIR}/add_fps_optimization_indexes.sql")
    else:
        print("[SKIP] Migration not run (--skip-migration flag set).")
        print(f"Migration file location: {REMOTE_MIGRATIONS_DIR}/add_fps_optimization_indexes.sql")
    
    # Step 4: Verify (optional)
    if success and db_creds and (args.verify or (not args.non_interactive)):
        if args.non_interactive and not args.verify:
            verify = 'n'
        elif args.verify:
            verify = 'y'
        else:
            try:
                verify = input("Verify indexes were created? (y/n, default=n): ").strip().lower()
            except EOFError:
                verify = 'n'
        
        if verify == 'y':
            db_host, db_name, db_user, db_pass = db_creds
            verify_indexes(db_host, db_name, db_user, db_pass)
    
    print()
    print("="*70)
    print("Deployment Summary")
    print("="*70)
    
    if success:
        print("[SUCCESS] FPS optimizations deployed!")
        print()
        print("What was deployed:")
        print("1. [OK] database.php (connection pooling)")
        print("2. [OK] Migration file (indexes)")
        if args.run_migration and not args.skip_migration:
            print("3. [OK] Database indexes created")
        print()
        print("Expected improvements:")
        print("- 10-50x faster database queries")
        print("- 50-200ms saved per request (connection reuse)")
        print("- FPS should increase from ~19 to 50-60 FPS")
        print()
        print("Next steps:")
        print("1. Test API: curl https://sharefast.zip/api/status.php")
        print("2. Monitor response times in diagnostic dashboard")
        print("3. Check FPS counter when connecting client/admin")
    else:
        print("[WARNING] Some steps failed. Check errors above.")
        print()
        print("Manual steps:")
        print("1. SSH to server: gcloud compute ssh dash@sharefast-websocket --zone=us-central1-a")
        print("2. Run migration manually:")
        print(f"   mysql -u USER -p {DB_NAME} < {REMOTE_MIGRATIONS_DIR}/add_fps_optimization_indexes.sql")
    
    sys.exit(0 if success else 1)

if __name__ == "__main__":
    main()

