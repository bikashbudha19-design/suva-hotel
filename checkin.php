
<?php

session_start();

// Only logged-in Admin and Staff may use check-in.
if (
    empty($_SESSION['user_id']) ||
    !in_array(
        strtolower(trim($_SESSION['role'] ?? '')),
        ['admin', 'staff'],
        true
    )
) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';


$message = '';
$messageType = '';

// Process guest check-in.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookingId = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);

    if (!$bookingId || $bookingId < 1) {
        $message = 'Please select a valid booking.';
        $messageType = 'error';
    } else {
        $transactionStarted = false;

        try {
            if (!pg_query($pgConnection, 'BEGIN')) {
                throw new RuntimeException('Could not start database transaction.');
            }

            $transactionStarted = true;

            // Lock the booking and its room while checking in.
            $bookingResult = pg_query_params(
                $pgConnection,
                "SELECT b.id, b.guest_id, b.room_id, b.status,
                        g.full_name, g.phone, r.room_number, r.status AS room_status
                 FROM bookings b
                 JOIN guests g ON g.id = b.guest_id
                 JOIN rooms r ON r.id = b.room_id
                 WHERE b.id = $1
                 FOR UPDATE OF b, r",
                [$bookingId]
            );

            if (!$bookingResult) {
                throw new RuntimeException('Could not read booking details.');
            }

            $booking = pg_fetch_assoc($bookingResult);

            if (!$booking) {
                throw new RuntimeException('Booking was not found.');
            }

            $bookingStatus = strtolower(trim($booking['status'] ?? ''));
            $roomStatus = strtolower(trim($booking['room_status'] ?? ''));

            if (!in_array($bookingStatus, ['', 'confirmed'], true)) {
                throw new RuntimeException(
                    'This booking cannot be checked in. Current status: ' .
                    ($booking['status'] ?: 'Empty')
                );
            }

            if ($roomStatus !== 'available') {
                throw new RuntimeException(
                    'The room is not available. Current status: ' . $roomStatus
                );
            }

            // Mark booking as checked in.
            $updateBooking = pg_query_params(
                $pgConnection,
                "UPDATE bookings SET status = 'Checked In' WHERE id = $1",
                [$bookingId]
            );

            if (!$updateBooking) {
                throw new RuntimeException('Could not update booking status.');
            }

            // Mark room as occupied.
            $updateRoom = pg_query_params(
                $pgConnection,
                "UPDATE rooms SET status = 'occupied' WHERE id = $1",
                [$booking['room_id']]
            );

            if (!$updateRoom) {
                throw new RuntimeException('Could not update room status.');
            }

            if (!pg_query($pgConnection, 'COMMIT')) {
                throw new RuntimeException('Could not finish database transaction.');
            }

            $transactionStarted = false;
            
            // Prepare a welcome message after successful check-in.
            // This does NOT send an SMS.
            $welcomeText = 'Welcome to SUVA HOTEL, ' .
                $booking['full_name'] .
                '! We are delighted to have you with us. Enjoy your stay in Room ' .
                $booking['room_number'] . '.';

            $smsRecord = pg_query_params(
                $pgConnection,
                "INSERT INTO guest_welcome_sms
                    (booking_id, phone, message, sms_status)
                 VALUES ($1, $2, $3, 'prepared')
                 ON CONFLICT (booking_id) DO NOTHING",
                [
                    $bookingId,
                    $booking['phone'],
                    $welcomeText
                ]
            );

            if (!$smsRecord) {
                error_log(
                    'SUVA HOTEL: Could not prepare welcome message for booking #' .
                    $bookingId . ': ' . pg_last_error($pgConnection)
                );
            }


            $message = 'Check-in successful for ' .
                $booking['full_name'] . ', Room ' .
                $booking['room_number'] . '.';

            $messageType = 'success';

        } catch (Throwable $e) {
            if ($transactionStarted) {
                pg_query($pgConnection, 'ROLLBACK');
            }

            error_log('SUVA HOTEL check-in error: ' . $e->getMessage());

            $message = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Check-in failed. Please check the server error log.';

            $messageType = 'error';
        }
    }
}

// Show bookings that may be ready for check-in.
$result = pg_query(
    $pgConnection,
    "SELECT b.id, b.status, b.check_in, b.check_out,
            g.full_name, g.phone,
            r.room_number, r.room_type, r.status AS room_status
     FROM bookings b
     JOIN guests g ON g.id = b.guest_id
     JOIN rooms r ON r.id = b.room_id
     WHERE LOWER(TRIM(COALESCE(b.status, ''))) IN ('', 'confirmed')
     ORDER BY b.check_in ASC, b.id DESC"
);

if (!$result) {
    error_log('SUVA HOTEL check-in list error: ' . pg_last_error($pgConnection));
    die('Could not load bookings. Please check the server error log.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Guest Check-In | SUVA HOTEL</title>
<style>
* { box-sizing: border-box; }
body {
    margin: 0;
    padding: 24px;
    background: #f3f6fb;
    color: #202b3c;
    font-family: Arial, sans-serif;
}
.container {
    max-width: 1150px;
    margin: auto;
}
.header {
    background: #12345a;
    color: white;
    padding: 24px;
    border-radius: 12px;
    margin-bottom: 22px;
}
.header h1 { margin: 0 0 8px; }
.header p { margin: 0; color: #dce8f8; }
.notice {
    padding: 14px;
    border-radius: 8px;
    margin-bottom: 18px;
}
.success { background: #d9f5e5; color: #14532d; }
.error { background: #fee2e2; color: #991b1b; }
.table-wrap {
    overflow-x: auto;
    background: white;
    border-radius: 12px;
    box-shadow: 0 3px 12px #0000000b;
}
table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}
th, td {
    text-align: left;
    padding: 14px;
    border-bottom: 1px solid #e7edf4;
}
th { background: #eaf0f8; }
.status { text-transform: capitalize; }
button {
    border: 0;
    background: #147d50;
    color: white;
    padding: 10px 14px;
    border-radius: 7px;
    cursor: pointer;
    font-weight: bold;
}
button:disabled {
    background: #9ca3af;
    cursor: not-allowed;
}
.empty { padding: 24px; text-align: center; }
.back { display: inline-block; margin-top: 18px; color: #164e87; }
@media (max-width: 600px) {
    body { padding: 12px; }
    .header { padding: 18px; }
}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>SUVA HOTEL</h1>
        <p>Guest Arrival and Check-In Management</p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="notice <?= $messageType === 'success' ? 'success' : 'error' ?>">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <h2>Bookings Awaiting Check-In</h2>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Booking</th>
                    <th>Guest</th>
                    <th>Phone</th>
                    <th>Room</th>
                    <th>Arrival</th>
                    <th>Booking Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $found = false;
            while ($row = pg_fetch_assoc($result)):
                $found = true;
                $roomAvailable = strtolower(trim($row['room_status'] ?? '')) === 'available';
            ?>
                <tr>
                    <td>#<?= (int)$row['id'] ?></td>
                    <td><?= htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <?= htmlspecialchars($row['room_number'], ENT_QUOTES, 'UTF-8') ?>
                        (<?= htmlspecialchars($row['room_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>)
                    </td>
                    <td><?= htmlspecialchars($row['check_in'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="status">
                        <?= htmlspecialchars($row['status'] ?: 'Pending', ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td>
                        <?php if ($roomAvailable): ?>
                            <form method="post"
                                  onsubmit="return confirm('Confirm check-in for this guest?');">
                                <input type="hidden" name="booking_id"
                                       value="<?= (int)$row['id'] ?>">
                                <button type="submit">Check In Guest</button>
                            </form>
                        <?php else: ?>
                            <button type="button" disabled>Room Not Available</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>

            <?php if (!$found): ?>
                <tr>
                    <td colspan="7" class="empty">No bookings are awaiting check-in.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <a class="back" href="dashboard.php">← Back to Dashboard</a>
</div>
</body>
</html>
