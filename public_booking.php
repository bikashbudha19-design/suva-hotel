
<?php
/**
 * ============================================================
 * SUVA HOTEL — PUBLIC ONLINE BOOKING
 * File: public_booking.php
 *
 * PHP 8+ | PostgreSQL | XAMPP
 *
 * Features:
 * - Public guest room reservations
 * - PostgreSQL room availability checking
 * - Guest registration
 * - Automatic booking calculation
 * - 20% eSewa advance payment
 * - CSRF protection
 * - Database transaction and room locking
 * ============================================================
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('Asia/Kathmandu');

require_once __DIR__ . '/config/database.php';

// ------------------------------------------------------------
// DATABASE CONNECTION
// ------------------------------------------------------------

if (!isset($pgConnection) || !$pgConnection ||
    pg_connection_status($pgConnection) !== PGSQL_CONNECTION_OK) {
    die('Database connection failed. Please check config/database.php.');
}

// ------------------------------------------------------------
// SETTINGS
// ------------------------------------------------------------

$hotelName = 'Suva Hotel';
$advancePercentage = 20;

$error = '';
$success = '';

$bookingCreated = false;
$bookingReference = null;

$selectedRoom = (int)($_GET['room_id'] ?? 0);

$today = date('Y-m-d');

// ------------------------------------------------------------
// HELPERS
// ------------------------------------------------------------

function h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function money($amount): string
{
    return 'Rs. ' . number_format((float)$amount, 2);
}

function queryOrFail($connection, $sql, $params = [])
{
    if ($params) {
        $result = pg_query_params($connection, $sql, $params);
    } else {
        $result = pg_query($connection, $sql);
    }

    if (!$result) {
        throw new RuntimeException(
            'A database operation failed.'
        );
    }

    return $result;
}

// ------------------------------------------------------------
// CSRF TOKEN
// ------------------------------------------------------------

if (empty($_SESSION['public_booking_csrf'])) {
    $_SESSION['public_booking_csrf'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['public_booking_csrf'];

// ------------------------------------------------------------
// LOAD ROOMS
// ------------------------------------------------------------

$rooms = [];

try {

    $roomResult = queryOrFail(
        $pgConnection,
        "
        SELECT
            id,
            room_number,
            room_type,
            price_per_night,
            status,
            description,
            image
        FROM rooms
        WHERE LOWER(TRIM(COALESCE(status, '')))
              <> 'maintenance'
        ORDER BY room_number ASC
        "
    );

    while ($room = pg_fetch_assoc($roomResult)) {
        $rooms[] = $room;
    }

} catch (Throwable $e) {

    error_log($e->getMessage());

    $error = 'Unable to load room information.';
}

// ------------------------------------------------------------
// HANDLE BOOKING SUBMISSION
// ------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $transactionStarted = false;

    try {

        // ----------------------------------------------------
        // CSRF VALIDATION
        // ----------------------------------------------------

        $submittedToken = $_POST['csrf_token'] ?? '';

        if (
            !is_string($submittedToken) ||
            !hash_equals($csrfToken, $submittedToken)
        ) {
            throw new RuntimeException(
                'Invalid request. Please refresh the page and try again.'
            );
        }

        // ----------------------------------------------------
        // GET FORM DATA
        // ----------------------------------------------------

        $fullName = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');

        $roomId = filter_var(
            $_POST['room_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $checkIn = trim($_POST['check_in'] ?? '');
        $checkOut = trim($_POST['check_out'] ?? '');

        $adults = filter_var(
            $_POST['adults'] ?? null,
            FILTER_VALIDATE_INT
        );

        $children = filter_var(
            $_POST['children'] ?? null,
            FILTER_VALIDATE_INT
        );

        // ----------------------------------------------------
        // VALIDATION
        // ----------------------------------------------------

        if ($fullName === '' || mb_strlen($fullName) > 150) {
            throw new RuntimeException(
                'Please enter a valid full name.'
            );
        }

        if (
            !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)
        ) {
            throw new RuntimeException(
                'Please enter a valid contact number.'
            );
        }

        if (
            $email !== '' &&
            (
                !filter_var($email, FILTER_VALIDATE_EMAIL) ||
                strlen($email) > 254
            )
        ) {
            throw new RuntimeException(
                'Please enter a valid email address.'
            );
        }

        if (strlen($address) > 500) {
            throw new RuntimeException(
                'Address is too long.'
            );
        }

        if (!$roomId || $roomId < 1) {
            throw new RuntimeException(
                'Please select a room.'
            );
        }

        if (
            $adults === false ||
            $children === false ||
            $adults < 1 ||
            $children < 0 ||
            $adults + $children > 20
        ) {
            throw new RuntimeException(
                'Please enter valid guest numbers.'
            );
        }

        // ----------------------------------------------------
        // DATE VALIDATION
        // ----------------------------------------------------

        $checkInDate = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $checkIn
        );

        $checkOutDate = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $checkOut
        );

        if (
            !$checkInDate ||
            !$checkOutDate ||
            $checkInDate->format('Y-m-d') !== $checkIn ||
            $checkOutDate->format('Y-m-d') !== $checkOut
        ) {
            throw new RuntimeException(
                'Please select valid check-in and check-out dates.'
            );
        }

        if ($checkIn < $today) {
            throw new RuntimeException(
                'Check-in date cannot be in the past.'
            );
        }

        if ($checkOut <= $checkIn) {
            throw new RuntimeException(
                'Check-out must be after check-in.'
            );
        }

        $nights = (int)$checkInDate->diff(
            $checkOutDate
        )->days;

        if ($nights < 1 || $nights > 365) {
            throw new RuntimeException(
                'Please select a stay between 1 and 365 nights.'
            );
        }

        // ----------------------------------------------------
        // CHECK ESEWA FILE
        // ----------------------------------------------------

        $esewaFile = __DIR__ .
            '/payment_gateway/esewa_initiate.php';

        if (!is_file($esewaFile)) {
            throw new RuntimeException(
                'eSewa initiation file was not found. ' .
                'Please check website/payments/esewa_initiate.php.'
            );
        }

        // ----------------------------------------------------
        // START DATABASE TRANSACTION
        // ----------------------------------------------------

        queryOrFail($pgConnection, 'BEGIN');

        $transactionStarted = true;

        // ----------------------------------------------------
        // LOCK AND CHECK SELECTED ROOM
        // ----------------------------------------------------

        $roomResult = queryOrFail(
            $pgConnection,
            "
            SELECT
                id,
                room_number,
                room_type,
                price_per_night,
                status
            FROM rooms
            WHERE id = $1
            FOR UPDATE
            ",
            [$roomId]
        );

        $room = pg_fetch_assoc($roomResult);

        if (!$room) {
            throw new RuntimeException(
                'The selected room does not exist.'
            );
        }

        if (
            strtolower(trim($room['status'] ?? '')) ===
            'maintenance'
        ) {
            throw new RuntimeException(
                'This room is currently under maintenance.'
            );
        }

        $pricePerNight = (float)$room['price_per_night'];

        if (!is_finite($pricePerNight) || $pricePerNight <= 0) {
            throw new RuntimeException(
                'The selected room has an invalid price.'
            );
        }

        // ----------------------------------------------------
        // CHECK DATE OVERLAP
        // ----------------------------------------------------

        $availabilityResult = queryOrFail(
            $pgConnection,
            "
            SELECT id
            FROM bookings
            WHERE room_id = $1

              AND check_in < $3
              AND check_out > $2

              AND LOWER(TRIM(COALESCE(status, '')))
                  NOT IN (
                      'cancelled',
                      'canceled',
                      'checked out',
                      'checked-out'
                  )

            LIMIT 1
            ",
            [
                $roomId,
                $checkIn,
                $checkOut
            ]
        );

        if (pg_num_rows($availabilityResult) > 0) {
            throw new RuntimeException(
                'Sorry! This room is already reserved for your selected dates. ' .
                'Please select another room or different dates.'
            );
        }

        // ----------------------------------------------------
        // CALCULATE BOOKING AMOUNT
        // ----------------------------------------------------

        $totalAmount = round(
            $pricePerNight * $nights,
            2
        );

        $advanceAmount = round(
            $totalAmount * $advancePercentage / 100,
            2
        );

        // ----------------------------------------------------
        // REGISTER GUEST
        // ----------------------------------------------------

        $guestResult = queryOrFail(
            $pgConnection,
            "
            INSERT INTO guests (
                full_name,
                phone,
                email,
                address
            )
            VALUES ($1, $2, $3, $4)
            RETURNING id
            ",
            [
                $fullName,
                $phone,
                $email !== '' ? $email : null,
                $address !== '' ? $address : null
            ]
        );

        $guest = pg_fetch_assoc($guestResult);

        if (!$guest) {
            throw new RuntimeException(
                'Unable to register guest.'
            );
        }

        $guestId = (int)$guest['id'];

        // ----------------------------------------------------
        // CREATE PENDING BOOKING
        // ----------------------------------------------------

        $bookingResult = queryOrFail(
            $pgConnection,
            "
            INSERT INTO bookings (
                guest_id,
                room_id,
                check_in,
                check_out,
                adults,
                children,
                status,
                total_amount
            )
            VALUES (
                $1, $2, $3, $4, $5, $6, $7, $8
            )
            RETURNING id
            ",
            [
                $guestId,
                $roomId,
                $checkIn,
                $checkOut,
                $adults,
                $children,
                'Pending',
                $totalAmount
            ]
        );

        $booking = pg_fetch_assoc($bookingResult);

        if (!$booking) {
            throw new RuntimeException(
                'Unable to create your reservation.'
            );
        }

        $bookingId = (int)$booking['id'];

        // ----------------------------------------------------
        // COMMIT DATABASE TRANSACTION
        // ----------------------------------------------------

        queryOrFail($pgConnection, 'COMMIT');

        $transactionStarted = false;

        // ----------------------------------------------------
        // SAVE PUBLIC BOOKING SESSION
        // ----------------------------------------------------

        $_SESSION['public_booking_id'] = $bookingId;

        $_SESSION['public_booking_csrf'] = $csrfToken;

        $_SESSION['public_booking_advance'] = $advanceAmount;

        $_SESSION['public_booking_total'] = $totalAmount;

        // ----------------------------------------------------
        // REDIRECT TO ESEWA
        // ----------------------------------------------------

        header(
            'Location: website/payments/esewa_initiate.php'
        );

        exit;

    } catch (Throwable $e) {

        if ($transactionStarted) {
            @pg_query($pgConnection, 'ROLLBACK');
        }

        error_log(
            'Public Booking Error: ' . $e->getMessage()
        );

        $error = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Unable to complete your reservation. Please try again.';

    }
}

// ------------------------------------------------------------
// REFRESH CSRF TOKEN FOR DISPLAY
// ------------------------------------------------------------

$csrfToken = $_SESSION['public_booking_csrf'];

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<meta name="description"
content="Reserve your stay at Suva Hotel. Select your room, choose your dates and book online.">

<title>Book Your Stay | Suva Hotel</title>

<link rel="preconnect" href="https://fonts.googleapis.com">

<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>

:root {
    --gold: #c9a44c;
    --gold-light: #e5c77d;
    --dark: #10231d;
    --dark-light: #19362c;
    --cream: #faf8f2;
    --text: #26352f;
    --muted: #77827c;
    --white: #ffffff;
    --border: #e7e5dc;
    --success: #23784f;
    --danger: #bd3e3e;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'DM Sans', sans-serif;
    background: var(--cream);
    color: var(--text);
    line-height: 1.6;
}

a {
    text-decoration: none;
}

/* NAVIGATION */

.navbar {
    background: var(--dark);
    min-height: 75px;
    padding: 12px 6%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
}

.logo {
    color: var(--gold-light);
    font-family: 'Playfair Display', serif;
    font-size: 25px;
    font-weight: 700;
    letter-spacing: 2px;
}

.nav-links {
    display: flex;
    gap: 24px;
    align-items: center;
}

.nav-links a {
    color: #fff;
    font-size: 13px;
    transition: .3s;
}

.nav-links a:hover {
    color: var(--gold-light);
}

.nav-links .login-link {
    border: 1px solid var(--gold);
    padding: 9px 14px;
    border-radius: 5px;
    color: var(--gold-light);
}

/* HERO */

.hero {
    min-height: 340px;
    background:
        linear-gradient(
            rgba(5, 19, 14, .72),
            rgba(5, 19, 14, .70)
        ),
        url('assets/images/hero.jpg')
        center / cover no-repeat;

    display: flex;
    justify-content: center;
    align-items: center;
    text-align: center;
    padding: 55px 20px;
    color: white;
}

.hero-content {
    max-width: 750px;
}

.hero-label {
    color: var(--gold-light);
    letter-spacing: 4px;
    font-size: 11px;
    text-transform: uppercase;
}

.hero h1 {
    font-family: 'Playfair Display', serif;
    font-size: clamp(35px, 6vw, 58px);
    margin: 12px 0;
}

.hero p {
    font-size: 15px;
    color: rgba(255,255,255,.83);
}

/* MAIN */

.container {
    max-width: 1150px;
    margin: 0 auto;
    padding: 55px 20px 70px;
}

.section-heading {
    text-align: center;
    margin-bottom: 35px;
}

.section-heading span {
    color: var(--gold);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 3px;
    text-transform: uppercase;
}

.section-heading h2 {
    font-family: 'Playfair Display', serif;
    font-size: 34px;
    color: var(--dark);
    margin-top: 7px;
}

.section-heading p {
    color: var(--muted);
    font-size: 14px;
    margin-top: 8px;
}

/* BOOKING LAYOUT */

.booking-layout {
    display: grid;
    grid-template-columns: 1.6fr .8fr;
    gap: 25px;
    align-items: start;
}

.booking-card,
.summary-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
    padding: 28px;
    box-shadow: 0 10px 35px rgba(16,35,29,.045);
}

.card-heading {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 25px;
}

.card-heading i {
    color: var(--gold);
    font-size: 21px;
}

.card-heading h3 {
    color: var(--dark);
    font-size: 18px;
}

/* FORM */

.form-section-title {
    color: var(--dark);
    font-size: 13px;
    font-weight: 800;
    margin: 25px 0 15px;
    padding-bottom: 9px;
    border-bottom: 1px solid var(--border);
}

.form-section-title:first-of-type {
    margin-top: 0;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 17px;
}

.form-group {
    min-width: 0;
}

.form-group.full {
    grid-column: 1 / -1;
}

.form-group label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    color: #45564d;
    margin-bottom: 7px;
}

.form-group label span {
    color: var(--danger);
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    min-height: 46px;
    border: 1px solid #dddcd4;
    border-radius: 6px;
    padding: 11px 12px;
    background: #fff;
    color: var(--text);
    font-family: inherit;
    font-size: 13px;
    outline: none;
    transition: .2s;
}

.form-group textarea {
    min-height: 85px;
    resize: vertical;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    border-color: var(--gold);
    box-shadow: 0 0 0 3px rgba(201,164,76,.12);
}

.form-group input::placeholder,
.form-group textarea::placeholder {
    color: #a5aaa4;
}

/* ALERT */

.alert {
    padding: 13px 15px;
    margin-bottom: 20px;
    border-radius: 7px;
    font-size: 13px;
}

.alert.error {
    color: #a42e2e;
    background: #fff0f0;
    border: 1px solid #f1cccc;
}

/* SUMMARY */

.summary-card {
    position: sticky;
    top: 20px;
    background: var(--dark);
    color: white;
    border: none;
}

.summary-card h3 {
    font-family: 'Playfair Display', serif;
    font-size: 22px;
    color: var(--gold-light);
    margin-bottom: 22px;
}

.summary-line {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding: 12px 0;
    border-bottom: 1px solid rgba(255,255,255,.11);
    font-size: 12px;
}

.summary-line span:first-child {
    color: rgba(255,255,255,.65);
}

.summary-line strong {
    text-align: right;
}

.summary-total {
    margin-top: 12px;
    padding-top: 15px;
    border-top: 1px solid rgba(255,255,255,.2);
}

.summary-total .summary-line {
    border: 0;
    padding: 7px 0;
}

.summary-total .total strong {
    color: var(--gold-light);
    font-size: 21px;
}

.advance-box {
    margin-top: 18px;
    padding: 15px;
    background: rgba(201,164,76,.12);
    border: 1px solid rgba(201,164,76,.3);
    border-radius: 8px;
}

.advance-box p {
    font-size: 11px;
    color: rgba(255,255,255,.75);
}

.advance-box strong {
    display: block;
    font-size: 21px;
    color: var(--gold-light);
    margin: 5px 0;
}

.advance-box small {
    color: rgba(255,255,255,.6);
    font-size: 10px;
}

.payment-note {
    margin-top: 18px;
    color: rgba(255,255,255,.7);
    font-size: 11px;
    line-height: 1.8;
}

.payment-note i {
    color: var(--gold-light);
    margin-right: 5px;
}

/* SUBMIT */

.submit-btn {
    width: 100%;
    min-height: 50px;
    margin-top: 25px;
    border: none;
    border-radius: 6px;
    background: linear-gradient(135deg, #d7b35c, #b78b30);
    color: #17231c;
    font-family: inherit;
    font-size: 13px;
    font-weight: 800;
    letter-spacing: .5px;
    cursor: pointer;
    transition: .3s;
}

.submit-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(201,164,76,.25);
}

.submit-btn i {
    margin-right: 8px;
}

/* ROOM INFO */

.room-info {
    margin-top: 35px;
}

.room-info h3 {
    font-family: 'Playfair Display', serif;
    color: var(--dark);
    font-size: 25px;
    margin-bottom: 17px;
}

.room-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
}

.room-item {
    background: white;
    padding: 18px;
    border: 1px solid var(--border);
    border-radius: 8px;
}

.room-item h4 {
    color: var(--dark);
    font-size: 14px;
}

.room-item p {
    color: var(--muted);
    font-size: 11px;
    margin: 5px 0;
}

.room-item strong {
    color: var(--success);
    font-size: 14px;
}

/* FOOTER */

.footer {
    background: #0b1c16;
    color: rgba(255,255,255,.65);
    text-align: center;
    padding: 28px 15px;
    font-size: 12px;
}

.footer strong {
    color: var(--gold-light);
}

/* RESPONSIVE */

@media(max-width: 850px) {

    .booking-layout {
        grid-template-columns: 1fr;
    }

    .summary-card {
        position: static;
    }

    .nav-links {
        gap: 12px;
    }
}

@media(max-width: 600px) {

    .navbar {
        padding: 15px 5%;
        align-items: flex-start;
        flex-direction: column;
    }

    .nav-links {
        width: 100%;
        flex-wrap: wrap;
        gap: 13px;
    }

    .nav-links a {
        font-size: 11px;
    }

    .hero {
        min-height: 280px;
    }

    .container {
        padding: 38px 14px 50px;
    }

    .section-heading h2 {
        font-size: 28px;
    }

    .booking-card,
    .summary-card {
        padding: 19px;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .form-group.full {
        grid-column: auto;
    }

    .room-grid {
        grid-template-columns: 1fr 1fr;
    }
}

@media(max-width: 380px) {

    .room-grid {
        grid-template-columns: 1fr;
    }
}

</style>

</head>

<body>

<!-- ========================================================
     NAVIGATION
========================================================= -->

<nav class="navbar">

    <a href="index.php" class="logo">
        SUVA HOTEL
    </a>

    <div class="nav-links">

        <a href="index.php">Home</a>

        <a href="index.php#about">About</a>

        <a href="index.php#rooms">Rooms</a>

        <a href="index.php#restaurant">Restaurant</a>

        <a href="index.php#contact">Contact</a>

        <a href="login.php" class="login-link">
            <i class="fas fa-user-shield"></i>
            Staff Login
        </a>

    </div>

</nav>

<!-- ========================================================
     HERO
========================================================= -->

<header class="hero">

    <div class="hero-content">

        <span class="hero-label">
            SUVA HOTEL · ONLINE RESERVATION
        </span>

        <h1>Reserve Your Stay</h1>

        <p>
            Experience comfort, warm hospitality
            and memorable moments.
        </p>

    </div>

</header>

<!-- ========================================================
     BOOKING SECTION
========================================================= -->

<main class="container">

    <div class="section-heading">

        <span>YOUR STAY STARTS HERE</span>

        <h2>Complete Your Reservation</h2>

        <p>
            Enter your details and select your preferred room.
        </p>

    </div>

    <div class="booking-layout">

        <!-- ================================================
             BOOKING FORM
        ================================================= -->

        <section class="booking-card">

            <div class="card-heading">

                <i class="fas fa-calendar-check"></i>

                <h3>Reservation Details</h3>

            </div>

            <?php if ($error !== ''): ?>

                <div class="alert error">

                    <i class="fas fa-circle-exclamation"></i>

                    <?= h($error) ?>

                </div>

            <?php endif; ?>

            <form
                method="POST"
                action="public_booking.php"
                id="bookingForm"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h($csrfToken) ?>"
                >

                <!-- GUEST INFORMATION -->

                <div class="form-section-title">
                    <i class="fas fa-user"></i>
                    Guest Information
                </div>

                <div class="form-grid">

                    <div class="form-group full">

                        <label for="full_name">
                            Full Name <span>*</span>
                        </label>

                        <input
                            type="text"
                            id="full_name"
                            name="full_name"
                            maxlength="150"
                            placeholder="Enter your full name"
                            value="<?= h($_POST['full_name'] ?? '') ?>"
                            autocomplete="name"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label for="phone">
                            Contact Number <span>*</span>
                        </label>

                        <input
                            type="tel"
                            id="phone"
                            name="phone"
                            maxlength="20"
                            placeholder="+977 98XXXXXXXX"
                            value="<?= h($_POST['phone'] ?? '') ?>"
                            autocomplete="tel"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label for="email">
                            Email Address
                        </label>

                        <input
                            type="email"
                            id="email"
                            name="email"
                            maxlength="254"
                            placeholder="example@email.com"
                            value="<?= h($_POST['email'] ?? '') ?>"
                            autocomplete="email"
                        >

                    </div>

                    <div class="form-group full">

                        <label for="address">
                            Address
                        </label>

                        <textarea
                            id="address"
                            name="address"
                            maxlength="500"
                            placeholder="Enter your address"
                            autocomplete="street-address"
                        ><?= h($_POST['address'] ?? '') ?></textarea>

                    </div>

                </div>

                <!-- STAY INFORMATION -->

                <div class="form-section-title">

                    <i class="fas fa-bed"></i>
                    Stay Information

                </div>

                <div class="form-grid">

                    <div class="form-group">

                        <label for="check_in">
                            Check In <span>*</span>
                        </label>

                        <input
                            type="date"
                            id="check_in"
                            name="check_in"
                            min="<?= h($today) ?>"
                            value="<?= h($_POST['check_in'] ?? '') ?>"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label for="check_out">
                            Check Out <span>*</span>
                        </label>

                        <input
                            type="date"
                            id="check_out"
                            name="check_out"
                            min="<?= h($today) ?>"
                            value="<?= h($_POST['check_out'] ?? '') ?>"
                            required
                        >

                    </div>

                    <div class="form-group full">

                        <label for="room_id">
                            Select Room <span>*</span>
                        </label>

                        <select
                            id="room_id"
                            name="room_id"
                            required
                        >

                            <option value="">
                                Choose your preferred room
                            </option>

                            <?php foreach ($rooms as $room): ?>

                                <option
                                    value="<?= (int)$room['id'] ?>"
                                    data-price="<?= h($room['price_per_night']) ?>"
                                    <?= (
                                        (int)($_POST['room_id'] ?? $selectedRoom)
                                        === (int)$room['id']
                                    ) ? 'selected' : '' ?>
                                >

                                    Room <?= h($room['room_number']) ?>
                                    —
                                    <?= h(ucfirst($room['room_type'])) ?>
                                    —
                                    <?= h(money($room['price_per_night'])) ?>/night

                                    <?php if (
                                        strtolower($room['status']) === 'occupied'
                                    ): ?>
                                        (Currently Occupied)
                                    <?php endif; ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                        <small style="color:#8b948d;font-size:10px;">
                            Availability is checked against your selected dates
                            when you submit your reservation.
                        </small>

                    </div>

                    <div class="form-group">

                        <label for="adults">
                            Adults <span>*</span>
                        </label>

                        <select id="adults" name="adults" required>

                            <?php for ($i = 1; $i <= 10; $i++): ?>

                                <option
                                    value="<?= $i ?>"
                                    <?= (
                                        (int)($_POST['adults'] ?? 1) === $i
                                    ) ? 'selected' : '' ?>
                                >
                                    <?= $i ?>
                                    <?= $i === 1 ? 'Adult' : 'Adults' ?>
                                </option>

                            <?php endfor; ?>

                        </select>

                    </div>

                    <div class="form-group">

                        <label for="children">
                            Children
                        </label>

                        <select id="children" name="children">

                            <?php for ($i = 0; $i <= 10; $i++): ?>

                                <option
                                    value="<?= $i ?>"
                                    <?= (
                                        (int)($_POST['children'] ?? 0) === $i
                                    ) ? 'selected' : '' ?>
                                >
                                    <?= $i ?>
                                    <?= $i === 1 ? 'Child' : 'Children' ?>
                                </option>

                            <?php endfor; ?>

                        </select>

                    </div>

                </div>

                <!-- SUBMIT -->

                <button
                    type="submit"
                    class="submit-btn"
                    id="submitBtn"
                >

                    <i class="fas fa-lock"></i>

                    Continue to Secure Payment

                </button>

            </form>

        </section>

        <!-- ================================================
             BOOKING SUMMARY
        ================================================= -->

        <aside class="summary-card">

            <h3>Your Reservation</h3>

            <div class="summary-line">

                <span>Selected Room</span>

                <strong id="summaryRoom">
                    Not selected
                </strong>

            </div>

            <div class="summary-line">

                <span>Price per Night</span>

                <strong id="summaryPrice">
                    Rs. 0.00
                </strong>

            </div>

            <div class="summary-line">

                <span>Number of Nights</span>

                <strong id="summaryNights">
                    0
                </strong>

            </div>

            <div class="summary-total">

                <div class="summary-line total">

                    <span>Total Amount</span>

                    <strong id="summaryTotal">
                        Rs. 0.00
                    </strong>

                </div>

                <div class="summary-line">

                    <span>Advance (20%)</span>

                    <strong id="summaryAdvance">
                        Rs. 0.00
                    </strong>

                </div>

                <div class="summary-line">

                    <span>Remaining Balance</span>

                    <strong id="summaryBalance">
                        Rs. 0.00
                    </strong>

                </div>

            </div>

            <div class="advance-box">

                <p>PAY NOW TO RESERVE YOUR ROOM</p>

                <strong id="advanceHighlight">
                    Rs. 0.00
                </strong>

                <small>
                    20% advance payment through eSewa
                </small>

            </div>

            <div class="payment-note">

                <p>
                    <i class="fas fa-shield-halved"></i>
                    Secure payment through eSewa.
                </p>

                <p>
                    <i class="fas fa-circle-info"></i>
                    Final reservation is subject to successful payment
                    verification.
                </p>

            </div>

        </aside>

    </div>

    <!-- ================================================
         ROOM INFORMATION
    ================================================= -->

    <section class="room-info">

        <h3>Explore Our Rooms</h3>

        <div class="room-grid">

            <?php foreach ($rooms as $room): ?>

                <div class="room-item">

                    <h4>
                        Room <?= h($room['room_number']) ?>
                    </h4>

                    <p>
                        <?= h(ucfirst($room['room_type'])) ?>
                    </p>

                    <strong>
                        <?= h(money($room['price_per_night'])) ?>
                        / night
                    </strong>

                    <p>
                        <?= h($room['description'] ?: 'Comfortable accommodation.') ?>
                    </p>

                </div>

            <?php endforeach; ?>

        </div>

    </section>

</main>

<!-- ========================================================
     FOOTER
========================================================= -->

<footer class="footer">

    <strong>SUVA HOTEL</strong>

    <p>
        Comfort, Hospitality and Memorable Experiences.
    </p>

    <p style="margin-top:8px;">
        © <?= date('Y') ?> Suva Hotel. All Rights Reserved.
    </p>

    <p style="margin-top:8px;">

        <a href="index.php" style="color:#e5c77d;">
            <i class="fas fa-arrow-left"></i>
            Back to Website
        </a>

    </p>

</footer>

<!-- ========================================================
     JAVASCRIPT — AUTOMATIC BOOKING CALCULATION
========================================================= -->

<script>

const roomSelect = document.getElementById('room_id');

const checkIn = document.getElementById('check_in');

const checkOut = document.getElementById('check_out');

const summaryRoom = document.getElementById('summaryRoom');

const summaryPrice = document.getElementById('summaryPrice');

const summaryNights = document.getElementById('summaryNights');

const summaryTotal = document.getElementById('summaryTotal');

const summaryAdvance = document.getElementById('summaryAdvance');

const summaryBalance = document.getElementById('summaryBalance');

const advanceHighlight = document.getElementById('advanceHighlight');

function formatMoney(value) {

    return 'Rs. ' + Number(value).toLocaleString(
        'en-IN',
        {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }
    );

}

function updateCheckoutMinimum() {

    if (!checkIn.value) {
        checkOut.min = checkIn.min;
        return;
    }

    const nextDay = new Date(
        checkIn.value + 'T00:00:00'
    );

    nextDay.setDate(nextDay.getDate() + 1);

    const minimum = [
        nextDay.getFullYear(),
        String(nextDay.getMonth() + 1).padStart(2, '0'),
        String(nextDay.getDate()).padStart(2, '0')
    ].join('-');

    checkOut.min = minimum;

    if (checkOut.value && checkOut.value <= checkIn.value) {
        checkOut.value = '';
    }

}

function calculateBooking() {

    const selectedOption =
        roomSelect.options[roomSelect.selectedIndex];

    const roomName =
        selectedOption && selectedOption.value
            ? selectedOption.textContent.trim()
            : 'Not selected';

    const price =
        selectedOption && selectedOption.value
            ? Number(selectedOption.dataset.price || 0)
            : 0;

    let nights = 0;

    if (checkIn.value && checkOut.value) {

        const start = new Date(
            checkIn.value + 'T00:00:00'
        );

        const end = new Date(
            checkOut.value + 'T00:00:00'
        );

        const difference = end.getTime() - start.getTime();

        if (difference > 0) {

            nights = Math.round(
                difference / (1000 * 60 * 60 * 24)
            );

        }

    }

    const total = price * nights;

    const advance = total * 0.20;

    const balance = total - advance;

    summaryRoom.textContent = roomName;

    summaryPrice.textContent = formatMoney(price);

    summaryNights.textContent = nights;

    summaryTotal.textContent = formatMoney(total);

    summaryAdvance.textContent = formatMoney(advance);

    summaryBalance.textContent = formatMoney(balance);

    advanceHighlight.textContent = formatMoney(advance);

}

roomSelect.addEventListener('change', calculateBooking);

checkIn.addEventListener('change', function() {

    updateCheckoutMinimum();

    calculateBooking();

});

checkOut.addEventListener('change', calculateBooking);

updateCheckoutMinimum();

calculateBooking();

// --------------------------------------------------------
// FORM VALIDATION
// --------------------------------------------------------

document.getElementById('bookingForm').addEventListener(
    'submit',
    function(event) {

        if (
            checkIn.value &&
            checkOut.value &&
            checkOut.value <= checkIn.value
        ) {

            event.preventDefault();

            alert('Check-out date must be after check-in date.');

            return;
        }

        const adults = Number(
            document.getElementById('adults').value
        );

        const children = Number(
            document.getElementById('children').value
        );

        if (adults + children > 20) {

            event.preventDefault();

            alert('Maximum total guests allowed is 20.');

            return;
        }

        const submitButton =
            document.getElementById('submitBtn');

        submitButton.disabled = true;

        submitButton.innerHTML =
            '<i class="fas fa-spinner fa-spin"></i> Processing Reservation...';

    }
);

</script>

</body>
</html>
