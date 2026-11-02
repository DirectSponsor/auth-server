<?php
/**
 * Deletion status for the current user.
 *
 * GET ?token=<jwt>  or  POST {token: "<jwt>"}
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/deletion-auth.php';

setCorsHeaders();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$token = $_GET['token'] ?? '';
if ($token === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $token = $input['token'] ?? '';
}

$auth = deletionAuthenticate($token);
if (!$auth) {
    http_response_code(401);
    echo json_encode(['error' => 'Not signed in']);
    exit;
}

$request = deletionLoadRequest($auth['user_id']);

if (!$request) {
    echo json_encode(['status' => 'none']);
    exit;
}

$out = ['status' => $request['status']];
if ($request['status'] === 'pending') {
    $out['execute_at'] = $request['execute_at'];
    $out['execute_date'] = date('Y-m-d', $request['execute_at']);
    $out['requested_at'] = $request['requested_at'];
}
echo json_encode($out);
