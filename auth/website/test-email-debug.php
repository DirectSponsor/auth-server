<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'email-config.php';

echo "<h2>SMTP Connection Test</h2>";
echo "<p>Host: " . SMTP_HOST . ":" . SMTP_PORT . "</p>";

$host = SMTP_HOST;
$port = SMTP_PORT;
$username = SMTP_USERNAME;
$password = SMTP_PASSWORD;

// Try SSL connection on port 465
$context = stream_context_create([
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true
    ]
]);

echo "<p>Attempting SSL connection...</p>";
$smtp = stream_socket_client("ssl://$host:$port", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);

if (;smtp) {
    echo "<p style='color: red;'>Connection failed: $errstr ($errno)</p>";
    exit;
}


// Read greeting
$response = fgets($smtp, 515);
echo "<p>Server: " . htmlspecialchars($response) . "</p>";

// EHLO
fputs($smtp, "EHLO $host\r\n");
$response = '';
while ($line = fgets($smtp, 515)) {
    $response .= $line;
    if (substr($line, 3, 1) == ' ') break;
}
echo "<p>EHLO: " . htmlspecialchars($response) . "</p>";

// AUTH LOGIN
fputs($smtp, "AUTH LOGIN\r\n");
$response = fgets($smtp, 515);
echo "<p>AUTH: " . htmlspecialchars($response) . "</p>";

fputs($smtp, base64_encode($username) . "\r\n");
$response = fgets($smtp, 515);
echo "<p>User: " . htmlspecialchars($response) . "</p>";

fputs($smtp, base64_encode($password) . "\r\n");
$response = fgets($smtp, 515);
echo "<p>Pass: " . htmlspecialchars($response) . "</p>";

if (strpos($response, '235') !== false) {
} else {
}

fputs($smtp, "QUIT\r\n");
fclose($smtp);
?>
