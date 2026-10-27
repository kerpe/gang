<?php
// Copy to config.php on the server (next to app/, outside public_html) and fill in.
// Admin password hash: php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"
return [
    'public_url' => 'https://gang.lt',
    'db_dsn' => 'mysql:host=localhost;dbname=YOUR_DATABASE;charset=utf8mb4',
    'db_user' => 'YOUR_DATABASE_USER',
    'db_password' => 'YOUR_DATABASE_PASSWORD',
    'admin_email' => 'you@example.com',
    'admin_hash' => 'PASSWORD_HASH',
    'mail_from' => 'GANG <info@gang.lt>',
    // Optional: Resend API key. Empty means PHP mail() (works on Hostinger).
    'resend_key' => '',
    'job_days' => 30,
];
