<?php
// send_ticket_email.php – Send E-Ticket to user's registered email address

header('Content-Type: application/json');

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';

// Must be a logged-in POST request
if (!User::isLoggedIn() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$db = new Database();
$db->connect();

$user_id    = (int)$_SESSION['user_id'];
$booking_id = (int)($_POST['booking_id'] ?? 0);

if (!$booking_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID.']);
    exit();
}

// Fetch booking + route + train + user
$booking = $db->selectRow(
    "SELECT b.*, r.departure_city, r.arrival_city, r.departure_time, r.arrival_time,
            r.distance_km, r.base_fare,
            t.train_name, t.train_number, t.train_type,
            u.full_name AS booker_name, u.email AS booker_email, u.phone AS booker_phone
     FROM bookings b
     JOIN routes r ON b.route_id  = r.route_id
     JOIN trains t ON r.train_id  = t.train_id
     JOIN users  u ON b.user_id   = u.user_id
     WHERE b.booking_id = {$booking_id} AND b.user_id = {$user_id}"
);

if (!$booking) {
    echo json_encode(['success' => false, 'message' => 'Booking not found or access denied.']);
    exit();
}

// Passenger list
$passengers = $db->select(
    "SELECT bs.*, s.seat_number, s.seat_type
     FROM booking_seats bs
     JOIN seats s ON bs.seat_id = s.seat_id
     WHERE bs.booking_id = {$booking_id}
     ORDER BY bs.booking_seat_id ASC"
);
if (!$passengers) $passengers = [];

// Payment record
$payment = $db->selectRow(
    "SELECT * FROM payments WHERE booking_id = {$booking_id} ORDER BY created_at DESC LIMIT 1"
);

// ── Helper: berth from seat number ────────────────────────────────────────
function getBerthLabel(string $seat_num): string {
    $pos = (int)preg_replace('/[^0-9]/', '', $seat_num);
    return match(true) { $pos <= 2 => 'Lower', $pos <= 4 => 'Middle', default => 'Upper' };
}

// ── Build Passenger Table Rows ─────────────────────────────────────────────
$pax_rows = '';
if (!empty($passengers)) {
    foreach ($passengers as $i => $p) {
        $gender_label = match($p['passenger_gender'] ?? '') {
            'M' => 'Male', 'F' => 'Female', default => $p['passenger_gender'] ?? '—'
        };
        $berth = getBerthLabel($p['seat_number']);
        $row_bg = $i % 2 === 0 ? '#ffffff' : '#f8fafc';
        $pax_rows .= "
        <tr style='background:{$row_bg};'>
            <td style='padding:10px 14px;border:1px solid #e5e7eb;text-align:center;font-weight:700;'>" . ($i + 1) . "</td>
            <td style='padding:10px 14px;border:1px solid #e5e7eb;font-weight:600;'>" . htmlspecialchars($p['passenger_name']) . "</td>
            <td style='padding:10px 14px;border:1px solid #e5e7eb;text-align:center;'>" . htmlspecialchars($p['passenger_age'] ?? '—') . "</td>
            <td style='padding:10px 14px;border:1px solid #e5e7eb;text-align:center;'>{$gender_label}</td>
            <td style='padding:10px 14px;border:1px solid #e5e7eb;text-align:center;font-weight:800;color:#1d4ed8;'>" . htmlspecialchars($p['seat_number']) . "</td>
            <td style='padding:10px 14px;border:1px solid #e5e7eb;text-align:center;'>{$berth} / " . ucfirst($p['seat_type']) . "</td>
        </tr>";
    }
} else {
    $pax_rows = "<tr><td colspan='6' style='padding:12px;text-align:center;color:#9ca3af;border:1px solid #e5e7eb;'>No passenger details recorded.</td></tr>";
}

// ── Status colour ──────────────────────────────────────────────────────────
$status_colors = [
    'confirmed' => ['bg' => '#d1fae5', 'text' => '#065f46'],
    'pending'   => ['bg' => '#fef3c7', 'text' => '#78350f'],
    'cancelled' => ['bg' => '#fee2e2', 'text' => '#7f1d1d'],
];
$sc  = $status_colors[$booking['booking_status']] ?? $status_colors['pending'];
$txn = $payment ? htmlspecialchars($payment['transaction_id']) : '—';
$pay_method = $payment ? ucwords(str_replace('_', ' ', $payment['payment_method'])) : 'Pending';

// ── Site URL (for ticket link) ─────────────────────────────────────────────
$proto    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base_url = $proto . '://' . $host . '/Railway-Managment-System-php';

// ── E-Mail HTML Body ───────────────────────────────────────────────────────
$ref           = htmlspecialchars($booking['booking_reference']);
$dep_city      = htmlspecialchars($booking['departure_city']);
$arr_city      = htmlspecialchars($booking['arrival_city']);
$dep_time      = date('H:i', strtotime($booking['departure_time']));
$arr_time      = date('H:i', strtotime($booking['arrival_time']));
$journey_date  = date('l, d F Y', strtotime($booking['journey_date']));
$train_info    = htmlspecialchars($booking['train_name'] . ' (' . $booking['train_number'] . ')');
$booker_name   = htmlspecialchars($booking['booker_name']);
$total_fare    = number_format($booking['total_fare'], 2);
$booked_on     = date('d M Y H:i', strtotime($booking['booking_date']));
$booking_status = ucfirst($booking['booking_status']);
$seats_n       = (int)$booking['number_of_seats'];

$email_body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>E-Ticket {$ref}</title>
</head>
<body style="margin:0;padding:0;background:#eef2f7;font-family:'Segoe UI',Arial,sans-serif;color:#1e293b;">

<!-- Wrapper -->
<table width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f7;padding:32px 0;">
<tr><td align="center">
<table width="620" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,.12);">

  <!-- Header -->
  <tr>
    <td style="background:linear-gradient(135deg,#0f2d5c 0%,#1a5276 50%,#2980b9 100%);padding:28px 32px;">
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <td>
            <div style="font-size:22px;font-weight:800;color:#fff;letter-spacing:-.5px;">🚂 Pakistan Railways</div>
            <div style="font-size:13px;color:rgba(255,255,255,.65);margin-top:4px;">Electronic Travel Ticket</div>
          </td>
          <td align="right">
            <div style="background:rgba(255,255,255,.15);border:1.5px dashed rgba(255,255,255,.4);border-radius:8px;padding:8px 16px;color:#fff;display:inline-block;text-align:right;">
              <div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;opacity:.7;">Booking Ref</div>
              <div style="font-size:18px;font-weight:900;letter-spacing:2px;">{$ref}</div>
            </div>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- Status banner -->
  <tr>
    <td style="background:{$sc['bg']};padding:10px 32px;text-align:center;">
      <span style="color:{$sc['text']};font-weight:700;font-size:14px;">● {$booking_status}</span>
    </td>
  </tr>

  <!-- Journey timeline -->
  <tr>
    <td style="padding:28px 32px 20px;">
      <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4ff;border-radius:12px;padding:20px;">
        <tr>
          <td width="35%" style="text-align:center;padding:0 8px;">
            <div style="font-size:26px;font-weight:900;color:#0f2d5c;">{$dep_city}</div>
            <div style="font-size:18px;font-weight:700;color:#374151;margin-top:4px;">{$dep_time}</div>
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;margin-top:2px;">Departure</div>
          </td>
          <td width="30%" style="text-align:center;padding:0 8px;">
            <div style="font-size:28px;color:#2563eb;">→</div>
            <div style="font-size:12px;color:#6b7280;margin-top:4px;">{$journey_date}</div>
          </td>
          <td width="35%" style="text-align:center;padding:0 8px;">
            <div style="font-size:26px;font-weight:900;color:#0f2d5c;">{$arr_city}</div>
            <div style="font-size:18px;font-weight:700;color:#374151;margin-top:4px;">{$arr_time}</div>
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;margin-top:2px;">Arrival</div>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- Info grid -->
  <tr>
    <td style="padding:0 32px 24px;">
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <td width="50%" style="padding:8px 10px 8px 0;">
            <div style="background:#f8fafc;border-radius:10px;padding:12px 16px;">
              <div style="font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;">Train</div>
              <div style="font-size:14px;font-weight:700;color:#1e293b;margin-top:3px;">{$train_info}</div>
            </div>
          </td>
          <td width="50%" style="padding:8px 0 8px 10px;">
            <div style="background:#f8fafc;border-radius:10px;padding:12px 16px;">
              <div style="font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;">Passenger / Booker</div>
              <div style="font-size:14px;font-weight:700;color:#1e293b;margin-top:3px;">{$booker_name}</div>
            </div>
          </td>
        </tr>
        <tr>
          <td width="50%" style="padding:8px 10px 0 0;">
            <div style="background:#f8fafc;border-radius:10px;padding:12px 16px;">
              <div style="font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;">Seats</div>
              <div style="font-size:14px;font-weight:700;color:#1e293b;margin-top:3px;">{$seats_n}</div>
            </div>
          </td>
          <td width="50%" style="padding:8px 0 0 10px;">
            <div style="background:#f8fafc;border-radius:10px;padding:12px 16px;">
              <div style="font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;">Payment Method</div>
              <div style="font-size:14px;font-weight:700;color:#1e293b;margin-top:3px;">{$pay_method}</div>
            </div>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- Dashed divider -->
  <tr>
    <td style="padding:0 32px;">
      <div style="border-top:2px dashed #dee2e6;margin:0;"></div>
    </td>
  </tr>

  <!-- Passenger table -->
  <tr>
    <td style="padding:24px 32px;">
      <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#1a3c6e;margin-bottom:12px;">
        Passenger Details
      </div>
      <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
        <thead>
          <tr style="background:#0f2d5c;">
            <th style="padding:10px 14px;color:#fff;font-size:11px;text-align:center;border:1px solid #1a4a8a;">#</th>
            <th style="padding:10px 14px;color:#fff;font-size:11px;text-align:left;border:1px solid #1a4a8a;">Name</th>
            <th style="padding:10px 14px;color:#fff;font-size:11px;text-align:center;border:1px solid #1a4a8a;">Age</th>
            <th style="padding:10px 14px;color:#fff;font-size:11px;text-align:center;border:1px solid #1a4a8a;">Gender</th>
            <th style="padding:10px 14px;color:#fff;font-size:11px;text-align:center;border:1px solid #1a4a8a;">Seat</th>
            <th style="padding:10px 14px;color:#fff;font-size:11px;text-align:center;border:1px solid #1a4a8a;">Berth / Class</th>
          </tr>
        </thead>
        <tbody>
          {$pax_rows}
        </tbody>
      </table>
    </td>
  </tr>

  <!-- Fare box -->
  <tr>
    <td style="padding:0 32px 28px;">
      <table width="100%" cellpadding="0" cellspacing="0" style="background:linear-gradient(135deg,#0f2d5c,#2980b9);border-radius:12px;">
        <tr>
          <td style="padding:20px 24px;">
            <div style="color:rgba(255,255,255,.7);font-size:12px;">Total Fare</div>
            <div style="color:#fff;font-size:32px;font-weight:900;margin-top:4px;">Rs. {$total_fare}</div>
            <div style="color:rgba(255,255,255,.55);font-size:11px;margin-top:4px;">TXN: {$txn}</div>
          </td>
          <td align="right" style="padding:20px 24px;">
            <div style="color:rgba(255,255,255,.7);font-size:11px;">Booked on</div>
            <div style="color:#fff;font-weight:600;font-size:13px;">{$booked_on}</div>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- CTA Button -->
  <tr>
    <td style="padding:0 32px 28px;text-align:center;">
      <a href="{$base_url}/booking_details.php?id={$booking_id}"
         style="display:inline-block;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;font-weight:700;font-size:14px;text-decoration:none;padding:14px 36px;border-radius:10px;letter-spacing:.3px;">
        View Full E-Ticket Online
      </a>
    </td>
  </tr>

  <!-- Footer -->
  <tr>
    <td style="background:#f8fafc;border-top:2px dashed #dee2e6;padding:18px 32px;">
      <p style="font-size:12px;color:#6b7280;margin:0;">
        This is an official e-ticket from Pakistan Railways. Please present this email or the online ticket at entry gates.<br>
        For assistance call <strong>UAN: 051-111-060-060</strong> | <a href="mailto:support@pakrailways.pk" style="color:#2563eb;">support@pakrailways.pk</a>
      </p>
      <p style="font-size:11px;color:#9ca3af;margin:8px 0 0;">
        Ticket issued on <?= date('d M Y H:i') ?>. Modifications/cancellations must be made 24+ hours before journey.
      </p>
    </td>
  </tr>

</table>
</td></tr>
</table>
</body>
</html>
HTML;

// ── Send ──────────────────────────────────────────────────────────────────
$to      = $booking['booker_email'];
$subject = "E-Ticket {$booking['booking_reference']} – Pakistan Railways";

$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";
$headers .= "From: Pakistan Railways <noreply@pakrailways.pk>\r\n";
$headers .= "Reply-To: support@pakrailways.pk\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

if (mail($to, $subject, $email_body, $headers)) {
    echo json_encode([
        'success' => true,
        'message' => "E-Ticket sent to {$to} successfully.",
    ]);
} else {
    // mail() returned false — SMTP not configured in php.ini on this server
    // Return a helpful message instead of a silent failure
    echo json_encode([
        'success' => false,
        'message' => "Email could not be sent. Configure SMTP in php.ini (sendmail_path or SMTP settings) to enable this feature.",
    ]);
}
