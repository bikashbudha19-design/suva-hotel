<?php
// ============================================================
// SUVA HOTEL - SWIMMING BILL
// PostgreSQL Version
// File: /suva_hotel/swimming/bill.php
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
// GET ID
// ============================================================

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    die("Invalid swimming entry ID.");
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

pg_free_result($result);

if (!$swimming) {
    die("Swimming record not found.");
}

// ============================================================
// GUEST NAME
// ============================================================

$guest_name = $swimming['visitor_name'] ?? '';

if (
    ($swimming['visitor_type'] ?? '') === 'Hotel Guest' &&
    !empty($swimming['guest_id'])
) {

    $guest_id = (int) $swimming['guest_id'];

    /*
     * Your actual guests table uses:
     *
     * id
     * full_name
     * phone
     * email
     * address
     * id_number
     */

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

        if ($guest && !empty($guest['full_name'])) {
            $guest_name = $guest['full_name'];
        }

        pg_free_result($guest_result);
    }
}

// ============================================================
// FALLBACK VISITOR NAME
// ============================================================

if (empty(trim($guest_name))) {
    $guest_name = "Walk-in Visitor";
}

// ============================================================
// VALUES
// ============================================================

$adults = (int) ($swimming['adults'] ?? 0);

$children = (int) ($swimming['children'] ?? 0);

$adult_charge = (float) ($swimming['adult_charge'] ?? 0);

$child_charge = (float) ($swimming['child_charge'] ?? 0);

$total_amount = (float) ($swimming['total_amount'] ?? 0);

$visit_date = $swimming['visit_date'] ?? '';

$entry_time = $swimming['entry_time'] ?? '';

$exit_time = $swimming['exit_time'] ?? '';

$status = $swimming['status'] ?? '';

$visitor_type = $swimming['visitor_type'] ?? '';

$booking_id = $swimming['booking_id'] ?? null;

// ============================================================
// BILL NUMBER
// ============================================================

$bill_no =
    "SW-" .
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
    Swimming Bill - <?= e($bill_no) ?>
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
    width: 800px;
    max-width: 95%;
    margin: 40px auto;
}

.bill {
    background: white;
    padding: 35px;
    border-radius: 12px;
    box-shadow:
        0 5px 25px rgba(0,0,0,0.10);
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 2px solid #0f766e;
    padding-bottom: 20px;
}

.hotel h1 {
    margin: 0;
    color: #0f766e;
    font-size: 30px;
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
    color: #0f172a;
}

.bill-title p {
    margin: 5px 0;
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
    margin-top: 20px;
    display: flex;
    justify-content: flex-end;
}

.total-box {
    width: 300px;
    background: #f0fdfa;
    border: 2px solid #0f766e;
    padding: 18px;
    border-radius: 10px;
}

.total-box .label {
    color: #64748b;
}

.total-box .amount {
    font-size: 28px;
    font-weight: bold;
    color: #0f766e;
    margin-top: 5px;
}

.status {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 20px;
    background: #dcfce7;
    color: #166534;
    font-weight: bold;
}

.footer {
    margin-top: 35px;
    text-align: center;
    color: #64748b;
    border-top: 1px solid #e2e8f0;
    padding-top: 20px;
}

.buttons {
    text-align: center;
    margin: 25px 0;
}

.btn {
    display: inline-block;
    padding: 11px 18px;
    margin: 5px;
    text-decoration: none;
    border-radius: 7px;
    color: white;
    font-weight: bold;
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

@media (max-width: 700px) {

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

    .bill {
        padding: 20px;
    }

    table {
        font-size: 13px;
    }

    th,
    td {
        padding: 8px;
    }

}

</style>

</head>

<body>

<div class="container">

<div class="bill">

<!-- =====================================================
     HEADER
     ===================================================== -->

<div class="header">

<div class="hotel">

<h1>🏨 SUVA HOTEL</h1>

<p>Management System</p>

<p>Swimming Pool</p>

</div>

<div class="bill-title">

<h2>SWIMMING BILL</h2>

<p>
<strong><?= e($bill_no) ?></strong>
</p>

<p>

<?php

if (!empty($visit_date)) {

    $timestamp = strtotime($visit_date);

    if ($timestamp !== false) {
        echo date("d M Y", $timestamp);
    } else {
        echo e($visit_date);
    }

} else {

    echo "N/A";
}

?>

</p>

</div>

</div>


<!-- =====================================================
     VISITOR INFORMATION
     ===================================================== -->

<div class="info">


<div class="info-box">

<strong>
    Visitor Name
</strong>

<?= e($guest_name) ?>

</div>


<div class="info-box">

<strong>
    Visitor Type
</strong>

<?= e($visitor_type) ?>

</div>


<div class="info-box">

<strong>
    Entry Time
</strong>

<?php

if (!empty($entry_time)) {

    $timestamp = strtotime($entry_time);

    if ($timestamp !== false) {
        echo date("h:i A", $timestamp);
    } else {
        echo e($entry_time);
    }

} else {

    echo "Not Recorded";
}

?>

</div>


<div class="info-box">

<strong>
    Exit Time
</strong>

<?php

if (!empty($exit_time)) {

    $timestamp = strtotime($exit_time);

    if ($timestamp !== false) {
        echo date("h:i A", $timestamp);
    } else {
        echo e($exit_time);
    }

} else {

    echo "Not Exited";
}

?>

</div>


<div class="info-box">

<strong>
    Booking ID
</strong>

<?php if (!empty($booking_id)): ?>

#<?= e($booking_id) ?>

<?php else: ?>

No Booking

<?php endif; ?>

</div>


<div class="info-box">

<strong>
    Status
</strong>

<span class="status">

<?= e($status) ?>

</span>

</div>


</div>


<!-- =====================================================
     CHARGES TABLE
     ===================================================== -->

<table>

<thead>

<tr>

<th>
    Description
</th>

<th>
    Qty
</th>

<th>
    Rate
</th>

<th class="right">
    Amount
</th>

</tr>

</thead>

<tbody>


<!-- =====================================================
     ADULT SWIMMING
     ===================================================== -->

<?php if ($adults > 0): ?>

<tr>

<td>
    Adult Swimming
</td>

<td>
    <?= $adults ?>
</td>

<td>
    Rs.
    <?= number_format(
        $adult_charge,
        2
    ) ?>
</td>

<td class="right">

Rs.
<?= number_format(
    $adults * $adult_charge,
    2
) ?>

</td>

</tr>

<?php endif; ?>


<!-- =====================================================
     CHILD SWIMMING
     ===================================================== -->

<?php if ($children > 0): ?>

<tr>

<td>
    Child Swimming
</td>

<td>
    <?= $children ?>
</td>

<td>
    Rs.
    <?= number_format(
        $child_charge,
        2
    ) ?>
</td>

<td class="right">

Rs.
<?= number_format(
    $children * $child_charge,
    2
) ?>

</td>

</tr>

<?php endif; ?>


<!-- =====================================================
     NO CHARGES FALLBACK
     ===================================================== -->

<?php if (
    $adults <= 0 &&
    $children <= 0
): ?>

<tr>

<td colspan="4" style="text-align:center;">

No swimming charges recorded.

</td>

</tr>

<?php endif; ?>


</tbody>

</table>


<!-- =====================================================
     TOTAL
     ===================================================== -->

<div class="total">

<div class="total-box">

<div class="label">

Total Swimming Charge

</div>

<div class="amount">

Rs.
<?= number_format(
    $total_amount,
    2
) ?>

</div>

</div>

</div>


<!-- =====================================================
     FOOTER
     ===================================================== -->

<div class="footer">

<strong>
    Thank you for using Suva Hotel Swimming Pool!
</strong>

<br>

Please keep this bill for your records.

</div>


</div>


<!-- =====================================================
     BUTTONS
     ===================================================== -->

<div class="buttons">

<a
    href="print_bill.php?id=<?= $id ?>"
    class="btn print"
>
    🖨️ Print Bill
</a>

<a
    href="view_one.php?id=<?= $id ?>"
    class="btn back"
>
    ← Back
</a>

</div>


</div>

</body>

</html>