```php
<?php
// ============================================================
// SUVA HOTEL
// SWIMMING POOL FOOD & DRINK ORDERING
// File: /suva_hotel/swimming/order.php
// PostgreSQL Version
// ============================================================

session_start();

require_once __DIR__ . '/../config/database.php';

if (!isset($pgConnection) || !$pgConnection) {
    die("Database connection failed.");
}

// ============================================================
// HELPER
// ============================================================

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// ============================================================
// SWIMMING ID
// ============================================================

$swimming_id = isset($_GET['swimming_id'])
    ? (int)$_GET['swimming_id']
    : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

if ($swimming_id <= 0) {
    die("
        <div style='font-family:Arial;padding:40px;text-align:center'>
            <h2>Invalid Swimming ID</h2>
            <p>Please open the order page from the swimming records.</p>
            <a href='view.php'>← Back to Swimming Records</a>
        </div>
    ");
}

// ============================================================
// LOAD SWIMMING RECORD
// ============================================================

$sql = "
    SELECT
        s.id,
        s.guest_id,
        s.booking_id,
        s.visitor_type,
        s.visitor_name,
        s.visit_date,
        s.entry_time,
        s.exit_time,
        s.adults,
        s.children,
        s.adult_charge,
        s.child_charge,
        s.total_amount,
        s.status,
        s.created_at,

        g.full_name,
        g.phone,
        g.email

    FROM swimming s

    LEFT JOIN guests g
        ON g.id = s.guest_id

    WHERE s.id = $1

    LIMIT 1
";

$result = pg_query_params($pgConnection, $sql, [$swimming_id]);

if (!$result) {
    die("Swimming database error: " . e(pg_last_error($pgConnection)));
}

$swimmer = pg_fetch_assoc($result);

if (!$swimmer) {
    die("
        <div style='font-family:Arial;padding:40px;text-align:center'>
            <h2>Swimming Record Not Found</h2>
            <p>Swimming ID #".e($swimming_id)." does not exist.</p>
            <a href='view.php'>← Back to Swimming Records</a>
        </div>
    ");
}

// ============================================================
// STATUS CHECK
// ============================================================

if (strtolower((string)$swimmer['status']) === 'cancelled') {
    die("
        <div style='font-family:Arial;padding:40px;text-align:center'>
            <h2>Swimming Session Cancelled</h2>
            <p>Food ordering is not available for this swimming session.</p>
            <a href='view.php'>← Back to Swimming Records</a>
        </div>
    ");
}

// ============================================================
// SWIMMING CHARGE
// ============================================================

$swimming_charge = (float)$swimmer['total_amount'];

// ============================================================
// LOAD RESTAURANT FOODS
// IMPORTANT:
// restaurant_foods.status = Available / Unavailable
// ============================================================

$foodSql = "
    SELECT
        id,
        food_name,
        category,
        image,
        price,
        status,
        created_at
    FROM restaurant_foods
    WHERE status = 'Available'
    ORDER BY category ASC, food_name ASC
";

$foodResult = pg_query($pgConnection, $foodSql);

$foods = [];

if ($foodResult) {
    while ($food = pg_fetch_assoc($foodResult)) {
        $foods[] = $food;
    }
}

// ============================================================
// CATEGORY ICON
// ============================================================

function categoryIcon($category)
{
    $category = strtolower(trim((string)$category));

    if (strpos($category, 'beer') !== false) {
        return '🍺';
    }

    if (
        strpos($category, 'drink') !== false ||
        strpos($category, 'soft') !== false
    ) {
        return '🥤';
    }

    if (
        strpos($category, 'juice') !== false
    ) {
        return '🧃';
    }

    if (
        strpos($category, 'snack') !== false
    ) {
        return '🍟';
    }

    if (
        strpos($category, 'food') !== false
    ) {
        return '🍽️';
    }

    return '🍴';
}

// ============================================================
// IMAGE URL
// ============================================================


function foodImage($image)
{
    $image = trim((string)$image);

    if ($image === '') {
        return '';
    }

    // External image URL
    if (
        strpos($image, 'http://') === 0 ||
        strpos($image, 'https://') === 0
    ) {
        return $image;
    }

    // Remove any leading slash
    $image = ltrim(str_replace('\\', '/', $image), '/');

    // Image paths saved as restaurant/food/images/filename.jpg
    if (strpos($image, 'restaurant/food/images/') === 0) {
        return '../' . $image;
    }

    // Image paths saved as restaurant/food/uploads/filename.jpg
    if (strpos($image, 'restaurant/food/uploads/') === 0) {
        return '../' . $image;
    }

    // Database stores only the filename
    $filename = basename($image);

    $imagesFolder = __DIR__ . '/../restaurant/food/images/';
    $uploadsFolder = __DIR__ . '/../restaurant/food/uploads/';

    if (is_file($imagesFolder . $filename)) {
        return '../restaurant/food/images/' . rawurlencode($filename);
    }

    if (is_file($uploadsFolder . $filename)) {
        return '../restaurant/food/uploads/' . rawurlencode($filename);
    }

    return '';
}


// ============================================================
// BUILD CATEGORIES
// ============================================================

$categories = [];

foreach ($foods as $food) {

    $category = trim((string)$food['category']);

    if ($category === '') {
        $category = 'Other';
    }

    if (!in_array($category, $categories, true)) {
        $categories[] = $category;
    }
}

sort($categories, SORT_NATURAL | SORT_FLAG_CASE);

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>
    Swimming Food Order | Suva Hotel
</title>

<style>

/* =========================================================
   RESET
========================================================= */

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family:
        Inter,
        Arial,
        Helvetica,
        sans-serif;

    background:
        linear-gradient(
            135deg,
            #f4f7fb 0%,
            #eef3f8 50%,
            #e8f1f5 100%
        );

    color: #172033;
    min-height: 100vh;
}

/* =========================================================
   HEADER
========================================================= */

.top-header {

    background:
        linear-gradient(
            135deg,
            #071b33,
            #0b3154,
            #075e73
        );

    color: white;

    padding: 22px 32px;

    box-shadow:
        0 8px 30px rgba(0,0,0,.16);

    position: sticky;
    top: 0;
    z-index: 100;
}

.header-inner {

    max-width: 1500px;
    margin: auto;

    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 20px;
}

.hotel-brand {

    display: flex;
    align-items: center;
    gap: 14px;
}

.hotel-logo {

    width: 55px;
    height: 55px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 16px;

    background:
        rgba(255,255,255,.14);

    font-size: 28px;

    border:
        1px solid rgba(255,255,255,.2);
}

.hotel-name {

    font-size: 25px;
    font-weight: 800;
    letter-spacing: .5px;
}

.hotel-subtitle {

    font-size: 13px;
    color: #b9d7e7;
    margin-top: 3px;
}

.header-actions {

    display: flex;
    gap: 10px;
}

.header-btn {

    color: white;
    text-decoration: none;

    padding: 11px 17px;

    border-radius: 11px;

    background:
        rgba(255,255,255,.10);

    border:
        1px solid rgba(255,255,255,.16);

    font-size: 14px;
    font-weight: 600;

    transition: .2s;
}

.header-btn:hover {

    background:
        rgba(255,255,255,.20);

    transform: translateY(-1px);
}

/* =========================================================
   MAIN
========================================================= */

.container {

    max-width: 1500px;
    margin: auto;

    padding: 28px;
}

/* =========================================================
   SWIMMER CARD
========================================================= */

.swimmer-card {

    background: white;

    border-radius: 22px;

    padding: 24px;

    margin-bottom: 25px;

    box-shadow:
        0 10px 35px rgba(21,44,67,.08);

    border:
        1px solid #e3ebf2;

    display: flex;
    justify-content: space-between;
    align-items: center;

    gap: 20px;
}

.swimmer-left {

    display: flex;
    align-items: center;
    gap: 17px;
}

.swimmer-icon {

    width: 65px;
    height: 65px;

    border-radius: 18px;

    background:
        linear-gradient(
            135deg,
            #dff7ff,
            #bcecf7
        );

    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 32px;
}

.swimmer-name {

    font-size: 22px;
    font-weight: 800;

    color: #12263a;
}

.swimmer-details {

    color: #6c7b8a;

    margin-top: 7px;

    font-size: 14px;
}

.swimmer-details strong {
    color: #26384a;
}

.swimmer-right {

    display: flex;
    align-items: center;
    gap: 14px;
}

.charge-box {

    background: #f4f8fb;

    border-radius: 14px;

    padding: 12px 18px;

    text-align: right;
}

.charge-label {

    color: #7a8794;

    font-size: 12px;
}

.charge-value {

    font-size: 20px;

    font-weight: 800;

    color: #0b6173;
}

.status {

    padding: 10px 14px;

    border-radius: 30px;

    background: #e8f8ef;

    color: #168047;

    font-size: 13px;

    font-weight: 700;
}

/* =========================================================
   LAYOUT
========================================================= */

.order-layout {

    display: grid;

    grid-template-columns:
        minmax(0, 1fr)
        390px;

    gap: 25px;

    align-items: start;
}

/* =========================================================
   MENU
========================================================= */

.menu-panel {

    background: white;

    border-radius: 22px;

    padding: 25px;

    box-shadow:
        0 10px 35px rgba(21,44,67,.08);

    border:
        1px solid #e3ebf2;
}

.panel-header {

    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 15px;

    margin-bottom: 20px;
}

.panel-title {

    font-size: 23px;
    font-weight: 800;

    color: #12263a;
}

.panel-description {

    color: #7a8794;

    font-size: 13px;

    margin-top: 5px;
}

/* =========================================================
   SEARCH
========================================================= */

.search-box {

    position: relative;

    margin-bottom: 17px;
}

.search-box input {

    width: 100%;

    border: 1px solid #dbe5ed;

    background: #f8fafc;

    padding: 14px 17px 14px 44px;

    border-radius: 13px;

    font-size: 14px;

    outline: none;

    transition: .2s;
}

.search-box input:focus {

    border-color: #0b7892;

    background: white;

    box-shadow:
        0 0 0 4px rgba(11,120,146,.08);
}

.search-icon {

    position: absolute;

    left: 16px;
    top: 50%;

    transform: translateY(-50%);

    font-size: 18px;
}

/* =========================================================
   CATEGORIES
========================================================= */

.categories {

    display: flex;

    gap: 9px;

    overflow-x: auto;

    padding-bottom: 8px;

    margin-bottom: 20px;
}

.category-btn {

    white-space: nowrap;

    border: 1px solid #dce6ed;

    background: white;

    color: #526274;

    padding: 10px 15px;

    border-radius: 30px;

    cursor: pointer;

    font-size: 13px;

    font-weight: 700;

    transition: .2s;
}

.category-btn:hover {

    border-color: #0b7892;

    color: #0b7892;
}

.category-btn.active {

    background:
        linear-gradient(
            135deg,
            #0b6173,
            #0787a0
        );

    color: white;

    border-color: transparent;

    box-shadow:
        0 5px 15px rgba(7,135,160,.25);
}

/* =========================================================
   FOOD GRID
========================================================= */

.food-grid {

    display: grid;

    grid-template-columns:
        repeat(
            auto-fill,
            minmax(190px, 1fr)
        );

    gap: 17px;
}

.food-card {

    border:
        1px solid #e2eaf0;

    border-radius: 17px;

    overflow: hidden;

    background: white;

    transition:
        transform .2s,
        box-shadow .2s,
        border-color .2s;

    position: relative;
}

.food-card:hover {

    transform: translateY(-4px);

    box-shadow:
        0 12px 28px rgba(16,48,72,.13);

    border-color: #b8dce6;
}

.food-image {

    height: 145px;

    background:
        linear-gradient(
            135deg,
            #e7f5f7,
            #f3f7f8
        );

    display: flex;
    align-items: center;
    justify-content: center;

    overflow: hidden;

    position: relative;
}

.food-image img {

    width: 100%;
    height: 100%;

    object-fit: cover;
}

.food-emoji {

    font-size: 54px;
}

.food-category {

    position: absolute;

    top: 10px;
    left: 10px;

    background:
        rgba(7,30,48,.78);

    color: white;

    padding: 5px 9px;

    border-radius: 8px;

    font-size: 10px;

    font-weight: 700;

    backdrop-filter: blur(5px);
}

.food-info {

    padding: 14px;
}

.food-name {

    font-size: 15px;

    font-weight: 800;

    color: #172b3d;

    min-height: 38px;
}

.food-bottom {

    display: flex;

    align-items: center;

    justify-content: space-between;

    margin-top: 11px;

    gap: 8px;
}

.food-price {

    font-size: 16px;

    font-weight: 800;

    color: #08728b;
}

.add-btn {

    border: none;

    background:
        linear-gradient(
            135deg,
            #0b6173,
            #0787a0
        );

    color: white;

    width: 36px;
    height: 36px;

    border-radius: 10px;

    cursor: pointer;

    font-size: 20px;

    font-weight: 700;

    transition: .2s;
}

.add-btn:hover {

    transform: scale(1.08);

    box-shadow:
        0 5px 15px rgba(7,135,160,.3);
}

/* =========================================================
   EMPTY
========================================================= */

.empty-menu {

    text-align: center;

    padding: 70px 20px;

    color: #7c8a96;
}

.empty-menu-icon {

    font-size: 60px;

    margin-bottom: 15px;
}

.empty-menu h3 {

    color: #304355;

    margin-bottom: 8px;
}

/* =========================================================
   CART
========================================================= */

.cart-panel {

    background: white;

    border-radius: 22px;

    box-shadow:
        0 10px 35px rgba(21,44,67,.10);

    border:
        1px solid #e3ebf2;

    overflow: hidden;

    position: sticky;

    top: 105px;
}

.cart-header {

    background:
        linear-gradient(
            135deg,
            #071b33,
            #0b6173
        );

    color: white;

    padding: 22px;

    display: flex;

    justify-content: space-between;

    align-items: center;
}

.cart-title {

    font-size: 20px;

    font-weight: 800;
}

.cart-count {

    background:
        rgba(255,255,255,.18);

    padding: 6px 10px;

    border-radius: 20px;

    font-size: 12px;

    font-weight: 700;
}

.cart-body {

    padding: 18px;
}

.cart-items {

    max-height: 330px;

    overflow-y: auto;

    margin-bottom: 17px;
}

.empty-cart {

    text-align: center;

    padding: 35px 15px;

    color: #8996a3;
}

.empty-cart-icon {

    font-size: 42px;

    margin-bottom: 10px;
}

/* =========================================================
   CART ITEM
========================================================= */

.cart-item {

    display: grid;

    grid-template-columns:
        1fr
        auto;

    gap: 8px;

    padding: 12px 0;

    border-bottom:
        1px solid #edf1f4;
}

.cart-item-name {

    font-size: 14px;

    font-weight: 700;

    color: #24384a;
}

.cart-item-price {

    font-size: 12px;

    color: #7c8995;

    margin-top: 3px;
}

.cart-item-right {

    text-align: right;
}

.cart-item-total {

    font-size: 14px;

    font-weight: 800;

    color: #0a7188;
}

.qty-controls {

    display: flex;

    align-items: center;

    justify-content: flex-end;

    gap: 6px;

    margin-top: 7px;
}

.qty-btn {

    width: 25px;
    height: 25px;

    border: 1px solid #d9e3e9;

    background: #f6f9fb;

    border-radius: 7px;

    cursor: pointer;

    font-weight: 800;

    color: #526273;
}

.qty-number {

    min-width: 22px;

    text-align: center;

    font-size: 12px;

    font-weight: 700;
}

/* =========================================================
   SUMMARY
========================================================= */

.summary {

    border-top:
        1px solid #e5edf2;

    padding-top: 15px;
}

.summary-row {

    display: flex;

    justify-content: space-between;

    padding: 6px 0;

    font-size: 13px;

    color: #647383;
}

.summary-row strong {

    color: #273b4e;
}

.grand-total {

    display: flex;

    justify-content: space-between;

    align-items: center;

    margin-top: 12px;

    padding: 15px;

    border-radius: 13px;

    background:
        linear-gradient(
            135deg,
            #eef9fb,
            #e3f5f7
        );
}

.grand-total span:first-child {

    font-weight: 800;

    color: #19364a;
}

.grand-total span:last-child {

    font-size: 21px;

    font-weight: 900;

    color: #08728b;
}

/* =========================================================
   PAYMENT
========================================================= */

.payment-section {

    margin-top: 18px;
}

.payment-title {

    font-size: 13px;

    font-weight: 800;

    color: #35495b;

    margin-bottom: 9px;
}

.payment-options {

    display: grid;

    grid-template-columns:
        1fr 1fr;

    gap: 8px;
}

.payment-option {

    position: relative;
}

.payment-option input {

    display: none;
}

.payment-option label {

    display: block;

    text-align: center;

    padding: 10px 5px;

    border:
        1px solid #dce5eb;

    border-radius: 10px;

    cursor: pointer;

    font-size: 12px;

    font-weight: 700;

    color: #596a79;

    transition: .2s;
}

.payment-option input:checked + label {

    border-color: #0787a0;

    background: #edf9fb;

    color: #067087;

    box-shadow:
        inset 0 0 0 1px #0787a0;
}

/* =========================================================
   ORDER BUTTON
========================================================= */

.order-button {

    width: 100%;

    border: none;

    margin-top: 18px;

    padding: 16px;

    border-radius: 13px;

    background:
        linear-gradient(
            135deg,
            #0b6173,
            #0787a0
        );

    color: white;

    font-size: 15px;

    font-weight: 900;

    cursor: pointer;

    box-shadow:
        0 8px 20px rgba(7,135,160,.25);

    transition: .2s;
}

.order-button:hover {

    transform: translateY(-2px);

    box-shadow:
        0 12px 25px rgba(7,135,160,.35);
}

.order-button:disabled {

    background: #b7c4cc;

    cursor: not-allowed;

    box-shadow: none;

    transform: none;
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1050px) {

    .order-layout {

        grid-template-columns: 1fr;
    }

    .cart-panel {

        position: static;
    }

}

@media (max-width: 700px) {

    .top-header {

        padding: 17px;
    }

    .header-inner {

        align-items: flex-start;
    }

    .hotel-name {

        font-size: 19px;
    }

    .header-actions {

        display: none;
    }

    .container {

        padding: 15px;
    }

    .swimmer-card {

        flex-direction: column;

        align-items: flex-start;
    }

    .swimmer-right {

        width: 100%;

        justify-content: space-between;
    }

    .food-grid {

        grid-template-columns:
            repeat(2, 1fr);

        gap: 10px;
    }

    .food-image {

        height: 120px;
    }

    .menu-panel {

        padding: 16px;
    }

}

/* =========================================================
   SCROLLBAR
========================================================= */

::-webkit-scrollbar {

    width: 7px;
    height: 7px;
}

::-webkit-scrollbar-thumb {

    background: #c4d3dc;

    border-radius: 10px;
}

</style>

</head>

<body>

<!-- =======================================================
     HEADER
======================================================= -->

<header class="top-header">

    <div class="header-inner">

        <div class="hotel-brand">

            <div class="hotel-logo">
                🏨
            </div>

            <div>

                <div class="hotel-name">
                    SUVA HOTEL
                </div>

                <div class="hotel-subtitle">
                    Swimming Pool Food & Drink Ordering
                </div>

            </div>

        </div>

        <div class="header-actions">

            <a
                href="view.php"
                class="header-btn"
            >
                ← Swimming Records
            </a>

            <a
                href="index.php"
                class="header-btn"
            >
                🏊 Swimming
            </a>

        </div>

    </div>

</header>


<!-- =======================================================
     MAIN
======================================================= -->

<main class="container">


    <!-- ===================================================
         SWIMMER INFORMATION
    ==================================================== -->

    <section class="swimmer-card">

        <div class="swimmer-left">

            <div class="swimmer-icon">
                🏊
            </div>

            <div>

                <div class="swimmer-name">

                    <?= e(
                        $swimmer['full_name']
                        ?: $swimmer['visitor_name']
                        ?: 'Swimming Guest'
                    ) ?>

                </div>

                <div class="swimmer-details">

                    Swimming ID:
                    <strong>
                        #<?= e($swimmer['id']) ?>
                    </strong>

                    &nbsp;•&nbsp;

                    <?= e($swimmer['visitor_type'] ?: 'Guest') ?>

                    &nbsp;•&nbsp;

                    <?= e($swimmer['adults']) ?> Adult(s)

                    &nbsp;•&nbsp;

                    <?= e($swimmer['children']) ?> Child(ren)

                </div>

            </div>

        </div>


        <div class="swimmer-right">

            <div class="charge-box">

                <div class="charge-label">
                    Swimming Charge
                </div>

                <div class="charge-value">
                    Rs. <?= number_format($swimming_charge, 2) ?>
                </div>

            </div>

            <div class="status">
                🟢 Active
            </div>

        </div>

    </section>


    <!-- ===================================================
         ORDER LAYOUT
    ==================================================== -->

    <div class="order-layout">


        <!-- =================================================
             MENU
        ================================================== -->

        <section class="menu-panel">

            <div class="panel-header">

                <div>

                    <div class="panel-title">
                        🍽️ Restaurant Menu
                    </div>

                    <div class="panel-description">
                        Select food and drinks for this swimming guest.
                    </div>

                </div>

            </div>


            <?php if (!empty($foods)): ?>


                <!-- SEARCH -->

                <div class="search-box">

                    <span class="search-icon">
                        🔎
                    </span>

                    <input
                        type="text"
                        id="searchInput"
                        placeholder="Search food, drinks, beer..."
                    >

                </div>


                <!-- CATEGORIES -->

                <div class="categories">

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
                            data-category="<?= e(strtolower($category)) ?>"
                        >
                            <?= categoryIcon($category) ?>
                            <?= e($category) ?>
                        </button>

                    <?php endforeach; ?>

                </div>


                <!-- FOOD GRID -->

                <div class="food-grid" id="foodGrid">


                    <?php foreach ($foods as $food): ?>

                        <?php

                        $image = foodImage($food['image']);

                        $category =
                            trim((string)$food['category']);

                        if ($category === '') {
                            $category = 'Other';
                        }

                        ?>

                        <div
                            class="food-card"
                            data-name="<?= e(strtolower($food['food_name'])) ?>"
                            data-category="<?= e(strtolower($category)) ?>"
                        >

                            <div class="food-image">

                                <?php if ($image): ?>

                                    <img
                                        src="<?= e($image) ?>"
                                        alt="<?= e($food['food_name']) ?>"
                                        onerror="this.style.display='none';this.nextElementSibling.style.display='block';"
                                    >

                                    <div
                                        class="food-emoji"
                                        style="display:none"
                                    >
                                        <?= categoryIcon($category) ?>
                                    </div>

                                <?php else: ?>

                                    <div class="food-emoji">
                                        <?= categoryIcon($category) ?>
                                    </div>

                                <?php endif; ?>


                                <div class="food-category">

                                    <?= categoryIcon($category) ?>

                                    <?= e($category) ?>

                                </div>

                            </div>


                            <div class="food-info">

                                <div class="food-name">

                                    <?= e($food['food_name']) ?>

                                </div>


                                <div class="food-bottom">

                                    <div class="food-price">

                                        Rs.
                                        <?= number_format(
                                            (float)$food['price'],
                                            2
                                        ) ?>

                                    </div>


                                    <button
                                        type="button"
                                        class="add-btn"
                                        onclick="addToCart(
                                            <?= (int)$food['id'] ?>,
                                            '<?= e(
                                                addslashes(
                                                    $food['food_name']
                                                )
                                            ) ?>',
                                            <?= (float)$food['price'] ?>
                                        )"
                                    >
                                        +
                                    </button>

                                </div>

                            </div>

                        </div>

                    <?php endforeach; ?>


                </div>


                <div
                    id="noSearchResults"
                    class="empty-menu"
                    style="display:none"
                >

                    <div class="empty-menu-icon">
                        🔎
                    </div>

                    <h3>
                        No items found
                    </h3>

                    <p>
                        Try another food or drink name.
                    </p>

                </div>


            <?php else: ?>


                <div class="empty-menu">

                    <div class="empty-menu-icon">
                        🍽️
                    </div>

                    <h3>
                        No restaurant items available
                    </h3>

                    <p>
                        Please add food or drink items with
                        <strong>Available</strong> status
                        from restaurant management.
                    </p>

                </div>


            <?php endif; ?>

        </section>


        <!-- =================================================
             CART
        ================================================== -->

        <aside class="cart-panel">

            <div class="cart-header">

                <div class="cart-title">
                    🛒 Current Order
                </div>

                <div
                    class="cart-count"
                    id="cartCount"
                >
                    0 items
                </div>

            </div>


            <div class="cart-body">


                <!-- CART ITEMS -->

                <div
                    class="cart-items"
                    id="cartItems"
                >

                    <div class="empty-cart">

                        <div class="empty-cart-icon">
                            🛒
                        </div>

                        <p>
                            No items selected.
                        </p>

                        <small>
                            Add food or drinks from the menu.
                        </small>

                    </div>

                </div>


                <!-- SUMMARY -->

                <div class="summary">


                    <div class="summary-row">

                        <span>
                            Swimming Charge
                        </span>

                        <strong>
                            Rs.
                            <?= number_format(
                                $swimming_charge,
                                2
                            ) ?>
                        </strong>

                    </div>


                    <div class="summary-row">

                        <span>
                            Food Subtotal
                        </span>

                        <strong id="foodSubtotal">
                            Rs. 0.00
                        </strong>

                    </div>


                    <div class="summary-row">

                        <span>
                            VAT (13%)
                        </span>

                        <strong id="vatAmount">
                            Rs. 0.00
                        </strong>

                    </div>


                    <div class="grand-total">

                        <span>
                            Grand Total
                        </span>

                        <span id="grandTotal">

                            Rs.
                            <?= number_format(
                                $swimming_charge,
                                2
                            ) ?>

                        </span>

                    </div>


                </div>


                <!-- =================================================
                     PAYMENT
                ================================================== -->

                <div class="payment-section">

                    <div class="payment-title">
                        Payment Method
                    </div>


                    <div class="payment-options">


                        <div class="payment-option">

                            <input
                                type="radio"
                                name="payment_method"
                                id="cash"
                                value="Cash"
                                checked
                            >

                            <label for="cash">
                                💵 Cash
                            </label>

                        </div>


                        <div class="payment-option">

                            <input
                                type="radio"
                                name="payment_method"
                                id="card"
                                value="Card"
                            >

                            <label for="card">
                                💳 Card
                            </label>

                        </div>


                        <div class="payment-option">

                            <input
                                type="radio"
                                name="payment_method"
                                id="online"
                                value="Online"
                            >

                            <label for="online">
                                📱 Online
                            </label>

                        </div>


                        <div class="payment-option">

                            <input
                                type="radio"
                                name="payment_method"
                                id="room"
                                value="Room Charge"
                            >

                            <label for="room">
                                🛏️ Room
                            </label>

                        </div>


                    </div>

                </div>


                <!-- =================================================
                     FORM
                ================================================== -->

                <form
                    method="POST"
                    action="order_add.php"
                    id="orderForm"
                >

                    <input
                        type="hidden"
                        name="swimming_id"
                        value="<?= (int)$swimming_id ?>"
                    >

                    <input
                        type="hidden"
                        name="items"
                        id="itemsInput"
                        value=""
                    >

                    <input
                        type="hidden"
                        name="payment_method"
                        id="paymentMethodInput"
                        value="Cash"
                    >


                    <button
                        type="submit"
                        class="order-button"
                        id="orderButton"
                        disabled
                    >
                        🧾 Place Food Order
                    </button>

                </form>


            </div>

        </aside>


    </div>

</main>


<script>

// ============================================================
// CART
// ============================================================

let cart = {};

const swimmingCharge =
    <?= json_encode($swimming_charge) ?>;


// ============================================================
// ADD TO CART
// ============================================================

function addToCart(id, name, price)
{
    id = String(id);

    if (!cart[id]) {

        cart[id] = {

            food_id: parseInt(id),

            name: name,

            price: parseFloat(price),

            quantity: 1

        };

    } else {

        cart[id].quantity++;

    }

    renderCart();
}


// ============================================================
// CHANGE QUANTITY
// ============================================================

function changeQuantity(id, change)
{
    id = String(id);

    if (!cart[id]) {
        return;
    }

    cart[id].quantity += change;

    if (cart[id].quantity <= 0) {
        delete cart[id];
    }

    renderCart();
}


// ============================================================
// CART COUNT
// ============================================================

function getCartItemCount()
{
    return Object.values(cart).reduce(
        (total, item) => total + item.quantity,
        0
    );
}


// ============================================================
// FOOD SUBTOTAL
// ============================================================

function getFoodSubtotal()
{
    return Object.values(cart).reduce(
        (total, item) =>
            total +
            (item.price * item.quantity),
        0
    );
}


// ============================================================
// RENDER CART
// ============================================================

function renderCart()
{
    const cartItems =
        document.getElementById('cartItems');

    const cartCount =
        document.getElementById('cartCount');

    const foodSubtotalElement =
        document.getElementById('foodSubtotal');

    const vatAmountElement =
        document.getElementById('vatAmount');

    const grandTotalElement =
        document.getElementById('grandTotal');

    const orderButton =
        document.getElementById('orderButton');

    const items =
        Object.values(cart);

    const itemCount =
        getCartItemCount();

    const subtotal =
        getFoodSubtotal();

    const vat =
        subtotal * 0.13;

    const grandTotal =
        swimmingCharge +
        subtotal +
        vat;


    // --------------------------------------------------------
    // COUNT
    // --------------------------------------------------------

    cartCount.textContent =
        itemCount +
        (itemCount === 1 ? ' item' : ' items');


    // --------------------------------------------------------
    // EMPTY CART
    // --------------------------------------------------------

    if (items.length === 0) {

        cartItems.innerHTML = `

            <div class="empty-cart">

                <div class="empty-cart-icon">
                    🛒
                </div>

                <p>
                    No items selected.
                </p>

                <small>
                    Add food or drinks from the menu.
                </small>

            </div>

        `;

        orderButton.disabled = true;

    }

    // --------------------------------------------------------
    // CART ITEMS
    // --------------------------------------------------------

    else {

        cartItems.innerHTML = items.map(item => `

            <div class="cart-item">

                <div>

                    <div class="cart-item-name">
                        ${escapeHtml(item.name)}
                    </div>

                    <div class="cart-item-price">
                        Rs. ${item.price.toFixed(2)}
                    </div>

                </div>

                <div class="cart-item-right">

                    <div class="cart-item-total">
                        Rs.
                        ${(item.price * item.quantity).toFixed(2)}
                    </div>

                    <div class="qty-controls">

                        <button
                            type="button"
                            class="qty-btn"
                            onclick="changeQuantity(
                                ${item.food_id},
                                -1
                            )"
                        >
                            −
                        </button>

                        <span class="qty-number">
                            ${item.quantity}
                        </span>

                        <button
                            type="button"
                            class="qty-btn"
                            onclick="changeQuantity(
                                ${item.food_id},
                                1
                            )"
                        >
                            +
                        </button>

                    </div>

                </div>

            </div>

        `).join('');

        orderButton.disabled = false;
    }


    // --------------------------------------------------------
    // SUMMARY
    // --------------------------------------------------------

    foodSubtotalElement.textContent =
        'Rs. ' +
        subtotal.toFixed(2);

    vatAmountElement.textContent =
        'Rs. ' +
        vat.toFixed(2);

    grandTotalElement.textContent =
        'Rs. ' +
        grandTotal.toFixed(2);


    // --------------------------------------------------------
    // HIDDEN JSON
    // --------------------------------------------------------

    const cleanItems =
        items.map(item => ({

            food_id:
                parseInt(item.food_id),

            quantity:
                parseInt(item.quantity)

        }));

    document.getElementById(
        'itemsInput'
    ).value =
        JSON.stringify(cleanItems);
}


// ============================================================
// ESCAPE HTML
// ============================================================

function escapeHtml(value)
{
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}


// ============================================================
// CATEGORY FILTER
// ============================================================

document
    .querySelectorAll('.category-btn')
    .forEach(button => {

        button.addEventListener(
            'click',
            function()
            {

                document
                    .querySelectorAll('.category-btn')
                    .forEach(btn =>
                        btn.classList.remove('active')
                    );

                this.classList.add('active');

                const category =
                    this.dataset.category;

                filterFoods();

            }
        );

    });


// ============================================================
// SEARCH
// ============================================================

const searchInput =
    document.getElementById('searchInput');

if (searchInput) {

    searchInput.addEventListener(
        'input',
        filterFoods
    );

}


// ============================================================
// FILTER FOODS
// ============================================================

function filterFoods()
{

    const search =
        searchInput
            ? searchInput.value
                .toLowerCase()
                .trim()
            : '';

    const activeCategory =
        document.querySelector(
            '.category-btn.active'
        );

    const category =
        activeCategory
            ? activeCategory.dataset.category
            : 'all';

    const cards =
        document.querySelectorAll('.food-card');

    let visible = 0;


    cards.forEach(card => {

        const name =
            card.dataset.name;

        const cardCategory =
            card.dataset.category;

        const searchMatch =
            !search ||
            name.includes(search);

        const categoryMatch =
            category === 'all' ||
            cardCategory === category;

        if (
            searchMatch &&
            categoryMatch
        ) {

            card.style.display = '';

            visible++;

        } else {

            card.style.display = 'none';

        }

    });


    const noResults =
        document.getElementById(
            'noSearchResults'
        );

    if (noResults) {

        noResults.style.display =
            visible === 0
                ? 'block'
                : 'none';

    }

}


// ============================================================
// PAYMENT METHOD
// ============================================================

document
    .querySelectorAll(
        'input[name="payment_method"]'
    )
    .forEach(input => {

        input.addEventListener(
            'change',
            function()
            {

                document.getElementById(
                    'paymentMethodInput'
                ).value =
                    this.value;

            }
        );

    });


// ============================================================
// FORM VALIDATION
// ============================================================

document
    .getElementById('orderForm')
    .addEventListener(
        'submit',
        function(event)
        {

            if (
                Object.keys(cart).length === 0
            ) {

                event.preventDefault();

                alert(
                    'Please select at least one food or drink.'
                );

                return;
            }


            const payment =
                document.querySelector(
                    'input[name="payment_method"]:checked'
                );


            if (!payment) {

                event.preventDefault();

                alert(
                    'Please select a payment method.'
                );

                return;
            }


            const confirmed =
                confirm(
                    'Place this food and drink order for Swimming ID #<?= (int)$swimming_id ?>?'
                );


            if (!confirmed) {

                event.preventDefault();

            }

        }
    );


// Initial render

renderCart();

</script>

</body>

</html>
```
