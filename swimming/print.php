<?php
// ============================================================
// SUVA HOTEL - SWIMMING BILL PRINT
// PostgreSQL Version
// File: /suva_hotel/swimming/print.php
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
    && $swimming['visitor_type'] === 'Hotel Guest'
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
// FALLBACK VISITOR NAME
// ============================================================

if (empty($visitor_name)) {
    $visitor_name = "Walk-in Visitor";
}

// ============================================================
// SWIMMING DETAILS
// ============================================================

$adults = (int) (
    $swimming['adults'] ?? 0
);

$children = (int) (
    $swimming['children'] ?? 0
);

// These are TOTAL charges stored in the database.
$adult_charge = (float) (
    $swimming['adult_charge'] ?? 0
);

$child_charge = (float) (
    $swimming['child_charge'] ?? 0
);

$total_amount = (float) (
    $swimming['total_amount'] ?? 0
);

// ============================================================
// PER-PERSON RATES
// ============================================================

$adult_rate = 500;
$child_rate = 300;

// ============================================================
// BILL NUMBER
// ============================================================

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
// FORMAT DATE
// ============================================================

$visit_date = !empty($swimming['visit_date'])
    ? date(
        "d M Y",
        strtotime($swimming['visit_date'])
    )
    : "N/A";

// ============================================================
// FORMAT ENTRY TIME
// ============================================================

$entry_time = !empty($swimming['entry_time'])
    ? date(
        "h:i A",
        strtotime($swimming['entry_time'])
    )
    : "Not Recorded";

// ============================================================
// FORMAT EXIT TIME
// ============================================================

$exit_time = !empty($swimming['exit_time'])
    ? date(
        "h:i A",
        strtotime($swimming['exit_time'])
    )
    : "Not Exited";

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
Swimming Bill <?= e($bill_no) ?>
</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #f1f5f9;
    font-family: Arial, sans-serif;
    color: #1e293b;
}

.container {
    width: 800px;
    max-width: 95%;
    margin: 30px auto;
}

.bill {
    background: white;
    padding: 35px;
    border-radius: 12px;
    box-shadow: 0 5px 25px rgba(0,0,0,.12);
}

.header {
    display: flex;
    justify-content: space-between;
    border-bottom: 2px solid #0f766e;
    padding-bottom: 20px;
}

.hotel h1 {
    margin: 0;
    color: #0f766e;
}

.hotel p {
    margin: 5px 0;
    color: #64748b;
}

.bill-title {
    text-align: right;
}

.bill-title h2 {
    margin: 0;
}

.info {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
    margin: 25px 0;
}

.info-box {
    background: #f8fafc;
    padding: 15px;
    border-radius: 8px;
}

.info-box strong {
    display: block;
    color: #64748b;
    font-size: 13px;
    margin-bottom: 5px;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

th {
    background: #0f766e;
    color: white;
    padding: 12px;
    text-align: left;
}

td {
    padding: 12px;
    border-bottom: 1px solid #e2e8f0;
}

.right {
    text-align: right;
}

.total {
    margin-top: 25px;
    display: flex;
    justify-content: flex-end;
}

.total-box {
    width: 300px;
    padding: 18px;
    background: #f0fdfa;
    border: 2px solid #0f766e;
    border-radius: 10px;
}

.total-label {
    color: #64748b;
}

.total-amount {
    font-size: 27px;
    font-weight: bold;
    color: #0f766e;
    margin-top: 5px;
}

.footer {
    margin-top: 35px;
    padding-top: 20px;
    border-top: 1px solid #ddd;
    text-align: center;
    color: #64748b;
}

.buttons {
    text-align: center;
    margin-top: 20px;
}

.btn {
    display: inline-block;
    padding: 12px 20px;
    margin: 5px;
    border-radius: 7px;
    text-decoration: none;
    color: white;
    font-weight: bold;
    border: none;
    cursor: pointer;
}

.print {
    background: #0f766e;
}

.back {
    background: #475569;
}

@media print {

    body {
        background: white;
    }

    .container {
        width: 100%;
        max-width: 100%;
        margin: 0;
    }

    .bill {
        box-shadow: none;
        border-radius: 0;
    }

    .buttons {
        display: none;
    }

    @page {
        size: A4;
        margin: 15mm;
    }
}

@media(max-width:600px) {

    .header {
        flex-direction: column;
        gap: 20px;
    }

    .bill-title {
        text-align: left;
    }

    .info {
        grid-template-columns: 1fr;
    }

}

</style>

</head>

<body>

<div class="container">

<div class="bill">

<!-- ========================================================
     HEADER
========================================================= -->

<div class="header">

    <div class="hotel">

        <h1>🏨 SUVA HOTEL</h1>

        <p>Management System</p>

        <p>🏊 Swimming Pool</p>

    </div>


    <div class="bill-title">

        <h2>SWIMMING BILL</h2>

        <p>
            <strong>
                <?= e($bill_no) ?>
            </strong>
        </p>

        <p>
            <?= e($visit_date) ?>
        </p>

    </div>

</div>


<!-- ========================================================
     VISITOR INFORMATION
========================================================= -->

<div class="info">

    <div class="info-box">

        <strong>Visitor Name</strong>

        <?= e($visitor_name) ?>

    </div>


    <div class="info-box">

        <strong>Visitor Type</strong>

        <?= e(
            $swimming['visitor_type']
            ?? 'N/A'
        ) ?>

    </div>


    <div class="info-box">

        <strong>Entry Time</strong>

        <?= e($entry_time) ?>

    </div>


    <div class="info-box">

        <strong>Exit Time</strong>

        <?= e($exit_time) ?>

    </div>


    <div class="info-box">

        <strong>Adults</strong>

        <?= e($adults) ?>

    </div>


    <div class="info-box">

        <strong>Children</strong>

        <?= e($children) ?>

    </div>

</div>


<!-- ========================================================
     BILL TABLE
========================================================= -->

<table>

<thead>

<tr>

    <th>Description</th>

    <th>Quantity</th>

    <th>Rate</th>

    <th class="right">
        Amount
    </th>

</tr>

</thead>

<tbody>


<?php if ($adults > 0): ?>

<tr>

    <td>
        Adult Swimming
    </td>

    <td>
        <?= e($adults) ?>
    </td>

    <td>
        Rs.
        <?= number_format(
            $adult_rate,
            2
        ) ?>
    </td>

    <td class="right">

        Rs.
        <?= number_format(
            $adult_charge,
            2
        ) ?>

    </td>

</tr>

<?php endif; ?>


<?php if ($children > 0): ?>

<tr>

    <td>
        Child Swimming
    </td>

    <td>
        <?= e($children) ?>
    </td>

    <td>
        Rs.
        <?= number_format(
            $child_rate,
            2
        ) ?>
    </td>

    <td class="right">

        Rs.
        <?= number_format(
            $child_charge,
            2
        ) ?>

    </td>

</tr>

<?php endif; ?>


<?php if ($adults <= 0 && $children <= 0): ?>

<tr>

    <td colspan="4" style="text-align:center;">
        No swimming charges recorded.
    </td>

</tr>

<?php endif; ?>

</tbody>

</table>


<!-- ========================================================
     TOTAL
========================================================= -->

<div class="total">

    <div class="total-box">

        <div class="total-label">
            Total Swimming Charge
        </div>

        <div class="total-amount">

            Rs.
            <?= number_format(
                $total_amount,
                2
            ) ?>

        </div>

    </div>

</div>


<!-- ========================================================
     FOOTER
========================================================= -->

<div class="footer">

    <strong>
        Thank you for using Suva Hotel Swimming Pool!
    </strong>

    <br><br>

    Please keep this bill for your records.

</div>

</div>


<!-- ========================================================
     BUTTONS
========================================================= -->

<div class="buttons">

    <a
        href="print_bill.php?id=<?= e($id) ?>"
        class="btn print"
    >
        🖨️ Print Bill
    </a>


    <a
        href="view_one.php?id=<?= e($id) ?>"
        class="btn back"
    >
        ← Back
    </a>

</div>

</div>

</body>

</html>