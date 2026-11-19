<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit;
}

require_once __DIR__ . "/../../config/database.php";

if (!$pgConnection) {
    die("PostgreSQL connection failed.");
}

function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        "UTF-8"
    );
}

$orderId =
    isset($_GET["id"])
        ? (int)$_GET["id"]
        : 0;

if ($orderId <= 0) {
    die("Invalid order ID.");
}


/* =========================================================
   ORDER
========================================================= */

$orderResult =
    pg_query_params(
        $pgConnection,
        "
        SELECT
            *
        FROM restaurant_orders
        WHERE id = $1
        ",
        [$orderId]
    );

if (!$orderResult ||
    pg_num_rows($orderResult) === 0) {

    die("Restaurant order not found.");
}

$order =
    pg_fetch_assoc($orderResult);


/* =========================================================
   ITEMS
========================================================= */

$itemResult =
    pg_query_params(
        $pgConnection,
        "
        SELECT
            i.*,
            f.food_name,
            f.category,
            f.image
        FROM restaurant_order_items i

        LEFT JOIN restaurant_foods f
            ON f.id = i.food_id

        WHERE i.order_id = $1

        ORDER BY i.id ASC
        ",
        [$orderId]
    );

$items = [];

if ($itemResult) {

    while ($row = pg_fetch_assoc($itemResult)) {
        $items[] = $row;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>
Order #<?= $orderId ?> - Suva Hotel
</title>

<style>

* {
    box-sizing: border-box;
}

body {

    margin: 0;

    font-family: Arial, Helvetica, sans-serif;

    background:
        linear-gradient(
            135deg,
            #f0fdfa,
            #f8fafc
        );

    color: #1f2937;
}

.header {

    background:
        linear-gradient(
            135deg,
            #064e3b,
            #0f766e,
            #14b8a6
        );

    color: white;

    padding: 25px;
}

.header-inner {

    max-width: 1000px;

    margin: auto;

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 15px;
}

.header h1 {

    margin: 0;
}

.header a {

    color: white;

    text-decoration: none;

    padding: 10px 15px;

    background:
        rgba(255,255,255,.15);

    border-radius: 9px;
}

.container {

    max-width: 1000px;

    margin: 25px auto;

    padding: 0 15px;
}

.card {

    background: white;

    border-radius: 18px;

    padding: 25px;

    margin-bottom: 20px;

    border:
        1px solid #e5e7eb;

    box-shadow:
        0 8px 28px
        rgba(0,0,0,.07);
}

.info-grid {

    display: grid;

    grid-template-columns:
        repeat(3,1fr);

    gap: 15px;
}

.info {

    padding: 15px;

    background: #f8fafc;

    border-radius: 12px;
}

.info small {

    color: #64748b;

    display: block;

    margin-bottom: 5px;
}

.info strong {

    font-size: 16px;
}

table {

    width: 100%;

    border-collapse: collapse;
}

th {

    background: #f8fafc;

    text-align: left;

    padding: 13px;
}

td {

    padding: 13px;

    border-top:
        1px solid #e5e7eb;
}

.total-box {

    max-width: 400px;

    margin-left: auto;

    margin-top: 20px;
}

.row {

    display: flex;

    justify-content: space-between;

    padding: 7px 0;
}

.grand {

    border-top:
        2px solid #d1d5db;

    margin-top: 8px;

    padding-top: 13px;

    font-size: 21px;

    font-weight: bold;

    color: #047857;
}

.status {

    display: inline-block;

    padding: 7px 12px;

    border-radius: 999px;

    background: #fef3c7;

    color: #92400e;

    font-weight: bold;
}

.buttons {

    display: flex;

    gap: 10px;

    flex-wrap: wrap;

    margin-top: 20px;
}

.btn {

    text-decoration: none;

    border: none;

    padding: 11px 16px;

    border-radius: 9px;

    font-weight: bold;

    cursor: pointer;
}

.green {

    background: #0f766e;

    color: white;
}

.blue {

    background: #2563eb;

    color: white;
}

.gray {

    background: #e5e7eb;

    color: #374151;
}

@media(max-width:700px) {

    .info-grid {

        grid-template-columns: 1fr;
    }

    .header-inner {

        flex-direction: column;

        align-items: flex-start;
    }

    .table-card {

        overflow-x: auto;
    }

    table {

        min-width: 650px;
    }
}

</style>

</head>

<body>

<header class="header">

    <div class="header-inner">

        <div>

            <h1>
                🧾 Order #<?= $orderId ?>
            </h1>

            <p>
                Suva Hotel Restaurant
            </p>

        </div>

        <a href="index.php">
            ← Orders
        </a>

    </div>

</header>


<div class="container">


<!-- =====================================================
     CUSTOMER
===================================================== -->

<div class="card">

    <h2>
        👤 Customer Information
    </h2>

    <div class="info-grid">

        <div class="info">

            <small>
                Customer
            </small>

            <strong>
                <?= e(
                    $order["customer_name"]
                ) ?>
            </strong>

        </div>


        <div class="info">

            <small>
                Customer Type
            </small>

            <strong>
                <?= e(
                    $order["customer_type"]
                ) ?>
            </strong>

        </div>


        <div class="info">

            <small>
                Order Type
            </small>

            <strong>
                <?= e(
                    $order["order_type"]
                ) ?>
            </strong>

        </div>


        <div class="info">

            <small>
                Period
            </small>

            <strong>
                <?= e(
                    $order["order_period"]
                ) ?>
            </strong>

        </div>


        <div class="info">

            <small>
                Payment
            </small>

            <strong>
                <?= e(
                    $order["payment_method"]
                ) ?>
            </strong>

        </div>


        <div class="info">

            <small>
                Status
            </small>

            <strong>

                <span class="status">

                    <?= e(
                        $order["order_status"]
                    ) ?>

                </span>

            </strong>

        </div>

    </div>

</div>


<!-- =====================================================
     ITEMS
===================================================== -->

<div class="card">

    <h2>
        🍽️ Ordered Food
    </h2>

    <table>

        <thead>

            <tr>

                <th>
                    Food
                </th>

                <th>
                    Category
                </th>

                <th>
                    Qty
                </th>

                <th>
                    Unit Price
                </th>

                <th>
                    Total
                </th>

            </tr>

        </thead>

        <tbody>

        <?php foreach ($items as $item): ?>

            <tr>

                <td>

                    <strong>
                        <?= e(
                            $item["food_name"] ??
                            "Food"
                        ) ?>
                    </strong>

                </td>

                <td>
                    <?= e(
                        $item["category"] ?? ""
                    ) ?>
                </td>

                <td>
                    <?= e(
                        $item["quantity"]
                    ) ?>
                </td>

                <td>
                    Rs.
                    <?= number_format(
                        (float)$item["unit_price"],
                        2
                    ) ?>
                </td>

                <td>

                    <strong>

                        Rs.
                        <?= number_format(
                            (float)$item["total_price"],
                            2
                        ) ?>

                    </strong>

                </td>

            </tr>

        <?php endforeach; ?>

        </tbody>

    </table>


    <div class="total-box">

        <div class="row">

            <span>
                Subtotal
            </span>

            <strong>
                Rs.
                <?= number_format(
                    (float)$order["subtotal"],
                    2
                ) ?>
            </strong>

        </div>


        <div class="row">

            <span>
                VAT (13%)
            </span>

            <strong>
                Rs.
                <?= number_format(
                    (float)$order["vat_amount"],
                    2
                ) ?>
            </strong>

        </div>


        <div class="row grand">

            <span>
                Total
            </span>

            <strong>
                Rs.
                <?= number_format(
                    (float)$order["total_amount"],
                    2
                ) ?>
            </strong>

        </div>

    </div>


    <div class="buttons">

        <a
            href="add.php"
            class="btn green"
        >
            + New Order
        </a>


        <a
            href="print.php?id=<?= $orderId ?>"
            target="_blank"
            class="btn blue"
        >
            🖨️ Print Receipt
        </a>


        <a
            href="status.php?id=<?= $orderId ?>"
            class="btn gray"
        >
            🔄 Update Status
        </a>

    </div>

</div>

</div>

</body>

</html>