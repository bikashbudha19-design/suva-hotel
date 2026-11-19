
<?php

require_once "../config/database.php";

$message = "";
$message_type = "";

/* Escape output */
function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

/* Load bookings that have a bill and are not checked out */
$sql = "
    SELECT
        b.id AS booking_id,
        g.full_name AS guest_name,
        r.room_number,
        r.room_type,
        b.check_in,
        b.check_out,
        b.status AS booking_status,
        bl.total_amount
    FROM bookings b
    JOIN guests g ON g.id = b.guest_id
    JOIN rooms r ON r.id = b.room_id
    JOIN LATERAL (
        SELECT total_amount
        FROM billing
        WHERE booking_id = b.id
        ORDER BY id DESC
        LIMIT 1
    ) bl ON TRUE
    WHERE b.status IS DISTINCT FROM 'Checked Out'
    ORDER BY b.id DESC
";

$result = pg_query($pgConnection, $sql);

if (!$result) {
    error_log("Checkout booking query failed: " . pg_last_error($pgConnection));
    die("Unable to load bookings. Please check the PHP error log.");
}

/* Process checkout */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $booking_id = filter_input(
        INPUT_POST,
        "booking_id",
        FILTER_VALIDATE_INT
    );

    if (!$booking_id || $booking_id <= 0) {

        $message = "Please select a valid booking.";
        $message_type = "error";

    } else {

        $in_transaction = false;

        try {

            /* Begin transaction */
            if (!pg_query($pgConnection, "BEGIN")) {
                throw new Exception("Could not start checkout transaction.");
            }

            $in_transaction = true;

            /*
             * Lock booking so two checkout requests cannot
             * process the same booking simultaneously.
             */
            $booking_sql = "
                SELECT
                    b.id AS booking_id,
                    b.room_id,
                    b.status AS booking_status,
                    g.full_name AS guest_name,
                    r.room_number,
                    bl.total_amount
                FROM bookings b
                JOIN guests g ON g.id = b.guest_id
                JOIN rooms r ON r.id = b.room_id
                JOIN LATERAL (
                    SELECT total_amount
                    FROM billing
                    WHERE booking_id = b.id
                    ORDER BY id DESC
                    LIMIT 1
                ) bl ON TRUE
                WHERE b.id = $1
                FOR UPDATE OF b
            ";

            $booking_result = pg_query_params(
                $pgConnection,
                $booking_sql,
                [$booking_id]
            );

            if (!$booking_result) {
                throw new Exception("Could not retrieve booking details.");
            }

            $booking = pg_fetch_assoc($booking_result);

            if (!$booking) {
                throw new Exception(
                    "Booking or its bill was not found. Please create the bill first."
                );
            }

            if ($booking["booking_status"] === "Checked Out") {
                throw new Exception(
                    "This booking has already been checked out."
                );
            }

            $room_id = (int)$booking["room_id"];
            $room_number = $booking["room_number"];
            $total_amount = (float)$booking["total_amount"];

            if ($total_amount < 0) {
                throw new Exception("The bill total is invalid.");
            }

            /*
             * Sum only payments marked Paid or Partial.
             * Pending or failed payment records are not counted.
             */
            $paid_sql = "
                SELECT COALESCE(SUM(amount), 0) AS total_paid
                FROM payments
                WHERE booking_id = $1
                  AND LOWER(TRIM(COALESCE(payment_status, '')))
                      IN ('paid', 'partial')
            ";

            $paid_result = pg_query_params(
                $pgConnection,
                $paid_sql,
                [$booking_id]
            );

            if (!$paid_result) {
                throw new Exception("Could not calculate confirmed payments.");
            }

            $paid_row = pg_fetch_assoc($paid_result);
            $total_paid = (float)$paid_row["total_paid"];

            $remaining = round($total_amount - $total_paid, 2);

            /* Block checkout if money is still owed */
            if ($remaining > 0.01) {

                throw new Exception(
                    "Checkout cannot be completed. Remaining payment is Rs. "
                    . number_format($remaining, 2)
                );
            }

            /*
             * Prevent checkout if payment records exceed the bill.
             * Resolve any credit or duplicate payment before checkout.
             */
            if ($remaining < -0.01) {

                throw new Exception(
                    "Checkout blocked: confirmed payments exceed the bill by Rs. "
                    . number_format(abs($remaining), 2)
                    . ". Please review the payment records."
                );
            }

            /* Update booking status */
            $update_booking = pg_query_params(
                $pgConnection,
                "UPDATE bookings
                 SET status = 'Checked Out'
                 WHERE id = $1
                   AND status IS DISTINCT FROM 'Checked Out'",
                [$booking_id]
            );

            if (
                !$update_booking ||
                pg_affected_rows($update_booking) !== 1
            ) {
                throw new Exception("Could not update booking status.");
            }

            /* Make room available */
            
$update_room = pg_query_params(
    $pgConnection,
    "UPDATE rooms
     SET status = 'available'
     WHERE id = $1",
    [$room_id]
);

            if (
                !$update_room ||
                pg_affected_rows($update_room) !== 1
            ) {
                throw new Exception("Could not update room availability.");
            }

            /* Commit both updates together */
            if (!pg_query($pgConnection, "COMMIT")) {
                throw new Exception("Could not save checkout changes.");
            }

            $in_transaction = false;

            $message = "Checkout completed successfully! Room "
                     . $room_number
                     . " is now Available.";

            $message_type = "success";

            /*
             * Reload the booking list so the checked-out booking
             * is no longer displayed.
             */
            $result = pg_query($pgConnection, $sql);

            if (!$result) {
                error_log(
                    "Could not reload checkout list: "
                    . pg_last_error($pgConnection)
                );
            }

        } catch (Throwable $ex) {

            if ($in_transaction) {
                pg_query($pgConnection, "ROLLBACK");
            }

            error_log("Checkout error: " . $ex->getMessage());

            $message = $ex->getMessage();
            $message_type = "error";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Guest Checkout - Suva Hotel</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 850px;
            margin: 35px auto;
            padding: 20px;
            background: #f5f6fa;
            color: #222;
        }

        .panel {
            background: #fff;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        select, button {
            padding: 11px;
            font-size: 16px;
            max-width: 100%;
        }

        select {
            width: 100%;
        }

        button {
            cursor: pointer;
            background: #176b3a;
            color: white;
            border: none;
            border-radius: 4px;
        }

        .message {
            padding: 12px;
            border-radius: 4px;
            overflow-wrap: anywhere;
        }

        .success {
            background: #e7f6ec;
            color: #176b3a;
        }

        .error {
            background: #fdecec;
            color: #a32020;
        }
    </style>
</head>

<body>

<div class="panel">

    <h1>Suva Hotel</h1>
    <h2>Guest Checkout</h2>

    <?php if ($message !== ""): ?>
        <p class="message <?= $message_type === "success" ? "success" : "error" ?>">
            <strong><?= e($message) ?></strong>
        </p>
    <?php endif; ?>

    <form method="POST">

        <label for="booking_id">
            <strong>Select Booking:</strong>
        </label>

        <br><br>

        <select name="booking_id" id="booking_id" required>
            <option value="">-- Select Booking --</option>

            <?php while ($row = pg_fetch_assoc($result)): ?>
                <option value="<?= (int)$row["booking_id"] ?>">
                    Booking #<?= (int)$row["booking_id"] ?>
                    - <?= e($row["guest_name"]) ?>
                    - Room <?= e($row["room_number"]) ?>
                    - Rs. <?= number_format((float)$row["total_amount"], 2) ?>
                </option>
            <?php endwhile; ?>

        </select>

        <br><br>

        <button type="submit">Complete Checkout</button>

    </form>

    <br>

    <a href="../dashboard.php">Back to Dashboard</a>

</div>

</body>
</html>
