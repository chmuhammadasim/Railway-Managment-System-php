<?php

class PasswordReset {
    private const TTL_MINUTES = 30;

    private $db;
    private $mailCfg;

    public function __construct($database) {
        $this->db = $database;
        $this->mailCfg = require __DIR__ . '/../../config/mail.php';
        $this->ensureTable();
    }

    public function requestLink(int $userId, string $email, string $recipientName = ''): array {
        try {
            $token = bin2hex(random_bytes(32));
        } catch (\Exception $exception) {
            return ['success' => false, 'message' => 'Unable to generate a reset link right now.'];
        }

        $connection = $this->db->getConnection();
        $userId = (int) $userId;
        $emailEscaped = $connection->real_escape_string($email);
        $tokenHash = hash('sha256', $token);
        $tokenHashEscaped = $connection->real_escape_string($tokenHash);
        $expiresAt = date('Y-m-d H:i:s', time() + self::TTL_MINUTES * 60);
        $expiresAtEscaped = $connection->real_escape_string($expiresAt);

        $connection->query("UPDATE password_reset_tokens SET used=1 WHERE user_id={$userId} AND used=0");

        $inserted = $connection->query(
            "INSERT INTO password_reset_tokens (user_id, email, token_hash, expires_at)
             VALUES ({$userId}, '{$emailEscaped}', '{$tokenHashEscaped}', '{$expiresAtEscaped}')"
        );

        if (!$inserted) {
            return ['success' => false, 'message' => 'Unable to create a reset link right now.'];
        }

        $resetLink = $this->buildResetLink($token);
        if (!$this->sendEmail($email, $recipientName, $resetLink)) {
            // Do NOT invalidate the token – the link was created; only the email failed.
            // Check if SMTP is configured so we can return a helpful message.
            $smtpMissing = empty($this->mailCfg['username']) || empty($this->mailCfg['from_email']);
            $msg = $smtpMissing
                ? 'Email delivery is not configured on this server. Please contact the administrator.'
                : 'Unable to send the reset email right now. Please try again later.';
            return ['success' => false, 'message' => $msg, 'reset_link' => $smtpMissing ? $resetLink : ''];
        }

        return ['success' => true, 'message' => 'Password reset link sent successfully.'];
    }

    public function validateToken(string $token): array {
        if ($token === '' || !ctype_xdigit($token)) {
            return ['success' => false, 'message' => 'This reset link is invalid. Please request a new one.'];
        }

        $connection = $this->db->getConnection();
        $tokenHashEscaped = $connection->real_escape_string(hash('sha256', $token));

        $record = $this->db->selectRow(
            "SELECT password_reset_tokens.reset_id, password_reset_tokens.user_id, password_reset_tokens.email,
                    password_reset_tokens.expires_at, users.full_name
             FROM password_reset_tokens
             JOIN users ON users.user_id = password_reset_tokens.user_id
             WHERE password_reset_tokens.token_hash = '{$tokenHashEscaped}'
               AND password_reset_tokens.used = 0
             LIMIT 1"
        );

        if (!$record) {
            return ['success' => false, 'message' => 'This reset link is invalid or has already been used.'];
        }

        if (strtotime($record['expires_at']) < time()) {
            $connection->query(
                'UPDATE password_reset_tokens SET used=1 WHERE reset_id=' . (int) $record['reset_id']
            );
            return ['success' => false, 'message' => 'This reset link has expired. Please request a new one.'];
        }

        return [
            'success' => true,
            'user_id' => (int) $record['user_id'],
            'email' => $record['email'],
            'full_name' => $record['full_name'],
        ];
    }

    public function consumeToken(string $token): void {
        if ($token === '' || !ctype_xdigit($token)) {
            return;
        }

        $connection = $this->db->getConnection();
        $tokenHashEscaped = $connection->real_escape_string(hash('sha256', $token));
        $connection->query(
            "UPDATE password_reset_tokens SET used=1 WHERE token_hash='{$tokenHashEscaped}' AND used=0"
        );
    }

    private function ensureTable(): void {
        $this->db->query(
            'CREATE TABLE IF NOT EXISTS password_reset_tokens (
                reset_id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL,
                email VARCHAR(100) NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_reset_token (token_hash),
                INDEX idx_reset_user (user_id, used),
                INDEX idx_reset_expiry (expires_at, used),
                FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
            )'
        );
    }

    private function buildResetLink(string $token): string {
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        $scheme = $isSecure ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));

        if ($basePath === '/' || $basePath === '.' || $basePath === '\\') {
            $basePath = '';
        }

        return $scheme . '://' . $host . rtrim($basePath, '/') . '/forgot_password.php?token=' . rawurlencode($token);
    }

    private function sendEmail(string $to, string $name, string $resetLink): bool {
        $phpmailerPath = __DIR__ . '/../PHPMailer/PHPMailer.php';
        if (!file_exists($phpmailerPath)) {
            return false;
        }

        // Guard: require SMTP credentials to be configured
        if (empty($this->mailCfg['username']) || empty($this->mailCfg['from_email'])) {
            return false;
        }

        require_once $phpmailerPath;
        require_once __DIR__ . '/../PHPMailer/SMTP.php';
        require_once __DIR__ . '/../PHPMailer/Exception.php';

        $mailCfg = $this->mailCfg;
        $recipientName = $name !== '' ? $name : 'Passenger';
        $year = date('Y');

        $body = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
  body{font-family:Arial,sans-serif;background:#f4f6f8;margin:0;padding:0}
  .wrap{max-width:520px;margin:32px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.09)}
  .hdr{background:linear-gradient(135deg,#0b1728,#1a3a6e);padding:28px 32px;text-align:center;color:#fff}
  .body{padding:32px}
  .cta{display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:700;margin:18px 0}
  .meta{font-size:13px;color:#6b7280;line-height:1.6}
  .footer{background:#f8fafc;padding:16px 32px;text-align:center;font-size:12px;color:#9ca3af;border-top:1px solid #e5e7eb}
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr">
    <h1 style="margin:0;font-size:20px;">Reset Your Password</h1>
    <p style="margin:8px 0 0;opacity:.8;">Pakistan Railways account recovery</p>
  </div>
  <div class="body">
    <p style="color:#374151;font-size:15px;">Hi <strong>{$recipientName}</strong>,</p>
    <p class="meta">We received a request to reset your password. Use the button below to choose a new password. This link will expire in 30 minutes.</p>
    <p><a class="cta" href="{$resetLink}">Reset Password</a></p>
    <p class="meta">If the button does not work, copy and paste this link into your browser:</p>
    <p class="meta" style="word-break:break-all;">{$resetLink}</p>
    <p class="meta">If you did not request this change, you can ignore this email.</p>
  </div>
  <div class="footer">&copy; {$year} Pakistan Railways. All rights reserved.</div>
</div>
</body></html>
HTML;

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $mailCfg['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $mailCfg['username'];
            $mail->Password = $mailCfg['password'];
            $mail->SMTPSecure = $mailCfg['encryption'] === 'ssl'
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $mailCfg['port'];
            $mail->setFrom($mailCfg['from_email'], $mailCfg['from_name']);
            $mail->addAddress($to, $recipientName);
            $mail->isHTML(true);
            $mail->Subject = 'Reset Your Pakistan Railways Password';
            $mail->Body = $body;
            $mail->AltBody = "Reset your password using this link: {$resetLink}";
            $mail->send();
            return true;
        } catch (\Exception $exception) {
            error_log('Password reset mail error: ' . $exception->getMessage());
            return false;
        }
    }
}