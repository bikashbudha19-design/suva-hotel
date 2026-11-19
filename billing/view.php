<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once "../config/database.php";

/*
=========================================================
GET BILLING RECORDS
=========================================================
*/

$sql = "
    SELECT
        billing.id,
        billing.booking_id,
        billing.guest_id,
        billing.invoice_no,
        billing.billing_date,
        billing.room_charge,
        billing.food_charge,
        billing.service_charge,
        billing.discount,
        billing.tax,
        billing.total_amount,
        billing.status,
        billing.created_at,
        guests.full_name
    FROM billing
    LEFT JOIN guests
        ON billing.guest_id = guests.id
    ORDER BY billing.id DESC
";

$result = $conn->query($sql);

if (!$result) {
    die("Database Error: " . $conn->error);
}

/*
=========================================================
CALCULATE SUMMARY
=========================================================
*/

$totalBills = 0;
$paidBills = 0;
$unpaidBills = 0;
$partialBills = 0;
$cancelledBills = 0;
$totalRevenue = 0;

$bills = [];

while ($row = $result->fetch_assoc()) {

    $bills[] = $row;

    $totalBills++;

    $status = trim($row["status"] ?? "");

    if ($status === "") {
        $status = "Unpaid";
    }

    if (strcasecmp($status, "Paid") === 0) {
        $paidBills++;
        $totalRevenue += (float)$row["total_amount"];
    }
    elseif (strcasecmp($status, "Partial") === 0) {
        $partialBills++;
        $totalRevenue += (float)$row["total_amount"];
    }
    elseif (strcasecmp($status, "Cancelled") === 0) {
        $cancelledBills++;
    }
    else {
        $unpaidBills++;
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

    <title>Billing Management - Suva Hotel</title>

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f7fb;
            color: #263238;
        }

        /* =========================================
           HEADER
        ========================================= */

        .header {
            background: linear-gradient(
                135deg,
                #0f4c75,
                #3282b8
            );

            color: white;

            padding: 22px 35px;

            display: flex;

            justify-content: space-between;

            align-items: center;

            box-shadow: 0 4px 15px rgba(0,0,0,0.12);
        }

        .hotel-title {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .hotel-icon {
            width: 48px;
            height: 48px;

            background: rgba(255,255,255,0.18);

            border-radius: 12px;

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 25px;
        }

        .hotel-title h1 {
            font-size: 24px;
            margin-bottom: 4px;
        }

        .hotel-title p {
            font-size: 13px;
            opacity: 0.85;
        }

        .dashboard-btn {
            text-decoration: none;

            color: white;

            background: rgba(255,255,255,0.15);

            padding: 11px 18px;

            border-radius: 8px;

            font-size: 14px;

            transition: 0.3s;
        }

        .dashboard-btn:hover {
            background: rgba(255,255,255,0.28);
        }


        /* =========================================
           MAIN CONTAINER
        ========================================= */

        .container {
            width: 94%;

            max-width: 1500px;

            margin: 30px auto;
        }

        .page-header {
            display: flex;

            justify-content: space-between;

            align-items: center;

            margin-bottom: 25px;
        }

        .page-header h2 {
            font-size: 27px;

            color: #17324d;
        }

        .page-header p {
            color: #718096;

            margin-top: 5px;

            font-size: 14px;
        }

        .new-bill {
            text-decoration: none;

            background: #0f766e;

            color: white;

            padding: 13px 20px;

            border-radius: 8px;

            font-weight: bold;

            transition: 0.3s;
        }

        .new-bill:hover {
            background: #0b5f59;
        }


        /* =========================================
           SUMMARY CARDS
        ========================================= */

        .summary-grid {
            display: grid;

            grid-template-columns:
                repeat(5, 1fr);

            gap: 18px;

            margin-bottom: 25px;
        }

        .summary-card {
            background: white;

            border-radius: 12px;

            padding: 20px;

            box-shadow:
                0 4px 15px rgba(0,0,0,0.06);

            border-left: 5px solid #3282b8;
        }

        .summary-card h3 {
            font-size: 13px;

            color: #718096;

            margin-bottom: 9px;

            text-transform: uppercase;

            letter-spacing: 0.4px;
        }

        .summary-number {
            font-size: 25px;

            font-weight: bold;

            color: #1a202c;
        }

        .summary-card.revenue {
            border-left-color: #0f766e;
        }

        .summary-card.paid {
            border-left-color: #16a34a;
        }

        .summary-card.unpaid {
            border-left-color: #f59e0b;
        }

        .summary-card.cancelled {
            border-left-color: #dc2626;
        }


        /* =========================================
           SEARCH
        ========================================= */

        .toolbar {
            background: white;

            padding: 18px;

            border-radius: 12px;

            margin-bottom: 20px;

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 15px;

            box-shadow:
                0 4px 15px rgba(0,0,0,0.05);
        }

        .search-box {
            position: relative;

            width: 400px;

            max-width: 100%;
        }

        .search-box input {
            width: 100%;

            padding: 13px 15px 13px 42px;

            border: 1px solid #d9e2ec;

            border-radius: 8px;

            outline: none;

            font-size: 14px;

            transition: 0.3s;
        }

        .search-box input:focus {
            border-color: #3282b8;

            box-shadow:
                0 0 0 3px rgba(50,130,184,0.1);
        }

        .search-icon {
            position: absolute;

            left: 15px;

            top: 50%;

            transform: translateY(-50%);

            color: #718096;

            font-size: 17px;
        }

        .record-count {
            color: #718096;

            font-size: 14px;
        }


        /* =========================================
           TABLE
        ========================================= */

        .table-container {
            background: white;

            border-radius: 12px;

            box-shadow:
                0 4px 15px rgba(0,0,0,0.06);

            overflow: hidden;
        }

        .table-scroll {
            overflow-x: auto;
        }

        table {
            width: 100%;

            border-collapse: collapse;

            min-width: 1250px;
        }

        thead {
            background: #17324d;

            color: white;
        }

        th {
            padding: 15px 13px;

            text-align: left;

            font-size: 12px;

            text-transform: uppercase;

            letter-spacing: 0.5px;

            white-space: nowrap;
        }

        td {
            padding: 15px 13px;

            border-bottom: 1px solid #edf2f7;

            font-size: 13px;

            white-space: nowrap;
        }

        tbody tr {
            transition: 0.2s;
        }

        tbody tr:hover {
            background: #f8fbff;
        }

        .invoice {
            font-weight: bold;

            color: #2563eb;
        }

        .guest {
            font-weight: bold;

            color: #334155;
        }

        .amount {
            font-weight: 600;
        }

        .total {
            font-weight: bold;

            color: #0f766e;

            font-size: 14px;
        }


        /* =========================================
           STATUS BADGES
        ========================================= */

        .badge {
            display: inline-block;

            padding: 6px 11px;

            border-radius: 20px;

            font-size: 11px;

            font-weight: bold;

            text-transform: uppercase;
        }

        .badge-paid {
            color: #166534;

            background: #dcfce7;
        }

        .badge-unpaid {
            color: #92400e;

            background: #fef3c7;
        }

        .badge-partial {
            color: #1d4ed8;

            background: #dbeafe;
        }

        .badge-cancelled {
            color: #991b1b;

            background: #fee2e2;
        }


        /* =========================================
           ACTION BUTTONS
        ========================================= */

        .actions {
            display: flex;

            gap: 6px;
        }

        .action-btn {
            text-decoration: none;

            padding: 7px 10px;

            border-radius: 6px;

            font-size: 12px;

            font-weight: bold;

            transition: 0.2s;
        }

        .view-btn {
            background: #e0f2fe;

            color: #0369a1;
        }

        .view-btn:hover {
            background: #bae6fd;
        }

        .edit-btn {
            background: #fef3c7;

            color: #92400e;
        }

        .edit-btn:hover {
            background: #fde68a;
        }

        .delete-btn {
            background: #fee2e2;

            color: #b91c1c;
        }

        .delete-btn:hover {
            background: #fecaca;
        }


        /* =========================================
           NO RESULTS
        ========================================= */

        .no-results {
            text-align: center;

            padding: 45px;

            color: #718096;

            display: none;
        }

        .empty-message {
            text-align: center;

            padding: 60px 20px;

            color: #718096;
        }

        .empty-message .icon {
            font-size: 45px;

            margin-bottom: 12px;
        }


        /* =========================================
           FOOTER
        ========================================= */

        .footer {
            text-align: center;

            color: #94a3b8;

            font-size: 12px;

            padding: 25px 0;
        }


        /* =========================================
           RESPONSIVE
        ========================================= */

        @media (max-width: 1100px) {

            .summary-grid {
                grid-template-columns:
                    repeat(3, 1fr);
            }

        }

        @media (max-width: 700px) {

            .header {
                padding: 18px;

                flex-direction: column;

                gap: 15px;

                align-items: flex-start;
            }

            .container {
                width: 96%;

                margin-top: 20px;
            }

            .page-header {
                flex-direction: column;

                align-items: flex-start;

                gap: 15px;
            }

            .summary-grid {
                grid-template-columns:
                    repeat(2, 1fr);
            }

            .toolbar {
                flex-direction: column;

                align-items: stretch;
            }

            .search-box {
                width: 100%;
            }

        }

        @media (max-width: 450px) {

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .page-header h2 {
                font-size: 23px;
            }

        }

    </style>

</head>


<body>


<!-- =========================================
     HEADER
========================================= -->

<header class="header">

    <div class="hotel-title">

        <div class="hotel-icon">
            🏨
        </div>

        <div>

            <h1>SUVA HOTEL</h1>

            <p>
                Hotel Management System
            </p>

        </div>

    </div>


    <a
        href="../dashboard.php"
        class="dashboard-btn"
    >
        ← Dashboard
    </a>

</header>



<!-- =========================================
     MAIN
========================================= -->

<main class="container">


    <!-- PAGE TITLE -->

    <div class="page-header">

        <div>

            <h2>Billing Management</h2>

            <p>
                Manage hotel invoices, charges and payments
            </p>

        </div>


        <a
            href="add.php"
            class="new-bill"
        >
            + Create New Bill
        </a>

    </div>



    <!-- =========================================
         SUMMARY CARDS
    ========================================= -->

    <div class="summary-grid">


        <div class="summary-card">

            <h3>Total Bills</h3>

            <div class="summary-number">
                <?php echo $totalBills; ?>
            </div>

        </div>


        <div class="summary-card paid">

            <h3>Paid Bills</h3>

            <div class="summary-number">
                <?php echo $paidBills; ?>
            </div>

        </div>


        <div class="summary-card unpaid">

            <h3>Unpaid Bills</h3>

            <div class="summary-number">
                <?php echo $unpaidBills; ?>
            </div>

        </div>


        <div class="summary-card cancelled">

            <h3>Cancelled</h3>

            <div class="summary-number">
                <?php echo $cancelledBills; ?>
            </div>

        </div>


        <div class="summary-card revenue">

            <h3>Total Revenue</h3>

            <div class="summary-number">

                Rs.
                <?php
                echo number_format(
                    $totalRevenue,
                    2
                );
                ?>

            </div>

        </div>


    </div>



    <!-- =========================================
         SEARCH TOOLBAR
    ========================================= -->

    <div class="toolbar">


        <div class="search-box">

            <span class="search-icon">
                🔍
            </span>

            <input
                type="text"
                id="billSearch"
                placeholder="Search invoice, guest, booking ID, status..."
                onkeyup="searchBills()"
            >

        </div>


        <div
            class="record-count"
            id="recordCount"
        >

            Showing
            <strong>
                <?php echo $totalBills; ?>
            </strong>
            bills

        </div>


    </div>



    <!-- =========================================
         BILLING TABLE
    ========================================= -->

    <div class="table-container">

        <div class="table-scroll">

            <table id="billingTable">

                <thead>

                    <tr>

                        <th>ID</th>

                        <th>Booking</th>

                        <th>Guest</th>

                        <th>Invoice</th>

                        <th>Billing Date</th>

                        <th>Room Charge</th>

                        <th>Food Charge</th>

                        <th>Service</th>

                        <th>Discount</th>

                        <th>Tax</th>

                        <th>Total</th>

                        <th>Status</th>

                        <th>Created</th>

                        <th>Actions</th>

                    </tr>

                </thead>


                <tbody id="billBody">


                <?php if (count($bills) > 0): ?>


                    <?php foreach ($bills as $row): ?>

                        <?php

                        $status =
                            trim(
                                $row["status"] ?? ""
                            );

                        if ($status === "") {
                            $status = "Unpaid";
                        }

                        $statusLower =
                            strtolower($status);

                        if ($statusLower === "paid") {

                            $badgeClass =
                                "badge-paid";

                        }
                        elseif (
                            $statusLower === "partial"
                        ) {

                            $badgeClass =
                                "badge-partial";

                        }
                        elseif (
                            $statusLower === "cancelled"
                        ) {

                            $badgeClass =
                                "badge-cancelled";

                        }
                        else {

                            $badgeClass =
                                "badge-unpaid";

                        }

                        ?>


                        <tr class="bill-row">


                            <!-- ID -->

                            <td>

                                <strong>
                                    #<?php
                                    echo (int)$row["id"];
                                    ?>
                                </strong>

                            </td>


                            <!-- BOOKING -->

                            <td>

                                #<?php
                                echo (int)$row["booking_id"];
                                ?>

                            </td>


                            <!-- GUEST -->

                            <td class="guest">

                                <?php

                                echo htmlspecialchars(
                                    $row["full_name"]
                                    ?? "Unknown Guest"
                                );

                                ?>

                            </td>


                            <!-- INVOICE -->

                            <td class="invoice">

                                <?php

                                echo htmlspecialchars(
                                    $row["invoice_no"]
                                    ?? "-"
                                );

                                ?>

                            </td>


                            <!-- BILLING DATE -->

                            <td>

                                <?php

                                echo htmlspecialchars(
                                    $row["billing_date"]
                                    ?? "-"
                                );

                                ?>

                            </td>


                            <!-- ROOM -->

                            <td class="amount">

                                Rs.
                                <?php

                                echo number_format(
                                    (float)$row[
                                        "room_charge"
                                    ],
                                    2
                                );

                                ?>

                            </td>


                            <!-- FOOD -->

                            <td class="amount">

                                Rs.
                                <?php

                                echo number_format(
                                    (float)$row[
                                        "food_charge"
                                    ],
                                    2
                                );

                                ?>

                            </td>


                            <!-- SERVICE -->

                            <td class="amount">

                                Rs.
                                <?php

                                echo number_format(
                                    (float)$row[
                                        "service_charge"
                                    ],
                                    2
                                );

                                ?>

                            </td>


                            <!-- DISCOUNT -->

                            <td class="amount">

                                Rs.
                                <?php

                                echo number_format(
                                    (float)$row[
                                        "discount"
                                    ],
                                    2
                                );

                                ?>

                            </td>


                            <!-- TAX -->

                            <td class="amount">

                                Rs.
                                <?php

                                echo number_format(
                                    (float)$row[
                                        "tax"
                                    ],
                                    2
                                );

                                ?>

                            </td>


                            <!-- TOTAL -->

                            <td class="total">

                                Rs.
                                <?php

                                echo number_format(
                                    (float)$row[
                                        "total_amount"
                                    ],
                                    2
                                );

                                ?>

                            </td>


                            <!-- STATUS -->

                            <td>

                                <span
                                    class="badge <?php
                                    echo $badgeClass;
                                    ?>"
                                >

                                    <?php
                                    echo htmlspecialchars(
                                        $status
                                    );
                                    ?>

                                </span>

                            </td>


                            <!-- CREATED -->

                            <td>

                                <?php

                                echo htmlspecialchars(
                                    $row["created_at"]
                                    ?? "-"
                                );

                                ?>

                            </td>


                            <!-- ACTIONS -->

                            <td>

                                <div class="actions">


                                    <!-- VIEW INVOICE -->

                                    <a
                                        href="invoice.php?id=<?php
                                        echo (int)$row["id"];
                                        ?>"
                                        class="action-btn view-btn"
                                        title="View Invoice"
                                    >
                                        👁 View
                                    </a>


                                    <!-- EDIT -->

                                    <a
                                        href="edit.php?id=<?php
                                        echo (int)$row["id"];
                                        ?>"
                                        class="action-btn edit-btn"
                                        title="Edit Bill"
                                    >
                                        ✏ Edit
                                    </a>


                                    <!-- DELETE -->

                                    <a
                                        href="delete.php?id=<?php
                                        echo (int)$row["id"];
                                        ?>"
                                        class="action-btn delete-btn"
                                        title="Delete Bill"
                                        onclick="
                                        return confirm(
                                            'Are you sure you want to delete this bill?'
                                        );
                                        "
                                    >
                                        🗑 Delete
                                    </a>


                                </div>

                            </td>


                        </tr>


                    <?php endforeach; ?>


                <?php else: ?>


                    <tr>

                        <td colspan="14">

                            <div class="empty-message">

                                <div class="icon">
                                    🧾
                                </div>

                                <h3>
                                    No Bills Found
                                </h3>

                                <p>
                                    You have not created any
                                    billing records yet.
                                </p>

                            </div>

                        </td>

                    </tr>


                <?php endif; ?>


                </tbody>

            </table>


            <!-- SEARCH NO RESULT -->

            <div
                class="no-results"
                id="noResults"
            >

                🔍

                <br><br>

                <strong>
                    No matching bills found
                </strong>

                <br>

                Try another search.

            </div>


        </div>

    </div>


</main>



<!-- =========================================
     FOOTER
========================================= -->

<footer class="footer">

    © <?php echo date("Y"); ?>

    <strong>Suva Hotel</strong>

    · Billing Management System

</footer>



<!-- =========================================
     SEARCH JAVASCRIPT
========================================= -->

<script>

function searchBills() {

    const input =
        document
        .getElementById("billSearch")
        .value
        .toLowerCase()
        .trim();

    const rows =
        document.querySelectorAll(
            "#billBody .bill-row"
        );

    let visibleCount = 0;


    rows.forEach(function(row) {

        const text =
            row.innerText.toLowerCase();


        if (
            text.includes(input)
        ) {

            row.style.display = "";

            visibleCount++;

        }
        else {

            row.style.display = "none";

        }

    });


    const noResults =
        document.getElementById(
            "noResults"
        );


    const recordCount =
        document.getElementById(
            "recordCount"
        );


    if (
        visibleCount === 0 &&
        rows.length > 0
    ) {

        noResults.style.display =
            "block";

    }
    else {

        noResults.style.display =
            "none";

    }


    recordCount.innerHTML =
        "Showing <strong>" +
        visibleCount +
        "</strong> bills";

}

</script>


</body>

</html>
```
