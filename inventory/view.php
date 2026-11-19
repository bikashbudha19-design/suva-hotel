
<?php

// ============================================================
// SUVA HOTEL - INVENTORY PRODUCT LIST
// PostgreSQL Version
// File: /Suva_hotel/inventory/view.php
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
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// ============================================================
// GET ALL PRODUCTS WITH CATEGORY
// ============================================================

$sql = "
    SELECT
        p.id,
        p.product_name,
        p.unit,
        p.purchase_price,
        p.selling_price,
        p.current_stock,
        p.minimum_stock,
        COALESCE(c.category_name, 'Uncategorized') AS category
    FROM products p
    LEFT JOIN categories c
        ON p.category_id = c.id
    ORDER BY p.id DESC
";

$result = pg_query($pgConnection, $sql);

if (!$result) {
    die(
        "Database Error: " .
        e(pg_last_error($pgConnection))
    );
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Inventory - Suva Hotel</title>

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
            max-width: 1400px;
            margin: 0 auto;
            background: #ffffff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        h1 {
            margin: 0 0 5px;
            color: #1f2937;
        }

        h2 {
            margin-top: 10px;
            margin-bottom: 25px;
            color: #374151;
        }

        .top-actions {
            margin-bottom: 20px;
        }

        .btn {
            display: inline-block;
            padding: 10px 16px;
            text-decoration: none;
            border-radius: 6px;
            color: #ffffff;
            font-weight: bold;
        }

        .btn-add {
            background: #198754;
        }

        .btn-add:hover {
            background: #146c43;
        }

        .btn-back {
            background: #6c757d;
        }

        .btn-back:hover {
            background: #5c636a;
        }

        .table-container {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #ffffff;
        }

        th {
            background: #212529;
            color: #ffffff;
            padding: 13px;
            text-align: left;
            white-space: nowrap;
        }

        td {
            padding: 13px;
            border-bottom: 1px solid #dddddd;
            white-space: nowrap;
        }

        tbody tr:hover {
            background: #f8f9fa;
        }

        .low-stock {
            color: #dc3545;
            font-weight: bold;
        }

        .available {
            color: #198754;
            font-weight: bold;
        }

        .no-products {
            text-align: center;
            padding: 30px;
            color: #777777;
        }

    </style>

</head>

<body>

<div class="container">

    <h1>Suva Hotel</h1>

    <h2>Inventory</h2>

    <div class="top-actions">

        <a href="add.php" class="btn btn-add">
            + Add New Product
        </a>

    </div>

    <div class="table-container">

        <table>

            <thead>

                <tr>

                    <th>ID</th>

                    <th>Product Name</th>

                    <th>Category</th>

                    <th>Unit</th>

                    <th>Purchase Price</th>

                    <th>Selling Price</th>

                    <th>Quantity</th>

                    <th>Minimum Stock</th>

                    <th>Stock Status</th>

                </tr>

            </thead>

            <tbody>

            <?php if (pg_num_rows($result) > 0): ?>

                <?php while ($row = pg_fetch_assoc($result)): ?>

                    <?php

                    $currentStock = (float)$row["current_stock"];
                    $minimumStock = (float)$row["minimum_stock"];

                    ?>

                    <tr>

                        <!-- ID -->

                        <td>
                            <?php echo e($row["id"]); ?>
                        </td>

                        <!-- PRODUCT NAME -->

                        <td>
                            <?php echo e($row["product_name"]); ?>
                        </td>

                        <!-- CATEGORY -->

                        <td>
                            <?php echo e($row["category"]); ?>
                        </td>

                        <!-- UNIT -->

                        <td>
                            <?php echo e($row["unit"]); ?>
                        </td>

                        <!-- PURCHASE PRICE -->

                        <td>
                            Rs. <?php echo number_format(
                                (float)$row["purchase_price"],
                                2
                            ); ?>
                        </td>

                        <!-- SELLING PRICE -->

                        <td>
                            Rs. <?php echo number_format(
                                (float)$row["selling_price"],
                                2
                            ); ?>
                        </td>

                        <!-- CURRENT STOCK -->

                        <td>
                            <?php echo number_format(
                                $currentStock,
                                2
                            ); ?>
                        </td>

                        <!-- MINIMUM STOCK -->

                        <td>
                            <?php echo number_format(
                                $minimumStock,
                                2
                            ); ?>
                        </td>

                        <!-- STOCK STATUS -->

                        <td>

                            <?php if ($currentStock <= 0): ?>

                                <span class="low-stock">
                                    ❌ Out of Stock
                                </span>

                            <?php elseif ($currentStock <= $minimumStock): ?>

                                <span class="low-stock">
                                    ⚠️ Low Stock
                                </span>

                            <?php else: ?>

                                <span class="available">
                                    ✅ Available
                                </span>

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endwhile; ?>

            <?php else: ?>

                <tr>

                    <td colspan="9" class="no-products">
                        No products found.
                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

    <br><br>

    <a href="../dashboard.php" class="btn btn-back">
        ← Back to Dashboard
    </a>

</div>

</body>

</html>
