<?php
// ============================================================
// SUVA HOTEL - PREMIUM MONTHLY MANAGEMENT REPORT
// PostgreSQL Version
// File: /Suva_hotel/reports/monthly.php
// ============================================================

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();

// ============================================================
// DATABASE
// ============================================================

require_once __DIR__ . '/../config/database.php';

$db = $pgConnection ?? null;

if (!$db) {
    die('Database connection failed.');
}

// ============================================================
// AUTHENTICATION
// ============================================================

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$role = strtolower($_SESSION['role'] ?? '');

if (!in_array($role, ['admin', 'staff'], true)) {
    header('Location: ../index.php');
    exit;
}

// ============================================================
// HELPERS
// ============================================================

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($value)
{
    return 'Rs. ' . number_format((float)$value, 2);
}

function queryValue($db, $sql, $params = [], $default = 0)
{
    $result = pg_query_params($db, $sql, $params);

    if ($result === false) {
        return $default;
    }

    $row = pg_fetch_row($result);

    if (!$row) {
        return $default;
    }

    return is_numeric($row[0]) ? $row[0] : $default;
}

function tableExists($db, $table)
{
    $sql = "
        SELECT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_name = $1
        )
    ";

    $result = pg_query_params($db, $sql, [$table]);

    if ($result === false) {
        return false;
    }

    $row = pg_fetch_assoc($result);

    return (($row['exists'] ?? 'f') === 't');
}

function columnExists($db, $table, $column)
{
    $sql = "
        SELECT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = $1
              AND column_name = $2
        )
    ";

    $result = pg_query_params($db, $sql, [$table, $column]);

    if ($result === false) {
        return false;
    }

    $row = pg_fetch_assoc($result);

    return (($row['exists'] ?? 'f') === 't');
}

// ============================================================
// USER
// ============================================================

$username = $_SESSION['username'] ?? 'User';
$fullName = $_SESSION['full_name'] ?? $username;

// ============================================================
// MONTH SELECTION
// ============================================================

$selectedMonth = $_GET['month'] ?? date('Y-m');

if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}

$monthStart = $selectedMonth . '-01';

$monthEnd = date(
    'Y-m-d',
    strtotime($monthStart . ' +1 month')
);

$monthName = date(
    'F Y',
    strtotime($monthStart)
);

// ============================================================
// TABLE CHECKS
// ============================================================

$hasBilling    = tableExists($db, 'billing');
$hasBookings   = tableExists($db, 'bookings');
$hasPayments   = tableExists($db, 'payments');
$hasRooms      = tableExists($db, 'rooms');
$hasRestaurant = tableExists($db, 'restaurant_orders');
$hasSwimming   = tableExists($db, 'swimming');
$hasExpenses   = tableExists($db, 'expenses');

// ============================================================
// DEFAULT VALUES
// ============================================================

$hotelRevenue = 0;
$bookingCount = 0;
$paymentTotal = 0;

$restaurantRevenue = 0;
$restaurantOrders = 0;

$swimmingRevenue = 0;
$swimmingVisitors = 0;

$expenses = 0;

$totalRooms = 0;
$occupiedRooms = 0;
$availableRooms = 0;

// ============================================================
// HOTEL BILLING
// ============================================================

if (
    $hasBilling &&
    columnExists($db, 'billing', 'total_amount') &&
    columnExists($db, 'billing', 'billing_date')
) {
    $hotelRevenue = (float) queryValue(
        $db,
        "
        SELECT COALESCE(SUM(total_amount), 0)
        FROM billing
        WHERE billing_date >= $1::date
          AND billing_date < $2::date
          AND (
              status IS NULL
              OR LOWER(status) NOT IN ('cancelled', 'canceled')
          )
        ",
        [$monthStart, $monthEnd]
    );
}

// ============================================================
// BOOKINGS
// ============================================================

if (
    $hasBookings &&
    columnExists($db, 'bookings', 'check_in')
) {
    $bookingCount = (int) queryValue(
        $db,
        "
        SELECT COUNT(*)
        FROM bookings
        WHERE check_in >= $1::date
          AND check_in < $2::date
        ",
        [$monthStart, $monthEnd]
    );
}

// ============================================================
// PAYMENTS
// ============================================================

if (
    $hasPayments &&
    columnExists($db, 'payments', 'amount') &&
    columnExists($db, 'payments', 'paid_at')
) {
    $paymentTotal = (float) queryValue(
        $db,
        "
        SELECT COALESCE(SUM(amount), 0)
        FROM payments
        WHERE paid_at >= $1::date
          AND paid_at < $2::date
          AND (
              payment_status IS NULL
              OR LOWER(payment_status)
                 NOT IN ('cancelled', 'canceled', 'pending')
          )
        ",
        [$monthStart, $monthEnd]
    );
}

// ============================================================
// RESTAURANT
// ============================================================

if ($hasRestaurant) {

    if (
        columnExists($db, 'restaurant_orders', 'total_amount') &&
        columnExists($db, 'restaurant_orders', 'created_at')
    ) {
        $restaurantRevenue = (float) queryValue(
            $db,
            "
            SELECT COALESCE(SUM(total_amount), 0)
            FROM restaurant_orders
            WHERE created_at >= $1::date
              AND created_at < $2::date
              AND (
                  order_status IS NULL
                  OR LOWER(order_status)
                     NOT IN ('cancelled', 'canceled')
              )
            ",
            [$monthStart, $monthEnd]
        );
    }

    if (columnExists($db, 'restaurant_orders', 'created_at')) {

        $restaurantOrders = (int) queryValue(
            $db,
            "
            SELECT COUNT(*)
            FROM restaurant_orders
            WHERE created_at >= $1::date
              AND created_at < $2::date
              AND (
                  order_status IS NULL
                  OR LOWER(order_status)
                     NOT IN ('cancelled', 'canceled')
              )
            ",
            [$monthStart, $monthEnd]
        );
    }
}

// ============================================================
// SWIMMING
// ============================================================

if ($hasSwimming) {

    if (
        columnExists($db, 'swimming', 'total_amount') &&
        columnExists($db, 'swimming', 'created_at')
    ) {
        $swimmingRevenue = (float) queryValue(
            $db,
            "
            SELECT COALESCE(SUM(total_amount), 0)
            FROM swimming
            WHERE created_at >= $1::date
              AND created_at < $2::date
              AND (
                  status IS NULL
                  OR LOWER(status) <> 'cancelled'
              )
            ",
            [$monthStart, $monthEnd]
        );
    }

    if (
        columnExists($db, 'swimming', 'adults') &&
        columnExists($db, 'swimming', 'children') &&
        columnExists($db, 'swimming', 'created_at')
    ) {
        $swimmingVisitors = (int) queryValue(
            $db,
            "
            SELECT COALESCE(
                SUM(
                    COALESCE(adults, 0) +
                    COALESCE(children, 0)
                ),
                0
            )
            FROM swimming
            WHERE created_at >= $1::date
              AND created_at < $2::date
              AND (
                  status IS NULL
                  OR LOWER(status) <> 'cancelled'
              )
            ",
            [$monthStart, $monthEnd]
        );
    }
}

// ============================================================
// EXPENSES
// ============================================================

if (
    $hasExpenses &&
    columnExists($db, 'expenses', 'amount') &&
    columnExists($db, 'expenses', 'expense_date')
) {
    $expenses = (float) queryValue(
        $db,
        "
        SELECT COALESCE(SUM(amount), 0)
        FROM expenses
        WHERE expense_date >= $1::date
          AND expense_date < $2::date
        ",
        [$monthStart, $monthEnd]
    );
}

// ============================================================
// ROOMS
// ============================================================

if ($hasRooms) {

    $totalRooms = (int) queryValue(
        $db,
        "SELECT COUNT(*) FROM rooms"
    );

    if (columnExists($db, 'rooms', 'status')) {

        $occupiedRooms = (int) queryValue(
            $db,
            "
            SELECT COUNT(*)
            FROM rooms
            WHERE LOWER(COALESCE(status, '')) = 'occupied'
            "
        );

        $availableRooms = (int) queryValue(
            $db,
            "
            SELECT COUNT(*)
            FROM rooms
            WHERE LOWER(COALESCE(status, '')) = 'available'
            "
        );
    }
}

// ============================================================
// CALCULATIONS
// ============================================================

$totalRevenue =
    $hotelRevenue +
    $restaurantRevenue +
    $swimmingRevenue;

$netResult =
    $totalRevenue -
    $expenses;

$occupancy = 0;

if ($totalRooms > 0) {
    $occupancy =
        ($occupiedRooms / $totalRooms) * 100;
}

$occupancy = min(100, max(0, $occupancy));

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Monthly Report - <?= e($monthName) ?> | Suva Hotel
    </title>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --gold: #d4af37;
            --gold-light: #f2d77b;

            --bg: #061521;
            --bg2: #0a1d2d;

            --card: rgba(7, 27, 43, .88);

            --border: rgba(255, 255, 255, .10);

            --text: #f5f8fa;
            --muted: #9eb0be;

            --green: #32d39b;
            --red: #ff6678;
            --blue: #5daaff;
            --orange: #ffb452;
        }

        body {
            min-height: 100vh;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            color: var(--text);

            background:
                linear-gradient(
                    rgba(3, 15, 25, .90),
                    rgba(3, 15, 25, .96)
                ),
                url("../assets/image/hotel-lighting.jpg")
                center center / cover fixed no-repeat;
        }

        body::before {
            content: "";

            position: fixed;

            inset: 0;

            pointer-events: none;

            background:
                radial-gradient(
                    circle at 10% 10%,
                    rgba(212, 175, 55, .09),
                    transparent 28%
                ),
                radial-gradient(
                    circle at 90% 80%,
                    rgba(50, 170, 255, .07),
                    transparent 30%
                );
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        .page {
            position: relative;
            min-height: 100vh;
            padding: 28px;
        }

        .container {
            width: 100%;
            max-width: 1450px;
            margin: auto;
        }

        /* =====================================================
           HEADER
        ===================================================== */

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;

            padding: 20px 24px;
            margin-bottom: 20px;

            border-radius: 20px;

            background: rgba(5, 20, 33, .84);

            border: 1px solid var(--border);

            backdrop-filter: blur(18px);

            box-shadow:
                0 20px 50px rgba(0, 0, 0, .35);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .brand-icon {
            width: 56px;
            height: 56px;

            display: flex;
            align-items: center;
            justify-content: center;

            border-radius: 15px;

            color: #111;

            font-size: 23px;

            background:
                linear-gradient(
                    135deg,
                    var(--gold-light),
                    var(--gold)
                );

            box-shadow:
                0 8px 25px rgba(212, 175, 55, .18);
        }

        .brand h1 {
            font-size: 24px;
            letter-spacing: 1px;
        }

        .brand p {
            margin-top: 5px;
            color: var(--muted);
            font-size: 13px;
        }

        .user {
            display: flex;
            align-items: center;
            gap: 12px;

            color: var(--muted);

            font-size: 13px;

            text-align: right;
        }

        .user strong {
            color: var(--text);
        }

        .user-icon {
            width: 43px;
            height: 43px;

            display: flex;
            align-items: center;
            justify-content: center;

            border-radius: 50%;

            color: var(--gold-light);

            background: rgba(255, 255, 255, .06);

            border: 1px solid var(--border);
        }

        /* =====================================================
           NAVIGATION
        ===================================================== */

        .nav {
            display: flex;
            align-items: center;
            justify-content: space-between;

            flex-wrap: wrap;

            gap: 12px;

            margin-bottom: 25px;
        }

        .nav-left {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .nav-btn {
            min-height: 42px;

            display: inline-flex;
            align-items: center;

            gap: 8px;

            padding: 0 16px;

            border-radius: 11px;

            background: rgba(8, 27, 42, .82);

            border: 1px solid var(--border);

            color: #dce7ee;

            font-size: 13px;

            font-weight: 600;

            transition: .25s ease;
        }

        .nav-btn:hover {
            transform: translateY(-2px);

            color: var(--gold-light);

            border-color:
                rgba(212, 175, 55, .40);
        }

        .nav-btn.active {
            color: #111;

            border: none;

            background:
                linear-gradient(
                    135deg,
                    var(--gold-light),
                    var(--gold)
                );
        }

        /* =====================================================
           TITLE
        ===================================================== */

        .title {
            display: flex;

            align-items: flex-end;

            justify-content: space-between;

            gap: 20px;

            margin-bottom: 23px;
        }

        .title h2 {
            font-size: 31px;
        }

        .title p {
            margin-top: 7px;

            color: var(--muted);

            font-size: 13px;
        }

        .month-form {
            display: flex;

            align-items: center;

            gap: 9px;
        }

        .month-form label {
            color: var(--muted);

            font-size: 12px;
        }

        .month-form input {
            height: 42px;

            padding: 0 12px;

            border-radius: 10px;

            color: var(--text);

            background: rgba(7, 27, 43, .90);

            border: 1px solid var(--border);

            outline: none;
        }

        .month-form input:focus {
            border-color:
                rgba(212, 175, 55, .55);
        }

        .month-form button {
            height: 42px;

            padding: 0 16px;

            border: none;

            border-radius: 10px;

            cursor: pointer;

            font-weight: 700;

            background:
                linear-gradient(
                    135deg,
                    var(--gold-light),
                    var(--gold)
                );

            color: #111;
        }

        /* =====================================================
           SUMMARY
        ===================================================== */

        .summary {
            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            gap: 16px;

            margin-bottom: 20px;
        }

        .card {
            padding: 21px;

            border-radius: 18px;

            background: var(--card);

            border: 1px solid var(--border);

            backdrop-filter: blur(15px);

            box-shadow:
                0 15px 35px rgba(0, 0, 0, .25);
        }

        .card-head {
            display: flex;

            align-items: center;

            justify-content: space-between;

            margin-bottom: 15px;
        }

        .icon {
            width: 45px;
            height: 45px;

            display: flex;

            align-items: center;
            justify-content: center;

            border-radius: 12px;

            font-size: 18px;
        }

        .gold {
            color: var(--gold-light);
            background: rgba(212, 175, 55, .11);
        }

        .blue {
            color: var(--blue);
            background: rgba(93, 170, 255, .10);
        }

        .green {
            color: var(--green);
            background: rgba(50, 211, 155, .10);
        }

        .orange {
            color: var(--orange);
            background: rgba(255, 180, 82, .10);
        }

        .label {
            color: var(--muted);

            font-size: 11px;

            text-transform: uppercase;

            letter-spacing: 1px;
        }

        .value {
            margin-top: 5px;

            font-size: 25px;

            font-weight: 700;
        }

        .sub {
            color: var(--muted);

            font-size: 11px;
        }

        /* =====================================================
           MAIN GRID
        ===================================================== */

        .grid {
            display: grid;

            grid-template-columns:
                1.4fr .6fr;

            gap: 18px;

            margin-bottom: 20px;
        }

        .panel {
            padding: 23px;

            border-radius: 19px;

            background: var(--card);

            border: 1px solid var(--border);

            backdrop-filter: blur(15px);

            box-shadow:
                0 15px 35px rgba(0, 0, 0, .20);
        }

        .panel-head {
            display: flex;

            align-items: center;

            justify-content: space-between;

            margin-bottom: 20px;
        }

        .panel-head h3 {
            display: flex;

            align-items: center;

            gap: 10px;

            font-size: 17px;
        }

        .panel-head h3 i {
            color: var(--gold-light);
        }

        .panel-head span {
            color: var(--muted);

            font-size: 11px;
        }

        /* =====================================================
           REVENUE BREAKDOWN
        ===================================================== */

        .breakdown {
            display: grid;
            gap: 11px;
        }

        .row {
            display: flex;

            align-items: center;

            justify-content: space-between;

            padding: 15px;

            border-radius: 12px;

            background: rgba(255, 255, 255, .035);

            border: 1px solid rgba(255, 255, 255, .06);
        }

        .row-left {
            display: flex;

            align-items: center;

            gap: 12px;
        }

        .row-icon {
            width: 37px;
            height: 37px;

            display: flex;

            align-items: center;
            justify-content: center;

            border-radius: 10px;

            color: var(--gold-light);

            background: rgba(212, 175, 55, .09);
        }

        .row-name strong {
            display: block;
            font-size: 13px;
        }

        .row-name small {
            display: block;

            margin-top: 3px;

            color: var(--muted);

            font-size: 10px;
        }

        .amount {
            font-weight: 700;
            font-size: 13px;
        }

        /* =====================================================
           FINANCIAL SUMMARY
        ===================================================== */

        .profit-box {
            display: grid;
            gap: 13px;
        }

        .profit-line {
            display: flex;

            align-items: center;

            justify-content: space-between;

            padding: 13px;

            border-radius: 11px;

            background: rgba(255, 255, 255, .035);
        }

        .profit-line span {
            color: var(--muted);
            font-size: 12px;
        }

        .profit-line strong {
            font-size: 13px;
        }

        .profit-total {
            margin-top: 5px;

            padding: 18px;

            border-radius: 13px;

            text-align: center;

            background: rgba(50, 211, 155, .08);

            border: 1px solid rgba(50, 211, 155, .15);
        }

        .profit-total small {
            color: var(--muted);
            font-size: 10px;
        }

        .profit-total strong {
            display: block;

            margin-top: 5px;

            font-size: 25px;
        }

        /* =====================================================
           OPERATIONAL CARDS
        ===================================================== */

        .bottom-grid {
            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap: 16px;
        }

        .info {
            padding: 20px;

            border-radius: 17px;

            background: var(--card);

            border: 1px solid var(--border);

            box-shadow:
                0 15px 35px rgba(0, 0, 0, .20);
        }

        .info-icon {
            width: 42px;
            height: 42px;

            display: flex;

            align-items: center;
            justify-content: center;

            margin-bottom: 13px;

            border-radius: 11px;

            color: var(--gold-light);

            background: rgba(212, 175, 55, .09);
        }

        .info h4 {
            font-size: 14px;
        }

        .info-value {
            margin-top: 8px;

            font-size: 23px;

            font-weight: 700;
        }

        .info small {
            display: block;

            margin-top: 5px;

            color: var(--muted);

            font-size: 11px;
        }

        /* =====================================================
           FOOTER
        ===================================================== */

        .footer {
            padding: 25px;

            text-align: center;

            color: var(--muted);

            font-size: 11px;
        }

        .footer span {
            color: var(--gold);
            margin: 0 5px;
        }

        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media (max-width: 1100px) {

            .summary {
                grid-template-columns:
                    repeat(2, 1fr);
            }

            .grid {
                grid-template-columns: 1fr;
            }

            .bottom-grid {
                grid-template-columns:
                    repeat(2, 1fr);
            }
        }

        @media (max-width: 700px) {

            .page {
                padding: 15px;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 18px;
            }

            .user {
                text-align: left;
            }

            .title {
                flex-direction: column;
                align-items: flex-start;
            }

            .summary {
                grid-template-columns: 1fr;
            }

            .bottom-grid {
                grid-template-columns: 1fr;
            }

            .month-form {
                width: 100%;
                flex-wrap: wrap;
            }

            .month-form input {
                flex: 1;
            }

            .nav-left {
                width: 100%;
            }

            .nav-btn {
                font-size: 12px;
            }
        }

    </style>

</head>

<body>

<div class="page">

<div class="container">

    <!-- =====================================================
         HEADER
    ====================================================== -->

    <header class="header">

        <div class="brand">

            <div class="brand-icon">
                <i class="fa-solid fa-chart-line"></i>
            </div>

            <div>

                <h1>Suva Hotel</h1>

                <p>
                    Premium Monthly Management Report
                </p>

            </div>

        </div>

        <div class="user">

            <div>
                <strong>
                    <?= e($fullName) ?>
                </strong>

                <br>

                <?= e(ucfirst($role)) ?>
            </div>

            <div class="user-icon">
                <i class="fa-solid fa-user"></i>
            </div>

        </div>

    </header>


    <!-- =====================================================
         NAVIGATION
    ====================================================== -->

    <nav class="nav">

        <div class="nav-left">

            <a
                href="index.php"
                class="nav-btn"
            >
                <i class="fa-solid fa-chart-pie"></i>
                Reports Center
            </a>

            <a
                href="../dashboard.php"
                class="nav-btn"
            >
                <i class="fa-solid fa-house"></i>
                Dashboard
            </a>

            <a
                href="../bookings/view.php"
                class="nav-btn"
            >
                <i class="fa-solid fa-calendar-check"></i>
                Bookings
            </a>

            <a
                href="../payment/view.php"
                class="nav-btn"
            >
                <i class="fa-solid fa-credit-card"></i>
                Payments
            </a>

            <a
                href="monthly.php"
                class="nav-btn active"
            >
                <i class="fa-solid fa-calendar-days"></i>
                Monthly Report
            </a>

        </div>

        <a
            href="../logout.php"
            class="nav-btn"
        >
            <i class="fa-solid fa-right-from-bracket"></i>
            Logout
        </a>

    </nav>


    <!-- =====================================================
         TITLE + MONTH SELECTOR
    ====================================================== -->

    <section class="title">

        <div>

            <h2>
                Monthly Report
            </h2>

            <p>
                Detailed financial and operational performance
                for <?= e($monthName) ?>.
            </p>

        </div>

        <form
            method="GET"
            class="month-form"
        >

            <label for="month">
                Select Month
            </label>

            <input
                type="month"
                id="month"
                name="month"
                value="<?= e($selectedMonth) ?>"
                required
            >

            <button type="submit">
                <i class="fa-solid fa-filter"></i>
                View
            </button>

        </form>

    </section>


    <!-- =====================================================
         SUMMARY CARDS
    ====================================================== -->

    <section class="summary">

        <div class="card">

            <div class="card-head">

                <div>

                    <div class="label">
                        Total Revenue
                    </div>

                    <div class="value">
                        <?= e(money($totalRevenue)) ?>
                    </div>

                </div>

                <div class="icon gold">
                    <i class="fa-solid fa-coins"></i>
                </div>

            </div>

            <div class="sub">
                Hotel + restaurant + swimming
            </div>

        </div>


        <div class="card">

            <div class="card-head">

                <div>

                    <div class="label">
                        Bookings
                    </div>

                    <div class="value">
                        <?= e($bookingCount) ?>
                    </div>

                </div>

                <div class="icon blue">
                    <i class="fa-solid fa-calendar-check"></i>
                </div>

            </div>

            <div class="sub">
                Check-ins during this month
            </div>

        </div>


        <div class="card">

            <div class="card-head">

                <div>

                    <div class="label">
                        Payments Received
                    </div>

                    <div class="value">
                        <?= e(money($paymentTotal)) ?>
                    </div>

                </div>

                <div class="icon green">
                    <i class="fa-solid fa-money-bill-wave"></i>
                </div>

            </div>

            <div class="sub">
                Payments received during this month
            </div>

        </div>


        <div class="card">

            <div class="card-head">

                <div>

                    <div class="label">
                        Expenses
                    </div>

                    <div class="value">
                        <?= e(money($expenses)) ?>
                    </div>

                </div>

                <div class="icon orange">
                    <i class="fa-solid fa-receipt"></i>
                </div>

            </div>

            <div class="sub">
                Expenses recorded this month
            </div>

        </div>

    </section>


    <!-- =====================================================
         REVENUE + FINANCIAL SUMMARY
    ====================================================== -->

    <section class="grid">

        <div class="panel">

            <div class="panel-head">

                <h3>
                    <i class="fa-solid fa-chart-column"></i>
                    Revenue Breakdown
                </h3>

                <span>
                    <?= e($monthName) ?>
                </span>

            </div>


            <div class="breakdown">

                <div class="row">

                    <div class="row-left">

                        <div class="row-icon">
                            <i class="fa-solid fa-hotel"></i>
                        </div>

                        <div class="row-name">

                            <strong>
                                Hotel / Room Revenue
                            </strong>

                            <small>
                                Billing records
                            </small>

                        </div>

                    </div>

                    <div class="amount">
                        <?= e(money($hotelRevenue)) ?>
                    </div>

                </div>


                <div class="row">

                    <div class="row-left">

                        <div class="row-icon">
                            <i class="fa-solid fa-utensils"></i>
                        </div>

                        <div class="row-name">

                            <strong>
                                Restaurant Revenue
                            </strong>

                            <small>
                                Restaurant orders
                            </small>

                        </div>

                    </div>

                    <div class="amount">
                        <?= e(money($restaurantRevenue)) ?>
                    </div>

                </div>


                <div class="row">

                    <div class="row-left">

                        <div class="row-icon">
                            <i class="fa-solid fa-person-swimming"></i>
                        </div>

                        <div class="row-name">

                            <strong>
                                Swimming Revenue
                            </strong>

                            <small>
                                Swimming service
                            </small>

                        </div>

                    </div>

                    <div class="amount">
                        <?= e(money($swimmingRevenue)) ?>
                    </div>

                </div>


                <div class="row">

                    <div class="row-left">

                        <div class="row-icon">
                            <i class="fa-solid fa-wallet"></i>
                        </div>

                        <div class="row-name">

                            <strong>
                                Total Revenue
                            </strong>

                            <small>
                                All operating departments
                            </small>

                        </div>

                    </div>

                    <div
                        class="amount"
                        style="color:var(--gold-light);"
                    >
                        <?= e(money($totalRevenue)) ?>
                    </div>

                </div>

            </div>

        </div>


        <div class="panel">

            <div class="panel-head">

                <h3>
                    <i class="fa-solid fa-scale-balanced"></i>
                    Financial Summary
                </h3>

            </div>


            <div class="profit-box">

                <div class="profit-line">

                    <span>
                        Total Revenue
                    </span>

                    <strong>
                        <?= e(money($totalRevenue)) ?>
                    </strong>

                </div>


                <div class="profit-line">

                    <span>
                        Total Expenses
                    </span>

                    <strong style="color:var(--red);">
                        <?= e(money($expenses)) ?>
                    </strong>

                </div>


                <div
                    class="profit-total"
                    style="
                        background:
                        <?= $netResult >= 0
                            ? 'rgba(50,211,155,.08)'
                            : 'rgba(255,102,120,.08)' ?>;

                        border-color:
                        <?= $netResult >= 0
                            ? 'rgba(50,211,155,.15)'
                            : 'rgba(255,102,120,.15)' ?>;
                    "
                >

                    <small>
                        NET RESULT
                    </small>

                    <strong
                        style="
                            color:
                            <?= $netResult >= 0
                                ? 'var(--green)'
                                : 'var(--red)' ?>;
                        "
                    >
                        <?= e(money($netResult)) ?>
                    </strong>

                </div>

            </div>

        </div>

    </section>


    <!-- =====================================================
         OPERATIONAL SUMMARY
    ====================================================== -->

    <section class="bottom-grid">


        <div class="info">

            <div class="info-icon">
                <i class="fa-solid fa-utensils"></i>
            </div>

            <h4>
                Restaurant Activity
            </h4>

            <div class="info-value">
                <?= e($restaurantOrders) ?>
            </div>

            <small>
                Orders during <?= e($monthName) ?>
            </small>

        </div>


        <div class="info">

            <div class="info-icon">
                <i class="fa-solid fa-person-swimming"></i>
            </div>

            <h4>
                Swimming Visitors
            </h4>

            <div class="info-value">
                <?= e($swimmingVisitors) ?>
            </div>

            <small>
                Adults + children during <?= e($monthName) ?>
            </small>

        </div>


        <div class="info">

            <div class="info-icon">
                <i class="fa-solid fa-bed"></i>
            </div>

            <h4>
                Current Room Occupancy
            </h4>

            <div class="info-value">
                <?= e(number_format($occupancy, 1)) ?>%
            </div>

            <small>
                <?= e($occupiedRooms) ?>
                occupied /
                <?= e($availableRooms) ?>
                available /
                <?= e($totalRooms) ?>
                total rooms
            </small>

        </div>


    </section>


    <!-- =====================================================
         FOOTER
    ====================================================== -->

    <footer class="footer">

        Suva Hotel Management System

        <span>•</span>

        Monthly Report

        <span>•</span>

        <?= e($monthName) ?>

    </footer>


</div>

</div>

</body>

</html>