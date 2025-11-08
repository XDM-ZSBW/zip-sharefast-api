@echo off
REM Curl test command for test_frame_flow.php
REM Usage: test_curl_command.bat [code] [session_id]

set CODE=%1
if "%CODE%"=="" set CODE=eagle-hill
set SESSION_ID=%2

echo === Testing test_frame_flow.php ===
echo Code: %CODE%
if not "%SESSION_ID%"=="" (
    echo Session ID: %SESSION_ID%
    set URL=https://sharefast.zip/api/test_frame_flow.php?code=%CODE%&session_id=%SESSION_ID%
) else (
    set URL=https://sharefast.zip/api/test_frame_flow.php?code=%CODE%
)

echo URL: %URL%
echo.

REM Test with curl
where curl >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    echo Testing with curl...
    curl -s "%URL%"
    if %ERRORLEVEL% EQU 0 (
        echo.
        echo [SUCCESS] Test completed successfully
    ) else (
        echo.
        echo [ERROR] Test failed
    )
) else (
    REM Try PowerShell instead
    echo Testing with PowerShell...
    powershell -Command "try { $response = Invoke-WebRequest -Uri '%URL%' -UseBasicParsing; $json = $response.Content | ConvertFrom-Json; $json | ConvertTo-Json -Depth 10 } catch { Write-Host $_.Exception.Message; Write-Host $_.Exception.Response.StatusCode }"
)

echo.
pause

