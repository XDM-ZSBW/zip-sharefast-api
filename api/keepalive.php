<?php
/**
 * Keepalive API - Clients send periodic keepalive to maintain active status
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

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['session_id']) || !isset($input['code'])) {
    echo json_encode(array('success' => false, 'message' => 'Missing session_id or code'));
    exit;
}

$session_id = $input['session_id'];
$code = trim($input['code']);
$code = strtolower($code);  // Ensure code is lowercase to match database storage
$peer_ip = isset($input['peer_ip']) ? $input['peer_ip'] : null;
$peer_port = isset($input['peer_port']) ? intval($input['peer_port']) : null;
$timestamp = time();

if (STORAGE_METHOD === 'database') {
    $escaped_session_id = Database::escape($session_id);
    $escaped_code = Database::escape($code);
    $escaped_expires_at = $timestamp + CODE_EXPIRY;
    
    // Check if session exists
    $check_sql = "SELECT * FROM sessions WHERE session_id = '$escaped_session_id' AND code = '$escaped_code' AND mode = 'client' LIMIT 1";
    $result = Database::query($check_sql);
    
    if ($result && $result->num_rows > 0) {
        // Update keepalive and extend expiry
        $update_fields = array("last_keepalive = $timestamp", "expires_at = $escaped_expires_at");
        
        if ($peer_ip) {
            $escaped_ip = Database::escape($peer_ip);
            $update_fields[] = "ip_address = '$escaped_ip'";
        }
        if ($peer_port) {
            $update_fields[] = "port = $peer_port";
        }
        
        $update_sql = "UPDATE sessions SET " . implode(", ", $update_fields) . " WHERE session_id = '$escaped_session_id'";
        Database::query($update_sql);
        
        echo json_encode(array('success' => true, 'message' => 'Keepalive updated'));
        exit;
    }
    
    echo json_encode(array('success' => false, 'message' => 'Session not found or invalid'));
} elseif (STORAGE_METHOD === 'file') {
    // Try to find session by code first (primary lookup for word-word codes)
    $code_file = STORAGE_PATH . $code . '.json';
    $session_file = STORAGE_PATH . $session_id . '.json';
    
    // Update both files if they exist (code file is primary, session_id file is secondary)
    $updated = false;
    
    if (file_exists($code_file)) {
        $session_data = json_decode(file_get_contents($code_file), true);
        
        if ($session_data && $session_data['mode'] === 'client') {
            // Update last_keepalive timestamp
            $session_data['last_keepalive'] = $timestamp;
            $session_data['expires_at'] = $timestamp + CODE_EXPIRY;  // Extend expiry
            $session_data['active'] = true;
            
            // Update peer info if provided
            if (isset($input['peer_ip'])) {
                $session_data['ip_address'] = $input['peer_ip'];
            }
            if (isset($input['peer_port'])) {
                $session_data['port'] = intval($input['peer_port']);
            }
            
            file_put_contents($code_file, json_encode($session_data));
            $updated = true;
            
            // Also update session_id file if it exists
            if (file_exists($session_file)) {
                file_put_contents($session_file, json_encode($session_data));
            }
        }
    } elseif (file_exists($session_file)) {
        // Fallback: try session_id file
        $session_data = json_decode(file_get_contents($session_file), true);
        
        if ($session_data && $session_data['mode'] === 'client') {
            // Update last_keepalive timestamp
            $session_data['last_keepalive'] = $timestamp;
            $session_data['expires_at'] = $timestamp + CODE_EXPIRY;  // Extend expiry
            $session_data['active'] = true;
            
            // Update peer info if provided
            if (isset($input['peer_ip'])) {
                $session_data['ip_address'] = $input['peer_ip'];
            }
            if (isset($input['peer_port'])) {
                $session_data['port'] = intval($input['peer_port']);
            }
            
            file_put_contents($session_file, json_encode($session_data));
            $updated = true;
            
            // Also update code file if code is known
            if (isset($session_data['code'])) {
                $code_file = STORAGE_PATH . $session_data['code'] . '.json';
                file_put_contents($code_file, json_encode($session_data));
            }
        }
    }
    
    if ($updated) {
        echo json_encode([
            'success' => true,
            'message' => 'Keepalive received',
            'active' => true
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Session not found'
        ]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Storage method not configured']);
}

?>

