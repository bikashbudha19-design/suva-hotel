
<?php
session_start();

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

require_once __DIR__ . '/../config/database.php';

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['edit_csrf'])) {
    $_SESSION['edit_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = 'error';
$bookingId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookingId = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);

    if (
        !hash_equals(
            $_SESSION['edit_csrf'],
            (string)($_POST['csrf_token'] ?? '')
        )
    ) {
        $message = 'Security check failed. Please reload the page.';
    } elseif (!$bookingId || $bookingId < 1) {
        $message = 'Invalid booking ID.';
    } else {
        $name = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if ($name === '') {
            $message = 'Guest name is required.';
        } elseif (
            $phone !== '' &&
            !preg_match('/^\+?[0-9][0-9\s()-]{6,18}$/', $phone)
        ) {
            $message = 'Please enter a valid phone number.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
        } else {
            $result = pg_query_params(
                $pgConnection,
                "UPDATE guests
                 SET full_name = $1,
                     phone = $2,
                     email = $3,
                     address = $4
                 WHERE id = (
                     SELECT guest_id FROM bookings WHERE id = $5
                 )
                 RETURNING id",
                [
                    $name,
                    $phone,
                    $email !== '' ? $email : null,
                    $address !== '' ? $address : null,
                    $bookingId
                ]
            );

            if ($result && pg_fetch_assoc($result)) {
                $message = 'Guest details updated successfully.';
                $messageType = 'success';
            } elseif (!$result) {
                error_log('Guest edit error: ' . pg_last_error($pgConnection));
                $message = 'Could not update guest details. Check the server log.';
            } else {
                $message = 'Booking or guest was not found.';
            }
        }
    }
}

if (!$bookingId || $bookingId < 1) {
    http_response_code(400);
    exit('Invalid booking ID.');
}

$result = pg_query_params(
    $pgConnection,
    "SELECT b.id AS booking_id, b.status AS booking_status,
            b.check_in, b.check_out,
            g.full_name, g.phone, g.email, g.address,
            r.room_number, r.room_type
     FROM bookings b
     JOIN guests g ON g.id = b.guest_id
     LEFT JOIN rooms r ON r.id = b.room_id
     WHERE b.id = $1",
    [$bookingId]
);

$guest = $result ? pg_fetch_assoc($result) : false;

if (!$guest) {
    http_response_code(404);
    exit('Booking not found.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit Guest | SUVA HOTEL</title>
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
h1 { color: #12345a; margin-top: 0; }
.subtitle { color: #64748b; }
label {
    display: block;
    margin-top: 16px;
    margin-bottom: 6px;
    font-weight: bold;
}
input, textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    font: inherit;
}
textarea { min-height: 85px; resize: vertical; }
button {
    margin-top: 22px;
    padding: 12px 18px;
    border: 0;
    border-radius: 7px;
    background: #147d50;
    color: white;
    font-weight: bold;
    cursor: pointer;
}
.notice {
    padding: 12px;
    border-radius: 7px;
    margin: 18px 0;
}
.success { background: #dcfce7; color: #166534; }
.error { background: #fee2e2; color: #991b1b; }
.back { display: inline-block; margin-top: 20px; color: #164e87; }
</style>
</head>
<body>
<div class="container">
    <h1>SUVA HOTEL</h1>
    <p class="subtitle">
        Edit Guest Details — Booking #<?= (int)$guest['booking_id'] ?>
    </p>

    <p>
        <strong>Room:</strong>
        <?= e($guest['room_number'] ?? 'Not assigned') ?>
        (<?= e($guest['room_type'] ?? '') ?>)
    </p>
    <p>
        <strong>Booking status:</strong>
        <?= e($guest['booking_status'] ?: 'Pending') ?>
    </p>

    <?php if ($message !== ''): ?>
        <div class="notice <?= e($messageType) ?>">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="booking_id"
               value="<?= (int)$guest['booking_id'] ?>">
        <input type="hidden" name="csrf_token"
               value="<?= e($_SESSION['edit_csrf']) ?>">

        <label for="full_name">Guest Name *</label>
        <input id="full_name" name="full_name" required
               value="<?= e($guest['full_name']) ?>">

        <label for="phone">Phone Number</label>
        <input id="phone" name="phone" type="tel"
               value="<?= e($guest['phone']) ?>"
               placeholder="Enter guest phone number">

        <label for="email">Email Address</label>
        <input id="email" name="email" type="email"
               value="<?= e($guest['email']) ?>">

        <label for="address">Address</label>
        <textarea id="address" name="address"><?= e($guest['address']) ?></textarea>

        <button type="submit">Save Guest Details</button>
    </form>

    <a class="back" href="view.php">← Back to Bookings</a>
</div>
</body>
</html>
