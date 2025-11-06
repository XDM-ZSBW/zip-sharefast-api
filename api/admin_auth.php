<?php
/**
 * Admin Authentication API - Verify admin email and return admin code
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

function authenticateAdmin($email) {
    $email = strtolower(trim($email));
    
    // Validate email format
    if (!validateEmailFormat($email)) {
        return array(
            'success' => false,
            'authenticated' => false,
            'message' => 'Invalid email format'
        );
    }
    
    // Validate email domain
    if (!validateEmailDomain($email)) {
        return array(
            'success' => false,
            'authenticated' => false,
            'message' => 'Email domain does not exist or has no mail servers'
        );
    }
    
    if (STORAGE_METHOD === 'database') {
        $escaped_email = Database::escape($email);
        
        $sql = "SELECT * FROM admins WHERE email = '$escaped_email' AND active = 1 LIMIT 1";
        $result = Database::query($sql);
        
        if ($result && $result->num_rows > 0) {
            $admin = $result->fetch_assoc();
            return array(
                'success' => true,
                'authenticated' => true,
                'admin_code' => $admin['admin_code'],
                'email' => $admin['email']
            );
        }
        
        return array(
            'success' => true,
            'authenticated' => false,
            'message' => 'Email not found in admin list'
        );
    } elseif (STORAGE_METHOD === 'file') {
        $admin_file = STORAGE_PATH . 'admins.json';
        
        if (!file_exists($admin_file)) {
            return array('success' => false, 'message' => 'No admins configured');
        }
        
        $admins = json_decode(file_get_contents($admin_file), true);
        
        if (!is_array($admins)) {
            return array('success' => false, 'message' => 'Invalid admin configuration');
        }
        
        // Check if email exists in admin list
        foreach ($admins as $admin) {
            if (isset($admin['email']) && strtolower($admin['email']) === $email) {
                $admin_code = isset($admin['admin_code']) ? $admin['admin_code'] : null;
                return array(
                    'success' => true,
                    'authenticated' => true,
                    'admin_code' => $admin_code,
                    'email' => $admin['email']
                );
            }
        }
        
        return array(
            'success' => true,
            'authenticated' => false,
            'message' => 'Email not found in admin list'
        );
    }
    
    return array('success' => false, 'message' => 'Storage method not configured');
}

// Get POST data
$raw_input = file_get_contents('php://input');
$input = json_decode($raw_input, true);

// Debug logging (can be removed in production)
if (empty($raw_input)) {
    error_log("admin_auth.php: No input received. REQUEST_METHOD: " . ($_SERVER['REQUEST_METHOD'] ?? 'unknown'));
}

if (!is_array($input)) {
    $input = array();
}

// Also check $_POST for form-encoded data (fallback)
if (empty($input) && !empty($_POST['email'])) {
    $input = array('email' => $_POST['email']);
}

if (!isset($input['email'])) {
    error_log("admin_auth.php: Missing email in request. Raw input: " . substr($raw_input, 0, 200));
    echo json_encode(array('success' => false, 'message' => 'Missing email'));
    exit;
}

$email = $input['email'];
$result = authenticateAdmin($email);

echo json_encode($result);

?>

