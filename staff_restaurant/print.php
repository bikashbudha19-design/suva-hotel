<?php

session_start();

/*
|--------------------------------------------------------------------------
| STAFF RESTAURANT PRINT
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once "../config/database.php";

/*
|--------------------------------------------------------------------------
| GET ORDER ID
|--------------------------------------------------------------------------
*/

$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

if ($id <= 0) {
    die("Invalid restaurant order ID.");
}

/*
|--------------------------------------------------------------------------
| GET RESTAURANT ORDER
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        id,
        customer_name,
        order_type,
        customer_type,
        room_id,
        order_period,
        subtotal,
        vat_amount,
        total_amount,
        payment_method,
        payment_status,
        order_status,
        created_at
    FROM restaurant_orders
    WHERE id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Order database error: " . htmlspecialchars($conn->error));
}

$stmt->bind_param("i", $id);
$stmt->execute();

$result = $stmt->get_result();
$order = $result->fetch_assoc();

$stmt->close();

if (!$order) {
    die("Restaurant order not found.");
}

/*
|--------------------------------------------------------------------------
| GET ORDER ITEMS
|--------------------------------------------------------------------------
*/

$itemSql = "
    SELECT
        id,
        food_id,
        quantity,
        unit_price,
        total_price
    FROM restaurant_order_items
    WHERE order_id = ?
    ORDER BY id ASC
";

$itemStmt = $conn->prepare($itemSql);

if (!$itemStmt) {
    die("Order item database error: " . htmlspecialchars($conn->error));
}

$itemStmt->bind_param("i", $id);
$itemStmt->execute();

$itemResult = $itemStmt->get_result();

$items = [];

while ($row = $itemResult->fetch_assoc()) {
    $items[] = $row;
}

$itemStmt->close();

/*
|--------------------------------------------------------------------------
| GET FOOD NAMES
|--------------------------------------------------------------------------
|
| Your restaurant_order_items table stores food_id.
| We try common food table names/columns safely.
|
*/

$foodNames = [];

/*
|--------------------------------------------------------------------------
| Try foods table
|--------------------------------------------------------------------------
*/

$foodQuery = $conn->query("
    SELECT id, name
    FROM foods
");

if ($foodQuery) {

    while ($food = $foodQuery->fetch_assoc()) {

        $foodNames[(int)$food["id"]] =
            $food["name"];
    }
}

/*
|--------------------------------------------------------------------------
| If foods table does not exist, use food_id as fallback.
|--------------------------------------------------------------------------
*/

function getFoodName($foodId, $foodNames)
{
    if (isset($foodNames[(int)$foodId])) {
        return $foodNames[(int)$foodId];
    }

    return "Food Item #" . (int)$foodId;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>
    Restaurant Order #<?= (int)$order["id"] ?>
</title>

<style>

* {
    box-sizing: border-box;
}

body {

    margin: 0;

    padding: 30px;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    background: #f3f6f9;

    color: #222;
}

.print-container {

    max-width: 850px;

    margin: auto;

    background: white;

    padding: 35px;

    box-shadow:
        0 5px 25px
        rgba(0,0,0,0.10);
}

.header {

    text-align: center;

    border-bottom:
        2px solid #063970;

    padding-bottom: 20px;

    margin-bottom: 25px;
}

.header h1 {

    margin: 0;

    color: #063970;

    font-size: 30px;
}

.header h2 {

    margin: 7px 0;

    font-size: 20px;
}

.header p {

    margin: 4px 0;

    color: #666;
}

.order-info {

    display: grid;

    grid-template-columns:
        repeat(2, 1fr);

    gap: 10px 30px;

    margin-bottom: 25px;
}

.info-box {

    padding: 10px;

    background: #f8fafc;

    border-radius: 6px;
}

.info-box strong {

    display: inline-block;

    width: 145px;

    color: #063970;
}

table {

    width: 100%;

    border-collapse: collapse;

    margin-top: 15px;
}

th,
td {

    border-bottom:
        1px solid #ddd;

    padding: 12px;

    text-align: left;
}

th {

    background: #063970;

    color: white;
}

td:nth-child(2),
td:nth-child(3),
td:nth-child(4) {

    text-align: right;
}

th:nth-child(2),
th:nth-child(3),
th:nth-child(4) {

    text-align: right;
}

.total-section {

    margin-top: 25px;

    margin-left: auto;

    width: 350px;
}

.total-row {

    display: flex;

    justify-content:
        space-between;

    padding: 8px 0;
}

.grand-total {

    border-top:
        2px solid #063970;

    margin-top: 8px;

    padding-top: 12px;

    font-size: 20px;

    font-weight: bold;

    color: #063970;
}

.status {

    margin-top: 25px;

    padding: 12px;

    background: #eef7ff;

    border-radius: 7px;

    text-align: center;
}

.footer {

    text-align: center;

    margin-top: 35px;

    padding-top: 20px;

    border-top:
        1px solid #ddd;

    color: #777;

    font-size: 13px;
}

.buttons {

    max-width: 850px;

    margin: 20px auto;

    text-align: center;
}

.buttons button,
.buttons a {

    display: inline-block;

    padding: 11px 18px;

    margin: 5px;

    border: none;

    border-radius: 7px;

    text-decoration: none;

    cursor: pointer;

    font-size: 14px;
}

.print-btn {

    background: #063970;

    color: white;
}

.back-btn {

    background: #777;

    color: white;
}

@media print {

    body {

        padding: 0;

        background: white;
    }

    .print-container {

        box-shadow: none;

        max-width: 100%;

        padding: 20px;
    }

    .buttons {

        display: none;
    }
}

@media(max-width:600px) {

    body {
        padding: 10px;
    }

    .print-container {
        padding: 20px;
    }

    .order-info {
        grid-template-columns: 1fr;
    }

    .total-section {
        width: 100%;
    }
}

</style>

</head>

<body>


<div class="buttons">

    <button
        class="print-btn"
        onclick="window.print()"
    >
        🖨️ Print
    </button>

    <a
        class="back-btn"
        href="view_one.php?id=<?= (int)$order["id"] ?>"
    >
        ← Back
    </a>

</div>


<div class="print-container">


    <!-- HEADER -->

    <div class="header">

        <h1>
            🏨 SUVA HOTEL
        </h1>

        <h2>
            Restaurant Order
        </h2>

        <p>
            Restaurant Management System
        </p>

        <p>
            Order #<?= (int)$order["id"] ?>
        </p>

    </div>


    <!-- ORDER INFORMATION -->

    <div class="order-info">


        <div class="info-box">

            <strong>
                Customer:
            </strong>

            <?= htmlspecialchars(
                $order["customer_name"]
            ) ?>

        </div>


        <div class="info-box">

            <strong>
                Order Type:
            </strong>

            <?= htmlspecialchars(
                $order["order_type"]
            ) ?>

        </div>


        <div class="info-box">

            <strong>
                Customer Type:
            </strong>

            <?= htmlspecialchars(
                $order["customer_type"]
            ) ?>

        </div>


        <div class="info-box">

            <strong>
                Order Period:
            </strong>

            <?= htmlspecialchars(
                $order["order_period"]
            ) ?>

        </div>


        <div class="info-box">

            <strong>
                Room:
            </strong>

            <?php if (!empty($order["room_id"])): ?>

                Room #<?= (int)$order["room_id"] ?>

            <?php else: ?>

                -

            <?php endif; ?>

        </div>


        <div class="info-box">

            <strong>
                Date:
            </strong>

            <?= date(
                "d M Y h:i A",
                strtotime($order["created_at"])
            ) ?>

        </div>


        <div class="info-box">

            <strong>
                Payment:
            </strong>

            <?= htmlspecialchars(
                $order["payment_method"] ?? "-"
            ) ?>

        </div>


        <div class="info-box">

            <strong>
                Payment Status:
            </strong>

            <?= htmlspecialchars(
                $order["payment_status"]
            ) ?>

        </div>


    </div>


    <!-- FOOD ITEMS -->

    <h3>
        🍴 Ordered Food Items
    </h3>


    <table>

        <thead>

            <tr>

                <th>
                    Food Item
                </th>

                <th>
                    Qty
                </th>

                <th>
                    Unit Price
                </th>

                <th>
                    Amount
                </th>

            </tr>

        </thead>


        <tbody>

        <?php if (count($items) > 0): ?>

            <?php foreach ($items as $item): ?>

                <tr>

                    <td>

                        <?= htmlspecialchars(
                            getFoodName(
                                $item["food_id"],
                                $foodNames
                            )
                        ) ?>

                    </td>

                    <td>

                        <?= number_format(
                            (float)$item["quantity"],
                            2
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

                        Rs.
                        <?= number_format(
                            (float)$item["total_price"],
                            2
                        ) ?>

                    </td>

                </tr>

            <?php endforeach; ?>

        <?php else: ?>

            <tr>

                <td colspan="4"
                    style="text-align:center;">

                    No food items found.

                </td>

            </tr>

        <?php endif; ?>

        </tbody>

    </table>


    <!-- TOTAL -->

    <div class="total-section">


        <div class="total-row">

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


        <div class="total-row">

            <span>
                VAT
            </span>

            <strong>
                Rs.
                <?= number_format(
                    (float)$order["vat_amount"],
                    2
                ) ?>
            </strong>

        </div>


        <div class="total-row grand-total">

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


    <div class="status">

        <strong>
            Order Status:
        </strong>

        <?= htmlspecialchars(
            $order["order_status"]
        ) ?>

    </div>


    <div class="footer">

        Thank you for choosing
        <strong>Suva Hotel</strong>.

        <br>

        Please visit us again.

    </div>


</div>


</body>

</html>