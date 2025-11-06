<?php
/**
 * ShareFast Real-Time Status API
 * Access via: curl https://sharefast.zip/api/status.php
 * Or view in browser: https://sharefast.zip/api/status.php?format=html
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Get format parameter (json or html)
$format = isset($_GET['format']) ? $_GET['format'] : 'json';

$conn = Database::getConnection();
if (!$conn) {
    if ($format === 'html') {
        header('Content-Type: text/html');
        echo "<html><body><h1>Error</h1><p>Database connection failed</p></body></html>";
    } else {
        echo json_encode(['error' => 'Database connection failed'], JSON_PRETTY_PRINT);
    }
    exit;
}

$status = array(
    'timestamp' => time(),
    'datetime' => date('Y-m-d H:i:s'),
    'sessions' => array(),
    'relay_messages' => array(),
    'statistics' => array()
);

// Get all active sessions (connected within last 5 minutes)
$current_time = time();
$recent_sessions_sql = "SELECT id, session_id, code, mode, peer_id, ip_address, port, connected, 
                         created_at, expires_at, last_keepalive 
                         FROM sessions 
                         WHERE expires_at > $current_time 
                         ORDER BY created_at DESC 
                         LIMIT 50";
$sessions_result = Database::query($recent_sessions_sql);

if ($sessions_result && $sessions_result->num_rows > 0) {
    while ($row = $sessions_result->fetch_assoc()) {
        $session = array(
            'id' => intval($row['id']),
            'session_id' => $row['session_id'],
            'code' => $row['code'],
            'mode' => $row['mode'],
            'peer_id' => $row['peer_id'],
            'ip_address' => $row['ip_address'],
            'port' => intval($row['port']),
            'connected' => $row['connected'] ? true : false,
            'created_at' => intval($row['created_at']),
            'created_datetime' => date('Y-m-d H:i:s', $row['created_at']),
            'expires_at' => intval($row['expires_at']),
            'last_keepalive' => $row['last_keepalive'] ? intval($row['last_keepalive']) : null,
            'last_keepalive_datetime' => $row['last_keepalive'] ? date('Y-m-d H:i:s', $row['last_keepalive']) : null,
            'age_seconds' => $current_time - intval($row['created_at']),
            'is_linked' => !empty($row['peer_id'])
        );
        $status['sessions'][] = $session;
    }
}

// Get linked sessions (pairs)
$linked_sessions = array();
foreach ($status['sessions'] as $session) {
    if ($session['is_linked'] && $session['connected']) {
        $peer_id = $session['peer_id'];
        // Find peer session
        foreach ($status['sessions'] as $peer_session) {
            if ($peer_session['session_id'] === $peer_id && $peer_session['connected']) {
                $linked_sessions[] = array(
                    'client' => $session['mode'] === 'client' ? $session : $peer_session,
                    'admin' => $session['mode'] === 'admin' ? $session : $peer_session,
                    'code' => $session['code']
                );
                break;
            }
        }
    }
}
$status['linked_pairs'] = array_values(array_unique($linked_sessions, SORT_REGULAR));

// Get recent relay messages (last 100)
$relay_sql = "SELECT id, session_id, message_type, LENGTH(message_data) as data_size, 
              created_at, read_at 
              FROM relay_messages 
              ORDER BY created_at DESC 
              LIMIT 100";
$relay_result = Database::query($relay_sql);

$message_stats = array('frame' => 0, 'input' => 0, 'total' => 0, 'total_bytes' => 0);
$recent_messages = array();

if ($relay_result && $relay_result->num_rows > 0) {
    while ($row = $relay_result->fetch_assoc()) {
        $msg_type = $row['message_type'];
        $data_size = intval($row['data_size']);
        
        $message_stats[$msg_type] = isset($message_stats[$msg_type]) ? $message_stats[$msg_type] + 1 : 1;
        $message_stats['total']++;
        $message_stats['total_bytes'] += $data_size;
        
        $message = array(
            'id' => intval($row['id']),
            'session_id' => $row['session_id'],
            'type' => $msg_type,
            'data_size' => $data_size,
            'data_size_formatted' => format_bytes($data_size),
            'created_at' => intval($row['created_at']),
            'created_datetime' => date('Y-m-d H:i:s', $row['created_at']),
            'read_at' => $row['read_at'] ? intval($row['read_at']) : null,
            'is_read' => !empty($row['read_at']),
            'age_seconds' => $current_time - intval($row['created_at'])
        );
        
        // Only include unread messages in recent_messages for cleaner output
        if (empty($row['read_at'])) {
            $recent_messages[] = $message;
        }
    }
}

$status['relay_messages'] = array(
    'recent_unread' => array_slice($recent_messages, 0, 20), // Last 20 unread
    'statistics' => $message_stats
);

// Get messages by type summary
$type_summary_sql = "SELECT message_type, COUNT(*) as count, SUM(LENGTH(message_data)) as total_bytes 
                     FROM relay_messages 
                     WHERE created_at > " . ($current_time - 300) . " 
                     GROUP BY message_type";
$type_summary_result = Database::query($type_summary_sql);

$status['relay_messages']['by_type_last_5min'] = array();
if ($type_summary_result && $type_summary_result->num_rows > 0) {
    while ($row = $type_summary_result->fetch_assoc()) {
        $status['relay_messages']['by_type_last_5min'][$row['message_type']] = array(
            'count' => intval($row['count']),
            'total_bytes' => intval($row['total_bytes']),
            'total_bytes_formatted' => format_bytes(intval($row['total_bytes']))
        );
    }
}

// Overall statistics
$status['statistics'] = array(
    'total_sessions' => count($status['sessions']),
    'connected_sessions' => count(array_filter($status['sessions'], function($s) { return $s['connected']; })),
    'linked_pairs' => count($status['linked_pairs']),
    'active_clients' => count(array_filter($status['sessions'], function($s) { return $s['mode'] === 'client' && $s['connected']; })),
    'active_admins' => count(array_filter($status['sessions'], function($s) { return $s['mode'] === 'admin' && $s['connected']; })),
    'total_relay_messages' => $message_stats['total'],
    'total_relay_bytes' => $message_stats['total_bytes'],
    'total_relay_bytes_formatted' => format_bytes($message_stats['total_bytes']),
    'frames_sent' => $message_stats['frame'],
    'inputs_sent' => $message_stats['input']
);

// Helper function to format bytes
function format_bytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Output based on format
if ($format === 'html') {
    header('Content-Type: text/html');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>ShareFast Real-Time Status</title>
        <style>
            body { font-family: 'Segoe UI', Arial, sans-serif; margin: 20px; background: #f5f5f5; }
            .container { max-width: 1400px; margin: 0 auto; }
            .header { background: #667eea; color: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
            .section { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            h1 { margin: 0; }
            h2 { margin-top: 0; color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
            table { width: 100%; border-collapse: collapse; margin-top: 10px; }
            th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
            th { background: #f0f0f0; font-weight: bold; }
            tr:hover { background: #f9f9f9; }
            .badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
            .badge-client { background: #667eea; color: white; }
            .badge-admin { background: #764ba2; color: white; }
            .badge-connected { background: #28a745; color: white; }
            .badge-disconnected { background: #dc3545; color: white; }
            .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px; }
            .stat-card { background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #667eea; }
            .stat-value { font-size: 24px; font-weight: bold; color: #667eea; }
            .stat-label { font-size: 12px; color: #666; margin-top: 5px; }
            .refresh-btn { background: #667eea; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-size: 14px; }
            .refresh-btn:hover { background: #5568d3; }
            .timestamp { color: #666; font-size: 12px; }
        </style>
        <script>
            function refresh() {
                location.reload();
            }
            // Auto-refresh every 5 seconds
            setTimeout(refresh, 5000);
        </script>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>🌵 ShareFast Real-Time Status</h1>
                <p class="timestamp">Last updated: <?php echo $status['datetime']; ?></p>
                <button class="refresh-btn" onclick="refresh()">🔄 Refresh</button>
            </div>
            
            <div class="section">
                <h2>📊 Statistics</h2>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $status['statistics']['total_sessions']; ?></div>
                        <div class="stat-label">Total Sessions</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $status['statistics']['connected_sessions']; ?></div>
                        <div class="stat-label">Connected</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $status['statistics']['linked_pairs']; ?></div>
                        <div class="stat-label">Active Connections</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $status['statistics']['active_clients']; ?></div>
                        <div class="stat-label">Active Clients</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $status['statistics']['active_admins']; ?></div>
                        <div class="stat-label">Active Admins</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $status['statistics']['frames_sent']; ?></div>
                        <div class="stat-label">Frames Sent</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $status['statistics']['total_relay_bytes_formatted']; ?></div>
                        <div class="stat-label">Total Data Relayed</div>
                    </div>
                </div>
            </div>
            
            <div class="section">
                <h2>🔗 Active Connections (<?php echo count($status['linked_pairs']); ?>)</h2>
                <?php if (empty($status['linked_pairs'])): ?>
                    <p>No active connections</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Client Session</th>
                                <th>Admin Session</th>
                                <th>Created</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($status['linked_pairs'] as $pair): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($pair['code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars(substr($pair['client']['session_id'], 0, 30)) . '...'; ?></td>
                                    <td><?php echo htmlspecialchars(substr($pair['admin']['session_id'], 0, 30)) . '...'; ?></td>
                                    <td><?php echo $pair['client']['created_datetime']; ?></td>
                                    <td>
                                        <span class="badge badge-connected">Connected</span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <div class="section">
                <h2>📨 Recent Relay Messages (Last 20 Unread)</h2>
                <?php if (empty($status['relay_messages']['recent_unread'])): ?>
                    <p>No recent unread messages</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Session</th>
                                <th>Type</th>
                                <th>Size</th>
                                <th>Age</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($status['relay_messages']['recent_unread'] as $msg): ?>
                                <tr>
                                    <td><?php echo $msg['id']; ?></td>
                                    <td><?php echo htmlspecialchars(substr($msg['session_id'], 0, 30)) . '...'; ?></td>
                                    <td><strong><?php echo htmlspecialchars($msg['type']); ?></strong></td>
                                    <td><?php echo $msg['data_size_formatted']; ?></td>
                                    <td><?php echo $msg['age_seconds']; ?>s ago</td>
                                    <td><?php echo $msg['is_read'] ? '<span class="badge badge-connected">Read</span>' : '<span class="badge badge-disconnected">Unread</span>'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <div class="section">
                <h2>📈 Message Statistics (Last 5 Minutes)</h2>
                <?php if (empty($status['relay_messages']['by_type_last_5min'])): ?>
                    <p>No messages in the last 5 minutes</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Count</th>
                                <th>Total Size</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($status['relay_messages']['by_type_last_5min'] as $type => $stats): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($type); ?></strong></td>
                                    <td><?php echo $stats['count']; ?></td>
                                    <td><?php echo $stats['total_bytes_formatted']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <div class="section">
                <h2>🌐 Usage</h2>
                <p><strong>JSON API:</strong> <code>curl https://sharefast.zip/api/status.php</code></p>
                <p><strong>HTML View:</strong> <code>https://sharefast.zip/api/status.php?format=html</code></p>
                <p><strong>Auto-refresh:</strong> Page refreshes every 5 seconds</p>
            </div>
        </div>
    </body>
    </html>
    <?php
} else {
    // JSON output
    echo json_encode($status, JSON_PRETTY_PRINT);
}
?>

