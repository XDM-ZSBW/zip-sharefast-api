<?php
/**
 * Test Frame Flow - Diagnostic script to check if frames are flowing through the relay
 * Usage: php test_frame_flow.php [code] [session_id]
 * Or access via web: https://sharefast.zip/api/test_frame_flow.php?code=test-code&session_id=test-session
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

// Get parameters from command line or GET request
$code = isset($argv[1]) ? $argv[1] : (isset($_GET['code']) ? $_GET['code'] : null);
$session_id = isset($argv[2]) ? $argv[2] : (isset($_GET['session_id']) ? $_GET['session_id'] : null);

$results = [
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'code' => $code,
    'session_id' => $session_id,
    'tests' => []
];

function addTestResult($name, $status, $message, $data = null) {
    global $results;
    $results['tests'][] = [
        'name' => $name,
        'status' => $status, // 'pass', 'fail', 'warning'
        'message' => $message,
        'data' => $data
    ];
}

// Test 1: Database connection
try {
    $conn = Database::getConnection();
    if ($conn) {
        addTestResult('Database Connection', 'pass', 'Database connection successful');
    } else {
        addTestResult('Database Connection', 'fail', 'Failed to connect to database');
        echo json_encode($results, JSON_PRETTY_PRINT);
        exit;
    }
} catch (Exception $e) {
    addTestResult('Database Connection', 'fail', 'Database error: ' . $e->getMessage());
    echo json_encode($results, JSON_PRETTY_PRINT);
    exit;
}

// Test 2: Check if session exists
if ($code || $session_id) {
    $escaped_code = Database::escape($code);
    $escaped_session_id = Database::escape($session_id);
    
    $sql = "SELECT * FROM sessions WHERE ";
    $conditions = [];
    if ($code) {
        $conditions[] = "code = '$escaped_code'";
    }
    if ($session_id) {
        $conditions[] = "session_id = '$escaped_session_id'";
    }
    $sql .= implode(' OR ', $conditions) . " ORDER BY created_at DESC LIMIT 1";
    
    $result = Database::query($sql);
    if ($result && $result->num_rows > 0) {
        $session = $result->fetch_assoc();
        $session_data = [
            'session_id' => $session['session_id'],
            'code' => $session['code'],
            'mode' => $session['mode'],
            'peer_id' => $session['peer_id'],
            'created_at' => date('Y-m-d H:i:s', $session['created_at'])
        ];
        // Add last_activity if column exists
        if (isset($session['last_activity']) && $session['last_activity']) {
            $session_data['last_activity'] = date('Y-m-d H:i:s', $session['last_activity']);
        }
        addTestResult('Session Found', 'pass', 'Session exists in database', $session_data);
        
        // Update session_id and code from database
        $session_id = $session['session_id'];
        $code = $session['code'];
        $peer_id = $session['peer_id'];
    } else {
        addTestResult('Session Found', 'warning', 'Session not found in database - may be new or expired');
    }
} else {
    addTestResult('Session Check', 'warning', 'No code or session_id provided - checking all recent sessions');
}

// Test 3: Check recent relay messages (frames)
$escaped_session_id = Database::escape($session_id);
$escaped_code = Database::escape($code);

// Check for frames sent by client (to admin)
$frame_sql = "SELECT COUNT(*) as count, 
              MAX(created_at) as last_frame,
              MIN(created_at) as first_frame,
              AVG(LENGTH(message_data)) as avg_size
              FROM relay_messages 
              WHERE message_type = 'frame' 
              AND session_id IN (
                  SELECT session_id FROM sessions WHERE code = '$escaped_code' OR session_id = '$escaped_session_id'
              )
              AND created_at > " . (time() - 300) . "  -- Last 5 minutes
              ORDER BY created_at DESC";

$frame_result = Database::query($frame_sql);
if ($frame_result && $frame_result->num_rows > 0) {
    $frame_data = $frame_result->fetch_assoc();
    $frame_count = intval($frame_data['count']);
    
    if ($frame_count > 0) {
        addTestResult('Frame Flow (Client → Admin)', 'pass', "Found $frame_count frames in last 5 minutes", [
            'count' => $frame_count,
            'last_frame' => $frame_data['last_frame'] ? date('Y-m-d H:i:s', $frame_data['last_frame']) : null,
            'first_frame' => $frame_data['first_frame'] ? date('Y-m-d H:i:s', $frame_data['first_frame']) : null,
            'avg_size' => round($frame_data['avg_size'] / 1024, 2) . ' KB'
        ]);
        
        // Calculate FPS (frames per second)
        if ($frame_data['first_frame'] && $frame_data['last_frame']) {
            $time_span = $frame_data['last_frame'] - $frame_data['first_frame'];
            if ($time_span > 0) {
                $fps = $frame_count / $time_span;
                $results['estimated_fps'] = round($fps, 2);
            }
        }
    } else {
        addTestResult('Frame Flow (Client → Admin)', 'fail', 'No frames found in last 5 minutes - client may not be sending frames');
    }
} else {
    addTestResult('Frame Flow (Client → Admin)', 'warning', 'Could not query frame data');
}

// Test 4: Check for input messages (admin → client)
$input_sql = "SELECT COUNT(*) as count, 
              MAX(created_at) as last_input,
              MIN(created_at) as first_input
              FROM relay_messages 
              WHERE message_type = 'input' 
              AND session_id IN (
                  SELECT session_id FROM sessions WHERE code = '$escaped_code' OR session_id = '$escaped_session_id'
              )
              AND created_at > " . (time() - 300) . "  -- Last 5 minutes";

$input_result = Database::query($input_sql);
if ($input_result && $input_result->num_rows > 0) {
    $input_data = $input_result->fetch_assoc();
    $input_count = intval($input_data['count']);
    
    if ($input_count > 0) {
        addTestResult('Input Flow (Admin → Client)', 'pass', "Found $input_count input events in last 5 minutes", [
            'count' => $input_count,
            'last_input' => $input_data['last_input'] ? date('Y-m-d H:i:s', $input_data['last_input']) : null
        ]);
    } else {
        addTestResult('Input Flow (Admin → Client)', 'warning', 'No input events found - admin may not be sending input');
    }
}

// Test 5: Check for cursor position messages
$cursor_sql = "SELECT COUNT(*) as count, 
               MAX(created_at) as last_cursor
               FROM relay_messages 
               WHERE message_type = 'cursor' 
               AND session_id IN (
                   SELECT session_id FROM sessions WHERE code = '$escaped_code' OR session_id = '$escaped_session_id'
               )
               AND created_at > " . (time() - 300);

$cursor_result = Database::query($cursor_sql);
if ($cursor_result && $cursor_result->num_rows > 0) {
    $cursor_data = $cursor_result->fetch_assoc();
    $cursor_count = intval($cursor_data['count']);
    
    if ($cursor_count > 0) {
        addTestResult('Cursor Flow', 'pass', "Found $cursor_count cursor position updates in last 5 minutes", [
            'count' => $cursor_count,
            'last_cursor' => $cursor_data['last_cursor'] ? date('Y-m-d H:i:s', $cursor_data['last_cursor']) : null
        ]);
    } else {
        addTestResult('Cursor Flow', 'warning', 'No cursor position updates found');
    }
}

// Test 6: Check recent signals
$signal_sql = "SELECT signal_type, COUNT(*) as count, MAX(created_at) as last_signal
               FROM signals 
               WHERE session_id = '$escaped_session_id' OR code = '$escaped_code'
               AND created_at > " . (time() - 300) . "
               GROUP BY signal_type
               ORDER BY last_signal DESC";

$signal_result = Database::query($signal_sql);
$signals = [];
if ($signal_result && $signal_result->num_rows > 0) {
    while ($row = $signal_result->fetch_assoc()) {
        $signals[] = [
            'type' => $row['signal_type'],
            'count' => intval($row['count']),
            'last_signal' => date('Y-m-d H:i:s', $row['last_signal'])
        ];
    }
    addTestResult('Signals', 'pass', 'Found signals in last 5 minutes', $signals);
} else {
    addTestResult('Signals', 'warning', 'No signals found in last 5 minutes');
}

// Test 7: Check for unread relay messages (indicates admin not polling)
if (isset($peer_id) && $peer_id) {
    $escaped_peer_id = Database::escape($peer_id);
    $unread_sql = "SELECT COUNT(*) as count FROM relay_messages 
                   WHERE session_id = '$escaped_peer_id' 
                   AND message_type = 'frame'
                   AND created_at > " . (time() - 60);
    
    $unread_result = Database::query($unread_sql);
    if ($unread_result && $unread_result->num_rows > 0) {
        $unread_data = $unread_result->fetch_assoc();
        $unread_count = intval($unread_data['count']);
        
        if ($unread_count > 10) {
            addTestResult('Unread Messages', 'warning', "Found $unread_count unread frames (admin may not be polling)", [
                'count' => $unread_count,
                'message' => 'High unread count suggests admin is not receiving frames'
            ]);
        } else {
            addTestResult('Unread Messages', 'pass', "Unread frame count is normal ($unread_count)", [
                'count' => $unread_count
            ]);
        }
    }
}

// Test 8: Recent activity check
// Check if last_activity column exists (may not be in all database versions)
$activity_sql = "SELECT mode, COUNT(*) as count, MAX(created_at) as last_created";
// Try to include last_activity if column exists
try {
    $test_sql = "SELECT last_activity FROM sessions LIMIT 1";
    $test_result = Database::query($test_sql);
    if ($test_result) {
        $activity_sql .= ", MAX(last_activity) as last_activity";
    }
} catch (Exception $e) {
    // Column doesn't exist, use created_at instead
}

$activity_sql .= " FROM sessions 
                 WHERE code = '$escaped_code' OR session_id = '$escaped_session_id'
                 GROUP BY mode";

$activity_result = Database::query($activity_sql);
$activity = [];
if ($activity_result && $activity_result->num_rows > 0) {
    while ($row = $activity_result->fetch_assoc()) {
        $activity_item = [
            'mode' => $row['mode'],
            'count' => intval($row['count']),
            'last_created' => date('Y-m-d H:i:s', $row['last_created'])
        ];
        if (isset($row['last_activity']) && $row['last_activity']) {
            $activity_item['last_activity'] = date('Y-m-d H:i:s', $row['last_activity']);
        }
        $activity[] = $activity_item;
    }
    addTestResult('Session Activity', 'pass', 'Session activity found', $activity);
}

// Summary
$pass_count = 0;
$fail_count = 0;
$warning_count = 0;

foreach ($results['tests'] as $test) {
    if ($test['status'] === 'pass') $pass_count++;
    elseif ($test['status'] === 'fail') $fail_count++;
    elseif ($test['status'] === 'warning') $warning_count++;
}

$results['summary'] = [
    'total_tests' => count($results['tests']),
    'passed' => $pass_count,
    'failed' => $fail_count,
    'warnings' => $warning_count
];

// Recommendations
$recommendations = [];
if ($fail_count > 0) {
    $recommendations[] = 'Some tests failed - check the detailed results above';
}
if (isset($results['estimated_fps']) && $results['estimated_fps'] < 10) {
    $recommendations[] = 'Low FPS detected - check network connection and server performance';
}
if ($warning_count > 2) {
    $recommendations[] = 'Multiple warnings detected - verify client and admin are both connected';
}

$results['recommendations'] = $recommendations;

echo json_encode($results, JSON_PRETTY_PRINT);

Database::close();
?>

