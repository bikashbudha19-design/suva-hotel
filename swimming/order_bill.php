```php
<?php
// ============================================================
// SUVA HOTEL - SWIMMING FOOD & DRINK ORDER BILL
// File: /swimming/order_bill.php
// PostgreSQL Version
// ============================================================

session_start();

require_once __DIR__ . '/../config/database.php';

if (!isset($pgConnection) || !$pgConnection) {
    die("PostgreSQL database connection failed.");
}

// ============================================================
// AUTHENTICATION
// ============================================================

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// ============================================================
// HELPER
// ============================================================

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// ============================================================
// GET ORDER ID
// ============================================================

$orderId = isset($_GET['order_id'])
    ? (int)$_GET['order_id']
    : 0;

if ($orderId <= 0) {
    die("Invalid order ID.");
}

// ============================================================
// CHECK TABLES
// ============================================================

$tableResult = pg_query(
    $pgConnection,
    "
    SELECT table_name
    FROM information_schema.tables
    WHERE table_schema = 'public'
    AND table_name IN (
        'restaurant_orders',
        'restaurant_order_items',
        'restaurant_foods',
        'swimming',
        'guests'
    )
    "
);

$tables = [];

if ($tableResult) {
    while ($row = pg_fetch_assoc($tableResult)) {
        $tables[] = $row['table_name'];
    }
}

if (!in_array('restaurant_orders', $tables, true)) {
    die("restaurant_orders table was not found.");
}

if (!in_array('restaurant_order_items', $tables, true)) {
    die("restaurant_order_items table was not found.");
}

if (!in_array('restaurant_foods', $tables, true)) {
    die("restaurant_foods table was not found.");
}

if (!in_array('swimming', $tables, true)) {
    die("swimming table was not found.");
}

// ============================================================
// GET ORDER TABLE COLUMNS
// ============================================================

$orderColumns = [];

$result = pg_query(
    $pgConnection,
    "
    SELECT column_name
    FROM information_schema.columns
    WHERE table_schema = 'public'
    AND table_name = 'restaurant_orders'
    "
);

if ($result) {
    while ($row = pg_fetch_assoc($result)) {
        $orderColumns[] = $row['column_name'];
    }
}

// ============================================================
// GET ORDER
// ============================================================

$orderSql = "
    SELECT *
    FROM restaurant_orders
    WHERE id = $1
    LIMIT 1
";

$orderResult = pg_query_params(
    $pgConnection,
    $orderSql,
    [$orderId]
);

if (!$orderResult) {
    die(
        "Unable to load order.<br><br>" .
        e(pg_last_error($pgConnection))
    );
}

$order = pg_fetch_assoc($orderResult);

if (!$order) {
    die("Food order not found.");
}

// ============================================================
// FIND SWIMMING ID
// ============================================================

$swimmingId = 0;

if (
    isset($order['swimming_id']) &&
    !empty($order['swimming_id'])
) {
    $swimmingId = (int)$order['swimming_id'];
}

// If swimming_id doesn't exist, try booking_id
// to find the swimming record.
if (
    $swimmingId <= 0 &&
    isset($order['booking_id']) &&
    !empty($order['booking_id'])
) {

    $swimResult = pg_query_params(
        $pgConnection,
        "
        SELECT id
        FROM swimming
        WHERE booking_id = $1
        ORDER BY id DESC
        LIMIT 1
        ",
        [
            (int)$order['booking_id']
        ]
    );

    if ($swimResult) {

        $swimRow = pg_fetch_assoc($swimResult);

        if ($swimRow) {
            $swimmingId = (int)$swimRow['id'];
        }
    }
}

// ============================================================
// GET SWIMMING INFORMATION
// ============================================================

$swimmer = null;

if ($swimmingId > 0) {

    $swimmerResult = pg_query_params(
        $pgConnection,
        "
        SELECT
            s.*,
            g.full_name AS guest_name,
            g.phone AS guest_phone,
            g.email AS guest_email
        FROM swimming s
        LEFT JOIN guests g
            ON s.guest_id = g.id
        WHERE s.id = $1
        LIMIT 1
        ",
        [$swimmingId]
    );

    if ($swimmerResult) {
        $swimmer =
            pg_fetch_assoc($swimmerResult);
    }
}

// ============================================================
// GET ORDER ITEM COLUMNS
// ============================================================

$itemColumns = [];

$result = pg_query(
    $pgConnection,
    "
    SELECT column_name
    FROM information_schema.columns
    WHERE table_schema = 'public'
    AND table_name = 'restaurant_order_items'
    "
);

if ($result) {
    while ($row = pg_fetch_assoc($result)) {
        $itemColumns[] = $row['column_name'];
    }
}

// ============================================================
// DETECT ORDER ID COLUMN
// ============================================================

$orderIdColumn = null;

if (
    in_array('order_id', $itemColumns, true)
) {
    $orderIdColumn = 'order_id';
} elseif (
    in_array('restaurant_order_id', $itemColumns, true)
) {
    $orderIdColumn = 'restaurant_order_id';
}

if (!$orderIdColumn) {
    die(
        "Order ID column was not found in restaurant_order_items."
    );
}

// ============================================================
// DETECT FOOD ID COLUMN
// ============================================================

$foodIdColumn = null;

$foodIdCandidates = [
    'food_id',
    'product_id',
    'item_id'
];

foreach ($foodIdCandidates as $candidate) {

    if (
        in_array(
            $candidate,
            $itemColumns,
            true
        )
    ) {
        $foodIdColumn = $candidate;
        break;
    }
}

if (!$foodIdColumn) {
    die(
        "Food/Product ID column was not found in " .
        "restaurant_order_items."
    );
}

// ============================================================
// DETECT QUANTITY COLUMN
// ============================================================

$quantityColumn = null;

foreach (
    ['quantity', 'qty']
    as $candidate
) {

    if (
        in_array(
            $candidate,
            $itemColumns,
            true
        )
    ) {
        $quantityColumn = $candidate;
        break;
    }
}

if (!$quantityColumn) {
    die(
        "Quantity column was not found."
    );
}

// ============================================================
// DETECT PRICE COLUMN
// ============================================================

$priceColumn = null;

foreach (
    [
        'price',
        'unit_price',
        'selling_price',
        'rate'
    ]
    as $candidate
) {

    if (
        in_array(
            $candidate,
            $itemColumns,
            true
        )
    ) {
        $priceColumn = $candidate;
        break;
    }
}

if (!$priceColumn) {
    die(
        "Price column was not found."
    );
}

// ============================================================
// DETECT ITEM TOTAL COLUMN
// ============================================================

$itemTotalColumn = null;

foreach (
    [
        'total',
        'total_price',
        'subtotal',
        'amount'
    ]
    as $candidate
) {

    if (
        in_array(
            $candidate,
            $itemColumns,
            true
        )
    ) {
        $itemTotalColumn = $candidate;
        break;
    }
}

// ============================================================
// GET FOOD TABLE COLUMNS
// ============================================================

$foodColumns = [];

$result = pg_query(
    $pgConnection,
    "
    SELECT column_name
    FROM information_schema.columns
    WHERE table_schema = 'public'
    AND table_name = 'restaurant_foods'
    "
);

if ($result) {
    while ($row = pg_fetch_assoc($result)) {
        $foodColumns[] = $row['column_name'];
    }
}

// ============================================================
// DETECT FOOD NAME
// ============================================================

$foodNameColumn = null;

foreach (
    [
        'food_name',
        'name',
        'item_name',
        'product_name',
        'title'
    ]
    as $candidate
) {

    if (
        in_array(
            $candidate,
            $foodColumns,
            true
        )
    ) {
        $foodNameColumn = $candidate;
        break;
    }
}

if (!$foodNameColumn) {
    die(
        "Food name column was not found."
    );
}

// ============================================================
// GET ORDER ITEMS
// ============================================================

$items = [];

$itemSelect = "
    SELECT
        oi.{$quantityColumn} AS quantity,
        oi.{$priceColumn} AS unit_price,
        f.{$foodNameColumn} AS item_name
";

if ($itemTotalColumn) {

    $itemSelect .= ",
        oi.{$itemTotalColumn} AS item_total
    ";

} else {

    $itemSelect .= ",
        (
            oi.{$quantityColumn} *
            oi.{$priceColumn}
        ) AS item_total
    ";
}

$itemSelect .= "
    FROM restaurant_order_items oi
    LEFT JOIN restaurant_foods f
        ON f.id = oi.{$foodIdColumn}
    WHERE oi.{$orderIdColumn} = $1
    ORDER BY oi.id ASC
";

$itemResult = pg_query_params(
    $pgConnection,
    $itemSelect,
    [$orderId]
);

if ($itemResult) {

    while ($row = pg_fetch_assoc($itemResult)) {

        $items[] = $row;
    }
}

// ============================================================
// CALCULATE SUBTOTAL FROM ITEMS
// ============================================================

$calculatedSubtotal = 0;

foreach ($items as $item) {

    $calculatedSubtotal +=
        (float)$item['item_total'];
}

$calculatedSubtotal =
    round($calculatedSubtotal, 2);

// ============================================================
// USE SAVED ORDER VALUES
// ============================================================

$subtotal = isset($order['subtotal'])
    ? (float)$order['subtotal']
    : $calculatedSubtotal;

if ($subtotal <= 0 && $calculatedSubtotal > 0) {
    $subtotal = $calculatedSubtotal;
}

// ============================================================
// VAT
// ============================================================

$vatAmount = isset($order['vat_amount'])
    ? (float)$order['vat_amount']
    : round($subtotal * 0.13, 2);

// ============================================================
// FOOD ORDER TOTAL
// ============================================================

$foodOrderTotal = isset($order['total_amount'])
    ? (float)$order['total_amount']
    : round($subtotal + $vatAmount, 2);

// ============================================================
// SWIMMING CHARGE
// ============================================================

$swimmingCharge = 0;

if ($swimmer) {

    $swimmingCharge =
        (float)($swimmer['total_amount'] ?? 0);
}

// ============================================================
// COMBINED TOTAL
// ============================================================

$combinedTotal =
    round(
        $swimmingCharge +
        $foodOrderTotal,
        2
    );

// ============================================================
// CUSTOMER NAME
// ============================================================

$customerName =
    $order['customer_name'] ?? '';

if (
    trim($customerName) === '' &&
    $swimmer
) {

    $customerName =
        $swimmer['visitor_name'] ??
        '';

    if (
        trim($customerName) === ''
    ) {
        $customerName =
            $swimmer['guest_name'] ??
            'Swimming Guest';
    }
}

if (trim($customerName) === '') {
    $customerName = 'Swimming Guest';
}

// ============================================================
// PAYMENT METHOD
// ============================================================

$paymentMethod =
    $order['payment_method'] ??
    'Cash';

// ============================================================
// PAYMENT STATUS
// ============================================================

$paymentStatus =
    $order['payment_status'] ??
    'Paid';

// ============================================================
// DATE
// ============================================================

$orderDate = '';

if (
    !empty($order['created_at'])
) {

    $timestamp =
        strtotime($order['created_at']);

    if ($timestamp) {
        $orderDate =
            date(
                'd M Y, h:i A',
                $timestamp
            );
    }
}

if ($orderDate === '') {
    $orderDate =
        date(
            'd M Y, h:i A'
        );
}

// ============================================================
// SWIMMING DATE
// ============================================================

$swimmingDate = '';

if (
    $swimmer &&
    !empty($swimmer['visit_date'])
) {
    $swimmingDate =
        date(
            'd M Y',
            strtotime(
                $swimmer['visit_date']
            )
        );
}

// ============================================================
// BILL NUMBER
// ============================================================

$billNumber =
    'SWF-' .
    str_pad(
        $orderId,
        6,
        '0',
        STR_PAD_LEFT
    );

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
        <?php echo e($billNumber); ?> - Suva Hotel
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #eef2f7;
            color: #1e293b;
            font-family:
                Arial,
                Helvetica,
                sans-serif;
        }

        .toolbar {
            background: #0f172a;
            padding: 14px 20px;
            display: flex;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .toolbar a,
        .toolbar button {
            border: 0;
            text-decoration: none;
            padding: 10px 17px;
            border-radius: 7px;
            cursor: pointer;
            font-weight: 700;
            font-size: 14px;
        }

        .print-btn {
            background: #16a34a;
            color: white;
        }

        .back-btn {
            background: #334155;
            color: white;
        }

        .bill {
            width: 800px;
            max-width: calc(100% - 30px);
            margin: 25px auto;
            background: white;
            padding: 40px;
            box-shadow:
                0 8px 30px
                rgba(15, 23, 42, .12);
        }

        .hotel-header {
            text-align: center;
            border-bottom: 2px solid #0f766e;
            padding-bottom: 20px;
        }

        .hotel-name {
            font-size: 31px;
            font-weight: 800;
            letter-spacing: 1px;
            color: #0f766e;
        }

        .hotel-subtitle {
            margin-top: 5px;
            color: #64748b;
            font-size: 14px;
        }

        .bill-title {
            margin-top: 18px;
            font-size: 21px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .bill-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin: 25px 0;
        }

        .info-box {
            background: #f8fafc;
            padding: 15px;
            border-radius: 9px;
            border: 1px solid #e2e8f0;
        }

        .info-label {
            color: #64748b;
            font-size: 11px;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .info-value {
            font-weight: 700;
            font-size: 14px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .table th {
            background: #0f766e;
            color: white;
            text-align: left;
            padding: 11px;
            font-size: 13px;
        }

        .table td {
            padding: 11px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 13px;
        }

        .table th:last-child,
        .table td:last-child {
            text-align: right;
        }

        .table th:nth-child(2),
        .table td:nth-child(2),
        .table th:nth-child(3),
        .table td:nth-child(3) {
            text-align: center;
        }

        .section-heading {
            margin-top: 28px;
            margin-bottom: 8px;
            font-size: 16px;
            font-weight: 800;
            color: #0f766e;
        }

        .summary {
            width: 360px;
            max-width: 100%;
            margin-left: auto;
            margin-top: 25px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 14px;
        }

        .summary-row.food {
            border-top: 1px solid #e2e8f0;
            margin-top: 5px;
            padding-top: 13px;
        }

        .summary-row.total {
            border-top: 2px solid #0f766e;
            margin-top: 8px;
            padding-top: 13px;
            font-size: 20px;
            font-weight: 800;
            color: #0f766e;
        }

        .payment-box {
            margin-top: 25px;
            padding: 15px;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 9px;
            display: flex;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
        }

        .paid {
            color: #15803d;
            font-weight: 800;
        }

        .notes {
            margin-top: 25px;
            padding: 15px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 9px;
            font-size: 13px;
        }

        .footer {
            text-align: center;
            border-top: 1px solid #e2e8f0;
            margin-top: 30px;
            padding-top: 20px;
            color: #64748b;
            font-size: 12px;
        }

        .no-items {
            text-align: center;
            padding: 25px;
            color: #64748b;
            border: 1px dashed #cbd5e1;
        }

        @media (max-width: 650px) {

            .bill {
                padding: 20px;
            }

            .bill-info {
                grid-template-columns: 1fr;
            }

            .hotel-name {
                font-size: 25px;
            }

            .table th,
            .table td {
                padding: 8px 5px;
                font-size: 11px;
            }
        }

        @media print {

            @page {
                size: A4;
                margin: 12mm;
            }

            body {
                background: white;
            }

            .toolbar {
                display: none;
            }

            .bill {
                width: 100%;
                max-width: none;
                margin: 0;
                padding: 0;
                box-shadow: none;
            }

            .hotel-header {
                padding-top: 10px;
            }

            .table th {
                background: #0f766e !important;
                color: white !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

        }

    </style>

</head>

<body>

<!-- ========================================================
     TOOLBAR
========================================================= -->

<div class="toolbar">

    <button
        type="button"
        class="print-btn"
        onclick="window.print()"
    >
        🖨 Print Bill
    </button>

    <a
        href="order.php?swimming_id=<?php echo e($swimmingId); ?>"
        class="back-btn"
    >
        ← New Order
    </a>

    <a
        href="view.php"
        class="back-btn"
    >
        Swimming Records
    </a>

</div>


<!-- ========================================================
     BILL
========================================================= -->

<div class="bill">

    <!-- HOTEL HEADER -->

    <div class="hotel-header">

        <div class="hotel-name">
            SUVA HOTEL
        </div>

        <div class="hotel-subtitle">
            Hotel Management System
        </div>

        <div class="hotel-subtitle">
            Swimming Pool Food & Drinks
        </div>

        <div class="bill-title">
            Food & Drinks Bill
        </div>

    </div>


    <!-- BILL INFORMATION -->

    <div class="bill-info">

        <div class="info-box">

            <div class="info-label">
                Bill Number
            </div>

            <div class="info-value">
                <?php echo e($billNumber); ?>
            </div>

        </div>


        <div class="info-box">

            <div class="info-label">
                Order Number
            </div>

            <div class="info-value">
                #<?php echo e($orderId); ?>
            </div>

        </div>


        <div class="info-box">

            <div class="info-label">
                Swimmer / Guest
            </div>

            <div class="info-value">
                <?php echo e($customerName); ?>
            </div>

        </div>


        <div class="info-box">

            <div class="info-label">
                Order Date
            </div>

            <div class="info-value">
                <?php echo e($orderDate); ?>
            </div>

        </div>


        <?php if ($swimmer): ?>

            <div class="info-box">

                <div class="info-label">
                    Swimming Date
                </div>

                <div class="info-value">
                    <?php echo e($swimmingDate); ?>
                </div>

            </div>


            <div class="info-box">

                <div class="info-label">
                    Swimming ID
                </div>

                <div class="info-value">
                    #<?php echo e($swimmingId); ?>
                </div>

            </div>

        <?php endif; ?>

    </div>


    <!-- FOOD ITEMS -->

    <div class="section-heading">
        🍔 Food & Drinks
    </div>


    <?php if (empty($items)): ?>

        <div class="no-items">
            No food or drink items found for this order.
        </div>

    <?php else: ?>

        <table class="table">

            <thead>

                <tr>

                    <th>
                        Item
                    </th>

                    <th>
                        Qty
                    </th>

                    <th>
                        Rate
                    </th>

                    <th>
                        Amount
                    </th>

                </tr>

            </thead>

            <tbody>

                <?php foreach ($items as $item): ?>

                    <tr>

                        <td>
                            <?php
                            echo e(
                                $item['item_name']
                                ?: 'Food Item'
                            );
                            ?>
                        </td>

                        <td>
                            <?php
                            echo e(
                                $item['quantity']
                            );
                            ?>
                        </td>

                        <td>
                            Rs.
                            <?php
                            echo number_format(
                                (float)$item['unit_price'],
                                2
                            );
                            ?>
                        </td>

                        <td>
                            Rs.
                            <?php
                            echo number_format(
                                (float)$item['item_total'],
                                2
                            );
                            ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    <?php endif; ?>


    <!-- SUMMARY -->

    <div class="summary">

        <div class="summary-row">

            <span>
                Food & Drinks Subtotal
            </span>

            <strong>
                Rs.
                <?php
                echo number_format(
                    $subtotal,
                    2
                );
                ?>
            </strong>

        </div>


        <div class="summary-row">

            <span>
                VAT (13%)
            </span>

            <strong>
                Rs.
                <?php
                echo number_format(
                    $vatAmount,
                    2
                );
                ?>
            </strong>

        </div>


        <div class="summary-row food">

            <span>
                Food Order Total
            </span>

            <strong>
                Rs.
                <?php
                echo number_format(
                    $foodOrderTotal,
                    2
                );
                ?>
            </strong>

        </div>


        <div class="summary-row">

            <span>
                Swimming Charge
            </span>

            <strong>
                Rs.
                <?php
                echo number_format(
                    $swimmingCharge,
                    2
                );
                ?>
            </strong>

        </div>


        <div class="summary-row total">

            <span>
                Grand Total
            </span>

            <strong>
                Rs.
                <?php
                echo number_format(
                    $combinedTotal,
                    2
                );
                ?>
            </strong>

        </div>

    </div>


    <!-- PAYMENT -->

    <div class="payment-box">

        <div>

            <strong>
                Payment Method
            </strong>

            <br>

            <?php echo e($paymentMethod); ?>

        </div>


        <div>

            <strong>
                Payment Status
            </strong>

            <br>

            <span class="paid">
                <?php echo e($paymentStatus); ?>
            </span>

        </div>

    </div>


    <!-- NOTES -->

    <?php if (
        isset($order['notes']) &&
        trim($order['notes']) !== ''
    ): ?>

        <div class="notes">

            <strong>
                Order Note:
            </strong>

            <br>

            <?php
            echo nl2br(
                e($order['notes'])
            );
            ?>

        </div>

    <?php endif; ?>


    <!-- FOOTER -->

    <div class="footer">

        <strong>
            Thank you for choosing Suva Hotel!
        </strong>

        <br><br>

        Swimming Pool Food & Drinks Bill

        <br>

        Bill:
        <?php echo e($billNumber); ?>

    </div>

</div>

</body>

</html>
```
