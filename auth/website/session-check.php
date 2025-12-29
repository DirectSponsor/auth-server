<?php
/**
 * DirectSponsor Session Check API
 * 
 * Returns current session info for cross-domain SSO
 * Sites call this to check if user is logged in on the auth server
 */

require_once 'config.php';

setCorsHeaders();

// Handle OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();

// Check if user has an active session
if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
    $response = [
        'success' => true,
        'logged_in' => true,
        'user_id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'combined_user_id' => $_SESSION['user_id'] . '-' . $_SESSION['username'],
        'email' => $_SESSION['email'] ?? null,
        'session_expires' => $_SESSION['expires'] ?? null
    ];
} else {
    $response = [
        'success' => true,
        'logged_in' => false
    ];
}

header('Content-Type: application/json');
echo json_encode($response);
?>
