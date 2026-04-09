<?php
// signup.php – Registration

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';

$error_message = '';
$success_message = '';

$db = new Database();
$db->connect();
$user = new User($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if (empty($username) || empty($email) || empty($password) || empty($full_name)) {
        $error_message = 'Please fill all required fields!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address.';
    } elseif ($password !== $confirm_password) {
        $error_message = 'Passwords do not match!';
    } elseif (strlen($password) < 6) {
        $error_message = 'Password must be at least 6 characters!';
    } else {
        $result = $user->register($username, $email, $password, $full_name, $phone, $address);
        if ($result['success']) {
            $success_message = 'Account created successfully! Redirecting to login...';
            header('Refresh: 2; url=login.php');
        } else {
            $error_message = $result['message'];
        }
    }
}

$extraScripts = ['public/js/signup.js'];
$pageTitle = 'Sign Up – Railway Management System';
require_once 'inc/header.php';
?>

<style>
.auth-page {
    min-height: calc(100vh - 120px);
    background: linear-gradient(135deg, #0f1e32 0%, #1a3a5c 50%, #1e40af 100%);
    display: flex; align-items: flex-start; justify-content: center;
    padding: 3rem 1rem 4rem;
    position: relative; overflow: hidden;
}
.auth-page::before, .auth-page::after {
    content: ''; position: absolute; border-radius: 50%;
    background: rgba(255,255,255,.05); pointer-events: none;
}
.auth-page::before { width: 700px; height: 700px; top: -250px; right: -250px; }
.auth-page::after  { width: 500px; height: 500px; bottom: -200px; left: -200px; }

.su-card {
    background: #fff;
    border-radius: 22px;
    box-shadow: 0 24px 80px rgba(0,0,0,.25);
    width: 100%; max-width: 640px;
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
.auth-logo h1 { font-size: 1.55rem; font-weight: 800; color: #0f172a; margin: 0; }
.auth-logo p  { color: #64748b; font-size: .875rem; margin: .25rem 0 0; }

.su-section-label {
    font-size: .7rem; text-transform: uppercase; letter-spacing: .1em;
    color: #94a3b8; font-weight: 700; margin: 1.5rem 0 .85rem;
    display: flex; align-items: center; gap: .65rem;
}
.su-section-label::after {
    content: ''; flex: 1; height: 1px; background: #e2e8f0;
}

.auth-label {
    font-size: .8rem; font-weight: 600; color: #374151; margin-bottom: .35rem; display: block;
}
.auth-input {
    display: block; width: 100%;
    padding: .725rem 1rem;
    border: 1.5px solid #d1d5db; border-radius: 10px;
    font-size: .925rem; color: #0f172a; background: #f9fafb;
    transition: border-color .2s, box-shadow .2s, background .2s;
}
.auth-input:focus {
    outline: none; border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,.15);
    background: #fff;
}
.pw-wrap { position: relative; }
.pw-toggle {
    position: absolute; right: .75rem; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer;
    color: #94a3b8; font-size: 1.05rem; padding: .25rem;
    transition: color .2s;
}
.pw-toggle:hover { color: #3b82f6; }

/* strength meter */
.strength-bar-wrap { height: 5px; background: #e2e8f0; border-radius: 999px; margin-top: .45rem; overflow: hidden; }
.strength-bar      { height: 100%; width: 0; border-radius: 999px; transition: width .3s, background .3s; }
.strength-hint     { font-size: .72rem; color: #94a3b8; margin-top: .25rem; }

/* match indicator */
.match-hint { font-size: .72rem; margin-top: .3rem; }
.match-hint.ok  { color: #16a34a; }
.match-hint.err { color: #dc2626; }

.btn-auth {
    display: block; width: 100%;
    padding: .85rem 1.5rem;
    background: linear-gradient(135deg, #2563eb, #1e40af);
    color: #fff; border: none; border-radius: 12px;
    font-size: 1rem; font-weight: 700; cursor: pointer;
    box-shadow: 0 4px 16px rgba(37,99,235,.35);
    transition: opacity .2s, transform .15s;
    margin-top: .5rem;
}
.btn-auth:hover { opacity: .9; transform: translateY(-1px); }

.auth-divider {
    text-align: center; position: relative; margin: 1.5rem 0;
    color: #94a3b8; font-size: .8rem;
}
.auth-divider::before, .auth-divider::after {
    content: ''; position: absolute; top: 50%;
    width: 44%; height: 1px; background: #e2e8f0;
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
.alert-ok {
    background: #f0fdf4; border: 1px solid #bbf7d0;
    color: #15803d; border-radius: 10px;
    padding: .75rem 1rem; font-size: .875rem;
    display: flex; align-items: center; gap: .6rem;
    margin-bottom: 1.25rem;
}

@media(max-width:560px){ .su-card { padding: 2rem 1.25rem 1.75rem; } }
</style>

<div class="auth-page">
    <div class="su-card">
        <div class="auth-logo">
            <div class="logo-icon">🚂</div>
            <h1>Create Your Account</h1>
            <p>Join thousands of travellers booking smarter every day</p>
        </div>

        <?php if ($error_message): ?>
        <div class="alert-err">
            <i class="bi bi-exclamation-circle-fill"></i>
            <?= htmlspecialchars($error_message) ?>
        </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
        <div class="alert-ok">
            <i class="bi bi-check-circle-fill"></i>
            <?= htmlspecialchars($success_message) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="signup.php" id="signupForm" novalidate>

            <div class="su-section-label">Personal details</div>

            <div class="row g-3">
                <div class="col-sm-6">
                    <label class="auth-label" for="full_name">Full Name <span class="text-danger">*</span></label>
                    <input type="text" id="full_name" name="full_name" class="auth-input"
                           placeholder="e.g. Ali Hassan" required autocomplete="name">
                </div>
                <div class="col-sm-6">
                    <label class="auth-label" for="username">Username <span class="text-danger">*</span></label>
                    <input type="text" id="username" name="username" class="auth-input"
                           placeholder="choose-a-username" required autocomplete="username">
                </div>
                <div class="col-12">
                    <label class="auth-label" for="email">Email Address <span class="text-danger">*</span></label>
                    <input type="email" id="email" name="email" class="auth-input"
                           placeholder="you@example.com" required autocomplete="email">
                </div>
                <div class="col-sm-6">
                    <label class="auth-label" for="phone">Phone</label>
                    <input type="tel" id="phone" name="phone" class="auth-input"
                           placeholder="+92 300 0000000" autocomplete="tel">
                </div>
                <div class="col-sm-6">
                    <label class="auth-label" for="address">City / Address</label>
                    <input type="text" id="address" name="address" class="auth-input"
                           placeholder="Lahore, Pakistan" autocomplete="street-address">
                </div>
            </div>

            <div class="su-section-label">Security</div>

            <div class="row g-3">
                <div class="col-sm-6">
                    <label class="auth-label" for="password">Password <span class="text-danger">*</span></label>
                    <div class="pw-wrap">
                        <input type="password" id="password" name="password" class="auth-input"
                               placeholder="Min. 6 characters" required autocomplete="new-password"
                               style="padding-right:2.8rem;">
                        <button type="button" class="pw-toggle" onclick="togglePwd('password','ei1')">
                            <i class="bi bi-eye" id="ei1"></i>
                        </button>
                    </div>
                    <div class="strength-bar-wrap"><div class="strength-bar" id="strengthBar"></div></div>
                    <div class="strength-hint" id="strengthHint"></div>
                </div>
                <div class="col-sm-6">
                    <label class="auth-label" for="confirm_password">Confirm Password <span class="text-danger">*</span></label>
                    <div class="pw-wrap">
                        <input type="password" id="confirm_password" name="confirm_password"
                               class="auth-input" placeholder="Repeat password" required
                               autocomplete="new-password" style="padding-right:2.8rem;">
                        <button type="button" class="pw-toggle" onclick="togglePwd('confirm_password','ei2')">
                            <i class="bi bi-eye" id="ei2"></i>
                        </button>
                    </div>
                    <div class="match-hint" id="matchHint"></div>
                </div>
            </div>

            <button type="submit" class="btn-auth mt-3">
                <i class="bi bi-person-plus-fill me-2"></i>Create Account
            </button>
        </form>

        <div class="auth-divider">or</div>
        <div class="auth-link-row">
            Already have an account? <a href="login.php">Sign in here</a>
        </div>

    </div>
</div>

<script>
function togglePwd(fieldId, iconId) {
    var f = document.getElementById(fieldId);
    var i = document.getElementById(iconId);
    if (f.type === 'password') { f.type = 'text'; i.className = 'bi bi-eye-slash'; }
    else { f.type = 'password'; i.className = 'bi bi-eye'; }
}

(function () {
    var pwd  = document.getElementById('password');
    var cpwd = document.getElementById('confirm_password');
    var bar  = document.getElementById('strengthBar');
    var hint = document.getElementById('strengthHint');
    var mh   = document.getElementById('matchHint');

    function strength(p) {
        var s = 0;
        if (p.length >= 6) s++;
        if (p.length >= 10) s++;
        if (/[A-Z]/.test(p)) s++;
        if (/[0-9]/.test(p)) s++;
        if (/[^A-Za-z0-9]/.test(p)) s++;
        return s;
    }

    pwd.addEventListener('input', function () {
        var s = strength(this.value);
        var pct  = [0, 20, 40, 60, 80, 100][s];
        var clr  = ['#e2e8f0','#ef4444','#f97316','#eab308','#22c55e','#16a34a'][s];
        var lbl  = ['','Weak','Fair','Good','Strong','Very Strong'][s];
        bar.style.width = pct + '%';
        bar.style.background = clr;
        hint.textContent = this.value ? lbl : '';
        hint.style.color = clr;
        checkMatch();
    });

    cpwd.addEventListener('input', checkMatch);

    function checkMatch() {
        if (!cpwd.value) { mh.textContent = ''; return; }
        if (pwd.value === cpwd.value) {
            mh.textContent = '✓ Passwords match';
            mh.className = 'match-hint ok';
        } else {
            mh.textContent = '✗ Passwords do not match';
            mh.className = 'match-hint err';
        }
    }
}());
</script>

<?php require_once 'inc/footer.php'; ?>



