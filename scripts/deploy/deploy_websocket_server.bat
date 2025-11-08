@echo off
REM Deploy WebSocket Relay Server to GCP VM (sharefast-websocket)

echo ========================================
echo Deploying WebSocket Relay Server to GCP VM
echo ========================================
echo.

set INSTANCE=sharefast-websocket
set ZONE=us-central1-a
set USER=dash
set REMOTE_DIR=/opt/sharefast-websocket

echo [1/4] Creating remote directory...
gcloud compute ssh %USER%@%INSTANCE% --zone=%ZONE% --command="sudo mkdir -p %REMOTE_DIR% && sudo chown -R %USER%:%USER% %REMOTE_DIR%"
if errorlevel 1 (
    echo [ERROR] Failed to create directory
    pause
    exit /b 1
)

echo.
echo [2/4] Uploading WebSocket server file...
gcloud compute scp scripts/server/websocket_relay_server.js %USER%@%INSTANCE%:%REMOTE_DIR%/ --zone=%ZONE%
if errorlevel 1 (
    echo [ERROR] Failed to upload WebSocket server file
    pause
    exit /b 1
)

echo.
echo [3/4] Setting permissions...
gcloud compute ssh %USER%@%INSTANCE% --zone=%ZONE% --command="sudo chmod +x %REMOTE_DIR%/websocket_relay_server.js"
if errorlevel 1 (
    echo [WARNING] Failed to set execute permissions (may not be needed)
)

echo.
echo [4/4] Restarting WebSocket server...
echo.
echo [INFO] You need to restart the WebSocket server manually:
echo.
echo   Option 1 (PM2):
echo     gcloud compute ssh %USER%@%INSTANCE% --zone=%ZONE%
echo     pm2 restart sharefast-websocket
echo.
echo   Option 2 (systemd):
echo     gcloud compute ssh %USER%@%INSTANCE% --zone=%ZONE%
echo     sudo systemctl restart sharefast-websocket
echo.
echo   Option 3 (Manual):
echo     gcloud compute ssh %USER%@%INSTANCE% --zone=%ZONE%
echo     cd %REMOTE_DIR%
echo     node websocket_relay_server.js
echo.

echo ========================================
echo [SUCCESS] WebSocket server file deployed!
echo ========================================
echo.
echo IMPORTANT: Restart the WebSocket server for changes to take effect!
echo.
pause

