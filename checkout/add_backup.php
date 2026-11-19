
<?php

require_once "../config/database.php";

$message = "";
$message_type = "";

/* Escape output safely */
function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

/* Get active bookings with their latest bill */
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
    JOIN guests g ON b.guest_id = g.id
    JOIN rooms r ON b.room_id = r.id
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
    error_log(pg_last_error($pgConnection));
    die("Unable to load bookings. Please check the database error log.");
}

/* Process checkout */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $booking_id = filter_input(INPUT_POST, "booking_id", FILTER_VALIDATE_INT);

    if (!$booking_id || $booking_id <= 0) {

        $message = "Please select a valid booking.";
        $message_type = "error";

    } else {

        $in_transaction = false;

        try {

            /* Start transaction */
            if (!pg_query($pgConnection, "BEGIN")) {
                throw new Exception("Could not start checkout transaction.");
            }

            $in_transaction = true;

            /*
             * Lock the booking while checking payment and
             * completing checkout.
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
                JOIN guests g ON b.guest_id = g.id
                JOIN rooms r ON b.room_id = r.id
                LEFT JOIN LATERAL (
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

                throw new Exception("Booking not found.");

            } elseif ($booking["booking_status"] === "Checked Out") {

                throw new Exception("This booking has already been checked out.");

            } elseif ($booking["total_amount"] === null) {

                throw new Exception("No bill was found for this booking. Create the bill before checkout.");

            } else {

                $room_id = (int)$booking["room_id"];
                $room_number = $booking["room_number"];
                $total_amount = (float)$booking["total_amount"];

                /* Count confirmed payments only */
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

                /* Do not allow checkout with an outstanding balance */
                if ($remaining > 0.01) {

                    throw new Exception(
                        "Checkout cannot be completed. Remaining payment is Rs. "
                        . number_format($remaining, 2)
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

                if (!$update_booking || pg_affected_rows($update_booking) !== 1) {
                    throw new Exception("Could not update booking status.");
                }

                /* Make the room available */
                $update_room = pg_query_params(
                    $pgConnection,
                    "UPDATE rooms
                     SET status = 'Available'
                     WHERE id = $1",
                    [$room_id]
                );

                if (!$update_room || pg_affected_rows($update_room) !== 1) {
                    throw new Exception("Could not update room availability.");
                }

                /* Commit all changes together */
                if (!pg_query($pgConnection, "COMMIT")) {
                    throw new Exception("Could not save checkout changes.");
                }

                $in_transaction = false;

                $message = "Checkout completed successfully! Room "
                         . $room_number . " is now Available.";

                $message_type = "success";
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
    <title>Checkout - Suva Hotel</title>
</head>
<body>

<h1>Suva Hotel</h1>
<h2>Guest Checkout</h2>

<?php if ($message !== ""): ?>
    <p style="color: <?= $message_type === "success" ? "green" : "red" ?>;">
        <strong><?= e($message) ?></strong>
    </p>
<?php endif; ?>

<form method="POST">
    <label for="booking_id"><strong>Select Booking:</strong></label>
    <br><br>

    <select name="booking_id" id="booking_id" required>
        <option value="">Select Booking</option>

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

</body>
</html>
