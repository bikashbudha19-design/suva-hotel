
<?php
/**
 * ============================================================
 * SUVA HOTEL — ESEWA PAYMENT INITIATION
 * File: payment_gateway/esewa_initiate.php
 * PHP 8+ | PostgreSQL | XAMPP
 *
 * Starts a 20% advance payment for a public reservation.
 * Uses eSewa ePay v2.
 * ============================================================
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('Asia/Kathmandu');

require_once __DIR__ . '/esewa_config.php';
require_once __DIR__ . '/../../config/database.php';

/* ------------------------------------------------------------
   1. BASIC VALIDATION
------------------------------------------------------------ */

function esewaInitiateError(string $message, int $statusCode = 400): void
{
    http_response_code($statusCode);

    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Payment | Suva Hotel</title>
        <style>
            body {
                margin: 0;
                min-height: 100vh;
                display: grid;
                place-items: center;
                background: #f7f5ef;
                font-family: Arial, sans-serif;
                color: #26382f;
            }
            .card {
                width: min(440px, calc(100% - 40px));
                padding: 35px;
                box-sizing: border-box;
                background: white;
                border: 1px solid #e8e1d3;
                text-align: center;
                box-shadow: 0 10px 35px rgba(0,0,0,.06);
            }
            h2 { color: #174a38; }
            p { line-height: 1.7; color: #667168; }
            a {
                display: inline-block;
                margin-top: 15px;
                padding: 12px 20px;
                background: #174a38;
                color: white;
                text-decoration: none;
            }
        </style>
    </head>
    <body>
        <div class="card">
            <h2>Payment could not start</h2>
            <p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>
            <a href="../booking.php">Return to Reservations</a>
        </div>
    </body>
    </html>';

    exit;
}

/* ------------------------------------------------------------
   2. ONLY ACCEPT POST REQUESTS
------------------------------------------------------------ */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    esewaInitiateError('Invalid payment request.');
}

/* ------------------------------------------------------------
   3. VERIFY SAME-SESSION BOOKING AND CSRF TOKEN
------------------------------------------------------------ */

$bookingId = (int)($_SESSION['public_booking_id'] ?? 0);

$sessionToken = (string)($_SESSION['public_booking_csrf'] ?? '');
$submittedToken = (string)($_POST['payment_csrf'] ?? '');

if ($bookingId < 1 || $sessionToken === '' || $submittedToken === '') {
    esewaInitiateError(
        'Your reservation session has expired. Please submit your reservation again.'
    );
}

if (!hash_equals($sessionToken, $submittedToken)) {
    esewaInitiateError('Invalid security token. Please try again.');
}

/* ------------------------------------------------------------
   4. CHECK ESEWA CONFIGURATION
------------------------------------------------------------ */

if (
    !defined('ESEWA_MODE') ||
    !defined('ESEWA_PRODUCT_CODE') ||
    !defined('ESEWA_SECRET_KEY') ||
    !defined('ESEWA_PAYMENT_URL')
) {
    error_log('Suva Hotel: eSewa configuration is incomplete.');

    esewaInitiateError('Payment configuration is incomplete.', 500);
}

if (
    ESEWA_PRODUCT_CODE === '' ||
    ESEWA_SECRET_KEY === ''
) {
    esewaInitiateError(
        'The eSewa sandbox merchant configuration has not been completed yet.',
        503
    );
}

if (!isset($pgConnection) || !$pgConnection) {
    error_log('Suva Hotel: PostgreSQL connection unavailable.');

    esewaInitiateError('Database connection is unavailable.', 500);
}

/* ------------------------------------------------------------
   5. LOAD THE PENDING BOOKING
------------------------------------------------------------ */

$bookingResult = pg_query_params(
    $pgConnection,
    "SELECT
        b.id,
        b.guest_id,
        b.room_id,
        b.check_in,
        b.check_out,
        b.total_amount,
        b.status,
        g.full_name,
        r.room_number,
        r.status AS room_status
     FROM bookings b
     INNER JOIN guests g ON g.id = b.guest_id
     INNER JOIN rooms r ON r.id = b.room_id
     WHERE b.id = $1
     LIMIT 1",
    [$bookingId]
);

if ($bookingResult === false) {
    error_log('eSewa booking query error: ' . pg_last_error($pgConnection));

    esewaInitiateError('Could not load your reservation.', 500);
}

$booking = pg_fetch_assoc($bookingResult);
pg_free_result($bookingResult);

if (!$booking) {
    esewaInitiateError('Reservation not found.', 404);
}

/* Only public pending reservations can use this checkout. */

if (strtolower(trim($booking['status'])) !== 'pending') {
    esewaInitiateError(
        'This reservation is not awaiting online payment.'
    );
}

/* Do not start payment for a room that is no longer available. */

if (strtolower(trim($booking['room_status'])) !== 'available') {
    esewaInitiateError(
        'The selected room is no longer available. Please contact the hotel.'
    );
}

/* ------------------------------------------------------------
   6. CALCULATE THE 20% ADVANCE
------------------------------------------------------------ */

$totalAmount = round((float)$booking['total_amount'], 2);

if ($totalAmount <= 0) {
    esewaInitiateError('The reservation amount is invalid.');
}

$advancePercent = (float)HOTEL_ADVANCE_PERCENT;

if ($advancePercent <= 0 || $advancePercent > 100) {
    esewaInitiateError('Invalid advance payment configuration.', 500);
}

$advanceAmount = round(
    $totalAmount * ($advancePercent / 100),
    2
);

if ($advanceAmount <= 0) {
    esewaInitiateError('The advance amount is invalid.');
}

/* ------------------------------------------------------------
   7. GENERATE UNIQUE TRANSACTION REFERENCE
------------------------------------------------------------ */

/*
 * eSewa transaction_uuid supports letters, numbers and hyphens.
 * The merchant_reference column has a UNIQUE constraint.
 */

try {
    $transactionUuid =
        'SHB-' . $bookingId . '-' . strtoupper(bin2hex(random_bytes(6)));
} catch (Throwable $exception) {
    error_log('eSewa reference generation error: ' . $exception->getMessage());

    esewaInitiateError('Could not create a payment reference.', 500);
}

$merchantReference = $transactionUuid;

/* ------------------------------------------------------------
   8. PREPARE ESEWA ePAY V2 FORM FIELDS
------------------------------------------------------------ */

$amountString = number_format($advanceAmount, 2, '.', '');

$fields = [
    'amount'                  => $amountString,
    'tax_amount'              => '0',
    'total_amount'            => $amountString,
    'transaction_uuid'        => $transactionUuid,
    'product_code'            => ESEWA_PRODUCT_CODE,
    'product_service_charge'  => '0',
    'product_delivery_charge' => '0',
    'success_url'             => ESEWA_SUCCESS_URL,
    'failure_url'             => ESEWA_FAILURE_URL,
    'signed_field_names'      => 'total_amount,transaction_uuid,product_code'
];

/*
 * eSewa ePay v2 signature:
 *
 * total_amount=...,transaction_uuid=...,product_code=...
 *
 * HMAC SHA-256, Base64 encoded.
 */

$signatureMessage =
    'total_amount=' . $fields['total_amount']
    . ',transaction_uuid=' . $fields['transaction_uuid']
    . ',product_code=' . $fields['product_code'];

$fields['signature'] = base64_encode(
    hash_hmac(
        'sha256',
        $signatureMessage,
        ESEWA_SECRET_KEY,
        true
    )
);

/* ------------------------------------------------------------
   9. SAVE PAYMENT ATTEMPT IN POSTGRESQL
------------------------------------------------------------ */

$transactionStarted = false;

try {
    if (!pg_query($pgConnection, 'BEGIN')) {
        throw new RuntimeException('Could not begin payment transaction.');
    }

    $transactionStarted = true;

    /*
     * Lock the booking while creating its payment attempt.
     * Do not change its Pending status or the room status here.
     */

    $lockResult = pg_query_params(
        $pgConnection,
        "SELECT id, status, total_amount
         FROM bookings
         WHERE id = $1
         FOR UPDATE",
        [$bookingId]
    );

    if ($lockResult === false) {
        throw new RuntimeException('Could not lock booking.');
    }

    $lockedBooking = pg_fetch_assoc($lockResult);
    pg_free_result($lockResult);

    if (
        !$lockedBooking ||
        strtolower(trim($lockedBooking['status'])) !== 'pending'
    ) {
        throw new RuntimeException('Booking is no longer pending.');
    }

    if (
        round((float)$lockedBooking['total_amount'], 2) !== $totalAmount
    ) {
        throw new RuntimeException('Booking amount changed. Please try again.');
    }

    /*
     * Record the payment attempt.
     * The gateway status starts as INITIATED.
     */

    $insertResult = pg_query_params(
        $pgConnection,
        "INSERT INTO online_payment_transactions (
            booking_id,
            provider,
            merchant_reference,
            amount,
            currency,
            payment_status,
            checkout_url,
            created_at,
            updated_at
        )
        VALUES (
            $1,
            'eSewa',
            $2,
            $3,
            $4,
            'INITIATED',
            $5,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        )
        RETURNING id",
        [
            $bookingId,
            $merchantReference,
            $advanceAmount,
            HOTEL_CURRENCY,
            ESEWA_PAYMENT_URL
        ]
    );

    if ($insertResult === false) {
        throw new RuntimeException('Could not save online payment attempt.');
    }

    $transactionId = (int)pg_fetch_result($insertResult, 0, 0);
    pg_free_result($insertResult);

    if ($transactionId < 1) {
        throw new RuntimeException('Invalid online payment transaction ID.');
    }

    if (!pg_query($pgConnection, 'COMMIT')) {
        throw new RuntimeException('Could not commit payment attempt.');
    }

    $transactionStarted = false;

    /*
     * Consume the session authorization so this exact checkout
     * cannot be initiated again by refreshing the page.
     */

    unset(
        $_SESSION['public_booking_id'],
        $_SESSION['public_booking_csrf']
    );

} catch (Throwable $exception) {
    if ($transactionStarted) {
        @pg_query($pgConnection, 'ROLLBACK');
    }

    error_log(
        'Suva Hotel eSewa initiation error: '
        . $exception->getMessage()
    );

    esewaInitiateError(
        'We could not prepare your payment. Please contact the hotel team.',
        500
    );
}

/* ------------------------------------------------------------
   10. AUTO-SUBMIT SIGNED FORM TO ESEWA
------------------------------------------------------------ */

function esewaHtmlEscape($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Redirecting to eSewa | Suva Hotel</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: #f7f5ef;
            font-family: Arial, sans-serif;
            color: #26382f;
        }

        .payment-card {
            width: min(440px, calc(100% - 35px));
            padding: 38px 28px;
            background: #fff;
            border: 1px solid #e8e1d3;
            text-align: center;
            box-shadow: 0 12px 40px rgba(25, 53, 39, .08);
        }

        .hotel-name {
            color: #a08143;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 3px;
        }

        h1 {
            margin: 20px 0 10px;
            color: #174a38;
            font-family: Georgia, serif;
            font-weight: normal;
        }

        p {
            color: #68746b;
            font-size: 13px;
            line-height: 1.8;
        }

        .amount {
            margin: 22px 0;
            color: #174a38;
            font-size: 29px;
            font-weight: bold;
        }

        .pay-button {
            width: 100%;
            padding: 14px;
            border: 0;
            background: #174a38;
            color: white;
            font-size: 13px;
            font-weight: bold;
            cursor: pointer;
        }

        .small-note {
            margin-top: 18px;
            font-size: 11px;
        }
    </style>
</head>

<body>

    <main class="payment-card">

        <div class="hotel-name">SUVA HOTEL</div>

        <h1>Secure Advance Payment</h1>

        <p>
            Your reservation request has been saved.
            You are being redirected to eSewa to pay the required advance.
        </p>

        <div class="amount">
            Rs. <?= esewaHtmlEscape($amountString) ?>
        </div>

        <p>
            Reservation #<?= (int)$bookingId ?><br>
            Payment reference: <?= esewaHtmlEscape($transactionUuid) ?>
        </p>

        <form
            id="esewaPaymentForm"
            method="POST"
            action="<?= esewaHtmlEscape(ESEWA_PAYMENT_URL) ?>"
        >

            <?php foreach ($fields as $name => $value): ?>

                <input
                    type="hidden"
                    name="<?= esewaHtmlEscape($name) ?>"
                    value="<?= esewaHtmlEscape($value) ?>"
                >

            <?php endforeach; ?>

            <button type="submit" class="pay-button">
                Continue to eSewa
            </button>

        </form>

        <p class="small-note">
            Please do not refresh or close this page while payment is starting.
        </p>

    </main>

    <script>
        window.addEventListener('load', function () {
            document.getElementById('esewaPaymentForm').submit();
        });
    </script>

</body>
</html>
