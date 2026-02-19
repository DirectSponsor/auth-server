<?php
require_once 'config.php';

$token = $_GET['token'] ?? '';
$error_message = '';

if (!$token) {
    die('Invalid token');
}

try {
    $db = getAuthDB();
    
    // Check if token exists and is valid
    $stmt = $db->prepare("SELECT id, username, email FROM users WHERE magic_token = ? AND magic_token_expires > NOW()");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    if ($user) {
        // Clear the token
        $updateInfo = $db->prepare("UPDATE users SET magic_token = NULL, magic_token_expires = NULL WHERE id = ?");
        $updateInfo->execute([$user['id']]);
        
        // Log the user in (Same logic as jwt-login.php)
        session_start();
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['expires'] = time() + (30 * 24 * 60 * 60); // 30 days
        
        $login_site = siteFromRedirectUri($_SESSION['login_redirect_uri'] ?? '');
        logLoginEvent($db, $user['id'], $login_site, 'magic');
        
        // Create JWT token
        global $jwt_issuer, $jwt_expiry;
        
        $now = time();
        $payload = [
            'iss' => $jwt_issuer,
            'iat' => $now,
            'exp' => $now + $jwt_expiry,
            'sub' => (string)$user['id'],
            'username' => $user['username'],
            'email' => $user['email']
        ];
        
        $jwt_token = createJWT($payload);
        
        // Default redirect
        $redirect_url = "https://roflfaucet.com/"; 
        if (isset($_SESSION['login_redirect_uri'])) {
             $redirect_url = $_SESSION['login_redirect_uri'];
             unset($_SESSION['login_redirect_uri']);
        }
        
        // Append JWT
        $separator = strpos($redirect_url, '?') !== false ? '&' : '?';
        $final_url = $redirect_url . $separator . 'jwt=' . urlencode($jwt_token);
        
        header("Location: $final_url");
        exit;
        
    } else {
        $error_message = 'Invalid or expired login link. Please try again.';
    }
} catch (Exception $e) {
    error_log("Magic verify error: " . $e->getMessage());
    $error_message = 'An error occurred during verification.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Failed - DirectSponsor</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            color: white;
        }
        .container {
            text-align: center;
            background: rgba(255, 255, 255, 0.1);
            padding: 40px;
            border-radius: 10px;
            backdrop-filter: blur(10px);
        }
        h1 { margin-bottom: 20px; }
        a { color: #fff; text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Login Failed</h1>
        <p><?php echo htmlspecialchars($error_message); ?></p>
        <p><a href="magic-login.php">Try again</a></p>
    </div>
</body>
</html>
