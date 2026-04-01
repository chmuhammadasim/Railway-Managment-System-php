<?php
/**
 * Otp.php – Generate, store, send, and verify one-time passwords.
 *
 * Purposes:
 *   signup            – verify new account email
 *   reset_password    – forgotten-password flow
 *   booking_confirm   – confirm a railway booking
 *   profile_update    – confirm profile/password changes
 */
class Otp {
    private $db;
    private $mailCfg;

    // OTP validity in minutes per purpose
    private const TTL = [
        'signup'           => 15,
        'reset_password'   => 15,
        'booking_confirm'  => 10,
        'profile_update'   => 10,
    ];

    private const MAX_ATTEMPTS = 5;   // wrong codes before OTP is invalidated
    private const RESEND_WAIT  = 60;  // seconds before another OTP can be sent

    public function __construct($database) {
        $this->db      = $database;
        $this->mailCfg = require __DIR__ . '/../../config/mail.php';
    }

    // ─────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────

    /**
     * Generate + store + email an OTP.
     * Returns ['success'=>bool, 'message'=>string]
     */
    public function send(string $identifier, string $purpose, string $recipientEmail, string $recipientName = ''): array {
        if (!array_key_exists($purpose, self::TTL)) {
            return ['success' => false, 'message' => 'Unknown OTP purpose.'];
        }

        $conn = $this->db->getConnection();
        $id_e = $conn->real_escape_string($identifier);
        $pur  = $conn->real_escape_string($purpose);

        // Enforce resend throttle
        $recent = $this->db->selectRow(
            "SELECT created_at FROM otp_verifications
             WHERE identifier = '{$id_e}' AND purpose = '{$pur}'
             ORDER BY created_at DESC LIMIT 1"
        );
        if ($recent) {
            $age = time() - strtotime($recent['created_at']);
            if ($age < self::RESEND_WAIT) {
                $wait = self::RESEND_WAIT - $age;
                return ['success' => false, 'message' => "Please wait {$wait}s before requesting a new OTP."];
            }
        }

        // Invalidate old OTPs for this identifier+purpose
        $conn->query("UPDATE otp_verifications SET used=1
                      WHERE identifier='{$id_e}' AND purpose='{$pur}' AND used=0");

        $code      = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttl       = self::TTL[$purpose];
        $expires   = date('Y-m-d H:i:s', time() + $ttl * 60);
        $code_hash = password_hash($code, PASSWORD_BCRYPT);
        $code_e    = $conn->real_escape_string($code_hash);
        $exp_e     = $conn->real_escape_string($expires);

        $conn->query(
            "INSERT INTO otp_verifications (identifier, purpose, otp_code, expires_at)
             VALUES ('{$id_e}', '{$pur}', '{$code_e}', '{$exp_e}')"
        );

        // Send email
        $sent = $this->sendEmail($recipientEmail, $recipientName, $code, $purpose, $ttl);
        if (!$sent) {
            return ['success' => false, 'message' => 'Failed to send OTP email. Check mail configuration.'];
        }

        return ['success' => true, 'message' => "OTP sent to {$recipientEmail}. Valid for {$ttl} minutes."];
    }

    /**
     * Verify the code entered by the user.
     * Returns ['success'=>bool, 'message'=>string]
     * On success, marks OTP as used.
     */
    public function verify(string $identifier, string $purpose, string $code): array {
        $conn = $this->db->getConnection();
        $id_e = $conn->real_escape_string($identifier);
        $pur  = $conn->real_escape_string($purpose);

        $row = $this->db->selectRow(
            "SELECT * FROM otp_verifications
             WHERE identifier='{$id_e}' AND purpose='{$pur}' AND used=0
             ORDER BY created_at DESC LIMIT 1"
        );

        if (!$row) {
            return ['success' => false, 'message' => 'No active OTP found. Please request a new one.'];
        }

        // Check expiry
        if (strtotime($row['expires_at']) < time()) {
            $conn->query("UPDATE otp_verifications SET used=1 WHERE otp_id={$row['otp_id']}");
            return ['success' => false, 'message' => 'OTP has expired. Please request a new one.'];
        }

        // Check max attempts
        if ((int)$row['attempts'] >= self::MAX_ATTEMPTS) {
            $conn->query("UPDATE otp_verifications SET used=1 WHERE otp_id={$row['otp_id']}");
            return ['success' => false, 'message' => 'Too many incorrect attempts. Please request a new OTP.'];
        }

        if (!password_verify(trim($code), $row['otp_code'])) {
            $conn->query("UPDATE otp_verifications SET attempts=attempts+1 WHERE otp_id={$row['otp_id']}");
            $left = self::MAX_ATTEMPTS - (int)$row['attempts'] - 1;
            return ['success' => false, 'message' => "Incorrect OTP. {$left} attempt(s) remaining."];
        }

        // Mark used
        $conn->query("UPDATE otp_verifications SET used=1 WHERE otp_id={$row['otp_id']}");

        return ['success' => true, 'message' => 'OTP verified successfully.'];
    }

    // ─────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────

    private function sendEmail(string $to, string $name, string $code, string $purpose, int $ttl): bool {
        $phpmailerPath = __DIR__ . '/../PHPMailer/PHPMailer.php';
        if (!file_exists($phpmailerPath)) return false;

        require_once $phpmailerPath;
        require_once __DIR__ . '/../PHPMailer/SMTP.php';
        require_once __DIR__ . '/../PHPMailer/Exception.php';

        $cfg = $this->mailCfg;

        $subjects = [
            'signup'           => 'Verify Your Pakistan Railways Account',
            'reset_password'   => 'Reset Your Password – Pakistan Railways',
            'booking_confirm'  => 'Confirm Your Train Booking – Pakistan Railways',
            'profile_update'   => 'Confirm Profile Changes – Pakistan Railways',
        ];

        $labels = [
            'signup'           => 'Email Verification',
            'reset_password'   => 'Password Reset',
            'booking_confirm'  => 'Booking Confirmation',
            'profile_update'   => 'Profile Update',
        ];

        $subject  = $subjects[$purpose] ?? 'Your OTP – Pakistan Railways';
        $label    = $labels[$purpose]  ?? 'Verification';
        $nameDisp = $name ?: 'Valued Customer';
        $year     = date('Y');

        $body = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
  body{font-family:Arial,sans-serif;background:#f4f6f8;margin:0;padding:0}
  .wrap{max-width:520px;margin:32px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.09)}
  .hdr{background:linear-gradient(135deg,#0b1728,#1a3a6e);padding:28px 32px;text-align:center}
  .hdr img{height:40px}
  .hdr h1{color:#fff;font-size:20px;margin:12px 0 0;letter-spacing:.02em}
  .body{padding:32px}
  .otp-box{background:#f0f4ff;border:2px dashed #2563eb;border-radius:10px;text-align:center;padding:20px 10px;margin:24px 0}
  .otp-code{font-size:42px;font-weight:900;letter-spacing:14px;color:#1d4ed8;font-family:monospace}
  .otp-note{font-size:13px;color:#6b7280;margin-top:6px}
  .footer{background:#f8fafc;padding:16px 32px;text-align:center;font-size:12px;color:#9ca3af;border-top:1px solid #e5e7eb}
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr">
    <h1>🚆 Pakistan Railways</h1>
  </div>
  <div class="body">
    <p style="color:#374151;font-size:15px;">Hi <strong>{$nameDisp}</strong>,</p>
    <p style="color:#6b7280;font-size:14px;">
      Your <strong>{$label}</strong> OTP code is:
    </p>
    <div class="otp-box">
      <div class="otp-code">{$code}</div>
      <div class="otp-note">Valid for {$ttl} minutes &nbsp;·&nbsp; Do not share this code</div>
    </div>
    <p style="color:#6b7280;font-size:13px;">
      If you did not request this, please ignore this email or contact support.
    </p>
  </div>
  <div class="footer">© {$year} Pakistan Railways. All rights reserved.</div>
</div>
</body></html>
HTML;

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $cfg['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $cfg['username'];
            $mail->Password   = $cfg['password'];
            $mail->SMTPSecure = $cfg['encryption'];
            $mail->Port       = $cfg['port'];
            $mail->setFrom($cfg['from_email'], $cfg['from_name']);
            $mail->addAddress($to, $nameDisp);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = "Your {$label} OTP: {$code}  (valid {$ttl} min)";
            $mail->send();
            return true;
        } catch (\Exception $e) {
            error_log('OTP mail error: ' . $e->getMessage());
            return false;
        }
    }
}
