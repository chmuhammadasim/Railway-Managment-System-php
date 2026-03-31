<?php
// manage-payments.php – Admin: All Payments List

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';

if (!User::isLoggedIn() || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();
$conn = $db->getConnection();

// ── Filters ───────────────────────────────────────────────────────────────────
$filter_status = $_GET['status']  ?? 'all';
$filter_method = $_GET['method']  ?? 'all';
$search        = trim($_GET['q']  ?? '');
$date_from     = $_GET['date_from'] ?? '';
$date_to       = $_GET['date_to']   ?? '';
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 20;
$offset        = ($page - 1) * $per_page;

// ── Build WHERE ───────────────────────────────────────────────────────────────
$wheres = [];
if ($filter_status !== 'all') {
    $s = $conn->real_escape_string($filter_status);
    $wheres[] = "p.payment_status = '{$s}'";
}
if ($filter_method !== 'all') {
    $m = $conn->real_escape_string($filter_method);
    $wheres[] = "p.payment_method = '{$m}'";
}
if ($search !== '') {
    $sq = $conn->real_escape_string($search);
    $wheres[] = "(b.booking_reference LIKE '%{$sq}%' OR u.full_name LIKE '%{$sq}%' OR p.transaction_id LIKE '%{$sq}%')";
}
if ($date_from !== '') {
    $df = $conn->real_escape_string($date_from);
    $wheres[] = "DATE(p.payment_date) >= '{$df}'";
}
if ($date_to !== '') {
    $dt = $conn->real_escape_string($date_to);
    $wheres[] = "DATE(p.payment_date) <= '{$dt}'";
}
$where_sql = $wheres ? 'WHERE ' . implode(' AND ', $wheres) : '';

// ── Revenue KPIs (unfiltered) ─────────────────────────────────────────────────
$kpi = $db->selectRow(
    "SELECT
        COUNT(*)                                   AS total_txns,
        SUM(payment_status = 'completed') * 1      AS completed_n,
        SUM(payment_status = 'pending')   * 1      AS pending_n,
        SUM(payment_status = 'refunded')  * 1      AS refunded_n,
        SUM(payment_status = 'failed')    * 1      AS failed_n,
        SUM(CASE WHEN payment_status = 'completed' THEN amount ELSE 0 END) AS total_received,
        SUM(CASE WHEN payment_status = 'refunded'  THEN amount ELSE 0 END) AS total_refunded,
        SUM(CASE WHEN payment_status = 'pending'   THEN amount ELSE 0 END) AS total_pending
     FROM payments"
);

// ── Payment methods list for filter ──────────────────────────────────────────
$methods_rows = $db->select("SELECT DISTINCT payment_method FROM payments ORDER BY payment_method");
$all_methods  = $methods_rows ? array_column($methods_rows, 'payment_method') : [];

// ── Count for pagination ──────────────────────────────────────────────────────
$count_row = $db->selectRow(
    "SELECT COUNT(*) AS n
     FROM payments p
     JOIN bookings b ON p.booking_id = b.booking_id
     JOIN users    u ON p.user_id    = u.user_id
     {$where_sql}"
);
$total_rows  = (int)($count_row['n'] ?? 0);
$total_pages = max(1, (int)ceil($total_rows / $per_page));

// ── Payments query ────────────────────────────────────────────────────────────
$payments = $db->select(
    "SELECT p.payment_id, p.amount, p.payment_method, p.transaction_id,
            p.payment_status, p.payment_date, p.refund_date, p.refund_reason,
            b.booking_id, b.booking_reference,
            u.full_name, u.email
     FROM payments p
     JOIN bookings b ON p.booking_id = b.booking_id
     JOIN users    u ON p.user_id    = u.user_id
     {$where_sql}
     ORDER BY p.created_at DESC
     LIMIT {$per_page} OFFSET {$offset}"
);
if (!$payments) $payments = [];

$error_message   = '';
$success_message = '';

// ── Inline refund action ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $pid = (int)($_POST['payment_id'] ?? 0);
    if ($pid > 0 && $_POST['action'] === 'refund') {
        $reason = $conn->real_escape_string(trim($_POST['refund_reason'] ?? 'Admin refund'));
        $db->query(
            "UPDATE payments
             SET payment_status = 'refunded',
                 refund_date    = NOW(),
                 refund_reason  = '{$reason}'
             WHERE payment_id = {$pid} AND payment_status = 'completed'"
        );
        // Also mark the booking payment as refunded
        $pay_row = $db->selectRow("SELECT booking_id FROM payments WHERE payment_id = {$pid}");
        if ($pay_row) {
            $db->query("UPDATE bookings SET payment_status='refunded' WHERE booking_id = {$pay_row['booking_id']}");
        }
        $success_message = 'Refund processed successfully.';
    }
    $qs = http_build_query(array_filter([
        'status'    => $filter_status !== 'all' ? $filter_status : null,
        'method'    => $filter_method !== 'all' ? $filter_method : null,
        'q'         => $search        ?: null,
        'date_from' => $date_from     ?: null,
        'date_to'   => $date_to       ?: null,
        'page'      => $page > 1      ? $page  : null,
    ]));
    header('Location: manage-payments.php' . ($qs ? "?{$qs}" : ''));
    exit();
}

function pUrl(array $overrides = []): string {
    global $filter_status, $filter_method, $search, $date_from, $date_to, $page;
    $params = array_filter(array_merge([
        'status'    => $filter_status !== 'all' ? $filter_status : null,
        'method'    => $filter_method !== 'all' ? $filter_method : null,
        'q'         => $search        ?: null,
        'date_from' => $date_from     ?: null,
        'date_to'   => $date_to       ?: null,
        'page'      => $page > 1      ? $page  : null,
    ], $overrides));
    $qs = http_build_query($params);
    return 'manage-payments.php' . ($qs ? "?{$qs}" : '');
}

$hideMainNavbar = true;
$pageTitle = 'Manage Payments – Admin';
require_once 'inc/header.php';
?>

<style>
.adm-wrap    { display: flex; min-height: calc(100vh - 60px); }
.adm-sidebar {
    width: 230px; flex-shrink: 0;
    background: linear-gradient(180deg, #7c2d12 0%, #991b1b 100%);
    padding: 1.5rem 0;
    position: sticky; top: 60px;
    height: calc(100vh - 60px); overflow-y: auto;
}
.adm-sidebar .sb-brand {
    padding: .5rem 1.25rem 1.25rem;
    font-weight: 800; font-size: 1.05rem; color: #fef2f2;
    border-bottom: 1px solid rgba(255,255,255,.15); margin-bottom: .75rem;
}
.adm-sidebar a {
    display: flex; align-items: center; gap: .65rem;
    padding: .55rem 1.25rem; color: rgba(255,255,255,.8);
    text-decoration: none; font-size: .88rem;
    transition: background .2s, color .2s;
}
.adm-sidebar a:hover,
.adm-sidebar a.active { background: rgba(255,255,255,.15); color: #fff; }
.adm-sidebar .sb-section {
    font-size: .7rem; text-transform: uppercase; letter-spacing: .08em;
    color: rgba(255,255,255,.45); padding: .9rem 1.25rem .3rem; font-weight: 700;
}
.adm-main { flex: 1; padding: 1.75rem; overflow: hidden; }

/* KPI cards */
.kpi-card { border-radius: 12px; border: none; box-shadow: 0 2px 8px rgba(0,0,0,.07); }
.kpi-icon { width: 46px; height: 46px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; }

/* Status pills */
.sp { display: inline-block; padding: .25em .75em; border-radius: 20px; font-size: .77rem; font-weight: 600; }
.sp-completed { background: #d1fae5; color: #065f46; }
.sp-pending   { background: #fef3c7; color: #92400e; }
.sp-refunded  { background: #ede9fe; color: #4c1d95; }
.sp-failed    { background: #fee2e2; color: #7f1d1d; }

/* Table */
.pay-table thead th { background: #1e3a5f; color: #fff; font-weight: 600; font-size: .82rem; white-space: nowrap; }
.pay-table tbody tr:hover { background: #f5f8ff; }
.pay-table td { font-size: .84rem; vertical-align: middle; }

.filter-bar { background: #fff; border-radius: 12px; box-shadow: 0 1px 6px rgba(0,0,0,.07); padding: 1rem 1.25rem; margin-bottom: 1.25rem; }

@media (max-width: 768px) {
    .adm-sidebar { display: none; }
    .adm-main    { padding: 1rem; }
}
</style>

<div class="adm-wrap">
<!-- ── Sidebar ── -->
<aside class="adm-sidebar">
    <div class="sb-brand"><i class="bi bi-train-front-fill me-2"></i>Admin Panel</div>
    <div class="sb-section">Main</div>
    <a href="admin-dashboard.php"><i class="bi bi-speedometer2"></i>Dashboard</a>
    <div class="sb-section">Bookings</div>
    <a href="manage-bookings.php"><i class="bi bi-journal-text"></i>All Bookings</a>
    <a href="manage-payments.php" class="active"><i class="bi bi-cash-stack"></i>Payments</a>
    <div class="sb-section">Operations</div>
    <a href="manage-trains.php"><i class="bi bi-train-front"></i>Trains</a>
    <a href="manage-routes.php"><i class="bi bi-map"></i>Routes</a>
    <div class="sb-section">Users</div>
    <a href="manage-users.php"><i class="bi bi-people"></i>Users</a>
    <div class="sb-section">Reports</div>
    <a href="reports.php"><i class="bi bi-bar-chart-line"></i>Reports</a>
</aside>

<!-- ── Main Content ── -->
<main class="adm-main">

    <!-- Page header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-0"><i class="bi bi-cash-stack me-2 text-success"></i>Manage Payments</h4>
            <small class="text-muted"><?= number_format($total_rows) ?> records match current filters</small>
        </div>
        <a href="reports.php?tab=income_trains" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-graph-up me-1"></i>Revenue Reports
        </a>
    </div>

    <?php if ($success_message): ?>
    <div class="alert alert-success border-0 rounded-3 py-2 mb-3">
        <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success_message) ?>
    </div>
    <?php endif; ?>

    <!-- Revenue KPI Strip -->
    <div class="row g-3 mb-4">
        <?php foreach ([
            ['Total Revenue',  'Rs. ' . number_format($kpi['total_received'] ?? 0, 0), 'bi-currency-dollar', 'bg-success text-white', '#f0fdf4'],
            ['Pending Amount', 'Rs. ' . number_format($kpi['total_pending']  ?? 0, 0), 'bi-hourglass-split', 'bg-warning text-dark',  '#fffbeb'],
            ['Refunded',       'Rs. ' . number_format($kpi['total_refunded'] ?? 0, 0), 'bi-arrow-counterclockwise', 'bg-purple text-white', '#f5f3ff'],
            ['Completed Txns', number_format($kpi['completed_n'] ?? 0), 'bi-check-circle', 'bg-primary text-white', '#eff6ff'],
            ['Pending Txns',   number_format($kpi['pending_n']   ?? 0), 'bi-clock-history',  'bg-warning text-dark', '#fefce8'],
            ['Failed / Refund', number_format(($kpi['failed_n'] ?? 0) + ($kpi['refunded_n'] ?? 0)), 'bi-x-octagon', 'bg-danger text-white', '#fef2f2'],
        ] as [$lbl, $val, $icon, $ibg, $bg]): ?>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="kpi-card card p-3" style="background:<?= $bg ?>;">
                <div class="d-flex align-items-center gap-3">
                    <div class="kpi-icon <?= $ibg ?>"><i class="bi <?= $icon ?>"></i></div>
                    <div>
                        <div class="fw-bold fs-6"><?= $val ?></div>
                        <div class="text-muted small"><?= $lbl ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filter bar -->
    <form method="GET" class="filter-bar">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-sm-6 col-lg-3">
                <label class="form-label mb-1 small fw-semibold">Search</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" class="form-control"
                           placeholder="Ref / Name / TXN ID"
                           value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-6 col-sm-3 col-lg-2">
                <label class="form-label mb-1 small fw-semibold">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <?php foreach (['all'=>'All Statuses','completed'=>'Completed','pending'=>'Pending','refunded'=>'Refunded','failed'=>'Failed'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= $filter_status === $v ? 'selected':'' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-sm-3 col-lg-2">
                <label class="form-label mb-1 small fw-semibold">Method</label>
                <select name="method" class="form-select form-select-sm">
                    <option value="all">All Methods</option>
                    <?php foreach ($all_methods as $m): ?>
                    <option value="<?= htmlspecialchars($m) ?>" <?= $filter_method === $m ? 'selected':'' ?>>
                        <?= ucwords(str_replace('_', ' ', $m)) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-sm-3 col-lg-2">
                <label class="form-label mb-1 small fw-semibold">Date From</label>
                <input type="date" name="date_from" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($date_from) ?>">
            </div>
            <div class="col-6 col-sm-3 col-lg-2">
                <label class="form-label mb-1 small fw-semibold">Date To</label>
                <input type="date" name="date_to" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($date_to) ?>">
            </div>
            <div class="col-12 col-lg-1 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm flex-fill">
                    <i class="bi bi-funnel"></i>
                </button>
                <a href="manage-payments.php" class="btn btn-outline-secondary btn-sm flex-fill" title="Reset">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </div>
    </form>

    <!-- Payments Table -->
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0 pay-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Booking Ref</th>
                        <th>Passenger</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Transaction ID</th>
                        <th>Status</th>
                        <th>Payment Date</th>
                        <th>Refund Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($payments)): ?>
                    <tr><td colspan="10" class="text-center py-5 text-muted">
                        <i class="bi bi-inbox fs-2 d-block mb-2"></i>No payment records found.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($payments as $pay): ?>
                    <tr>
                        <td class="text-muted small"><?= $pay['payment_id'] ?></td>
                        <td>
                            <a href="booking-admin.php?id=<?= $pay['booking_id'] ?>"
                               class="fw-bold text-primary text-decoration-none small">
                                <?= htmlspecialchars($pay['booking_reference']) ?>
                            </a>
                        </td>
                        <td>
                            <div class="fw-semibold small"><?= htmlspecialchars($pay['full_name']) ?></div>
                            <div class="text-muted" style="font-size:.73rem;"><?= htmlspecialchars($pay['email']) ?></div>
                        </td>
                        <td class="fw-bold small">Rs. <?= number_format($pay['amount'], 2) ?></td>
                        <td class="small">
                            <?php
                            $icons = ['credit_card'=>'💳','debit_card'=>'💳','easypaisa'=>'📱','jazzcash'=>'📲','bank_transfer'=>'🏦','cash'=>'💵'];
                            echo ($icons[$pay['payment_method']] ?? '💰') . ' ';
                            echo ucwords(str_replace('_', ' ', $pay['payment_method']));
                            ?>
                        </td>
                        <td>
                            <code class="small"><?= htmlspecialchars($pay['transaction_id'] ?? '—') ?></code>
                        </td>
                        <td>
                            <span class="sp sp-<?= $pay['payment_status'] ?>">
                                <?= ucfirst($pay['payment_status']) ?>
                            </span>
                        </td>
                        <td class="small text-nowrap text-muted">
                            <?= $pay['payment_date'] ? date('d M Y H:i', strtotime($pay['payment_date'])) : '—' ?>
                        </td>
                        <td class="small text-nowrap text-muted">
                            <?php if ($pay['refund_date']): ?>
                                <span class="text-purple"><?= date('d M Y H:i', strtotime($pay['refund_date'])) ?></span>
                                <?php if ($pay['refund_reason']): ?>
                                <br><span class="text-muted" style="font-size:.72rem;"><?= htmlspecialchars($pay['refund_reason']) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1 flex-nowrap">
                                <a href="booking_details.php?id=<?= $pay['booking_id'] ?>"
                                   class="btn btn-outline-secondary btn-sm py-0 px-2" title="View E-Ticket">
                                    <i class="bi bi-ticket-detailed"></i>
                                </a>
                                <?php if ($pay['payment_status'] === 'completed'): ?>
                                <button type="button"
                                        class="btn btn-outline-warning btn-sm py-0 px-2"
                                        title="Process Refund"
                                        data-bs-toggle="modal"
                                        data-bs-target="#refundModal"
                                        data-payid="<?= $pay['payment_id'] ?>"
                                        data-name="<?= htmlspecialchars($pay['full_name']) ?>"
                                        data-amount="Rs. <?= number_format($pay['amount'], 2) ?>"
                                        data-ref="<?= htmlspecialchars($pay['booking_reference']) ?>">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <small class="text-muted">
                Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $per_page, $total_rows)) ?>
                of <?= number_format($total_rows) ?>
            </small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(pUrl(['page' => $page - 1])) ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>
                    <?php for ($pp = max(1,$page-2); $pp <= min($total_pages,$page+2); $pp++): ?>
                    <li class="page-item <?= $pp === $page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(pUrl(['page' => $pp])) ?>"><?= $pp ?></a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(pUrl(['page' => $page + 1])) ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>

</main>
</div><!-- /adm-wrap -->

<!-- ── Refund Confirmation Modal ── -->
<div class="modal fade" id="refundModal" tabindex="-1" aria-labelledby="refundModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="action" value="refund">
                <input type="hidden" name="payment_id" id="modalPayId">
                <div class="modal-header bg-warning bg-opacity-10 border-bottom-0">
                    <h5 class="modal-title fw-bold" id="refundModalLabel">
                        <i class="bi bi-arrow-counterclockwise me-2 text-warning"></i>Process Refund
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">
                        You are processing a refund for:
                        <br><strong id="modalName"></strong> &nbsp;·&nbsp; Booking <strong id="modalRef"></strong>
                        <br>Amount: <strong class="text-success" id="modalAmount"></strong>
                    </p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Refund Reason</label>
                        <input type="text" name="refund_reason" class="form-control"
                               placeholder="e.g. Booking cancelled by admin"
                               maxlength="255" required>
                    </div>
                    <div class="alert alert-warning border-0 py-2 small">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        This action marks the payment as <strong>Refunded</strong> and cannot be undone.
                    </div>
                </div>
                <div class="modal-footer border-top-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning fw-bold">
                        <i class="bi bi-check-lg me-1"></i>Confirm Refund
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('refundModal');
    if (modal) {
        modal.addEventListener('show.bs.modal', function (e) {
            var btn = e.relatedTarget;
            document.getElementById('modalPayId').value  = btn.dataset.payid;
            document.getElementById('modalName').textContent   = btn.dataset.name;
            document.getElementById('modalRef').textContent    = btn.dataset.ref;
            document.getElementById('modalAmount').textContent = btn.dataset.amount;
        });
    }
}());
</script>

<?php require_once 'inc/footer.php'; ?>
