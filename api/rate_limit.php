<?php
/**
 * Rate Limiting Helper for API Endpoints
 * 
 * Prevents abuse by limiting requests per IP address
 * Usage: Call rateLimit() at the start of each API endpoint
 */

require_once __DIR__ . '/../config.php';

// Rate limiting configuration
define('RATE_LIMIT_ENABLED', true);
define('RATE_LIMIT_REQUESTS', 100);  // Max requests per window
define('RATE_LIMIT_WINDOW', 60);     // Time window in seconds (1 minute)
define('RATE_LIMIT_STORAGE', STORAGE_PATH . 'rate_limit/');

// Create rate limit storage directory if it doesn't exist
if (!file_exists(RATE_LIMIT_STORAGE)) {
    @mkdir(RATE_LIMIT_STORAGE, 0755, true);
}

/**
 * Get client IP address
 */
function getClientIP() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    } else {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}

/**
 * Check rate limit for client IP
 * Returns: array('allowed' => bool, 'remaining' => int, 'reset' => int)
 */
function checkRateLimit($client_ip = null) {
    if (!RATE_LIMIT_ENABLED) {
        return ['allowed' => true, 'remaining' => RATE_LIMIT_REQUESTS, 'reset' => time() + RATE_LIMIT_WINDOW];
    }
    
    if (!$client_ip) {
        $client_ip = getClientIP();
    }
    
    // Sanitize IP for filename
    $ip_file = RATE_LIMIT_STORAGE . md5($client_ip) . '.json';
    
    $current_time = time();
    $rate_data = ['count' => 0, 'window_start' => $current_time];
    
    // Load existing rate limit data
    if (file_exists($ip_file)) {
        $data = json_decode(file_get_contents($ip_file), true);
        if ($data && isset($data['window_start'])) {
            // Check if window has expired
            if ($current_time - $data['window_start'] < RATE_LIMIT_WINDOW) {
                // Still in same window
                $rate_data = $data;
            } else {
                // New window
                $rate_data['window_start'] = $current_time;
            }
        }
    }
    
    // Increment request count
    $rate_data['count']++;
    
    // Check if limit exceeded
    $allowed = $rate_data['count'] <= RATE_LIMIT_REQUESTS;
    $remaining = max(0, RATE_LIMIT_REQUESTS - $rate_data['count']);
    $reset_time = $rate_data['window_start'] + RATE_LIMIT_WINDOW;
    
    // Save updated rate limit data
    @file_put_contents($ip_file, json_encode($rate_data), LOCK_EX);
    
    // Clean up old rate limit files (older than 1 hour)
    if (rand(1, 100) === 1) { // 1% chance to cleanup on each request
        cleanupOldRateLimitFiles();
    }
    
    return [
        'allowed' => $allowed,
        'remaining' => $remaining,
        'reset' => $reset_time,
        'limit' => RATE_LIMIT_REQUESTS
    ];
}

/**
 * Clean up old rate limit files
 */
function cleanupOldRateLimitFiles() {
    $files = glob(RATE_LIMIT_STORAGE . '*.json');
    $current_time = time();
    
    foreach ($files as $file) {
        if (filemtime($file) < $current_time - 3600) { // Older than 1 hour
            @unlink($file);
        }
    }
}

/**
 * Enforce rate limit - returns false if rate limit exceeded
 * Sets appropriate HTTP headers
 */
function enforceRateLimit($client_ip = null) {
    $rate_limit = checkRateLimit($client_ip);
    
    // Set rate limit headers
    header('X-RateLimit-Limit: ' . $rate_limit['limit']);
    header('X-RateLimit-Remaining: ' . $rate_limit['remaining']);
    header('X-RateLimit-Reset: ' . $rate_limit['reset']);
    
    if (!$rate_limit['allowed']) {
        http_response_code(429); // Too Many Requests
        header('Retry-After: ' . ($rate_limit['reset'] - time()));
        echo json_encode([
            'success' => false,
            'error' => 'Rate limit exceeded',
            'message' => 'Too many requests. Please try again later.',
            'retry_after' => $rate_limit['reset'] - time()
        ]);
        return false;
    }
    
    return true;
}

