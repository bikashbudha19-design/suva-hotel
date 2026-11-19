<?php
// ============================================================
// SUVA HOTEL - RESTAURANT ORDER EDIT
// PostgreSQL Version
// File: restaurant/edit.php
// ============================================================

session_start();

require_once __DIR__ . '/../config/database.php';

// ------------------------------------------------------------
// LOGIN PROTECTION
// ------------------------------------------------------------
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// ------------------------------------------------------------
// CHECK DATABASE CONNECTION
// ------------------------------------------------------------
if (!isset($pgConnection) || !$pgConnection) {
    die("Database connection failed.");
}

// ------------------------------------------------------------
// HELPER FUNCTIONS
// ------------------------------------------------------------
function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function columnExists($connection, $table, $column)
{
    $sql = "
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = $1
          AND column_name = $2
        LIMIT 1
    ";

    $result = pg_query_params($connection, $sql, [$table, $column]);

    return $result && pg_num_rows($result) > 0;
}

function getFoodImage($image)
{
    $image = trim((string)$image);

    if ($image === '') {
        return '../assets/images/food-default.jpg';
    }

    $file = basename($image);
    $fullPath = __DIR__ . '/../assets/images/' . $file;

    if (!file_exists($fullPath)) {
        return '../assets/images/food-default.jpg';
    }

    return '../assets/images/' . rawurlencode($file);
}

// ------------------------------------------------------------
// GET ORDER ID
// ------------------------------------------------------------
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id <= 0) {
    die("Invalid Order ID.");
}

// ------------------------------------------------------------
// DETECT ORDER ITEM FOOD COLUMN
// Supports both food_id and product_id
// ------------------------------------------------------------
if (columnExists($pgConnection, 'restaurant_order_items', 'food_id')) {
    $itemColumn = 'food_id';
} elseif (columnExists($pgConnection, 'restaurant_order_items', 'product_id')) {
    $itemColumn = 'product_id';
} else {
    die("Error: restaurant_order_items table has neither food_id nor product_id column.");
}

// ------------------------------------------------------------
// LOAD ORDER
// ------------------------------------------------------------
$orderSQL = "
    SELECT
        ro.id,
        ro.customer_name,
        ro.order_type,
        ro.customer_type,
        ro.room_id,
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
    WHERE ro.id = $1
    LIMIT 1
";

$orderResult = pg_query_params($pgConnection, $orderSQL, [$id]);

if (!$orderResult) {
    die("Unable to load order: " . e(pg_last_error($pgConnection)));
}

if (pg_num_rows($orderResult) === 0) {
    die("Restaurant order not found.");
}

$order = pg_fetch_assoc($orderResult);

// ------------------------------------------------------------
// LOAD ROOMS
// ------------------------------------------------------------
$rooms = [];

$roomSQL = "
    SELECT id, room_number
    FROM rooms
    ORDER BY room_number ASC
";

$roomResult = pg_query($pgConnection, $roomSQL);

if ($roomResult) {
    while ($row = pg_fetch_assoc($roomResult)) {
        $rooms[] = $row;
    }
}

// ------------------------------------------------------------
// LOAD FOOD ITEMS
// ------------------------------------------------------------
// Include available foods plus foods already used in this order.
// This prevents an old/unavailable food from disappearing while editing.
// ------------------------------------------------------------
$foodSQL = "
    SELECT
        id,
        name,
        category,
        price,
        image,
        status
    FROM restaurant_items
    WHERE
        LOWER(COALESCE(status, '')) = 'available'
        OR id IN (
            SELECT {$itemColumn}
            FROM restaurant_order_items
            WHERE order_id = $1
        )
    ORDER BY category ASC, name ASC
";

$foodResult = pg_query_params($pgConnection, $foodSQL, [$id]);

if (!$foodResult) {
    die("Unable to load restaurant menu: " . e(pg_last_error($pgConnection)));
}

$foods = [];

while ($row = pg_fetch_assoc($foodResult)) {
    $foods[(int)$row['id']] = $row;
}

// ------------------------------------------------------------
// LOAD CURRENT ORDER ITEMS
// ------------------------------------------------------------
$itemSQL = "
    SELECT
        id,
        {$itemColumn} AS item_id,
        quantity,
        unit_price,
        total_price
    FROM restaurant_order_items
    WHERE order_id = $1
    ORDER BY id ASC
";

$itemResult = pg_query_params($pgConnection, $itemSQL, [$id]);

if (!$itemResult) {
    die("Unable to load order items: " . e(pg_last_error($pgConnection)));
}

$currentItems = [];

while ($row = pg_fetch_assoc($itemResult)) {
    $itemId = (int)$row['item_id'];

    $currentItems[$itemId] = [
        'quantity' => (int)$row['quantity'],
        'unit_price' => (float)$row['unit_price'],
        'total_price' => (float)$row['total_price']
    ];
}

// ------------------------------------------------------------
// FORM DEFAULTS
// ------------------------------------------------------------
$customerName = $order['customer_name'] ?? '';
$orderType = $order['order_type'] ?? 'Dine-In';
$customerType = $order['customer_type'] ?? 'Walk-In';
$roomId = $order['room_id'] ?? '';
$orderPeriod = $order['order_period'] ?? 'Day';
$paymentMethod = $order['payment_method'] ?? 'Cash';
$orderStatus = $order['order_status'] ?? 'Pending';

$error = '';

// ------------------------------------------------------------
// POST - UPDATE ORDER
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $customerName = trim($_POST['customer_name'] ?? '');
    $orderType = trim($_POST['order_type'] ?? 'Dine-In');
    $customerType = trim($_POST['customer_type'] ?? 'Walk-In');
    $roomId = trim($_POST['room_id'] ?? '');
    $orderPeriod = trim($_POST['order_period'] ?? 'Day');
    $paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
    $orderStatus = trim($_POST['order_status'] ?? 'Pending');

    $selectedIds = $_POST['item_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];

    // --------------------------------------------------------
    // VALIDATION
    // --------------------------------------------------------
    if ($customerName === '') {
        $error = "Please enter the customer name.";
    }

    // Hotel guest must have room
    if (
        $error === '' &&
        strtolower($customerType) === 'hotel guest' &&
        ($roomId === '' || !ctype_digit((string)$roomId))
    ) {
        $error = "Please select a room for the hotel guest.";
    }

    // Validate order type
    $allowedOrderTypes = ['Dine-In', 'Takeaway'];

    if ($error === '' && !in_array($orderType, $allowedOrderTypes, true)) {
        $error = "Invalid order type.";
    }

    // Validate customer type
    $allowedCustomerTypes = ['Walk-In', 'Hotel Guest'];

    if ($error === '' && !in_array($customerType, $allowedCustomerTypes, true)) {
        $error = "Invalid customer type.";
    }

    // Validate period
    $allowedPeriods = ['Day', 'Night'];

    if ($error === '' && !in_array($orderPeriod, $allowedPeriods, true)) {
        $error = "Invalid order period.";
    }

    // Validate payment method
    $allowedPaymentMethods = ['Cash', 'QR', 'Card', 'Room Bill'];

    if ($error === '' && !in_array($paymentMethod, $allowedPaymentMethods, true)) {
        $error = "Invalid payment method.";
    }

    // Validate order status
    $allowedStatuses = [
        'Pending',
        'Preparing',
        'Ready',
        'Completed',
        'Cancelled'
    ];

    if ($error === '' && !in_array($orderStatus, $allowedStatuses, true)) {
        $error = "Invalid order status.";
    }

    // --------------------------------------------------------
    // BUILD ORDER ITEMS
    // --------------------------------------------------------
    $newItems = [];
    $subtotal = 0;

    if ($error === '') {

        if (!is_array($selectedIds)) {
            $selectedIds = [];
        }

        if (!is_array($quantities)) {
            $quantities = [];
        }

        foreach ($selectedIds as $index => $rawItemId) {

            $itemId = filter_var(
                $rawItemId,
                FILTER_VALIDATE_INT
            );

            if (!$itemId || !isset($foods[$itemId])) {
                continue;
            }

            $qty = isset($quantities[$index])
                ? (int)$quantities[$index]
                : 0;

            if ($qty <= 0) {
                continue;
            }

            $price = (float)$foods[$itemId]['price'];
            $lineTotal = round($price * $qty, 2);

            $newItems[] = [
                'item_id' => $itemId,
                'quantity' => $qty,
                'unit_price' => $price,
                'total_price' => $lineTotal
            ];

            $subtotal += $lineTotal;
        }

        $subtotal = round($subtotal, 2);

        if (count($newItems) === 0) {
            $error = "Please select at least one food item.";
        }
    }

    // --------------------------------------------------------
    // CALCULATE VAT
    // --------------------------------------------------------
    $vatAmount = round($subtotal * 0.13, 2);
    $totalAmount = round($subtotal + $vatAmount, 2);

    // --------------------------------------------------------
    // PAYMENT STATUS
    // --------------------------------------------------------
    if ($paymentMethod === 'Cash' ||
        $paymentMethod === 'QR' ||
        $paymentMethod === 'Card') {

        $paymentStatus = 'Paid';

    } elseif ($paymentMethod === 'Room Bill') {

        $paymentStatus = 'Added to Room Bill';

    } else {

        $paymentStatus = 'Pending';
    }

    // --------------------------------------------------------
    // UPDATE DATABASE
    // --------------------------------------------------------
    if ($error === '') {

        $transactionStarted = false;

        try {

            // BEGIN
            if (!pg_query($pgConnection, "BEGIN")) {
                throw new Exception(
                    "Could not start database transaction."
                );
            }

            $transactionStarted = true;

            // Convert empty room to NULL
            $roomValue = null;

            if ($roomId !== '' && ctype_digit((string)$roomId)) {
                $roomValue = (int)$roomId;
            }

            // ------------------------------------------------
            // UPDATE MAIN ORDER
            // ------------------------------------------------
            $updateSQL = "
                UPDATE restaurant_orders
                SET
                    customer_name = $1,
                    order_type = $2,
                    customer_type = $3,
                    room_id = $4,
                    order_period = $5,
                    subtotal = $6,
                    vat_amount = $7,
                    total_amount = $8,
                    payment_method = $9,
                    payment_status = $10,
                    order_status = $11
                WHERE id = $12
            ";

            $updateParams = [
                $customerName,
                $orderType,
                $customerType,
                $roomValue,
                $orderPeriod,
                $subtotal,
                $vatAmount,
                $totalAmount,
                $paymentMethod,
                $paymentStatus,
                $orderStatus,
                $id
            ];

            $updateResult = pg_query_params(
                $pgConnection,
                $updateSQL,
                $updateParams
            );

            if (!$updateResult) {
                throw new Exception(
                    "Failed to update order: " .
                    pg_last_error($pgConnection)
                );
            }

            // ------------------------------------------------
            // DELETE OLD ORDER ITEMS
            // ------------------------------------------------
            $deleteItemsSQL = "
                DELETE FROM restaurant_order_items
                WHERE order_id = $1
            ";

            $deleteItemsResult = pg_query_params(
                $pgConnection,
                $deleteItemsSQL,
                [$id]
            );

            if (!$deleteItemsResult) {
                throw new Exception(
                    "Failed to remove old order items: " .
                    pg_last_error($pgConnection)
                );
            }

            // ------------------------------------------------
            // INSERT UPDATED ORDER ITEMS
            // ------------------------------------------------
            foreach ($newItems as $item) {

                $insertItemSQL = "
                    INSERT INTO restaurant_order_items
                    (
                        order_id,
                        {$itemColumn},
                        quantity,
                        unit_price,
                        total_price
                    )
                    VALUES
                    (
                        $1,
                        $2,
                        $3,
                        $4,
                        $5
                    )
                ";

                $insertItemParams = [
                    $id,
                    $item['item_id'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['total_price']
                ];

                $insertItemResult = pg_query_params(
                    $pgConnection,
                    $insertItemSQL,
                    $insertItemParams
                );

                if (!$insertItemResult) {
                    throw new Exception(
                        "Failed to save food item: " .
                        pg_last_error($pgConnection)
                    );
                }
            }

            // ------------------------------------------------
            // COMMIT
            // ------------------------------------------------
            if (!pg_query($pgConnection, "COMMIT")) {
                throw new Exception(
                    "Failed to commit changes."
                );
            }

            $transactionStarted = false;

            // ------------------------------------------------
            // SUCCESS REDIRECT
            // ------------------------------------------------
            header(
                "Location: view.php?updated=1"
            );
            exit;

        } catch (Throwable $e) {

            if ($transactionStarted) {
                pg_query($pgConnection, "ROLLBACK");
            }

            $error = $e->getMessage();
        }
    }
}

// ------------------------------------------------------------
// CURRENT SELECTED ITEMS FOR DISPLAY
// ------------------------------------------------------------
$selectedForJS = [];

foreach ($currentItems as $itemId => $item) {
    $selectedForJS[(string)$itemId] = (int)$item['quantity'];
}

?>

<!DOCTYPE html>

<html lang="en">
<head>

```
<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Edit Restaurant Order #<?= e($id) ?> | Suva Hotel</title>

<link
    rel="preconnect"
    href="https://fonts.googleapis.com"
>

<link
    rel="preconnect"
    href="https://fonts.gstatic.com"
    crossorigin
>

<link
    href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
>

<style>

    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    body {
        font-family: 'Poppins', sans-serif;
        min-height: 100vh;
        background:
            radial-gradient(
                circle at 10% 10%,
                rgba(255, 214, 102, 0.20),
                transparent 28%
            ),
            radial-gradient(
                circle at 90% 20%,
                rgba(255, 179, 71, 0.16),
                transparent 30%
            ),
            linear-gradient(
                135deg,
                #fffdf7,
                #fff7e8,
                #fffdf8
            );
        color: #2b2118;
    }

    body::before {
        content: "";
        position: fixed;
        width: 300px;
        height: 300px;
        border-radius: 50%;
        background: rgba(255, 193, 7, 0.12);
        filter: blur(80px);
        top: 5%;
        left: -100px;
        pointer-events: none;
        z-index: -1;
    }

    body::after {
        content: "";
        position: fixed;
        width: 350px;
        height: 350px;
        border-radius: 50%;
        background: rgba(255, 145, 0, 0.10);
        filter: blur(100px);
        bottom: 0;
        right: -100px;
        pointer-events: none;
        z-index: -1;
    }

    .topbar {
        position: sticky;
        top: 0;
        z-index: 1000;
        background: rgba(39, 27, 17, 0.94);
        backdrop-filter: blur(16px);
        color: white;
        padding: 16px 5%;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-shadow: 0 8px 30px rgba(55, 35, 10, 0.20);
    }

    .brand {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .brand-icon {
        width: 44px;
        height: 44px;
        border-radius: 13px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(
            135deg,
            #ffc107,
            #ff9800
        );
        color: #2b1c0d;
        box-shadow:
            0 0 25px rgba(255, 193, 7, 0.35);
    }

    .brand h1 {
        font-size: 19px;
        letter-spacing: 1px;
    }

    .brand span {
        display: block;
        color: #ffd87a;
        font-size: 11px;
        margin-top: 2px;
    }

    .back-btn {
        text-decoration: none;
        color: white;
        border: 1px solid rgba(255,255,255,0.20);
        padding: 10px 17px;
        border-radius: 12px;
        transition: 0.25s;
        font-size: 14px;
    }

    .back-btn:hover {
        background: rgba(255,255,255,0.10);
        transform: translateY(-2px);
    }

    .container {
        width: min(1450px, 94%);
        margin: 35px auto 60px;
    }

    .page-heading {
        margin-bottom: 25px;
    }

    .page-heading h2 {
        font-size: clamp(25px, 3vw, 38px);
        font-weight: 800;
        color: #3a2718;
    }

    .page-heading p {
        color: #7b6b5b;
        margin-top: 5px;
    }

    .alert {
        padding: 15px 18px;
        border-radius: 14px;
        margin-bottom: 22px;
        background: #fff0f0;
        color: #b42318;
        border: 1px solid #f5c2c0;
        box-shadow: 0 8px 25px rgba(180, 35, 24, 0.08);
    }

    .layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 380px;
        gap: 25px;
        align-items: start;
    }

    .card {
        background: rgba(255,255,255,0.88);
        border: 1px solid rgba(166, 120, 50, 0.13);
        border-radius: 22px;
        box-shadow:
            0 15px 50px rgba(78, 49, 14, 0.09),
            0 0 40px rgba(255, 193, 7, 0.05);
        padding: 25px;
        backdrop-filter: blur(12px);
    }

    .card + .card {
        margin-top: 22px;
    }

    .section-title {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 19px;
        font-weight: 700;
        margin-bottom: 20px;
        color: #4a2d16;
    }

    .section-title i {
        color: #e69b00;
    }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 18px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 7px;
    }

    .form-group.full {
        grid-column: 1 / -1;
    }

    label {
        font-size: 13px;
        font-weight: 600;
        color: #594737;
    }

    input,
    select {
        width: 100%;
        border: 1px solid #e6d8c5;
        background: #fffdf9;
        border-radius: 12px;
        padding: 13px 14px;
        font-family: inherit;
        font-size: 14px;
        color: #34271d;
        outline: none;
        transition: 0.2s;
    }

    input:focus,
    select:focus {
        border-color: #f0ad00;
        box-shadow:
            0 0 0 4px rgba(240, 173, 0, 0.10);
    }

    .menu-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
        margin-bottom: 18px;
    }

    .menu-search {
        max-width: 300px;
    }

    .menu-grid {
        display: grid;
        grid-template-columns:
            repeat(auto-fill, minmax(220px, 1fr));
        gap: 17px;
    }

    .food-card {
        position: relative;
        overflow: hidden;
        border: 1px solid #eadcc9;
        border-radius: 17px;
        background: #fffdf9;
        transition: 0.28s ease;
    }

    .food-card:hover {
        transform: translateY(-5px);
        box-shadow:
            0 16px 35px rgba(91, 57, 13, 0.13),
            0 0 25px rgba(255, 193, 7, 0.09);
    }

    .food-image {
        width: 100%;
        height: 145px;
        object-fit: cover;
        display: block;
    }

    .food-content {
        padding: 14px;
    }

    .food-category {
        color: #a97924;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        font-weight: 700;
    }

    .food-name {
        font-weight: 700;
        font-size: 16px;
        margin: 4px 0;
    }

    .food-price {
        font-size: 16px;
        font-weight: 800;
        color: #b36b00;
    }

    .food-status {
        position: absolute;
        top: 10px;
        right: 10px;
        padding: 5px 9px;
        border-radius: 20px;
        font-size: 10px;
        font-weight: 700;
        background: rgba(39, 27, 17, 0.78);
        color: white;
    }

    .food-status.available {
        background: rgba(22, 101, 52, 0.88);
    }

    .quantity-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-top: 12px;
        padding-top: 10px;
        border-top: 1px solid #eee2d2;
    }

    .qty-control {
        display: flex;
        align-items: center;
        gap: 7px;
    }

    .qty-btn {
        width: 31px;
        height: 31px;
        border: none;
        border-radius: 9px;
        background: #f6ead7;
        color: #6e430b;
        cursor: pointer;
        font-weight: 800;
        transition: 0.2s;
    }

    .qty-btn:hover {
        background: #ffc107;
        color: #2b1c0d;
    }

    .qty-input {
        width: 45px;
        text-align: center;
        padding: 7px 4px;
        border-radius: 8px;
    }

    .selected {
        border-color: #f1b000;
        box-shadow:
            0 0 0 2px rgba(241, 176, 0, 0.16);
    }

    .summary-card {
        position: sticky;
        top: 95px;
    }

    .order-number {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        background: #fff4d6;
        color: #895900;
        padding: 8px 12px;
        border-radius: 10px;
        font-size: 13px;
        font-weight: 700;
        margin-bottom: 18px;
    }

    .summary-line {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 11px 0;
        border-bottom: 1px dashed #e5d7c5;
        color: #675747;
        font-size: 14px;
    }

    .summary-line strong {
        color: #39291c;
    }

    .summary-total {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-top: 18px;
        padding: 17px;
        border-radius: 15px;
        background:
            linear-gradient(
                135deg,
                #fff2c9,
                #fff9ea
            );
        border: 1px solid #f2d37e;
    }

    .summary-total span {
        font-weight: 700;
    }

    .summary-total strong {
        font-size: 23px;
        color: #a86100;
    }

    .selected-items {
        margin: 18px 0;
        max-height: 250px;
        overflow-y: auto;
    }

    .selected-item {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        padding: 10px 0;
        border-bottom: 1px solid #eee4d6;
        font-size: 13px;
    }

    .selected-item-name {
        font-weight: 600;
    }

    .selected-item-price {
        color: #9b630e;
        font-weight: 700;
        white-space: nowrap;
    }

    .submit-btn {
        width: 100%;
        border: none;
        border-radius: 14px;
        padding: 15px;
        margin-top: 20px;
        cursor: pointer;
        font-family: inherit;
        font-size: 15px;
        font-weight: 800;
        color: #2b1c0d;
        background:
            linear-gradient(
                135deg,
                #ffd45a,
                #ffae00
            );
        box-shadow:
            0 10px 25px rgba(220, 143, 0, 0.20);
        transition: 0.25s;
    }

    .submit-btn:hover {
        transform: translateY(-3px);
        box-shadow:
            0 15px 35px rgba(220, 143, 0, 0.28);
    }

    .cancel-btn {
        display: block;
        text-align: center;
        text-decoration: none;
        width: 100%;
        padding: 13px;
        margin-top: 10px;
        border-radius: 13px;
        color: #694d35;
        background: #f7efe3;
        font-weight: 600;
        font-size: 14px;
    }

    .empty-menu {
        text-align: center;
        padding: 50px 20px;
        color: #8b7967;
    }

    @media (max-width: 1050px) {

        .layout {
            grid-template-columns: 1fr;
        }

        .summary-card {
            position: static;
        }

    }

    @media (max-width: 700px) {

        .form-grid {
            grid-template-columns: 1fr;
        }

        .form-group.full {
            grid-column: auto;
        }

        .menu-header {
            flex-direction: column;
            align-items: stretch;
        }

        .menu-search {
            max-width: none;
        }

        .topbar {
            padding: 14px 4%;
        }

        .brand h1 {
            font-size: 16px;
        }

        .back-btn {
            padding: 8px 11px;
        }

        .card {
            padding: 18px;
        }

    }

</style>
```

</head>

<body>

<!-- ============================================================
     TOP BAR
============================================================ -->

<header class="topbar">

```
<div class="brand">

    <div class="brand-icon">
        <i class="fa-solid fa-utensils"></i>
    </div>

    <div>
        <h1>SUVA HOTEL</h1>
        <span>Restaurant Management</span>
    </div>

</div>

<a
    href="view.php"
    class="back-btn"
>
    <i class="fa-solid fa-arrow-left"></i>
    Back to Orders
</a>
```

</header>

<!-- ============================================================
     MAIN
============================================================ -->

<main class="container">

```
<div class="page-heading">

    <h2>
        <i class="fa-solid fa-pen-to-square"></i>
        Edit Restaurant Order
    </h2>

    <p>
        Update the customer information, food items,
        payment and order status.
    </p>

</div>


<?php if ($error !== ''): ?>

    <div class="alert">
        <i class="fa-solid fa-circle-exclamation"></i>
        <?= e($error) ?>
    </div>

<?php endif; ?>


<form
    method="POST"
    action="edit.php?id=<?= e($id) ?>"
    id="editOrderForm"
>

    <div class="layout">

        <!-- ==================================================
             LEFT SIDE
        ================================================== -->

        <div>

            <!-- CUSTOMER INFORMATION -->

            <section class="card">

                <div class="section-title">
                    <i class="fa-solid fa-user"></i>
                    Customer Information
                </div>

                <div class="form-grid">

                    <div class="form-group full">

                        <label for="customer_name">
                            Customer Name
                        </label>

                        <input
                            type="text"
                            id="customer_name"
                            name="customer_name"
                            value="<?= e($customerName) ?>"
                            placeholder="Enter customer name"
                            required
                        >

                    </div>


                    <div class="form-group">

                        <label for="customer_type">
                            Customer Type
                        </label>

                        <select
                            name="customer_type"
                            id="customer_type"
                        >

                            <option
                                value="Walk-In"
                                <?= $customerType === 'Walk-In' ? 'selected' : '' ?>
                            >
                                Walk-In
                            </option>

                            <option
                                value="Hotel Guest"
                                <?= $customerType === 'Hotel Guest' ? 'selected' : '' ?>
                            >
                                Hotel Guest
                            </option>

                        </select>

                    </div>


                    <div class="form-group">

                        <label for="room_id">
                            Room
                        </label>

                        <select
                            name="room_id"
                            id="room_id"
                        >

                            <option value="">
                                -- No Room --
                            </option>

                            <?php foreach ($rooms as $room): ?>

                                <option
                                    value="<?= e($room['id']) ?>"
                                    <?= (string)$roomId === (string)$room['id'] ? 'selected' : '' ?>
                                >
                                    Room <?= e($room['room_number']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                </div>

            </section>


            <!-- ORDER INFORMATION -->

            <section class="card">

                <div class="section-title">
                    <i class="fa-solid fa-clipboard-list"></i>
                    Order Information
                </div>

                <div class="form-grid">

                    <div class="form-group">

                        <label for="order_type">
                            Order Type
                        </label>

                        <select
                            name="order_type"
                            id="order_type"
                        >

                            <option
                                value="Dine-In"
                                <?= $orderType === 'Dine-In' ? 'selected' : '' ?>
                            >
                                Dine-In
                            </option>

                            <option
                                value="Takeaway"
                                <?= $orderType === 'Takeaway' ? 'selected' : '' ?>
                            >
                                Takeaway
                            </option>

                        </select>

                    </div>


                    <div class="form-group">

                        <label for="order_period">
                            Order Period
                        </label>

                        <select
                            name="order_period"
                            id="order_period"
                        >

                            <option
                                value="Day"
                                <?= $orderPeriod === 'Day' ? 'selected' : '' ?>
                            >
                                Day
                            </option>

                            <option
                                value="Night"
                                <?= $orderPeriod === 'Night' ? 'selected' : '' ?>
                            >
                                Night
                            </option>

                        </select>

                    </div>


                    <div class="form-group">

                        <label for="payment_method">
                            Payment Method
                        </label>

                        <select
                            name="payment_method"
                            id="payment_method"
                        >

                            <option
                                value="Cash"
                                <?= $paymentMethod === 'Cash' ? 'selected' : '' ?>
                            >
                                Cash
                            </option>

                            <option
                                value="QR"
                                <?= $paymentMethod === 'QR' ? 'selected' : '' ?>
                            >
                                QR
                            </option>

                            <option
                                value="Card"
                                <?= $paymentMethod === 'Card' ? 'selected' : '' ?>
                            >
                                Card
                            </option>

                            <option
                                value="Room Bill"
                                <?= $paymentMethod === 'Room Bill' ? 'selected' : '' ?>
                            >
                                Room Bill
                            </option>

                        </select>

                    </div>


                    <div class="form-group">

                        <label for="order_status">
                            Order Status
                        </label>

                        <select
                            name="order_status"
                            id="order_status"
                        >

                            <?php
                            $statuses = [
                                'Pending',
                                'Preparing',
                                'Ready',
                                'Completed',
                                'Cancelled'
                            ];
                            ?>

                            <?php foreach ($statuses as $status): ?>

                                <option
                                    value="<?= e($status) ?>"
                                    <?= $orderStatus === $status ? 'selected' : '' ?>
                                >
                                    <?= e($status) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                </div>

            </section>


            <!-- FOOD MENU -->

            <section class="card">

                <div class="menu-header">

                    <div class="section-title" style="margin-bottom:0;">
                        <i class="fa-solid fa-bowl-food"></i>
                        Food Items
                    </div>

                    <input
                        type="text"
                        id="menuSearch"
                        class="menu-search"
                        placeholder="Search food..."
                    >

                </div>


                <?php if (count($foods) > 0): ?>

                    <div class="menu-grid" id="menuGrid">

                        <?php foreach ($foods as $food): ?>

                            <?php

                            $foodId = (int)$food['id'];

                            $existingQty =
                                $currentItems[$foodId]['quantity']
                                ?? 0;

                            $isAvailable =
                                strtolower(
                                    trim(
                                        (string)($food['status'] ?? '')
                                    )
                                ) === 'available';

                            ?>

                            <div
                                class="food-card <?= $existingQty > 0 ? 'selected' : '' ?>"
                                data-name="<?= e(strtolower($food['name'])) ?>"
                                data-category="<?= e(strtolower($food['category'] ?? '')) ?>"
                            >

                                <span
                                    class="food-status <?= $isAvailable ? 'available' : '' ?>"
                                >
                                    <?= e($food['status'] ?? 'Unavailable') ?>
                                </span>


                                <img
                                    src="<?= e(getFoodImage($food['image'] ?? '')) ?>"
                                    alt="<?= e($food['name']) ?>"
                                    class="food-image"
                                    onerror="this.src='../assets/images/food-default.jpg';"
                                >


                                <div class="food-content">

                                    <div class="food-category">
                                        <?= e($food['category'] ?? 'Food') ?>
                                    </div>

                                    <div class="food-name">
                                        <?= e($food['name']) ?>
                                    </div>

                                    <div class="food-price">
                                        Rs. <?= number_format((float)$food['price'], 2) ?>
                                    </div>


                                    <div class="quantity-row">

                                        <span style="font-size:12px;color:#7a6959;">
                                            Quantity
                                        </span>

                                        <div class="qty-control">

                                            <button
                                                type="button"
                                                class="qty-btn minus"
                                                data-id="<?= $foodId ?>"
                                            >
                                                −
                                            </button>

                                            <input
                                                type="number"
                                                class="qty-input"
                                                name="quantity[]"
                                                value="<?= $existingQty ?>"
                                                min="0"
                                                max="99"
                                                data-id="<?= $foodId ?>"
                                                data-price="<?= e($food['price']) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="item_id[]"
                                                value="<?= $foodId ?>"
                                                class="item-id-input"
                                                data-id="<?= $foodId ?>"
                                            >

                                            <button
                                                type="button"
                                                class="qty-btn plus"
                                                data-id="<?= $foodId ?>"
                                            >
                                                +
                                            </button>

                                        </div>

                                    </div>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <div class="empty-menu">

                        <i
                            class="fa-solid fa-utensils"
                            style="font-size:40px;margin-bottom:15px;"
                        ></i>

                        <p>No restaurant food items found.</p>

                    </div>

                <?php endif; ?>

            </section>

        </div>


        <!-- ==================================================
             RIGHT SIDE - SUMMARY
        ================================================== -->

        <aside class="card summary-card">

            <div class="order-number">

                <i class="fa-solid fa-receipt"></i>

                Order #<?= e($id) ?>

            </div>


            <div class="section-title">

                <i class="fa-solid fa-calculator"></i>

                Order Summary

            </div>


            <div
                class="selected-items"
                id="selectedItems"
            >
            </div>


            <div class="summary-line">

                <span>Subtotal</span>

                <strong id="subtotal">
                    Rs. 0.00
                </strong>

            </div>


            <div class="summary-line">

                <span>VAT (13%)</span>

                <strong id="vat">
                    Rs. 0.00
                </strong>

            </div>


            <div class="summary-total">

                <span>Total</span>

                <strong id="total">
                    Rs. 0.00
                </strong>

            </div>


            <button
                type="submit"
                class="submit-btn"
            >

                <i class="fa-solid fa-floppy-disk"></i>

                Update Order

            </button>


            <a
                href="view.php"
                class="cancel-btn"
            >

                <i class="fa-solid fa-xmark"></i>

                Cancel

            </a>

        </aside>

    </div>

</form>
```

</main>

<script>

    // ========================================================
    // EXISTING SELECTED ITEMS
    // ========================================================

    const existingItems =
        <?= json_encode($selectedForJS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;


    // ========================================================
    // FOOD CARDS
    // ========================================================

    const foodCards =
        document.querySelectorAll('.food-card');

    const quantityInputs =
        document.querySelectorAll('.qty-input');

    const selectedItemsContainer =
        document.getElementById('selectedItems');

    const subtotalElement =
        document.getElementById('subtotal');

    const vatElement =
        document.getElementById('vat');

    const totalElement =
        document.getElementById('total');


    // ========================================================
    // FORMAT MONEY
    // ========================================================

    function money(value)
    {
        return 'Rs. ' + Number(value).toLocaleString(
            'en-IN',
            {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }
        );
    }


    // ========================================================
    // FIND FOOD CARD
    // ========================================================

    function getCard(id)
    {
        const input =
            document.querySelector(
                '.qty-input[data-id="' + id + '"]'
            );

        if (!input) {
            return null;
        }

        return input.closest('.food-card');
    }


    // ========================================================
    // UPDATE SELECTED STATE
    // ========================================================

    function updateCardState(id, quantity)
    {
        const card = getCard(id);

        if (!card) {
            return;
        }

        if (quantity > 0) {
            card.classList.add('selected');
        } else {
            card.classList.remove('selected');
        }
    }


    // ========================================================
    // UPDATE SUMMARY
    // ========================================================

    function updateSummary()
    {
        let subtotal = 0;

        selectedItemsContainer.innerHTML = '';

        quantityInputs.forEach(function(input)
        {
            const quantity =
                parseInt(input.value) || 0;

            const price =
                parseFloat(input.dataset.price) || 0;

            const id =
                input.dataset.id;

            updateCardState(id, quantity);

            if (quantity > 0) {

                const lineTotal =
                    quantity * price;

                subtotal += lineTotal;

                const card =
                    getCard(id);

                if (card) {

                    const name =
                        card.querySelector('.food-name')
                            .textContent
                            .trim();

                    const item =
                        document.createElement('div');

                    item.className =
                        'selected-item';

                    item.innerHTML =
                        '<div>' +
                            '<div class="selected-item-name">' +
                                name +
                            '</div>' +
                            '<div style="color:#8b7967;font-size:11px;">' +
                                quantity +
                                ' × ' +
                                money(price) +
                            '</div>' +
                        '</div>' +
                        '<div class="selected-item-price">' +
                            money(lineTotal) +
                        '</div>';

                    selectedItemsContainer.appendChild(item);
                }
            }
        });


        const vat =
            subtotal * 0.13;

        const total =
            subtotal + vat;


        subtotalElement.textContent =
            money(subtotal);

        vatElement.textContent =
            money(vat);

        totalElement.textContent =
            money(total);


        if (selectedItemsContainer.children.length === 0) {

            selectedItemsContainer.innerHTML =
                '<div style="text-align:center;color:#8b7967;padding:25px 5px;">' +
                    '<i class="fa-solid fa-cart-shopping" style="font-size:25px;margin-bottom:8px;"></i>' +
                    '<div>No food selected</div>' +
                '</div>';
        }
    }


    // ========================================================
    // PLUS BUTTON
    // ========================================================

    document.querySelectorAll('.plus').forEach(function(button)
    {
        button.addEventListener('click', function()
        {
            const id =
                this.dataset.id;

            const input =
                document.querySelector(
                    '.qty-input[data-id="' + id + '"]'
                );

            if (!input) {
                return;
            }

            let value =
                parseInt(input.value) || 0;

            if (value < 99) {
                value++;
            }

            input.value = value;

            updateSummary();
        });
    });


    // ========================================================
    // MINUS BUTTON
    // ========================================================

    document.querySelectorAll('.minus').forEach(function(button)
    {
        button.addEventListener('click', function()
        {
            const id =
                this.dataset.id;

            const input =
                document.querySelector(
                    '.qty-input[data-id="' + id + '"]'
                );

            if (!input) {
                return;
            }

            let value =
                parseInt(input.value) || 0;

            if (value > 0) {
                value--;
            }

            input.value = value;

            updateSummary();
        });
    });


    // ========================================================
    // MANUAL QUANTITY CHANGE
    // ========================================================

    quantityInputs.forEach(function(input)
    {
        input.addEventListener('input', function()
        {
            let value =
                parseInt(this.value) || 0;

            if (value < 0) {
                value = 0;
            }

            if (value > 99) {
                value = 99;
            }

            this.value = value;

            updateSummary();
        });
    });


    // ========================================================
    // SEARCH MENU
    // ========================================================

    const menuSearch =
        document.getElementById('menuSearch');

    if (menuSearch) {

        menuSearch.addEventListener(
            'input',
            function()
            {
                const search =
                    this.value
                        .toLowerCase()
                        .trim();

                foodCards.forEach(function(card)
                {
                    const name =
                        card.dataset.name || '';

                    const category =
                        card.dataset.category || '';

                    const match =
                        name.includes(search) ||
                        category.includes(search);

                    card.style.display =
                        match ? '' : 'none';
                });
            }
        );

    }


    // ========================================================
    // CUSTOMER TYPE / ROOM
    // ========================================================

    const customerType =
        document.getElementById('customer_type');

    const roomSelect =
        document.getElementById('room_id');

    function updateRoomRequirement()
    {
        if (
            customerType &&
            roomSelect
        ) {

            if (
                customerType.value === 'Hotel Guest'
            ) {

                roomSelect.required = true;

            } else {

                roomSelect.required = false;

            }

        }
    }

    if (customerType) {

        customerType.addEventListener(
            'change',
            updateRoomRequirement
        );

    }

    updateRoomRequirement();


    // ========================================================
    // INITIAL SUMMARY
    // ========================================================

    updateSummary();

</script>

</body>
</html>
