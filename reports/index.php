<?php
declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| SUVA HOTEL - PREMIUM REPORTS CENTER
| File: /suva_hotel/reports/index.php
| Database: PostgreSQL
|--------------------------------------------------------------------------
*/

ini_set('display_errors', '0');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}


$role = strtolower(trim((string)($_SESSION['role'] ?? '')));

// Only Admin and Manager may view reports.
if (!in_array($role, ['admin', 'manager'], true)) {
    http_response_code(403);

    exit(
        '<!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Access Denied | Suva Hotel</title>
            <style>
                body {
                    margin: 0;
                    min-height: 100vh;
                    display: grid;
                    place-items: center;
                    background: #071b2b;
                    color: #f4f7fa;
                    font-family: Arial, sans-serif;
                }
                .card {
                    max-width: 420px;
                    margin: 20px;
                    padding: 35px;
                    text-align: center;
                    border: 1px solid #d4af37;
                    border-radius: 18px;
                    background: #0b2639;
                }
                h1 { color: #f2d77b; }
                a { color: #f2d77b; }
            </style>
        </head>
        <body>
            <div class="card">
                <h1>🔒 Access Denied</h1>
                <p>This Reports Center is private.</p>
                <p>Only Admin or Manager accounts can access it.</p>
                <a href="../staff_dashboard/staff_dashboard.php">
                    Return to Dashboard
                </a>
            </div>
        </body>
        </html>'
    );
}


require_once __DIR__ . '/../config/database.php';

$db = $pgConnection ?? null;
if (!$db) {
    http_response_code(500);
    exit('Database connection is unavailable. Please check config/database.php.');
}

/* --------------------------- Helper functions --------------------------- */

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money($value): string
{
    return 'Rs. ' . number_format((float)$value, 2);
}

function tableExists($db, string $table): bool
{
    $result = @pg_query_params(
        $db,
        'SELECT EXISTS (
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = current_schema() AND table_name = $1
        ) AS found',
        [$table]
    );

    return $result && pg_fetch_result($result, 0, 'found') === 't';
}

function columnExists($db, string $table, string $column): bool
{
    $result = @pg_query_params(
        $db,
        'SELECT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = $1 AND column_name = $2
        ) AS found',
        [$table, $column]
    );

    return $result && pg_fetch_result($result, 0, 'found') === 't';
}

function queryValue($db, string $sql, array $params = [], $fallback = 0)
{
    $result = $params
        ? @pg_query_params($db, $sql, $params)
        : @pg_query($db, $sql);

    if (!$result || pg_num_rows($result) < 1) {
        return $fallback;
    }

    $value = pg_fetch_result($result, 0, 0);
    return ($value === false || $value === null || $value === '') ? $fallback : $value;
}

function chartJson($value): string
{
    return json_encode(
        $value,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ) ?: '[]';
}

/* ------------------------------ Date range ------------------------------ */

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$nextMonthStart = date('Y-m-01', strtotime('+1 month'));
$monthLabel = date('F Y');

/* -------------------------- Detect existing tables ---------------------- */

$hasBilling = tableExists($db, 'billing');
$hasBookings = tableExists($db, 'bookings');
$hasPayments = tableExists($db, 'payments');
$hasRooms = tableExists($db, 'rooms');
$hasRestaurantOrders = tableExists($db, 'restaurant_orders');
$hasSwimming = tableExists($db, 'swimming');
$hasExpenses = tableExists($db, 'expenses');
$hasProducts = tableExists($db, 'products');

/* ------------------------------- KPI values ----------------------------- */

$totalRevenue = 0.0;
$monthlyRevenue = 0.0;
$totalBookings = 0;
$monthlyBookings = 0;
$totalRooms = 0;
$occupiedRooms = 0;
$availableRooms = 0;
$paymentTotal = 0.0;
$restaurantOrders = 0;
$restaurantRevenue = 0.0;
$swimmingVisitors = 0;
$swimmingRevenue = 0.0;
$monthlyExpenses = 0.0;
$lowStock = 0;

if ($hasBilling && columnExists($db, 'billing', 'total_amount')) {
    $statusFilter = columnExists($db, 'billing', 'status')
        ? " AND LOWER(COALESCE(status, '')) NOT IN ('cancelled', 'canceled')"
        : '';

    $totalRevenue = (float)queryValue(
        $db,
        "SELECT COALESCE(SUM(total_amount), 0) FROM billing WHERE 1=1 $statusFilter"
    );

    if (columnExists($db, 'billing', 'billing_date')) {
        $monthlyRevenue = (float)queryValue(
            $db,
            "SELECT COALESCE(SUM(total_amount), 0)
             FROM billing
             WHERE billing_date >= $1 AND billing_date < $2 $statusFilter",
            [$monthStart, $nextMonthStart]
        );
    }
}

if ($hasBookings) {
    $totalBookings = (int)queryValue($db, 'SELECT COUNT(*) FROM bookings');

    if (columnExists($db, 'bookings', 'check_in')) {
        $monthlyBookings = (int)queryValue(
            $db,
            'SELECT COUNT(*) FROM bookings WHERE check_in >= $1 AND check_in < $2',
            [$monthStart, $nextMonthStart]
        );
    }
}

if ($hasRooms) {
    $totalRooms = (int)queryValue($db, 'SELECT COUNT(*) FROM rooms');

    if (columnExists($db, 'rooms', 'status')) {
        $occupiedRooms = (int)queryValue(
            $db,
            "SELECT COUNT(*) FROM rooms WHERE LOWER(TRIM(COALESCE(status, ''))) IN ('occupied', 'booked', 'in use')"
        );
        $availableRooms = (int)queryValue(
            $db,
            "SELECT COUNT(*) FROM rooms WHERE LOWER(TRIM(COALESCE(status, ''))) IN ('available', 'vacant', 'free')"
        );
    }
}

if ($hasPayments && columnExists($db, 'payments', 'amount')) {
    $paymentFilter = columnExists($db, 'payments', 'payment_status')
        ? " WHERE LOWER(COALESCE(payment_status, '')) NOT IN ('cancelled', 'canceled', 'pending', 'failed', 'unpaid')"
        : '';

    $paymentTotal = (float)queryValue(
        $db,
        "SELECT COALESCE(SUM(amount), 0) FROM payments $paymentFilter"
    );
}

if ($hasRestaurantOrders) {
    $restaurantOrders = (int)queryValue($db, 'SELECT COUNT(*) FROM restaurant_orders');

    $restaurantAmountColumn = null;
    foreach (['total_amount', 'total', 'amount', 'grand_total'] as $candidate) {
        if (columnExists($db, 'restaurant_orders', $candidate)) {
            $restaurantAmountColumn = $candidate;
            break;
        }
    }

    if ($restaurantAmountColumn !== null) {
        $restaurantRevenue = (float)queryValue(
            $db,
            'SELECT COALESCE(SUM(' . $restaurantAmountColumn . '), 0) FROM restaurant_orders'
        );
    }
}

if ($hasSwimming) {
    $swimmingVisitors = (int)queryValue($db, 'SELECT COUNT(*) FROM swimming');

    foreach (['total_amount', 'amount', 'price', 'fee'] as $candidate) {
        if (columnExists($db, 'swimming', $candidate)) {
            $swimmingRevenue = (float)queryValue(
                $db,
                'SELECT COALESCE(SUM(' . $candidate . '), 0) FROM swimming'
            );
            break;
        }
    }
}

if ($hasExpenses && columnExists($db, 'expenses', 'amount')) {
    if (columnExists($db, 'expenses', 'expense_date')) {
        $monthlyExpenses = (float)queryValue(
            $db,
            'SELECT COALESCE(SUM(amount), 0)
             FROM expenses WHERE expense_date >= $1 AND expense_date < $2',
            [$monthStart, $nextMonthStart]
        );
    } else {
        $monthlyExpenses = (float)queryValue(
            $db,
            'SELECT COALESCE(SUM(amount), 0) FROM expenses'
        );
    }
}

if ($hasProducts && columnExists($db, 'products', 'stock')) {
    $lowStock = (int)queryValue(
        $db,
        'SELECT COUNT(*) FROM products WHERE COALESCE(stock, 0) <= 5'
    );
}

$monthlyNet = $monthlyRevenue - $monthlyExpenses;
$occupancyRate = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100, 1) : 0;
$totalOperationalRevenue = $totalRevenue + $restaurantRevenue + $swimmingRevenue;

/* ------------------------------- Chart data ----------------------------- */

// Six months of billing revenue, including months with no billing records.
$chartMonths = [];
$chartRevenue = [];

if ($hasBilling && columnExists($db, 'billing', 'billing_date')
    && columnExists($db, 'billing', 'total_amount')) {
    $billingStatusJoin = columnExists($db, 'billing', 'status')
        ? " AND LOWER(COALESCE(b.status, '')) NOT IN ('cancelled', 'canceled')"
        : '';

    $chartResult = @pg_query(
        $db,
        "SELECT TO_CHAR(m.month_start, 'Mon YY') AS month_label,
                COALESCE(SUM(b.total_amount), 0) AS revenue
         FROM generate_series(
             date_trunc('month', CURRENT_DATE) - INTERVAL '5 months',
             date_trunc('month', CURRENT_DATE),
             INTERVAL '1 month'
         ) AS m(month_start)
         LEFT JOIN billing b
           ON b.billing_date >= m.month_start
          AND b.billing_date < m.month_start + INTERVAL '1 month'
          $billingStatusJoin
         GROUP BY m.month_start
         ORDER BY m.month_start"
    );

    if ($chartResult) {
        while ($row = pg_fetch_assoc($chartResult)) {
            $chartMonths[] = $row['month_label'];
            $chartRevenue[] = (float)$row['revenue'];
        }
    }
}

$bookingStatusLabels = [];
$bookingStatusValues = [];
if ($hasBookings && columnExists($db, 'bookings', 'status')) {
    $result = @pg_query(
        $db,
        "SELECT COALESCE(NULLIF(TRIM(status), ''), 'Unspecified') AS label,
                COUNT(*) AS total
         FROM bookings GROUP BY label ORDER BY total DESC"
    );
    if ($result) {
        while ($row = pg_fetch_assoc($result)) {
            $bookingStatusLabels[] = $row['label'];
            $bookingStatusValues[] = (int)$row['total'];
        }
    }
}

$roomStatusLabels = [];
$roomStatusValues = [];
if ($hasRooms && columnExists($db, 'rooms', 'status')) {
    $result = @pg_query(
        $db,
        "SELECT COALESCE(NULLIF(TRIM(status), ''), 'Unspecified') AS label,
                COUNT(*) AS total
         FROM rooms GROUP BY label ORDER BY total DESC"
    );
    if ($result) {
        while ($row = pg_fetch_assoc($result)) {
            $roomStatusLabels[] = $row['label'];
            $roomStatusValues[] = (int)$row['total'];
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Suva Hotel | Premium Reports</title>
<meta name="theme-color" content="#071b2b">
<style>
:root {
    --navy: #071b2b;
    --navy-2: #0b2639;
    --panel: rgba(10, 34, 51, .91);
    --gold: #d4af37;
    --gold-light: #f2d77b;
    --text: #f4f7fa;
    --muted: #9eb0be;
    --border: rgba(212,175,55,.18);
    --green: #32d39b;
    --blue: #5daaff;
    --red: #ff7587;
    --shadow: 0 18px 48px rgba(0,0,0,.26);
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    min-height: 100vh;
    color: var(--text);
    font-family: Inter, "Segoe UI", Arial, sans-serif;
    background:
        radial-gradient(ellipse at 15% 0%, rgba(28,83,111,.30), transparent 45%),
        radial-gradient(ellipse at 90% 20%, rgba(212,175,55,.09), transparent 34%),
        linear-gradient(135deg, #04111d, var(--navy) 52%, #091f30);
    background-attachment: fixed;
}
a { color: inherit; text-decoration: none; }
button, a { -webkit-tap-highlight-color: transparent; }
.page { width: min(1450px, 100%); margin: auto; padding: 28px clamp(15px, 3vw, 42px) 42px; }
.topbar {
    display: flex; justify-content: space-between; align-items: center;
    gap: 18px; flex-wrap: wrap; margin-bottom: 28px;
}
.brand { display: flex; align-items: center; gap: 13px; }
.brand-mark {
    display: grid; place-items: center; width: 49px; height: 49px;
    border: 1px solid rgba(242,215,123,.55); border-radius: 15px;
    color: var(--gold-light); font-size: 23px;
    background: linear-gradient(145deg, rgba(212,175,55,.17), rgba(212,175,55,.03));
    box-shadow: 0 0 28px rgba(212,175,55,.09);
}
.brand h1 { font-size: clamp(19px, 2.5vw, 25px); letter-spacing: 1.8px; }
.brand p { color: var(--muted); font-size: 11px; letter-spacing: 2px; margin-top: 5px; text-transform: uppercase; }
.top-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    padding: 11px 15px; border-radius: 11px; font-weight: 650; font-size: 12px;
    border: 1px solid var(--border); transition: .25s ease; cursor: pointer;
}
.btn:hover { transform: translateY(-2px); border-color: rgba(242,215,123,.6); }
.btn-gold { background: linear-gradient(135deg, var(--gold-light), var(--gold)); color: #17202a; border: 0; }
.btn-ghost { background: rgba(255,255,255,.035); color: #e8eef3; }
.hero {
    position: relative; overflow: hidden; padding: clamp(24px, 4vw, 43px);
    border: 1px solid var(--border); border-radius: 23px;
    background: linear-gradient(115deg, rgba(12,42,61,.97), rgba(7,27,43,.89));
    box-shadow: var(--shadow); margin-bottom: 25px;
}
.hero:after {
    content: ""; position: absolute; width: 260px; height: 260px; right: -75px; top: -115px;
    border: 1px solid rgba(212,175,55,.18); border-radius: 50%;
    box-shadow: 0 0 0 25px rgba(212,175,55,.025), 0 0 0 55px rgba(212,175,55,.02);
    pointer-events: none;
}
.eyebrow { color: var(--gold-light); font-size: 11px; letter-spacing: 2.5px; font-weight: 800; text-transform: uppercase; margin-bottom: 12px; }
.hero h2 { font-size: clamp(27px, 4vw, 43px); line-height: 1.13; max-width: 720px; }
.hero h2 span { color: var(--gold-light); }
.hero p { color: #b3c2ce; font-size: 14px; line-height: 1.7; max-width: 670px; margin-top: 13px; }
.hero-bottom { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px; margin-top: 25px; }
.live-pill { display: inline-flex; align-items: center; gap: 8px; color: #b8f4df; font-size: 11px; letter-spacing: .6px; }
.live-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--green); box-shadow: 0 0 12px var(--green); animation: pulse 1.8s infinite; }
.date-pill { border: 1px solid var(--border); border-radius: 10px; padding: 10px 13px; color: var(--gold-light); font-size: 12px; background: rgba(212,175,55,.055); }
.section-heading { display: flex; justify-content: space-between; align-items: end; gap: 15px; flex-wrap: wrap; margin: 29px 0 16px; }
.section-heading h2 { font-size: 20px; letter-spacing: .2px; }
.section-heading p { color: var(--muted); font-size: 12px; margin-top: 6px; }
.section-note { font-size: 11px; color: var(--gold-light); border: 1px solid var(--border); background: rgba(212,175,55,.06); border-radius: 20px; padding: 8px 12px; }
.stats-grid { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 15px; }
.stat-card {
    position: relative; overflow: hidden; min-width: 0; padding: 21px;
    background: var(--panel); border: 1px solid var(--border); border-radius: 17px;
    box-shadow: 0 12px 35px rgba(0,0,0,.17); animation: enter .65s both;
    transition: transform .28s, border-color .28s, box-shadow .28s;
}
.stat-card:hover { transform: translateY(-5px); border-color: rgba(242,215,123,.43); box-shadow: 0 18px 40px rgba(0,0,0,.28); }
.stat-card:nth-child(2) { animation-delay: .07s; }
.stat-card:nth-child(3) { animation-delay: .14s; }
.stat-card:nth-child(4) { animation-delay: .21s; }
.stat-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.stat-label { color: #b0c0cc; font-size: 12px; line-height: 1.5; }
.stat-icon { width: 39px; height: 39px; flex: 0 0 auto; display: grid; place-items: center; border-radius: 12px; font-size: 19px; background: rgba(212,175,55,.10); color: var(--gold-light); }
.stat-value { margin-top: 18px; font-size: clamp(21px, 2.2vw, 30px); font-weight: 750; letter-spacing: -.6px; overflow-wrap: anywhere; }
.stat-foot { display: flex; align-items: center; gap: 7px; color: var(--muted); font-size: 11px; margin-top: 10px; }
.stat-foot strong { color: var(--green); font-weight: 700; }
.analytics-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 17px; }
.analytics-card { min-width: 0; padding: 22px; border-radius: 18px; background: var(--panel); border: 1px solid var(--border); box-shadow: var(--shadow); animation: enter .7s both; transition: border-color .25s, transform .25s; }
.analytics-card:hover { border-color: rgba(212,175,55,.38); transform: translateY(-3px); }
.analytics-card:nth-child(2) { animation-delay: .08s; }
.analytics-card:nth-child(3) { animation-delay: .16s; }
.analytics-card:nth-child(4) { animation-delay: .24s; }
.analytics-card-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 19px; }
.analytics-card-header h3 { font-size: 15px; }
.analytics-card-header p { margin-top: 6px; color: var(--muted); font-size: 11px; line-height: 1.5; }
.analytics-icon { display: grid; place-items: center; width: 41px; height: 41px; flex-shrink: 0; border-radius: 12px; color: var(--gold-light); background: rgba(212,175,55,.10); border: 1px solid rgba(212,175,55,.16); font-size: 16px; font-weight: 750; }
.chart-wrap { position: relative; height: 275px; }
.chart-empty { display: none; padding: 15px 0; color: var(--muted); font-size: 12px; }
.overview-list { display: grid; gap: 18px; padding: 4px 0; }
.overview-row { display: grid; grid-template-columns: 115px 1fr 45px; gap: 12px; align-items: center; font-size: 12px; }
.overview-row span:first-child { color: #b5c5d0; }
.track { height: 8px; background: rgba(255,255,255,.07); border-radius: 99px; overflow: hidden; }
.track-fill { height: 100%; border-radius: inherit; background: linear-gradient(90deg, #9d7a16, var(--gold-light)); width: 0; transition: width 1.1s ease; }
.overview-row strong { text-align: right; font-size: 12px; }
.insight-grid { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 15px; margin-top: 17px; }
.insight { padding: 19px; border: 1px solid var(--border); border-radius: 16px; background: rgba(10,34,51,.76); }
.insight .label { color: var(--muted); font-size: 11px; }
.insight .value { font-size: 21px; font-weight: 750; margin-top: 9px; }
.insight .sub { font-size: 11px; color: var(--muted); margin-top: 7px; line-height: 1.5; }
.quick-grid { display: grid; grid-template-columns: repeat(4,minmax(0,1fr)); gap: 12px; margin-top: 17px; }
.quick-link { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px; border: 1px solid var(--border); border-radius: 13px; background: rgba(255,255,255,.025); font-size: 12px; transition: .25s; }
.quick-link:hover { background: rgba(212,175,55,.07); border-color: rgba(212,175,55,.45); transform: translateY(-2px); }
.quick-link span:first-child { display: flex; align-items: center; gap: 9px; }
.quick-link .arrow { color: var(--gold-light); font-size: 17px; }
.footer { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-top: 32px; padding-top: 18px; border-top: 1px solid rgba(255,255,255,.08); color: #7f95a5; font-size: 11px; }
.footer strong { color: var(--gold-light); letter-spacing: 1px; }
@keyframes enter { from { opacity: 0; transform: translateY(13px); } to { opacity: 1; transform: translateY(0); } }
@keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: .42; } }
@media (max-width: 1050px) { .stats-grid { grid-template-columns: repeat(2,minmax(0,1fr)); } .quick-grid { grid-template-columns: repeat(2,minmax(0,1fr)); } }
@media (max-width: 760px) { .analytics-grid { grid-template-columns: 1fr; } .insight-grid { grid-template-columns: 1fr; } .hero { padding: 25px; } }
@media (max-width: 480px) { .page { padding: 17px 12px 28px; } .stats-grid { gap: 10px; } .stat-card { padding: 15px; } .stat-value { font-size: 21px; } .stat-icon { width: 33px; height: 33px; } .analytics-card { padding: 16px; } .chart-wrap { height: 245px; } .quick-grid { grid-template-columns: 1fr; } .overview-row { grid-template-columns: 90px 1fr 35px; gap: 8px; } }
@media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .01ms !important; animation-iteration-count: 1 !important; scroll-behavior: auto !important; transition-duration: .01ms !important; } }
</style>
</head>
<body>
<div class="page">
    <header class="topbar">
        <div class="brand">
            <div class="brand-mark">✦</div>
            <div>
                <h1>SUVA HOTEL</h1>
                <p>Management &amp; Analytics</p>
            </div>
        </div>
        <nav class="top-actions" aria-label="Main navigation">
            <a class="btn btn-ghost" href="../dashboard.php">← Dashboard</a>
            <a class="btn btn-gold" href="monthly.php">Monthly Report ↗</a>
        </nav>
    </header>

    <section class="hero">
        <div class="eyebrow">✦ Executive Report Center</div>
        <h2>Clarity for every decision.<br><span>Performance at a glance.</span></h2>
        <p>Welcome to your hotel analytics center. Review billing revenue, bookings, room status and operational performance from one elegant dashboard.</p>
        <div class="hero-bottom">
            <div class="live-pill"><span class="live-dot"></span> CONNECTED TO HOTEL DATABASE</div>
            <div class="date-pill">◷ &nbsp; <?= e(date('l, d F Y')) ?></div>
        </div>
    </section>

    <div class="section-heading">
        <div>
            <h2>Performance Overview</h2>
            <p>Key figures calculated from the records currently in your database.</p>
        </div>
        <span class="section-note">MONTHLY PERIOD: <?= e($monthLabel) ?></span>
    </div>

    <section class="stats-grid" aria-label="Hotel statistics">
        <article class="stat-card">
            <div class="stat-top"><div class="stat-label">Total Billing Revenue</div><div class="stat-icon">Rs</div></div>
            <div class="stat-value"><?= e(money($totalRevenue)) ?></div>
            <div class="stat-foot"><strong>●</strong> Non-cancelled billing records</div>
        </article>
        <article class="stat-card">
            <div class="stat-top"><div class="stat-label">This Month's Revenue</div><div class="stat-icon">↗</div></div>
            <div class="stat-value"><?= e(money($monthlyRevenue)) ?></div>
            <div class="stat-foot"><strong><?= e($monthLabel) ?></strong> billing total</div>
        </article>
        <article class="stat-card">
            <div class="stat-top"><div class="stat-label">Total Bookings</div><div class="stat-icon">▤</div></div>
            <div class="stat-value"><?= number_format($totalBookings) ?></div>
            <div class="stat-foot"><strong><?= number_format($monthlyBookings) ?></strong> check-ins scheduled this month</div>
        </article>
        <article class="stat-card">
            <div class="stat-top"><div class="stat-label">Room Occupancy</div><div class="stat-icon">⌂</div></div>
            <div class="stat-value"><?= e(number_format($occupancyRate, 1)) ?>%</div>
            <div class="stat-foot"><?= number_format($occupiedRooms) ?> occupied / <?= number_format($totalRooms) ?> total rooms</div>
        </article>
    </section>

    <div class="section-heading">
        <div><h2>Performance Analytics</h2><p>Visual trends to help you understand your hotel's activity.</p></div>
        <span class="section-note">INTERACTIVE CHARTS</span>
    </div>

    <section class="analytics-grid">
        <article class="analytics-card">
            <div class="analytics-card-header">
                <div><h3>Revenue Growth</h3><p>Billing revenue over the last six months</p></div>
                <div class="analytics-icon">Rs.</div>
            </div>
            <div class="chart-wrap"><canvas id="revenueGraph" aria-label="Revenue over six months"></canvas></div>
            <p id="revenueEmpty" class="chart-empty">Revenue chart is unavailable. Check billing table fields and database connection.</p>
        </article>
        <article class="analytics-card">
            <div class="analytics-card-header">
                <div><h3>Booking Status</h3><p>How current bookings are distributed</p></div>
                <div class="analytics-icon">◉</div>
            </div>
            <div class="chart-wrap"><canvas id="bookingGraph" aria-label="Booking status distribution"></canvas></div>
            <p id="bookingEmpty" class="chart-empty">No booking status data is available yet.</p>
        </article>
        <article class="analytics-card">
            <div class="analytics-card-header">
                <div><h3>Room Status</h3><p>Current room status breakdown</p></div>
                <div class="analytics-icon">⌂</div>
            </div>
            <div class="chart-wrap"><canvas id="roomGraph" aria-label="Room status distribution"></canvas></div>
            <p id="roomEmpty" class="chart-empty">No room status data is available yet.</p>
        </article>
        <article class="analytics-card">
            <div class="analytics-card-header">
                <div><h3>Bookings &amp; Inventory</h3><p>Bookings compared with room inventory</p></div>
                <div class="analytics-icon">▥</div>
            </div>
            <div class="chart-wrap"><canvas id="overviewGraph" aria-label="Booking and room counts"></canvas></div>
        </article>
    </section>

    <div class="section-heading">
        <div><h2>Monthly Business Snapshot</h2><p>Operational figures for <?= e($monthLabel) ?>.</p></div>
        <span class="section-note">MONTH-TO-DATE</span>
    </div>

    <section class="insight-grid">
        <article class="insight">
            <div class="label">Monthly Net Estimate</div>
            <div class="value"><?= e(money($monthlyNet)) ?></div>
            <div class="sub">Billing revenue minus recorded expenses. This is an estimate, not audited profit.</div>
        </article>
        <article class="insight">
            <div class="label">Payments Recorded</div>
            <div class="value"><?= e(money($paymentTotal)) ?></div>
            <div class="sub">Sum of payments excluding pending, failed and cancelled statuses when status is available.</div>
        </article>
        <article class="insight">
            <div class="label">Other Operations</div>
            <div class="value"><?= e(money($restaurantRevenue + $swimmingRevenue)) ?></div>
            <div class="sub"><?= number_format($restaurantOrders) ?> restaurant orders · <?= number_format($swimmingVisitors) ?> swimming records</div>
        </article>
    </section>

    <div class="section-heading">
        <div><h2>Room &amp; Operations Summary</h2><p>A quick view of room utilization and operational records.</p></div>
    </div>
    <section class="analytics-card">
        <div class="overview-list">
            <?php
            $overviewRows = [
                ['Occupied rooms', $occupiedRooms, $totalRooms, '#d4af37'],
                ['Available rooms', $availableRooms, $totalRooms, '#32d39b'],
                ['Monthly bookings', $monthlyBookings, max($totalBookings, 1), '#5daaff'],
            ];
            foreach ($overviewRows as $item):
                $percent = $item[2] > 0 ? min(100, max(0, ($item[1] / $item[2]) * 100)) : 0;
            ?>
                <div class="overview-row">
                    <span><?= e($item[0]) ?></span>
                    <div class="track"><div class="track-fill" style="width: <?= e(number_format($percent, 2, '.', '')) ?>%; background: <?= e($item[3]) ?>;"></div></div>
                    <strong><?= number_format((int)$item[1]) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="insight-grid">
        <article class="insight">
            <div class="label">Restaurant Orders</div>
            <div class="value"><?= number_format($restaurantOrders) ?></div>
            <div class="sub">Recorded restaurant orders, if the table exists.</div>
        </article>
        <article class="insight">
            <div class="label">Swimming Records</div>
            <div class="value"><?= number_format($swimmingVisitors) ?></div>
            <div class="sub">Recorded swimming entries, if the table exists.</div>
        </article>
        <article class="insight">
            <div class="label">Low-Stock Products</div>
            <div class="value"><?= number_format($lowStock) ?></div>
            <div class="sub">Products with stock at or below five, if stock tracking is available.</div>
        </article>
    </section>

    <div class="section-heading">
        <div><h2>Quick Access</h2><p>Jump to common hotel management pages.</p></div>
    </div>
    <nav class="quick-grid" aria-label="Quick access">
        <a class="quick-link" href="../bookings/view.php"><span>▤ &nbsp; Bookings</span><span class="arrow">↗</span></a>
        <a class="quick-link" href="../rooms/view.php"><span>⌂ &nbsp; Rooms</span><span class="arrow">↗</span></a>
        <a class="quick-link" href="../payment/view.php"><span>Rs &nbsp; Payments</span><span class="arrow">↗</span></a>
        <a class="quick-link" href="../inventory/view.php"><span>▦ &nbsp; Inventory</span><span class="arrow">↗</span></a>
        <a class="quick-link" href="../restaurant/view.php"><span>♨ &nbsp; Restaurant</span><span class="arrow">↗</span></a>
        <a class="quick-link" href="monthly.php"><span>▥ &nbsp; Monthly Report</span><span class="arrow">↗</span></a>
        <a class="quick-link" href="../dashboard.php"><span>⌂ &nbsp; Dashboard</span><span class="arrow">↗</span></a>
        <a class="quick-link" href="../logout.php" onclick="return confirm('Are you sure you want to log out?');"><span>⇥ &nbsp; Logout</span><span class="arrow">↗</span></a>
    </nav>

    <footer class="footer">
        <span>© <?= date('Y') ?> <strong>SUVA HOTEL</strong> · Premium Reports Center</span>
        <span>Reports reflect the records available in your database.</span>
    </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
    const revenueLabels = <?= chartJson($chartMonths) ?>;
    const revenueValues = <?= chartJson($chartRevenue) ?>;
    const bookingLabels = <?= chartJson($bookingStatusLabels) ?>;
    const bookingValues = <?= chartJson($bookingStatusValues) ?>;
    const roomLabels = <?= chartJson($roomStatusLabels) ?>;
    const roomValues = <?= chartJson($roomStatusValues) ?>;

    const chartPalette = [
        '#d4af37', '#5daaff', '#32d39b', '#b38aff',
        '#ffb452', '#ff7587', '#52d6d0', '#d78bbd'
    ];

    if (typeof Chart === 'undefined') {
        document.querySelectorAll('.chart-empty').forEach(function (el) {
            el.textContent = 'Charts could not load. Check your internet connection or Chart.js availability.';
            el.style.display = 'block';
        });
        return;
    }

    Chart.defaults.color = '#9eb0be';
    Chart.defaults.font.family = '"Segoe UI", Arial, sans-serif';
    Chart.defaults.plugins.tooltip.backgroundColor = '#061521';
    Chart.defaults.plugins.tooltip.titleColor = '#f2d77b';
    Chart.defaults.plugins.tooltip.bodyColor = '#ffffff';
    Chart.defaults.plugins.tooltip.padding = 12;
    Chart.defaults.plugins.tooltip.cornerRadius = 9;

    const revenueCanvas = document.getElementById('revenueGraph');
    if (revenueCanvas && revenueLabels.length) {
        new Chart(revenueCanvas, {
            type: 'line',
            data: {
                labels: revenueLabels,
                datasets: [{
                    label: 'Revenue (Rs.)',
                    data: revenueValues,
                    borderColor: '#d4af37',
                    backgroundColor: 'rgba(212,175,55,.13)',
                    fill: true,
                    tension: .38,
                    borderWidth: 3,
                    pointRadius: 4,
                    pointHoverRadius: 7,
                    pointBackgroundColor: '#f2d77b'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 1300, easing: 'easeOutQuart' },
                interaction: { intersect: false, mode: 'index' },
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(255,255,255,.065)' },
                        ticks: {
                            callback: function (value) {
                                return 'Rs. ' + Number(value).toLocaleString('en-IN');
                            }
                        }
                    },
                    x: { grid: { display: false } }
                }
            }
        });
    } else {
        document.getElementById('revenueEmpty').style.display = 'block';
    }

    function doughnutChart(canvasId, emptyId, labels, values, title) {
        const canvas = document.getElementById(canvasId);
        if (!canvas || !labels.length || !values.length) {
            document.getElementById(emptyId).style.display = 'block';
            return;
        }

        new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    label: title,
                    data: values,
                    backgroundColor: labels.map(function (_, index) {
                        return chartPalette[index % chartPalette.length];
                    }),
                    borderColor: '#0a2233',
                    borderWidth: 4,
                    hoverOffset: 9
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '66%',
                animation: { animateRotate: true, animateScale: true, duration: 1200 },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#c7d4df',
                            usePointStyle: true,
                            padding: 15,
                            boxWidth: 8
                        }
                    }
                }
            }
        });
    }

    doughnutChart('bookingGraph', 'bookingEmpty', bookingLabels, bookingValues, 'Bookings');
    doughnutChart('roomGraph', 'roomEmpty', roomLabels, roomValues, 'Rooms');

    const overviewCanvas = document.getElementById('overviewGraph');
    if (overviewCanvas) {
        new Chart(overviewCanvas, {
            type: 'bar',
            data: {
                labels: ['Bookings', 'Total Rooms', 'Available', 'Occupied'],
                datasets: [{
                    label: 'Count',
                    data: [
                        <?= (int)$totalBookings ?>,
                        <?= (int)$totalRooms ?>,
                        <?= (int)$availableRooms ?>,
                        <?= (int)$occupiedRooms ?>
                    ],
                    backgroundColor: ['#5daaff', '#d4af37', '#32d39b', '#b38aff'],
                    borderRadius: 7,
                    maxBarThickness: 48
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 1200, easing: 'easeOutQuart' },
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 },
                        grid: { color: 'rgba(255,255,255,.065)' }
                    },
                    x: { grid: { display: false } }
                }
            }
        });
    }
})();
</script>
</body>
</html>
