<?php
// bookings.php – My Bookings

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Booking.php';

function booking_departure_timestamp(array $booking) {
    $journey_date = $booking['journey_date'] ?? date('Y-m-d');
    $departure_time = $booking['departure_time'] ?? '00:00:00';

    return strtotime($journey_date . ' ' . $departure_time);
}

if (!User::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();

$user_id      = $_SESSION['user_id'];
$booking_obj  = new Booking($db);
$all_bookings = $booking_obj->getUserBookings($user_id) ?: [];

// Stats
$total       = count($all_bookings);
$upcoming    = 0;
$total_spent = 0;
$cancelled   = 0;
foreach ($all_bookings as $b) {
    $jts = booking_departure_timestamp($b);
    if ($b['booking_status'] === 'confirmed' && $jts > time()) $upcoming++;
    if ($b['booking_status'] === 'cancelled') $cancelled++;
    if ($b['payment_status'] === 'completed') $total_spent += (float)$b['total_fare'];
}

// Active filter & search
$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['q'] ?? '');

$bookings = array_filter($all_bookings, function($b) use ($filter, $search) {
    $departure_ts = booking_departure_timestamp($b);

    if ($filter === 'upcoming') {
        if ($b['booking_status'] === 'cancelled') return false;
        if ($departure_ts <= time()) return false;
    } elseif ($filter === 'past') {
        if ($departure_ts > time()) return false;
    } elseif ($filter !== 'all') {
        if (strtolower($b['booking_status']) !== $filter) return false;
    }
    if ($search !== '') {
        $haystack = strtolower(
            ($b['booking_reference'] ?? '') . ' ' .
            ($b['departure_city']   ?? '') . ' ' .
            ($b['arrival_city']     ?? '') . ' ' .
            ($b['train_name']       ?? '')
        );
        if (strpos($haystack, strtolower($search)) === false) return false;
    }
    return true;
});
$pageTitle = 'My Bookings – Railway System';
require_once 'inc/header.php';
?>
<style>
    .hero-band { background: linear-gradient(135deg, #0b1728 0%, #0f2040 55%, #1a3a6e 100%); padding: 3rem 0 5rem; }
    .hero-band h1 { color: #fff; font-size: 2rem; font-weight: 800; }
    .hero-meta { color: rgba(255,255,255,.6); font-size: .88rem; }
    .stat-chip { background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.18); border-radius: 12px; padding: .6rem 1.2rem; color: #fff; text-align: center; }
    .stat-chip .num { font-size: 1.5rem; font-weight: 800; }
    .stat-chip .lbl { font-size: .72rem; text-transform: uppercase; letter-spacing: .5px; opacity: .7; }
    .wave-div { line-height: 0; background: linear-gradient(135deg, #0b1728 0%, #0f2040 55%, #1a3a6e 100%); }
    .wave-div svg { display: block; }
    .content-area { background: #eef2f7; padding-bottom: 4rem; }
    .filter-tabs { display: flex; gap: .5rem; flex-wrap: wrap; }
    .filter-tab { padding: .4rem 1rem; border-radius: 20px; font-size: .82rem; font-weight: 600; border: 1.5px solid #d1d5db; background: #fff; color: #64748b; cursor: pointer; text-decoration: none; transition: all .15s; }
    .filter-tab:hover { border-color: #1a3a6e; color: #1a3a6e; }
    .filter-tab.active { background: #1a3a6e; border-color: #1a3a6e; color: #fff; }
    .search-box { position: relative; }
    .search-box .bi-search { position: absolute; left: .9rem; top: 50%; transform: translateY(-50%); color: #9ca3af; }
    .search-box input { padding-left: 2.4rem; border-radius: 10px; border: 1.5px solid #e2e8f0; background: #fff; }
    .search-box input:focus { border-color: #1a3a6e; box-shadow: 0 0 0 3px rgba(26,58,110,.12); outline: none; }
    .bk-card { background: #fff; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,.07); overflow: hidden; margin-bottom: 1rem; transition: box-shadow .2s, transform .2s; }
    .bk-card:hover { box-shadow: 0 8px 32px rgba(0,0,0,.12); transform: translateY(-2px); }
    .bk-card-top { display: flex; justify-content: space-between; align-items: flex-start; padding: 1.1rem 1.4rem .8rem; border-bottom: 1px solid #f1f5f9; flex-wrap: wrap; gap: .5rem; }
    .bk-route { font-size: 1.15rem; font-weight: 800; color: #0b1728; }
    .bk-route .arrow { color: #10b981; margin: 0 .4rem; font-size: .9rem; }
    .bk-train-pill { display: inline-flex; align-items: center; gap: .3rem; background: #f0f4ff; color: #1a3a6e; border-radius: 20px; padding: .2em .8em; font-size: .76rem; font-weight: 600; margin-top: .3rem; }
    .bk-ref-pill { display: inline-flex; align-items: center; gap: .3rem; background: #f8fafc; color: #64748b; border-radius: 20px; padding: .2em .8em; font-size: .74rem; margin-top: .3rem; margin-left: .3rem; font-family: monospace; letter-spacing: .5px; }
    .s-pill { display: inline-flex; align-items: center; gap: .3rem; padding: .3em .9em; border-radius: 20px; font-weight: 700; font-size: .78rem; }
    .s-confirmed, .s-completed { background: #d1fae5; color: #065f46; }
    .s-pending   { background: #fef3c7; color: #78350f; }
    .s-cancelled { background: #fee2e2; color: #7f1d1d; }
    .s-refunded  { background: #ede9fe; color: #4c1d95; }
    .s-failed    { background: #fee2e2; color: #7f1d1d; }
    .bk-card-body { padding: .9rem 1.4rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: .6rem; }
    .bk-info-cell .lbl { font-size: .68rem; text-transform: uppercase; letter-spacing: .4px; color: #9ca3af; display: block; }
    .bk-info-cell .val { font-size: .88rem; font-weight: 600; color: #1e293b; }
    .countdown-badge { display: inline-flex; align-items: center; gap: .35rem; background: #fff7ed; color: #c2410c; border: 1.5px solid #fed7aa; border-radius: 20px; padding: .25em .8em; font-size: .75rem; font-weight: 700; }
    .bk-card-footer { padding: .8rem 1.4rem; background: #f8fafc; border-top: 1px solid #f1f5f9; display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; }
    .bk-card.s-confirmed { border-left: 4px solid #10b981; }
    .bk-card.s-pending   { border-left: 4px solid #f59e0b; }
    .bk-card.s-cancelled { border-left: 4px solid #ef4444; opacity: .88; }
    .bk-card.s-completed { border-left: 4px solid #3b82f6; }
    .bk-card.s-refunded  { border-left: 4px solid #8b5cf6; }
    .empty-state { text-align: center; padding: 4rem 2rem; background: #fff; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,.06); }
    .empty-icon { font-size: 4rem; color: #d1d5db; margin-bottom: 1rem; }
    @media (max-width: 576px) { .hero-band h1 { font-size: 1.5rem; } .bk-card-body { grid-template-columns: 1fr 1fr; } }
</style>


<div class="hero-band">
    <div class="container">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
            <div>
                <h1><i class="bi bi-ticket-perforated me-2"></i>My Bookings</h1>
                <p class="hero-meta mb-0">All your train journeys in one place</p>
            </div>
            <a href="index.php" class="btn btn-warning fw-bold align-self-start">
                <i class="bi bi-plus-circle me-2"></i>Book New Train
            </a>
        </div>
        <div class="row g-3">
            <div class="col-6 col-sm-3">
                <div class="stat-chip"><div class="num"><?= $total ?></div><div class="lbl">Total Bookings</div></div>
            </div>
            <div class="col-6 col-sm-3">
                <div class="stat-chip"><div class="num" style="color:#6ee7b7;"><?= $upcoming ?></div><div class="lbl">Upcoming</div></div>
            </div>
            <div class="col-6 col-sm-3">
                <div class="stat-chip"><div class="num" style="color:#fbbf24;">Rs <?= number_format($total_spent, 0) ?></div><div class="lbl">Total Spent</div></div>
            </div>
            <div class="col-6 col-sm-3">
                <div class="stat-chip"><div class="num" style="color:#fca5a5;"><?= $cancelled ?></div><div class="lbl">Cancelled</div></div>
            </div>
        </div>
    </div>
</div>

<div class="wave-div">
    <svg viewBox="0 0 1440 60" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none" style="height:60px;width:100%;">
        <path d="M0,0 C360,60 1080,0 1440,60 L1440,0 Z" fill="#eef2f7"/>
    </svg>
</div>

<div class="content-area">
<div class="container py-4" style="max-width:900px;">

    <?php
    // Flash banner from booking_cancel.php PRG redirect
    if (!empty($_SESSION['cancel_flash'])):
        $cf = $_SESSION['cancel_flash'];
        unset($_SESSION['cancel_flash']);
    ?>
    <div class="alert alert-success d-flex align-items-start gap-3 mb-4 shadow-sm" role="alert" style="border-radius:12px;">
        <i class="bi bi-check-circle-fill fs-4 flex-shrink-0 mt-1"></i>
        <div>
            <div class="fw-bold mb-1">Booking Cancelled Successfully</div>
            <div style="font-size:.88rem;">
                Ticket <strong><?= htmlspecialchars($cf['booking_reference']) ?></strong> has been cancelled.
                <?php if (!$cf['is_unpaid'] && (float)$cf['refund_amount'] > 0): ?>
                    A refund of <strong>Rs <?= number_format((float)$cf['refund_amount'], 2) ?></strong>
                    (<?= htmlspecialchars($cf['tier_label']) ?>) will be credited within 5–7 business days.
                <?php elseif ($cf['is_unpaid']): ?>
                    This was an unpaid reservation — the seats have been released with no charge.
                <?php else: ?>
                    No refund applies (<?= htmlspecialchars($cf['tier_label']) ?>).
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div class="filter-tabs">
            <?php foreach (['all'=>['All','bi-list-ul'],'upcoming'=>['Upcoming','bi-calendar-check'],'confirmed'=>['Confirmed','bi-check-circle'],'pending'=>['Pending','bi-clock'],'past'=>['Past','bi-calendar-x'],'cancelled'=>['Cancelled','bi-x-circle']] as $key=>[$label,$icon]):
                $active = ($filter===$key)?' active':'';
            ?>
            <a href="?filter=<?= $key ?><?= $search?'&q='.urlencode($search):'' ?>" class="filter-tab<?= $active ?>">
                <i class="bi <?= $icon ?> me-1"></i><?= $label ?>
            </a>
            <?php endforeach; ?>
        </div>
        <form method="GET" action="" class="search-box" style="min-width:220px;">
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
            <i class="bi bi-search"></i>
            <input type="search" name="q" class="form-control form-control-sm"
                   placeholder="Ref, city, train…" value="<?= htmlspecialchars($search) ?>">
        </form>
    </div>

    <?php if (count($bookings) > 0): ?>
    <?php foreach ($bookings as $booking):
        $status  = strtolower($booking['booking_status']);
        $jts     = booking_departure_timestamp($booking);
        $hours   = ($jts - time()) / 3600;
        $can_update = $hours >= 4  && $status !== 'cancelled';
        $can_cancel = $hours > 0   && $status !== 'cancelled';
        $is_future  = $jts > time();
    ?>
    <div class="bk-card s-<?= $status ?>">
        <div class="bk-card-top">
            <div>
                <div class="bk-route">
                    <?= htmlspecialchars($booking['departure_city'] ?? 'N/A') ?>
                    <span class="arrow"><i class="bi bi-arrow-right"></i></span>
                    <?= htmlspecialchars($booking['arrival_city'] ?? 'N/A') ?>
                </div>
                <div>
                    <span class="bk-train-pill"><i class="bi bi-train-front-fill"></i><?= htmlspecialchars($booking['train_name'] ?? '') ?></span>
                    <span class="bk-ref-pill">#<?= htmlspecialchars($booking['booking_reference']) ?></span>
                </div>
            </div>
            <div class="text-end d-flex flex-column gap-1 align-items-end">
                <span class="s-pill s-<?= $status ?>"><i class="bi bi-circle-fill" style="font-size:.5rem;"></i><?= ucfirst($booking['booking_status']) ?></span>
                <span class="s-pill s-<?= strtolower($booking['payment_status']) ?>" style="font-size:.72rem;padding:.2em .75em;"><?= ucfirst($booking['payment_status']) ?></span>
                <?php if ($is_future && $status !== 'cancelled' && $hours < 72 && $hours > 0): ?>
                <span class="countdown-badge"><i class="bi bi-alarm"></i>
                    <?php if ($hours < 1): ?>Departing soon!
                    <?php elseif ($hours < 24): ?><?= round($hours) ?> hrs left
                    <?php else: ?><?= round($hours/24,1) ?> days left<?php endif; ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="bk-card-body">
            <div class="bk-info-cell"><span class="lbl"><i class="bi bi-calendar3 me-1"></i>Journey Date</span><span class="val"><?= date('D, d M Y', $jts) ?></span></div>
            <div class="bk-info-cell"><span class="lbl"><i class="bi bi-clock me-1"></i>Departure</span><span class="val"><?= date('H:i', strtotime($booking['departure_time'])) ?></span></div>
            <div class="bk-info-cell"><span class="lbl"><i class="bi bi-people me-1"></i>Seats</span><span class="val"><?= (int)$booking['number_of_seats'] ?></span></div>
            <div class="bk-info-cell"><span class="lbl"><i class="bi bi-cash me-1"></i>Total Fare</span><span class="val fw-bold" style="color:#1a3a6e;">Rs <?= number_format($booking['total_fare'], 2) ?></span></div>
            <div class="bk-info-cell"><span class="lbl"><i class="bi bi-calendar-plus me-1"></i>Booked On</span><span class="val"><?= date('d M Y', strtotime($booking['booking_date'])) ?></span></div>
        </div>
        <div class="bk-card-footer">
            <a href="booking_details.php?id=<?= $booking['booking_id'] ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-ticket-perforated me-1"></i>E-Ticket
            </a>
            <?php if ($booking['booking_status'] === 'confirmed'): ?>
            <button type="button" class="btn btn-success btn-sm" onclick="emailTicket(<?= $booking['booking_id'] ?>, this)">
                <i class="bi bi-envelope-fill me-1"></i>Email Ticket
            </button>
            <a href="booking_details.php?id=<?= $booking['booking_id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-printer me-1"></i>Print
            </a>
            <?php endif; ?>
            <?php if ($booking['booking_status'] === 'pending' && $booking['payment_status'] !== 'completed'): ?>
            <a href="payment.php?booking_id=<?= $booking['booking_id'] ?>" class="btn btn-warning btn-sm">
                <i class="bi bi-credit-card me-1"></i>Pay Now
            </a>
            <?php endif; ?>
            <?php if ($can_update): ?>
            <a href="booking_update.php?id=<?= $booking['booking_id'] ?>" class="btn btn-outline-warning btn-sm">
                <i class="bi bi-pencil-square me-1"></i>Change
            </a>
            <?php endif; ?>
            <?php if ($can_cancel): ?>
            <a href="booking_cancel.php?id=<?= $booking['booking_id'] ?>" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-x-circle me-1"></i>Cancel
            </a>
            <?php elseif ($status !== 'cancelled' && $hours <= 0): ?>
            <span class="text-muted small align-self-center"><i class="bi bi-lock me-1"></i>Departed</span>
            <?php elseif ($status !== 'cancelled' && $hours > 0 && $hours < 4): ?>
            <a href="booking_cancel.php?id=<?= $booking['booking_id'] ?>" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-x-circle me-1"></i>Cancel
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="empty-state">
        <div class="empty-icon"><i class="bi bi-ticket-perforated"></i></div>
        <?php if ($search !== '' || $filter !== 'all'): ?>
        <h5 class="fw-bold mb-2">No bookings match your filter</h5>
        <p class="text-muted mb-3">Try clearing the search or switching to "All".</p>
        <a href="bookings.php" class="btn btn-outline-secondary btn-sm">Clear Filters</a>
        <?php else: ?>
        <h5 class="fw-bold mb-2">No bookings yet</h5>
        <p class="text-muted mb-3">You haven't made any bookings yet. Start your journey today!</p>
        <a href="index.php" class="btn btn-primary"><i class="bi bi-train-front me-2"></i>Book a Train</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>
</div>

<div class="position-fixed bottom-0 end-0 p-3" style="z-index:9999;">
    <div id="bkToast" class="toast align-items-center border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body fw-semibold" id="bkToastMsg"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script>
function emailTicket(bookingId, btn) {
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending…';
    fetch('send_ticket_email.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'booking_id=' + encodeURIComponent(bookingId),
    })
    .then(r => r.json())
    .then(data => {
        const el  = document.getElementById('bkToast');
        const msg = document.getElementById('bkToastMsg');
        msg.innerHTML = (data.success
            ? '<i class="bi bi-check-circle me-2"></i>'
            : '<i class="bi bi-exclamation-circle me-2"></i>') + data.message;
        el.classList.remove('bg-success','bg-danger','text-white');
        el.classList.add(data.success ? 'bg-success' : 'bg-danger', 'text-white');
        new bootstrap.Toast(el, {delay:6000}).show();
    })
    .catch(() => alert('Network error. Please try again.'))
    .finally(() => { btn.disabled = false; btn.innerHTML = orig; });
}
</script>

<?php require_once 'inc/footer.php'; ?>