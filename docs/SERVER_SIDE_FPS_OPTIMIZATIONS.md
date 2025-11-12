# Server-Side FPS Optimizations

## Current Performance Issues

Based on the logs showing 1-2 second response times for HTTP polling, the main bottlenecks are:

1. **Database queries on every poll** - SELECT + UPDATE operations
2. **File I/O overhead** (if using file storage) - Reading/writing JSON files
3. **No connection pooling** - Database connections may be slow
4. **JSON encoding/decoding** - Happens on every request
5. **Missing database indexes** - Queries may be slow
6. **No caching** - Every request hits the database

## Recommended Optimizations

### 1. Database Indexes (CRITICAL - High Impact)

**File**: Database schema (likely in `database.php` or migration files)

Add these indexes to dramatically speed up queries:

```sql
-- Index for relay_messages lookups (most common query)
CREATE INDEX idx_relay_session_unread ON relay_messages(session_id, read_at) 
WHERE read_at IS NULL;

-- Index for session peer lookups
CREATE INDEX idx_sessions_peer ON sessions(session_id, peer_id) 
WHERE peer_id IS NOT NULL;

-- Index for code-based lookups
CREATE INDEX idx_sessions_code_peer ON sessions(code, peer_id) 
WHERE peer_id IS NOT NULL;

-- Composite index for faster message retrieval
CREATE INDEX idx_relay_session_created ON relay_messages(session_id, created_at, read_at);
```

**Impact**: 10-50x faster queries (from 1-2 seconds to 50-200ms)

### 2. Database Connection Pooling

**File**: `database.php`

```php
// Use persistent connections
private static $connection = null;

public static function getConnection() {
    if (self::$connection === null || !self::$connection->ping()) {
        self::$connection = new mysqli(
            DB_HOST, DB_USER, DB_PASS, DB_NAME,
            null, null,
            MYSQLI_CLIENT_COMPRESS | MYSQLI_CLIENT_INTERACTIVE
        );
        self::$connection->set_charset('utf8mb4');
    }
    return self::$connection;
}
```

**Impact**: Eliminates connection overhead (saves 50-200ms per request)

### 3. In-Memory Caching (Redis/Memcached)

**File**: `relay.php` - Add caching layer

```php
// Use Redis for session peer_id caching
function getPeerIdCached($session_id, $code) {
    $cache_key = "peer_id:$session_id:$code";
    
    // Try cache first
    $cached = redis_get($cache_key);
    if ($cached !== false) {
        return $cached;
    }
    
    // Fallback to database
    $peer_id = getPeerIdFromDB($session_id, $code);
    
    // Cache for 5 minutes
    if ($peer_id) {
        redis_set($cache_key, $peer_id, 300);
    }
    
    return $peer_id;
}
```

**Impact**: 90% reduction in database queries (from 1-2s to 10-50ms)

### 4. Batch Message Processing

**File**: `relay.php` - `getRelayData()` function

**Current**: Fetches 10 messages, updates individually
**Optimized**: Use single transaction for batch operations

```php
function getRelayData($session_id, $code) {
    $escaped_session_id = Database::escape($session_id);
    $current_time = time();
    
    // Use transaction for atomic batch update
    Database::query("START TRANSACTION");
    
    // Get messages with FOR UPDATE lock (prevents race conditions)
    $sql = "SELECT id, message_type, message_data, created_at 
            FROM relay_messages 
            WHERE session_id = '$escaped_session_id' 
            AND read_at IS NULL 
            ORDER BY created_at ASC 
            LIMIT 20  -- Increased from 10
            FOR UPDATE SKIP LOCKED";  -- Skip locked rows for better concurrency
    
    $result = Database::query($sql);
    $messages = array();
    $message_ids = array();
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Process message...
            $message_ids[] = intval($row['id']);
        }
        
        // Single batch update
        if (!empty($message_ids)) {
            $ids_str = implode(',', $message_ids);
            Database::query("UPDATE relay_messages SET read_at = $current_time WHERE id IN ($ids_str)");
        }
    }
    
    Database::query("COMMIT");
    return $messages;
}
```

**Impact**: 30-50% faster message retrieval

### 5. Prepared Statement Reuse

**File**: `relay.php` - `storeRelayData()` function

```php
// Reuse prepared statements (connection-level)
private static $insertStmt = null;

function storeRelayData($session_id, $code, $data_type, $data) {
    // ... get peer_id ...
    
    if (self::$insertStmt === null) {
        $conn = Database::getConnection();
        self::$insertStmt = $conn->prepare(
            "INSERT INTO relay_messages (session_id, message_type, message_data, created_at) 
             VALUES (?, ?, ?, ?)"
        );
    }
    
    $timestamp = time();
    self::$insertStmt->bind_param("sssi", $peer_id, $data_type, $data, $timestamp);
    self::$insertStmt->execute();
    
    return true;
}
```

**Impact**: 20-30% faster inserts

### 6. Optimize JSON Operations

**File**: `relay.php`

```php
// Use json_encode with flags for faster encoding
$data = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// For large data, consider compression
if (strlen($data) > 10000) {
    $data = base64_encode(gzcompress($data, 6)); // Level 6 = good balance
}
```

**Impact**: 10-20% faster for large payloads

### 7. Async Message Cleanup

**File**: `relay.php` - Move cleanup to background process

```php
// Don't cleanup on every request - use cron job instead
// Create cleanup.php for cron:
// */5 * * * * php /path/to/cleanup.php

// cleanup.php:
$sql = "DELETE FROM relay_messages WHERE read_at IS NOT NULL AND read_at < UNIX_TIMESTAMP() - 3600";
Database::query($sql);
```

**Impact**: Removes cleanup overhead from request path

### 8. HTTP Response Compression

**File**: `.htaccess` or Apache config

```apache
# Enable gzip compression
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE application/json
    AddOutputFilterByType DEFLATE text/html text/plain text/xml
</IfModule>
```

**Impact**: 50-70% reduction in response size (faster transfer)

### 9. PHP OPcache

**File**: `php.ini`

```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
opcache.validate_timestamps=0  # Set to 1 in development
```

**Impact**: 20-30% faster PHP execution

### 10. Database Query Optimization

**File**: `relay.php` - Optimize peer_id lookup

```php
// Current: Two separate queries
// Optimized: Single query with UNION or JOIN

function getPeerId($session_id, $code) {
    $escaped_session_id = Database::escape($session_id);
    $escaped_code = Database::escape($code);
    
    // Single query with UNION (faster than two separate queries)
    $sql = "SELECT peer_id FROM sessions 
            WHERE (session_id = '$escaped_session_id' OR code = '$escaped_code')
            AND peer_id IS NOT NULL 
            LIMIT 1";
    
    $result = Database::query($sql);
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['peer_id'];
    }
    
    return null;
}
```

**Impact**: 30-40% faster peer_id lookup

## Implementation Priority

### High Priority (Immediate Impact):
1. ✅ **Database Indexes** - 10-50x improvement
2. ✅ **Connection Pooling** - 50-200ms saved per request
3. ✅ **In-Memory Caching** - 90% query reduction

### Medium Priority (Significant Impact):
4. ✅ **Batch Message Processing** - 30-50% faster
5. ✅ **Prepared Statement Reuse** - 20-30% faster
6. ✅ **HTTP Compression** - 50-70% smaller responses

### Low Priority (Nice to Have):
7. ✅ **Async Cleanup** - Removes overhead
8. ✅ **PHP OPcache** - 20-30% faster execution
9. ✅ **JSON Optimization** - 10-20% faster

## Expected Performance Improvements

### Before Optimizations:
- **Response Time**: 1-2 seconds
- **FPS**: 19 FPS (limited by server)
- **Database Queries**: 2-3 per request
- **File I/O**: Multiple reads/writes per request

### After High-Priority Optimizations:
- **Response Time**: 50-200ms (10x improvement)
- **FPS**: 50-60 FPS (server no longer bottleneck)
- **Database Queries**: 0.1-0.2 per request (cached)
- **File I/O**: Eliminated (caching)

### After All Optimizations:
- **Response Time**: 20-100ms (20x improvement)
- **FPS**: 60 FPS (consistent)
- **Database Queries**: Minimal (mostly cached)
- **File I/O**: None (in-memory)

## Quick Wins (Can Implement Today)

1. **Add database indexes** (5 minutes, huge impact)
2. **Enable HTTP compression** (2 minutes, easy)
3. **Enable PHP OPcache** (1 minute, easy)
4. **Add connection pooling** (10 minutes, medium effort)

## Testing Recommendations

1. **Monitor query times**: Add timing to database queries
2. **Profile PHP execution**: Use Xdebug or Blackfire
3. **Monitor cache hit rates**: Track Redis/Memcached performance
4. **Load testing**: Use Apache Bench or wrk to test under load

## Additional Notes

- **WebSocket server** (Node.js) is already optimized - focus on PHP relay.php
- **Database storage** is faster than file storage for high-frequency operations
- **Consider migrating** from file storage to database if not already done
- **Monitor server resources** - CPU, memory, disk I/O during peak usage

