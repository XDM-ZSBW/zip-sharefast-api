#!/bin/bash
# Setup MySQL for ShareFast on GCP VM
# Creates database and user matching config.php credentials

set -e

DB_NAME="lwavhbte_sharefast"
DB_USER="lwavhbte_sharefast"
# Password should be set via environment variable or config.php
# SECURITY: Never commit passwords to git!
DB_PASS="${DB_PASSWORD:-$(grep "define('DB_PASS'" /var/www/html/config.php 2>/dev/null | sed "s/.*'\(.*\)'.*/\1/" || echo "CHANGE_ME")}"

echo "Setting up MySQL..."

# Start MySQL service
sudo systemctl start mysql
sudo systemctl enable mysql

# Create database and user
sudo mysql << EOF
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF

echo "MySQL database and user created!"

# Import schema
if [ -f "/var/www/html/database_schema.sql" ]; then
    echo "Importing database schema..."
    sudo mysql ${DB_NAME} < /var/www/html/database_schema.sql
    echo "Schema imported!"
else
    echo "Warning: database_schema.sql not found, skipping import"
fi

echo "MySQL setup complete!"

