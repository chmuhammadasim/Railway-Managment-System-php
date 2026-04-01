<?php
// login.php - Login Page

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';

$error_message   = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = new Database();
    $db->connect();

    $user     = new User($db);
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $result = $user->login($username, $password);

    if ($result['success']) {
        header('Location: dashboard.php');
        exit();
    } else {
        $error_message = $result['message'];
    }
}

$extraScripts = ['public/js/signup.js'];
$pageTitle     = 'Login – Railway Management System';
require_once 'inc/header.php';
?>

<style>
.auth-page {
    min-height: calc(100vh - 120px);
    background: linear-gradient(135deg, #0f1e32 0%, #1a3a5c 50%, #1e40af 100%);
    display: flex; align-items: center; justify-content: center;
    padding: 3rem 1rem;
    position: relative; overflow: hidden;
}
/* decorative blobs */
.auth-page::before, .auth-page::after {
    content: ''; position: absolute; border-radius: 50%;
    background: rgba(255,255,255,.05); pointer-events: none;
}
.auth-page::before { width: 600px; height: 600px; top: -200px; right: -200px; }
.auth-page::after  { width: 400px; height: 400px; bottom: -150px; left: -150px; }

.auth-card {
    background: #fff;
    border-radius: 22px;
    box-shadow: 0 24px 80px rgba(0,0,0,.25);
    width: 100%; max-width: 440px;
    padding: 2.75rem 2.5rem 2.25rem;
    position: relative; z-index: 1;
    animation: slideUp .45s ease both;
}
@keyframes slideUp { from{opacity:0;transform:translateY(28px)} to{opacity:1;transform:translateY(0)} }

.auth-logo {
    text-align: center; margin-bottom: 1.75rem;
}
.auth-logo .logo-icon {
    width: 60px; height: 60px; border-radius: 16px;
    background: linear-gradient(135deg, #2563eb, #1e40af);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 1.8rem; margin-bottom: .75rem;
    box-shadow: 0 8px 24px rgba(37,99,235,.35);
}
.auth-logo h1 { font-size: 1.5rem; font-weight: 800; color: #0f172a; margin: 0; }
.auth-logo p  { color: #64748b; font-size: .875rem; margin: .25rem 0 0; }

.auth-label {
    font-size: .8rem; font-weight: 600; color: #374151; margin-bottom: .35rem; display: block;
}
.auth-input {
    display: block; width: 100%;
    padding: .725rem 1rem;
    border: 1.5px solid #d1d5db;
    border-radius: 10px;
    font-size: .925rem; color: #0f172a;
    background: #f9fafb;
    transition: border-color .2s, box-shadow .2s, background .2s;
}
.auth-input:focus {
    outline: none; border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,.15);
    background: #fff;
}
.auth-input.is-invalid { border-color: #ef4444; }

.pw-wrap { position: relative; }
.pw-toggle {
    position: absolute; right: .75rem; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer;
    color: #94a3b8; font-size: 1.05rem; padding: .25rem;
    transition: color .2s;
}
.pw-toggle:hover { color: #3b82f6; }

.btn-auth {
    display: block; width: 100%;
    padding: .8rem 1.5rem;
    background: linear-gradient(135deg, #2563eb, #1e40af);
    color: #fff; border: none; border-radius: 12px;
    font-size: 1rem; font-weight: 700; cursor: pointer;
    box-shadow: 0 4px 16px rgba(37,99,235,.35);
    transition: opacity .2s, transform .15s;
}
.btn-auth:hover { opacity: .9; transform: translateY(-1px); }
.btn-auth:active { transform: translateY(0); }

.auth-divider {
    text-align: center; position: relative; margin: 1.5rem 0;
    color: #94a3b8; font-size: .8rem;
}
.auth-divider::before, .auth-divider::after {
    content: ''; position: absolute; top: 50%;
    width: 42%; height: 1px; background: #e2e8f0;
}
.auth-divider::before { left: 0; }
.auth-divider::after  { right: 0; }

.auth-link-row { text-align: center; font-size: .875rem; color: #64748b; }
.auth-link-row a { color: #2563eb; font-weight: 600; text-decoration: none; }
.auth-link-row a:hover { text-decoration: underline; }

.alert-err {
    background: #fef2f2; border: 1px solid #fecaca;
    color: #b91c1c; border-radius: 10px;
    padding: .75rem 1rem; font-size: .875rem;
    display: flex; align-items: center; gap: .6rem;
    margin-bottom: 1.25rem;
}
</style>

<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <div class="logo-icon">🚂</div>
            <h1>Welcome Back</h1>
            <p>Sign in to manage your train bookings</p>
        </div>

        <?php if ($error_message): ?>
        <div class="alert-err">
            <i class="bi bi-exclamation-circle-fill"></i>
            <?= htmlspecialchars($error_message) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="login.php" id="loginForm" novalidate>
            <div class="mb-3">
                <label class="auth-label" for="username">Username or Email</label>
                <input type="text" id="username" name="username" class="auth-input"
                       placeholder="Enter username or email" required autocomplete="username">
            </div>

            <div class="mb-4">
                <label class="auth-label" for="password">Password</label>
                <div class="pw-wrap">
                    <input type="password" id="password" name="password" class="auth-input"
                           placeholder="Enter your password" required autocomplete="current-password"
                           style="padding-right:2.8rem;">
                    <button type="button" class="pw-toggle" onclick="togglePwd('password','eyeBtn')"
                            id="eyeBtn" title="Show/Hide password">
                        <i class="bi bi-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-auth">
                <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
            </button>
        </form>

        <div class="auth-divider">or</div>

        <div class="auth-link-row">
            Don't have an account? <a href="signup.php">Create one free</a>
        </div>
    </div>
</div>

<script>
function togglePwd(fieldId, btnId) {
    var f = document.getElementById(fieldId);
    var i = document.getElementById('eyeIcon');
    if (f.type === 'password') { f.type = 'text'; i.className = 'bi bi-eye-slash'; }
    else { f.type = 'password'; i.className = 'bi bi-eye'; }
}
</script>

<?php require_once 'inc/footer.php'; ?>

