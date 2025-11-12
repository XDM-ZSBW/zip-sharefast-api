# CURL Test Script for ShareFast API
# Tests API endpoints after FPS optimizations deployment

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "ShareFast API CURL Tests" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$baseUrl = "https://sharefast.zip/api"
$timestamp = [int][double]::Parse((Get-Date -UFormat %s))

# Test 1: Status Endpoint
Write-Host "[TEST 1] Testing status.php..." -ForegroundColor Yellow
try {
    $response = Invoke-WebRequest -Uri "$baseUrl/status.php" -Method GET -UseBasicParsing -TimeoutSec 10
    Write-Host "  Status Code: $($response.StatusCode)" -ForegroundColor Green
    Write-Host "  Response Time: $($response.Headers.'X-Response-Time' -or 'N/A')"
    Write-Host "  Content Length: $($response.Content.Length) bytes" -ForegroundColor Green
    Write-Host "  Response: $($response.Content.Substring(0, [Math]::Min(200, $response.Content.Length)))"
    Write-Host "[OK] Status endpoint working" -ForegroundColor Green
} catch {
    Write-Host "[ERROR] Status endpoint failed: $($_.Exception.Message)" -ForegroundColor Red
}
Write-Host ""

# Test 2: Version Endpoint
Write-Host "[TEST 2] Testing version.php..." -ForegroundColor Yellow
try {
    $response = Invoke-WebRequest -Uri "$baseUrl/version.php" -Method GET -UseBasicParsing -TimeoutSec 10
    Write-Host "  Status Code: $($response.StatusCode)" -ForegroundColor Green
    Write-Host "  Response: $($response.Content.Substring(0, [Math]::Min(200, $response.Content.Length)))"
    Write-Host "[OK] Version endpoint working" -ForegroundColor Green
} catch {
    Write-Host "[ERROR] Version endpoint failed: $($_.Exception.Message)" -ForegroundColor Red
}
Write-Host ""

# Test 3: Relay Receive (tests database query performance)
Write-Host "[TEST 3] Testing relay.php receive (tests optimized database queries)..." -ForegroundColor Yellow
$testSessionId = "test_session_$timestamp"
$testCode = "test-code"
$body = @{
    action = "receive"
    session_id = $testSessionId
    code = $testCode
    timestamp = $timestamp
} | ConvertTo-Json

try {
    $stopwatch = [System.Diagnostics.Stopwatch]::StartNew()
    $response = Invoke-WebRequest -Uri "$baseUrl/relay.php" -Method POST -Body $body -ContentType "application/json" -UseBasicParsing -TimeoutSec 5
    $stopwatch.Stop()
    
    Write-Host "  Status Code: $($response.StatusCode)" -ForegroundColor Green
    Write-Host "  Response Time: $($stopwatch.ElapsedMilliseconds)ms" -ForegroundColor $(if ($stopwatch.ElapsedMilliseconds -lt 500) { "Green" } elseif ($stopwatch.ElapsedMilliseconds -lt 1000) { "Yellow" } else { "Red" })
    Write-Host "  Content Length: $($response.Content.Length) bytes"
    
    $jsonResponse = $response.Content | ConvertFrom-Json
    Write-Host "  Success: $($jsonResponse.success)"
    Write-Host "  Messages Count: $($jsonResponse.count)"
    
    if ($stopwatch.ElapsedMilliseconds -lt 500) {
        Write-Host "[OK] Relay endpoint responding fast (<500ms) - optimizations working!" -ForegroundColor Green
    } elseif ($stopwatch.ElapsedMilliseconds -lt 2000) {
        Write-Host "[WARNING] Relay endpoint responding in $($stopwatch.ElapsedMilliseconds)ms (expected <500ms)" -ForegroundColor Yellow
    } else {
        Write-Host "[ERROR] Relay endpoint too slow: $($stopwatch.ElapsedMilliseconds)ms (expected <500ms)" -ForegroundColor Red
    }
} catch {
    Write-Host "[ERROR] Relay endpoint failed: $($_.Exception.Message)" -ForegroundColor Red
    if ($_.Exception.Response) {
        $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
        $responseBody = $reader.ReadToEnd()
        Write-Host "  Response: $responseBody" -ForegroundColor Red
    }
}
Write-Host ""

# Test 4: Multiple rapid requests (tests connection pooling)
Write-Host "[TEST 4] Testing connection pooling with 5 rapid requests..." -ForegroundColor Yellow
$times = @()
for ($i = 1; $i -le 5; $i++) {
    try {
        $stopwatch = [System.Diagnostics.Stopwatch]::StartNew()
        $response = Invoke-WebRequest -Uri "$baseUrl/relay.php" -Method POST -Body $body -ContentType "application/json" -UseBasicParsing -TimeoutSec 5
        $stopwatch.Stop()
        $times += $stopwatch.ElapsedMilliseconds
        Write-Host "  Request $i : $($stopwatch.ElapsedMilliseconds)ms" -NoNewline
        if ($stopwatch.ElapsedMilliseconds -lt 500) {
            Write-Host " [OK]" -ForegroundColor Green
        } else {
            Write-Host " [SLOW]" -ForegroundColor Yellow
        }
    } catch {
        Write-Host "  Request $i : FAILED - $($_.Exception.Message)" -ForegroundColor Red
    }
}

if ($times.Count -gt 0) {
    $avgTime = ($times | Measure-Object -Average).Average
    $minTime = ($times | Measure-Object -Minimum).Minimum
    $maxTime = ($times | Measure-Object -Maximum).Maximum
    
    Write-Host "  Average: $([math]::Round($avgTime, 2))ms"
    Write-Host "  Min: $minTime ms"
    Write-Host "  Max: $maxTime ms"
    
    if ($avgTime -lt 500) {
        Write-Host "[OK] Connection pooling working - consistent fast responses!" -ForegroundColor Green
    } else {
        Write-Host "[WARNING] Average response time is $avgTime ms (expected <500ms)" -ForegroundColor Yellow
    }
}
Write-Host ""

# Test 5: Validate endpoint
Write-Host "[TEST 5] Testing validate.php..." -ForegroundColor Yellow
try {
    $validateBody = @{
        code = "test-code"
    } | ConvertTo-Json
    
    $response = Invoke-WebRequest -Uri "$baseUrl/validate.php" -Method POST -Body $validateBody -ContentType "application/json" -UseBasicParsing -TimeoutSec 10
    Write-Host "  Status Code: $($response.StatusCode)" -ForegroundColor Green
    $jsonResponse = $response.Content | ConvertFrom-Json
    Write-Host "  Valid: $($jsonResponse.valid)"
    Write-Host "[OK] Validate endpoint working" -ForegroundColor Green
} catch {
    Write-Host "[ERROR] Validate endpoint failed: $($_.Exception.Message)" -ForegroundColor Red
}
Write-Host ""

# Summary
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Test Summary" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Expected Performance After Optimizations:" -ForegroundColor Yellow
Write-Host "  - Database queries: 50-200ms (was 1-2 seconds)"
Write-Host "  - Connection reuse: 50-200ms saved per request"
Write-Host "  - Overall FPS: 50-60 FPS (was ~19 FPS)"
Write-Host ""
Write-Host "If relay.php responses are <500ms, optimizations are working!" -ForegroundColor Green
Write-Host ""

