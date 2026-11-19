
<?php
// ============================================================
// SUVA HOTEL - SAFE BOOKING CANCELLATION
// File: /suva_hotel/bookings/cancel.php
// PostgreSQL - preserves booking and payment history
// ============================================================

session_start();

// ------------------------------------------------------------
// LOGIN AND ROLE CHECK
// ------------------------------------------------------------

if (
    empty($_SESSION['user_id']) ||
    !in_array(
        strtolower(trim($_SESSION['role'] ?? '')),
        ['admin', 'staff'],
        true
    )
) {
    header('Location: ../login.php');
    exit;
}

// ------------------------------------------------------------
// DATABASE CONNECTION
// ------------------------------------------------------------

require_once __DIR__ . '/../config/database.php';

if (!isset($pgConnection) || !$pgConnection) {
    exit('Database connection failed.');
}

// ------------------------------------------------------------
// HELPERS
// ------------------------------------------------------------

function e($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

if (empty($_SESSION['cancel_csrf'])) {
    $_SESSION['cancel_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = 'error';

$bookingId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

// ------------------------------------------------------------
// PROCESS CANCELLATION - POST ONLY
// ------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $bookingId = filter_input(
        INPUT_POST,
        'booking_id',
        FILTER_VALIDATE_INT
    );

    $token = (string)($_POST['csrf_token'] ?? '');

    if (
        !hash_equals(
            $_SESSION['cancel_csrf'],
            $token
        )
    ) {
        $message = 'Security check failed. Reload the page.';
    } elseif (!$bookingId || $bookingId < 1) {
        $message = 'Invalid booking ID.';
    } else {

        $begin = pg_query($pgConnection, 'BEGIN');

        if (!$begin) {
            $message = 'Could not start cancellation.';
        } else {

            // Lock the booking while processing cancellation.
            $bookingResult = pg_query_params(
                $pgConnection,
                "SELECT id, room_id, status
                 FROM bookings
                 WHERE id = $1
                 FOR UPDATE",
                [$bookingId]
            );

            if (!$bookingResult) {

                pg_query($pgConnection, 'ROLLBACK');
                error_log(pg_last_error($pgConnection));
                $message = 'Could not verify the booking.';

            } else {

                $booking = pg_fetch_assoc($bookingResult);

                if (!$booking) {

                    pg_query($pgConnection, 'ROLLBACK');
                    $message = 'Booking not found.';

                } elseif (
                    !in_array(
                        strtolower(trim($booking['status'] ?? '')),
                        ['confirmed', 'pending'],
                        true
                    )
                ) {

                    pg_query($pgConnection, 'ROLLBACK');

                    $message =
                        'Only Pending or Confirmed bookings can be '
                        . 'cancelled here. No changes were made.';

                } else {

                    // Never silently cancel a booking with payments.
                    $paymentResult = pg_query_params(
                        $pgConnection,
                        "SELECT COUNT(*) AS payment_count
                         FROM payments
                         WHERE booking_id = $1",
                        [$bookingId]
                    );

                    if (!$paymentResult) {

                        pg_query($pgConnection, 'ROLLBACK');
                        error_log(pg_last_error($pgConnection));

                        $message =
                            'Could not verify payment history. '
                            . 'No changes were made.';

                    } else {

                        $paymentRow = pg_fetch_assoc($paymentResult);
                        $paymentCount = (int)$paymentRow['payment_count'];

                        if ($paymentCount > 0) {

                            pg_query($pgConnection, 'ROLLBACK');

                            $message =
                                'This booking has payment records. '
                                . 'Cancellation is stopped so payment '
                                . 'history and reconciliation can be '
                                . 'reviewed first.';

                        } else {

                            // Preserve the booking row; do not DELETE.
                            $updateResult = pg_query_params(
                                $pgConnection,
                                "UPDATE bookings
                                 SET status = 'Cancelled'
                                 WHERE id = $1
                                   AND LOWER(TRIM(status))
                                       IN ('confirmed', 'pending')
                                 RETURNING id",
                                [$bookingId]
                            );

                            if (
                                !$updateResult ||
                                !pg_fetch_assoc($updateResult)
                            ) {

                                pg_query($pgConnection, 'ROLLBACK');

                                if (!$updateResult) {
                                    error_log(
                                        pg_last_error($pgConnection)
                                    );
                                }

                                $message =
                                    'Cancellation failed. '
                                    . 'No changes were committed.';

                            } else {

                                $commit = pg_query(
                                    $pgConnection,
                                    'COMMIT'
                                );

                                if (!$commit) {

                                    error_log(
                                        'Cancellation commit error: '
                                        . pg_last_error($pgConnection)
                                    );

                                    $message =
                                        'The result could not be confirmed. '
                                        . 'Check the booking status before '
                                        . 'trying again.';

                                } else {

                                    $message =
                                        'Booking #' . $bookingId
                                        . ' was cancelled successfully. '
                                        . 'The booking record was preserved. '
                                        . 'Room status was not changed.';

                                    $messageType = 'success';

                                    // Prevent accidental form resubmission.
                                    $_SESSION['cancel_csrf'] =
                                        bin2hex(random_bytes(32));
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

// ------------------------------------------------------------
// LOAD BOOKING DETAILS
// ------------------------------------------------------------

if (!$bookingId || $bookingId < 1) {
    http_response_code(400);
    exit('Invalid booking ID. Open this page with ?id=16');
}

$result = pg_query_params(
    $pgConnection,
    "SELECT
        b.id AS booking_id,
        b.status AS booking_status,
        b.check_in,
        b.check_out,
        b.room_id,
        r.room_number,
        r.status AS room_status,
        g.full_name
     FROM bookings b
     LEFT JOIN rooms r ON r.id = b.room_id
     LEFT JOIN guests g ON g.id = b.guest_id
     WHERE b.id = $1",
    [$bookingId]
);

if (!$result) {
    error_log(pg_last_error($pgConnection));
    exit('Could not load booking details.');
}

$booking = pg_fetch_assoc($result);

if (!$booking) {
    http_response_code(404);
    exit('Booking not found.');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cancel Booking | Suva Hotel</title>

<style>
* { box-sizing: border-box; }

body {
    margin: 0;
    padding: 24px;
    background: #f3f6fb;
    color: #243247;
    font-family: Arial, sans-serif;
}

.container {
    max-width: 650px;
    margin: 25px auto;
    background: white;
    padding: 28px;
    border-radius: 12px;
    box-shadow: 0 4px 20px #0000000d;
}

h1 { color: #12345a; }

.details {
    background: #eef3ff;
    padding: 16px;
    border-radius: 8px;
    line-height: 1.8;
}

.notice {
    padding: 12px;
    border-radius: 7px;
    margin: 18px 0;
}

.success {
    background: #dcfce7;
    color: #166534;
}

.error {
    background: #fee2e2;
    color: #991b1b;
}

.warning {
    background: #fff7ed;
    color: #9a3412;
    padding: 14px;
    border-radius: 8px;
    line-height: 1.6;
}

button {
    margin-top: 18px;
    padding: 13px 18px;
    border: 0;
    border-radius: 7px;
    background: #b91c1c;
    color: white;
    font-weight: bold;
    cursor: pointer;
}

button:hover { background: #991b1b; }

a {
    display: inline-block;
    margin-top: 20px;
    margin-right: 15px;
    color: #164e87;
}
</style>
</head>

<body>
<div class="container">

<h1>🏨 Suva Hotel</h1>
<h2>Cancel Booking</h2>

<?php if ($message !== ''): ?>
    <div class="notice <?= e($messageType) ?>">
        <?= e($message) ?>
    </div>
<?php endif; ?>

<div class="details">
    <strong>Booking ID:</strong>
    <?= (int)$booking['booking_id'] ?><br>

    <strong>Guest:</strong>
    <?= e($booking['full_name'] ?? 'Unknown') ?><br>

    <strong>Room:</strong>
    <?= e($booking['room_number'] ?? 'Not assigned') ?><br>

    <strong>Booking Status:</strong>
    <?= e($booking['booking_status']) ?><br>

    <strong>Check-in:</strong>
    <?= e($booking['check_in']) ?><br>

    <strong>Check-out:</strong>
    <?= e($booking['check_out']) ?><br>

    <strong>Current Room Status:</strong>
    <?= e($booking['room_status'] ?? 'Unknown') ?>
</div>

<div class="warning">
    <strong>Before proceeding:</strong>
    Confirm that this is the mistaken reservation.
    This action changes the booking status, but does not
    delete the booking, change room status, refund payments,
    or send an SMS.
</div>

<?php if (
    in_array(
        strtolower(trim($booking['booking_status'] ?? '')),
        ['confirmed', 'pending'],
        true
    )
): ?>

<form method="post"
      onsubmit="return confirm(
          'Are you sure you want to cancel this booking?'
      );">

    <input type="hidden"
           name="booking_id"
           value="<?= (int)$booking['booking_id'] ?>">

    <input type="hidden"
           name="csrf_token"
           value="<?= e($_SESSION['cancel_csrf']) ?>">

    <button type="submit">
        Cancel This Booking
    </button>
</form>

<?php else: ?>

<p>This booking is not eligible for cancellation through this page.</p>

<?php endif; ?>

<a href="view.php">← Back to Bookings</a>
<a href="../dashboard.php">Dashboard</a>

</div>
</body>
</html>
