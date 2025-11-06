<?php
/**
 * Validate if a code exists and is available
 */

// Handle CORS and set headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

function validateCode($code) {
    if (STORAGE_METHOD === 'database') {
        $escaped_code = Database::escape($code);
        $current_time = time();
        
        $sql = "SELECT * FROM sessions WHERE code = '$escaped_code' AND expires_at > $current_time LIMIT 1";
        $result = Database::query($sql);
        
        if (!$result || $result->num_rows === 0) {
            return array('success' => true, 'valid' => false, 'message' => 'Code not found');
        }
        
        $session = $result->fetch_assoc();
        
        // Check if already connected
        if ($session['connected']) {
            return array('success' => true, 'valid' => false, 'message' => 'Code already in use');
        }
        
        // Check if it's a client session
        if ($session['mode'] === 'client') {
            return array('success' => true, 'valid' => true, 'message' => 'Code is valid');
        }
        
        return array('success' => true, 'valid' => false, 'message' => 'Invalid code type');
    } elseif (STORAGE_METHOD === 'file') {
        $file = STORAGE_PATH . $code . '.json';
        
        if (!file_exists($file)) {
            return ['success' => true, 'valid' => false, 'message' => 'Code not found'];
        }
        
        $session = json_decode(file_get_contents($file), true);
        
        // Check if expired
        if (isset($session['expires_at']) && $session['expires_at'] < time()) {
            // Clean up expired session
            @unlink($file);
            return ['success' => true, 'valid' => false, 'message' => 'Code expired'];
        }
        
        // Check if already connected
        if (isset($session['connected']) && $session['connected']) {
            return ['success' => true, 'valid' => false, 'message' => 'Code already in use'];
        }
        
        // Check if it's a client session
        if (isset($session['mode']) && $session['mode'] === 'client') {
            return ['success' => true, 'valid' => true, 'message' => 'Code is valid'];
        }
        
        return ['success' => true, 'valid' => false, 'message' => 'Invalid code type'];
    }
    
    return ['success' => false, 'valid' => false, 'message' => 'Storage method not configured'];
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['code'])) {
    echo json_encode(['success' => false, 'valid' => false, 'message' => 'Missing code']);
    exit;
}

$code = trim($input['code']);
$code = strtolower($code);  // Ensure code is lowercase for validation

// Validate code format: word-word format only (e.g., "happy-cloud")
// Word codes: lowercase letters and hyphens only, 3-15 chars per word, hyphen separator
if (preg_match('/^[a-z]+-[a-z]+$/', $code)) {
    // Word-based code (e.g., "happy-cloud")
    // Validate length: each word should be 3-15 characters
    $parts = explode('-', $code);
    if (count($parts) !== 2 || strlen($parts[0]) < 3 || strlen($parts[0]) > 15 || 
        strlen($parts[1]) < 3 || strlen($parts[1]) > 15) {
        echo json_encode(['success' => false, 'valid' => false, 'message' => 'Invalid code format - each word must be 3-15 characters']);
        exit;
    }
} else {
    echo json_encode(['success' => false, 'valid' => false, 'message' => 'Invalid code format - expected word-word format (e.g., happy-cloud)']);
    exit;
}

$result = validateCode($code);
echo json_encode($result);

?>

