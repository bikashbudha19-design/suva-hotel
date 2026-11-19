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

/* =========================================================
   ORDERS
========================================================= */

$sql = "
    SELECT
        o.id,
        o.customer_name,
        o.order_type,
        o.customer_type,
        o.room_id,
        o.order_period,
        o.subtotal,
        o.vat_amount,
        o.total_amount,
        o.payment_method,
        o.payment_status,
        o.order_status,
        o.created_at,

        COUNT(i.id) AS item_count

    FROM restaurant_orders o

    LEFT JOIN restaurant_order_items i
        ON i.order_id = o.id

    GROUP BY
        o.id

    ORDER BY
        o.id DESC
";

$result =
    pg_query(
        $pgConnection,
        $sql
    );

$orders = [];

if ($result) {

    while ($row = pg_fetch_assoc($result)) {

        $orders[] = $row;

    }
}

/* =========================================================
   STATISTICS
========================================================= */

$totalOrders = count($orders);

$pending = 0;
$preparing = 0;
$completed = 0;
$totalSales = 0;

foreach ($orders as $order) {

    $status =
        strtolower(
            trim($order["order_status"])
        );

    if ($status === "pending") {
        $pending++;
    }

    if ($status === "preparing") {
        $preparing++;
    }

    if ($status === "completed") {
        $completed++;
    }

    $totalSales +=
        (float)$order["total_amount"];
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>Restaurant Orders - Suva Hotel</title>

<style>

* {
    box-sizing: border-box;
}

body {

    margin: 0;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

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

    padding: 25px 35px;
}

.header-inner {

    max-width: 1450px;

    margin: auto;

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 20px;
}

.header h1 {

    margin: 0;
}

.header p {

    margin: 6px 0 0;

    opacity: .85;
}

.actions {

    display: flex;

    gap: 10px;

    flex-wrap: wrap;
}

.actions a {

    text-decoration: none;

    color: white;

    background:
        rgba(255,255,255,.14);

    border:
        1px solid
        rgba(255,255,255,.25);

    padding: 11px 16px;

    border-radius: 10px;

    font-weight: bold;
}

.container {

    max-width: 1450px;

    margin: 25px auto;

    padding: 0 20px;
}

.stats {

    display: grid;

    grid-template-columns:
        repeat(4,1fr);

    gap: 18px;

    margin-bottom: 25px;
}

.stat {

    background: white;

    border-radius: 16px;

    padding: 20px;

    border:
        1px solid #e5e7eb;

    box-shadow:
        0 7px 25px
        rgba(0,0,0,.06);
}

.stat-label {

    color: #64748b;

    font-size: 14px;
}

.stat-value {

    font-size: 28px;

    font-weight: bold;

    margin-top: 8px;

    color: #047857;
}

.table-card {

    background: white;

    border-radius: 18px;

    overflow: hidden;

    border:
        1px solid #e5e7eb;

    box-shadow:
        0 8px 28px
        rgba(15,23,42,.07);
}

.table-header {

    padding: 20px;

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 15px;
}

.table-header h2 {

    margin: 0;
}

.new-order {

    background: #0f766e;

    color: white;

    text-decoration: none;

    padding: 11px 17px;

    border-radius: 9px;

    font-weight: bold;
}

table {

    width: 100%;

    border-collapse: collapse;
}

th {

    background: #f8fafc;

    color: #475569;

    text-align: left;

    padding: 14px;

    font-size: 13px;
}

td {

    padding: 14px;

    border-top:
        1px solid #e5e7eb;

    font-size: 14px;
}

.status {

    display: inline-block;

    padding: 5px 9px;

    border-radius: 999px;

    font-size: 12px;

    font-weight: bold;
}

.pending {

    background: #fef3c7;

    color: #92400e;
}

.preparing {

    background: #dbeafe;

    color: #1e40af;
}

.completed {

    background: #dcfce7;

    color: #166534;
}

.cancelled {

    background: #fee2e2;

    color: #991b1b;
}

.view {

    display: inline-block;

    background: #ecfdf5;

    color: #047857;

    text-decoration: none;

    padding: 7px 10px;

    border-radius: 7px;

    font-weight: bold;
}

.empty {

    padding: 60px;

    text-align: center;

    color: #64748b;
}

@media(max-width:900px) {

    .stats {

        grid-template-columns:
            repeat(2,1fr);
    }

    .table-card {

        overflow-x: auto;
    }

    table {

        min-width: 1000px;
    }
}

@media(max-width:550px) {

    .stats {

        grid-template-columns: 1fr;
    }

    .header-inner {

        flex-direction: column;

        align-items: flex-start;
    }
}

</style>

</head>

<body>

<header class="header">

    <div class="header-inner">

        <div>

            <h1>
                🍽️ Restaurant Orders
            </h1>

            <p>
                Suva Hotel Restaurant Management
            </p>

        </div>

        <div class="actions">

            <a href="add.php">
                + New Order
            </a>

            <a href="../menu.php">
                ← Restaurant
            </a>

        </div>

    </div>

</header>


<div class="container">


<!-- =====================================================
     STATISTICS
===================================================== -->

<div class="stats">

    <div class="stat">

        <div class="stat-label">
            Total Orders
        </div>

        <div class="stat-value">
            <?= $totalOrders ?>
        </div>

    </div>


    <div class="stat">

        <div class="stat-label">
            Pending
        </div>

        <div class="stat-value">
            <?= $pending ?>
        </div>

    </div>


    <div class="stat">

        <div class="stat-label">
            Preparing
        </div>

        <div class="stat-value">
            <?= $preparing ?>
        </div>

    </div>


    <div class="stat">

        <div class="stat-label">
            Total Sales
        </div>

        <div class="stat-value">
            Rs. <?= number_format($totalSales, 2) ?>
        </div>

    </div>

</div>


<!-- =====================================================
     TABLE
===================================================== -->

<div class="table-card">

    <div class="table-header">

        <h2>
            📋 All Restaurant Orders
        </h2>

        <a
            href="add.php"
            class="new-order"
        >
            + Create Order
        </a>

    </div>


    <?php if (!empty($orders)): ?>

    <table>

        <thead>

            <tr>

                <th>
                    Order #
                </th>

                <th>
                    Customer
                </th>

                <th>
                    Type
                </th>

                <th>
                    Items
                </th>

                <th>
                    Total
                </th>

                <th>
                    Payment
                </th>

                <th>
                    Status
                </th>

                <th>
                    Date
                </th>

                <th>
                    Action
                </th>

            </tr>

        </thead>

        <tbody>

        <?php foreach ($orders as $order): ?>

            <?php

            $status =
                strtolower(
                    trim(
                        $order["order_status"]
                    )
                );

            ?>

            <tr>

                <td>

                    <strong>
                        #<?= (int)$order["id"] ?>
                    </strong>

                </td>


                <td>

                    <strong>
                        <?= e($order["customer_name"]) ?>
                    </strong>

                    <br>

                    <small>
                        <?= e($order["customer_type"]) ?>
                    </small>

                </td>


                <td>

                    <?= e($order["order_type"]) ?>

                </td>


                <td>

                    <?= (int)$order["item_count"] ?>

                </td>


                <td>

                    <strong>
                        Rs.
                        <?= number_format(
                            (float)$order["total_amount"],
                            2
                        ) ?>
                    </strong>

                </td>


                <td>

                    <?= e(
                        $order["payment_method"]
                    ) ?>

                    <br>

                    <small>
                        <?= e(
                            $order["payment_status"]
                        ) ?>
                    </small>

                </td>


                <td>

                    <span
                        class="status <?= e($status) ?>"
                    >
                        <?= e(
                            $order["order_status"]
                        ) ?>
                    </span>

                </td>


                <td>

                    <?= e(
                        date(
                            "Y-m-d H:i",
                            strtotime(
                                $order["created_at"]
                            )
                        )
                    ) ?>

                </td>


                <td>

                    <a
                        href="view.php?id=<?= (int)$order["id"] ?>"
                        class="view"
                    >
                        View
                    </a>

                </td>

            </tr>

        <?php endforeach; ?>

        </tbody>

    </table>

    <?php else: ?>

        <div class="empty">

            <div style="font-size:55px;">
                🍽️
            </div>

            <h2>
                No Restaurant Orders
            </h2>

            <p>
                Create your first restaurant order.
            </p>

            <a
                href="add.php"
                class="new-order"
            >
                + Create First Order
            </a>

        </div>

    <?php endif; ?>

</div>

</div>

</body>

</html>