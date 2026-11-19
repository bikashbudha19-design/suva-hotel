
<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once "../config/database.php";

$message = "";
$message_type = "";

function e($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

/* ============================================================
   HANDLE CREATE OR UPDATE CURRENT BILL
   ============================================================ */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $booking_id = filter_input(INPUT_POST, "booking_id", FILTER_VALIDATE_INT);
    $food_raw = $_POST["food_charge"] ?? "0";
    $service_raw = $_POST["service_charge"] ?? "0";
    $discount_raw = $_POST["discount"] ?? "0";

    $transaction_started = false;

    try {

        // Validate submitted values before using them.
        if (!$booking_id || $booking_id <= 0) {
            throw new RuntimeException("Please select a valid booking.");
        }

        foreach ([
            "Food charge" => $food_raw,
            "Service charge" => $service_raw,
            "Discount" => $discount_raw
        ] as $label => $value) {

            if (!is_scalar($value) || !is_numeric((string)$value)) {
                throw new RuntimeException($label . " must be a valid number.");
            }
        }

        $food_charge = round((float)$food_raw, 2);
        $service_charge = round((float)$service_raw, 2);
        $discount_percent = (float)$discount_raw;

        if (!is_finite($food_charge) || $food_charge < 0) {
            throw new RuntimeException("Food charge cannot be negative or invalid.");
        }

        if (!is_finite($service_charge) || $service_charge < 0) {
            throw new RuntimeException("Service charge cannot be negative or invalid.");
        }

        if (!is_finite($discount_percent) ||
            $discount_percent < 0 ||
            $discount_percent > 100) {
            throw new RuntimeException("Discount must be between 0% and 100%.");
        }

        /*
         * Start a transaction and lock the booking.
         */
        if (!pg_query($pgConnection, "BEGIN")) {
            throw new RuntimeException("Could not start the database transaction.");
        }

        $transaction_started = true;

        $booking_sql = "
            SELECT id, guest_id, total_amount
            FROM bookings
            WHERE id = $1
            FOR UPDATE
        ";

        $booking_result = pg_query_params(
            $pgConnection,
            $booking_sql,
            [$booking_id]
        );

        if (!$booking_result) {
            throw new RuntimeException("Could not load the selected booking.");
        }

        $booking = pg_fetch_assoc($booking_result);

        if (!$booking) {
            throw new RuntimeException("Booking not found.");
        }

        $guest_id = (int)$booking["guest_id"];
        $room_charge = round((float)$booking["total_amount"], 2);

        if ($guest_id <= 0 || !is_finite($room_charge) || $room_charge < 0) {
            throw new RuntimeException("The booking has invalid guest or room-charge data.");
        }

        /*
         * Calculate the bill on the server.
         */
        $gross_amount = round(
            $room_charge + $food_charge + $service_charge,
            2
        );

        $discount = round(
            $gross_amount * ($discount_percent / 100),
            2
        );

        $subtotal = max(0, round($gross_amount - $discount, 2));

        $tax = round($subtotal * 0.13, 2);

        $total_amount = round($subtotal + $tax, 2);

        $billing_date = date("Y-m-d");

        /*
         * Find the latest invoice only.
         * Older invoices remain untouched.
         */
        $latest_sql = "
            SELECT id, invoice_no, status
            FROM billing
            WHERE booking_id = $1
            ORDER BY id DESC
            LIMIT 1
            FOR UPDATE
        ";

        $latest_result = pg_query_params(
            $pgConnection,
            $latest_sql,
            [$booking_id]
        );

        if (!$latest_result) {
            throw new RuntimeException("Could not check existing invoices.");
        }

        $latest_invoice = pg_fetch_assoc($latest_result);

        if ($latest_invoice) {

            /*
             * Update the latest invoice.
             * Keep its invoice number and existing payment status.
             * Do not modify any payment records.
             */
            $invoice_id = (int)$latest_invoice["id"];
            $invoice_no = $latest_invoice["invoice_no"];

            $update_sql = "
                UPDATE billing
                SET guest_id = $1,
                    billing_date = $2,
                    room_charge = $3,
                    food_charge = $4,
                    service_charge = $5,
                    discount = $6,
                    tax = $7,
                    total_amount = $8
                WHERE id = $9
                  AND booking_id = $10
            ";

            $save_result = pg_query_params(
                $pgConnection,
                $update_sql,
                [
                    $guest_id,
                    $billing_date,
                    $room_charge,
                    $food_charge,
                    $service_charge,
                    $discount,
                    $tax,
                    $total_amount,
                    $invoice_id,
                    $booking_id
                ]
            );

            $success_message = "Current invoice updated successfully! ";

        } else {

            /*
             * No invoice exists: create the first one.
             */
            $invoice_no =
                "INV-" . date("YmdHis") . "-" . $booking_id;

            $insert_sql = "
                INSERT INTO billing (
                    booking_id,
                    guest_id,
                    invoice_no,
                    billing_date,
                    room_charge,
                    food_charge,
                    service_charge,
                    discount,
                    tax,
                    total_amount,
                    status
                )
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11)
            ";

            $save_result = pg_query_params(
                $pgConnection,
                $insert_sql,
                [
                    $booking_id,
                    $guest_id,
                    $invoice_no,
                    $billing_date,
                    $room_charge,
                    $food_charge,
                    $service_charge,
                    $discount,
                    $tax,
                    $total_amount,
                    "unpaid"
                ]
            );

            $success_message = "First invoice created successfully! ";
        }

        if (!$save_result) {
            throw new RuntimeException("Could not save the invoice.");
        }

        if (!pg_query($pgConnection, "COMMIT")) {
            throw new RuntimeException("Could not commit the invoice changes.");
        }

        $transaction_started = false;

        $message = $success_message
            . "Invoice: " . $invoice_no
            . " | Total: Rs. " . number_format($total_amount, 2);

        $message_type = "success";

    } catch (Throwable $ex) {

        if ($transaction_started) {
            pg_query($pgConnection, "ROLLBACK");
        }

        error_log("Suva Hotel billing error: " . $ex->getMessage());

        $message = $ex instanceof RuntimeException
            ? $ex->getMessage()
            : "Unable to save the bill. Check the PHP error log.";

        $message_type = "error";
    }
}

/* ============================================================
   LOAD BOOKINGS FOR THE FORM
   ============================================================ */

$sql = "
    SELECT
        b.id,
        b.guest_id,
        b.room_id,
        g.full_name,
        r.room_number,
        b.check_in,
        b.check_out,
        b.total_amount
    FROM bookings b
    LEFT JOIN guests g ON g.id = b.guest_id
    LEFT JOIN rooms r ON r.id = b.room_id
    ORDER BY b.id DESC
";

$result = pg_query($pgConnection, $sql);

if (!$result) {
    error_log("Suva Hotel booking list error: " . pg_last_error($pgConnection));
    die("Unable to load bookings. Check the PHP error log.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Bill - Suva Hotel</title>

<style>
* { box-sizing: border-box; }

body {
    font-family: Arial, sans-serif;
    background: #f4f6f8;
    margin: 0;
    padding: 24px;
    color: #222;
}

.container {
    max-width: 750px;
    margin: auto;
    background: #fff;
    padding: 28px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,.10);
}

h1 { color: #1f3c88; margin-top: 0; }

label {
    display: block;
    font-weight: bold;
    margin-bottom: 6px;
}

select, input {
    width: 100%;
    padding: 12px;
    margin-top: 5px;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 15px;
}

input[readonly] { background: #eee; }

.section { margin-top: 18px; }

.booking-info {
    display: none;
    margin-top: 10px;
    padding: 14px;
    background: #eef4ff;
    border: 1px solid #cbdcff;
    border-radius: 7px;
    line-height: 1.8;
}

.calculation-box {
    background: #f7f9fc;
    border: 1px solid #ddd;
    padding: 18px;
    border-radius: 8px;
    margin-top: 20px;
}

.calculation-row {
    display: flex;
    justify-content: space-between;
    gap: 20px;
    padding: 8px 0;
}

.discount-row { color: #b42318; }

.total-row {
    font-size: 20px;
    font-weight: bold;
    border-top: 2px solid #333;
    margin-top: 8px;
    padding-top: 14px;
}

button {
    background: #1f3c88;
    color: #fff;
    border: 0;
    padding: 13px 22px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 16px;
    margin-top: 20px;
}

button:hover { background: #162d68; }

.success, .error {
    padding: 14px;
    border-radius: 6px;
    margin-bottom: 20px;
}

.success { color: #176b35; background: #e9f7ef; }
.error { color: #b00020; background: #fdeaea; }

.links { margin-top: 25px; }
.links a { margin-right: 15px; color: #1f3c88; text-decoration: none; }
.links a:hover { text-decoration: underline; }

small { color: #666; }
</style>
</head>

<body>
<div class="container">

<h1>🏨 Suva Hotel</h1>
<h2>💰 Create or Update Current Bill</h2>

<?php if ($message !== ""): ?>
    <div class="<?php echo $message_type === "success" ? "success" : "error"; ?>">
        <strong><?php echo e($message); ?></strong>
    </div>
<?php endif; ?>

<form method="POST" id="bill_form">

<div class="section">
<label for="booking_id">Booking:</label>

<select name="booking_id" id="booking_id" required>
<option value="">-- Select Booking --</option>

<?php while ($row = pg_fetch_assoc($result)): ?>
<option
    value="<?php echo (int)$row["id"]; ?>"
    data-room-charge="<?php echo e($row["total_amount"] ?? "0"); ?>"
    data-guest="<?php echo e($row["full_name"] ?? "Unknown Guest"); ?>"
    data-room="<?php echo e($row["room_number"] ?? "N/A"); ?>"
    data-checkin="<?php echo e($row["check_in"] ?? ""); ?>"
    data-checkout="<?php echo e($row["check_out"] ?? ""); ?>"
>
<?php
echo "Booking #" . (int)$row["id"]
    . " - " . ($row["full_name"] ?? "Unknown Guest")
    . " - Room " . ($row["room_number"] ?? "N/A")
    . " - " . ($row["check_in"] ?? "")
    . " to " . ($row["check_out"] ?? "")
    . " - Rs. " . number_format((float)($row["total_amount"] ?? 0), 2);
?>
</option>
<?php endwhile; ?>
</select>

<div id="booking_info" class="booking-info">
<strong>Selected Booking</strong><br>
👤 Guest: <span id="selected_guest">-</span><br>
🚪 Room: <span id="selected_room">-</span><br>
📅 Check-in: <span id="selected_checkin">-</span><br>
📅 Check-out: <span id="selected_checkout">-</span>
</div>
</div>

<div class="section">
<label for="room_charge">Room Charge:</label>
<input type="number" id="room_charge" value="0" step="0.01" readonly>
</div>

<div class="section">
<label for="food_charge">Food Charge:</label>
<input type="number" name="food_charge" id="food_charge" value="0" min="0" step="0.01" required>
</div>

<div class="section">
<label for="service_charge">Service Charge:</label>
<input type="number" name="service_charge" id="service_charge" value="0" min="0" step="0.01" required>
</div>

<div class="section">
<label for="discount">Discount (%):</label>
<input type="number" name="discount" id="discount" min="0" max="100" step="0.01" value="0" required>
<small>Enter a percentage, for example 10 for a 10% discount.</small>
</div>

<div class="calculation-box">
    <div class="calculation-row">
        <span>Gross Amount:</span>
        <strong>Rs. <span id="gross_display">0.00</span></strong>
    </div>
    <div class="calculation-row discount-row">
        <span>Discount (<span id="discount_percent_display">0.00</span>%):</span>
        <strong>- Rs. <span id="discount_amount_display">0.00</span></strong>
    </div>
    <div class="calculation-row">
        <span>Amount After Discount:</span>
        <strong>Rs. <span id="subtotal_display">0.00</span></strong>
    </div>
    <div class="calculation-row">
        <span>VAT 13%:</span>
        <strong>Rs. <span id="tax_display">0.00</span></strong>
    </div>
    <div class="calculation-row total-row">
        <span>Final Total:</span>
        <strong>Rs. <span id="total_display">0.00</span></strong>
    </div>
</div>

<button type="submit">💰 Save Current Bill</button>
</form>

<div class="links">
<a href="view.php">📄 View Bills</a>

<?php if (($_SESSION["role"] ?? "") === "admin"): ?>
<a href="../dashboard.php">← Admin Dashboard</a>
<?php else: ?>
<a href="../staff_dashboard/staff_dashboard.php">← Staff Dashboard</a>
<?php endif; ?>
</div>

</div>

<script>
function calculateBill() {
    const room = Number(document.getElementById("room_charge").value) || 0;
    const food = Number(document.getElementById("food_charge").value) || 0;
    const service = Number(document.getElementById("service_charge").value) || 0;
    const discountPercent = Math.min(
        100,
        Math.max(0, Number(document.getElementById("discount").value) || 0)
    );

    const gross = room + food + service;
    const discount = gross * discountPercent / 100;
    const subtotal = Math.max(0, gross - discount);
    const tax = subtotal * 0.13;
    const total = subtotal + tax;

    document.getElementById("gross_display").textContent = gross.toFixed(2);
    document.getElementById("discount_percent_display").textContent = discountPercent.toFixed(2);
    document.getElementById("discount_amount_display").textContent = discount.toFixed(2);
    document.getElementById("subtotal_display").textContent = subtotal.toFixed(2);
    document.getElementById("tax_display").textContent = tax.toFixed(2);
    document.getElementById("total_display").textContent = total.toFixed(2);
}

document.getElementById("booking_id").addEventListener("change", function () {
    const option = this.options[this.selectedIndex];
    const hasBooking = Boolean(this.value);

    document.getElementById("booking_info").style.display = hasBooking ? "block" : "none";
    document.getElementById("room_charge").value =
        hasBooking ? (option.getAttribute("data-room-charge") || "0") : "0";

    document.getElementById("selected_guest").textContent =
        option.getAttribute("data-guest") || "-";
    document.getElementById("selected_room").textContent =
        option.getAttribute("data-room") || "-";
    document.getElementById("selected_checkin").textContent =
        option.getAttribute("data-checkin") || "-";
    document.getElementById("selected_checkout").textContent =
        option.getAttribute("data-checkout") || "-";

    calculateBill();
});

["food_charge", "service_charge", "discount"].forEach(function (id) {
    document.getElementById(id).addEventListener("input", calculateBill);
});

document.getElementById("bill_form").addEventListener("submit", function (event) {
    const food = Number(document.getElementById("food_charge").value);
    const service = Number(document.getElementById("service_charge").value);
    const discount = Number(document.getElementById("discount").value);

    if (!Number.isFinite(food) || food < 0 ||
        !Number.isFinite(service) || service < 0 ||
        !Number.isFinite(discount) || discount < 0 || discount > 100) {
        event.preventDefault();
        alert("Please enter valid food, service, and discount values.");
    }
});

calculateBill();
</script>

</body>
</html>
