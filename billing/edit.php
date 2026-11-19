```php
<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once "../config/database.php";

$message = "";
$message_type = "";


/* =====================================================
   CHECK BILL ID
===================================================== */

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid Bill ID.");
}

$id = (int)$_GET["id"];


/* =====================================================
   GET BILL
===================================================== */

$sql = "
    SELECT
        id,
        booking_id,
        guest_id,
        invoice_no,
        billing_date,
        room_charge,
        food_charge,
        service_charge,
        discount,
        tax,
        total_amount,
        status
    FROM billing
    WHERE id = $1
    LIMIT 1
";

$result = pg_query_params(
    $pgConnection,
    $sql,
    [$id]
);

if (!$result) {
    die(
        "Database Error: " .
        htmlspecialchars(
            pg_last_error($pgConnection)
        )
    );
}

$bill = pg_fetch_assoc($result);

if (!$bill) {
    die("Bill not found.");
}


/* =====================================================
   GET BOOKINGS
===================================================== */

$booking_sql = "
    SELECT
        bookings.id,
        bookings.guest_id,
        bookings.room_id,
        bookings.check_in,
        bookings.check_out,
        bookings.total_amount,
        guests.full_name,
        rooms.room_number
    FROM bookings

    LEFT JOIN guests
        ON bookings.guest_id = guests.id

    LEFT JOIN rooms
        ON bookings.room_id = rooms.id

    ORDER BY bookings.id DESC
";

$booking_result = pg_query(
    $pgConnection,
    $booking_sql
);

if (!$booking_result) {
    die(
        "Booking Database Error: " .
        htmlspecialchars(
            pg_last_error($pgConnection)
        )
    );
}


/* =====================================================
   CONVERT STORED DISCOUNT AMOUNT TO PERCENTAGE
===================================================== */

$gross_amount =
    (float)$bill["room_charge"]
    + (float)$bill["food_charge"]
    + (float)$bill["service_charge"];


if ($gross_amount > 0) {

    $discount_percent =
        ((float)$bill["discount"] / $gross_amount) * 100;

} else {

    $discount_percent = 0;
}


/* =====================================================
   UPDATE BILL
===================================================== */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $booking_id =
        (int)($_POST["booking_id"] ?? 0);

    $food_charge =
        (float)($_POST["food_charge"] ?? 0);

    $service_charge =
        (float)($_POST["service_charge"] ?? 0);

    $discount_percent =
        (float)($_POST["discount_percent"] ?? 0);

    $status =
        $_POST["status"] ?? "unpaid";


    /* =================================================
       VALIDATION
    ================================================= */

    if ($booking_id <= 0) {

        $message = "Please select a booking.";
        $message_type = "error";

    } elseif ($food_charge < 0) {

        $message = "Food charge cannot be negative.";
        $message_type = "error";

    } elseif ($service_charge < 0) {

        $message = "Service charge cannot be negative.";
        $message_type = "error";

    } elseif (
        $discount_percent < 0 ||
        $discount_percent > 100
    ) {

        $message =
            "Discount must be between 0% and 100%.";

        $message_type = "error";

    } elseif (
        !in_array(
            $status,
            [
                "unpaid",
                "partial",
                "paid",
                "cancelled"
            ],
            true
        )
    ) {

        $message = "Invalid payment status.";
        $message_type = "error";

    } else {


        /* =================================================
           GET SELECTED BOOKING
        ================================================= */

        $booking_sql = "
            SELECT
                guest_id,
                total_amount
            FROM bookings
            WHERE id = $1
            LIMIT 1
        ";

        $booking_result_selected = pg_query_params(
            $pgConnection,
            $booking_sql,
            [$booking_id]
        );

        if (!$booking_result_selected) {

            $message =
                "Database Error: " .
                pg_last_error($pgConnection);

            $message_type = "error";

        } else {

            $booking_data =
                pg_fetch_assoc(
                    $booking_result_selected
                );


            if (!$booking_data) {

                $message = "Booking not found.";
                $message_type = "error";

            } else {


                /* =================================================
                   ROOM CHARGE
                ================================================= */

                $guest_id =
                    (int)$booking_data["guest_id"];

                $room_charge =
                    (float)$booking_data["total_amount"];


                /* =================================================
                   GROSS AMOUNT
                ================================================= */

                $gross_amount =
                    $room_charge
                    + $food_charge
                    + $service_charge;


                /* =================================================
                   DISCOUNT AMOUNT
                ================================================= */

                $discount =
                    ($gross_amount * $discount_percent) / 100;


                /* =================================================
                   AFTER DISCOUNT
                ================================================= */

                $subtotal =
                    $gross_amount - $discount;

                if ($subtotal < 0) {
                    $subtotal = 0;
                }


                /* =================================================
                   VAT 13%
                ================================================= */

                $tax =
                    $subtotal * 0.13;


                /* =================================================
                   FINAL TOTAL
                ================================================= */

                $total_amount =
                    $subtotal + $tax;


                /* =================================================
                   UPDATE BILL
                ================================================= */

                $update_sql = "
                    UPDATE billing
                    SET
                        booking_id = $1,
                        guest_id = $2,
                        room_charge = $3,
                        food_charge = $4,
                        service_charge = $5,
                        discount = $6,
                        tax = $7,
                        total_amount = $8,
                        status = $9
                    WHERE id = $10
                ";


                $update_result = pg_query_params(
                    $pgConnection,
                    $update_sql,
                    [
                        $booking_id,
                        $guest_id,
                        $room_charge,
                        $food_charge,
                        $service_charge,
                        $discount,
                        $tax,
                        $total_amount,
                        $status,
                        $id
                    ]
                );


                if ($update_result) {

                    $message =
                        "Bill updated successfully! "
                        . "Discount: "
                        . number_format(
                            $discount_percent,
                            2
                        )
                        . "% (Rs. "
                        . number_format(
                            $discount,
                            2
                        )
                        . ")"
                        . " | Total: Rs. "
                        . number_format(
                            $total_amount,
                            2
                        );

                    $message_type = "success";


                    /* =================================================
                       UPDATE DISPLAYED VALUES
                    ================================================= */

                    $bill["booking_id"] =
                        $booking_id;

                    $bill["guest_id"] =
                        $guest_id;

                    $bill["room_charge"] =
                        $room_charge;

                    $bill["food_charge"] =
                        $food_charge;

                    $bill["service_charge"] =
                        $service_charge;

                    $bill["discount"] =
                        $discount;

                    $bill["tax"] =
                        $tax;

                    $bill["total_amount"] =
                        $total_amount;

                    $bill["status"] =
                        $status;

                } else {

                    $message =
                        "Error updating bill: "
                        . pg_last_error(
                            $pgConnection
                        );

                    $message_type = "error";
                }
            }
        }
    }
}


/* =====================================================
   RECALCULATE DISPLAYED DISCOUNT %
===================================================== */

$display_gross =
    (float)$bill["room_charge"]
    + (float)$bill["food_charge"]
    + (float)$bill["service_charge"];


if ($display_gross > 0) {

    $display_discount_percent =
        ((float)$bill["discount"] / $display_gross) * 100;

} else {

    $display_discount_percent = 0;
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
    Edit Bill - Suva Hotel
</title>

<style>

body {
    font-family: Arial, sans-serif;
    background: #f4f6f8;
    margin: 0;
    padding: 30px;
}

.container {
    max-width: 750px;
    margin: auto;
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.10);
}

h1 {
    color: #1f3c88;
}

h2 {
    color: #333;
}

label {
    display: block;
    font-weight: bold;
    margin-top: 15px;
}

select,
input {
    width: 100%;
    padding: 11px;
    margin-top: 6px;
    box-sizing: border-box;
    border: 1px solid #ccc;
    border-radius: 5px;
    font-size: 15px;
}

input[readonly] {
    background: #eeeeee;
}

button {
    background: #1f3c88;
    color: white;
    border: none;
    padding: 13px 20px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 16px;
    margin-top: 20px;
}

button:hover {
    background: #162d68;
}

.success {
    background: #e9f7ef;
    color: green;
    padding: 12px;
    border-radius: 6px;
}

.error {
    background: #fdeaea;
    color: red;
    padding: 12px;
    border-radius: 6px;
}

.info {
    background: #eef3ff;
    padding: 15px;
    border-radius: 7px;
    margin-bottom: 20px;
}

.calculation-box {
    margin-top: 20px;
    padding: 18px;
    background: #f7f9fc;
    border: 1px solid #ddd;
    border-radius: 8px;
}

.calculation-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
}

.discount-row {
    color: #d9534f;
}

.total-row {
    border-top: 2px solid #333;
    margin-top: 8px;
    padding-top: 12px;
    font-size: 20px;
    font-weight: bold;
}

.links {
    margin-top: 25px;
}

.links a {
    color: #1f3c88;
    text-decoration: none;
    margin-right: 15px;
}

small {
    color: #666;
}

</style>

</head>


<body>

<div class="container">

<h1>
    🏨 Suva Hotel
</h1>

<h2>
    ✏️ Edit Bill
</h2>


<?php if ($message !== ""): ?>

    <p class="<?php echo htmlspecialchars($message_type); ?>">

        <strong>
            <?php
            echo htmlspecialchars($message);
            ?>
        </strong>

    </p>

<?php endif; ?>


<div class="info">

    <strong>Invoice:</strong>

    <?php
    echo htmlspecialchars(
        $bill["invoice_no"]
    );
    ?>

    <br><br>

    <strong>Billing Date:</strong>

    <?php
    echo htmlspecialchars(
        $bill["billing_date"]
    );
    ?>

</div>


<form method="POST">


<!-- =====================================================
     BOOKING
===================================================== -->

<label for="booking_id">
    Booking
</label>


<select
    name="booking_id"
    id="booking_id"
    required
>

<option value="">
    -- Select Booking --
</option>


<?php while ($booking = pg_fetch_assoc($booking_result)): ?>

    <option
        value="<?php echo (int)$booking["id"]; ?>"
        data-room-charge="<?php
            echo $booking["id"] == $bill["booking_id"]
                ? htmlspecialchars($bill["room_charge"])
                : htmlspecialchars($booking["total_amount"] ?? "0");
        ?>"
        <?php
        if (
            (int)$booking["id"]
            === (int)$bill["booking_id"]
        ) {
            echo "selected";
        }
        ?>
    >

        <?php
        echo "Booking #"
            . (int)$booking["id"]
            . " - "
            . htmlspecialchars(
                $booking["full_name"] ?? "Unknown Guest"
            )
            . " - Room "
            . htmlspecialchars(
                $booking["room_number"] ?? "Unknown"
            )
            . " - "
            . htmlspecialchars(
                $booking["check_in"] ?? ""
            )
            . " to "
            . htmlspecialchars(
                $booking["check_out"] ?? ""
            );
        ?>

    </option>

<?php endwhile; ?>

</select>


<!-- =====================================================
     ROOM CHARGE
===================================================== -->

<label for="room_charge">
    🏨 Room Charge (Rs.)
</label>


<input
    type="number"
    id="room_charge"
    value="<?php
        echo number_format(
            (float)$bill["room_charge"],
            2,
            ".",
            ""
        );
    ?>"
    step="0.01"
    readonly
>


<!-- =====================================================
     FOOD
===================================================== -->

<label for="food_charge">
    🍽️ Food Charge (Rs.)
</label>


<input
    type="number"
    name="food_charge"
    id="food_charge"
    value="<?php
        echo number_format(
            (float)$bill["food_charge"],
            2,
            ".",
            ""
        );
    ?>"
    min="0"
    step="0.01"
>


<!-- =====================================================
     SERVICE
===================================================== -->

<label for="service_charge">
    🛎️ Service Charge (Rs.)
</label>


<input
    type="number"
    name="service_charge"
    id="service_charge"
    value="<?php
        echo number_format(
            (float)$bill["service_charge"],
            2,
            ".",
            ""
        );
    ?>"
    min="0"
    step="0.01"
>


<!-- =====================================================
     DISCOUNT %
===================================================== -->

<label for="discount_percent">
    🎁 Discount (%)
</label>


<input
    type="number"
    name="discount_percent"
    id="discount_percent"
    value="<?php
        echo number_format(
            $display_discount_percent,
            2,
            ".",
            ""
        );
    ?>"
    min="0"
    max="100"
    step="0.01"
>


<small>
    Enter percentage. Example: 10 means 10% discount.
</small>


<!-- =====================================================
     TAX
===================================================== -->

<label for="tax">
    🧾 Tax (13%)
</label>


<input
    type="number"
    id="tax"
    value="<?php
        echo number_format(
            (float)$bill["tax"],
            2,
            ".",
            ""
        );
    ?>"
    step="0.01"
    readonly
>


<!-- =====================================================
     STATUS
===================================================== -->

<label for="status">
    Payment Status
</label>


<select
    name="status"
    id="status"
    required
>

<option
    value="unpaid"
    <?php
    echo $bill["status"] === "unpaid"
        ? "selected"
        : "";
    ?>
>
    Unpaid
</option>


<option
    value="partial"
    <?php
    echo $bill["status"] === "partial"
        ? "selected"
        : "";
    ?>
>
    Partially Paid
</option>


<option
    value="paid"
    <?php
    echo $bill["status"] === "paid"
        ? "selected"
        : "";
    ?>
>
    Paid
</option>


<option
    value="cancelled"
    <?php
    echo $bill["status"] === "cancelled"
        ? "selected"
        : "";
    ?>
>
    Cancelled
</option>

</select>


<!-- =====================================================
     CALCULATION
===================================================== -->

<div class="calculation-box">

<div class="calculation-row">

    <span>
        Gross Amount:
    </span>

    <strong>
        Rs.
        <span id="gross_display">0.00</span>
    </strong>

</div>


<div class="calculation-row discount-row">

    <span>
        Discount
        (<span id="discount_display">0.00</span>%):
    </span>

    <strong>
        - Rs.
        <span id="discount_amount_display">0.00</span>
    </strong>

</div>


<div class="calculation-row">

    <span>
        Amount After Discount:
    </span>

    <strong>
        Rs.
        <span id="subtotal_display">0.00</span>
    </strong>

</div>


<div class="calculation-row">

    <span>
        VAT 13%:
    </span>

    <strong>
        Rs.
        <span id="tax_display">0.00</span>
    </strong>

</div>


<div class="calculation-row total-row">

    <span>
        Total:
    </span>

    <strong>
        Rs.
        <span id="total_display">0.00</span>
    </strong>

</div>

</div>


<button type="submit">
    💾 Update Bill
</button>

</form>


<div class="links">

<a href="view.php">
    ← Back to Billing
</a>

</div>


</div>


<script>

/* =====================================================
   CALCULATE BILL
===================================================== */

function calculateBill() {

    var room =
        parseFloat(
            document.getElementById("room_charge").value
        ) || 0;


    var food =
        parseFloat(
            document.getElementById("food_charge").value
        ) || 0;


    var service =
        parseFloat(
            document.getElementById("service_charge").value
        ) || 0;


    var discountPercent =
        parseFloat(
            document.getElementById("discount_percent").value
        ) || 0;


    if (discountPercent < 0) {

        discountPercent = 0;

        document.getElementById(
            "discount_percent"
        ).value = 0;
    }


    if (discountPercent > 100) {

        discountPercent = 100;

        document.getElementById(
            "discount_percent"
        ).value = 100;
    }


    /* Gross */

    var gross =
        room
        + food
        + service;


    /* Discount */

    var discountAmount =
        gross
        * discountPercent
        / 100;


    /* Subtotal */

    var subtotal =
        gross
        - discountAmount;


    if (subtotal < 0) {
        subtotal = 0;
    }


    /* VAT */

    var tax =
        subtotal
        * 0.13;


    /* Final */

    var total =
        subtotal
        + tax;


    /* Display */

    document.getElementById(
        "gross_display"
    ).innerText =
        gross.toFixed(2);


    document.getElementById(
        "discount_display"
    ).innerText =
        discountPercent.toFixed(2);


    document.getElementById(
        "discount_amount_display"
    ).innerText =
        discountAmount.toFixed(2);


    document.getElementById(
        "subtotal_display"
    ).innerText =
        subtotal.toFixed(2);


    document.getElementById(
        "tax_display"
    ).innerText =
        tax.toFixed(2);


    document.getElementById(
        "total_display"
    ).innerText =
        total.toFixed(2);


    document.getElementById(
        "tax"
    ).value =
        tax.toFixed(2);
}


/* =====================================================
   BOOKING CHANGE
===================================================== */

document.getElementById(
    "booking_id"
).addEventListener(
    "change",
    function () {

        var option =
            this.options[this.selectedIndex];


        if (!this.value) {

            document.getElementById(
                "room_charge"
            ).value = 0;

            calculateBill();

            return;
        }


        var roomCharge =
            option.getAttribute(
                "data-room-charge"
            );


        document.getElementById(
            "room_charge"
        ).value =
            roomCharge || 0;


        calculateBill();
    }
);


/* =====================================================
   FOOD
===================================================== */

document.getElementById(
    "food_charge"
).addEventListener(
    "input",
    calculateBill
);


/* =====================================================
   SERVICE
===================================================== */

document.getElementById(
    "service_charge"
).addEventListener(
    "input",
    calculateBill
);


/* =====================================================
   DISCOUNT
===================================================== */

document.getElementById(
    "discount_percent"
).addEventListener(
    "input",
    calculateBill
);


/* =====================================================
   INITIAL CALCULATION
===================================================== */

calculateBill();

</script>


</body>

</html>
```
