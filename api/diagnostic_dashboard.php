<?php
/**
 * Real-Time Diagnostic Dashboard
 * Shows live frame flow, diagnostic system status, and auto-fix attempts
 * 
 * Usage:
 * - HTML Dashboard: https://sharefast.zip/api/diagnostic_dashboard.php?code=eagle-hill
 * - JSON API: https://sharefast.zip/api/diagnostic_dashboard.php?code=eagle-hill&format=json
 * - Auto-refresh: https://sharefast.zip/api/diagnostic_dashboard.php?code=eagle-hill&refresh=5
 */

header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

$code = isset($_GET['code']) ? $_GET['code'] : null;
$format = isset($_GET['format']) ? $_GET['format'] : 'html';
$refresh_interval = isset($_GET['refresh']) ? intval($_GET['refresh']) : 5;

// Get all active sessions for dropdown (if HTML format)
$active_sessions = [];
if ($format === 'html') {
    $active_sessions_sql = "SELECT DISTINCT code, MAX(created_at) as last_activity, COUNT(*) as session_count
                            FROM sessions 
                            WHERE expires_at > " . time() . "
                            GROUP BY code
                            ORDER BY last_activity DESC
                            LIMIT 50";
    $active_sessions_result = Database::query($active_sessions_sql);
    if ($active_sessions_result && $active_sessions_result->num_rows > 0) {
        while ($row = $active_sessions_result->fetch_assoc()) {
            $active_sessions[] = [
                'code' => $row['code'],
                'last_activity' => date('Y-m-d H:i:s', $row['last_activity']),
                'session_count' => intval($row['session_count'])
            ];
        }
    }
}

if (!$code && $format === 'html' && !empty($active_sessions)) {
    // If no code specified but we have active sessions, use the most recent one
    $code = $active_sessions[0]['code'];
} elseif (!$code) {
    if ($format === 'json') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Code parameter required'], JSON_PRETTY_PRINT);
    } else {
        header('Content-Type: text/html');
        echo "<html><body><h1>ShareFast Diagnostic Dashboard</h1><p>No active sessions found. <a href='?action=generate'>Generate a test session</a></p></body></html>";
    }
    exit;
}

$escaped_code = Database::escape($code);

// Get session information
$session_sql = "SELECT * FROM sessions WHERE code = '$escaped_code' ORDER BY created_at DESC LIMIT 2";
$session_result = Database::query($session_sql);

$sessions = [];
$client_session = null;
$admin_session = null;

if ($session_result && $session_result->num_rows > 0) {
    while ($row = $session_result->fetch_assoc()) {
        $sessions[] = $row;
        if ($row['mode'] === 'client') {
            $client_session = $row;
        } elseif ($row['mode'] === 'admin') {
            $admin_session = $row;
        }
    }
}

$client_session_id = $client_session ? $client_session['session_id'] : null;
$admin_session_id = $admin_session ? $admin_session['session_id'] : null;

// Get frame statistics (last 5 minutes)
$current_time = time();
$frame_sql = "SELECT 
    COUNT(*) as total_frames,
    MAX(created_at) as last_frame_time,
    MIN(created_at) as first_frame_time,
    AVG(LENGTH(message_data)) as avg_frame_size,
    SUM(LENGTH(message_data)) as total_bytes
    FROM relay_messages 
    WHERE message_type = 'frame' 
    AND session_id IN (
        SELECT session_id FROM sessions WHERE code = '$escaped_code'
    )
    AND created_at > " . ($current_time - 300);

$frame_result = Database::query($frame_sql);
$frame_stats = null;
if ($frame_result && $frame_result->num_rows > 0) {
    $frame_stats = $frame_result->fetch_assoc();
}

// Calculate FPS if we have frame data
$estimated_fps = 0.0;
if ($frame_stats && $frame_stats['first_frame_time'] && $frame_stats['last_frame_time'] && $frame_stats['total_frames'] > 1) {
    $time_span = $frame_stats['last_frame_time'] - $frame_stats['first_frame_time'];
    if ($time_span > 0) {
        $estimated_fps = round($frame_stats['total_frames'] / $time_span, 2);
    }
}

// Get recent frames (last 30 seconds) for real-time monitoring
$recent_frames_sql = "SELECT 
    id, created_at, LENGTH(message_data) as size
    FROM relay_messages 
    WHERE message_type = 'frame' 
    AND session_id IN (
        SELECT session_id FROM sessions WHERE code = '$escaped_code'
    )
    AND created_at > " . ($current_time - 30) . "
    ORDER BY created_at DESC
    LIMIT 100";

$recent_frames_result = Database::query($recent_frames_sql);
$recent_frames = [];
if ($recent_frames_result && $recent_frames_result->num_rows > 0) {
    while ($row = $recent_frames_result->fetch_assoc()) {
        $recent_frames[] = [
            'id' => $row['id'],
            'time' => $row['created_at'],
            'age_seconds' => $current_time - $row['created_at'],
            'size' => intval($row['size'])
        ];
    }
}

// Get input events
$input_sql = "SELECT COUNT(*) as count, MAX(created_at) as last_input
    FROM relay_messages 
    WHERE message_type = 'input' 
    AND session_id IN (
        SELECT session_id FROM sessions WHERE code = '$escaped_code'
    )
    AND created_at > " . ($current_time - 300);

$input_result = Database::query($input_sql);
$input_stats = null;
if ($input_result && $input_result->num_rows > 0) {
    $input_stats = $input_result->fetch_assoc();
}

// Get cursor positions
$cursor_sql = "SELECT COUNT(*) as count, MAX(created_at) as last_cursor
    FROM relay_messages 
    WHERE message_type = 'cursor' 
    AND session_id IN (
        SELECT session_id FROM sessions WHERE code = '$escaped_code'
    )
    AND created_at > " . ($current_time - 300);

$cursor_result = Database::query($cursor_sql);
$cursor_stats = null;
if ($cursor_result && $cursor_result->num_rows > 0) {
    $cursor_stats = $cursor_result->fetch_assoc();
}

// Get signals
$signal_sql = "SELECT signal_type, created_at, read_at
    FROM signals 
    WHERE code = '$escaped_code' OR session_id IN (
        SELECT session_id FROM sessions WHERE code = '$escaped_code'
    )
    AND created_at > " . ($current_time - 300) . "
    ORDER BY created_at DESC
    LIMIT 20";

$signal_result = Database::query($signal_sql);
$signals = [];
if ($signal_result && $signal_result->num_rows > 0) {
    while ($row = $signal_result->fetch_assoc()) {
        $signals[] = [
            'type' => $row['signal_type'],
            'created_at' => $row['created_at'],
            'read_at' => $row['read_at'],
            'age_seconds' => $current_time - $row['created_at'],
            'is_read' => !empty($row['read_at'])
        ];
    }
}

// Prepare diagnostic data
$diagnostic_data = [
    'code' => $code,
    'timestamp' => date('Y-m-d H:i:s'),
    'sessions' => [
        'client' => $client_session ? [
            'session_id' => $client_session['session_id'],
            'created_at' => date('Y-m-d H:i:s', $client_session['created_at']),
            'connected' => $client_session['connected']
        ] : null,
        'admin' => $admin_session ? [
            'session_id' => $admin_session['session_id'],
            'created_at' => date('Y-m-d H:i:s', $admin_session['created_at']),
            'connected' => $admin_session['connected']
        ] : null
    ],
    'frame_flow' => [
        'status' => $frame_stats && $frame_stats['total_frames'] > 0 ? 'active' : 'inactive',
        'total_frames_5min' => $frame_stats ? intval($frame_stats['total_frames']) : 0,
        'estimated_fps' => $estimated_fps,
        'last_frame' => $frame_stats && $frame_stats['last_frame_time'] ? [
            'time' => date('Y-m-d H:i:s', $frame_stats['last_frame_time']),
            'age_seconds' => $current_time - $frame_stats['last_frame_time']
        ] : null,
        'avg_frame_size_kb' => $frame_stats ? round(intval($frame_stats['avg_frame_size']) / 1024, 2) : 0,
        'recent_frames_30s' => count($recent_frames),
        'frames_per_second_recent' => count($recent_frames) > 0 ? round(count($recent_frames) / 30, 2) : 0
    ],
    'input_flow' => [
        'total_inputs_5min' => $input_stats ? intval($input_stats['count']) : 0,
        'last_input' => $input_stats && $input_stats['last_input'] ? [
            'time' => date('Y-m-d H:i:s', $input_stats['last_input']),
            'age_seconds' => $current_time - $input_stats['last_input']
        ] : null
    ],
    'cursor_flow' => [
        'total_updates_5min' => $cursor_stats ? intval($cursor_stats['count']) : 0,
        'last_update' => $cursor_stats && $cursor_stats['last_cursor'] ? [
            'time' => date('Y-m-d H:i:s', $cursor_stats['last_cursor']),
            'age_seconds' => $current_time - $cursor_stats['last_cursor']
        ] : null
    ],
    'signals' => $signals,
    'diagnostic_status' => [
        'frame_flow_healthy' => $frame_stats && $frame_stats['total_frames'] > 0 && ($current_time - $frame_stats['last_frame_time']) < 10,
        'connection_active' => ($client_session && $client_session['connected']) && ($admin_session && $admin_session['connected']),
        'fps_acceptable' => $estimated_fps >= 10
    ]
];

// Output based on format
if ($format === 'json') {
    header('Content-Type: application/json');
    echo json_encode($diagnostic_data, JSON_PRETTY_PRINT);
} else {
    header('Content-Type: text/html');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>ShareFast Diagnostic Dashboard - <?php echo htmlspecialchars($code); ?></title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { 
                font-family: 'Segoe UI', Arial, sans-serif; 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                padding: 20px;
            }
            .container { max-width: 1400px; margin: 0 auto; }
            .header { 
                background: white; 
                padding: 30px; 
                border-radius: 12px; 
                margin-bottom: 20px; 
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            }
            .header h1 { color: #667eea; margin-bottom: 10px; }
            .header .code { font-size: 24px; font-weight: bold; color: #764ba2; }
            .header .timestamp { color: #666; font-size: 14px; margin-top: 10px; }
            .grid { 
                display: grid; 
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); 
                gap: 20px; 
                margin-bottom: 20px;
            }
            .card { 
                background: white; 
                padding: 25px; 
                border-radius: 12px; 
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            }
            .card h2 { 
                color: #333; 
                margin-bottom: 15px; 
                font-size: 18px;
                border-bottom: 2px solid #667eea;
                padding-bottom: 10px;
            }
            .status-indicator {
                display: inline-block;
                width: 12px;
                height: 12px;
                border-radius: 50%;
                margin-right: 8px;
            }
            .status-active { background: #28a745; }
            .status-inactive { background: #dc3545; }
            .status-warning { background: #ffc107; }
            .stat-row {
                display: flex;
                justify-content: space-between;
                padding: 8px 0;
                border-bottom: 1px solid #eee;
            }
            .stat-label { color: #666; }
            .stat-value { font-weight: bold; color: #333; }
            .fps-display {
                font-size: 32px;
                font-weight: bold;
                color: #667eea;
                text-align: center;
                margin: 15px 0;
            }
            .fps-good { color: #28a745; }
            .fps-warning { color: #ffc107; }
            .fps-bad { color: #dc3545; }
            .refresh-btn {
                background: #667eea;
                color: white;
                border: none;
                padding: 12px 24px;
                border-radius: 6px;
                cursor: pointer;
                font-size: 14px;
                margin-top: 10px;
            }
            .refresh-btn:hover { background: #5568d3; }
            .generate-btn {
                background: #28a745;
                color: white;
                border: none;
                padding: 12px 24px;
                border-radius: 6px;
                cursor: pointer;
                font-size: 14px;
                margin-left: 10px;
            }
            .generate-btn:hover { background: #218838; }
            .generate-btn:disabled { background: #6c757d; cursor: not-allowed; }
            .terminate-btn {
                background: #dc3545;
                color: white;
                border: none;
                padding: 12px 24px;
                border-radius: 6px;
                cursor: pointer;
                font-size: 14px;
                margin-left: 10px;
            }
            .terminate-btn:hover { background: #c82333; }
            .terminate-btn:disabled { background: #6c757d; cursor: not-allowed; }
            .session-selector {
                margin-top: 15px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .session-selector label {
                font-weight: bold;
                color: #333;
            }
            .session-selector select {
                padding: 8px 12px;
                border: 2px solid #667eea;
                border-radius: 6px;
                font-size: 14px;
                min-width: 200px;
            }
            .session-selector select:focus {
                outline: none;
                border-color: #764ba2;
            }
            .recent-frames {
                max-height: 300px;
                overflow-y: auto;
            }
            .frame-item {
                padding: 8px;
                margin: 4px 0;
                background: #f8f9fa;
                border-radius: 4px;
                font-size: 12px;
                display: flex;
                justify-content: space-between;
            }
            .signal-item {
                padding: 8px;
                margin: 4px 0;
                background: #f8f9fa;
                border-radius: 4px;
                font-size: 12px;
            }
            .signal-read { opacity: 0.6; }
        </style>
        <script>
            function refresh() {
                location.reload();
            }
            
            function generateSession() {
                const btn = document.getElementById('generate-btn');
                btn.disabled = true;
                btn.textContent = 'Generating...';
                
                fetch('generate_test_session.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Redirect to the new session's diagnostic page
                        window.location.href = 'diagnostic_dashboard.php?code=' + encodeURIComponent(data.code);
                    } else {
                        alert('Error generating session: ' + (data.error || 'Unknown error'));
                        btn.disabled = false;
                        btn.textContent = '🔄 Generate Test Session';
                    }
                })
                .catch(error => {
                    alert('Error: ' + error.message);
                    btn.disabled = false;
                    btn.textContent = '🔄 Generate Test Session';
                });
            }
            
            function changeSession() {
                const select = document.getElementById('session-select');
                const selectedCode = select.value;
                if (selectedCode) {
                    window.location.href = 'diagnostic_dashboard.php?code=' + encodeURIComponent(selectedCode);
                }
            }
            
            function terminateSession() {
                const code = '<?php echo htmlspecialchars($code, ENT_QUOTES); ?>';
                if (!confirm('Are you sure you want to terminate this session?\n\nThis will permanently delete:\n- All session data\n- All frames, inputs, and cursor positions\n- All signals\n\nThis action cannot be undone.')) {
                    return;
                }
                
                const btn = document.getElementById('terminate-btn');
                btn.disabled = true;
                btn.textContent = 'Terminating...';
                
                // Create form data
                const formData = new FormData();
                formData.append('code', code);
                
                fetch('terminate_session.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Session terminated successfully!\n\nDeleted:\n' +
                              '- ' + data.deleted.sessions + ' session(s)\n' +
                              '- ' + data.deleted.relay_messages + ' relay message(s)\n' +
                              '- ' + data.deleted.signals + ' signal(s)\n\n' +
                              'Redirecting to dashboard...');
                        // Redirect to dashboard without code (will show active sessions or generate new)
                        window.location.href = 'diagnostic_dashboard.php';
                    } else {
                        alert('Error terminating session: ' + (data.error || 'Unknown error'));
                        btn.disabled = false;
                        btn.textContent = '🗑️ Terminate Session';
                    }
                })
                .catch(error => {
                    alert('Error: ' + error.message);
                    btn.disabled = false;
                    btn.textContent = '🗑️ Terminate Session';
                });
            }
            
            // Auto-refresh
            setTimeout(refresh, <?php echo $refresh_interval * 1000; ?>);
        </script>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>🔍 ShareFast Diagnostic Dashboard</h1>
                <div class="code">Code: <?php echo htmlspecialchars($code); ?></div>
                <div class="timestamp">Last updated: <?php echo $diagnostic_data['timestamp']; ?> (auto-refresh every <?php echo $refresh_interval; ?>s)</div>
                <div style="margin-top: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
                    <button class="refresh-btn" onclick="refresh()">🔄 Refresh Now</button>
                    <button class="generate-btn" id="generate-btn" onclick="generateSession()">🔄 Generate Test Session</button>
                    <button class="terminate-btn" id="terminate-btn" onclick="terminateSession()">🗑️ Terminate Session</button>
                </div>
                <div class="session-selector">
                    <label for="session-select">Active Sessions:</label>
                    <select id="session-select" onchange="changeSession()">
                        <option value="">-- Select Session --</option>
                        <?php foreach ($active_sessions as $session): ?>
                            <option value="<?php echo htmlspecialchars($session['code']); ?>" 
                                    <?php echo $session['code'] === $code ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($session['code']); ?> 
                                (<?php echo $session['session_count']; ?> sessions, <?php echo $session['last_activity']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="grid">
                <!-- Frame Flow Status -->
                <div class="card">
                    <h2>📹 Frame Flow Status</h2>
                    <div class="stat-row">
                        <span class="stat-label">Status:</span>
                        <span class="stat-value">
                            <span class="status-indicator <?php echo $diagnostic_data['frame_flow']['status'] === 'active' ? 'status-active' : 'status-inactive'; ?>"></span>
                            <?php echo strtoupper($diagnostic_data['frame_flow']['status']); ?>
                        </span>
                    </div>
                    <div class="fps-display <?php 
                        echo $diagnostic_data['frame_flow']['estimated_fps'] >= 30 ? 'fps-good' : 
                            ($diagnostic_data['frame_flow']['estimated_fps'] >= 10 ? 'fps-warning' : 'fps-bad'); 
                    ?>">
                        <?php echo $diagnostic_data['frame_flow']['estimated_fps']; ?> FPS
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">Frames (5 min):</span>
                        <span class="stat-value"><?php echo number_format($diagnostic_data['frame_flow']['total_frames_5min']); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">Frames (30s):</span>
                        <span class="stat-value"><?php echo $diagnostic_data['frame_flow']['recent_frames_30s']; ?> (<?php echo $diagnostic_data['frame_flow']['frames_per_second_recent']; ?> fps)</span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">Avg Frame Size:</span>
                        <span class="stat-value"><?php echo $diagnostic_data['frame_flow']['avg_frame_size_kb']; ?> KB</span>
                    </div>
                    <?php if ($diagnostic_data['frame_flow']['last_frame']): ?>
                    <div class="stat-row">
                        <span class="stat-label">Last Frame:</span>
                        <span class="stat-value"><?php echo $diagnostic_data['frame_flow']['last_frame']['age_seconds']; ?>s ago</span>
                    </div>
                    <?php else: ?>
                    <div class="stat-row">
                        <span class="stat-label">Last Frame:</span>
                        <span class="stat-value" style="color: #dc3545;">Never</span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Connection Status -->
                <div class="card">
                    <h2>🔗 Connection Status</h2>
                    <div class="stat-row">
                        <span class="stat-label">Client:</span>
                        <span class="stat-value">
                            <?php if ($diagnostic_data['sessions']['client']): ?>
                                <span class="status-indicator <?php echo $diagnostic_data['sessions']['client']['connected'] ? 'status-active' : 'status-inactive'; ?>"></span>
                                <?php echo $diagnostic_data['sessions']['client']['connected'] ? 'Connected' : 'Disconnected'; ?>
                            <?php else: ?>
                                <span class="status-indicator status-inactive"></span>
                                Not Found
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">Admin:</span>
                        <span class="stat-value">
                            <?php if ($diagnostic_data['sessions']['admin']): ?>
                                <span class="status-indicator <?php echo $diagnostic_data['sessions']['admin']['connected'] ? 'status-active' : 'status-inactive'; ?>"></span>
                                <?php echo $diagnostic_data['sessions']['admin']['connected'] ? 'Connected' : 'Disconnected'; ?>
                            <?php else: ?>
                                <span class="status-indicator status-inactive"></span>
                                Not Found
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">Connection Active:</span>
                        <span class="stat-value">
                            <span class="status-indicator <?php echo $diagnostic_data['diagnostic_status']['connection_active'] ? 'status-active' : 'status-inactive'; ?>"></span>
                            <?php echo $diagnostic_data['diagnostic_status']['connection_active'] ? 'Yes' : 'No'; ?>
                        </span>
                    </div>
                </div>
                
                <!-- Input & Cursor -->
                <div class="card">
                    <h2>🖱️ Input & Cursor</h2>
                    <div class="stat-row">
                        <span class="stat-label">Input Events (5min):</span>
                        <span class="stat-value"><?php echo $diagnostic_data['input_flow']['total_inputs_5min']; ?></span>
                    </div>
                    <?php if ($diagnostic_data['input_flow']['last_input']): ?>
                    <div class="stat-row">
                        <span class="stat-label">Last Input:</span>
                        <span class="stat-value"><?php echo $diagnostic_data['input_flow']['last_input']['age_seconds']; ?>s ago</span>
                    </div>
                    <?php endif; ?>
                    <div class="stat-row">
                        <span class="stat-label">Cursor Updates (5min):</span>
                        <span class="stat-value"><?php echo $diagnostic_data['cursor_flow']['total_updates_5min']; ?></span>
                    </div>
                    <?php if ($diagnostic_data['cursor_flow']['last_update']): ?>
                    <div class="stat-row">
                        <span class="stat-label">Last Cursor:</span>
                        <span class="stat-value"><?php echo $diagnostic_data['cursor_flow']['last_update']['age_seconds']; ?>s ago</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Recent Frames -->
            <div class="card">
                <h2>📊 Recent Frames (Last 30 seconds)</h2>
                <div class="recent-frames">
                    <?php if (empty($recent_frames)): ?>
                        <p style="color: #dc3545; padding: 20px; text-align: center;">⚠️ No frames detected in last 30 seconds</p>
                    <?php else: ?>
                        <?php foreach (array_slice($recent_frames, 0, 50) as $frame): ?>
                            <div class="frame-item">
                                <span>Frame #<?php echo $frame['id']; ?></span>
                                <span><?php echo $frame['age_seconds']; ?>s ago</span>
                                <span><?php echo round($frame['size'] / 1024, 1); ?> KB</span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Signals -->
            <div class="card">
                <h2>📡 Signals (Last 5 minutes)</h2>
                <?php if (empty($signals)): ?>
                    <p style="color: #ffc107; padding: 20px;">⚠️ No signals found</p>
                <?php else: ?>
                    <?php foreach ($signals as $signal): ?>
                        <div class="signal-item <?php echo $signal['is_read'] ? 'signal-read' : ''; ?>">
                            <strong><?php echo htmlspecialchars($signal['type']); ?></strong>
                            - <?php echo $signal['age_seconds']; ?>s ago
                            <?php echo $signal['is_read'] ? ' (read)' : ' (unread)'; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Diagnostic Status -->
            <div class="card">
                <h2>✅ Diagnostic Status</h2>
                <div class="stat-row">
                    <span class="stat-label">Frame Flow Healthy:</span>
                    <span class="stat-value">
                        <span class="status-indicator <?php echo $diagnostic_data['diagnostic_status']['frame_flow_healthy'] ? 'status-active' : 'status-inactive'; ?>"></span>
                        <?php echo $diagnostic_data['diagnostic_status']['frame_flow_healthy'] ? 'Yes' : 'No'; ?>
                    </span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">FPS Acceptable:</span>
                    <span class="stat-value">
                        <span class="status-indicator <?php echo $diagnostic_data['diagnostic_status']['fps_acceptable'] ? 'status-active' : 'status-warning'; ?>"></span>
                        <?php echo $diagnostic_data['diagnostic_status']['fps_acceptable'] ? 'Yes (≥10 FPS)' : 'No (<10 FPS)'; ?>
                    </span>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
}

Database::close();
?>

