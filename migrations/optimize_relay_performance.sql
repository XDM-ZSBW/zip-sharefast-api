-- Performance optimizations for relay.php
-- Run this to improve relay performance

USE lwavhbte_sharefast;

-- 1. Add missing index on peer_id (critical for peer lookups)
-- Check if index exists first (MySQL doesn't support IF NOT EXISTS for indexes)
SET @index_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = 'lwavhbte_sharefast' 
    AND TABLE_NAME = 'sessions' 
    AND INDEX_NAME = 'idx_peer_id'
);

SET @sql = IF(@index_exists = 0, 
    'ALTER TABLE sessions ADD INDEX idx_peer_id (peer_id)',
    'SELECT "Index idx_peer_id already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Add composite index for the common query pattern: session_id + read_at
-- This speeds up: SELECT * FROM relay_messages WHERE session_id = ? AND read_at IS NULL
SET @index_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = 'lwavhbte_sharefast' 
    AND TABLE_NAME = 'relay_messages' 
    AND INDEX_NAME = 'idx_session_read'
);

SET @sql = IF(@index_exists = 0, 
    'ALTER TABLE relay_messages ADD INDEX idx_session_read (session_id, read_at)',
    'SELECT "Index idx_session_read already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Add composite index for peer lookup optimization
SET @index_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = 'lwavhbte_sharefast' 
    AND TABLE_NAME = 'sessions' 
    AND INDEX_NAME = 'idx_peer_id_not_null'
);

SET @sql = IF(@index_exists = 0, 
    'ALTER TABLE sessions ADD INDEX idx_peer_id_not_null (peer_id, session_id, code)',
    'SELECT "Index idx_peer_id_not_null already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Show summary
SELECT 'Migration completed successfully!' AS status;
SELECT INDEX_NAME, COLUMN_NAME 
FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_SCHEMA = 'lwavhbte_sharefast' 
AND TABLE_NAME IN ('sessions', 'relay_messages')
AND INDEX_NAME IN ('idx_peer_id', 'idx_session_read', 'idx_peer_id_not_null')
ORDER BY TABLE_NAME, INDEX_NAME;

