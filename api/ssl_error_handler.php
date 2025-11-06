<?php
/**
 * SSL Error Handler
 * Returns plain text error messages when SSL cannot be established
 * Messages are limited to 255 characters as per security requirement
 */

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Check if request is HTTPS
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
            $_SERVER['SERVER_PORT'] == 443 ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

if (!$is_https) {
    // Request is not HTTPS - provide clear error message (max 255 chars)
    http_response_code(403);
    echo "SSL required. This endpoint requires HTTPS. Use https://sharefast.zip instead. (255 chars max)";
    exit;
}

// If we reach here, SSL is established but there might be other SSL issues
// Check for SSL certificate errors
if (isset($_SERVER['SSL_CLIENT_VERIFY']) && $_SERVER['SSL_CLIENT_VERIFY'] === 'NONE') {
    http_response_code(403);
    echo "SSL certificate verification failed. Please ensure your connection is secure and try again.";
    exit;
}

// Default success message (if this handler is called but SSL is OK)
http_response_code(200);
echo "SSL connection established successfully.";
?>

