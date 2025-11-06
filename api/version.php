<?php
/**
 * Version API Endpoint
 * Returns the current deployed version of ShareFast
 * Automatically syncs with latest GitHub release version
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Cache-Control: no-cache, no-store, must-revalidate'); // Prevent caching
header('Pragma: no-cache');
header('Expires: 0');

// GitHub repository configuration
$github_repo = 'XDM-ZSBW/zip-sharefast-app';
$github_api_url = "https://api.github.com/repos/{$github_repo}/releases/latest";

// Cache file for GitHub version (1 hour cache)
$cache_file = __DIR__ . '/version_cache.json';
$cache_duration = 3600; // 1 hour in seconds

// Default version (fallback)
$default_version = '1.1.0';

/**
 * Fetch latest version from GitHub API
 */
function fetchGitHubVersion($api_url, $cache_file, $cache_duration) {
    // Check cache first
    if (file_exists($cache_file)) {
        $cache_data = json_decode(file_get_contents($cache_file), true);
        if ($cache_data && isset($cache_data['version']) && isset($cache_data['timestamp'])) {
            $age = time() - $cache_data['timestamp'];
            if ($age < $cache_duration) {
                return [
                    'version' => $cache_data['version'],
                    'published_at' => $cache_data['published_at'] ?? null,
                    'source' => 'github_cache'
                ];
            }
        }
    }
    
    // Fetch from GitHub API
    // Try curl first, fallback to file_get_contents
    $response = null;
    $http_code = 0;
    
    if (function_exists('curl_init')) {
        // Use curl if available
        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: ShareFast-Version-Checker',
            'Accept: application/vnd.github.v3+json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        // Fallback to file_get_contents with stream context
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: ShareFast-Version-Checker',
                    'Accept: application/vnd.github.v3+json'
                ],
                'timeout' => 5,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ]);
        
        $response = @file_get_contents($api_url, false, $context);
        if ($response !== false) {
            // Extract HTTP status code from response headers
            $http_code = 200; // Assume success if no error
            if (isset($http_response_header)) {
                foreach ($http_response_header as $header) {
                    if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $header, $matches)) {
                        $http_code = (int)$matches[1];
                        break;
                    }
                }
            }
        }
    }
    
    if ($http_code === 200 && $response) {
        $data = json_decode($response, true);
        if ($data && isset($data['tag_name'])) {
            // Extract version from tag (remove 'v' prefix if present)
            $version = preg_replace('/^v/i', '', $data['tag_name']);
            
            // Save to cache
            $cache_data = [
                'version' => $version,
                'published_at' => $data['published_at'] ?? null,
                'timestamp' => time()
            ];
            @file_put_contents($cache_file, json_encode($cache_data));
            
            return [
                'version' => $version,
                'published_at' => $data['published_at'] ?? null,
                'source' => 'github_api'
            ];
        }
    }
    
    return null;
}

// Priority 1: Try to fetch from GitHub (with caching)
$github_version = fetchGitHubVersion($github_api_url, $cache_file, $cache_duration);
if ($github_version) {
    echo json_encode([
        'version' => $github_version['version'],
        'build_date' => $github_version['published_at'],
        'source' => $github_version['source']
    ]);
    exit;
}

// Priority 2: Try to read version from local file
$version_file = __DIR__ . '/../../dist/version.json';  // From /server/api/ to /dist/
if (!file_exists($version_file)) {
    $version_file = __DIR__ . '/../dist/version.json';  // Fallback to /server/dist/
}
if (!file_exists($version_file)) {
    $version_file = '/var/www/html/dist/version.json';  // Absolute path fallback
}

if (file_exists($version_file)) {
    $version_data = json_decode(file_get_contents($version_file), true);
    if ($version_data && isset($version_data['version'])) {
        echo json_encode([
            'version' => $version_data['version'],
            'build_date' => $version_data['build_date'] ?? date('Y-m-d H:i:s'),
            'source' => 'file'
        ]);
        exit;
    }
}

// Priority 3: Use default version
echo json_encode([
    'version' => $default_version,
    'build_date' => null,
    'source' => 'default'
]);

