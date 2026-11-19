<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit;
}

require_once __DIR__ . "/../../config/database.php";

$orderId =
    (int)($_GET["id"] ?? 0);

if ($orderId <= 0) {
    die("Invalid order.");
}

$orderResult =
    pg_query_params(
        $pgConnection,
        "
        SELECT *
        FROM restaurant_orders
        WHERE id = $1
        ",
        [$orderId]
    );

if (
    !$orderResult ||
    pg_num_rows($orderResult) === 0
) {
    die("Order not found.");
}

$order =
    pg_fetch_assoc($orderResult);


$itemResult =
    pg_query_params(
        $pgConnection,
        "
        SELECT
            i.*,
            f.food_name
        FROM restaurant_order_items i

        LEFT JOIN restaurant_foods f
            ON f.id = i.food_id

        WHERE i.order_id = $1

        ORDER BY i.id
        ",
        [$orderId]
    );

$items = [];

while ($itemResult &&
       ($row = pg_fetch_assoc($itemResult))) {

    $items[] = $row;
}

function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        "UTF-8"
    );
}

?>

<!DOCTYPE html>
<html>

<head>

<meta charset="UTF-8">

<title>
Suva Hotel - Order #<?= $orderId ?>
</title>

<style>

body {

    font-family: Arial;

    max-width: 700px;

    margin: 30px auto;

    color: #111;
}

.center {
    text-align: center;
}

hr {
    border: none;

    border-top:
        1px dashed #777;

    margin: 15px 0;
}

table {

    width: 100%;

    border-collapse: collapse;
}

th,
td {

    padding: 7px;

    text-align: left;
}

.total {

    text-align: right;
}

.grand {

    font-size: 20px;

    font-weight: bold;
}

.print {

    margin-bottom: 20px;

    padding: 10px 15px;

    cursor: pointer;
}

@media print {

    .print {
        display: none;
    }

    body {
        margin: 0;
    }

}

</style>

</head>

<body>

<button
    class="print"
    onclick="window.print()"
>
    🖨️ Print
</button>

<div class="center">

    <h1>
        SUVA HOTEL
    </h1>

    <h3>
        Restaurant Order
    </h3>

    <strong>
        Order #<?= $orderId ?>
    </strong>

</div>

<hr>

<p>
    <strong>Customer:</strong>
    <?= e($order["customer_name"]) ?>
</p>

<p>
    <strong>Order Type:</strong>
    <?= e($order["order_type"]) ?>
</p>

<p>
    <strong>Customer Type:</strong>
    <?= e($order["customer_type"]) ?>
</p>

<p>
    <strong>Payment:</strong>
    <?= e($order["payment_method"]) ?>
</p>

<hr>

<table>

<thead>

<tr>

    <th>
        Food
    </th>

    <th>
        Qty
    </th>

    <th>
        Price
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
        <?= e($item["food_name"] ?? "Food") ?>
    </td>

    <td>
        <?= e($item["quantity"]) ?>
    </td>

    <td>
        Rs.
        <?= number_format(
            (float)$item["unit_price"],
            2
        ) ?>
    </td>

    <td>
        Rs.
        <?= number_format(
            (float)$item["total_price"],
            2
        ) ?>
    </td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

<hr>

<div class="total">

<p>
    Subtotal:
    Rs.
    <?= number_format(
        (float)$order["subtotal"],
        2
    ) ?>
</p>

<p>
    VAT 13%:
    Rs.
    <?= number_format(
        (float)$order["vat_amount"],
        2
    ) ?>
</p>

<p class="grand">

    Total:
    Rs.
    <?= number_format(
        (float)$order["total_amount"],
        2
    ) ?>

</p>

</div>

<hr>

<div class="center">

    <p>
        Thank you for dining with us!
    </p>

    <small>
        Suva Hotel Restaurant
    </small>

</div>

</body>

</html>