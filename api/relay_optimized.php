<?php
/**
 * OPTIMIZED Relay Server - Performance improvements
 * 
 * Key optimizations:
 * - Separate queries for session_id and code (avoids OR condition)
 * - Composite index usage (session_id, read_at)
 * - Reduced error logging overhead
 * - Prepared statements for large data
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

// OPTIMIZATION: Cache peer_id lookups (in-memory, per-request)
// This avoids repeated database queries for the same session
static $peer_id_cache = array();
static $cache_ttl = 5; // Cache for 5 seconds

function getCachedPeerId($session_id, $code) {
    global $peer_id_cache;
    
    // Check cache first
    $cache_key = $session_id . '|' . $code;
    if (isset($peer_id_cache[$cache_key])) {
        $cached = $peer_id_cache[$cache_key];
        if (time() - $cached['time'] < $GLOBALS['cache_ttl']) {
            return $cached['peer_id'];
        }
        unset($peer_id_cache[$cache_key]);
    }
    
    // Query database
    $escaped_session_id = Database::escape($session_id);
    $escaped_code = Database::escape($code);
    
    // Try session_id first (most common, has index)
    $peer_sql = "SELECT peer_id FROM sessions WHERE session_id = '$escaped_session_id' AND peer_id IS NOT NULL LIMIT 1";
    $peer_result = Database::query($peer_sql);
    
    $peer_id = null;
    if ($peer_result && $peer_result->num_rows > 0) {
        $peer_row = $peer_result->fetch_assoc();
        $peer_id = $peer_row['peer_id'];
    } else {
        // Try code (has index)
        $peer_sql = "SELECT peer_id FROM sessions WHERE code = '$escaped_code' AND peer_id IS NOT NULL LIMIT 1";
        $peer_result = Database::query($peer_sql);
        if ($peer_result && $peer_result->num_rows > 0) {
            $peer_row = $peer_result->fetch_assoc();
            $peer_id = $peer_row['peer_id'];
        }
    }
    
    // Cache result
    if ($peer_id) {
        $peer_id_cache[$cache_key] = array(
            'peer_id' => $peer_id,
            'time' => time()
        );
    }
    
    return $peer_id;
}

function storeRelayData($session_id, $code, $data_type, $data) {
    if (STORAGE_METHOD === 'database') {
        $escaped_type = Database::escape($data_type);
        $timestamp = time();
        
        // OPTIMIZATION: Use cached peer_id lookup
        $peer_id = getCachedPeerId($session_id, $code);
        
        if (!$peer_id) {
            return false;
        }
        
        // Store data for peer to retrieve
        $conn = Database::getConnection();
        if ($conn) {
            // Use prepared statement for better handling of large data
            $stmt = $conn->prepare("INSERT INTO relay_messages (session_id, message_type, message_data, created_at) VALUES (?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("sssi", $peer_id, $data_type, $data, $timestamp);
                $result = $stmt->execute();
                $stmt->close();
                
                if ($result) {
                    return true;
                }
            }
            
            // Fallback to regular insert if prepared statement fails
            $escaped_peer_id = Database::escape($peer_id);
            $escaped_data = Database::escape($data);
            $insert_sql = "INSERT INTO relay_messages (session_id, message_type, message_data, created_at) 
                          VALUES ('$escaped_peer_id', '$escaped_type', '$escaped_data', $timestamp)";
            return Database::query($insert_sql) !== false;
        }
        
        return false;
    }
    
    // File storage (unchanged)
    return false;
}

function getRelayData($session_id, $code) {
    if (STORAGE_METHOD === 'database') {
        $escaped_session_id = Database::escape($session_id);
        $current_time = time();
        
        // OPTIMIZATION: Use composite index (session_id, read_at) - only select needed columns
        $sql = "SELECT id, message_type, message_data, created_at FROM relay_messages 
                WHERE session_id = '$escaped_session_id' AND read_at IS NULL 
                ORDER BY created_at ASC LIMIT 10";
        $result = Database::query($sql);
        
        $messages = array();
        $message_ids = array();
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $msg_data = $row['message_data'];
                
                // If it's input data, try to decode JSON
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
                
                $message_ids[] = intval($row['id']);
            }
            
            // OPTIMIZATION: Batch update (much faster than individual updates)
            if (!empty($message_ids)) {
                $ids_str = implode(',', $message_ids);
                $update_sql = "UPDATE relay_messages SET read_at = $current_time WHERE id IN ($ids_str)";
                Database::query($update_sql);
            }
        }
        
        return $messages;
    }
    
    return array();
}

// Handle CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['action'])) {
    echo json_encode(['success' => false, 'message' => 'Missing action']);
    exit;
}

$action = $input['action'];

if ($action === 'send') {
    if (!isset($input['session_id']) || !isset($input['code']) || !isset($input['type']) || !isset($input['data'])) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }
    
    $session_id = $input['session_id'];
    $code = trim(strtolower($input['code']));
    $data_type = $input['type'];
    $data = is_array($input['data']) ? json_encode($input['data']) : $input['data'];
    
    $result = storeRelayData($session_id, $code, $data_type, $data);
    echo json_encode(['success' => $result, 'message' => $result ? 'Data relayed' : 'Failed to relay']);
    
} elseif ($action === 'receive') {
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

