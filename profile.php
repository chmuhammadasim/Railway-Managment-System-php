<?php
// profile.php - User Profile Management

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';

if (!User::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();

$user_id  = (int)$_SESSION['user_id'];
$user_obj = new User($db);
$user     = $user_obj->getUserById($user_id);

if (!$user || !is_array($user)) {
    // Session exists but no matching DB record – force re-login
    session_destroy();
    header('Location: login.php?reason=session_expired');
    exit();
}

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($full_name) || empty($email)) {
        $error_message = 'Full name and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address.';
    } elseif (!empty($password) && $password !== $confirm_password) {
        $error_message = 'Passwords do not match.';
    } elseif (!empty($password) && strlen($password) < 6) {
        $error_message = 'Password must be at least 6 characters.';
    } else {
        $result = $user_obj->updateProfile($user_id, $full_name, $email, $phone, $address, $password);
        if ($result['success']) {
            $success_message = 'Profile updated successfully!';
            $user = $user_obj->getUserById($user_id);
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email'] = $user['email'];
        } else {
            $error_message = $result['message'];
        }
    }
}

$back_url = match($_SESSION['role'] ?? 'user') {
    'admin'    => 'admin-dashboard.php',
    default    => 'dashboard.php',
};

$pageTitle = 'My Profile – Railway System';
require_once 'inc/header.php';
?>

<style>
.profile-wrap {
    min-height: calc(100vh - 120px);
    background: linear-gradient(135deg, #f0f4ff 0%, #e8f4fd 100%);
    padding: 2.5rem 0;
}
.profile-avatar-card {
    background: linear-gradient(135deg, #1a2e4a 0%, #0f1e32 100%);
    border-radius: 20px;
    padding: 2.5rem 1.5rem;
    text-align: center;
    color: #fff;
    box-shadow: 0 4px 20px rgba(0,0,0,.15);
}
.profile-avatar {
    width: 90px; height: 90px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6, #6366f1);
    display: flex; align-items: center; justify-content: center;
    font-size: 2.2rem; font-weight: 800;
    color: #fff; margin: 0 auto 1rem;
    border: 4px solid rgba(255,255,255,.25);
    box-shadow: 0 4px 16px rgba(59,130,246,.4);
}
.profile-avatar-card .name  { font-size: 1.15rem; font-weight: 700; }
.profile-avatar-card .email { font-size: .82rem; opacity: .65; margin-top: .2rem; }
.role-pill {
    display: inline-block; margin-top: .75rem;
    padding: .3em .9em; border-radius: 999px; font-size: .75rem; font-weight: 700;
}
.role-admin    { background: #fee2e2; color: #991b1b; }
.role-employee { background: #fef3c7; color: #92400e; }
.role-user     { background: #dbeafe; color: #1d4ed8; }

.profile-meta { margin-top: 1.5rem; text-align: left; }
.profile-meta .meta-row {
    display: flex; align-items: center; gap: .65rem;
    padding: .55rem 0; border-bottom: 1px solid rgba(255,255,255,.1);
    font-size: .85rem; color: rgba(255,255,255,.8);
}
.profile-meta .meta-row:last-child { border-bottom: none; }
.profile-meta .meta-row i { width: 18px; text-align: center; color: #60a5fa; }
.profile-meta .meta-label { font-size: .7rem; text-transform: uppercase; letter-spacing: .06em; opacity: .5; display: block; }

.form-card {
    background: #fff;
    border-radius: 20px;
    padding: 2rem;
    box-shadow: 0 4px 20px rgba(0,0,0,.07);
}
.form-card .section-divider {
    font-size: .7rem; text-transform: uppercase; letter-spacing: .1em;
    color: #94a3b8; font-weight: 700; margin: 1.5rem 0 1rem;
    display: flex; align-items: center; gap: .75rem;
}
.form-card .section-divider::after {
    content: ''; flex: 1; height: 1px; background: #e2e8f0;
}
.form-card .form-label { font-size: .83rem; font-weight: 600; color: #374151; }
.form-card .form-control:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,.15);
}
.btn-save {
    background: linear-gradient(135deg, #2563eb, #1e40af);
    border: none; border-radius: 10px;
    padding: .65rem 2rem; font-weight: 700; font-size: .95rem;
    color: #fff; width: 100%;
    transition: opacity .2s, transform .15s;
}
.btn-save:hover { opacity: .9; transform: translateY(-1px); }

.joined-badge {
    margin-top: 1.25rem;
    background: rgba(255,255,255,.08);
    border-radius: 10px;
    padding: .65rem 1rem;
    font-size: .78rem;
    color: rgba(255,255,255,.6);
    text-align: center;
}
</style>

<div class="profile-wrap">
    <div class="container">
        <div class="row g-4 justify-content-center">

            <div class="col-lg-3 col-md-4">
                <div class="profile-avatar-card">
                    <div class="profile-avatar">
                        <?= strtoupper(substr($user['full_name'] ?? $user['username'] ?? '?', 0, 1)) ?>
                    </div>
                    <div class="name"><?= htmlspecialchars($user['full_name']) ?></div>
                    <div class="email"><?= htmlspecialchars($user['email']) ?></div>
                    <span class="role-pill role-<?= htmlspecialchars($user['role']) ?>">
                        <?= ucfirst(htmlspecialchars($user['role'])) ?>
                    </span>

                    <div class="profile-meta">
                        <?php if (!empty($user['phone'])): ?>
                        <div class="meta-row">
                            <i class="bi bi-telephone"></i>
                            <div>
                                <span class="meta-label">Phone</span>
                                <?= htmlspecialchars($user['phone']) ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($user['address'])): ?>
                        <div class="meta-row">
                            <i class="bi bi-geo-alt"></i>
                            <div>
                                <span class="meta-label">Address</span>
                                <?= htmlspecialchars($user['address']) ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="meta-row">
                            <i class="bi bi-person-badge"></i>
                            <div>
                                <span class="meta-label">Username</span>
                                @<?= htmlspecialchars($user['username']) ?>
                            </div>
                        </div>
                        <div class="meta-row">
                            <i class="bi bi-check-circle"></i>
                            <div>
                                <span class="meta-label">Status</span>
                                <?= (int)$user['is_active'] ? 'Active' : 'Inactive' ?>
                            </div>
                        </div>
                    </div>

                    <div class="joined-badge">
                        <i class="bi bi-calendar3 me-1"></i>
                        Joined <?= date('d M Y', strtotime($user['created_at'])) ?>
                    </div>

                    <div class="mt-3">
                        <a href="<?= $back_url ?>" class="btn btn-outline-light btn-sm w-100">
                            <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-lg-7 col-md-8">
                <div class="form-card">
                    <h4 class="fw-bold mb-0" style="color:#0f172a;">
                        <i class="bi bi-person-gear me-2 text-primary"></i>Edit Profile
                    </h4>
                    <p class="text-muted mt-1 mb-0" style="font-size:.875rem;">Update your personal details and security settings below.</p>

                    <?php if ($error_message): ?>
                    <div class="alert alert-danger border-0 rounded-3 py-2 mt-3">
                        <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($error_message) ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($success_message): ?>
                    <div class="alert alert-success border-0 rounded-3 py-2 mt-3">
                        <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
                    </div>
                    <?php endif; ?>

                    <form method="POST" class="mt-3">
                        <div class="section-divider">Personal Information</div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="full_name" class="form-control"
                                       value="<?= htmlspecialchars($user['full_name']) ?>" required
                                       placeholder="Enter your full name">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email Address <span class="text-danger">*</span></label>
                                <input type="email" name="email" class="form-control"
                                       value="<?= htmlspecialchars($user['email']) ?>" required
                                       placeholder="Enter your email">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone Number</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-telephone text-muted"></i></span>
                                    <input type="text" name="phone" class="form-control"
                                           value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                                           placeholder="+92 300 0000000">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Address</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-geo-alt text-muted"></i></span>
                                    <input type="text" name="address" class="form-control"
                                           value="<?= htmlspecialchars($user['address'] ?? '') ?>"
                                           placeholder="City, Country">
                                </div>
                            </div>
                        </div>

                        <div class="section-divider">Change Password</div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">
                                    New Password
                                    <span class="text-muted fw-normal" style="font-size:.78rem;">(leave blank to keep current)</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock text-muted"></i></span>
                                    <input type="password" name="password" id="pwdField" class="form-control"
                                           placeholder="Min. 6 characters">
                                    <button type="button" class="btn btn-outline-secondary border-start-0"
                                            onclick="togglePwd('pwdField','eyeIcon1')" tabindex="-1">
                                        <i class="bi bi-eye" id="eyeIcon1"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Confirm New Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock-fill text-muted"></i></span>
                                    <input type="password" name="confirm_password" id="pwdField2" class="form-control"
                                           placeholder="Repeat new password">
                                    <button type="button" class="btn btn-outline-secondary border-start-0"
                                            onclick="togglePwd('pwdField2','eyeIcon2')" tabindex="-1">
                                        <i class="bi bi-eye" id="eyeIcon2"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-12" id="pwdMatchMsg" style="display:none;"></div>
                        </div>

                        <div class="mt-4 d-flex gap-3 align-items-center">
                            <button type="submit" class="btn-save btn">
                                <i class="bi bi-floppy me-2"></i>Save Changes
                            </button>
                            <a href="<?= $back_url ?>" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
function togglePwd(fieldId, iconId) {
    var field = document.getElementById(fieldId);
    var icon  = document.getElementById(iconId);
    if (field.type === 'password') {
        field.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        field.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

(function () {
    var p1 = document.getElementById('pwdField');
    var p2 = document.getElementById('pwdField2');
    var msg = document.getElementById('pwdMatchMsg');

    function check() {
        if (!p1.value && !p2.value) { msg.style.display = 'none'; return; }
        if (p1.value !== p2.value) {
            msg.innerHTML = '<small class="text-danger"><i class="bi bi-x-circle me-1"></i>Passwords do not match.</small>';
            msg.style.display = 'block';
        } else {
            msg.innerHTML = '<small class="text-success"><i class="bi bi-check-circle me-1"></i>Passwords match.</small>';
            msg.style.display = 'block';
        }
    }

    p1.addEventListener('input', check);
    p2.addEventListener('input', check);
}());
</script>

<?php require_once 'inc/footer.php'; ?>
