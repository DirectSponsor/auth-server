<?php
require_once 'config.php';
require_once 'email-helper.php';

$message = '';
$message_type = '';
$redirect_uri = $_GET['redirect_uri'] ?? '';

// Start session to safe-keep redirect uri (though reset-password usually relies on user clicking link in email, typically we don't redirect back automatically after reset but we offer a link)
session_start();

// Validate redirect URI if provided
if ($redirect_uri) {
    if (validateRedirectUri($redirect_uri)) {
        $_SESSION['login_redirect_uri'] = $redirect_uri;
    } else {
        $redirect_uri = '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $username = trim($_POST['username']);
    
    if (empty($username)) {
        $message = 'Please enter your username';
        $message_type = 'error';
    } else {
        // Check rate limiting
        if (checkRateLimit($username, 'reset', 3, 3600)) {
            $message = 'Too many password reset attempts. Please try again later.';
            $message_type = 'error';
        } else {
            try {
                $db = getAuthDB();
                
                // Check if username exists
                $stmt = $db->prepare("SELECT id, username, email FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch();
                
                if ($user) {
                    // Generate reset token
                    $token = generateToken();
                    $expires = date('Y-m-d H:i:s', time() + RESET_TOKEN_EXPIRY);
                    
                    // Store token in database
                    $stmt = $db->prepare("UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?");
                    $stmt->execute([$token, $expires, $user['id']]);
                    
                    // Send email
                    if (sendPasswordResetEmail($user['email'], $user['username'], $token)) {
                        $message = 'Password reset instructions have been sent to the email address on file.';
                        $message_type = 'success';
                    } else {
                        $message = 'Failed to send email. Please try again later.';
                        $message_type = 'error';
                    }
                } else {
                    // Don't reveal if user exists or not for security (timing attack risk exists but minor here)
                    $message = 'If that username exists, we sent password reset instructions to the registered email.';
                    $message_type = 'success';
                }
            } catch (Exception $e) {
                error_log("Forgot password error: " . $e->getMessage());
                $message = 'An error occurred. Please try again later.';
                $message_type = 'error';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password &mdash; DirectSponsor &middot; RoflFaucet &middot; Click For Charity</title>
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
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            color: #555;
            margin-bottom: 8px;
            font-weight: 500;
        }
        input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        input[type="text"]:focus {
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
    </style>
</head>
<body>
    <div class="container">
        <h1>Forgot Password</h1>
        <p class="subtitle">Enter your username and we'll send reset instructions to your email.</p>
        
        <?php if ($message): ?>
            <div class="message <?php echo htmlspecialchars($message_type); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <input 
                    type="text" 
                    id="username" 
                    name="username" 
                    required 
                    placeholder="Enter your username"
                    value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                >
            </div>
            
            <?php if ($redirect_uri): ?>
                <input type="hidden" name="redirect_uri" value="<?php echo htmlspecialchars($redirect_uri); ?>">
            <?php endif; ?>
            
            <button type="submit">Send Reset Instructions</button>
        </form>
        
        <?php if ($redirect_uri): ?>
            <div class="back-link">
                <a href="<?php echo htmlspecialchars($redirect_uri); ?>">Back to site</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
