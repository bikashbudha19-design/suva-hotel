
<?php
/**
 * ============================================================
 * SUVA HOTEL — ESEWA PAYMENT FAILED / CANCELLED PAGE
 * File: payment_gateway/esewa_failed.php
 * PHP 8+ | PostgreSQL
 *
 * A failed redirect is NOT proof of a failed transaction.
 * Do not cancel bookings or record payments here.
 * ============================================================
 */

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('Asia/Kathmandu');

require_once __DIR__ . '/esewa_config.php';
require_once __DIR__ . '/../config/database.php';

$hotelHome = '../../index.php';
$bookingPage = '../../public_booking.php';

$message = 'Your payment was not completed.';
$reference = '';

/*
 * eSewa may return query parameters on a failure redirect.
 * Do not trust them as proof of payment status.
 */
if (isset($_GET['transaction_uuid']) && is_string($_GET['transaction_uuid'])) {
    $reference = trim($_GET['transaction_uuid']);
}

/*
 * If a transaction reference is available, check the local
 * transaction record and display a neutral message.
 *
 * Do not change the transaction or booking status here.
 */
if (
    $reference !== '' &&
    isset($pgConnection) &&
    $pgConnection
) {
    $result = pg_query_params(
        $pgConnection,
        "SELECT payment_status
         FROM online_payment_transactions
         WHERE provider = $1
           AND merchant_reference = $2
         LIMIT 1",
        ['eSewa', $reference]
    );

    if ($result && pg_num_rows($result) === 1) {
        $row = pg_fetch_assoc($result);

        if (strtoupper((string) $row['payment_status']) === 'COMPLETE') {
            $message = 'Our records show that this payment was already verified. Please contact the hotel if you need assistance.';
        } elseif (strtoupper((string) $row['payment_status']) === 'INITIATED') {
            $message = 'Payment was not confirmed through this return. Your reservation remains pending until payment is verified.';
        }
    }

    if ($result) {
        pg_free_result($result);
    }
}

/*
 * Escape all displayed values.
 */
function failureEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Payment Not Completed | Suva Hotel</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background:
                radial-gradient(circle at top, #174638, transparent 55%),
                #071b16;
            color: #f5f1e5;
            font-family: Arial, Helvetica, sans-serif;
        }

        .payment-card {
            width: 100%;
            max-width: 570px;
            padding: 45px 35px;
            text-align: center;
            border: 1px solid rgba(205, 177, 105, .7);
            border-radius: 20px;
            background: rgba(15, 43, 34, .96);
            box-shadow: 0 25px 70px rgba(0, 0, 0, .35);
        }

        .hotel-name {
            color: #d5b76e;
            font-size: 14px;
            font-weight: bold;
            letter-spacing: 5px;
            text-transform: uppercase;
        }

        .status-icon {
            width: 88px;
            height: 88px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 30px auto 22px;
            border: 2px solid #d6a35b;
            border-radius: 50%;
            color: #e5b76d;
            font-size: 44px;
        }

        h1 {
            margin: 0 0 15px;
            color: #e4c77e;
            font-family: Georgia, serif;
            font-size: 34px;
        }

        .description {
            margin: 0 auto;
            max-width: 430px;
            color: #d6dfd8;
            font-size: 15px;
            line-height: 1.8;
        }

        .notice {
            margin-top: 25px;
            padding: 17px;
            border: 1px solid rgba(214, 163, 91, .35);
            border-radius: 10px;
            background: rgba(214, 163, 91, .08);
            color: #e9d9b2;
            font-size: 13px;
            line-height: 1.7;
        }

        .buttons {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 12px;
            margin-top: 30px;
        }

        .button {
            display: inline-block;
            padding: 14px 22px;
            border-radius: 8px;
            background: #c6a65e;
            color: #10251d;
            font-size: 14px;
            font-weight: bold;
            text-decoration: none;
            transition: .25s ease;
        }

        .button:hover {
            transform: translateY(-3px);
            background: #e0c47e;
        }

        .button.secondary {
            border: 1px solid #c6a65e;
            background: transparent;
            color: #e4c77e;
        }

        .footer {
            margin-top: 32px;
            color: #91a69a;
            font-size: 12px;
        }

        @media (max-width: 480px) {
            .payment-card {
                padding: 35px 22px;
            }

            h1 {
                font-size: 28px;
            }

            .buttons {
                flex-direction: column;
            }

            .button {
                width: 100%;
            }
        }
    </style>
</head>

<body>

    <main class="payment-card">

        <div class="hotel-name">Suva Hotel</div>

        <div class="status-icon">!</div>

        <h1>Payment Not Completed</h1>

        <p class="description">
            <?php echo failureEscape($message); ?>
        </p>

        <div class="notice">
            Your booking has not been confirmed by this payment return.
            Please check your payment status before attempting another payment.
            If money was deducted, contact the hotel for assistance.
        </div>

        <div class="buttons">

            <a class="button" href="<?php echo failureEscape($bookingPage); ?>">
                Explore Rooms
            </a>

            <a class="button secondary" href="<?php echo failureEscape($hotelHome); ?>">
                Return Home
            </a>

        </div>

        <div class="footer">
            Suva Hotel · Luxury Hospitality · Nepal
        </div>

    </main>

</body>
</html>
