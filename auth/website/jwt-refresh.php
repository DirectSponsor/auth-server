<?php
require_once 'config.php';

setCorsHeaders();

// Handle OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json');

function sendError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function validateJWT($token) {
    global $jwt_secret;
    
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }
    
    list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;
    
    // Verify signature
    $expectedSignature = base64UrlEncode(
        hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $jwt_secret, true)
    );
    
    if (!hash_equals($expectedSignature, $signatureEncoded)) {
        return false;
    }
    
    // Decode payload
    $payload = json_decode(base64UrlDecode($payloadEncoded), true);
    if (!$payload) {
        return false;
    }
    
    return $payload;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 405);
}

// Get token from Authorization header or POST body
$token = null;
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
    $token = $matches[1];
} elseif (isset($_POST['token'])) {
    $token = $_POST['token'];
}

if (!$token) {
    sendError('No token provided', 401);
}

// Validate the current token
$payload = validateJWT($token);
if (!$payload) {
    sendError('Invalid token', 401);
}

// Check if token is expired (we'll allow refresh of recently expired tokens)
$now = time();
$grace_period = 300; // 5 minutes grace period for expired tokens

if (isset($payload['exp']) && ($now - $payload['exp']) > $grace_period) {
    sendError('Token too old to refresh', 401);
}

// Verify user still exists in database
try {
    $db = getAuthDB();
    $stmt = $db->prepare("SELECT id, username FROM users WHERE id = ?");
    $stmt->execute([$payload['sub']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sendError('User not found', 401);
    }
    
    // Create new JWT token with extended expiration
    global $jwt_issuer, $jwt_expiry;
    
    $newPayload = [
        'iss' => $jwt_issuer,
        'iat' => $now,
        'exp' => $now + $jwt_expiry,
        'sub' => (string)$user['id'],
        'username' => $user['username']
    ];
    
    $new_token = createJWT($newPayload);
    
    echo json_encode([
        'success' => true,
        'jwt_token' => $new_token,
        'expires_in' => $jwt_expiry,
        'issued_at' => $now
    ]);
    
} catch (Exception $e) {
    error_log("Token refresh error: " . $e->getMessage());
    sendError('Token refresh failed', 500);
}
?>
