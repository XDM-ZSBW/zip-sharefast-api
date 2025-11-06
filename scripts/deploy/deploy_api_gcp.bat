@echo off
REM Deploy ShareFast API files to GCP VM (sharefast-websocket)

echo ========================================
echo Deploying ShareFast API to GCP VM
echo ========================================
echo.

set INSTANCE=sharefast-websocket
set ZONE=us-central1-a
set USER=dash
set REMOTE_DIR=/var/www/html

echo [1/6] Creating remote directories...
gcloud compute ssh %USER%@%INSTANCE% --zone=%ZONE% --command="sudo mkdir -p %REMOTE_DIR%/api && sudo mkdir -p %REMOTE_DIR%/storage && sudo chown -R www-data:www-data %REMOTE_DIR%"
if errorlevel 1 (
    echo [ERROR] Failed to create directories
    pause
    exit /b 1
)

echo.
echo [2/6] Uploading root files...
gcloud compute scp server/config.php server/database.php server/.htaccess %USER%@%INSTANCE%:/tmp/ --zone=%ZONE%
if errorlevel 1 (
    echo [ERROR] Failed to upload root files
    pause
    exit /b 1
)

gcloud compute ssh %USER%@%INSTANCE% --zone=%ZONE% --command="sudo mv /tmp/config.php /tmp/database.php /tmp/.htaccess %REMOTE_DIR%/ && sudo chown www-data:www-data %REMOTE_DIR%/*.php %REMOTE_DIR%/.htaccess && sudo chmod 644 %REMOTE_DIR%/*.php %REMOTE_DIR%/.htaccess"

echo.
echo [3/6] Uploading core API files...
gcloud compute scp server/api/register.php server/api/validate.php server/api/signal.php server/api/poll.php server/api/disconnect.php %USER%@%INSTANCE%:/tmp/ --zone=%ZONE%
if errorlevel 1 (
    echo [ERROR] Failed to upload core API files
    pause
    exit /b 1
)

gcloud compute ssh %USER%@%INSTANCE% --zone=%ZONE% --command="sudo mv /tmp/register.php /tmp/validate.php /tmp/signal.php /tmp/poll.php /tmp/disconnect.php %REMOTE_DIR%/api/ && sudo chown www-data:www-data %REMOTE_DIR%/api/*.php && sudo chmod 644 %REMOTE_DIR%/api/*.php"

echo.
echo [4/6] Uploading additional API files...
gcloud compute scp server/api/keepalive.php server/api/list_clients.php server/api/admin_auth.php server/api/admin_codes.php server/api/admin_manage.php server/api/reconnect.php server/api/status.php server/api/version.php %USER%@%INSTANCE%:/tmp/ --zone=%ZONE%
if errorlevel 1 (
    echo [ERROR] Failed to upload additional API files
    pause
    exit /b 1
)

gcloud compute ssh %USER%@%INSTANCE% --zone=%ZONE% --command="sudo mv /tmp/keepalive.php /tmp/list_clients.php /tmp/admin_auth.php /tmp/admin_codes.php /tmp/admin_manage.php /tmp/reconnect.php /tmp/status.php /tmp/version.php %REMOTE_DIR%/api/ && sudo chown www-data:www-data %REMOTE_DIR%/api/*.php && sudo chmod 644 %REMOTE_DIR%/api/*.php"

echo.
echo [5/6] Uploading remaining files...
gcloud compute scp server/api/relay.php server/api/relay_hybrid.php server/api/rate_limit.php server/api/ssl_error_handler.php server/index.html %USER%@%INSTANCE%:/tmp/ --zone=%ZONE%
if errorlevel 1 (
    echo [ERROR] Failed to upload remaining files
    pause
    exit /b 1
)

gcloud compute ssh %USER%@%INSTANCE% --zone=%ZONE% --command="sudo mv /tmp/relay.php /tmp/relay_hybrid.php /tmp/rate_limit.php /tmp/ssl_error_handler.php %REMOTE_DIR%/api/ && sudo mv /tmp/index.html %REMOTE_DIR%/ && sudo chown www-data:www-data %REMOTE_DIR%/api/*.php %REMOTE_DIR%/index.html && sudo chmod 644 %REMOTE_DIR%/api/*.php %REMOTE_DIR%/index.html"

echo.
echo [6/6] Setting permissions and restarting Apache...
gcloud compute ssh %USER%@%INSTANCE% --zone=%ZONE% --command="sudo chown -R www-data:www-data %REMOTE_DIR%/storage && sudo chmod -R 755 %REMOTE_DIR%/storage && sudo systemctl restart apache2"

echo.
echo ========================================
echo [SUCCESS] API deployment complete!
echo ========================================
echo.
echo Test the API:
echo   curl https://sharefast.zip/api/status.php
echo.
pause

