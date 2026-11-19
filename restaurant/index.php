<?php

// ============================================================
// SUVA HOTEL - RESTAURANT DASHBOARD
// PostgreSQL Version
// File: /restaurant/index.php
// ============================================================

session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// ============================================================
// AUTHENTICATION
// ============================================================

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/database.php';

$role = strtolower(trim($_SESSION['role'] ?? ''));

if ($role !== 'admin' && $role !== 'staff') {
    header("Location: ../login.php");
    exit;
}

$userName = $_SESSION['username'] ?? 'Administrator';

// ============================================================
// DATABASE CONNECTION
// ============================================================

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

function scalarValue($db, $sql, $default = 0)
{
    $result = @pg_query($db, $sql);

    if (!$result) {
        return $default;
    }

    $row = pg_fetch_row($result);

    if (!$row || $row[0] === null) {
        return $default;
    }

    return $row[0];
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

    $result = @pg_query_params($db, $sql, [$table]);

    if (!$result) {
        return false;
    }

    $row = pg_fetch_row($result);

    return ($row && $row[0] === 't');
}

// ============================================================
// DASHBOARD URL
// ============================================================

if ($role === 'admin') {
    $dashboardUrl = '../dashboard.php';
} else {
    $dashboardUrl = '../staff_dashboard/staff_dashboard.php';
}

// ============================================================
// RESTAURANT ORDERS STATISTICS
// ============================================================

$totalOrders = 0;
$todayOrders = 0;
$pendingOrders = 0;
$preparingOrders = 0;
$readyOrders = 0;
$orderRevenue = 0;

$ordersTableExists = tableExists($db, 'restaurant_orders');

if ($ordersTableExists) {

    // Total orders
    $totalOrders = (int) scalarValue(
        $db,
        "SELECT COUNT(*) FROM public.restaurant_orders"
    );

    // Today's orders
    $todayOrders = (int) scalarValue(
        $db,
        "
        SELECT COUNT(*)
        FROM public.restaurant_orders
        WHERE created_at::date = CURRENT_DATE
        "
    );

    // Pending
    $pendingOrders = (int) scalarValue(
        $db,
        "
        SELECT COUNT(*)
        FROM public.restaurant_orders
        WHERE LOWER(TRIM(COALESCE(order_status, ''))) = 'pending'
        "
    );

    // Preparing
    $preparingOrders = (int) scalarValue(
        $db,
        "
        SELECT COUNT(*)
        FROM public.restaurant_orders
        WHERE LOWER(TRIM(COALESCE(order_status, ''))) = 'preparing'
        "
    );

    // Ready
    $readyOrders = (int) scalarValue(
        $db,
        "
        SELECT COUNT(*)
        FROM public.restaurant_orders
        WHERE LOWER(TRIM(COALESCE(order_status, ''))) = 'ready'
        "
    );

    // Revenue excluding cancelled orders
    $orderRevenue = (float) scalarValue(
        $db,
        "
        SELECT COALESCE(SUM(total_amount), 0)
        FROM public.restaurant_orders
        WHERE LOWER(TRIM(COALESCE(order_status, ''))) <> 'cancelled'
        "
    );
}

// ============================================================
// FOOD STATISTICS
// ============================================================

$totalFoods = 0;
$availableFoods = 0;
$unavailableFoods = 0;

$foodsTableExists = tableExists($db, 'restaurant_foods');

if ($foodsTableExists) {

    $totalFoods = (int) scalarValue(
        $db,
        "SELECT COUNT(*) FROM public.restaurant_foods"
    );

    $availableFoods = (int) scalarValue(
        $db,
        "
        SELECT COUNT(*)
        FROM public.restaurant_foods
        WHERE LOWER(TRIM(COALESCE(status, ''))) = 'available'
        "
    );

    $unavailableFoods = (int) scalarValue(
        $db,
        "
        SELECT COUNT(*)
        FROM public.restaurant_foods
        WHERE LOWER(TRIM(COALESCE(status, ''))) <> 'available'
        "
    );
}

// ============================================================
// FOOD IMAGE FUNCTION
// IMPORTANT:
// restaurant/index.php
//       ↓
// restaurant/food/images/
// Browser path:
// food/images/filename.jpg
// ============================================================

function getFoodImage($image)
{
    $image = trim((string)$image);

    if ($image === '') {
        return '';
    }

    // External image
    if (
        strpos($image, 'http://') === 0 ||
        strpos($image, 'https://') === 0
    ) {
        return $image;
    }

    // Normalize Windows paths
    $image = str_replace('\\', '/', $image);

    // Remove query string
    $image = explode('?', $image)[0];

    // Remove hash
    $image = explode('#', $image)[0];

    /*
     * Database may contain:
     *
     * momos.jpg
     * images/momos.jpg
     * food/images/momos.jpg
     * restaurant/food/images/momos.jpg
     *
     * We only need the filename because all food images
     * are stored in restaurant/food/images/
     */

    $filename = basename($image);

    if (
        $filename === '' ||
        $filename === '.' ||
        $filename === '..'
    ) {
        return '';
    }

    // Physical file location
    $physicalPath = __DIR__ . '/food/images/' . $filename;

    // Only return image if it actually exists
    if (is_file($physicalPath)) {
        return 'food/images/' . rawurlencode($filename);
    }

    return '';
}

// ============================================================
// FOOD PLACEHOLDER
// ============================================================

$foodPlaceholder =
    'data:image/svg+xml;charset=UTF-8,' .
    rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="520">
            <rect width="800" height="520" fill="#171c28"/>
            <circle cx="400" cy="210" r="85" fill="#242c3c"/>
            <text x="400" y="235"
                  text-anchor="middle"
                  font-size="72"
                  font-family="Arial">🍽</text>
            <text x="400" y="350"
                  text-anchor="middle"
                  fill="#9da6b7"
                  font-size="25"
                  font-family="Arial">No Image Available</text>
        </svg>'
    );

// ============================================================
// LOAD FOOD ITEMS
// ============================================================

$foods = [];
$foodError = '';

if ($foodsTableExists) {

    $foodSql = "
        SELECT
            id,
            food_name,
            category,
            image,
            price,
            status,
            created_at
        FROM public.restaurant_foods
        ORDER BY
            CASE
                WHEN LOWER(TRIM(COALESCE(status, ''))) = 'available'
                THEN 0
                ELSE 1
            END,
            category ASC,
            food_name ASC,
            id DESC
    ";

    $foodResult = @pg_query($db, $foodSql);

    if ($foodResult) {

        while ($row = pg_fetch_assoc($foodResult)) {

            $row['image_url'] = getFoodImage($row['image'] ?? '');

            $foods[] = $row;
        }

    } else {

        $foodError = pg_last_error($db);
    }
}

// ============================================================
// LOAD RECENT ORDERS
// IMPORTANT:
// Customer/Guest name is intentionally NOT selected.
// ============================================================

$recentOrders = [];

if ($ordersTableExists) {

    $ordersSql = "
        SELECT
            id,
            order_type,
            total_amount,
            payment_method,
            payment_status,
            order_status,
            created_at
        FROM public.restaurant_orders
        ORDER BY id DESC
        LIMIT 10
    ";

    $ordersResult = @pg_query($db, $ordersSql);

    if ($ordersResult) {

        while ($row = pg_fetch_assoc($ordersResult)) {
            $recentOrders[] = $row;
        }
    }
}

// ============================================================
// CATEGORY LIST
// ============================================================

$categories = [];

foreach ($foods as $food) {

    $category = trim((string)($food['category'] ?? ''));

    if ($category !== '' && !in_array($category, $categories, true)) {
        $categories[] = $category;
    }
}

sort($categories);

// ============================================================
// STATUS BADGE CLASS
// ============================================================

function orderStatusClass($status)
{
    $status = strtolower(trim((string)$status));

    switch ($status) {

        case 'pending':
            return 'status-pending';

        case 'preparing':
            return 'status-preparing';

        case 'ready':
            return 'status-ready';

        case 'completed':
        case 'complete':
            return 'status-completed';

        case 'cancelled':
        case 'canceled':
            return 'status-cancelled';

        default:
            return 'status-default';
    }
}

function paymentStatusClass($status)
{
    $status = strtolower(trim((string)$status));

    if ($status === 'paid') {
        return 'payment-paid';
    }

    if ($status === 'pending') {
        return 'payment-pending';
    }

    return 'payment-default';
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

    <title>Restaurant Dashboard | Suva Hotel</title>

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --gold: #d7b56d;
            --gold-light: #f1d58e;
            --gold-dark: #9f7b38;

            --bg: #080b12;
            --panel: rgba(17, 22, 32, 0.94);
            --panel-soft: rgba(22, 28, 40, 0.88);

            --border: rgba(215, 181, 109, 0.22);

            --text: #f4f4f5;
            --muted: #929aaa;

            --green: #42d392;
            --orange: #f4ad57;
            --blue: #63a9ff;
            --red: #ff6d75;

            --shadow: 0 25px 70px rgba(0, 0, 0, 0.42);
        }

        body {

            font-family:
                Inter,
                ui-sans-serif,
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;

            min-height: 100vh;

            color: var(--text);

            background:
                linear-gradient(
                    rgba(4, 6, 11, 0.88),
                    rgba(4, 6, 11, 0.94)
                ),
                url("../assets/image/hotel-lighting.jpg")
                center / cover fixed no-repeat;

            background-color: var(--bg);
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        button,
        input,
        select {
            font: inherit;
        }

        /* =====================================================
           TOP BAR
        ===================================================== */

        .topbar {

            position: sticky;
            top: 0;
            z-index: 100;

            min-height: 76px;

            display: flex;
            align-items: center;
            justify-content: space-between;

            padding: 14px 30px;

            background:
                rgba(7, 10, 16, 0.92);

            border-bottom:
                1px solid rgba(215, 181, 109, 0.18);

            backdrop-filter: blur(18px);
        }

        .brand {

            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-logo {

            width: 45px;
            height: 45px;

            display: grid;
            place-items: center;

            border-radius: 14px;

            background:
                linear-gradient(
                    145deg,
                    #f2d58d,
                    #9e7837
                );

            color: #0c0d11;

            font-size: 22px;

            box-shadow:
                0 10px 30px rgba(215, 181, 109, 0.18);
        }

        .brand-text h1 {

            font-size: 18px;
            font-weight: 800;

            letter-spacing: 0.3px;
        }

        .brand-text span {

            display: block;

            margin-top: 2px;

            color: var(--muted);

            font-size: 11px;

            text-transform: uppercase;

            letter-spacing: 1.7px;
        }

        .top-actions {

            display: flex;
            align-items: center;
            gap: 10px;
        }

        .top-btn {

            min-height: 42px;

            display: inline-flex;
            align-items: center;
            justify-content: center;

            padding: 0 15px;

            border-radius: 11px;

            border: 1px solid var(--border);

            background:
                rgba(255,255,255,0.035);

            color: #eee;

            font-size: 13px;
            font-weight: 700;

            transition: 0.25s ease;
        }

        .top-btn:hover {

            transform: translateY(-2px);

            border-color: rgba(215, 181, 109, 0.55);

            color: var(--gold-light);

            background:
                rgba(215, 181, 109, 0.08);
        }

        .top-btn.gold {

            color: #17120a;

            border-color: transparent;

            background:
                linear-gradient(
                    135deg,
                    var(--gold-light),
                    var(--gold)
                );
        }

        .top-btn.gold:hover {

            color: #17120a;

            box-shadow:
                0 12px 30px rgba(215, 181, 109, 0.2);
        }

        .user-info {

            display: flex;
            align-items: center;
            gap: 9px;

            padding-left: 10px;

            color: var(--muted);

            font-size: 12px;
        }

        .user-dot {

            width: 9px;
            height: 9px;

            border-radius: 50%;

            background: var(--green);

            box-shadow:
                0 0 14px rgba(66, 211, 146, 0.7);
        }

        /* =====================================================
           MAIN
        ===================================================== */

        .container {

            width: min(1450px, calc(100% - 44px));

            margin: 0 auto;

            padding: 34px 0 60px;
        }

        /* =====================================================
           HERO
        ===================================================== */

        .hero {

            position: relative;

            overflow: hidden;

            padding: 34px;

            margin-bottom: 24px;

            border:
                1px solid rgba(215, 181, 109, 0.2);

            border-radius: 25px;

            background:
                linear-gradient(
                    135deg,
                    rgba(22, 27, 39, 0.96),
                    rgba(10, 13, 20, 0.92)
                );

            box-shadow: var(--shadow);
        }

        .hero::before {

            content: "";

            position: absolute;

            width: 340px;
            height: 340px;

            right: -130px;
            top: -190px;

            border-radius: 50%;

            background:
                radial-gradient(
                    circle,
                    rgba(215,181,109,0.17),
                    transparent 67%
                );
        }

        .hero-content {

            position: relative;
            z-index: 1;

            display: flex;
            align-items: center;
            justify-content: space-between;

            gap: 25px;
        }

        .hero-title {

            color: var(--gold-light);

            font-size: 13px;

            text-transform: uppercase;

            letter-spacing: 2.4px;

            margin-bottom: 9px;
        }

        .hero h2 {

            font-size: clamp(27px, 4vw, 42px);

            line-height: 1.05;

            letter-spacing: -1px;
        }

        .hero p {

            max-width: 650px;

            margin-top: 12px;

            color: var(--muted);

            font-size: 14px;

            line-height: 1.7;
        }

        .hero-buttons {

            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .primary-btn,
        .secondary-btn {

            min-height: 52px;

            display: inline-flex;
            align-items: center;
            justify-content: center;

            padding: 0 20px;

            border-radius: 13px;

            font-weight: 800;

            font-size: 13px;

            transition: 0.25s ease;
        }

        .primary-btn {

            color: #15110a;

            background:
                linear-gradient(
                    135deg,
                    var(--gold-light),
                    var(--gold)
                );

            border: 1px solid transparent;

            box-shadow:
                0 12px 30px rgba(215, 181, 109, 0.13);
        }

        .primary-btn:hover {

            transform: translateY(-3px);

            box-shadow:
                0 17px 38px rgba(215, 181, 109, 0.22);
        }

        .secondary-btn {

            color: #eee;

            background:
                rgba(255,255,255,0.035);

            border:
                1px solid rgba(215,181,109,0.25);
        }

        .secondary-btn:hover {

            transform: translateY(-3px);

            color: var(--gold-light);

            border-color:
                rgba(215,181,109,0.55);
        }
        
/* Kitchen Dashboard - Luxury Gold Accent */
.kitchen-btn {
    gap: 9px;
    white-space: nowrap;
    color: var(--gold-light);
    border-color: rgba(215, 181, 109, 0.38);
    background: linear-gradient(
        135deg,
        rgba(215, 181, 109, 0.12),
        rgba(17, 22, 32, 0.96)
    );
}

.kitchen-btn:hover {
    color: #ffffff;
    border-color: var(--gold);
    background: rgba(215, 181, 109, 0.14);
}

.kitchen-icon {
    font-size: 18px;
}

.kitchen-arrow {
    color: var(--gold-light);
    font-size: 20px;
    transition: transform 0.2s ease;
}

.kitchen-btn:hover .kitchen-arrow {
    transform: translateX(4px);
}


        /* =====================================================
           STAT GRID
        ===================================================== */

        .stats-grid {

            display: grid;

            grid-template-columns:
                repeat(6, minmax(0, 1fr));

            gap: 14px;

            margin-bottom: 30px;
        }

        .stat-card {

            position: relative;

            overflow: hidden;

            min-height: 135px;

            padding: 20px;

            border:
                1px solid rgba(255,255,255,0.07);

            border-radius: 18px;

            background:
                linear-gradient(
                    145deg,
                    rgba(20,25,36,0.96),
                    rgba(12,16,24,0.96)
                );

            box-shadow:
                0 16px 45px rgba(0,0,0,0.22);

            transition: 0.25s ease;
        }

        .stat-card:hover {

            transform: translateY(-4px);

            border-color:
                rgba(215,181,109,0.25);
        }

        .stat-icon {

            width: 42px;
            height: 42px;

            display: grid;
            place-items: center;

            border-radius: 12px;

            background:
                rgba(215,181,109,0.09);

            border:
                1px solid rgba(215,181,109,0.15);

            color: var(--gold-light);

            font-size: 19px;

            margin-bottom: 14px;
        }

        .stat-label {

            color: var(--muted);

            font-size: 11px;

            text-transform: uppercase;

            letter-spacing: 1.2px;
        }

        .stat-value {

            margin-top: 6px;

            color: #fff;

            font-size: 25px;

            font-weight: 850;

            letter-spacing: -0.6px;
        }

        .stat-sub {

            margin-top: 4px;

            color: #747d8f;

            font-size: 11px;
        }

        /* =====================================================
           SECTION HEADER
        ===================================================== */

        .section-header {

            display: flex;

            align-items: flex-end;

            justify-content: space-between;

            gap: 20px;

            margin: 34px 0 16px;
        }

        .section-heading h3 {

            font-size: 22px;

            letter-spacing: -0.3px;
        }

        .section-heading p {

            margin-top: 5px;

            color: var(--muted);

            font-size: 12px;
        }

        .section-actions {

            display: flex;

            align-items: center;

            gap: 9px;

            flex-wrap: wrap;
        }

        /* =====================================================
           FOOD FILTERS
        ===================================================== */

        .food-tools {

            display: flex;

            gap: 10px;

            flex-wrap: wrap;

            margin-bottom: 18px;
        }

        .search-box {

            flex: 1;

            min-width: 240px;

            position: relative;
        }

        .search-box span {

            position: absolute;

            left: 14px;
            top: 50%;

            transform: translateY(-50%);

            color: #777f90;
        }

        .search-box input {

            width: 100%;

            height: 46px;

            padding: 0 15px 0 42px;

            color: #fff;

            background:
                rgba(14,18,27,0.9);

            border:
                1px solid rgba(255,255,255,0.08);

            border-radius: 12px;

            outline: none;

            transition: 0.2s ease;
        }

        .search-box input:focus {

            border-color:
                rgba(215,181,109,0.45);

            box-shadow:
                0 0 0 3px rgba(215,181,109,0.06);
        }

        .category-filter {

            height: 46px;

            min-width: 190px;

            padding: 0 14px;

            color: #ddd;

            background:
                rgba(14,18,27,0.9);

            border:
                1px solid rgba(255,255,255,0.08);

            border-radius: 12px;

            outline: none;
        }

        .category-filter option {

            background: #111722;

            color: #fff;
        }

        /* =====================================================
           FOOD GRID
        ===================================================== */

        .food-grid {

            display: grid;

            grid-template-columns:
                repeat(4, minmax(0, 1fr));

            gap: 18px;
        }

        .food-card {

            overflow: hidden;

            border:
                1px solid rgba(255,255,255,0.075);

            border-radius: 19px;

            background:
                linear-gradient(
                    145deg,
                    rgba(22,27,38,0.98),
                    rgba(12,16,24,0.98)
                );

            box-shadow:
                0 18px 50px rgba(0,0,0,0.24);

            transition:
                transform 0.3s ease,
                border-color 0.3s ease,
                box-shadow 0.3s ease;
        }

        .food-card:hover {

            transform: translateY(-6px);

            border-color:
                rgba(215,181,109,0.32);

            box-shadow:
                0 25px 65px rgba(0,0,0,0.36);
        }

        .food-card.hidden {

            display: none;
        }

        .food-image-wrap {

            position: relative;

            height: 225px;

            overflow: hidden;

            background:
                #171c28;
        }

        .food-image {

            width: 100%;
            height: 100%;

            display: block;

            object-fit: cover;

            transition:
                transform 0.55s ease;
        }

        .food-card:hover .food-image {

            transform: scale(1.07);
        }

        .food-overlay {

            position: absolute;

            inset: 0;

            background:
                linear-gradient(
                    to bottom,
                    rgba(0,0,0,0.05),
                    transparent 40%,
                    rgba(0,0,0,0.7)
                );

            pointer-events: none;
        }

        .food-category {

            position: absolute;

            left: 13px;
            top: 13px;

            padding: 6px 10px;

            border-radius: 999px;

            color: #17120a;

            background:
                rgba(241,213,142,0.96);

            font-size: 10px;

            font-weight: 900;

            text-transform: uppercase;

            letter-spacing: 0.8px;
        }

        .food-status {

            position: absolute;

            right: 13px;
            top: 13px;

            padding: 6px 10px;

            border-radius: 999px;

            font-size: 10px;

            font-weight: 800;

            backdrop-filter: blur(8px);
        }

        .food-status.available {

            color: #c6ffe2;

            background:
                rgba(30, 112, 77, 0.85);

            border:
                1px solid rgba(66,211,146,0.3);
        }

        .food-status.unavailable {

            color: #ffd0d3;

            background:
                rgba(125, 42, 49, 0.85);

            border:
                1px solid rgba(255,109,117,0.3);
        }

        .food-info {

            padding: 17px;
        }

        .food-name {

            min-height: 45px;

            color: #fff;

            font-size: 16px;

            font-weight: 800;

            line-height: 1.35;
        }

        .food-meta {

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 10px;

            margin-top: 9px;
        }

        .food-price {

            color: var(--gold-light);

            font-size: 17px;

            font-weight: 900;
        }

        .food-id {

            color: #697285;

            font-size: 10px;

            text-transform: uppercase;

            letter-spacing: 1px;
        }

        .food-actions {

            display: flex;

            gap: 7px;

            margin-top: 15px;
        }

        .food-action {

            flex: 1;

            height: 38px;

            display: inline-flex;

            align-items: center;

            justify-content: center;

            border-radius: 9px;

            border:
                1px solid rgba(255,255,255,0.08);

            background:
                rgba(255,255,255,0.035);

            color: #cdd2dc;

            font-size: 11px;

            font-weight: 800;

            transition: 0.2s ease;
        }

        .food-action:hover {

            color: var(--gold-light);

            border-color:
                rgba(215,181,109,0.35);

            background:
                rgba(215,181,109,0.07);
        }

        .food-action.order {

            color: #17120a;

            background:
                linear-gradient(
                    135deg,
                    var(--gold-light),
                    var(--gold)
                );

            border-color: transparent;
        }

        .food-action.order:hover {

            color: #17120a;

            box-shadow:
                0 7px 20px rgba(215,181,109,0.18);
        }

        /* =====================================================
           EMPTY FOOD STATE
        ===================================================== */

        .empty-food {

            grid-column: 1 / -1;

            padding: 55px 25px;

            text-align: center;

            border:
                1px dashed rgba(215,181,109,0.22);

            border-radius: 18px;

            background:
                rgba(16,21,31,0.75);
        }

        .empty-food-icon {

            font-size: 42px;

            opacity: 0.75;

            margin-bottom: 12px;
        }

        .empty-food h4 {

            font-size: 18px;
        }

        .empty-food p {

            margin-top: 7px;

            color: var(--muted);

            font-size: 12px;
        }

        /* =====================================================
           RECENT ORDERS
        ===================================================== */

        .orders-panel {

            overflow: hidden;

            border:
                1px solid rgba(255,255,255,0.07);

            border-radius: 18px;

            background:
                rgba(15,20,29,0.93);

            box-shadow:
                0 20px 55px rgba(0,0,0,0.25);
        }

        .orders-table-wrap {

            width: 100%;

            overflow-x: auto;
        }

        .orders-table {

            width: 100%;

            border-collapse: collapse;

            min-width: 850px;
        }

        .orders-table th {

            padding: 15px 17px;

            text-align: left;

            color: #7f8797;

            background:
                rgba(255,255,255,0.025);

            border-bottom:
                1px solid rgba(255,255,255,0.06);

            font-size: 10px;

            text-transform: uppercase;

            letter-spacing: 1.1px;
        }

        .orders-table td {

            padding: 15px 17px;

            border-bottom:
                1px solid rgba(255,255,255,0.045);

            color: #d8dbe1;

            font-size: 12px;
        }

        .orders-table tr:last-child td {

            border-bottom: none;
        }

        .orders-table tbody tr {

            transition: 0.2s ease;
        }

        .orders-table tbody tr:hover {

            background:
                rgba(215,181,109,0.035);
        }

        .order-number {

            color: var(--gold-light);

            font-weight: 900;
        }

        .amount {

            color: #fff;

            font-weight: 850;
        }

        .badge {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            min-width: 76px;

            padding: 6px 9px;

            border-radius: 999px;

            font-size: 10px;

            font-weight: 850;

            text-transform: capitalize;
        }

        .status-pending {

            color: #ffdca5;

            background:
                rgba(180, 116, 31, 0.18);

            border:
                1px solid rgba(244,173,87,0.18);
        }

        .status-preparing {

            color: #b9dcff;

            background:
                rgba(54, 117, 190, 0.18);

            border:
                1px solid rgba(99,169,255,0.18);
        }

        .status-ready {

            color: #c8ffe4;

            background:
                rgba(40, 135, 91, 0.18);

            border:
                1px solid rgba(66,211,146,0.18);
        }

        .status-completed {

            color: #d9e5ff;

            background:
                rgba(76, 92, 145, 0.18);

            border:
                1px solid rgba(130,150,220,0.18);
        }

        .status-cancelled {

            color: #ffd0d3;

            background:
                rgba(155, 49, 58, 0.18);

            border:
                1px solid rgba(255,109,117,0.18);
        }

        .status-default {

            color: #d6d9df;

            background:
                rgba(255,255,255,0.06);
        }

        .payment-paid {

            color: #c8ffe4;

            background:
                rgba(40, 135, 91, 0.18);
        }

        .payment-pending {

            color: #ffe0a8;

            background:
                rgba(180, 116, 31, 0.18);
        }

        .payment-default {

            color: #d5d8df;

            background:
                rgba(255,255,255,0.06);
        }

        .view-order {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            min-width: 68px;

            height: 32px;

            padding: 0 10px;

            border-radius: 8px;

            border:
                1px solid rgba(215,181,109,0.25);

            color: var(--gold-light);

            font-size: 10px;

            font-weight: 800;
        }

        .view-order:hover {

            background:
                rgba(215,181,109,0.08);

            border-color:
                rgba(215,181,109,0.45);
        }

        /* =====================================================
           QUICK ACTIONS
        ===================================================== */

        .quick-grid {

            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            gap: 13px;

            margin-top: 30px;
        }

        .quick-card {

            display: flex;

            align-items: center;

            gap: 13px;

            padding: 17px;

            border-radius: 15px;

            border:
                1px solid rgba(255,255,255,0.07);

            background:
                rgba(17,22,32,0.9);

            transition: 0.25s ease;
        }

        .quick-card:hover {

            transform: translateY(-3px);

            border-color:
                rgba(215,181,109,0.3);
        }

        .quick-icon {

            width: 42px;
            height: 42px;

            flex: 0 0 42px;

            display: grid;

            place-items: center;

            border-radius: 11px;

            color: var(--gold-light);

            background:
                rgba(215,181,109,0.08);

            border:
                1px solid rgba(215,181,109,0.13);

            font-size: 18px;
        }

        .quick-text strong {

            display: block;

            color: #fff;

            font-size: 12px;
        }

        .quick-text span {

            display: block;

            margin-top: 3px;

            color: var(--muted);

            font-size: 10px;
        }

        /* =====================================================
           ERROR BOX
        ===================================================== */

        .error-box {

            padding: 14px 16px;

            margin-bottom: 18px;

            color: #ffd5d7;

            border:
                1px solid rgba(255,109,117,0.25);

            border-radius: 12px;

            background:
                rgba(125,42,49,0.14);

            font-size: 12px;
        }

        /* =====================================================
           FOOTER
        ===================================================== */

        .footer {

            margin-top: 45px;

            padding-top: 22px;

            text-align: center;

            border-top:
                1px solid rgba(255,255,255,0.06);

            color: #687183;

            font-size: 11px;
        }

        .footer strong {

            color: var(--gold);
        }

        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media (max-width: 1250px) {

            .stats-grid {

                grid-template-columns:
                    repeat(3, 1fr);
            }

            .food-grid {

                grid-template-columns:
                    repeat(3, 1fr);
            }

        }

        @media (max-width: 900px) {

            .topbar {

                padding: 13px 18px;
            }

            .user-info {

                display: none;
            }

            .container {

                width: min(100% - 28px, 1450px);

                padding-top: 22px;
            }

            .hero-content {

                flex-direction: column;

                align-items: flex-start;
            }

            .food-grid {

                grid-template-columns:
                    repeat(2, 1fr);
            }

            .quick-grid {

                grid-template-columns:
                    repeat(2, 1fr);
            }

        }

        @media (max-width: 620px) {

            .topbar {

                min-height: 70px;
            }

            .brand-text {

                display: none;
            }

            .top-actions .dashboard-text {

                display: none;
            }

            .top-btn {

                min-height: 40px;

                padding: 0 12px;
            }

            .hero {

                padding: 24px 20px;

                border-radius: 20px;
            }

            .hero h2 {

                font-size: 28px;
            }

            .hero-buttons {

                width: 100%;
            }

            .primary-btn,
            .secondary-btn {

                flex: 1;

                min-width: 140px;
            }

            .stats-grid {

                grid-template-columns:
                    repeat(2, 1fr);

                gap: 10px;
            }

            .stat-card {

                min-height: 125px;

                padding: 15px;
            }

            .stat-value {

                font-size: 21px;
            }

            .section-header {

                align-items: flex-start;

                flex-direction: column;
            }

            .section-actions {

                width: 100%;
            }

            .food-grid {

                grid-template-columns: 1fr;

                gap: 14px;
            }

            .food-image-wrap {

                height: 240px;
            }

            .quick-grid {

                grid-template-columns: 1fr;
            }

        }

    </style>

</head>

<body>

<!-- ============================================================
     TOP BAR
============================================================ -->

<header class="topbar">

    <a href="<?php echo e($dashboardUrl); ?>" class="brand">

        <div class="brand-logo">
            🍴
        </div>

        <div class="brand-text">

            <h1>Suva Hotel</h1>

            <span>Restaurant Management</span>

        </div>

    </a>

    <div class="top-actions">

        <a
            href="<?php echo e($dashboardUrl); ?>"
            class="top-btn"
        >
            ← <span class="dashboard-text">&nbsp;Dashboard</span>
        </a>

        <a
            href="orders/add.php"
            class="top-btn gold"
        >
            + New Order
        </a>

        <div class="user-info">

            <span class="user-dot"></span>

            <?php echo e($userName); ?>

        </div>

    </div>

</header>


<main class="container">


    <!-- ========================================================
         HERO
    ========================================================= -->

    <section class="hero">

        <div class="hero-content">

            <div>

                <div class="hero-title">
                    Suva Hotel Restaurant
                </div>

                <h2>
                    Restaurant Dashboard
                </h2>

                <p>
                    Manage food items, restaurant orders, menu availability
                    and daily restaurant operations from one premium workspace.
                </p>

            </div>

            <div class="hero-buttons">

                <a
                    href="orders/add.php"
                    class="primary-btn"
                >
                    🍽️ &nbsp; Create New Order
                </a>
                


<a href="orders/kitchen.php" class="secondary-btn kitchen-btn">
    <span class="kitchen-icon">👨‍🍳</span>
    <span>Kitchen Dashboard</span>
    <span class="kitchen-arrow">→</span>
</a>


                <a
                    href="food/add.php"
                    class="secondary-btn"
                >
                    + &nbsp; Add Food Item
                </a>

            </div>

        </div>

    </section>


    <!-- ========================================================
         STATISTICS
    ========================================================= -->

    <section class="stats-grid">

        <div class="stat-card">

            <div class="stat-icon">
                🍴
            </div>

            <div class="stat-label">
                Total Orders
            </div>

            <div class="stat-value">
                <?php echo number_format($totalOrders); ?>
            </div>

            <div class="stat-sub">
                All restaurant orders
            </div>

        </div>


        <div class="stat-card">

            <div class="stat-icon">
                📅
            </div>

            <div class="stat-label">
                Today's Orders
            </div>

            <div class="stat-value">
                <?php echo number_format($todayOrders); ?>
            </div>

            <div class="stat-sub">
                Orders placed today
            </div>

        </div>


        <div class="stat-card">

            <div class="stat-icon">
                ⏳
            </div>

            <div class="stat-label">
                Pending
            </div>

            <div class="stat-value">
                <?php echo number_format($pendingOrders); ?>
            </div>

            <div class="stat-sub">
                Waiting for preparation
            </div>

        </div>


        <div class="stat-card">

            <div class="stat-icon">
                👨‍🍳
            </div>

            <div class="stat-label">
                Preparing
            </div>

            <div class="stat-value">
                <?php echo number_format($preparingOrders); ?>
            </div>

            <div class="stat-sub">
                Currently preparing
            </div>

        </div>


        <div class="stat-card">

            <div class="stat-icon">
                ✓
            </div>

            <div class="stat-label">
                Ready
            </div>

            <div class="stat-value">
                <?php echo number_format($readyOrders); ?>
            </div>

            <div class="stat-sub">
                Ready to serve
            </div>

        </div>


        <div class="stat-card">

            <div class="stat-icon">
                ₹
            </div>

            <div class="stat-label">
                Restaurant Revenue
            </div>

            <div class="stat-value">
                <?php echo money($orderRevenue); ?>
            </div>

            <div class="stat-sub">
                Non-cancelled orders
            </div>

        </div>

    </section>


    <!-- ========================================================
         FOOD MENU
    ========================================================= -->

    <div class="section-header">

        <div class="section-heading">

            <h3>
                🍽️ Food Menu
            </h3>

            <p>
                <?php echo number_format($availableFoods); ?>
                available items
                ·
                <?php echo number_format($totalFoods); ?>
                total menu items
            </p>

        </div>

        <div class="section-actions">

            <a
                href="food/view.php"
                class="secondary-btn"
                style="min-height:42px;padding:0 15px;"
            >
                Manage Food
            </a>

            <a
                href="food/add.php"
                class="primary-btn"
                style="min-height:42px;padding:0 15px;"
            >
                + Add Food
            </a>

        </div>

    </div>


    <?php if ($foodError !== ''): ?>

        <div class="error-box">

            Unable to load food items.

            <?php echo e($foodError); ?>

        </div>

    <?php endif; ?>


    <!-- FOOD SEARCH/FILTER -->

    <div class="food-tools">

        <div class="search-box">

            <span>⌕</span>

            <input
                type="text"
                id="foodSearch"
                placeholder="Search food items..."
                autocomplete="off"
            >

        </div>


        <select
            id="categoryFilter"
            class="category-filter"
        >

            <option value="all">
                All Categories
            </option>

            <?php foreach ($categories as $category): ?>

                <option value="<?php echo e(strtolower($category)); ?>">
                    <?php echo e($category); ?>
                </option>

            <?php endforeach; ?>

        </select>

    </div>


    <!-- FOOD GRID -->

    <div
        class="food-grid"
        id="foodGrid"
    >

        <?php if (empty($foods)): ?>

            <div class="empty-food">

                <div class="empty-food-icon">
                    🍽️
                </div>

                <h4>
                    No food items found
                </h4>

                <p>
                    Add food items from the food management section.
                </p>

            </div>

        <?php else: ?>

            <?php foreach ($foods as $food): ?>

                <?php

                $foodId = (int)($food['id'] ?? 0);

                $foodName = trim(
                    (string)($food['food_name'] ?? 'Unnamed Food')
                );

                $category = trim(
                    (string)($food['category'] ?? 'Food')
                );

                $price = (float)(
                    $food['price'] ?? 0
                );

                $status = trim(
                    (string)($food['status'] ?? '')
                );

                $statusLower = strtolower($status);

                $imageUrl = $food['image_url'] ?? '';

                if ($imageUrl === '') {
                    $imageUrl = $foodPlaceholder;
                }

                ?>

                <article
                    class="food-card"
                    data-name="<?php echo e(strtolower($foodName)); ?>"
                    data-category="<?php echo e(strtolower($category)); ?>"
                    data-status="<?php echo e($statusLower); ?>"
                >

                    <!-- IMAGE -->

                    <div class="food-image-wrap">

                        <img
                            src="<?php echo e($imageUrl); ?>"
                            alt="<?php echo e($foodName); ?>"
                            class="food-image"
                            loading="lazy"
                            onerror="this.onerror=null;this.src='<?php echo e($foodPlaceholder); ?>';"
                        >

                        <div class="food-overlay"></div>


                        <div class="food-category">
                            <?php echo e($category); ?>
                        </div>


                        <?php if ($statusLower === 'available'): ?>

                            <div class="food-status available">
                                ● Available
                            </div>

                        <?php else: ?>

                            <div class="food-status unavailable">
                                ● Unavailable
                            </div>

                        <?php endif; ?>

                    </div>


                    <!-- FOOD INFORMATION -->

                    <div class="food-info">

                        <div class="food-name">
                            <?php echo e($foodName); ?>
                        </div>


                        <div class="food-meta">

                            <div class="food-price">
                                <?php echo money($price); ?>
                            </div>

                            <div class="food-id">
                                ID #<?php echo $foodId; ?>
                            </div>

                        </div>


                        <div class="food-actions">

                            <?php if ($statusLower === 'available'): ?>

                                <a
                                    href="orders/add.php?food_id=<?php echo $foodId; ?>"
                                    class="food-action order"
                                >
                                    Order
                                </a>

                            <?php else: ?>

                                <span
                                    class="food-action"
                                    style="opacity:.45;cursor:not-allowed;"
                                >
                                    Unavailable
                                </span>

                            <?php endif; ?>


                            <a
                                href="food/edit.php?id=<?php echo $foodId; ?>"
                                class="food-action"
                            >
                                Edit
                            </a>

                        </div>

                    </div>

                </article>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>


    <!-- ========================================================
         RECENT ORDERS
    ========================================================= -->

    <div class="section-header">

        <div class="section-heading">

            <h3>
                📋 Recent Restaurant Orders
            </h3>

            <p>
                Latest 10 restaurant orders
            </p>

        </div>

        <div class="section-actions">

            <a
                href="orders/index.php"
                class="secondary-btn"
                style="min-height:42px;padding:0 15px;"
            >
                View All Orders →
            </a>

        </div>

    </div>


    <div class="orders-panel">

        <div class="orders-table-wrap">

            <table class="orders-table">

                <thead>

                    <tr>

                        <th>
                            Order
                        </th>

                        <th>
                            Order Type
                        </th>

                        <th>
                            Amount
                        </th>

                        <th>
                            Payment
                        </th>

                        <th>
                            Payment Status
                        </th>

                        <th>
                            Order Status
                        </th>

                        <th>
                            Time
                        </th>

                        <th>
                            Action
                        </th>

                    </tr>

                </thead>


                <tbody>

                    <?php if (empty($recentOrders)): ?>

                        <tr>

                            <td
                                colspan="8"
                                style="
                                    text-align:center;
                                    padding:45px;
                                    color:#7f8797;
                                "
                            >
                                No restaurant orders found.
                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($recentOrders as $order): ?>

                            <?php

                            $orderId = (int)(
                                $order['id'] ?? 0
                            );

                            $orderType = trim(
                                (string)(
                                    $order['order_type'] ?? '-'
                                )
                            );

                            $totalAmount = (float)(
                                $order['total_amount'] ?? 0
                            );

                            $paymentMethod = trim(
                                (string)(
                                    $order['payment_method'] ?? '-'
                                )
                            );

                            $paymentStatus = trim(
                                (string)(
                                    $order['payment_status'] ?? '-'
                                )
                            );

                            $orderStatus = trim(
                                (string)(
                                    $order['order_status'] ?? '-'
                                )
                            );

                            $createdAt = $order['created_at'] ?? '';

                            $formattedTime = '-';

                            if ($createdAt !== '') {

                                $timestamp = strtotime(
                                    $createdAt
                                );

                                if ($timestamp !== false) {

                                    $formattedTime =
                                        date(
                                            'M d, Y h:i A',
                                            $timestamp
                                        );
                                }
                            }

                            ?>

                            <tr>

                                <td>

                                    <span class="order-number">
                                        #<?php echo $orderId; ?>
                                    </span>

                                </td>


                                <td>
                                    <?php echo e($orderType); ?>
                                </td>


                                <td>

                                    <span class="amount">
                                        <?php echo money($totalAmount); ?>
                                    </span>

                                </td>


                                <td>
                                    <?php echo e($paymentMethod); ?>
                                </td>


                                <td>

                                    <span
                                        class="badge <?php echo e(
                                            paymentStatusClass($paymentStatus)
                                        ); ?>"
                                    >
                                        <?php echo e($paymentStatus); ?>
                                    </span>

                                </td>


                                <td>

                                    <span
                                        class="badge <?php echo e(
                                            orderStatusClass($orderStatus)
                                        ); ?>"
                                    >
                                        <?php echo e($orderStatus); ?>
                                    </span>

                                </td>


                                <td>
                                    <?php echo e($formattedTime); ?>
                                </td>


                                <td>

                                    <a
                                        href="orders/view.php?id=<?php echo $orderId; ?>"
                                        class="view-order"
                                    >
                                        View
                                    </a>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


    <!-- ========================================================
         QUICK ACTIONS
    ========================================================= -->

    <div class="section-header">

        <div class="section-heading">

            <h3>
                ⚡ Quick Actions
            </h3>

            <p>
                Restaurant management shortcuts
            </p>

        </div>

    </div>


    <div class="quick-grid">

        <a
            href="orders/add.php"
            class="quick-card"
        >

            <div class="quick-icon">
                🍽️
            </div>

            <div class="quick-text">

                <strong>
                    New Restaurant Order
                </strong>

                <span>
                    Create a new food order
                </span>

            </div>

        </a>


        <a
            href="orders/index.php"
            class="quick-card"
        >

            <div class="quick-icon">
                📋
            </div>

            <div class="quick-text">

                <strong>
                    Order Records
                </strong>

                <span>
                    View and manage orders
                </span>

            </div>

        </a>


        <a
            href="food/view.php"
            class="quick-card"
        >

            <div class="quick-icon">
                🍔
            </div>

            <div class="quick-text">

                <strong>
                    Food Management
                </strong>

                <span>
                    Edit menu and availability
                </span>

            </div>

        </a>


        <a
            href="<?php echo e($dashboardUrl); ?>"
            class="quick-card"
        >

            <div class="quick-icon">
                🏨
            </div>

            <div class="quick-text">

                <strong>
                    Back to Dashboard
                </strong>

                <span>
                    Return to hotel management
                </span>

            </div>

        </a>

    </div>


    <!-- ========================================================
         FOOTER
    ========================================================= -->

    <footer class="footer">

        <strong>Suva Hotel</strong>
        · Restaurant Management System
        · <?php echo date('Y'); ?>

    </footer>

</main>


<script>

    // =========================================================
    // FOOD SEARCH + CATEGORY FILTER
    // =========================================================

    const foodSearch =
        document.getElementById('foodSearch');

    const categoryFilter =
        document.getElementById('categoryFilter');

    const foodCards =
        document.querySelectorAll('.food-card');


    function filterFoods() {

        const search =
            foodSearch.value
                .toLowerCase()
                .trim();

        const category =
            categoryFilter.value
                .toLowerCase();


        foodCards.forEach(function(card) {

            const name =
                card.dataset.name || '';

            const cardCategory =
                card.dataset.category || '';


            const matchesSearch =
                name.includes(search);


            const matchesCategory =
                category === 'all' ||
                cardCategory === category;


            if (
                matchesSearch &&
                matchesCategory
            ) {

                card.classList.remove('hidden');

            } else {

                card.classList.add('hidden');

            }

        });

    }


    foodSearch.addEventListener(
        'input',
        filterFoods
    );


    categoryFilter.addEventListener(
        'change',
        filterFoods
    );


</script>


</body>
</html>