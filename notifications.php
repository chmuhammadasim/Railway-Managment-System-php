<?php
// notifications.php - User Notifications
require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';

if (!User::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();
$user_id = (int)$_SESSION['user_id'];

// Mark all as read when the page is opened
$db->query("UPDATE notifications SET is_read=1 WHERE user_id={$user_id} AND is_read=0");

// Fetch notifications for this user (latest first)
$notifications = $db->select("SELECT * FROM notifications WHERE user_id = {$user_id} ORDER BY created_at DESC LIMIT 100");
if (!$notifications) $notifications = [];

$pageTitle = 'Notifications – Railway System';
require_once 'inc/header.php';
?>

<style>
.notif-wrap {
    min-height: calc(100vh - 120px);
    background: #f1f5f9;
    padding: 2rem 0 3rem;
}
.notif-card {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 2px 12px rgba(0,0,0,.07);
    overflow: hidden;
}
.notif-header {
    background: linear-gradient(135deg, #0f2040, #1e40af);
    color: #fff;
    padding: 1.25rem 1.75rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.notif-header h4 { margin: 0; font-weight: 800; font-size: 1.1rem; }
.notif-empty {
    text-align: center;
    padding: 4rem 2rem;
    color: #94a3b8;
}
.notif-empty i { font-size: 3.5rem; display: block; margin-bottom: 1rem; opacity: .35; }
.notif-item {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    padding: 1rem 1.75rem;
    border-bottom: 1px solid #f1f5f9;
    transition: background .15s;
}
.notif-item:last-child { border-bottom: none; }
.notif-item:hover { background: #f8fafc; }
.notif-ico {
    width: 40px; height: 40px; border-radius: 50%;
    background: #dbeafe; color: #2563eb;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; flex-shrink: 0; margin-top: .15rem;
}
.notif-body { flex: 1; min-width: 0; }
.notif-msg { font-size: .9rem; color: #1e293b; line-height: 1.5; }
.notif-time { font-size: .75rem; color: #94a3b8; margin-top: .2rem; }
.badge-unread {
    background: #dbeafe; color: #1d4ed8;
    font-size: .65rem; font-weight: 700;
    padding: .2em .6em; border-radius: 999px;
    vertical-align: middle;
}
</style>

<div class="notif-wrap">
    <div class="container" style="max-width:700px;">
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm mb-3">
            <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
        </a>

        <div class="notif-card">
            <div class="notif-header">
                <h4><i class="bi bi-bell-fill me-2"></i>Notifications</h4>
                <span style="font-size:.82rem;opacity:.7;"><?= count($notifications) ?> total</span>
            </div>

            <?php if (empty($notifications)): ?>
            <div class="notif-empty">
                <i class="bi bi-bell-slash"></i>
                <p class="fw-semibold mb-1">No notifications yet</p>
                <small>You'll see booking updates, cancellations, and alerts here.</small>
            </div>
            <?php else: ?>
            <?php foreach ($notifications as $notif):
                $ago = '';
                $ts = strtotime($notif['created_at']);
                $diff = time() - $ts;
                if ($diff < 60)            $ago = 'Just now';
                elseif ($diff < 3600)      $ago = floor($diff/60) . ' min ago';
                elseif ($diff < 86400)     $ago = floor($diff/3600) . ' hr ago';
                elseif ($diff < 604800)    $ago = floor($diff/86400) . ' days ago';
                else                       $ago = date('d M Y', $ts);

                $msg = htmlspecialchars($notif['message']);
                // Colour the icon based on message keywords
                $ico_bg = '#dbeafe'; $ico_color = '#2563eb'; $icon = 'bi-info-circle-fill';
                if (stripos($msg,'cancel') !== false) { $ico_bg = '#fee2e2'; $ico_color = '#dc2626'; $icon = 'bi-x-circle-fill'; }
                elseif (stripos($msg,'confirm') !== false || stripos($msg,'success') !== false) { $ico_bg = '#dcfce7'; $ico_color = '#16a34a'; $icon = 'bi-check-circle-fill'; }
                elseif (stripos($msg,'refund') !== false) { $ico_bg = '#fef3c7'; $ico_color = '#d97706'; $icon = 'bi-cash-coin'; }
                elseif (stripos($msg,'payment') !== false) { $ico_bg = '#ede9fe'; $ico_color = '#7c3aed'; $icon = 'bi-credit-card-fill'; }
            ?>
            <div class="notif-item">
                <div class="notif-ico" style="background:<?= $ico_bg ?>;color:<?= $ico_color ?>;">
                    <i class="bi <?= $icon ?>"></i>
                </div>
                <div class="notif-body">
                    <div class="notif-msg"><?= $msg ?></div>
                    <div class="notif-time">
                        <i class="bi bi-clock me-1"></i><?= $ago ?>
                        &nbsp;·&nbsp; <?= date('d M Y, H:i', $ts) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once 'inc/footer.php'; ?>
