<?php
// config/mail.php – SMTP credentials for PHPMailer
// ─────────────────────────────────────────────────────────────────────────────
// Gmail setup:
//   1. Enable 2-Step Verification on your Google account.
//   2. Go to https://myaccount.google.com/apppasswords
//   3. Create an App Password (select "Mail" + "Windows Computer").
//   4. Paste the 16-character password below (no spaces).
// ─────────────────────────────────────────────────────────────────────────────

return [
    'host'       => 'smtp.gmail.com',   // or smtp.office365.com, etc.
    'port'       => 587,
    'encryption' => 'tls',              // 'tls' for port 587, 'ssl' for port 465
    'username'   => '',  // your Gmail address
    'password'   => '',          // 16-char App Password
    'from_email' => '',
    'from_name'  => 'Pakistan Railways',
];
