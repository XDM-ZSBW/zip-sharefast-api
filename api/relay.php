<?php
/**
 * Relay Server - Routes data between client and admin
 * Similar to Google Remote Desktop relay functionality
 * 
 * Attribution: Relay architecture inspired by Google Remote Desktop's relay server model.
 * Google Remote Desktop uses a central relay server to route data between peers when
 * direct P2P connections aren't possible due to NAT/firewall restrictions.
 * Reference: Google Remote Desktop service architecture.
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

function storeRelayData($session_id, $code, $data_type, $data) {
    if (STORAGE_METHOD === 'database') {
        $escaped_session_id = Database::escape($session_id);
        $escaped_code = Database::escape($code);
        $escaped_type = Database::escape($data_type);
        $timestamp = time();
        
        // OPTIMIZATION: Try session_id first (has index), then code (has index)
        // This avoids the OR condition which can't use indexes efficiently
        $peer_id = null;
        
        // First try by session_id (most common case, has index)
        $peer_sql = "SELECT peer_id FROM sessions WHERE session_id = '$escaped_session_id' AND peer_id IS NOT NULL LIMIT 1";
        $peer_result = Database::query($peer_sql);
        if ($peer_result && $peer_result->num_rows > 0) {
            $peer_row = $peer_result->fetch_assoc();
            $peer_id = $peer_row['peer_id'];
        }
        
        // If not found, try by code (has index)
        if (!$peer_id) {
            $peer_sql = "SELECT peer_id FROM sessions WHERE code = '$escaped_code' AND peer_id IS NOT NULL LIMIT 1";
            $peer_result = Database::query($peer_sql);
            if ($peer_result && $peer_result->num_rows > 0) {
                $peer_row = $peer_result->fetch_assoc();
                $peer_id = $peer_row['peer_id'];
            }
        }
        
        if (!$peer_id) {
            // Only log errors, not debug info (reduces overhead)
            error_log("storeRelayData: No peer_id found for session_id=$session_id, code=$code, type=$data_type");
            return false;
        }
        
        // Store data for peer to retrieve
        $escaped_peer_id = Database::escape($peer_id);
        
        // For large data (frames), use prepared statement or direct insert with proper escaping
        // real_escape_string might have issues with very large strings
        $conn = Database::getConnection();
        if ($conn) {
            // Use prepared statement for better handling of large data
            $stmt = $conn->prepare("INSERT INTO relay_messages (session_id, message_type, message_data, created_at) VALUES (?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("sssi", $peer_id, $data_type, $data, $timestamp);
                $result = $stmt->execute();
                
                if (!$result) {
                    error_log("storeRelayData: Prepared statement failed: " . $stmt->error . " | type=$data_type, data_size=" . strlen($data) . ", peer_id=$peer_id");
                } else {
                    error_log("storeRelayData: Frame stored successfully via prepared statement | type=$data_type, data_size=" . strlen($data) . ", peer_id=$peer_id");
                }
                
                $stmt->close();
                
                if ($result) {
                    // Don't cleanup on every insert - it's too expensive
                    // Instead, cleanup will happen periodically or on disconnect
                    // This significantly improves frame rate performance
                    return true;
                }
            } else {
                error_log("storeRelayData: Failed to prepare statement: " . $conn->error);
            }
            
            // Fallback to regular insert if prepared statement fails
            $escaped_data = Database::escape($data);
            $insert_sql = "INSERT INTO relay_messages (session_id, message_type, message_data, created_at) 
                          VALUES ('$escaped_peer_id', '$escaped_type', '$escaped_data', $timestamp)";
            $fallback_result = Database::query($insert_sql);
            
            if (!$fallback_result) {
                $conn = Database::getConnection();
                $error = $conn ? $conn->error : 'Unknown error';
                error_log("storeRelayData: Fallback insert failed: $error | type=$data_type, data_size=" . strlen($data));
                return false;
            }
            
            // Don't cleanup on every insert - cleanup happens periodically
            // This significantly improves frame rate performance
            return true;
        }
        
        return false;
    } elseif (STORAGE_METHOD === 'file') {
        // Find peer session - check both by code and by session_id
        $peer_id = null;
        
        // First check by session_id (most reliable)
        $session_file = STORAGE_PATH . $session_id . '.json';
        if (file_exists($session_file)) {
            $session = json_decode(file_get_contents($session_file), true);
            if (isset($session['peer_id'])) {
                $peer_id = $session['peer_id'];
            }
        }
        
        // Also check by code (for backwards compatibility)
        if (!$peer_id) {
            $session_file = STORAGE_PATH . $code . '.json';
            if (file_exists($session_file)) {
                $session = json_decode(file_get_contents($session_file), true);
                if (isset($session['peer_id'])) {
                    $peer_id = $session['peer_id'];
                }
            }
        }
        
        // Store data for peer to retrieve
        if ($peer_id) {
            $relay_file = STORAGE_PATH . $peer_id . '_relay.json';
            $messages = [];
            if (file_exists($relay_file)) {
                $messages = json_decode(file_get_contents($relay_file), true) ?: [];
            }
            
            $messages[] = [
                'type' => $data_type,
                'data' => $data,
                'timestamp' => time()
            ];
            
            // Keep only last 100 messages (prevent memory issues)
            if (count($messages) > 100) {
                $messages = array_slice($messages, -100);
            }
            
            file_put_contents($relay_file, json_encode($messages));
            return true;
        }
        
        return false;
    }
    
    return false;
}

function getRelayData($session_id, $code) {
    if (STORAGE_METHOD === 'database') {
        $escaped_session_id = Database::escape($session_id);
        $current_time = time();
        
        // OPTIMIZATION: Use composite index (session_id, read_at) for faster queries
        // Get unread relay messages (limit to 10 for performance - process in batches)
        // Only select needed columns (not *) for better performance
        $sql = "SELECT id, message_type, message_data, created_at FROM relay_messages WHERE session_id = '$escaped_session_id' AND read_at IS NULL ORDER BY created_at ASC LIMIT 10";
        $result = Database::query($sql);
        
        $messages = array();
        $message_ids = array();
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $msg_data = $row['message_data'];
                
                // If it's input data, try to decode JSON
                // Frame data is base64 string, keep as-is
                if ($row['message_type'] === 'input' && !empty($msg_data)) {
                    $decoded = json_decode($msg_data, true);
                    if ($decoded !== null) {
                        $msg_data = $decoded;
                    }
                }
                
                $messages[] = array(
                    'type' => $row['message_type'],
                    'data' => $msg_data,
                    'timestamp' => $row['created_at']
                );
                
                // Collect IDs for batch update
                $message_ids[] = intval($row['id']);
            }
            
            // Batch mark all messages as read at once (much faster than individual updates)
            if (!empty($message_ids)) {
                $ids_str = implode(',', $message_ids);
                $update_sql = "UPDATE relay_messages SET read_at = $current_time WHERE id IN ($ids_str)";
                Database::query($update_sql);
            }
        }
        
        return $messages;
    } elseif (STORAGE_METHOD === 'file') {
        $relay_file = STORAGE_PATH . $session_id . '_relay.json';
        
        if (file_exists($relay_file)) {
            $messages = json_decode(file_get_contents($relay_file), true) ?: [];
            
            // Return all pending messages and clear them
            if (!empty($messages)) {
                file_put_contents($relay_file, json_encode([])); // Clear after reading
                return $messages;
            }
        }
        
        return [];
    }
    
    return [];
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['action'])) {
    echo json_encode(['success' => false, 'message' => 'Missing action']);
    exit;
}

$action = $input['action'];

if ($action === 'send') {
    // Send data to peer
    if (!isset($input['session_id']) || !isset($input['code']) || !isset($input['type']) || !isset($input['data'])) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }
    
    $session_id = $input['session_id'];
    $code = trim($input['code']);
    $code = strtolower($code);  // Normalize code (handle word-word codes)
    $data_type = $input['type']; // 'frame' or 'input'
    $data = $input['data'];
    
    // JSON encode data if it's an array/dict (for input events)
    // Frames are already base64 strings, so keep them as-is
    if (is_array($data)) {
        $data = json_encode($data);
    }
    
    $result = storeRelayData($session_id, $code, $data_type, $data);
    
    // Get more detailed error info if failed (only when needed)
    $error_msg = 'Data relayed';
    if (!$result) {
        // OPTIMIZATION: Simplified error check (avoid extra query if possible)
        // Only check peer_id if we're sure it's a peer connection issue
        $error_msg = 'Failed to store relay data - peer may not be connected';
    }
    
    echo json_encode(['success' => $result, 'message' => $error_msg]);
    
} elseif ($action === 'receive') {
    // Receive data from peer
    if (!isset($input['session_id']) || !isset($input['code'])) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }
    
    $session_id = $input['session_id'];
    $code = preg_replace('/[^0-9]/', '', $input['code']);
    
    $messages = getRelayData($session_id, $code);
    echo json_encode([
        'success' => true,
        'messages' => $messages,
        'count' => count($messages)
    ]);
    
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

?>

