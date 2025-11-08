# Test Commands for test_frame_flow.php

## Quick Test (Once Deployed)

### Using curl:
```bash
curl "https://sharefast.zip/api/test_frame_flow.php?code=eagle-hill"
```

### With JSON formatting:
```bash
curl -s "https://sharefast.zip/api/test_frame_flow.php?code=eagle-hill" | python3 -m json.tool
```

### With session_id:
```bash
curl "https://sharefast.zip/api/test_frame_flow.php?code=eagle-hill&session_id=session_xxx"
```

## Windows PowerShell:
```powershell
Invoke-WebRequest -Uri "https://sharefast.zip/api/test_frame_flow.php?code=eagle-hill" | Select-Object -ExpandProperty Content | ConvertFrom-Json | ConvertTo-Json -Depth 10
```

## Using the Test Scripts:

### Linux/Mac:
```bash
cd scripts
./test_curl_command.sh eagle-hill
```

### Windows:
```cmd
cd scripts
test_curl_command.bat eagle-hill
```

## Expected Output:

```json
{
    "success": true,
    "timestamp": "2025-01-08 12:00:00",
    "code": "eagle-hill",
    "session_id": null,
    "tests": [
        {
            "name": "Database Connection",
            "status": "pass",
            "message": "Database connection successful"
        },
        {
            "name": "Frame Flow (Client → Admin)",
            "status": "pass",
            "message": "Found 1234 frames in last 5 minutes",
            "data": {
                "count": 1234,
                "last_frame": "2025-01-08 11:59:58",
                "avg_size": "45.2 KB"
            }
        }
    ],
    "summary": {
        "total_tests": 8,
        "passed": 6,
        "failed": 0,
        "warnings": 2
    },
    "estimated_fps": 45.2,
    "recommendations": []
}
```

## Troubleshooting:

### If you get 404:
- File not deployed yet - see DEPLOY_TEST_SCRIPT.md

### If you get 500 error:
- Check Apache error logs: `sudo tail -f /var/log/apache2/error.log`
- Verify config.php and database.php exist
- Check database connection settings

### If you get empty response:
- Check PHP error logs
- Verify database is accessible
- Check file permissions (should be 644, owned by www-data)

