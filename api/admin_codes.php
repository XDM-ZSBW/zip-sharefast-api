<?php
/**
 * Admin Client Codes API - Manage previously used client codes per admin
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

function getAdminCodes($email) {
    $email = strtolower(trim($email));
    
    if (STORAGE_METHOD === 'database') {
        $escaped_email = Database::escape($email);
        $sql = "SELECT client_code, client_name, allow_reconnect, last_used_at, created_at 
                FROM admin_client_codes 
                WHERE admin_email = '$escaped_email' 
                ORDER BY last_used_at DESC";
        $result = Database::query($sql);
        
        $codes = array();
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $codes[] = array(
                    'code' => $row['client_code'],
                    'name' => $row['client_name'] ? $row['client_name'] : null,
                    'allow_reconnect' => $row['allow_reconnect'] ? true : false,
                    'last_used_at' => intval($row['last_used_at']),
                    'created_at' => intval($row['created_at'])
                );
            }
        }
        
        return array(
            'success' => true,
            'codes' => $codes,
            'count' => count($codes)
        );
    } elseif (STORAGE_METHOD === 'file') {
        $admin_codes_file = STORAGE_PATH . 'admin_' . md5($email) . '_codes.json';
        
        if (!file_exists($admin_codes_file)) {
            return array('success' => true, 'codes' => array(), 'count' => 0);
        }
        
        $codes_data = json_decode(file_get_contents($admin_codes_file), true);
        if (!is_array($codes_data)) {
            return array('success' => true, 'codes' => array(), 'count' => 0);
        }
        
        // Sort by last_used_at descending
        usort($codes_data, function($a, $b) {
            return ($b['last_used_at'] ?? 0) - ($a['last_used_at'] ?? 0);
        });
        
        return array(
            'success' => true,
            'codes' => $codes_data,
            'count' => count($codes_data)
        );
    }
    
    return array('success' => false, 'message' => 'Storage method not configured');
}

function saveAdminCode($email, $client_code, $client_name = null, $allow_reconnect = true) {
    $email = strtolower(trim($email));
    $client_code = strtolower(trim($client_code));
    $timestamp = time();
    
    if (STORAGE_METHOD === 'database') {
        $escaped_email = Database::escape($email);
        $escaped_code = Database::escape($client_code);
        $escaped_name = $client_name ? Database::escape($client_name) : null;
        $escaped_allow_reconnect = $allow_reconnect ? 1 : 0;
        
        // Check if entry already exists
        $check_sql = "SELECT * FROM admin_client_codes 
                      WHERE admin_email = '$escaped_email' AND client_code = '$escaped_code' LIMIT 1";
        $check_result = Database::query($check_sql);
        
        if ($check_result && $check_result->num_rows > 0) {
            // Update existing entry
            $update_sql = "UPDATE admin_client_codes 
                          SET last_used_at = $timestamp";
            
            if ($escaped_name !== null) {
                $update_sql .= ", client_name = '$escaped_name'";
            }
            
            $update_sql .= ", allow_reconnect = $escaped_allow_reconnect
                           WHERE admin_email = '$escaped_email' AND client_code = '$escaped_code'";
            
            Database::query($update_sql);
        } else {
            // Insert new entry
            $name_sql = $escaped_name ? "'$escaped_name'" : "NULL";
            $insert_sql = "INSERT INTO admin_client_codes 
                          (admin_email, client_code, client_name, allow_reconnect, last_used_at, created_at) 
                          VALUES ('$escaped_email', '$escaped_code', $name_sql, $escaped_allow_reconnect, $timestamp, $timestamp)";
            Database::query($insert_sql);
        }
        
        return array('success' => true, 'message' => 'Code saved successfully');
    } elseif (STORAGE_METHOD === 'file') {
        $admin_codes_file = STORAGE_PATH . 'admin_' . md5($email) . '_codes.json';
        
        $codes_data = array();
        if (file_exists($admin_codes_file)) {
            $codes_data = json_decode(file_get_contents($admin_codes_file), true);
            if (!is_array($codes_data)) {
                $codes_data = array();
            }
        }
        
        // Check if code already exists
        $found = false;
        foreach ($codes_data as &$code_entry) {
            if ($code_entry['code'] === $client_code) {
                $code_entry['last_used_at'] = $timestamp;
                if ($client_name !== null) {
                    $code_entry['name'] = $client_name;
                }
                $code_entry['allow_reconnect'] = $allow_reconnect;
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            $codes_data[] = array(
                'code' => $client_code,
                'name' => $client_name,
                'allow_reconnect' => $allow_reconnect,
                'last_used_at' => $timestamp,
                'created_at' => $timestamp
            );
        }
        
        file_put_contents($admin_codes_file, json_encode($codes_data));
        
        return array('success' => true, 'message' => 'Code saved successfully');
    }
    
    return array('success' => false, 'message' => 'Storage method not configured');
}

function deleteAdminCode($email, $client_code) {
    $email = strtolower(trim($email));
    $client_code = strtolower(trim($client_code));
    
    if (STORAGE_METHOD === 'database') {
        $escaped_email = Database::escape($email);
        $escaped_code = Database::escape($client_code);
        
        $sql = "DELETE FROM admin_client_codes 
                WHERE admin_email = '$escaped_email' AND client_code = '$escaped_code'";
        Database::query($sql);
        
        return array('success' => true, 'message' => 'Code deleted successfully');
    } elseif (STORAGE_METHOD === 'file') {
        $admin_codes_file = STORAGE_PATH . 'admin_' . md5($email) . '_codes.json';
        
        if (!file_exists($admin_codes_file)) {
            return array('success' => true, 'message' => 'Code not found');
        }
        
        $codes_data = json_decode(file_get_contents($admin_codes_file), true);
        if (!is_array($codes_data)) {
            return array('success' => true, 'message' => 'Code not found');
        }
        
        $codes_data = array_filter($codes_data, function($entry) use ($client_code) {
            return $entry['code'] !== $client_code;
        });
        
        file_put_contents($admin_codes_file, json_encode(array_values($codes_data)));
        
        return array('success' => true, 'message' => 'Code deleted successfully');
    }
    
    return array('success' => false, 'message' => 'Storage method not configured');
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = array();
}

if (!isset($input['action'])) {
    echo json_encode(array('success' => false, 'message' => 'Missing action'));
    exit;
}

if (!isset($input['email'])) {
    echo json_encode(array('success' => false, 'message' => 'Missing email'));
    exit;
}

$action = $input['action'];
$email = $input['email'];

switch ($action) {
    case 'get':
        $result = getAdminCodes($email);
        break;
        
    case 'save':
        if (!isset($input['client_code'])) {
            echo json_encode(array('success' => false, 'message' => 'Missing client_code'));
            exit;
        }
        $client_code = $input['client_code'];
        $client_name = isset($input['client_name']) ? $input['client_name'] : null;
        $allow_reconnect = isset($input['allow_reconnect']) ? $input['allow_reconnect'] : true;
        $result = saveAdminCode($email, $client_code, $client_name, $allow_reconnect);
        break;
        
    case 'delete':
        if (!isset($input['client_code'])) {
            echo json_encode(array('success' => false, 'message' => 'Missing client_code'));
            exit;
        }
        $client_code = $input['client_code'];
        $result = deleteAdminCode($email, $client_code);
        break;
        
    default:
        $result = array('success' => false, 'message' => 'Invalid action');
}

echo json_encode($result);

?>



