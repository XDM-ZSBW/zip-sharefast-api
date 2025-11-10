# ShareFast API Deployment Guide

## Overview

This guide covers multiple deployment methods for the ShareFast API to GCP VM, from most reliable to fastest.

## Deployment Methods

### 1. Git-based Deployment (Recommended) ⭐

**Best for:** Production, reliability, maintainability

**Benefits:**
- Single source of truth (Git repository)
- Automatic file tracking (no manual file lists)
- Cross-platform compatible
- Rollback capability
- Deployment verification
- Version control integration

**Usage:**
```bash
cd zip-sharefast-api
python scripts/deploy/deploy_git_based.py
```

**How it works:**
1. Clones/pulls the repository on the server
2. Syncs files to the web directory
3. Verifies deployment
4. Provides rollback information

**Requirements:**
- Git repository must be accessible from GCP VM
- Public repo or SSH key configured

**Rollback:**
```bash
gcloud compute ssh dash@sharefast-websocket --zone=us-central1-a
cd /opt/sharefast-api
git log --oneline -10  # View recent commits
git checkout <commit-hash>  # Rollback to specific commit
# Then re-run sync script
```

---

### 2. File-by-File Deployment (Current)

**Best for:** Quick updates, specific files only

**Benefits:**
- Works with any file structure
- No Git required
- Can deploy individual files

**Usage:**
```bash
cd zip-sharefast-api
python scripts/deploy/deploy_api_to_gcp.py
```

**Issues:**
- Requires manual file list maintenance
- Slow for many files
- Windows/PowerShell compatibility issues
- No rollback capability

---

### 3. Rsync-based Deployment (Future)

**Best for:** Fast updates, large file sets

**Benefits:**
- Very fast (only syncs changed files)
- Efficient bandwidth usage
- Incremental updates

**Usage:**
```bash
cd zip-sharefast-api
python scripts/deploy/deploy_rsync.py
```

**Requirements:**
- rsync installed on both local and remote
- SSH access configured

---

## Recommended Workflow

### Initial Setup (One-time)

1. **Set up Git repository on server:**
   ```bash
   gcloud compute ssh dash@sharefast-websocket --zone=us-central1-a
   sudo mkdir -p /opt/sharefast-api
   sudo git clone https://github.com/XDM-ZSBW/zip-sharefast-api.git /opt/sharefast-api
   sudo chown -R dash:dash /opt/sharefast-api
   ```

2. **Configure deployment script:**
   - Set `GIT_REPO_URL` environment variable if using private repo
   - Set `GIT_BRANCH` if not using `main`

### Regular Deployments

**For production (recommended):**
```bash
# Make changes and commit
git add .
git commit -m "Update API"
git push origin main

# Deploy
python scripts/deploy/deploy_git_based.py
```

**For quick testing:**
```bash
python scripts/deploy/deploy_api_to_gcp.py
```

---

## Environment Variables

```bash
# Git-based deployment
export GIT_REPO_URL="https://github.com/XDM-ZSBW/zip-sharefast-api.git"
export GIT_BRANCH="main"
export GCLOUD_USER="dash"

# Then run:
python scripts/deploy/deploy_git_based.py
```

---

## Troubleshooting

### Git-based Deployment Issues

**Problem: Repository not accessible**
```bash
# Check if repo is public or SSH key is configured
gcloud compute ssh dash@sharefast-websocket --zone=us-central1-a
cd /opt/sharefast-api
git remote -v
```

**Problem: Permission denied**
```bash
# Fix ownership
gcloud compute ssh dash@sharefast-websocket --zone=us-central1-a
sudo chown -R dash:dash /opt/sharefast-api
```

**Problem: Files not syncing**
```bash
# Manually sync
gcloud compute ssh dash@sharefast-websocket --zone=us-central1-a
cd /opt/sharefast-api
git pull origin main
sudo cp -r api/* /var/www/html/api/
sudo chown -R www-data:www-data /var/www/html
```

### File-by-File Deployment Issues

**Problem: Permission denied**
- Script now uploads to `/tmp` first, then moves with sudo
- This should be resolved

**Problem: Windows path issues**
- Script now converts Windows paths to forward slashes
- Should work on Windows PowerShell

**Problem: Files not found**
- Check that you're running from `zip-sharefast-api` directory
- Verify files exist in the expected locations

---

## CI/CD Integration (Future)

For automated deployments, consider GitHub Actions:

```yaml
name: Deploy to GCP
on:
  push:
    branches: [main]
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - uses: google-github-actions/setup-gcloud@v0
      - run: python scripts/deploy/deploy_git_based.py
```

---

## Best Practices

1. **Always test locally first**
2. **Use Git-based deployment for production**
3. **Keep deployment scripts in version control**
4. **Document any manual steps**
5. **Verify deployment after each update**
6. **Maintain rollback capability**

---

## Quick Reference

| Method | Speed | Reliability | Rollback | Maintenance |
|--------|-------|-------------|----------|-------------|
| Git-based | Medium | ⭐⭐⭐⭐⭐ | Yes | Low |
| File-by-file | Slow | ⭐⭐⭐ | No | High |
| Rsync | Fast | ⭐⭐⭐⭐ | No | Medium |

**Recommendation:** Use Git-based deployment for all production updates.

