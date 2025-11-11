<?php
/**
 * Get Recent Logs API
 * Retrieves recent activity logs for a session (frames, inputs, cursor positions, signals)
 * 
 * Usage:
 * - Get logs for specific session: ?code=eagle-hill&limit=100
 * - Get logs for most recent session: ?limit=100&recent=true
 * - Format: ?format=json (default) or ?format=text
 */

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

$code = isset($_GET['code']) ? $_GET['code'] : null;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 200;
$format = isset($_GET['format']) ? $_GET['format'] : 'json';
$recent = isset($_GET['recent']) ? ($_GET['recent'] === 'true' || $_GET['recent'] === '1') : false;

// If recent=true and no code, get the most recent session
if ($recent && !$code) {
    $recent_sql = "SELECT code FROM sessions 
                   WHERE expires_at > " . time() . "
                   ORDER BY created_at DESC 
                   LIMIT 1";
    $recent_result = Database::query($recent_sql);
    if ($recent_result && $recent_result->num_rows > 0) {
        $row = $recent_result->fetch_assoc();
        $code = $row['code'];
    }
}

if (!$code) {
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Code parameter required (or use recent=true)',
        'available_sessions' => []
    ], JSON_PRETTY_PRINT);
    exit;
}

// Escape code for SQL
$escaped_code = Database::escape($code);

// Get session info
$session_sql = "SELECT * FROM sessions WHERE code = '$escaped_code' AND expires_at > " . time() . " ORDER BY created_at DESC LIMIT 1";
$session_result = Database::query($session_sql);

$session_info = null;
$session_id = null;
$escaped_session_id = null;

if ($session_result && $session_result->num_rows > 0) {
    $session_row = $session_result->fetch_assoc();
    $session_info = [
        'code' => $session_row['code'],
        'session_id' => $session_row['session_id'],
        'mode' => $session_row['mode'],
        'created_at' => date('Y-m-d H:i:s', $session_row['created_at']),
        'expires_at' => date('Y-m-d H:i:s', $session_row['expires_at'])
    ];
    $session_id = $session_row['session_id'];
    
    // Escape session_id for SQL queries
    $escaped_session_id = Database::escape($session_id);
} else {
    // No active session found
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'No active session found for code: ' . $code,
        'code' => $code
    ], JSON_PRETTY_PRINT);
    exit;
}

// Get recent relay messages (frames, inputs, cursor positions)
$logs = [];

if ($session_id && $escaped_session_id) {
    // Get frames
    $frames_sql = "SELECT id, session_id, message_type, data_length, created_at 
                   FROM relay_messages 
                   WHERE session_id = '$escaped_session_id' AND message_type = 'frame'
                   ORDER BY created_at DESC 
                   LIMIT $limit";
    $frames_result = Database::query($frames_sql);
    
    if ($frames_result && $frames_result->num_rows > 0) {
        while ($row = $frames_result->fetch_assoc()) {
            $logs[] = [
                'type' => 'frame',
                'timestamp' => date('Y-m-d H:i:s', $row['created_at']),
                'size' => intval($row['data_length']),
                'id' => intval($row['id'])
            ];
        }
    }
    
    // Get inputs
    $inputs_sql = "SELECT id, session_id, message_type, data_length, created_at 
                   FROM relay_messages 
                   WHERE session_id = '$escaped_session_id' AND message_type = 'input'
                   ORDER BY created_at DESC 
                   LIMIT $limit";
    $inputs_result = Database::query($inputs_sql);
    
    if ($inputs_result && $inputs_result->num_rows > 0) {
        while ($row = $inputs_result->fetch_assoc()) {
            $logs[] = [
                'type' => 'input',
                'timestamp' => date('Y-m-d H:i:s', $row['created_at']),
                'size' => intval($row['data_length']),
                'id' => intval($row['id'])
            ];
        }
    }
    
    // Get cursor positions
    $cursor_sql = "SELECT id, session_id, message_type, data_length, created_at 
                   FROM relay_messages 
                   WHERE session_id = '$escaped_session_id' AND message_type = 'cursor'
                   ORDER BY created_at DESC 
                   LIMIT $limit";
    $cursor_result = Database::query($cursor_sql);
    
    if ($cursor_result && $cursor_result->num_rows > 0) {
        while ($row = $cursor_result->fetch_assoc()) {
            $logs[] = [
                'type' => 'cursor',
                'timestamp' => date('Y-m-d H:i:s', $row['created_at']),
                'size' => intval($row['data_length']),
                'id' => intval($row['id'])
            ];
        }
    }
}

// Get signals
$signals_sql = "SELECT id, code, type, data, created_at 
                FROM signals 
                WHERE code = '$escaped_code'
                ORDER BY created_at DESC 
                LIMIT $limit";
$signals_result = Database::query($signals_sql);

if ($signals_result && $signals_result->num_rows > 0) {
    while ($row = $signals_result->fetch_assoc()) {
        $logs[] = [
            'type' => 'signal',
            'signal_type' => $row['type'],
            'timestamp' => date('Y-m-d H:i:s', $row['created_at']),
            'data' => json_decode($row['data'], true),
            'id' => intval($row['id'])
        ];
    }
}

// Sort all logs by timestamp (newest first)
usort($logs, function($a, $b) {
    return strtotime($b['timestamp']) - strtotime($a['timestamp']);
});

// Limit to requested number
$logs = array_slice($logs, 0, $limit);

// Format output
if ($format === 'text') {
    header('Content-Type: text/plain');
    echo "=== Recent Logs for Session: {$code} ===\n\n";
    if ($session_info) {
        echo "Session Info:\n";
        echo "  Code: {$session_info['code']}\n";
        echo "  Mode: {$session_info['mode']}\n";
        echo "  Created: {$session_info['created_at']}\n";
        echo "  Expires: {$session_info['expires_at']}\n\n";
    }
    echo "Recent Activity (last " . count($logs) . " events):\n\n";
    foreach ($logs as $log) {
        $type = strtoupper($log['type']);
        $time = $log['timestamp'];
        if ($log['type'] === 'signal') {
            echo "[{$time}] [{$type}] {$log['signal_type']}\n";
        } else {
            $size = isset($log['size']) ? " ({$log['size']} bytes)" : "";
            echo "[{$time}] [{$type}]{$size}\n";
        }
    }
} else {
    echo json_encode([
        'success' => true,
        'session' => $session_info,
        'logs' => $logs,
        'count' => count($logs),
        'code' => $code
    ], JSON_PRETTY_PRINT);
}

