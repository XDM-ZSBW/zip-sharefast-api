<?php
/**
 * Test endpoint - uses same auth as application, should bypass ModSecurity
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

// Get recent signals
$sql = "SELECT id, session_id, code, signal_type, created_at, read_at FROM signals ORDER BY created_at DESC LIMIT 20";
$result = Database::query($sql);

$signals = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $signals[] = [
            'id' => $row['id'],
            'session_id' => $row['session_id'],
            'code' => $row['code'],
            'type' => $row['signal_type'],
            'created_at' => date('Y-m-d H:i:s', $row['created_at']),
            'read_at' => $row['read_at'] ? date('Y-m-d H:i:s', $row['read_at']) : null
        ];
    }
}

echo json_encode([
    'success' => true,
    'signals' => $signals,
    'count' => count($signals)
], JSON_PRETTY_PRINT);

Database::close();
?>

