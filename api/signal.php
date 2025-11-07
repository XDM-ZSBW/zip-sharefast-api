<?php
/**
 * Send WebRTC signaling data (offer, answer, ICE candidates)
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

function storeSignal($session_id, $code, $signal_type, $data) {
    if (STORAGE_METHOD === 'database') {
        $escaped_session_id = Database::escape($session_id);
        $escaped_code = Database::escape($code);
        $escaped_type = Database::escape($signal_type);
        $escaped_data = Database::escape(json_encode($data));
        $timestamp = time();
        
        // Find peer session
        // IMPORTANT: When admin sends signal, we need to find the CLIENT's session_id (peer_id)
        // So we should query by admin's session_id first, then fallback to code
        $peer_sql = "SELECT peer_id FROM sessions WHERE session_id = '$escaped_session_id' AND peer_id IS NOT NULL LIMIT 1";
        $peer_result = Database::query($peer_sql);
        
        $peer_id = null;
        if ($peer_result && $peer_result->num_rows > 0) {
            $peer_row = $peer_result->fetch_assoc();
            $peer_id = $peer_row['peer_id'];
            error_log("storeSignal: Found peer_id=$peer_id for session_id=$session_id");
        } else {
            // Fallback: try by code (but this might match wrong session)
            $peer_sql = "SELECT peer_id FROM sessions WHERE code = '$escaped_code' AND peer_id IS NOT NULL LIMIT 1";
            $peer_result = Database::query($peer_sql);
            if ($peer_result && $peer_result->num_rows > 0) {
                $peer_row = $peer_result->fetch_assoc();
                $peer_id = $peer_row['peer_id'];
                error_log("storeSignal: Found peer_id=$peer_id by code=$code (fallback)");
            }
        }
        
        // Store signal for peer to retrieve
        if ($peer_id) {
            $escaped_peer_id = Database::escape($peer_id);
            error_log("storeSignal: Storing signal type=$signal_type for peer_id=$peer_id (original session_id=$session_id, code=$code)");
            $insert_sql = "INSERT INTO signals (session_id, code, signal_type, signal_data, created_at) 
                          VALUES ('$escaped_peer_id', '$escaped_code', '$escaped_type', '$escaped_data', $timestamp)";
            $insert_result = Database::query($insert_sql);
            if (!$insert_result) {
                error_log("storeSignal: INSERT failed: " . Database::getConnection()->error);
            } else {
                error_log("storeSignal: Signal stored successfully");
            }
            
            // Clean up old signals (keep only last 100 per session)
            $cleanup_sql = "DELETE FROM signals WHERE session_id = '$escaped_peer_id' AND id NOT IN 
                           (SELECT id FROM (SELECT id FROM signals WHERE session_id = '$escaped_peer_id' ORDER BY created_at DESC LIMIT 100) AS temp)";
            Database::query($cleanup_sql);
            
            return true;
        } else {
            error_log("storeSignal: No peer_id found for session_id=$session_id, code=$code");
        }
        
        return false;
    } elseif (STORAGE_METHOD === 'file') {
        // Find peer session
        $peer_id = null;
        $session_file = STORAGE_PATH . $code . '.json';
        if (file_exists($session_file)) {
            $session = json_decode(file_get_contents($session_file), true);
            if (isset($session['peer_id'])) {
                $peer_id = $session['peer_id'];
            }
        }
        
        // Also check by session_id
        $session_file = STORAGE_PATH . $session_id . '.json';
        if (file_exists($session_file)) {
            $session = json_decode(file_get_contents($session_file), true);
            if (isset($session['peer_id']) && !$peer_id) {
                $peer_id = $session['peer_id'];
            }
        }
        
        // Store signal for peer to retrieve
        if ($peer_id) {
            $peer_signal_file = STORAGE_PATH . $peer_id . '_signals.json';
            $signals = [];
            if (file_exists($peer_signal_file)) {
                $signals = json_decode(file_get_contents($peer_signal_file), true) ?: [];
            }
            
            $signals[] = [
                'type' => $signal_type,
                'data' => $data,
                'timestamp' => time()
            ];
            
            // Keep only last 50 signals
            if (count($signals) > 50) {
                $signals = array_slice($signals, -50);
            }
            
            file_put_contents($peer_signal_file, json_encode($signals));
        }
        
        return true;
    }
    
    return false;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['session_id']) || !isset($input['code']) || !isset($input['type']) || !isset($input['data'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$session_id = $input['session_id'];
$code = trim($input['code']);
$code = strtolower($code);  // Ensure code is lowercase to match database storage
$signal_type = $input['type'];
$data = $input['data'];

// Allow WebRTC signals and custom signals like admin_connected
$allowed_types = ['offer', 'answer', 'ice-candidate', 'admin_connected', 'admin_disconnected', 'client_ready', 'peer_info', 'p2p_connect_request', 'p2p_ready'];
if (!in_array($signal_type, $allowed_types)) {
    echo json_encode(['success' => false, 'message' => 'Invalid signal type']);
    exit;
}

$result = storeSignal($session_id, $code, $signal_type, $data);
echo json_encode(['success' => $result, 'message' => $result ? 'Signal stored' : 'Failed to store signal']);

?>

