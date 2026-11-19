
<?php
/**
 * ============================================================
 * SUVA HOTEL - ESEWA PAYMENT VERIFICATION
 * File: payment_gateway/esewa_verify.php
 * PHP 8+ | PostgreSQL | eSewa ePay v2
 *
 * IMPORTANT:
 * Never confirm a booking from the browser redirect alone.
 * Verify the signature AND eSewa status API first.
 * ============================================================
 */

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('Asia/Kathmandu');

require_once __DIR__ . '/esewa_config.php';
require_once __DIR__ . '/../config/database.php';

function verifyPage(string $title, string $message, bool $success = false): void
{
    $color = $success ? '#087f5b' : '#b45309';

    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>' . htmlspecialchars($title) . ' - Suva Hotel</title>
        <style>
            *{box-sizing:border-box}
            body{
                margin:0;
                min-height:100vh;
                display:flex;
                align-items:center;
                justify-content:center;
                padding:20px;
                background:#071d18;
                color:#f5f0df;
                font-family:Arial,sans-serif
            }
            .card{
                width:100%;
                max-width:560px;
                padding:40px;
                border:1px solid #b89b55;
                border-radius:18px;
                background:#102b23;
                text-align:center;
                box-shadow:0 20px 60px #0008
            }
            h1{color:#d8bd75;margin-top:0}
            p{line-height:1.7;color:#e4e5dc}
            .status{
                color:' . $color . ';
                font-weight:bold;
                font-size:18px
            }
            a{
                display:inline-block;
                margin-top:20px;
                padding:13px 23px;
                border-radius:7px;
                background:#c5a45b;
                color:#10251e;
                text-decoration:none;
                font-weight:bold
            }
        </style>
    </head>
    <body>
        <main class="card">
            <h1>Suva Hotel</h1>
            <h2>' . htmlspecialchars($title) . '</h2>
            <p class="status">' . htmlspecialchars($message) . '</p>
            <a href="../website/index.php">Return to Hotel Website</a>
        </main>
    </body>
    </html>';

    exit;
}

function verifyAmount($amount): float
{
    // eSewa may return values such as "1,000.0".
    return (float) str_replace(',', '', (string) $amount);
}

function verifyEsewaSignature(array $data, string $secret): bool
{
    if (
        empty($data['signed_field_names']) ||
        empty($data['signature']) ||
        $secret === ''
    ) {
        return false;
    }

    $fields = explode(',', $data['signed_field_names']);
    $messageParts = [];

    foreach ($fields as $field) {
        $field = trim($field);

        if ($field === '' || !array_key_exists($field, $data)) {
            return false;
        }

        $messageParts[] = $field . '=' . $data[$field];
    }

    $message = implode(',', $messageParts);

    $expectedSignature = base64_encode(
        hash_hmac('sha256', $message, $secret, true)
    );

    return hash_equals($expectedSignature, (string) $data['signature']);
}

function databaseErrorMessage($connection): string
{
    return $connection ? pg_last_error($connection) : 'Database connection unavailable.';
}

/* ------------------------------------------------------------
   1. CHECK DATABASE CONNECTION
------------------------------------------------------------ */

if (!$pgConnection) {
    error_log('Suva Hotel eSewa verification: database connection failed.');
    verifyPage('Verification Unavailable', 'We could not connect to the hotel system. Please contact the hotel.');
}

/* ------------------------------------------------------------
   2. CHECK CONFIGURATION
------------------------------------------------------------ */

if (
    !defined('ESEWA_PRODUCT_CODE') ||
    !defined('ESEWA_SECRET_KEY') ||
    ESEWA_PRODUCT_CODE === '' ||
    ESEWA_SECRET_KEY === ''
) {
    verifyPage('Configuration Error', 'Payment verification is not configured. Please contact the hotel.');
}

/* ------------------------------------------------------------
   3. READ ESEWA RESPONSE
------------------------------------------------------------ */

$encodedData = $_GET['data'] ?? '';

if (!is_string($encodedData) || $encodedData === '') {
    verifyPage('Payment Not Verified', 'No payment confirmation was received from eSewa.');
}

$decodedJson = base64_decode($encodedData, true);

if ($decodedJson === false) {
    verifyPage('Invalid Response', 'The payment response could not be decoded.');
}

$response = json_decode($decodedJson, true);

if (!is_array($response)) {
    verifyPage('Invalid Response', 'The payment response format is invalid.');
}

/* ------------------------------------------------------------
   4. VERIFY RESPONSE SIGNATURE
------------------------------------------------------------ */

if (!verifyEsewaSignature($response, ESEWA_SECRET_KEY)) {
    error_log('Suva Hotel: invalid eSewa callback signature.');
    verifyPage('Verification Failed', 'The payment response could not be authenticated.');
}

/* ------------------------------------------------------------
   5. CHECK REQUIRED RESPONSE FIELDS
------------------------------------------------------------ */

$transactionUuid = trim((string) ($response['transaction_uuid'] ?? ''));
$productCode     = trim((string) ($response['product_code'] ?? ''));
$responseStatus  = strtoupper(trim((string) ($response['status'] ?? '')));
$callbackAmount  = verifyAmount($response['total_amount'] ?? 0);
$transactionCode = trim((string) ($response['transaction_code'] ?? ''));

if (
    $transactionUuid === '' ||
    $productCode !== ESEWA_PRODUCT_CODE ||
    $responseStatus !== 'COMPLETE' ||
    $callbackAmount <= 0
) {
    verifyPage('Payment Not Completed', 'eSewa did not return a valid completed payment.');
}

/* ------------------------------------------------------------
   6. FIND THE ORIGINAL PAYMENT TRANSACTION
------------------------------------------------------------ */

$transactionResult = pg_query_params(
    $pgConnection,
    "SELECT
        id,
        booking_id,
        merchant_reference,
        amount,
        payment_status,
        provider_transaction_id,
        reference_code
     FROM online_payment_transactions
     WHERE provider = $1
       AND merchant_reference = $2
     LIMIT 1",
    ['eSewa', $transactionUuid]
);

if (!$transactionResult || pg_num_rows($transactionResult) !== 1) {
    error_log('Suva Hotel: unknown eSewa transaction ' . $transactionUuid);
    verifyPage('Transaction Not Found', 'We could not match this payment with a hotel reservation.');
}

$onlineTransaction = pg_fetch_assoc($transactionResult);
pg_free_result($transactionResult);

$onlineTransactionId = (int) $onlineTransaction['id'];
$bookingId = (int) $onlineTransaction['booking_id'];
$expectedAmount = (float) $onlineTransaction['amount'];

if (abs($callbackAmount - $expectedAmount) > 0.01) {
    error_log('Suva Hotel: eSewa callback amount mismatch for ' . $transactionUuid);
    verifyPage('Amount Mismatch', 'The payment amount does not match the reservation.');
}

/* ------------------------------------------------------------
   7. ASK ESEWA STATUS API
------------------------------------------------------------ */

$statusUrl = ESEWA_STATUS_URL;
$query = http_build_query([
    'product_code' => ESEWA_PRODUCT_CODE,
    'total_amount' => number_format($expectedAmount, 2, '.', ''),
    'transaction_uuid' => $transactionUuid
]);

$curl = curl_init($statusUrl . '?' . $query);

curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPGET => true
]);

$apiResponse = curl_exec($curl);
$httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
$curlError = curl_error($curl);

curl_close($curl);

if ($apiResponse === false || $httpStatus !== 200) {
    error_log('Suva Hotel eSewa status API error: ' . $curlError);
    verifyPage('Verification Pending', 'We could not confirm the payment with eSewa right now. Please contact the hotel before trying again.');
}

$statusData = json_decode($apiResponse, true);

if (!is_array($statusData)) {
    verifyPage('Verification Pending', 'eSewa returned an unreadable status response. Please contact the hotel.');
}

$apiStatus = strtoupper(trim((string) ($statusData['status'] ?? '')));
$apiUuid = trim((string) ($statusData['transaction_uuid'] ?? ''));
$apiProduct = trim((string) ($statusData['product_code'] ?? ''));
$apiAmount = verifyAmount($statusData['total_amount'] ?? 0);
$referenceCode = trim((string) ($statusData['ref_id'] ?? ''));

if (
    $apiStatus !== 'COMPLETE' ||
    $apiUuid !== $transactionUuid ||
    $apiProduct !== ESEWA_PRODUCT_CODE ||
    abs($apiAmount - $expectedAmount) > 0.01
) {
    verifyPage('Payment Not Confirmed', 'eSewa has not confirmed this payment. Your reservation has not been confirmed.');
}

/* ------------------------------------------------------------
   8. RECORD PAYMENT AND CONFIRM BOOKING SAFELY
------------------------------------------------------------ */

if (!pg_query($pgConnection, 'BEGIN')) {
    verifyPage('System Error', 'The hotel could not complete payment processing. Please contact the hotel.');
}

try {
    /*
     * Lock payment transaction so two callbacks cannot
     * record the same payment simultaneously.
     */
    $lockedResult = pg_query_params(
        $pgConnection,
        "SELECT *
         FROM online_payment_transactions
         WHERE id = $1
         FOR UPDATE",
        [$onlineTransactionId]
    );

    if (!$lockedResult || pg_num_rows($lockedResult) !== 1) {
        throw new Exception('Online payment transaction not found.');
    }

    $lockedTransaction = pg_fetch_assoc($lockedResult);
    pg_free_result($lockedResult);

    $currentPaymentStatus = strtoupper(
        trim((string) $lockedTransaction['payment_status'])
    );

    if (in_array($currentPaymentStatus, ['COMPLETE', 'COMPLETE_REVIEW'], true)) {
        pg_query($pgConnection, 'COMMIT');

        if ($currentPaymentStatus === 'COMPLETE') {
            verifyPage('Payment Successful', 'Your advance payment has already been verified.', true);
        }

        verifyPage('Payment Received', 'Your payment was received, but the reservation requires hotel staff review.');
    }

    if (abs((float) $lockedTransaction['amount'] - $expectedAmount) > 0.01) {
        throw new Exception('Stored payment amount changed.');
    }

    /*
     * Lock the booking.
     */
    $bookingResult = pg_query_params(
        $pgConnection,
        "SELECT id, room_id, check_in, check_out, status, total_amount
         FROM bookings
         WHERE id = $1
         FOR UPDATE",
        [$bookingId]
    );

    if (!$bookingResult || pg_num_rows($bookingResult) !== 1) {
        throw new Exception('Booking not found.');
    }

    $booking = pg_fetch_assoc($bookingResult);
    pg_free_result($bookingResult);

    /*
     * Lock the room so concurrent confirmations for this
     * room are processed one at a time.
     */
    $roomResult = pg_query_params(
        $pgConnection,
        "SELECT id, status
         FROM rooms
         WHERE id = $1
         FOR UPDATE",
        [(int) $booking['room_id']]
    );

    if (!$roomResult || pg_num_rows($roomResult) !== 1) {
        throw new Exception('Room not found.');
    }

    $room = pg_fetch_assoc($roomResult);
    pg_free_result($roomResult);

    $bookingStatus = strtolower(trim((string) $booking['status']));

    if (in_array($bookingStatus, ['cancelled', 'rejected', 'completed', 'checked out', 'checked_out'], true)) {
        throw new Exception('Booking is no longer eligible for confirmation.');
    }

    /*
     * Check whether another active booking overlaps
     * the same room and dates.
     */
    $overlapResult = pg_query_params(
        $pgConnection,
        "SELECT COUNT(*) AS conflict_count
         FROM bookings
         WHERE room_id = $1
           AND id <> $2
           AND check_in < $3
           AND check_out > $4
           AND LOWER(TRIM(status)) NOT IN
               ('cancelled', 'rejected', 'completed', 'checked out', 'checked_out')",
        [
            (int) $booking['room_id'],
            $bookingId,
            $booking['check_out'],
            $booking['check_in']
        ]
    );

    if (!$overlapResult) {
        throw new Exception('Could not check room availability.');
    }

    $overlapRow = pg_fetch_assoc($overlapResult);
    pg_free_result($overlapResult);

    $hasDateConflict = (int) $overlapRow['conflict_count'] > 0;
    $roomIsAvailable = strtolower(trim((string) $room['status'])) === 'available';

    /*
     * A verified payment must be recorded even if the room
     * has become unavailable. In that case, staff must review
     * the reservation and arrange the appropriate next step.
     */
    $paymentInsert = pg_query_params(
        $pgConnection,
        "INSERT INTO payments
            (booking_id, amount, payment_method, payment_status, paid_at)
         VALUES ($1, $2, $3, $4, CURRENT_TIMESTAMP)",
        [
            $bookingId,
            $expectedAmount,
            'eSewa Online',
            'Paid'
        ]
    );

    if (!$paymentInsert) {
        throw new Exception('Could not record the verified payment.');
    }

    $needsReview = $hasDateConflict || !$roomIsAvailable;

    if ($needsReview) {
        $newTransactionStatus = 'COMPLETE_REVIEW';
        $newBookingStatus = (string) $booking['status'];
    } else {
        $newTransactionStatus = 'COMPLETE';
        $newBookingStatus = 'Confirmed';
    }

    /*
     * Update online transaction only after verification.
     */
    $updateTransaction = pg_query_params(
        $pgConnection,
        "UPDATE online_payment_transactions
         SET payment_status = $1,
             provider_transaction_id = $2,
             reference_code = $3,
             verified_at = CURRENT_TIMESTAMP,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = $4",
        [
            $newTransactionStatus,
            $transactionCode !== '' ? $transactionCode : $referenceCode,
            $referenceCode,
            $onlineTransactionId
        ]
    );

    if (!$updateTransaction) {
        throw new Exception('Could not update the online payment record.');
    }

    /*
     * Do not change room status to occupied here.
     * Occupied should be set during the actual guest check-in.
     */
    if (!$needsReview) {
        $updateBooking = pg_query_params(
            $pgConnection,
            "UPDATE bookings
             SET status = $1
             WHERE id = $2",
            [$newBookingStatus, $bookingId]
        );

        if (!$updateBooking) {
            throw new Exception('Could not confirm the reservation.');
        }
    }

    if (!pg_query($pgConnection, 'COMMIT')) {
        throw new Exception('Could not commit payment verification.');
    }

    if ($needsReview) {
        verifyPage(
            'Payment Received - Review Required',
            'Your advance payment was verified. The room needs staff review, so the hotel will contact you about your reservation.'
        );
    }

    verifyPage(
        'Payment Successful',
        'Your advance payment has been verified and your reservation is confirmed.',
        true
    );

} catch (Throwable $e) {
    pg_query($pgConnection, 'ROLLBACK');

    error_log(
        'Suva Hotel eSewa verification error for booking ' .
        $bookingId . ': ' . $e->getMessage()
    );

    verifyPage(
        'Payment Review Required',
        'We received a payment response but could not finish updating the reservation. Please contact the hotel with your reservation reference.'
    );
}
