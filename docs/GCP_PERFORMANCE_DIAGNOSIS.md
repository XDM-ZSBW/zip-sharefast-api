# GCP Server Performance Diagnosis Report

**Date**: November 12, 2025  
**Server**: sharefast-websocket (us-central1-a)  
**Status**: ⚠️ Performance Issues Identified

## Executive Summary

The server is experiencing performance degradation due to:
1. **Database table bloat**: `relay_messages` table was 1.2GB (now cleaned to ~289 messages)
2. **Apache MaxRequestWorkers reached**: Server rejected requests when all 150 workers were busy
3. **High database connection count**: Peak of 152 concurrent connections
4. **WebSocket server instability**: 48 restarts in 4 days

## Critical Issues Found

### 1. Database Table Bloat ⚠️ **FIXED**
- **Issue**: `relay_messages` table was 1.2GB with 3,097 messages (2,808 old read messages)
- **Impact**: Slow queries, high memory usage, database connection exhaustion
- **Action Taken**: Deleted 2,808 old messages (read >1 hour ago)
- **Result**: Table reduced to 289 messages
- **Recommendation**: Set up automated cleanup (see below)

### 2. Apache MaxRequestWorkers Limit ⚠️
- **Issue**: Apache reached MaxRequestWorkers (150) at 23:50:14
- **Error**: `AH00161: server reached MaxRequestWorkers setting, consider raising the MaxRequestWorkers setting`
- **Impact**: New requests rejected, causing client timeouts and connection failures
- **Current Config**:
  - MaxRequestWorkers: 150
  - StartServers: 5
  - MinSpareServers: 5
  - MaxSpareServers: 10
- **Recommendation**: 
  - Increase MaxRequestWorkers to 200-250 (if memory allows)
  - Monitor memory usage (currently 486MB used / 958MB total)

### 3. Database Connection Spikes ⚠️
- **Issue**: Peak of 152 concurrent database connections
- **Current**: 1-2 connections (normal)
- **Impact**: Connection pool exhaustion, slow queries
- **Root Cause**: Connection pooling may not be working correctly, or connections not being closed
- **Recommendation**: 
  - Verify connection pooling in `database.php` is working
  - Check for connection leaks in PHP code
  - Monitor connection count over time

### 4. WebSocket Server Instability ⚠️
- **Issue**: WebSocket server restarted 48 times in 4 days
- **Status**: Currently online, 50.5MB memory usage
- **Impact**: Connection interruptions, client reconnection delays
- **Recommendation**: 
  - Check PM2 logs for crash reasons
  - Monitor memory usage (may be OOM kills)
  - Consider increasing server memory if needed

## System Resources

### Memory
- **Total**: 958MB
- **Used**: 486MB
- **Free**: 76MB
- **Available**: 325MB
- **Swap**: 829MB used / 2GB total
- **Status**: ⚠️ Memory pressure (swap being used)

### CPU
- **Current Load**: 0.32 (1 min), 6.11 (5 min), 5.21 (15 min)
- **Status**: ⚠️ High 5/15 minute averages indicate recent load spikes
- **MySQL CPU**: 6.2% (normal)

### Disk
- **Usage**: 15GB / 49GB (32%)
- **Status**: ✅ Healthy

## Database Status

### Connections
- **Current**: 1-2 threads connected
- **Peak**: 152 connections (problematic)
- **Total Connections**: 175,665 (lifetime)

### Table Sizes (After Cleanup)
- `relay_messages`: ~289 messages (was 3,097)
- `sessions`: 377 sessions
- Other tables: <1MB each

## Recommendations

### Immediate Actions (Completed)
1. ✅ Cleaned up 2,808 old relay messages
2. ✅ Reduced table size from 1.2GB to manageable size

### Short-term Actions (Next 24 hours)
1. **Set up automated cleanup**:
   ```bash
   # Add to crontab (run every hour)
   0 * * * * /opt/sharefast-api/scripts/server/cleanup_relay_messages.sh
   ```

2. **Increase Apache MaxRequestWorkers** (if memory allows):
   ```bash
   sudo nano /etc/apache2/mods-enabled/mpm_prefork.conf
   # Change: MaxRequestWorkers 150 → 200
   sudo systemctl restart apache2
   ```

3. **Monitor WebSocket server**:
   ```bash
   pm2 logs sharefast-websocket --lines 100
   # Look for OOM kills or crash patterns
   ```

### Long-term Actions (Next Week)
1. **Upgrade server memory** (if budget allows):
   - Current: 1GB RAM
   - Recommended: 2GB RAM minimum
   - This will allow more Apache workers and reduce swap usage

2. **Implement connection pool monitoring**:
   - Add logging to track connection pool usage
   - Alert when connections exceed threshold

3. **Optimize relay.php cleanup**:
   - Currently cleanup is disabled (too expensive)
   - Consider batch cleanup every N requests instead of on every insert
   - Or use a background cron job (recommended)

4. **Add database query monitoring**:
   - Enable slow query log
   - Monitor query performance
   - Optimize slow queries

## Cleanup Script

A cleanup script has been created at:
- `scripts/server/cleanup_relay_messages.sh`

To deploy and schedule:
```bash
# Deploy script
gcloud compute scp scripts/server/cleanup_relay_messages.sh dash@sharefast-websocket:/opt/sharefast-api/scripts/server/ --zone=us-central1-a
gcloud compute ssh dash@sharefast-websocket --zone=us-central1-a --command="chmod +x /opt/sharefast-api/scripts/server/cleanup_relay_messages.sh"

# Add to crontab
gcloud compute ssh dash@sharefast-websocket --zone=us-central1-a --command="(crontab -l 2>/dev/null; echo '0 * * * * /opt/sharefast-api/scripts/server/cleanup_relay_messages.sh >> /var/log/relay_cleanup.log 2>&1') | crontab -"
```

## Monitoring Commands

### Check System Resources
```bash
gcloud compute ssh dash@sharefast-websocket --zone=us-central1-a --command="top -bn1 | head -20 && free -h"
```

### Check Database Connections
```bash
gcloud compute ssh dash@sharefast-websocket --zone=us-central1-a --command="mysql -u lwavhbte_sharefast -p'Jyojk&Fz{(e~' lwavhbte_sharefast -e 'SHOW STATUS LIKE \"Threads_connected\"; SHOW STATUS LIKE \"Max_used_connections\";'"
```

### Check Table Size
```bash
gcloud compute ssh dash@sharefast-websocket --zone=us-central1-a --command="mysql -u lwavhbte_sharefast -p'Jyojk&Fz{(e~' lwavhbte_sharefast -e 'SELECT COUNT(*) FROM relay_messages; SELECT table_name, ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb FROM information_schema.TABLES WHERE table_schema = \"lwavhbte_sharefast\" AND table_name = \"relay_messages\";'"
```

### Check Apache Status
```bash
gcloud compute ssh dash@sharefast-websocket --zone=us-central1-a --command="sudo tail -20 /var/log/apache2/error.log"
```

### Check WebSocket Server
```bash
gcloud compute ssh dash@sharefast-websocket --zone=us-central1-a --command="pm2 list && pm2 logs sharefast-websocket --lines 50 --nostream"
```

## Conclusion

The immediate issue (table bloat) has been resolved. However, the server needs:
1. Automated cleanup to prevent future bloat
2. Apache configuration tuning to handle more concurrent requests
3. Memory upgrade consideration for long-term stability
4. WebSocket server stability investigation

The server should perform better now that the database table has been cleaned, but monitoring is essential to prevent recurrence.

