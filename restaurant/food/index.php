<?php
// ============================================================
// SUVA HOTEL - RESTAURANT MANAGEMENT
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

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}


// ============================================================
// DATABASE
// ============================================================

require_once __DIR__ . "/../config/database.php";

if (!isset($pgConnection) || !$pgConnection) {
    die("PostgreSQL database connection is not available.");
}


// ============================================================
// USER
// ============================================================

$role = strtolower($_SESSION["role"] ?? "");

$username = $_SESSION["username"] ?? "User";

if ($role !== "admin" && $role !== "staff") {
    header("Location: ../login.php");
    exit;
}


// ============================================================
// HELPER
// ============================================================

function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        "UTF-8"
    );
}


function money($value)
{
    return "Rs. " . number_format(
        (float)$value,
        2
    );
}


// ============================================================
// DASHBOARD URL
// ============================================================

if ($role === "admin") {

    $dashboardUrl = "../dashboard.php";

} else {

    $dashboardUrl =
        "../staff_dashboard/staff_dashboard.php";
}


// ============================================================
// IMAGE DIRECTORY
//
// restaurant/index.php
//       |
//       └── food/images/
// ============================================================

$imageDirectory =
    __DIR__ . "/food/images/";

$imageWebDirectory =
    "food/images/";


// ============================================================
// FOOD IMAGE FUNCTION
// ============================================================

function getFoodImage($image)
{
    global $imageDirectory;
    global $imageWebDirectory;

    $image = trim((string)$image);


    // No image
    if ($image === "") {
        return "";
    }


    // External image
    if (
        strpos($image, "http://") === 0 ||
        strpos($image, "https://") === 0
    ) {
        return $image;
    }


    // Normalize path
    $image = str_replace(
        "\\",
        "/",
        $image
    );


    // Remove query string
    $image = explode(
        "?",
        $image
    )[0];


    // Only filename
    $filename =
        basename($image);


    if (
        $filename === "" ||
        $filename === "." ||
        $filename === ".."
    ) {
        return "";
    }


    // Physical file
    $physicalPath =
        $imageDirectory . $filename;


    // Only return image if it actually exists
    if (is_file($physicalPath)) {

        return $imageWebDirectory .
            rawurlencode($filename);
    }


    return "";
}


// ============================================================
// FOOD STATISTICS
// ============================================================

$totalFoods = 0;
$availableFoods = 0;
$unavailableFoods = 0;


$foodStatsSql = "

    SELECT

        COUNT(*) AS total,

        COUNT(*) FILTER (
            WHERE LOWER(
                TRIM(
                    COALESCE(status, '')
                )
            ) = 'available'
        ) AS available,

        COUNT(*) FILTER (
            WHERE LOWER(
                TRIM(
                    COALESCE(status, '')
                )
            ) = 'unavailable'
        ) AS unavailable

    FROM public.restaurant_foods

";


$foodStatsResult =
    pg_query(
        $pgConnection,
        $foodStatsSql
    );


if ($foodStatsResult) {

    $foodStats =
        pg_fetch_assoc(
            $foodStatsResult
        );


    $totalFoods =
        (int)(
            $foodStats["total"] ?? 0
        );


    $availableFoods =
        (int)(
            $foodStats["available"] ?? 0
        );


    $unavailableFoods =
        (int)(
            $foodStats["unavailable"] ?? 0
        );
}


// ============================================================
// ORDER STATISTICS
// ============================================================

$totalOrders = 0;
$todayOrders = 0;
$pendingOrders = 0;
$preparingOrders = 0;
$readyOrders = 0;
$orderRevenue = 0;


$orderStatsSql = "

    SELECT

        COUNT(*) AS total_orders,

        COUNT(*) FILTER (
            WHERE DATE(created_at) = CURRENT_DATE
        ) AS today_orders,

        COUNT(*) FILTER (
            WHERE LOWER(
                TRIM(
                    COALESCE(order_status, '')
                )
            ) = 'pending'
        ) AS pending_orders,

        COUNT(*) FILTER (
            WHERE LOWER(
                TRIM(
                    COALESCE(order_status, '')
                )
            ) = 'preparing'
        ) AS preparing_orders,

        COUNT(*) FILTER (
            WHERE LOWER(
                TRIM(
                    COALESCE(order_status, '')
                )
            ) = 'ready'
        ) AS ready_orders,

        COALESCE(
            SUM(
                CASE
                    WHEN LOWER(
                        TRIM(
                            COALESCE(payment_status, '')
                        )
                    ) IN (
                        'paid',
                        'completed'
                    )
                    THEN COALESCE(total_amount, 0)
                    ELSE 0
                END
            ),
            0
        ) AS revenue

    FROM public.restaurant_orders

";


$orderStatsResult =
    pg_query(
        $pgConnection,
        $orderStatsSql
    );


if ($orderStatsResult) {

    $orderStats =
        pg_fetch_assoc(
            $orderStatsResult
        );


    $totalOrders =
        (int)(
            $orderStats["total_orders"] ?? 0
        );


    $todayOrders =
        (int)(
            $orderStats["today_orders"] ?? 0
        );


    $pendingOrders =
        (int)(
            $orderStats["pending_orders"] ?? 0
        );


    $preparingOrders =
        (int)(
            $orderStats["preparing_orders"] ?? 0
        );


    $readyOrders =
        (int)(
            $orderStats["ready_orders"] ?? 0
        );


    $orderRevenue =
        (float)(
            $orderStats["revenue"] ?? 0
        );
}


// ============================================================
// RECENT ORDERS
//
// Customer/Guest name intentionally NOT displayed.
// ============================================================

$recentOrders = [];


$recentOrdersSql = "

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


$recentOrdersResult =
    pg_query(
        $pgConnection,
        $recentOrdersSql
    );


if ($recentOrdersResult) {

    while (
        $order =
        pg_fetch_assoc(
            $recentOrdersResult
        )
    ) {

        $recentOrders[] =
            $order;
    }
}


// ============================================================
// FOOD ITEMS
//
// Latest 12 food items shown on dashboard.
// ============================================================

$foodItems = [];


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

    ORDER BY id DESC

    LIMIT 12

";


$foodResult =
    pg_query(
        $pgConnection,
        $foodSql
    );


if ($foodResult) {

    while (
        $food =
        pg_fetch_assoc(
            $foodResult
        )
    ) {

        $foodItems[] =
            $food;
    }
}


// ============================================================
// CATEGORIES
// ============================================================

$categories = [];


$categorySql = "

    SELECT DISTINCT

        category

    FROM public.restaurant_foods

    WHERE
        category IS NOT NULL

        AND TRIM(category) <> ''

    ORDER BY category ASC

";


$categoryResult =
    pg_query(
        $pgConnection,
        $categorySql
    );


if ($categoryResult) {

    while (
        $category =
        pg_fetch_assoc(
            $categoryResult
        )
    ) {

        $categories[] =
            $category["category"];
    }
}


// ============================================================
// DATE
// ============================================================

$currentDate =
    date("l, F j, Y");

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
    Restaurant Management - Suva Hotel
</title>


<style>

/* ============================================================
   RESET
============================================================ */

* {
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;
}

body {

    margin: 0;

    min-height: 100vh;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    color: #f8fafc;

    background:

        linear-gradient(
            135deg,
            rgba(3, 7, 18, .94),
            rgba(10, 15, 28, .96)
        ),

        url("../assets/image/hotel-lighting.jpg")
        center / cover fixed no-repeat;
}


/* ============================================================
   HEADER
============================================================ */

.header {

    position: sticky;

    top: 0;

    z-index: 1000;

    min-height: 76px;

    padding:
        13px 30px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 20px;

    background:
        rgba(3, 7, 18, .92);

    border-bottom:
        1px solid
        rgba(217,180,91,.18);

    backdrop-filter:
        blur(18px);

    box-shadow:
        0 12px 40px
        rgba(0,0,0,.35);
}


.logo {

    display: flex;

    align-items: center;

    gap: 11px;

    white-space: nowrap;
}


.logo-icon {

    width: 43px;

    height: 43px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 12px;

    background:
        linear-gradient(
            135deg,
            #d9b45b,
            #f0cf79
        );

    color: #111;

    font-size: 22px;

    box-shadow:
        0 8px 22px
        rgba(217,180,91,.18);
}


.logo-text {

    font-size: 20px;

    font-weight: 900;

    letter-spacing:
        .3px;
}


.logo-text span {

    color:
        #d9b45b;
}


.header-right {

    display: flex;

    align-items: center;

    gap: 10px;

    flex-wrap: wrap;

    justify-content: flex-end;
}


.user-info {

    display: flex;

    align-items: center;

    gap: 8px;

    padding:
        9px 12px;

    border-radius: 10px;

    background:
        rgba(255,255,255,.045);

    border:
        1px solid
        rgba(255,255,255,.08);

    color:
        #cbd5e1;

    font-size: 12px;
}


.user-role {

    padding:
        4px 7px;

    border-radius: 5px;

    background:
        rgba(217,180,91,.12);

    color:
        #d9b45b;

    font-size: 10px;

    font-weight: 800;

    text-transform:
        uppercase;
}


.header-btn {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    min-height: 41px;

    padding:
        9px 14px;

    border-radius: 9px;

    text-decoration: none;

    font-size: 12px;

    font-weight: 800;

    transition:
        .2s ease;
}


.header-btn:hover {

    transform:
        translateY(-2px);
}


.header-dashboard {

    color:
        #ffffff;

    background:
        rgba(255,255,255,.07);

    border:
        1px solid
        rgba(255,255,255,.10);
}


.header-order {

    color:
        #111111;

    background:
        linear-gradient(
            135deg,
            #d9b45b,
            #f0cf79
        );

    border:
        1px solid
        #d9b45b;
}


/* ============================================================
   MAIN CONTAINER
============================================================ */

.container {

    width:
        min(1500px, 94%);

    margin:
        0 auto;

    padding:
        32px 0 60px;
}


/* ============================================================
   HERO
============================================================ */

.hero {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 25px;

    margin-bottom:
        27px;

    padding:
        28px 30px;

    border-radius:
        20px;

    background:

        linear-gradient(
            135deg,
            rgba(18,25,40,.94),
            rgba(10,15,28,.86)
        );

    border:
        1px solid
        rgba(217,180,91,.16);

    box-shadow:
        0 20px 60px
        rgba(0,0,0,.30);

    backdrop-filter:
        blur(14px);

    overflow:
        hidden;

    position:
        relative;
}


.hero::after {

    content: "";

    position:
        absolute;

    width:
        260px;

    height:
        260px;

    right:
        -100px;

    top:
        -120px;

    border-radius:
        50%;

    border:
        1px solid
        rgba(217,180,91,.12);

    box-shadow:
        0 0 0 40px
        rgba(217,180,91,.025),
        0 0 0 80px
        rgba(217,180,91,.018);
}


.hero h1 {

    margin:
        0 0 8px;

    font-size:
        31px;

    letter-spacing:
        -.6px;
}


.hero h1 span {

    color:
        #d9b45b;
}


.hero p {

    margin:
        0;

    color:
        #929baa;

    font-size:
        14px;
}


.hero-date {

    position:
        relative;

    z-index:
        2;

    padding:
        13px 16px;

    border-radius:
        11px;

    background:
        rgba(255,255,255,.045);

    border:
        1px solid
        rgba(255,255,255,.08);

    color:
        #cbd5e1;

    font-size:
        13px;

    white-space:
        nowrap;
}


/* ============================================================
   STAT GRID
============================================================ */

.stats {

    display:
        grid;

    grid-template-columns:
        repeat(6, 1fr);

    gap:
        14px;

    margin-bottom:
        28px;
}


.stat {

    position:
        relative;

    min-height:
        118px;

    padding:
        18px;

    border-radius:
        15px;

    background:
        rgba(13,20,34,.83);

    border:
        1px solid
        rgba(255,255,255,.075);

    backdrop-filter:
        blur(15px);

    box-shadow:
        0 12px 35px
        rgba(0,0,0,.23);

    overflow:
        hidden;

    transition:
        .22s ease;
}


.stat:hover {

    transform:
        translateY(-3px);

    border-color:
        rgba(217,180,91,.28);
}


.stat-icon {

    font-size:
        20px;

    margin-bottom:
        9px;
}


.stat-number {

    font-size:
        25px;

    font-weight:
        900;

    color:
        #ffffff;

    margin-bottom:
        4px;
}


.stat-label {

    color:
        #8791a1;

    font-size:
        11px;

    font-weight:
        600;
}


.stat.gold .stat-number {

    color:
        #d9b45b;
}


.stat.green .stat-number {

    color:
        #4ade80;
}


.stat.orange .stat-number {

    color:
        #fbbf24;
}


.stat.blue .stat-number {

    color:
        #60a5fa;
}


/* ============================================================
   SECTION HEADER
============================================================ */

.section-header {

    display:
        flex;

    align-items:
        center;

    justify-content:
        space-between;

    gap:
        15px;

    margin-bottom:
        17px;
}


.section-title h2 {

    margin:
        0 0 5px;

    font-size:
        21px;
}


.section-title p {

    margin:
        0;

    color:
        #778293;

    font-size:
        12px;
}


.section-actions {

    display:
        flex;

    gap:
        8px;

    flex-wrap:
        wrap;
}


.action-btn {

    display:
        inline-flex;

    align-items:
        center;

    justify-content:
        center;

    min-height:
        39px;

    padding:
        8px 13px;

    border-radius:
        8px;

    text-decoration:
        none;

    font-size:
        12px;

    font-weight:
        800;

    transition:
        .2s ease;
}


.action-btn:hover {

    transform:
        translateY(-2px);
}


.action-primary {

    background:
        #d9b45b;

    color:
        #111111;
}


.action-secondary {

    background:
        rgba(255,255,255,.06);

    color:
        #e2e8f0;

    border:
        1px solid
        rgba(255,255,255,.09);
}


/* ============================================================
   FOOD SECTION
============================================================ */

.food-section {

    margin-bottom:
        30px;

    padding:
        23px;

    border-radius:
        18px;

    background:
        rgba(9,15,27,.78);

    border:
        1px solid
        rgba(255,255,255,.075);

    box-shadow:
        0 18px 55px
        rgba(0,0,0,.25);

    backdrop-filter:
        blur(15px);
}


/* ============================================================
   FOOD CONTROLS
============================================================ */

.food-controls {

    display:
        flex;

    align-items:
        center;

    justify-content:
        space-between;

    gap:
        12px;

    margin-bottom:
        19px;

    flex-wrap:
        wrap;
}


.search-box {

    position:
        relative;

    flex:
        1;

    min-width:
        230px;

    max-width:
        430px;
}


.search-box input {

    width:
        100%;

    height:
        43px;

    padding:
        0 15px 0 42px;

    border-radius:
        9px;

    border:
        1px solid
        rgba(255,255,255,.09);

    background:
        rgba(255,255,255,.045);

    color:
        #ffffff;

    outline:
        none;

    font-size:
        13px;
}


.search-box input::placeholder {

    color:
        #6f7888;
}


.search-box input:focus {

    border-color:
        rgba(217,180,91,.50);

    box-shadow:
        0 0 0 3px
        rgba(217,180,91,.07);
}


.search-icon {

    position:
        absolute;

    left:
        14px;

    top:
        12px;

    color:
        #778293;

    font-size:
        16px;
}


.category-filter {

    display:
        flex;

    gap:
        6px;

    overflow-x:
        auto;

    max-width:
        100%;

    padding-bottom:
        2px;
}


.category-btn {

    flex:
        0 0 auto;

    padding:
        9px 12px;

    border-radius:
        8px;

    border:
        1px solid
        rgba(255,255,255,.08);

    background:
        rgba(255,255,255,.04);

    color:
        #aab3c1;

    cursor:
        pointer;

    font-size:
        11px;

    font-weight:
        700;
}


.category-btn.active,
.category-btn:hover {

    background:
        rgba(217,180,91,.12);

    border-color:
        rgba(217,180,91,.30);

    color:
        #d9b45b;
}


/* ============================================================
   FOOD GRID
============================================================ */

.food-grid {

    display:
        grid;

    grid-template-columns:
        repeat(4, minmax(0, 1fr));

    gap:
        17px;
}


/* ============================================================
   FOOD CARD
============================================================ */

.food-card {

    overflow:
        hidden;

    border-radius:
        14px;

    background:
        rgba(20,27,42,.78);

    border:
        1px solid
        rgba(255,255,255,.075);

    transition:
        .25s ease;

    position:
        relative;
}


.food-card:hover {

    transform:
        translateY(-5px);

    border-color:
        rgba(217,180,91,.32);

    box-shadow:
        0 18px 40px
        rgba(0,0,0,.32);
}


.food-card.hidden {

    display:
        none;
}


/* ============================================================
   FOOD IMAGE
============================================================ */

.food-image-box {

    position:
        relative;

    width:
        100%;

    height:
        185px;

    overflow:
        hidden;

    background:
        linear-gradient(
            135deg,
            #1c2638,
            #0e1625
        );
}


.food-image {

    width:
        100%;

    height:
        100%;

    display:
        block;

    object-fit:
        cover;

    transition:
        transform .35s ease;
}


.food-card:hover .food-image {

    transform:
        scale(1.07);
}


.image-placeholder {

    width:
        100%;

    height:
        100%;

    display:
        flex;

    align-items:
        center;

    justify-content:
        center;

    flex-direction:
        column;

    color:
        #677387;

    font-size:
        42px;
}


.image-placeholder span {

    margin-top:
        7px;

    font-size:
        10px;

    color:
        #586376;
}


/* ============================================================
   FOOD STATUS
============================================================ */

.food-status {

    position:
        absolute;

    top:
        10px;

    right:
        10px;

    padding:
        5px 8px;

    border-radius:
        20px;

    font-size:
        9px;

    font-weight:
        800;

    backdrop-filter:
        blur(7px);
}


.food-status.available {

    color:
        #bbf7d0;

    background:
        rgba(22,101,52,.85);

    border:
        1px solid
        rgba(74,222,128,.25);
}


.food-status.unavailable {

    color:
        #fecaca;

    background:
        rgba(127,29,29,.88);

    border:
        1px solid
        rgba(248,113,113,.25);
}


/* ============================================================
   FOOD CONTENT
============================================================ */

.food-content {

    padding:
        14px;
}


.food-name {

    min-height:
        20px;

    margin-bottom:
        6px;

    color:
        #f8fafc;

    font-size:
        14px;

    font-weight:
        800;

    line-height:
        1.3;
}


.food-category {

    display:
        inline-block;

    margin-bottom:
        11px;

    padding:
        4px 8px;

    border-radius:
        20px;

    background:
        rgba(96,165,250,.10);

    border:
        1px solid
        rgba(96,165,250,.14);

    color:
        #93c5fd;

    font-size:
        9px;

    font-weight:
        700;
}


.food-footer {

    display:
        flex;

    align-items:
        center;

    justify-content:
        space-between;

    gap:
        10px;
}


.food-price {

    color:
        #d9b45b;

    font-size:
        15px;

    font-weight:
        900;
}


.order-food-btn {

    display:
        inline-flex;

    align-items:
        center;

    justify-content:
        center;

    padding:
        7px 9px;

    border-radius:
        7px;

    text-decoration:
        none;

    color:
        #111111;

    background:
        #d9b45b;

    font-size:
        10px;

    font-weight:
        900;

    transition:
        .2s ease;
}


.order-food-btn:hover {

    background:
        #f0cf79;

    transform:
        translateY(-1px);
}


/* ============================================================
   BOTTOM GRID
============================================================ */

.bottom-grid {

    display:
        grid;

    grid-template-columns:
        minmax(0, 1.6fr)
        minmax(320px, .8fr);

    gap:
        20px;
}


/* ============================================================
   ORDERS PANEL
============================================================ */

.orders-panel {

    padding:
        23px;

    border-radius:
        18px;

    background:
        rgba(9,15,27,.78);

    border:
        1px solid
        rgba(255,255,255,.075);

    box-shadow:
        0 18px 55px
        rgba(0,0,0,.25);

    backdrop-filter:
        blur(15px);
}


/* ============================================================
   TABLE
============================================================ */

.table-wrapper {

    overflow-x:
        auto;
}


.orders-table {

    width:
        100%;

    border-collapse:
        collapse;

    min-width:
        650px;
}


.orders-table th {

    padding:
        11px 9px;

    text-align:
        left;

    color:
        #6f798a;

    font-size:
        10px;

    font-weight:
        800;

    text-transform:
        uppercase;

    letter-spacing:
        .5px;

    border-bottom:
        1px solid
        rgba(255,255,255,.08);
}


.orders-table td {

    padding:
        13px 9px;

    color:
        #cbd5e1;

    font-size:
        12px;

    border-bottom:
        1px solid
        rgba(255,255,255,.045);
}


.orders-table tr:last-child td {

    border-bottom:
        none;
}


.orders-table tr:hover td {

    background:
        rgba(255,255,255,.025);
}


.order-number {

    color:
        #ffffff;

    font-weight:
        800;
}


.order-amount {

    color:
        #d9b45b;

    font-weight:
        800;
}


.badge {

    display:
        inline-block;

    padding:
        5px 8px;

    border-radius:
        20px;

    font-size:
        9px;

    font-weight:
        800;
}


.badge.pending {

    color:
        #fde68a;

    background:
        rgba(217,119,6,.13);
}


.badge.preparing {

    color:
        #bfdbfe;

    background:
        rgba(37,99,235,.13);
}


.badge.ready {

    color:
        #bbf7d0;

    background:
        rgba(22,163,74,.13);
}


.badge.completed {

    color:
        #bbf7d0;

    background:
        rgba(22,163,74,.13);
}


.badge.cancelled {

    color:
        #fecaca;

    background:
        rgba(220,38,38,.13);
}


.badge.paid {

    color:
        #bbf7d0;

    background:
        rgba(22,163,74,.13);
}


.badge.unpaid {

    color:
        #fecaca;

    background:
        rgba(220,38,38,.13);
}


.view-order {

    color:
        #d9b45b;

    text-decoration:
        none;

    font-weight:
        800;

    font-size:
        11px;
}


.view-order:hover {

    text-decoration:
        underline;
}


/* ============================================================
   QUICK ACTIONS
============================================================ */

.quick-panel {

    padding:
        23px;

    border-radius:
        18px;

    background:
        rgba(9,15,27,.78);

    border:
        1px solid
        rgba(255,255,255,.075);

    box-shadow:
        0 18px 55px
        rgba(0,0,0,.25);

    backdrop-filter:
        blur(15px);
}


.quick-actions {

    display:
        grid;

    gap:
        10px;
}


.quick-action {

    display:
        flex;

    align-items:
        center;

    gap:
        13px;

    padding:
        13px;

    border-radius:
        11px;

    text-decoration:
        none;

    background:
        rgba(255,255,255,.035);

    border:
        1px solid
        rgba(255,255,255,.06);

    transition:
        .2s ease;
}


.quick-action:hover {

    transform:
        translateX(4px);

    border-color:
        rgba(217,180,91,.25);

    background:
        rgba(217,180,91,.055);
}


.quick-icon {

    width:
        40px;

    height:
        40px;

    flex:
        0 0 40px;

    display:
        flex;

    align-items:
        center;

    justify-content:
        center;

    border-radius:
        10px;

    background:
        rgba(217,180,91,.10);

    font-size:
        18px;
}


.quick-text strong {

    display:
        block;

    margin-bottom:
        3px;

    color:
        #ffffff;

    font-size:
        12px;
}


.quick-text span {

    color:
        #707a8c;

    font-size:
        10px;
}


/* ============================================================
   EMPTY
============================================================ */

.empty {

    padding:
        45px 20px;

    text-align:
        center;

    color:
        #697487;

    border:
        1px dashed
        rgba(255,255,255,.10);

    border-radius:
        12px;
}


.empty-icon {

    font-size:
        45px;

    margin-bottom:
        10px;
}


/* ============================================================
   FOOTER
============================================================ */

.footer {

    margin-top:
        28px;

    padding-top:
        18px;

    border-top:
        1px solid
        rgba(255,255,255,.06);

    display:
        flex;

    align-items:
        center;

    justify-content:
        space-between;

    color:
        #596476;

    font-size:
        10px;
}


/* ============================================================
   RESPONSIVE
============================================================ */

@media (max-width: 1250px) {

    .stats {

        grid-template-columns:
            repeat(3, 1fr);
    }


    .food-grid {

        grid-template-columns:
            repeat(3, minmax(0, 1fr));
    }

}


@media (max-width: 1000px) {

    .bottom-grid {

        grid-template-columns:
            1fr;
    }


    .food-grid {

        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }

}


@media (max-width: 750px) {

    .header {

        padding:
            12px 16px;

        align-items:
            flex-start;

        flex-direction:
            column;
    }


    .header-right {

        width:
            100%;

        justify-content:
            flex-start;
    }


    .container {

        width:
            92%;

        padding-top:
            22px;
    }


    .hero {

        flex-direction:
            column;

        align-items:
            flex-start;

        padding:
            22px;
    }


    .hero h1 {

        font-size:
            26px;
    }


    .hero-date {

        width:
            100%;
    }


    .stats {

        grid-template-columns:
            repeat(2, 1fr);
    }


    .food-section,
    .orders-panel,
    .quick-panel {

        padding:
            17px;
    }


    .section-header {

        align-items:
            flex-start;

        flex-direction:
            column;
    }


    .section-actions {

        width:
            100%;
    }


    .action-btn {

        flex:
            1;
    }

}


@media (max-width: 480px) {

    .stats {

        grid-template-columns:
            1fr 1fr;

        gap:
            9px;
    }


    .stat {

        min-height:
            100px;

        padding:
            13px;
    }


    .stat-number {

        font-size:
            21px;
    }


    .food-grid {

        grid-template-columns:
            1fr;
    }


    .food-image-box {

        height:
            205px;
    }


    .user-info {

        display:
            none;
    }


    .header-btn {

        flex:
            1;
    }


    .footer {

        flex-direction:
            column;

        gap:
            7px;

        align-items:
            flex-start;
    }

}

</style>

</head>


<body>


<!-- ============================================================
     HEADER
============================================================ -->

<header class="header">


    <div class="logo">

        <div class="logo-icon">
            🍽️
        </div>

        <div class="logo-text">
            SUVA <span>HOTEL</span>
        </div>

    </div>


    <div class="header-right">


        <div class="user-info">

            👤

            <?= e($username) ?>

            <span class="user-role">
                <?= e($role) ?>
            </span>

        </div>


        <a
            href="<?= e($dashboardUrl) ?>"
            class="header-btn header-dashboard"
        >
            ← Dashboard
        </a>


        <a
            href="orders/add.php"
            class="header-btn header-order"
        >
            ＋ Create Order
        </a>


    </div>

</header>


<!-- ============================================================
     MAIN
============================================================ -->

<main class="container">


    <!-- ========================================================
         HERO
    ========================================================= -->

    <section class="hero">


        <div>

            <h1>
                Restaurant <span>Management</span>
            </h1>

            <p>
                Manage food items, restaurant orders,
                availability and daily operations.
            </p>

        </div>


        <div class="hero-date">

            📅
            <?= e($currentDate) ?>

        </div>


    </section>


    <!-- ========================================================
         STATISTICS
    ========================================================= -->

    <section class="stats">


        <div class="stat gold">

            <div class="stat-icon">
                🍽️
            </div>

            <div class="stat-number">
                <?= $totalFoods ?>
            </div>

            <div class="stat-label">
                Total Food Items
            </div>

        </div>


        <div class="stat green">

            <div class="stat-icon">
                ✅
            </div>

            <div class="stat-number">
                <?= $availableFoods ?>
            </div>

            <div class="stat-label">
                Available Foods
            </div>

        </div>


        <div class="stat">

            <div class="stat-icon">
                📦
            </div>

            <div class="stat-number">
                <?= $totalOrders ?>
            </div>

            <div class="stat-label">
                Total Orders
            </div>

        </div>


        <div class="stat blue">

            <div class="stat-icon">
                🗓️
            </div>

            <div class="stat-number">
                <?= $todayOrders ?>
            </div>

            <div class="stat-label">
                Today's Orders
            </div>

        </div>


        <div class="stat orange">

            <div class="stat-icon">
                ⏳
            </div>

            <div class="stat-number">
                <?= $pendingOrders ?>
            </div>

            <div class="stat-label">
                Pending Orders
            </div>

        </div>


        <div class="stat gold">

            <div class="stat-icon">
                💰
            </div>

            <div class="stat-number"
                 style="font-size:20px;">

                <?= money($orderRevenue) ?>

            </div>

            <div class="stat-label">
                Restaurant Revenue
            </div>

        </div>


    </section>


    <!-- ========================================================
         FOOD MENU
    ========================================================= -->

    <section class="food-section">


        <div class="section-header">


            <div class="section-title">

                <h2>
                    🍴 Food Menu
                </h2>

                <p>
                    Food items and their uploaded pictures
                </p>

            </div>


            <div class="section-actions">

                <a
                    href="food/view.php"
                    class="action-btn action-secondary"
                >
                    View All Foods
                </a>


                <a
                    href="food/add.php"
                    class="action-btn action-primary"
                >
                    ＋ Add Food
                </a>

            </div>


        </div>


        <!-- ====================================================
             SEARCH + CATEGORY
        ===================================================== -->

        <div class="food-controls">


            <div class="search-box">

                <span class="search-icon">
                    🔍
                </span>

                <input
                    type="text"
                    id="foodSearch"
                    placeholder="Search food items..."
                    autocomplete="off"
                >

            </div>


            <div class="category-filter">


                <button
                    type="button"
                    class="category-btn active"
                    data-category="all"
                >
                    All
                </button>


                <?php foreach ($categories as $category): ?>

                    <button
                        type="button"
                        class="category-btn"
                        data-category="<?= e(
                            strtolower(
                                trim($category)
                            )
                        ) ?>"
                    >

                        <?= e($category) ?>

                    </button>

                <?php endforeach; ?>


            </div>


        </div>


        <!-- ====================================================
             FOOD GRID
        ===================================================== -->

        <?php if (empty($foodItems)): ?>


            <div class="empty">

                <div class="empty-icon">
                    🍽️
                </div>

                No food items found.

            </div>


        <?php else: ?>


            <div
                class="food-grid"
                id="foodGrid"
            >


                <?php foreach ($foodItems as $food): ?>


                    <?php

                    $foodId =
                        (int)(
                            $food["id"] ?? 0
                        );


                    $foodName =
                        $food["food_name"] ?? "Food";


                    $category =
                        trim(
                            $food["category"] ?? ""
                        );


                    $price =
                        (float)(
                            $food["price"] ?? 0
                        );


                    $status =
                        trim(
                            $food["status"] ??
                            "Unavailable"
                        );


                    $imageUrl =
                        getFoodImage(
                            $food["image"] ?? ""
                        );


                    $available =
                        strtolower(
                            $status
                        ) === "available";


                    $searchText =
                        strtolower(
                            $foodName .
                            " " .
                            $category
                        );

                    ?>


                    <article
                        class="food-card"
                        data-category="<?= e(
                            strtolower($category)
                        ) ?>"
                        data-search="<?= e(
                            $searchText
                        ) ?>"
                    >


                        <!-- IMAGE -->

                        <div class="food-image-box">


                            <?php if ($imageUrl !== ""): ?>


                                <img
                                    src="<?= e($imageUrl) ?>"
                                    alt="<?= e($foodName) ?>"
                                    class="food-image"
                                    loading="lazy"
                                    onerror="
                                        this.style.display='none';
                                        this.nextElementSibling.style.display='flex';
                                    "
                                >


                                <div
                                    class="image-placeholder"
                                    style="display:none;"
                                >

                                    🍽️

                                    <span>
                                        Image unavailable
                                    </span>

                                </div>


                            <?php else: ?>


                                <div class="image-placeholder">

                                    🍽️

                                    <span>
                                        No image
                                    </span>

                                </div>


                            <?php endif; ?>


                            <!-- STATUS -->

                            <div
                                class="food-status
                                <?= $available
                                    ? 'available'
                                    : 'unavailable'
                                ?>"
                            >

                                <?= $available
                                    ? "● Available"
                                    : "● Unavailable"
                                ?>

                            </div>


                        </div>


                        <!-- CONTENT -->

                        <div class="food-content">


                            <div class="food-name">

                                <?= e($foodName) ?>

                            </div>


                            <?php if ($category !== ""): ?>

                                <div class="food-category">

                                    <?= e($category) ?>

                                </div>

                            <?php endif; ?>


                            <div class="food-footer">


                                <div class="food-price">

                                    <?= money($price) ?>

                                </div>


                                <?php if ($available): ?>

                                    <a
                                        href="orders/add.php"
                                        class="order-food-btn"
                                    >
                                        ＋ Order
                                    </a>

                                <?php else: ?>

                                    <span
                                        style="
                                            color:#687386;
                                            font-size:10px;
                                            font-weight:700;
                                        "
                                    >
                                        Unavailable
                                    </span>

                                <?php endif; ?>


                            </div>


                        </div>


                    </article>


                <?php endforeach; ?>


            </div>


            <div
                id="noSearchResults"
                class="empty"
                style="display:none;margin-top:18px;"
            >

                <div class="empty-icon">
                    🔍
                </div>

                No matching food items found.

            </div>


        <?php endif; ?>


    </section>


    <!-- ========================================================
         BOTTOM GRID
    ========================================================= -->

    <div class="bottom-grid">


        <!-- ====================================================
             RECENT ORDERS
        ===================================================== -->

        <section class="orders-panel">


            <div class="section-header">


                <div class="section-title">

                    <h2>
                        🧾 Recent Orders
                    </h2>

                    <p>
                        Latest restaurant transactions
                    </p>

                </div>


                <div class="section-actions">

                    <a
                        href="orders/index.php"
                        class="action-btn action-secondary"
                    >
                        View All Orders
                    </a>


                    <a
                        href="orders/add.php"
                        class="action-btn action-primary"
                    >
                        ＋ New Order
                    </a>

                </div>


            </div>


            <?php if (empty($recentOrders)): ?>


                <div class="empty">

                    <div class="empty-icon">
                        🧾
                    </div>

                    No restaurant orders found.

                </div>


            <?php else: ?>


                <div class="table-wrapper">


                    <table class="orders-table">


                        <thead>

                            <tr>

                                <th>
                                    Order
                                </th>

                                <th>
                                    Type
                                </th>

                                <th>
                                    Amount
                                </th>

                                <th>
                                    Payment
                                </th>

                                <th>
                                    Status
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


                            <?php foreach (
                                $recentOrders
                                as $order
                            ): ?>


                                <?php

                                $orderId =
                                    (int)(
                                        $order["id"] ?? 0
                                    );


                                $orderType =
                                    $order[
                                        "order_type"
                                    ] ??
                                    "Dine-In";


                                $amount =
                                    (float)(
                                        $order[
                                            "total_amount"
                                        ] ?? 0
                                    );


                                $paymentMethod =
                                    $order[
                                        "payment_method"
                                    ] ??
                                    "-";


                                $paymentStatus =
                                    strtolower(
                                        trim(
                                            $order[
                                                "payment_status"
                                            ] ??
                                            ""
                                        )
                                    );


                                $orderStatus =
                                    strtolower(
                                        trim(
                                            $order[
                                                "order_status"
                                            ] ??
                                            ""
                                        )
                                    );


                                $createdAt =
                                    $order[
                                        "created_at"
                                    ] ??
                                    "";

                                ?>


                                <tr>


                                    <td>

                                        <span class="order-number">

                                            #<?= $orderId ?>

                                        </span>

                                    </td>


                                    <td>

                                        <?= e(
                                            $orderType
                                        ) ?>

                                    </td>


                                    <td>

                                        <span
                                            class="order-amount"
                                        >

                                            <?= money(
                                                $amount
                                            ) ?>

                                        </span>

                                    </td>


                                    <td>

                                        <?= e(
                                            $paymentMethod
                                        ) ?>

                                        <br>

                                        <span
                                            class="badge
                                            <?= $paymentStatus === "paid"
                                                ? "paid"
                                                : "unpaid"
                                            ?>"
                                        >

                                            <?= e(
                                                ucfirst(
                                                    $paymentStatus !== ""
                                                        ? $paymentStatus
                                                        : "unpaid"
                                                )
                                            ) ?>

                                        </span>

                                    </td>


                                    <td>


                                        <?php

                                        $statusClass =
                                            "pending";


                                        if (
                                            $orderStatus ===
                                            "preparing"
                                        ) {

                                            $statusClass =
                                                "preparing";

                                        } elseif (
                                            $orderStatus ===
                                            "ready"
                                        ) {

                                            $statusClass =
                                                "ready";

                                        } elseif (
                                            $orderStatus ===
                                            "completed"
                                        ) {

                                            $statusClass =
                                                "completed";

                                        } elseif (
                                            $orderStatus ===
                                            "cancelled"
                                        ) {

                                            $statusClass =
                                                "cancelled";
                                        }

                                        ?>


                                        <span
                                            class="badge
                                            <?= $statusClass ?>"
                                        >

                                            <?= e(
                                                ucfirst(
                                                    $orderStatus !== ""
                                                        ? $orderStatus
                                                        : "Pending"
                                                )
                                            ) ?>

                                        </span>


                                    </td>


                                    <td>

                                        <?php

                                        if ($createdAt !== "") {

                                            echo e(
                                                date(
                                                    "M d, h:i A",
                                                    strtotime(
                                                        $createdAt
                                                    )
                                                )
                                            );

                                        } else {

                                            echo "-";
                                        }

                                        ?>

                                    </td>


                                    <td>

                                        <a
                                            href="orders/view.php?id=<?= $orderId ?>"
                                            class="view-order"
                                        >
                                            View
                                        </a>

                                    </td>


                                </tr>


                            <?php endforeach; ?>


                        </tbody>


                    </table>


                </div>


            <?php endif; ?>


        </section>


        <!-- ====================================================
             QUICK ACTIONS
        ===================================================== -->

        <section class="quick-panel">


            <div class="section-header">

                <div class="section-title">

                    <h2>
                        ⚡ Quick Actions
                    </h2>

                    <p>
                        Restaurant shortcuts
                    </p>

                </div>

            </div>


            <div class="quick-actions">


                <a
                    href="orders/add.php"
                    class="quick-action"
                >

                    <div class="quick-icon">
                        🧾
                    </div>

                    <div class="quick-text">

                        <strong>
                            Create Restaurant Order
                        </strong>

                        <span>
                            Start a new customer order
                        </span>

                    </div>

                </a>


                <a
                    href="orders/index.php"
                    class="quick-action"
                >

                    <div class="quick-icon">
                        📋
                    </div>

                    <div class="quick-text">

                        <strong>
                            Order Records
                        </strong>

                        <span>
                            View and manage restaurant orders
                        </span>

                    </div>

                </a>


                <a
                    href="food/view.php"
                    class="quick-action"
                >

                    <div class="quick-icon">
                        🍴
                    </div>

                    <div class="quick-text">

                        <strong>
                            Manage Food Menu
                        </strong>

                        <span>
                            Edit prices and availability
                        </span>

                    </div>

                </a>


                <a
                    href="food/add.php"
                    class="quick-action"
                >

                    <div class="quick-icon">
                        ➕
                    </div>

                    <div class="quick-text">

                        <strong>
                            Add Food Item
                        </strong>

                        <span>
                            Add food with picture and price
                        </span>

                    </div>

                </a>


            </div>


            <!-- MINI SUMMARY -->

            <div
                style="
                    margin-top:20px;
                    padding-top:18px;
                    border-top:1px solid rgba(255,255,255,.07);
                "
            >

                <div
                    style="
                        display:flex;
                        justify-content:space-between;
                        margin-bottom:10px;
                        color:#7c8798;
                        font-size:11px;
                    "
                >

                    <span>
                        Preparing
                    </span>

                    <strong
                        style="color:#60a5fa;"
                    >
                        <?= $preparingOrders ?>
                    </strong>

                </div>


                <div
                    style="
                        display:flex;
                        justify-content:space-between;
                        margin-bottom:10px;
                        color:#7c8798;
                        font-size:11px;
                    "
                >

                    <span>
                        Ready
                    </span>

                    <strong
                        style="color:#4ade80;"
                    >
                        <?= $readyOrders ?>
                    </strong>

                </div>


                <div
                    style="
                        display:flex;
                        justify-content:space-between;
                        color:#7c8798;
                        font-size:11px;
                    "
                >

                    <span>
                        Unavailable Foods
                    </span>

                    <strong
                        style="color:#f87171;"
                    >
                        <?= $unavailableFoods ?>
                    </strong>

                </div>


            </div>


        </section>


    </div>


    <!-- ========================================================
         FOOTER
    ========================================================= -->

    <footer class="footer">

        <div>
            © <?= date("Y") ?> Suva Hotel
            · Restaurant Management
        </div>

        <div>
            PostgreSQL · Premium Management System
        </div>

    </footer>


</main>


<script>

/* ============================================================
   FOOD SEARCH
============================================================ */

const searchInput =
    document.getElementById(
        "foodSearch"
    );


const foodCards =
    document.querySelectorAll(
        ".food-card"
    );


const categoryButtons =
    document.querySelectorAll(
        ".category-btn"
    );


const noSearchResults =
    document.getElementById(
        "noSearchResults"
    );


let selectedCategory =
    "all";


function filterFoods()
{
    const search =
        (
            searchInput
                ? searchInput.value
                : ""
        )
        .toLowerCase()
        .trim();


    let visibleCount = 0;


    foodCards.forEach(
        function(card)
        {

            const cardSearch =
                (
                    card.dataset.search ||
                    ""
                ).toLowerCase();


            const cardCategory =
                (
                    card.dataset.category ||
                    ""
                ).toLowerCase();


            const matchesSearch =
                cardSearch.includes(
                    search
                );


            const matchesCategory =
                selectedCategory ===
                "all" ||

                cardCategory ===
                selectedCategory;


            if (
                matchesSearch &&
                matchesCategory
            ) {

                card.classList.remove(
                    "hidden"
                );

                visibleCount++;

            } else {

                card.classList.add(
                    "hidden"
                );
            }

        }
    );


    if (noSearchResults) {

        noSearchResults.style.display =
            visibleCount === 0
                ? "block"
                : "none";
    }
}


/* ============================================================
   SEARCH EVENT
============================================================ */

if (searchInput) {

    searchInput.addEventListener(
        "input",
        filterFoods
    );
}


/* ============================================================
   CATEGORY EVENTS
============================================================ */

categoryButtons.forEach(
    function(button)
    {

        button.addEventListener(
            "click",
            function()
            {

                categoryButtons.forEach(
                    function(btn)
                    {

                        btn.classList.remove(
                            "active"
                        );

                    }
                );


                button.classList.add(
                    "active"
                );


                selectedCategory =
                    (
                        button.dataset.category ||
                        "all"
                    ).toLowerCase();


                filterFoods();

            }
        );

    }
);


/* ============================================================
   INITIAL FILTER
============================================================ */

filterFoods();

</script>


</body>

</html>