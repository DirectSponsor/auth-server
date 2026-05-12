<?php
require_once 'config.php';

$message = '';
$message_type = '';
$token = $_GET['token'] ?? '';
$verified = false;

// Validate token
if ($token) {
    try {
        $db = getAuthDB();
        
        // Check if token exists and is not expired
        $stmt = $db->prepare("SELECT id, username, email FROM users WHERE verification_token = ? AND verification_token_expires > NOW()");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Mark email as verified and clear token
            $stmt = $db->prepare("UPDATE users SET email_verified = 1, verification_token = NULL, verification_token_expires = NULL WHERE id = ?");
            $stmt->execute([$user['id']]);
            
            $message = 'Your email has been successfully verified! You can now log in.';
            $message_type = 'success';
            $verified = true;
        } else {
            $message = 'This verification link is invalid or has expired.';
            $message_type = 'error';
        }
    } catch (Exception $e) {
        error_log("Email verification error: " . $e->getMessage());
        $message = 'An error occurred. Please try again.';
        $message_type = 'error';
    }
} else {
    $message = 'No verification token provided.';
    $message_type = 'error';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - DirectSponsor Authentication</title>
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
            text-align: center;
        }
        h1 {
            color: #333;
            margin-bottom: 20px;
            font-size: 24px;
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
        .icon {
            font-size: 48px;
            margin-bottom: 20px;
        }
        .icon.success {
            color: #28a745;
        }
        .icon.error {
            color: #dc3545;
        }
        .links {
            margin-top: 30px;
        }
        .links a {
            display: inline-block;
            margin: 5px 10px;
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
        }
        .links a:hover {
            text-decoration: underline;
        }
        .links a.button {
            color: white;
        }
        button, .button {
            display: inline-block;
            padding: 12px 24px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
            text-decoration: none;
            margin-top: 20px;
        }
        button:hover, .button:hover {
            background: #5568d3;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon <?php echo $message_type; ?>">
            <?php echo $verified ? '✓' : '✗'; ?>
        </div>
        
        <h1><?php echo $verified ? 'Email Verified!' : 'Verification Failed'; ?></h1>
        
        <div class="message <?php echo htmlspecialchars($message_type); ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
        
        <?php if ($verified): ?>
            <p style="font-size:14px;color:#555;margin-bottom:24px;">Your account works across all three of our sites &mdash; choose where you&rsquo;d like to go:</p>
            <div class="links">
                <a href="https://directsponsor.net/" class="button">DirectSponsor</a>
                <a href="https://roflfaucet.com/" class="button">RoflFaucet</a>
                <a href="https://clickforcharity.net/" class="button">Click For Charity</a>
            </div>
        <?php else: ?>
            <div class="links">
                <a href="/resend-verification.php">Resend verification email</a>
            </div>
            <div class="links">
                <a href="https://directsponsor.net/">DirectSponsor</a> |
                <a href="https://roflfaucet.com/">RoflFaucet</a> |
                <a href="https://clickforcharity.net/">Click For Charity</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
