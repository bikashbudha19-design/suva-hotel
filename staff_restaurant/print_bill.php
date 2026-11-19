<?php

session_start();

/*
|--------------------------------------------------------------------------
| STAFF RESTAURANT BILL
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
| GET ORDER
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
    die(
        "Order database error: " .
        htmlspecialchars($conn->error)
    );
}

$stmt->bind_param("i", $id);

if (!$stmt->execute()) {
    die(
        "Order execution error: " .
        htmlspecialchars($stmt->error)
    );
}

$result = $stmt->get_result();

$order = $result->fetch_assoc();

$stmt->close();

if (!$order) {
    die("Restaurant order not found.");
}

/*
|--------------------------------------------------------------------------
| GET ITEMS
|--------------------------------------------------------------------------
*/

$itemSql = "
    SELECT
        id,
        order_id,
        food_id,
        quantity,
        unit_price,
        total_price,
        created_at
    FROM restaurant_order_items
    WHERE order_id = ?
    ORDER BY id ASC
";

$itemStmt = $conn->prepare($itemSql);

if (!$itemStmt) {
    die(
        "Order item database error: " .
        htmlspecialchars($conn->error)
    );
}

$itemStmt->bind_param("i", $id);

if (!$itemStmt->execute()) {
    die(
        "Order item execution error: " .
        htmlspecialchars($itemStmt->error)
    );
}

$itemResult = $itemStmt->get_result();

$items = [];

while ($row = $itemResult->fetch_assoc()) {

    $items[] = $row;
}

$itemStmt->close();

/*
|--------------------------------------------------------------------------
| FOOD NAMES
|--------------------------------------------------------------------------
*/

$foodNames = [];

/*
|--------------------------------------------------------------------------
| Try foods table
|--------------------------------------------------------------------------
*/

$foodResult = $conn->query("
    SELECT id, name
    FROM foods
");

if ($foodResult) {

    while ($food = $foodResult->fetch_assoc()) {

        $foodNames[(int)$food["id"]] =
            $food["name"];
    }
}

/*
|--------------------------------------------------------------------------
| FOOD NAME FUNCTION
|--------------------------------------------------------------------------
*/

function restaurantFoodName(
    $foodId,
    $foodNames
) {

    if (
        isset(
            $foodNames[(int)$foodId]
        )
    ) {

        return $foodNames[(int)$foodId];
    }

    return "Food Item #" .
        (int)$foodId;
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
    Suva Hotel - Restaurant Bill
</title>


<style>

* {

    box-sizing:
        border-box;
}

body {

    margin: 0;

    background:
        #e9eef3;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    color: #222;

    padding: 30px;
}

.bill {

    max-width:
        750px;

    margin:
        auto;

    background:
        white;

    padding:
        40px;

    box-shadow:
        0 5px 30px
        rgba(0,0,0,0.12);
}

.hotel {

    text-align:
        center;

    border-bottom:
        3px solid #063970;

    padding-bottom:
        20px;

    margin-bottom:
        20px;
}

.hotel h1 {

    margin:
        0;

    color:
        #063970;

    font-size:
        32px;
}

.hotel h2 {

    margin:
        7px 0;

    font-size:
        21px;
}

.hotel p {

    margin:
        4px 0;

    color:
        #666;
}

.bill-info {

    display:
        grid;

    grid-template-columns:
        1fr 1fr;

    gap:
        10px;

    margin-bottom:
        25px;
}

.info {

    padding:
        8px 0;
}

.info strong {

    color:
        #063970;
}

table {

    width:
        100%;

    border-collapse:
        collapse;

    margin-top:
        15px;
}

th {

    background:
        #063970;

    color:
        white;

    padding:
        12px;

    font-size:
        14px;
}

td {

    padding:
        12px;

    border-bottom:
        1px solid #ddd;

    font-size:
        14px;
}

.text-right {

    text-align:
        right;
}

.summary {

    width:
        330px;

    margin-left:
        auto;

    margin-top:
        25px;
}

.summary-row {

    display:
        flex;

    justify-content:
        space-between;

    padding:
        7px 0;
}

.grand {

    border-top:
        2px solid #063970;

    margin-top:
        8px;

    padding-top:
        12px;

    font-size:
        21px;

    font-weight:
        bold;

    color:
        #063970;
}

.payment {

    margin-top:
        25px;

    padding:
        15px;

    border:
        1px solid #ddd;

    border-radius:
        8px;

    background:
        #f8fafc;
}

.thankyou {

    text-align:
        center;

    margin-top:
        35px;

    padding-top:
        20px;

    border-top:
        1px solid #ddd;

    color:
        #666;

    font-size:
        13px;
}

.actions {

    max-width:
        750px;

    margin:
        20px auto;

    text-align:
        center;
}

.actions button,
.actions a {

    display:
        inline-block;

    padding:
        11px 20px;

    margin:
        4px;

    border:
        none;

    border-radius:
        6px;

    text-decoration:
        none;

    cursor:
        pointer;

    font-size:
        14px;
}

.print {

    background:
        #063970;

    color:
        white;
}

.back {

    background:
        #666;

    color:
        white;
}

@media print {

    body {

        padding:
            0;

        background:
            white;
    }

    .bill {

        max-width:
            100%;

        box-shadow:
            none;

        padding:
            20px;
    }

    .actions {

        display:
            none;
    }
}

@media(max-width:600px) {

    body {

        padding:
            10px;
    }

    .bill {

        padding:
            20px;
    }

    .bill-info {

        grid-template-columns:
            1fr;
    }

    .summary {

        width:
            100%;
    }
}

</style>

</head>


<body>


<div class="actions">

    <button
        class="print"
        onclick="window.print()"
    >
        🖨️ Print Bill
    </button>

    <a
        class="back"
        href="view_one.php?id=<?= (int)$order["id"] ?>"
    >
        ← Back to Order
    </a>

</div>


<div class="bill">


    <!-- HOTEL HEADER -->

    <div class="hotel">

        <h1>
            🏨 SUVA HOTEL
        </h1>

        <h2>
            RESTAURANT BILL
        </h2>

        <p>
            Restaurant Management System
        </p>

    </div>


    <!-- BILL INFORMATION -->

    <div class="bill-info">


        <div class="info">

            <strong>
                Bill / Order No:
            </strong>

            #<?= (int)$order["id"] ?>

        </div>


        <div class="info">

            <strong>
                Date:
            </strong>

            <?= date(
                "d M Y",
                strtotime(
                    $order["created_at"]
                )
            ) ?>

        </div>


        <div class="info">

            <strong>
                Customer:
            </strong>

            <?= htmlspecialchars(
                $order["customer_name"]
            ) ?>

        </div>


        <div class="info">

            <strong>
                Time:
            </strong>

            <?= date(
                "h:i A",
                strtotime(
                    $order["created_at"]
                )
            ) ?>

        </div>


        <div class="info">

            <strong>
                Customer Type:
            </strong>

            <?= htmlspecialchars(
                $order["customer_type"]
            ) ?>

        </div>


        <div class="info">

            <strong>
                Order Type:
            </strong>

            <?= htmlspecialchars(
                $order["order_type"]
            ) ?>

        </div>


        <div class="info">

            <strong>
                Order Period:
            </strong>

            <?= htmlspecialchars(
                $order["order_period"]
            ) ?>

        </div>


        <div class="info">

            <strong>
                Room:
            </strong>

            <?php if (
                !empty($order["room_id"])
            ): ?>

                Room #<?= (int)$order["room_id"] ?>

            <?php else: ?>

                -

            <?php endif; ?>

        </div>


    </div>


    <!-- ITEMS -->

    <table>

        <thead>

            <tr>

                <th>
                    #
                </th>

                <th>
                    Food Item
                </th>

                <th class="text-right">
                    Qty
                </th>

                <th class="text-right">
                    Price
                </th>

                <th class="text-right">
                    Amount
                </th>

            </tr>

        </thead>


        <tbody>

        <?php if (
            count($items) > 0
        ): ?>

            <?php

            $counter = 1;

            foreach (
                $items as $item
            ):

            ?>

            <tr>

                <td>
                    <?= $counter++ ?>
                </td>

                <td>

                    <?= htmlspecialchars(
                        restaurantFoodName(
                            $item["food_id"],
                            $foodNames
                        )
                    ) ?>

                </td>

                <td class="text-right">

                    <?= number_format(
                        (float)$item["quantity"],
                        2
                    ) ?>

                </td>

                <td class="text-right">

                    Rs.
                    <?= number_format(
                        (float)$item["unit_price"],
                        2
                    ) ?>

                </td>

                <td class="text-right">

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

                <td
                    colspan="5"
                    style="text-align:center;"
                >

                    No food items found.

                </td>

            </tr>

        <?php endif; ?>

        </tbody>

    </table>


    <!-- BILL SUMMARY -->

    <div class="summary">


        <div class="summary-row">

            <span>
                Subtotal:
            </span>

            <strong>

                Rs.
                <?= number_format(
                    (float)$order["subtotal"],
                    2
                ) ?>

            </strong>

        </div>


        <div class="summary-row">

            <span>
                VAT:
            </span>

            <strong>

                Rs.
                <?= number_format(
                    (float)$order["vat_amount"],
                    2
                ) ?>

            </strong>

        </div>


        <div class="summary-row grand">

            <span>
                GRAND TOTAL:
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


    <!-- PAYMENT -->

    <div class="payment">

        <strong>
            Payment Method:
        </strong>

        <?= htmlspecialchars(
            $order["payment_method"] ?? "-"
        ) ?>

        <br><br>

        <strong>
            Payment Status:
        </strong>

        <?= htmlspecialchars(
            $order["payment_status"]
        ) ?>

        <br><br>

        <strong>
            Order Status:
        </strong>

        <?= htmlspecialchars(
            $order["order_status"]
        ) ?>

    </div>


    <!-- FOOTER -->

    <div class="thankyou">

        <strong>
            Thank you for dining with Suva Hotel!
        </strong>

        <br><br>

        We hope to serve you again.

    </div>


</div>


</body>

</html>