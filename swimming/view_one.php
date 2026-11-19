<?php
// ============================================================
// SUVA HOTEL - VIEW ONE SWIMMING RECORD
// PostgreSQL Version
// File: /suva_hotel/swimming/view_one.php
// ============================================================

require_once __DIR__ . '/../config/database.php';

// ============================================================
// DATABASE CONNECTION CHECK
// ============================================================

if (!isset($pgConnection) || !$pgConnection) {
    die("Database connection failed.");
}

// ============================================================
// GET ID
// ============================================================

$id = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

if ($id <= 0) {
    die("Invalid swimming ID.");
}

// ============================================================
// GET SWIMMING RECORD
// ============================================================

$sql = "
    SELECT *
    FROM swimming
    WHERE id = $1
    LIMIT 1
";

$result = pg_query_params(
    $pgConnection,
    $sql,
    [$id]
);

if (!$result) {
    die(
        "Database Error: " .
        pg_last_error($pgConnection)
    );
}

$swimming = pg_fetch_assoc($result);

if (!$swimming) {
    die("Swimming record not found.");
}

// ============================================================
// VISITOR NAME
// ============================================================

$visitor_name = trim(
    $swimming['visitor_name'] ?? ''
);

if (
    empty($visitor_name)
    && ($swimming['visitor_type'] ?? '') === 'Hotel Guest'
    && !empty($swimming['guest_id'])
) {

    $guest_id = (int) $swimming['guest_id'];

    $guest_sql = "
        SELECT full_name
        FROM guests
        WHERE id = $1
        LIMIT 1
    ";

    $guest_result = pg_query_params(
        $pgConnection,
        $guest_sql,
        [$guest_id]
    );

    if ($guest_result) {

        $guest = pg_fetch_assoc($guest_result);

        if (
            $guest
            && !empty($guest['full_name'])
        ) {
            $visitor_name = $guest['full_name'];
        }
    }
}

// ============================================================
// FALLBACK NAME
// ============================================================

if (empty($visitor_name)) {
    $visitor_name = "Walk-in Visitor";
}

// ============================================================
// VALUES
// ============================================================

$adults = (int) (
    $swimming['adults'] ?? 0
);

$children = (int) (
    $swimming['children'] ?? 0
);

$adult_charge = (float) (
    $swimming['adult_charge'] ?? 0
);

$child_charge = (float) (
    $swimming['child_charge'] ?? 0
);

$total_amount = (float) (
    $swimming['total_amount'] ?? 0
);

$status = $swimming['status'] ?? 'Active';

$visitor_type = $swimming['visitor_type'] ?? 'Walk-in';

$bill_no = "SW-" .
    str_pad(
        $id,
        5,
        "0",
        STR_PAD_LEFT
    );

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

// ============================================================
// DATE FORMAT
// ============================================================

$visit_date = !empty($swimming['visit_date'])
    ? date(
        "d M Y",
        strtotime($swimming['visit_date'])
    )
    : "-";

// ============================================================
// ENTRY TIME
// ============================================================

$entry_time = !empty($swimming['entry_time'])
    ? date(
        "h:i A",
        strtotime($swimming['entry_time'])
    )
    : "-";

// ============================================================
// EXIT TIME
// ============================================================

$exit_time = !empty($swimming['exit_time'])
    ? date(
        "h:i A",
        strtotime($swimming['exit_time'])
    )
    : "Not Exited";

// ============================================================
// CREATED AT
// ============================================================

$created_at = !empty($swimming['created_at'])
    ? date(
        "d M Y h:i A",
        strtotime($swimming['created_at'])
    )
    : "-";

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
Swimming Record #<?= e($id) ?> - Suva Hotel
</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f1f5f9;
    color: #1e293b;
}

.container {
    width: 900px;
    max-width: 95%;
    margin: 35px auto;
}

.card {
    background: white;
    padding: 30px;
    border-radius: 14px;
    box-shadow: 0 5px 25px rgba(0,0,0,.10);
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 2px solid #0f766e;
    padding-bottom: 20px;
    margin-bottom: 25px;
}

.header h1 {
    margin: 0;
    color: #0f766e;
}

.header p {
    margin: 6px 0;
    color: #64748b;
}

.actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.btn {
    display: inline-block;
    padding: 11px 16px;
    border-radius: 7px;
    text-decoration: none;
    font-weight: bold;
    color: white;
}

.back {
    background: #475569;
}

.edit {
    background: #2563eb;
}

.bill {
    background: #0f766e;
}

.print {
    background: #7c3aed;
}

.delete {
    background: #dc2626;
}

.exit {
    background: #ea580c;
}

.status {
    display: inline-block;
    padding: 7px 15px;
    border-radius: 20px;
    font-weight: bold;
}

.completed {
    background: #dcfce7;
    color: #166534;
}

.active {
    background: #dbeafe;
    color: #1d4ed8;
}

.cancelled {
    background: #fee2e2;
    color: #991b1b;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.info-box {
    background: #f8fafc;
    padding: 17px;
    border-radius: 9px;
    border: 1px solid #e2e8f0;
}

.label {
    font-size: 13px;
    color: #64748b;
    margin-bottom: 7px;
}

.value {
    font-size: 17px;
    font-weight: bold;
}

.total-box {
    margin-top: 25px;
    padding: 25px;
    background: #f0fdfa;
    border: 2px solid #0f766e;
    border-radius: 12px;
    text-align: center;
}

.total-title {
    color: #64748b;
    font-size: 15px;
}

.total {
    color: #0f766e;
    font-size: 34px;
    font-weight: bold;
    margin-top: 7px;
}

.bill-section {
    margin-top: 25px;
    padding: 20px;
    border-radius: 10px;
    background: #f8fafc;
    text-align: center;
}

.bill-section h2 {
    margin-top: 0;
}

.bill-number {
    color: #0f766e;
    font-weight: bold;
}

@media(max-width:650px) {

    .header {
        flex-direction: column;
        align-items: flex-start;
        gap: 20px;
    }

    .info-grid {
        grid-template-columns: 1fr;
    }

}

</style>

</head>

<body>

<div class="container">

<div class="card">

<!-- ========================================================
     HEADER
========================================================= -->

<div class="header">

    <div>

        <h1>
            🏊 Swimming Record #<?= e($id) ?>
        </h1>

        <p>
            Suva Hotel Swimming Pool Management
        </p>

    </div>


    <div>

        <?php if ($status === "Completed"): ?>

            <span class="status completed">
                🟢 Completed
            </span>

        <?php elseif ($status === "Active"): ?>

            <span class="status active">
                🔵 Active
            </span>

        <?php else: ?>

            <span class="status cancelled">
                🔴 Cancelled
            </span>

        <?php endif; ?>

    </div>

</div>


<!-- ========================================================
     ACTION BUTTONS
========================================================= -->

<div class="actions">

    <a
        href="view.php"
        class="btn back"
    >
        ← Records
    </a>


    <a
        href="edit.php?id=<?= e($id) ?>"
        class="btn edit"
    >
        ✏️ Edit
    </a>


    <?php if ($status === "Active"): ?>

        <a
            href="exit.php?id=<?= e($id) ?>"
            class="btn exit"
            onclick="return confirm('Mark this swimmer as completed and record the exit time?');"
        >
            🚪 Exit / Complete
        </a>

    <?php endif; ?>


    <a
        href="bill.php?id=<?= e($id) ?>"
        class="btn bill"
    >
        🧾 Swimming Bill
    </a>


    <a
        href="print.php?id=<?= e($id) ?>"
        class="btn print"
    >
        🖨️ Print Bill
    </a>


    <a
        href="delete.php?id=<?= e($id) ?>"
        class="btn delete"
        onclick="return confirm('Are you sure you want to delete this swimming record?');"
    >
        🗑️ Delete Record
    </a>

</div>


<!-- ========================================================
     RECORD DETAILS
========================================================= -->

<div
    class="info-grid"
    style="margin-top:25px;"
>


<!-- VISITOR NAME -->

<div class="info-box">

    <div class="label">
        Visitor Name
    </div>

    <div class="value">
        <?= e($visitor_name) ?>
    </div>

</div>


<!-- VISITOR TYPE -->

<div class="info-box">

    <div class="label">
        Visitor Type
    </div>

    <div class="value">
        <?= e($visitor_type) ?>
    </div>

</div>


<!-- GUEST ID -->

<div class="info-box">

    <div class="label">
        Guest ID
    </div>

    <div class="value">

        <?php if (!empty($swimming['guest_id'])): ?>

            #<?= e($swimming['guest_id']) ?>

        <?php else: ?>

            -

        <?php endif; ?>

    </div>

</div>


<!-- BOOKING ID -->

<div class="info-box">

    <div class="label">
        Booking ID
    </div>

    <div class="value">

        <?php if (!empty($swimming['booking_id'])): ?>

            #<?= e($swimming['booking_id']) ?>

        <?php else: ?>

            -

        <?php endif; ?>

    </div>

</div>


<!-- VISIT DATE -->

<div class="info-box">

    <div class="label">
        Visit Date
    </div>

    <div class="value">
        <?= e($visit_date) ?>
    </div>

</div>


<!-- ENTRY TIME -->

<div class="info-box">

    <div class="label">
        Entry Time
    </div>

    <div class="value">
        <?= e($entry_time) ?>
    </div>

</div>


<!-- EXIT TIME -->

<div class="info-box">

    <div class="label">
        Exit Time
    </div>

    <div class="value">
        <?= e($exit_time) ?>
    </div>

</div>


<!-- ADULTS -->

<div class="info-box">

    <div class="label">
        Adults
    </div>

    <div class="value">
        <?= e($adults) ?>
    </div>

</div>


<!-- CHILDREN -->

<div class="info-box">

    <div class="label">
        Children
    </div>

    <div class="value">
        <?= e($children) ?>
    </div>

</div>


<!-- ADULT CHARGE -->

<div class="info-box">

    <div class="label">
        Adult Charge
    </div>

    <div class="value">
        Rs.
        <?= number_format(
            $adult_charge,
            2
        ) ?>
    </div>

</div>


<!-- CHILD CHARGE -->

<div class="info-box">

    <div class="label">
        Child Charge
    </div>

    <div class="value">
        Rs.
        <?= number_format(
            $child_charge,
            2
        ) ?>
    </div>

</div>


<!-- CREATED AT -->

<div class="info-box">

    <div class="label">
        Created At
    </div>

    <div class="value">
        <?= e($created_at) ?>
    </div>

</div>

</div>


<!-- ========================================================
     BILL INFORMATION
========================================================= -->

<div class="bill-section">

    <h2>
        🧾 Swimming Bill
    </h2>

    <p>

        Bill Number:

        <span class="bill-number">
            <?= e($bill_no) ?>
        </span>

    </p>


    <p>

        Visitor:

        <strong>
            <?= e($visitor_name) ?>
        </strong>

    </p>


    <p>

        <?= e($adults) ?> Adult(s)

        <?php if ($children > 0): ?>

            +

            <?= e($children) ?> Child(ren)

        <?php endif; ?>

    </p>


    <div
        class="actions"
        style="justify-content:center;"
    >

        <a
            href="bill.php?id=<?= e($id) ?>"
            class="btn bill"
        >
            👁️ View Swimming Bill
        </a>


        <a
            href="print.php?id=<?= e($id) ?>"
            class="btn print"
        >
            🖨️ Print Bill
        </a>

    </div>

</div>


<!-- ========================================================
     TOTAL
========================================================= -->

<div class="total-box">

    <div class="total-title">
        Total Swimming Charge
    </div>

    <div class="total">

        Rs.

        <?= number_format(
            $total_amount,
            2
        ) ?>

    </div>

</div>

</div>

</div>

</body>

</html>