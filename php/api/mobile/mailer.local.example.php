<?php
// Copy this file to mailer.local.php and fill your SMTP settings.
// Do NOT commit mailer.local.php with real credentials.

return [
    'host' => 'smtp.gmail.com',
    'port' => '587',
    'username' => 'your-email@gmail.com',
    'password' => 'your-app-password',
    'encryption' => 'tls',
    'from_email' => 'your-email@gmail.com',
    'from_name' => 'UniDiPay',
];
