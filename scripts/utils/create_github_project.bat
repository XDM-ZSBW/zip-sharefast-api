@echo off
REM Create GitHub Project with Roadmap for ShareFast API
if "%GITHUB_TOKEN%"=="" (
    echo ERROR: GITHUB_TOKEN not set
    pause
    exit /b 1
)
python scripts\utils\create_github_project.py
pause

