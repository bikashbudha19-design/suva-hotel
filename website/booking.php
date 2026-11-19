
<?php
/**
 * ============================================================
 * SUVA HOTEL — PUBLIC RESERVATION FORM
 * File: website/booking.php
 * PHP 8+ | PostgreSQL | XAMPP
 *
 * Public guests submit booking requests.
 * Requests are saved with Pending status.
 * Room status is not changed by this page.
 * ============================================================
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('Asia/Kathmandu');

require_once __DIR__ . '/../config/database.php';

$pageTitle = 'Reservations | Suva Hotel';

$errors = [];
$successMessage = '';
$selectedRoom = null;
$estimatedTotal = 0;
$submitted = false;

/* ------------------------------------------------------------
   HELPER FUNCTIONS
------------------------------------------------------------ */

function bookingEscape($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}
/* Format room types for display */
function formatRoomType($value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return 'Guest Room';
    }

    return ucwords(strtolower($value));
}
function bookingColumnExists($connection, string $table, string $column): bool
{
    $result = pg_query_params(
        $connection,
        "SELECT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = $1
              AND column_name = $2
        )",
        [$table, $column]
    );

    if ($result === false) {
        return false;
    }

    $exists = pg_fetch_result($result, 0, 0) === 't';
    pg_free_result($result);

    return $exists;
}

function bookingFindColumn(
    $connection,
    string $table,
    array $candidates
): ?string {
    foreach ($candidates as $column) {
        if (bookingColumnExists($connection, $table, $column)) {
            return $column;
        }
    }

    return null;
}

/* ------------------------------------------------------------
   DEFAULT FORM VALUES
------------------------------------------------------------ */

$form = [
    'room_id'  => trim((string)($_GET['room_id'] ?? '')),
    'full_name' => '',
    'phone'     => '',
    'email'     => '',
    'check_in'  => '',
    'check_out' => '',
    'adults'    => '1',
    'children'  => '0',
    'message'   => ''
];

/* ------------------------------------------------------------
   LOAD THE SELECTED ROOM
------------------------------------------------------------ */

if (
    $form['room_id'] !== '' &&
    ctype_digit($form['room_id']) &&
    (int)$form['room_id'] > 0
) {
    $roomResult = pg_query_params(
        $pgConnection,
        "SELECT
            id,
            room_number,
            room_type,
            price_per_night,
            description,
            image,
            status
         FROM rooms
         WHERE id = $1",
        [(int)$form['room_id']]
    );

    if ($roomResult !== false) {
        $selectedRoom = pg_fetch_assoc($roomResult) ?: null;
        pg_free_result($roomResult);
    }
}

/* ------------------------------------------------------------
   PROCESS RESERVATION FORM
------------------------------------------------------------ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = true;

    foreach ($form as $key => $value) {
        $form[$key] = trim((string)($_POST[$key] ?? ''));
    }

    /* Validate room ID */
    if (
        $form['room_id'] === '' ||
        !ctype_digit($form['room_id']) ||
        (int)$form['room_id'] < 1
    ) {
        $errors[] = 'Please select a valid room.';
    }

    /* Validate guest name */
    if ($form['full_name'] === '') {
        $errors[] = 'Please enter your full name.';
    } elseif (mb_strlen($form['full_name']) > 150) {
        $errors[] = 'Your name must be 150 characters or fewer.';
    }

    /* Validate phone */
    if ($form['phone'] === '') {
        $errors[] = 'Please enter your phone number.';
    } elseif (mb_strlen($form['phone']) > 30) {
        $errors[] = 'Please enter a valid phone number.';
    }

    /* Validate email if supplied */
    if (
        $form['email'] !== '' &&
        !filter_var($form['email'], FILTER_VALIDATE_EMAIL)
    ) {
        $errors[] = 'Please enter a valid email address.';
    }

    /* Validate dates */
    $checkInDate = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $form['check_in']
    );

    $checkOutDate = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $form['check_out']
    );

    $today = new DateTimeImmutable('today');

    if (
        !$checkInDate ||
        $checkInDate->format('Y-m-d') !== $form['check_in']
    ) {
        $errors[] = 'Please select a valid check-in date.';
    } elseif ($checkInDate < $today) {
        $errors[] = 'Check-in date cannot be in the past.';
    }

    if (
        !$checkOutDate ||
        $checkOutDate->format('Y-m-d') !== $form['check_out']
    ) {
        $errors[] = 'Please select a valid check-out date.';
    } elseif (
        $checkInDate &&
        $checkOutDate <= $checkInDate
    ) {
        $errors[] = 'Check-out must be after check-in.';
    }

    /* Validate adults and children */
    $adults = filter_var(
        $form['adults'],
        FILTER_VALIDATE_INT
    );

    $children = filter_var(
        $form['children'],
        FILTER_VALIDATE_INT
    );

    if ($adults === false || $adults < 1 || $adults > 20) {
        $errors[] = 'Adults must be between 1 and 20.';
    }

    if (
        $children === false ||
        $children < 0 ||
        $children > 20
    ) {
        $errors[] = 'Children must be between 0 and 20.';
    }

    if (mb_strlen($form['message']) > 1000) {
        $errors[] = 'Additional information must be 1000 characters or fewer.';
    }

    /* --------------------------------------------------------
       CHECK ROOM AVAILABILITY AND CALCULATE ESTIMATE
    -------------------------------------------------------- */

    if (
        empty($errors) &&
        ctype_digit($form['room_id'])
    ) {
        $roomResult = pg_query_params(
            $pgConnection,
            "SELECT
                id,
                room_number,
                room_type,
                price_per_night,
                description,
                image,
                status
             FROM rooms
             WHERE id = $1
               AND LOWER(TRIM(status)) = 'available'",
            [(int)$form['room_id']]
        );

        if ($roomResult === false) {
            $errors[] = 'We could not check room availability. Please try again.';
        } else {
            $selectedRoom = pg_fetch_assoc($roomResult) ?: null;
            pg_free_result($roomResult);

            if (!$selectedRoom) {
                $errors[] = 'This room is not currently available.';
            }
        }

        if (
            empty($errors) &&
            $checkInDate &&
            $checkOutDate &&
            $selectedRoom
        ) {
            $nights = (int)$checkInDate->diff($checkOutDate)->days;

            $roomPrice = (float)$selectedRoom['price_per_night'];
            $estimatedTotal = $nights * $roomPrice;

            if ($nights < 1) {
                $errors[] = 'Please choose at least one night.';
            } elseif ($estimatedTotal <= 0) {
                $errors[] = 'The selected room price is invalid. Please contact the hotel.';
            }
        }
    }

    /* --------------------------------------------------------
       SAVE GUEST AND BOOKING REQUEST
    -------------------------------------------------------- */

    if (empty($errors) && $selectedRoom) {
        $transactionStarted = false;

        try {
            if (!pg_query($pgConnection, 'BEGIN')) {
                throw new RuntimeException('Could not begin transaction.');
            }

            $transactionStarted = true;

            /*
             * Find the guest table's supported columns.
             * Common alternatives are supported for phone and email.
             */
            $nameColumn = bookingFindColumn(
                $pgConnection,
                'guests',
                ['full_name', 'guest_name', 'name']
            );

            $phoneColumn = bookingFindColumn(
                $pgConnection,
                'guests',
                ['phone', 'phone_number', 'mobile', 'contact_number']
            );

            $emailColumn = bookingFindColumn(
                $pgConnection,
                'guests',
                ['email', 'email_address']
            );

            if (!$nameColumn) {
                throw new RuntimeException(
                    'The guests table has no recognized name column.'
                );
            }

            if (!$phoneColumn) {
                throw new RuntimeException(
                    'The guests table has no recognized phone column.'
                );
            }

            /*
             * Create a guest record.
             * Email is included only if the column exists.
             */
            $guestColumns = [$nameColumn, $phoneColumn];
            $guestValues = [$form['full_name'], $form['phone']];

            if ($emailColumn && $form['email'] !== '') {
                $guestColumns[] = $emailColumn;
                $guestValues[] = $form['email'];
            }

            $guestColumnSql = implode(
                ', ',
                array_map(
                    fn($column) => '"' . $column . '"',
                    $guestColumns
                )
            );

            $guestPlaceholders = [];

            for ($i = 1; $i <= count($guestValues); $i++) {
                $guestPlaceholders[] = '$' . $i;
            }

            $guestSql = "
                INSERT INTO guests ($guestColumnSql)
                VALUES (" . implode(', ', $guestPlaceholders) . ")
                RETURNING id
            ";

            $guestResult = pg_query_params(
                $pgConnection,
                $guestSql,
                $guestValues
            );

            if ($guestResult === false) {
                throw new RuntimeException('Guest record could not be created.');
            }

            $guestId = (int)pg_fetch_result($guestResult, 0, 0);
            pg_free_result($guestResult);

            if ($guestId < 1) {
                throw new RuntimeException('Invalid guest record.');
            }

            /*
             * Save the reservation request.
             * Existing booking table columns are used.
             */
            $bookingColumns = [
                'guest_id',
                'room_id',
                'check_in',
                'check_out',
                'adults',
                'children',
                'status',
                'total_amount'
            ];

            $bookingValues = [
                $guestId,
                (int)$selectedRoom['id'],
                $form['check_in'],
                $form['check_out'],
                (int)$adults,
                (int)$children,
                'Pending',
                $estimatedTotal
            ];

            $bookingColumnSql = implode(
                ', ',
                array_map(
                    fn($column) => '"' . $column . '"',
                    $bookingColumns
                )
            );

            $bookingPlaceholders = [];

            for ($i = 1; $i <= count($bookingValues); $i++) {
                $bookingPlaceholders[] = '$' . $i;
            }

            $bookingSql = "
                INSERT INTO bookings ($bookingColumnSql)
                VALUES (" . implode(', ', $bookingPlaceholders) . ")
                RETURNING id
            ";

            $bookingResult = pg_query_params(
                $pgConnection,
                $bookingSql,
                $bookingValues
            );

            if ($bookingResult === false) {
                throw new RuntimeException('Booking request could not be saved.');
            }

            $bookingId = (int)pg_fetch_result($bookingResult, 0, 0);
            pg_free_result($bookingResult);

            if ($bookingId < 1) {
                throw new RuntimeException('Invalid booking request.');
            }

            if (!pg_query($pgConnection, 'COMMIT')) {
                throw new RuntimeException('Could not finalize reservation.');
            }

            $transactionStarted = false;
            /* Allow this browser session to initiate payment for this booking. */
$_SESSION['public_booking_id'] = $bookingId;
$_SESSION['public_booking_csrf'] = bin2hex(random_bytes(32));

            $successMessage =
                'Your reservation request has been submitted successfully. '
                . 'Your reference number is #' . $bookingId
                . '. The hotel team will review your request. '
                . 'Your reservation is not confirmed yet.';

            $form = [
                'room_id'   => '',
                'full_name' => '',
                'phone'     => '',
                'email'     => '',
                'check_in'  => '',
                'check_out' => '',
                'adults'    => '1',
                'children'  => '0',
                'message'   => ''
            ];

            $selectedRoom = null;
            $estimatedTotal = 0;

        } catch (Throwable $exception) {
            if ($transactionStarted) {
                @pg_query($pgConnection, 'ROLLBACK');
            }

            error_log(
                'Suva Hotel public booking error: '
                . $exception->getMessage()
            );

            $errors[] =
                'We could not save your request. '
                . 'Please check the details or contact the hotel team. '
                . 'If this problem continues, ask the administrator to check the database schema.';
        }
    }
}

/* ------------------------------------------------------------
   PAGE HEADER
------------------------------------------------------------ */

require_once __DIR__ . '/includes/header.php';
?>

<style>
.booking-page {
    background: #faf8f2;
    color: #26382f;
}

.booking-hero {
    padding: 65px 20px;
    background: linear-gradient(135deg, #123c30, #0b2922);
    color: #fffdf7;
    text-align: center;
}

.booking-hero span {
    color: #e3c98f;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 3px;
    text-transform: uppercase;
}

.booking-hero h1 {
    margin: 18px 0;
    font-family: "Playfair Display", Georgia, serif;
    font-size: clamp(36px, 5vw, 55px);
    font-weight: 500;
}

.booking-hero p {
    max-width: 650px;
    margin: 0 auto;
    color: #e2e8e1;
    font-size: 14px;
    line-height: 1.9;
}

.booking-container {
    display: grid;
    grid-template-columns: minmax(0, 1.5fr) minmax(270px, 0.8fr);
    gap: 30px;
    width: min(1100px, calc(100% - 36px));
    margin: 55px auto 75px;
    align-items: start;
}

.booking-form-card,
.booking-summary {
    border: 1px solid #e8e1d3;
    border-radius: 7px;
    background: #fff;
    box-shadow: 0 10px 35px rgba(25, 53, 39, 0.06);
}

.booking-form-card {
    padding: 32px;
}

.booking-form-card h2,
.booking-summary h2 {
    margin: 0 0 12px;
    color: #173d30;
    font-family: "Playfair Display", Georgia, serif;
    font-size: 29px;
    font-weight: 500;
}

.booking-subtitle {
    margin: 0 0 28px;
    color: #717a72;
    font-size: 13px;
    line-height: 1.8;
}

.booking-section-title {
    margin: 28px 0 17px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee8dc;
    color: #a08143;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 2px;
    text-transform: uppercase;
}

.booking-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.booking-field {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.booking-field.full {
    grid-column: 1 / -1;
}

.booking-field label {
    color: #34483b;
    font-size: 12px;
    font-weight: 700;
}

.booking-field input,
.booking-field select,
.booking-field textarea {
    width: 100%;
    min-width: 0;
    padding: 13px 12px;
    border: 1px solid #dddccf;
    border-radius: 3px;
    outline: none;
    background: #fffefa;
    color: #273b30;
    font: inherit;
    font-size: 13px;
    box-sizing: border-box;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.booking-field input:focus,
.booking-field select:focus,
.booking-field textarea:focus {
    border-color: #8a9d77;
    box-shadow: 0 0 0 3px rgba(37, 91, 65, 0.08);
}

.booking-field textarea {
    min-height: 105px;
    resize: vertical;
}

.booking-submit {
    width: 100%;
    margin-top: 25px;
    padding: 15px 20px;
    border: 1px solid #174a38;
    border-radius: 3px;
    background: #174a38;
    color: #fffdf7;
    font-size: 13px;
    font-weight: 800;
    cursor: pointer;
    transition: 0.25s;
}

.booking-submit:hover {
    border-color: #c7a65d;
    background: #c7a65d;
    color: #173b2e;
}

.booking-summary {
    position: sticky;
    top: 25px;
    overflow: hidden;
}

.booking-summary-top {
    padding: 25px;
    background: #153c30;
    color: #fff;
}

.booking-summary-top span {
    display: block;
    margin-bottom: 10px;
    color: #e1c78b;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 2px;
    text-transform: uppercase;
}

.booking-summary-top h2 {
    margin: 0;
    color: #fffdf7;
    font-size: 27px;
}

.booking-summary-body {
    padding: 25px;
}

.booking-summary-body p {
    color: #6d766f;
    font-size: 13px;
    line-height: 1.8;
}

.booking-summary-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 13px 0;
    border-bottom: 1px solid #eee9df;
    font-size: 13px;
}

.booking-summary-row span:first-child {
    color: #758076;
}

.booking-summary-row strong {
    color: #203f30;
    text-align: right;
}

.booking-total {
    margin-top: 20px;
    padding: 18px;
    border-radius: 4px;
    background: #f2efe5;
}

.booking-total span {
    display: block;
    margin-bottom: 8px;
    color: #68756b;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.booking-total strong {
    color: #173d30;
    font-size: 25px;
}

.booking-note {
    margin-top: 20px;
    padding: 15px;
    border-left: 3px solid #c7a65d;
    background: #faf7ee;
    color: #6c746b;
    font-size: 12px;
    line-height: 1.8;
}

.booking-alert {
    width: min(1036px, calc(100% - 36px));
    margin: 30px auto 0;
    padding: 18px 20px;
    border-radius: 4px;
    font-size: 13px;
    line-height: 1.8;
}

.booking-alert.error {
    border: 1px solid #e7c9c5;
    background: #fff4f2;
    color: #87372f;
}

.booking-alert.success {
    border: 1px solid #c8ddc8;
    background: #f0f8ef;
    color: #245c36;
}

.booking-alert ul {
    margin: 8px 0 0;
    padding-left: 20px;
}

.booking-success-actions {
    margin-top: 15px;
}

.booking-success-actions a {
    display: inline-block;
    margin: 5px 8px 0 0;
    padding: 10px 15px;
    border-radius: 3px;
    background: #174a38;
    color: #fff;
    text-decoration: none;
    font-size: 12px;
    font-weight: 700;
}

@media (max-width: 800px) {
    .booking-container {
        grid-template-columns: 1fr;
    }

    .booking-summary {
        position: static;
        grid-row: 1;
    }
}

@media (max-width: 520px) {
    .booking-container {
        width: calc(100% - 24px);
        margin: 28px auto 45px;
        gap: 20px;
    }

    .booking-form-card {
        padding: 22px 17px;
    }

    .booking-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }

    .booking-field.full {
        grid-column: auto;
    }

    .booking-hero {
        padding: 50px 18px;
    }
}
</style>

<div class="booking-page">

    <section class="booking-hero">
        <span>Suva Hotel · Reservations</span>

        <h1>Reserve Your Stay.</h1>

        <p>
            Plan your stay with us. Complete the reservation form below,
            and our hotel team will review your request and contact you
            regarding confirmation.
        </p>
    </section>


    <?php if (!empty($errors)): ?>
        <div class="booking-alert error" role="alert">
            <strong>Please review the following:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= bookingEscape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>


    <?php if ($successMessage !== ''): ?>

        <div class="booking-alert success" role="status">
            <strong>Reservation request received!</strong><br>
            <?= bookingEscape($successMessage) ?>

            <div class="booking-success-actions">

    
<form
    method="POST"
    action="payments/esewa_initiate.php"
    style="display:inline-block;"
>
    <input
        type="hidden"
        name="payment_csrf"
        value="<?= bookingEscape($_SESSION['public_booking_csrf'] ?? '') ?>"
    >

    <button
        type="submit"
        style="
            display:inline-block;
            margin:5px 8px 0 0;
            padding:12px 18px;
            border:0;
            border-radius:3px;
            background:#174a38;
            color:#fff;
            font-size:12px;
            font-weight:700;
            cursor:pointer;
        "
    >
        Pay 20% Advance with eSewa
    </button>
</form>


    <a href="index.php">Return Home</a>
    <a href="rooms.php">Explore Rooms</a>

</div>
        </div>

    <?php else: ?>

        <div class="booking-container">

            <section class="booking-form-card">

                <h2>Guest Reservation Form</h2>

                <p class="booking-subtitle">
                    Enter your details carefully. Fields marked with *
                    are required.
                </p>

                <form method="POST" action="booking.php" id="bookingForm">

                    <div class="booking-section-title">
                        Choose Your Room
                    </div>

                    <div class="booking-grid">

                        <div class="booking-field full">
                            <label for="room_id">Available Room *</label>

                            <select
                                name="room_id"
                                id="room_id"
                                required
                            >
                                <option value="">-- Select a room --</option>

                                <?php
                                $availableResult = pg_query(
                                    $pgConnection,
                                    "SELECT id, room_number, room_type, price_per_night
                                     FROM rooms
                                     WHERE LOWER(TRIM(status)) = 'available'
                                     ORDER BY price_per_night ASC, room_number ASC"
                                );

                                if ($availableResult !== false):
                                    while ($available = pg_fetch_assoc($availableResult)):
                                        $optionLabel =
                                            'Room ' . $available['room_number']
                                            . ' — ' . formatRoomType($available['room_type'])
                                            . ' — Rs. '
                                            . number_format((float)$available['price_per_night'], 2);
                                ?>
                                        <option
                                            value="<?= (int)$available['id'] ?>"
                                            data-price="<?= bookingEscape($available['price_per_night']) ?>"
                                            <?= (
                                                (string)$available['id'] ===
                                                (string)$form['room_id']
                                            ) ? 'selected' : '' ?>
                                        >
                                            <?= bookingEscape($optionLabel) ?>
                                        </option>
                                <?php
                                    endwhile;

                                    pg_free_result($availableResult);
                                endif;
                                ?>
                            </select>
                        </div>

                    </div>


                    <div class="booking-section-title">
                        Guest Information
                    </div>

                    <div class="booking-grid">

                        <div class="booking-field full">
                            <label for="full_name">Full Name *</label>

                            <input
                                type="text"
                                id="full_name"
                                name="full_name"
                                maxlength="150"
                                autocomplete="name"
                                value="<?= bookingEscape($form['full_name']) ?>"
                                placeholder="Enter your full name"
                                required
                            >
                        </div>

                        <div class="booking-field">
                            <label for="phone">Phone Number *</label>

                            <input
                                type="tel"
                                id="phone"
                                name="phone"
                                maxlength="30"
                                autocomplete="tel"
                                value="<?= bookingEscape($form['phone']) ?>"
                                placeholder="Your contact number"
                                required
                            >
                        </div>

                        <div class="booking-field">
                            <label for="email">Email Address</label>

                            <input
                                type="email"
                                id="email"
                                name="email"
                                maxlength="254"
                                autocomplete="email"
                                value="<?= bookingEscape($form['email']) ?>"
                                placeholder="you@example.com"
                            >
                        </div>

                    </div>


                    <div class="booking-section-title">
                        Your Stay
                    </div>

                    <div class="booking-grid">

                        <div class="booking-field">
                            <label for="check_in">Check-in Date *</label>

                            <input
                                type="date"
                                id="check_in"
                                name="check_in"
                                min="<?= date('Y-m-d') ?>"
                                value="<?= bookingEscape($form['check_in']) ?>"
                                required
                            >
                        </div>

                        <div class="booking-field">
                            <label for="check_out">Check-out Date *</label>

                            <input
                                type="date"
                                id="check_out"
                                name="check_out"
                                min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                                value="<?= bookingEscape($form['check_out']) ?>"
                                required
                            >
                        </div>

                        <div class="booking-field">
                            <label for="adults">Adults *</label>

                            <select id="adults" name="adults" required>
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                    <option
                                        value="<?= $i ?>"
                                        <?= (string)$i === $form['adults'] ? 'selected' : '' ?>
                                    >
                                        <?= $i ?> <?= $i === 1 ? 'Adult' : 'Adults' ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="booking-field">
                            <label for="children">Children</label>

                            <select id="children" name="children">
                                <?php for ($i = 0; $i <= 10; $i++): ?>
                                    <option
                                        value="<?= $i ?>"
                                        <?= (string)$i === $form['children'] ? 'selected' : '' ?>
                                    >
                                        <?= $i ?> <?= $i === 1 ? 'Child' : 'Children' ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="booking-field full">
                            <label for="message">
                                Additional Requests (Optional)
                            </label>

                            <textarea
                                id="message"
                                name="message"
                                maxlength="1000"
                                placeholder="Tell us about any special requests."
                            ><?= bookingEscape($form['message']) ?></textarea>
                        </div>

                    </div>

                    <button type="submit" class="booking-submit">
                        Submit Reservation Request &rarr;
                    </button>

                </form>

            </section>


            <aside class="booking-summary">

                <div class="booking-summary-top">
                    <span>Your Reservation</span>
                    <h2>Stay Summary</h2>
                </div>

                <div class="booking-summary-body">

                    <p>
                        Review your selected room and estimated cost
                        before submitting your request.
                    </p>

                    <div class="booking-summary-row">
                        <span>Selected room</span>
                        <strong id="summaryRoom">Not selected</strong>
                    </div>

                    <div class="booking-summary-row">
                        <span>Price per night</span>
                        <strong id="summaryPrice">Rs. 0.00</strong>
                    </div>

                    <div class="booking-summary-row">
                        <span>Number of nights</span>
                        <strong id="summaryNights">0</strong>
                    </div>

                    <div class="booking-total">
                        <span>Estimated room total</span>
                        <strong id="summaryTotal">Rs. 0.00</strong>
                    </div>

                    <div class="booking-note">
                        <strong>Please note:</strong>
                        This amount is an estimate based on the room's
                        current nightly price. Your request is not
                        confirmed until the hotel team reviews it.
                    </div>

                </div>

            </aside>

        </div>

    <?php endif; ?>

</div>


<script>
(function () {
    const roomSelect = document.getElementById('room_id');
    const checkIn = document.getElementById('check_in');
    const checkOut = document.getElementById('check_out');

    const summaryRoom = document.getElementById('summaryRoom');
    const summaryPrice = document.getElementById('summaryPrice');
    const summaryNights = document.getElementById('summaryNights');
    const summaryTotal = document.getElementById('summaryTotal');

    if (!roomSelect || !checkIn || !checkOut) {
        return;
    }

    function parseDate(value) {
        if (!value) return null;

        const parts = value.split('-').map(Number);

        if (parts.length !== 3) return null;

        const date = new Date(
            Date.UTC(parts[0], parts[1] - 1, parts[2])
        );

        if (
            date.getUTCFullYear() !== parts[0] ||
            date.getUTCMonth() !== parts[1] - 1 ||
            date.getUTCDate() !== parts[2]
        ) {
            return null;
        }

        return date;
    }

    function money(amount) {
        return 'Rs. ' + amount.toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function updateSummary() {
        const option = roomSelect.options[roomSelect.selectedIndex];

        const price = option && option.dataset.price
            ? Number(option.dataset.price)
            : 0;

        const roomLabel = option && option.value
            ? option.textContent.trim()
            : 'Not selected';

        const start = parseDate(checkIn.value);
        const end = parseDate(checkOut.value);

        let nights = 0;

        if (start && end && end > start) {
            nights = Math.round(
                (end.getTime() - start.getTime()) / 86400000
            );
        }

        summaryRoom.textContent = roomLabel;
        summaryPrice.textContent = money(price);
        summaryNights.textContent = String(nights);
        summaryTotal.textContent = money(price * nights);
    }

    checkIn.addEventListener('change', function () {
        if (checkIn.value) {
            const date = parseDate(checkIn.value);

            if (date) {
                date.setUTCDate(date.getUTCDate() + 1);

                const nextDay = [
                    date.getUTCFullYear(),
                    String(date.getUTCMonth() + 1).padStart(2, '0'),
                    String(date.getUTCDate()).padStart(2, '0')
                ].join('-');

                checkOut.min = nextDay;

                if (checkOut.value && checkOut.value <= checkIn.value) {
                    checkOut.value = '';
                }
            }
        }

        updateSummary();
    });

    roomSelect.addEventListener('change', updateSummary);
    checkOut.addEventListener('change', updateSummary);

    updateSummary();
})();
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
