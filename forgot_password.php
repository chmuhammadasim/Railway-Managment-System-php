<?php
// forgot_password.php – Password reset with email link

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/PasswordReset.php';

if (User::isLoggedIn()) { header('Location: dashboard.php'); exit(); }

$db = new Database();
$db->connect();
$user = new User($db);
$passwordReset = new PasswordReset($db);

$error_message = '';
$success_message = '';
$step = 1;
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$reset_context = null;

if ($token !== '') {
    $reset_context = $passwordReset->validateToken($token);
    if ($reset_context['success']) {
        $step = 2;
    } else {
        $error_message = $reset_context['message'];
        $token = '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'request_reset') {
        $email = trim($_POST['email'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = 'Please enter a valid email address.';
        } else {
            $connection = $db->getConnection();
            $emailEscaped = $connection->real_escape_string($email);
            $account = $db->selectRow("SELECT user_id, full_name FROM users WHERE email='{$emailEscaped}' AND is_active=1");

            if ($account) {
                $result = $passwordReset->requestLink((int) $account['user_id'], $email, $account['full_name']);
                if (!$result['success']) {
                    $error_message = $result['message'];
                } else {
                    $success_message = "If that email is registered, we've sent a password reset link.";
                }
            } else {
                $success_message = "If that email is registered, we've sent a password reset link.";
            }
        }
    } elseif ($action === 'reset_password') {
        $token = trim($_POST['token'] ?? '');
        $reset_context = $passwordReset->validateToken($token);

        if (!$reset_context['success']) {
            $error_message = $reset_context['message'];
            $token = '';
            $step = 1;
        } else {
            $step = 2;
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            if (strlen($new_password) < 6) {
                $error_message = 'Password must be at least 6 characters.';
            } elseif ($new_password !== $confirm_password) {
                $error_message = 'Passwords do not match.';
            } else {
                $result = $user->resetPassword($reset_context['email'], $new_password);
                if ($result['success']) {
                    $passwordReset->consumeToken($token);
                    $success_message = 'Password reset successfully! Redirecting to login...';
                    header('Refresh: 2; url=login.php');
                    $step = 1;
                    $token = '';
                } else {
                    $error_message = $result['message'];
                }
            }
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
    width: 100%; max-width: 460px;
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
            <?php if ($step === 1): ?>
                Enter your email to receive a secure reset link
            <?php else: ?>
                Choose a new password for <?= htmlspecialchars($reset_context['email'] ?? '') ?>
            <?php endif; ?>
        </p>
    </div>

    <div class="fp-step-dots">
        <div class="fp-dot <?= $step >= 1 ? 'active' : '' ?>"></div>
        <div class="fp-dot <?= $step >= 2 ? 'active' : '' ?>"></div>
    </div>

    <?php if ($error_message): ?>
    <div class="alert-err"><i class="bi bi-exclamation-circle-fill"></i><?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>
    <?php if ($success_message): ?>
    <div class="alert-ok"><i class="bi bi-check-circle-fill"></i><?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
    <form method="POST">
        <input type="hidden" name="action" value="request_reset">
        <label class="auth-label" for="email">Email Address</label>
        <input type="email" id="email" name="email" class="auth-input" placeholder="you@example.com" required autofocus>
        <button type="submit" class="btn-auth mt-3"><i class="bi bi-send me-2"></i>Send Reset Link</button>
    </form>
    <?php else: ?>
    <form method="POST">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <label class="auth-label">New Password</label>
        <div class="pw-wrap mb-3">
            <input type="password" name="new_password" id="np" class="auth-input" placeholder="Min. 6 characters"
                   required style="padding-right:2.8rem;">
            <button type="button" class="pw-toggle" onclick="var field=document.getElementById('np');field.type=field.type==='password'?'text':'password';">
                <i class="bi bi-eye"></i>
            </button>
        </div>
        <label class="auth-label">Confirm Password</label>
        <input type="password" name="confirm_password" class="auth-input mb-3" placeholder="Repeat password" required>
        <p class="text-muted mb-3" style="font-size:.78rem;">This link expires 30 minutes after it is sent.</p>
        <button type="submit" class="btn-auth"><i class="bi bi-check-lg me-2"></i>Reset Password</button>
    </form>
    <?php endif; ?>

    <p class="text-center mt-3" style="font-size:.85rem;color:#64748b;">
        <a href="login.php" style="color:#2563eb;font-weight:600;">← Back to Login</a>
    </p>
</div>
</div>

<?php require_once 'inc/footer.php'; ?>
