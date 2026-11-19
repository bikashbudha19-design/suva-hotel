<?php
// ============================================================
// SUVA HOTEL - ADD RESTAURANT FOOD
// PostgreSQL Version
//
// File:
// /Suva_hotel/restaurant/food/add.php
//
// Images:
// /Suva_hotel/restaurant/food/images/
//
// Database table:
// restaurant_foods
// ============================================================

session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);


// ============================================================
// LOGIN PROTECTION
// ============================================================

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}


// ============================================================
// DATABASE
// ============================================================

require_once __DIR__ . '/../../config/database.php';

if (!isset($pgConnection) || !$pgConnection) {
    die("Database connection is not available.");
}


// ============================================================
// ROLE
// ============================================================

$role = strtolower($_SESSION['role'] ?? '');

if ($role !== 'admin' && $role !== 'staff') {
    header("Location: ../../login.php");
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
        'UTF-8'
    );
}


// ============================================================
// VARIABLES
// ============================================================

$message = '';
$messageType = '';

$food_name = '';
$category = '';
$price = '';
$status = 'Available';

$selectedImage = '';


// ============================================================
// IMAGE DIRECTORY
// ============================================================
//
// All restaurant food images are stored here:
//
// restaurant/food/images/
//

$imageDir = __DIR__ . '/images/';
$imageWebPath = 'images/';


// Create directory automatically
if (!is_dir($imageDir)) {

    if (!mkdir($imageDir, 0755, true)) {
        die("Could not create food image directory.");
    }
}


// ============================================================
// GET EXISTING IMAGES
// ============================================================

$existingImages = [];

$allowedExtensions = [
    'jpg',
    'jpeg',
    'png',
    'webp'
];

$files = @scandir($imageDir);

if ($files !== false) {

    foreach ($files as $file) {

        if (
            $file === '.' ||
            $file === '..'
        ) {
            continue;
        }

        $fullPath = $imageDir . $file;

        if (!is_file($fullPath)) {
            continue;
        }

        $extension = strtolower(
            pathinfo(
                $file,
                PATHINFO_EXTENSION
            )
        );

        if (
            in_array(
                $extension,
                $allowedExtensions,
                true
            )
        ) {

            $existingImages[] = $file;
        }
    }
}


// Sort
sort(
    $existingImages,
    SORT_NATURAL | SORT_FLAG_CASE
);


// ============================================================
// ADD FOOD
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $food_name = trim(
        $_POST['food_name'] ?? ''
    );

    $category = trim(
        $_POST['category'] ?? ''
    );

    $price = trim(
        $_POST['price'] ?? ''
    );

    $status = trim(
        $_POST['status'] ?? 'Available'
    );

    $selectedImage = trim(
        $_POST['existing_image'] ?? ''
    );


    // ========================================================
    // VALIDATION
    // ========================================================

    if ($food_name === '') {

        $message = 'Food name is required.';
        $messageType = 'error';

    } elseif (mb_strlen($food_name) > 150) {

        $message = 'Food name must not exceed 150 characters.';
        $messageType = 'error';

    } elseif (
        $price === '' ||
        !is_numeric($price)
    ) {

        $message = 'Please enter a valid price.';
        $messageType = 'error';

    } elseif ((float)$price < 0) {

        $message = 'Price cannot be negative.';
        $messageType = 'error';

    } elseif (
        !in_array(
            $status,
            ['Available', 'Unavailable'],
            true
        )
    ) {

        $message = 'Invalid food status.';
        $messageType = 'error';
    }


    // ========================================================
    // IMAGE VARIABLES
    // ========================================================

    $imageName = null;
    $newImageUploaded = false;


    // ========================================================
    // EXISTING IMAGE
    // ========================================================

    if ($messageType !== 'error' && $selectedImage !== '') {

        $safeSelectedImage =
            basename($selectedImage);

        $selectedPath =
            $imageDir . $safeSelectedImage;


        if (
            !in_array(
                $safeSelectedImage,
                $existingImages,
                true
            )
        ) {

            $message =
                'Selected food image does not exist.';

            $messageType = 'error';

        } elseif (!is_file($selectedPath)) {

            $message =
                'Selected food image could not be found.';

            $messageType = 'error';

        } else {

            $imageName =
                $safeSelectedImage;
        }
    }


    // ========================================================
    // NEW IMAGE UPLOAD
    // ========================================================

    if (
        $messageType !== 'error' &&
        isset($_FILES['image']) &&
        $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE
    ) {

        $uploadError =
            $_FILES['image']['error'];


        // ----------------------------------------------------
        // Upload error
        // ----------------------------------------------------

        if ($uploadError !== UPLOAD_ERR_OK) {

            $message =
                'There was an error uploading the image.';

            $messageType = 'error';
        }


        // ----------------------------------------------------
        // File size
        // ----------------------------------------------------

        elseif (
            $_FILES['image']['size'] >
            5 * 1024 * 1024
        ) {

            $message =
                'Image size must not exceed 5 MB.';

            $messageType = 'error';
        }


        else {

            $tmpName =
                $_FILES['image']['tmp_name'];


            // ------------------------------------------------
            // Check actual image
            // ------------------------------------------------

            $imageInfo =
                @getimagesize($tmpName);


            if ($imageInfo === false) {

                $message =
                    'The uploaded file is not a valid image.';

                $messageType = 'error';

            } else {

                $mimeType =
                    $imageInfo['mime'] ?? '';


                $allowedMimeTypes = [
                    'image/jpeg',
                    'image/png',
                    'image/webp'
                ];


                if (
                    !in_array(
                        $mimeType,
                        $allowedMimeTypes,
                        true
                    )
                ) {

                    $message =
                        'Only JPG, JPEG, PNG and WEBP images are allowed.';

                    $messageType = 'error';

                } else {

                    // ----------------------------------------
                    // Determine extension
                    // ----------------------------------------

                    if ($mimeType === 'image/jpeg') {

                        $extension = 'jpg';

                    } elseif ($mimeType === 'image/png') {

                        $extension = 'png';

                    } elseif ($mimeType === 'image/webp') {

                        $extension = 'webp';

                    } else {

                        $extension = '';
                    }


                    if ($extension === '') {

                        $message =
                            'Invalid image format.';

                        $messageType = 'error';

                    } else {

                        // ------------------------------------
                        // Safe food name
                        // ------------------------------------

                        $safeName =
                            preg_replace(
                                '/[^a-zA-Z0-9_-]+/',
                                '_',
                                $food_name
                            );


                        $safeName =
                            trim(
                                $safeName,
                                '_'
                            );


                        if ($safeName === '') {
                            $safeName = 'food';
                        }


                        // ------------------------------------
                        // Unique filename
                        // ------------------------------------

                        try {

                            $randomPart =
                                bin2hex(
                                    random_bytes(6)
                                );

                        } catch (Throwable $e) {

                            $randomPart =
                                uniqid();
                        }


                        $imageName =
                            strtolower($safeName)
                            . '_'
                            . date('Ymd_His')
                            . '_'
                            . $randomPart
                            . '.'
                            . $extension;


                        $destination =
                            $imageDir . $imageName;


                        // ------------------------------------
                        // Move uploaded image
                        // ------------------------------------

                        if (
                            !move_uploaded_file(
                                $tmpName,
                                $destination
                            )
                        ) {

                            $message =
                                'Could not save the uploaded image.';

                            $messageType = 'error';

                            $imageName = null;

                        } else {

                            $newImageUploaded = true;
                        }
                    }
                }
            }
        }
    }


    // ========================================================
    // DATABASE INSERT
    // ========================================================

    if ($messageType !== 'error') {

        $priceValue =
            (float)$price;


        $sql = "
            INSERT INTO public.restaurant_foods
            (
                food_name,
                category,
                image,
                price,
                status
            )
            VALUES
            (
                $1,
                $2,
                $3,
                $4,
                $5
            )
        ";


        $params = [

            $food_name,

            $category !== ''
                ? $category
                : null,

            $imageName,

            $priceValue,

            $status
        ];


        $result =
            pg_query_params(
                $pgConnection,
                $sql,
                $params
            );


        // ====================================================
        // SUCCESS
        // ====================================================

        if ($result) {

            header(
                'Location: view.php?success=added'
            );

            exit;
        }


        // ====================================================
        // DATABASE ERROR
        // ====================================================

        $databaseError =
            pg_last_error(
                $pgConnection
            );


        // Delete only newly uploaded image
        if (
            $newImageUploaded &&
            $imageName !== null
        ) {

            @unlink(
                $imageDir . $imageName
            );
        }


        $message =
            'Database Error: ' .
            $databaseError;

        $messageType = 'error';
    }
}


// ============================================================
// DASHBOARD URL
// ============================================================

if ($role === 'admin') {

    $dashboardUrl =
        '../../dashboard.php';

} else {

    $dashboardUrl =
        '../../staff_dashboard/staff_dashboard.php';
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

<title>Add Food | Suva Hotel</title>


<style>

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
        "Segoe UI",
        Arial,
        sans-serif;

    color: #f5f7fa;

    background:
        linear-gradient(
            rgba(5,9,15,.90),
            rgba(5,9,15,.96)
        ),
        url("../../assets/image/hotel_lighting.jpg")
        center / cover fixed;
}


/* =========================================================
   SIDEBAR
========================================================= */

.sidebar {

    position: fixed;

    left: 0;
    top: 0;
    bottom: 0;

    width: 255px;

    padding: 25px 15px;

    overflow-y: auto;

    background:
        linear-gradient(
            180deg,
            rgba(9,14,23,.98),
            rgba(5,9,15,.98)
        );

    border-right:
        1px solid
        rgba(255,255,255,.08);

    box-shadow:
        10px 0 35px
        rgba(0,0,0,.35);

    z-index: 1000;
}


.logo {

    padding:
        8px 10px 25px;

    margin-bottom:
        20px;

    border-bottom:
        1px solid
        rgba(255,255,255,.08);
}


.logo h2 {

    margin: 0;

    font-size: 23px;

    letter-spacing: 1px;
}


.logo span {

    display: block;

    margin-top: 5px;

    color: #7f8b9a;

    font-size: 10px;

    letter-spacing: 2px;

    text-transform: uppercase;
}


.nav-title {

    padding:
        0 10px 10px;

    color: #667384;

    font-size: 10px;

    letter-spacing: 1.5px;

    text-transform: uppercase;
}


.nav-link {

    display: flex;

    align-items: center;

    gap: 12px;

    padding:
        12px 13px;

    margin-bottom: 5px;

    border-radius: 10px;

    color: #aeb8c5;

    text-decoration: none;

    font-size: 13px;

    transition: .2s ease;
}


.nav-link:hover {

    color: white;

    background:
        rgba(255,255,255,.07);

    transform:
        translateX(3px);
}


.nav-link.active {

    color: white;

    background:
        rgba(255,255,255,.10);

    box-shadow:
        inset 3px 0 0 #ffffff;
}


.nav-icon {

    width: 23px;

    text-align: center;

    font-size: 16px;
}


.sidebar-bottom {

    margin-top: 25px;

    padding-top: 18px;

    border-top:
        1px solid
        rgba(255,255,255,.08);
}


.user-box {

    padding: 13px;

    border-radius: 11px;

    background:
        rgba(255,255,255,.04);
}


.user-box small {

    display: block;

    color: #6e7a89;

    font-size: 9px;

    text-transform: uppercase;

    letter-spacing: 1px;
}


.user-box strong {

    display: block;

    margin-top: 5px;

    font-size: 13px;
}


/* =========================================================
   MAIN
========================================================= */

.main {

    min-height: 100vh;

    margin-left: 255px;

    padding:
        30px 35px 50px;
}


/* =========================================================
   TOPBAR
========================================================= */

.topbar {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 20px;

    margin-bottom: 25px;
}


.title-area h1 {

    margin: 0;

    font-size: 30px;
}


.title-area p {

    margin:
        6px 0 0;

    color: #84909f;

    font-size: 13px;
}


.clock {

    padding:
        10px 15px;

    text-align: right;

    border:
        1px solid
        rgba(255,255,255,.08);

    border-radius: 11px;

    background:
        rgba(8,14,22,.70);

    backdrop-filter:
        blur(14px);
}


.clock-time {

    font-size: 13px;

    font-weight: 600;
}


.clock-date {

    margin-top: 3px;

    color: #707c8a;

    font-size: 9px;
}


/* =========================================================
   CONTENT
========================================================= */

.form-wrapper {

    width:
        min(1050px, 100%);

    margin:
        0 auto;
}


/* =========================================================
   HERO
========================================================= */

.hero {

    padding:
        28px 32px;

    margin-bottom: 18px;

    border-radius: 17px;

    background:
        linear-gradient(
            100deg,
            rgba(8,14,22,.96),
            rgba(8,14,22,.72)
        );

    border:
        1px solid
        rgba(255,255,255,.08);

    box-shadow:
        0 18px 40px
        rgba(0,0,0,.25);
}


.hero-tag {

    display: inline-block;

    padding:
        5px 10px;

    border-radius: 20px;

    background:
        rgba(255,255,255,.07);

    color: #9da8b6;

    font-size: 9px;

    letter-spacing: 1.5px;

    text-transform: uppercase;
}


.hero h2 {

    margin:
        12px 0 6px;

    font-size: 24px;
}


.hero p {

    margin: 0;

    color: #7f8b99;

    font-size: 12px;
}


/* =========================================================
   MESSAGE
========================================================= */

.message {

    margin-bottom: 18px;

    padding:
        13px 16px;

    border-radius: 10px;

    color: #ffb4b4;

    background:
        rgba(160,30,30,.15);

    border:
        1px solid
        rgba(255,80,80,.20);

    font-size: 12px;
}


/* =========================================================
   CARD
========================================================= */

.card {

    padding: 28px;

    border-radius: 17px;

    background:
        linear-gradient(
            145deg,
            rgba(18,26,38,.90),
            rgba(7,12,19,.87)
        );

    border:
        1px solid
        rgba(255,255,255,.07);

    box-shadow:
        0 20px 45px
        rgba(0,0,0,.24);
}


/* =========================================================
   FORM GRID
========================================================= */

.form-grid {

    display: grid;

    grid-template-columns:
        1fr 1fr;

    gap: 20px;
}


.form-group {
    margin-bottom: 3px;
}


label {

    display: block;

    margin-bottom: 8px;

    color: #dce2e8;

    font-size: 12px;

    font-weight: 600;
}


.required {
    color: #ff8d8d;
}


/* =========================================================
   INPUT
========================================================= */

input,
select {

    width: 100%;

    padding:
        12px 13px;

    border:
        1px solid
        rgba(255,255,255,.10);

    border-radius: 9px;

    outline: none;

    background:
        rgba(255,255,255,.045);

    color: #f3f5f7;

    font-family: inherit;

    font-size: 12px;

    transition: .2s ease;
}


input::placeholder {
    color: #667281;
}


input:focus,
select:focus {

    border-color:
        rgba(255,255,255,.28);

    background:
        rgba(255,255,255,.07);

    box-shadow:
        0 0 0 3px
        rgba(255,255,255,.035);
}


select option {

    background: #101720;

    color: white;
}


/* =========================================================
   IMAGE SECTION
========================================================= */

.image-section {

    margin-top: 8px;

    padding: 20px;

    border-radius: 13px;

    background:
        rgba(255,255,255,.025);

    border:
        1px solid
        rgba(255,255,255,.06);
}


.image-section-title {

    margin-bottom: 15px;

    color: #dce2e8;

    font-size: 13px;

    font-weight: 600;
}


/* =========================================================
   EXISTING IMAGE GRID
========================================================= */

.image-grid {

    display: grid;

    grid-template-columns:
        repeat(5, 1fr);

    gap: 11px;

    margin-bottom: 20px;
}


.image-option {

    position: relative;

    cursor: pointer;
}


.image-option input {

    position: absolute;

    opacity: 0;

    pointer-events: none;
}


.image-option label {

    display: block;

    margin: 0;

    padding: 4px;

    border:
        2px solid
        transparent;

    border-radius: 10px;

    background:
        rgba(255,255,255,.04);

    cursor: pointer;

    transition: .2s ease;
}


.image-option label:hover {

    background:
        rgba(255,255,255,.08);
}


.image-option input:checked + label {

    border-color: #ffffff;

    background:
        rgba(255,255,255,.10);
}


.food-thumb {

    display: block;

    width: 100%;

    height: 95px;

    object-fit: cover;

    border-radius: 7px;
}


.image-name {

    padding:
        6px 3px 4px;

    overflow: hidden;

    color: #8b97a5;

    font-size: 9px;

    text-align: center;

    text-overflow: ellipsis;

    white-space: nowrap;
}


/* =========================================================
   UPLOAD
========================================================= */

.upload-divider {

    display: flex;

    align-items: center;

    gap: 12px;

    margin:
        18px 0;

    color: #5f6b79;

    font-size: 10px;

    letter-spacing: 1px;

    text-transform: uppercase;
}


.upload-divider::before,
.upload-divider::after {

    content: "";

    flex: 1;

    height: 1px;

    background:
        rgba(255,255,255,.07);
}


.upload-box {

    padding: 18px;

    text-align: center;

    border:
        2px dashed
        rgba(255,255,255,.10);

    border-radius: 11px;

    background:
        rgba(255,255,255,.025);
}


.upload-box input {

    padding: 5px;

    border: none;

    background: transparent;

    cursor: pointer;
}


.help {

    margin-top: 7px;

    color: #657181;

    font-size: 10px;
}


/* =========================================================
   PREVIEW
========================================================= */

.preview {

    display: none;

    margin-top: 15px;

    text-align: center;
}


.preview img {

    width: 210px;

    height: 135px;

    object-fit: cover;

    border-radius: 10px;

    border:
        1px solid
        rgba(255,255,255,.10);
}


/* =========================================================
   BUTTONS
========================================================= */

.buttons {

    display: flex;

    gap: 10px;

    margin-top: 25px;

    padding-top: 20px;

    border-top:
        1px solid
        rgba(255,255,255,.07);
}


.btn {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    gap: 7px;

    padding:
        11px 18px;

    border-radius: 9px;

    border: none;

    cursor: pointer;

    text-decoration: none;

    font-size: 12px;

    font-weight: 600;

    transition: .2s ease;
}


.btn-primary {

    color: #0b111a;

    background: #ffffff;
}


.btn-primary:hover {

    transform:
        translateY(-2px);

    box-shadow:
        0 8px 20px
        rgba(0,0,0,.25);
}


.btn-secondary {

    color: #dce2e8;

    background:
        rgba(255,255,255,.06);

    border:
        1px solid
        rgba(255,255,255,.08);
}


.btn-secondary:hover {

    background:
        rgba(255,255,255,.11);
}


/* =========================================================
   FOOTER
========================================================= */

.footer {

    margin-top: 30px;

    color: #53606f;

    font-size: 10px;

    text-align: center;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1100px) {

    .image-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}


@media (max-width: 900px) {

    .sidebar {
        width: 220px;
    }

    .main {
        margin-left: 220px;
        padding: 22px;
    }

    .image-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}


@media (max-width: 700px) {

    .sidebar {

        position: relative;

        width: 100%;

        height: auto;
    }

    .main {

        margin-left: 0;

        padding: 15px;
    }

    .topbar {

        flex-direction: column;

        align-items: flex-start;
    }

    .form-grid {

        grid-template-columns: 1fr;
    }

    .image-grid {

        grid-template-columns:
            repeat(2, 1fr);
    }

    .buttons {

        flex-direction: column;
    }

    .btn {

        width: 100%;
    }
}

</style>

</head>


<body>


<!-- =========================================================
     SIDEBAR
========================================================= -->

<aside class="sidebar">

    <div class="logo">

        <h2>SUVA HOTEL</h2>

        <span>
            Management System
        </span>

    </div>


    <div class="nav-title">
        Main Menu
    </div>


    <a
        href="../../dashboard.php"
        class="nav-link"
    >
        <span class="nav-icon">⌂</span>
        Dashboard
    </a>


    <a
        href="../../bookings/view.php"
        class="nav-link"
    >
        <span class="nav-icon">▣</span>
        Bookings
    </a>


    <a
        href="../../rooms/view.php"
        class="nav-link"
    >
        <span class="nav-icon">▤</span>
        Rooms
    </a>


    <a
        href="../../guests/view.php"
        class="nav-link"
    >
        <span class="nav-icon">♙</span>
        Guests
    </a>


    <a
        href="view.php"
        class="nav-link active"
    >
        <span class="nav-icon">♨</span>
        Restaurant
    </a>


    <a
        href="../../swimming/"
        class="nav-link"
    >
        <span class="nav-icon">≈</span>
        Swimming
    </a>


    <a
        href="../../inventory/dashboard.php"
        class="nav-link"
    >
        <span class="nav-icon">▦</span>
        Inventory
    </a>


    <a
        href="../../payment/view.php"
        class="nav-link"
    >
        <span class="nav-icon">₹</span>
        Payments
    </a>


    <a
        href="../../billing/view.php"
        class="nav-link"
    >
        <span class="nav-icon">▤</span>
        Billing
    </a>


    <a
        href="../../reports/"
        class="nav-link"
    >
        <span class="nav-icon">◫</span>
        Reports
    </a>


    <div class="sidebar-bottom">

        <div class="user-box">

            <small>
                Logged in as
            </small>

            <strong>
                <?= e($_SESSION['username'] ?? 'User') ?>
            </strong>

        </div>


        <a
            href="../../logout.php"
            class="nav-link"
            style="margin-top:10px;"
        >

            <span class="nav-icon">
                ↪
            </span>

            Logout

        </a>

    </div>

</aside>


<!-- =========================================================
     MAIN
========================================================= -->

<main class="main">


    <div class="topbar">

        <div class="title-area">

            <h1>
                Restaurant
            </h1>

            <p>
                Add a new food item to the hotel menu.
            </p>

        </div>


        <div class="clock">

            <div
                class="clock-time"
                id="clock"
            >
                00:00:00
            </div>

            <div
                class="clock-date"
                id="date"
            >
                Loading...
            </div>

        </div>

    </div>


    <div class="form-wrapper">


        <!-- =====================================================
             HERO
        ====================================================== -->

        <section class="hero">

            <span class="hero-tag">
                Food Management
            </span>

            <h2>
                🍽️ Add Food Item
            </h2>

            <p>
                Add unlimited food items to your restaurant menu.
                You can reuse an existing image or upload a new
                food image.
            </p>

        </section>


        <!-- =====================================================
             ERROR
        ====================================================== -->

        <?php if ($message !== ''): ?>

            <div class="message">

                ❌

                <?= e($message) ?>

            </div>

        <?php endif; ?>


        <!-- =====================================================
             FORM CARD
        ====================================================== -->

        <div class="card">


            <form
                method="POST"
                enctype="multipart/form-data"
            >


                <div class="form-grid">


                    <!-- FOOD NAME -->

                    <div class="form-group">

                        <label>

                            Food Name

                            <span class="required">
                                *
                            </span>

                        </label>

                        <input
                            type="text"
                            name="food_name"
                            value="<?= e($food_name) ?>"
                            maxlength="150"
                            required
                            autocomplete="off"
                            placeholder="Example: Chicken Momo"
                        >

                    </div>


                    <!-- CATEGORY -->

                    <div class="form-group">

                        <label>
                            Category
                        </label>

                        <input
                            type="text"
                            name="category"
                            value="<?= e($category) ?>"
                            maxlength="100"
                            autocomplete="off"
                            placeholder="Example: Nepali, Chinese, Pizza"
                        >

                    </div>


                    <!-- PRICE -->

                    <div class="form-group">

                        <label>

                            Price

                            <span class="required">
                                *
                            </span>

                        </label>

                        <input
                            type="number"
                            name="price"
                            value="<?= e($price) ?>"
                            min="0"
                            step="0.01"
                            required
                            placeholder="Example: 250"
                        >

                    </div>


                    <!-- STATUS -->

                    <div class="form-group">

                        <label>
                            Status
                        </label>

                        <select name="status">

                            <option
                                value="Available"
                                <?= $status === 'Available'
                                    ? 'selected'
                                    : '' ?>
                            >
                                Available
                            </option>

                            <option
                                value="Unavailable"
                                <?= $status === 'Unavailable'
                                    ? 'selected'
                                    : '' ?>
                            >
                                Unavailable
                            </option>

                        </select>

                    </div>


                    <!-- =================================================
                         IMAGE
                    ================================================== -->

                    <div
                        class="form-group"
                        style="grid-column:1 / -1;"
                    >

                        <div class="image-section">


                            <div class="image-section-title">

                                📷 Choose Food Image

                            </div>


                            <?php if (!empty($existingImages)): ?>

                                <div class="image-grid">


                                    <?php foreach (
                                        $existingImages
                                        as $index => $img
                                    ): ?>


                                        <div
                                            class="image-option"
                                        >


                                            <input
                                                type="radio"
                                                name="existing_image"
                                                id="image_<?= $index ?>"
                                                value="<?= e($img) ?>"
                                                <?= $selectedImage === $img
                                                    ? 'checked'
                                                    : '' ?>
                                            >


                                            <label
                                                for="image_<?= $index ?>"
                                            >

                                                <img
                                                    src="<?= e(
                                                        $imageWebPath .
                                                        rawurlencode($img)
                                                    ) ?>"
                                                    class="food-thumb"
                                                    alt="<?= e($img) ?>"
                                                    loading="lazy"
                                                    onerror="
                                                        this.style.display='none';
                                                    "
                                                >


                                                <div class="image-name">

                                                    <?= e($img) ?>

                                                </div>

                                            </label>


                                        </div>


                                    <?php endforeach; ?>


                                </div>

                            <?php else: ?>

                                <div
                                    style="
                                        color:#687586;
                                        font-size:11px;
                                        padding:15px;
                                        text-align:center;
                                    "
                                >

                                    No existing food images found.

                                    <br><br>

                                    Upload your first food image below.

                                </div>

                            <?php endif; ?>


                            <!-- DIVIDER -->

                            <div class="upload-divider">

                                Or upload a new image

                            </div>


                            <!-- UPLOAD -->

                            <div class="upload-box">


                                <input
                                    type="file"
                                    name="image"
                                    id="image"
                                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                >


                                <div class="help">

                                    JPG, JPEG, PNG or WEBP
                                    · Maximum 5 MB

                                </div>


                                <div
                                    class="preview"
                                    id="preview"
                                >

                                    <img
                                        id="previewImage"
                                        src=""
                                        alt="Food Preview"
                                    >

                                </div>


                            </div>


                        </div>

                    </div>


                </div>


                <!-- BUTTONS -->

                <div class="buttons">


                    <button
                        type="submit"
                        class="btn btn-primary"
                    >

                        ➕ Add Food

                    </button>


                    <a
                        href="view.php"
                        class="btn btn-secondary"
                    >

                        ← Back to Menu

                    </a>


                </div>


            </form>


        </div>


        <div class="footer">

            © <?= date('Y') ?>
            Suva Hotel · Restaurant Management

        </div>


    </div>


</main>


<script>

// ============================================================
// CLOCK
// ============================================================

function updateClock()
{
    const now = new Date();

    document.getElementById('clock').textContent =
        now.toLocaleTimeString(
            'en-US',
            {
                hour12: true,
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            }
        );

    document.getElementById('date').textContent =
        now.toLocaleDateString(
            'en-US',
            {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            }
        );
}


updateClock();

setInterval(
    updateClock,
    1000
);


// ============================================================
// IMAGE PREVIEW
// ============================================================

const imageInput =
    document.getElementById('image');

const preview =
    document.getElementById('preview');

const previewImage =
    document.getElementById('previewImage');


if (imageInput) {

    imageInput.addEventListener(
        'change',
        function () {

            const file =
                this.files[0];


            if (!file) {

                preview.style.display =
                    'none';

                previewImage.src = '';

                return;
            }


            const allowedTypes = [
                'image/jpeg',
                'image/png',
                'image/webp'
            ];


            if (
                !allowedTypes.includes(
                    file.type
                )
            ) {

                alert(
                    'Only JPG, JPEG, PNG and WEBP images are allowed.'
                );

                this.value = '';

                preview.style.display =
                    'none';

                return;
            }


            if (
                file.size >
                5 * 1024 * 1024
            ) {

                alert(
                    'Image size must not exceed 5 MB.'
                );

                this.value = '';

                preview.style.display =
                    'none';

                return;
            }


            // Clear existing image selection

            document
                .querySelectorAll(
                    'input[name="existing_image"]'
                )
                .forEach(
                    function (radio) {

                        radio.checked = false;

                    }
                );


            const reader =
                new FileReader();


            reader.onload =
                function (event) {

                    previewImage.src =
                        event.target.result;

                    preview.style.display =
                        'block';
                };


            reader.readAsDataURL(file);
        }
    );
}


// ============================================================
// EXISTING IMAGE SELECTION
// ============================================================

document
    .querySelectorAll(
        'input[name="existing_image"]'
    )
    .forEach(
        function (radio) {

            radio.addEventListener(
                'change',
                function () {

                    if (this.checked) {

                        imageInput.value = '';

                        preview.style.display =
                            'none';

                        previewImage.src = '';
                    }
                }
            );

        }
    );

</script>


</body>
</html>