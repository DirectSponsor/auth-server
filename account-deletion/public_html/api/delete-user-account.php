<?php
/**
 * Account Deletion REQUEST API
 *
 * Verifies the caller's JWT and queues a deletion request. Nothing is
 * deleted here — cron/process-deletions.php executes requests after
 * DELETION_GRACE_DAYS days. Cancel via cancel-deletion.php.
 *
 * POST {token: "<jwt>"}  →  {success, execute_at, cancel_info}
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/email-helper.php';
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
$auth = deletionAuthenticate($input['token'] ?? '');
if (!$auth) {
    http_response_code(401);
    echo json_encode(['error' => 'Not signed in']);
    exit;
}
deletionCheckOrigin();

$userId = $auth['user_id'];

// Already pending? Just report the existing schedule.
$existing = deletionLoadRequest($userId);
if ($existing && $existing['status'] === 'pending') {
    echo json_encode([
        'success' => true,
        'already_pending' => true,
        'execute_at' => $existing['execute_at'],
        'execute_date' => date('Y-m-d', $existing['execute_at']),
    ]);
    exit;
}
if ($existing && $existing['status'] === 'completed') {
    http_response_code(409);
    echo json_encode(['error' => 'Account already deleted']);
    exit;
}

if (!is_dir(DELETION_REQUESTS_DIR)) {
    mkdir(DELETION_REQUESTS_DIR, 0750, true);
}

$request = [
    'user_id' => $userId,
    'username' => $auth['username'],
    'requested_at' => time(),
    'execute_at' => time() + (DELETION_GRACE_DAYS * 86400),
    'status' => 'pending',
];

if (!file_put_contents(deletionRequestPath($userId), json_encode($request, JSON_PRETTY_PRINT))) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not record deletion request']);
    exit;
}

// Email the account owner — this is the real safety net for unauthorized
// requests: the actual owner gets told and can cancel.
$email = $auth['email'];
if ($email) {
    $date = date('Y-m-d', $request['execute_at']);
    sendEmail(
        $email,
        'Account deletion requested',
        "<p>A request was made to delete your account data. If this was you, no action is needed.</p>" .
        "<p>Your data will be permanently deleted on <b>$date</b>.</p>" .
        "<p>If you did NOT request this, sign in and use the cancel option on the delete-account page " .
        "before that date: <a href=\"https://auth.directsponsor.org/delete-account.php\">cancel deletion</a>.</p>"
    );
}

echo json_encode([
    'success' => true,
    'message' => 'Deletion scheduled.',
    'execute_at' => $request['execute_at'],
    'execute_date' => date('Y-m-d', $request['execute_at']),
    'grace_days' => DELETION_GRACE_DAYS,
    'cancel_info' => 'Sign in and visit delete-account.php before the scheduled date to cancel.',
]);
