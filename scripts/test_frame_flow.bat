@echo off
REM Test Frame Flow Script for Windows
REM Usage: test_frame_flow.bat [code] [session_id]

set CODE=%1
set SESSION_ID=%2

echo === ShareFast Frame Flow Test ===
echo Code: %CODE%
echo Session ID: %SESSION_ID%
echo.

if "%CODE%"=="" if "%SESSION_ID%"=="" (
    echo Usage: %0 ^<code^> [session_id]
    echo Example: %0 eagle-hill
    exit /b 1
)

set API_URL=https://sharefast.zip/api/test_frame_flow.php

if not "%SESSION_ID%"=="" (
    set URL=%API_URL%?code=%CODE%&session_id=%SESSION_ID%
) else (
    set URL=%API_URL%?code=%CODE%
)

echo Testing: %URL%
echo.

REM Use PowerShell to make HTTP request and format JSON
powershell -Command "try { $response = Invoke-WebRequest -Uri '%URL%' -UseBasicParsing; $json = $response.Content | ConvertFrom-Json; $json | ConvertTo-Json -Depth 10 } catch { Write-Host $_.Exception.Message }"

echo.
echo === Test Complete ===
pause

