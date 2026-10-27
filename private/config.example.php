<?php
// On Hostinger the deploy workflow writes config.php from repository secrets.
// For a manual or local setup, copy this to config.php (next to app/, outside public_html).
return [
    'public_url' => 'https://gang.lt',
    'db_dsn' => 'mysql:host=localhost;dbname=YOUR_DATABASE;charset=utf8mb4',
    'db_user' => 'YOUR_DATABASE_USER',
    'db_password' => 'YOUR_DATABASE_PASSWORD',
    // Logging in with this email opens /admin.
    'admin_email' => 'you@example.com',
    'mail_from' => 'GANG <info@gang.lt>',
    // Optional: Resend API key. Empty means PHP mail() (works on Hostinger).
    'resend_key' => '',
];
