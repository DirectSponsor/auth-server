<?php
/**
 * Cancel a pending account deletion.
 *
 * POST {token: "<jwt>"}  →  {success}
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/deletion-auth.php';

setCorsHeaders();
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$payload = deletionVerifyJWT($input['token'] ?? '');
if (!$payload) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or expired token']);
    exit;
}

$userId = $payload['sub'];
$request = deletionLoadRequest($userId);

if (!$request || $request['status'] !== 'pending') {
    http_response_code(404);
    echo json_encode(['error' => 'No pending deletion to cancel']);
    exit;
}

$request['status'] = 'cancelled';
$request['cancelled_at'] = time();
file_put_contents(deletionRequestPath($userId), json_encode($request, JSON_PRETTY_PRINT));

echo json_encode(['success' => true, 'message' => 'Deletion request cancelled.']);
