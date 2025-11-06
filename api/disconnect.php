<?php
/**
 * Disconnect a session and clean up
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

function disconnectSession($session_id, $code) {
    // Clean up relay files (hybrid storage)
    $relay_storage_path = __DIR__ . '/../storage/relay/';
    if (is_dir($relay_storage_path)) {
        $relay_files = [
            $relay_storage_path . $session_id . '_relay.json'
        ];
        
        // Also try to get peer_id to clean up peer's relay file
        if (STORAGE_METHOD === 'database') {
            $escaped_session_id = Database::escape($session_id);
            $sql = "SELECT peer_id FROM sessions WHERE session_id = '$escaped_session_id' LIMIT 1";
            $result = Database::query($sql);
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                if ($row['peer_id']) {
                    $relay_files[] = $relay_storage_path . $row['peer_id'] . '_relay.json';
                }
            }
        }
        
        foreach ($relay_files as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }
    
    if (STORAGE_METHOD === 'database') {
        $escaped_session_id = Database::escape($session_id);
        $escaped_code = Database::escape($code);
        
        // Get session info
        $sql = "SELECT * FROM sessions WHERE session_id = '$escaped_session_id' LIMIT 1";
        $result = Database::query($sql);
        
        if (!$result || $result->num_rows === 0) {
            return true; // Already disconnected
        }
        
        $session = $result->fetch_assoc();
        $is_admin = ($session['mode'] === 'admin');
        
        // Delete session-specific data
        Database::query("DELETE FROM signals WHERE session_id = '$escaped_session_id'");
        Database::query("DELETE FROM relay_messages WHERE session_id = '$escaped_session_id'");
        
        if ($is_admin) {
            // Admin disconnecting - remove admin session but keep client session
            Database::query("DELETE FROM admin_sessions WHERE admin_session_id = '$escaped_session_id'");
            Database::query("DELETE FROM sessions WHERE session_id = '$escaped_session_id'");
            
            // Update client session to mark as disconnected
            if ($session['peer_id']) {
                $escaped_peer_id = Database::escape($session['peer_id']);
                Database::query("UPDATE sessions SET connected = 0, peer_id = NULL WHERE session_id = '$escaped_peer_id'");
            }
        } else {
            // Client disconnecting - remove all related data
            $escaped_peer_id = Database::escape($session['peer_id']);
            if ($session['peer_id']) {
                Database::query("DELETE FROM signals WHERE session_id = '$escaped_peer_id'");
                Database::query("DELETE FROM relay_messages WHERE session_id = '$escaped_peer_id'");
                Database::query("DELETE FROM admin_sessions WHERE peer_session_id = '$escaped_session_id'");
                Database::query("DELETE FROM sessions WHERE session_id = '$escaped_peer_id'");
            }
            
            Database::query("DELETE FROM admin_sessions WHERE peer_session_id = '$escaped_session_id'");
            Database::query("DELETE FROM sessions WHERE session_id = '$escaped_session_id'");
            Database::query("DELETE FROM sessions WHERE code = '$escaped_code' AND mode = 'client'");
        }
        
        return true;
    } elseif (STORAGE_METHOD === 'file') {
        // First, check what type of session this is
        $session_file = STORAGE_PATH . $session_id . '.json';
        $is_admin = false;
        
        if (file_exists($session_file)) {
            $session_data = json_decode(file_get_contents($session_file), true);
            if (is_array($session_data) && isset($session_data['mode'])) {
                $is_admin = ($session_data['mode'] === 'admin');
            }
        }
        
        // Always remove the session-specific files
        $files = [
            STORAGE_PATH . $session_id . '.json',
            STORAGE_PATH . $session_id . '_signals.json'
        ];
        
        foreach ($files as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        
        // If this is an admin disconnecting, only remove admin session files
        // Don't delete the client's session file (code.json) so admin can reconnect
        if ($is_admin) {
            // Remove admin info file if it exists
            $admin_file = STORAGE_PATH . $code . '_admin.json';
            if (file_exists($admin_file)) {
                @unlink($admin_file);
            }
            
            // Update client session to mark as disconnected (but don't delete it)
            $client_file = STORAGE_PATH . $code . '.json';
            if (file_exists($client_file)) {
                $client_session = json_decode(file_get_contents($client_file), true);
                if (is_array($client_session) && $client_session['mode'] === 'client') {
                    $client_session['connected'] = false;
                    $client_session['peer_id'] = null;
                    file_put_contents($client_file, json_encode($client_session));
                }
            }
        } else {
            // Client disconnecting - remove client session file and all related files
            $client_files = [
                STORAGE_PATH . $code . '.json',
                STORAGE_PATH . $code . '_admin.json'
            ];
            
            foreach ($client_files as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                }
            }
            
            // Also clean up peer's files if connected
            if (file_exists($session_file)) {
                $session = json_decode(file_get_contents($session_file), true);
                if (is_array($session) && isset($session['peer_id'])) {
                    $peer_files = [
                        STORAGE_PATH . $session['peer_id'] . '.json',
                        STORAGE_PATH . $session['peer_id'] . '_signals.json'
                    ];
                    foreach ($peer_files as $file) {
                        if (file_exists($file)) {
                            @unlink($file);
                        }
                    }
                }
            }
        }
        
        return true;
    }
    
    return false;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['session_id']) || !isset($input['code'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$session_id = $input['session_id'];
$code = preg_replace('/[^0-9]/', '', $input['code']);

$result = disconnectSession($session_id, $code);
echo json_encode(['success' => $result, 'message' => $result ? 'Disconnected' : 'Failed to disconnect']);

?>

