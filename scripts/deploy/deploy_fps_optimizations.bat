@echo off
REM Deploy FPS Optimizations to GCP VM
REM This script deploys database.php and runs the migration

echo ======================================================================
echo FPS Optimizations Deployment
echo ======================================================================
echo.

REM Check if Python is available
python --version >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Python not found! Please install Python 3.
    pause
    exit /b 1
)

REM Run Python deployment script
python scripts\deploy\deploy_fps_optimizations.py

pause

