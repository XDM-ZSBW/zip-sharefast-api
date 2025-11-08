<?php
/**
 * Generate Test Session - Creates both client and admin sessions for testing
 * Usage: POST to generate_test_session.php
 * Returns: JSON with generated code and session IDs
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

function generateCode() {
    $adjectives = ['happy', 'bright', 'quick', 'calm', 'bold', 'swift', 'clear', 'sharp', 'smooth', 'fresh'];
    $nouns = ['cloud', 'river', 'mountain', 'ocean', 'forest', 'valley', 'star', 'moon', 'sun', 'wind'];
    return $adjectives[array_rand($adjectives)] . '-' . $nouns[array_rand($nouns)];
}

try {
    // Generate unique code
    $code = generateCode();
    $max_attempts = 10;
    $attempt = 0;
    
    // Ensure code is unique
    while ($attempt < $max_attempts) {
        $check_sql = "SELECT COUNT(*) as count FROM sessions WHERE code = '" . Database::escape($code) . "' AND expires_at > " . time();
        $check_result = Database::query($check_sql);
        if ($check_result && $check_result->num_rows > 0) {
            $row = $check_result->fetch_assoc();
            if (intval($row['count']) == 0) {
                break; // Code is available
            }
        } else {
            break; // No existing sessions found
        }
        $code = generateCode();
        $attempt++;
    }
    
    if ($attempt >= $max_attempts) {
        throw new Exception("Failed to generate unique code after $max_attempts attempts");
    }
    
    $current_time = time();
    $expires_at = $current_time + 3600; // 1 hour
    
    // Generate session IDs
    $client_session_id = 'session_' . uniqid() . '.' . mt_rand(10000000, 99999999);
    $admin_session_id = 'session_' . uniqid() . '.' . mt_rand(10000000, 99999999);
    
    $escaped_code = Database::escape($code);
    $escaped_client_session = Database::escape($client_session_id);
    $escaped_admin_session = Database::escape($admin_session_id);
    
    // Create client session
    $client_sql = "INSERT INTO sessions (session_id, code, mode, ip_address, port, connected, created_at, expires_at, last_keepalive) 
                   VALUES ('$escaped_client_session', '$escaped_code', 'client', '127.0.0.1', 0, 1, $current_time, $expires_at, $current_time)";
    
    $client_result = Database::query($client_sql);
    if (!$client_result) {
        throw new Exception("Failed to create client session: " . Database::getConnection()->error);
    }
    
    // Create admin session and link them
    $admin_sql = "INSERT INTO sessions (session_id, code, mode, ip_address, port, connected, peer_id, created_at, expires_at, last_keepalive) 
                  VALUES ('$escaped_admin_session', '$escaped_code', 'admin', '127.0.0.1', 0, 1, '$escaped_client_session', $current_time, $expires_at, $current_time)";
    
    $admin_result = Database::query($admin_sql);
    if (!$admin_result) {
        throw new Exception("Failed to create admin session: " . Database::getConnection()->error);
    }
    
    // Update client session with peer_id
    $update_client_sql = "UPDATE sessions SET peer_id = '$escaped_admin_session' WHERE session_id = '$escaped_client_session'";
    Database::query($update_client_sql);
    
    // Create a test signal (admin_connected)
    $signal_sql = "INSERT INTO signals (session_id, code, signal_type, signal_data, created_at) 
                   VALUES ('$escaped_client_session', '$escaped_code', 'admin_connected', '{}', $current_time)";
    Database::query($signal_sql);
    
    echo json_encode([
        'success' => true,
        'code' => $code,
        'client_session_id' => $client_session_id,
        'admin_session_id' => $admin_session_id,
        'message' => 'Test session created successfully',
        'diagnostic_url' => "https://sharefast.zip/api/diagnostic_dashboard.php?code=$code"
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

