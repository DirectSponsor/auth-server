<?php
require_once 'config.php';
require_once 'email-helper.php';

$message = '';
$message_type = '';
$redirect_uri = $_GET['redirect_uri'] ?? '';
$authenticated = false;
$user_id = null;
$username = '';
$email = '';

// Validate redirect URI if provided
if ($redirect_uri && !validateRedirectUri($redirect_uri)) {
    $redirect_uri = '';
}

// Get token from Authorization header, GET, or POST
$token = null;
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
    $token = $matches[1];
} elseif (isset($_GET['token'])) {
    $token = $_GET['token'];
} elseif (isset($_POST['token'])) {
    $token = $_POST['token'];
}

// Validate JWT token
if ($token) {
    $parts = explode('.', $token);
    if (count($parts) === 3) {
        list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;
        
        // Verify signature
        $expectedSignature = base64UrlEncode(
            hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $jwt_secret, true)
        );
        
        if (hash_equals($expectedSignature, $signatureEncoded)) {
            // Decode payload
            $payload = json_decode(base64UrlDecode($payloadEncoded), true);
            
            if ($payload && isset($payload['exp']) && $payload['exp'] > time()) {
                $authenticated = true;
                $user_id = $payload['sub'];
                $username = $payload['username'] ?? '';
                $email = $payload['email'] ?? '';
            }
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $authenticated) {
    $old_password = $_POST['old_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($old_password) || empty($new_password) || empty($confirm_password)) {
        $message = 'Please fill in all fields.';
        $message_type = 'error';
    } elseif ($new_password !== $confirm_password) {
        $message = 'New passwords do not match.';
        $message_type = 'error';
    } elseif (strlen($new_password) < 8) {
        $message = 'New password must be at least 8 characters long.';
        $message_type = 'error';
    } else {
        try {
            $db = getAuthDB();
            
            // Get current password hash
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($old_password, $user['password_hash'])) {
                // Hash the new password
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                
                // Update password
                $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([$password_hash, $user_id]);
                
                // Send notification email
                sendPasswordChangedEmail($email, $username);
                
                $message = 'Your password has been successfully changed!';
                $message_type = 'success';
            } else {
                $message = 'Current password is incorrect.';
                $message_type = 'error';
            }
        } catch (Exception $e) {
            error_log("Change password error: " . $e->getMessage());
            $message = 'An error occurred. Please try again later.';
            $message_type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - DirectSponsor Authentication</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            max-width: 400px;
            width: 100%;
            padding: 40px;
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 24px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .user-info {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .user-info strong {
            color: #333;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            color: #555;
            margin-bottom: 8px;
            font-weight: 500;
        }
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
        }
        button {
            width: 100%;
            padding: 12px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }
        button:hover {
            background: #5568d3;
        }
        .message {
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .back-link {
            text-align: center;
            margin-top: 20px;
        }
        .back-link a {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
        }
        .back-link a:hover {
            text-decoration: underline;
        }
        .password-hint {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        .error-box {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            padding: 20px;
            border-radius: 6px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (!$authenticated): ?>
            <h1>Authentication Required</h1>
            <div class="error-box">
                <p style="margin-bottom: 10px;">You must be logged in to change your password.</p>
                <p>This page requires a valid authentication token.</p>
            </div>
            <div class="back-link">
                <a href="<?php echo $redirect_uri ? htmlspecialchars($redirect_uri) : 'https://roflfaucet.com/'; ?>">Back to site</a>
            </div>
        <?php else: ?>
            <h1>Change Password</h1>
            <p class="subtitle">Update your password for all DirectSponsor sites.</p>
            
            <div class="user-info">
                Logged in as: <strong><?php echo htmlspecialchars($username); ?></strong>
            </div>
            
            <?php if ($message): ?>
                <div class="message <?php echo htmlspecialchars($message_type); ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <?php if ($redirect_uri): ?>
                    <input type="hidden" name="redirect_uri" value="<?php echo htmlspecialchars($redirect_uri); ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="old_password">Current Password</label>
                    <input 
                        type="password" 
                        id="old_password" 
                        name="old_password" 
                        required 
                        placeholder="Enter current password"
                    >
                </div>
                
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input 
                        type="password" 
                        id="new_password" 
                        name="new_password" 
                        required 
                        minlength="8"
                        placeholder="Enter new password"
                    >
                    <div class="password-hint">At least 8 characters</div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input 
                        type="password" 
                        id="confirm_password" 
                        name="confirm_password" 
                        required 
                        minlength="8"
                        placeholder="Confirm new password"
                    >
                </div>
                
                <button type="submit">Change Password</button>
            </form>
            
            <div class="back-link">
                <a href="<?php echo $redirect_uri ? htmlspecialchars($redirect_uri) : 'https://roflfaucet.com/'; ?>">Back to site</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
