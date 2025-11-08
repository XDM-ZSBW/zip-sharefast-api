#!/bin/bash
# Curl test command for test_frame_flow.php
# Usage: ./test_curl_command.sh [code] [session_id]

CODE=${1:-"eagle-hill"}
SESSION_ID=${2:-""}

echo "=== Testing test_frame_flow.php ==="
echo "Code: $CODE"
if [ -n "$SESSION_ID" ]; then
    echo "Session ID: $SESSION_ID"
    URL="https://sharefast.zip/api/test_frame_flow.php?code=${CODE}&session_id=${SESSION_ID}"
else
    URL="https://sharefast.zip/api/test_frame_flow.php?code=${CODE}"
fi

echo "URL: $URL"
echo ""

# Test with curl
if command -v curl &> /dev/null; then
    echo "Testing with curl..."
    curl -s "$URL" | python3 -m json.tool 2>/dev/null || curl -s "$URL"
    EXIT_CODE=$?
    
    if [ $EXIT_CODE -eq 0 ]; then
        echo ""
        echo "✅ Test completed successfully"
    else
        echo ""
        echo "❌ Test failed with exit code: $EXIT_CODE"
    fi
else
    echo "Error: curl not found"
    exit 1
fi

