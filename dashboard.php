<?php
// dashboard.php - User Dashboard

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Booking.php';
require_once 'src/classes/Payment.php';

if (!User::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();

$user_id = (int)$_SESSION['user_id'];
$role    = $_SESSION['role'];

if ($role === 'admin') {
    header('Location: admin-dashboard.php'); exit();
} elseif ($role === 'employee') {
    header('Location: employee-dashboard.php'); exit();
}

$user_obj = new User($db);
$user     = $user_obj->getUserById($user_id);

$booking_obj = new Booking($db);
$bookings    = $booking_obj->getUserBookings($user_id) ?: [];

$payment_obj = new Payment($db);
$payments    = $payment_obj->getUserPayments($user_id) ?: [];

// Stats
$total_bookings    = count($bookings);
$confirmed_n       = 0;
$upcoming_journeys = 0;
$cancelled_n       = 0;
$total_spent       = 0;

foreach ($bookings as $b) {
    if ($b['booking_status'] === 'confirmed') {
        $confirmed_n++;
        if (strtotime($b['journey_date']) > time()) $upcoming_journeys++;
    }
    if ($b['booking_status'] === 'cancelled') $cancelled_n++;
}
foreach ($payments as $p) {
    if (($p['payment_status'] ?? '') === 'completed') {
        $total_spent += (float)($p['amount'] ?? 0);
    }
}

// Next upcoming journey
$next_journey = null;
foreach ($bookings as $b) {
    if ($b['booking_status'] === 'confirmed' && strtotime($b['journey_date']) > time()) {
        if (!$next_journey || strtotime($b['journey_date']) < strtotime($next_journey['journey_date'])) {
            $next_journey = $b;
        }
    }
}

// Avatar initial
$avatar_initial = strtoupper(mb_substr($user['full_name'] ?? 'U', 0, 1));

$pageTitle = 'My Dashboard – Railway Management System';
require_once 'inc/header.php';
?>

<style>
/* ═══════════════════════════════════════════════
   Dashboard layout
═══════════════════════════════════════════════ */
.ud-wrap {
    background: #f1f5f9;
    min-height: calc(100vh - 60px);
    padding: 0 0 60px;
}

/* ─── Hero / greeting band ─────────────────── */
.ud-hero {
    background: linear-gradient(135deg, #0b1728 0%, #0f2040 45%, #1a3a6e 100%);
    color: #fff;
    padding: 2.5rem 0 4.5rem;
    position: relative;
    overflow: hidden;
}
.ud-hero::before {
    content: '';
    position: absolute; inset: 0;
    background-image: radial-gradient(rgba(255,255,255,.05) 1px, transparent 1px);
    background-size: 26px 26px;
    pointer-events: none;
}
.ud-hero-wave {
    position: absolute; bottom: -2px; left: 0; right: 0; line-height: 0;
}
.ud-hero-wave svg { display: block; width: 100%; height: 70px; }
.ud-hero-inner {
    max-width: 1160px; margin: 0 auto; padding: 0 1.5rem;
    position: relative; z-index: 2;
    display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap;
}
.ud-avatar {
    width: 62px; height: 62px; border-radius: 50%;
    background: linear-gradient(135deg, #10b981, #059669);
    color: #fff; font-size: 1.6rem; font-weight: 800;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 0 0 4px rgba(255,255,255,.2);
}
.ud-greeting h1 {
    font-size: clamp(1.4rem, 3vw, 2rem);
    font-weight: 800; margin: 0; letter-spacing: -.02em;
}
.ud-greeting p  {
    font-size: .88rem; color: rgba(255,255,255,.65); margin: .25rem 0 0;
}
.ud-hero-actions {
    margin-left: auto; display: flex; gap: .75rem; flex-wrap: wrap;
}
.ud-hero-actions a {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .62rem 1.25rem; border-radius: 10px;
    font-size: .85rem; font-weight: 600; text-decoration: none;
    transition: transform .15s, box-shadow .15s;
}
.ud-hero-actions .btn-book {
    background: #fbbf24; color: #1c1917;
    box-shadow: 0 4px 16px rgba(251,191,36,.4);
}
.ud-hero-actions .btn-book:hover { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(251,191,36,.5); color: #1c1917; }
.ud-hero-actions .btn-ghost {
    background: rgba(255,255,255,.1); color: #fff;
    border: 1.5px solid rgba(255,255,255,.25);
    backdrop-filter: blur(6px);
}
.ud-hero-actions .btn-ghost:hover { background: rgba(255,255,255,.18); color: #fff; }

/* ─── Main content area ─────────────────────── */
.ud-content {
    max-width: 1160px; margin: -56px auto 0;
    padding: 0 1.5rem;
    position: relative; z-index: 10;
}

/* ─── Stat cards ────────────────────────────── */
.ud-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem; margin-bottom: 1.5rem;
}
@media(max-width:900px){ .ud-stats { grid-template-columns: repeat(2,1fr); } }
@media(max-width:480px){ .ud-stats { grid-template-columns: 1fr 1fr; } }
.ud-stat {
    background: #fff; border-radius: 16px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
    padding: 1.25rem 1.35rem;
    transition: transform .2s, box-shadow .2s;
}
.ud-stat:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,.1); }
.ud-stat .s-ico {
    width: 44px; height: 44px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem; margin-bottom: .85rem;
}
.ud-stat .s-val { font-size: 2rem; font-weight: 900; color: #0f172a; line-height: 1; }
.ud-stat .s-lbl { font-size: .75rem; color: #6b7280; margin-top: .3rem; font-weight: 500; }

/* ─── Content grid ──────────────────────────── */
.ud-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 1.25rem;
}
@media(max-width:900px){ .ud-grid { grid-template-columns: 1fr; } }

/* ─── Surface card ──────────────────────────── */
.surface-card {
    background: #fff; border-radius: 16px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
    overflow: hidden;
}
.sc-head {
    padding: 1rem 1.35rem;
    border-bottom: 1px solid #f1f5f9;
    display: flex; align-items: center; justify-content: space-between; gap: .75rem;
}
.sc-head h2 {
    font-size: .95rem; font-weight: 700; color: #0f172a; margin: 0;
    display: flex; align-items: center; gap: .5rem;
}
.sc-body { padding: 1.25rem 1.35rem; }

/* ─── Booking table ─────────────────────────── */
.bk-table { width: 100%; border-collapse: collapse; }
.bk-table th {
    font-size: .72rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .06em; color: #6b7280;
    padding: .65rem .75rem; border-bottom: 1px solid #f1f5f9;
    text-align: left; white-space: nowrap;
}
.bk-table td {
    font-size: .84rem; color: #374151;
    padding: .8rem .75rem; border-bottom: 1px solid #f9fafb;
    vertical-align: middle;
}
.bk-table tbody tr:last-child td { border-bottom: none; }
.bk-table tbody tr:hover td { background: #f8fafc; }
.bk-ref { font-weight: 700; color: #2563eb; font-size: .82rem; }
.bk-route { font-weight: 600; color: #0f172a; font-size: .83rem; }
.bk-route .arrow { color: #10b981; margin: 0 .25rem; }
.bk-train { font-size: .72rem; color: #9ca3af; margin-top: .15rem; }

/* ─── Status badges ─────────────────────────── */
.sp { display: inline-block; padding: .22em .75em; border-radius: 999px; font-size: .73rem; font-weight: 600; white-space: nowrap; }
.sp-confirmed { background: #dcfce7; color: #15803d; }
.sp-pending   { background: #fef3c7; color: #92400e; }
.sp-cancelled { background: #fee2e2; color: #991b1b; }
.sp-completed { background: #dcfce7; color: #15803d; }
.sp-refunded  { background: #ede9fe; color: #6d28d9; }
.sp-failed    { background: #fee2e2; color: #991b1b; }

/* ─── Next journey card ─────────────────────── */
.nj-card {
    background: linear-gradient(135deg, #0f2040, #1e40af);
    border-radius: 14px; padding: 1.4rem;
    color: #fff; position: relative; overflow: hidden;
    margin-bottom: 1rem;
}
.nj-card::before {
    content: '';
    position: absolute; inset: 0;
    background-image: radial-gradient(rgba(255,255,255,.06) 1px, transparent 1px);
    background-size: 20px 20px;
}
.nj-card .nj-label {
    font-size: .68rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .1em; color: rgba(255,255,255,.55); margin-bottom: .6rem;
}
.nj-card .nj-route {
    font-size: 1.2rem; font-weight: 800; margin-bottom: .3rem;
    position: relative;
}
.nj-card .nj-arrow { color: #fbbf24; margin: 0 .35rem; }
.nj-card .nj-meta {
    font-size: .78rem; color: rgba(255,255,255,.65);
    position: relative; margin-bottom: .9rem;
}
.nj-card .nj-date {
    display: inline-flex; align-items: center; gap: .4rem;
    background: rgba(251,191,36,.2); border: 1px solid rgba(251,191,36,.35);
    border-radius: 999px; padding: .25rem .8rem;
    font-size: .78rem; font-weight: 700; color: #fde68a;
    position: relative;
}
.nj-card .nj-countdown {
    font-size: .72rem; color: rgba(255,255,255,.5);
    margin-top: .5rem; position: relative;
}
.btn-view-ticket {
    display: inline-flex; align-items: center; gap: .4rem;
    background: rgba(255,255,255,.12); color: #fff;
    border: 1.5px solid rgba(255,255,255,.25);
    padding: .5rem 1rem; border-radius: 8px;
    font-size: .8rem; font-weight: 600; text-decoration: none;
    transition: background .2s; position: relative;
    margin-top: .75rem;
}
.btn-view-ticket:hover { background: rgba(255,255,255,.2); color: #fff; }

/* ─── Quick actions ─────────────────────────── */
.qa-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: .6rem;
    margin-bottom: 1rem;
}
.qa-btn {
    display: flex; align-items: center; gap: .7rem;
    padding: .85rem 1rem; border-radius: 12px;
    background: #f8fafc; border: 1.5px solid #e5e7eb;
    color: #374151; text-decoration: none; font-size: .83rem; font-weight: 600;
    transition: background .15s, border-color .15s, color .15s;
}
.qa-btn:hover { background: #eff6ff; border-color: #bfdbfe; color: #1d4ed8; }
.qa-btn .qa-ico {
    width: 34px; height: 34px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; flex-shrink: 0;
}

/* ─── Recent payments ────────────────────────── */
.pay-row {
    display: flex; align-items: center; gap: .85rem;
    padding: .75rem 0; border-bottom: 1px solid #f1f5f9;
}
.pay-row:last-child { border-bottom: none; }
.pay-ico {
    width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: .95rem;
}
.pay-lbl  { font-size: .83rem; font-weight: 600; color: #0f172a; }
.pay-sub  { font-size: .72rem; color: #9ca3af; margin-top: .1rem; }
.pay-amt  { margin-left: auto; font-weight: 800; font-size: .95rem; }

/* ─── "See all" link ────────────────────────── */
.see-all {
    font-size: .8rem; font-weight: 600;
    color: #2563eb; text-decoration: none;
    display: inline-flex; align-items: center; gap: .3rem;
}
.see-all:hover { color: #1d4ed8; }

/* ─── Empty state ───────────────────────────── */
.ud-empty {
    text-align: center; padding: 2.5rem 1rem; color: #9ca3af;
}
.ud-empty i { font-size: 2.5rem; display: block; margin-bottom: .75rem; opacity: .35; }
.ud-empty p { font-size: .85rem; }
</style>

<!-- ═══════════════════════════════════
     HERO GREETING
════════════════════════════════════ -->
<div class="ud-wrap">
<div class="ud-hero">
    <div class="ud-hero-inner">
        <div class="ud-avatar"><?= htmlspecialchars($avatar_initial) ?></div>
        <div class="ud-greeting">
            <h1>Welcome back, <?= htmlspecialchars(explode(' ', $user['full_name'])[0]) ?>!</h1>
            <p>
                <?php
                $hour = (int)date('G');
                echo $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
                ?> &nbsp;·&nbsp; <?= date('D, d M Y') ?>
                <?php if ($upcoming_journeys > 0): ?>
                &nbsp;·&nbsp; <span style="color:#fbbf24;"><?= $upcoming_journeys ?> upcoming trip<?= $upcoming_journeys > 1 ? 's' : '' ?></span>
                <?php endif; ?>
            </p>
        </div>
        <div class="ud-hero-actions">
            <a href="book.php" class="btn-book">
                <i class="bi bi-search"></i> Book a Train
            </a>
            <a href="bookings.php" class="btn-ghost">
                <i class="bi bi-ticket-perforated"></i> My Bookings
            </a>
        </div>
    </div>
    <div class="ud-hero-wave">
        <svg viewBox="0 0 1440 70" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M0,35 C360,70 1080,0 1440,35 L1440,70 L0,70 Z" fill="#f1f5f9"/>
        </svg>
    </div>
</div>

<!-- ═══════════════════════════════════
     STATS ROW
════════════════════════════════════ -->
<div class="ud-content">
    <div class="ud-stats">
        <div class="ud-stat">
            <div class="s-ico" style="background:#dbeafe;">
                <i class="bi bi-ticket-perforated-fill" style="color:#2563eb;"></i>
            </div>
            <div class="s-val"><?= $total_bookings ?></div>
            <div class="s-lbl">Total Bookings</div>
        </div>
        <div class="ud-stat">
            <div class="s-ico" style="background:#dcfce7;">
                <i class="bi bi-train-front-fill" style="color:#16a34a;"></i>
            </div>
            <div class="s-val"><?= $upcoming_journeys ?></div>
            <div class="s-lbl">Upcoming Journeys</div>
        </div>
        <div class="ud-stat">
            <div class="s-ico" style="background:#fef3c7;">
                <i class="bi bi-check-circle-fill" style="color:#d97706;"></i>
            </div>
            <div class="s-val"><?= $confirmed_n ?></div>
            <div class="s-lbl">Confirmed Bookings</div>
        </div>
        <div class="ud-stat">
            <div class="s-ico" style="background:#ede9fe;">
                <i class="bi bi-wallet2" style="color:#7c3aed;"></i>
            </div>
            <div class="s-val">Rs <?= number_format($total_spent, 0) ?></div>
            <div class="s-lbl">Total Spent</div>
        </div>
    </div>

    <!-- ═══════════════════════════════════
         MAIN GRID
    ════════════════════════════════════ -->
    <div class="ud-grid">

        <!-- LEFT: Recent Bookings -->
        <div class="surface-card">
            <div class="sc-head">
                <h2><i class="bi bi-journal-text" style="color:#2563eb;"></i> Recent Bookings</h2>
                <?php if ($total_bookings > 5): ?>
                <a href="bookings.php" class="see-all">View all <?= $total_bookings ?> <i class="bi bi-arrow-right"></i></a>
                <?php endif; ?>
            </div>

            <?php if (empty($bookings)): ?>
            <div class="ud-empty">
                <i class="bi bi-ticket-perforated"></i>
                <p>No bookings yet.<br>
                <a href="book.php" style="color:#2563eb;font-weight:600;">Search trains and book your first trip</a></p>
            </div>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="bk-table">
                    <thead>
                        <tr>
                            <th>Ref</th>
                            <th>Route</th>
                            <th>Date</th>
                            <th>Seats</th>
                            <th>Fare</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_slice($bookings, 0, 6) as $b): ?>
                        <tr>
                            <td>
                                <div class="bk-ref"><?= htmlspecialchars($b['booking_reference']) ?></div>
                            </td>
                            <td>
                                <div class="bk-route">
                                    <?= htmlspecialchars($b['departure_city']) ?>
                                    <span class="arrow">→</span>
                                    <?= htmlspecialchars($b['arrival_city']) ?>
                                </div>
                                <div class="bk-train"><i class="bi bi-train-front me-1"></i><?= htmlspecialchars($b['train_name']) ?></div>
                            </td>
                            <td style="white-space:nowrap;font-size:.82rem;">
                                <?= date('d M Y', strtotime($b['journey_date'])) ?>
                            </td>
                            <td style="text-align:center;font-weight:700;color:#0f172a;">
                                <?= (int)($b['number_of_seats'] ?? 1) ?>
                            </td>
                            <td style="font-weight:700;color:#059669;white-space:nowrap;">
                                Rs <?= number_format($b['total_fare'], 0) ?>
                            </td>
                            <td>
                                <span class="sp sp-<?= htmlspecialchars($b['booking_status']) ?>">
                                    <?= ucfirst($b['booking_status']) ?>
                                </span>
                            </td>
                            <td>
                                <a href="booking_details.php?id=<?= (int)$b['booking_id'] ?>"
                                   style="display:inline-flex;align-items:center;gap:.3rem;font-size:.78rem;font-weight:600;color:#2563eb;text-decoration:none;white-space:nowrap;">
                                    <i class="bi bi-eye"></i> View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($total_bookings > 6): ?>
            <div style="padding:.75rem 1.35rem;border-top:1px solid #f1f5f9;text-align:center;">
                <a href="bookings.php" class="see-all">See all <?= $total_bookings ?> bookings <i class="bi bi-arrow-right"></i></a>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- RIGHT: Sidebar -->
        <div>

            <!-- Next Journey card (if any) -->
            <?php if ($next_journey): ?>
            <div class="nj-card">
                <div class="nj-label"><i class="bi bi-train-front-fill me-1"></i> Next Journey</div>
                <div class="nj-route">
                    <?= htmlspecialchars($next_journey['departure_city']) ?>
                    <span class="nj-arrow">→</span>
                    <?= htmlspecialchars($next_journey['arrival_city']) ?>
                </div>
                <div class="nj-meta">
                    <i class="bi bi-train-front me-1"></i><?= htmlspecialchars($next_journey['train_name']) ?>
                </div>
                <div class="nj-date">
                    <i class="bi bi-calendar3"></i>
                    <?= date('D, d M Y', strtotime($next_journey['journey_date'])) ?>
                </div>
                <?php
                $days_away = (int)ceil((strtotime($next_journey['journey_date']) - time()) / 86400);
                ?>
                <div class="nj-countdown">
                    <?php if ($days_away === 1): ?>
                    <i class="bi bi-exclamation-circle me-1"></i>Tomorrow!
                    <?php elseif ($days_away === 0): ?>
                    <i class="bi bi-exclamation-triangle me-1"></i>Today!
                    <?php else: ?>
                    <i class="bi bi-clock me-1"></i><?= $days_away ?> days away
                    <?php endif; ?>
                </div>
                <a href="booking_details.php?id=<?= (int)$next_journey['booking_id'] ?>" class="btn-view-ticket d-inline-flex">
                    <i class="bi bi-ticket-perforated"></i> View Ticket
                </a>
            </div>
            <?php else: ?>
            <div class="surface-card mb-3" style="border:2px dashed #e2e8f0;box-shadow:none;background:#fafafa;">
                <div class="ud-empty" style="padding:1.75rem 1rem;">
                    <i class="bi bi-train-front" style="opacity:.2;font-size:2rem;display:block;margin-bottom:.6rem;"></i>
                    <p style="font-size:.83rem;margin:0;color:#9ca3af;">No upcoming trips.<br>
                    <a href="book.php" style="color:#2563eb;font-weight:600;">Book one now →</a></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <div class="surface-card mb-3">
                <div class="sc-head">
                    <h2><i class="bi bi-lightning-charge-fill" style="color:#d97706;"></i> Quick Actions</h2>
                </div>
                <div class="sc-body" style="padding:.85rem 1rem;">
                    <div class="qa-grid">
                        <a href="book.php" class="qa-btn">
                            <div class="qa-ico" style="background:#dbeafe;color:#2563eb;"><i class="bi bi-search"></i></div>
                            Search Trains
                        </a>
                        <a href="bookings.php" class="qa-btn">
                            <div class="qa-ico" style="background:#dcfce7;color:#16a34a;"><i class="bi bi-journal-check"></i></div>
                            My Bookings
                        </a>
                        <a href="profile.php" class="qa-btn">
                            <div class="qa-ico" style="background:#ede9fe;color:#7c3aed;"><i class="bi bi-person-circle"></i></div>
                            Edit Profile
                        </a>
                        <a href="notifications.php" class="qa-btn">
                            <div class="qa-ico" style="background:#fef3c7;color:#d97706;"><i class="bi bi-bell"></i></div>
                            Notifications
                        </a>
                    </div>
                </div>
            </div>

            <!-- Recent Payments -->
            <?php if (!empty($payments)): ?>
            <div class="surface-card">
                <div class="sc-head">
                    <h2><i class="bi bi-credit-card-fill" style="color:#059669;"></i> Recent Payments</h2>
                    <a href="bookings.php" class="see-all">All <i class="bi bi-arrow-right"></i></a>
                </div>
                <div class="sc-body" style="padding:.5rem 1.35rem;">
                    <?php foreach (array_slice($payments, 0, 4) as $p): ?>
                    <div class="pay-row">
                        <div class="pay-ico" style="background:<?= ($p['payment_status'] ?? '') === 'completed' ? '#dcfce7' : '#fee2e2' ?>;color:<?= ($p['payment_status'] ?? '') === 'completed' ? '#15803d' : '#dc2626' ?>;">
                            <i class="bi bi-<?= ($p['payment_status'] ?? '') === 'completed' ? 'check-lg' : 'x-lg' ?>"></i>
                        </div>
                        <div>
                            <div class="pay-lbl"><?= htmlspecialchars($p['payment_method'] ?? 'Payment') ?></div>
                            <div class="pay-sub"><?= !empty($p['payment_date']) ? date('d M Y', strtotime($p['payment_date'])) : '—' ?></div>
                        </div>
                        <div class="pay-amt" style="color:<?= ($p['payment_status'] ?? '') === 'completed' ? '#059669' : '#dc2626' ?>;">
                            Rs <?= number_format((float)($p['amount'] ?? 0), 0) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /right sidebar -->
    </div><!-- /ud-grid -->
</div><!-- /ud-content -->
</div><!-- /ud-wrap -->

<?php require_once 'inc/footer.php'; ?>
