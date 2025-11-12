-- FPS Optimization: Critical Database Indexes
-- Run this to dramatically improve relay.php performance (10-50x faster queries)
-- Expected impact: 1-2 second queries → 50-200ms queries

USE lwavhbte_sharefast;

-- 1. Composite index for relay_messages unread lookups (MOST CRITICAL)
-- Optimizes: SELECT ... FROM relay_messages WHERE session_id = ? AND read_at IS NULL ORDER BY created_at ASC
-- This is the most common query in relay.php getRelayData()
SET @index_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = 'lwavhbte_sharefast' 
    AND TABLE_NAME = 'relay_messages' 
    AND INDEX_NAME = 'idx_relay_session_unread'
);

SET @sql = IF(@index_exists = 0, 
    'ALTER TABLE relay_messages ADD INDEX idx_relay_session_unread (session_id, read_at, created_at)',
    'SELECT "Index idx_relay_session_unread already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Composite index for sessions peer_id lookups (CRITICAL)
-- Optimizes: SELECT peer_id FROM sessions WHERE session_id = ? AND peer_id IS NOT NULL
-- This is used in relay.php storeRelayData() for every frame
SET @index_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = 'lwavhbte_sharefast' 
    AND TABLE_NAME = 'sessions' 
    AND INDEX_NAME = 'idx_sessions_peer'
);

SET @sql = IF(@index_exists = 0, 
    'ALTER TABLE sessions ADD INDEX idx_sessions_peer (session_id, peer_id)',
    'SELECT "Index idx_sessions_peer already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Composite index for code-based peer lookups (IMPORTANT)
-- Optimizes: SELECT peer_id FROM sessions WHERE code = ? AND peer_id IS NOT NULL
-- Fallback lookup in relay.php storeRelayData()
SET @index_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = 'lwavhbte_sharefast' 
    AND TABLE_NAME = 'sessions' 
    AND INDEX_NAME = 'idx_sessions_code_peer'
);

SET @sql = IF(@index_exists = 0, 
    'ALTER TABLE sessions ADD INDEX idx_sessions_code_peer (code, peer_id)',
    'SELECT "Index idx_sessions_code_peer already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Composite index for relay_messages ordered retrieval (IMPORTANT)
-- Optimizes: SELECT ... FROM relay_messages WHERE session_id = ? ORDER BY created_at ASC
-- Used for chronological message retrieval
SET @index_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = 'lwavhbte_sharefast' 
    AND TABLE_NAME = 'relay_messages' 
    AND INDEX_NAME = 'idx_relay_session_created'
);

SET @sql = IF(@index_exists = 0, 
    'ALTER TABLE relay_messages ADD INDEX idx_relay_session_created (session_id, created_at, read_at)',
    'SELECT "Index idx_relay_session_created already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Show summary of all indexes
SELECT 'FPS Optimization indexes migration completed!' AS status;
SELECT 
    TABLE_NAME,
    INDEX_NAME, 
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ', ') AS columns
FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_SCHEMA = 'lwavhbte_sharefast'
AND TABLE_NAME IN ('sessions', 'relay_messages')
AND INDEX_NAME IN (
    'idx_relay_session_unread',
    'idx_sessions_peer',
    'idx_sessions_code_peer',
    'idx_relay_session_created',
    'idx_session_read',  -- From previous migration
    'idx_peer_id',       -- From previous migration
    'idx_peer_id_not_null'  -- From previous migration
)
GROUP BY TABLE_NAME, INDEX_NAME
ORDER BY TABLE_NAME, INDEX_NAME;

