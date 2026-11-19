
<?php
// ============================================================
// SUVA HOTEL - RESTAURANT MENU
// PHP + PostgreSQL
// File: /restaurant/menu.php
// ============================================================

session_start();

require_once __DIR__ . "/../config/database.php";

if (!isset($pgConnection)) {
    exit("Database connection failed. Check config/database.php.");
}

// ------------------------------------------------------------
// 1. HELPER FUNCTIONS
// ------------------------------------------------------------

if (!function_exists("e")) {
    function e($value)
    {
        return htmlspecialchars(
            (string)($value ?? ""),
            ENT_QUOTES,
            "UTF-8"
        );
    }
}

/**
 * Find a suitable food image without changing database records.
 */
if (!function_exists("getFoodImage")) {
    function getFoodImage($image, $itemName = "")
    {
        $image = trim((string)($image ?? ""));
        $itemName = trim((string)($itemName ?? ""));

        if ($image === "") {
            return "";
        }

        if (preg_match('/^https:\/\/[^\s]+$/i', $image)) {
            return $image;
        }

        $image = str_replace('\\', '/', $image);
        $image = explode('?', $image)[0];
        $image = explode('#', $image)[0];

        $filename = basename($image);

        if (
            $filename === "" ||
            $filename === "." ||
            $filename === ".." ||
            !preg_match('/^[a-zA-Z0-9_. -]+$/', $filename)
        ) {
            return "";
        }

        $folder = __DIR__ . "/food/images/";

        // 1. Exact filename.
        if (is_file($folder . $filename)) {
            return "food/images/" . rawurlencode($filename);
        }

        // 2. Food-name image mappings.
        $nameMap = [
            "chicken momo" => [
                "Momo.jpg",
                "steam_momo.jpg",
                "steam__momo_1791645984_b269763d.jpg"
            ],
            "veg momo" => ["steam_momo.jpg", "Momo.jpg"],
            "jhol momo" => ["steam_momo.jpg", "Momo.jpg"],
            "chicken rice" => [
                "chicken-fried-rice-1.jpg",
                "plain-rice-6000x4000.jpg"
            ],
            "veg fried rice" => [
                "fried_rice_1791646704_a0156b88.jpg",
                "plain_rice_1791646821_eeb2506e.jpg"
            ],
            "chicken fried rice" => [
                "chicken-fried-rice-1.jpg",
                "chicken_fried_rice_1791646620_8530a17f.jpg"
            ],
            "egg fried rice" => [
                "fried_rice_1791646704_a0156b88.jpg",
                "plain_rice_1791646821_eeb2506e.jpg"
            ],
            "breakfast set" => [
                "south_indian_item.jpg",
                "butter_naan.jpg"
            ],
            "chicken chowmein" => [
                "chicken-chowmein-1.jpg",
                "chicken chowemein.jpg",
                "chicken_chowmein_1791643288_d4772e70.jpg"
            ],
            "veg chowmein" => [
                "veg-chowmein-1.jpg",
                "veg_chowmein_1791644279_62f367c4.jpg"
            ],
            "buff chowmein" => [
                "buff_chowmein_1791646259_10d071d3.jpg",
                "chowmein_1791645079_e68e4123.jpg"
            ],
            "chowmein" => [
                "chowmein_1791645079_e68e4123.jpg",
                "chowmein_1791646411_1d47706c.jpg"
            ],
            "chicken biryani" => [
                "Chicken Biryani.jpg",
                "chicken-biryani-1.jpg",
                "chicken_biryani_1788626154_0d9f466a.jpg"
            ],
            "special chicken biryani" => [
                "chicken-biryani-2.jpg",
                "Chicken Biryani.jpg"
            ],
            "veg biryani" => [
                "Chicken Biryani.jpg",
                "nepali-rice-items-1.jpg"
            ],
            "chicken curry" => [
                "chicken-curry-1.jpg",
                "chicken_curry_1791641521_378fed43.jpg"
            ],
            "mutton curry" => [
                "mutton-curry-1.jpg",
                "mutton_curry_1791641625_ccf63ef5.jpg"
            ],
            "buff curry" => [
                "mutton-curry-1.jpg",
                "chicken-curry-1.jpg"
            ],
            "veg curry" => [
                "veg-curry-5712x4284.jpg",
                "vegetable_curry_1791641820_aca723e6.jpg",
                "paneer-curry-5472x3648.jpg"
            ],
            "veg pizza" => [
                "veg_pizza.jpg",
                "veg_pizza_1791627928_eee8ba5f.jpg"
            ],
            "chicken pizza" => [
                "Chicken Pizza.jpg",
                "chicken_pizza_1791627809_c6782ff5.jpg"
            ],
            "margherita pizza" => ["pizza.jpg", "veg_pizza.jpg"],
            "special mixed pizza" => ["pizza.jpg", "Chicken Pizza.jpg"],
            "veg burger" => [
                "veg-burger-2.jpg",
                "veg_burger_1791645317_587d9172.jpg"
            ],
            "chicken burger" => [
                "chicken_burger_1791643194_e0be48fa.jpg",
                "Double cheese Burger.jpg"
            ],
            "cheese burger" => [
                "Double cheese Burger.jpg",
                "double_cheese_burger_1791628028_f551dd57.jpg"
            ],
            "special chicken burger" => [
                "chicken_burger_1791643194_e0be48fa.jpg",
                "Double cheese Burger.jpg"
            ],
            "french fries" => [
                "french_fries_1791647670_ed624d29.jpg",
                "french_fries_1791647780_422730d9.jpg"
            ],
            "cheese fries" => [
                "french_fries_1791647867_f30df13b.jpg",
                "french_fries_1791647670_ed624d29.jpg"
            ],
            "chicken chilli" => [
                "chicken-chilli-4176x2784.jpg",
                "chicken_chilli_1791643369_ee38f717.jpg"
            ],
            "veg pakora" => [
                "pakora-1.jpg",
                "pakora_1791645379_0f8937aa.jpg"
            ],
            "milk tea" => [
                "milk_tea_1791642907_47a8cc1a.jpg",
                "tea-1.jpg"
            ],
            "black tea" => [
                "black_tea_1791642802_88b509b2.jpg",
                "tea-2.jpg"
            ],
            "coffee" => [
                "coffee-1.jpg",
                "coffee_1791648607_6f1a4413.jpg",
                "americano_coffee_1791642258_4b427d55.jpg"
            ],
            "coke" => [
                "coke-1.jpg",
                "coca-cola__coke__1791642464_698caf70.jpg",
                "cocacola_1791641412_c1576e2f.jpg"
            ],
            "cold drinks" => [
                "cold-drinks-6000x4000.jpg",
                "drink_1791648666_d8fd0019.jpg"
            ],
            "fresh orange juice" => [
                "mango-juice-4880x3253.jpg",
                "lemon_juice.jpg"
            ],
            "fresh mango juice" => [
                "mango-juice-4880x3253.jpg",
                "mango_juice_1791644422_bb4ea47c.jpg"
            ],
            "lemon juice" => [
                "lemon_juice.jpg",
                "lemonade-1.jpg",
                "fresh_lemonade_1791644350_94090f35.jpg"
            ]
        ];

        $nameKey = strtolower($itemName);

        if (isset($nameMap[$nameKey])) {
            foreach ($nameMap[$nameKey] as $alternative) {
                if (is_file($folder . $alternative)) {
                    return "food/images/" . rawurlencode($alternative);
                }
            }
        }

        // 3. Generic image mappings.
        $imageMap = [
            "momo.jpg" => [
                "Momo.jpg",
                "steam_momo.jpg",
                "steam__momo_1791645984_b269763d.jpg"
            ],
            "dalbhat.jpg" => [
                "dal_bhat.jpg",
                "dal_bhat_.jpg",
                "dal_bhat_1.jpg",
                "nepali_dal_bhat.jpg",
                "chicken_dal_bhat_.jpg"
            ],
            "rice.jpg" => [
                "plain-rice-6000x4000.jpg",
                "nepali-rice-items-1.jpg",
                "fried_rice_1791646704_a0156b88.jpg"
            ],
            "breakfast.jpg" => ["south_indian_item.jpg", "butter_naan.jpg"],
            "drinks.jpg" => [
                "cold-drinks-6000x4000.jpg",
                "drink_1791648666_d8fd0019.jpg",
                "tea-1.jpg"
            ],
            "noodles.jpg" => [
                "chowmein_1791645079_e68e4123.jpg",
                "chicken chowemein.jpg",
                "veg-chowmein-1.jpg"
            ],
            "biryani.jpg" => [
                "Chicken Biryani.jpg",
                "chicken-biryani-1.jpg",
                "chicken_biryani_1788626154_0d9f466a.jpg"
            ],
            "curry.jpg" => [
                "chicken-curry-1.jpg",
                "paneer-curry-5472x3648.jpg",
                "veg-curry-5712x4284.jpg"
            ],
            "pizza.jpg" => ["pizza.jpg", "Chicken Pizza.jpg", "veg_pizza.jpg"],
            "burger.jpg" => [
                "Double cheese Burger.jpg",
                "veg_burger_1791645317_587d9172.jpg",
                "chicken_burger_1791643194_e0be48fa.jpg"
            ],
            "fries.jpg" => [
                "french_fries_1791647670_ed624d29.jpg",
                "french_fries_1791647780_422730d9.jpg"
            ],
            "chicken-chilli.jpg" => [
                "chicken-chilli-4176x2784.jpg",
                "chicken_chilli_1791643369_ee38f717.jpg"
            ],
            "snacks.jpg" => [
                "pakora-1.jpg",
                "samosa-1.jpg",
                "pakora_1791645379_0f8937aa.jpg"
            ],
            "juice.jpg" => [
                "mango-juice-4880x3253.jpg",
                "lemon_juice.jpg",
                "fresh_lemonade_1791644350_94090f35.jpg"
            ]
        ];

        $key = strtolower($filename);

        if (isset($imageMap[$key])) {
            foreach ($imageMap[$key] as $alternative) {
                if (is_file($folder . $alternative)) {
                    return "food/images/" . rawurlencode($alternative);
                }
            }
        }

        // 4. Try common filename variations.
        if ($itemName !== "") {
            $snake = preg_replace(
                '/[^a-z0-9]+/',
                '_',
                strtolower($itemName)
            );

            $dash = preg_replace(
                '/[^a-z0-9]+/',
                '-',
                strtolower($itemName)
            );

            foreach (["jpg", "jpeg", "png", "webp"] as $ext) {
                $candidates = [
                    $snake . "." . $ext,
                    $snake . "_1." . $ext,
                    $snake . "_." . $ext,
                    $dash . "." . $ext
                ];

                foreach ($candidates as $candidate) {
                    if (is_file($folder . $candidate)) {
                        return "food/images/" . rawurlencode($candidate);
                    }
                }
            }
        }

        // 5. Check the older image location.
        if (is_file(__DIR__ . "/" . $filename)) {
            return rawurlencode($filename);
        }

        return "";
    }
}

// ------------------------------------------------------------
// 2. SEARCH AND CATEGORY FILTERS
// ------------------------------------------------------------

$search = trim($_GET["search"] ?? "");
$selectedCategory = trim($_GET["category"] ?? "");

$items = [];
$categories = [];
$errorMessage = "";

// ------------------------------------------------------------
// 3. LOAD CATEGORIES
// ------------------------------------------------------------

$categoryResult = pg_query(
    $pgConnection,
    "SELECT DISTINCT category
     FROM restaurant_items
     WHERE category IS NOT NULL
       AND TRIM(category) <> ''
     ORDER BY category ASC"
);

if ($categoryResult === false) {
    $errorMessage = "Unable to load restaurant categories.";
} else {
    while ($row = pg_fetch_assoc($categoryResult)) {
        $categories[] = $row["category"];
    }
}

// ------------------------------------------------------------
// 4. LOAD MENU ITEMS
// ------------------------------------------------------------

if ($errorMessage === "") {
    $sql = "
        SELECT
            id,
            name,
            description,
            category,
            price,
            image,
            status
        FROM restaurant_items
        WHERE LOWER(TRIM(COALESCE(status, '')))
              IN ('available', 'active')
    ";

    $params = [];

    if ($search !== "") {
        $params[] = "%" . $search . "%";
        $p = count($params);

        $sql .= "
            AND (
                name ILIKE $" . $p . "
                OR COALESCE(description, '') ILIKE $" . $p . "
                OR COALESCE(category, '') ILIKE $" . $p . "
            )
        ";
    }

    if ($selectedCategory !== "") {
        $params[] = $selectedCategory;
        $p = count($params);

        $sql .= " AND category = $" . $p;
    }

    $sql .= " ORDER BY name ASC";

    if (count($params) > 0) {
        $itemResult = pg_query_params(
            $pgConnection,
            $sql,
            $params
        );
    } else {
        $itemResult = pg_query($pgConnection, $sql);
    }

    if ($itemResult === false) {
        $errorMessage =
            "Unable to load menu items. Check your database column names.";
    } else {
        while ($row = pg_fetch_assoc($itemResult)) {
            $items[] = $row;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Restaurant Menu | Suva Hotel</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Segoe UI", Arial, Helvetica, sans-serif;
            background: #f5f7f5;
            color: #29372e;
        }

        header {
            background: linear-gradient(135deg, #173d2b, #286044);
            color: white;
            padding: 36px 20px;
            text-align: center;
        }

        header h1 {
            margin: 0 0 8px;
            font-size: clamp(28px, 4vw, 40px);
        }

        header p {
            margin: 0;
            color: #e0e9e2;
        }

        .container {
            width: min(1200px, calc(100% - 32px));
            margin: 28px auto;
        }

        /* BOTH BUTTONS IN THE SAME ROW */

        .page-actions {
            display: flex;
            flex-direction: row;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
            margin-bottom: 28px;
        }

        .page-actions a {
            display: inline-flex;
            flex: 1 1 240px;
            min-height: 48px;
            align-items: center;
            justify-content: center;
            gap: 9px;
            padding: 12px 20px;
            border-radius: 9px;
            text-align: center;
            text-decoration: none;
            font-size: 15px;
            font-weight: 700;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .page-actions a:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 14px #00000018;
        }

        .dashboard-button {
            background: #24513a;
            color: #ffffff;
        }

        .dashboard-button:hover {
            background: #173d2b;
        }

        .order-button {
            background: #dcae48;
            color: #263326;
        }

        .order-button:hover {
            background: #c99a32;
        }

        /* SEARCH AND CATEGORY FILTERS */

        .filters {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            margin-bottom: 30px;
            padding: 20px;
            background: white;
            border: 1px solid #e1e8e2;
            border-radius: 12px;
            box-shadow: 0 4px 16px #00000008;
        }

        .filters input,
        .filters select {
            flex: 1 1 180px;
            min-width: 0;
            min-height: 46px;
            padding: 11px 13px;
            border: 1px solid #d5ded7;
            border-radius: 8px;
            background: white;
            color: #29372e;
            font: inherit;
        }

        .filters button,
        .reset-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 11px 18px;
            border: 0;
            border-radius: 8px;
            background: #24513a;
            color: white;
            text-decoration: none;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        .filters button:hover {
            background: #173d2b;
        }

        .filters .reset-button {
            background: #edf1ed;
            color: #34483a;
        }

        /* MENU HEADING */

        .menu-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
        }

        .menu-heading h2 {
            margin: 0;
            color: #214b34;
            font-size: 28px;
        }

        .item-count {
            color: #66736a;
            font-size: 14px;
        }

        /* FOOD CARDS */

        .menu-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            align-items: stretch;
            gap: 24px;
        }

        .food-card {
            min-width: 0;
            overflow: hidden;
            background: white;
            border: 1px solid #e5eae5;
            border-radius: 14px;
            box-shadow: 0 5px 18px #193c270b;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .food-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 28px #193c2715;
        }

        .food-card img {
            display: block;
            width: 100%;
            height: 215px;
            object-fit: cover;
            background: #eee9df;
        }

        .food-info {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            height: calc(100% - 215px);
            padding: 18px;
        }

        .food-info h3 {
            margin: 0 0 8px;
            color: #263d2d;
            font-size: 20px;
            line-height: 1.4;
        }

        .category {
            display: inline-block;
            margin-bottom: 10px;
            padding: 5px 10px;
            border-radius: 20px;
            background: #e9f3eb;
            color: #286044;
            font-size: 12px;
            font-weight: 700;
        }

        .description {
            margin: 0;
            color: #69736c;
            font-size: 14px;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }

        .price {
            margin: auto 0 0;
            padding-top: 16px;
            color: #23603b;
            font-size: 21px;
            font-weight: 800;
        }

        /* ERROR AND EMPTY RESULTS */

        .error-message,
        .empty-message {
            padding: 24px;
            border-radius: 12px;
            background: white;
            text-align: center;
        }

        .error-message {
            color: #a12727;
        }

        /* FOOTER */

        footer {
            margin-top: 48px;
            padding: 24px 16px;
            background: #173d2b;
            color: #e1eee5;
            text-align: center;
        }

        /* TABLET */

        @media (max-width: 900px) {
            .menu-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 18px;
            }

            .food-card img {
                height: 200px;
            }

            .food-info {
                height: calc(100% - 200px);
            }
        }

        /* MOBILE */

        @media (max-width: 600px) {
            .container {
                width: calc(100% - 24px);
                margin: 20px auto;
            }

            header {
                padding: 28px 16px;
            }

            .page-actions {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }

            .page-actions a {
                flex: auto;
                width: 100%;
            }

            .filters {
                padding: 14px;
            }

            .filters input,
            .filters select,
            .filters button,
            .filters .reset-button {
                width: 100%;
                flex: 1 1 100%;
            }

            .menu-grid {
                grid-template-columns: 1fr;
                gap: 18px;
            }

            .food-card img {
                height: 230px;
            }

            .food-info {
                height: auto;
                min-height: 180px;
            }
        }
    </style>
</head>

<body>

<header>
    <h1>Suva Hotel Restaurant</h1>
    <p>Discover delicious food and refreshing drinks</p>
</header>

<main class="container">

    <!-- DASHBOARD AND ORDER BUTTONS: SAME ROW -->

    <div class="page-actions">
        <a href="../dashboard.php" class="dashboard-button">
            &#8592; Back to Admin Dashboard
        </a>

        <a href="orders/add.php" class="order-button">
            &#127860; Create Restaurant Order
        </a>
    </div>

    <!-- SEARCH AND CATEGORY FILTERS -->

    <form method="GET" action="menu.php" class="filters">

        <input
            type="search"
            name="search"
            placeholder="Search food or drinks..."
            value="<?= e($search) ?>"
        >

        <select name="category">
            <option value="">All Categories</option>

            <?php foreach ($categories as $category): ?>
                <option
                    value="<?= e($category) ?>"
                    <?= $selectedCategory === $category ? "selected" : "" ?>
                >
                    <?= e($category) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit">Search Menu</button>

        <a class="reset-button" href="menu.php">Reset</a>

    </form>

    <!-- MENU HEADING -->

    <div class="menu-heading">
        <h2>Our Menu</h2>
        <span class="item-count">
            <?= count($items) ?> item(s) found
        </span>
    </div>

    <!-- MENU RESULTS -->

    <?php if ($errorMessage !== ""): ?>

        <div class="error-message">
            <?= e($errorMessage) ?>
        </div>

    <?php elseif (count($items) === 0): ?>

        <div class="empty-message">
            <h3>No menu items found</h3>
            <p>Try another search or select a different category.</p>
        </div>

    <?php else: ?>

        <div class="menu-grid">

            <?php foreach ($items as $item): ?>

                <?php
                    $foodImage = getFoodImage(
                        $item["image"] ?? "",
                        $item["name"] ?? ""
                    );

                    if ($foodImage === "") {
                        $foodImage =
                            "https://placehold.co/600x400/f3eee2/806c42?text="
                            . rawurlencode($item["name"] ?? "Suva Hotel");
                    }
                ?>

                <article class="food-card">

                    <img
                        src="<?= e($foodImage) ?>"
                        alt="<?= e($item["name"] ?? "Food item") ?>"
                        loading="lazy"
                        onerror="this.onerror=null;this.src='https://placehold.co/600x400/f3eee2/806c42?text=Suva+Hotel';"
                    >

                    <div class="food-info">

                        <?php if (!empty($item["category"])): ?>
                            <span class="category">
                                <?= e($item["category"]) ?>
                            </span>
                        <?php endif; ?>

                        <h3><?= e($item["name"] ?? "") ?></h3>

                        <p class="description">
                            <?= e(
                                $item["description"]
                                ?? "Freshly prepared at Suva Hotel."
                            ) ?>
                        </p>

                        <p class="price">
                            Rs. <?= number_format(
                                (float)($item["price"] ?? 0),
                                2
                            ) ?>
                        </p>

                    </div>

                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</main>

<footer>
    &copy; <?= date("Y") ?> Suva Hotel. All rights reserved.
</footer>

</body>
</html>
