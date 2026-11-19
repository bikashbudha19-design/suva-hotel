```php
<?php

// ============================================================
// SUVA HOTEL - INVENTORY DASHBOARD
// PostgreSQL Version
// File: /Suva_hotel/inventory/dashboard.php
// ============================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
// INVENTORY SUMMARY
// ============================================================


// ------------------------------------------------------------
// Total Products
// ------------------------------------------------------------

$product_sql = "
    SELECT COUNT(*) AS total
    FROM products
";

$product_result = $conn->query($product_sql);

$total_products = 0;

if ($product_result) {
    $row = $product_result->fetch_assoc();

    if ($row) {
        $total_products = (int)$row["total"];
    }
}


// ------------------------------------------------------------
// Total Stock Quantity
// ------------------------------------------------------------

$stock_sql = "
    SELECT COALESCE(SUM(quantity), 0) AS total
    FROM products
";

$stock_result = $conn->query($stock_sql);

$total_stock = 0;

if ($stock_result) {
    $row = $stock_result->fetch_assoc();

    if ($row) {
        $total_stock = (float)$row["total"];
    }
}


// ------------------------------------------------------------
// Low Stock Products
// ------------------------------------------------------------

$low_sql = "
    SELECT COUNT(*) AS total
    FROM products
    WHERE quantity <= minimum_stock
      AND quantity > 0
";

$low_result = $conn->query($low_sql);

$low_stock = 0;

if ($low_result) {
    $row = $low_result->fetch_assoc();

    if ($row) {
        $low_stock = (int)$row["total"];
    }
}


// ------------------------------------------------------------
// Out of Stock Products
// ------------------------------------------------------------

$out_sql = "
    SELECT COUNT(*) AS total
    FROM products
    WHERE quantity <= 0
";

$out_result = $conn->query($out_sql);

$out_of_stock = 0;

if ($out_result) {
    $row = $out_result->fetch_assoc();

    if ($row) {
        $out_of_stock = (int)$row["total"];
    }
}


// ------------------------------------------------------------
// Total Inventory Value
// ------------------------------------------------------------

$value_sql = "
    SELECT COALESCE(
        SUM(quantity * purchase_price),
        0
    ) AS total
    FROM products
";

$value_result = $conn->query($value_sql);

$total_value = 0;

if ($value_result) {
    $row = $value_result->fetch_assoc();

    if ($row) {
        $total_value = (float)$row["total"];
    }
}


// ============================================================
// TODAY'S STOCK ACTIVITY
// ============================================================


// ------------------------------------------------------------
// Today's Stock In Quantity
// PostgreSQL uses CURRENT_DATE
// ------------------------------------------------------------

$today_stock_in_qty_sql = "
    SELECT COALESCE(SUM(quantity), 0) AS total
    FROM stock_in
    WHERE DATE(created_at) = CURRENT_DATE
";

$today_stock_in_qty_result =
    $conn->query($today_stock_in_qty_sql);

$today_stock_in_qty = 0;

if ($today_stock_in_qty_result) {

    $row =
        $today_stock_in_qty_result->fetch_assoc();

    if ($row) {
        $today_stock_in_qty =
            (float)$row["total"];
    }
}


// ------------------------------------------------------------
// Today's Stock In Value
// ------------------------------------------------------------

$today_stock_in_sql = "
    SELECT COALESCE(SUM(quantity * unit_cost), 0) AS total
    FROM stock_in
    WHERE DATE(created_at) = CURRENT_DATE
";
$today_stock_in_result =
    $conn->query($today_stock_in_sql);

$today_stock_in = 0;

if ($today_stock_in_result) {

    $row =
        $today_stock_in_result->fetch_assoc();

    if ($row) {
        $today_stock_in =
            (float)$row["total"];
    }
}


// ------------------------------------------------------------
// Today's Stock Out Quantity
// ------------------------------------------------------------

$today_stock_out_sql = "
    SELECT COALESCE(SUM(quantity), 0) AS total
    FROM stock_out
    WHERE DATE(created_at) = CURRENT_DATE
";

$today_stock_out_result =
    $conn->query($today_stock_out_sql);

$today_stock_out = 0;

if ($today_stock_out_result) {

    $row =
        $today_stock_out_result->fetch_assoc();

    if ($row) {
        $today_stock_out =
            (float)$row["total"];
    }
}


// ============================================================
// HELPER
// ============================================================

function money($amount)
{
    return "Rs. " .
        number_format(
            (float)$amount,
            2
        );
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

    <title>
        Inventory Dashboard - Suva Hotel
    </title>


    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }


        body {

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background: #f4f6f9;

            color: #333;
        }


        /* =========================
           SIDEBAR
           ========================= */

        .sidebar {

            position: fixed;

            left: 0;

            top: 0;

            width: 240px;

            height: 100vh;

            background: #172033;

            color: white;

            padding: 25px 15px;
        }


        .logo {

            text-align: center;

            margin-bottom: 35px;
        }


        .logo h2 {

            font-size: 24px;

            margin-bottom: 5px;
        }


        .logo p {

            font-size: 12px;

            color: #aeb8c8;
        }


        .menu a {

            display: block;

            text-decoration: none;

            color: #dbe2ec;

            padding: 13px 15px;

            margin-bottom: 7px;

            border-radius: 8px;

            transition: 0.2s;
        }


        .menu a:hover {

            background: #263552;

            color: white;
        }


        .menu a.active {

            background: #2563eb;

            color: white;
        }


        .logout {

            position: absolute;

            bottom: 25px;

            left: 15px;

            right: 15px;
        }


        .logout a {

            display: block;

            text-align: center;

            padding: 12px;

            background: #dc3545;

            color: white;

            text-decoration: none;

            border-radius: 8px;
        }


        .logout a:hover {

            background: #bb2d3b;
        }


        /* =========================
           MAIN CONTENT
           ========================= */

        .main {

            margin-left: 240px;

            padding: 30px;
        }


        /* =========================
           HEADER
           ========================= */

        .topbar {

            display: flex;

            justify-content:
                space-between;

            align-items: center;

            margin-bottom: 30px;
        }


        .topbar h1 {

            font-size: 30px;

            color: #172033;
        }


        .topbar p {

            margin-top: 5px;

            color: #777;
        }


        .admin {

            background: white;

            padding: 12px 18px;

            border-radius: 8px;

            box-shadow:
                0 2px 10px
                rgba(0,0,0,0.06);
        }


        /* =========================
           CARDS
           ========================= */

        .cards {

            display: grid;

            grid-template-columns:
                repeat(5, 1fr);

            gap: 18px;

            margin-bottom: 30px;
        }


        .card {

            background: white;

            padding: 22px;

            border-radius: 12px;

            box-shadow:
                0 3px 12px
                rgba(0,0,0,0.07);
        }


        .card-title {

            color: #777;

            font-size: 14px;

            margin-bottom: 12px;
        }


        .card-value {

            font-size: 28px;

            font-weight: bold;

            color: #172033;
        }


        .card-icon {

            font-size: 28px;

            margin-bottom: 10px;
        }


        .card.blue {

            border-top:
                4px solid #2563eb;
        }


        .card.green {

            border-top:
                4px solid #198754;
        }


        .card.orange {

            border-top:
                4px solid #f59e0b;
        }


        .card.red {

            border-top:
                4px solid #dc3545;
        }


        .card.purple {

            border-top:
                4px solid #7c3aed;
        }


        /* =========================
           SECTION
           ========================= */

        .section-title {

            font-size: 21px;

            margin-bottom: 15px;

            color: #172033;
        }


        /* =========================
           STOCK ACTIVITY
           ========================= */

        .activity {

            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap: 18px;

            margin-bottom: 30px;
        }


        .activity-card {

            background: white;

            padding: 25px;

            border-radius: 12px;

            box-shadow:
                0 3px 12px
                rgba(0,0,0,0.07);
        }


        .activity-card h3 {

            font-size: 15px;

            color: #777;

            margin-bottom: 12px;
        }


        .activity-card p {

            font-size: 25px;

            font-weight: bold;

            color: #172033;
        }


        /* =========================
           QUICK ACTIONS
           ========================= */

        .actions {

            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap: 15px;
        }


        .action {

            background: white;

            padding: 20px;

            border-radius: 10px;

            text-decoration: none;

            color: #172033;

            box-shadow:
                0 3px 12px
                rgba(0,0,0,0.07);

            transition: 0.2s;
        }


        .action:hover {

            transform:
                translateY(-2px);

            box-shadow:
                0 6px 18px
                rgba(0,0,0,0.10);
        }


        .action-icon {

            font-size: 25px;

            margin-bottom: 8px;
        }


        .action-title {

            font-weight: bold;

            margin-bottom: 5px;
        }


        .action-description {

            font-size: 13px;

            color: #777;
        }


        /* =========================
           FOOTER
           ========================= */

        .footer {

            margin-top: 40px;

            text-align: center;

            color: #888;

            font-size: 13px;
        }


        /* =========================
           RESPONSIVE
           ========================= */

        @media (max-width: 1100px) {

            .cards {

                grid-template-columns:
                    repeat(3, 1fr);
            }
        }


        @media (max-width: 800px) {

            .sidebar {

                position: relative;

                width: 100%;

                height: auto;
            }


            .logout {

                position: relative;

                left: 0;

                right: 0;

                bottom: auto;

                margin-top: 20px;
            }


            .main {

                margin-left: 0;

                padding: 20px;
            }


            .cards,
            .activity,
            .actions {

                grid-template-columns: 1fr;
            }


            .topbar {

                display: block;
            }


            .admin {

                margin-top: 15px;
            }
        }

    </style>

</head>


<body>


<!-- =========================
     SIDEBAR
     ========================= -->

<div class="sidebar">


    <div class="logo">

        <h2>
            🏨 Suva Hotel
        </h2>

        <p>
            Management System
        </p>

    </div>


    <div class="menu">


        <a href="../dashboard.php">
            🏠 Main Dashboard
        </a>


        <a
            href="dashboard.php"
            class="active"
        >
            📦 Inventory
        </a>


        <a href="products/add.php">
            ➕ Add Product
        </a>


        <a href="stock_in.php">
            📥 Stock In
        </a>


        <a href="stock_out.php">
            📤 Stock Out
        </a>


        <a href="stock_history.php">
            📋 Stock In History
        </a>


        <a href="stock_out_history.php">
            📋 Stock Out History
        </a>


    </div>


    <div class="logout">

        <a href="../logout.php">
            🚪 Logout
        </a>

    </div>


</div>


<!-- =========================
     MAIN CONTENT
     ========================= -->

<div class="main">


    <!-- HEADER -->

    <div class="topbar">


        <div>

            <h1>
                Inventory Dashboard
            </h1>

            <p>
                Monitor products, stock and inventory value.
            </p>

        </div>


        <div class="admin">

            👤 <strong>
                <?php
                echo htmlspecialchars(
                    $_SESSION["username"] ?? "Admin",
                    ENT_QUOTES,
                    "UTF-8"
                );
                ?>
            </strong>

        </div>


    </div>


    <!-- =========================
         INVENTORY SUMMARY
         ========================= -->

    <h2 class="section-title">
        Inventory Summary
    </h2>


    <div class="cards">


        <!-- PRODUCTS -->

        <div class="card blue">

            <div class="card-icon">
                📦
            </div>

            <div class="card-title">
                Total Products
            </div>

            <div class="card-value">

                <?php
                echo $total_products;
                ?>

            </div>

        </div>


        <!-- STOCK -->

        <div class="card green">

            <div class="card-icon">
                📊
            </div>

            <div class="card-title">
                Total Stock
            </div>

            <div class="card-value">

                <?php
                echo $total_stock;
                ?>

            </div>

        </div>


        <!-- LOW STOCK -->

        <div class="card orange">

            <div class="card-icon">
                ⚠️
            </div>

            <div class="card-title">
                Low Stock
            </div>

            <div class="card-value">

                <?php
                echo $low_stock;
                ?>

            </div>

        </div>


        <!-- OUT OF STOCK -->

        <div class="card red">

            <div class="card-icon">
                ❌
            </div>

            <div class="card-title">
                Out of Stock
            </div>

            <div class="card-value">

                <?php
                echo $out_of_stock;
                ?>

            </div>

        </div>


        <!-- INVENTORY VALUE -->

        <div class="card purple">

            <div class="card-icon">
                💰
            </div>

            <div class="card-title">
                Inventory Value
            </div>

            <div
                class="card-value"
                style="font-size:20px;"
            >

                <?php
                echo money($total_value);
                ?>

            </div>

        </div>


    </div>


    <!-- =========================
         TODAY'S ACTIVITY
         ========================= -->

    <h2 class="section-title">
        Today's Stock Activity
    </h2>


    <div class="activity">


        <!-- STOCK IN -->

        <div class="activity-card">

            <h3>
                📥 Stock In Quantity
            </h3>

            <p>

                <?php
                echo $today_stock_in_qty;
                ?>

            </p>

        </div>


        <!-- STOCK IN VALUE -->

        <div class="activity-card">

            <h3>
                💰 Stock In Value
            </h3>

            <p>

                <?php
                echo money($today_stock_in);
                ?>

            </p>

        </div>


        <!-- STOCK OUT -->

        <div class="activity-card">

            <h3>
                📤 Stock Out Quantity
            </h3>

            <p>

                <?php
                echo $today_stock_out;
                ?>

            </p>

        </div>


    </div>


    <!-- =========================
         QUICK ACTIONS
         ========================= -->

    <h2 class="section-title">
        Inventory Management
    </h2>


    <div class="actions">


        <a
            href="view.php"
            class="action"
        >

            <div class="action-icon">
                📦
            </div>

            <div class="action-title">
                View Inventory
            </div>

            <div class="action-description">
                View all available products and stock.
            </div>

        </a>


        <a
            href="products/add.php"
            class="action"
        >

            <div class="action-icon">
                ➕
            </div>

            <div class="action-title">
                Add Product
            </div>

            <div class="action-description">
                Add a new product to inventory.
            </div>

        </a>


        <a
            href="stock_in.php"
            class="action"
        >

            <div class="action-icon">
                📥
            </div>

            <div class="action-title">
                Add Stock
            </div>

            <div class="action-description">
                Record incoming inventory.
            </div>

        </a>


        <a
            href="stock_history.php"
            class="action"
        >

            <div class="action-icon">
                📋
            </div>

            <div class="action-title">
                Stock In History
            </div>

            <div class="action-description">
                View previous stock-in records.
            </div>

        </a>


        <a
            href="stock_out.php"
            class="action"
        >

            <div class="action-icon">
                📤
            </div>

            <div class="action-title">
                Remove Stock
            </div>

            <div class="action-description">
                Record products removed from stock.
            </div>

        </a>


        <a
            href="stock_out_history.php"
            class="action"
        >

            <div class="action-icon">
                📋
            </div>

            <div class="action-title">
                Stock Out History
            </div>

            <div class="action-description">
                View previous stock-out records.
            </div>

        </a>


    </div>


    <!-- FOOTER -->

    <div class="footer">

        ©
        <?php
        echo date("Y");
        ?>

        Suva Hotel Management System

    </div>


</div>


</body>

</html>
```
