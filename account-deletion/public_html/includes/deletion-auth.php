<?php
/**
 * Shared helpers for the account-deletion endpoints.
 * config.php must already be loaded (provides $jwt_secret + base64 helpers).
 */

define('DELETION_DATA_DIR', '/var/directsponsor-data');
define('DELETION_REQUESTS_DIR', DELETION_DATA_DIR . '/userdata/.deletion-requests');
define('DELETION_GRACE_DAYS', 7);
define('DELETION_TRASH_RETENTION_DAYS', 30);

// Verify a JWT and return its payload, or null.
// user_id only ever comes from the verified 'sub' claim — never request params.
function deletionVerifyJWT($token) {
    global $jwt_secret;

    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;

    $expected = base64UrlEncode(
        hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $jwt_secret, true)
    );
    if (!hash_equals($expected, $signatureEncoded)) return null;

    $payload = json_decode(base64UrlDecode($payloadEncoded), true);
    if (!$payload || !isset($payload['exp']) || $payload['exp'] <= time()) return null;
    if (empty($payload['sub']) || !preg_match('/^[a-zA-Z0-9_-]+$/', $payload['sub'])) return null;

    return $payload;
}

function deletionRequestPath($userId) {
    return DELETION_REQUESTS_DIR . '/' . $userId . '.json';
}

function deletionLoadRequest($userId) {
    $path = deletionRequestPath($userId);
    if (!file_exists($path)) return null;
    return json_decode(file_get_contents($path), true);
}
