
<?php
session_start();

header('Content-Type: text/html; charset=utf-8');

function stopRequest(int $code, string $message): void {
    http_response_code($code);
    echo '<h2>SUVA HOTEL — Booking SMS</h2>';
    echo '<p>' . htmlspecialchars(
        $message,
        ENT_QUOTES,
        'UTF-8'
    ) . '</p>';
    echo '<p><a href="bookings/add.php">Return to booking page</a></p>';
    exit;
}

if (
    empty($_SESSION['user_id']) ||
    !in_array(
        strtolower(trim($_SESSION['role'] ?? '')),
        ['admin', 'staff'],
        true
    )
) {
    stopRequest(403, 'Please log in as Admin or Staff.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    stopRequest(405, 'POST requests only.');
}

$csrf = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_token']) ||
    !is_string($csrf) ||
    !hash_equals($_SESSION['csrf_token'], $csrf)
) {
    stopRequest(403, 'Security check failed. Reload the booking page.');
}

$bookingId = filter_input(
    INPUT_POST,
    'booking_id',
    FILTER_VALIDATE_INT
);

if (!$bookingId || $bookingId < 1) {
    stopRequest(400, 'Invalid booking ID.');
}

require_once __DIR__ . '/config/database.php';

$claimed = false;
$transactionStarted = false;
$smsId = null;

try {
    $config = require __DIR__ . '/private/sms_config.php';

    $token = getenv('KUSHA_SMS_TOKEN')
        ?: ($config['api_token'] ?? '');

    if (!is_string($token) || trim($token) === '') {
        stopRequest(500, 'Kusha SMS configuration is missing.');
    }

    if (!function_exists('curl_init')) {
        stopRequest(500, 'PHP cURL is not enabled.');
    }

    if (!pg_query($pgConnection, 'BEGIN')) {
        throw new RuntimeException('Could not start transaction.');
    }

    $transactionStarted = true;

    // Load and lock the booking and guest details.
    $result = pg_query_params(
        $pgConnection,
        "SELECT
            b.id,
            b.status,
            b.check_in,
            b.check_out,
            b.total_amount,
            g.full_name,
            g.phone,
            r.room_number
         FROM bookings b
         JOIN guests g ON g.id = b.guest_id
         JOIN rooms r ON r.id = b.room_id
         WHERE b.id = $1
         FOR UPDATE OF b",
        [$bookingId]
    );

    if (!$result) {
        throw new RuntimeException('Could not load booking details.');
    }

    $booking = pg_fetch_assoc($result);

    if (!$booking) {
        pg_query($pgConnection, 'ROLLBACK');
        $transactionStarted = false;
        stopRequest(404, 'Booking not found.');
    }

    if (
        strtolower(trim($booking['status'])) !== 'confirmed'
    ) {
        pg_query($pgConnection, 'ROLLBACK');
        $transactionStarted = false;
        stopRequest(
            409,
            'Only confirmed bookings can receive a booking confirmation SMS.'
        );
    }

    // Normalize and validate the Nepali mobile number.
    $phone = preg_replace(
        '/[\s\-()]/',
        '',
        trim($booking['phone'] ?? '')
    );

    if (str_starts_with($phone, '+977')) {
        $phone = substr($phone, 4);
    } elseif (str_starts_with($phone, '977')) {
        $phone = substr($phone, 3);
    }

    if (!preg_match('/^9[0-9]{9}$/', $phone)) {
        pg_query($pgConnection, 'ROLLBACK');
        $transactionStarted = false;
        stopRequest(
            400,
            'The guest phone number is missing or is not a valid 10-digit Nepali mobile number.'
        );
    }

    // Do not send a second confirmation for this booking.
    $existingResult = pg_query_params(
        $pgConnection,
        "SELECT id, sms_status
         FROM booking_confirmation_sms
         WHERE booking_id = $1
         FOR UPDATE",
        [$bookingId]
    );

    if (!$existingResult) {
        throw new RuntimeException('Could not check previous SMS records.');
    }

    $existing = pg_fetch_assoc($existingResult);

    if ($existing) {
        pg_query($pgConnection, 'ROLLBACK');
        $transactionStarted = false;

        stopRequest(
            409,
            'A confirmation SMS record already exists with status '
            . $existing['sms_status']
            . '. Check Kusha SMS reports before considering another send.'
        );
    }

    $guestName = trim($booking['full_name']);

    $message = 'Suva Hotel booking confirmation: Dear '
        . $guestName
        . ', your booking is confirmed. Room '
        . $booking['room_number']
        . ', check-in '
        . $booking['check_in']
        . ', check-out '
        . $booking['check_out']
        . '. Total booking amount: Rs. '
        . number_format((float)$booking['total_amount'], 2)
        . '. Please contact Suva Hotel if you need assistance.';

    // Reserve the booking before contacting the SMS provider.
    $insert = pg_query_params(
        $pgConnection,
        "INSERT INTO booking_confirmation_sms
            (booking_id, phone, message, sms_status)
         VALUES ($1, $2, $3, 'sending')
         ON CONFLICT (booking_id) DO NOTHING
         RETURNING id",
        [$bookingId, $phone, $message]
    );

    if (!$insert || !($row = pg_fetch_assoc($insert))) {
        pg_query($pgConnection, 'ROLLBACK');
        $transactionStarted = false;
        stopRequest(
            409,
            'This booking already has an SMS record. Check its status before retrying.'
        );
    }

    $smsId = (int)$row['id'];

    if (!pg_query($pgConnection, 'COMMIT')) {
        throw new RuntimeException('Could not reserve the SMS record.');
    }

    $transactionStarted = false;
    $claimed = true;

    // Send through Kusha SMS API v4.
    $payload = json_encode([
        'to' => [$phone],
        'text' => [$message]
    ], JSON_UNESCAPED_UNICODE);

    if ($payload === false) {
        throw new RuntimeException('Could not encode SMS message.');
    }

    $ch = curl_init('https://kushasms.com/sms/v4/send-user');

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'auth-token: ' . trim($token),
            'Content-Type: application/json',
            'Idempotency-Key' =>
                'suva-booking-' . $bookingId . '-confirmation-v1'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($response === false) {
        pg_query_params(
            $pgConnection,
            "UPDATE booking_confirmation_sms
             SET sms_status = 'unknown'
             WHERE id = $1",
            [$smsId]
        );

        error_log('SUVA booking SMS network error: ' . $curlError);

        stopRequest(
            502,
            'The provider response could not be confirmed. Check Kusha SMS reports before retrying.'
        );
    }

    $data = json_decode($response, true);

    $valid = $data['responses'][0]['data']['valid'] ?? [];
    $providerError = $data['responses'][0]['error'] ?? true;

    $queued = is_array($valid)
        && count($valid) > 0
        && !$providerError
        && ($valid[0]['status'] ?? '') === 'queued';

    $newStatus = $queued ? 'queued' : 'failed';

   
$update = pg_query_params(
    $pgConnection,
    "UPDATE booking_confirmation_sms
     SET sms_status = $1::varchar,
         sent_at = CASE
             WHEN $1::varchar = 'queued' THEN CURRENT_TIMESTAMP
             ELSE sent_at
         END
     WHERE id = $2",
    [$newStatus, $smsId]
);



    if (!$update) {
        error_log('SUVA booking SMS: Could not save provider status.');
        stopRequest(
            500,
            'The provider responded, but the SMS status could not be saved. Check Kusha SMS reports before retrying.'
        );
    }

    if (!$queued) {
        error_log(
            'SUVA booking SMS rejection: HTTP '
            . $httpCode
            . '; response='
            . substr($response, 0, 1500)
        );

        stopRequest(
            502,
            'Kusha SMS did not confirm the message was queued. Check the provider report before retrying.'
        );
    }

    echo '<h2>Booking confirmation SMS accepted!</h2>';
    echo '<p>Booking #: ' . (int)$bookingId . '</p>';
    echo '<p>Guest: ' . htmlspecialchars(
        $guestName,
        ENT_QUOTES,
        'UTF-8'
    ) . '</p>';
    echo '<p>Phone ending: '
        . htmlspecialchars(substr($phone, -4), ENT_QUOTES, 'UTF-8')
        . '</p>';
    echo '<p>Status: Queued by Kusha SMS.</p>';
    echo '<p><a href="bookings/add.php">Return to booking page</a></p>';

} catch (Throwable $e) {
    if ($transactionStarted) {
        pg_query($pgConnection, 'ROLLBACK');
    }

    error_log('SUVA booking confirmation SMS error: ' . $e->getMessage());

    if ($claimed && $smsId !== null) {
        pg_query_params(
            $pgConnection,
            "UPDATE booking_confirmation_sms
             SET sms_status = 'unknown'
             WHERE id = $1 AND sms_status = 'sending'",
            [$smsId]
        );
    }

    stopRequest(
        500,
        'An error occurred. Check the PHP error log and Kusha SMS reports before retrying.'
    );
}
