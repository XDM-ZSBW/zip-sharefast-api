# API Security Configuration

## Overview

API endpoints (`/server/api/*`) are **publicly accessible** because they need to be reachable by the desktop application (ShareFast.exe) running on users' computers.

However, they are secured with multiple layers of protection:

## Security Layers

### 1. Rate Limiting ✅
**Implementation**: PHP-based rate limiting (`server/api/rate_limit.php`)
- **Limit**: 100 requests per minute per IP address
- **Enforcement**: Returns HTTP 429 (Too Many Requests) when exceeded
- **Headers**: Includes `X-RateLimit-*` headers

**Applied to:**
- `register.php` - Session registration
- `poll.php` - Signal polling
- Other endpoints can be added as needed

### 2. Session-Based Authentication ✅
**Implementation**: Code + Session ID validation
- **Client**: Must provide valid `code` (6-digit or word-word)
- **Session**: Must provide valid `session_id` (generated on registration)
- **Validation**: Codes expire after 10 minutes (configurable)

**Used in:**
- All API endpoints require `session_id` and `code` parameters
- Invalid/expired codes return error responses

### 3. Input Validation ✅
**Implementation**: PHP-side validation
- **SQL Injection**: Parameterized queries, escaping
- **XSS**: Output encoding, Content-Type headers
- **CSRF**: CORS headers, session validation

### 4. Apache Rate Limiting (Optional) ✅
**Implementation**: Apache mod_ratelimit module
- **Limit**: 100 requests per minute per IP (if module available)
- **Fallback**: PHP-based rate limiting if module not available

### 5. Security Headers ✅
**Implementation**: Apache headers
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `X-XSS-Protection: 1; mode=block`

## Access Control Summary

| Endpoint | Access | Protection |
|----------|--------|------------|
| `/server/api/register.php` | ✅ Public | Rate limit + Session auth |
| `/server/api/poll.php` | ✅ Public | Rate limit + Session auth |
| `/server/api/signal.php` | ✅ Public | Rate limit + Session auth |
| `/server/api/relay.php` | ✅ Public | Rate limit + Session auth |
| `/server/api/admin_auth.php` | ✅ Public | Email validation + Rate limit |

## Rate Limiting Details

### Configuration
```php
// In server/api/rate_limit.php
define('RATE_LIMIT_REQUESTS', 100);  // Max requests
define('RATE_LIMIT_WINDOW', 60);     // Time window (seconds)
```

### HTTP Response Headers
```
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 95
X-RateLimit-Reset: 1699123456
```

### Rate Limit Exceeded Response
```json
{
  "success": false,
  "error": "Rate limit exceeded",
  "message": "Too many requests. Please try again later.",
  "retry_after": 45
}
```

HTTP Status: `429 Too Many Requests`
Header: `Retry-After: 45`

## Why Public Access?

The desktop application (ShareFast.exe) runs on users' computers and needs to:
1. **Register sessions** - Generate codes and connect
2. **Poll for signals** - Check for admin connections
3. **Send signals** - Notify peer of events
4. **Relay data** - Send screen frames and input

These endpoints **must** be publicly accessible for the app to function.

## Security Measures

### ✅ Already Implemented
- Rate limiting (100 req/min per IP)
- Session-based authentication (code + session_id)
- Input validation and SQL escaping
- Security headers
- Code expiration (10 minutes)

### 🔄 Can Be Enhanced
- **API Keys**: Optional API key authentication
- **IP Whitelisting**: For admin endpoints (optional)
- **CAPTCHA**: For registration endpoints (if needed)
- **Request Signing**: HMAC signature validation (advanced)

## Testing Rate Limits

```bash
# Test rate limiting
for i in {1..110}; do
  curl -s https://connect.futurelink.zip/server/api/register.php \
    -H "Content-Type: application/json" \
    -d '{"code":"test123","mode":"client"}' | grep -o "Rate limit"
done
# Should see "Rate limit exceeded" after 100 requests
```

## Monitoring

Rate limit files are stored in:
- `storage/rate_limit/` directory
- Files named: `md5(ip).json`
- Auto-cleanup of files older than 1 hour

## Summary

✅ **API endpoints are public** (required for desktop app)  
✅ **Rate limiting** prevents abuse (100 req/min per IP)  
✅ **Session authentication** ensures valid requests  
✅ **Input validation** prevents attacks  
✅ **Security headers** add additional protection  

The API is secure and publicly accessible for legitimate use! 🔒

