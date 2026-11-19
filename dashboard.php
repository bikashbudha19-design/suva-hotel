<?php
// ============================================================
// SUVA HOTEL - PREMIUM ADMIN DASHBOARD
// PostgreSQL Version
// File: /Suva_hotel/dashboard.php
// PHP 8.0+
// ============================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// ============================================================
// AUTHENTICATION
// ============================================================

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userName = $_SESSION['username'] ?? 'Administrator';
$userRole = strtolower($_SESSION['role'] ?? 'admin');

// ============================================================
// DATABASE
// ============================================================

require_once __DIR__ . '/config/database.php';

$db = $pgConnection ?? null;

// ============================================================
// SAFE DATABASE FUNCTIONS
// ============================================================

function getCount($db, $query)
{
    if (!$db) {
        return 0;
    }

    try {
        $result = @pg_query($db, $query);

        if ($result) {
            $value = pg_fetch_result($result, 0, 0);
            return (int)$value;
        }
    } catch (Throwable $e) {
        return 0;
    }

    return 0;
}

function getValue($db, $query)
{
    if (!$db) {
        return 0;
    }

    try {
        $result = @pg_query($db, $query);

        if ($result) {
            $value = pg_fetch_result($result, 0, 0);
            return $value !== null ? $value : 0;
        }
    } catch (Throwable $e) {
        return 0;
    }

    return 0;
}

// ============================================================
// HOTEL STATISTICS
// ============================================================

$totalRooms = getCount(
    $db,
    "SELECT COUNT(*) FROM public.rooms"
);

$totalGuests = getCount(
    $db,
    "SELECT COUNT(*) FROM public.guests"
);

$totalBookings = getCount(
    $db,
    "SELECT COUNT(*) FROM public.bookings"
);

$totalProducts = getCount(
    $db,
    "SELECT COUNT(*) FROM public.products"
);

$totalStaff = getCount(
    $db,
    "SELECT COUNT(*) FROM public.users"
);

$totalPayments = getValue(
    $db,
    "SELECT COALESCE(SUM(amount), 0) FROM public.payments"
);

$totalExpenses = getValue(
    $db,
    "SELECT COALESCE(SUM(amount), 0) FROM public.expenses"
);

$totalRevenue = $totalPayments;

// ============================================================
// SWIMMING STATISTICS
// ============================================================

$totalSwimmingVisitors = getCount(
    $db,
    "SELECT COUNT(*) FROM public.swimming"
);

$todaySwimmingVisitors = getCount(
    $db,
    "SELECT COUNT(*)
     FROM public.swimming
     WHERE created_at::date = CURRENT_DATE"
);

$activeSwimmingVisitors = getCount(
    $db,
    "SELECT COUNT(*)
     FROM public.swimming
     WHERE LOWER(TRIM(COALESCE(status, ''))) = 'active'"
);

$swimmingRevenue = getValue(
    $db,
    "SELECT COALESCE(SUM(total_amount), 0)
     FROM public.swimming"
);

// ============================================================
// CURRENT DATE
// ============================================================

$currentDate = date('l, F j, Y');

// ============================================================
// USER INITIAL
// ============================================================

$userInitial = strtoupper(
    substr(trim($userName), 0, 1)
);

if ($userInitial === '') {
    $userInitial = 'A';
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

    <title>Suva Hotel | Admin Dashboard</title>

    <!-- =====================================================
         GOOGLE FONT
    ====================================================== -->

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <!-- =====================================================
         FONT AWESOME
    ====================================================== -->

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <style>

        /* =====================================================
           RESET
        ===================================================== */

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html,
        body {
            width: 100%;
            min-height: 100%;
        }

        body {

            font-family: 'Inter', sans-serif;

            color: #ffffff;

            background: #07090d;

            overflow-x: hidden;
        }

        a {
            color: inherit;
            text-decoration: none;
        }


        /* =====================================================
           HOTEL BACKGROUND
        ===================================================== */

        .background {

            position: fixed;

            inset: 0;

            z-index: -10;

            background-image:

                linear-gradient(
                    rgba(3, 5, 9, 0.72),
                    rgba(3, 5, 9, 0.86)
                ),

                url("assets/image/hotel-lighting.jpg");

            background-size: cover;

            background-position: center center;

            background-repeat: no-repeat;

            transform: scale(1.01);
        }

        .background::after {

            content: "";

            position: absolute;

            inset: 0;

            background:

                linear-gradient(
                    90deg,
                    rgba(4, 6, 10, 0.90),
                    rgba(4, 6, 10, 0.62),
                    rgba(4, 6, 10, 0.80)
                );

            pointer-events: none;
        }


        /* =====================================================
           LAYOUT
        ===================================================== */

        .layout {

            display: flex;

            min-height: 100vh;
        }


        /* =====================================================
           SIDEBAR
        ===================================================== */

        .sidebar {

            width: 270px;

            height: 100vh;

            position: fixed;

            left: 0;
            top: 0;
            bottom: 0;

            z-index: 100;

            padding: 22px 18px 15px;

            display: flex;

            flex-direction: column;

            background:
                rgba(9, 12, 18, 0.94);

            border-right:
                1px solid rgba(255, 255, 255, 0.08);

            backdrop-filter: blur(20px);

            -webkit-backdrop-filter: blur(20px);

            box-shadow:
                10px 0 40px rgba(0,0,0,.20);
        }


        /* =====================================================
           LOGO
        ===================================================== */

        .logo {

            display: flex;

            align-items: center;

            gap: 13px;

            padding:
                5px 10px 22px;

            flex-shrink: 0;
        }

        .logo-icon {

            width: 48px;
            height: 48px;

            flex-shrink: 0;

            border-radius: 14px;

            display: flex;

            align-items: center;

            justify-content: center;

            background:

                linear-gradient(
                    135deg,
                    #d8b75c,
                    #8f6d1d
                );

            color: #111;

            font-size: 21px;

            box-shadow:

                0 8px 25px
                rgba(212, 175, 55, 0.25);
        }

        .logo-text h2 {

            font-size: 19px;

            font-weight: 800;

            letter-spacing: 0.5px;
        }

        .logo-text span {

            font-size: 10px;

            color: #8d95a3;

            text-transform: uppercase;

            letter-spacing: 2px;
        }


        /* =====================================================
           MAIN MENU TITLE
        ===================================================== */

        .nav-title {

            font-size: 10px;

            color: #606977;

            text-transform: uppercase;

            letter-spacing: 1.8px;

            margin:
                5px 12px 10px;

            flex-shrink: 0;
        }


        /* =====================================================
           SCROLLABLE MAIN MENU
        ===================================================== */

        .nav {

            display: flex;

            flex-direction: column;

            gap: 5px;

            flex: 1;

            min-height: 0;

            overflow-y: auto;

            overflow-x: hidden;

            padding:
                0 5px 12px 0;

            scrollbar-width: thin;

            scrollbar-color:
                rgba(214, 176, 78, 0.55)
                transparent;
        }


        /* =====================================================
           CHROME / EDGE / SAFARI SCROLLBAR
        ===================================================== */

        .nav::-webkit-scrollbar {

            width: 5px;
        }

        .nav::-webkit-scrollbar-track {

            background: transparent;
        }

        .nav::-webkit-scrollbar-thumb {

            background:
                rgba(214, 176, 78, 0.45);

            border-radius: 10px;
        }

        .nav::-webkit-scrollbar-thumb:hover {

            background:
                rgba(214, 176, 78, 0.75);
        }


        /* =====================================================
           NAVIGATION LINKS
        ===================================================== */

        .nav a {

            display: flex;

            align-items: center;

            gap: 13px;

            min-height: 47px;

            flex-shrink: 0;

            padding: 13px 14px;

            border-radius: 11px;

            color: #969eac;

            font-size: 13px;

            font-weight: 500;

            transition:
                background .25s ease,
                color .25s ease,
                border .25s ease,
                transform .25s ease;
        }

        .nav a i {

            width: 19px;

            min-width: 19px;

            text-align: center;

            font-size: 15px;
        }

        .nav a:hover {

            color: #ffffff;

            background:
                rgba(212, 175, 55, 0.08);

            transform: translateX(2px);
        }

        .nav a.active {

            color: #ffffff;

            background:

                linear-gradient(
                    90deg,
                    rgba(212, 175, 55, 0.18),
                    rgba(212, 175, 55, 0.05)
                );

            border-left:
                3px solid #d6b04e;

            padding-left: 11px;
        }

        .nav a.active i {

            color: #d6b04e;
        }


        /* =====================================================
           SWIMMING SPECIAL LINK
        ===================================================== */

        .nav a.swimming-link i {

            color: #65c7e8;
        }

        .nav a.swimming-link:hover {

            background:
                rgba(65, 190, 230, 0.08);
        }


        /* =====================================================
           SIDEBAR BOTTOM
        ===================================================== */

        .sidebar-bottom {

            flex-shrink: 0;

            margin-top: 5px;

            padding-top: 10px;

            border-top:
                1px solid rgba(255, 255, 255, 0.07);

            background:
                rgba(9, 12, 18, 0.96);
        }


        /* =====================================================
           USER BOX
        ===================================================== */

        .user-box {

            display: flex;

            align-items: center;

            gap: 10px;

            padding: 11px;

            margin-bottom: 7px;

            border:
                1px solid rgba(255, 255, 255, 0.07);

            background:
                rgba(255, 255, 255, 0.025);

            border-radius: 12px;
        }

        .avatar {

            width: 37px;
            height: 37px;

            flex-shrink: 0;

            border-radius: 50%;

            display: flex;

            align-items: center;

            justify-content: center;

            background:

                linear-gradient(
                    135deg,
                    #d6b04e,
                    #765a16
                );

            color: #111;

            font-weight: 800;
        }

        .user-info {

            min-width: 0;

            flex: 1;
        }

        .user-info strong {

            display: block;

            font-size: 12px;

            white-space: nowrap;

            overflow: hidden;

            text-overflow: ellipsis;
        }

        .user-info span {

            display: block;

            margin-top: 3px;

            font-size: 10px;

            color: #737c89;
        }


        /* =====================================================
           LOGOUT
        ===================================================== */

        .logout {

            display: flex;

            align-items: center;

            justify-content: flex-start;

            gap: 10px;

            min-height: 43px;

            padding: 11px 14px;

            color: #bd7474;

            border-radius: 10px;

            font-size: 12px;

            transition:
                background .2s ease,
                color .2s ease;
        }

        .logout i {

            width: 19px;

            text-align: center;
        }

        .logout:hover {

            background:
                rgba(220, 80, 80, 0.09);

            color: #e99090;
        }


        /* =====================================================
           MAIN
        ===================================================== */

        .main {

            margin-left: 270px;

            width:
                calc(100% - 270px);

            min-height: 100vh;

            padding:
                28px 35px 40px;
        }


        /* =====================================================
           TOP BAR
        ===================================================== */

        .topbar {

            display: flex;

            justify-content: space-between;

            align-items: center;

            margin-bottom: 28px;
        }

        .welcome h1 {

            font-size: 27px;

            font-weight: 800;

            letter-spacing: -0.5px;
        }

        .welcome p {

            margin-top: 6px;

            color: #89919e;

            font-size: 12px;
        }

        .date-box {

            padding:
                10px 15px;

            background:
                rgba(10, 14, 21, 0.72);

            border:
                1px solid rgba(255, 255, 255, 0.09);

            border-radius: 10px;

            color: #aeb5c0;

            font-size: 11px;

            backdrop-filter: blur(12px);
        }

        .date-box i {

            color: #d6b04e;
        }


        /* =====================================================
           HERO
        ===================================================== */

.hero {
    position: relative;
    isolation: isolate;
    padding: 32px 34px;
    min-height: 260px;
    border-radius: 18px;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.10);

    background:
        linear-gradient(
            110deg,
            rgba(212, 175, 55, 0.13),
            rgba(10, 14, 21, 0.78)
        );

    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.30);
    margin-bottom: 25px;
    backdrop-filter: blur(8px);
    background-color: #10151c;
}

.hero-content {
    position: relative;
    z-index: 2;
    max-width: 650px;
}

.hero h2 {
    font-size: 25px;
    margin-bottom: 8px;
}

.hero p {
    color: #c1c7d0;
    font-size: 12px;
    line-height: 1.7;
}

.hero-slideshow {
    position: absolute;
    inset: 0;
    z-index: 0;
    overflow: hidden;
    pointer-events: none;
}

.hero-slide {
    position: absolute;
    inset: 0;
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    opacity: 0;
    transform: scale(1.08);
    animation: hotelSlideShow 24s infinite;
}

.hero-slide:nth-child(1) {
    background-image: url("https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=1800&q=85");
    animation-delay: 0s;
}

.hero-slide:nth-child(2) {
    background-image: url("https://images.unsplash.com/photo-1542314831-068cd1dbfeeb?auto=format&fit=crop&w=1800&q=85");
    animation-delay: 8s;
}

.hero-slide:nth-child(3) {
    background-image: url("https://images.unsplash.com/photo-1571896349842-33c89424de2d?auto=format&fit=crop&w=1800&q=85");
    animation-delay: 16s;
}

.hero-slideshow::after {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(
        90deg,
        rgba(5, 8, 12, 0.94) 0%,
        rgba(5, 8, 12, 0.78) 52%,
        rgba(5, 8, 12, 0.48) 100%
    );
}

@keyframes hotelSlideShow {
    0% {
        opacity: 0;
        transform: scale(1.08);
    }

    5% {
        opacity: 0.60;
    }

    28% {
        opacity: 0.60;
        transform: scale(1);
    }

    34% {
        opacity: 0;
        transform: scale(1.02);
    }

    100% {
        opacity: 0;
        transform: scale(1.08);
    }
}

@media (max-width: 768px) {
    .hero {
        min-height: 240px;
        padding: 26px 22px;
        border-radius: 14px;
    }

    .hero h2 {
        font-size: 22px;
    }

    .hero p {
        font-size: 12px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .hero-slide {
        animation: none;
    }

    .hero-slide:first-child {
        opacity: 0.60;
        transform: none;
    }
}


        .hero::before {

            content: "";

            position: absolute;

            width: 300px;
            height: 300px;

            right: -100px;
            top: -160px;

            border-radius: 50%;

            background:
                rgba(212, 175, 55, 0.12);

            filter: blur(3px);
        }

        .hero-content {

            position: relative;

            z-index: 2;

            max-width: 650px;
        }

        .hero-label {

            display: inline-block;

            padding:
                6px 10px;

            border-radius: 20px;

            background:
                rgba(212, 175, 55, 0.12);

            color: #d8b75c;

            font-size: 9px;

            text-transform: uppercase;

            letter-spacing: 1.5px;

            margin-bottom: 12px;
        }

        .hero h2 {

            font-size: 25px;

            margin-bottom: 8px;
        }

        .hero p {

            color: #9ca4b1;

            font-size: 12px;

            line-height: 1.7;
        }


        /* =====================================================
           STATISTICS
        ===================================================== */

        .stats {

            display: grid;

            grid-template-columns:
                repeat(4, minmax(0, 1fr));

            gap: 15px;

            margin-bottom: 25px;
        }

        .card {

.stats .card:nth-child(1) .card-icon {
    background: rgba(37, 99, 235, 0.20);
    color: #60A5FA;
}
.stats .card:nth-child(2) .card-icon {
    background: rgba(13, 148, 136, 0.20);
    color: #2DD4BF;
}
.stats .card:nth-child(3) .card-icon {
    background: rgba(124, 58, 237, 0.20);
    color: #C4B5FD;
}
.stats .card:nth-child(4) .card-icon {
    background: rgba(234, 88, 12, 0.20);
    color: #FB923C;
}

.stats .card:nth-child(1) {
    border-top: 3px solid #3B82F6;
}
.stats .card:nth-child(2) {
    border-top: 3px solid #14B8A6;
}
.stats .card:nth-child(3) {
    border-top: 3px solid #8B5CF6;
}
.stats .card:nth-child(4) {
    border-top: 3px solid #F97316;
}


            position: relative;

            padding: 20px;

            min-height: 135px;

            border-radius: 15px;

            background:
                rgba(11, 15, 22, 0.83);

            border:
                1px solid rgba(255, 255, 255, 0.08);

            backdrop-filter: blur(14px);

            -webkit-backdrop-filter: blur(14px);

            overflow: hidden;

            transition:
                transform 0.25s,
                border-color 0.25s;
        }

        .card:hover {

            transform: translateY(-4px);

            border-color:
                rgba(212, 175, 55, 0.30);
        }

        .card::after {

            content: "";

            position: absolute;

            width: 80px;
            height: 80px;

            right: -30px;
            bottom: -35px;

            border-radius: 50%;

            background:
                rgba(212, 175, 55, 0.06);
        }

        .card-icon {

            width: 37px;
            height: 37px;

            border-radius: 10px;

            display: flex;

            align-items: center;

            justify-content: center;

            background:
                rgba(212, 175, 55, 0.10);

            color: #d5b255;

            margin-bottom: 15px;
        }

        .card-title {

            font-size: 10px;

            color: #7f8794;

            text-transform: uppercase;

            letter-spacing: 1px;
        }

        .card-value {

            margin-top: 5px;

            font-size: 25px;

            font-weight: 800;
        }


        /* =====================================================
           SWIMMING OVERVIEW
        ===================================================== */

        .swimming-section {

            margin-bottom: 25px;
        }

        .section-title {

            display: flex;

            align-items: center;

            justify-content: space-between;

            margin-bottom: 13px;
        }

        .section-title h3 {

            font-size: 14px;
        }

        .section-title span {

            color: #737c89;

            font-size: 10px;
        }

        .swimming-grid {

            display: grid;

            grid-template-columns:
                repeat(4, minmax(0, 1fr));

            gap: 15px;
        }

        .swim-card {

            position: relative;

            padding: 18px;

            min-height: 120px;

            border-radius: 15px;

            background:
                rgba(8, 18, 25, 0.84);

            border:
                1px solid rgba(75, 190, 225, 0.12);

            backdrop-filter: blur(14px);

            transition:
                transform .25s,
                border-color .25s;
        }

        .swim-card:hover {

            transform: translateY(-3px);

            border-color:
                rgba(75, 190, 225, 0.30);
        }

        .swim-icon {

            width: 35px;
            height: 35px;

            display: flex;

            align-items: center;

            justify-content: center;

            border-radius: 9px;

            background:
                rgba(65, 190, 230, 0.10);

            color: #65c7e8;

            margin-bottom: 12px;
        }

        .swim-title {

            font-size: 10px;

            color: #7f8794;

            text-transform: uppercase;

            letter-spacing: 1px;
        }

        .swim-value {

            margin-top: 5px;

            font-size: 23px;

            font-weight: 800;
        }

        .swim-link {

            display: inline-flex;

            align-items: center;

            gap: 6px;

            margin-top: 8px;

            font-size: 10px;

            color: #65c7e8;
        }


        /* =====================================================
           CONTENT GRID
        ===================================================== */

        .content-grid {

            display: grid;

            grid-template-columns:
                1.4fr 1fr;

            gap: 18px;
        }

        .panel {

            background:
                rgba(10, 14, 21, 0.84);

            border:
                1px solid rgba(255, 255, 255, 0.08);

            border-radius: 16px;

            padding: 22px;

            backdrop-filter: blur(15px);

            -webkit-backdrop-filter: blur(15px);
        }

        .panel-header {

            display: flex;

            justify-content: space-between;

            align-items: center;

            margin-bottom: 20px;
        }

        .panel-header h3 {

            font-size: 14px;
        }

        .panel-header span {

            font-size: 10px;

            color: #727b88;
        }


        /* =====================================================
           QUICK ACTIONS
        ===================================================== */

        .quick-actions {

            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 10px;
        }

        .quick-action {

            display: flex;

            align-items: center;

            gap: 11px;

            padding: 14px;

            border:
                1px solid rgba(255, 255, 255, 0.06);

            background:
                rgba(255, 255, 255, 0.025);

            border-radius: 11px;

            transition: 0.2s;
        }

        .quick-action:hover {

            background:
                rgba(212, 175, 55, 0.07);

            border-color:
                rgba(212, 175, 55, 0.20);

            transform: translateY(-1px);
        }

        .quick-action i {

            color: #d5b255;

            font-size: 14px;

            width: 18px;

            text-align: center;
        }

        .quick-action span {

            font-size: 11px;

            color: #aeb5bf;
        }


        /* =====================================================
           REVENUE PANEL
        ===================================================== */

        .revenue-box {

            padding: 22px;

            border-radius: 13px;

            background:

                linear-gradient(
                    135deg,
                    rgba(212, 175, 55, 0.11),
                    rgba(255, 255, 255, 0.02)
                );

            border:
                1px solid rgba(212, 175, 55, 0.12);
        }

        .revenue-label {

            font-size: 10px;

            color: #858e9b;

            text-transform: uppercase;

            letter-spacing: 1px;
        }

        .revenue-value {

            margin-top: 8px;

            font-size: 30px;

            font-weight: 800;

            color: #e0c166;
        }

        .revenue-small {

            margin-top: 8px;

            font-size: 10px;

            color: #78818e;
        }

        .revenue-small strong {

            color: #aeb6c0;
        }


        /* =====================================================
           LUXURY MOTION UPGRADE
        ===================================================== */

        .background {
            animation: backgroundDrift 28s ease-in-out infinite alternate;
            will-change: transform, background-position;
        }

        @keyframes backgroundDrift {
            from { transform: scale(1.01); background-position: 48% 50%; }
            to   { transform: scale(1.08); background-position: 54% 46%; }
        }

        .hero {
            isolation: isolate;
            background-color: #10151c;
        }

        .hero-slideshow {
            position: absolute;
            inset: 0;
            z-index: 0;
            overflow: hidden;
            pointer-events: none;
        }

       .hero-slide {
    position: absolute;
    inset: 0;
    background-size: cover;
    background-position: center;
    opacity: 0;
    transform: scale(1.08);
    animation: hotelSlideShow 24s infinite;
}

        .hero-slide:nth-child(1) {
            background-image: url("https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=1800&q=85");
            animation-delay: 0s;
        }

        .hero-slide:nth-child(2) {
            background-image: url("https://images.unsplash.com/photo-1542314831-068cd1dbfeeb?auto=format&fit=crop&w=1800&q=85");
            animation-delay: 8s;
        }

        .hero-slide:nth-child(3) {
            background-image: url("https://images.unsplash.com/photo-1571896349842-33c89424de2d?auto=format&fit=crop&w=1800&q=85");
            animation-delay: 16s;
        }

        .hero-slideshow::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, rgba(5, 8, 12, .94) 0%, rgba(5, 8, 12, .78) 52%, rgba(5, 8, 12, .48) 100%);
        }

        @keyframes hotelSlideShow {
            0%   { opacity: 0; transform: scale(1.08); }
            5%   { opacity: .60; }
            28%  { opacity: .60; transform: scale(1); }
            34%  { opacity: 0; transform: scale(1.02); }
            100% { opacity: 0; transform: scale(1.08); }
        }

        .hero-label,
        .hero h2,
        .hero p {
            opacity: 0;
            animation: textRise .8s cubic-bezier(.2,.7,.2,1) forwards;
        }

        .hero-label { animation-delay: .12s; }
        .hero h2 {
            animation-delay: .28s;
            background: linear-gradient(90deg, #fff7d7, #d8b75c, #ffffff, #d8b75c);
            background-size: 220% auto;
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            animation-name: textRise, goldShimmer;
            animation-duration: .8s, 7s;
            animation-delay: .28s, 1s;
            animation-fill-mode: forwards, both;
            animation-iteration-count: 1, infinite;
        }
        .hero p { animation-delay: .45s; }

        @keyframes textRise {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @keyframes goldShimmer {
            0%, 100% { background-position: 0% center; }
            50% { background-position: 100% center; }
        }

        .topbar {
            animation: textRise .65s ease both;
        }

        .card, .swim-card, .panel {
            animation: cardReveal .7s cubic-bezier(.2,.7,.2,1) both;
        }
        .card:nth-child(2) { animation-delay: .08s; }
        .card:nth-child(3) { animation-delay: .16s; }
        .card:nth-child(4) { animation-delay: .24s; }
        .swim-card:nth-child(2) { animation-delay: .07s; }
        .swim-card:nth-child(3) { animation-delay: .14s; }
        .swim-card:nth-child(4) { animation-delay: .21s; }
        .content-grid .panel:nth-child(2) { animation-delay: .14s; }

        @keyframes cardReveal {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .card-icon, .swim-icon, .logo-icon {
            transition: transform .35s ease, box-shadow .35s ease;
        }
        .card:hover .card-icon,
        .swim-card:hover .swim-icon,
        .logo:hover .logo-icon {
            transform: translateY(-2px) rotate(-4deg);
            box-shadow: 0 8px 26px rgba(212, 175, 55, .20);
        }

        .quick-action {
            animation: textRise .55s ease both;
        }
        .quick-action:nth-child(2) { animation-delay: .04s; }
        .quick-action:nth-child(3) { animation-delay: .08s; }
        .quick-action:nth-child(4) { animation-delay: .12s; }
        .quick-action:nth-child(5) { animation-delay: .16s; }
        .quick-action:nth-child(6) { animation-delay: .20s; }
        .quick-action:nth-child(7) { animation-delay: .24s; }
        .quick-action:nth-child(8) { animation-delay: .28s; }

        .date-box {
            transition: border-color .25s ease, transform .25s ease;
        }
        .date-box:hover {
            border-color: rgba(214, 176, 78, .45);
            transform: translateY(-2px);
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
                scroll-behavior: auto !important;
                transition-duration: .01ms !important;
            }
            .background { transform: none; }
        }

        /* =====================================================
           RESPONSIVE - 1100
        ===================================================== */

        @media (max-width: 1100px) {

            .stats {

                grid-template-columns:
                    repeat(2, 1fr);
            }

            .swimming-grid {

                grid-template-columns:
                    repeat(2, 1fr);
            }

            .content-grid {

                grid-template-columns:
                    1fr;
            }
        }


        /* =====================================================
           RESPONSIVE - 800
        ===================================================== */

        @media (max-width: 800px) {

            .sidebar {

                width: 75px;

                padding:
                    20px 10px 12px;
            }

            .logo {

                justify-content: center;

                padding-left: 0;

                padding-right: 0;
            }

            .logo-text,
            .nav-title,
            .nav a span,
            .user-info,
            .logout span {

                display: none;
            }

            .nav {

                padding-right: 0;
            }

            .nav a {

                justify-content: center;

                padding: 14px;
            }

            .nav a.active {

                padding-left: 14px;

                border-left: 0;

                border-bottom:
                    3px solid #d6b04e;
            }

            .user-box {

                justify-content: center;

                padding: 8px;
            }

            .logout {

                justify-content: center;

                padding: 12px;
            }

            .main {

                margin-left: 75px;

                width:
                    calc(100% - 75px);

                padding:
                    20px;
            }
        }


        /* =====================================================
           RESPONSIVE - 550
        ===================================================== */

        @media (max-width: 550px) {

            .stats {

                grid-template-columns:
                    1fr;
            }

            .swimming-grid {

                grid-template-columns:
                    1fr;
            }

            .topbar {

                align-items: flex-start;

                flex-direction: column;

                gap: 12px;
            }

            .hero {

                padding: 22px;
            }

            .quick-actions {

                grid-template-columns:
                    1fr;
            }

            .welcome h1 {

                font-size: 22px;
            }

            .date-box {

                width: 100%;
            }
        }

        /* SUVA HOTEL — LUXURY GREEN & GOLD */
        :root {
            --suva-green: #17352a;
            --suva-green-light: #234637;
            --suva-gold: #d8b66a;
            --suva-gold-light: #f0dba2;
        }

        .sidebar {
            background: rgba(12, 31, 24, 0.97);
            border-right: 1px solid rgba(216, 182, 106, 0.25);
        }

        .nav a.active {
            border-left-color: var(--suva-gold);
            color: var(--suva-gold-light);
        }

        .card,
        .swim-card {
            border-color: rgba(216, 182, 106, 0.25);
        }

        .card:hover,
        .swim-card:hover {
            border-color: var(--suva-gold);
        }

        .card-icon,
        .swim-icon {
            color: var(--suva-gold);
        }

        /* ==========================================
           SUVA HOTEL - FULL PAGE ANIMATED BACKGROUND
        ========================================== */

        html {
            min-height: 100%;
            background: #030c09;
        }

        body {
            min-height: 100vh;
            position: relative;
            isolation: isolate;
            background: transparent !important;
        }

        .hotel-background {
            position: fixed;
            inset: 0;
            z-index: -1;
            overflow: hidden;
            pointer-events: none;
            background: #030c09;
        }

        .hotel-background .hotel-bg-slide {
            position: absolute;
            inset: -3%;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            opacity: 0;
            transform: scale(1.04);
            animation: hotelBackgroundFade 36s infinite;
            will-change: opacity, transform;
        }

        .hotel-background .hotel-bg-slide:nth-child(1) {
            background-image: url("https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=1920&q=85");
            animation-delay: 0s;
        }

        .hotel-background .hotel-bg-slide:nth-child(2) {
            background-image: url("https://images.unsplash.com/photo-1542314831-068cd1dbfeeb?auto=format&fit=crop&w=1920&q=85");
            animation-delay: 12s;
        }

        .hotel-background .hotel-bg-slide:nth-child(3) {
            background-image: url("https://images.unsplash.com/photo-1571896349842-33c89424de2d?auto=format&fit=crop&w=1920&q=85");
            animation-delay: 24s;
        }

        .hotel-background::after {
            content: "";
            position: absolute;
            inset: 0;
            background:
                linear-gradient(
                    115deg,
                    rgba(2, 10, 7, 0.88),
                    rgba(3, 12, 9, 0.76) 50%,
                    rgba(2, 8, 7, 0.87)
                ),
                linear-gradient(
                    0deg,
                    rgba(212, 175, 55, 0.035),
                    transparent 55%
                );
        }

        @keyframes hotelBackgroundFade {
            0% {
                opacity: 0;
                transform: scale(1.04);
            }

            5% {
                opacity: 0.65;
            }

            28% {
                opacity: 0.65;
                transform: scale(1);
            }

            35% {
                opacity: 0;
                transform: scale(1.02);
            }

            100% {
                opacity: 0;
                transform: scale(1.04);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .hotel-background .hotel-bg-slide {
                animation: none;
                transform: none;
            }

            .hotel-background .hotel-bg-slide:first-child {
                opacity: 0.5;
            }
        }

    </style>

</head>


<body>
    
<div class="hotel-background" aria-hidden="true">
    <div class="hotel-bg-slide"></div>
    <div class="hotel-bg-slide"></div>
    <div class="hotel-bg-slide"></div>
</div>


    <!-- =====================================================
         HOTEL BACKGROUND
    ====================================================== -->

    <div class="background"></div>


    <!-- =====================================================
         MAIN LAYOUT
    ====================================================== -->

    <div class="layout">


        <!-- =================================================
             SIDEBAR
        ================================================== -->

        <aside class="sidebar">


            <!-- =================================================
                 LOGO
            ================================================== -->

            <div class="logo">

                <div class="logo-icon">

                    <i class="fa-solid fa-hotel"></i>

                </div>

                <div class="logo-text">

                    <h2>
                        SUVA HOTEL
                    </h2>

                    <span>
                        Management System
                    </span>

                </div>

            </div>


            <!-- =================================================
                 MENU TITLE
            ================================================== -->

            <div class="nav-title">

                Main Menu

            </div>


            <!-- =================================================
                 SCROLLABLE NAVIGATION
            ================================================== -->

            <nav class="nav">


                <!-- DASHBOARD -->

                <a
                    href="dashboard.php"
                    class="active"
                >

                    <i class="fa-solid fa-chart-pie"></i>

                    <span>
                        Dashboard
                    </span>

                </a>


                <!-- ROOMS -->

                <a href="rooms/view.php">

                    <i class="fa-solid fa-bed"></i>

                    <span>
                        Rooms
                    </span>

                </a>
                

<a href="checkin.php">
    <i class="fa-solid fa-right-to-bracket"></i> Guest Check-in
</a>

<a href="checkout/add.php">
    <i class="fa-solid fa-right-from-bracket"></i> Guest Checkout
</a>

<li>
    <a href="housekeeping/index.php">
        Housekeeping
    </a>
</li>





                <!-- GUESTS -->

                <a href="guests/view.php">

                    <i class="fa-solid fa-users"></i>

                    <span>
                        Guests
                    </span>

                </a>


                <!-- BOOKINGS -->

                <a href="bookings/view.php">

                    <i class="fa-solid fa-calendar-check"></i>

                    <span>
                        Bookings
                    </span>

                </a>


                <!-- PAYMENTS -->

                <a href="payment/view.php">

                    <i class="fa-solid fa-credit-card"></i>

                    <span>
                        Payments
                    </span>

                </a>


                <!-- INVOICES -->

                <a href="invoice/view.php">

                    <i class="fa-solid fa-file-invoice"></i>

                    <span>
                        Invoices
                    </span>

                </a>


                <!-- INVENTORY -->

                <a href="inventory/view.php">

                    <i class="fa-solid fa-boxes-stacked"></i>

                    <span>
                        Inventory
                    </span>

                </a>


                <!-- RESTAURANT -->

                <a href="restaurant/index.php">

                    <i class="fa-solid fa-utensils"></i>

                    <span>
                        Restaurant
                    </span>

                </a>


                <!-- SWIMMING -->

                <a
                    href="swimming/view.php"
                    class="swimming-link"
                >

                    <i class="fa-solid fa-person-swimming"></i>

                    <span>
                        Swimming Pool
                    </span>

                </a>


                <!-- REPORTS -->

                <a href="reports/index.php">

                    <i class="fa-solid fa-chart-line"></i>

                    <span>
                        Reports
                    </span>

                </a>


                <!-- STAFF -->

                <?php if ($userRole === 'admin'): ?>

                   
<a href="staff_management/index.php">
    <span>👥</span>
    <span>Staff Management</span>
</a>


                <?php endif; ?>


            </nav>


            <!-- =================================================
                 FIXED SIDEBAR BOTTOM
            ================================================== -->

            <div class="sidebar-bottom">


                <!-- USER -->

                <div class="user-box">

                    <div class="avatar">

                        <?= htmlspecialchars($userInitial) ?>

                    </div>

                    <div class="user-info">

                        <strong>

                            <?= htmlspecialchars($userName) ?>

                        </strong>

                        <span>

                            <?= htmlspecialchars(
                                ucfirst($userRole)
                            ) ?>

                        </span>

                    </div>

                </div>


                <!-- LOGOUT -->

                <a
                    href="logout.php"
                    class="logout"
                >

                    <i class="fa-solid fa-right-from-bracket"></i>

                    <span>
                        Logout
                    </span>

                </a>


            </div>

        </aside>


        <!-- =================================================
             MAIN CONTENT
        ================================================== -->

        <main class="main">


            <!-- =================================================
                 TOP BAR
            ================================================== -->

            <div class="topbar">

                <div class="welcome">

                    <h1>

                        Welcome back,
                        <?= htmlspecialchars($userName) ?>

                    </h1>

                    <p>

                        Here's what's happening at
                        Suva Hotel today.

                    </p>

                </div>


                <div class="date-box">

                    <i class="fa-regular fa-calendar"></i>

                    &nbsp;

                    <?= htmlspecialchars($currentDate) ?>

                </div>

            </div>


            <!-- =================================================
                 HERO
            ================================================== -->

            <section class="hero">

                <div class="hero-slideshow" aria-hidden="true">
                    <div class="hero-slide"></div>
                    <div class="hero-slide"></div>
                    <div class="hero-slide"></div>
                </div>

                <div class="hero-content">

                    <span class="hero-label">

                        Premium Hotel Management

                    </span>

                    <h2>

                        Manage your hotel with confidence.

                    </h2>

                    <p>

                        Monitor rooms, guests, bookings,
                        payments, inventory, restaurant,
                        swimming pool and hotel operations
                        from one centralized dashboard.

                    </p>

                </div>

            </section>


            <!-- =================================================
                 MAIN STATISTICS
            ================================================== -->

            <section class="stats">


                <!-- ROOMS -->

                <div class="card">

                    <div class="card-icon">

                        <i class="fa-solid fa-bed"></i>

                    </div>

                    <div class="card-title">

                        Total Rooms

                    </div>

                    <div class="card-value">

                        <?= number_format($totalRooms) ?>

                    </div>

                </div>


                <!-- GUESTS -->

                <div class="card">

                    <div class="card-icon">

                        <i class="fa-solid fa-users"></i>

                    </div>

                    <div class="card-title">

                        Total Guests

                    </div>

                    <div class="card-value">

                        <?= number_format($totalGuests) ?>

                    </div>

                </div>


                <!-- BOOKINGS -->

                <div class="card">

                    <div class="card-icon">

                        <i class="fa-solid fa-calendar-check"></i>

                    </div>

                    <div class="card-title">

                        Total Bookings

                    </div>

                    <div class="card-value">

                        <?= number_format($totalBookings) ?>

                    </div>

                </div>


                <!-- INVENTORY -->

                <div class="card">

                    <div class="card-icon">

                        <i class="fa-solid fa-box"></i>

                    </div>

                    <div class="card-title">

                        Inventory Products

                    </div>

                    <div class="card-value">

                        <?= number_format($totalProducts) ?>

                    </div>

                </div>


            </section>


            <!-- =================================================
                 SWIMMING OVERVIEW
            ================================================== -->

            <section class="swimming-section">


                <div class="section-title">

                    <h3>

                        <i
                            class="fa-solid fa-person-swimming"
                            style="color:#65c7e8;"
                        ></i>

                        &nbsp;

                        Swimming Pool Overview

                    </h3>

                    <span>

                        <a
                            href="swimming/view.php"
                            style="color:#65c7e8;"
                        >
                            Manage Swimming →
                        </a>

                    </span>

                </div>


                <div class="swimming-grid">


                    <!-- TOTAL -->

                    <div class="swim-card">

                        <div class="swim-icon">

                            <i class="fa-solid fa-users"></i>

                        </div>

                        <div class="swim-title">

                            Total Visitors

                        </div>

                        <div class="swim-value">

                            <?= number_format(
                                $totalSwimmingVisitors
                            ) ?>

                        </div>

                    </div>


                    <!-- TODAY -->

                    <div class="swim-card">

                        <div class="swim-icon">

                            <i class="fa-solid fa-calendar-day"></i>

                        </div>

                        <div class="swim-title">

                            Today's Visitors

                        </div>

                        <div class="swim-value">

                            <?= number_format(
                                $todaySwimmingVisitors
                            ) ?>

                        </div>

                    </div>


                    <!-- ACTIVE -->

                    <div class="swim-card">

                        <div class="swim-icon">

                            <i class="fa-solid fa-person-running"></i>

                        </div>

                        <div class="swim-title">

                            Active Visitors

                        </div>

                        <div class="swim-value">

                            <?= number_format(
                                $activeSwimmingVisitors
                            ) ?>

                        </div>

                    </div>


                    <!-- REVENUE -->

                    <div class="swim-card">

                        <div class="swim-icon">

                            <i class="fa-solid fa-water"></i>

                        </div>

                        <div class="swim-title">

                            Swimming Revenue

                        </div>

                        <div class="swim-value">

                            Rs.
                            <?= number_format(
                                (float)$swimmingRevenue,
                                2
                            ) ?>

                        </div>

                    </div>


                </div>

            </section>


            <!-- =================================================
                 LOWER CONTENT
            ================================================== -->

            <section class="content-grid">


                <!-- =================================================
                     QUICK ACTIONS
                ================================================== -->

                <div class="panel">

                    <div class="panel-header">

                        <h3>

                            Quick Actions

                        </h3>

                        <span>

                            Manage hotel

                        </span>

                    </div>


                    <div class="quick-actions">


                        <!-- ADD ROOM -->

                        <a
                            href="rooms/add.php"
                            class="quick-action"
                        >

                            <i class="fa-solid fa-plus"></i>

                            <span>
                                Add Room
                            </span>

                        </a>


                        <!-- NEW BOOKING -->

                        <a
                            href="bookings/add.php"
                            class="quick-action"
                        >

                            <i class="fa-solid fa-calendar-plus"></i>

                            <span>
                                New Booking
                            </span>

                        </a>


                        <!-- ADD GUEST -->

                        <a
                            href="guests/add.php"
                            class="quick-action"
                        >

                            <i class="fa-solid fa-user-plus"></i>

                            <span>
                                Add Guest
                            </span>

                        </a>


                        <!-- PAYMENT -->

                        <a
                            href="payment/add.php"
                            class="quick-action"
                        >

                            <i class="fa-solid fa-money-bill-wave"></i>

                            <span>
                                Record Payment
                            </span>

                        </a>


                        <!-- PRODUCT -->

                        <a
                            href="inventory/add.php"
                            class="quick-action"
                        >

                            <i class="fa-solid fa-box-open"></i>

                            <span>
                                Add Product
                            </span>

                        </a>


                        <!-- SWIMMING -->

                        <a
                            href="swimming/view.php"
                            class="quick-action"
                        >

                            <i class="fa-solid fa-person-swimming"></i>

                            <span>
                                Swimming Pool
                            </span>

                        </a>


                        <!-- REPORT -->

                        <a
                            href="reports/index.php"
                            class="quick-action"
                        >

                            <i class="fa-solid fa-chart-column"></i>

                            <span>
                                View Reports
                            </span>

                        </a>
                        <!-- EXPENSES -->

                        <a
                            href="expenses/view.php"
                            class="quick-action"
                        >

                            <i class="fa-solid fa-money-bill-transfer"></i>

                            <span>
                                Expense Management
                            </span>

                        </a>


                        <!-- RESTAURANT -->

                        <a
                            href="restaurant/index.php"
                            class="quick-action"
                        >

                            <i class="fa-solid fa-utensils"></i>

                            <span>
                                Restaurant
                            </span>

                        </a>


                    </div>

                </div>


                <!-- =================================================
                     FINANCIAL OVERVIEW
                ================================================== -->

                <div class="panel">

                    <div class="panel-header">

                        <h3>

                            Financial Overview

                        </h3>

                        <span>

                            All time

                        </span>

                    </div>


                    <div class="revenue-box">


                        <div class="revenue-label">

                            Total Payments

                        </div>


                        <div class="revenue-value">

                            Rs.

                            <?= number_format(
                                (float)$totalRevenue,
                                2
                            ) ?>

                        </div>


                        <div class="revenue-small">

                            Expenses:

                            <strong>

                                Rs.

                                <?= number_format(
                                    (float)$totalExpenses,
                                    2
                                ) ?>

                            </strong>

                        </div>


                        <div class="revenue-small">

                            Net:

                            <strong>

                                Rs.

                                <?= number_format(
                                    (float)$totalRevenue -
                                    (float)$totalExpenses,
                                    2
                                ) ?>

                            </strong>

                        </div>


                        <div class="revenue-small">

                            Swimming Revenue:

                            <strong>

                                Rs.

                                <?= number_format(
                                    (float)$swimmingRevenue,
                                    2
                                ) ?>

                            </strong>

                        </div>


                    </div>

                </div>


            </section>


        </main>

    </div>

<!-- GSAP animation library -->
<script src="https://cdn.jsdelivr.net/npm/gsap@3.15.0/dist/gsap.min.js"></script>

<!-- ScrollTrigger plugin -->
<script src="https://cdn.jsdelivr.net/npm/gsap@3.15.0/dist/ScrollTrigger.min.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function () {
    if (!window.gsap || !window.ScrollTrigger) {
        return;
    }

    if (window.matchMedia(
        "(prefers-reduced-motion: reduce)"
    ).matches) {
        return;
    }

    gsap.registerPlugin(ScrollTrigger);

    // Reveal the welcome section and hero as they enter view.
    gsap.utils.toArray(
        ".topbar, .hero-content"
    ).forEach(function (element) {
        gsap.from(element, {
            y: 22,
            autoAlpha: 0,
            duration: 0.7,
            ease: "power2.out",
            scrollTrigger: {
                trigger: element,
                start: "top 90%",
                once: true
            }
        });
    });

    // Reveal the hotel statistics in sequence.
    gsap.utils.toArray(
        ".stats, .swimming-grid"
    ).forEach(function (group) {
        gsap.from(group.children, {
            y: 24,
            autoAlpha: 0,
            duration: 0.65,
            stagger: 0.12,
            ease: "power2.out",
            scrollTrigger: {
                trigger: group,
                start: "top 88%",
                once: true
            }
        });
    });

    // Reveal lower dashboard sections.
    gsap.utils.toArray(
        ".content-grid, .quick-actions"
    ).forEach(function (element) {
        gsap.from(element, {
            y: 25,
            autoAlpha: 0,
            duration: 0.7,
            ease: "power2.out",
            scrollTrigger: {
                trigger: element,
                start: "top 88%",
                once: true
            }
        });
    });
});
</script>
</body>

</html>