
<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

function respond(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// Only logged-in Admin and Staff may send messages.
if (
    empty($_SESSION['user_id']) ||
    !in_array(
        strtolower(trim($_SESSION['role'] ?? '')),
        ['admin', 'staff'],
        true
    )
) {
    respond(403, [
        'success' => false,
        'message' => 'Authorized Admin or Staff login required.'
    ]);
}

// Require POST and a session CSRF token.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, [
        'success' => false,
        'message' => 'POST requests only.'
    ]);
}

$csrf = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_token']) ||
    !is_string($csrf) ||
    !hash_equals($_SESSION['csrf_token'], $csrf)
) {
    respond(403, [
        'success' => false,
        'message' => 'Security check failed. Reload the page and try again.'
    ]);
}

$bookingId = filter_input(
    INPUT_POST,
    'booking_id',
    FILTER_VALIDATE_INT
);

if (!$bookingId || $bookingId < 1) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid booking ID.'
    ]);
}

require_once __DIR__ . '/config/database.php';

$transactionStarted = false;
$claimed = false;

try {
    // Load the private API configuration.
    $smsConfig = require __DIR__ . '/private/sms_config.php';

    $token = getenv('KUSHA_SMS_TOKEN')
        ?: ($smsConfig['api_token'] ?? '');

    if (!is_string($token) || trim($token) === '') {
        respond(500, [
            'success' => false,
            'message' => 'SMS configuration is missing. Check the server configuration.'
        ]);
    }

    if (!function_exists('curl_init')) {
        respond(500, [
            'success' => false,
            'message' => 'PHP cURL is not enabled. Check your XAMPP PHP configuration.'
        ]);
    }

    if (!pg_query($pgConnection, 'BEGIN')) {
        throw new RuntimeException('Could not start transaction.');
    }

    $transactionStarted = true;

    // Lock the booking and its prepared message.
    $result = pg_query_params(
        $pgConnection,
        "SELECT
            s.id AS sms_id,
            s.booking_id,
            s.phone,
            s.message,
            s.sms_status,
            b.status AS booking_status,
            g.full_name
         FROM guest_welcome_sms s
         JOIN bookings b ON b.id = s.booking_id
         JOIN guests g ON g.id = b.guest_id
         WHERE s.booking_id = $1
         FOR UPDATE OF s, b",
        [$bookingId]
    );

    if (!$result) {
        throw new RuntimeException('Could not load the prepared message.');
    }

    $sms = pg_fetch_assoc($result);

    if (!$sms) {
        pg_query($pgConnection, 'ROLLBACK');
        $transactionStarted = false;

        respond(404, [
            'success' => false,
            'message' => 'No welcome message exists for this booking.'
        ]);
    }

    if (strtolower(trim($sms['booking_status'])) !== 'checked in') {
        pg_query($pgConnection, 'ROLLBACK');
        $transactionStarted = false;

        respond(409, [
            'success' => false,
            'message' => 'The guest must be checked in first.'
        ]);
    }

    // Never automatically send a message that is not prepared.
    if ($sms['sms_status'] !== 'prepared') {
        pg_query($pgConnection, 'ROLLBACK');
        $transactionStarted = false;

        respond(409, [
            'success' => false,
            'message' => 'This message is not in prepared status. Current status: '
                . $sms['sms_status']
                . '. Check the SMS provider report before retrying.'
        ]);
    }

    // Normalize a Nepali mobile number.
    $phone = preg_replace('/[\s\-()]/', '', trim($sms['phone']));

    if (str_starts_with($phone, '+977')) {
        $phone = substr($phone, 4);
    } elseif (str_starts_with($phone, '977')) {
        $phone = substr($phone, 3);
    }

    if (!preg_match('/^9[0-9]{9}$/', $phone)) {
        pg_query($pgConnection, 'ROLLBACK');
        $transactionStarted = false;

        respond(400, [
            'success' => false,
            'message' => 'The saved phone number is not a valid 10-digit Nepali mobile number.'
        ]);
    }

    // Claim the record before contacting the provider.
    $claim = pg_query_params(
        $pgConnection,
        "UPDATE guest_welcome_sms
         SET sms_status = 'sending', phone = $1
         WHERE id = $2 AND sms_status = 'prepared'
         RETURNING id",
        [$phone, $sms['sms_id']]
    );

    if (!$claim || !pg_fetch_assoc($claim)) {
        throw new RuntimeException('Could not reserve this message.');
    }

    if (!pg_query($pgConnection, 'COMMIT')) {
        throw new RuntimeException('Could not commit the message reservation.');
    }

    $transactionStarted = false;
    $claimed = true;

    // Send through Kusha SMS API v4.
    $payload = json_encode([
        'to' => [$phone],
        'text' => [$sms['message']]
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://kushasms.com/sms/v4/send-user');

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'auth-token: ' . trim($token),
            'Content-Type: application/json',
            'Idempotency-Key: suva-booking-' . $bookingId . '-welcome-v1'
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

    // A timeout can mean the provider accepted the message.
    // Do not reset it to prepared or blindly send it again.
    if ($response === false) {
        pg_query_params(
            $pgConnection,
            "UPDATE guest_welcome_sms
             SET sms_status = 'unknown'
             WHERE id = $1 AND sms_status = 'sending'",
            [$sms['sms_id']]
        );

        error_log('SUVA HOTEL SMS network error: ' . $curlError);

        respond(502, [
            'success' => false,
            'message' => 'The provider response could not be confirmed. Check Kusha SMS reports before retrying.'
        ]);
    }

    $data = json_decode($response, true);

    $valid = $data['responses'][0]['data']['valid'] ?? [];
    $providerError = $data['responses'][0]['error'] ?? true;
    $queued = is_array($valid)
        && count($valid) > 0
        && !$providerError
        && ($valid[0]['status'] ?? '') === 'queued';

    if ($queued) {
        $newStatus = 'queued';
    } else {
        // Record a provider rejection; inspect the provider response
        // before deciding whether another attempt is appropriate.
        $newStatus = 'failed';
    }

    $update = pg_query_params(
        $pgConnection,
        "UPDATE guest_welcome_sms
         SET sms_status = $1
         WHERE id = $2 AND sms_status IN ('sending', 'unknown')",
        [$newStatus, $sms['sms_id']]
    );

    if (!$update) {
        error_log('SUVA HOTEL: Could not save SMS provider result.');
        respond(500, [
            'success' => false,
            'message' => 'The provider responded, but the database status could not be saved. Check Kusha SMS reports before retrying.'
        ]);
    }

    if (!$queued) {
        error_log(
            'SUVA HOTEL SMS provider rejection: HTTP ' . $httpCode
            . '; response=' . substr($response, 0, 1500)
        );

        respond(502, [
            'success' => false,
            'message' => 'Kusha SMS did not confirm that the message was queued. Check the provider response and account configuration.'
        ]);
    }

    respond(200, [
        'success' => true,
        'message' => 'Kusha SMS accepted the welcome message for delivery.',
        'booking_id' => (int)$bookingId,
        'guest_name' => $sms['full_name'],
        'room_message' => 'Room welcome message',
        'phone_last_four' => substr($phone, -4),
        'sms_status' => 'queued'
    ]);

} catch (Throwable $e) {
    if ($transactionStarted) {
        pg_query($pgConnection, 'ROLLBACK');
    }

    error_log('SUVA HOTEL SMS error: ' . $e->getMessage());

    // If the API call may already have happened, do not make the
    // message available for a blind second send.
    if ($claimed) {
        pg_query_params(
            $pgConnection,
            "UPDATE guest_welcome_sms
             SET sms_status = 'unknown'
             WHERE booking_id = $1 AND sms_status = 'sending'",
            [$bookingId]
        );
    }

    respond(500, [
        'success' => false,
        'message' => 'An error occurred. Check the PHP error log and Kusha SMS reports before retrying.'
    ]);
}
