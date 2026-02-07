<?php
// Auth Server Configuration
// For auth.directsponsor.org

// Load sensitive credentials from local config (not in git)
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
} else {
    // Fallback defaults for template/documentation purposes
    $auth_db_host = 'localhost';
    $auth_db_name = 'directsponsor_oauth';
    $auth_db_user = 'directsponsor_oauth';
    $auth_db_pass = 'YOUR_DATABASE_PASSWORD_HERE';
    
    $jwt_secret = 'YOUR_JWT_SECRET_HERE';
    $jwt_algorithm = 'HS256';
    $jwt_issuer = 'roflfaucet';
    $jwt_expiry = 3600; // 1 hour
}

// Known redirect URIs (our own sites only)
$allowed_redirects = [
    'https://roflfaucet.com',
    'https://roflfaucet.com/',
    'https://www.roflfaucet.com',
    'https://www.roflfaucet.com/',
    'https://staging.roflfaucet.com',
    'https://staging.roflfaucet.com/',
    'https://clickforcharity.net',
    'https://clickforcharity.net/',
    'https://www.clickforcharity.net',
    'https://www.clickforcharity.net/',
    'https://satoshihost.top',
    'https://satoshihost.top/',
    'https://www.satoshihost.top',
    'https://www.satoshihost.top/',
    // Add localhost for testing
    'http://localhost',
    'https://satoshihost.ddns.net',
    'https://satoshihost.ddns.net/',
    'https://satoshihost.duckdns.org',
    'https://satoshihost.duckdns.org/',
    'http://127.0.0.1',
    'https://faucetlist.org',
    'https://faucetlist.org/',
    'https://www.faucetlist.org',
    'https://www.faucetlist.org/'
];

// Database connection function
function getAuthDB() {
    global $auth_db_host, $auth_db_name, $auth_db_user, $auth_db_pass;
    
    try {
        $pdo = new PDO(
            "mysql:host=$auth_db_host;dbname=$auth_db_name;charset=utf8mb4",
            $auth_db_user,
            $auth_db_pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        error_log("Auth DB Connection failed: " . $e->getMessage());
        die("Database connection failed");
    }
}

// Simple JWT functions
function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode($data) {
    return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
}

function createJWT($payload) {
    global $jwt_secret;
    
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payload = json_encode($payload);
    
    $base64Header = base64UrlEncode($header);
    $base64Payload = base64UrlEncode($payload);
    
    $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, $jwt_secret, true);
    $base64Signature = base64UrlEncode($signature);
    
    return $base64Header . "." . $base64Payload . "." . $base64Signature;
}

function validateRedirectUri($uri) {
    global $allowed_redirects;
    
    // Check exact matches first
    if (in_array($uri, $allowed_redirects)) {
        return true;
    }
    
    // Check if URI starts with any allowed base
    foreach ($allowed_redirects as $allowed) {
        if (strpos($uri, $allowed) === 0) {
            return true;
        }
    }
    
    return false;
}

// CORS headers for our known domains
function setCorsHeaders() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    $allowed_origins = [
        'https://roflfaucet.com',
        'https://www.roflfaucet.com',
        'https://clickforcharity.net',
        'https://www.clickforcharity.net',
        'https://satoshihost.top',
        'https://www.satoshihost.top',
        'https://faucetlist.org',
        'https://www.faucetlist.org',
        'http://localhost',
        'https://satoshihost.ddns.net',
        'https://satoshihost.ddns.net/',
        'https://satoshihost.duckdns.org',
        'https://satoshihost.duckdns.org/',
        'http://127.0.0.1'
    ];
    
    // Always set CORS headers to prevent Apache defaults
    if (in_array($origin, $allowed_origins)) {
        header("Access-Control-Allow-Origin: $origin", true); // true = replace
    } else {
        // Fallback: send clickforcharity if no origin or unknown origin
        header("Access-Control-Allow-Origin: https://clickforcharity.net", true);
    }
    
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS", true);
    header("Access-Control-Allow-Headers: Content-Type, Authorization", true);
    header("Access-Control-Allow-Credentials: true", true);
}
?>
