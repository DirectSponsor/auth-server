<?php
require_once 'config.php';
require_once 'email-helper.php';

$message = '';
$message_type = '';
$redirect_uri = $_GET['redirect_uri'] ?? '';

// Start session to store redirect_uri for verification page
session_start();
if ($redirect_uri) {
    if (validateRedirectUri($redirect_uri)) {
        $_SESSION['login_redirect_uri'] = $redirect_uri;
    } else {
        $redirect_uri = '';
    }
} else {
    // try to get from session if not in GET
    $redirect_uri = $_SESSION['login_redirect_uri'] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_id'])) {
    $login_id = trim($_POST['login_id']);
    
    if (empty($login_id)) {
        $message = 'Please enter your email or username';
        $message_type = 'error';
    } else {
        // Check rate limiting
        if (checkRateLimit($login_id, 'magic', 5, 300)) { // 5 attempts per 5 minutes
            $message = 'Too many login attempts. Please try again later.';
            $message_type = 'error';
        } else {
            try {
                $db = getAuthDB();
                
                $user = null;
                // Determine if input is email
                if (filter_var($login_id, FILTER_VALIDATE_EMAIL)) {
                    // It's an email
                    $stmt = $db->prepare("SELECT id, username, email FROM users WHERE email = ?");
                    $stmt->execute([$login_id]);
                    $user = $stmt->fetch();
                } else {
                    // Treat as username
                    $stmt = $db->prepare("SELECT id, username, email FROM users WHERE username = ?");
                    $stmt->execute([$login_id]);
                    $user = $stmt->fetch();
                }
                
                if ($user) {
                    // Generate magic token
                    $token = generateToken();
                    $expires = date('Y-m-d H:i:s', time() + 300); // 5 minutes expiry
                    
                    // Store token in database
                    $stmt = $db->prepare("UPDATE users SET magic_token = ?, magic_token_expires = ? WHERE id = ?");
                    $stmt->execute([$token, $expires, $user['id']]);
                    
                    // Send email
                    if (sendMagicLinkEmail($user['email'], $user['username'], $token)) {
                        $message = 'We sent a login link to your email. Click it to log in instantly!';
                        $message_type = 'success';
                    } else {
                        $message = 'Failed to send email. Please try again later.';
                        $message_type = 'error';
                    }
                } else {
                    $message = 'If an account exists with this email or username, we sent a login link to the registered email.';
                    $message_type = 'success';
                }
            } catch (Exception $e) {
                error_log("Magic login error: " . $e->getMessage());
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
    <title>Magic Link Login &mdash; DirectSponsor &middot; RoflFaucet &middot; Click For Charity</title>
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
        <h1>Magic Link Login</h1>
        <p class="subtitle">Enter your email or username and we'll send you a link to log in instantly. No password required.</p>
        
        <?php if ($message): ?>
            <div class="message <?php echo htmlspecialchars($message_type); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="login_id">Email or Username</label>
                <input 
                    type="text" 
                    id="login_id" 
                    name="login_id" 
                    required 
                    placeholder="Enter email or username"
                    value="<?php echo isset($_POST['login_id']) ? htmlspecialchars($_POST['login_id']) : ''; ?>"
                >
            </div>
            
            <?php if ($redirect_uri): ?>
                <input type="hidden" name="redirect_uri" value="<?php echo htmlspecialchars($redirect_uri); ?>">
            <?php endif; ?>
            
            <button type="submit">Send Login Link</button>
        </form>
        
        <div class="back-link">
            <a href="jwt-login.php<?php echo $redirect_uri ? '?redirect_uri=' . urlencode($redirect_uri) : ''; ?>">Back to Password Login</a>
        </div>
    </div>
</body>
</html>
