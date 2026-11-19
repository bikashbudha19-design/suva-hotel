<?php
// ============================================================
// SUVA HOTEL - INVOICE VIEW
// PostgreSQL Version
// File: /suva_hotel/invoice/view.php
// ============================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// ============================================================
// AUTHENTICATION
// ============================================================

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

// ============================================================
// DATABASE CONNECTION
// ============================================================

$db = $pgConnection ?? null;

if (!$db) {
    die('Database connection failed.');
}

// ============================================================
// HELPER FUNCTIONS
// ============================================================

function e($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($value)
{
    return 'Rs. ' . number_format((float)$value, 2);
}

// ============================================================
// USER / ROLE
// ============================================================

$userName = $_SESSION['username'] ?? 'User';
$userRole = strtolower($_SESSION['role'] ?? 'staff');

if ($userRole === 'admin') {
    $dashboardUrl = '../dashboard.php';
} else {
    $dashboardUrl = '../staff_dashboard/staff_dashboard.php';
}

// ============================================================
// PAYMENT ID
// ============================================================

$paymentId = filter_input(INPUT_GET, 'payment_id', FILTER_VALIDATE_INT);

if (!$paymentId || $paymentId <= 0) {

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>Invalid Payment - Suva Hotel</title>

        <style>
            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                min-height: 100vh;
                font-family: Arial, Helvetica, sans-serif;
                background:
                    linear-gradient(
                        rgba(5, 7, 12, 0.88),
                        rgba(5, 7, 12, 0.95)
                    ),
                    url('../assets/image/hotel-lighting.jpg') center/cover fixed;
                color: #fff;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 25px;
            }

            .error-box {
                width: 100%;
                max-width: 550px;
                background: rgba(18, 20, 28, 0.96);
                border: 1px solid rgba(212, 175, 55, 0.35);
                border-radius: 20px;
                padding: 45px 35px;
                text-align: center;
                box-shadow: 0 25px 70px rgba(0,0,0,.55);
            }

            .icon {
                width: 75px;
                height: 75px;
                border-radius: 50%;
                margin: 0 auto 20px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: rgba(220, 53, 69, .15);
                border: 1px solid rgba(220, 53, 69, .35);
                font-size: 32px;
            }

            h1 {
                margin: 0 0 12px;
                color: #f0c75e;
                font-size: 27px;
            }

            p {
                color: #aeb4c0;
                line-height: 1.7;
                margin-bottom: 25px;
            }

            .buttons {
                display: flex;
                justify-content: center;
                gap: 12px;
                flex-wrap: wrap;
            }

            a {
                display: inline-block;
                text-decoration: none;
                padding: 13px 22px;
                border-radius: 10px;
                font-weight: bold;
                color: #111;
                background: linear-gradient(135deg, #f5d77a, #c99a2e);
            }

            a.secondary {
                color: #f5d77a;
                background: transparent;
                border: 1px solid rgba(212,175,55,.5);
            }
        </style>
    </head>

    <body>

        <div class="error-box">

            <div class="icon">⚠️</div>

            <h1>Invalid Payment ID</h1>

            <p>
                No valid payment ID was provided.
                Please open the invoice from the Payment History page.
            </p>

            <div class="buttons">

                <a href="../payment/view.php">
                    ← Payment History
                </a>

                <a class="secondary" href="<?php echo e($dashboardUrl); ?>">
                    Dashboard
                </a>

            </div>

        </div>

    </body>
    </html>

    <?php
    exit;
}

// ============================================================
// GET PAYMENT + BOOKING + GUEST + ROOM
// ============================================================

$sql = "
    SELECT
        p.id AS payment_id,
        p.booking_id,
        p.amount AS amount_paid,
        p.payment_method,
        p.payment_status,
        p.paid_at,

        b.check_in,
        b.check_out,

        COALESCE(g.full_name, 'Unknown Guest') AS guest_name,

        r.room_number,
        r.room_type

    FROM payments p

    LEFT JOIN bookings b
        ON p.booking_id = b.id

    LEFT JOIN guests g
        ON b.guest_id = g.id

    LEFT JOIN rooms r
        ON b.room_id = r.id

    WHERE p.id = $1

    LIMIT 1
";

$result = pg_query_params($db, $sql, [$paymentId]);

if (!$result) {
    die(
        'Database Error: ' .
        e(pg_last_error($db))
    );
}

$payment = pg_fetch_assoc($result);

if (!$payment) {
    die('Payment / Invoice not found.');
}

// ============================================================
// BOOKING ID
// ============================================================

$bookingId = (int)($payment['booking_id'] ?? 0);

if ($bookingId <= 0) {
    die('Invalid booking associated with this payment.');
}

// ============================================================
// GET BILLING INFORMATION
// ============================================================

$billingSql = "
    SELECT
        id,
        invoice_no,
        billing_date,
        room_charge,
        food_charge,
        service_charge,
        discount,
        tax,
        total_amount,
        status

    FROM billing

    WHERE booking_id = $1

    ORDER BY id DESC

    LIMIT 1
";

$billingResult = pg_query_params(
    $db,
    $billingSql,
    [$bookingId]
);

if (!$billingResult) {
    die(
        'Billing Database Error: ' .
        e(pg_last_error($db))
    );
}

$bill = pg_fetch_assoc($billingResult);

// ============================================================
// IF NO BILLING RECORD EXISTS
// ============================================================

if (!$bill) {

    ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>

        <meta charset="UTF-8">

        <meta
            name="viewport"
            content="width=device-width, initial-scale=1.0"
        >

        <title>Invoice Not Found - Suva Hotel</title>

        <style>

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                min-height: 100vh;
                font-family: Arial, Helvetica, sans-serif;

                background:
                    linear-gradient(
                        rgba(5, 7, 12, .90),
                        rgba(5, 7, 12, .96)
                    ),
                    url('../assets/image/hotel-lighting.jpg')
                    center/cover fixed;

                color: white;

                display: flex;
                align-items: center;
                justify-content: center;

                padding: 25px;
            }

            .error-box {
                max-width: 600px;
                width: 100%;

                background: rgba(18,20,28,.97);

                border: 1px solid
                    rgba(212,175,55,.35);

                border-radius: 20px;

                padding: 45px 35px;

                text-align: center;

                box-shadow:
                    0 25px 70px rgba(0,0,0,.60);
            }

            .icon {
                font-size: 42px;
                margin-bottom: 15px;
            }

            h1 {
                color: #f0c75e;
                margin-bottom: 12px;
            }

            p {
                color: #aeb4c0;
                line-height: 1.7;
            }

            .buttons {
                margin-top: 25px;
                display: flex;
                justify-content: center;
                gap: 12px;
                flex-wrap: wrap;
            }

            a {
                text-decoration: none;
                padding: 13px 22px;
                border-radius: 10px;
                font-weight: bold;
            }

            .gold {
                color: #111;
                background:
                    linear-gradient(
                        135deg,
                        #f5d77a,
                        #c99a2e
                    );
            }

            .outline {
                color: #f5d77a;
                border: 1px solid
                    rgba(212,175,55,.5);
            }

        </style>

    </head>

    <body>

        <div class="error-box">

            <div class="icon">🧾</div>

            <h1>Billing Record Not Found</h1>

            <p>
                Payment #<?php echo e($paymentId); ?>
                exists, but no billing record was found for
                Booking #<?php echo e($bookingId); ?>.
            </p>

            <div class="buttons">

                <a
                    class="gold"
                    href="../payment/view.php"
                >
                    ← Payment History
                </a>

                <a
                    class="outline"
                    href="<?php echo e($dashboardUrl); ?>"
                >
                    Dashboard
                </a>

            </div>

        </div>

    </body>

    </html>

    <?php
    exit;
}

// ============================================================
// BILL VALUES
// ============================================================

$roomCharge =
    (float)($bill['room_charge'] ?? 0);

$foodCharge =
    (float)($bill['food_charge'] ?? 0);

$serviceCharge =
    (float)($bill['service_charge'] ?? 0);

$discount =
    (float)($bill['discount'] ?? 0);

$tax =
    (float)($bill['tax'] ?? 0);

$grandTotal =
    (float)($bill['total_amount'] ?? 0);

// ============================================================
// PAYMENT VALUES
// ============================================================

$amountPaid =
    (float)($payment['amount_paid'] ?? 0);

// ============================================================
// CALCULATE TOTAL PAID FOR THIS BOOKING
//
// Important:
// If a booking has multiple payment records,
// only using the current payment would incorrectly
// show the remaining balance.
// ============================================================

$totalPaidSql = "
    SELECT
        COALESCE(SUM(amount), 0) AS total_paid

    FROM payments

    WHERE booking_id = $1

      AND LOWER(COALESCE(payment_status, '')) = 'paid'
";

$totalPaidResult = pg_query_params(
    $db,
    $totalPaidSql,
    [$bookingId]
);

$totalPaid = 0;

if ($totalPaidResult) {

    $paidRow =
        pg_fetch_assoc($totalPaidResult);

    $totalPaid =
        (float)($paidRow['total_paid'] ?? 0);
}

// ============================================================
// FALLBACK
// ============================================================

if ($totalPaid <= 0 && $amountPaid > 0) {
    $totalPaid = $amountPaid;
}

// ============================================================
// REMAINING AMOUNT
// ============================================================

$dueAmount =
    $grandTotal - $totalPaid;

if ($dueAmount < 0) {
    $dueAmount = 0;
}

// ============================================================
// INVOICE NUMBER
// ============================================================

$invoiceNo =
    $bill['invoice_no'] ??
    ('INV-' . $bookingId);

// ============================================================
// PAYMENT STATUS
// ============================================================

$paymentStatus =
    $payment['payment_status'] ??
    'Pending';

// ============================================================
// DISPLAY STATUS
// ============================================================

if ($grandTotal > 0 && $dueAmount <= 0) {

    $statusClass = 'paid';
    $statusText = 'PAID';

} elseif ($totalPaid > 0) {

    $statusClass = 'partial';
    $statusText = 'PARTIALLY PAID';

} else {

    $statusClass = 'due';
    $statusText = 'UNPAID';
}

// ============================================================
// DATES
// ============================================================

$billingDate =
    $bill['billing_date'] ??
    date('Y-m-d');

$checkIn =
    $payment['check_in'] ??
    'N/A';

$checkOut =
    $payment['check_out'] ??
    'N/A';

$paidAt =
    $payment['paid_at'] ??
    'N/A';

?>
<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Invoice <?php echo e($invoiceNo); ?> -
        Suva Hotel
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {

            margin: 0;

            font-family:
                Inter,
                Arial,
                Helvetica,
                sans-serif;

            color: #f5f5f5;

            min-height: 100vh;

            background:

                linear-gradient(
                    rgba(4, 6, 11, .88),
                    rgba(4, 6, 11, .95)
                ),

                url('../assets/image/hotel-lighting.jpg')
                center center / cover fixed no-repeat;

            padding: 35px 20px;

        }

        /* =====================================================
           PAGE
        ===================================================== */

        .page {

            max-width: 1050px;

            margin: auto;

        }

        /* =====================================================
           TOP NAV
        ===================================================== */

        .topbar {

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 20px;

            margin-bottom: 25px;

            padding: 16px 20px;

            background:
                rgba(13, 15, 22, .90);

            border:
                1px solid
                rgba(212,175,55,.22);

            border-radius: 15px;

            backdrop-filter: blur(14px);

            box-shadow:
                0 15px 40px
                rgba(0,0,0,.35);

        }

        .brand {

            display: flex;

            align-items: center;

            gap: 12px;

        }

        .brand-icon {

            width: 45px;
            height: 45px;

            display: flex;

            align-items: center;
            justify-content: center;

            border-radius: 12px;

            background:
                linear-gradient(
                    135deg,
                    #f3d574,
                    #b98a24
                );

            color: #111;

            font-size: 21px;

        }

        .brand-text strong {

            display: block;

            font-size: 16px;

            letter-spacing: 1px;

            color: #f3d574;

        }

        .brand-text span {

            display: block;

            margin-top: 3px;

            font-size: 12px;

            color: #8e95a3;

        }

        .top-actions {

            display: flex;

            align-items: center;

            gap: 10px;

            flex-wrap: wrap;

            justify-content: flex-end;

        }

        .top-actions a {

            text-decoration: none;

            color: #dce0e8;

            border:
                1px solid
                rgba(255,255,255,.10);

            background:
                rgba(255,255,255,.035);

            padding: 10px 14px;

            border-radius: 9px;

            font-size: 13px;

            transition: .2s;

        }

        .top-actions a:hover {

            border-color:
                rgba(212,175,55,.45);

            color: #f3d574;

            transform:
                translateY(-1px);

        }

        /* =====================================================
           INVOICE
        ===================================================== */

        .invoice {

            background:
                rgba(15, 17, 24, .96);

            border:
                1px solid
                rgba(212,175,55,.25);

            border-radius: 20px;

            overflow: hidden;

            box-shadow:
                0 30px 80px
                rgba(0,0,0,.55);

        }

        /* =====================================================
           INVOICE HEADER
        ===================================================== */

        .invoice-header {

            padding: 35px;

            display: flex;

            justify-content: space-between;

            gap: 30px;

            border-bottom:
                1px solid
                rgba(212,175,55,.25);

            background:
                linear-gradient(
                    135deg,
                    rgba(212,175,55,.08),
                    transparent 55%
                );

        }

        .hotel {

            display: flex;

            gap: 16px;

            align-items: flex-start;

        }

        .hotel-logo {

            width: 60px;
            height: 60px;

            flex-shrink: 0;

            border-radius: 15px;

            display: flex;

            align-items: center;
            justify-content: center;

            background:
                linear-gradient(
                    135deg,
                    #f5d77a,
                    #b98720
                );

            color: #111;

            font-size: 28px;

            box-shadow:
                0 8px 25px
                rgba(212,175,55,.18);

        }

        .hotel h1 {

            margin: 0;

            font-size: 27px;

            letter-spacing: 1.5px;

            color: #f5d77a;

        }

        .hotel p {

            margin: 7px 0 0;

            color: #8f96a5;

            font-size: 13px;

        }

        .invoice-heading {

            text-align: right;

        }

        .invoice-heading h2 {

            margin: 0;

            font-size: 32px;

            letter-spacing: 2px;

            color: #ffffff;

        }

        .invoice-number {

            margin-top: 9px;

            color: #f5d77a;

            font-weight: bold;

        }

        .invoice-date {

            margin-top: 6px;

            color: #9299a7;

            font-size: 13px;

        }

        /* =====================================================
           CONTENT
        ===================================================== */

        .content {

            padding: 35px;

        }

        .section {

            margin-bottom: 30px;

        }

        .section-title {

            display: flex;

            align-items: center;

            gap: 10px;

            margin-bottom: 13px;

            font-size: 16px;

            font-weight: bold;

            color: #f3d574;

        }

        .section-title::after {

            content: "";

            height: 1px;

            flex: 1;

            background:
                rgba(212,175,55,.18);

        }

        /* =====================================================
           DETAILS GRID
        ===================================================== */

        .details-grid {

            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 1px;

            background:
                rgba(255,255,255,.08);

            border:
                1px solid
                rgba(255,255,255,.08);

            border-radius: 12px;

            overflow: hidden;

        }

        .detail {

            padding: 15px 17px;

            background:
                rgba(255,255,255,.025);

        }

        .detail-label {

            color: #858d9d;

            font-size: 11px;

            text-transform: uppercase;

            letter-spacing: .7px;

            margin-bottom: 5px;

        }

        .detail-value {

            color: #f2f3f5;

            font-size: 14px;

            font-weight: 600;

        }

        /* =====================================================
           BILL TABLE
        ===================================================== */

        .table-wrapper {

            overflow-x: auto;

            border-radius: 12px;

            border:
                1px solid
                rgba(255,255,255,.08);

        }

        table {

            width: 100%;

            border-collapse: collapse;

        }

        th {

            padding: 14px 17px;

            text-align: left;

            background:
                rgba(212,175,55,.10);

            color: #f3d574;

            font-size: 12px;

            text-transform: uppercase;

            letter-spacing: .6px;

        }

        td {

            padding: 14px 17px;

            border-top:
                1px solid
                rgba(255,255,255,.06);

            color: #d8dce4;

            font-size: 14px;

        }

        .amount {

            text-align: right;

            white-space: nowrap;

        }

        .discount {

            color: #e58b8b;

        }

        .total-row td {

            padding: 18px 17px;

            background:
                rgba(212,175,55,.10);

            color: #f5d77a;

            font-size: 18px;

            font-weight: bold;

        }

        /* =====================================================
           PAYMENT BOX
        ===================================================== */

        .payment-grid {

            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap: 15px;

        }

        .payment-card {

            padding: 20px;

            border-radius: 13px;

            background:
                rgba(255,255,255,.035);

            border:
                1px solid
                rgba(255,255,255,.08);

        }

        .payment-card span {

            display: block;

            color: #858d9d;

            font-size: 11px;

            text-transform: uppercase;

            letter-spacing: .7px;

            margin-bottom: 8px;

        }

        .payment-card strong {

            font-size: 20px;

            color: #f4f5f7;

        }

        .payment-card.gold strong {

            color: #f5d77a;

        }

        .payment-card.red strong {

            color: #ef8888;

        }

        /* =====================================================
           STATUS
        ===================================================== */

        .status {

            margin-top: 25px;

            padding: 18px;

            text-align: center;

            border-radius: 12px;

            font-size: 18px;

            font-weight: bold;

            letter-spacing: 1px;

        }

        .status.paid {

            color: #72e0a2;

            background:
                rgba(46,204,113,.10);

            border:
                1px solid
                rgba(46,204,113,.25);

        }

        .status.partial {

            color: #f3c96b;

            background:
                rgba(243,201,107,.10);

            border:
                1px solid
                rgba(243,201,107,.25);

        }

        .status.due {

            color: #f18b8b;

            background:
                rgba(231,76,60,.10);

            border:
                1px solid
                rgba(231,76,60,.25);

        }

        /* =====================================================
           FOOTER
        ===================================================== */

        .invoice-footer {

            padding: 25px 35px;

            border-top:
                1px solid
                rgba(255,255,255,.07);

            text-align: center;

            color: #777f8d;

            font-size: 13px;

            line-height: 1.7;

        }

        .invoice-footer strong {

            color: #f3d574;

        }

        /* =====================================================
           BUTTONS
        ===================================================== */

        .actions {

            display: flex;

            justify-content: center;

            gap: 12px;

            flex-wrap: wrap;

            margin-top: 25px;

        }

        .action {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            min-width: 180px;

            height: 52px;

            padding: 0 22px;

            border-radius: 11px;

            text-decoration: none;

            border: 1px solid transparent;

            cursor: pointer;

            font-size: 14px;

            font-weight: bold;

            transition: .25s;

        }

        .print {

            color: #111;

            background:
                linear-gradient(
                    135deg,
                    #f5d77a,
                    #c99a2e
                );

            box-shadow:
                0 8px 25px
                rgba(212,175,55,.14);

        }

        .print:hover {

            transform:
                translateY(-2px);

            box-shadow:
                0 12px 30px
                rgba(212,175,55,.22);

        }

        .back {

            color: #f5d77a;

            background:
                rgba(212,175,55,.06);

            border-color:
                rgba(212,175,55,.35);

        }

        .back:hover {

            background:
                rgba(212,175,55,.12);

            transform:
                translateY(-2px);

        }

        .dashboard {

            color: #dce0e8;

            background:
                rgba(255,255,255,.04);

            border-color:
                rgba(255,255,255,.10);

        }

        .dashboard:hover {

            color: #f5d77a;

            border-color:
                rgba(212,175,55,.30);

            transform:
                translateY(-2px);

        }

        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media (max-width: 750px) {

            body {
                padding: 15px;
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .top-actions {
                width: 100%;
                justify-content: flex-start;
            }

            .invoice-header {
                flex-direction: column;
            }

            .invoice-heading {
                text-align: left;
            }

            .content {
                padding: 22px;
            }

            .details-grid {
                grid-template-columns: 1fr;
            }

            .payment-grid {
                grid-template-columns: 1fr;
            }

            .invoice-header {
                padding: 25px;
            }

            .invoice-footer {
                padding: 22px;
            }

            .action {
                width: 100%;
            }

        }

        /* =====================================================
           PRINT
        ===================================================== */

        @media print {

            body {

                background: white !important;

                color: #111;

                padding: 0;

            }

            .topbar,
            .actions {

                display: none !important;

            }

            .invoice {

                border: none;

                box-shadow: none;

                border-radius: 0;

                background: white;

            }

            .invoice-header {

                background: white;

                border-bottom:
                    2px solid #222;

            }

            .hotel h1,
            .invoice-heading h2,
            .invoice-number,
            .section-title,
            th,
            .total-row td {

                color: #222 !important;

            }

            .hotel p,
            .invoice-date,
            .detail-label,
            .invoice-footer,
            .detail-value,
            td {

                color: #333 !important;

            }

            .details-grid,
            .table-wrapper {

                border-color: #ccc;

            }

            .detail {

                background: white;

            }

            th {

                background: #eeeeee;

            }

            .payment-card {

                background: white;

                border-color: #ccc;

            }

            .status {

                border: 1px solid #ccc !important;

                color: #111 !important;

                background: #f5f5f5 !important;

            }

        }

    </style>

</head>

<body>

<div class="page">

    <!-- =====================================================
         TOP BAR
    ====================================================== -->

    <div class="topbar">

        <div class="brand">

            <div class="brand-icon">
                🏨
            </div>

            <div class="brand-text">

                <strong>SUVA HOTEL</strong>

                <span>
                    Invoice Management
                </span>

            </div>

        </div>

        <div class="top-actions">

            <a href="<?php echo e($dashboardUrl); ?>">
                🏠 Dashboard
            </a>

            <a href="../payment/view.php">
                💳 Payments
            </a>

        </div>

    </div>


    <!-- =====================================================
         INVOICE
    ====================================================== -->

    <div class="invoice">

        <!-- HEADER -->

        <div class="invoice-header">

            <div class="hotel">

                <div class="hotel-logo">
                    🏨
                </div>

                <div>

                    <h1>
                        SUVA HOTEL
                    </h1>

                    <p>
                        Hotel Management System
                    </p>

                    <p>
                        Premium Hospitality & Guest Services
                    </p>

                </div>

            </div>


            <div class="invoice-heading">

                <h2>
                    INVOICE
                </h2>

                <div class="invoice-number">

                    <?php echo e($invoiceNo); ?>

                </div>

                <div class="invoice-date">

                    Billing Date:
                    <?php echo e($billingDate); ?>

                </div>

            </div>

        </div>


        <!-- CONTENT -->

        <div class="content">


            <!-- =================================================
                 GUEST INFORMATION
            ================================================== -->

            <div class="section">

                <div class="section-title">
                    👤 Guest & Booking Information
                </div>

                <div class="details-grid">

                    <div class="detail">

                        <div class="detail-label">
                            Guest Name
                        </div>

                        <div class="detail-value">

                            <?php
                            echo e(
                                $payment['guest_name']
                                ?? 'Unknown Guest'
                            );
                            ?>

                        </div>

                    </div>


                    <div class="detail">

                        <div class="detail-label">
                            Booking ID
                        </div>

                        <div class="detail-value">

                            #<?php
                            echo e($bookingId);
                            ?>

                        </div>

                    </div>


                    <div class="detail">

                        <div class="detail-label">
                            Room Number
                        </div>

                        <div class="detail-value">

                            <?php
                            echo e(
                                $payment['room_number']
                                ?? 'N/A'
                            );
                            ?>

                        </div>

                    </div>


                    <div class="detail">

                        <div class="detail-label">
                            Room Type
                        </div>

                        <div class="detail-value">

                            <?php
                            echo e(
                                $payment['room_type']
                                ?? 'N/A'
                            );
                            ?>

                        </div>

                    </div>


                    <div class="detail">

                        <div class="detail-label">
                            Check In
                        </div>

                        <div class="detail-value">

                            <?php echo e($checkIn); ?>

                        </div>

                    </div>


                    <div class="detail">

                        <div class="detail-label">
                            Check Out
                        </div>

                        <div class="detail-value">

                            <?php echo e($checkOut); ?>

                        </div>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 BILL DETAILS
            ================================================== -->

            <div class="section">

                <div class="section-title">
                    🧾 Bill Details
                </div>

                <div class="table-wrapper">

                    <table>

                        <thead>

                            <tr>

                                <th>
                                    Description
                                </th>

                                <th class="amount">
                                    Amount
                                </th>

                            </tr>

                        </thead>

                        <tbody>


                            <tr>

                                <td>
                                    Room Charge
                                </td>

                                <td class="amount">

                                    <?php
                                    echo money(
                                        $roomCharge
                                    );
                                    ?>

                                </td>

                            </tr>


                            <tr>

                                <td>
                                    Food Charge
                                </td>

                                <td class="amount">

                                    <?php
                                    echo money(
                                        $foodCharge
                                    );
                                    ?>

                                </td>

                            </tr>


                            <tr>

                                <td>
                                    Service Charge
                                </td>

                                <td class="amount">

                                    <?php
                                    echo money(
                                        $serviceCharge
                                    );
                                    ?>

                                </td>

                            </tr>


                            <tr>

                                <td>
                                    Discount
                                </td>

                                <td
                                    class="amount discount"
                                >

                                    - <?php
                                    echo money(
                                        $discount
                                    );
                                    ?>

                                </td>

                            </tr>


                            <tr>

                                <td>
                                    VAT / Tax
                                </td>

                                <td class="amount">

                                    <?php
                                    echo money(
                                        $tax
                                    );
                                    ?>

                                </td>

                            </tr>


                            <tr class="total-row">

                                <td>
                                    GRAND TOTAL
                                </td>

                                <td class="amount">

                                    <?php
                                    echo money(
                                        $grandTotal
                                    );
                                    ?>

                                </td>

                            </tr>

                        </tbody>

                    </table>

                </div>

            </div>


            <!-- =================================================
                 PAYMENT INFORMATION
            ================================================== -->

            <div class="section">

                <div class="section-title">
                    💳 Payment Information
                </div>

                <div class="payment-grid">


                    <div class="payment-card gold">

                        <span>
                            Total Bill
                        </span>

                        <strong>

                            <?php
                            echo money(
                                $grandTotal
                            );
                            ?>

                        </strong>

                    </div>


                    <div class="payment-card">

                        <span>
                            Total Paid
                        </span>

                        <strong>

                            <?php
                            echo money(
                                $totalPaid
                            );
                            ?>

                        </strong>

                    </div>


                    <div class="payment-card red">

                        <span>
                            Remaining
                        </span>

                        <strong>

                            <?php
                            echo money(
                                $dueAmount
                            );
                            ?>

                        </strong>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 PAYMENT DETAILS
            ================================================== -->

            <div class="section">

                <div class="section-title">
                    💰 Transaction Details
                </div>

                <div class="details-grid">

                    <div class="detail">

                        <div class="detail-label">
                            Current Payment ID
                        </div>

                        <div class="detail-value">

                            #<?php
                            echo e($paymentId);
                            ?>

                        </div>

                    </div>


                    <div class="detail">

                        <div class="detail-label">
                            Current Payment
                        </div>

                        <div class="detail-value">

                            <?php
                            echo money(
                                $amountPaid
                            );
                            ?>

                        </div>

                    </div>


                    <div class="detail">

                        <div class="detail-label">
                            Payment Method
                        </div>

                        <div class="detail-value">

                            <?php
                            echo e(
                                $payment['payment_method']
                                ?? 'N/A'
                            );
                            ?>

                        </div>

                    </div>


                    <div class="detail">

                        <div class="detail-label">
                            Payment Status
                        </div>

                        <div class="detail-value">

                            <?php
                            echo e(
                                $paymentStatus
                            );
                            ?>

                        </div>

                    </div>


                    <div class="detail">

                        <div class="detail-label">
                            Paid At
                        </div>

                        <div class="detail-value">

                            <?php
                            echo e($paidAt);
                            ?>

                        </div>

                    </div>


                    <div class="detail">

                        <div class="detail-label">
                            Billing Status
                        </div>

                        <div class="detail-value">

                            <?php
                            echo e(
                                $bill['status']
                                ?? 'N/A'
                            );
                            ?>

                        </div>

                    </div>

                </div>


                <!-- STATUS -->

                <div
                    class="status <?php echo e($statusClass); ?>"
                >

                    <?php echo e($statusText); ?>

                </div>

            </div>

        </div>


        <!-- =====================================================
             FOOTER
        ====================================================== -->

        <div class="invoice-footer">

            <strong>
                Thank you for staying at Suva Hotel.
            </strong>

            <br>

            We appreciate your visit and hope to
            welcome you again.

            <br>

            This invoice was generated by the
            Suva Hotel Management System.

        </div>

    </div>


    <!-- =====================================================
         ACTION BUTTONS
    ====================================================== -->

    <div class="actions">

        <a
            href="<?php echo e($dashboardUrl); ?>"
            class="action dashboard"
        >
            🏠 Back to Dashboard
        </a>


        <a
            href="../payment/view.php"
            class="action back"
        >
            ← Back to Payment History
        </a>


        <a
            href="#"
            onclick="window.print(); return false;"
            class="action print"
        >
            🖨️ Print Invoice
        </a>

    </div>

</div>

</body>

</html>