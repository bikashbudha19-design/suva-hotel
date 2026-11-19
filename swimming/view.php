```php
<?php
// ============================================================
// SUVA HOTEL - SWIMMING POOL MANAGEMENT
// PostgreSQL Version
// File: /suva_hotel/swimming/view.php
// ============================================================

session_start();

require_once __DIR__ . '/../config/database.php';

if (!isset($pgConnection) || !$pgConnection) {
    die("Database connection failed.");
}

// ============================================================
// HELPER
// ============================================================

function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

// ============================================================
// GET SWIMMING RECORDS + FOOD ORDER TOTAL
// ============================================================

$sql = "
    SELECT
        s.id,
        s.guest_id,
        s.booking_id,
        s.visitor_type,
        s.visitor_name,
        s.visit_date,
        s.entry_time,
        s.exit_time,
        s.adults,
        s.children,
        s.adult_charge,
        s.child_charge,
        s.total_amount,
        s.status,
        s.created_at,

        COALESCE(fo.food_order_count, 0) AS food_order_count,
        COALESCE(fo.food_total, 0) AS food_total,
        fo.latest_order_id

    FROM swimming s

    LEFT JOIN (
        SELECT
            swimming_id,
            COUNT(*) AS food_order_count,
            COALESCE(SUM(total_amount), 0) AS food_total,
            MAX(id) AS latest_order_id
        FROM restaurant_orders
        WHERE swimming_id IS NOT NULL
          AND LOWER(COALESCE(order_status, '')) != 'cancelled'
        GROUP BY swimming_id
    ) fo
        ON fo.swimming_id = s.id

    ORDER BY s.id DESC
";

$result = pg_query($pgConnection, $sql);

if (!$result) {
    die(
        "Database Error: " .
        pg_last_error($pgConnection)
    );
}

// ============================================================
// STATISTICS
// ============================================================

$active = 0;
$total_swimmers = 0;
$today_revenue = 0;
$total_revenue = 0;
$total_food_revenue = 0;

// ============================================================
// SWIMMING STATISTICS
// ============================================================

$stats_sql = "
    SELECT

        COALESCE(
            SUM(
                CASE
                    WHEN LOWER(COALESCE(status, '')) = 'active'
                    THEN adults + children
                    ELSE 0
                END
            ),
            0
        ) AS active_swimmers,

        COALESCE(
            SUM(adults + children),
            0
        ) AS total_swimmers,

        COALESCE(
            SUM(
                CASE
                    WHEN visit_date = CURRENT_DATE
                    AND LOWER(COALESCE(status, '')) != 'cancelled'
                    THEN total_amount
                    ELSE 0
                END
            ),
            0
        ) AS today_revenue,

        COALESCE(
            SUM(
                CASE
                    WHEN LOWER(COALESCE(status, '')) != 'cancelled'
                    THEN total_amount
                    ELSE 0
                END
            ),
            0
        ) AS total_revenue

    FROM swimming
";

$stats_result = pg_query(
    $pgConnection,
    $stats_sql
);

if (!$stats_result) {
    die(
        "Statistics Database Error: " .
        pg_last_error($pgConnection)
    );
}

$stat = pg_fetch_assoc($stats_result);

if ($stat) {

    $active =
        (int)($stat['active_swimmers'] ?? 0);

    $total_swimmers =
        (int)($stat['total_swimmers'] ?? 0);

    $today_revenue =
        (float)($stat['today_revenue'] ?? 0);

    $total_revenue =
        (float)($stat['total_revenue'] ?? 0);
}

// ============================================================
// TOTAL SWIMMING FOOD REVENUE
// ============================================================

$food_stats_sql = "
    SELECT
        COALESCE(SUM(total_amount), 0) AS food_revenue
    FROM restaurant_orders
    WHERE swimming_id IS NOT NULL
      AND LOWER(COALESCE(order_status, '')) != 'cancelled'
";

$food_stats_result = pg_query(
    $pgConnection,
    $food_stats_sql
);

if ($food_stats_result) {

    $food_stat =
        pg_fetch_assoc($food_stats_result);

    if ($food_stat) {

        $total_food_revenue =
            (float)(
                $food_stat['food_revenue'] ?? 0
            );
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

<title>
    Swimming Pool - Suva Hotel
</title>

<style>

/* ============================================================
   RESET
============================================================ */

* {
    box-sizing: border-box;
}

body {
    margin: 0;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    background: #f4f7fb;

    color: #1f2937;
}

/* ============================================================
   SIDEBAR
============================================================ */

.sidebar {
    position: fixed;

    left: 0;
    top: 0;

    width: 245px;

    height: 100vh;

    background: #123b5d;

    color: white;

    overflow-y: auto;

    padding-bottom: 20px;
}

.logo {
    padding: 25px 20px;

    text-align: center;

    border-bottom:
        1px solid rgba(255,255,255,.15);
}

.logo .icon {
    font-size: 38px;
}

.logo h2 {
    margin: 8px 0 3px;

    font-size: 21px;
}

.logo p {
    margin: 0;

    font-size: 12px;

    color: #cbdce9;
}

.menu-title {
    font-size: 12px;

    color: #9fb8ca;

    padding: 20px 20px 8px;

    font-weight: bold;
}

.menu a {
    display: block;

    color: white;

    text-decoration: none;

    padding: 13px 20px;

    font-size: 14px;
}

.menu a:hover,
.menu a.active {
    background: #1d557e;
}

.logout {
    margin-top: 15px;

    border-top:
        1px solid rgba(255,255,255,.15);
}

/* ============================================================
   MAIN
============================================================ */

.main {
    margin-left: 245px;

    padding: 30px;
}

.header {
    display: flex;

    justify-content: space-between;

    align-items: center;

    margin-bottom: 25px;

    gap: 20px;
}

.header h1 {
    margin: 0;

    font-size: 28px;
}

.header p {
    margin: 7px 0 0;

    color: #6b7280;
}

.add-btn {
    background: #087f5b;

    color: white;

    text-decoration: none;

    padding: 12px 18px;

    border-radius: 8px;

    font-weight: bold;

    white-space: nowrap;
}

.add-btn:hover {
    background: #066b4d;
}

/* ============================================================
   STATISTICS
============================================================ */

.cards {
    display: grid;

    grid-template-columns:
        repeat(5, 1fr);

    gap: 16px;

    margin-bottom: 28px;
}

.card {
    background: white;

    border-radius: 12px;

    padding: 20px;

    box-shadow:
        0 3px 12px rgba(0,0,0,.07);
}

.card-icon {
    font-size: 27px;

    margin-bottom: 10px;
}

.card h3 {
    margin: 0;

    font-size: 21px;
}

.card p {
    margin: 6px 0 0;

    color: #6b7280;

    font-size: 13px;
}

/* ============================================================
   TABLE BOX
============================================================ */

.table-box {
    background: white;

    border-radius: 12px;

    padding: 20px;

    box-shadow:
        0 3px 12px rgba(0,0,0,.07);

    overflow-x: auto;
}

.table-title {
    margin-bottom: 18px;
}

.table-title h2 {
    margin: 0;
}

.table-title p {
    color: #6b7280;

    margin: 5px 0 0;
}

table {
    width: 100%;

    border-collapse: collapse;

    min-width: 1450px;
}

th {
    background: #f1f5f9;

    padding: 12px;

    text-align: left;

    font-size: 13px;

    white-space: nowrap;
}

td {
    padding: 12px;

    border-bottom:
        1px solid #e5e7eb;

    font-size: 13px;

    vertical-align: middle;
}

tr:hover {
    background: #fafafa;
}

/* ============================================================
   BADGES
============================================================ */

.badge {
    display: inline-block;

    padding: 6px 10px;

    border-radius: 20px;

    font-size: 12px;

    font-weight: bold;

    white-space: nowrap;
}

.badge-active {
    background: #dcfce7;

    color: #166534;
}

.badge-completed {
    background: #dbeafe;

    color: #1e40af;
}

.badge-cancelled {
    background: #fee2e2;

    color: #991b1b;
}

/* ============================================================
   FOOD ORDER
============================================================ */

.food-box {
    min-width: 150px;
}

.food-count {
    font-weight: bold;

    color: #7c3aed;
}

.food-total {
    font-weight: bold;

    color: #047857;

    margin-top: 4px;

    white-space: nowrap;
}

.no-food {
    color: #9ca3af;

    font-size: 12px;
}

/* ============================================================
   COMBINED TOTAL
============================================================ */

.combined-total {
    font-weight: bold;

    color: #b45309;

    white-space: nowrap;
}

/* ============================================================
   ACTIONS
============================================================ */

.actions {
    white-space: nowrap;
}

.actions a {
    display: inline-block;

    text-decoration: none;

    margin-right: 4px;

    margin-bottom: 4px;

    padding: 7px 9px;

    border-radius: 5px;

    font-size: 12px;

    font-weight: bold;
}

.view {
    background: #e0f2fe;

    color: #0369a1;
}

.edit {
    background: #fef3c7;

    color: #92400e;
}

.exit {
    background: #dcfce7;

    color: #166534;
}

.delete {
    background: #fee2e2;

    color: #991b1b;
}

.order {
    background: #ede9fe;

    color: #6d28d9;
}

.order:hover {
    background: #ddd6fe;
}

.order-view {
    background: #d1fae5;

    color: #065f46;
}

.order-view:hover {
    background: #a7f3d0;
}

/* ============================================================
   FOOTER
============================================================ */

.footer {
    text-align: center;

    margin-top: 30px;

    color: #6b7280;

    font-size: 13px;
}

/* ============================================================
   RESPONSIVE
============================================================ */

@media(max-width: 1200px) {

    .cards {
        grid-template-columns:
            repeat(3, 1fr);
    }

}

@media(max-width: 900px) {

    .sidebar {
        width: 200px;
    }

    .main {
        margin-left: 200px;

        padding: 20px;
    }

    .cards {
        grid-template-columns:
            repeat(2, 1fr);
    }

}

@media(max-width: 600px) {

    .sidebar {
        position: relative;

        width: 100%;

        height: auto;
    }

    .main {
        margin-left: 0;

        padding: 15px;
    }

    .cards {
        grid-template-columns: 1fr;
    }

    .header {
        display: block;
    }

    .add-btn {
        display: inline-block;

        margin-top: 15px;
    }

}

</style>

</head>

<body>

<!-- ============================================================
     SIDEBAR
============================================================ -->

<div class="sidebar">

    <div class="logo">

        <div class="icon">
            🏨
        </div>

        <h2>
            SUVA HOTEL
        </h2>

        <p>
            Management System
        </p>

    </div>

    <div class="menu-title">
        MANAGEMENT
    </div>

    <div class="menu">

        <a href="../rooms/view.php">
            🏨 Rooms
        </a>

        <a href="../guests/view.php">
            👤 Guests
        </a>

        <a href="../bookings/view.php">
            📋 Bookings
        </a>

        <a href="../billing/view.php">
            💰 Billing
        </a>

        <a href="../payment/view.php">
            💳 Payments
        </a>

        <a href="../inventory/view.php">
            📦 Inventory
        </a>

        <a href="view.php" class="active">
            🏊 Swimming Pool
        </a>

    </div>

    <div class="menu logout">

        <a href="../dashboard.php">
            🏠 Back to Dashboard
        </a>

        <a href="../logout.php">
            🚪 Logout
        </a>

    </div>

</div>

<!-- ============================================================
     MAIN
============================================================ -->

<div class="main">

    <div class="header">

        <div>

            <h1>
                🏊 Swimming Pool
            </h1>

            <p>
                Manage swimmers, swimming charges and restaurant orders.
            </p>

        </div>

        <a
            href="add.php"
            class="add-btn"
        >
            + Add Pool Entry
        </a>

    </div>

    <!-- ========================================================
         STATISTICS
    ========================================================= -->

    <div class="cards">

        <div class="card">

            <div class="card-icon">
                🏊
            </div>

            <h3>
                <?= e($active) ?>
            </h3>

            <p>
                Active Swimmers
            </p>

        </div>

        <div class="card">

            <div class="card-icon">
                👥
            </div>

            <h3>
                <?= e($total_swimmers) ?>
            </h3>

            <p>
                Total Visitors
            </p>

        </div>

        <div class="card">

            <div class="card-icon">
                💰
            </div>

            <h3>
                Rs. <?= number_format(
                    $today_revenue,
                    2
                ) ?>
            </h3>

            <p>
                Today's Swimming Revenue
            </p>

        </div>

        <div class="card">

            <div class="card-icon">
                🍛
            </div>

            <h3>
                Rs. <?= number_format(
                    $total_food_revenue,
                    2
                ) ?>
            </h3>

            <p>
                Swimming Food Revenue
            </p>

        </div>

        <div class="card">

            <div class="card-icon">
                📈
            </div>

            <h3>
                Rs. <?= number_format(
                    $total_revenue,
                    2
                ) ?>
            </h3>

            <p>
                Swimming Revenue
            </p>

        </div>

    </div>

    <!-- ========================================================
         TABLE
    ========================================================= -->

    <div class="table-box">

        <div class="table-title">

            <h2>
                Swimming Pool Entries
            </h2>

            <p>
                Swimming charges and restaurant food/drink orders are linked together.
            </p>

        </div>

        <table>

            <thead>

            <tr>

                <th>
                    ID
                </th>

                <th>
                    Visitor
                </th>

                <th>
                    Type
                </th>

                <th>
                    Visit Date
                </th>

                <th>
                    Entry
                </th>

                <th>
                    Exit
                </th>

                <th>
                    Adults
                </th>

                <th>
                    Children
                </th>

                <th>
                    Swimming Charge
                </th>

                <th>
                    Food Orders
                </th>

                <th>
                    Food Total
                </th>

                <th>
                    Combined Total
                </th>

                <th>
                    Status
                </th>

                <th>
                    Actions
                </th>

            </tr>

            </thead>

            <tbody>

            <?php if (pg_num_rows($result) > 0): ?>

                <?php while ($row = pg_fetch_assoc($result)): ?>

                    <?php

                    // =================================================
                    // VISITOR
                    // =================================================

                    $visitor =
                        trim(
                            $row['visitor_name'] ?? ''
                        );

                    if ($visitor === '') {

                        if (
                            ($row['visitor_type'] ?? '')
                            === 'Hotel Guest'
                            &&
                            !empty($row['guest_id'])
                        ) {

                            $visitor =
                                'Guest ID: ' .
                                $row['guest_id'];

                        } else {

                            $visitor =
                                'Walk-in Visitor';

                        }
                    }

                    // =================================================
                    // STATUS
                    // =================================================

                    $status =
                        trim(
                            $row['status'] ?? 'Active'
                        );

                    $status_lower =
                        strtolower($status);

                    if (
                        $status_lower === 'active'
                    ) {

                        $status_class =
                            'badge-active';

                    } elseif (
                        $status_lower === 'completed'
                    ) {

                        $status_class =
                            'badge-completed';

                    } else {

                        $status_class =
                            'badge-cancelled';
                    }

                    // =================================================
                    // DATE
                    // =================================================

                    $display_date =
                        !empty($row['visit_date'])
                        ? date(
                            'd M Y',
                            strtotime(
                                $row['visit_date']
                            )
                        )
                        : '-';

                    // =================================================
                    // ENTRY
                    // =================================================

                    $display_entry =
                        !empty($row['entry_time'])
                        ? date(
                            'h:i A',
                            strtotime(
                                $row['entry_time']
                            )
                        )
                        : '-';

                    // =================================================
                    // EXIT
                    // =================================================

                    $display_exit =
                        !empty($row['exit_time'])
                        ? date(
                            'h:i A',
                            strtotime(
                                $row['exit_time']
                            )
                        )
                        : '-';

                    // =================================================
                    // SWIMMING CHARGE
                    // =================================================

                    $swimming_charge =
                        (float)(
                            $row['total_amount'] ?? 0
                        );

                    // =================================================
                    // FOOD ORDER DATA
                    // =================================================

                    $food_order_count =
                        (int)(
                            $row['food_order_count'] ?? 0
                        );

                    $food_total =
                        (float)(
                            $row['food_total'] ?? 0
                        );

                    $latest_order_id =
                        !empty(
                            $row['latest_order_id']
                        )
                        ? (int)$row['latest_order_id']
                        : 0;

                    // =================================================
                    // COMBINED TOTAL
                    // =================================================

                    $combined_total =
                        $swimming_charge +
                        $food_total;

                    ?>

                    <tr>

                        <!-- ID -->

                        <td>

                            <strong>
                                #<?= e($row['id']) ?>
                            </strong>

                        </td>

                        <!-- VISITOR -->

                        <td>

                            <strong>
                                <?= e($visitor) ?>
                            </strong>

                        </td>

                        <!-- TYPE -->

                        <td>

                            <?php if (
                                ($row['visitor_type'] ?? '')
                                === 'Hotel Guest'
                            ): ?>

                                🏨 Hotel Guest

                            <?php else: ?>

                                🚶 Walk-in

                            <?php endif; ?>

                        </td>

                        <!-- DATE -->

                        <td>
                            <?= e($display_date) ?>
                        </td>

                        <!-- ENTRY -->

                        <td>
                            <?= e($display_entry) ?>
                        </td>

                        <!-- EXIT -->

                        <td>
                            <?= e($display_exit) ?>
                        </td>

                        <!-- ADULTS -->

                        <td>
                            <?= e($row['adults']) ?>
                        </td>

                        <!-- CHILDREN -->

                        <td>
                            <?= e($row['children']) ?>
                        </td>

                        <!-- SWIMMING CHARGE -->

                        <td>

                            <strong>

                                Rs.
                                <?= number_format(
                                    $swimming_charge,
                                    2
                                ) ?>

                            </strong>

                        </td>

                        <!-- FOOD ORDERS -->

                        <td class="food-box">

                            <?php if (
                                $food_order_count > 0
                            ): ?>

                                <div class="food-count">

                                    🍛
                                    <?= e(
                                        $food_order_count
                                    ) ?>

                                    order(s)

                                </div>

                            <?php else: ?>

                                <span class="no-food">
                                    No food order
                                </span>

                            <?php endif; ?>

                        </td>

                        <!-- FOOD TOTAL -->

                        <td>

                            <?php if (
                                $food_total > 0
                            ): ?>

                                <div class="food-total">

                                    Rs.
                                    <?= number_format(
                                        $food_total,
                                        2
                                    ) ?>

                                </div>

                            <?php else: ?>

                                <span class="no-food">
                                    Rs. 0.00
                                </span>

                            <?php endif; ?>

                        </td>

                        <!-- COMBINED TOTAL -->

                        <td>

                            <span class="combined-total">

                                Rs.
                                <?= number_format(
                                    $combined_total,
                                    2
                                ) ?>

                            </span>

                        </td>

                        <!-- STATUS -->

                        <td>

                            <span
                                class="badge <?= e($status_class) ?>"
                            >

                                <?php if (
                                    $status_lower === 'active'
                                ): ?>

                                    🟢 Active

                                <?php elseif (
                                    $status_lower === 'completed'
                                ): ?>

                                    🔵 Completed

                                <?php else: ?>

                                    🔴 Cancelled

                                <?php endif; ?>

                            </span>

                        </td>

                        <!-- ACTIONS -->

                        <td class="actions">

                            <!-- VIEW -->

                            <a
                                href="view_one.php?id=<?= (int)$row['id'] ?>"
                                class="view"
                            >
                                👁 View
                            </a>

                            <!-- ORDER FOOD -->

                            <?php if (
                                $status_lower === 'active'
                            ): ?>

                                <a
                                    href="order.php?swimming_id=<?= (int)$row['id'] ?>"
                                    class="order"
                                >
                                    🍛 Order Food
                                </a>

                            <?php endif; ?>

                            <!-- FOOD BILL -->

                            <?php if (
                                $food_order_count > 0
                                &&
                                $latest_order_id > 0
                            ): ?>

                                <a
                                    href="order_bill.php?order_id=<?= (int)$latest_order_id ?>"
                                    class="order-view"
                                >
                                    🧾 Food Bill
                                </a>

                            <?php endif; ?>

                            <!-- EDIT -->

                            <a
                                href="edit.php?id=<?= (int)$row['id'] ?>"
                                class="edit"
                            >
                                ✏ Edit
                            </a>

                            <!-- EXIT -->

                            <?php if (
                                $status_lower === 'active'
                            ): ?>

                                <a
                                    href="exit.php?id=<?= (int)$row['id'] ?>"
                                    class="exit"
                                    onclick="
                                        return confirm(
                                            'Mark this swimmer as exited?'
                                        );
                                    "
                                >
                                    🚪 Exit
                                </a>

                            <?php endif; ?>

                            <!-- DELETE -->

                            <a
                                href="delete.php?id=<?= (int)$row['id'] ?>"
                                class="delete"
                                onclick="
                                    return confirm(
                                        'Are you sure you want to delete this swimming record?'
                                    );
                                "
                            >
                                🗑 Delete
                            </a>

                        </td>

                    </tr>

                <?php endwhile; ?>

            <?php else: ?>

                <tr>

                    <td
                        colspan="14"
                        style="
                            text-align:center;
                            padding:40px;
                            color:#6b7280;
                        "
                    >

                        🏊
                        No swimming records found.

                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

    <!-- ========================================================
         FOOTER
    ========================================================= -->

    <div class="footer">

        © <?= e(date('Y')) ?>
        Suva Hotel Management System
        • Swimming Pool Management

    </div>

</div>

</body>

</html>
```
