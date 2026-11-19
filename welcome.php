
<?php
// ============================================================
// SUVA HOTEL - Guest Welcome Page
// File: /Suva_hotel/welcome.php
// PostgreSQL
// ============================================================

header('Cache-Control: no-store, private, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/config/database.php';

$db = $pgConnection ?? false;

// Escape text before displaying it in HTML.
function h($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

// Format dates in a guest-friendly style.
function hotelDate($value)
{
    if (!$value) {
        return 'To be confirmed';
    }

    $timestamp = strtotime((string)$value);

    return $timestamp
        ? date('F j, Y', $timestamp)
        : 'To be confirmed';
}

// Show a safe error page without revealing guest information.
function showWelcomeError($message, $status = 404)
{
    http_response_code($status);

    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>SUVA HOTEL</title>
        <style>
            body {
                margin: 0;
                padding: 25px;
                background: #f3f5f8;
                font-family: Arial, sans-serif;
                color: #243247;
                text-align: center;
            }
            .card {
                max-width: 480px;
                margin: 50px auto;
                padding: 35px 25px;
                background: white;
                border-radius: 15px;
                box-shadow: 0 5px 25px #0000000c;
            }
            h1 { color: #b18b4e; letter-spacing: 2px; }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>SUVA HOTEL</h1>
            <p>' . h($message) . '</p>
            <p>Please contact our reception for assistance.</p>
        </div>
    </body>
    </html>';

    exit;
}

if (!$db) {
    error_log('SUVA HOTEL: PostgreSQL connection unavailable.');
    showWelcomeError(
        'The welcome page is temporarily unavailable.',
        500
    );
}

// Read the random access token from the QR link.
$token = $_GET['t'] ?? '';

if (
    !is_string($token) ||
    !preg_match('/\A[a-f0-9]{64}\z/i', $token)
) {
    showWelcomeError(
        'This welcome link is invalid or has expired.'
    );
}

// The database stores only the SHA-256 hash of the token.
$tokenHash = hash('sha256', $token);

$result = pg_query_params(
    $db,
    'SELECT
        g.full_name,
        r.room_number,
        b.check_in,
        b.check_out
     FROM public.guest_welcome_links AS w
     INNER JOIN public.bookings AS b
        ON b.id = w.booking_id
     INNER JOIN public.guests AS g
        ON g.id = b.guest_id
     LEFT JOIN public.rooms AS r
        ON r.id = b.room_id
     WHERE w.token_hash = $1
       AND w.is_active = TRUE
     LIMIT 1',
    [$tokenHash]
);

if (!$result) {
    error_log(
        'SUVA HOTEL welcome query failed: ' .
        pg_last_error($db)
    );

    showWelcomeError(
        'The welcome page is temporarily unavailable.',
        500
    );
}

$guest = pg_fetch_assoc($result);

if (!$guest) {
    showWelcomeError(
        'This welcome link is invalid or has expired.'
    );
}

$guestName = trim((string)$guest['full_name']);

if ($guestName === '') {
    $guestName = 'Valued Guest';
}

$roomNumber = $guest['room_number'] ?? '';

if ($roomNumber === '') {
    $roomNumber = 'To be confirmed';
}

$checkIn = hotelDate($guest['check_in']);
$checkOut = hotelDate($guest['check_out']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <meta
        name="robots"
        content="noindex, nofollow, noarchive"
    >

    <title>Welcome to SUVA HOTEL</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(
                135deg,
                #15243a,
                #263c57
            );
            font-family: Arial, Helvetica, sans-serif;
            color: #263246;
        }

        .welcome-card {
            width: 100%;
            max-width: 580px;
            overflow: hidden;
            background: #ffffff;
            border-radius: 18px;
            box-shadow: 0 15px 50px #00000030;
        }

        .hotel-header {
            padding: 35px 20px;
            background: #17273e;
            color: #ffffff;
            text-align: center;
            border-bottom: 4px solid #bd985b;
        }

        .hotel-name {
            margin: 0;
            color: #e0bf83;
            font-size: 30px;
            letter-spacing: 4px;
        }

        .hotel-subtitle {
            margin: 10px 0 0;
            font-size: 13px;
            letter-spacing: 2px;
        }

        .content {
            padding: 32px 25px;
            text-align: center;
        }

        .welcome-label {
            color: #b08a4e;
            font-size: 13px;
            font-weight: bold;
            letter-spacing: 3px;
            text-transform: uppercase;
        }

        h2 {
            margin: 15px 0;
            font-size: 27px;
            line-height: 1.3;
        }

        .message {
            color: #657084;
            font-size: 16px;
            line-height: 1.8;
        }

        .stay-details {
            margin-top: 28px;
            padding: 20px;
            background: #f7f5f0;
            border: 1px solid #eee5d5;
            border-radius: 12px;
            text-align: left;
        }

        .stay-details h3 {
            margin-top: 0;
            color: #24374e;
            text-align: center;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 13px 0;
            border-bottom: 1px solid #e6e0d5;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: #687387;
        }

        .detail-value {
            font-weight: bold;
            text-align: right;
            overflow-wrap: anywhere;
        }

        .footer {
            padding: 20px;
            background: #fafafa;
            color: #687387;
            text-align: center;
            font-size: 13px;
            line-height: 1.7;
        }

        @media (max-width: 420px) {
            .content {
                padding: 27px 18px;
            }

            h2 {
                font-size: 23px;
            }

            .hotel-name {
                font-size: 26px;
            }

            .detail-row {
                flex-direction: column;
                gap: 6px;
            }

            .detail-value {
                text-align: left;
            }
        }
    </style>
</head>

<body>

    <main class="welcome-card">

        <header class="hotel-header">
            <h1 class="hotel-name">SUVA HOTEL</h1>

            <p class="hotel-subtitle">
                COMFORT · HOSPITALITY · CARE
            </p>
        </header>

        <section class="content">

            <div class="welcome-label">
                A Warm Welcome
            </div>

            <h2>
                Welcome, <?= h($guestName) ?>!
            </h2>

            <p class="message">
                We are delighted to welcome you to SUVA HOTEL.
                Thank you for choosing to stay with us.
                Our team wishes you a comfortable, relaxing,
                and memorable experience.
            </p>

            <div class="stay-details">

                <h3>Your Stay Information</h3>

                <div class="detail-row">
                    <span class="detail-label">
                        Room Number
                    </span>

                    <span class="detail-value">
                        <?= h($roomNumber) ?>
                    </span>
                </div>

                <div class="detail-row">
                    <span class="detail-label">
                        Check-in
                    </span>

                    <span class="detail-value">
                        <?= h($checkIn) ?>
                    </span>
                </div>

                <div class="detail-row">
                    <span class="detail-label">
                        Check-out
                    </span>

                    <span class="detail-value">
                        <?= h($checkOut) ?>
                    </span>
                </div>

            </div>

            <p class="message">
                Need assistance? Please contact our hotel reception.
                We are happy to help.
            </p>

        </section>

        <footer class="footer">
            <strong>Thank you for choosing SUVA HOTEL.</strong>
            <br>
            We look forward to making your stay special.
        </footer>

    </main>

</body>
</html>
