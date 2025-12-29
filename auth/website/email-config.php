<?php
// Email Configuration for DirectSponsor Authentication System

define('SMTP_HOST', 'smtp.directsponsor.org');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'auth@directsponsor.org');
define('SMTP_PASSWORD', 'bwojliezZLQzuVYA');
define('SMTP_ENCRYPTION', 'tls'); // 'tls' for port 587, 'ssl' for port 465

define('FROM_EMAIL', 'auth@directsponsor.org');
define('FROM_NAME', 'DirectSponsor Authentication');

define('SITE_NAME', 'DirectSponsor');
define('SITE_URL', 'https://auth.directsponsor.org');

// Token expiry times
define('RESET_TOKEN_EXPIRY', 3600); // 1 hour in seconds
define('VERIFICATION_TOKEN_EXPIRY', 86400); // 24 hours in seconds
?>
