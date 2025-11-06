<?php
/**
 * Test signal routing - simulate admin sending admin_connected signal
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

// Test parameters from logs
$admin_session_id = 'session_690ad730890749.91455521';
$code = 'walk-peach';
$client_session_id = 'session_690ad72840b377.28162636'; // From peer_id

echo "=== Testing Signal Routing ===\n\n";

// 1. Check admin session
echo "1. Checking admin session:\n";
$admin_sql = "SELECT * FROM sessions WHERE session_id = '" . Database::escape($admin_session_id) . "'";
$admin_result = Database::query($admin_sql);
if ($admin_result && $admin_result->num_rows > 0) {
    $admin_row = $admin_result->fetch_assoc();
    echo "   Admin session found:\n";
    echo "   - session_id: " . $admin_row['session_id'] . "\n";
    echo "   - code: " . $admin_row['code'] . "\n";
    echo "   - peer_id: " . ($admin_row['peer_id'] ?? 'NULL') . "\n";
    echo "   - mode: " . $admin_row['mode'] . "\n\n";
} else {
    echo "   Admin session NOT found!\n\n";
}

// 2. Check client session
echo "2. Checking client session:\n";
$client_sql = "SELECT * FROM sessions WHERE session_id = '" . Database::escape($client_session_id) . "'";
$client_result = Database::query($client_sql);
if ($client_result && $client_result->num_rows > 0) {
    $client_row = $client_result->fetch_assoc();
    echo "   Client session found:\n";
    echo "   - session_id: " . $client_row['session_id'] . "\n";
    echo "   - code: " . $client_row['code'] . "\n";
    echo "   - peer_id: " . ($client_row['peer_id'] ?? 'NULL') . "\n";
    echo "   - mode: " . $client_row['mode'] . "\n\n";
} else {
    echo "   Client session NOT found!\n\n";
}

// 3. Check what peer_id signal.php would find
echo "3. Testing signal.php peer lookup:\n";
$escaped_admin_session_id = Database::escape($admin_session_id);
$escaped_code = Database::escape($code);
$peer_sql = "SELECT peer_id FROM sessions WHERE (session_id = '$escaped_admin_session_id' OR code = '$escaped_code') AND peer_id IS NOT NULL LIMIT 1";
$peer_result = Database::query($peer_sql);
if ($peer_result && $peer_result->num_rows > 0) {
    $peer_row = $peer_result->fetch_assoc();
    $found_peer_id = $peer_row['peer_id'];
    echo "   Found peer_id: " . $found_peer_id . "\n";
    echo "   Expected client session_id: " . $client_session_id . "\n";
    if ($found_peer_id === $client_session_id) {
        echo "   ✓ MATCH - Signal would be stored correctly!\n\n";
    } else {
        echo "   ✗ MISMATCH - Signal would be stored for wrong session!\n\n";
    }
} else {
    echo "   ✗ No peer_id found!\n\n";
}

// 4. Check existing signals
echo "4. Checking existing signals:\n";
$signal_sql = "SELECT * FROM signals WHERE session_id IN ('" . Database::escape($admin_session_id) . "', '" . Database::escape($client_session_id) . "') ORDER BY created_at DESC LIMIT 10";
$signal_result = Database::query($signal_sql);
if ($signal_result && $signal_result->num_rows > 0) {
    echo "   Found " . $signal_result->num_rows . " signals:\n";
    while ($row = $signal_result->fetch_assoc()) {
        echo "   - Signal ID: " . $row['id'] . "\n";
        echo "     session_id: " . $row['session_id'] . "\n";
        echo "     code: " . $row['code'] . "\n";
        echo "     type: " . $row['signal_type'] . "\n";
        echo "     created_at: " . date('Y-m-d H:i:s', $row['created_at']) . "\n";
        echo "     read_at: " . ($row['read_at'] ? date('Y-m-d H:i:s', $row['read_at']) : 'NULL') . "\n";
        echo "\n";
    }
} else {
    echo "   No signals found\n\n";
}

Database::close();
?>

