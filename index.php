<?php
require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Train.php';

$db = new Database();
$db->connect();

$train  = new Train($db);
$routes = $train->getAllRoutes();

$citiesQuery = "SELECT DISTINCT departure_city AS city FROM routes UNION SELECT DISTINCT arrival_city AS city FROM routes ORDER BY city";
$cities = $db->select($citiesQuery);
if (!$cities) $cities = [];

// Stats for trust bar
$total_trains   = (int)($db->selectRow("SELECT COUNT(*) AS c FROM trains WHERE status='active'")['c'] ?? 0);
$total_routes   = (int)($db->selectRow("SELECT COUNT(*) AS c FROM routes WHERE status='scheduled' AND journey_date >= CURDATE()")['c'] ?? 0);
$total_users    = (int)($db->selectRow("SELECT COUNT(*) AS c FROM users WHERE role='user'")['c'] ?? 0);
$total_bookings = (int)($db->selectRow("SELECT COUNT(*) AS c FROM bookings WHERE booking_status='confirmed'")['c'] ?? 0);

$pageTitle = 'Railway Management System – Book Tickets Online';
require_once 'inc/header.php';
?>

<style>
/* ═══════════════════════════════════════════════
   HERO
═══════════════════════════════════════════════ */
.ix-hero {
    background: linear-gradient(135deg, #0b1728 0%, #0f2040 40%, #1a3a6e 100%);
    color: #fff;
    padding: 80px 20px 160px;
    position: relative;
    overflow: hidden;
}
/* dot-grid texture */
.ix-hero::before {
    content: '';
    position: absolute; inset: 0;
    background-image: radial-gradient(rgba(255,255,255,.07) 1px, transparent 1px);
    background-size: 28px 28px;
    pointer-events: none;
}
/* wave bottom divider */
.ix-hero-wave {
    position: absolute; bottom: -2px; left: 0; right: 0;
    line-height: 0; overflow: hidden;
}
.ix-hero-wave svg { display: block; width: 100%; height: 80px; }

.ix-hero-inner {
    position: relative; z-index: 2;
    max-width: 1160px; margin: 0 auto;
    display: grid;
    grid-template-columns: 1fr 420px;
    gap: 3rem;
    align-items: center;
}
@media(max-width:900px){ .ix-hero-inner { grid-template-columns: 1fr; text-align: center; } }

.ix-hero-eyebrow {
    display: inline-flex; align-items: center; gap: .45rem;
    background: rgba(251,191,36,.15); border: 1px solid rgba(251,191,36,.35);
    border-radius: 999px; padding: .3rem .9rem;
    font-size: .78rem; font-weight: 700; letter-spacing: .07em;
    text-transform: uppercase; color: #fde68a;
    margin-bottom: 1.25rem;
    animation: fadeUp .5s ease both;
}
.ix-hero h1 {
    font-size: clamp(2.4rem, 4.5vw, 3.8rem);
    font-weight: 900; line-height: 1.08;
    letter-spacing: -.035em;
    animation: fadeUp .55s .08s ease both;
    margin-bottom: 1.1rem;
}
.ix-hero h1 em { font-style: normal; color: #fbbf24; }
.ix-hero-sub {
    font-size: clamp(.95rem, 1.8vw, 1.15rem);
    color: rgba(255,255,255,.7); max-width: 480px;
    line-height: 1.65; margin-bottom: 2rem;
    animation: fadeUp .55s .16s ease both;
}
@media(max-width:900px){ .ix-hero-sub { margin-left: auto; margin-right: auto; } }
.ix-hero-btns {
    display: flex; gap: .85rem; flex-wrap: wrap;
    animation: fadeUp .55s .24s ease both;
}
@media(max-width:900px){ .ix-hero-btns { justify-content: center; } }

.btn-primary-hero {
    background: #fbbf24; color: #1c1917; border: none;
    padding: .85rem 2rem; border-radius: 12px; font-weight: 800; font-size: .95rem;
    text-decoration: none; display: inline-flex; align-items: center; gap: .5rem;
    transition: transform .2s, box-shadow .2s;
    box-shadow: 0 5px 22px rgba(251,191,36,.45);
}
.btn-primary-hero:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(251,191,36,.55); color: #1c1917; }
.btn-outline-hero {
    background: rgba(255,255,255,.08); color: #fff;
    border: 1.5px solid rgba(255,255,255,.3);
    padding: .85rem 2rem; border-radius: 12px; font-weight: 600; font-size: .95rem;
    text-decoration: none; display: inline-flex; align-items: center; gap: .5rem;
    backdrop-filter: blur(6px);
    transition: background .2s, border-color .2s;
}
.btn-outline-hero:hover { background: rgba(255,255,255,.14); border-color: rgba(255,255,255,.7); color: #fff; }

/* Hero side card */
.ix-hero-card {
    background: rgba(255,255,255,.07);
    border: 1px solid rgba(255,255,255,.13);
    border-radius: 20px;
    backdrop-filter: blur(12px);
    padding: 1.75rem;
    animation: fadeUp .55s .32s ease both;
}
@media(max-width:900px){ .ix-hero-card { display: none; } }
.ix-hero-card .card-label {
    font-size: .72rem; font-weight: 700; letter-spacing: .08em;
    color: rgba(255,255,255,.5); text-transform: uppercase; margin-bottom: 1.1rem;
    display: flex; align-items: center; gap: .4rem;
}
.ix-hero-card .card-label::before { content: ''; width: 20px; height: 2px; background: #fbbf24; border-radius: 2px; }
.hc-stat {
    display: flex; align-items: center; gap: .9rem;
    padding: .9rem 1rem;
    border-radius: 12px;
    background: rgba(255,255,255,.06);
    margin-bottom: .6rem;
    border: 1px solid rgba(255,255,255,.08);
}
.hc-stat:last-child { margin-bottom: 0; }
.hc-stat .hc-ico {
    width: 42px; height: 42px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.25rem; flex-shrink: 0;
}
.hc-stat .hc-val { font-size: 1.35rem; font-weight: 800; color: #fff; line-height: 1; }
.hc-stat .hc-lbl { font-size: .75rem; color: rgba(255,255,255,.55); margin-top: .15rem; }
.ix-hero-train-bar {
    margin-top: 1.25rem; padding: .85rem 1rem;
    background: rgba(251,191,36,.1); border: 1px solid rgba(251,191,36,.25);
    border-radius: 12px;
    display: flex; align-items: center; gap: .65rem;
    font-size: .82rem; color: #fde68a; font-weight: 600;
}
.ix-hero-train-bar .train-anim {
    font-size: 1.5rem;
    animation: trainRide 2.5s ease-in-out infinite;
    display: inline-block;
}

@keyframes trainRide  { 0%,100%{transform:translateX(-4px)} 50%{transform:translateX(4px)} }
@keyframes fadeUp     { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }

/* ═══════════════════════════════════════════════
   STATS STRIP
═══════════════════════════════════════════════ */
.ix-stats {
    background: #fff;
    border-bottom: 1px solid #e5e7eb;
    padding: 1.5rem 20px;
}
.ix-stats-inner {
    max-width: 1000px; margin: 0 auto;
    display: flex; justify-content: center; flex-wrap: wrap; gap: 0;
}
.ix-stat-item {
    display: flex; align-items: center; gap: .75rem;
    padding: .5rem 2.5rem;
    border-right: 1px solid #e5e7eb;
}
.ix-stat-item:last-child { border-right: none; }
@media(max-width:600px){
    .ix-stat-item { border-right: none; padding: .5rem 1.5rem; }
}
.ix-stat-item .si-ico {
    width: 44px; height: 44px; border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem; flex-shrink: 0;
}
.ix-stat-item .si-val {
    font-size: 1.6rem; font-weight: 900; color: #0f172a; line-height: 1;
}
.ix-stat-item .si-lbl { font-size: .72rem; color: #6b7280; margin-top: .15rem; font-weight: 500; }

/* ═══════════════════════════════════════════════
   SEARCH CARD
═══════════════════════════════════════════════ */
.ix-search-band {
    background: #f1f5f9;
    padding: 0 20px 64px;
}
.ix-search-card {
    max-width: 960px; margin: -56px auto 0;
    background: #fff;
    border-radius: 22px;
    box-shadow: 0 16px 56px rgba(15,30,50,.13);
    padding: 2rem 2.25rem;
    position: relative; z-index: 10;
    border: 1px solid #e2e8f0;
}
.ix-search-card .sc-title {
    font-size: 1.05rem; font-weight: 700; color: #0f172a;
    margin-bottom: 1.4rem; display: flex; align-items: center; gap: .5rem;
}
.ix-search-card .sc-title span {
    background: #dbeafe; color: #1d4ed8;
    font-size: .72rem; font-weight: 600; letter-spacing: .05em;
    text-transform: uppercase; padding: .2em .75em; border-radius: 999px; margin-left: .4rem;
}
.ix-search-form {
    display: grid;
    grid-template-columns: 1fr auto 1fr 1fr auto;
    gap: .75rem; align-items: end;
}
@media(max-width:780px){
    .ix-search-form { grid-template-columns: 1fr 1fr; }
    .ix-search-swap { display: none !important; }
    .btn-search-ix  { grid-column: span 2; }
}
@media(max-width:420px){
    .ix-search-form { grid-template-columns: 1fr; }
    .btn-search-ix  { grid-column: span 1; }
}
.sf-group label {
    font-size: .76rem; font-weight: 700; color: #374151;
    display: flex; align-items: center; gap: .3rem;
    margin-bottom: .4rem;
    text-transform: uppercase; letter-spacing: .05em;
}
.sf-group label i { color: #6b7280; font-size: .85rem; }
.sf-group select,
.sf-group input[type="date"] {
    width: 100%;
    padding: .72rem 1rem;
    border: 1.5px solid #d1d5db;
    border-radius: 11px;
    font-size: .9rem; font-family: inherit;
    background: #f9fafb; color: #0f172a;
    transition: border-color .2s, box-shadow .2s, background .2s;
    appearance: auto;
}
.sf-group select:focus,
.sf-group input[type="date"]:focus {
    outline: none; border-color: #3b82f6;
    box-shadow: 0 0 0 3.5px rgba(59,130,246,.14);
    background: #fff;
}
/* swap button */
.ix-search-swap {
    width: 38px; height: 38px; border-radius: 50%;
    background: #f1f5f9; border: 1.5px solid #d1d5db;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: #64748b; font-size: 1.1rem;
    transition: background .2s, color .2s, transform .25s;
    align-self: end; margin-bottom: .15rem; flex-shrink: 0;
}
.ix-search-swap:hover { background: #2563eb; color: #fff; border-color: #2563eb; transform: rotate(180deg); }
.btn-search-ix {
    background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
    color: #fff; border: none; border-radius: 11px;
    padding: .78rem 1.9rem; font-weight: 700; font-size: .95rem;
    cursor: pointer; white-space: nowrap;
    display: flex; align-items: center; gap: .45rem;
    transition: opacity .2s, transform .15s;
    box-shadow: 0 4px 16px rgba(37,99,235,.38);
    align-self: end;
}
.btn-search-ix:hover { opacity: .9; transform: translateY(-1px); }

/* ═══════════════════════════════════════════════
   HOW IT WORKS
═══════════════════════════════════════════════ */
.ix-how {
    background: #f1f5f9; padding: 80px 20px;
}
.ix-section-badge {
    display: inline-flex; align-items: center; gap: .4rem;
    background: #dbeafe; color: #1d4ed8;
    font-size: .72rem; font-weight: 700; letter-spacing: .08em;
    text-transform: uppercase; padding: .3em 1em; border-radius: 999px;
    margin-bottom: .9rem;
}
.ix-section-head { text-align: center; margin-bottom: 3rem; }
.ix-section-head h2 {
    font-size: clamp(1.7rem, 3vw, 2.3rem);
    font-weight: 800; color: #0f172a; margin-bottom: .45rem;
}
.ix-section-head p { color: #64748b; font-size: .95rem; max-width: 480px; margin: 0 auto; }

.ix-steps {
    max-width: 860px; margin: 0 auto;
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 1.5rem; position: relative;
}
/* connector line */
.ix-steps::before {
    content: '';
    position: absolute; top: 28px; left: calc(16.66% + 1px); right: calc(16.66% + 1px);
    height: 2px;
    background: repeating-linear-gradient(90deg, #cbd5e1 0, #cbd5e1 6px, transparent 6px, transparent 14px);
    z-index: 0;
}
@media(max-width:600px){
    .ix-steps { grid-template-columns: 1fr; }
    .ix-steps::before { display: none; }
}
.ix-step {
    text-align: center; position: relative; z-index: 1;
    background: #fff; border-radius: 18px; padding: 2rem 1.5rem;
    border: 1.5px solid #e2e8f0;
    transition: box-shadow .25s, border-color .25s, transform .25s;
}
.ix-step:hover { border-color: #3b82f6; box-shadow: 0 8px 28px rgba(59,130,246,.1); transform: translateY(-3px); }
.ix-step-num {
    width: 52px; height: 52px; border-radius: 50%;
    background: linear-gradient(135deg, #2563eb, #1e40af);
    color: #fff; font-size: 1.2rem; font-weight: 800;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 1.2rem; box-shadow: 0 4px 16px rgba(37,99,235,.35);
}
.ix-step h3 { font-size: 1rem; font-weight: 700; color: #0f172a; margin-bottom: .4rem; }
.ix-step p  { font-size: .85rem; color: #64748b; line-height: 1.55; }
.ix-step .step-icon { font-size: 1.5rem; color: #3b82f6; margin-bottom: .75rem; }

/* ═══════════════════════════════════════════════
   ROUTES
═══════════════════════════════════════════════ */
.ix-routes-band { background: #fff; padding: 80px 20px; }
.ix-routes-grid {
    max-width: 1160px; margin: 0 auto;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
    gap: 1.35rem;
}
.ix-route-card {
    background: #fff; border-radius: 18px;
    border: 1.5px solid #e2e8f0;
    padding: 1.5rem;
    transition: transform .25s, box-shadow .25s, border-color .25s;
    display: flex; flex-direction: column;
}
.ix-route-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 14px 42px rgba(15,30,50,.1);
    border-color: #3b82f6;
}
.ix-rc-top {
    display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: .85rem;
}
.ix-rc-top .train-name { font-weight: 700; font-size: 1rem; color: #0f172a; }
.ix-rc-top .badges { display: flex; gap: .4rem; flex-wrap: wrap; }
.badge-num {
    font-size: .68rem; font-weight: 700;
    background: #dbeafe; color: #1d4ed8;
    padding: .2em .7em; border-radius: 999px;
}
.badge-date {
    font-size: .68rem; font-weight: 600;
    background: #f0fdf4; color: #15803d;
    padding: .2em .7em; border-radius: 999px;
}
.ix-rc-route {
    display: flex; align-items: center; gap: .4rem; margin-bottom: .95rem;
}
.ix-rc-route .city { font-weight: 800; font-size: 1.1rem; color: #0f172a; white-space: nowrap; }
.ix-rc-route .rc-connector {
    flex: 1; display: flex; align-items: center; gap: .2rem; min-width: 0;
}
.rc-connector .line { flex: 1; height: 1.5px; background: #e2e8f0; }
.rc-connector .dot  { width: 6px; height: 6px; border-radius: 50%; background: #3b82f6; flex-shrink: 0; }
.rc-connector .train-ic { color: #3b82f6; font-size: .85rem; flex-shrink: 0; }
.ix-rc-meta {
    display: grid; grid-template-columns: 1fr 1fr 1fr;
    gap: .4rem; background: #f8fafc; border-radius: 12px;
    padding: .8rem; margin-bottom: .95rem; text-align: center;
}
.ix-rc-meta .m-val { font-weight: 700; font-size: .88rem; color: #0f172a; }
.ix-rc-meta .m-lbl { font-size: .66rem; color: #94a3b8; margin-top: .1rem; letter-spacing: .02em; }
.ix-rc-meta .m-sep { width: 1px; background: #e2e8f0; }
.ix-rc-footer {
    display: flex; justify-content: space-between; align-items: center;
    padding-top: .8rem; border-top: 1px solid #f1f5f9; margin-top: auto;
}
.ix-rc-footer .fare { font-size: 1.35rem; font-weight: 900; color: #059669; line-height: 1; }
.ix-rc-footer .fare small { font-size: .68rem; color: #94a3b8; font-weight: 400; display: block; }
.seats-badge {
    font-size: .72rem; font-weight: 600;
    padding: .25em .8em; border-radius: 999px;
    display: inline-flex; align-items: center; gap: .3rem;
}
.seats-badge.high  { background: #dcfce7; color: #15803d; }
.seats-badge.mid   { background: #fef3c7; color: #b45309; }
.seats-badge.low   { background: #fee2e2; color: #dc2626; }
.btn-book-ix {
    display: block; width: 100%; margin-top: .85rem;
    padding: .72rem 1rem;
    background: linear-gradient(135deg, #2563eb, #1e40af);
    color: #fff; border: none; border-radius: 12px;
    font-weight: 700; font-size: .9rem; cursor: pointer;
    transition: opacity .2s, transform .15s;
    text-align: center; text-decoration: none;
    box-shadow: 0 3px 14px rgba(37,99,235,.3);
}
.btn-book-ix:hover { opacity: .87; transform: translateY(-1px); color: #fff; }
.ix-routes-more {
    text-align: center; margin-top: 2.5rem;
}
.btn-routes-all {
    display: inline-flex; align-items: center; gap: .45rem;
    background: #fff; border: 1.5px solid #d1d5db;
    color: #374151; font-weight: 600; font-size: .9rem;
    padding: .7rem 1.75rem; border-radius: 12px; text-decoration: none;
    transition: border-color .2s, color .2s, background .2s;
}
.btn-routes-all:hover { border-color: #3b82f6; color: #2563eb; background: #eff6ff; }

/* ═══════════════════════════════════════════════
   FEATURES
═══════════════════════════════════════════════ */
.ix-features-band { background: #f8fafc; padding: 80px 20px; }
.ix-features-grid {
    max-width: 1100px; margin: 0 auto;
    display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1.35rem;
}
.ix-feat {
    text-align: center; padding: 2rem 1.25rem;
    border-radius: 18px; border: 1.5px solid #e2e8f0;
    background: #fff;
    transition: border-color .2s, box-shadow .2s, transform .2s;
}
.ix-feat:hover { border-color: #3b82f6; box-shadow: 0 8px 28px rgba(59,130,246,.1); transform: translateY(-3px); }
.ix-feat .f-ico {
    width: 58px; height: 58px; border-radius: 15px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.5rem; margin: 0 auto 1.1rem;
}
.ix-feat h3 { font-size: .975rem; font-weight: 700; color: #0f172a; margin-bottom: .4rem; }
.ix-feat p  { font-size: .83rem; color: #6b7280; line-height: 1.55; }

/* ═══════════════════════════════════════════════
   CTA BANNER
═══════════════════════════════════════════════ */
.ix-cta-wave {
    line-height: 0; background: #f8fafc; overflow: hidden;
}
.ix-cta-wave svg { display: block; width: 100%; }
.ix-cta {
    background: linear-gradient(135deg, #0f2040 0%, #1e40af 100%);
    color: #fff; padding: 70px 20px 80px;
    position: relative; overflow: hidden;
}
.ix-cta::before {
    content: '';
    position: absolute; inset: 0;
    background-image: radial-gradient(rgba(255,255,255,.05) 1px, transparent 1px);
    background-size: 22px 22px;
}
.ix-cta-inner {
    position: relative; z-index: 1;
    max-width: 1100px; margin: 0 auto;
    display: grid; grid-template-columns: 1fr auto; align-items: center; gap: 3rem;
}
@media(max-width:700px){
    .ix-cta-inner { grid-template-columns: 1fr; text-align: center; }
    .ix-cta-side  { display: none; }
}
.ix-cta h2 { font-size: clamp(1.7rem, 3vw, 2.5rem); font-weight: 900; margin-bottom: .65rem; letter-spacing: -.02em; }
.ix-cta p  { color: rgba(255,255,255,.7); max-width: 480px; margin-bottom: 2rem; font-size: .975rem; line-height: 1.6; }
.ix-cta-btns { display: flex; gap: .85rem; flex-wrap: wrap; }
@media(max-width:700px){ .ix-cta-btns { justify-content: center; } }
.btn-cta-prim {
    display: inline-flex; align-items: center; gap: .5rem;
    background: #fbbf24; color: #1c1917; font-weight: 800; font-size: .95rem;
    padding: .85rem 2.2rem; border-radius: 12px; text-decoration: none;
    transition: transform .2s, box-shadow .2s;
    box-shadow: 0 4px 20px rgba(251,191,36,.45);
}
.btn-cta-prim:hover { transform: translateY(-2px); box-shadow: 0 10px 32px rgba(251,191,36,.55); color: #1c1917; }
.btn-cta-sec {
    display: inline-flex; align-items: center; gap: .5rem;
    background: rgba(255,255,255,.1); color: #fff; font-weight: 600; font-size: .95rem;
    padding: .85rem 2rem; border-radius: 12px; text-decoration: none;
    border: 1.5px solid rgba(255,255,255,.25);
    transition: background .2s, border-color .2s;
}
.btn-cta-sec:hover { background: rgba(255,255,255,.18); border-color: rgba(255,255,255,.6); color: #fff; }
.ix-cta-side {
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.14);
    border-radius: 20px;
    padding: 1.75rem 2rem;
    text-align: center; white-space: nowrap;
    backdrop-filter: blur(8px);
}
.ix-cta-side .big { font-size: 3rem; font-weight: 900; color: #fbbf24; line-height: 1; }
.ix-cta-side .sub { font-size: .82rem; color: rgba(255,255,255,.6); margin-top: .3rem; }
</style>

<!-- ═══════════════════════════════════════════════
     HERO
════════════════════════════════════════════════ -->
<section class="ix-hero">
    <div class="ix-hero-inner">
        <!-- Left: copy -->
        <div>
            <div class="ix-hero-eyebrow">
                <i class="bi bi-lightning-charge-fill"></i> Pakistan's No.1 Online Rail Booking
            </div>
            <h1>Travel Smarter,<br>Book <em>Faster</em>.</h1>
            <p class="ix-hero-sub">
                Search hundreds of routes, compare fares in real time,
                and reserve your seat in under 2 minutes — no queues, no counters.
            </p>
            <div class="ix-hero-btns">
                <a href="#search" class="btn-primary-hero">
                    <i class="bi bi-search"></i> Search Trains
                </a>
                <?php if (!User::isLoggedIn()): ?>
                <a href="signup.php" class="btn-outline-hero">
                    <i class="bi bi-person-plus"></i> Create Account
                </a>
                <?php else: ?>
                <a href="bookings.php" class="btn-outline-hero">
                    <i class="bi bi-ticket-perforated"></i> My Bookings
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right: live stat card -->
        <div class="ix-hero-card">
            <div class="card-label"><i class="bi bi-bar-chart-fill"></i> Live Platform Stats</div>
            <div class="hc-stat">
                <div class="hc-ico" style="background:rgba(59,130,246,.18);color:#60a5fa;">
                    <i class="bi bi-train-front-fill"></i>
                </div>
                <div>
                    <div class="hc-val"><?= number_format($total_trains) ?>+</div>
                    <div class="hc-lbl">Active Trains</div>
                </div>
            </div>
            <div class="hc-stat">
                <div class="hc-ico" style="background:rgba(34,197,94,.18);color:#4ade80;">
                    <i class="bi bi-map-fill"></i>
                </div>
                <div>
                    <div class="hc-val"><?= number_format($total_routes) ?>+</div>
                    <div class="hc-lbl">Scheduled Routes</div>
                </div>
            </div>
            <div class="hc-stat">
                <div class="hc-ico" style="background:rgba(251,191,36,.18);color:#fbbf24;">
                    <i class="bi bi-people-fill"></i>
                </div>
                <div>
                    <div class="hc-val"><?= number_format($total_users) ?>+</div>
                    <div class="hc-lbl">Registered Passengers</div>
                </div>
            </div>
            <div class="ix-hero-train-bar">
                <span class="train-anim"><i class="bi bi-train-front-fill"></i></span>
                <span><?= number_format($total_bookings) ?>+ confirmed bookings and counting</span>
            </div>
        </div>
    </div>

    <!-- wave divider -->
    <div class="ix-hero-wave">
        <svg viewBox="0 0 1440 80" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M0,40 C360,80 1080,0 1440,40 L1440,80 L0,80 Z" fill="#f1f5f9"/>
        </svg>
    </div>
</section>


<!-- ═══════════════════════════════════════════════
     SEARCH
════════════════════════════════════════════════ -->
<div class="ix-search-band" id="search">
    <div class="ix-search-card">
        <div class="sc-title">
            <i class="bi bi-search" style="color:#2563eb;"></i>
            Find Your Train
            <span>Instant Search</span>
        </div>
        <form method="GET" action="book.php" class="ix-search-form" id="searchForm">
            <div class="sf-group">
                <label><i class="bi bi-geo-alt"></i> From</label>
                <select name="departure_city" id="fromCity" required>
                    <option value="">Select departure city</option>
                    <?php foreach ($cities as $c): ?>
                    <option value="<?= htmlspecialchars($c['city']) ?>"><?= htmlspecialchars($c['city']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="button" class="ix-search-swap" id="swapCities" title="Swap cities" aria-label="Swap departure and arrival cities">
                <i class="bi bi-arrow-left-right"></i>
            </button>

            <div class="sf-group">
                <label><i class="bi bi-geo-alt-fill"></i> To</label>
                <select name="arrival_city" id="toCity" required>
                    <option value="">Select arrival city</option>
                    <?php foreach ($cities as $c): ?>
                    <option value="<?= htmlspecialchars($c['city']) ?>"><?= htmlspecialchars($c['city']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sf-group">
                <label><i class="bi bi-calendar3"></i> Travel Date</label>
                <input type="date" name="journey_date" required
                       min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
            </div>

            <button type="submit" class="btn-search-ix">
                <i class="bi bi-search"></i> Search
            </button>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════
     HOW IT WORKS
════════════════════════════════════════════════ -->
<div class="ix-how">
    <div class="ix-section-head">
        <div class="ix-section-badge"><i class="bi bi-info-circle"></i> Quick Guide</div>
        <h2>Book a Ticket in 3 Simple Steps</h2>
        <p>From search to boarding in minutes — no account needed to browse.</p>
    </div>
    <div class="ix-steps">
        <div class="ix-step">
            <div class="ix-step-num">1</div>
            <div class="step-icon"><i class="bi bi-search"></i></div>
            <h3>Search Routes</h3>
            <p>Enter your departure city, destination, and travel date to instantly see all available trains and fares.</p>
        </div>
        <div class="ix-step">
            <div class="ix-step-num">2</div>
            <div class="step-icon"><i class="bi bi-ticket-perforated"></i></div>
            <h3>Select &amp; Book</h3>
            <p>Pick the train that fits your schedule, choose your seats, and complete the secure online payment.</p>
        </div>
        <div class="ix-step">
            <div class="ix-step-num">3</div>
            <div class="step-icon"><i class="bi bi-qr-code"></i></div>
            <h3>Travel with E-Ticket</h3>
            <p>Receive your digital ticket instantly. Show it on any device at the station — no printing needed.</p>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════
     ROUTES
════════════════════════════════════════════════ -->
<div class="ix-routes-band">
    <div class="ix-section-head">
        <div class="ix-section-badge"><i class="bi bi-map"></i> Upcoming Trips</div>
        <h2>Popular Routes</h2>
        <p>Browse upcoming departures — click any card to book a seat instantly.</p>
    </div>
    <div class="ix-routes-grid">
        <?php if ($routes): ?>
            <?php foreach (array_slice($routes, 0, 6) as $route): ?>
            <?php
                $seats = (int)$route['available_seats'];
                if ($seats > 20)      { $seatClass = 'high'; $seatIcon = 'bi-check-circle'; }
                elseif ($seats > 10)  { $seatClass = 'mid';  $seatIcon = 'bi-exclamation-circle'; }
                else                  { $seatClass = 'low';  $seatIcon = 'bi-x-circle'; }
                $journeyDate = !empty($route['journey_date']) ? date('d M', strtotime($route['journey_date'])) : '';
            ?>
            <div class="ix-route-card">
                <div class="ix-rc-top">
                    <span class="train-name"><?= htmlspecialchars($route['train_name']) ?></span>
                    <div class="badges">
                        <span class="badge-num"><?= htmlspecialchars($route['train_number']) ?></span>
                        <?php if ($journeyDate): ?>
                        <span class="badge-date"><?= $journeyDate ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ix-rc-route">
                    <span class="city"><?= htmlspecialchars($route['departure_city']) ?></span>
                    <span class="rc-connector">
                        <span class="line"></span>
                        <span class="dot"></span>
                        <span class="train-ic"><i class="bi bi-train-front-fill"></i></span>
                        <span class="dot"></span>
                        <span class="line"></span>
                    </span>
                    <span class="city"><?= htmlspecialchars($route['arrival_city']) ?></span>
                </div>
                <div class="ix-rc-meta">
                    <div>
                        <div class="m-val"><?= date('H:i', strtotime($route['departure_time'])) ?></div>
                        <div class="m-lbl">Departs</div>
                    </div>
                    <div>
                        <div class="m-val"><?= number_format($route['distance_km'], 0) ?> km</div>
                        <div class="m-lbl">Distance</div>
                    </div>
                    <div>
                        <div class="m-val"><?= date('H:i', strtotime($route['arrival_time'])) ?></div>
                        <div class="m-lbl">Arrives</div>
                    </div>
                </div>
                <div class="ix-rc-footer">
                    <div class="fare">
                        Rs <?= number_format($route['base_fare'], 0) ?>
                        <small>per seat</small>
                    </div>
                    <span class="seats-badge <?= $seatClass ?>">
                        <i class="bi <?= $seatIcon ?>"></i> <?= $seats ?> left
                    </span>
                </div>
                <a href="book.php?route_id=<?= (int)$route['route_id'] ?>" class="btn-book-ix">
                    <i class="bi bi-ticket-perforated me-1"></i> Book Now
                </a>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="text-center text-muted py-5" style="grid-column:1/-1;">
                <i class="bi bi-train-front fs-1 d-block mb-3 opacity-25"></i>
                <strong>No routes currently available.</strong><br>
                <small>Check back soon for new departures.</small>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($routes && count($routes) > 6): ?>
    <div class="ix-routes-more">
        <a href="book.php" class="btn-routes-all">
            View All <?= count($routes) ?> Routes <i class="bi bi-arrow-right ms-1"></i>
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════
     FEATURES
════════════════════════════════════════════════ -->
<div class="ix-features-band">
    <div class="ix-section-head">
        <div class="ix-section-badge"><i class="bi bi-star-fill"></i> Why Us</div>
        <h2>Everything You Need for a Great Journey</h2>
        <p>Designed for speed, simplicity, and peace of mind at every step.</p>
    </div>
    <div class="ix-features-grid">
        <div class="ix-feat">
            <div class="f-ico" style="background:#dbeafe;">
                <i class="bi bi-lightning-charge-fill" style="color:#2563eb;font-size:1.4rem;"></i>
            </div>
            <h3>Instant Booking</h3>
            <p>Reserve any seat in under 2 minutes without visiting a ticket counter.</p>
        </div>
        <div class="ix-feat">
            <div class="f-ico" style="background:#dcfce7;">
                <i class="bi bi-shield-lock-fill" style="color:#16a34a;font-size:1.4rem;"></i>
            </div>
            <h3>Secure Payments</h3>
            <p>Multiple payment methods with end-to-end encrypted transactions.</p>
        </div>
        <div class="ix-feat">
            <div class="f-ico" style="background:#fef3c7;">
                <i class="bi bi-phone-fill" style="color:#d97706;font-size:1.4rem;"></i>
            </div>
            <h3>Digital E-Ticket</h3>
            <p>Paperless boarding — your ticket lives on your phone, always ready.</p>
        </div>
        <div class="ix-feat">
            <div class="f-ico" style="background:#ede9fe;">
                <i class="bi bi-arrow-counterclockwise" style="color:#7c3aed;font-size:1.4rem;"></i>
            </div>
            <h3>Easy Cancellation</h3>
            <p>Cancel or modify any booking with hassle-free instant refund processing.</p>
        </div>
        <div class="ix-feat">
            <div class="f-ico" style="background:#fee2e2;">
                <i class="bi bi-broadcast" style="color:#dc2626;font-size:1.4rem;"></i>
            </div>
            <h3>Live Updates</h3>
            <p>Real-time seat availability, train status, and delay notifications.</p>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════
     CTA
════════════════════════════════════════════════ -->
<?php if (!User::isLoggedIn()): ?>
<div class="ix-cta-wave">
    <svg viewBox="0 0 1440 50" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" height="50">
        <path d="M0,25 C360,50 1080,0 1440,25 L1440,50 L0,50 Z"
              fill="<?php /* matches cta background start */ ?>#0f2040"/>
    </svg>
</div>
<div class="ix-cta">
    <div class="ix-cta-inner">
        <div>
            <h2>Ready to Start Your Journey?</h2>
            <p>Create a free account today and get access to exclusive fares, booking history, and real-time notifications.</p>
            <div class="ix-cta-btns">
                <a href="signup.php" class="btn-cta-prim">
                    <i class="bi bi-person-plus-fill"></i> Get Started — It's Free
                </a>
                <a href="login.php" class="btn-cta-sec">
                    <i class="bi bi-box-arrow-in-right"></i> Sign In
                </a>
            </div>
        </div>
        <div class="ix-cta-side">
            <div class="big"><?= number_format($total_bookings) ?>+</div>
            <div class="sub">Tickets booked so far</div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Swap cities
document.getElementById('swapCities')?.addEventListener('click', function () {
    const from = document.getElementById('fromCity');
    const to   = document.getElementById('toCity');
    const tmp  = from.value;
    from.value = to.value;
    to.value   = tmp;
});

// Animated stat counters (Intersection Observer)
(function () {
    const items = document.querySelectorAll('.si-val[data-count]');
    if (!items.length) return;
    const fmt = n => n.toLocaleString('en-PK');
    const animate = (el, target) => {
        const dur = 900, start = performance.now();
        const tick = now => {
            const t = Math.min((now - start) / dur, 1);
            const ease = 1 - Math.pow(1 - t, 3);
            el.textContent = fmt(Math.round(ease * target));
            if (t < 1) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    };
    const obs = new IntersectionObserver(entries => {
        entries.forEach(e => {
            if (e.isIntersecting) {
                animate(e.target, parseInt(e.target.dataset.count, 10));
                obs.unobserve(e.target);
            }
        });
    }, { threshold: 0.4 });
    items.forEach(el => obs.observe(el));
})();
</script>

<?php require_once 'inc/footer.php'; ?>

