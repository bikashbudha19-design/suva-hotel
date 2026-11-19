
<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once "../config/database.php";

$role = $_SESSION["role"] ?? "";

$message = "";
$message_type = "";

/* =====================================================
   GET GUESTS - POSTGRESQL
===================================================== */

$guest_sql = "
    SELECT id, full_name
    FROM guests
    ORDER BY full_name ASC
";

$guest_result = pg_query($pgConnection, $guest_sql);

if (!$guest_result) {
    die(
        "Guest Database Error: " .
        pg_last_error($pgConnection)
    );
}

/* =====================================================
   GET ROOMS - POSTGRESQL
===================================================== */

$room_sql = "
    SELECT
        id,
        room_number,
        room_type,
        price_per_night,
        status
    FROM rooms
    WHERE status = 'available'
    ORDER BY room_number ASC
";

$room_result = pg_query($pgConnection, $room_sql);

if (!$room_result) {
    die(
        "Room Database Error: " .
        pg_last_error($pgConnection)
    );
}

/* =====================================================
   CREATE BOOKING
===================================================== */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $guest_id = (int)($_POST["guest_id"] ?? 0);
    $room_id = (int)($_POST["room_id"] ?? 0);

    $check_in = $_POST["check_in"] ?? "";
    $check_out = $_POST["check_out"] ?? "";

    $adults = (int)($_POST["adults"] ?? 1);
    $children = (int)($_POST["children"] ?? 0);

    /* =================================================
       VALIDATION
    ================================================= */

    if ($guest_id <= 0) {

        $message = "Please select a guest.";
        $message_type = "error";

    } elseif ($room_id <= 0) {

        $message = "Please select a room.";
        $message_type = "error";

    } elseif ($check_in === "" || $check_out === "") {

        $message =
            "Please select check-in and check-out dates.";

        $message_type = "error";

    } elseif ($check_out <= $check_in) {

        $message =
            "Check-out date must be after check-in date.";

        $message_type = "error";

    } elseif ($adults < 1) {

        $message =
            "At least one adult is required.";

        $message_type = "error";

    } elseif ($children < 0) {

        $message =
            "Children cannot be negative.";

        $message_type = "error";

    } else {

        /* =============================================
           CHECK GUEST EXISTS
        ============================================= */

        $guest_check_sql = "
            SELECT id
            FROM guests
            WHERE id = $1
        ";

        $guest_check_result = pg_query_params(
            $pgConnection,
            $guest_check_sql,
            [$guest_id]
        );

        if (!$guest_check_result) {

            die(
                "Guest Check Error: " .
                pg_last_error($pgConnection)
            );
        }

        $guest_exists =
            pg_fetch_assoc($guest_check_result);

        if (!$guest_exists) {

            $message = "Selected guest does not exist.";
            $message_type = "error";

        } else {

            /* =========================================
               CHECK ROOM EXISTS
            ========================================= */

            $room_sql_check = "
                SELECT
                    room_number,
                    room_type,
                    price_per_night,
                    status
                FROM rooms
                WHERE id = $1
            ";

            $room_check_result = pg_query_params(
                $pgConnection,
                $room_sql_check,
                [$room_id]
            );

            if (!$room_check_result) {

                die(
                    "Room Database Error: " .
                    pg_last_error($pgConnection)
                );
            }

            $room_data =
                pg_fetch_assoc($room_check_result);


            if (!$room_data) {

                $message =
                    "Selected room does not exist.";

                $message_type = "error";

            } elseif (
                strtolower($room_data["status"]) !== "available"
            ) {

                $message =
                    "This room is currently not available.";

                $message_type = "error";

            } else {

                /* =====================================
                   CHECK OVERLAPPING BOOKINGS
                ===================================== */

                $overlap_sql = "
                    SELECT id
                    FROM bookings
                    WHERE room_id = $1
                      AND LOWER(status) != 'cancelled'
                      AND check_in < $2
                      AND check_out > $3
                    LIMIT 1
                ";

                $overlap_result = pg_query_params(
                    $pgConnection,
                    $overlap_sql,
                    [
                        $room_id,
                        $check_out,
                        $check_in
                    ]
                );

                if (!$overlap_result) {

                    die(
                        "Booking Check Error: " .
                        pg_last_error($pgConnection)
                    );
                }

                $existing_booking =
                    pg_fetch_assoc($overlap_result);


                if ($existing_booking) {

                    $message =
                        "❌ Room " .
                        $room_data["room_number"] .
                        " is already booked for the selected dates.";

                    $message_type = "error";

                } else {

                    /* =================================
                       CALCULATE NUMBER OF NIGHTS
                    ================================= */

                    try {

                        $date1 =
                            new DateTime($check_in);

                        $date2 =
                            new DateTime($check_out);

                        $interval =
                            $date1->diff($date2);

                        $nights =
                            $interval->days;

                    } catch (Exception $e) {

                        $nights = 0;
                    }


                    if ($nights <= 0) {

                        $message =
                            "Invalid booking dates.";

                        $message_type = "error";

                    } else {

                        /* =============================
                           CALCULATE ROOM CHARGE
                        ============================= */

                        $price_per_night =
                            (float)$room_data["price_per_night"];

                        $room_charge =
                            $price_per_night * $nights;


                        /* =============================
                           VAT 13%
                        ============================= */

                        $vat =
                            $room_charge * 0.13;


                        /* =============================
                           TOTAL
                        ============================= */

                        $total_amount =
                            $room_charge + $vat;


                        /* =============================
                           INSERT BOOKING
                        ============================= */

                        $insert_sql = "
                            INSERT INTO bookings
                            (
                                guest_id,
                                room_id,
                                check_in,
                                check_out,
                                adults,
                                children,
                                status,
                                total_amount
                            )
                            VALUES
                            (
                                $1,
                                $2,
                                $3,
                                $4,
                                $5,
                                $6,
                                'Confirmed',
                                $7
                            )
                            RETURNING id
                        ";

                        $insert_result =
                            pg_query_params(
                                $pgConnection,
                                $insert_sql,
                                [
                                    $guest_id,
                                    $room_id,
                                    $check_in,
                                    $check_out,
                                    $adults,
                                    $children,
                                    $total_amount
                                ]
                            );


                        if ($insert_result) {

                            /* =========================
                               GET NEW BOOKING ID
                            ========================= */

                            $new_booking =
                                pg_fetch_assoc(
                                    $insert_result
                                );

                            /* =========================
                               UPDATE ROOM STATUS
                            ========================= */

                            $update_room = "
                                UPDATE rooms
                                SET status = 'occupied'
                                WHERE id = $1
                            ";

                            $update_result =
                                pg_query_params(
                                    $pgConnection,
                                    $update_room,
                                    [$room_id]
                                );


                            if (!$update_result) {

                                /*
                                 * Booking was created,
                                 * but room status failed.
                                 */

                                $message =
                                    "⚠️ Booking created, but room status could not be updated: " .
                                    pg_last_error($pgConnection);

                                $message_type = "error";

                            } else {

                                $message =
                                    "✅ Booking created successfully! " .
                                    "Room " .
                                    $room_data["room_number"] .
                                    " | Nights: " .
                                    $nights .
                                    " | Total: Rs. " .
                                    number_format(
                                        $total_amount,
                                        2
                                    );

                                $message_type = "success";

                                /*
                                 * Clear form after
                                 * successful booking.
                                 */

                                $_POST = [];
                            }
                        } else {

                            $message =
                                "Error creating booking: " .
                                pg_last_error($pgConnection);

                            $message_type = "error";
                        }
                    }
                }
            }
        }
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

<title>Add Booking - Suva Hotel</title>

<style>

body {
    font-family: Arial, sans-serif;
    background: #f4f6f8;
    margin: 0;
    padding: 30px;
}

.container {
    max-width: 700px;
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
    font-weight: bold;
    display: block;
    margin-top: 15px;
}

select,
input {
    width: 100%;
    padding: 11px;
    margin-top: 6px;
    box-sizing: border-box;
    border: 1px solid #ccc;
    border-radius: 6px;
}

button {
    width: 100%;
    margin-top: 25px;
    padding: 13px;
    background: #1f3c88;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 16px;
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
    background: #eef4ff;
    padding: 12px;
    border-radius: 6px;
    margin-top: 15px;
}

.total-box {
    margin-top: 20px;
    background: #f1f5ff;
    padding: 15px;
    border-radius: 8px;
}

.links {
    margin-top: 20px;
}

.links a {
    color: #1f3c88;
    text-decoration: none;
    margin-right: 15px;
}

.links a:hover {
    text-decoration: underline;
}

</style>

</head>

<body>

<div class="container">

<h1>🏨 Suva Hotel</h1>

<h2>📋 Create New Booking</h2>

<?php if ($message !== ""): ?>

    <div class="<?php echo htmlspecialchars($message_type); ?>">

        <?php echo htmlspecialchars($message); ?>

    </div>

<?php endif; ?>


<div class="info">

    💡 Select check-in and check-out dates first.
    The system will prevent overlapping bookings.

</div>


<form method="POST">


<!-- =================================================
     GUEST
================================================= -->

<label>
    👤 Guest
</label>

<select name="guest_id" required>

<option value="">
    -- Select Guest --
</option>

<?php while ($guest = pg_fetch_assoc($guest_result)): ?>

<option
    value="<?php echo htmlspecialchars($guest["id"]); ?>"
    <?php
    if (
        isset($_POST["guest_id"]) &&
        $_POST["guest_id"] == $guest["id"]
    ) {
        echo "selected";
    }
    ?>
>

    <?php
    echo htmlspecialchars(
        $guest["full_name"]
    );
    ?>

</option>

<?php endwhile; ?>

</select>


<!-- =================================================
     ROOM
================================================= -->

<label>
    🏨 Room
</label>

<select
    name="room_id"
    id="room_id"
    required
>

<option value="">
    -- Select Available Room --
</option>

<?php while ($room = pg_fetch_assoc($room_result)): ?>

<option
    value="<?php echo htmlspecialchars($room["id"]); ?>"
    data-price="<?php echo htmlspecialchars($room["price_per_night"]); ?>"
    <?php
    if (
        isset($_POST["room_id"]) &&
        $_POST["room_id"] == $room["id"]
    ) {
        echo "selected";
    }
    ?>
>

    Room
    <?php echo htmlspecialchars($room["room_number"]); ?>

    -
    <?php echo htmlspecialchars($room["room_type"]); ?>

    -
    Rs.
    <?php
    echo number_format(
        (float)$room["price_per_night"],
        2
    );
    ?>

</option>

<?php endwhile; ?>

</select>


<!-- =================================================
     CHECK IN
================================================= -->

<label>
    📅 Check-in
</label>

<input
    type="date"
    name="check_in"
    id="check_in"
    value="<?php
        echo htmlspecialchars(
            $_POST["check_in"] ?? ""
        );
    ?>"
    required
>


<!-- =================================================
     CHECK OUT
================================================= -->

<label>
    📅 Check-out
</label>

<input
    type="date"
    name="check_out"
    id="check_out"
    value="<?php
        echo htmlspecialchars(
            $_POST["check_out"] ?? ""
        );
    ?>"
    required
>


<!-- =================================================
     ADULTS
================================================= -->

<label>
    👨 Adults
</label>

<input
    type="number"
    name="adults"
    value="<?php
        echo htmlspecialchars(
            $_POST["adults"] ?? "1"
        );
    ?>"
    min="1"
    required
>


<!-- =================================================
     CHILDREN
================================================= -->

<label>
    👶 Children
</label>

<input
    type="number"
    name="children"
    value="<?php
        echo htmlspecialchars(
            $_POST["children"] ?? "0"
        );
    ?>"
    min="0"
    required
>


<!-- =================================================
     CALCULATION
================================================= -->

<div class="total-box">

    <strong>💰 Booking Calculation</strong>

    <p>
        Price Per Night:
        Rs.
        <span id="price">
            0.00
        </span>
    </p>

    <p>
        Number of Nights:
        <span id="nights">
            0
        </span>
    </p>

    <p>
        Room Charge:
        Rs.
        <span id="room_charge">
            0.00
        </span>
    </p>

    <p>
        VAT 13%:
        Rs.
        <span id="vat">
            0.00
        </span>
    </p>

    <h3>
        Total Amount:
        Rs.
        <span id="total">
            0.00
        </span>
    </h3>

</div>


<button type="submit">

    💾 Create Booking

</button>

</form>


<div class="links">

<a href="view.php">
    📋 View Bookings
</a>

<?php if ($role === "admin"): ?>

<a href="../dashboard.php">
    ← Admin Dashboard
</a>

<?php else: ?>

<a href="../staff_dashboard/staff_dashboard.php">
    ← Staff Dashboard
</a>

<?php endif; ?>

</div>

</div>


<script>

// =====================================================
// CALCULATE BOOKING
// =====================================================

function calculateBooking() {

    const room =
        document.getElementById("room_id");

    const checkIn =
        document.getElementById("check_in").value;

    const checkOut =
        document.getElementById("check_out").value;


    if (!room.value || !checkIn || !checkOut) {

        document.getElementById("price").textContent =
            "0.00";

        document.getElementById("nights").textContent =
            "0";

        document.getElementById("room_charge").textContent =
            "0.00";

        document.getElementById("vat").textContent =
            "0.00";

        document.getElementById("total").textContent =
            "0.00";

        return;
    }


    const selected =
        room.options[room.selectedIndex];


    const price =
        parseFloat(
            selected.dataset.price
        ) || 0;


    const start =
        new Date(checkIn);

    const end =
        new Date(checkOut);


    const difference =
        end - start;


    const nights =
        difference /
        (1000 * 60 * 60 * 24);


    if (nights <= 0) {

        document.getElementById("nights").textContent =
            "0";

        document.getElementById("room_charge").textContent =
            "0.00";

        document.getElementById("vat").textContent =
            "0.00";

        document.getElementById("total").textContent =
            "0.00";

        return;
    }


    const roomCharge =
        price * nights;


    const vat =
        roomCharge * 0.13;


    const total =
        roomCharge + vat;


    document.getElementById("price").textContent =
        price.toFixed(2);

    document.getElementById("nights").textContent =
        nights;

    document.getElementById("room_charge").textContent =
        roomCharge.toFixed(2);

    document.getElementById("vat").textContent =
        vat.toFixed(2);

    document.getElementById("total").textContent =
        total.toFixed(2);
}


document.getElementById("room_id")
    .addEventListener(
        "change",
        calculateBooking
    );

document.getElementById("check_in")
    .addEventListener(
        "change",
        calculateBooking
    );

document.getElementById("check_out")
    .addEventListener(
        "change",
        calculateBooking
    );


/* Calculate on page load */

calculateBooking();

</script>

</body>

</html>
