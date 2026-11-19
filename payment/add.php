
<?php

// ============================================================
// SUVA HOTEL - NEW PAYMENT
// PostgreSQL Version
// File: /suva_hotel/payment/add.php
// ============================================================

session_start();

// ============================================================
// LOGIN CHECK
// ============================================================

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

// ============================================================
// DATABASE CONNECTION
// ============================================================

require_once __DIR__ . "/../config/database.php";

if (!isset($pgConnection) || !$pgConnection) {
    die("Database connection failed.");
}

$message = "";
$message_type = "";

// ============================================================
// ESCAPE HELPER
// ============================================================

function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        "UTF-8"
    );
}

// ============================================================
// PROCESS PAYMENT
// ============================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $booking_id = (int)($_POST["booking_id"] ?? 0);

    $amount = round(
        (float)($_POST["amount"] ?? 0),
        2
    );

    $payment_method = trim(
        $_POST["payment_method"] ?? ""
    );

    // --------------------------------------------------------
    // VALIDATION
    // --------------------------------------------------------

    if ($booking_id <= 0) {

        $message = "Please select a booking.";
        $message_type = "error";

    } elseif (!is_numeric($_POST["amount"] ?? null)
        || $amount <= 0) {

        $message = "Payment amount must be greater than 0.";
        $message_type = "error";

    } elseif (!in_array(
        $payment_method,
        ["Cash", "QR Payment", "Card"],
        true
    )) {

        $message = "Please select a valid payment method.";
        $message_type = "error";

    } else {

        // ====================================================
        // START TRANSACTION
        // ====================================================

        $begin_result = pg_query(
            $pgConnection,
            "BEGIN"
        );

        if (!$begin_result) {

            error_log(
                "Payment Transaction Start Error: "
                . pg_last_error($pgConnection)
            );

            $message = "Could not start the payment process.";
            $message_type = "error";

        } else {

            // =================================================
            // LOCK THE LATEST BILL FOR THIS BOOKING
            // =================================================

            $bill_sql = "
                SELECT
                    id,
                    booking_id,
                    total_amount,
                    status
                FROM billing
                WHERE booking_id = $1
                ORDER BY id DESC
                LIMIT 1
                FOR UPDATE
            ";

            $bill_result = pg_query_params(
                $pgConnection,
                $bill_sql,
                [$booking_id]
            );

            if (!$bill_result) {

                $error = pg_last_error($pgConnection);

                pg_query($pgConnection, "ROLLBACK");

                error_log(
                    "Billing Query Error: " . $error
                );

                $message = "Could not retrieve the bill.";
                $message_type = "error";

            } else {

                $bill = pg_fetch_assoc($bill_result);

                // =============================================
                // CHECK BILL
                // =============================================

                if (!$bill) {

                    pg_query($pgConnection, "ROLLBACK");

                    $message =
                        "No bill found for this booking.";

                    $message_type = "error";

                } else {

                    $bill_total = round(
                        (float)$bill["total_amount"],
                        2
                    );

                    // =========================================
                    // CALCULATE EXISTING PAYMENTS
                    // =========================================

                    $paid_sql = "
                        SELECT
                            COALESCE(SUM(amount), 0)
                                AS paid_amount
                        FROM payments
                        WHERE booking_id = $1
                          AND LOWER(
                              TRIM(
                                  COALESCE(payment_status, '')
                              )
                          ) IN ('paid', 'partial')
                    ";

                    $paid_result = pg_query_params(
                        $pgConnection,
                        $paid_sql,
                        [$booking_id]
                    );

                    if (!$paid_result) {

                        $error = pg_last_error($pgConnection);

                        pg_query($pgConnection, "ROLLBACK");

                        error_log(
                            "Payment Total Query Error: "
                            . $error
                        );

                        $message =
                            "Could not calculate existing payments.";

                        $message_type = "error";

                    } else {

                        $paid_row = pg_fetch_assoc(
                            $paid_result
                        );

                        $already_paid = round(
                            (float)(
                                $paid_row["paid_amount"] ?? 0
                            ),
                            2
                        );

                        // =====================================
                        // REMAINING BALANCE
                        // =====================================

                        $remaining = round(
                            $bill_total - $already_paid,
                            2
                        );

                        if ($remaining < 0) {
                            $remaining = 0;
                        }

                        // =====================================
                        // VALIDATE PAYMENT AMOUNT
                        // =====================================

                        if ($remaining <= 0) {

                            pg_query(
                                $pgConnection,
                                "ROLLBACK"
                            );

                            $message =
                                "This booking is already fully paid.";

                            $message_type = "error";

                        } elseif ($amount > $remaining) {

                            pg_query(
                                $pgConnection,
                                "ROLLBACK"
                            );

                            $message =
                                "Payment cannot be greater than "
                                . "the remaining amount. Remaining: Rs. "
                                . number_format(
                                    $remaining,
                                    2
                                );

                            $message_type = "error";

                        } else {

                            // =================================
                            // CALCULATE NEW TOTAL
                            // =================================

                            $new_paid_total = round(
                                $already_paid + $amount,
                                2
                            );

                            if (
                                $new_paid_total >= $bill_total
                            ) {

                                $payment_status = "Paid";
                                $bill_status = "paid";

                            } else {

                                $payment_status = "Partial";
                                $bill_status = "partial";

                            }

                            // =================================
                            // INSERT PAYMENT
                            // =================================

                            $insert_sql = "
                                INSERT INTO payments
                                (
                                    booking_id,
                                    amount,
                                    payment_method,
                                    payment_status,
                                    paid_at
                                )
                                VALUES
                                (
                                    $1,
                                    $2,
                                    $3,
                                    $4,
                                    CURRENT_TIMESTAMP
                                )
                            ";

                            $insert_result = pg_query_params(
                                $pgConnection,
                                $insert_sql,
                                [
                                    $booking_id,
                                    $amount,
                                    $payment_method,
                                    $payment_status
                                ]
                            );

                            if (!$insert_result) {

                                $error = pg_last_error(
                                    $pgConnection
                                );

                                pg_query(
                                    $pgConnection,
                                    "ROLLBACK"
                                );

                                error_log(
                                    "Payment Insert Error: "
                                    . $error
                                );

                                $message =
                                    "Payment could not be saved. "
                                    . "No transaction changes were committed.";

                                $message_type = "error";

                            } else {

                                // =============================
                                // UPDATE INVOICE STATUS
                                // =============================

                                $update_bill_sql = "
                                    UPDATE billing
                                    SET status = $1
                                    WHERE id = $2
                                ";

                                $update_result = pg_query_params(
                                    $pgConnection,
                                    $update_bill_sql,
                                    [
                                        $bill_status,
                                        $bill["id"]
                                    ]
                                );

                                if (
                                    !$update_result
                                    || pg_affected_rows(
                                        $update_result
                                    ) !== 1
                                ) {

                                    $error = pg_last_error(
                                        $pgConnection
                                    );

                                    pg_query(
                                        $pgConnection,
                                        "ROLLBACK"
                                    );

                                    error_log(
                                        "Billing Update Error: "
                                        . $error
                                    );

                                    $message =
                                        "Invoice update failed. "
                                        . "The payment transaction "
                                        . "was rolled back.";

                                    $message_type = "error";

                                } else {

                                    // =========================
                                    // COMMIT TRANSACTION
                                    // =========================

                                    $commit_result = pg_query(
                                        $pgConnection,
                                        "COMMIT"
                                    );

                                    if (!$commit_result) {

                                        error_log(
                                            "Payment Commit Error: "
                                            . pg_last_error(
                                                $pgConnection
                                            )
                                        );

                                        // Do not automatically
                                        // retry the payment.
                                        // Check the database first.

                                        $message =
                                            "The payment result "
                                            . "could not be confirmed. "
                                            . "Check payment history "
                                            . "before retrying.";

                                        $message_type = "error";

                                    } else {

                                        // =====================
                                        // SUCCESS MESSAGE
                                        // =====================

                                        $new_remaining = round(
                                            $bill_total
                                            - $new_paid_total,
                                            2
                                        );

                                        if ($new_remaining < 0) {
                                            $new_remaining = 0;
                                        }

                                        $message =
                                            "Payment recorded successfully! "
                                            . "Paid: Rs. "
                                            . number_format(
                                                $amount,
                                                2
                                            )
                                            . " | Remaining: Rs. "
                                            . number_format(
                                                $new_remaining,
                                                2
                                            );

                                        $message_type = "success";
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

// ============================================================
// GET BOOKINGS FOR FORM
// ============================================================

$sql = "
    SELECT
        b.id,
        b.guest_id,
        b.room_id,
        b.total_amount,
        g.full_name,
        r.room_number
    FROM bookings b
    LEFT JOIN guests g
        ON b.guest_id = g.id
    LEFT JOIN rooms r
        ON b.room_id = r.id
    ORDER BY b.id DESC
";

$result = pg_query(
    $pgConnection,
    $sql
);

if (!$result) {
    die(
        "Database Error: "
        . pg_last_error($pgConnection)
    );
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>New Payment - Suva Hotel</title>

<style>

body {
    font-family: Arial, sans-serif;
    background: #f4f6f8;
    margin: 0;
    padding: 30px;
}

.container {
    max-width: 700px;
    margin: auto;
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.10);
}

h1 {
    color: #1f3c88;
}

h2 {
    color: #333;
}

label {
    font-weight: bold;
}

select,
input {
    width: 100%;
    padding: 12px;
    margin-top: 7px;
    box-sizing: border-box;
    border: 1px solid #ccc;
    border-radius: 6px;
}

.info {
    background: #eef3ff;
    padding: 15px;
    border-radius: 8px;
    margin: 15px 0;
}

button {
    width: 100%;
    background: #1f3c88;
    color: white;
    border: none;
    padding: 13px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 16px;
}

button:hover {
    background: #162d68;
}

.success {
    color: #155724;
    background: #d4edda;
    padding: 12px;
    border-radius: 6px;
    margin-bottom: 15px;
}

.error {
    color: #721c24;
    background: #f8d7da;
    padding: 12px;
    border-radius: 6px;
    margin-bottom: 15px;
}

.links {
    margin-top: 20px;
}

.links a {
    display: inline-block;
    margin: 0 12px 8px 0;
    text-decoration: none;
    color: #1f3c88;
    font-weight: bold;
}

</style>

</head>

<body>

<div class="container">

<h1>🏨 Suva Hotel</h1>

<h2>💳 New Payment</h2>

<?php if ($message !== ""): ?>

    <div class="<?php echo e($message_type); ?>">
        <strong><?php echo e($message); ?></strong>
    </div>

<?php endif; ?>

<form method="POST">

<label for="booking_id">
    Select Booking:
</label>

<select
    name="booking_id"
    id="booking_id"
    required
>

<option value="">
    -- Select Booking --
</option>

<?php while ($row = pg_fetch_assoc($result)): ?>

<option
    value="<?php echo e($row["id"]); ?>"
    <?php
    echo (
        isset($booking_id)
        && $booking_id === (int)$row["id"]
    ) ? "selected" : "";
    ?>
>

Booking #<?php echo e($row["id"]); ?>
-
<?php echo e($row["full_name"] ?? "Unknown Guest"); ?>
-
Room <?php echo e($row["room_number"] ?? "Unknown"); ?>

</option>

<?php endwhile; ?>

</select>

<br><br>

<div class="info">

    <strong>Bill Information</strong>

    <p>
        Bill Total:
        <strong>Rs. <span id="bill_total">0.00</span></strong>
    </p>

    <p>
        Already Paid:
        <strong>Rs. <span id="already_paid">0.00</span></strong>
    </p>

    <p>
        Remaining:
        <strong>Rs. <span id="remaining">0.00</span></strong>
    </p>

    <p>
        The server checks the actual balance before saving.
    </p>

</div>

<label for="amount">
    Payment Amount (Rs.):
</label>

<input
    type="number"
    name="amount"
    id="amount"
    min="0.01"
    step="0.01"
    required
>

<br><br>

<label for="payment_method">
    Payment Method:
</label>

<select
    name="payment_method"
    id="payment_method"
    required
>

<option value="">
    -- Select Payment Method --
</option>

<option value="Cash">💵 Cash</option>

<option value="QR Payment">📱 QR Payment</option>

<option value="Card">💳 Card</option>

</select>

<br><br>

<button type="submit">
    💰 Record Payment
</button>

</form>

<div class="links">

<a href="view.php">📄 Payment History</a>

<a href="../billing/view.php">💰 Billing</a>

<a href="../dashboard.php">← Dashboard</a>

</div>

</div>

<script>
const bookingSelect = document.getElementById("booking_id");

bookingSelect.addEventListener("change", async function () {
    const bookingId = this.value;

    document.getElementById("bill_total").textContent = "0.00";
    document.getElementById("already_paid").textContent = "0.00";
    document.getElementById("remaining").textContent = "0.00";

    if (!bookingId) {
        return;
    }

    try {
        const response = await fetch(
            "get_bill_info.php?booking_id=" +
            encodeURIComponent(bookingId)
        );

        const data = await response.json();

        if (!response.ok) {
            alert(data.error || "Could not load bill information.");
            return;
        }

        document.getElementById("bill_total").textContent =
            data.bill_total;

        document.getElementById("already_paid").textContent =
            data.already_paid;

        document.getElementById("remaining").textContent =
            data.remaining;

    } catch (error) {
        alert("Unable to load bill information. Please try again.");
    }
});
</script>


</body>
</html>
