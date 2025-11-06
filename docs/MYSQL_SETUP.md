# MySQL Database Setup Guide

## Overview

ShareFast now supports MySQL database storage for better scalability and reliability. This guide will help you set up the MySQL database for your ShareFast server.

## Prerequisites

1. MySQL database access (usually via cPanel or phpMyAdmin on HostGator)
2. Database name, username, and password
3. Ability to run SQL scripts

## Step 1: Create Database

1. Log into your HostGator cPanel
2. Navigate to "MySQL Databases"
3. Create a new database named `sharefast` (or your preferred name)
4. Create a MySQL user and grant it full privileges to the database
5. Note down:
   - Database name
   - Database username
   - Database password
   - Database host (usually `localhost`)

## Step 2: Run Database Schema

1. Open phpMyAdmin in cPanel
2. Select your `sharefast` database
3. Go to the "SQL" tab
4. Copy and paste the contents of `server/database_schema.sql`
5. Click "Go" to execute

Alternatively, you can run the SQL file directly:
```bash
mysql -u your_db_user -p sharefast < server/database_schema.sql
```

## Step 3: Update Configuration

Edit `server/config.php` and update these values:

```php
define('DB_HOST', 'localhost');  // Usually 'localhost' on HostGator
define('DB_NAME', 'sharefast');  // Your database name
define('DB_USER', 'your_db_user');  // Your MySQL username
define('DB_PASS', 'your_db_password');  // Your MySQL password

// Change storage method to database
define('STORAGE_METHOD', 'database');
```

## Step 4: Initialize Seed Admin

If you haven't already created the seed admin, you can do it via MySQL:

```sql
INSERT INTO admins (email, admin_code, active, added_at) 
VALUES ('your_email@example.com', '12345678', 1, UNIX_TIMESTAMP());
```

Or use the Python script:
```bash
python init_seed_admin.py your_email@example.com
```

Then manually insert into MySQL (admin codes are stored in the database, not in files).

## Step 5: Upload Files

Upload the updated files to your server:
- `server/config.php` (with MySQL credentials)
- `server/database.php` (new database helper)
- All updated `server/api/*.php` files

## Step 6: Test

1. Test admin authentication via `admin.html`
2. Test client registration
3. Test admin connection to client

## Migration from File Storage

If you're migrating from file-based storage:

1. **Export existing admins** from `server/storage/admins.json`:
   ```sql
   INSERT INTO admins (email, admin_code, active, added_at) 
   VALUES ('email1@example.com', '12345678', 1, UNIX_TIMESTAMP()),
          ('email2@example.com', '87654321', 1, UNIX_TIMESTAMP());
   ```

2. **Active sessions** will need to be re-registered (they're temporary anyway)

3. **Old file storage** can remain as backup until you're confident MySQL is working

## Troubleshooting

### Connection Errors
- Verify database credentials in `config.php`
- Check database host (try `localhost` or IP address)
- Ensure MySQL user has proper permissions

### Table Errors
- Make sure you ran the `database_schema.sql` script
- Check that tables were created: `sessions`, `admins`, `relay_messages`, `signals`, `admin_sessions`

### Performance
- MySQL should be faster than file storage for concurrent connections
- Consider adding indexes if needed (already included in schema)
- Old relay/signal messages are auto-cleaned (keeps last 100 per session)

## Benefits of MySQL

- **Concurrent access**: Multiple connections can read/write simultaneously
- **Better performance**: Indexed queries are faster than file scanning
- **Scalability**: Handles more sessions without file system issues
- **Reliability**: Transaction support and better error handling
- **Maintenance**: Easier to query and manage data

## Reverting to File Storage

If you need to revert back to file storage:

1. Change `STORAGE_METHOD` back to `'file'` in `config.php`
2. Keep MySQL tables intact (they won't interfere)
3. File-based storage will work as before

