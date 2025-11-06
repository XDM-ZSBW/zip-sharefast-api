<?php
/**
 * Register a new session (client or admin)
 */

// Suppress any output before headers
if (ob_get_level()) ob_clean();

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors, but log them
ini_set('log_errors', 1);

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

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../database.php';
    require_once __DIR__ . '/rate_limit.php';
    
    // Enforce rate limiting (prevents abuse)
    if (!enforceRateLimit()) {
        exit;
    }
} catch (Exception $e) {
    error_log("register.php: Error loading config/files - " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server configuration error']);
    exit;
} catch (Error $e) {
    error_log("register.php: Fatal error loading config/files - " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server configuration error']);
    exit;
}

function registerSession($code, $mode, $peer_ip = null, $peer_port = null, $allow_autonomous = false, $admin_email = null) {
    $session_id = uniqid('session_', true);
    $timestamp = time();
    
    // Get client IP address (handle proxies)
    $client_ip = $peer_ip;
    if (!$client_ip) {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $client_ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $client_ip = $_SERVER['HTTP_X_REAL_IP'];
        } else {
            $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        }
    }
    
    $session_data = [
        'session_id' => $session_id,
        'code' => $code,
        'mode' => $mode,
        'created_at' => $timestamp,
        'expires_at' => $timestamp + CODE_EXPIRY,
        'connected' => false,
        'peer_id' => null,
        'ip_address' => $client_ip,
        'port' => $peer_port ?? 8765,  // Default port
        'allow_autonomous' => $allow_autonomous,  // Allow autonomous logon/reconnection
        'admin_email' => ($mode === 'client' && $admin_email) ? strtolower(trim($admin_email)) : null  // Admin email for client sessions
    ];
    
    if (STORAGE_METHOD === 'database') {
        $escaped_session_id = Database::escape($session_id);
        $escaped_code = Database::escape($code);
        $escaped_mode = Database::escape($mode);
        $escaped_ip = Database::escape($client_ip);
        $escaped_port = intval($peer_port ?? 8765);
        $escaped_allow_autonomous = $allow_autonomous ? 1 : 0;
        $escaped_expires_at = $timestamp + CODE_EXPIRY;
        $escaped_admin_email = ($mode === 'client' && $admin_email) ? Database::escape(strtolower(trim($admin_email))) : 'NULL';
        
        // Check if code already exists
        $check_sql = "SELECT * FROM sessions WHERE code = '$escaped_code' AND expires_at > " . time() . " LIMIT 1";
        $existing_result = Database::query($check_sql);
        
        if ($existing_result && $existing_result->num_rows > 0) {
            $existing = $existing_result->fetch_assoc();
            
            // If client already exists, return error
            if ($existing['mode'] === 'client' && $mode === 'client') {
                return array('success' => false, 'message' => 'Code already in use');
            }
            
            // If admin connecting and client exists, link them
            if ($existing['mode'] === 'client' && $mode === 'admin') {
                $existing_session_id = Database::escape($existing['session_id']);
                
                // Update existing client session - set client's peer_id to admin's session_id
                $update_sql = "UPDATE sessions SET peer_id = '$escaped_session_id', connected = 1 WHERE session_id = '$existing_session_id'";
                $update_result = Database::query($update_sql);
                $affected_rows = Database::affectedRows();
                
                if (!$update_result || $affected_rows === 0) {
                    error_log("register.php: Failed to update client session peer_id. SQL: $update_sql");
                } else {
                    error_log("register.php: Successfully linked client session_id=$existing_session_id to admin session_id=$escaped_session_id");
                }
                
                // Store admin session info for reconnection if autonomous logon is allowed
                if ($existing['allow_autonomous']) {
                    $admin_sql = "INSERT INTO admin_sessions (admin_session_id, admin_code, peer_session_id, peer_code, peer_ip, peer_port, connected_at, expires_at) 
                                  VALUES ('$escaped_session_id', '$escaped_code', '$existing_session_id', '$escaped_code', '$escaped_ip', $escaped_port, $timestamp, $escaped_expires_at)";
                    Database::query($admin_sql);
                }
                
                // Insert admin session
                $insert_sql = "INSERT INTO sessions (session_id, code, mode, peer_id, ip_address, port, allow_autonomous, connected, created_at, expires_at) 
                               VALUES ('$escaped_session_id', '$escaped_code', '$escaped_mode', '$existing_session_id', '$escaped_ip', $escaped_port, $escaped_allow_autonomous, 1, $timestamp, $escaped_expires_at)";
                Database::query($insert_sql);
                
                // Return peer IP/port info for P2P connection
                return array(
                    'success' => true,
                    'session_id' => $session_id,
                    'message' => 'Connected to client',
                    'peer_id' => $existing['session_id'],
                    'peer_ip' => $existing['ip_address'],
                    'peer_port' => intval($existing['port']),
                    'allow_autonomous' => $existing['allow_autonomous'] ? true : false
                );
            }
        }
        
        // Create new session
        $admin_email_sql = ($escaped_admin_email === 'NULL') ? 'NULL' : "'$escaped_admin_email'";
        $insert_sql = "INSERT INTO sessions (session_id, code, mode, peer_id, ip_address, port, allow_autonomous, connected, admin_email, created_at, expires_at) 
                       VALUES ('$escaped_session_id', '$escaped_code', '$escaped_mode', NULL, '$escaped_ip', $escaped_port, $escaped_allow_autonomous, 0, $admin_email_sql, $timestamp, $escaped_expires_at)";
        $insert_result = Database::query($insert_sql);
        
        if (!$insert_result) {
            $conn = Database::getConnection();
            $error = $conn ? $conn->error : 'Unknown error';
            error_log("register.php: INSERT failed - Error: $error | SQL: $insert_sql");
            return array('success' => false, 'message' => 'Failed to create session: ' . $error);
        }
        
        $insert_id = Database::insertId();
        if ($insert_id === 0) {
            error_log("register.php: INSERT returned no insert_id - SQL: $insert_sql");
            return array('success' => false, 'message' => 'Failed to create session: No insert ID returned');
        }
        
        error_log("register.php: Session created successfully - insert_id=$insert_id, session_id=$session_id, code=$code, mode=$mode");
        
        return array(
            'success' => true,
            'session_id' => $session_id,
            'message' => 'Session registered'
        );
    } elseif (STORAGE_METHOD === 'file') {
        $file = STORAGE_PATH . $code . '.json';
        
        // Check if code already exists
        if (file_exists($file)) {
            $existing = json_decode(file_get_contents($file), true);
            
            // If client already exists, return error
            if ($existing['mode'] === 'client' && $mode === 'client') {
                return array('success' => false, 'message' => 'Code already in use');
            }
            
            // If admin connecting and client exists, link them
            if ($existing['mode'] === 'client' && $mode === 'admin') {
                $existing['peer_id'] = $session_id;
                $existing['connected'] = true;
                $session_data['peer_id'] = $existing['session_id'];
                $session_data['connected'] = true;
                
                // Store admin session info for reconnection if autonomous logon is allowed
                if ($existing['allow_autonomous'] ?? false) {
                    // Store admin session info for autonomous reconnection
                    $admin_info = array(
                        'admin_session_id' => $session_id,
                        'admin_code' => $code,
                        'peer_session_id' => $existing['session_id'],
                        'peer_code' => $existing['code'],
                        'peer_ip' => $existing['ip_address'] ?? null,
                        'peer_port' => $existing['port'] ?? 8765,
                        'connected_at' => $timestamp,
                        'expires_at' => $timestamp + CODE_EXPIRY
                    );
                    file_put_contents(STORAGE_PATH . $existing['session_id'] . '_admin.json', json_encode($admin_info));
                }
                
                file_put_contents($file, json_encode($existing));
                file_put_contents(STORAGE_PATH . $session_id . '.json', json_encode($session_data));
                
                // Return peer IP/port info for P2P connection
                return array(
                    'success' => true,
                    'session_id' => $session_id,
                    'message' => 'Connected to client',
                    'peer_id' => $existing['session_id'],
                    'peer_ip' => $existing['ip_address'] ?? null,
                    'peer_port' => $existing['port'] ?? 8765,
                    'allow_autonomous' => $existing['allow_autonomous'] ?? false
                );
            }
        }
        
        // Create new session
        file_put_contents(STORAGE_PATH . $code . '.json', json_encode($session_data));
        file_put_contents(STORAGE_PATH . $session_id . '.json', json_encode($session_data));
        
        return array(
            'success' => true,
            'session_id' => $session_id,
            'message' => 'Session registered'
        );
    }
    
    return array('success' => false, 'message' => 'Storage method not configured');
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

// Log incoming request for debugging
error_log("register.php: Received request - " . json_encode($input));

// Error handling wrapper
try {
    if (!isset($input['code']) || !isset($input['mode'])) {
        error_log("register.php: Missing required fields - code: " . (isset($input['code']) ? 'set' : 'missing') . ", mode: " . (isset($input['mode']) ? 'set' : 'missing'));
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }

$code = trim($input['code']);
$code = strtolower($code);  // Ensure code is lowercase for validation
$mode = $input['mode'];
$session_id = $input['session_id'] ?? null;

// Handle query mode (for WebSocket server to get peer_id)
if ($mode === 'query') {
    if (!$session_id || !$code) {
        echo json_encode(['success' => false, 'message' => 'Missing session_id or code for query']);
        exit;
    }
    
    $escaped_session_id = Database::escape($session_id);
    $escaped_code = Database::escape($code);
    
    // Query database for peer_id
    if (STORAGE_METHOD === 'database') {
        // First, try to find peer_id directly from the session
        $sql = "SELECT peer_id FROM sessions WHERE session_id = '$escaped_session_id' AND peer_id IS NOT NULL LIMIT 1";
        $result = Database::query($sql);
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            echo json_encode(['success' => true, 'peer_id' => $row['peer_id']]);
            exit;
        }
        
        // If not found, look for the peer session that points to this session as peer_id
        // (e.g., if admin connects, admin's peer_id is client's session_id, so query for admin session)
        $sql2 = "SELECT session_id FROM sessions WHERE peer_id = '$escaped_session_id' LIMIT 1";
        $result2 = Database::query($sql2);
        if ($result2 && $result2->num_rows > 0) {
            $row2 = $result2->fetch_assoc();
            echo json_encode(['success' => true, 'peer_id' => $row2['session_id']]);
            exit;
        }
        
        // Also check by code - find the other session with the same code
        $sql3 = "SELECT session_id FROM sessions WHERE code = '$escaped_code' AND session_id != '$escaped_session_id' AND mode != (SELECT mode FROM sessions WHERE session_id = '$escaped_session_id' LIMIT 1) LIMIT 1";
        $result3 = Database::query($sql3);
        if ($result3 && $result3->num_rows > 0) {
            $row3 = $result3->fetch_assoc();
            echo json_encode(['success' => true, 'peer_id' => $row3['session_id']]);
            exit;
        }
        
        echo json_encode(['success' => true, 'peer_id' => null]);
    } else {
        // File storage - check for peer_id in session file
        $session_file = STORAGE_PATH . $session_id . '.json';
        if (file_exists($session_file)) {
            $session_data = json_decode(file_get_contents($session_file), true);
            echo json_encode(['success' => true, 'peer_id' => $session_data['peer_id'] ?? null]);
        } else {
            echo json_encode(['success' => true, 'peer_id' => null]);
        }
    }
    exit;
}

// Validate mode for registration
$mode = in_array($input['mode'], ['client', 'admin']) ? $input['mode'] : null;
$peer_ip = $input['peer_ip'] ?? null;  // Client's self-reported IP
$peer_port = isset($input['peer_port']) ? intval($input['peer_port']) : null;  // Client's port
$allow_autonomous = isset($input['allow_autonomous']) ? (bool)$input['allow_autonomous'] : false;  // Allow autonomous logon
$admin_email = isset($input['admin_email']) ? trim($input['admin_email']) : null;  // Admin email entered by client

// Log parsed values
error_log("register.php: Parsed - code: '$code', mode: " . ($mode ?? 'NULL') . ", length: " . strlen($code) . ", admin_email: " . ($admin_email ?? 'NULL'));

// Validate code format: word-word format only (e.g., "happy-cloud")
$code_valid = false;
if (preg_match('/^[a-z]+-[a-z]+$/', $code)) {
    // Word-based code (e.g., "happy-cloud")
    // Validate length: each word should be 3-15 characters
    $parts = explode('-', $code);
    if (count($parts) === 2 && strlen($parts[0]) >= 3 && strlen($parts[0]) <= 15 && 
        strlen($parts[1]) >= 3 && strlen($parts[1]) <= 15) {
        $code_valid = true;
        error_log("register.php: Code validated as word-word format: '$parts[0]'-'$parts[1]'");
    } else {
        error_log("register.php: Code format invalid - parts: " . json_encode($parts) . ", lengths: " . strlen($parts[0]) . ", " . strlen($parts[1]));
    }
} else {
    error_log("register.php: Code does not match valid format pattern (expected word-word format)");
}

if (!$mode || !$code_valid) {
    $error_details = [];
    if (!$mode) {
        $error_details[] = "mode: " . ($input['mode'] ?? 'not set') . " (expected 'client' or 'admin')";
    }
    if (!$code_valid) {
        $error_details[] = "code: '$code' (length: " . strlen($code) . ", format: " . (preg_match('/^[a-z]+-[a-z]+$/', $code) ? 'word-word' : 'invalid - expected word-word format') . ")";
    }
    $error_msg = "Invalid code or mode - " . implode(", ", $error_details);
    error_log("register.php: Validation failed - $error_msg");
    echo json_encode(['success' => false, 'message' => $error_msg]);
    exit;
}

$result = registerSession($code, $mode, $peer_ip, $peer_port, $allow_autonomous, $admin_email);
echo json_encode($result);

} catch (Exception $e) {
    error_log("register.php: Exception - " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error occurred',
        'error' => $e->getMessage()
    ]);
} catch (Error $e) {
    error_log("register.php: Fatal Error - " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error occurred',
        'error' => $e->getMessage()
    ]);
}

?>

