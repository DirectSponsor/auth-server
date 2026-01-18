<?php
require_once 'config.php';

setCorsHeaders();

// Start session for SSO
session_start();

// Handle OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$redirect_uri = $_GET['redirect_uri'] ?? $_POST['redirect_uri'] ?? '';
$error_message = '';

// Validate redirect URI
if ($redirect_uri && !validateRedirectUri($redirect_uri)) {
    http_response_code(400);
    die('Invalid redirect URI');
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'], $_POST['password'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error_message = 'Username and password are required';
    } else {
        try {
            $db = getAuthDB();
            
            // Get user from database
            $stmt = $db->prepare("SELECT id, username, email, password_hash FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                // Set PHP session for SSO
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['expires'] = time() + (30 * 24 * 60 * 60); // 30 days
                
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
                
                // Redirect with JWT token
                if ($redirect_uri) {
                    $separator = strpos($redirect_uri, '?') !== false ? '&' : '?';
                    $redirect_url = $redirect_uri . $separator . 'jwt=' . urlencode($jwt_token);
                    header("Location: $redirect_url");
                    exit;
                } else {
                    // Just return the token as JSON
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'jwt_token' => $jwt_token,
                        'expires_in' => $jwt_expiry
                    ]);
                    exit;
                }
            } else {
                $error_message = 'Invalid username or password';
            }
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            $error_message = 'Login failed. Please try again.';
        }
    }
}

// Show login form
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DirectSponsor Login</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="alternate icon" href="favicon.ico">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .login-container {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .login-header h1 {
            color: #333;
            margin: 0;
            font-size: 1.8rem;
        }
        
        .login-header p {
            color: #666;
            margin: 0.5rem 0 0 0;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
        }
        
        input[type="text"], 
        input[type="password"] {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s;
            box-sizing: border-box;
        }
        
        input[type="text"]:focus, 
        input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .login-button {
            width: 100%;
            padding: 0.75rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .login-button:hover {
            transform: translateY(-2px);
        }
        
        .error-message {
            background: #fee;
            color: #c33;
            padding: 0.75rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            text-align: center;
        }
        
        .signup-link {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #eee;
        }
        
        .signup-link a {
            color: #667eea;
            text-decoration: none;
        }
        
        .signup-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>🔐 DirectSponsor</h1>
            <p>Sign in to continue</p>
        </div>
        
        <?php if ($error_message): ?>
            <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <?php if ($redirect_uri): ?>
                <input type="hidden" name="redirect_uri" value="<?php echo htmlspecialchars($redirect_uri); ?>">
            <?php endif; ?>
            
            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" 
                       id="username" 
                       name="username" 
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                       required>
            </div>
            
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
                <div style="text-align: right; margin-top: 5px; font-size: 0.9em;">
                    <a href="forgot-password.php<?php echo $redirect_uri ? '?redirect_uri=' . urlencode($redirect_uri) : ''; ?>" style="color: #667eea; text-decoration: none;">Forgot Password?</a>
                </div>
            </div>
            
            <button type="submit" class="login-button">Sign In</button>
        </form>
        
        <div style="margin-top: 20px; text-align: center; border-bottom: 1px solid #eee; line-height: 0.1em; margin-bottom: 20px;">
            <span style="background:#fff; padding:0 10px; color:#999; font-size: 0.9em;">OR</span>
        </div>
        
        <div class="magic-link-section" style="text-align: center;">
            <a href="magic-login.php<?php echo $redirect_uri ? '?redirect_uri=' . urlencode($redirect_uri) : ''; ?>" style="display: inline-block; width: 100%; padding: 0.75rem; border: 2px solid #667eea; border-radius: 5px; color: #667eea; text-decoration: none; font-weight: 500; transition: all 0.2s; box-sizing: border-box;">
                ✨ Log in with Magic Link
            </a>
        </div>
        
        <div class="signup-link">
            Don't have an account? <a href="jwt-signup.php<?php echo $redirect_uri ? '?redirect_uri=' . urlencode($redirect_uri) : ''; ?>">Sign up here</a>
        </div>
    </div>
</body>
</html>
