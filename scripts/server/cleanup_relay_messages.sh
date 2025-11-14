#!/bin/bash
# Cleanup old relay messages from database
# This should be run periodically (e.g., every hour via cron) to prevent table bloat

# Database credentials (read from config.php if available, otherwise use defaults)
DB_USER="${DB_USER:-lwavhbte_sharefast}"
DB_PASS="${DB_PASS:-Jyojk&Fz{(e~}"
DB_NAME="${DB_NAME:-lwavhbte_sharefast}"

# Cleanup messages that were read more than 1 hour ago
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<SQL
-- Delete messages that were read more than 1 hour ago
DELETE FROM relay_messages WHERE read_at IS NOT NULL AND read_at < UNIX_TIMESTAMP() - 3600;

-- Optimize table periodically (run less frequently, e.g., daily)
-- OPTIMIZE TABLE relay_messages;
SQL

echo "Cleanup completed at $(date)"

