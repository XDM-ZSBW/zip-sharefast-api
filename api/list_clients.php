<?php
/**
 * List Available Clients API - Returns list of clients ready to be controlled
 */

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

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

function listAvailableClients($admin_email = null) {
    $clients = array();
    
    if (STORAGE_METHOD === 'database') {
        $current_time = time();
        $keepalive_threshold = $current_time - 60; // Active within last 60 seconds
        $recent_registration_threshold = $current_time - 120; // Allow clients registered in last 2 minutes
        
        // Build SQL query - filter by admin_email if provided
        $sql = "SELECT * FROM sessions WHERE mode = 'client' AND expires_at > $current_time 
                AND (last_keepalive IS NULL OR last_keepalive > $keepalive_threshold OR created_at > $recent_registration_threshold)";
        
        // Filter by admin_email if provided
        if ($admin_email) {
            $escaped_admin_email = Database::escape(strtolower(trim($admin_email)));
            $sql .= " AND admin_email = '$escaped_admin_email'";
        }
        
        $sql .= " ORDER BY created_at DESC";
        $result = Database::query($sql);
        
        if ($result && $result->num_rows > 0) {
            $seen_codes = array();
            
            while ($row = $result->fetch_assoc()) {
                $code = $row['code'];
                
                // Deduplicate by code - keep only the most recent
                if (!isset($seen_codes[$code])) {
                    $seen_codes[$code] = true;
                    
                    $clients[] = array(
                        'code' => $code,
                        'session_id' => $row['session_id'],
                        'created_at' => intval($row['created_at']),
                        'expires_at' => intval($row['expires_at']),
                        'ip_address' => $row['ip_address'],
                        'port' => intval($row['port']),
                        'allow_autonomous' => $row['allow_autonomous'] ? true : false,
                        'connected' => $row['connected'] ? true : false,
                        'time_remaining' => max(0, intval($row['expires_at']) - $current_time)
                    );
                }
            }
        }
    } elseif (STORAGE_METHOD === 'file') {
        $storage_path = STORAGE_PATH;
        
        // Scan for client session files
        $files = glob($storage_path . '*.json');
        
        foreach ($files as $file) {
            // Skip admin session files and relay files
            if (strpos(basename($file), '_admin.json') !== false || 
                strpos(basename($file), '_relay.json') !== false ||
                strpos(basename($file), '_signals.json') !== false) {
                continue;
            }
            
            $session_data = json_decode(file_get_contents($file), true);
            
            if ($session_data && $session_data['mode'] === 'client') {
                // Filter by admin_email if provided
                if ($admin_email) {
                    $session_admin_email = isset($session_data['admin_email']) ? strtolower(trim($session_data['admin_email'])) : null;
                    if ($session_admin_email !== strtolower(trim($admin_email))) {
                        continue;  // Skip this client - doesn't match admin_email filter
                    }
                }
                // Check if session is still valid and not expired
                $expires_at = $session_data['expires_at'] ?? 0;
                $created_at = $session_data['created_at'] ?? 0;
                $last_keepalive = $session_data['last_keepalive'] ?? 0;
                
                // Check if session is active (not expired and has keepalive within last 60 seconds)
                $current_time = time();
                $keepalive_threshold = $current_time - 60;  // Active within last 60 seconds
                $recent_registration_threshold = $current_time - 120;  // Allow clients registered in last 2 minutes
                
                // Client is active if:
                // 1. Not expired AND
                // 2. Has valid creation time AND
                // 3. Either: no keepalive yet (newly registered) OR keepalive within last 60 seconds OR registered recently
                if ($expires_at > $current_time && $created_at > 0 && 
                    ($last_keepalive === 0 || $last_keepalive > $keepalive_threshold || $created_at > $recent_registration_threshold)) {
                    // Client is available
                    $clients[] = [
                        'code' => $session_data['code'],
                        'session_id' => $session_data['session_id'],
                        'created_at' => $created_at,
                        'expires_at' => $expires_at,
                        'ip_address' => $session_data['ip_address'] ?? null,
                        'port' => $session_data['port'] ?? 8765,
                        'allow_autonomous' => $session_data['allow_autonomous'] ?? false,
                        'connected' => $session_data['connected'] ?? false,
                        'time_remaining' => max(0, $expires_at - $current_time)
                    ];
                }
            }
        }
        
        // Sort by creation time (newest first)
        usort($clients, function($a, $b) {
            return $b['created_at'] - $a['created_at'];
        });
        
        // Deduplicate by code - keep only the most recent session for each code
        $unique_clients = [];
        $seen_codes = [];
        
        foreach ($clients as $client) {
            $code = $client['code'];
            if (!isset($seen_codes[$code])) {
                $seen_codes[$code] = true;
                $unique_clients[] = $client;
            }
        }
        
        $clients = $unique_clients;
    }
    
    return $clients;
}

// Get admin_email from request (POST or GET)
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = array();
}

// Also check $_POST and $_GET for admin_email
$admin_email = null;
if (isset($input['admin_email'])) {
    $admin_email = trim($input['admin_email']);
} elseif (isset($_POST['admin_email'])) {
    $admin_email = trim($_POST['admin_email']);
} elseif (isset($_GET['admin_email'])) {
    $admin_email = trim($_GET['admin_email']);
}

$clients = listAvailableClients($admin_email);

echo json_encode([
    'success' => true,
    'clients' => $clients,
    'count' => count($clients)
]);

?>

