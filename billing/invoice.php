
<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";

/*
=========================================================
SUVA HOTEL - INVOICE PAGE
PostgreSQL compatible
File: billing/invoice.php
=========================================================
*/

// Escape output safely
function e($value)
{
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

// Format money
function money($amount)
{
    return "Rs. " . number_format((float) $amount, 2);
}

/*
=========================================================
CHECK BILL ID
=========================================================
*/

if (!isset($_GET["id"]) || !ctype_digit((string) $_GET["id"])) {
    http_response_code(400);
    die("Invalid bill ID.");
}

$bill_id = (int) $_GET["id"];

if ($bill_id <= 0) {
    http_response_code(400);
    die("Invalid bill ID.");
}

/*
=========================================================
GET BILL INFORMATION
=========================================================
*/

$sql = "
    SELECT
        b.id,
        b.booking_id,
        b.guest_id,
        b.invoice_no,
        b.billing_date,
        b.room_charge,
        b.food_charge,
        b.service_charge,
        b.discount,
        b.tax,
        b.total_amount,
        b.status,
        b.created_at,
        g.full_name
    FROM billing AS b
    LEFT JOIN guests AS g
        ON b.guest_id = g.id
    WHERE b.id = $1
    LIMIT 1
";

$result = pg_query_params(
    $pgConnection,
    $sql,
    [$bill_id]
);

if ($result === false) {
    error_log("Invoice query failed: " . pg_last_error($pgConnection));
    http_response_code(500);
    die("Unable to load invoice. Please check the database.");
}

$bill = pg_fetch_assoc($result);
pg_free_result($result);

if (!$bill) {
    http_response_code(404);
    die("Bill not found.");
}

/*
=========================================================
GET PAYMENTS FOR THIS BOOKING
Count actual payment records marked Paid or Partial.
=========================================================
*/

$paymentSql = "
    SELECT
        COALESCE(SUM(amount), 0) AS total_paid
    FROM payments
    WHERE booking_id = $1
      AND LOWER(TRIM(COALESCE(payment_status, '')))
          IN ('paid', 'partial')
";

$paymentResult = pg_query_params(
    $pgConnection,
    $paymentSql,
    [(int) $bill["booking_id"]]
);

if ($paymentResult === false) {
    error_log(
        "Invoice payment query failed: " .
        pg_last_error($pgConnection)
    );

    http_response_code(500);
    die("Unable to calculate payments. Please check the database.");
}

$paymentRow = pg_fetch_assoc($paymentResult);
pg_free_result($paymentResult);

$totalPaid = (float) ($paymentRow["total_paid"] ?? 0);
$grandTotal = (float) $bill["total_amount"];

$remainingDue = max(0, $grandTotal - $totalPaid);
$overpayment = max(0, $totalPaid - $grandTotal);

if ($totalPaid <= 0) {
    $paymentStatus = "Unpaid";
} elseif ($remainingDue > 0.009) {
    $paymentStatus = "Partially Paid";
} else {
    $paymentStatus = "Paid";
}

/*
=========================================================
INVOICE DISPLAY VALUES
=========================================================
*/

$invoiceNumber = $bill["invoice_no"] ?: ("INV-" . $bill["id"]);

$invoiceDate = !empty($bill["billing_date"])
    ? date("d M Y", strtotime($bill["billing_date"]))
    : date("d M Y", strtotime($bill["created_at"] ?? "now"));

$guestName = $bill["full_name"] ?: "Guest";

$roomCharge = (float) $bill["room_charge"];
$foodCharge = (float) $bill["food_charge"];
$serviceCharge = (float) $bill["service_charge"];
$discount = (float) $bill["discount"];
$tax = (float) $bill["tax"];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Invoice - <?= e($invoiceNumber) ?> | Suva Hotel</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px 15px;
            background: #f1f5f9;
            color: #1e293b;
            font-family: Arial, Helvetica, sans-serif;
        }

        .invoice {
            width: 100%;
            max-width: 850px;
            margin: auto;
            padding: 40px;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.08);
        }

        .top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            padding-bottom: 25px;
            border-bottom: 2px solid #e2e8f0;
        }

        .hotel-name {
            margin: 0;
            color: #0f766e;
            font-size: 30px;
        }

        .subtitle {
            margin-top: 8px;
            color: #64748b;
        }

        .invoice-heading {
            text-align: right;
        }

        .invoice-heading h2 {
            margin: 0 0 10px;
            font-size: 25px;
        }

        .muted {
            color: #64748b;
        }

        .details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin: 28px 0;
        }

        .details h3 {
            margin: 0 0 12px;
            color: #0f766e;
            font-size: 15px;
        }

        .details p {
            margin: 7px 0;
            overflow-wrap: anywhere;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th,
        td {
            padding: 14px 12px;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
        }

        th {
            background: #f8fafc;
            color: #334155;
        }

        .amount {
            text-align: right;
            white-space: nowrap;
        }

        .totals {
            width: 100%;
            max-width: 380px;
            margin: 20px 0 0 auto;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 9px 0;
        }

        .grand-total {
            margin-top: 8px;
            padding-top: 15px;
            border-top: 2px solid #cbd5e1;
            font-size: 19px;
            font-weight: bold;
        }

        .payment-box {
            margin-top: 30px;
            padding: 20px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #f8fafc;
        }

        .payment-box h3 {
            margin-top: 0;
        }

        .payment-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 8px 0;
        }

        .status {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: bold;
        }

        .status-paid {
            color: #166534;
            background: #dcfce7;
        }

        .status-partial {
            color: #92400e;
            background: #fef3c7;
        }

        .status-unpaid {
            color: #991b1b;
            background: #fee2e2;
        }

        .footer {
            margin-top: 35px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            text-align: center;
            color: #64748b;
            line-height: 1.7;
        }

        .actions {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin: 25px auto 0;
            max-width: 850px;
        }

        .btn {
            display: inline-block;
            padding: 12px 20px;
            border: 0;
            border-radius: 7px;
            background: #0f766e;
            color: #ffffff;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-secondary {
            background: #475569;
        }

        @media (max-width: 600px) {
            body {
                padding: 10px;
            }

            .invoice {
                padding: 20px 15px;
            }

            .top {
                flex-direction: column;
            }

            .invoice-heading {
                text-align: left;
            }

            .details {
                grid-template-columns: 1fr;
            }

            th,
            td {
                padding: 10px 6px;
                font-size: 13px;
            }

            .actions {
                flex-wrap: wrap;
            }
        }

        @media print {
            body {
                padding: 0;
                background: #ffffff;
            }

            .invoice {
                max-width: none;
                padding: 20px;
                box-shadow: none;
                border-radius: 0;
            }

            .actions {
                display: none;
            }

            .payment-box,
            th {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
        }
    </style>
</head>

<body>

<div class="invoice">

    <div class="top">
        <div>
            <h1 class="hotel-name">SUVA HOTEL</h1>
            <p class="subtitle">Hotel Management and Guest Services</p>
        </div>

        <div class="invoice-heading">
            <h2>INVOICE</h2>
            <p>
                <strong>Invoice No:</strong><br>
                <?= e($invoiceNumber) ?>
            </p>
            <p>
                <strong>Invoice Date:</strong><br>
                <?= e($invoiceDate) ?>
            </p>
        </div>
    </div>

    <div class="details">
        <div>
            <h3>BILL TO</h3>

            <p>
                <strong>Guest:</strong>
                <?= e($guestName) ?>
            </p>

            <p>
                <strong>Guest ID:</strong>
                <?= e($bill["guest_id"]) ?>
            </p>

            <p>
                <strong>Booking ID:</strong>
                <?= e($bill["booking_id"]) ?>
            </p>
        </div>

        <div>
            <h3>INVOICE INFORMATION</h3>

            <p>
                <strong>Bill ID:</strong>
                <?= e($bill["id"]) ?>
            </p>

            <p>
                <strong>Created:</strong>
                <?= e($bill["created_at"] ?? $invoiceDate) ?>
            </p>

            <p>
                <strong>Payment Status:</strong>
                <span class="status <?=
                    $paymentStatus === "Paid"
                        ? "status-paid"
                        : ($paymentStatus === "Unpaid"
                            ? "status-unpaid"
                            : "status-partial")
                ?>">
                    <?= e($paymentStatus) ?>
                </span>
            </p>
        </div>
    </div>

    <h3>Bill Details</h3>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="amount">Amount</th>
            </tr>
        </thead>

        <tbody>
            <tr>
                <td>Room Charges</td>
                <td class="amount"><?= money($roomCharge) ?></td>
            </tr>

            <tr>
                <td>Food Charges</td>
                <td class="amount"><?= money($foodCharge) ?></td>
            </tr>

            <tr>
                <td>Restaurant and Other Service Charges</td>
                <td class="amount"><?= money($serviceCharge) ?></td>
            </tr>

            <tr>
                <td>Discount</td>
                <td class="amount">- <?= money($discount) ?></td>
            </tr>

            <tr>
                <td>Tax</td>
                <td class="amount"><?= money($tax) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="totals">
        <div class="total-row grand-total">
            <span>Total Bill</span>
            <span><?= money($grandTotal) ?></span>
        </div>
    </div>

    <div class="payment-box">
        <h3>Payment Summary</h3>

        <div class="payment-row">
            <span>Total Bill</span>
            <strong><?= money($grandTotal) ?></strong>
        </div>

        <div class="payment-row">
            <span>Total Paid</span>
            <strong><?= money($totalPaid) ?></strong>
        </div>

        <div class="payment-row">
            <span>Remaining Due</span>
            <strong><?= money($remainingDue) ?></strong>
        </div>

        <?php if ($overpayment > 0.009): ?>
            <div class="payment-row">
                <span>Excess Payment</span>
                <strong><?= money($overpayment) ?></strong>
            </div>
        <?php endif; ?>

        <div class="payment-row">
            <span>Payment Status</span>
            <strong><?= e($paymentStatus) ?></strong>
        </div>
    </div>

    <div class="footer">
        <strong>Thank you for choosing Suva Hotel!</strong>
        <br>
        Please keep this invoice for your records.
        <br>
        <small>This invoice was generated by the Suva Hotel Management System.</small>
    </div>

</div>

<div class="actions">
    <button class="btn" onclick="window.print()">
        Print Invoice
    </button>

    <button class="btn btn-secondary" onclick="history.back()">
        Go Back
    </button>
</div>

</body>
</html>
