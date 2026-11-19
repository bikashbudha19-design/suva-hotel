<?php
// ============================================================
// SUVA HOTEL - SWIMMING POOL ENTRY
// PostgreSQL Version
// File: /suva_hotel/swimming/add.php
// ============================================================

session_start();

// ============================================================
// DATABASE CONNECTION
// ============================================================

require_once __DIR__ . '/../config/database.php';

if (!isset($pgConnection) || !$pgConnection) {
    die("Database connection failed.");
}

// ============================================================
// MESSAGE VARIABLES
// ============================================================

$message = "";
$error = "";

// ============================================================
// SWIMMING RATES
// ============================================================

$ADULT_RATE = 500;
$CHILD_RATE = 300;

// ============================================================
// LOAD GUESTS
//
// guests table:
// id, full_name, phone, email, address, id_number
// ============================================================

$guests = [];

$guest_sql = "
    SELECT
        id,
        full_name,
        phone
    FROM guests
    ORDER BY full_name ASC
";

$guest_result = pg_query($pgConnection, $guest_sql);

if ($guest_result) {

    while ($row = pg_fetch_assoc($guest_result)) {
        $guests[] = $row;
    }

    pg_free_result($guest_result);
}

// ============================================================
// LOAD ACTIVE BOOKINGS
//
// bookings table:
// id, guest_id, room_id, check_in, check_out,
// adults, children, status, total_amount
// ============================================================

$bookings = [];

$booking_sql = "
    SELECT
        b.id,
        b.guest_id,
        b.check_in,
        b.check_out,
        b.status,
        g.full_name
    FROM bookings b
    INNER JOIN guests g
        ON b.guest_id = g.id
    WHERE b.status IN ('reserved', 'checked_in')
    ORDER BY b.id DESC
";

$booking_result = pg_query($pgConnection, $booking_sql);

if ($booking_result) {

    while ($row = pg_fetch_assoc($booking_result)) {
        $bookings[] = $row;
    }

    pg_free_result($booking_result);
}

// ============================================================
// DEFAULT VALUES
// ============================================================

$visitor_type = "Walk-in";

$guest_id = "";

$booking_id = "";

$visitor_name = "";

$visit_date = date("Y-m-d");

$entry_time = date("H:i");

$adults = 1;

$children = 0;

$payment_method = "Cash";

// ============================================================
// FORM SUBMISSION
// ============================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // --------------------------------------------------------
    // GET FORM VALUES
    // --------------------------------------------------------

    $visitor_type = $_POST["visitor_type"] ?? "Walk-in";

    $guest_id = !empty($_POST["guest_id"])
        ? (int) $_POST["guest_id"]
        : null;

    $booking_id = !empty($_POST["booking_id"])
        ? (int) $_POST["booking_id"]
        : null;

    $visitor_name = trim(
        $_POST["visitor_name"] ?? ""
    );

    $visit_date = $_POST["visit_date"]
        ?? date("Y-m-d");

    $entry_time = $_POST["entry_time"]
        ?? date("H:i");

    $adults = isset($_POST["adults"])
        ? max(0, (int) $_POST["adults"])
        : 0;

    $children = isset($_POST["children"])
        ? max(0, (int) $_POST["children"])
        : 0;

    $payment_method = $_POST["payment_method"]
        ?? "Cash";

    // --------------------------------------------------------
    // VALIDATE VISITOR TYPE
    // --------------------------------------------------------

    if (!in_array(
        $visitor_type,
        ["Hotel Guest", "Walk-in"],
        true
    )) {

        $visitor_type = "Walk-in";
    }

    // --------------------------------------------------------
    // VALIDATE NUMBER OF PEOPLE
    // --------------------------------------------------------

    if ($adults == 0 && $children == 0) {

        $error =
            "Please enter at least one adult or child.";
    }

    // ========================================================
    // HOTEL GUEST
    // ========================================================

    if (
        !$error &&
        $visitor_type === "Hotel Guest"
    ) {

        // ----------------------------------------------------
        // GUEST REQUIRED
        // ----------------------------------------------------

        if (!$guest_id) {

            $error =
                "Please select a hotel guest.";
        }

        // ----------------------------------------------------
        // VALIDATE BOOKING
        // Booking is optional.
        // If selected, it must belong to guest.
        // ----------------------------------------------------

        if (
            !$error &&
            $booking_id
        ) {

            $check_sql = "
                SELECT id
                FROM bookings
                WHERE id = $1
                AND guest_id = $2
                LIMIT 1
            ";

            $check_params = [
                $booking_id,
                $guest_id
            ];

            $check_result = pg_query_params(
                $pgConnection,
                $check_sql,
                $check_params
            );

            if (!$check_result) {

                $error =
                    "Booking validation failed: "
                    . pg_last_error($pgConnection);

            } else {

                if (pg_num_rows($check_result) === 0) {

                    $error =
                        "Selected booking does not belong to this guest.";

                    $booking_id = null;
                }

                pg_free_result($check_result);
            }
        }

        // ----------------------------------------------------
        // GET GUEST NAME AUTOMATICALLY
        // ----------------------------------------------------

        if (
            !$error &&
            $guest_id
        ) {

            $name_sql = "
                SELECT full_name
                FROM guests
                WHERE id = $1
                LIMIT 1
            ";

            $name_result = pg_query_params(
                $pgConnection,
                $name_sql,
                [$guest_id]
            );

            if (!$name_result) {

                $error =
                    "Unable to load guest name: "
                    . pg_last_error($pgConnection);

            } else {

                if (pg_num_rows($name_result) > 0) {

                    $guest_row =
                        pg_fetch_assoc($name_result);

                    $visitor_name =
                        $guest_row["full_name"];
                }

                pg_free_result($name_result);
            }
        }
    }

    // ========================================================
    // WALK-IN VISITOR
    // ========================================================

    if (
        !$error &&
        $visitor_type === "Walk-in"
    ) {

        $guest_id = null;

        $booking_id = null;

        if ($visitor_name === "") {

            $error =
                "Please enter the walk-in visitor name.";
        }
    }

    // ========================================================
    // VALIDATE PAYMENT METHOD
    // ========================================================

    $allowed_payment_methods = [
        "Cash",
        "QR",
        "Card",
        "Room Bill"
    ];

    if (
        !in_array(
            $payment_method,
            $allowed_payment_methods,
            true
        )
    ) {

        $payment_method = "Cash";
    }

    // ========================================================
    // CALCULATE CHARGES
    // ========================================================

    $adult_charge =
        $adults * $ADULT_RATE;

    $child_charge =
        $children * $CHILD_RATE;

    $total_amount =
        $adult_charge + $child_charge;

    // ========================================================
    // SAVE SWIMMING RECORD
    // ========================================================

    if (!$error) {

        /*
         * PostgreSQL parameter placeholders:
         *
         * $1  = guest_id
         * $2  = booking_id
         * $3  = visitor_type
         * $4  = visitor_name
         * $5  = visit_date
         * $6  = entry_time
         * $7  = adults
         * $8  = children
         * $9  = adult_charge
         * $10 = child_charge
         * $11 = total_amount
         */

        $insert_sql = "
            INSERT INTO swimming
            (
                guest_id,
                booking_id,
                visitor_type,
                visitor_name,
                visit_date,
                entry_time,
                exit_time,
                adults,
                children,
                adult_charge,
                child_charge,
                total_amount,
                status
            )
            VALUES
            (
                $1,
                $2,
                $3,
                $4,
                $5,
                $6,
                NULL,
                $7,
                $8,
                $9,
                $10,
                $11,
                'Active'
            )
            RETURNING id
        ";

        $insert_params = [
            $guest_id,
            $booking_id,
            $visitor_type,
            $visitor_name,
            $visit_date,
            $entry_time,
            $adults,
            $children,
            $adult_charge,
            $child_charge,
            $total_amount
        ];

        $result = pg_query_params(
            $pgConnection,
            $insert_sql,
            $insert_params
        );

        // ----------------------------------------------------
        // INSERT SUCCESS
        // ----------------------------------------------------

        if ($result) {

            $row = pg_fetch_assoc($result);

            $new_id = (int) $row["id"];

            pg_free_result($result);

            /*
             * Redirect to the newly created swimming record.
             */

            header(
                "Location: view_one.php?id=" . $new_id
            );

            exit;

        } else {

            $error =
                "Database Error: "
                . pg_last_error($pgConnection);
        }
    }
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

<title>Swimming Pool Entry - Suva Hotel</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    background: linear-gradient(
        135deg,
        #e0f7fa,
        #ffffff
    );
    color: #263238;
}

.container {
    max-width: 1000px;
    margin: 40px auto;
    padding: 20px;
}

.card {
    background: white;
    border-radius: 18px;
    padding: 35px;
    box-shadow: 0 10px 35px rgba(0,0,0,0.10);
}

.header {
    text-align: center;
    margin-bottom: 30px;
}

.header .icon {
    font-size: 55px;
}

.header h1 {
    margin: 10px 0 5px;
    color: #0277bd;
}

.header p {
    margin: 0;
    color: #607d8b;
}

.rate-box {
    display: flex;
    justify-content: center;
    gap: 20px;
    margin: 25px 0;
    flex-wrap: wrap;
}

.rate {
    background: #e1f5fe;
    border-radius: 12px;
    padding: 15px 30px;
    text-align: center;
    min-width: 180px;
}

.rate strong {
    display: block;
    font-size: 20px;
    color: #0277bd;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.full {
    grid-column: 1 / -1;
}

label {
    font-weight: bold;
    margin-bottom: 8px;
    color: #37474f;
}

input,
select {
    width: 100%;
    padding: 13px;
    border: 1px solid #cfd8dc;
    border-radius: 9px;
    font-size: 15px;
    background: #fff;
}

input:focus,
select:focus {
    outline: none;
    border-color: #0288d1;
    box-shadow:
        0 0 0 3px rgba(2,136,209,0.10);
}

.info {
    margin-top: 7px;
    color: #78909c;
    font-size: 13px;
}

.visitor-type {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
}

.type-option {
    flex: 1;
    min-width: 220px;
}

.type-option input {
    display: none;
}

.type-option label {
    display: block;
    padding: 18px;
    border: 2px solid #cfd8dc;
    border-radius: 12px;
    text-align: center;
    cursor: pointer;
    transition: 0.2s;
}

.type-option input:checked + label {
    border-color: #0288d1;
    background: #e1f5fe;
    color: #0277bd;
}

.people-box {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.total-box {
    margin-top: 30px;
    padding: 25px;
    background: linear-gradient(
        135deg,
        #e0f7fa,
        #f1f8e9
    );
    border-radius: 15px;
    text-align: center;
}

.total-title {
    color: #546e7a;
    font-size: 16px;
}

.total-amount {
    font-size: 36px;
    font-weight: bold;
    color: #00796b;
    margin-top: 8px;
}

.charge-details {
    display: flex;
    justify-content: center;
    gap: 30px;
    margin-top: 12px;
    flex-wrap: wrap;
    color: #546e7a;
}

.buttons {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    margin-top: 30px;
}

.btn {
    border: none;
    padding: 14px 25px;
    border-radius: 10px;
    text-decoration: none;
    font-size: 15px;
    cursor: pointer;
    font-weight: bold;
    display: inline-block;
}

.btn-primary {
    background: #0288d1;
    color: white;
}

.btn-primary:hover {
    background: #0277bd;
}

.btn-secondary {
    background: #eceff1;
    color: #37474f;
}

.btn-secondary:hover {
    background: #cfd8dc;
}

.error {
    background: #ffebee;
    color: #c62828;
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 20px;
    border-left: 5px solid #e53935;
}

.note {
    background: #fff8e1;
    color: #795548;
    padding: 14px;
    border-radius: 10px;
    margin-top: 20px;
    font-size: 14px;
}

.hidden {
    display: none !important;
}

@media (max-width: 700px) {

    .form-grid {
        grid-template-columns: 1fr;
    }

    .full {
        grid-column: auto;
    }

    .people-box {
        grid-template-columns: 1fr;
    }

    .buttons {
        flex-direction: column;
    }

    .btn {
        text-align: center;
        width: 100%;
    }

    .card {
        padding: 20px;
    }
}

</style>

</head>

<body>

<div class="container">

<div class="card">

<div class="header">

<div class="icon">🏊</div>

<h1>Swimming Pool Entry</h1>

<p>
Suva Hotel Swimming Pool Management
</p>

</div>

<!-- =====================================================
     ERROR
     ===================================================== -->

<?php if ($error): ?>

<div class="error">
    ❌ <?= htmlspecialchars($error) ?>
</div>

<?php endif; ?>


<!-- =====================================================
     RATES
     ===================================================== -->

<div class="rate-box">

<div class="rate">

<strong>Rs. 500.00</strong>

Adult Rate

</div>

<div class="rate">

<strong>Rs. 300.00</strong>

Child Rate

</div>

</div>


<form
    method="POST"
    action=""
    id="swimmingForm"
>


<div class="form-grid">


<!-- =====================================================
     VISITOR TYPE
     ===================================================== -->

<div class="form-group full">

<label>
    Visitor Type
</label>

<div class="visitor-type">


<div class="type-option">

<input
    type="radio"
    name="visitor_type"
    id="hotel_guest"
    value="Hotel Guest"
    <?= $visitor_type === "Hotel Guest"
        ? "checked"
        : "" ?>
>

<label for="hotel_guest">
    🏨 Hotel Guest
</label>

</div>


<div class="type-option">

<input
    type="radio"
    name="visitor_type"
    id="walk_in"
    value="Walk-in"
    <?= $visitor_type === "Walk-in"
        ? "checked"
        : "" ?>
>

<label for="walk_in">
    🚶 Walk-in Visitor
</label>

</div>


</div>

</div>


<!-- =====================================================
     WALK-IN NAME
     ===================================================== -->

<div
    class="form-group full"
    id="walkin_name_group"
>

<label for="visitor_name">
    👤 Visitor Name
</label>

<input
    type="text"
    name="visitor_name"
    id="visitor_name"
    value="<?= htmlspecialchars($visitor_name) ?>"
    placeholder="Enter visitor name"
>

<div class="info">
    Required for walk-in visitors.
</div>

</div>


<!-- =====================================================
     HOTEL GUEST
     ===================================================== -->

<div
    class="form-group full"
    id="guest_group"
>

<label for="guest_id">
    🏨 Select Hotel Guest
</label>

<select
    name="guest_id"
    id="guest_id"
>

<option value="">
    -- Select Guest --
</option>

<?php foreach ($guests as $guest): ?>

<option
    value="<?= (int)$guest["id"] ?>"
    <?= (string)$guest_id ===
        (string)$guest["id"]
        ? "selected"
        : "" ?>
>

<?= htmlspecialchars($guest["full_name"]) ?>

<?php if (!empty($guest["phone"])): ?>

 - <?= htmlspecialchars($guest["phone"]) ?>

<?php endif; ?>

</option>

<?php endforeach; ?>

</select>

<div class="info">
    Select the hotel guest who is using
    the swimming pool.
</div>

</div>


<!-- =====================================================
     BOOKING
     ===================================================== -->

<div
    class="form-group full"
    id="booking_group"
>

<label for="booking_id">
    📋 Booking
</label>

<select
    name="booking_id"
    id="booking_id"
>

<option value="">
    -- No Booking / Optional --
</option>

<?php foreach ($bookings as $booking): ?>

<option
    value="<?= (int)$booking["id"] ?>"
    data-guest="<?= (int)$booking["guest_id"] ?>"
    <?= (string)$booking_id ===
        (string)$booking["id"]
        ? "selected"
        : "" ?>
>

Booking #<?= (int)$booking["id"] ?>

-
<?= htmlspecialchars($booking["full_name"]) ?>

-
<?= htmlspecialchars($booking["check_in"]) ?>

to

<?= htmlspecialchars($booking["check_out"]) ?>

[<?= htmlspecialchars($booking["status"]) ?>]

</option>

<?php endforeach; ?>

</select>

<div class="info">
    Select the guest's active booking if available.
</div>

</div>


<!-- =====================================================
     DATE
     ===================================================== -->

<div class="form-group">

<label for="visit_date">
    📅 Visit Date
</label>

<input
    type="date"
    name="visit_date"
    id="visit_date"
    value="<?= htmlspecialchars($visit_date) ?>"
    required
>

</div>


<!-- =====================================================
     ENTRY TIME
     ===================================================== -->

<div class="form-group">

<label for="entry_time">
    🕐 Entry Time
</label>

<input
    type="time"
    name="entry_time"
    id="entry_time"
    value="<?= htmlspecialchars($entry_time) ?>"
    required
>

</div>


<!-- =====================================================
     PEOPLE
     ===================================================== -->

<div class="form-group full">

<label>
    Number of People
</label>

<div class="people-box">


<div>

<label for="adults">
    👨 Number of Adults
</label>

<input
    type="number"
    name="adults"
    id="adults"
    min="0"
    value="<?= (int)$adults ?>"
>

</div>


<div>

<label for="children">
    👶 Number of Children
</label>

<input
    type="number"
    name="children"
    id="children"
    min="0"
    value="<?= (int)$children ?>"
>

</div>


</div>

</div>


<!-- =====================================================
     PAYMENT METHOD
     ===================================================== -->

<div class="form-group full">

<label for="payment_method">
    💳 Payment Method
</label>

<select
    name="payment_method"
    id="payment_method"
>

<option
    value="Cash"
    <?= $payment_method === "Cash"
        ? "selected"
        : "" ?>
>
    💵 Cash
</option>

<option
    value="QR"
    <?= $payment_method === "QR"
        ? "selected"
        : "" ?>
>
    📱 QR
</option>

<option
    value="Card"
    <?= $payment_method === "Card"
        ? "selected"
        : "" ?>
>
    💳 Card
</option>

<option
    value="Room Bill"
    <?= $payment_method === "Room Bill"
        ? "selected"
        : "" ?>
>
    🏨 Room Bill
</option>

</select>

</div>


</div>


<!-- =====================================================
     TOTAL
     ===================================================== -->

<div class="total-box">

<div class="total-title">
    Total Swimming Charge
</div>

<div class="total-amount">

Rs.
<span id="total_amount">
    500.00
</span>

</div>

<div class="charge-details">

<span>
    Adults:
    Rs.
    <span id="adult_charge">
        500.00
    </span>
</span>

<span>
    Children:
    Rs.
    <span id="child_charge">
        0.00
    </span>
</span>

</div>

</div>


<div class="note">

💡 <strong>Note:</strong>

For Hotel Guests, select the guest and optionally
select their active booking. For Walk-in Visitors,
enter their name.

</div>


<!-- =====================================================
     BUTTONS
     ===================================================== -->

<div class="buttons">

<a
    href="view.php"
    class="btn btn-secondary"
>
    ← Swimming Records
</a>

<button
    type="submit"
    class="btn btn-primary"
>
    🏊 Register Swimming Entry
</button>

</div>


</form>

</div>

</div>


<script>

/* =========================================================
   ELEMENTS
   ========================================================= */

const hotelGuestRadio =
    document.getElementById("hotel_guest");

const walkInRadio =
    document.getElementById("walk_in");

const guestGroup =
    document.getElementById("guest_group");

const bookingGroup =
    document.getElementById("booking_group");

const walkinNameGroup =
    document.getElementById("walkin_name_group");

const guestSelect =
    document.getElementById("guest_id");

const bookingSelect =
    document.getElementById("booking_id");

const visitorName =
    document.getElementById("visitor_name");

const adultsInput =
    document.getElementById("adults");

const childrenInput =
    document.getElementById("children");

const adultChargeDisplay =
    document.getElementById("adult_charge");

const childChargeDisplay =
    document.getElementById("child_charge");

const totalAmountDisplay =
    document.getElementById("total_amount");


/* =========================================================
   SHOW / HIDE FIELDS
   ========================================================= */

function updateVisitorType() {

    if (hotelGuestRadio.checked) {

        guestGroup.classList.remove("hidden");

        bookingGroup.classList.remove("hidden");

        walkinNameGroup.classList.add("hidden");

        visitorName.removeAttribute("required");

    } else {

        guestGroup.classList.add("hidden");

        bookingGroup.classList.add("hidden");

        walkinNameGroup.classList.remove("hidden");

        visitorName.setAttribute(
            "required",
            "required"
        );

        guestSelect.value = "";

        bookingSelect.value = "";
    }

    filterBookings();
}


/* =========================================================
   FILTER BOOKINGS BY SELECTED GUEST
   ========================================================= */

function filterBookings() {

    const selectedGuest =
        guestSelect.value;

    const options =
        bookingSelect.querySelectorAll("option");

    options.forEach(function(option) {

        if (option.value === "") {

            option.style.display = "";

            return;
        }

        const bookingGuest =
            option.getAttribute("data-guest");

        if (
            selectedGuest !== "" &&
            bookingGuest === selectedGuest
        ) {

            option.style.display = "";

        } else {

            option.style.display = "none";

            if (option.selected) {

                bookingSelect.value = "";
            }
        }

    });
}


/* =========================================================
   CALCULATE CHARGES
   ========================================================= */

function calculateCharges() {

    let adults =
        parseInt(adultsInput.value) || 0;

    let children =
        parseInt(childrenInput.value) || 0;

    if (adults < 0) {

        adults = 0;

        adultsInput.value = 0;
    }

    if (children < 0) {

        children = 0;

        childrenInput.value = 0;
    }

    const adultCharge =
        adults * 500;

    const childCharge =
        children * 300;

    const total =
        adultCharge + childCharge;

    adultChargeDisplay.textContent =
        adultCharge.toFixed(2);

    childChargeDisplay.textContent =
        childCharge.toFixed(2);

    totalAmountDisplay.textContent =
        total.toFixed(2);
}


/* =========================================================
   EVENTS
   ========================================================= */

hotelGuestRadio.addEventListener(
    "change",
    updateVisitorType
);

walkInRadio.addEventListener(
    "change",
    updateVisitorType
);

guestSelect.addEventListener(
    "change",
    filterBookings
);

adultsInput.addEventListener(
    "input",
    calculateCharges
);

childrenInput.addEventListener(
    "input",
    calculateCharges
);


/* =========================================================
   FORM VALIDATION
   ========================================================= */

document
    .getElementById("swimmingForm")
    .addEventListener(
        "submit",
        function(event) {

            const adults =
                parseInt(adultsInput.value) || 0;

            const children =
                parseInt(childrenInput.value) || 0;

            if (
                adults === 0 &&
                children === 0
            ) {

                alert(
                    "Please enter at least one adult or child."
                );

                event.preventDefault();

                return;
            }

            if (hotelGuestRadio.checked) {

                if (guestSelect.value === "") {

                    alert(
                        "Please select a hotel guest."
                    );

                    guestSelect.focus();

                    event.preventDefault();

                    return;
                }

            } else {

                if (
                    visitorName.value.trim() === ""
                ) {

                    alert(
                        "Please enter the walk-in visitor name."
                    );

                    visitorName.focus();

                    event.preventDefault();

                    return;
                }
            }

        }
    );


/* =========================================================
   INITIALIZE
   ========================================================= */

updateVisitorType();

calculateCharges();

</script>

</body>

</html>