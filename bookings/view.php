<?php
// ============================================================
// SUVA HOTEL - BOOKINGS MANAGEMENT
// PostgreSQL Version
// File: /suva_hotel/bookings/view.php
// ============================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// ============================================================
// AUTHENTICATION
// ============================================================

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$userName = $_SESSION['username'] ?? 'Administrator';
$userRole = $_SESSION['role'] ?? 'admin';

if (!in_array($userRole, ['admin', 'staff'], true)) {
    header("Location: ../login.php");
    exit;
}

// ============================================================
// DATABASE
// ============================================================

require_once __DIR__ . '/../config/database.php';

$db = $pgConnection ?? null;

if (!$db) {
    die("Database connection failed.");
}

// ============================================================
// HELPER FUNCTIONS
// ============================================================

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($value)
{
    return 'Rs. ' . number_format((float)$value, 2);
}

function formatDate($date)
{
    if (empty($date)) {
        return '—';
    }

    $timestamp = strtotime($date);

    if (!$timestamp) {
        return e($date);
    }

    return date('d M Y', $timestamp);
}

function getStatusClass($status)
{
    $status = strtolower(trim((string)$status));

    switch ($status) {
        case 'confirmed':
            return 'status-confirmed';

        case 'pending':
            return 'status-pending';

        case 'completed':
            return 'status-completed';

        case 'cancelled':
        case 'canceled':
            return 'status-cancelled';

        default:
            return 'status-default';
    }
}

// ============================================================
// FETCH BOOKINGS
// ============================================================

$sql = "
    SELECT
        b.id,
        b.guest_id,
        b.room_id,
        COALESCE(g.full_name, 'Unknown Guest') AS full_name,
        r.room_number,
        r.room_type,
        r.price_per_night,
        b.check_in,
        b.check_out,
        b.adults,
        b.children,
        b.status,
        b.total_amount,
        b.created_at
    FROM bookings b
    LEFT JOIN guests g
        ON b.guest_id = g.id
    LEFT JOIN rooms r
        ON b.room_id = r.id
    ORDER BY b.id DESC
";

$result = @pg_query($db, $sql);

$bookings = [];

if ($result) {
    while ($row = pg_fetch_assoc($result)) {
        $bookings[] = $row;
    }
}

// ============================================================
// STATISTICS
// ============================================================

$totalBookings     = count($bookings);
$confirmedBookings = 0;
$pendingBookings   = 0;
$completedBookings = 0;
$cancelledBookings = 0;
$totalBookingValue = 0;

foreach ($bookings as $booking) {

    $status = strtolower(
        trim((string)($booking['status'] ?? ''))
    );

    switch ($status) {

        case 'confirmed':
            $confirmedBookings++;
            break;

        case 'pending':
            $pendingBookings++;
            break;

        case 'completed':
            $completedBookings++;
            break;

        case 'cancelled':
        case 'canceled':
            $cancelledBookings++;
            break;
    }

    if ($status !== 'cancelled' && $status !== 'canceled') {
        $totalBookingValue +=
            (float)($booking['total_amount'] ?? 0);
    }
}

// ============================================================
// CURRENT MONTH
// ============================================================

$currentMonth = date('Y-m');
$monthlyBookings = 0;

foreach ($bookings as $booking) {

    if (!empty($booking['created_at'])) {

        $bookingMonth = date(
            'Y-m',
            strtotime($booking['created_at'])
        );

        if ($bookingMonth === $currentMonth) {
            $monthlyBookings++;
        }
    }
}

// ============================================================
// DASHBOARD URL
// ============================================================

$dashboardUrl = '../dashboard.php';

if ($userRole === 'staff') {
    $dashboardUrl = '../staff_dashboard/staff_dashboard.php';
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Bookings | Suva Hotel</title>

<style>

/* ============================================================
   RESET
============================================================ */

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;
}

body {

    font-family:
        "Segoe UI",
        Arial,
        Helvetica,
        sans-serif;

    min-height: 100vh;

    color: #f5f1e8;

    background:
        linear-gradient(
            rgba(8, 8, 10, .91),
            rgba(8, 8, 10, .96)
        ),
        url("../assets/image/hotel-lighting.jpg")
        center / cover fixed no-repeat;

    overflow-x: hidden;
}

/* ============================================================
   BACKGROUND
============================================================ */

body::before {

    content: "";

    position: fixed;

    inset: 0;

    pointer-events: none;

    z-index: -1;

    background:
        radial-gradient(
            circle at 15% 10%,
            rgba(212,175,55,.08),
            transparent 30%
        ),
        radial-gradient(
            circle at 85% 90%,
            rgba(212,175,55,.05),
            transparent 35%
        );
}

/* ============================================================
   TOPBAR
============================================================ */

.topbar {

    position: sticky;

    top: 0;

    z-index: 1000;

    width: 100%;

    padding: 16px 32px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    background:
        rgba(12,12,14,.94);

    border-bottom:
        1px solid
        rgba(212,175,55,.18);

    backdrop-filter: blur(18px);
}

/* ============================================================
   BRAND
============================================================ */

.brand {

    display: flex;

    align-items: center;

    gap: 13px;
}

.brand-icon {

    width: 44px;
    height: 44px;

    display: flex;

    align-items: center;
    justify-content: center;

    border-radius: 12px;

    background:
        linear-gradient(
            135deg,
            #d4af37,
            #f1d675
        );

    color: #111;

    font-size: 21px;

    font-weight: 900;

    box-shadow:
        0 8px 25px
        rgba(212,175,55,.18);
}

.brand-text h2 {

    color: #f1d675;

    font-size: 17px;

    letter-spacing: 1.5px;
}

.brand-text span {

    color: #8f8b82;

    font-size: 11px;

    letter-spacing: 1px;
}

/* ============================================================
   USER
============================================================ */

.user-area {

    display: flex;

    align-items: center;

    gap: 13px;
}

.user-info {

    text-align: right;
}

.user-name {

    display: block;

    color: #eee;

    font-size: 13px;

    font-weight: 700;
}

.user-role {

    display: block;

    margin-top: 2px;

    color: #d4af37;

    font-size: 10px;

    text-transform: uppercase;

    letter-spacing: 1px;
}

.logout-btn {

    text-decoration: none;

    padding: 9px 15px;

    border-radius: 9px;

    color: #bbb;

    background:
        rgba(255,255,255,.04);

    border:
        1px solid
        rgba(255,255,255,.08);

    font-size: 12px;

    font-weight: 600;

    transition: .25s ease;
}

.logout-btn:hover {

    color: #fff;

    border-color:
        rgba(212,175,55,.35);

    background:
        rgba(212,175,55,.08);
}

/* ============================================================
   MAIN
============================================================ */

.container {

    width: min(1450px, 94%);

    margin: 0 auto;

    padding: 38px 0 70px;
}

/* ============================================================
   PAGE HEADER
============================================================ */

.page-header {

    display: flex;

    align-items: flex-end;

    justify-content: space-between;

    gap: 25px;

    margin-bottom: 30px;
}

.page-title small {

    display: block;

    margin-bottom: 8px;

    color: #d4af37;

    font-size: 11px;

    font-weight: 700;

    text-transform: uppercase;

    letter-spacing: 2.5px;
}

.page-title h1 {

    color: #fff;

    font-size:
        clamp(28px,4vw,42px);

    line-height: 1.1;

    letter-spacing: -.8px;
}

.page-title p {

    margin-top: 10px;

    color: #8f8b82;

    font-size: 14px;
}

/* ============================================================
   ACTION AREA
============================================================ */

.action-area {

    display: flex;

    align-items: center;

    gap: 12px;

    flex-wrap: wrap;

    justify-content: flex-end;
}

/* ============================================================
   BUTTON BASE
============================================================ */

.btn {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    gap: 10px;

    text-decoration: none;

    border-radius: 13px;

    font-weight: 700;

    cursor: pointer;

    transition:
        transform .25s ease,
        box-shadow .25s ease,
        background .25s ease,
        border-color .25s ease;
}

/* ============================================================
   BACK TO DASHBOARD
============================================================ */

.btn-dashboard {

    min-width: 165px;

    height: 48px;

    padding: 0 20px;

    color: #d2d0ca;

    background:
        rgba(255,255,255,.045);

    border:
        1px solid
        rgba(255,255,255,.12);

    font-size: 13px;
}

.btn-dashboard .icon {

    font-size: 18px;

    color: #d4af37;
}

.btn-dashboard:hover {

    transform:
        translateY(-3px);

    color: #fff;

    border-color:
        rgba(212,175,55,.40);

    background:
        rgba(212,175,55,.08);

    box-shadow:
        0 10px 25px
        rgba(0,0,0,.25);
}

/* ============================================================
   NEW BOOKING
============================================================ */

.btn-new-booking {

    min-width: 205px;

    height: 58px;

    padding: 0 27px;

    font-size: 16px;

    color: #111;

    background:
        linear-gradient(
            135deg,
            #d4af37,
            #f1d675
        );

    box-shadow:
        0 10px 28px
        rgba(212,175,55,.25);
}

.btn-new-booking .icon {

    width: 28px;
    height: 28px;

    display: flex;

    align-items: center;
    justify-content: center;

    border-radius: 8px;

    background:
        rgba(0,0,0,.12);

    font-size: 20px;

    font-weight: 900;
}

.btn-new-booking:hover {

    transform:
        translateY(-4px);

    box-shadow:
        0 15px 35px
        rgba(212,175,55,.38);
}

/* ============================================================
   CREATE BILL
============================================================ */

.btn-create-bill {

    min-width: 190px;

    height: 58px;

    padding: 0 27px;

    font-size: 16px;

    color: #f1d675;

    background:
        rgba(212,175,55,.10);

    border:
        1px solid
        rgba(212,175,55,.55);

    box-shadow:
        0 7px 20px
        rgba(0,0,0,.15);
}

.btn-create-bill .icon {

    width: 28px;
    height: 28px;

    display: flex;

    align-items: center;
    justify-content: center;

    border-radius: 8px;

    background:
        rgba(212,175,55,.13);

    font-size: 17px;
}

.btn-create-bill:hover {

    transform:
        translateY(-4px);

    background:
        rgba(212,175,55,.19);

    border-color:
        rgba(212,175,55,.85);

    box-shadow:
        0 13px 30px
        rgba(212,175,55,.18);
}

/* ============================================================
   STATS
============================================================ */

.stats-grid {

    display: grid;

    grid-template-columns:
        repeat(5,1fr);

    gap: 16px;

    margin-bottom: 25px;
}

.stat-card {

    position: relative;

    min-height: 125px;

    padding: 22px;

    overflow: hidden;

    border-radius: 16px;

    background:
        linear-gradient(
            145deg,
            rgba(24,24,27,.95),
            rgba(15,15,17,.92)
        );

    border:
        1px solid
        rgba(255,255,255,.07);

    box-shadow:
        0 15px 35px
        rgba(0,0,0,.20);
}

.stat-card::after {

    content: "";

    position: absolute;

    right: -25px;

    bottom: -35px;

    width: 110px;

    height: 110px;

    border-radius: 50%;

    background:
        rgba(212,175,55,.05);
}

.stat-label {

    color: #89857d;

    font-size: 11px;

    text-transform: uppercase;

    letter-spacing: 1.2px;
}

.stat-value {

    margin-top: 10px;

    color: #f1d675;

    font-size: 28px;

    font-weight: 800;
}

.stat-sub {

    margin-top: 5px;

    color: #65625c;

    font-size: 11px;
}

/* ============================================================
   PANEL
============================================================ */

.panel {

    overflow: hidden;

    border-radius: 18px;

    background:
        rgba(15,15,17,.92);

    border:
        1px solid
        rgba(255,255,255,.07);

    box-shadow:
        0 20px 55px
        rgba(0,0,0,.28);
}

.panel-header {

    padding: 22px 25px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 20px;

    border-bottom:
        1px solid
        rgba(255,255,255,.06);
}

.panel-title h2 {

    color: #eee;

    font-size: 18px;
}

.panel-title p {

    margin-top: 4px;

    color: #716e68;

    font-size: 12px;
}

/* ============================================================
   FILTER
============================================================ */

.filters {

    display: flex;

    align-items: center;

    gap: 10px;
}

.search-box {

    width: 260px;

    height: 42px;

    padding: 0 14px;

    border-radius: 10px;

    color: #eee;

    background:
        rgba(255,255,255,.045);

    border:
        1px solid
        rgba(255,255,255,.08);

    outline: none;

    font-size: 12px;

    transition: .25s ease;
}

.search-box:focus {

    border-color:
        rgba(212,175,55,.55);

    background:
        rgba(255,255,255,.065);

    box-shadow:
        0 0 0 3px
        rgba(212,175,55,.06);
}

.status-filter {

    height: 42px;

    padding: 0 12px;

    border-radius: 10px;

    color: #ccc;

    background: #19191c;

    border:
        1px solid
        rgba(255,255,255,.08);

    outline: none;

    font-size: 12px;
}

/* ============================================================
   BOOKING LIST
============================================================ */

.bookings-container {

    padding: 22px;
}

.booking-card {

    display: grid;

    grid-template-columns:
        65px
        minmax(180px,1.5fr)
        minmax(150px,1fr)
        minmax(150px,1fr)
        minmax(120px,.8fr)
        auto;

    align-items: center;

    gap: 18px;

    padding: 19px 20px;

    margin-bottom: 12px;

    border-radius: 14px;

    background:
        rgba(255,255,255,.025);

    border:
        1px solid
        rgba(255,255,255,.055);

    transition:
        transform .22s ease,
        border-color .22s ease,
        background .22s ease;
}

.booking-card:last-child {

    margin-bottom: 0;
}

.booking-card:hover {

    transform:
        translateY(-2px);

    background:
        rgba(212,175,55,.035);

    border-color:
        rgba(212,175,55,.20);
}

/* ============================================================
   BOOKING ID
============================================================ */

.booking-id {

    width: 48px;
    height: 48px;

    display: flex;

    align-items: center;
    justify-content: center;

    border-radius: 12px;

    color: #f1d675;

    background:
        rgba(212,175,55,.09);

    border:
        1px solid
        rgba(212,175,55,.16);

    font-size: 13px;

    font-weight: 800;
}

/* ============================================================
   INFORMATION
============================================================ */

.guest-name {

    color: #f0eee8;

    font-size: 14px;

    font-weight: 700;
}

.guest-id {

    margin-top: 5px;

    color: #67645e;

    font-size: 10px;
}

.info-label {

    color: #62605a;

    font-size: 9px;

    text-transform: uppercase;

    letter-spacing: 1px;
}

.info-value {

    margin-top: 4px;

    color: #c9c6bf;

    font-size: 12px;

    font-weight: 600;
}

.room-number {

    color: #f1d675;

    font-size: 13px;

    font-weight: 800;
}

.room-type {

    margin-top: 4px;

    color: #77746d;

    font-size: 10px;
}

/* ============================================================
   STATUS
============================================================ */

.status {

    display: inline-flex;

    align-items: center;

    gap: 7px;

    width: max-content;

    padding: 7px 10px;

    border-radius: 20px;

    font-size: 10px;

    font-weight: 700;

    text-transform: uppercase;

    letter-spacing: .7px;
}

.status::before {

    content: "";

    width: 6px;
    height: 6px;

    border-radius: 50%;

    background: currentColor;
}

.status-confirmed {

    color: #7fd7a0;

    background:
        rgba(70,180,110,.09);
}

.status-pending {

    color: #e4bd62;

    background:
        rgba(212,175,55,.09);
}

.status-completed {

    color: #8cb7e8;

    background:
        rgba(80,140,210,.09);
}

.status-cancelled {

    color: #e47c7c;

    background:
        rgba(210,70,70,.09);
}

.status-default {

    color: #aaa;

    background:
        rgba(255,255,255,.06);
}

/* ============================================================
   CARD ACTIONS
============================================================ */

.card-actions {

    display: flex;

    align-items: center;

    justify-content: flex-end;

    gap: 7px;
}

.btn-small {

    min-height: 36px;

    padding: 8px 12px;

    border-radius: 9px;

    font-size: 11px;

    font-weight: 700;

    color: #bdb9b0;

    background:
        rgba(255,255,255,.04);

    border:
        1px solid
        rgba(255,255,255,.07);
}

.btn-small:hover {

    color: #f1d675;

    border-color:
        rgba(212,175,55,.35);

    background:
        rgba(212,175,55,.07);
}

.btn-bill {

    color: #f1d675;

    border-color:
        rgba(212,175,55,.28);

    background:
        rgba(212,175,55,.07);
}

.btn-delete {

    color: #d98787;
}

.btn-delete:hover {

    color: #ff9a9a;

    border-color:
        rgba(220,90,90,.35);

    background:
        rgba(220,90,90,.07);
}

/* ============================================================
   EMPTY
============================================================ */

.empty-state {

    padding: 75px 20px;

    text-align: center;
}

.empty-icon {

    width: 70px;
    height: 70px;

    margin:
        0 auto 18px;

    display: flex;

    align-items: center;
    justify-content: center;

    border-radius: 18px;

    color: #d4af37;

    background:
        rgba(212,175,55,.08);

    border:
        1px solid
        rgba(212,175,55,.15);

    font-size: 30px;
}

.empty-state h3 {

    color: #ddd;

    font-size: 18px;
}

.empty-state p {

    margin-top: 7px;

    color: #69665f;

    font-size: 12px;
}

/* ============================================================
   FOOTER
============================================================ */

.footer {

    padding-top: 28px;

    text-align: center;

    color: #55524d;

    font-size: 10px;

    letter-spacing: .8px;
}

.footer span {

    color: #9b8030;
}

/* ============================================================
   RESPONSIVE
============================================================ */

@media (max-width: 1250px) {

    .stats-grid {

        grid-template-columns:
            repeat(3,1fr);
    }

    .booking-card {

        grid-template-columns:
            55px
            1.5fr
            1fr
            1fr;
    }

    .booking-card .card-actions {

        grid-column:
            2 / -1;

        justify-content:
            flex-start;
    }
}

@media (max-width: 850px) {

    .topbar {

        padding: 13px 18px;
    }

    .user-info {

        display: none;
    }

    .container {

        width: 92%;

        padding-top: 25px;
    }

    .page-header {

        align-items:
            flex-start;

        flex-direction:
            column;
    }

    .action-area {

        width: 100%;

        justify-content:
            flex-start;
    }

    .stats-grid {

        grid-template-columns:
            repeat(2,1fr);
    }

    .panel-header {

        align-items:
            flex-start;

        flex-direction:
            column;
    }

    .filters {

        width: 100%;
    }

    .search-box {

        width: 100%;
    }

    .booking-card {

        display: block;

        padding: 20px;
    }

    .booking-id {

        margin-bottom: 15px;
    }

    .booking-info {

        margin-bottom: 15px;
    }

    .card-actions {

        justify-content:
            flex-start;

        flex-wrap: wrap;
    }
}

@media (max-width: 550px) {

    .brand-text {

        display: none;
    }

    .action-area {

        flex-direction:
            column;

        align-items:
            stretch;
    }

    .btn-dashboard,
    .btn-new-booking,
    .btn-create-bill {

        width: 100%;

        min-width: 0;
    }

    .stats-grid {

        grid-template-columns:
            1fr;
    }

    .filters {

        flex-direction:
            column;
    }

    .search-box,
    .status-filter {

        width: 100%;
    }

    .bookings-container {

        padding: 12px;
    }
}

</style>

</head>

<body>

<!-- ============================================================
     TOP BAR
============================================================ -->

<header class="topbar">

    <div class="brand">

        <div class="brand-icon">
            SH
        </div>

        <div class="brand-text">

            <h2>SUVA HOTEL</h2>

            <span>
                HOTEL MANAGEMENT SYSTEM
            </span>

        </div>

    </div>


    <div class="user-area">

        <div class="user-info">

            <span class="user-name">
                <?= e($userName) ?>
            </span>

            <span class="user-role">
                <?= e($userRole) ?>
            </span>

        </div>

        <a
            href="../logout.php"
            class="logout-btn"
        >
            Logout
        </a>

    </div>

</header>


<!-- ============================================================
     MAIN
============================================================ -->

<main class="container">


    <!-- ========================================================
         PAGE HEADER
    ======================================================== -->

    <section class="page-header">


        <div class="page-title">

            <small>
                Reservations
            </small>

            <h1>
                Booking Management
            </h1>

            <p>
                Manage hotel reservations, guests, rooms and billing.
            </p>

        </div>


        <!-- ====================================================
             ACTION BUTTONS
        ==================================================== -->

        <div class="action-area">


            <!-- BACK TO DASHBOARD -->

            <a
                href="<?= e($dashboardUrl) ?>"
                class="btn btn-dashboard"
            >

                <span class="icon">
                    ←
                </span>

                <span>
                    Back to Dashboard
                </span>

            </a>


            <!-- NEW BOOKING -->

            <a
                href="add.php"
                class="btn btn-new-booking"
            >

                <span class="icon">
                    +
                </span>

                <span>
                    New Booking
                </span>

            </a>


            <!-- CREATE BILL -->

            <a
                href="../billing/add.php"
                class="btn btn-create-bill"
            >

                <span class="icon">
                    ▣
                </span>

                <span>
                    Create Bill
                </span>

            </a>


        </div>

    </section>


    <!-- ========================================================
         STATISTICS
    ======================================================== -->

    <section class="stats-grid">


        <div class="stat-card">

            <div class="stat-label">
                Total Bookings
            </div>

            <div class="stat-value">
                <?= number_format($totalBookings) ?>
            </div>

            <div class="stat-sub">
                All reservations
            </div>

        </div>


        <div class="stat-card">

            <div class="stat-label">
                Confirmed
            </div>

            <div class="stat-value">
                <?= number_format($confirmedBookings) ?>
            </div>

            <div class="stat-sub">
                Active reservations
            </div>

        </div>


        <div class="stat-card">

            <div class="stat-label">
                Pending
            </div>

            <div class="stat-value">
                <?= number_format($pendingBookings) ?>
            </div>

            <div class="stat-sub">
                Awaiting confirmation
            </div>

        </div>


        <div class="stat-card">

            <div class="stat-label">
                This Month
            </div>

            <div class="stat-value">
                <?= number_format($monthlyBookings) ?>
            </div>

            <div class="stat-sub">
                New bookings
            </div>

        </div>


        <div class="stat-card">

            <div class="stat-label">
                Booking Value
            </div>

            <div class="stat-value">
                <?= money($totalBookingValue) ?>
            </div>

            <div class="stat-sub">
                Non-cancelled bookings
            </div>

        </div>


    </section>


    <!-- ========================================================
         BOOKINGS PANEL
    ======================================================== -->

    <section class="panel">


        <div class="panel-header">


            <div class="panel-title">

                <h2>
                    All Bookings
                </h2>

                <p>
                    View and manage hotel reservations
                </p>

            </div>


            <div class="filters">

                <input
                    type="text"
                    id="searchInput"
                    class="search-box"
                    placeholder="Search guest, room or booking..."
                    autocomplete="off"
                >


                <select
                    id="statusFilter"
                    class="status-filter"
                >

                    <option value="all">
                        All Status
                    </option>

                    <option value="confirmed">
                        Confirmed
                    </option>

                    <option value="pending">
                        Pending
                    </option>

                    <option value="completed">
                        Completed
                    </option>

                    <option value="cancelled">
                        Cancelled
                    </option>

                </select>

            </div>

        </div>


        <div
            class="bookings-container"
            id="bookingList"
        >


            <?php if (empty($bookings)): ?>


                <div class="empty-state">

                    <div class="empty-icon">
                        +
                    </div>

                    <h3>
                        No Bookings Found
                    </h3>

                    <p>
                        Start by creating your first hotel booking.
                    </p>

                    <br>

                    <a
                        href="add.php"
                        class="btn btn-new-booking"
                    >
                        + New Booking
                    </a>

                </div>


            <?php else: ?>


                <?php foreach ($bookings as $booking): ?>

                    <?php

                    $bookingId =
                        (int)($booking['id'] ?? 0);

                    $guestId =
                        (int)($booking['guest_id'] ?? 0);

                    $guestName =
                        $booking['full_name']
                        ?? 'Unknown Guest';

                    $roomNumber =
                        $booking['room_number']
                        ?? 'N/A';

                    $roomType =
                        $booking['room_type']
                        ?? 'Room';

                    $status =
                        $booking['status']
                        ?? 'Pending';

                    $pricePerNight =
                        (float)(
                            $booking['price_per_night']
                            ?? 0
                        );

                    $storedTotal =
                        (float)(
                            $booking['total_amount']
                            ?? 0
                        );

                    // ------------------------------------------------
                    // CALCULATE NIGHTS
                    // ------------------------------------------------

                    $nights = 0;

                    if (
                        !empty($booking['check_in']) &&
                        !empty($booking['check_out'])
                    ) {

                        $checkInTimestamp =
                            strtotime(
                                $booking['check_in']
                            );

                        $checkOutTimestamp =
                            strtotime(
                                $booking['check_out']
                            );

                        if (
                            $checkInTimestamp &&
                            $checkOutTimestamp
                        ) {

                            $difference =
                                $checkOutTimestamp -
                                $checkInTimestamp;

                            $nights =
                                (int)ceil(
                                    $difference / 86400
                                );

                            if ($nights < 1) {
                                $nights = 1;
                            }
                        }
                    }

                    $calculatedTotal =
                        $pricePerNight * $nights;

                    $displayTotal =
                        $storedTotal > 0
                        ? $storedTotal
                        : $calculatedTotal;

                    $adults =
                        (int)(
                            $booking['adults']
                            ?? 0
                        );

                    $children =
                        (int)(
                            $booking['children']
                            ?? 0
                        );

                    $statusClass =
                        getStatusClass($status);

                    $searchData = strtolower(
                        implode(' ', [
                            $bookingId,
                            $guestName,
                            $roomNumber,
                            $roomType,
                            $status,
                            $booking['check_in'] ?? '',
                            $booking['check_out'] ?? ''
                        ])
                    );

                    ?>


                    <article
                        class="booking-card"
                        data-status="<?= e(strtolower($status)) ?>"
                        data-search="<?= e($searchData) ?>"
                    >


                        <!-- BOOKING ID -->

                        <div>

                            <div class="booking-id">
                                #<?= $bookingId ?>
                            </div>

                        </div>


                        <!-- GUEST -->

                        <div class="booking-info">

                            <div class="guest-name">
                                <?= e($guestName) ?>
                            </div>

                            <div class="guest-id">
                                Guest ID:
                                <?= $guestId ?>
                            </div>

                        </div>


                        <!-- ROOM -->

                        <div class="booking-info">

                            <div class="info-label">
                                Room
                            </div>

                            <div class="info-value">

                                <span class="room-number">
                                    <?= e($roomNumber) ?>
                                </span>

                            </div>

                            <div class="room-type">
                                <?= e($roomType) ?>
                            </div>

                        </div>


                        <!-- STAY -->

                        <div class="booking-info">

                            <div class="info-label">
                                Stay
                            </div>

                            <div class="info-value">

                                <?= formatDate($booking['check_in']) ?>

                                →

                                <?= formatDate($booking['check_out']) ?>

                            </div>

                            <div class="room-type">

                                <?= $nights ?>
                                night<?= $nights === 1 ? '' : 's' ?>

                                ·

                                <?= $adults ?>
                                adult<?= $adults === 1 ? '' : 's' ?>

                                <?php if ($children > 0): ?>

                                    ·

                                    <?= $children ?>

                                    child<?= $children === 1 ? '' : 'ren' ?>

                                <?php endif; ?>

                            </div>

                        </div>


                        <!-- STATUS -->

                        <div class="booking-info">

                            <span
                                class="status <?= e($statusClass) ?>"
                            >
                                <?= e($status) ?>
                            </span>

                            <div class="info-value">
                                <?= money($displayTotal) ?>
                            </div>

                        </div>


                        <!-- ACTIONS -->

                        <div class="card-actions">


                            <a
                                href="edit.php?id=<?= $bookingId ?>"
                                class="btn btn-small"
                            >
                                Edit
                            </a>
                            

<?php
$normalizedStatus = strtolower(trim((string)$status));

if (in_array($normalizedStatus, ['confirmed', 'pending'], true)):
?>
    <a
        href="cancel.php?id=<?= (int)$bookingId ?>"
        class="btn btn-small"
        style="color:#ff9a9a; border-color:rgba(220,90,90,.35);"
        onclick="return confirm('Are you sure you want to cancel booking #<?= (int)$bookingId ?>?');"
    >
        ❌ Cancel Booking
    </a>
<?php endif; ?>




                            <a
                                href="../billing/add.php?booking_id=<?= $bookingId ?>"
                                class="btn btn-small btn-bill"
                            >
                                Bill
                            </a>


                            <?php if ($userRole === 'admin'): ?>

                                
<form action="delete.php" method="POST"
      style="display:inline;"
      onsubmit="return confirm('Delete this booking permanently? Only eligible bookings without payment history can be deleted.');">

    <input type="hidden" name="id"
           value="<?= (int) $booking['id'] ?>">

    <button type="submit"
            style="background:#dc2626;color:white;
                   padding:8px 12px;border:0;
                   border-radius:5px;cursor:pointer;">
        Delete
    </button>
</form>


                            <?php endif; ?>


                        </div>


                    </article>


                <?php endforeach; ?>


            <?php endif; ?>


        </div>

    </section>


    <!-- ========================================================
         FOOTER
    ======================================================== -->

    <footer class="footer">

        SUVA HOTEL

        <span>•</span>

        PREMIUM MANAGEMENT SYSTEM

        <span>•</span>

        BOOKINGS

    </footer>


</main>


<!-- ============================================================
     JAVASCRIPT
============================================================ -->

<script>

const searchInput =
    document.getElementById('searchInput');

const statusFilter =
    document.getElementById('statusFilter');

const bookingCards =
    document.querySelectorAll('.booking-card');


function filterBookings()
{
    const search =
        searchInput
            ? searchInput.value
                .toLowerCase()
                .trim()
            : '';

    const status =
        statusFilter
            ? statusFilter.value
            : 'all';


    bookingCards.forEach(function(card)
    {

        const cardSearch =
            card.dataset.search || '';

        const cardStatus =
            card.dataset.status || '';


        const matchesSearch =
            search === '' ||
            cardSearch.includes(search);


        const matchesStatus =
            status === 'all' ||
            cardStatus === status;


        if (
            matchesSearch &&
            matchesStatus
        ) {

            card.style.display = '';

        }
        else {

            card.style.display = 'none';

        }

    });
}


if (searchInput)
{
    searchInput.addEventListener(
        'input',
        filterBookings
    );
}


if (statusFilter)
{
    statusFilter.addEventListener(
        'change',
        filterBookings
    );
}


/* ============================================================
   ENTRANCE ANIMATION
============================================================ */

bookingCards.forEach(function(card, index)
{

    card.style.opacity = '0';

    card.style.transform =
        'translateY(8px)';


    setTimeout(function()
    {

        card.style.transition =
            'opacity .35s ease, transform .35s ease';

        card.style.opacity = '1';

        card.style.transform =
            'translateY(0)';

    }, index * 35);

});

</script>

</body>
</html>