<?php
require_once 'config.php';
require_once 'email-helper.php';

setCorsHeaders();

// Start session for SSO
session_start();

/**
 * Get the next available user ID, reusing deleted IDs
 */
function getNextUserId($db) {
    // Find the lowest available user ID by looking for gaps
    $stmt = $db->prepare("
        SELECT t1.id + 1 as next_id
        FROM users t1
        LEFT JOIN users t2 ON t1.id + 1 = t2.id
        WHERE t2.id IS NULL
        ORDER BY t1.id
        LIMIT 1
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    
    if ($result) {
        return $result['next_id'];
    } else {
        // No users exist, start with ID 1
        return 1;
    }
}

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

// Handle signup form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'], $_POST['password'], $_POST['email'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $email = trim($_POST['email']);
    
    if (empty($username) || empty($password) || empty($email)) {
        $error_message = 'All fields are required';
    } elseif (strlen($password) < 8) {
        $error_message = 'Password must be at least 8 characters';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address';
    } else {
        try {
            $db = getAuthDB();
            
            // Check if username already exists
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error_message = 'Username already exists';
            } else {
                // Check if email already exists
                $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $error_message = 'Email already registered';
                } else {
                    // Create new user with recycled ID and email unverified
                    $user_id = getNextUserId($db);
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Generate verification token
                    $verification_token = generateToken();
                    $token_expires = date('Y-m-d H:i:s', time() + VERIFICATION_TOKEN_EXPIRY);
                    
                    $signup_site = siteFromRedirectUri($redirect_uri);
                    $stmt = $db->prepare("INSERT INTO users (id, username, password_hash, email, email_verified, verification_token, verification_token_expires, signup_site) VALUES (?, ?, ?, ?, 1, ?, ?, ?)");
                    $stmt->execute([$user_id, $username, $hashed_password, $email, $verification_token, $token_expires, $signup_site]);
                    
                    // Create initial user data files
                    $user_identifier = $user_id . '-' . $username;
                    $data_dir = '/var/directsponsor-data/userdata';
                    
                    // Create balance file
                    $balance_data = [
                        'user_id' => $user_identifier,
                        'username' => $username,
                        'balance' => ['coins' => 0],
                        'last_updated' => date('c')
                    ];
                    file_put_contents("$data_dir/balances/$user_identifier.txt", json_encode($balance_data, JSON_PRETTY_PRINT));
                    
                    // Create profile file
                    $profile_data = [
                        'user_id' => $user_identifier,
                        'username' => $username,
                        'profile' => [
                            'display_name' => $username,
                            'avatar' => '👤',
                            'bio' => '',
                            'location' => '',
                            'website' => ''
                        ],
                        'global_roles' => [],
                        'site_roles' => [
                            'roflfaucet' => [],
                            'clickforcharity' => []
                        ],
                        'last_updated' => date('c')
                    ];
                    file_put_contents("$data_dir/profiles/$user_identifier.txt", json_encode($profile_data, JSON_PRETTY_PRINT));
                    
                    // Note: Sites fetch user data via sync API from auth server
                    // No need to copy files - the API serves them from $data_dir
                    
                    // Send verification email (optional - user can use site regardless)
                    sendVerificationEmail($email, $username, $verification_token);
                    
                    // Set PHP session for SSO
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['username'] = $username;
                    $_SESSION['email'] = $email;
                    $_SESSION['expires'] = time() + (30 * 24 * 60 * 60); // 30 days
                    
                    // Create JWT token for immediate login
                    global $jwt_issuer, $jwt_expiry;
                    
                    $now = time();
                    $payload = [
                        'iss' => $jwt_issuer,
                        'iat' => $now,
                        'exp' => $now + $jwt_expiry,
                        'sub' => (string)$user_id,
                        'username' => $username,
                        'email' => $email
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
                            'expires_in' => $jwt_expiry,
                            'message' => 'Account created successfully'
                        ]);
                        exit;
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Signup error: " . $e->getMessage());
            $error_message = 'Registration failed. Please try again.';
        }
    }
}

// Show signup form
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DirectSponsor Signup</title>
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
        
        .signup-container {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
        }
        
        .signup-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .signup-header h1 {
            color: #333;
            margin: 0;
            font-size: 1.8rem;
        }
        
        .signup-header p {
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
        input[type="email"],
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
        input[type="email"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .signup-button {
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
        
        .signup-button:hover {
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
        
        .login-link {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #eee;
        }
        
        .login-link a {
            color: #667eea;
            text-decoration: none;
        }
        
        .login-link a:hover {
            text-decoration: underline;
        }
        
        .password-hint {
            font-size: 0.85rem;
            color: #666;
            margin-top: 0.25rem;
        }
    </style>
</head>
<body>
    <div class="signup-container">
        <div class="signup-header">
            <h1>🚀 Join DirectSponsor</h1>
            <p>Create your account</p>
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
                <label for="email">Email:</label>
                <input type="email" 
                       id="email" 
                       name="email" 
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                       required>
            </div>
            
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required minlength="8">
                <div class="password-hint">Minimum 8 characters</div>
            </div>
            
            <button type="submit" class="signup-button">Create Account</button>
        </form>
        
        <div class="login-link">
            Already have an account? <a href="jwt-login.php<?php echo $redirect_uri ? '?redirect_uri=' . urlencode($redirect_uri) : ''; ?>">Sign in here</a>
        </div>
    </div>
</body>
</html>
