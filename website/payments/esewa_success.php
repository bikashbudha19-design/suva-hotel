
<?php
/**
 * ============================================================
 * SUVA HOTEL — ESEWA PAYMENT SUCCESS PAGE
 * File: payment_gateway/esewa_success.php
 *
 * This page is only for displaying a verified payment result.
 * It must never verify or create a payment itself.
 * ============================================================
 */

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('Asia/Kathmandu');

/*
 * Only esewa_verify.php should set this session value,
 * after successful server-side verification and database commit.
 */
$verifiedPayment = $_SESSION['esewa_verified_payment'] ?? null;

if (
    !is_array($verifiedPayment) ||
    empty($verifiedPayment['booking_id']) ||
    empty($verifiedPayment['amount'])
) {
    header('Location: ../website/index.php');
    exit;
}

$bookingId = (int) $verifiedPayment['booking_id'];
$amount = (float) $verifiedPayment['amount'];

$reference = (string) ($verifiedPayment['reference'] ?? '');

function successEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/*
 * Consume the success message so refreshing or revisiting
 * this page does not reuse the old confirmation.
 */
unset($_SESSION['esewa_verified_payment']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Payment Successful | Suva Hotel</title>

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
                radial-gradient(circle at top, #174b3a, transparent 55%),
                #071b16;
            color: #f5f1e5;
            font-family: Arial, Helvetica, sans-serif;
        }

        .success-card {
            width: 100%;
            max-width: 570px;
            padding: 45px 35px;
            text-align: center;
            border: 1px solid rgba(205, 177, 105, .75);
            border-radius: 20px;
            background: #102b23;
            box-shadow: 0 25px 70px rgba(0, 0, 0, .4);
        }

        .hotel-name {
            color: #d5b76e;
            font-size: 14px;
            font-weight: bold;
            letter-spacing: 5px;
            text-transform: uppercase;
        }

        .success-icon {
            width: 88px;
            height: 88px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 30px auto 22px;
            border: 2px solid #c6a65e;
            border-radius: 50%;
            color: #d9bd76;
            font-size: 43px;
        }

        h1 {
            margin: 0 0 15px;
            color: #e4c77e;
            font-family: Georgia, serif;
            font-size: 34px;
        }

        .description {
            max-width: 440px;
            margin: 0 auto;
            color: #d6dfd8;
            font-size: 15px;
            line-height: 1.8;
        }

        .details {
            margin-top: 28px;
            padding: 20px;
            border: 1px solid rgba(214, 190, 122, .3);
            border-radius: 12px;
            background: rgba(214, 190, 122, .07);
            text-align: left;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255,255,255,.09);
            font-size: 14px;
        }

        .detail-row:last-child {
            border-bottom: 0;
        }

        .detail-label {
            color: #aebfb4;
        }

        .detail-value {
            color: #e8d39b;
            font-weight: bold;
            text-align: right;
            overflow-wrap: anywhere;
        }

        .notice {
            margin-top: 22px;
            color: #c8d7cd;
            font-size: 13px;
            line-height: 1.7;
        }

        .button {
            display: inline-block;
            margin-top: 28px;
            padding: 14px 25px;
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

        .footer {
            margin-top: 30px;
            color: #91a69a;
            font-size: 12px;
        }

        @media (max-width: 480px) {
            .success-card {
                padding: 35px 22px;
            }

            h1 {
                font-size: 28px;
            }
        }
    </style>
</head>

<body>

    <main class="success-card">

        <div class="hotel-name">Suva Hotel</div>

        <div class="success-icon">✓</div>

        <h1>Payment Successful!</h1>

        <p class="description">
            Your eSewa advance payment has been successfully verified.
            Thank you for choosing Suva Hotel.
        </p>

        <section class="details">

            <div class="detail-row">
                <span class="detail-label">Booking Reference</span>
                <span class="detail-value">
                    #<?php echo $bookingId; ?>
                </span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Advance Paid</span>
                <span class="detail-value">
                    Rs. <?php echo number_format($amount, 2); ?>
                </span>
            </div>

            <?php if ($reference !== ''): ?>
                <div class="detail-row">
                    <span class="detail-label">Payment Reference</span>
                    <span class="detail-value">
                        <?php echo successEscape($reference); ?>
                    </span>
                </div>
            <?php endif; ?>

            <div class="detail-row">
                <span class="detail-label">Payment Method</span>
                <span class="detail-value">eSewa</span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Payment Status</span>
                <span class="detail-value">Verified</span>
            </div>

        </section>

        <p class="notice">
            Please keep your booking reference for future communication
            with the hotel. The remaining balance is payable according
            to the hotel's payment policy.
        </p>

        <a class="button" href="../website/index.php">
            Return to Suva Hotel
        </a>

        <div class="footer">
            Suva Hotel · Luxury Hospitality · Nepal
        </div>

    </main>

</body>
</html>
