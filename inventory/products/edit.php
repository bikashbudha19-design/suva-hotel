<?php

require_once "../../config/database.php";

$message = "";
$message_type = "";

/*
|--------------------------------------------------------------------------
| Check Product ID
|--------------------------------------------------------------------------
*/

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid Product ID.");
}

$id = (int) $_GET["id"];

/*
|--------------------------------------------------------------------------
| Update Product
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $product_name = trim($_POST["product_name"] ?? "");
    $category_id = (int) ($_POST["category_id"] ?? 0);
    $unit = trim($_POST["unit"] ?? "");

    $purchase_price = (float) ($_POST["purchase_price"] ?? 0);
    $selling_price = (float) ($_POST["selling_price"] ?? 0);
    $current_stock = (int) ($_POST["quantity"] ?? 0);
    $minimum_stock = (int) ($_POST["minimum_stock"] ?? 0);

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if ($product_name === "") {

        $message = "Product name is required.";
        $message_type = "error";

    } elseif ($category_id <= 0) {

        $message = "Please select a category.";
        $message_type = "error";

    } elseif ($unit === "") {

        $message = "Unit is required.";
        $message_type = "error";

    } elseif ($purchase_price < 0) {

        $message = "Purchase price cannot be negative.";
        $message_type = "error";

    } elseif ($selling_price < 0) {

        $message = "Selling price cannot be negative.";
        $message_type = "error";

    } elseif ($current_stock < 0) {

        $message = "Quantity cannot be negative.";
        $message_type = "error";

    } elseif ($minimum_stock < 0) {

        $message = "Minimum stock cannot be negative.";
        $message_type = "error";

    } else {

        /*
        |--------------------------------------------------------------------------
        | Update Database
        |--------------------------------------------------------------------------
        */

        $sql = "UPDATE products
                SET
                    category_id = ?,
                    product_name = ?,
                    unit = ?,
                    purchase_price = ?,
                    selling_price = ?,
                    current_stock = ?,
                    minimum_stock = ?
                WHERE id = ?";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            die("Database Error: " . $conn->error);
        }

        $stmt->bind_param(
            "issddiii",
            $category_id,
            $product_name,
            $unit,
            $purchase_price,
            $selling_price,
            $current_stock,
            $minimum_stock,
            $id
        );

        if ($stmt->execute()) {

            $message = "Product updated successfully!";
            $message_type = "success";

        } else {

            $message = "Error updating product: " . $stmt->error;
            $message_type = "error";
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Get Product
|--------------------------------------------------------------------------
*/

$sql = "SELECT
            id,
            category_id,
            product_name,
            unit,
            purchase_price,
            selling_price,
            current_stock,
            minimum_stock
        FROM products
        WHERE id = ?";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Database Error: " . $conn->error);
}

$stmt->bind_param("i", $id);

$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    die("Product not found.");
}

$product = $result->fetch_assoc();

$stmt->close();

/*
|--------------------------------------------------------------------------
| Get Categories
|--------------------------------------------------------------------------
*/

$category_sql = "SELECT
                    id,
                    category_name
                 FROM categories
                 ORDER BY category_name ASC";

$category_result = $conn->query($category_sql);

if (!$category_result) {
    die("Category Database Error: " . $conn->error);
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Edit Product - Suva Hotel</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px;
            font-family: Arial, sans-serif;
            background: #f4f6f9;
        }

        .container {
            max-width: 700px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.10);
        }

        .hotel-title {
            margin: 0;
            color: #1f2937;
            font-size: 28px;
        }

        .page-title {
            margin-top: 8px;
            margin-bottom: 25px;
            color: #555;
            font-size: 22px;
        }

        .message {
            padding: 12px 15px;
            border-radius: 7px;
            margin-bottom: 20px;
            font-weight: bold;
        }

        .success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
            color: #374151;
        }

        input,
        select {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 15px;
        }

        input:focus,
        select:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15);
        }

        .button-area {
            margin-top: 25px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .update-btn {
            border: none;
            background: #2563eb;
            color: white;
            padding: 12px 20px;
            border-radius: 6px;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
        }

        .update-btn:hover {
            background: #1d4ed8;
        }

        .back-btn {
            display: inline-block;
            padding: 12px 20px;
            background: #6b7280;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: bold;
        }

        .back-btn:hover {
            background: #4b5563;
        }

        .dashboard-link {
            display: inline-block;
            margin-top: 20px;
            color: #2563eb;
            text-decoration: none;
            font-weight: bold;
        }

        .dashboard-link:hover {
            text-decoration: underline;
        }

    </style>

</head>

<body>

<div class="container">

    <h1 class="hotel-title">🏨 Suva Hotel</h1>

    <h2 class="page-title">Edit Product</h2>


    <?php if ($message !== ""): ?>

        <div class="message <?php echo $message_type; ?>">

            <?php echo htmlspecialchars($message); ?>

        </div>

    <?php endif; ?>


    <form method="POST">


        <!-- Product Name -->

        <div class="form-group">

            <label for="product_name">
                Product Name
            </label>

            <input
                type="text"
                id="product_name"
                name="product_name"
                value="<?php echo htmlspecialchars(
                    $product["product_name"]
                ); ?>"
                required
            >

        </div>


        <!-- Category -->

        <div class="form-group">

            <label for="category_id">
                Category
            </label>

            <select
                id="category_id"
                name="category_id"
                required
            >

                <option value="">
                    -- Select Category --
                </option>

                <?php while (
                    $category = $category_result->fetch_assoc()
                ): ?>

                    <option
                        value="<?php echo $category["id"]; ?>"
                        <?php
                        echo (
                            (int)$category["id"]
                            === (int)$product["category_id"]
                        )
                        ? "selected"
                        : "";
                        ?>
                    >

                        <?php
                        echo htmlspecialchars(
                            $category["category_name"]
                        );
                        ?>

                    </option>

                <?php endwhile; ?>

            </select>

        </div>


        <!-- Unit -->

        <div class="form-group">

            <label for="unit">
                Unit
            </label>

            <input
                type="text"
                id="unit"
                name="unit"
                value="<?php echo htmlspecialchars(
                    $product["unit"]
                ); ?>"
                required
            >

        </div>


        <!-- Purchase Price -->

        <div class="form-group">

            <label for="purchase_price">
                Purchase Price (Rs.)
            </label>

            <input
                type="number"
                id="purchase_price"
                name="purchase_price"
                min="0"
                step="0.01"
                value="<?php echo htmlspecialchars(
                    $product["purchase_price"]
                ); ?>"
                required
            >

        </div>


        <!-- Selling Price -->

        <div class="form-group">

            <label for="selling_price">
                Selling Price (Rs.)
            </label>

            <input
                type="number"
                id="selling_price"
                name="selling_price"
                min="0"
                step="0.01"
                value="<?php echo htmlspecialchars(
                    $product["selling_price"]
                ); ?>"
                required
            >

        </div>


        <!-- Quantity -->

        <div class="form-group">

            <label for="quantity">
                Quantity
            </label>

            <input
                type="number"
                id="quantity"
                name="quantity"
                min="0"
                value="<?php echo htmlspecialchars(
                    $product["current_stock"]
                ); ?>"
                required
            >

        </div>


        <!-- Minimum Stock -->

        <div class="form-group">

            <label for="minimum_stock">
                Minimum Stock
            </label>

            <input
                type="number"
                id="minimum_stock"
                name="minimum_stock"
                min="0"
                value="<?php echo htmlspecialchars(
                    $product["minimum_stock"]
                ); ?>"
                required
            >

        </div>


        <!-- Buttons -->

        <div class="button-area">

            <button
                type="submit"
                class="update-btn"
            >
                💾 Update Product
            </button>

            <a
                href="view.php"
                class="back-btn"
            >
                ← Back to Products
            </a>

        </div>

    </form>


    <a
        href="../../dashboard.php"
        class="dashboard-link"
    >
        ← Back to Dashboard
    </a>

</div>

</body>

</html>