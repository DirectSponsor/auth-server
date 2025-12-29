<?php
require_once 'email-config.php';
require_once 'email-helper.php';

// Test email sending
$testEmail = 'andy@directsponsor.org'; // Change to your email
$testUsername = 'TestUser';
$testToken = generateToken();

echo "<h2>Email System Test</h2>";
echo "<p>Testing email to: " . htmlspecialchars($testEmail) . "</p>";

// Test password reset email
$result = sendPasswordResetEmail($testEmail, $testUsername, $testToken);

if ($result) {
    echo "<p>Check your inbox at " . htmlspecialchars($testEmail) . "</p>";
} else {
    echo "<p style='color: red;'>❌ Failed to send email</p>";
    echo "<p>Error: " . error_get_last()['message'] . "</p>";
}

echo "<p>Token generated: " . htmlspecialchars($testToken) . "</p>";
?>
