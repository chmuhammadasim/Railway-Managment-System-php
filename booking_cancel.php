<?php
// booking_cancel.php - Ticket cancellation with actual departure cutoffs
require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Booking.php';
require_once 'src/classes/AuditLog.php';

if (!User::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

function format_time_remaining($hours_remaining) {
    if ($hours_remaining <= 0) {
        return 'Departed';
    }

    $total_minutes = (int)round($hours_remaining * 60);
    $days = intdiv($total_minutes, 1440);
    $hours = intdiv($total_minutes % 1440, 60);
    $minutes = $total_minutes % 60;

    $parts = [];
    if ($days > 0) {
        $parts[] = $days . 'd';
    }
    if ($hours > 0) {
        $parts[] = $hours . 'h';
    }
    if ($minutes > 0 && $days === 0) {
        $parts[] = $minutes . 'm';
    }

    return implode(' ', $parts);
}

$db = new Database();
$db->connect();

$user_id = (int)$_SESSION['user_id'];
$booking_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($booking_id <= 0) {
    header('Location: bookings.php');
    exit();
}

$booking_obj = new Booking($db);
$booking = $booking_obj->getBookingById($booking_id);

if (!$booking || (int)$booking['user_id'] !== $user_id) {
    header('Location: bookings.php');
    exit();
}

$route = $db->selectRow(
    "SELECT r.*, t.train_name, t.train_number
     FROM routes r
     JOIN trains t ON r.train_id = t.train_id
     WHERE r.route_id = " . (int)$booking['route_id']
);

$booked_seats = $db->select(
    "SELECT bs.passenger_name, s.seat_number, s.seat_type
     FROM booking_seats bs
     JOIN seats s ON bs.seat_id = s.seat_id
     WHERE bs.booking_id = {$booking_id}
     ORDER BY bs.booking_seat_id ASC"
);
if (!$booked_seats) {
    $booked_seats = [];
}

$preview = $booking_obj->getRefundPreview($booking_id, $user_id);

$error_msg = '';
$success_msg = '';
$result_data = null;
$reason_value = trim($_POST['reason'] ?? '');
$custom_reason = trim($_POST['custom_reason'] ?? '');

$departure_timestamp = $route
    ? strtotime(($route['journey_date'] ?? $booking['journey_date']) . ' ' . ($route['departure_time'] ?? '00:00:00'))
    : strtotime($booking['journey_date'] . ' 00:00:00');
$hours_until_departure = $preview['hours'] ?? (($departure_timestamp - time()) / 3600);

if (empty($_SESSION['booking_cancel_csrf'])) {
    $_SESSION['booking_cancel_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['booking_cancel_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_cancel'])) {
    $submitted_token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrf_token, $submitted_token)) {
        $error_msg = 'Invalid request. Please refresh the page and try again.';
    } elseif (!$preview['allowed']) {
        $error_msg = $preview['message'];
    } else {
        $reason = $reason_value;
        if ($reason === 'Other') {
            $reason = $custom_reason;
        }

        $old_status = $booking['booking_status'];
        $result = $booking_obj->requestCancellation($booking_id, $user_id, trim($reason));

        if ($result['success']) {
            AuditLog::log(
                $db,
                'CANCEL',
                'bookings',
                "Booking #{$booking_id} ({$booking['booking_reference']}) cancelled by passenger. Tier: {$result['tier_label']}. Refund: Rs " . number_format($result['refund_amount'], 2),
                $booking_id,
                json_encode(['status' => $old_status, 'fare' => $booking['total_fare']]),
                json_encode([
                    'status' => 'cancelled',
                    'refund_amount' => $result['refund_amount'],
                    'cancel_fee' => $result['cancel_fee'],
                    'tier' => $result['tier_label'],
                    'is_unpaid' => $result['is_unpaid'],
                ])
            );
            // PRG: redirect so a page refresh doesn't re-submit the form
            // and so bookings.php shows the freshly-updated cancelled status.
            unset($_SESSION['booking_cancel_csrf']);
            $_SESSION['cancel_flash'] = [
                'booking_reference' => $booking['booking_reference'],
                'refund_amount'     => $result['refund_amount'],
                'cancel_fee'        => $result['cancel_fee'],
                'tier_label'        => $result['tier_label'],
                'is_unpaid'         => $result['is_unpaid'],
            ];
            header('Location: bookings.php');
            exit();
        } else {
            $error_msg = $result['message'];
        }
    }
}

$is_unpaid_preview = (bool)($preview['is_unpaid'] ?? false);
$pageTitle = 'Cancel Booking – Railway System';
require_once 'inc/header.php';
?>
<style>
    body { background: #f0f2f5; }
    .refund-tier-card { border-radius: 14px; overflow: hidden; }
    .tier-row { display: flex; align-items: center; gap: .75rem; padding: .7rem 1rem; border-bottom: 1px solid #eef2f7; font-size: .88rem; }
    .tier-row:last-child { border-bottom: none; }
    .tier-row.active-tier { background: #f0fdf4; border-left: 4px solid #10b981; }
    .tier-time { min-width: 150px; font-weight: 600; color: #374151; }
    .tier-refund { min-width: 90px; font-size: .95rem; font-weight: 700; }
    .policy-header { background: #1a2e4a; color: #fff; padding: .75rem 1rem; font-size: .8rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
    .booking-meta span { font-size: .82rem; color: #6b7280; }
    .booking-meta strong { color: #111827; }
    .refund-summary { background: linear-gradient(135deg, #f0fdf4, #ecfdf5); border: 1.5px solid #10b981; border-radius: 12px; padding: 1.2rem 1.4rem; }
    .refund-summary .rs-amount { font-size: 1.4rem; font-weight: 800; color: #059669; }
    .refund-summary .rs-fee { font-size: .95rem; color: #dc2626; font-weight: 600; }
    .info-summary { background: linear-gradient(135deg, #eff6ff, #e0f2fe); border: 1.5px solid #2563eb; border-radius: 12px; padding: 1.2rem 1.4rem; }
    .blocked-box { background: #fff7f7; border: 1.5px solid #ef4444; border-radius: 12px; padding: 1.2rem 1.4rem; }
    .seat-chip { display: inline-flex; align-items: center; gap: .35rem; background: #fff; border: 1px solid #d1d5db; border-radius: 999px; padding: .25rem .7rem; font-size: .75rem; font-weight: 700; color: #374151; }
    .countdown-badge { font-size: .72rem; padding: .3rem .6rem; border-radius: 20px; }
    .helper-text { font-size: .82rem; color: #64748b; }
</style>

<div class="container py-4" style="max-width:700px;">
    <a href="bookings.php" class="btn btn-outline-secondary btn-sm mb-3">
        <i class="bi bi-arrow-left me-1"></i>Back to My Bookings
    </a>

    <h4 class="fw-bold mb-3" style="color:#1a2e4a;">
        <i class="bi bi-x-circle-fill text-danger me-2"></i>Cancel Booking
    </h4>

    <?php if ($error_msg): ?>
    <div class="alert alert-danger d-flex align-items-center mb-3" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2 flex-shrink-0"></i>
        <?= htmlspecialchars($error_msg) ?>
    </div>
    <?php endif; ?>

    <?php if ($success_msg && $result_data): ?>
    <div class="card shadow-sm border-0 mb-3" style="border-radius:14px;">
        <div class="card-body p-4 text-center">
            <div class="mb-3"><i class="bi bi-check-circle-fill text-success" style="font-size:3rem;"></i></div>
            <h5 class="fw-bold text-success mb-1">Booking Cancelled</h5>
            <p class="text-muted mb-3" style="font-size:.9rem;">
                <?= htmlspecialchars($booking['booking_reference']) ?> has been cancelled.
            </p>
            <?php if ($result_data['is_unpaid']): ?>
            <div class="alert alert-info py-2 mb-3">
                <i class="bi bi-info-circle me-1"></i>
                This reservation was unpaid, so the seats were released with no fee and no refund.
            </div>
            <?php elseif ($result_data['refund_amount'] > 0): ?>
            <div class="alert alert-success py-2 mb-3">
                <i class="bi bi-cash-coin me-1"></i>
                <strong>Refund of Rs <?= number_format($result_data['refund_amount'], 2) ?></strong>
                will be credited within 5-7 business days.
            </div>
            <?php else: ?>
            <div class="alert alert-warning py-2 mb-3">
                <i class="bi bi-info-circle me-1"></i>
                No refund applies (<?= htmlspecialchars($result_data['tier_label']) ?>).
            </div>
            <?php endif; ?>
            <div class="d-flex gap-2 justify-content-center">
                <a href="bookings.php" class="btn btn-primary">View My Bookings</a>
                <a href="dashboard.php" class="btn btn-outline-secondary">Dashboard</a>
            </div>
        </div>
    </div>

    <?php else: ?>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <div class="fw-bold" style="font-size:1.05rem;"><?= htmlspecialchars($route['departure_city'] ?? '') ?> to <?= htmlspecialchars($route['arrival_city'] ?? '') ?></div>
                    <div class="text-muted" style="font-size:.83rem;">
                        <i class="bi bi-train-front me-1"></i><?= htmlspecialchars($route['train_name'] ?? 'N/A') ?>
                        <?php if (!empty($route['train_number'])): ?>
                            (<?= htmlspecialchars($route['train_number']) ?>)
                        <?php endif; ?>
                        &nbsp;|&nbsp; <?= date('D, d M Y H:i', $departure_timestamp) ?>
                    </div>
                </div>
                <div class="text-end">
                    <div class="badge bg-light text-dark border" style="font-size:.75rem;"><?= htmlspecialchars($booking['booking_reference']) ?></div>
                    <div class="mt-1">
                        <span class="badge <?= $booking['booking_status'] === 'confirmed' ? 'bg-success' : 'bg-warning text-dark' ?>">
                            <?= ucfirst($booking['booking_status']) ?>
                        </span>
                    </div>
                </div>
            </div>
            <hr class="my-2">
            <div class="row g-2 booking-meta">
                <div class="col-6"><span>Seats</span><br><strong><?= (int)$booking['number_of_seats'] ?> seat<?= $booking['number_of_seats'] > 1 ? 's' : '' ?></strong></div>
                <div class="col-6"><span>Total Fare</span><br><strong>Rs <?= number_format((float)$booking['total_fare'], 2) ?></strong></div>
                <div class="col-6"><span>Payment</span><br><strong><?= ucfirst($booking['payment_status'] ?? 'N/A') ?></strong></div>
                <div class="col-6"><span>Departure In</span><br><strong><?= htmlspecialchars(format_time_remaining($hours_until_departure)) ?></strong></div>
            </div>
            <?php if ($booked_seats): ?>
            <hr class="my-2">
            <div class="helper-text mb-2">Passengers on this ticket</div>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($booked_seats as $seat): ?>
                <span class="seat-chip">
                    <?= htmlspecialchars($seat['seat_number']) ?>
                    <span style="color:#94a3b8;">/</span>
                    <?= htmlspecialchars($seat['passenger_name']) ?>
                    <span style="color:#94a3b8;">(<?= ucfirst($seat['seat_type']) ?>)</span>
                </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$is_unpaid_preview): ?>
    <div class="card shadow-sm border-0 mb-3 refund-tier-card">
        <div class="policy-header"><i class="bi bi-shield-check me-1"></i>Cancellation and Refund Policy</div>
        <?php
        $tiers = [
            ['label' => 'More than 72 hrs before', 'refund' => 100, 'fee' => 0, 'color' => '#059669'],
            ['label' => '48-72 hrs before', 'refund' => 75, 'fee' => 25, 'color' => '#0891b2'],
            ['label' => '24-48 hrs before', 'refund' => 50, 'fee' => 50, 'color' => '#d97706'],
            ['label' => 'Less than 24 hrs', 'refund' => 0, 'fee' => 100, 'color' => '#dc2626'],
        ];
        $active_pct = ($preview['allowed'] ?? false) ? ($preview['refund_pct'] ?? -1) : -1;
        foreach ($tiers as $tier):
            $is_active = ($active_pct === $tier['refund'] && ($preview['allowed'] ?? false));
            $is_blocked = (!($preview['allowed'] ?? false) && isset($preview['hours']) && $preview['hours'] < 24 && $tier['refund'] === 0);
        ?>
        <div class="tier-row <?= ($is_active || $is_blocked) ? 'active-tier' : '' ?>">
            <span class="tier-time">
                <?= ($is_active || $is_blocked) ? '<i class="bi bi-arrow-right-circle-fill text-success me-1"></i>' : '<i class="bi bi-circle me-1" style="color:#d1d5db;"></i>' ?>
                <?= htmlspecialchars($tier['label']) ?>
            </span>
            <span class="tier-refund" style="color:<?= htmlspecialchars($tier['color']) ?>;"><?= (int)$tier['refund'] ?>% back</span>
            <span style="font-size:.82rem;color:#6b7280;"><?= (int)$tier['fee'] ?>% fee</span>
            <?php if ($is_active): ?><span class="badge bg-success countdown-badge ms-auto">Current tier</span>
            <?php elseif ($is_blocked): ?><span class="badge bg-danger countdown-badge ms-auto">Blocked now</span><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($booking['booking_status'] === 'cancelled'): ?>
    <div class="alert alert-warning mb-3">
        <i class="bi bi-info-circle me-1"></i>This booking has already been cancelled.
    </div>
    <a href="bookings.php" class="btn btn-secondary">Back to Bookings</a>

    <?php elseif (!($preview['allowed'] ?? false)): ?>
    <div class="blocked-box mb-3">
        <div class="d-flex align-items-center gap-2 mb-1">
            <i class="bi bi-slash-circle-fill text-danger fs-5"></i>
            <span class="fw-bold text-danger">Cancellation Not Available</span>
        </div>
        <p class="text-muted mb-0" style="font-size:.88rem;"><?= htmlspecialchars($preview['message']) ?></p>
    </div>
    <a href="bookings.php" class="btn btn-secondary"><i class="bi bi-arrow-left me-1"></i>Back to Bookings</a>

    <?php else: ?>
        <?php if ($is_unpaid_preview): ?>
        <div class="info-summary mb-3">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#2563eb;"><i class="bi bi-info-circle me-1"></i>Unpaid Reservation</div>
                    <div class="fw-bold" style="font-size:1.15rem;color:#1d4ed8;">No fee and no refund</div>
                </div>
                <div class="text-end helper-text">
                    Cancelling will simply release these seats back to inventory.
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="refund-summary mb-3">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                <div>
                    <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#059669;"><i class="bi bi-cash-stack me-1"></i>Estimated Refund</div>
                    <div class="rs-amount">Rs <?= number_format((float)$preview['refund_amount'], 2) ?></div>
                    <div style="font-size:.78rem;color:#6b7280;"><?= htmlspecialchars($preview['tier_label']) ?></div>
                </div>
                <div class="text-end">
                    <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#dc2626;">Cancellation Fee</div>
                    <div class="rs-fee">Rs <?= number_format((float)$preview['cancel_fee'], 2) ?></div>
                    <div style="font-size:.78rem;color:#6b7280;"><?= (int)$preview['fee_pct'] ?>% of Rs <?= number_format((float)$booking['total_fare'], 2) ?></div>
                </div>
            </div>
            <div class="progress" style="height:8px;border-radius:4px;">
                <div class="progress-bar bg-success" style="width:<?= (float)$preview['refund_pct'] ?>%;" role="progressbar"></div>
                <?php if (($preview['fee_pct'] ?? 0) > 0): ?>
                <div class="progress-bar bg-danger" style="width:<?= (float)$preview['fee_pct'] ?>%;" role="progressbar"></div>
                <?php endif; ?>
            </div>
            <div class="d-flex justify-content-between mt-1" style="font-size:.7rem;color:#6b7280;">
                <span>Refund (<?= (int)$preview['refund_pct'] ?>%)</span>
                <?php if (($preview['fee_pct'] ?? 0) > 0): ?><span>Fee (<?= (int)$preview['fee_pct'] ?>%)</span><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body p-3">
                <form method="POST" id="cancelForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                    <div class="mb-3">
                        <label for="reason" class="form-label fw-semibold" style="font-size:.88rem;">
                            Reason for cancellation <span class="text-muted fw-normal">(optional)</span>
                        </label>
                        <select class="form-select form-select-sm" name="reason" id="reason">
                            <option value="">Select reason</option>
                            <option value="Change of plans" <?= $reason_value === 'Change of plans' ? 'selected' : '' ?>>Change of plans</option>
                            <option value="Medical emergency" <?= $reason_value === 'Medical emergency' ? 'selected' : '' ?>>Medical emergency</option>
                            <option value="Work commitment" <?= $reason_value === 'Work commitment' ? 'selected' : '' ?>>Work commitment</option>
                            <option value="Family emergency" <?= $reason_value === 'Family emergency' ? 'selected' : '' ?>>Family emergency</option>
                            <option value="Travel plan changed" <?= $reason_value === 'Travel plan changed' ? 'selected' : '' ?>>Travel plan changed</option>
                            <option value="Other" <?= $reason_value === 'Other' ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>

                    <div class="mb-3 <?= $reason_value === 'Other' ? '' : 'd-none' ?>" id="customReasonWrap">
                        <label for="custom_reason" class="form-label fw-semibold" style="font-size:.88rem;">Custom reason</label>
                        <textarea class="form-control form-control-sm" name="custom_reason" id="custom_reason" rows="3" maxlength="255"><?= htmlspecialchars($custom_reason) ?></textarea>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="confirmCheck" required>
                        <label class="form-check-label" for="confirmCheck" style="font-size:.87rem;">
                            <?php if ($is_unpaid_preview): ?>
                                I understand this unpaid reservation will be cancelled immediately and the released seats may be booked by someone else.
                            <?php else: ?>
                                I understand that a cancellation fee of <strong>Rs <?= number_format((float)$preview['cancel_fee'], 2) ?></strong>
                                will be charged and I will receive <strong>Rs <?= number_format((float)$preview['refund_amount'], 2) ?></strong> back.
                            <?php endif; ?>
                            This action <strong>cannot be undone</strong>.
                        </label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" name="confirm_cancel" value="1" class="btn btn-danger" id="cancelBtn" disabled>
                            <i class="bi bi-x-circle me-1"></i>Confirm Cancellation
                        </button>
                        <a href="booking_details.php?id=<?= $booking_id ?>" class="btn btn-outline-secondary">Keep My Booking</a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script>
(function () {
    var check = document.getElementById('confirmCheck');
    var button = document.getElementById('cancelBtn');
    var reason = document.getElementById('reason');
    var customWrap = document.getElementById('customReasonWrap');
    var form = document.getElementById('cancelForm');

    if (check && button) {
        check.addEventListener('change', function () {
            button.disabled = !this.checked;
        });
    }

    if (reason && customWrap) {
        reason.addEventListener('change', function () {
            customWrap.classList.toggle('d-none', this.value !== 'Other');
        });
    }

    if (form && button) {
        form.addEventListener('submit', function () {
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Cancelling...';
        });
    }
}());
</script>
<?php require_once 'inc/footer.php'; ?>
