<?php
/**
 * Terminate Session - Clean up orphaned sessions and all related data
 * Usage: POST to terminate_session.php with code parameter
 * Returns: JSON with termination status
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

$code = isset($_POST['code']) ? $_POST['code'] : (isset($_GET['code']) ? $_GET['code'] : null);

if (!$code) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Code parameter required'
    ], JSON_PRETTY_PRINT);
    exit;
}

$escaped_code = Database::escape($code);

try {
    // Get all session IDs for this code
    $session_sql = "SELECT session_id FROM sessions WHERE code = '$escaped_code'";
    $session_result = Database::query($session_sql);
    
    $session_ids = [];
    if ($session_result && $session_result->num_rows > 0) {
        while ($row = $session_result->fetch_assoc()) {
            $session_ids[] = Database::escape($row['session_id']);
        }
    }
    
    if (empty($session_ids)) {
        echo json_encode([
            'success' => true,
            'message' => 'No sessions found for code: ' . $code,
            'deleted' => [
                'sessions' => 0,
                'relay_messages' => 0,
                'signals' => 0
            ]
        ], JSON_PRETTY_PRINT);
        Database::close();
        exit;
    }
    
    $session_ids_str = "'" . implode("', '", $session_ids) . "'";
    
    // Count items before deletion for reporting
    $count_sessions = count($session_ids);
    
    $count_relay_sql = "SELECT COUNT(*) as count FROM relay_messages WHERE session_id IN ($session_ids_str)";
    $count_relay_result = Database::query($count_relay_sql);
    $count_relay = 0;
    if ($count_relay_result && $count_relay_result->num_rows > 0) {
        $row = $count_relay_result->fetch_assoc();
        $count_relay = intval($row['count']);
    }
    
    $count_signals_sql = "SELECT COUNT(*) as count FROM signals WHERE session_id IN ($session_ids_str) OR code = '$escaped_code'";
    $count_signals_result = Database::query($count_signals_sql);
    $count_signals = 0;
    if ($count_signals_result && $count_signals_result->num_rows > 0) {
        $row = $count_signals_result->fetch_assoc();
        $count_signals = intval($row['count']);
    }
    
    // Delete relay messages (frames, inputs, cursor positions)
    $delete_relay_sql = "DELETE FROM relay_messages WHERE session_id IN ($session_ids_str)";
    Database::query($delete_relay_sql);
    
    // Delete signals
    $delete_signals_sql = "DELETE FROM signals WHERE session_id IN ($session_ids_str) OR code = '$escaped_code'";
    Database::query($delete_signals_sql);
    
    // Delete sessions
    $delete_sessions_sql = "DELETE FROM sessions WHERE code = '$escaped_code'";
    Database::query($delete_sessions_sql);
    
    echo json_encode([
        'success' => true,
        'message' => 'Session terminated successfully',
        'code' => $code,
        'deleted' => [
            'sessions' => $count_sessions,
            'relay_messages' => $count_relay,
            'signals' => $count_signals
        ]
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}

Database::close();
?>

