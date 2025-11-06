<?php
/**
 * Automated Build Trigger Endpoint
 * 
 * POST /api/build.php?token=SECRET_TOKEN
 * 
 * Triggers server-side build of ShareFast executable
 * 
 * Security: Requires secret token in query parameter
 */

// Configuration
// SECURITY: Store token in environment variable or config.php, never commit to git!
$SECRET_TOKEN = getenv('BUILD_SECRET_TOKEN') ?: 'CHANGE_THIS_TO_SECRET_TOKEN';
$BUILD_SCRIPT = '/var/www/html/src/build.sh';
$LOG_FILE = '/var/log/sharefast-build.log';

// Get token from query parameter
$token = $_GET['token'] ?? '';

// Validate token
if ($token !== $SECRET_TOKEN) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized - Invalid token'
    ]);
    exit;
}

// Check if build script exists
if (!file_exists($BUILD_SCRIPT)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Build script not found',
        'path' => $BUILD_SCRIPT
    ]);
    exit;
}

// Check if script is executable
if (!is_executable($BUILD_SCRIPT)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Build script is not executable'
    ]);
    exit;
}

// Execute build script
$output = [];
$return_var = 0;

// Change to script directory
$script_dir = dirname($BUILD_SCRIPT);
chdir($script_dir);

// Execute build
exec("bash $BUILD_SCRIPT 2>&1", $output, $return_var);

// Log result
$log_entry = date('Y-m-d H:i:s') . " - Build triggered via API\n";
$log_entry .= "Return code: $return_var\n";
$log_entry .= "Output:\n" . implode("\n", $output) . "\n\n";
file_put_contents($LOG_FILE, $log_entry, FILE_APPEND);

// Return result
header('Content-Type: application/json');
echo json_encode([
    'success' => $return_var === 0,
    'return_code' => $return_var,
    'output' => $output,
    'timestamp' => date('c')
]);

