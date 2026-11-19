
<?php

session_start();

/* =========================================================
   SUVA HOTEL
   RESTAURANT ORDERS - VIEW
   PostgreSQL Version

   File:
   C:\xampp\htdocs\Suva_hotel\restaurant\view.php
========================================================= */


/* =========================================================
   DATABASE CONNECTION
========================================================= */

require_once __DIR__ . '/../config/database.php';


/* =========================================================
   LOGIN PROTECTION
========================================================= */

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}


/* =========================================================
   CHECK POSTGRESQL CONNECTION
========================================================= */

if (!isset($pgConnection) || !$pgConnection) {
    die("Database connection is not available.");
}


/* =========================================================
   GET RESTAURANT ORDERS
========================================================= */

$sql = "
    SELECT
        ro.id,
        ro.customer_name,
        ro.order_type,
        ro.customer_type,
        ro.order_period,
        ro.subtotal,
        ro.vat_amount,
        ro.total_amount,
        ro.payment_method,
        ro.payment_status,
        ro.order_status,
        ro.created_at,
        r.room_number
    FROM restaurant_orders ro
    LEFT JOIN rooms r
        ON ro.room_id = r.id
    ORDER BY ro.id DESC
";


$result = pg_query(
    $pgConnection,
    $sql
);


/* =========================================================
   CHECK QUERY
========================================================= */

if (!$result) {

    die(
        "Database Error: " .
        htmlspecialchars(
            pg_last_error($pgConnection)
        )
    );

}


/* =========================================================
   COUNT ORDERS
========================================================= */

$orderCount = pg_num_rows($result);

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
    Restaurant Orders - Suva Hotel
</title>


<style>

/* =========================================================
   RESET
========================================================= */

* {
    box-sizing: border-box;
}


/* =========================================================
   BODY
========================================================= */

body {

    font-family:
        Arial,
        sans-serif;

    background:
        #f4f6f9;

    margin:
        0;

    padding:
        30px;

}


/* =========================================================
   CONTAINER
========================================================= */

.container {

    max-width:
        1500px;

    margin:
        auto;

    background:
        white;

    padding:
        25px;

    border-radius:
        12px;

    box-shadow:
        0 4px 15px
        rgba(0,0,0,0.08);

}


/* =========================================================
   TOP BAR
========================================================= */

.top-bar {

    display:
        flex;

    justify-content:
        space-between;

    align-items:
        center;

    gap:
        15px;

    margin-bottom:
        20px;

    flex-wrap:
        wrap;

}


/* =========================================================
   HEADINGS
========================================================= */

h1 {

    color:
        #173b6c;

    margin:
        0 0 5px;

}


h2 {

    color:
        #555;

    margin:
        0;

}


/* =========================================================
   BUTTON
========================================================= */

.btn {

    text-decoration:
        none;

    padding:
        10px 16px;

    border-radius:
        6px;

    color:
        white;

    display:
        inline-block;

    font-weight:
        bold;

    margin:
        3px;

}


.add-btn {

    background:
        #198754;

}


.add-btn:hover {

    background:
        #157347;

}


.dashboard-btn {

    background:
        #173b6c;

}


.dashboard-btn:hover {

    background:
        #0f2d52;

}


/* =========================================================
   TABLE WRAPPER
========================================================= */

.table-wrapper {

    overflow-x:
        auto;

}


/* =========================================================
   TABLE
========================================================= */

table {

    width:
        100%;

    border-collapse:
        collapse;

    margin-top:
        20px;

    min-width:
        1200px;

}


/* =========================================================
   TABLE HEADER
========================================================= */

th {

    background:
        #173b6c;

    color:
        white;

    padding:
        12px;

    white-space:
        nowrap;

}


/* =========================================================
   TABLE DATA
========================================================= */

td {

    padding:
        10px;

    border-bottom:
        1px solid #ddd;

    text-align:
        center;

    white-space:
        nowrap;

}


/* =========================================================
   ROW HOVER
========================================================= */

tr:hover {

    background:
        #f8f9fa;

}


/* =========================================================
   STATUS COLORS
========================================================= */

.paid {

    color:
        green;

    font-weight:
        bold;

}


.pending {

    color:
        orange;

    font-weight:
        bold;

}


.cancelled {

    color:
        red;

    font-weight:
        bold;

}


/* =========================================================
   ACTION BUTTONS
========================================================= */

.edit {

    background:
        #0d6efd;

}


.edit:hover {

    background:
        #0b5ed7;

}


.delete {

    background:
        #dc3545;

}


.delete:hover {

    background:
        #bb2d3b;

}


.bill {

    background:
        #6f42c1;

}


.bill:hover {

    background:
        #59359a;

}


.action-btn {

    padding:
        6px 9px;

    color:
        white;

    text-decoration:
        none;

    border-radius:
        5px;

    margin:
        2px;

    display:
        inline-block;

    font-size:
        13px;

}


/* =========================================================
   EMPTY MESSAGE
========================================================= */

.empty {

    padding:
        30px;

    text-align:
        center;

    color:
        #777;

}


.empty h3 {

    color:
        #555;

}


/* =========================================================
   TOTAL
========================================================= */

.total {

    font-weight:
        bold;

    color:
        #173b6c;

}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 768px) {

    body {

        padding:
            10px;

    }


    .container {

        padding:
            15px;

    }


    .top-bar {

        align-items:
            flex-start;

    }


    h1 {

        font-size:
            24px;

    }


    h2 {

        font-size:
            18px;

    }

}

</style>

</head>


<body>


<div class="container">


    <!-- =====================================================
         HEADER
    ====================================================== -->

    <div class="top-bar">


        <div>

            <h1>
                🏨 Suva Hotel
            </h1>

            <h2>
                🍽️ Restaurant Orders
            </h2>

        </div>


        <div>

            <a
                href="add.php"
                class="btn add-btn"
            >
                + New Order
            </a>


            <a
                href="../dashboard.php"
                class="btn dashboard-btn"
            >
                Dashboard
            </a>

        </div>


    </div>


    <!-- =====================================================
         ORDERS
    ====================================================== -->

    <?php if ($orderCount > 0): ?>


        <div class="table-wrapper">


            <table>


                <thead>

                <tr>

                    <th>
                        ID
                    </th>

                    <th>
                        Customer
                    </th>

                    <th>
                        Period
                    </th>

                    <th>
                        Type
                    </th>

                    <th>
                        Customer Type
                    </th>

                    <th>
                        Room
                    </th>

                    <th>
                        Subtotal
                    </th>

                    <th>
                        VAT 13%
                    </th>

                    <th>
                        Total
                    </th>

                    <th>
                        Payment
                    </th>

                    <th>
                        Payment Status
                    </th>

                    <th>
                        Order Status
                    </th>

                    <th>
                        Date
                    </th>

                    <th>
                        Actions
                    </th>

                </tr>

                </thead>


                <tbody>


                <?php while ($row = pg_fetch_assoc($result)): ?>


                    <tr>


                        <!-- =================================
                             ID
                        ================================== -->

                        <td>

                            <?php
                            echo (int)$row["id"];
                            ?>

                        </td>


                        <!-- =================================
                             CUSTOMER
                        ================================== -->

                        <td>

                            <?php

                            echo htmlspecialchars(
                                $row["customer_name"] ?? ""
                            );

                            ?>

                        </td>


                        <!-- =================================
                             PERIOD
                        ================================== -->

                        <td>

                            <?php

                            $period =
                                $row["order_period"] ?? "";


                            if (
                                $period === "Day" ||
                                $period === "Day Time"
                            ) {

                                echo "☀️ Day";

                            }

                            elseif (
                                $period === "Night" ||
                                $period === "Night Time"
                            ) {

                                echo "🌙 Night";

                            }

                            else {

                                echo htmlspecialchars(
                                    $period
                                );

                            }

                            ?>

                        </td>


                        <!-- =================================
                             ORDER TYPE
                        ================================== -->

                        <td>

                            <?php

                            echo htmlspecialchars(
                                $row["order_type"] ?? ""
                            );

                            ?>

                        </td>


                        <!-- =================================
                             CUSTOMER TYPE
                        ================================== -->

                        <td>

                            <?php

                            echo htmlspecialchars(
                                $row["customer_type"] ?? ""
                            );

                            ?>

                        </td>


                        <!-- =================================
                             ROOM
                        ================================== -->

                        <td>

                            <?php

                            if (
                                !empty(
                                    $row["room_number"]
                                )
                            ) {

                                echo
                                    "Room " .
                                    htmlspecialchars(
                                        $row["room_number"]
                                    );

                            }

                            else {

                                echo "-";

                            }

                            ?>

                        </td>


                        <!-- =================================
                             SUBTOTAL
                        ================================== -->

                        <td>

                            Rs.

                            <?php

                            echo number_format(
                                (float)(
                                    $row["subtotal"] ?? 0
                                ),
                                2
                            );

                            ?>

                        </td>


                        <!-- =================================
                             VAT
                        ================================== -->

                        <td>

                            Rs.

                            <?php

                            echo number_format(
                                (float)(
                                    $row["vat_amount"] ?? 0
                                ),
                                2
                            );

                            ?>

                        </td>


                        <!-- =================================
                             TOTAL
                        ================================== -->

                        <td class="total">

                            Rs.

                            <?php

                            echo number_format(
                                (float)(
                                    $row["total_amount"] ?? 0
                                ),
                                2
                            );

                            ?>

                        </td>


                        <!-- =================================
                             PAYMENT METHOD
                        ================================== -->

                        <td>

                            <?php

                            echo htmlspecialchars(
                                $row["payment_method"] ?? ""
                            );

                            ?>

                        </td>


                        <!-- =================================
                             PAYMENT STATUS
                        ================================== -->

                        <td>


                            <?php

                            $paymentStatus =
                                $row["payment_status"] ?? "";


                            if (
                                strtolower(
                                    $paymentStatus
                                ) === "paid"
                            ):

                            ?>

                                <span class="paid">

                                    ✅ Paid

                                </span>


                            <?php

                            elseif (
                                strtolower(
                                    $paymentStatus
                                ) === "pending"
                            ):

                            ?>

                                <span class="pending">

                                    ⏳ Pending

                                </span>


                            <?php else: ?>


                                <span>

                                    <?php

                                    echo htmlspecialchars(
                                        $paymentStatus
                                    );

                                    ?>

                                </span>


                            <?php endif; ?>


                        </td>


                        <!-- =================================
                             ORDER STATUS
                        ================================== -->

                        <td>


                            <?php

                            $orderStatus =
                                $row["order_status"] ?? "";


                            if (
                                strtolower(
                                    $orderStatus
                                ) === "completed"
                            ):

                            ?>

                                <span class="paid">

                                    ✅ Completed

                                </span>


                            <?php

                            elseif (
                                strtolower(
                                    $orderStatus
                                ) === "cancelled"
                            ):

                            ?>

                                <span class="cancelled">

                                    ❌ Cancelled

                                </span>


                            <?php else: ?>


                                <span class="pending">

                                    <?php

                                    echo htmlspecialchars(
                                        $orderStatus
                                    );

                                    ?>

                                </span>


                            <?php endif; ?>


                        </td>


                        <!-- =================================
                             DATE
                        ================================== -->

                        <td>

                            <?php

                            echo htmlspecialchars(
                                $row["created_at"] ?? ""
                            );

                            ?>

                        </td>


                        <!-- =================================
                             ACTIONS
                        ================================== -->

                        <td>


                            <!-- EDIT -->

                            <a
                                href="edit.php?id=<?php
                                    echo (int)$row["id"];
                                ?>"
                                class="action-btn edit"
                            >

                                ✏️ Edit

                            </a>


                           
<!-- PRINT RESTAURANT BILL -->

<a
    href="print.php?id=<?php
        echo (int)$row["id"];
    ?>"
    class="action-btn bill"
    target="_blank"
    rel="noopener"
>

    🧾 Print Bill

</a>



                            <!-- DELETE -->

                            <a
                                href="delete.php?id=<?php
                                    echo (int)$row["id"];
                                ?>"
                                class="action-btn delete"
                                onclick="return confirm(
                                    'Are you sure you want to delete Order #<?php
                                        echo (int)$row["id"];
                                    ?>?'
                                );"
                            >

                                🗑️ Delete

                            </a>


                        </td>


                    </tr>


                <?php endwhile; ?>


                </tbody>


            </table>


        </div>


    <?php else: ?>


        <!-- =================================================
             NO ORDERS
        ================================================== -->

        <div class="empty">


            <h3>

                🍽️ No restaurant orders found.

            </h3>


            <p>

                Create your first restaurant order.

            </p>


            <a
                href="add.php"
                class="btn add-btn"
            >

                + New Order

            </a>


        </div>


    <?php endif; ?>


</div>


</body>

</html>
