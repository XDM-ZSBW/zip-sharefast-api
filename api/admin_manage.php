<?php
/**
 * Admin Management API - Add/remove admins (requires seed admin)
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

function validateEmailFormat($email) {
    // Validate email format using PHP filter
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validateEmailDomain($email) {
    // Extract domain from email
    $domain = substr(strrchr($email, "@"), 1);
    
    if (!$domain || empty($domain)) {
        return false;
    }
    
    // Check if domain has valid MX records
    if (function_exists('checkdnsrr')) {
        if (@checkdnsrr($domain, "MX")) {
            return true;
        }
        
        // Fallback: Check if domain has any DNS records (A record)
        if (@checkdnsrr($domain, "A")) {
            return true;
        }
    }
    
    // If checkdnsrr is not available, do basic domain validation
    // At minimum, check that domain has a dot and looks valid
    if (preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $domain)) {
        return true;
    }
    
    return false;
}

function verifySeedAdmin($email_hash) {
    // Hash of the seed admin email (will be provided by user)
    // This should be set in config.php or environment variable
    $seed_hash = defined('SEED_ADMIN_HASH') ? SEED_ADMIN_HASH : null;
    
    if (!$seed_hash) {
        return false;
    }
    
    return hash_equals($seed_hash, $email_hash);
}

function generateAdminCode() {
    // Generate 8-digit admin code
    return str_pad(strval(rand(10000000, 99999999)), 8, '0', STR_PAD_LEFT);
}

function addAdmin($email, $seed_email_hash) {
    // Verify seed admin
    if (!verifySeedAdmin($seed_email_hash)) {
        return array('success' => false, 'message' => 'Unauthorized - invalid seed admin');
    }
    
    $email = strtolower(trim($email));
    
    // Validate email format
    if (!validateEmailFormat($email)) {
        return array('success' => false, 'message' => 'Invalid email format');
    }
    
    // Validate email domain
    if (!validateEmailDomain($email)) {
        return array('success' => false, 'message' => 'Email domain does not exist or has no mail servers');
    }
    
    if (STORAGE_METHOD === 'database') {
        $escaped_email = Database::escape($email);
        
        // Check if email already exists
        $check_sql = "SELECT * FROM admins WHERE email = '$escaped_email' LIMIT 1";
        $check_result = Database::query($check_sql);
        
        if ($check_result && $check_result->num_rows > 0) {
            return array('success' => false, 'message' => 'Admin already exists');
        }
        
        // Generate admin code
        $admin_code = generateAdminCode();
        $escaped_code = Database::escape($admin_code);
        $timestamp = time();
        
        // Insert new admin
        $insert_sql = "INSERT INTO admins (email, admin_code, active, added_at) VALUES ('$escaped_email', '$escaped_code', 1, $timestamp)";
        Database::query($insert_sql);
        
        return array(
            'success' => true,
            'message' => 'Admin added successfully',
            'admin_code' => $admin_code,
            'email' => $email
        );
    } elseif (STORAGE_METHOD === 'file') {
        $admin_file = STORAGE_PATH . 'admins.json';
        
        // Load existing admins
        $admins = array();
        if (file_exists($admin_file)) {
            $admins_data = json_decode(file_get_contents($admin_file), true);
            $admins = is_array($admins_data) ? $admins_data : array();
        }
        
        // Check if email already exists
        foreach ($admins as $admin) {
            if (isset($admin['email']) && strtolower($admin['email']) === $email) {
                return array('success' => false, 'message' => 'Admin already exists');
            }
        }
        
        // Generate admin code
        $admin_code = generateAdminCode();
        
        // Add new admin
        $admins[] = array(
            'email' => $email,
            'admin_code' => $admin_code,
            'added_at' => time(),
            'active' => true
        );
        
        // Save admins
        file_put_contents($admin_file, json_encode($admins, JSON_PRETTY_PRINT));
        
        return array(
            'success' => true,
            'message' => 'Admin added successfully',
            'admin_code' => $admin_code,
            'email' => $email
        );
    }
    
    return array('success' => false, 'message' => 'Storage method not configured');
}

function removeAdmin($email, $seed_email_hash) {
    // Verify seed admin
    if (!verifySeedAdmin($seed_email_hash)) {
        return array('success' => false, 'message' => 'Unauthorized - invalid seed admin');
    }
    
    $email = strtolower(trim($email));
    
    if (STORAGE_METHOD === 'database') {
        $escaped_email = Database::escape($email);
        
        // Delete admin
        $delete_sql = "DELETE FROM admins WHERE email = '$escaped_email'";
        Database::query($delete_sql);
        
        if (Database::affectedRows() > 0) {
            return array(
                'success' => true,
                'message' => 'Admin removed successfully'
            );
        } else {
            return array('success' => false, 'message' => 'Admin not found');
        }
    } elseif (STORAGE_METHOD === 'file') {
        $admin_file = STORAGE_PATH . 'admins.json';
        
        if (!file_exists($admin_file)) {
            return array('success' => false, 'message' => 'No admins configured');
        }
        
        $admins = json_decode(file_get_contents($admin_file), true);
        if (!is_array($admins)) {
            $admins = array();
        }
        
        // Remove admin
        $admins = array_filter($admins, function($admin) use ($email) {
            return !isset($admin['email']) || strtolower($admin['email']) !== $email;
        });
        
        // Re-index array
        $admins = array_values($admins);
        
        // Save admins
        file_put_contents($admin_file, json_encode($admins, JSON_PRETTY_PRINT));
        
        return array(
            'success' => true,
            'message' => 'Admin removed successfully'
        );
    }
    
    return array('success' => false, 'message' => 'Storage method not configured');
}

function listAdmins($seed_email_hash) {
    // Verify seed admin
    if (!verifySeedAdmin($seed_email_hash)) {
        return array('success' => false, 'message' => 'Unauthorized - invalid seed admin');
    }
    
    if (STORAGE_METHOD === 'database') {
        $sql = "SELECT email, added_at, active FROM admins ORDER BY added_at DESC";
        $result = Database::query($sql);
        
        $safe_admins = array();
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $safe_admins[] = array(
                    'email' => $row['email'],
                    'added_at' => intval($row['added_at']),
                    'active' => $row['active'] ? true : false
                );
            }
        }
        
        return array(
            'success' => true,
            'admins' => $safe_admins,
            'count' => count($safe_admins)
        );
    } elseif (STORAGE_METHOD === 'file') {
        $admin_file = STORAGE_PATH . 'admins.json';
        
        if (!file_exists($admin_file)) {
            return array('success' => true, 'admins' => array());
        }
        
        $admins = json_decode(file_get_contents($admin_file), true);
        if (!is_array($admins)) {
            $admins = array();
        }
        
        // Remove admin codes from response (security)
        $safe_admins = array();
        foreach ($admins as $admin) {
            $safe_admins[] = array(
                'email' => isset($admin['email']) ? $admin['email'] : '',
                'added_at' => isset($admin['added_at']) ? $admin['added_at'] : 0,
                'active' => isset($admin['active']) ? $admin['active'] : true
            );
        }
        
        return array(
            'success' => true,
            'admins' => $safe_admins,
            'count' => count($safe_admins)
        );
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

$action = $input['action'];
$seed_email_hash = isset($input['seed_email_hash']) ? $input['seed_email_hash'] : '';

switch ($action) {
    case 'add':
        if (!isset($input['email'])) {
            echo json_encode(array('success' => false, 'message' => 'Missing email'));
            exit;
        }
        $result = addAdmin($input['email'], $seed_email_hash);
        break;
        
    case 'remove':
        if (!isset($input['email'])) {
            echo json_encode(array('success' => false, 'message' => 'Missing email'));
            exit;
        }
        $result = removeAdmin($input['email'], $seed_email_hash);
        break;
        
    case 'list':
        $result = listAdmins($seed_email_hash);
        break;
        
    default:
        $result = array('success' => false, 'message' => 'Invalid action');
}

echo json_encode($result);

?>

