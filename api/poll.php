<?php
/**
 * Poll for incoming WebRTC signals
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
require_once __DIR__ . '/rate_limit.php';

// Enforce rate limiting (prevents abuse)
if (!enforceRateLimit()) {
    exit;
}

function getSignals($session_id, $code) {
    if (STORAGE_METHOD === 'database') {
        $escaped_session_id = Database::escape($session_id);
        $escaped_code = Database::escape($code);
        
        error_log("getSignals: Looking for signals with session_id=$session_id, code=$code");
        
        // Get unread signals for this session
        $sql = "SELECT * FROM signals WHERE session_id = '$escaped_session_id' AND read_at IS NULL ORDER BY created_at DESC LIMIT 1";
        $result = Database::query($sql);
        
        if ($result && $result->num_rows > 0) {
            $signal = $result->fetch_assoc();
            error_log("getSignals: Found signal id=" . $signal['id'] . ", type=" . $signal['signal_type']);
            
            // Mark as read
            $signal_id = intval($signal['id']);
            $update_sql = "UPDATE signals SET read_at = " . time() . " WHERE id = $signal_id";
            Database::query($update_sql);
            
            return array(
                'type' => $signal['signal_type'],
                'data' => json_decode($signal['signal_data'], true)
            );
        } else {
            error_log("getSignals: No unread signals found for session_id=$session_id");
            // Debug: Check if there are ANY signals for this session (even read ones)
            $debug_sql = "SELECT COUNT(*) as count FROM signals WHERE session_id = '$escaped_session_id'";
            $debug_result = Database::query($debug_sql);
            if ($debug_result && $debug_result->num_rows > 0) {
                $debug_row = $debug_result->fetch_assoc();
                error_log("getSignals: Total signals for session_id=$session_id: " . $debug_row['count']);
            }
        }
        
        return null;
    } elseif (STORAGE_METHOD === 'file') {
        // Get signals for this session
        $signal_file = STORAGE_PATH . $session_id . '_signals.json';
        
        // Also check peer's signals
        $session_file = STORAGE_PATH . $code . '.json';
        $peer_id = null;
        
        if (file_exists($session_file)) {
            $session = json_decode(file_get_contents($session_file), true);
            if (isset($session['peer_id'])) {
                $peer_id = $session['peer_id'];
            }
        }
        
        // Check by session_id too
        $session_file = STORAGE_PATH . $session_id . '.json';
        if (file_exists($session_file)) {
            $session = json_decode(file_get_contents($session_file), true);
            if (isset($session['peer_id']) && !$peer_id) {
                $peer_id = $session['peer_id'];
            }
        }
        
        $signals = [];
        
        // Get signals from peer
        if ($peer_id) {
            $peer_signal_file = STORAGE_PATH . $peer_id . '_signals.json';
            if (file_exists($peer_signal_file)) {
                $peer_signals = json_decode(file_get_contents($peer_signal_file), true) ?: [];
                // Get only new signals (after last check)
                $signals = array_merge($signals, $peer_signals);
            }
        }
        
        // Return most recent signal
        if (!empty($signals)) {
            $latest = end($signals);
            return [
                'type' => $latest['type'],
                'data' => $latest['data']
            ];
        }
        
        return null;
    }
    
    return null;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['session_id']) || !isset($input['code'])) {
    echo json_encode(['success' => false, 'signal' => null]);
    exit;
}

$session_id = $input['session_id'];
$code = trim($input['code']);
$code = strtolower($code);  // Normalize code (handle word-word codes)

error_log("poll.php: Request from session_id=$session_id, code=$code");

// Debug mode - return additional info
$debug = isset($input['debug']) && $input['debug'] === 'true';

$signal = getSignals($session_id, $code);

if ($signal) {
    error_log("poll.php: Returning signal type=" . $signal['type']);
} else {
    error_log("poll.php: No signal found");
    
    // In debug mode, check if signals exist for this session (even read ones)
    if ($debug) {
        $escaped_session_id = Database::escape($session_id);
        $debug_sql = "SELECT COUNT(*) as total, SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) as unread FROM signals WHERE session_id = '$escaped_session_id'";
        $debug_result = Database::query($debug_sql);
        if ($debug_result && $debug_result->num_rows > 0) {
            $debug_row = $debug_result->fetch_assoc();
            error_log("poll.php: Debug - Total signals: " . $debug_row['total'] . ", Unread: " . $debug_row['unread']);
        }
    }
}

$response = ['success' => true, 'signal' => $signal];
if ($debug) {
    $response['debug'] = [
        'session_id' => $session_id,
        'code' => $code,
        'timestamp' => time()
    ];
}

echo json_encode($response);

?>

