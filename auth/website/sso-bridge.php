<?php
/**
 * DirectSponsor SSO Bridge
 * 
 * This file is loaded in an invisible iframe on all network sites.
 * It checks the PHP session (which works because it's on auth.directsponsor.org)
 * and sends the session data to the parent window via postMessage.
 * 
 * Deploy to: /var/www/auth.directsponsor.org/public_html/sso-bridge.php
 */

// No CORS needed - this is loaded directly as HTML
session_start();

// Prepare session data
$sessionData = [
    'logged_in' => false,
    'source' => 'sso-bridge'
];

if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
    $sessionData = [
        'logged_in' => true,
        'user_id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'combined_user_id' => $_SESSION['user_id'] . '-' . $_SESSION['username'],
        'email' => $_SESSION['email'] ?? null,
        'session_expires' => $_SESSION['expires'] ?? null,
        'source' => 'sso-bridge'
    ];
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>SSO Bridge</title>
</head>
<body>
<script>
(function() {
    'use strict';
    
    // Session data from PHP
    const sessionData = <?php echo json_encode($sessionData); ?>;
    
    // Send to parent window
    // Note: Using '*' for target origin. For better security, you could restrict to specific origins.
    if (window.parent && window.parent !== window) {
        window.parent.postMessage({
            type: 'directsponsor-sso',
            data: sessionData
        }, '*');
        
        console.log('[SSO Bridge] Session data sent to parent:', sessionData.logged_in ? 'logged in' : 'not logged in');
    }
})();
</script>
</body>
</html>
