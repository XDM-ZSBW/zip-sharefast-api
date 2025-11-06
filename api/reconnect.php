<?php
/**
 * Reconnect API - Check if autonomous reconnection is allowed and return peer info
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

if (!isset($input['admin_session_id'])) {
    echo json_encode(array('success' => false, 'message' => 'Missing admin_session_id'));
    exit;
}

$admin_session_id = $input['admin_session_id'];

if (STORAGE_METHOD === 'database') {
    $escaped_admin_session_id = Database::escape($admin_session_id);
    $current_time = time();
    
    // Check admin_sessions table
    $admin_sql = "SELECT * FROM admin_sessions WHERE admin_session_id = '$escaped_admin_session_id' AND expires_at > $current_time LIMIT 1";
    $admin_result = Database::query($admin_sql);
    
    if ($admin_result && $admin_result->num_rows > 0) {
        $admin_info = $admin_result->fetch_assoc();
        $escaped_peer_session_id = Database::escape($admin_info['peer_session_id']);
        
        // Check if client session still exists and allows autonomous logon
        $client_sql = "SELECT * FROM sessions WHERE session_id = '$escaped_peer_session_id' AND allow_autonomous = 1 AND expires_at > $current_time LIMIT 1";
        $client_result = Database::query($client_sql);
        
        if ($client_result && $client_result->num_rows > 0) {
            $client_session = $client_result->fetch_assoc();
            
            // Update admin session expiry
            $new_expires_at = $current_time + CODE_EXPIRY;
            $update_sql = "UPDATE admin_sessions SET connected_at = $current_time, expires_at = $new_expires_at WHERE admin_session_id = '$escaped_admin_session_id'";
            Database::query($update_sql);
            
            echo json_encode(array(
                'success' => true,
                'allowed' => true,
                'admin_session_id' => $admin_info['admin_session_id'],
                'peer_ip' => $admin_info['peer_ip'] ? $admin_info['peer_ip'] : $client_session['ip_address'],
                'peer_port' => intval($admin_info['peer_port'] ? $admin_info['peer_port'] : $client_session['port']),
                'peer_session_id' => $admin_info['peer_session_id'],
                'peer_code' => $admin_info['peer_code']
            ));
            exit;
        }
    }
    
    echo json_encode(array(
        'success' => true,
        'allowed' => false,
        'message' => 'Autonomous reconnection not allowed or session expired'
    ));
} elseif (STORAGE_METHOD === 'file') {
    // Check if admin session info exists
    $admin_info_file = STORAGE_PATH . $admin_session_id . '_admin.json';
    
    if (file_exists($admin_info_file)) {
        $admin_info = json_decode(file_get_contents($admin_info_file), true);
        
        // Check if admin info is still valid (not expired)
        if ($admin_info && isset($admin_info['expires_at']) && $admin_info['expires_at'] > time()) {
            // Check if client session still exists
            $client_session_file = STORAGE_PATH . $admin_info['peer_session_id'] . '.json';
            
            if (file_exists($client_session_file)) {
                $client_session = json_decode(file_get_contents($client_session_file), true);
                
                // Check if client session is still valid and allows autonomous logon
                if ($client_session && ($client_session['allow_autonomous'] ?? false)) {
                    // Update admin session info
                    $admin_info['connected_at'] = time();
                    $admin_info['expires_at'] = time() + CODE_EXPIRY;
                    file_put_contents($admin_info_file, json_encode($admin_info));
                    
                    echo json_encode([
                        'success' => true,
                        'allowed' => true,
                        'admin_session_id' => $admin_info['admin_session_id'],
                        'peer_ip' => $admin_info['peer_ip'] ?? $client_session['ip_address'] ?? null,
                        'peer_port' => $admin_info['peer_port'] ?? $client_session['port'] ?? 8765,
                        'peer_session_id' => $admin_info['peer_session_id'],
                        'peer_code' => $admin_info['peer_code']
                    ]);
                    exit;
                }
            }
        }
    }
    
    // No valid autonomous reconnection found
    echo json_encode([
        'success' => true,
        'allowed' => false,
        'message' => 'Autonomous reconnection not allowed or session expired'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Storage method not configured']);
}

?>

