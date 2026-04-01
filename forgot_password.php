<?php
// forgot_password.php – OTP-based password reset

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Otp.php';

if (User::isLoggedIn()) { header('Location: dashboard.php'); exit(); }

$db   = new Database();
$db->connect();
$otp  = new Otp($db);
$user = new User($db);

$error_message   = '';
$success_message = '';

// step 1 = enter email
// step 2 = enter OTP
// step 3 = set new password
$step = (int)($_SESSION['fp_step'] ?? 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Step 1: Send OTP ─────────────────────────────────────────────
    if ($action === 'send_otp') {
        $email = trim($_POST['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = 'Please enter a valid email address.';
        } else {
            $conn = $db->getConnection();
            $eml  = $conn->real_escape_string($email);
            $row  = $db->selectRow("SELECT user_id, full_name FROM users WHERE email='{$eml}' AND is_active=1");
            if (!$row) {
                // Deliberate vague message to avoid user enumeration
                $success_message = "If that email is registered, you'll receive an OTP shortly.";
            } else {
                $sent = $otp->send($email, 'reset_password', $email, $row['full_name']);
                if ($sent['success']) {
                    $_SESSION['fp_step']  = 2;
                    $_SESSION['fp_email'] = $email;
                    $_SESSION['fp_name']  = $row['full_name'];
                    $step = 2;
                    $success_message = "OTP sent to {$email}. Valid for 15 minutes.";
                } else {
                    $error_message = $sent['message'];
                }
            }
        }
    }

    // ── Step 2: Verify OTP ───────────────────────────────────────────
    elseif ($action === 'verify_otp') {
        $code  = trim($_POST['otp_code'] ?? '');
        $email = $_SESSION['fp_email'] ?? '';
        $step  = 2;
        if (!$email) { $error_message = 'Session expired. Please start over.'; $step = 1; }
        else {
            $res = $otp->verify($email, 'reset_password', $code);
            if ($res['success']) {
                $_SESSION['fp_step']     = 3;
                $_SESSION['fp_verified'] = true;
                $step = 3;
            } else {
                $error_message = $res['message'];
            }
        }
    }

    // ── Step 3: Set new password ─────────────────────────────────────
    elseif ($action === 'reset_password') {
        $email    = $_SESSION['fp_email']    ?? '';
        $verified = $_SESSION['fp_verified'] ?? false;
        $newpwd   = $_POST['new_password']   ?? '';
        $conpwd   = $_POST['confirm_password'] ?? '';
        $step     = 3;
        if (!$email || !$verified) { $error_message = 'Session expired.'; $step = 1; }
        elseif (strlen($newpwd) < 6) { $error_message = 'Password must be at least 6 characters.'; }
        elseif ($newpwd !== $conpwd)  { $error_message = 'Passwords do not match.'; }
        else {
            $res = $user->resetPassword($email, $newpwd);
            if ($res['success']) {
                unset($_SESSION['fp_step'], $_SESSION['fp_email'], $_SESSION['fp_name'], $_SESSION['fp_verified']);
                $success_message = 'Password reset successfully! Redirecting to login…';
                header('Refresh: 2; url=login.php');
                $step = 1;
            } else {
                $error_message = $res['message'];
            }
        }
    }

    // ── Resend OTP ────────────────────────────────────────────────────
    elseif ($action === 'resend_otp') {
        $email = $_SESSION['fp_email'] ?? '';
        $name  = $_SESSION['fp_name']  ?? '';
        $step  = 2;
        if ($email) {
            $sent = $otp->send($email, 'reset_password', $email, $name);
            $success_message = $sent['success'] ? "OTP resent to {$email}." : $sent['message'];
            if (!$sent['success']) $error_message = $sent['message'];
        } else {
            $error_message = 'Session expired.'; $step = 1;
        }
    }
}

$pageTitle = 'Forgot Password – Pakistan Railways';
require_once 'inc/header.php';
?>

<style>
.fp-page {
    min-height: calc(100vh - 120px);
    background: linear-gradient(135deg, #0f1e32 0%, #1a3a5c 50%, #1e40af 100%);
    display: flex; align-items: center; justify-content: center;
    padding: 3rem 1rem;
    position: relative; overflow: hidden;
}
.fp-page::before { content:''; position:absolute; inset:0; background-image:radial-gradient(rgba(255,255,255,.05) 1px,transparent 1px); background-size:26px 26px; pointer-events:none; }
.fp-card {
    background: #fff; border-radius: 22px;
    box-shadow: 0 24px 80px rgba(0,0,0,.25);
    width: 100%; max-width: 440px;
    padding: 2.75rem 2.5rem 2.25rem;
    position: relative; z-index: 1;
    animation: slideUp .45s ease both;
}
@keyframes slideUp { from{opacity:0;transform:translateY(28px)} to{opacity:1;transform:translateY(0)} }
.fp-logo { text-align:center; margin-bottom:1.75rem; }
.fp-icon { width:60px;height:60px;border-radius:16px;background:linear-gradient(135deg,#2563eb,#1e40af);display:inline-flex;align-items:center;justify-content:center;font-size:1.8rem;margin-bottom:.75rem;box-shadow:0 8px 24px rgba(37,99,235,.35); }
.fp-logo h1 { font-size:1.45rem;font-weight:800;color:#0f172a;margin:0; }
.fp-logo p  { color:#64748b;font-size:.875rem;margin:.25rem 0 0; }
.fp-step-dots { display:flex;justify-content:center;gap:.5rem;margin-bottom:1.5rem; }
.fp-dot { width:10px;height:10px;border-radius:50%;background:#e2e8f0;transition:background .3s; }
.fp-dot.active { background:#2563eb; }
.auth-label { font-size:.8rem;font-weight:600;color:#374151;margin-bottom:.35rem;display:block; }
.auth-input { display:block;width:100%;padding:.725rem 1rem;border:1.5px solid #d1d5db;border-radius:10px;font-size:.925rem;color:#0f172a;background:#f9fafb;transition:border-color .2s,box-shadow .2s; }
.auth-input:focus { outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.15);background:#fff; }
.btn-auth { display:block;width:100%;padding:.85rem;background:linear-gradient(135deg,#2563eb,#1e40af);color:#fff;border:none;border-radius:12px;font-size:1rem;font-weight:700;cursor:pointer;box-shadow:0 4px 16px rgba(37,99,235,.35);transition:opacity .2s;margin-top:.5rem; }
.btn-auth:hover { opacity:.9; }
.alert-err { background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;border-radius:10px;padding:.75rem 1rem;font-size:.875rem;display:flex;align-items:center;gap:.6rem;margin-bottom:1.25rem; }
.alert-ok  { background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;border-radius:10px;padding:.75rem 1rem;font-size:.875rem;display:flex;align-items:center;gap:.6rem;margin-bottom:1.25rem; }
.pw-wrap { position:relative; }
.pw-toggle { position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94a3b8;font-size:1.05rem; }
</style>

<div class="fp-page">
<div class="fp-card">
    <div class="fp-logo">
        <div class="fp-icon">🔑</div>
        <h1>Forgot Password</h1>
        <p>
            <?php if ($step===1) echo 'Enter your email to receive a reset code';
            elseif ($step===2) echo 'Enter the OTP sent to your email';
            else               echo 'Set a new password'; ?>
        </p>
    </div>

    <!-- Step indicator -->
    <div class="fp-step-dots">
        <div class="fp-dot <?= $step>=1?'active':'' ?>"></div>
        <div class="fp-dot <?= $step>=2?'active':'' ?>"></div>
        <div class="fp-dot <?= $step>=3?'active':'' ?>"></div>
    </div>

    <?php if ($error_message): ?>
    <div class="alert-err"><i class="bi bi-exclamation-circle-fill"></i><?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>
    <?php if ($success_message): ?>
    <div class="alert-ok"><i class="bi bi-check-circle-fill"></i><?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
    <!-- STEP 1: Email -->
    <form method="POST">
        <input type="hidden" name="action" value="send_otp">
        <label class="auth-label" for="email">Email Address</label>
        <input type="email" id="email" name="email" class="auth-input" placeholder="you@example.com" required autofocus>
        <button type="submit" class="btn-auth mt-3"><i class="bi bi-send me-2"></i>Send OTP</button>
    </form>

    <?php elseif ($step === 2): ?>
    <!-- STEP 2: OTP -->
    <form method="POST">
        <input type="hidden" name="action" value="verify_otp">
        <label class="auth-label">OTP Code</label>
        <input type="text" name="otp_code" class="auth-input" placeholder="— — — — — —"
               maxlength="6" inputmode="numeric" pattern="\d{6}" required autofocus
               style="font-size:2rem;font-weight:900;letter-spacing:.5rem;text-align:center;">
        <button type="submit" class="btn-auth mt-3"><i class="bi bi-shield-check me-2"></i>Verify OTP</button>
    </form>
    <form method="POST" class="mt-3 text-center">
        <input type="hidden" name="action" value="resend_otp">
        <button type="submit" style="background:none;border:none;color:#2563eb;font-size:.85rem;cursor:pointer;font-weight:600;">
            <i class="bi bi-arrow-repeat me-1"></i>Resend OTP
        </button>
    </form>

    <?php elseif ($step === 3): ?>
    <!-- STEP 3: New password -->
    <form method="POST">
        <input type="hidden" name="action" value="reset_password">
        <label class="auth-label">New Password</label>
        <div class="pw-wrap mb-3">
            <input type="password" name="new_password" id="np" class="auth-input" placeholder="Min. 6 characters"
                   required style="padding-right:2.8rem;">
            <button type="button" class="pw-toggle" onclick="var f=document.getElementById('np');f.type=f.type==='password'?'text':'password';">
                <i class="bi bi-eye"></i>
            </button>
        </div>
        <label class="auth-label">Confirm Password</label>
        <input type="password" name="confirm_password" class="auth-input mb-3" placeholder="Repeat password" required>
        <button type="submit" class="btn-auth"><i class="bi bi-check-lg me-2"></i>Reset Password</button>
    </form>
    <?php endif; ?>

    <p class="text-center mt-3" style="font-size:.85rem;color:#64748b;">
        <a href="login.php" style="color:#2563eb;font-weight:600;">← Back to Login</a>
    </p>
</div>
</div>

<?php require_once 'inc/footer.php'; ?>
