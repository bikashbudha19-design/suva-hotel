
<?php

session_start();

/*
|--------------------------------------------------------------------------
| LOGIN PROTECTION
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit;
}

require_once "../../config/database.php";

/*
|--------------------------------------------------------------------------
| ROLE PROTECTION
|--------------------------------------------------------------------------
*/

$role = $_SESSION["role"] ?? "";

if ($role !== "admin" && $role !== "staff") {
    header("Location: ../../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| GET PRODUCTS WITH CATEGORY NAME
|--------------------------------------------------------------------------
|
| products.category_id
|          ↓
| categories.id
|          ↓
| categories.category_name
|
*/

$sql = "SELECT
            p.id,
            p.product_name,
            p.category_id,
            c.category_name,
            p.unit,
            p.purchase_price,
            p.selling_price,
            p.current_stock,
            p.minimum_stock,
            p.created_at

        FROM products p

        LEFT JOIN categories c
            ON p.category_id = c.id

        ORDER BY p.id DESC";


$result = $conn->query($sql);


if (!$result) {

    die(
        "Database Error: "
        . htmlspecialchars($conn->error)
    );

}


/*
|--------------------------------------------------------------------------
| INVENTORY SUMMARY
|--------------------------------------------------------------------------
*/

$total_products = 0;
$total_stock = 0;
$low_stock_count = 0;
$out_stock_count = 0;

$products = [];


while ($row = $result->fetch_assoc()) {

    $products[] = $row;

    $total_products++;

    $current_stock =
        (float)($row["current_stock"] ?? 0);

    $minimum_stock =
        (float)($row["minimum_stock"] ?? 0);


    $total_stock += $current_stock;


    if ($current_stock <= 0) {

        $out_stock_count++;

    }

    elseif ($current_stock <= $minimum_stock) {

        $low_stock_count++;

    }

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
    Products - Suva Hotel
</title>


<style>

/* =====================================================
   RESET
===================================================== */

* {
    box-sizing: border-box;
}


/* =====================================================
   BODY
===================================================== */

body {

    margin: 0;

    font-family:
        "Segoe UI",
        Arial,
        sans-serif;

    background:
        linear-gradient(
            135deg,
            #eef2f7,
            #f8fafc
        );

    color: #1f2937;

}


/* =====================================================
   MAIN PAGE
===================================================== */

.page {

    width: 100%;

    max-width: 1550px;

    margin: auto;

    padding: 25px;

}


/* =====================================================
   HEADER
===================================================== */

.header {

    background:
        linear-gradient(
            135deg,
            #173b73,
            #2456a6
        );

    color: white;

    border-radius: 18px;

    padding: 25px 30px;

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 20px;

    box-shadow:
        0 10px 30px
        rgba(23,59,115,0.20);

}


.hotel-title {

    display: flex;

    align-items: center;

    gap: 15px;

}


.hotel-icon {

    width: 58px;

    height: 58px;

    border-radius: 15px;

    background:
        rgba(255,255,255,0.15);

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 29px;

}


.hotel-title h1 {

    margin: 0;

    font-size: 27px;

}


.hotel-title p {

    margin: 5px 0 0;

    color:
        rgba(255,255,255,0.80);

    font-size: 14px;

}


/* =====================================================
   USER
===================================================== */

.user-box {

    text-align: right;

    font-size: 13px;

}


.user-name {

    font-weight: 700;

    font-size: 15px;

    margin-top: 3px;

}


.role-badge {

    display: inline-block;

    margin-top: 6px;

    padding: 5px 13px;

    border-radius: 20px;

    background:
        rgba(255,255,255,0.16);

    font-size: 11px;

    font-weight: 800;

    text-transform: uppercase;

}


/* =====================================================
   PAGE TITLE
===================================================== */

.page-title {

    margin-top: 30px;

    margin-bottom: 20px;

}


.page-title h2 {

    margin: 0;

    font-size: 27px;

    color: #172033;

}


.page-title p {

    margin: 7px 0 0;

    color: #6b7280;

}


/* =====================================================
   SUMMARY CARDS
===================================================== */

.summary-grid {

    display: grid;

    grid-template-columns:
        repeat(4, 1fr);

    gap: 18px;

    margin-bottom: 25px;

}


.summary-card {

    background: white;

    border-radius: 15px;

    padding: 20px;

    display: flex;

    align-items: center;

    gap: 15px;

    box-shadow:
        0 5px 20px
        rgba(0,0,0,0.06);

    border:
        1px solid #edf0f4;

    transition: 0.2s;

}


.summary-card:hover {

    transform:
        translateY(-3px);

    box-shadow:
        0 10px 25px
        rgba(0,0,0,0.09);

}


.summary-icon {

    width: 52px;

    height: 52px;

    border-radius: 13px;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 24px;

}


.icon-blue {
    background: #e8f0ff;
}


.icon-green {
    background: #e8f8ef;
}


.icon-orange {
    background: #fff3df;
}


.icon-red {
    background: #ffe8e8;
}


.summary-text span {

    display: block;

    color: #6b7280;

    font-size: 13px;

    margin-bottom: 4px;

}


.summary-text strong {

    font-size: 23px;

    color: #111827;

}


/* =====================================================
   TOOLBAR
===================================================== */

.toolbar {

    background: white;

    border-radius: 15px;

    padding: 18px;

    margin-bottom: 20px;

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 15px;

    flex-wrap: wrap;

    box-shadow:
        0 5px 20px
        rgba(0,0,0,0.05);

}


.toolbar-left {

    display: flex;

    gap: 10px;

    flex-wrap: wrap;

}


/* =====================================================
   BUTTONS
===================================================== */

.btn {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    gap: 7px;

    padding: 11px 17px;

    border-radius: 9px;

    text-decoration: none;

    font-weight: 700;

    font-size: 14px;

    transition: 0.2s;

    border: none;

}


.add-btn {

    background: #198754;

    color: white;

}


.add-btn:hover {

    background: #157347;

    transform:
        translateY(-1px);

}


.back-btn {

    background: #173b73;

    color: white;

}


.back-btn:hover {

    background: #122e5b;

    transform:
        translateY(-1px);

}


/* =====================================================
   SEARCH
===================================================== */

.search-box {

    position: relative;

}


.search-box input {

    width: 300px;

    padding:
        11px
        15px
        11px
        42px;

    border:
        1px solid #d8dee8;

    border-radius: 9px;

    outline: none;

    font-size: 14px;

    transition: 0.2s;

}


.search-box input:focus {

    border-color: #2456a6;

    box-shadow:
        0 0 0 3px
        rgba(36,86,166,0.10);

}


.search-icon {

    position: absolute;

    left: 14px;

    top: 10px;

    font-size: 16px;

}


/* =====================================================
   TABLE CARD
===================================================== */

.table-card {

    background: white;

    border-radius: 16px;

    overflow: hidden;

    box-shadow:
        0 7px 25px
        rgba(0,0,0,0.06);

}


.table-header {

    padding:
        20px 22px;

    border-bottom:
        1px solid #edf0f4;

    display: flex;

    justify-content: space-between;

    align-items: center;

}


.table-header h3 {

    margin: 0;

    font-size: 18px;

}


.table-header span {

    color: #6b7280;

    font-size: 13px;

}


/* =====================================================
   TABLE
===================================================== */

.table-wrapper {

    overflow-x: auto;

}


table {

    width: 100%;

    border-collapse: collapse;

    min-width: 1150px;

}


thead th {

    background: #f7f9fc;

    color: #4b5563;

    font-size: 12px;

    text-transform: uppercase;

    letter-spacing: 0.4px;

    padding: 15px 14px;

    text-align: center;

    border-bottom:
        1px solid #e5e7eb;

}


tbody td {

    padding: 15px 14px;

    text-align: center;

    border-bottom:
        1px solid #edf0f4;

    font-size: 14px;

}


tbody tr {

    transition: 0.15s;

}


tbody tr:hover {

    background: #f8fbff;

}


/* =====================================================
   PRODUCT
===================================================== */

.product-name {

    text-align: left;

}


.product-name strong {

    color: #172033;

    font-size: 14px;

}


.product-id {

    color: #9aa3b2;

    font-size: 11px;

    margin-top: 4px;

}


/* =====================================================
   CATEGORY
===================================================== */

.category-badge {

    display: inline-block;

    padding:
        6px 11px;

    border-radius: 8px;

    background: #eef3ff;

    color: #2456a6;

    font-size: 12px;

    font-weight: 700;

}


/* =====================================================
   UNIT
===================================================== */

.unit-badge {

    display: inline-block;

    background: #f1f3f5;

    color: #495057;

    padding:
        6px 10px;

    border-radius: 7px;

    font-size: 12px;

    font-weight: 600;

}


/* =====================================================
   PRICE
===================================================== */

.price {

    font-weight: 700;

    white-space: nowrap;

}


.purchase {

    color: #6b7280;

}


.selling {

    color: #198754;

}


/* =====================================================
   STOCK
===================================================== */

.stock-number {

    font-weight: 800;

}


.stock-good {

    color: #198754;

}


.stock-low {

    color: #d97706;

}


.stock-out {

    color: #dc2626;

}


/* =====================================================
   STATUS
===================================================== */

.status {

    display: inline-flex;

    align-items: center;

    gap: 5px;

    padding:
        6px 11px;

    border-radius: 20px;

    font-size: 12px;

    font-weight: 800;

    white-space: nowrap;

}


.status-good {

    background: #e8f8ef;

    color: #157347;

}


.status-low {

    background: #fff3df;

    color: #b45309;

}


.status-out {

    background: #ffe8e8;

    color: #b91c1c;

}


/* =====================================================
   ACTIONS
===================================================== */

.action-buttons {

    display: flex;

    justify-content: center;

    gap: 7px;

}


.action-btn {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    width: 37px;

    height: 37px;

    border-radius: 8px;

    text-decoration: none;

    transition: 0.2s;

    font-size: 15px;

}


.edit-btn {

    background: #eaf1ff;

    color: #2456a6;

}


.edit-btn:hover {

    background: #dbe7ff;

    transform:
        translateY(-2px);

}


.delete-btn {

    background: #ffeaea;

    color: #dc2626;

}


.delete-btn:hover {

    background: #ffdada;

    transform:
        translateY(-2px);

}


/* =====================================================
   EMPTY STATE
===================================================== */

.empty-state {

    padding: 60px 20px !important;

    text-align: center !important;

}


.empty-icon {

    font-size: 50px;

    margin-bottom: 10px;

}


.empty-state h3 {

    margin: 5px 0;

    color: #374151;

}


.empty-state p {

    color: #7b8494;

}


/* =====================================================
   FOOTER
===================================================== */

.footer {

    text-align: center;

    color: #8a93a3;

    font-size: 12px;

    margin-top: 25px;

    padding-bottom: 10px;

}


/* =====================================================
   RESPONSIVE
===================================================== */

@media (max-width: 1100px) {

    .summary-grid {

        grid-template-columns:
            repeat(2, 1fr);

    }

}


@media (max-width: 700px) {

    .page {

        padding: 15px;

    }


    .header {

        padding: 20px;

        flex-direction: column;

        align-items: flex-start;

    }


    .user-box {

        text-align: left;

    }


    .summary-grid {

        grid-template-columns: 1fr;

    }


    .toolbar {

        align-items: stretch;

    }


    .toolbar-left {

        width: 100%;

    }


    .search-box {

        width: 100%;

    }


    .search-box input {

        width: 100%;

    }

}

</style>

</head>


<body>


<div class="page">


<!-- =====================================================
     HEADER
===================================================== -->

<header class="header">


    <div class="hotel-title">


        <div class="hotel-icon">
            🏨
        </div>


        <div>

            <h1>
                Suva Hotel
            </h1>

            <p>
                Inventory Management System
            </p>

        </div>


    </div>


    <div class="user-box">

        <div>
            Logged in as
        </div>


        <div class="user-name">

            <?php

            echo htmlspecialchars(
                $_SESSION["username"] ?? "User"
            );

            ?>

        </div>


        <span class="role-badge">

            <?php

            echo htmlspecialchars(
                ucfirst($role)
            );

            ?>

        </span>

    </div>


</header>



<!-- =====================================================
     PAGE TITLE
===================================================== -->

<div class="page-title">

    <h2>
        📦 Product Inventory
    </h2>

    <p>
        Manage hotel products, stock levels,
        categories and prices.
    </p>

</div>



<!-- =====================================================
     SUMMARY CARDS
===================================================== -->

<div class="summary-grid">


    <div class="summary-card">

        <div class="summary-icon icon-blue">
            📦
        </div>

        <div class="summary-text">

            <span>
                Total Products
            </span>

            <strong>
                <?php
                echo $total_products;
                ?>
            </strong>

        </div>

    </div>



    <div class="summary-card">

        <div class="summary-icon icon-green">
            📊
        </div>

        <div class="summary-text">

            <span>
                Total Stock
            </span>

            <strong>

                <?php

                echo number_format(
                    $total_stock,
                    2
                );

                ?>

            </strong>

        </div>

    </div>



    <div class="summary-card">

        <div class="summary-icon icon-orange">
            ⚠️
        </div>

        <div class="summary-text">

            <span>
                Low Stock
            </span>

            <strong>
                <?php
                echo $low_stock_count;
                ?>
            </strong>

        </div>

    </div>



    <div class="summary-card">

        <div class="summary-icon icon-red">
            🚫
        </div>

        <div class="summary-text">

            <span>
                Out of Stock
            </span>

            <strong>
                <?php
                echo $out_stock_count;
                ?>
            </strong>

        </div>

    </div>


</div>



<!-- =====================================================
     TOOLBAR
===================================================== -->

<div class="toolbar">


    <div class="toolbar-left">


        <a
            href="add.php"
            class="btn add-btn"
        >
            ➕ Add New Product
        </a>



        <?php if ($role === "admin"): ?>


            <a
                href="../../dashboard.php"
                class="btn back-btn"
            >
                🏠 Admin Dashboard
            </a>


        <?php else: ?>


            <a
                href="../../staff_dashboard/staff_dashboard.php"
                class="btn back-btn"
            >
                🏠 Staff Dashboard
            </a>


        <?php endif; ?>


    </div>



    <!-- SEARCH -->

    <div class="search-box">

        <span class="search-icon">
            🔍
        </span>


        <input
            type="text"
            id="searchInput"
            placeholder="Search product, category or unit..."
            onkeyup="searchProducts()"
        >

    </div>


</div>



<!-- =====================================================
     PRODUCT TABLE
===================================================== -->

<div class="table-card">


    <div class="table-header">

        <h3>
            Product List
        </h3>


        <span>

            <?php
            echo $total_products;
            ?>

            products

        </span>

    </div>



    <div class="table-wrapper">


        <table id="productTable">


            <thead>

                <tr>

                    <th>
                        ID
                    </th>

                    <th>
                        Product
                    </th>

                    <th>
                        Category
                    </th>

                    <th>
                        Unit
                    </th>

                    <th>
                        Purchase Price
                    </th>

                    <th>
                        Selling Price
                    </th>

                    <th>
                        Quantity
                    </th>

                    <th>
                        Minimum Stock
                    </th>

                    <th>
                        Stock Status
                    </th>

                    <th>
                        Actions
                    </th>

                </tr>

            </thead>



            <tbody>


            <?php if ($total_products > 0): ?>


                <?php foreach ($products as $row): ?>


                    <?php

                    $id =
                        (int)$row["id"];


                    $product_name =
                        $row["product_name"]
                        ?? "Unnamed Product";


                    $category_name =
                        $row["category_name"]
                        ?? "Uncategorized";


                    $unit =
                        $row["unit"]
                        ?? "-";


                    $purchase_price =
                        (float)(
                            $row["purchase_price"]
                            ?? 0
                        );


                    $selling_price =
                        (float)(
                            $row["selling_price"]
                            ?? 0
                        );


                    $current_stock =
                        (float)(
                            $row["current_stock"]
                            ?? 0
                        );


                    $minimum_stock =
                        (float)(
                            $row["minimum_stock"]
                            ?? 0
                        );


                    /*
                    |--------------------------------------------------------------------------
                    | STOCK STATUS
                    |--------------------------------------------------------------------------
                    */

                    if ($current_stock <= 0) {

                        $status = '
                            <span class="status status-out">
                                🔴 Out of Stock
                            </span>
                        ';

                        $stock_class =
                            "stock-out";

                    }


                    elseif (
                        $current_stock
                        <=
                        $minimum_stock
                    ) {

                        $status = '
                            <span class="status status-low">
                                🟠 Low Stock
                            </span>
                        ';

                        $stock_class =
                            "stock-low";

                    }


                    else {

                        $status = '
                            <span class="status status-good">
                                🟢 In Stock
                            </span>
                        ';

                        $stock_class =
                            "stock-good";

                    }

                    ?>


                    <tr>


                        <!-- ID -->

                        <td>

                            <strong>

                                #
                                <?php
                                echo $id;
                                ?>

                            </strong>

                        </td>



                        <!-- PRODUCT -->

                        <td class="product-name">

                            <strong>

                                <?php

                                echo htmlspecialchars(
                                    $product_name
                                );

                                ?>

                            </strong>


                            <div class="product-id">

                                Product ID:
                                <?php
                                echo $id;
                                ?>

                            </div>

                        </td>



                        <!-- CATEGORY -->

                        <td>

                            <span class="category-badge">

                                <?php

                                echo htmlspecialchars(
                                    $category_name
                                );

                                ?>

                            </span>

                        </td>



                        <!-- UNIT -->

                        <td>

                            <span class="unit-badge">

                                <?php

                                echo htmlspecialchars(
                                    $unit
                                );

                                ?>

                            </span>

                        </td>



                        <!-- PURCHASE PRICE -->

                        <td class="price purchase">

                            Rs.
                            <?php

                            echo number_format(
                                $purchase_price,
                                2
                            );

                            ?>

                        </td>



                        <!-- SELLING PRICE -->

                        <td class="price selling">

                            Rs.
                            <?php

                            echo number_format(
                                $selling_price,
                                2
                            );

                            ?>

                        </td>



                        <!-- CURRENT STOCK -->

                        <td>

                            <span
                                class="
                                    stock-number
                                    <?php
                                    echo $stock_class;
                                    ?>
                                "
                            >

                                <?php

                                echo number_format(
                                    $current_stock,
                                    2
                                );

                                ?>

                            </span>

                        </td>



                        <!-- MINIMUM STOCK -->

                        <td>

                            <?php

                            echo number_format(
                                $minimum_stock,
                                2
                            );

                            ?>

                        </td>



                        <!-- STATUS -->

                        <td>

                            <?php
                            echo $status;
                            ?>

                        </td>



                        <!-- ACTIONS -->

                        <td>

                            <div class="action-buttons">


                                <!-- EDIT -->

                                <a
                                    href="edit.php?id=<?php echo $id; ?>"
                                    class="action-btn edit-btn"
                                    title="Edit Product"
                                >
                                    ✏️
                                </a>



                                <!-- DELETE -->

                                <?php if ($role === "admin"): ?>


                                    <a
                                        href="delete.php?id=<?php echo $id; ?>"
                                        class="action-btn delete-btn"
                                        title="Delete Product"
                                        onclick="return confirm(
                                            'Are you sure you want to delete this product?'
                                        );"
                                    >
                                        🗑️
                                    </a>


                                <?php endif; ?>


                            </div>

                        </td>


                    </tr>


                <?php endforeach; ?>


            <?php else: ?>


                <tr>

                    <td
                        colspan="10"
                        class="empty-state"
                    >

                        <div class="empty-icon">
                            📦
                        </div>


                        <h3>
                            No Products Found
                        </h3>


                        <p>
                            Your inventory is currently empty.
                        </p>


                        <a
                            href="add.php"
                            class="btn add-btn"
                        >
                            ➕ Add Your First Product
                        </a>

                    </td>

                </tr>


            <?php endif; ?>


            </tbody>


        </table>


    </div>


</div>



<!-- =====================================================
     FOOTER
===================================================== -->

<div class="footer">

    ©
    <?php
    echo date("Y");
    ?>

    Suva Hotel ·
    Inventory Management System

</div>


</div>



<!-- =====================================================
     SEARCH JAVASCRIPT
===================================================== -->

<script>

function searchProducts() {

    const input =
        document
            .getElementById("searchInput")
            .value
            .toLowerCase();


    const table =
        document.getElementById(
            "productTable"
        );


    const rows =
        table
            .getElementsByTagName("tbody")[0]
            .getElementsByTagName("tr");


    for (
        let i = 0;
        i < rows.length;
        i++
    ) {

        const rowText =
            rows[i]
                .innerText
                .toLowerCase();


        if (
            rowText.includes(input)
        ) {

            rows[i].style.display =
                "";

        }

        else {

            rows[i].style.display =
                "none";

        }

    }

}

</script>


</body>

</html>
```
