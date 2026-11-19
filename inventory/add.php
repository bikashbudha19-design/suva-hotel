
<?php

// ============================================================
// SUVA HOTEL - ADD INVENTORY PRODUCT
// PostgreSQL Version
// File: /Suva_hotel/inventory/add.php
// ============================================================

session_start();

// ============================================================
// AUTHENTICATION
// ============================================================

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

// ============================================================
// DATABASE CONNECTION
// ============================================================

require_once "../config/database.php";

// ============================================================
// ROLE CHECK
// ============================================================

$role = $_SESSION["role"] ?? "";

if ($role !== "admin" && $role !== "staff") {
    header("Location: ../login.php");
    exit;
}

// ============================================================
// HELPER FUNCTION
// ============================================================

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$message = "";
$message_type = "";

// ============================================================
// GET CATEGORIES
// ============================================================

$category_sql = "
    SELECT id, category_name
    FROM categories
    ORDER BY category_name ASC
";

$category_result = pg_query($pgConnection, $category_sql);

if (!$category_result) {
    die(
        "Category Database Error: " .
        e(pg_last_error($pgConnection))
    );
}

// ============================================================
// SAVE PRODUCT
// ============================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $product_name = trim($_POST["product_name"] ?? "");
    $category_id = (int)($_POST["category_id"] ?? 0);
    $unit = trim($_POST["unit"] ?? "");

    $purchase_price = (float)($_POST["purchase_price"] ?? 0);
    $selling_price = (float)($_POST["selling_price"] ?? 0);
    $current_stock = (float)($_POST["current_stock"] ?? 0);
    $minimum_stock = (float)($_POST["minimum_stock"] ?? 0);

    // ========================================================
    // VALIDATION
    // ========================================================

    if ($product_name === "" || $unit === "") {

        $message = "Product name and unit are required.";
        $message_type = "error";

    } elseif ($category_id <= 0) {

        $message = "Please select a category.";
        $message_type = "error";

    } elseif (
        $purchase_price < 0 ||
        $selling_price < 0 ||
        $current_stock < 0 ||
        $minimum_stock < 0
    ) {

        $message = "Values cannot be negative.";
        $message_type = "error";

    } elseif ($selling_price < $purchase_price) {

        $message = "Selling price cannot be lower than purchase price.";
        $message_type = "error";

    } else {

        // ====================================================
        // CHECK CATEGORY EXISTS
        // ====================================================

        $category_check_sql = "
            SELECT id
            FROM categories
            WHERE id = $1
        ";

        $category_check = pg_query_params(
            $pgConnection,
            $category_check_sql,
            [$category_id]
        );

        if (!$category_check) {

            $message = "Database Error: " .
                pg_last_error($pgConnection);

            $message_type = "error";

        } elseif (pg_num_rows($category_check) === 0) {

            $message = "Selected category does not exist.";
            $message_type = "error";

        } else {

            // =================================================
            // INSERT PRODUCT
            // =================================================

            $sql = "
                INSERT INTO products
                (
                    category_id,
                    product_name,
                    unit,
                    purchase_price,
                    selling_price,
                    current_stock,
                    minimum_stock
                )
                VALUES
                (
                    $1,
                    $2,
                    $3,
                    $4,
                    $5,
                    $6,
                    $7
                )
            ";

            $params = [
                $category_id,
                $product_name,
                $unit,
                $purchase_price,
                $selling_price,
                $current_stock,
                $minimum_stock
            ];

            $result = pg_query_params(
                $pgConnection,
                $sql,
                $params
            );

            if ($result) {

                $message = "Product added successfully!";
                $message_type = "success";

                // Clear form values after successful insertion
                $product_name = "";
                $category_id = 0;
                $unit = "";
                $purchase_price = "";
                $selling_price = "";
                $current_stock = "";
                $minimum_stock = "";

            } else {

                $message = "Database Error: " .
                    pg_last_error($pgConnection);

                $message_type = "error";
            }
        }
    }
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Add Product - Suva Hotel</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            margin: 0;
            padding: 30px;
            background: #f4f6f8;
            color: #333;
        }

        .container {
            max-width: 700px;
            margin: 0 auto;
            background: #ffffff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        h1 {
            margin-top: 0;
            color: #1f2937;
        }

        h2 {
            color: #374151;
            margin-bottom: 25px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
        }

        input,
        select {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 15px;
        }

        input:focus,
        select:focus {
            outline: none;
            border-color: #198754;
        }

        .form-group {
            margin-bottom: 18px;
        }

        button {
            width: 100%;
            padding: 12px;
            background: #198754;
            color: #ffffff;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #146c43;
        }

        .message {
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: bold;
        }

        .success {
            background: #d1e7dd;
            color: #0f5132;
            border: 1px solid #badbcc;
        }

        .error {
            background: #f8d7da;
            color: #842029;
            border: 1px solid #f5c2c7;
        }

        .links {
            margin-top: 25px;
        }

        .links a {
            display: inline-block;
            margin-right: 15px;
            text-decoration: none;
            font-weight: bold;
            color: #0d6efd;
        }

        .links a:hover {
            text-decoration: underline;
        }

    </style>

</head>

<body>

<div class="container">

    <h1>Suva Hotel</h1>

    <h2>Add Product</h2>

    <?php if ($message !== ""): ?>

        <div class="message <?php echo e($message_type); ?>">

            <?php echo e($message); ?>

        </div>

    <?php endif; ?>

    <form method="POST">

        <!-- PRODUCT NAME -->

        <div class="form-group">

            <label for="product_name">
                Product Name:
            </label>

            <input
                type="text"
                id="product_name"
                name="product_name"
                value="<?php echo e($product_name ?? ""); ?>"
                placeholder="Example: Mineral Water"
                required
            >

        </div>


        <!-- CATEGORY -->

        <div class="form-group">

            <label for="category_id">
                Category:
            </label>

            <select
                id="category_id"
                name="category_id"
                required
            >

                <option value="">
                    -- Select Category --
                </option>

                <?php while ($category = pg_fetch_assoc($category_result)): ?>

                    <option
                        value="<?php echo e($category["id"]); ?>"
                        <?php
                        echo (
                            isset($category_id) &&
                            (int)$category_id === (int)$category["id"]
                        )
                        ? "selected"
                        : "";
                        ?>
                    >

                        <?php echo e($category["category_name"]); ?>

                    </option>

                <?php endwhile; ?>

            </select>

        </div>


        <!-- UNIT -->

        <div class="form-group">

            <label for="unit">
                Unit:
            </label>

            <input
                type="text"
                id="unit"
                name="unit"
                value="<?php echo e($unit ?? ""); ?>"
                placeholder="Example: kg, liter, bottle, piece"
                required
            >

        </div>


        <!-- PURCHASE PRICE -->

        <div class="form-group">

            <label for="purchase_price">
                Purchase Price:
            </label>

            <input
                type="number"
                id="purchase_price"
                name="purchase_price"
                value="<?php echo e($purchase_price ?? ""); ?>"
                step="0.01"
                min="0"
                placeholder="Example: 20"
                required
            >

        </div>


        <!-- SELLING PRICE -->

        <div class="form-group">

            <label for="selling_price">
                Selling Price:
            </label>

            <input
                type="number"
                id="selling_price"
                name="selling_price"
                value="<?php echo e($selling_price ?? ""); ?>"
                step="0.01"
                min="0"
                placeholder="Example: 30"
                required
            >

        </div>


        <!-- CURRENT STOCK -->

        <div class="form-group">

            <label for="current_stock">
                Opening Quantity:
            </label>

            <input
                type="number"
                id="current_stock"
                name="current_stock"
                value="<?php echo e($current_stock ?? ""); ?>"
                step="0.01"
                min="0"
                placeholder="Example: 100"
                required
            >

        </div>


        <!-- MINIMUM STOCK -->

        <div class="form-group">

            <label for="minimum_stock">
                Minimum Stock:
            </label>

            <input
                type="number"
                id="minimum_stock"
                name="minimum_stock"
                value="<?php echo e($minimum_stock ?? ""); ?>"
                step="0.01"
                min="0"
                placeholder="Example: 20"
                required
            >

        </div>


        <!-- SUBMIT -->

        <button type="submit">
            + Add Product
        </button>

    </form>


    <div class="links">

        <a href="view.php">
            View Inventory
        </a>

        <a href="../dashboard.php">
            Back to Dashboard
        </a>

    </div>

</div>

</body>

</html>