<?php
// ============================================================
// SUVA HOTEL - EDIT SWIMMING ENTRY
// PostgreSQL Version
// File: /suva_hotel/swimming/edit.php
// ============================================================

require_once __DIR__ . '/../config/database.php';

// ============================================================
// DATABASE CONNECTION CHECK
// ============================================================

if (!isset($pgConnection) || !$pgConnection) {
    die("Database connection failed.");
}

// ============================================================
// VALIDATE ID
// ============================================================

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid swimming record ID.");
}

$id = (int) $_GET['id'];

if ($id <= 0) {
    die("Invalid swimming record ID.");
}

$error = "";

// ============================================================
// GET CURRENT SWIMMING RECORD
// ============================================================

$get_sql = "
    SELECT *
    FROM swimming
    WHERE id = $1
    LIMIT 1
";

$get_result = pg_query_params(
    $pgConnection,
    $get_sql,
    [$id]
);

if (!$get_result) {
    die("Database Error: " . pg_last_error($pgConnection));
}

if (pg_num_rows($get_result) === 0) {
    die("Swimming record not found.");
}

$row = pg_fetch_assoc($get_result);

// ============================================================
// UPDATE RECORD
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $visitor_type = $_POST['visitor_type'] ?? 'Walk-in';

    $visitor_name = trim(
        $_POST['visitor_name'] ?? ''
    );

    $guest_id = !empty($_POST['guest_id'])
        ? (int) $_POST['guest_id']
        : null;

    $booking_id = !empty($_POST['booking_id'])
        ? (int) $_POST['booking_id']
        : null;

    $visit_date = $_POST['visit_date']
        ?? date('Y-m-d');

    $entry_time = !empty($_POST['entry_time'])
        ? $_POST['entry_time']
        : null;

    $exit_time = !empty($_POST['exit_time'])
        ? $_POST['exit_time']
        : null;

    $adults = max(
        0,
        (int) ($_POST['adults'] ?? 0)
    );

    $children = max(
        0,
        (int) ($_POST['children'] ?? 0)
    );

    $status = $_POST['status'] ?? 'Active';

    // ========================================================
    // SWIMMING RATES
    // ========================================================

    $adult_rate = 500;
    $child_rate = 300;

    $adult_charge = $adults * $adult_rate;
    $child_charge = $children * $child_rate;

    $total_amount = $adult_charge + $child_charge;

    // ========================================================
    // VALIDATION
    // ========================================================

    if (($adults + $children) <= 0) {

        $error = "At least one adult or child is required.";

    } elseif (!in_array(
        $visitor_type,
        ['Hotel Guest', 'Walk-in'],
        true
    )) {

        $error = "Invalid visitor type.";

    } elseif (!in_array(
        $status,
        ['Active', 'Completed', 'Cancelled'],
        true
    )) {

        $error = "Invalid status.";

    } elseif (
        $visitor_type === 'Hotel Guest'
        && empty($guest_id)
    ) {

        $error = "Please select a hotel guest.";

    } else {

        // ====================================================
        // IF HOTEL GUEST, VERIFY GUEST EXISTS
        // ====================================================

        if (!empty($guest_id)) {

            $guest_check_sql = "
                SELECT id
                FROM guests
                WHERE id = $1
                LIMIT 1
            ";

            $guest_check = pg_query_params(
                $pgConnection,
                $guest_check_sql,
                [$guest_id]
            );

            if (!$guest_check) {

                $error = "Database Error: " .
                    pg_last_error($pgConnection);

            } elseif (pg_num_rows($guest_check) === 0) {

                $error = "Selected hotel guest does not exist.";
            }
        }

        // ====================================================
        // VERIFY BOOKING IF PROVIDED
        // ====================================================

        if (
            empty($error)
            && !empty($booking_id)
        ) {

            $booking_check_sql = "
                SELECT id
                FROM bookings
                WHERE id = $1
                LIMIT 1
            ";

            $booking_check = pg_query_params(
                $pgConnection,
                $booking_check_sql,
                [$booking_id]
            );

            if (!$booking_check) {

                $error = "Database Error: " .
                    pg_last_error($pgConnection);

            } elseif (pg_num_rows($booking_check) === 0) {

                $error = "Selected booking does not exist.";
            }
        }

        // ====================================================
        // UPDATE
        // ====================================================

        if (empty($error)) {

            $update_sql = "
                UPDATE swimming
                SET
                    guest_id = $1,
                    booking_id = $2,
                    visitor_type = $3,
                    visitor_name = $4,
                    visit_date = $5,
                    entry_time = $6,
                    exit_time = $7,
                    adults = $8,
                    children = $9,
                    adult_charge = $10,
                    child_charge = $11,
                    total_amount = $12,
                    status = $13
                WHERE id = $14
            ";

            $update_result = pg_query_params(
                $pgConnection,
                $update_sql,
                [
                    $guest_id,
                    $booking_id,
                    $visitor_type,
                    $visitor_name,
                    $visit_date,
                    $entry_time,
                    $exit_time,
                    $adults,
                    $children,
                    $adult_charge,
                    $child_charge,
                    $total_amount,
                    $status,
                    $id
                ]
            );

            if ($update_result) {

                header(
                    "Location: view_one.php?id=" . $id
                );

                exit;

            } else {

                $error = "Database Error: " .
                    pg_last_error($pgConnection);
            }
        }
    }

    // ========================================================
    // KEEP FORM VALUES AFTER ERROR
    // ========================================================

    if (!empty($error)) {

        $row['visitor_type'] = $visitor_type;
        $row['visitor_name'] = $visitor_name;
        $row['guest_id'] = $guest_id;
        $row['booking_id'] = $booking_id;
        $row['visit_date'] = $visit_date;
        $row['entry_time'] = $entry_time;
        $row['exit_time'] = $exit_time;
        $row['adults'] = $adults;
        $row['children'] = $children;
        $row['status'] = $status;
    }
}

// ============================================================
// GET ALL GUESTS
// PostgreSQL guests table uses full_name
// ============================================================

$guests_sql = "
    SELECT id, full_name
    FROM guests
    ORDER BY full_name ASC
";

$guests_result = pg_query(
    $pgConnection,
    $guests_sql
);

if (!$guests_result) {
    die(
        "Unable to load guests: " .
        pg_last_error($pgConnection)
    );
}

// ============================================================
// ESCAPE HELPER
// ============================================================

function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
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

<title>Edit Swimming Entry</title>

<style>

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f4f7fb;
}

.container {
    max-width: 850px;
    margin: 35px auto;
    padding: 20px;
}

.card {
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 3px 15px rgba(0,0,0,.08);
}

h1 {
    color: #123b5d;
}

.grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 18px;
}

.full {
    grid-column: 1 / -1;
}

label {
    display: block;
    margin-bottom: 7px;
    font-weight: bold;
}

input,
select {
    width: 100%;
    padding: 11px;
    border: 1px solid #d1d5db;
    border-radius: 7px;
    font-size: 14px;
    box-sizing: border-box;
}

.rate {
    background: #eff6ff;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.total {
    background: #ecfdf5;
    padding: 18px;
    border-radius: 8px;
    margin-top: 20px;
    font-size: 20px;
    font-weight: bold;
}

.error {
    background: #fee2e2;
    color: #991b1b;
    padding: 12px;
    border-radius: 7px;
    margin-bottom: 15px;
}

.buttons {
    margin-top: 25px;
    display: flex;
    gap: 10px;
}

button,
.btn {
    border: 0;
    padding: 12px 18px;
    border-radius: 7px;
    cursor: pointer;
    text-decoration: none;
    font-weight: bold;
}

.save {
    background: #087f5b;
    color: white;
}

.back {
    background: #e5e7eb;
    color: #111827;
}

@media(max-width:600px) {

    .grid {
        grid-template-columns: 1fr;
    }

    .full {
        grid-column: auto;
    }

}

</style>

</head>

<body>

<div class="container">

<div class="card">

<h1>
🏊 Edit Swimming Entry #<?php echo e($id); ?>
</h1>

<?php if ($error): ?>

<div class="error">
    ❌ <?php echo e($error); ?>
</div>

<?php endif; ?>


<div class="rate">

<strong>Swimming Rates</strong>

<br><br>

Adult:
<strong>Rs. 500</strong>

&nbsp;&nbsp;&nbsp;

Child:
<strong>Rs. 300</strong>

</div>


<form method="POST">

<div class="grid">


<!-- VISITOR TYPE -->

<div>

<label>Visitor Type</label>

<select
    name="visitor_type"
    id="visitor_type"
    onchange="toggleGuest()"
>

<option
    value="Hotel Guest"
    <?php
    echo ($row['visitor_type'] === 'Hotel Guest')
        ? 'selected'
        : '';
    ?>
>
    🏨 Hotel Guest
</option>

<option
    value="Walk-in"
    <?php
    echo ($row['visitor_type'] === 'Walk-in')
        ? 'selected'
        : '';
    ?>
>
    🚶 Walk-in Visitor
</option>

</select>

</div>


<!-- VISITOR NAME -->

<div>

<label>Visitor Name</label>

<input
    type="text"
    name="visitor_name"
    value="<?php echo e($row['visitor_name'] ?? ''); ?>"
    placeholder="Enter visitor name"
>

</div>


<!-- HOTEL GUEST -->

<div id="guestBox">

<label>Hotel Guest</label>

<select name="guest_id">

<option value="">
    -- Select Guest --
</option>

<?php while ($guest = pg_fetch_assoc($guests_result)): ?>

<option
    value="<?php echo e($guest['id']); ?>"
    <?php
    echo (
        (int)($row['guest_id'] ?? 0)
        ===
        (int)$guest['id']
    )
        ? 'selected'
        : '';
    ?>
>

<?php echo e($guest['full_name']); ?>

</option>

<?php endwhile; ?>

</select>

</div>


<!-- BOOKING -->

<div>

<label>Booking ID</label>

<input
    type="number"
    name="booking_id"
    value="<?php echo e($row['booking_id'] ?? ''); ?>"
    placeholder="Optional"
    min="1"
>

</div>


<!-- VISIT DATE -->

<div>

<label>Visit Date</label>

<input
    type="date"
    name="visit_date"
    value="<?php echo e($row['visit_date'] ?? ''); ?>"
    required
>

</div>


<!-- ENTRY TIME -->

<div>

<label>Entry Time</label>

<input
    type="time"
    name="entry_time"
    value="<?php echo e($row['entry_time'] ?? ''); ?>"
>

</div>


<!-- EXIT TIME -->

<div>

<label>Exit Time</label>

<input
    type="time"
    name="exit_time"
    value="<?php echo e($row['exit_time'] ?? ''); ?>"
>

</div>


<!-- ADULTS -->

<div>

<label>Adults</label>

<input
    type="number"
    name="adults"
    id="adults"
    min="0"
    value="<?php echo e($row['adults'] ?? 0); ?>"
    oninput="calculateTotal()"
>

</div>


<!-- CHILDREN -->

<div>

<label>Children</label>

<input
    type="number"
    name="children"
    id="children"
    min="0"
    value="<?php echo e($row['children'] ?? 0); ?>"
    oninput="calculateTotal()"
>

</div>


<!-- STATUS -->

<div>

<label>Status</label>

<select name="status">

<option
    value="Active"
    <?php
    echo ($row['status'] === 'Active')
        ? 'selected'
        : '';
    ?>
>
    🟢 Active
</option>

<option
    value="Completed"
    <?php
    echo ($row['status'] === 'Completed')
        ? 'selected'
        : '';
    ?>
>
    🔵 Completed
</option>

<option
    value="Cancelled"
    <?php
    echo ($row['status'] === 'Cancelled')
        ? 'selected'
        : '';
    ?>
>
    🔴 Cancelled
</option>

</select>

</div>

</div>


<!-- TOTAL -->

<div class="total">

Total Swimming Charge:

Rs.

<span id="total">0.00</span>

</div>


<!-- BUTTONS -->

<div class="buttons">

<button
    type="submit"
    class="save"
>
    💾 Update Entry
</button>

<a
    href="view_one.php?id=<?php echo e($id); ?>"
    class="btn back"
>
    ← Cancel
</a>

</div>

</form>

</div>

</div>


<script>

function calculateTotal() {

    let adults =
        parseInt(
            document.getElementById("adults").value
        ) || 0;

    let children =
        parseInt(
            document.getElementById("children").value
        ) || 0;

    let total =
        (adults * 500) +
        (children * 300);

    document.getElementById("total").innerText =
        total.toFixed(2);
}


function toggleGuest() {

    let type =
        document.getElementById("visitor_type").value;

    let box =
        document.getElementById("guestBox");

    if (type === "Hotel Guest") {

        box.style.display = "block";

    } else {

        box.style.display = "none";

    }
}


calculateTotal();

toggleGuest();

</script>

</body>

</html>