#!/bin/bash
# Test Frame Flow Script
# Usage: ./test_frame_flow.sh [code] [session_id]

CODE=${1:-""}
SESSION_ID=${2:-""}

echo "=== ShareFast Frame Flow Test ==="
echo "Code: $CODE"
echo "Session ID: $SESSION_ID"
echo ""

if [ -z "$CODE" ] && [ -z "$SESSION_ID" ]; then
    echo "Usage: $0 <code> [session_id]"
    echo "Example: $0 eagle-hill"
    exit 1
fi

# Test via web API
API_URL="https://sharefast.zip/api/test_frame_flow.php"

if [ -n "$SESSION_ID" ]; then
    URL="${API_URL}?code=${CODE}&session_id=${SESSION_ID}"
else
    URL="${API_URL}?code=${CODE}"
fi

echo "Testing: $URL"
echo ""

# Use curl if available, otherwise use wget
if command -v curl &> /dev/null; then
    curl -s "$URL" | python3 -m json.tool 2>/dev/null || curl -s "$URL"
elif command -v wget &> /dev/null; then
    wget -qO- "$URL" | python3 -m json.tool 2>/dev/null || wget -qO- "$URL"
else
    echo "Error: curl or wget required"
    exit 1
fi

echo ""
echo "=== Test Complete ==="

