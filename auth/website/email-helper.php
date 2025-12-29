<?php
// Email Helper Functions for DirectSponsor Authentication System

require_once 'email-config.php';

/**
 * Generate a secure random token
 */
function generateToken() {
    return bin2hex(random_bytes(32)); // 64 character hex string
}

/**
 * Send email via SMTP with authentication
 */
function sendEmail($to, $subject, $body, $isHTML = true) {
    $host = SMTP_HOST;
    $port = SMTP_PORT;
    $username = SMTP_USERNAME;
    $password = SMTP_PASSWORD;
    $from = FROM_EMAIL;
    $fromName = FROM_NAME;
    
    // Connect to SMTP server
    // Port 465 uses SSL from the start, port 587 uses plain then STARTTLS
    if ($port == 465) {
        // SSL connection from the start
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);
        $smtp = stream_socket_client("ssl://$host:$port", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
    } else {
        // Plain connection for STARTTLS
        $smtp = fsockopen($host, $port, $errno, $errstr, 30);
    }
    
    if (!$smtp) {
        error_log("SMTP connection failed: $errstr ($errno)");
        return false;
    }
    
    // Read server greeting
    $response = fgets($smtp, 515);
    
    // Send EHLO
    fputs($smtp, "EHLO $host\r\n");
    // Read all EHLO response lines (multi-line response)
    $response = '';
    while ($line = fgets($smtp, 515)) {
        $response .= $line;
        // Last line has space after code (e.g., "250 HELP"), continuation lines have dash (e.g., "250-SIZE")
        if (preg_match('/^\d{3} /', $line)) break;
    }
    
    // STARTTLS if using port 587
    if ($port == 587) {
        fputs($smtp, "STARTTLS\r\n");
        $response = fgets($smtp, 515);
        
        // Enable TLS encryption
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);
        stream_context_set_option($smtp, 'ssl', 'verify_peer', false);
        stream_context_set_option($smtp, 'ssl', 'verify_peer_name', false);
        stream_context_set_option($smtp, 'ssl', 'allow_self_signed', true);
        
        if (!stream_socket_enable_crypto($smtp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log("STARTTLS failed");
            fclose($smtp);
            return false;
        }
        
        // Send EHLO again after STARTTLS
        fputs($smtp, "EHLO $host\r\n");
        // Read all EHLO response lines again
        $response = '';
        while ($line = fgets($smtp, 515)) {
            $response .= $line;
            if (preg_match('/^\d{3} /', $line)) break;
        }
    }
    
    // AUTH LOGIN
    fputs($smtp, "AUTH LOGIN\r\n");
    $response = fgets($smtp, 515);
    
    fputs($smtp, base64_encode($username) . "\r\n");
    $response = fgets($smtp, 515);
    
    fputs($smtp, base64_encode($password) . "\r\n");
    $response = fgets($smtp, 515);
    
    if (strpos($response, '235') === false) {
        error_log("SMTP AUTH failed: $response");
        fclose($smtp);
        return false;
    }
    
    // MAIL FROM
    fputs($smtp, "MAIL FROM: <$from>\r\n");
    $response = fgets($smtp, 515);
    
    // RCPT TO
    fputs($smtp, "RCPT TO: <$to>\r\n");
    $response = fgets($smtp, 515);
    
    // DATA
    fputs($smtp, "DATA\r\n");
    $response = fgets($smtp, 515);
    
    // Message headers and body
    $message = "From: $fromName <$from>\r\n";
    $message .= "To: <$to>\r\n";
    $message .= "Subject: $subject\r\n";
    $message .= "MIME-Version: 1.0\r\n";
    
    if ($isHTML) {
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    } else {
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    }
    
    $message .= "\r\n";
    $message .= $body;
    $message .= "\r\n.\r\n";
    
    fputs($smtp, $message);
    $response = fgets($smtp, 515);
    
    // QUIT
    fputs($smtp, "QUIT\r\n");
    fclose($smtp);
    
    return strpos($response, '250') !== false;
}

/**
 * Send password reset email
 */
function sendPasswordResetEmail($email, $username, $token) {
    $resetLink = SITE_URL . "/reset-password.php?token=" . urlencode($token);
    
    $subject = "Reset Your " . SITE_NAME . " Password";
    
    $body = "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .button { display: inline-block; padding: 12px 24px; background-color: #007bff; color: white; text-decoration: none; border-radius: 4px; margin: 20px 0; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class='container'>
        <h2>Password Reset Request</h2>
        <p>Hi <strong>" . htmlspecialchars($username) . "</strong>,</p>
        <p>We received a request to reset your password. Click the button below to create a new password:</p>
        <p><a href='" . $resetLink . "' class='button'>Reset Password</a></p>
        <p>Or copy and paste this link into your browser:</p>
        <p><a href='" . $resetLink . "'>" . $resetLink . "</a></p>
        <p><strong>This link will expire in 1 hour.</strong></p>
        <p>If you didn't request this, you can safely ignore this email.</p>
        <div class='footer'>
            <p>Thanks,<br>" . SITE_NAME . " Team</p>
        </div>
    </div>
</body>
</html>";

    return sendEmail($email, $subject, $body, true);
}

/**
 * Send email verification email
 */
function sendVerificationEmail($email, $username, $token) {
    $verificationLink = SITE_URL . "/verify-email.php?token=" . urlencode($token);
    
    $subject = "Verify Your " . SITE_NAME . " Email";
    
    $body = "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .button { display: inline-block; padding: 12px 24px; background-color: #28a745; color: white; text-decoration: none; border-radius: 4px; margin: 20px 0; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class='container'>
        <h2>Welcome to " . SITE_NAME . "!</h2>
        <p>Hi <strong>" . htmlspecialchars($username) . "</strong>,</p>
        <p>Thanks for signing up! Please verify your email address by clicking the button below:</p>
        <p><a href='" . $verificationLink . "' class='button'>Verify Email</a></p>
        <p>Or copy and paste this link into your browser:</p>
        <p><a href='" . $verificationLink . "'>" . $verificationLink . "</a></p>
        <p><strong>This link will expire in 24 hours.</strong></p>
        <div class='footer'>
            <p>Thanks,<br>" . SITE_NAME . " Team</p>
        </div>
    </div>
</body>
</html>";

    return sendEmail($email, $subject, $body, true);
}

/**
 * Send password changed notification email
 */
function sendPasswordChangedEmail($email, $username) {
    $subject = "Your " . SITE_NAME . " Password Was Changed";
    
    $body = "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .alert { background-color: #fff3cd; border: 1px solid #ffc107; padding: 12px; border-radius: 4px; margin: 20px 0; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class='container'>
        <h2>Password Changed</h2>
        <p>Hi <strong>" . htmlspecialchars($username) . "</strong>,</p>
        <p>Your password was successfully changed.</p>
        <div class='alert'>
            <strong>⚠️ Security Notice:</strong> If you didn't make this change, please contact us immediately at " . FROM_EMAIL . "
        </div>
        <div class='footer'>
            <p>Thanks,<br>" . SITE_NAME . " Team</p>
        </div>
    </div>
</body>
</html>";

    return sendEmail($email, $subject, $body, true);
}

/**
 * Check rate limiting for email requests
 * Returns true if rate limit exceeded, false otherwise
 */
function checkRateLimit($email, $type = 'reset', $maxAttempts = 3, $timeWindow = 3600) {
    $rateFile = sys_get_temp_dir() . '/email_rate_' . md5($email . $type) . '.json';
    
    $attempts = [];
    if (file_exists($rateFile)) {
        $attempts = json_decode(file_get_contents($rateFile), true) ?? [];
    }
    
    // Remove attempts older than time window
    $now = time();
    $attempts = array_filter($attempts, function($timestamp) use ($now, $timeWindow) {
        return ($now - $timestamp) < $timeWindow;
    });
    
    // Check if limit exceeded
    if (count($attempts) >= $maxAttempts) {
        return true;
    }
    
    // Add new attempt
    $attempts[] = $now;
    file_put_contents($rateFile, json_encode($attempts));
    
    return false;
}
?>
