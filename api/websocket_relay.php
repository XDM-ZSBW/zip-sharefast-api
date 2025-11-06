<?php
/**
 * WebSocket Relay Server
 * 
 * Handles WebSocket connections for real-time frame relay
 * Much faster than HTTP polling - enables 30-60 FPS
 */

// WebSocket upgrade handling
function handleWebSocketUpgrade() {
    $key = '';
    $headers = array();
    
    // Parse headers
    foreach ($_SERVER as $name => $value) {
        if (substr($name, 0, 5) == 'HTTP_') {
            $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
        }
    }
    
    if (isset($headers['Sec-Websocket-Key'])) {
        $key = $headers['Sec-Websocket-Key'];
    } else {
        http_response_code(400);
        exit('Invalid WebSocket request');
    }
    
    // Generate accept key
    $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC11B08', true));
    
    // Send upgrade headers
    header('HTTP/1.1 101 Switching Protocols');
    header('Upgrade: websocket');
    header('Connection: Upgrade');
    header('Sec-WebSocket-Accept: ' . $accept);
    header('Sec-WebSocket-Version: 13');
    
    // Flush headers
    if (ob_get_length()) {
        ob_end_flush();
    }
    flush();
    
    return true;
}

/**
 * Decode WebSocket frame
 */
function decodeWebSocketFrame($data) {
    $length = ord($data[1]) & 127;
    $maskOffset = 2;
    
    if ($length == 126) {
        $length = unpack('n', substr($data, 2, 2))[1];
        $maskOffset = 4;
    } elseif ($length == 127) {
        $length = unpack('J', substr($data, 2, 8))[1];
        $maskOffset = 10;
    }
    
    $masks = substr($data, $maskOffset, 4);
    $payload = substr($data, $maskOffset + 4, $length);
    
    $decoded = '';
    for ($i = 0; $i < $length; $i++) {
        $decoded .= $payload[$i] ^ $masks[$i % 4];
    }
    
    return $decoded;
}

/**
 * Encode WebSocket frame
 */
function encodeWebSocketFrame($data) {
    $length = strlen($data);
    $frame = chr(0x81); // FIN + text frame
    
    if ($length < 126) {
        $frame .= chr($length);
    } elseif ($length < 65536) {
        $frame .= chr(126) . pack('n', $length);
    } else {
        $frame .= chr(127) . pack('J', $length);
    }
    
    $frame .= $data;
    return $frame;
}

// Check if this is a WebSocket upgrade request
if (isset($_SERVER['HTTP_UPGRADE']) && strtolower($_SERVER['HTTP_UPGRADE']) == 'websocket') {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../database.php';
    
    // Handle WebSocket upgrade
    handleWebSocketUpgrade();
    
    // Get session info from query string
    $query = $_SERVER['QUERY_STRING'] ?? '';
    parse_str($query, $params);
    $session_id = $params['session_id'] ?? '';
    $code = isset($params['code']) ? strtolower(trim($params['code'])) : '';
    $mode = $params['mode'] ?? 'client';
    
    // Get peer_id
    $peer_id = null;
    if ($session_id && $code) {
        $escaped_session_id = Database::escape($session_id);
        $escaped_code = Database::escape($code);
        $sql = "SELECT peer_id FROM sessions WHERE (session_id = '$escaped_session_id' OR code = '$escaped_code') AND peer_id IS NOT NULL LIMIT 1";
        $result = Database::query($sql);
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $peer_id = $row['peer_id'];
        }
    }
    
    if (!$peer_id) {
        // Send error and close
        $error = json_encode(['type' => 'error', 'message' => 'No peer connection found']);
        echo encodeWebSocketFrame($error);
        exit;
    }
    
    // Define relay file path
    define('RELAY_STORAGE_PATH', __DIR__ . '/../storage/relay/');
    
    // WebSocket message loop
    $socket = fopen('php://input', 'r');
    stream_set_blocking($socket, false);
    
    $last_send_time = time();
    $keepalive_interval = 30; // Send keepalive every 30 seconds
    
    while (true) {
        // Check for incoming WebSocket frames
        $data = fread($socket, 8192);
        if ($data && strlen($data) > 0) {
            try {
                $decoded = decodeWebSocketFrame($data);
                $message = json_decode($decoded, true);
                
                if ($message && isset($message['type'])) {
                    if ($message['type'] == 'send_frame' || $message['type'] == 'send_input') {
                        // Store relay data
                        $relay_file = RELAY_STORAGE_PATH . $peer_id . '_relay.json';
                        $relay_message = json_encode([
                            'type' => $message['type'] == 'send_frame' ? 'frame' : 'input',
                            'data' => $message['data'],
                            'timestamp' => time()
                        ]) . "\n";
                        file_put_contents($relay_file, $relay_message, FILE_APPEND | LOCK_EX);
                        
                        // Send acknowledgment
                        $ack = json_encode(['type' => 'ack', 'success' => true]);
                        echo encodeWebSocketFrame($ack);
                        flush();
                    }
                }
            } catch (Exception $e) {
                error_log("WebSocket decode error: " . $e->getMessage());
            }
        }
        
        // Send available frames (for admin mode)
        if ($mode == 'admin') {
            $relay_file = RELAY_STORAGE_PATH . $session_id . '_relay.json';
            if (!file_exists($relay_file)) {
                $relay_file = RELAY_STORAGE_PATH . $peer_id . '_relay.json';
            }
            
            if (file_exists($relay_file)) {
                // Read and clear frames atomically
                $temp_file = $relay_file . '.tmp.' . time() . '.' . rand(1000, 9999);
                $rename_success = @rename($relay_file, $temp_file);
                
                if ($rename_success && file_exists($temp_file)) {
                    $lines = file($temp_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    @unlink($temp_file);
                    
                    if ($lines) {
                        foreach ($lines as $line) {
                            $decoded = json_decode($line, true);
                            if ($decoded && isset($decoded['type'])) {
                                // Send frame via WebSocket
                                $ws_message = json_encode([
                                    'type' => $decoded['type'],
                                    'data' => $decoded['data']
                                ]);
                                echo encodeWebSocketFrame($ws_message);
                                flush();
                            }
                        }
                    }
                }
            }
        }
        
        // Keepalive
        if (time() - $last_send_time > $keepalive_interval) {
            $ping = json_encode(['type' => 'ping']);
            echo encodeWebSocketFrame($ping);
            flush();
            $last_send_time = time();
        }
        
        usleep(10000); // 10ms sleep to prevent CPU spinning
    }
    
    exit;
}

// Fallback to regular HTTP endpoint
require_once __DIR__ . '/relay_hybrid.php';

