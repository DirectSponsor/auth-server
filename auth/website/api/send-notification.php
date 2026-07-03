<?php
/**
 * send-notification.php
 * Internal server-to-server endpoint — sends an email to a DS user by username.
 * Called by RN1 cron scripts (e.g. sponsorship reminders). Never exposed to browsers.
 *
 * POST body (JSON):
 *   secret      — shared secret (set NOTIFY_SECRET below to match /root/.ds-notify-secret on RN1)
 *   username    — DS username to email
 *   subject     — email subject
 *   body_text   — plain text body
 *   body_html   — HTML body (optional; falls back to body_text wrapped in <pre>)
 *
 * Response: {"success": true} or {"error": "..."}
 */

header('Content-Type: application/json');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

// Shared secret — read from server file, never hardcoded in git
// Create on auth server: echo 'yourSecret' > /etc/ds-notify-secret && chmod 640 /etc/ds-notify-secret && chown root:apache /etc/ds-notify-secret
$_secret_file = '/etc/ds-notify-secret';
if (!file_exists($_secret_file)) {
    http_response_code(500);
    echo json_encode(['error' => 'Notification secret not configured on server']);
    exit;
}
define('NOTIFY_SECRET', trim(file_get_contents($_secret_file)));

require_once '../config.php';
require_once '../email-helper.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Validate secret
if (($input['secret'] ?? '') !== NOTIFY_SECRET) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$username  = trim($input['username'] ?? '');
$subject   = trim($input['subject'] ?? '');
$bodyText  = trim($input['body_text'] ?? '');
$bodyHtml  = trim($input['body_html'] ?? '');

if (!$username || !$subject || !$bodyText) {
    http_response_code(400);
    echo json_encode(['error' => 'username, subject and body_text are required']);
    exit;
}

// Look up email by username
try {
    $db   = getAuthDB();
    $stmt = $db->prepare('SELECT email FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $row  = $stmt->fetch();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error']);
    exit;
}

if (!$row || empty($row['email'])) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found or no email']);
    exit;
}

$to   = $row['email'];
$html = $bodyHtml ?: '<pre style="font-family:sans-serif;white-space:pre-wrap;">' . htmlspecialchars($bodyText) . '</pre>';

$sent = sendEmail($to, $subject, $html, true);

if ($sent) {
    echo json_encode(['success' => true, 'to' => $to]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Mail send failed']);
}
