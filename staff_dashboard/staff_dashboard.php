
<?php
/**
 * ============================================================
 * SUVA HOTEL — LUXURY STAFF DASHBOARD
 * File: staff_dashboard/staff_dashboard.php
 * PHP 8+ | PostgreSQL | XAMPP
 *
 * Preserves the existing database connection and page links.
 * ============================================================
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('Asia/Kathmandu');

/* -------------------- LOGIN PROTECTION -------------------- */

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$loginUsername = (string) ($_SESSION['username'] ?? '');
$role = strtolower(trim((string) ($_SESSION['role'] ?? 'staff')));

$canViewReports = in_array($role, ['admin', 'manager'], true);

/* -------------------- DATABASE CONNECTION ----------------- */

require_once __DIR__ . '/../config/database.php';

$dbReady = isset($pgConnection) && $pgConnection;

/* -------------------- STAFF DISPLAY NAME ------------------ */
/* Prefer the staff member's real name from the users table.
   Never display the PostgreSQL database account as the staff name. */

$displayName = trim((string) ($_SESSION['full_name'] ?? ''));

if ($displayName === '' && $dbReady) {
    $userId = (int) $_SESSION['user_id'];
    $nameResult = @pg_query_params(
        $pgConnection,
        'SELECT full_name FROM users WHERE id = $1 LIMIT 1',
        [$userId]
    );

    if ($nameResult !== false) {
        $databaseName = trim((string) (pg_fetch_result($nameResult, 0, 0) ?: ''));
        if ($databaseName !== '') {
            $displayName = $databaseName;
        }
        pg_free_result($nameResult);
    }
}

if ($displayName === '') {
    $sessionName = trim((string) ($_SESSION['username'] ?? ''));
    $displayName = ($sessionName !== '' && strtolower($sessionName) !== 'postgres')
        ? $sessionName
        : 'Team Member';
}

function e($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

/**
 * Return a single PostgreSQL count.
 * If an optional statistic query fails, return zero.
 */
function dashboardCount(string $sql): int
{
    global $pgConnection, $dbReady;

    if (!$dbReady) {
        return 0;
    }

    $result = @pg_query($pgConnection, $sql);

    if ($result === false) {
        return 0;
    }

    $value = pg_fetch_result($result, 0, 0);
    pg_free_result($result);

    return max(0, (int) $value);
}

/* -------------------- LIVE STATISTICS --------------------- */

$totalRooms = dashboardCount(
    'SELECT COUNT(*) FROM rooms'
);

$availableRooms = dashboardCount(
    "SELECT COUNT(*) FROM rooms
     WHERE LOWER(status) = 'available'"
);

$occupiedRooms = dashboardCount(
    "SELECT COUNT(*) FROM rooms
     WHERE LOWER(status) = 'occupied'"
);

$maintenanceRooms = dashboardCount(
    "SELECT COUNT(*) FROM rooms
     WHERE LOWER(status) = 'maintenance'"
);

$todaysArrivals = dashboardCount(
    "SELECT COUNT(*) FROM bookings
     WHERE check_in::date = CURRENT_DATE
       AND LOWER(status) NOT IN ('cancelled', 'canceled')"
);

$activeStays = dashboardCount(
    "SELECT COUNT(*) FROM bookings
     WHERE LOWER(status) = 'checked in'"
);

$pendingOrders = dashboardCount(
    "SELECT COUNT(*) FROM restaurant_orders
     WHERE LOWER(order_status) IN
           ('pending', 'preparing', 'ready')"
);

$lowStockItems = dashboardCount(
    "SELECT COUNT(*) FROM products
     WHERE quantity <= minimum_stock"
);

/* -------------------- GREETING ---------------------------- */

$hour = (int) date('G');

if ($hour >= 5 && $hour < 12) {
    $greeting = 'Good morning';
} elseif ($hour >= 12 && $hour < 17) {
    $greeting = 'Good afternoon';
} elseif ($hour >= 17 && $hour < 21) {
    $greeting = 'Good evening';
} else {
    $greeting = 'Welcome back';
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <meta name="theme-color" content="#071c15">

    <title>Staff Dashboard | Suva Hotel</title>

    <style>
        :root {
            --bg: #07130f;
            --sidebar: #091b14;
            --panel: #10271d;
            --panel-light: #163528;
            --green: #1d7650;
            --green-bright: #41bd82;
            --gold: #d8b66a;
            --gold-light: #f2dca1;
            --white: #f6f5ed;
            --muted: #a7b8ac;
            --border: rgba(216,182,106,.18);
            --danger: #ff7777;
            --shadow: 0 16px 40px rgba(0,0,0,.20);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            min-height: 100vh;
            background:
                radial-gradient(
                    circle at 85% 0%,
                    rgba(29,118,80,.16),
                    transparent 35%
                ),
                var(--bg);
            color: var(--white);
            font-family: "Segoe UI", Arial, sans-serif;
            line-height: 1.55;
        }

        a {
            color: inherit;
        }

        button,
        a {
            -webkit-tap-highlight-color: transparent;
        }

        button:focus-visible,
        a:focus-visible {
            outline: 2px solid var(--gold-light);
            outline-offset: 4px;
        }

        /* SIDEBAR */

        .sidebar {
            position: fixed;
            inset: 0 auto 0 0;
            z-index: 1000;
            width: 258px;
            padding: 24px 16px;
            overflow-y: auto;
            background: linear-gradient(
                180deg,
                #0d291d,
                #07160f 85%
            );
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            transition: transform .3s ease;
        }

        .brand {
            text-align: center;
            padding: 4px 4px 24px;
            border-bottom: 1px solid var(--border);
        }

        .brand-logo {
            width: 65px;
            height: 65px;
            margin: 0 auto 10px;
            display: grid;
            place-items: center;
            border-radius: 20px;
            background: linear-gradient(
                145deg,
                #245b40,
                #102a1c
            );
            border: 1px solid var(--gold);
            box-shadow: 0 8px 24px rgba(0,0,0,.2);
            font-size: 34px;
        }

        .brand h2 {
            color: var(--gold-light);
            font-size: 20px;
            letter-spacing: 2px;
        }

        .brand p {
            color: var(--muted);
            font-size: 10px;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-top: 5px;
        }

        .menu-label {
            margin: 23px 10px 9px;
            color: var(--gold);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.8px;
            text-transform: uppercase;
        }

        .menu {
            display: grid;
            gap: 5px;
        }

        .menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 44px;
            padding: 11px 12px;
            text-decoration: none;
            color: #d4e0d7;
            font-size: 13px;
            border: 1px solid transparent;
            border-radius: 10px;
            transition:
                background .2s ease,
                color .2s ease,
                border-color .2s ease,
                transform .2s ease;
        }

        .menu a:hover,
        .menu a.active {
            color: var(--gold-light);
            background: rgba(216,182,106,.09);
            border-color: var(--border);
            transform: translateX(3px);
        }

        .menu-icon {
            width: 24px;
            flex-shrink: 0;
            text-align: center;
            font-size: 18px;
        }

        .menu-text {
            flex: 1;
        }

        .menu-badge {
            min-width: 21px;
            padding: 2px 6px;
            text-align: center;
            border-radius: 20px;
            background: rgba(216,182,106,.15);
            color: var(--gold-light);
            font-size: 10px;
        }

        .sidebar-bottom {
            margin-top: auto;
            padding-top: 22px;
        }

        .staff-mini {
            padding: 13px;
            margin-bottom: 12px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,.025);
            border-radius: 12px;
        }

        .staff-mini strong {
            display: block;
            overflow-wrap: anywhere;
            font-size: 13px;
        }

        .staff-mini span {
            display: block;
            color: var(--muted);
            font-size: 11px;
            margin-top: 3px;
        }

        .logout-link {
            color: #ffc1bd !important;
        }

        /* MAIN */

        .main {
            margin-left: 258px;
            padding: 28px clamp(16px, 3vw, 38px);
            min-height: 100vh;
            max-width: 1800px;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 27px;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .eyebrow {
            color: var(--gold);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .welcome-title {
            font-size: clamp(23px, 2.6vw, 34px);
            line-height: 1.25;
            margin: 5px 0 8px;
            font-weight: 650;
        }

        .welcome-title span {
            color: var(--gold-light);
            overflow-wrap: anywhere;
        }

        .subtext {
            color: var(--muted);
            font-size: 13px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .date-pill,
        .role-pill {
            padding: 10px 13px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,.025);
            color: var(--muted);
            font-size: 11px;
            white-space: nowrap;
        }

        .role-pill {
            color: var(--gold-light);
        }

        .mobile-toggle {
            display: none;
            flex-shrink: 0;
            width: 43px;
            height: 43px;
            border: 1px solid var(--border);
            background: var(--panel);
            color: var(--gold-light);
            border-radius: 10px;
            font-size: 21px;
            cursor: pointer;
        }

        /* HERO */

        .hero {
            position: relative;
            overflow: hidden;
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(180px, .6fr);
            align-items: center;
            gap: 24px;
            padding: clamp(25px, 4vw, 44px);
            min-height: 235px;
            margin-bottom: 28px;
            border: 1px solid rgba(216,182,106,.3);
            border-radius: 22px;
            background:
                radial-gradient(
                    circle at 90% 40%,
                    rgba(216,182,106,.15),
                    transparent 34%
                ),
                linear-gradient(
                    115deg,
                    #173d2b,
                    #10271c 62%,
                    #0b1a12
                );
            box-shadow: var(--shadow);
        }

        .hero::after {
            content: "SH";
            position: absolute;
            right: 30px;
            bottom: -85px;
            font-size: clamp(150px, 20vw, 280px);
            font-family: Georgia, serif;
            font-weight: bold;
            line-height: 1;
            color: rgba(216,182,106,.045);
            pointer-events: none;
        }

        .hero-content {
            position: relative;
            z-index: 1;
        }

        .hero h2 {
            max-width: 640px;
            margin: 12px 0;
            font-family: Georgia, "Times New Roman", serif;
            font-size: clamp(26px, 3vw, 42px);
            font-weight: 500;
            line-height: 1.2;
        }

        .hero h2 span {
            color: var(--gold-light);
        }

        .hero p {
            max-width: 580px;
            color: #bdcec2;
            font-size: 13px;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 23px;
        }

        .btn {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            min-height: 42px;
            padding: 10px 16px;
            border-radius: 9px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
            transition: transform .2s ease, filter .2s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            filter: brightness(1.08);
        }

        .btn-gold {
            background: linear-gradient(
                135deg,
                var(--gold-light),
                var(--gold)
            );
            color: #182316;
        }

        .btn-outline {
            border: 1px solid rgba(242,220,161,.5);
            color: var(--gold-light);
            background: rgba(0,0,0,.1);
        }

        .hero-emblem {
            position: relative;
            z-index: 1;
            display: grid;
            justify-items: center;
            gap: 8px;
            text-align: center;
        }

        .emblem-ring {
            width: 118px;
            height: 118px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            border: 1px solid rgba(216,182,106,.55);
            outline: 1px solid rgba(216,182,106,.15);
            outline-offset: 8px;
            background: rgba(0,0,0,.1);
            font-size: 52px;
        }

        .hero-emblem strong {
            color: var(--gold-light);
            font-family: Georgia, serif;
            font-size: 15px;
            letter-spacing: 1px;
        }

        .hero-emblem small {
            color: var(--muted);
            font-size: 10px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        /* QUOTE */

        .quote-strip {
            position: relative;
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 17px 21px;
            margin-bottom: 30px;
            border-left: 3px solid var(--gold);
            background: linear-gradient(
                90deg,
                rgba(216,182,106,.08),
                rgba(255,255,255,.015)
            );
            border-radius: 0 12px 12px 0;
        }

        .quote-mark {
            color: var(--gold);
            font-size: 36px;
            font-family: Georgia, serif;
            line-height: 1;
        }

        .quote-copy {
            min-width: 0;
        }

        .quote-text {
            color: #e7e5d9;
            font-size: 14px;
            font-style: italic;
            transition: opacity .3s ease;
        }

        .quote-caption {
            margin-top: 5px;
            color: var(--gold);
            font-size: 10px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        /* SECTION HEADINGS */

        .section-heading {
            display: flex;
            justify-content: space-between;
            align-items: end;
            gap: 15px;
            margin-bottom: 16px;
        }

        .section-heading h2 {
            font-size: 19px;
            font-weight: 650;
        }

        .section-heading p {
            margin-top: 3px;
            color: var(--muted);
            font-size: 11px;
        }

        .section-link {
            color: var(--gold-light);
            text-decoration: none;
            font-size: 11px;
        }

        .section-link:hover {
            text-decoration: underline;
        }

        /* STATISTICS */

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 32px;
        }

        .stat-card {
            position: relative;
            min-width: 0;
            overflow: hidden;
            padding: 19px;
            border: 1px solid var(--border);
            background: linear-gradient(
                145deg,
                var(--panel-light),
                var(--panel)
            );
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0,0,0,.1);
            transition:
                transform .25s ease,
                border-color .25s ease;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            border-color: rgba(216,182,106,.45);
        }

        .stat-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            margin-bottom: 18px;
        }

        .stat-icon {
            width: 43px;
            height: 43px;
            display: grid;
            place-items: center;
            background: rgba(216,182,106,.1);
            border-radius: 12px;
            font-size: 23px;
        }

        .stat-tag {
            color: var(--muted);
            font-size: 10px;
        }

        .stat-value {
            color: var(--gold-light);
            font-size: clamp(25px, 2.5vw, 34px);
            font-weight: 700;
            line-height: 1.2;
        }

        .stat-label {
            margin-top: 6px;
            color: var(--muted);
            font-size: 12px;
        }

        .stat-link {
            display: block;
            margin-top: 14px;
            color: #d6e2d8;
            text-decoration: none;
            font-size: 10px;
        }

        .stat-link:hover {
            color: var(--gold-light);
        }

        /* ALERTS */

        .alerts-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 32px;
        }

        .alert-card {
            display: flex;
            align-items: flex-start;
            gap: 13px;
            padding: 17px;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: rgba(255,255,255,.025);
        }

        .alert-icon {
            display: grid;
            place-items: center;
            width: 40px;
            height: 40px;
            flex-shrink: 0;
            border-radius: 11px;
            background: rgba(216,182,106,.1);
            font-size: 21px;
        }

        .alert-card h3 {
            margin-bottom: 4px;
            font-size: 13px;
        }

        .alert-card p {
            color: var(--muted);
            font-size: 11px;
        }

        .alert-number {
            display: inline-block;
            margin-top: 8px;
            color: var(--gold-light);
            font-size: 12px;
            font-weight: 700;
        }

        .alert-number.warning {
            color: var(--danger);
        }

        /* QUICK ACTIONS */

        .quick-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 15px;
        }

        .quick-card {
            position: relative;
            min-width: 0;
            padding: 20px;
            text-decoration: none;
            color: var(--white);
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 15px;
            transition:
                transform .25s ease,
                background .25s ease,
                border-color .25s ease;
        }

        .quick-card:hover {
            transform: translateY(-5px);
            background: #173b2b;
            border-color: rgba(216,182,106,.5);
        }

        .quick-icon {
            display: grid;
            place-items: center;
            width: 49px;
            height: 49px;
            margin-bottom: 16px;
            border-radius: 13px;
            background: rgba(216,182,106,.1);
            font-size: 26px;
        }

        .quick-card h3 {
            margin-bottom: 7px;
            font-size: 14px;
        }

        .quick-card p {
            min-height: 48px;
            color: var(--muted);
            font-size: 11px;
            line-height: 1.6;
        }

        .quick-arrow {
            display: block;
            margin-top: 13px;
            color: var(--gold-light);
            font-size: 11px;
        }

        .quick-card.restaurant {
            border-top: 2px solid #d5a45c;
        }

        .quick-card.pool {
            border-top: 2px solid #49b8cf;
        }

        .quick-card.housekeeping {
            border-top: 2px solid #9acb8a;
        }

        /* =========================================================
   PROPERTY AMENITIES — LUXURY HOTEL STYLE
========================================================= */

.amenities-section,
.hotel-info-section {
    margin-top: 34px;
}

.amenities-panel {
    padding: clamp(18px, 3vw, 30px);
    border: 1px solid var(--border);
    border-radius: 19px;
    background:
        radial-gradient(
            circle at 100% 0%,
            rgba(216, 182, 106, .07),
            transparent 35%
        ),
        linear-gradient(145deg, var(--panel-light), var(--panel));
    box-shadow: var(--shadow);
}

.amenities-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 15px;
    margin-bottom: 26px;
}

.amenities-heading h2 {
    font-family: Georgia, "Times New Roman", serif;
    font-size: clamp(23px, 2.5vw, 30px);
    font-weight: 500;
    color: var(--gold-light);
}

.amenities-heading p {
    margin-top: 6px;
    color: var(--muted);
    font-size: 12px;
}

.amenities-count {
    flex-shrink: 0;
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 30px;
    color: var(--gold-light);
    font-size: 11px;
}

.amenities-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 13px;
}

.amenity-card {
    min-width: 0;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 17px 13px;
    text-decoration: none;
    color: var(--white);
    border: 1px solid transparent;
    border-radius: 13px;
    background: rgba(255, 255, 255, .025);
    transition:
        transform .25s ease,
        background .25s ease,
        border-color .25s ease;
}

.amenity-card:hover {
    transform: translateY(-4px);
    background: rgba(216, 182, 106, .075);
    border-color: var(--border);
}

.amenity-icon {
    width: 43px;
    height: 43px;
    flex-shrink: 0;
    display: grid;
    place-items: center;
    border-radius: 12px;
    color: var(--gold-light);
    background: rgba(216, 182, 106, .10);
    font-size: 23px;
}

.amenity-content {
    min-width: 0;
}

.amenity-content h3 {
    font-size: 13px;
    font-weight: 650;
    overflow-wrap: anywhere;
}

.amenity-content p {
    margin-top: 5px;
    color: var(--muted);
    font-size: 11px;
    line-height: 1.6;
}

.amenity-action {
    display: inline-block;
    margin-top: 8px;
    color: var(--gold-light);
    font-size: 10px;
}

.amenity-card.information {
    cursor: default;
}

.amenity-card.information:hover {
    transform: none;
}

.amenity-card.information .amenity-action {
    color: var(--muted);
}

/* =========================================================
   HOTEL INFORMATION
========================================================= */

.hotel-info-panel {
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: 19px;
    background: var(--panel);
    box-shadow: var(--shadow);
}

.hotel-info-header {
    padding: 24px 26px;
    background:
        radial-gradient(
            circle at 100% 0%,
            rgba(216, 182, 106, .12),
            transparent 40%
        ),
        linear-gradient(110deg, #173b2a, #10271d);
    border-bottom: 1px solid var(--border);
}

.hotel-info-header h2 {
    font-family: Georgia, "Times New Roman", serif;
    font-size: clamp(22px, 2.5vw, 29px);
    font-weight: 500;
    color: var(--gold-light);
}

.hotel-info-header p {
    margin-top: 6px;
    color: #c1d0c4;
    font-size: 12px;
}

.hotel-info-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.hotel-info-item {
    display: flex;
    align-items: flex-start;
    gap: 13px;
    min-width: 0;
    padding: 23px 20px;
    border-right: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
}

.hotel-info-item:nth-child(3n) {
    border-right: none;
}

.hotel-info-item:nth-last-child(-n+3) {
    border-bottom: none;
}

.hotel-info-icon {
    width: 42px;
    height: 42px;
    flex-shrink: 0;
    display: grid;
    place-items: center;
    border-radius: 12px;
    background: rgba(216, 182, 106, .10);
    color: var(--gold-light);
    font-size: 21px;
}

.hotel-info-content {
    min-width: 0;
}

.hotel-info-content h3 {
    font-size: 13px;
    font-weight: 650;
}

.hotel-info-content p {
    margin-top: 6px;
    color: var(--muted);
    font-size: 11px;
    line-height: 1.8;
    overflow-wrap: anywhere;
}

.hotel-info-content a {
    color: var(--gold-light);
    text-decoration: none;
}

.hotel-info-content a:hover {
    text-decoration: underline;
}

.hotel-info-note {
    margin: 0;
    padding: 15px 22px;
    color: var(--muted);
    background: rgba(255, 255, 255, .02);
    border-top: 1px solid var(--border);
    font-size: 10px;
}

/* =========================================================
   RESPONSIVE AMENITIES AND HOTEL INFORMATION
========================================================= */

@media (max-width: 1200px) {
    .amenities-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .hotel-info-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .hotel-info-item:nth-child(3n) {
        border-right: 1px solid var(--border);
    }

    .hotel-info-item:nth-child(2n) {
        border-right: none;
    }

    .hotel-info-item:nth-last-child(-n+3) {
        border-bottom: 1px solid var(--border);
    }

    .hotel-info-item:last-child,
    .hotel-info-item:nth-last-child(2) {
        border-bottom: none;
    }
}

@media (max-width: 550px) {
    .amenities-heading {
        flex-direction: column;
    }

    .amenities-grid {
        grid-template-columns: 1fr;
        gap: 8px;
    }

    .amenity-card {
        padding: 14px;
    }

    .hotel-info-header {
        padding: 20px;
    }

    .hotel-info-grid {
        grid-template-columns: 1fr;
    }

    .hotel-info-item,
    .hotel-info-item:nth-child(2n),
    .hotel-info-item:nth-child(3n),
    .hotel-info-item:nth-last-child(-n+3) {
        border-right: none;
        border-bottom: 1px solid var(--border);
    }

    .hotel-info-item:last-child {
        border-bottom: none;
    }

    .hotel-info-note {
        line-height: 1.8;
    }
}

        /* FOOTER */

        .footer {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 38px;
            padding: 22px 0 8px;
            border-top: 1px solid var(--border);
            color: var(--muted);
            font-size: 10px;
        }

        .footer span {
            color: var(--gold);
        }

        .backdrop {
            display: none;
        }

        /* TABLET */

        @media (max-width: 1200px) {
            .stats-grid,
            .quick-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .alerts-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .hero {
                grid-template-columns: minmax(0, 1fr) 150px;
            }

            .emblem-ring {
                width: 95px;
                height: 95px;
                font-size: 43px;
            }
        }

        /* MOBILE SIDEBAR */

        @media (max-width: 800px) {
            .sidebar {
                width: 270px;
                transform: translateX(-105%);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .backdrop.show {
                display: block;
                position: fixed;
                inset: 0;
                z-index: 999;
                background: rgba(0,0,0,.65);
            }

            .main {
                margin-left: 0;
                padding: 20px;
            }

            .mobile-toggle {
                display: inline-grid;
                place-items: center;
            }

            .hero {
                grid-template-columns: 1fr;
            }

            .hero-emblem {
                display: none;
            }

            .topbar {
                align-items: flex-start;
            }

            .topbar-right {
                flex-wrap: wrap;
                justify-content: flex-end;
            }
        }

        @media (max-width: 550px) {
            .main {
                padding: 15px;
            }

            .topbar {
                gap: 10px;
            }

            .topbar-right {
                display: none;
            }

            .welcome-title {
                font-size: 23px;
            }

            .subtext {
                font-size: 12px;
            }

            .hero {
                padding: 24px 19px;
                min-height: auto;
            }

            .hero h2 {
                font-size: 29px;
            }

            .hero p {
                font-size: 12px;
            }

            .hero-actions {
                flex-direction: column;
            }

            .hero-actions .btn {
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }

            .stat-card {
                padding: 13px;
            }

            .stat-top {
                margin-bottom: 12px;
            }

            .stat-icon {
                width: 36px;
                height: 36px;
                font-size: 19px;
            }

            .stat-value {
                font-size: 26px;
            }

            .stat-label {
                font-size: 11px;
            }

            .alerts-grid,
            .quick-grid {
                grid-template-columns: 1fr;
            }

            .quick-card {
                padding: 17px;
            }

            .quick-card p {
                min-height: auto;
            }

            .section-heading h2 {
                font-size: 17px;
            }

            .quote-strip {
                padding: 14px;
            }

            .quote-text {
                font-size: 12px;
            }

            .footer {
                flex-direction: column;
            }
        }

        /* ACCESSIBILITY */

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                scroll-behavior: auto !important;
                animation-duration: .01ms !important;
                transition-duration: .01ms !important;
            }
        }
    </style>
</head>

<body>

<div class="backdrop" id="sidebarBackdrop"></div>

<!-- ================= SIDEBAR ================= -->

<aside class="sidebar" id="sidebar">

    <div class="brand">
        <div class="brand-logo">🏨</div>
        <h2>SUVA HOTEL</h2>
        <p>Hospitality · Excellence</p>
    </div>

    <div class="menu-label">Workspace</div>

    <nav class="menu" aria-label="Staff navigation">

        <a href="staff_dashboard.php" class="active">
            <a href="staff_dashboard.php#property-amenities">
    <span class="menu-icon">✦</span>
    <span class="menu-text">Property Amenities</span>
</a>

<a href="staff_dashboard.php#hotel-information">
    <span class="menu-icon">ℹ️</span>
    <span class="menu-text">Hotel Information</span>
</a>
            <span class="menu-icon">⌂</span>
            <span class="menu-text">Dashboard</span>
        </a>

        <a href="../rooms/view.php">
            <span class="menu-icon">🏨</span>
            <span class="menu-text">Rooms</span>
        </a>

        <a href="../guests/view.php">
            <span class="menu-icon">👤</span>
            <span class="menu-text">Guests</span>
        </a>

        <a href="../bookings/view.php">
            <span class="menu-icon">📋</span>
            <span class="menu-text">Bookings</span>
            <?php if ($todaysArrivals > 0): ?>
                <span class="menu-badge">
                    <?= $todaysArrivals ?>
                </span>
            <?php endif; ?>
        </a>

        <a href="../checkin.php">
            <span class="menu-icon">🛎️</span>
            <span class="menu-text">Guest Check-in</span>
        </a>

        <a href="../checkout/add.php">
            <span class="menu-icon">🚪</span>
            <span class="menu-text">Guest Checkout</span>
        </a>

        <div class="menu-label">Hotel Services</div>

        <a href="../billing/add.php">
            <span class="menu-icon">🧾</span>
            <span class="menu-text">Billing & Invoices</span>
        </a>

        <a href="../payment/view.php">
            <span class="menu-icon">💳</span>
            <span class="menu-text">Payments</span>
        </a>

        <a href="../inventory/view.php">
            <span class="menu-icon">📦</span>
            <span class="menu-text">Inventory</span>
            <?php if ($lowStockItems > 0): ?>
                <span class="menu-badge">
                    <?= $lowStockItems ?>
                </span>
            <?php endif; ?>
        </a>

        <a href="../staff_restaurant/view.php">
            <span class="menu-icon">🍽️</span>
            <span class="menu-text">Restaurant Orders</span>
            <?php if ($pendingOrders > 0): ?>
                <span class="menu-badge">
                    <?= $pendingOrders ?>
                </span>
            <?php endif; ?>
        </a>

        <a href="../swimming/view.php">
            <span class="menu-icon">🏊</span>
            <span class="menu-text">Swimming Pool</span>
        </a>

        <a href="../housekeeping/index.php">
            <span class="menu-icon">🧹</span>
            <span class="menu-text">Housekeeping</span>
        </a>

        <?php if ($canViewReports): ?>
            <div class="menu-label">Administration</div>

            <a href="../reports/index.php">
                <span class="menu-icon">📊</span>
                <span class="menu-text">Reports</span>
            </a>
        <?php endif; ?>

    </nav>

    <div class="sidebar-bottom">
        <div class="staff-mini">
            <strong><?= e($displayName) ?></strong>
            <span>
                <?= e(ucfirst($role)) ?> · Suva Hotel Team
            </span>
        </div>

        <nav class="menu">
            <a href="../logout.php" class="logout-link">
                <span class="menu-icon">↪</span>
                <span class="menu-text">Logout</span>
            </a>
        </nav>
    </div>

</aside>

<!-- ================= MAIN CONTENT ================= -->

<main class="main">

    <!-- TOP BAR -->

    <header class="topbar">

        <div class="topbar-left">

            <button
                type="button"
                class="mobile-toggle"
                id="sidebarToggle"
                aria-label="Toggle navigation"
                aria-controls="sidebar"
                aria-expanded="false"
            >☰</button>

            <div>
                <div class="eyebrow">Suva Hotel · Staff Workspace</div>

                <h1 class="welcome-title">
                    <?= e($greeting) ?>,
                    <span><?= e($displayName) ?></span>
                </h1>

                <p class="subtext">
                    Your team's dedication creates unforgettable stays.
                </p>
            </div>

        </div>

        <div class="topbar-right">
            <div class="date-pill">
                📅 <?= e(date('d M Y')) ?>
            </div>

            <div class="role-pill">
                👤 <?= e(ucfirst($role)) ?>
            </div>
        </div>

    </header>

    <!-- LUXURY HERO -->

    <section class="hero reveal">

        <div class="hero-content">

            <div class="eyebrow">
                Welcome to exceptional hospitality
            </div>

            <h2>
                Every detail matters.<br>
                Every guest deserves <span>the best.</span>
            </h2>

            <p>
                Coordinate hotel operations, support your team,
                welcome guests warmly and make every stay special.
                Your next great service moment starts here.
            </p>

            <div class="hero-actions">

                <a href="../bookings/view.php" class="btn btn-gold">
                    📋 Manage Bookings
                </a>

                <a href="../staff_restaurant/view.php"
                   class="btn btn-outline">
                    🍽️ View Restaurant Orders
                </a>

            </div>

        </div>

        <div class="hero-emblem">
            <div class="emblem-ring">✦</div>
            <strong>Stay Exceptional</strong>
            <small>Service · Comfort · Trust</small>
        </div>

    </section>

    <!-- MOVING MOTIVATIONAL QUOTE -->

    <section class="quote-strip reveal" aria-live="polite">

        <div class="quote-mark">“</div>

        <div class="quote-copy">
            <p class="quote-text" id="quoteText">
                Great hospitality turns moments into memories.
            </p>

            <p class="quote-caption" id="quoteCaption">
                The Suva Hotel Standard
            </p>
        </div>

    </section>

    <!-- LIVE STATISTICS -->

    <section class="reveal">

        <div class="section-heading">
            <div>
                <h2>Hotel at a Glance</h2>
                <p>Live figures retrieved from your PostgreSQL database.</p>
            </div>

            <a href="staff_dashboard.php" class="section-link">
                ↻ Refresh figures
            </a>
        </div>

        <div class="stats-grid">

            <article class="stat-card">
                <div class="stat-top">
                    <div class="stat-icon">🏨</div>
                    <span class="stat-tag">ROOMS</span>
                </div>

                <div class="stat-value">
                    <?= $totalRooms ?>
                </div>

                <div class="stat-label">Total Rooms</div>

                <a href="../rooms/view.php" class="stat-link">
                    View room management →
                </a>
            </article>

            <article class="stat-card">
                <div class="stat-top">
                    <div class="stat-icon">🔑</div>
                    <span class="stat-tag">AVAILABLE</span>
                </div>

                <div class="stat-value">
                    <?= $availableRooms ?>
                </div>

                <div class="stat-label">Available Rooms</div>

                <a href="../rooms/view.php" class="stat-link">
                    Check availability →
                </a>
            </article>

            <article class="stat-card">
                <div class="stat-top">
                    <div class="stat-icon">🛏️</div>
                    <span class="stat-tag">IN-HOUSE</span>
                </div>

                <div class="stat-value">
                    <?= $activeStays ?>
                </div>

                <div class="stat-label">Checked-in Bookings</div>

                <a href="../bookings/view.php" class="stat-link">
                    View reservations →
                </a>
            </article>

            <article class="stat-card">
                <div class="stat-top">
                    <div class="stat-icon">🛎️</div>
                    <span class="stat-tag">ARRIVALS</span>
                </div>

                <div class="stat-value">
                    <?= $todaysArrivals ?>
                </div>

                <div class="stat-label">Today's Arrivals</div>

                <a href="../checkin.php" class="stat-link">
                    Open guest check-in →
                </a>
            </article>

        </div>

    </section>

    <!-- ATTENTION & TASK ALERTS -->

    <section class="reveal">

        <div class="section-heading">
            <div>
                <h2>Attention & Service Alerts</h2>
                <p>Items worth checking during your shift.</p>
            </div>
        </div>

        <div class="alerts-grid">

            <article class="alert-card">
                <div class="alert-icon">🧹</div>

                <div>
                    <h3>Room Maintenance</h3>

                    <p>
                        Rooms marked as maintenance in the room
                        register need attention.
                    </p>

                    <span class="alert-number
                        <?= $maintenanceRooms > 0 ? 'warning' : '' ?>">
                        <?= $maintenanceRooms ?> room(s)
                    </span>
                </div>
            </article>

            <article class="alert-card">
                <div class="alert-icon">🍽️</div>

                <div>
                    <h3>Restaurant Orders</h3>

                    <p>
                        Orders marked Pending, Preparing or Ready
                        may require staff action.
                    </p>

                    <span class="alert-number
                        <?= $pendingOrders > 0 ? 'warning' : '' ?>">
                        <?= $pendingOrders ?> order(s)
                    </span>

                    <a href="../staff_restaurant/view.php"
                       class="stat-link">
                        Review orders →
                    </a>
                </div>
            </article>

            <article class="alert-card">
                <div class="alert-icon">📦</div>

                <div>
                    <h3>Inventory Watch</h3>

                    <p>
                        Products at or below their configured
                        minimum stock level.
                    </p>

                    <span class="alert-number
                        <?= $lowStockItems > 0 ? 'warning' : '' ?>">
                        <?= $lowStockItems ?> item(s)
                    </span>

                    <a href="../inventory/view.php"
                       class="stat-link">
                        Review stock →
                    </a>
                </div>
            </article>

        </div>

    </section>

    <!-- QUICK ACTIONS -->

    <section class="reveal">

        <div class="section-heading">
            <div>
                <h2>Quick Access</h2>
                <p>Get to your everyday tasks without extra clicks.</p>
            </div>
        </div>

        <div class="quick-grid">

            <a href="../rooms/view.php" class="quick-card">
                <div class="quick-icon">🏨</div>
                <h3>Room Management</h3>
                <p>
                    Review room records, availability and
                    room information.
                </p>
                <span class="quick-arrow">Open rooms →</span>
            </a>

            <a href="../guests/view.php" class="quick-card">
                <div class="quick-icon">👤</div>
                <h3>Guest Records</h3>
                <p>
                    Find guest information and maintain
                    customer records.
                </p>
                <span class="quick-arrow">View guests →</span>
            </a>

            <a href="../bookings/view.php" class="quick-card">
                <div class="quick-icon">📋</div>
                <h3>Reservations</h3>
                <p>
                    Manage bookings and check reservation
                    details before arrival.
                </p>
                <span class="quick-arrow">View bookings →</span>
            </a>

            <a href="../checkin.php" class="quick-card">
                <div class="quick-icon">🛎️</div>
                <h3>Guest Check-in</h3>
                <p>
                    Access the existing check-in workflow
                    for arriving guests.
                </p>
                <span class="quick-arrow">Open check-in →</span>
            </a>

            <a href="../checkout/add.php" class="quick-card">
                <div class="quick-icon">🚪</div>
                <h3>Guest Checkout</h3>
                <p>
                    Open checkout and follow your existing
                    billing balance checks.
                </p>
                <span class="quick-arrow">Open checkout →</span>
            </a>

            <a href="../billing/add.php" class="quick-card">
                <div class="quick-icon">🧾</div>
                <h3>Billing & Invoices</h3>
                <p>
                    Access the billing workflow for guest
                    charges and invoices.
                </p>
                <span class="quick-arrow">Open billing →</span>
            </a>

            <a href="../payment/view.php" class="quick-card">
                <div class="quick-icon">💳</div>
                <h3>Payments</h3>
                <p>
                    Review payment records and payment
                    status through the existing page.
                </p>
                <span class="quick-arrow">View payments →</span>
            </a>

            <a href="../inventory/view.php" class="quick-card">
                <div class="quick-icon">📦</div>
                <h3>Inventory</h3>
                <p>
                    Review products, stock levels and
                    supplies for hotel operations.
                </p>
                <span class="quick-arrow">Open inventory →</span>
            </a>

            <a href="../staff_restaurant/view.php"
               class="quick-card restaurant">
                <div class="quick-icon">🍽️</div>
                <h3>Restaurant Orders</h3>
                <p>
                    Review orders in the existing staff
                    restaurant workflow.
                </p>
                <span class="quick-arrow">View orders →</span>
            </a>

            <a href="../staff_restaurant/add.php"
               class="quick-card restaurant">
                <div class="quick-icon">➕</div>
                <h3>New Restaurant Order</h3>
                <p>
                    Open the existing staff order entry
                    page for a new customer order.
                </p>
                <span class="quick-arrow">Create order →</span>
            </a>

            <a href="../swimming/view.php"
               class="quick-card pool">
                <div class="quick-icon">🏊</div>
                <h3>Swimming Pool</h3>
                <p>
                    Access swimming pool visitor and
                    service records.
                </p>
                <span class="quick-arrow">Open swimming pool →</span>
            </a>

            <a href="../housekeeping/index.php"
               class="quick-card housekeeping">
                <div class="quick-icon">🧹</div>
                <h3>Housekeeping</h3>
                <p>
                    Open the housekeeping workflow to review
                    cleaning and room-service tasks.
                </p>
                <span class="quick-arrow">Open housekeeping →</span>
            </a>

            <?php if ($canViewReports): ?>
                <a href="../reports/index.php" class="quick-card">
                    <div class="quick-icon">📊</div>
                    <h3>Hotel Reports</h3>
                    <p>
                        Access reports available to authorized
                        administration and management roles.
                    </p>
                    <span class="quick-arrow">Open reports →</span>
                </a>
            <?php endif; ?>

        </div>

    </section>

        <!-- =====================================================
         PROPERTY AMENITIES ON-SITE
    ====================================================== -->

    <section class="amenities-section reveal"
             id="property-amenities">

        <div class="section-heading">
            <div>
                <h2>Property Amenities On-Site</h2>
                <p>
                    Explore hotel facilities and access your
                    everyday service management tools.
                </p>
            </div>
        </div>

        <div class="amenities-panel">

            <div class="amenities-heading">
                <div>
                    <h2>Discover Suva Hotel</h2>
                    <p>
                        Thoughtful service. Comfortable stays.
                        Memorable experiences.
                    </p>
                </div>

                <span class="amenities-count">
                    ✦ Hotel Facilities
                </span>
            </div>

            <div class="amenities-grid">

                <!-- Restaurant -->
                <a href="../staff_restaurant/view.php"
                   class="amenity-card">

                    <div class="amenity-icon">🍽️</div>

                    <div class="amenity-content">
                        <h3>Restaurant On-Site</h3>
                        <p>
                            Access restaurant orders and
                            food-service operations.
                        </p>
                        <span class="amenity-action">
                            Manage restaurant →
                        </span>
                    </div>

                </a>

                <!-- Swimming Pool -->
                <a href="../swimming/view.php"
                   class="amenity-card">

                    <div class="amenity-icon">🏊</div>

                    <div class="amenity-content">
                        <h3>Swimming Pool</h3>
                        <p>
                            Access swimming pool records
                            and visitor services.
                        </p>
                        <span class="amenity-action">
                            Open swimming pool →
                        </span>
                    </div>

                </a>

                <!-- Rooms -->
                <a href="../rooms/view.php"
                   class="amenity-card">

                    <div class="amenity-icon">🛏️</div>

                    <div class="amenity-content">
                        <h3>Guest Rooms</h3>
                        <p>
                            Review room availability and
                            room management information.
                        </p>
                        <span class="amenity-action">
                            View rooms →
                        </span>
                    </div>

                </a>

                <!-- Housekeeping -->
                <a href="../housekeeping/index.php"
                   class="amenity-card">

                    <div class="amenity-icon">🧹</div>

                    <div class="amenity-content">
                        <h3>Housekeeping</h3>
                        <p>
                            Monitor room cleanliness and
                            cleaning activities.
                        </p>
                        <span class="amenity-action">
                            Manage housekeeping →
                        </span>
                    </div>

                </a>

                <!-- Meeting Space -->
                <article class="amenity-card information">

                    <div class="amenity-icon">🏢</div>

                    <div class="amenity-content">
                        <h3>Meeting Space</h3>
                        <p>
                            Meeting and conference facility
                            information.
                        </p>
                        <span class="amenity-action">
                            Contact reception for details
                        </span>
                    </div>

                </article>

                <!-- Laundry -->
                <article class="amenity-card information">

                    <div class="amenity-icon">🧺</div>

                    <div class="amenity-content">
                        <h3>Laundry Services</h3>
                        <p>
                            Information about laundry
                            service availability.
                        </p>
                        <span class="amenity-action">
                            Contact reception for details
                        </span>
                    </div>

                </article>

                <!-- Parking -->
                <article class="amenity-card information">

                    <div class="amenity-icon">🅿️</div>

                    <div class="amenity-content">
                        <h3>Parking Information</h3>
                        <p>
                            Ask reception about parking
                            availability and charges.
                        </p>
                        <span class="amenity-action">
                            Confirm availability
                        </span>
                    </div>

                </article>

                <!-- Accessibility -->
                <article class="amenity-card information">

                    <div class="amenity-icon">♿</div>

                    <div class="amenity-content">
                        <h3>Accessibility</h3>
                        <p>
                            Contact reception to confirm
                            available accessibility facilities.
                        </p>
                        <span class="amenity-action">
                            Request assistance
                        </span>
                    </div>

                </article>

            </div>

        </div>

    </section>


    <!-- =====================================================
         HOTEL INFORMATION
    ====================================================== -->

    <section class="hotel-info-section reveal"
             id="hotel-information">

        <div class="section-heading">
            <div>
                <h2>Hotel Information</h2>
                <p>
                    Important information for staff and guest
                    service coordination.
                </p>
            </div>
        </div>

        <div class="hotel-info-panel">

            <div class="hotel-info-header">
                <h2>Suva Hotel · Guest Services</h2>

                <p>
                    Clear information helps our team deliver
                    consistent and welcoming hospitality.
                </p>
            </div>

            <div class="hotel-info-grid">

                <!-- Check-in -->
                <article class="hotel-info-item">

                    <div class="hotel-info-icon">🕒</div>

                    <div class="hotel-info-content">
                        <h3>Check-in</h3>
                        <p>
                            3:00 PM<br>
                            Confirm the guest's reservation
                            before check-in.
                        </p>
                    </div>

                </article>

                <!-- Check-out -->
                <article class="hotel-info-item">

                    <div class="hotel-info-icon">🕛</div>

                    <div class="hotel-info-content">
                        <h3>Check-out</h3>
                        <p>
                            12:00 PM<br>
                            Verify outstanding balances
                            before completing checkout.
                        </p>
                    </div>

                </article>

                <!-- Front Desk -->
                <article class="hotel-info-item">

                    <div class="hotel-info-icon">🛎️</div>

                    <div class="hotel-info-content">
                        <h3>Front Desk</h3>
                        <p>
                            Guest assistance and reservation
                            coordination.
                            <br>
                            <a href="../checkin.php">
                                Open guest check-in →
                            </a>
                        </p>
                    </div>

                </article>

                <!-- Minimum Age -->
                <article class="hotel-info-item">

                    <div class="hotel-info-icon">✓</div>

                    <div class="hotel-info-content">
                        <h3>Minimum Check-in Age</h3>
                        <p>
                            Follow the hotel's approved
                            guest identification and
                            check-in policy.
                        </p>
                    </div>

                </article>

                <!-- Pet Policy -->
                <article class="hotel-info-item">

                    <div class="hotel-info-icon">🐾</div>

                    <div class="hotel-info-content">
                        <h3>Pet Policy</h3>
                        <p>
                            Confirm pet permissions and
                            applicable conditions with
                            hotel management.
                        </p>
                    </div>

                </article>

                <!-- Parking -->
                <article class="hotel-info-item">

                    <div class="hotel-info-icon">🅿️</div>

                    <div class="hotel-info-content">
                        <h3>Parking</h3>
                        <p>
                            Confirm parking availability,
                            location and charges with
                            reception.
                        </p>
                    </div>

                </article>

                <!-- Accessibility -->
                <article class="hotel-info-item">

                    <div class="hotel-info-icon">♿</div>

                    <div class="hotel-info-content">
                        <h3>Accessibility</h3>
                        <p>
                            Contact reception or management
                            to confirm available facilities
                            and guest assistance.
                        </p>
                    </div>

                </article>

                <!-- Guest Reservations -->
                <article class="hotel-info-item">

                    <div class="hotel-info-icon">📋</div>

                    <div class="hotel-info-content">
                        <h3>Reservations</h3>
                        <p>
                            Review arrival dates, room
                            assignments and guest details.
                            <br>
                            <a href="../bookings/view.php">
                                Manage bookings →
                            </a>
                        </p>
                    </div>

                </article>

                <!-- Hotel Operations -->
                <article class="hotel-info-item">

                    <div class="hotel-info-icon">✦</div>

                    <div class="hotel-info-content">
                        <h3>Our Service Standard</h3>
                        <p>
                            Courtesy, cleanliness,
                            attention to detail and
                            excellent guest service.
                        </p>
                    </div>

                </article>

            </div>

            <p class="hotel-info-note">
                <strong>Staff reminder:</strong>
                Confirm hotel policies and facility availability
                with management before providing information
                to guests.
            </p>

        </div>

    </section>
    <!-- FOOTER -->

    <footer class="footer">
        <div>
            © <?= e(date('Y')) ?> <span>SUVA HOTEL</span>
            · Hotel Management System
        </div>

        <div>
            Hospitality with care. Excellence in every detail.
        </div>
    </footer>

</main>

<!-- GSAP animations: dashboard still works if CDN is unavailable. -->

<script>
(function () {
    "use strict";

    /* Mobile navigation */

    const sidebar = document.getElementById("sidebar");
    const toggle = document.getElementById("sidebarToggle");
    const backdrop = document.getElementById("sidebarBackdrop");

    function closeSidebar() {
        sidebar.classList.remove("open");
        backdrop.classList.remove("show");

        if (toggle) {
            toggle.setAttribute("aria-expanded", "false");
        }
    }

    if (toggle) {
        toggle.addEventListener("click", function () {
            const isOpen = sidebar.classList.toggle("open");

            backdrop.classList.toggle("show", isOpen);
            toggle.setAttribute("aria-expanded", String(isOpen));
        });
    }

    backdrop.addEventListener("click", closeSidebar);

    document.querySelectorAll(".sidebar a").forEach(function (link) {
        link.addEventListener("click", function () {
            if (window.innerWidth <= 800) {
                closeSidebar();
            }
        });
    });

    window.addEventListener("resize", function () {
        if (window.innerWidth > 800) {
            closeSidebar();
        }
    });

    /* Rotating hospitality quotes */

    const quotes = [
        {
            text: "Great hospitality turns moments into memories.",
            caption: "The Suva Hotel Standard"
        },
        {
            text: "A warm welcome is the beginning of a wonderful stay.",
            caption: "Guest Experience"
        },
        {
            text: "Excellence is found in the little things we do every day.",
            caption: "Our Team, Our Pride"
        },
        {
            text: "Take care of your guests, and the memories will take care of themselves.",
            caption: "Service with Heart"
        },
        {
            text: "Luxury is not only what guests see; it is how they feel.",
            caption: "The Art of Hospitality"
        }
    ];

    const quoteText = document.getElementById("quoteText");
    const quoteCaption = document.getElementById("quoteCaption");

    let quoteIndex = 0;

    function rotateQuote() {
        if (!quoteText || !quoteCaption) {
            return;
        }

        quoteIndex = (quoteIndex + 1) % quotes.length;

        quoteText.style.opacity = "0";
        quoteCaption.style.opacity = "0";

        window.setTimeout(function () {
            quoteText.textContent = quotes[quoteIndex].text;
            quoteCaption.textContent = quotes[quoteIndex].caption;

            quoteText.style.opacity = "1";
            quoteCaption.style.opacity = "1";
        }, 300);
    }

    if (!window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
        window.setInterval(rotateQuote, 6000);
    }

    /* Graceful animation fallback */

    document.querySelectorAll(".reveal").forEach(function (element) {
        element.style.opacity = "1";
    });

    const reducedMotion = window.matchMedia(
        "(prefers-reduced-motion: reduce)"
    ).matches;

    if (!reducedMotion) {
        const gsapScript = document.createElement("script");

        gsapScript.src =
            "https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js";

        gsapScript.onload = function () {
            if (typeof window.gsap === "undefined") {
                return;
            }

            window.gsap.from(".topbar", {
                y: -12,
                opacity: 0,
                duration: 0.55,
                ease: "power2.out"
            });

            window.gsap.from(".hero", {
                y: 22,
                opacity: 0,
                duration: 0.8,
                delay: 0.12,
                ease: "power3.out"
            });

            window.gsap.from(".quote-strip", {
                y: 12,
                opacity: 0,
                duration: 0.55,
                delay: 0.25
            });

            if (window.gsap.matchMedia) {
                const mm = window.gsap.matchMedia();

                mm.add("(min-width: 801px)", function () {
                    window.gsap.from(".stat-card", {
                        y: 18,
                        opacity: 0,
                        duration: 0.5,
                        stagger: 0.08,
                        delay: 0.3,
                        ease: "power2.out"
                    });

                    window.gsap.from(".alert-card, .quick-card", {
                        y: 14,
                        opacity: 0,
                        duration: 0.45,
                        stagger: 0.045,
                        delay: 0.45,
                        ease: "power2.out"
                    });
                });
            }
        };

        document.head.appendChild(gsapScript);
    }
})();
</script>

</body>
</html>
