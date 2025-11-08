@echo off
REM Quick deploy script for test_frame_flow.php
REM Deploys just the test script to GCP VM

echo ======================================================================
echo Deploying test_frame_flow.php to GCP VM
echo ======================================================================
echo.

cd /d "%~dp0\..\.."

REM Check if file exists
if exist "api\test_frame_flow.php" (
    set LOCAL_FILE=api\test_frame_flow.php
) else if exist "server\api\test_frame_flow.php" (
    set LOCAL_FILE=server\api\test_frame_flow.php
) else (
    echo [ERROR] test_frame_flow.php not found!
    pause
    exit /b 1
)

echo Found: %LOCAL_FILE%
echo.

REM Deploy using Python script
python scripts\deploy\deploy_test_script.py

if errorlevel 1 (
    echo.
    echo [ERROR] Deployment failed
    pause
    exit /b 1
)

echo.
echo [SUCCESS] Deployment complete!
echo.
echo Test it at:
echo   https://sharefast.zip/api/test_frame_flow.php?code=eagle-hill
echo.
pause

