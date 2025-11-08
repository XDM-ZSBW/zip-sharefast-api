# Frame Flow Test Script

This diagnostic script helps identify why frames aren't flowing between client and admin.

## Usage

### Via Web Browser
```
https://sharefast.zip/api/test_frame_flow.php?code=eagle-hill
https://sharefast.zip/api/test_frame_flow.php?code=eagle-hill&session_id=session_xxx
```

### Via Command Line (Linux/Mac)
```bash
cd scripts
./test_frame_flow.sh eagle-hill
./test_frame_flow.sh eagle-hill session_xxx
```

### Via Command Line (Windows)
```cmd
cd scripts
test_frame_flow.bat eagle-hill
test_frame_flow.bat eagle-hill session_xxx
```

### Via PHP CLI
```bash
php api/test_frame_flow.php eagle-hill
php api/test_frame_flow.php eagle-hill session_xxx
```

## What It Tests

1. **Database Connection** - Verifies database is accessible
2. **Session Found** - Checks if session exists in database
3. **Frame Flow (Client → Admin)** - Counts frames sent by client in last 5 minutes
4. **Input Flow (Admin → Client)** - Counts input events sent by admin
5. **Cursor Flow** - Counts cursor position updates
6. **Signals** - Checks for admin_connected and other signals
7. **Unread Messages** - Detects if admin isn't polling for frames
8. **Session Activity** - Shows recent activity timestamps

## Interpreting Results

### ✅ Pass
- Test completed successfully
- Data is flowing as expected

### ⚠️ Warning
- Test completed but found potential issues
- May indicate timing issues or temporary conditions

### ❌ Fail
- Critical issue detected
- Action required to fix

## Common Issues

### No Frames Found
**Problem:** `Frame Flow (Client → Admin)` shows 0 frames

**Possible Causes:**
- Client hasn't started screen capture
- Client didn't receive `admin_connected` signal
- Relay connection not established
- Client-side error preventing frame sending

**Check:**
- Client logs for `[CRITICAL] send_frame callback INVOKED!`
- Client logs for `[FRAME-SEND] Frame #1:`
- Verify `admin_connected` signal was sent and received

### High Unread Count
**Problem:** `Unread Messages` shows high count (>10)

**Possible Causes:**
- Admin not polling for frames
- WebSocket connection failed, admin using HTTP polling
- Admin viewer not receiving frames

**Check:**
- Admin logs for `[FRAME-HANDLE] Frame #1:`
- Admin WebSocket connection status
- Admin relay polling thread status

### No Signals Found
**Problem:** `Signals` test shows no signals

**Possible Causes:**
- `admin_connected` signal not sent
- Signal polling not working
- Signal expired or already read

**Check:**
- Admin logs for signal sending
- Client logs for signal polling
- Database signals table

## Example Output

```json
{
    "success": true,
    "timestamp": "2025-01-08 11:45:00",
    "code": "eagle-hill",
    "session_id": "session_xxx",
    "estimated_fps": 45.2,
    "tests": [
        {
            "name": "Database Connection",
            "status": "pass",
            "message": "Database connection successful"
        },
        {
            "name": "Frame Flow (Client → Admin)",
            "status": "pass",
            "message": "Found 1356 frames in last 5 minutes",
            "data": {
                "count": 1356,
                "last_frame": "2025-01-08 11:44:58",
                "avg_size": "45.2 KB"
            }
        }
    ],
    "summary": {
        "total_tests": 8,
        "passed": 6,
        "failed": 0,
        "warnings": 2
    }
}
```

## Troubleshooting Steps

1. **Run the test script** with the active code
2. **Check which tests fail** - this indicates where the problem is
3. **Review client logs** for frame sending activity
4. **Review admin logs** for frame receiving activity
5. **Check WebSocket status** on both sides
6. **Verify signals** are being sent and received

## Integration with Monitoring

This script can be called periodically to monitor frame flow health:

```bash
# Monitor every 30 seconds
watch -n 30 'curl -s "https://sharefast.zip/api/test_frame_flow.php?code=eagle-hill" | jq'
```

