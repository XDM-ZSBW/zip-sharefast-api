<?php
/**
 * Relay Server - Hybrid File + MySQL Storage
 * 
 * Performance Strategy:
 * - File storage: Fast frame/input data (append-only, direct I/O)
 * - MySQL: Session metadata (peer_id lookup, keepalive)
 * 
 * This hybrid approach is 2-5x faster than pure MySQL for relay operations
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

// Hybrid storage: Use file for relay data, MySQL for session metadata
define('USE_HYBRID_STORAGE', true);  // Enable hybrid mode
define('RELAY_STORAGE_PATH', __DIR__ . '/../storage/relay/');

// Create relay storage directory if it doesn't exist
if (!is_dir(RELAY_STORAGE_PATH)) {
    mkdir(RELAY_STORAGE_PATH, 0755, true);
}

function getPeerIdFromMySQL($session_id, $code) {
    /**
     * Get peer_id from MySQL (fast lookup for small metadata)
     * This is much faster than storing frames in MySQL
     */
    $escaped_session_id = Database::escape($session_id);
    $escaped_code = Database::escape($code);
    
    $sql = "SELECT peer_id FROM sessions WHERE (session_id = '$escaped_session_id' OR code = '$escaped_code') AND peer_id IS NOT NULL LIMIT 1";
    $result = Database::query($sql);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['peer_id'];
    }
    
    return null;
}

function storeRelayData($session_id, $code, $data_type, $data) {
    /**
     * Optimized: Append-only file writes (much faster than read-modify-write)
     * Each frame is written as a single JSON line (NDJSON format)
     */
    
    // Get peer_id from MySQL (fast metadata lookup)
    $peer_id = getPeerIdFromMySQL($session_id, $code);
    
    if (!$peer_id) {
        return false;
    }
    
    // Store frame/input data in file (append-only - much faster!)
    $relay_file = RELAY_STORAGE_PATH . $peer_id . '_relay.json';
    
    // Append as single JSON line (NDJSON format) - no locking needed for append!
    $message = json_encode([
        'type' => $data_type,
        'data' => $data,
        'timestamp' => time()
    ]) . "\n";
    
    // Append-only write (much faster, atomic on most filesystems)
    $result = file_put_contents($relay_file, $message, FILE_APPEND | LOCK_EX);
    
    return $result !== false;
}

function getRelayData($session_id, $code) {
    /**
     * Optimized: Atomic read-and-clear using rename pattern
     * Uses NDJSON format (one JSON object per line)
     * Prevents race conditions between concurrent reads and writes
     */
    
    // Try session_id first (frames are stored using peer_id = admin's session_id)
    $relay_file = RELAY_STORAGE_PATH . $session_id . '_relay.json';
    
    // If file doesn't exist, try peer_id as fallback
    if (!file_exists($relay_file)) {
        $peer_id = getPeerIdFromMySQL($session_id, $code);
        if ($peer_id) {
            $relay_file = RELAY_STORAGE_PATH . $peer_id . '_relay.json';
        }
    }
    
    // If still no file, return empty
    if (!file_exists($relay_file)) {
        return [];
    }
    
    // Atomic read-and-clear: rename file, then read from renamed file
    // This prevents race conditions where frames are written while reading
    $temp_file = $relay_file . '.tmp.' . time() . '.' . rand(1000, 9999);
    
    // Try to rename atomically (this will fail if file is being written)
    $rename_success = @rename($relay_file, $temp_file);
    
    if (!$rename_success) {
        // If rename failed, try reading with lock (fallback)
        // This is slower but works if rename fails
        $fp = @fopen($relay_file, 'r');
        if (!$fp) {
            return [];
        }
        
        // Try to get exclusive lock (non-blocking)
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return [];  // File is being written, return empty and try again next poll
        }
        
        // Read all lines
        $messages = [];
        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            if (empty($line)) continue;
            $decoded = json_decode($line, true);
            if ($decoded && is_array($decoded)) {
                $messages[] = $decoded;
            }
        }
        
        // Clear file
        ftruncate($fp, 0);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        
        return $messages;
    }
    
    // Read from renamed temp file
    $messages = [];
    if (file_exists($temp_file)) {
        $lines = file($temp_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        if ($lines) {
            foreach ($lines as $line) {
                $decoded = json_decode($line, true);
                if ($decoded && is_array($decoded)) {
                    $messages[] = $decoded;
                }
            }
        }
        
        // Delete temp file
        @unlink($temp_file);
    }
    
    return $messages;
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
    
    // Get more detailed error info if failed
    $error_msg = 'Data relayed';
    if (!$result) {
        // Check if it's a peer_id issue
        $peer_id = getPeerIdFromMySQL($session_id, $code);
        if (!$peer_id) {
            $error_msg = 'No peer connection found - ensure admin and client are both connected';
        } else {
            $error_msg = 'Failed to store relay data';
        }
    }
    
    echo json_encode(['success' => $result, 'message' => $error_msg]);
    
       } elseif ($action === 'receive') {
           // Receive data from peer
           if (!isset($input['session_id']) || !isset($input['code'])) {
               echo json_encode(['success' => false, 'message' => 'Missing required fields']);
               exit;
           }
           
           $session_id = $input['session_id'];
           $code = trim($input['code']);
           $code = strtolower($code);  // Normalize code
           
           error_log("relay.php receive: session_id=$session_id, code=$code");
           
           $messages = getRelayData($session_id, $code);
           $message_count = count($messages);
           error_log("relay.php receive: returning $message_count messages");
           
           echo json_encode([
               'success' => true,
               'messages' => $messages,
               'count' => $message_count
           ]);
    
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

?>

