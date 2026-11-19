
<?php
// ============================================================
// SUVA HOTEL - PAYMENT HISTORY
// PostgreSQL Version
// File: /Suva_hotel/payment/view.php
// Latest invoice per booking + complete payment history
// ============================================================

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";

if (!isset($pgConnection) || !$pgConnection) {
    die("Database connection failed.");
}

function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        "UTF-8"
    );
}

function money($amount)
{
    return number_format((float)$amount, 2);
}

$search = trim($_GET["q"] ?? "");
$searchPattern = "%" . $search . "%";
$error = "";
$bookings = [];
$payments = [];

// ============================================================
// 1. LATEST BILL AND PAYMENT SUMMARY
// Old invoices are not added together.
// ============================================================

$summarySql = "
    WITH latest_bills AS (
        SELECT DISTINCT ON (booking_id)
            id,
            booking_id,
            invoice_no,
            billing_date,
            total_amount,
            status
        FROM billing
        ORDER BY
            booking_id,
            billing_date DESC NULLS LAST,
            id DESC
    ),
    payment_totals AS (
        SELECT
            booking_id,
            COALESCE(
                SUM(amount) FILTER (
                    WHERE LOWER(
                        COALESCE(payment_status, '')
                    ) IN ('paid', 'partial')
                ),
                0
            ) AS total_paid
        FROM payments
        GROUP BY booking_id
    )
    SELECT
        bk.id AS booking_id,
        g.full_name AS guest_name,
        r.room_number,
        lb.id AS bill_id,
        lb.invoice_no,
        lb.billing_date,
        lb.total_amount AS bill_total,
        lb.status AS bill_status,
        COALESCE(pt.total_paid, 0) AS total_paid
    FROM bookings bk
    JOIN guests g
        ON bk.guest_id = g.id
    LEFT JOIN rooms r
        ON bk.room_id = r.id
    LEFT JOIN latest_bills lb
        ON lb.booking_id = bk.id
    LEFT JOIN payment_totals pt
        ON pt.booking_id = bk.id
    WHERE
        (
            $1 = ''
            OR g.full_name ILIKE $2
            OR CAST(bk.id AS TEXT) ILIKE $2
            OR COALESCE(lb.invoice_no, '') ILIKE $2
        )
    ORDER BY bk.id DESC
";

$summaryResult = pg_query_params(
    $pgConnection,
    $summarySql,
    [$search, $searchPattern]
);

if (!$summaryResult) {
    error_log(pg_last_error($pgConnection));
    $error = "Could not load bill summaries. Check the database.";
} else {
    while ($row = pg_fetch_assoc($summaryResult)) {
        $bookings[] = $row;
    }
}

// ============================================================
// 2. COMPLETE PAYMENT TRANSACTION HISTORY
// Existing payment rows are displayed without modification.
// ============================================================

$historySql = "
    SELECT
        p.id,
        p.booking_id,
        g.full_name AS guest_name,
        r.room_number,
        p.amount,
        p.payment_method,
        p.payment_status,
        p.paid_at
    FROM payments p
    JOIN bookings bk
        ON p.booking_id = bk.id
    JOIN guests g
        ON bk.guest_id = g.id
    LEFT JOIN rooms r
        ON bk.room_id = r.id
    WHERE
        (
            $1 = ''
            OR g.full_name ILIKE $2
            OR CAST(bk.id AS TEXT) ILIKE $2
        )
    ORDER BY p.paid_at DESC NULLS LAST, p.id DESC
";

$historyResult = pg_query_params(
    $pgConnection,
    $historySql,
    [$search, $searchPattern]
);

if (!$historyResult) {
    error_log(pg_last_error($pgConnection));
    $error = "Could not load payment history. Check the database.";
} else {
    while ($row = pg_fetch_assoc($historyResult)) {
        $payments[] = $row;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>Payment History - Suva Hotel</title>

<style>
* {
    box-sizing: border-box;
}

body {
    font-family: Arial, sans-serif;
    background: #f4f6f8;
    margin: 0;
    padding: 24px;
    color: #263238;
}

.container {
    max-width: 1250px;
    margin: auto;
}

.header,
.panel {
    background: #fff;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 22px;
    box-shadow: 0 4px 15px rgba(0,0,0,.07);
}

h1 {
    color: #1f3c88;
    margin: 0 0 8px;
}

h2 {
    margin-top: 0;
    font-size: 21px;
}

.muted {
    color: #64748b;
    font-size: 13px;
}

.actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 18px;
}

.btn {
    display: inline-block;
    background: #1f3c88;
    color: white;
    padding: 10px 15px;
    border-radius: 6px;
    text-decoration: none;
    border: 0;
    cursor: pointer;
    font-size: 14px;
}

.btn.green {
    background: #198754;
}

.search-form {
    display: flex;
    gap: 10px;
    margin: 16px 0;
}

.search-form input {
    flex: 1;
    min-width: 0;
    padding: 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 15px;
}

.table-box {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}

th {
    background: #1f3c88;
    color: white;
    padding: 12px;
    text-align: left;
    white-space: nowrap;
}

td {
    padding: 12px;
    border-bottom: 1px solid #e2e8f0;
    vertical-align: top;
}

tbody tr:hover {
    background: #f8fafc;
}

.amount {
    font-weight: bold;
    white-space: nowrap;
}

.paid {
    color: #198754;
    font-weight: bold;
}

.partial {
    color: #b7791f;
    font-weight: bold;
}

.unpaid {
    color: #c62828;
    font-weight: bold;
}

.credit {
    color: #6f42c1;
    font-weight: bold;
}

.notice {
    background: #fff3cd;
    border: 1px solid #ffe69c;
    color: #664d03;
    border-radius: 8px;
    padding: 13px;
    margin-bottom: 18px;
}

.error {
    background: #f8d7da;
    color: #842029;
    padding: 12px;
    border-radius: 6px;
    margin-bottom: 18px;
}

.empty {
    text-align: center;
    color: #64748b;
    padding: 24px;
}

@media (max-width: 650px) {
    body {
        padding: 10px;
    }

    .header,
    .panel {
        padding: 15px;
    }

    .search-form {
        flex-direction: column;
    }
}
</style>
</head>

<body>
<div class="container">

    <div class="header">
        <h1>🏨 Suva Hotel</h1>
        <h2>💳 Payment History & Bill Summary</h2>

        <p class="muted">
            Latest invoice per booking, recorded payments,
            and outstanding balance.
        </p>

        <div class="actions">
            <a class="btn green" href="add.php">+ Add Payment</a>
            <a class="btn" href="../billing/view.php">Billing</a>
            <a class="btn" href="../dashboard.php">Dashboard</a>
        </div>
    </div>

    <?php if ($error !== ""): ?>
        <div class="error"><?php echo e($error); ?></div>
    <?php endif; ?>

    <div class="panel">
        <h2>🔎 Search Guest or Booking</h2>

        <form method="GET" class="search-form">
            <input
                type="text"
                name="q"
                placeholder="Enter guest name, booking ID or invoice number"
                value="<?php echo e($search); ?>"
            >
            <button type="submit" class="btn">Search</button>
            <a href="view.php" class="btn">Clear</a>
        </form>
    </div>

    <div class="panel">
        <h2>📋 Latest Bill Summary</h2>

        <div class="notice">
            Only the latest invoice for each booking is used in this
            summary. Older invoices remain in the database.
            Payment transactions are preserved and shown below.
        </div>

        <div class="table-box">
        <table>
            <thead>
            <tr>
                <th>Booking</th>
                <th>Guest</th>
                <th>Room</th>
                <th>Latest Invoice</th>
                <th>Bill Total</th>
                <th>Total Paid</th>
                <th>Remaining</th>
                <th>Credit / Excess</th>
                <th>Status</th>
            </tr>
            </thead>

            <tbody>
            <?php if (count($bookings) > 0): ?>

                <?php foreach ($bookings as $row): ?>
                    <?php
                    $hasBill = $row["bill_id"] !== null;

                    $billTotal = $hasBill
                        ? (float)$row["bill_total"]
                        : 0.0;

                    $totalPaid = (float)$row["total_paid"];

                    $remaining = max(
                        0,
                        $billTotal - $totalPaid
                    );

                    $credit = max(
                        0,
                        $totalPaid - $billTotal
                    );

                    if (!$hasBill) {
                        $status = "No bill";
                        $statusClass = "unpaid";
                    } elseif ($credit > 0) {
                        $status = "Excess payment";
                        $statusClass = "credit";
                    } elseif ($remaining <= 0) {
                        $status = "Paid";
                        $statusClass = "paid";
                    } elseif ($totalPaid > 0) {
                        $status = "Partially Paid";
                        $statusClass = "partial";
                    } else {
                        $status = "Unpaid";
                        $statusClass = "unpaid";
                    }
                    ?>

                    <tr>
                        <td>
                            #<?php echo (int)$row["booking_id"]; ?>
                        </td>

                        <td>
                            <strong>
                                <?php echo e($row["guest_name"]); ?>
                            </strong>
                        </td>

                        <td>
                            <?php
                            echo e($row["room_number"] ?? "—");
                            ?>
                        </td>

                        <td>
                            <?php if ($hasBill): ?>
                                <?php echo e($row["invoice_no"]); ?>
                                <br>
                                <span class="muted">
                                    <?php
                                    echo e(
                                        $row["billing_date"] ?? ""
                                    );
                                    ?>
                                </span>
                            <?php else: ?>
                                No bill
                            <?php endif; ?>
                        </td>

                        <td class="amount">
                            Rs. <?php echo money($billTotal); ?>
                        </td>

                        <td class="amount">
                            Rs. <?php echo money($totalPaid); ?>
                        </td>

                        <td class="amount">
                            Rs. <?php echo money($remaining); ?>
                        </td>

                        <td class="amount">
                            <?php if ($credit > 0): ?>
                                <span class="credit">
                                    Rs. <?php echo money($credit); ?>
                                </span>
                            <?php else: ?>
                                Rs. 0.00
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="<?php echo e($statusClass); ?>">
                                <?php echo e($status); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>

            <?php else: ?>
                <tr>
                    <td colspan="9" class="empty">
                        No matching bookings found.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="panel">
        <h2>💰 All Payment Transactions</h2>

        <p class="muted">
            Existing payment records are displayed individually,
            including their original payment status and method.
        </p>

        <div class="table-box">
        <table>
            <thead>
            <tr>
                <th>Payment ID</th>
                <th>Booking</th>
                <th>Guest</th>
                <th>Room</th>
                <th>Amount</th>
                <th>Method</th>
                <th>Recorded Status</th>
                <th>Paid At</th>
            </tr>
            </thead>

            <tbody>
            <?php if (count($payments) > 0): ?>

                <?php foreach ($payments as $row): ?>
                    <?php
                    $paymentStatus = $row["payment_status"] ?? "";
                    $normalizedStatus = strtolower($paymentStatus);

                    $statusClass = "unpaid";

                    if ($normalizedStatus === "paid") {
                        $statusClass = "paid";
                    } elseif ($normalizedStatus === "partial") {
                        $statusClass = "partial";
                    }
                    ?>

                    <tr>
                        <td><?php echo (int)$row["id"]; ?></td>

                        <td>
                            #<?php echo (int)$row["booking_id"]; ?>
                        </td>

                        <td><?php echo e($row["guest_name"]); ?></td>

                        <td>
                            <?php
                            echo e($row["room_number"] ?? "—");
                            ?>
                        </td>

                        <td class="amount">
                            Rs. <?php echo money($row["amount"]); ?>
                        </td>

                        <td>
                            <?php echo e($row["payment_method"]); ?>
                        </td>

                        <td>
                            <span class="<?php echo e($statusClass); ?>">
                                <?php echo e($paymentStatus); ?>
                            </span>
                        </td>

                        <td>
                            <?php
                            if (!empty($row["paid_at"])) {
                                echo e(
                                    date(
                                        "Y-m-d h:i A",
                                        strtotime($row["paid_at"])
                                    )
                                );
                            } else {
                                echo "—";
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

            <?php else: ?>
                <tr>
                    <td colspan="8" class="empty">
                        No payment records found.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

</div>
</body>
</html>
