<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once "../config/database.php";

/*
=========================================================
GET ORDER ID
=========================================================
*/

$order_id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

if ($order_id <= 0) {
    die("Invalid restaurant order ID.");
}


/*
=========================================================
GET RESTAURANT ORDER
=========================================================
*/

$sql = "
    SELECT
        ro.id,
        ro.customer_name,
        ro.order_type,
        ro.customer_type,
        ro.room_id,
        ro.order_period,
        ro.subtotal,
        ro.vat_amount,
        ro.total_amount,
        ro.payment_method,
        ro.payment_status,
        ro.order_status,
        ro.created_at
    FROM restaurant_orders ro
    WHERE ro.id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Order database error: " . $conn->error);
}

$stmt->bind_param("i", $order_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Restaurant order not found.");
}

$order = $result->fetch_assoc();

$stmt->close();


/*
=========================================================
GET ORDER ITEMS

IMPORTANT:
restaurant_order_items uses:
food_id
quantity
unit_price
total_price
=========================================================
*/

$item_sql = "
    SELECT
        roi.id,
        roi.order_id,
        roi.food_id,
        roi.quantity,
        roi.unit_price,
        roi.total_price
    FROM restaurant_order_items roi
    WHERE roi.order_id = ?
    ORDER BY roi.id ASC
";

$item_stmt = $conn->prepare($item_sql);

if (!$item_stmt) {
    die("Order item database error: " . $conn->error);
}

$item_stmt->bind_param("i", $order_id);
$item_stmt->execute();

$item_result = $item_stmt->get_result();

$items = [];

while ($row = $item_result->fetch_assoc()) {
    $items[] = $row;
}

$item_stmt->close();


/*
=========================================================
GET FOOD NAMES

Since food_id points to a food table, we try to find
the food name safely.

If your system does not have a food table, the code
will still display "Food #ID".
=========================================================
*/

$food_names = [];


/*
---------------------------------------------------------
FIRST TRY inventory table
---------------------------------------------------------
*/

$food_sql = "
    SELECT id, name
    FROM inventory
";

$food_result = $conn->query($food_sql);

if ($food_result) {

    while ($food = $food_result->fetch_assoc()) {

        $food_names[(int)$food["id"]] =
            $food["name"];
    }
}


/*
=========================================================
FORMAT FUNCTIONS
=========================================================
*/

function money($amount)
{
    return "Rs. " . number_format(
        (float)$amount,
        2
    );
}


function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        "UTF-8"
    );
}


/*
=========================================================
STATUS CLASS
=========================================================
*/

$order_status = strtolower(
    $order["order_status"]
);

$payment_status = strtolower(
    $order["payment_status"]
);

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
    Restaurant Order #<?= e($order["id"]) ?>
    - Suva Hotel
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

    background:
        linear-gradient(
            135deg,
            #eef7fb,
            #dff1f7
        );

    min-height: 100vh;

    color: #243447;

}


/* =========================================
   HEADER
========================================= */

.header {

    background:
        linear-gradient(
            135deg,
            #063970,
            #087ea4
        );

    color: white;

    padding: 22px 35px;

    box-shadow:
        0 5px 20px
        rgba(0,0,0,0.15);

}

.header-inner {

    max-width: 1100px;

    margin: auto;

}

.hotel {

    font-size: 14px;

    opacity: 0.85;

    margin-bottom: 5px;

}

.header h1 {

    font-size: 28px;

}


/* =========================================
   CONTAINER
========================================= */

.container {

    max-width: 1100px;

    margin: 30px auto;

    padding: 0 20px;

}


/* =========================================
   ACTIONS
========================================= */

.actions {

    display: flex;

    gap: 10px;

    flex-wrap: wrap;

    margin-bottom: 20px;

}

.btn {

    display: inline-block;

    text-decoration: none;

    border: none;

    padding: 11px 18px;

    border-radius: 8px;

    font-size: 14px;

    cursor: pointer;

    font-weight: bold;

}

.btn-back {

    background: #ffffff;

    color: #063970;

    box-shadow:
        0 3px 10px
        rgba(0,0,0,0.08);

}

.btn-edit {

    background: #f39c12;

    color: white;

}

.btn-print {

    background: #087ea4;

    color: white;

}

.btn-bill {

    background: #16a085;

    color: white;

}


/* =========================================
   MAIN CARD
========================================= */

.card {

    background: white;

    border-radius: 16px;

    padding: 28px;

    margin-bottom: 22px;

    box-shadow:
        0 5px 20px
        rgba(0,0,0,0.08);

}


/* =========================================
   ORDER TOP
========================================= */

.order-top {

    display: flex;

    justify-content:
        space-between;

    align-items: flex-start;

    gap: 20px;

    margin-bottom: 25px;

}

.order-number {

    font-size: 24px;

    color: #063970;

    font-weight: bold;

}

.date {

    color: #718096;

    font-size: 13px;

    margin-top: 6px;

}


/* =========================================
   STATUS
========================================= */

.status {

    padding: 8px 14px;

    border-radius: 20px;

    font-size: 13px;

    font-weight: bold;

    display: inline-block;

}

.status-pending {

    background: #fff3cd;

    color: #856404;

}

.status-completed {

    background: #d4edda;

    color: #155724;

}

.status-cancelled {

    background: #f8d7da;

    color: #721c24;

}


/* =========================================
   INFORMATION GRID
========================================= */

.info-grid {

    display: grid;

    grid-template-columns:
        repeat(
            auto-fit,
            minmax(220px, 1fr)
        );

    gap: 18px;

}

.info-box {

    background: #f7fafc;

    border-radius: 10px;

    padding: 15px;

}

.info-label {

    font-size: 12px;

    color: #718096;

    margin-bottom: 6px;

}

.info-value {

    font-size: 16px;

    font-weight: bold;

    color: #243447;

}


/* =========================================
   SECTION TITLE
========================================= */

.section-title {

    color: #063970;

    font-size: 20px;

    margin-bottom: 18px;

}


/* =========================================
   TABLE
========================================= */

.table-wrapper {

    overflow-x: auto;

}

table {

    width: 100%;

    border-collapse: collapse;

}

th {

    background: #063970;

    color: white;

    padding: 13px;

    text-align: left;

    font-size: 13px;

}

td {

    padding: 13px;

    border-bottom:
        1px solid #e2e8f0;

    font-size: 14px;

}

tr:hover {

    background: #f8fafc;

}

.text-right {

    text-align: right;

}


/* =========================================
   TOTAL BOX
========================================= */

.total-box {

    margin-top: 20px;

    max-width: 420px;

    margin-left: auto;

}

.total-row {

    display: flex;

    justify-content:
        space-between;

    padding: 10px 0;

    border-bottom:
        1px solid #e2e8f0;

}

.total-row strong {

    color: #063970;

}

.grand-total {

    font-size: 21px;

    font-weight: bold;

    color: #087ea4;

    padding-top: 15px;

}


/* =========================================
   EMPTY
========================================= */

.empty {

    text-align: center;

    padding: 30px;

    color: #718096;

    background: #f8fafc;

    border-radius: 10px;

}


/* =========================================
   PAYMENT
========================================= */

.payment-badge {

    display: inline-block;

    padding: 7px 12px;

    border-radius: 15px;

    background: #e0f2fe;

    color: #0369a1;

    font-size: 12px;

    font-weight: bold;

}


/* =========================================
   FOOTER
========================================= */

footer {

    text-align: center;

    color: #718096;

    font-size: 12px;

    padding: 25px;

}


/* =========================================
   MOBILE
========================================= */

@media (max-width: 650px) {

    .header {

        padding: 20px;

    }

    .header h1 {

        font-size: 22px;

    }

    .container {

        margin-top: 20px;

    }

    .card {

        padding: 20px;

    }

    .order-top {

        flex-direction: column;

    }

}

</style>

</head>


<body>


<header class="header">

<div class="header-inner">

    <div class="hotel">
        🍽️ SUVA HOTEL
    </div>

    <h1>
        Restaurant Order Details
    </h1>

</div>

</header>


<main class="container">


<!-- =========================================
     ACTION BUTTONS
========================================= -->

<div class="actions">

    <a
        href="view.php"
        class="btn btn-back"
    >
        ← Restaurant Orders
    </a>


    <a
        href="edit.php?id=<?= (int)$order["id"] ?>"
        class="btn btn-edit"
    >
        ✏️ Edit
    </a>


    <a
        href="print.php?id=<?= (int)$order["id"] ?>"
        target="_blank"
        class="btn btn-print"
    >
        🖨️ Print Order
    </a>


    <a
        href="print_bill.php?id=<?= (int)$order["id"] ?>"
        target="_blank"
        class="btn btn-bill"
    >
        🧾 Print Bill
    </a>

</div>


<!-- =========================================
     ORDER INFORMATION
========================================= -->

<div class="card">

    <div class="order-top">

        <div>

            <div class="order-number">

                🍽️ Restaurant Order
                #<?= (int)$order["id"] ?>

            </div>

            <div class="date">

                Created:
                <?= date(
                    "d M Y h:i A",
                    strtotime($order["created_at"])
                ) ?>

            </div>

        </div>


        <div>

            <?php

            if (
                $order_status === "completed"
            ) {

                $status_class =
                    "status-completed";

                $status_icon = "🟢";

            } elseif (
                $order_status === "cancelled"
            ) {

                $status_class =
                    "status-cancelled";

                $status_icon = "🔴";

            } else {

                $status_class =
                    "status-pending";

                $status_icon = "🟡";

            }

            ?>

            <span
                class="status <?= $status_class ?>"
            >

                <?= $status_icon ?>

                <?= e(
                    $order["order_status"]
                ) ?>

            </span>

        </div>

    </div>


    <div class="info-grid">


        <div class="info-box">

            <div class="info-label">
                Customer Name
            </div>

            <div class="info-value">
                👤 <?= e(
                    $order["customer_name"]
                ) ?>
            </div>

        </div>


        <div class="info-box">

            <div class="info-label">
                Customer Type
            </div>

            <div class="info-value">
                <?= e(
                    $order["customer_type"]
                ) ?>
            </div>

        </div>


        <div class="info-box">

            <div class="info-label">
                Order Type
            </div>

            <div class="info-value">
                <?= e(
                    $order["order_type"]
                ) ?>
            </div>

        </div>


        <div class="info-box">

            <div class="info-label">
                Order Period
            </div>

            <div class="info-value">
                <?= e(
                    $order["order_period"]
                ) ?>
            </div>

        </div>


        <div class="info-box">

            <div class="info-label">
                Room ID
            </div>

            <div class="info-value">

                <?php

                if (
                    !empty($order["room_id"])
                ) {

                    echo "🏨 Room #"
                        . (int)$order["room_id"];

                } else {

                    echo "-";

                }

                ?>

            </div>

        </div>


        <div class="info-box">

            <div class="info-label">
                Payment Method
            </div>

            <div class="info-value">

                <?php

                if (
                    !empty($order["payment_method"])
                ) {

                ?>

                    <span class="payment-badge">

                        <?= e(
                            $order["payment_method"]
                        ) ?>

                    </span>

                <?php

                } else {

                    echo "-";

                }

                ?>

            </div>

        </div>


        <div class="info-box">

            <div class="info-label">
                Payment Status
            </div>

            <div class="info-value">

                <?= e(
                    $order["payment_status"]
                ) ?>

            </div>

        </div>


    </div>

</div>


<!-- =========================================
     FOOD ITEMS
========================================= -->

<div class="card">

    <h2 class="section-title">
        🍴 Ordered Food Items
    </h2>


    <?php if (count($items) > 0): ?>


        <div class="table-wrapper">

        <table>

            <thead>

                <tr>

                    <th>
                        #
                    </th>

                    <th>
                        Food Item
                    </th>

                    <th>
                        Quantity
                    </th>

                    <th>
                        Unit Price
                    </th>

                    <th class="text-right">
                        Total
                    </th>

                </tr>

            </thead>


            <tbody>


            <?php

            $counter = 1;

            foreach ($items as $item):

                $food_id =
                    (int)$item["food_id"];

                if (
                    isset(
                        $food_names[$food_id]
                    )
                ) {

                    $food_name =
                        $food_names[$food_id];

                } else {

                    $food_name =
                        "Food #" . $food_id;

                }

            ?>


                <tr>

                    <td>
                        <?= $counter++ ?>
                    </td>


                    <td>

                        <strong>
                            <?= e(
                                $food_name
                            ) ?>
                        </strong>

                    </td>


                    <td>
                        <?= number_format(
                            (float)$item["quantity"],
                            2
                        ) ?>
                    </td>


                    <td>
                        <?= money(
                            $item["unit_price"]
                        ) ?>
                    </td>


                    <td class="text-right">

                        <strong>

                            <?= money(
                                $item["total_price"]
                            ) ?>

                        </strong>

                    </td>

                </tr>


            <?php endforeach; ?>


            </tbody>

        </table>

        </div>


    <?php else: ?>


        <div class="empty">

            🍽️ No food items found
            for this restaurant order.

        </div>


    <?php endif; ?>


<!-- =========================================
     TOTAL
========================================= -->

<div class="total-box">


    <div class="total-row">

        <span>
            Subtotal
        </span>

        <strong>
            <?= money(
                $order["subtotal"]
            ) ?>
        </strong>

    </div>


    <div class="total-row">

        <span>
            VAT
        </span>

        <strong>
            <?= money(
                $order["vat_amount"]
            ) ?>
        </strong>

    </div>


    <div class="total-row grand-total">

        <span>
            Total Amount
        </span>

        <span>

            <?= money(
                $order["total_amount"]
            ) ?>

        </span>

    </div>


</div>


</div>


<!-- =========================================
     BILL BUTTON
========================================= -->

<div class="card">

    <h2 class="section-title">
        🧾 Restaurant Bill
    </h2>

    <p
        style="
        color:#718096;
        margin-bottom:18px;
        "
    >

        Generate a printable restaurant bill
        for this order.

    </p>


    <a
        href="print_bill.php?id=<?= (int)$order["id"] ?>"
        target="_blank"
        class="btn btn-bill"
    >

        🧾 View & Print Restaurant Bill

    </a>

</div>


</main>


<footer>

    © <?= date("Y") ?>
    Suva Hotel Management System

    <br>

    Restaurant Management

</footer>


</body>

</html>