<?php
// ============================================================
// SUVA HOTEL - RESTAURANT FOOD VIEW
// PostgreSQL Version
// File: /Suva_hotel/restaurant/food/view.php
// ============================================================

session_start();

// ============================================================
// AUTHENTICATION
// ============================================================

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit;
}

$role = $_SESSION["role"] ?? "";

if ($role !== "admin" && $role !== "staff") {
    header("Location: ../../login.php");
    exit;
}

// ============================================================
// DATABASE
// ============================================================

require_once __DIR__ . "/../../config/database.php";

if (!isset($pgConnection) || !$pgConnection) {
    die("PostgreSQL database connection failed.");
}

// ============================================================
// HELPER: ESCAPE HTML
// ============================================================

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

// ============================================================
// IMAGE HANDLER
// ============================================================
// Returns:
// [
//     "exists" => true/false,
//     "url"    => browser URL,
//     "name"   => filename
// ]
//
// Main image folder:
// /Suva_hotel/restaurant/food/images/
// ============================================================

function getFoodImage($image)
{
    $image = trim((string)$image);

    // No image in database
    if ($image === "") {
        return [
            "exists" => false,
            "url"    => "",
            "name"   => ""
        ];
    }

    // --------------------------------------------------------
    // Remove query strings if accidentally stored
    // --------------------------------------------------------

    $cleanImage = preg_replace('/\?.*$/', '', $image);

    // --------------------------------------------------------
    // Get filename only
    // --------------------------------------------------------

    $filename = basename(str_replace("\\", "/", $cleanImage));

    if ($filename === "" || $filename === "." || $filename === "..") {
        return [
            "exists" => false,
            "url"    => "",
            "name"   => ""
        ];
    }

    // --------------------------------------------------------
    // SECURITY: only allow normal image extensions
    // --------------------------------------------------------

    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    $allowedExtensions = [
        "jpg",
        "jpeg",
        "png",
        "webp",
        "gif"
    ];

    if (!in_array($extension, $allowedExtensions, true)) {
        return [
            "exists" => false,
            "url"    => "",
            "name"   => $filename
        ];
    }

    // ========================================================
    // LOCATION 1
    // restaurant/food/images/
    // ========================================================

    $localFile = __DIR__ . "/images/" . $filename;

    if (is_file($localFile) && filesize($localFile) > 0) {

        return [
            "exists" => true,
            "url"    => "images/" . rawurlencode($filename),
            "name"   => $filename
        ];
    }

    // ========================================================
    // LOCATION 2
    // Old uploads/food/ folder
    //
    // /Suva_hotel/uploads/food/
    // ========================================================

    $projectRoot = dirname(__DIR__, 2);

    $uploadFile = $projectRoot . "/uploads/food/" . $filename;

    if (is_file($uploadFile) && filesize($uploadFile) > 0) {

        return [
            "exists" => true,
            "url"    => "../../uploads/food/" . rawurlencode($filename),
            "name"   => $filename
        ];
    }

    // ========================================================
    // IMAGE NOT FOUND
    // ========================================================

    return [
        "exists" => false,
        "url"    => "",
        "name"   => $filename
    ];
}

// ============================================================
// GET FOOD DATA
// ============================================================

$sql = "
    SELECT
        id,
        food_name,
        category,
        image,
        price,
        status
    FROM restaurant_foods
    ORDER BY id DESC
";

$result = pg_query($pgConnection, $sql);

if ($result === false) {
    die(
        "Database Error: " .
        e(pg_last_error($pgConnection))
    );
}

// ============================================================
// LOAD FOODS
// ============================================================

$foods = [];

while ($row = pg_fetch_assoc($result)) {
    $foods[] = $row;
}

// ============================================================
// STATISTICS
// ============================================================

$totalFoods = count($foods);

$activeFoods = 0;
$inactiveFoods = 0;
$availableImages = 0;
$missingImages = 0;

foreach ($foods as $food) {

    $status = strtolower(trim((string)($food["status"] ?? "")));

    if ($status === "active" || $status === "available" || $status === "") {
        $activeFoods++;
    } else {
        $inactiveFoods++;
    }

    $imageInfo = getFoodImage($food["image"] ?? "");

    if ($imageInfo["exists"]) {
        $availableImages++;
    } else {
        $missingImages++;
    }
}

// ============================================================
// USERNAME
// ============================================================

$username = $_SESSION["username"] ?? "User";

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Restaurant Foods | Suva Hotel</title>

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            min-height: 100vh;
            color: #fff;

            background:
                linear-gradient(
                    rgba(5, 8, 15, 0.90),
                    rgba(5, 8, 15, 0.94)
                ),
                url("../../assets/images/hotel-lighting.jpg")
                center / cover fixed no-repeat;
        }

        /* ====================================================
           TOP BAR
        ==================================================== */

        .topbar {
            height: 76px;

            display: flex;
            align-items: center;
            justify-content: space-between;

            padding: 0 35px;

            background: rgba(10, 14, 24, 0.88);

            border-bottom: 1px solid rgba(255,255,255,0.10);

            backdrop-filter: blur(18px);

            position: sticky;
            top: 0;
            z-index: 100;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-icon {
            width: 44px;
            height: 44px;

            display: flex;
            align-items: center;
            justify-content: center;

            border-radius: 12px;

            background: rgba(255,255,255,0.08);

            font-size: 22px;
        }

        .brand h2 {
            font-size: 20px;
            letter-spacing: 0.5px;
        }

        .brand p {
            color: #aab4c4;
            font-size: 12px;
            margin-top: 3px;
        }

        .user-box {
            color: #d9e0eb;
            font-size: 14px;
        }

        .user-box strong {
            color: #fff;
        }

        /* ====================================================
           MAIN
        ==================================================== */

        .container {
            width: min(1400px, 94%);
            margin: 35px auto 60px;
        }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;

            gap: 20px;

            margin-bottom: 28px;
        }

        .page-title h1 {
            font-size: 32px;
            margin-bottom: 7px;
        }

        .page-title p {
            color: #aeb8c8;
            font-size: 14px;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;

            padding: 12px 18px;

            border-radius: 11px;

            text-decoration: none;

            color: white;

            background: rgba(255,255,255,0.08);

            border: 1px solid rgba(255,255,255,0.12);

            transition: 0.25s;
        }

        .btn:hover {
            background: rgba(255,255,255,0.15);
            transform: translateY(-2px);
        }

        .btn-primary {
            background: rgba(255,255,255,0.16);
        }

        /* ====================================================
           STAT CARDS
        ==================================================== */

        .stats {
            display: grid;

            grid-template-columns:
                repeat(4, minmax(0, 1fr));

            gap: 18px;

            margin-bottom: 30px;
        }

        .stat-card {
            padding: 22px;

            border-radius: 18px;

            background: rgba(17, 23, 35, 0.72);

            border: 1px solid rgba(255,255,255,0.09);

            backdrop-filter: blur(15px);

            box-shadow: 0 15px 35px rgba(0,0,0,0.25);
        }

        .stat-label {
            color: #aeb8c8;
            font-size: 13px;
            margin-bottom: 8px;
        }

        .stat-number {
            font-size: 29px;
            font-weight: bold;
        }

        .stat-note {
            margin-top: 7px;
            font-size: 12px;
            color: #8995a8;
        }

        /* ====================================================
           FOOD GRID
        ==================================================== */

        .food-grid {
            display: grid;

            grid-template-columns:
                repeat(4, minmax(0, 1fr));

            gap: 22px;
        }

        .food-card {
            overflow: hidden;

            border-radius: 19px;

            background: rgba(15, 21, 33, 0.82);

            border: 1px solid rgba(255,255,255,0.09);

            backdrop-filter: blur(14px);

            box-shadow: 0 15px 35px rgba(0,0,0,0.28);

            transition:
                transform 0.25s,
                box-shadow 0.25s;
        }

        .food-card:hover {
            transform: translateY(-6px);

            box-shadow:
                0 20px 45px rgba(0,0,0,0.40);
        }

        /* ====================================================
           IMAGE AREA
        ==================================================== */

        .food-image {
            width: 100%;
            height: 210px;

            position: relative;

            background:
                linear-gradient(
                    135deg,
                    rgba(255,255,255,0.06),
                    rgba(255,255,255,0.02)
                );

            overflow: hidden;
        }

        .food-image img {
            width: 100%;
            height: 100%;

            display: block;

            object-fit: cover;

            transition: transform 0.35s;
        }

        .food-card:hover .food-image img {
            transform: scale(1.05);
        }

        /* ====================================================
           FALLBACK IMAGE
        ==================================================== */

        .image-fallback {
            width: 100%;
            height: 100%;

            display: flex;
            flex-direction: column;

            align-items: center;
            justify-content: center;

            text-align: center;

            color: #9ba7b9;

            background:
                linear-gradient(
                    135deg,
                    rgba(255,255,255,0.06),
                    rgba(0,0,0,0.22)
                );
        }

        .fallback-icon {
            width: 58px;
            height: 58px;

            border-radius: 16px;

            display: flex;
            align-items: center;
            justify-content: center;

            margin-bottom: 10px;

            background: rgba(255,255,255,0.08);

            font-size: 27px;
        }

        .fallback-title {
            color: #d7deea;
            font-size: 14px;
            font-weight: bold;
        }

        .fallback-text {
            color: #7f8a9c;
            font-size: 11px;
            margin-top: 5px;

            max-width: 90%;

            overflow-wrap: anywhere;
        }

        /* ====================================================
           CARD BODY
        ==================================================== */

        .food-body {
            padding: 18px;
        }

        .food-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;

            margin-bottom: 8px;
        }

        .food-name {
            font-size: 18px;
            font-weight: bold;

            line-height: 1.25;
        }

        .price {
            white-space: nowrap;

            font-size: 17px;
            font-weight: bold;
        }

        .category {
            color: #a9b3c3;

            font-size: 13px;

            margin-bottom: 13px;
        }

        .food-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;

            padding-top: 13px;

            border-top: 1px solid rgba(255,255,255,0.08);
        }

        .status {
            display: inline-flex;
            align-items: center;

            gap: 7px;

            padding: 6px 10px;

            border-radius: 20px;

            background: rgba(255,255,255,0.06);

            color: #d8dfeb;

            font-size: 11px;
        }

        .status-dot {
            width: 7px;
            height: 7px;

            border-radius: 50%;

            background: currentColor;
        }

        .image-name {
            max-width: 48%;

            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;

            color: #697587;

            font-size: 10px;
        }

        /* ====================================================
           EMPTY
        ==================================================== */

        .empty {
            grid-column: 1 / -1;

            text-align: center;

            padding: 70px 20px;

            border-radius: 18px;

            background: rgba(15,21,33,0.75);

            border: 1px solid rgba(255,255,255,0.08);

            color: #aeb8c8;
        }

        .empty-icon {
            font-size: 50px;
            margin-bottom: 15px;
        }

        /* ====================================================
           RESPONSIVE
        ==================================================== */

        @media (max-width: 1100px) {

            .food-grid {
                grid-template-columns:
                    repeat(3, minmax(0, 1fr));
            }

            .stats {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 760px) {

            .topbar {
                padding: 0 18px;
            }

            .user-box {
                display: none;
            }

            .container {
                width: 92%;
                margin-top: 25px;
            }

            .page-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .food-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 520px) {

            .stats {
                grid-template-columns: 1fr;
            }

            .food-grid {
                grid-template-columns: 1fr;
            }

            .food-image {
                height: 230px;
            }

            .page-title h1 {
                font-size: 27px;
            }
        }

    </style>

</head>

<body>

<!-- =========================================================
     TOP BAR
========================================================= -->

<header class="topbar">

    <div class="brand">

        <div class="brand-icon">
            🍽️
        </div>

        <div>
            <h2>Suva Hotel</h2>
            <p>Restaurant Management</p>
        </div>

    </div>

    <div class="user-box">
        Logged in as <strong><?= e($username) ?></strong>
    </div>

</header>


<!-- =========================================================
     MAIN
========================================================= -->

<main class="container">

    <div class="page-header">

        <div class="page-title">

            <h1>Restaurant Foods</h1>

            <p>
                Manage your restaurant menu and food images.
            </p>

        </div>

        <div class="actions">

            <a href="../../dashboard.php" class="btn">
                ← Dashboard
            </a>

            <a href="add.php" class="btn btn-primary">
                + Add Food
            </a>

        </div>

    </div>


    <!-- =====================================================
         STATISTICS
    ====================================================== -->

    <div class="stats">

        <div class="stat-card">

            <div class="stat-label">
                Total Foods
            </div>

            <div class="stat-number">
                <?= number_format($totalFoods) ?>
            </div>

            <div class="stat-note">
                All menu items
            </div>

        </div>


        <div class="stat-card">

            <div class="stat-label">
                Active Foods
            </div>

            <div class="stat-number">
                <?= number_format($activeFoods) ?>
            </div>

            <div class="stat-note">
                Available menu items
            </div>

        </div>


        <div class="stat-card">

            <div class="stat-label">
                Working Images
            </div>

            <div class="stat-number">
                <?= number_format($availableImages) ?>
            </div>

            <div class="stat-note">
                Image files found
            </div>

        </div>


        <div class="stat-card">

            <div class="stat-label">
                Missing Images
            </div>

            <div class="stat-number">
                <?= number_format($missingImages) ?>
            </div>

            <div class="stat-note">
                Showing fallback
            </div>

        </div>

    </div>


    <!-- =====================================================
         FOOD CARDS
    ====================================================== -->

    <div class="food-grid">

        <?php if (empty($foods)): ?>

            <div class="empty">

                <div class="empty-icon">
                    🍽️
                </div>

                <h2>No Food Items Found</h2>

                <p style="margin-top:8px;">
                    Add your first restaurant food item.
                </p>

            </div>

        <?php else: ?>

            <?php foreach ($foods as $food): ?>

                <?php

                $foodId = $food["id"] ?? "";

                $foodName = trim(
                    (string)($food["food_name"] ?? "Unnamed Food")
                );

                if ($foodName === "") {
                    $foodName = "Unnamed Food";
                }

                $category = trim(
                    (string)($food["category"] ?? "Uncategorized")
                );

                if ($category === "") {
                    $category = "Uncategorized";
                }

                $price = (float)($food["price"] ?? 0);

                $status = trim(
                    (string)($food["status"] ?? "")
                );

                if ($status === "") {
                    $status = "Active";
                }

                $imageInfo = getFoodImage(
                    $food["image"] ?? ""
                );

                ?>

                <article class="food-card">

                    <!-- IMAGE -->

                    <div class="food-image">

                        <?php if ($imageInfo["exists"]): ?>

                            <img
                                src="<?= e($imageInfo["url"]) ?>"
                                alt="<?= e($foodName) ?>"
                                loading="lazy"
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                            >

                            <!--
                                Secondary fallback.
                                This is hidden normally.
                                If the browser cannot load the image,
                                JavaScript/CSS will reveal it.
                            -->

                            <div
                                class="image-fallback"
                                style="display:none;"
                            >

                                <div class="fallback-icon">
                                    🍽️
                                </div>

                                <div class="fallback-title">
                                    Image unavailable
                                </div>

                                <div class="fallback-text">
                                    <?= e($imageInfo["name"]) ?>
                                </div>

                            </div>

                        <?php else: ?>

                            <!--
                                File does not exist on the server.
                            -->

                            <div class="image-fallback">

                                <div class="fallback-icon">
                                    🖼️
                                </div>

                                <div class="fallback-title">
                                    Food Image
                                </div>

                                <?php if ($imageInfo["name"] !== ""): ?>

                                    <div class="fallback-text">
                                        File not found:
                                        <?= e($imageInfo["name"]) ?>
                                    </div>

                                <?php else: ?>

                                    <div class="fallback-text">
                                        No image assigned
                                    </div>

                                <?php endif; ?>

                            </div>

                        <?php endif; ?>

                    </div>


                    <!-- BODY -->

                    <div class="food-body">

                        <div class="food-top">

                            <div class="food-name">
                                <?= e($foodName) ?>
                            </div>

                            <div class="price">
                                Rs. <?= number_format($price, 2) ?>
                            </div>

                        </div>


                        <div class="category">
                            <?= e($category) ?>
                        </div>


                        <div class="food-footer">

                            <div class="status">

                                <span class="status-dot"></span>

                                <?= e(ucfirst($status)) ?>

                            </div>


                            <?php if ($imageInfo["name"] !== ""): ?>

                                <div
                                    class="image-name"
                                    title="<?= e($imageInfo["name"]) ?>"
                                >
                                    <?= e($imageInfo["name"]) ?>
                                </div>

                            <?php endif; ?>

                        </div>

                    </div>

                </article>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>

</main>


<!-- =========================================================
     CLOCK / IMAGE ERROR PROTECTION
========================================================= -->

<script>

    /*
     * Extra protection against broken images.
     *
     * If an image fails to load after the page has loaded,
     * hide the broken image and show the fallback.
     */

    document.querySelectorAll(".food-image img").forEach(function(img) {

        img.addEventListener("error", function() {

            this.style.display = "none";

            const fallback = this.nextElementSibling;

            if (fallback) {
                fallback.style.display = "flex";
            }

        });

    });

</script>

</body>
</html>