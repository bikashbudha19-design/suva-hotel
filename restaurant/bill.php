```php
<?php

session_start();

require_once __DIR__ . '/../config/database.php';

/*
|--------------------------------------------------------------------------
| PostgreSQL CONNECTION CHECK
|--------------------------------------------------------------------------
*/
if (!isset($pgConnection) || !$pgConnection) {
    die("Database connection is not available.");
}

$message = "";
$error = "";

/*
|--------------------------------------------------------------------------
| LOAD RESTAURANT FOOD ITEMS
|--------------------------------------------------------------------------
| Expected table:
| restaurant_items
|
| Columns:
| id
| name
| category
| description
| price
| image
| status
|--------------------------------------------------------------------------
*/

$foods = [];

$sql = "
    SELECT
        id,
        name,
        category,
        description,
        price,
        image,
        status
    FROM restaurant_items
    WHERE LOWER(TRIM(status)) = 'available'
    ORDER BY category ASC, name ASC
";

$result = pg_query($pgConnection, $sql);

if ($result) {
    while ($row = pg_fetch_assoc($result)) {
        $foods[] = $row;
    }
} else {
    $error = "Unable to load restaurant food items: " .
             pg_last_error($pgConnection);
}

/*
|--------------------------------------------------------------------------
| LOAD HOTEL GUESTS
|--------------------------------------------------------------------------
*/

$guests = [];

/*
| Check whether guests table exists
*/
$tableCheckSql = "
    SELECT EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = 'public'
        AND table_name = 'guests'
    ) AS table_exists
";

$tableCheckResult = pg_query(
    $pgConnection,
    $tableCheckSql
);

$guestTableExists = false;

if ($tableCheckResult) {
    $tableRow = pg_fetch_assoc($tableCheckResult);

    $guestTableExists =
        isset($tableRow['table_exists']) &&
        (
            $tableRow['table_exists'] === 't' ||
            $tableRow['table_exists'] === 'true' ||
            $tableRow['table_exists'] === '1'
        );
}

/*
| Detect guest name column
*/
if ($guestTableExists) {

    $guestColumns = [];

    $guestColumnSql = "
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = 'public'
        AND table_name = 'guests'
        ORDER BY ordinal_position
    ";

    $guestColumnResult = pg_query(
        $pgConnection,
        $guestColumnSql
    );

    if ($guestColumnResult) {
        while ($col = pg_fetch_assoc($guestColumnResult)) {
            $guestColumns[] = $col['column_name'];
        }
    }

    $guestId = in_array(
        'id',
        $guestColumns,
        true
    ) ? 'id' : null;

    $guestName = null;

    foreach (
        ['name', 'full_name', 'guest_name']
        as $possible
    ) {
        if (
            in_array(
                $possible,
                $guestColumns,
                true
            )
        ) {
            $guestName = $possible;
            break;
        }
    }

    if ($guestId && $guestName) {

        /*
        | Column names come from our own database metadata,
        | so quoting them is safe.
        */
        $guestSql = "
            SELECT
                \"$guestId\" AS guest_id,
                \"$guestName\" AS guest_name
            FROM guests
            ORDER BY \"$guestName\" ASC
        ";

        $guestResult = pg_query(
            $pgConnection,
            $guestSql
        );

        if ($guestResult) {
            while ($row = pg_fetch_assoc($guestResult)) {
                $guests[] = $row;
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| CREATE ORDER
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $customerName = trim(
        $_POST['customer_name'] ?? ''
    );

    $orderType = $_POST['order_type']
        ?? 'Dine-In';

    $customerType = $_POST['customer_type']
        ?? 'Walk-In';

    $roomId = !empty($_POST['room_id'])
        ? (int)$_POST['room_id']
        : null;

    $orderPeriod = $_POST['order_period']
        ?? 'Day';

    $paymentMethod = $_POST['payment_method']
        ?? 'Cash';

    $foodIds = $_POST['food_id'] ?? [];

    $quantities = $_POST['quantity'] ?? [];

    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($customerName === '') {

        $error = "Please enter customer name.";

    } elseif (
        !in_array(
            $orderType,
            ['Dine-In', 'Takeaway'],
            true
        )
    ) {

        $error = "Invalid order type.";

    } elseif (
        !in_array(
            $customerType,
            ['Walk-In', 'Hotel Guest'],
            true
        )
    ) {

        $error = "Invalid customer type.";

    } elseif (
        !in_array(
            $orderPeriod,
            ['Day', 'Night'],
            true
        )
    ) {

        $error = "Invalid order period.";

    } elseif (
        !in_array(
            $paymentMethod,
            ['Cash', 'QR', 'Card', 'Room Bill'],
            true
        )
    ) {

        $error = "Invalid payment method.";

    } elseif (empty($foodIds)) {

        $error = "Please select at least one food item.";
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATE SELECTED FOOD
    |--------------------------------------------------------------------------
    */

    $selectedItems = [];
    $subtotal = 0;

    if ($error === '') {

        foreach ($foodIds as $index => $foodId) {

            $foodId = (int)$foodId;

            $quantity = isset($quantities[$index])
                ? (float)$quantities[$index]
                : 1;

            if (
                $foodId <= 0 ||
                $quantity <= 0
            ) {
                continue;
            }

            $found = null;

            foreach ($foods as $food) {

                if (
                    (int)$food['id'] === $foodId
                ) {
                    $found = $food;
                    break;
                }
            }

            if (!$found) {
                continue;
            }

            $unitPrice = (float)$found['price'];

            $totalPrice =
                $unitPrice * $quantity;

            $selectedItems[] = [
                'food_id' => $foodId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice
            ];

            $subtotal += $totalPrice;
        }

        if (empty($selectedItems)) {
            $error = "No valid food items were selected.";
        }
    }

    /*
    |--------------------------------------------------------------------------
    | VAT
    |--------------------------------------------------------------------------
    */

    $vatAmount = round(
        $subtotal * 0.13,
        2
    );

    $totalAmount = round(
        $subtotal + $vatAmount,
        2
    );

    /*
    |--------------------------------------------------------------------------
    | PAYMENT STATUS
    |--------------------------------------------------------------------------
    */

    $paymentStatus = 'Pending';

    if (
        in_array(
            $paymentMethod,
            ['Cash', 'QR', 'Card'],
            true
        )
    ) {
        $paymentStatus = 'Paid';
    }

    if ($paymentMethod === 'Room Bill') {
        $paymentStatus = 'Added to Room Bill';
    }

    /*
    |--------------------------------------------------------------------------
    | HOTEL GUEST VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($error === '') {

        if (
            $customerType === 'Hotel Guest' &&
            (!$roomId || $roomId <= 0)
        ) {

            $error =
                "Please enter/select a room for Hotel Guest.";
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SAVE ORDER
    |--------------------------------------------------------------------------
    */

    if ($error === '') {

        /*
        | Start PostgreSQL transaction
        */
        if (!pg_query($pgConnection, "BEGIN")) {

            $error =
                "Unable to start database transaction: " .
                pg_last_error($pgConnection);

        } else {

            try {

                /*
                |--------------------------------------------------------------------------
                | INSERT RESTAURANT ORDER
                |--------------------------------------------------------------------------
                */

                $orderSql = "
                    INSERT INTO restaurant_orders
                    (
                        customer_name,
                        order_type,
                        customer_type,
                        room_id,
                        order_period,
                        subtotal,
                        vat_amount,
                        total_amount,
                        payment_method,
                        payment_status,
                        order_status
                    )
                    VALUES
                    (
                        $1,
                        $2,
                        $3,
                        $4,
                        $5,
                        $6,
                        $7,
                        $8,
                        $9,
                        $10,
                        'Pending'
                    )
                    RETURNING id
                ";

                $orderParams = [
                    $customerName,
                    $orderType,
                    $customerType,
                    $roomId,
                    $orderPeriod,
                    $subtotal,
                    $vatAmount,
                    $totalAmount,
                    $paymentMethod,
                    $paymentStatus
                ];

                $orderResult = pg_query_params(
                    $pgConnection,
                    $orderSql,
                    $orderParams
                );

                if (!$orderResult) {

                    throw new Exception(
                        "Unable to create order: " .
                        pg_last_error($pgConnection)
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | GET NEW ORDER ID
                |--------------------------------------------------------------------------
                */

                $orderRow = pg_fetch_assoc(
                    $orderResult
                );

                if (
                    !$orderRow ||
                    !isset($orderRow['id'])
                ) {

                    throw new Exception(
                        "Order was created but the order ID could not be retrieved."
                    );
                }

                $orderId = (int)$orderRow['id'];

                /*
                |--------------------------------------------------------------------------
                | INSERT ORDER ITEMS
                |--------------------------------------------------------------------------
                */

                foreach (
                    $selectedItems as $item
                ) {

                    $itemSql = "
                        INSERT INTO restaurant_order_items
                        (
                            order_id,
                            food_id,
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

                    $itemParams = [
                        $orderId,
                        $item['food_id'],
                        $item['quantity'],
                        $item['unit_price'],
                        $item['total_price']
                    ];

                    $itemResult = pg_query_params(
                        $pgConnection,
                        $itemSql,
                        $itemParams
                    );

                    if (!$itemResult) {

                        throw new Exception(
                            "Unable to save food item: " .
                            pg_last_error($pgConnection)
                        );
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | COMMIT
                |--------------------------------------------------------------------------
                */

                if (!pg_query($pgConnection, "COMMIT")) {

                    throw new Exception(
                        "Unable to commit order: " .
                        pg_last_error($pgConnection)
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | SUCCESS REDIRECT
                |--------------------------------------------------------------------------
                */

                header(
                    "Location: view.php?id=" .
                    $orderId .
                    "&success=1"
                );

                exit;

            } catch (Exception $e) {

                /*
                | Rollback transaction
                */
                pg_query(
                    $pgConnection,
                    "ROLLBACK"
                );

                $error = $e->getMessage();
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| CREATE CATEGORY LIST
|--------------------------------------------------------------------------
*/

$categories = [];

foreach ($foods as $food) {

    $category = trim(
        $food['category'] ?? ''
    );

    if (
        $category !== '' &&
        !in_array(
            $category,
            $categories,
            true
        )
    ) {
        $categories[] = $category;
    }
}

sort($categories);

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Restaurant Order - Suva Hotel</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family:
        Arial,
        Helvetica,
        sans-serif;
    background:
        linear-gradient(
            135deg,
            #f0fdfa,
            #f8fafc
        );
    color: #1f2937;
}

.header {
    background:
        linear-gradient(
            135deg,
            #064e3b,
            #0f766e,
            #14b8a6
        );
    color: white;
    padding: 28px 35px;
    box-shadow:
        0 8px 30px
        rgba(0,0,0,.12);
}

.header-content {
    max-width: 1400px;
    margin: auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
}

.hotel-title {
    display: flex;
    align-items: center;
    gap: 14px;
}

.hotel-icon {
    width: 55px;
    height: 55px;
    background:
        rgba(255,255,255,.15);
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
}

.header h1 {
    margin: 0;
    font-size: 27px;
}

.header p {
    margin: 5px 0 0;
    opacity: .85;
}

.back-btn {
    color: white;
    text-decoration: none;
    background:
        rgba(255,255,255,.14);
    border:
        1px solid
        rgba(255,255,255,.25);
    padding:
        11px 17px;
    border-radius: 10px;
    font-weight: bold;
}

.back-btn:hover {
    background:
        rgba(255,255,255,.25);
}

.container {
    max-width: 1400px;
    margin:
        25px auto;
    padding:
        0 20px;
}

.alert {
    padding: 15px 18px;
    border-radius: 12px;
    margin-bottom: 20px;
    font-weight: bold;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border:
        1px solid
        #fecaca;
}

.card {
    background: white;
    border-radius: 18px;
    padding: 25px;
    margin-bottom: 22px;
    box-shadow:
        0 8px 28px
        rgba(15,23,42,.07);
    border:
        1px solid
        #e5e7eb;
}

.card-title {
    display: flex;
    align-items: center;
    gap: 10px;
    margin:
        0 0 20px;
    font-size: 21px;
}

.grid {
    display: grid;
    grid-template-columns:
        repeat(2, 1fr);
    gap: 18px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group.full {
    grid-column:
        1 / -1;
}

label {
    font-size: 14px;
    font-weight: bold;
    margin-bottom: 7px;
    color: #374151;
}

input,
select {
    width: 100%;
    padding: 12px 14px;
    border:
        1px solid
        #d1d5db;
    border-radius: 10px;
    background: white;
    font-size: 15px;
}

input:focus,
select:focus {
    outline: none;
    border-color:
        #0f766e;
    box-shadow:
        0 0 0 3px
        rgba(15,118,110,.12);
}

.radio-group {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.radio-option {
    flex: 1;
    min-width: 125px;
}

.radio-option input {
    display: none;
}

.radio-option label {
    display: block;
    padding: 12px;
    text-align: center;
    border:
        1px solid
        #d1d5db;
    border-radius: 10px;
    cursor: pointer;
    background: white;
    transition: .2s;
}

.radio-option label:hover {
    border-color:
        #0f766e;
}

.radio-option input:checked + label {
    background:
        #ecfdf5;
    color:
        #047857;
    border-color:
        #10b981;
    box-shadow:
        0 3px 10px
        rgba(16,185,129,.12);
}

.room-box {
    display: none;
}

.menu-toolbar {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.search-box {
    flex: 1;
    min-width: 240px;
    position: relative;
}

.search-box input {
    padding-left: 43px;
}

.search-icon {
    position: absolute;
    left: 15px;
    top: 50%;
    transform:
        translateY(-50%);
    font-size: 18px;
    color: #64748b;
}

.category-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 22px;
}

.category-btn {
    border: none;
    padding:
        10px 16px;
    border-radius: 999px;
    background:
        #f1f5f9;
    color:
        #475569;
    cursor: pointer;
    font-weight: bold;
    transition: .2s;
}

.category-btn:hover {
    background:
        #d1fae5;
    color:
        #047857;
}

.category-btn.active {
    background:
        #0f766e;
    color: white;
}

.order-layout {
    display: grid;
    grid-template-columns:
        minmax(0, 1fr)
        380px;
    gap: 22px;
    align-items: start;
}

.food-grid {
    display: grid;
    grid-template-columns:
        repeat(3, 1fr);
    gap: 18px;
}

.food-card {
    background: white;
    border-radius: 17px;
    overflow: hidden;
    border:
        1px solid
        #e5e7eb;
    box-shadow:
        0 5px 20px
        rgba(0,0,0,.06);
    transition:
        transform .2s,
        box-shadow .2s;
    position: relative;
}

.food-card:hover {
    transform:
        translateY(-5px);
    box-shadow:
        0 12px 30px
        rgba(0,0,0,.12);
}

.food-image {
    width: 100%;
    height: 180px;
    object-fit: cover;
    display: block;
    background:
        linear-gradient(
            135deg,
            #ccfbf1,
            #f0fdfa
        );
}

.food-placeholder {
    width: 100%;
    height: 180px;
    display: flex;
    align-items: center;
    justify-content: center;
    background:
        linear-gradient(
            135deg,
            #ccfbf1,
            #f0fdfa
        );
    font-size: 55px;
}

.food-info {
    padding: 16px;
}

.food-category {
    display: inline-block;
    background:
        #ecfdf5;
    color:
        #047857;
    font-size: 11px;
    font-weight: bold;
    padding:
        5px 9px;
    border-radius: 999px;
    margin-bottom: 8px;
    text-transform: uppercase;
}

.food-name {
    margin: 0;
    font-size: 18px;
    color:
        #111827;
}

.food-description {
    color:
        #64748b;
    font-size: 13px;
    line-height: 1.5;
    min-height: 39px;
    margin:
        7px 0;
}

.food-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-top: 13px;
}

.food-price {
    font-size: 19px;
    font-weight: bold;
    color:
        #047857;
}

.add-btn {
    border: none;
    background:
        #0f766e;
    color: white;
    padding:
        10px 14px;
    border-radius: 9px;
    cursor: pointer;
    font-weight: bold;
    transition: .2s;
}

.add-btn:hover {
    background:
        #115e59;
    transform:
        scale(1.03);
}

.cart-card {
    background: white;
    border-radius: 18px;
    border:
        1px solid
        #e5e7eb;
    box-shadow:
        0 8px 28px
        rgba(15,23,42,.08);
    position: sticky;
    top: 18px;
    overflow: hidden;
}

.cart-header {
    background:
        linear-gradient(
            135deg,
            #064e3b,
            #0f766e
        );
    color: white;
    padding: 19px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.cart-header h2 {
    margin: 0;
    font-size: 19px;
}

.cart-count {
    background:
        white;
    color:
        #047857;
    min-width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
}

.cart-items {
    padding: 15px;
    max-height: 390px;
    overflow-y: auto;
}

.empty-cart {
    text-align: center;
    padding:
        35px 15px;
    color:
        #64748b;
}

.empty-cart-icon {
    font-size: 48px;
    margin-bottom: 10px;
}

.cart-item {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding:
        13px 0;
    border-bottom:
        1px solid
        #e5e7eb;
}

.cart-item:last-child {
    border-bottom: none;
}

.cart-item-name {
    font-weight: bold;
    font-size: 14px;
    margin-bottom: 6px;
}

.cart-item-price {
    color:
        #047857;
    font-size: 13px;
    font-weight: bold;
}

.qty-controls {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-top: 7px;
}

.qty-btn {
    width: 26px;
    height: 26px;
    border: none;
    border-radius: 7px;
    background:
        #ecfdf5;
    color:
        #047857;
    cursor: pointer;
    font-weight: bold;
}

.qty-value {
    min-width: 25px;
    text-align: center;
    font-weight: bold;
}

.remove-cart {
    border: none;
    background:
        transparent;
    color:
        #ef4444;
    cursor: pointer;
    font-size: 18px;
}

.cart-summary {
    border-top:
        1px solid
        #e5e7eb;
    padding: 18px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 6px 0;
    color:
        #475569;
}

.summary-total {
    border-top:
        2px solid
        #d1d5db;
    margin-top: 8px;
    padding-top: 13px;
    font-size: 21px;
    font-weight: bold;
    color:
        #111827;
}

.create-order {
    width: 100%;
    border: none;
    background:
        #0f766e;
    color: white;
    padding: 15px;
    border-radius: 10px;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
    margin-top: 13px;
}

.create-order:hover {
    background:
        #115e59;
}

.payment-card {
    margin-top: 22px;
}

.no-food {
    padding: 50px;
    text-align: center;
    background:
        #fff7ed;
    color:
        #9a3412;
    border:
        1px solid
        #fed7aa;
    border-radius: 15px;
}

.hidden-inputs {
    display: none;
}

@media(max-width: 1100px) {

    .order-layout {
        grid-template-columns:
            1fr;
    }

    .cart-card {
        position: relative;
        top: auto;
    }

    .food-grid {
        grid-template-columns:
            repeat(3, 1fr);
    }
}

@media(max-width: 800px) {

    .grid {
        grid-template-columns:
            1fr;
    }

    .food-grid {
        grid-template-columns:
            repeat(2, 1fr);
    }

    .header-content {
        flex-direction: column;
        align-items: flex-start;
    }
}

@media(max-width: 550px) {

    .food-grid {
        grid-template-columns:
            1fr;
    }

    .container {
        padding:
            0 10px;
    }

    .header {
        padding:
            22px 18px;
    }
}

</style>

</head>

<body>

<header class="header">

    <div class="header-content">

        <div class="hotel-title">

            <div class="hotel-icon">
                🏨
            </div>

            <div>

                <h1>
                    Suva Hotel
                </h1>

                <p>
                    Restaurant Ordering System
                </p>

            </div>

        </div>

        <a
            href="menu.php"
            class="back-btn"
        >
            ← Restaurant Menu
        </a>

    </div>

</header>

<div class="container">

<?php if ($error !== ''): ?>

    <div class="alert alert-error">

        ⚠️

        <?= htmlspecialchars($error) ?>

    </div>

<?php endif; ?>

<form
    method="POST"
    id="orderForm"
>

<div class="card">

    <h2 class="card-title">
        👤 Customer Information
    </h2>

    <div class="grid">

        <div class="form-group full">

            <label for="customer_name">
                Customer Name
            </label>

            <input
                type="text"
                id="customer_name"
                name="customer_name"
                placeholder="Enter customer name"
                value="<?= htmlspecialchars(
                    $_POST['customer_name'] ?? ''
                ) ?>"
                required
            >

        </div>

        <div class="form-group">

            <label>
                Order Type
            </label>

            <div class="radio-group">

                <div class="radio-option">

                    <input
                        type="radio"
                        id="dinein"
                        name="order_type"
                        value="Dine-In"
                        <?= (
                            ($_POST['order_type']
                            ?? 'Dine-In')
                            === 'Dine-In'
                        )
                            ? 'checked'
                            : ''
                        ?>
                    >

                    <label for="dinein">
                        🍽️ Dine-In
                    </label>

                </div>

                <div class="radio-option">

                    <input
                        type="radio"
                        id="takeaway"
                        name="order_type"
                        value="Takeaway"
                        <?= (
                            ($_POST['order_type']
                            ?? '')
                            === 'Takeaway'
                        )
                            ? 'checked'
                            : ''
                        ?>
                    >

                    <label for="takeaway">
                        🥡 Takeaway
                    </label>

                </div>

            </div>

        </div>

        <div class="form-group">

            <label>
                Customer Type
            </label>

            <div class="radio-group">

                <div class="radio-option">

                    <input
                        type="radio"
                        id="walkin"
                        name="customer_type"
                        value="Walk-In"
                        <?= (
                            ($_POST['customer_type']
                            ?? 'Walk-In')
                            === 'Walk-In'
                        )
                            ? 'checked'
                            : ''
                        ?>
                    >

                    <label for="walkin">
                        🚶 Walk-In
                    </label>

                </div>

                <div class="radio-option">

                    <input
                        type="radio"
                        id="hotelguest"
                        name="customer_type"
                        value="Hotel Guest"
                        <?= (
                            ($_POST['customer_type']
                            ?? '')
                            === 'Hotel Guest'
                        )
                            ? 'checked'
                            : ''
                        ?>
                    >

                    <label for="hotelguest">
                        🏨 Hotel Guest
                    </label>

                </div>

            </div>

        </div>

        <div
            class="form-group room-box"
            id="roomBox"
        >

            <label for="room_id">
                Room ID
            </label>

            <input
                type="number"
                id="room_id"
                name="room_id"
                placeholder="Enter room ID"
                value="<?= htmlspecialchars(
                    $_POST['room_id'] ?? ''
                ) ?>"
            >

        </div>

        <div class="form-group">

            <label>
                Order Period
            </label>

            <div class="radio-group">

                <div class="radio-option">

                    <input
                        type="radio"
                        id="day"
                        name="order_period"
                        value="Day"
                        <?= (
                            ($_POST['order_period']
                            ?? 'Day')
                            === 'Day'
                        )
                            ? 'checked'
                            : ''
                        ?>
                    >

                    <label for="day">
                        ☀️ Day
                    </label>

                </div>

                <div class="radio-option">

                    <input
                        type="radio"
                        id="night"
                        name="order_period"
                        value="Night"
                        <?= (
                            ($_POST['order_period']
                            ?? '')
                            === 'Night'
                        )
                            ? 'checked'
                            : ''
                        ?>
                    >

                    <label for="night">
                        🌙 Night
                    </label>

                </div>

            </div>

        </div>

    </div>

</div>

<div class="order-layout">

<div class="card">

    <h2 class="card-title">
        🍴 Our Food Menu
    </h2>

    <div class="menu-toolbar">

        <div class="search-box">

            <span class="search-icon">
                🔍
            </span>

            <input
                type="text"
                id="foodSearch"
                placeholder="Search food..."
            >

        </div>

    </div>

    <div class="category-buttons">

        <button
            type="button"
            class="category-btn active"
            data-category="all"
            onclick="filterCategory('all', this)"
        >
            🍽️ All
        </button>

        <?php foreach ($categories as $category): ?>

            <button
                type="button"
                class="category-btn"
                data-category="<?= htmlspecialchars(
                    strtolower($category)
                ) ?>"
                onclick="filterCategory(
                    <?= json_encode(
                        strtolower($category)
                    ) ?>,
                    this
                )"
            >
                <?= htmlspecialchars($category) ?>
            </button>

        <?php endforeach; ?>

    </div>

    <?php if (count($foods) > 0): ?>

        <div
            class="food-grid"
            id="foodGrid"
        >

            <?php foreach ($foods as $food): ?>

                <?php

                $foodId =
                    (int)$food['id'];

                $foodName =
                    $food['name'] ?? '';

                $category =
                    $food['category'] ?? '';

                $description =
                    $food['description'] ?? '';

                $price =
                    (float)$food['price'];

                $image =
                    trim($food['image'] ?? '');

                $imagePath = '';

                if ($image !== '') {

                    if (
                        str_starts_with(
                            $image,
                            'http://'
                        ) ||
                        str_starts_with(
                            $image,
                            'https://'
                        )
                    ) {

                        $imagePath = $image;

                    } elseif (
                        str_contains(
                            $image,
                            '/'
                        ) ||
                        str_contains(
                            $image,
                            '\\'
                        )
                    ) {

                        $imagePath = $image;

                    } else {

                        $imagePath =
                            'uploads/' .
                            $image;
                    }
                }

                ?>

                <div
                    class="food-card"
                    data-name="<?= htmlspecialchars(
                        strtolower($foodName)
                    ) ?>"
                    data-category="<?= htmlspecialchars(
                        strtolower($category)
                    ) ?>"
                    data-id="<?= $foodId ?>"
                    data-price="<?= $price ?>"
                    data-description="<?= htmlspecialchars(
                        $description
                    ) ?>"
                >

                    <?php if ($imagePath !== ''): ?>

                        <img
                            src="<?= htmlspecialchars(
                                $imagePath
                            ) ?>"
                            alt="<?= htmlspecialchars(
                                $foodName
                            ) ?>"
                            class="food-image"
                            onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                        >

                        <div
                            class="food-placeholder"
                            style="display:none;"
                        >
                            🍽️
                        </div>

                    <?php else: ?>

                        <div class="food-placeholder">
                            🍽️
                        </div>

                    <?php endif; ?>

                    <div class="food-info">

                        <span class="food-category">
                            <?= htmlspecialchars(
                                $category
                            ) ?>
                        </span>

                        <h3 class="food-name">
                            <?= htmlspecialchars(
                                $foodName
                            ) ?>
                        </h3>

                        <div class="food-description">

                            <?= htmlspecialchars(
                                $description !== ''
                                    ? $description
                                    : 'Delicious food prepared by Suva Hotel.'
                            ) ?>

                        </div>

                        <div class="food-bottom">

                            <div class="food-price">

                                Rs.
                                <?= number_format(
                                    $price,
                                    2
                                ) ?>

                            </div>

                            <button
                                type="button"
                                class="add-btn"
                                onclick="addToCart(
                                    <?= $foodId ?>,
                                    <?= htmlspecialchars(
                                        json_encode($foodName),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>,
                                    <?= $price ?>
                                )"
                            >
                                + Add
                            </button>

                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

        <div
            id="noSearchResults"
            class="no-food"
            style="display:none;"
        >

            <div style="font-size:40px;">
                🔍
            </div>

            <strong>
                No food found
            </strong>

            <p>
                Try another food name or category.
            </p>

        </div>

    <?php else: ?>

        <div class="no-food">

            <div style="font-size:50px;">
                🍽️
            </div>

            <h3>
                No Available Food
            </h3>

            <p>
                Please add food items from
                Restaurant Food Management.
            </p>

        </div>

    <?php endif; ?>

</div>

<div class="cart-card">

    <div class="cart-header">

        <h2>
            🛒 Your Order
        </h2>

        <div
            class="cart-count"
            id="cartCount"
        >
            0
        </div>

    </div>

    <div
        class="cart-items"
        id="cartItems"
    >

        <div class="empty-cart">

            <div class="empty-cart-icon">
                🛒
            </div>

            <strong>
                Your cart is empty
            </strong>

            <p>
                Click "+ Add" on a food item.
            </p>

        </div>

    </div>

    <div class="cart-summary">

        <div class="summary-row">

            <span>
                Subtotal
            </span>

            <strong>
                Rs.
                <span id="subtotalDisplay">
                    0.00
                </span>
            </strong>

        </div>

        <div class="summary-row">

            <span>
                VAT (13%)
            </span>

            <strong>
                Rs.
                <span id="vatDisplay">
                    0.00
                </span>
            </strong>

        </div>

        <div class="summary-row summary-total">

            <span>
                Total
            </span>

            <strong>
                Rs.
                <span id="totalDisplay">
                    0.00
                </span>
            </strong>

        </div>

        <button
            type="submit"
            class="create-order"
            id="createOrderBtn"
        >
            🧾 Create Order
        </button>

    </div>

</div>

</div>

<div class="card payment-card">

    <h2 class="card-title">
        💳 Payment Method
    </h2>

    <div class="radio-group">

        <div class="radio-option">

            <input
                type="radio"
                id="cash"
                name="payment_method"
                value="Cash"
                <?= (
                    ($_POST['payment_method']
                    ?? 'Cash')
                    === 'Cash'
                )
                    ? 'checked'
                    : ''
                ?>
            >

            <label for="cash">
                💵 Cash
            </label>

        </div>

        <div class="radio-option">

            <input
                type="radio"
                id="qr"
                name="payment_method"
                value="QR"
                <?= (
                    ($_POST['payment_method']
                    ?? '')
                    === 'QR'
                )
                    ? 'checked'
                    : ''
                ?>
            >

            <label for="qr">
                📱 QR Payment
            </label>

        </div>

        <div class="radio-option">

            <input
                type="radio"
                id="card"
                name="payment_method"
                value="Card"
                <?= (
                    ($_POST['payment_method']
                    ?? '')
                    === 'Card'
                )
                    ? 'checked'
                    : ''
                ?>
            >

            <label for="card">
                💳 Card
            </label>

        </div>

        <div class="radio-option">

            <input
                type="radio"
                id="roombill"
                name="payment_method"
                value="Room Bill"
                <?= (
                    ($_POST['payment_method']
                    ?? '')
                    === 'Room Bill'
                )
                    ? 'checked'
                    : ''
                ?>
            >

            <label for="roombill">
                🏨 Room Bill
            </label>

        </div>

    </div>

</div>

<div
    id="hiddenInputs"
    class="hidden-inputs"
></div>

</form>

</div>

<script>

/*
|--------------------------------------------------------------------------
| CART DATA
|--------------------------------------------------------------------------
*/

let cart = {};

/*
|--------------------------------------------------------------------------
| ADD FOOD
|--------------------------------------------------------------------------
*/

function addToCart(id, name, price) {

    id = String(id);

    price = parseFloat(price) || 0;

    if (cart[id]) {

        cart[id].quantity += 1;

    } else {

        cart[id] = {
            id: id,
            name: name,
            price: price,
            quantity: 1
        };
    }

    renderCart();
}

/*
|--------------------------------------------------------------------------
| INCREASE
|--------------------------------------------------------------------------
*/

function increaseQuantity(id) {

    id = String(id);

    if (cart[id]) {
        cart[id].quantity++;
    }

    renderCart();
}

/*
|--------------------------------------------------------------------------
| DECREASE
|--------------------------------------------------------------------------
*/

function decreaseQuantity(id) {

    id = String(id);

    if (!cart[id]) {
        return;
    }

    cart[id].quantity--;

    if (cart[id].quantity <= 0) {
        delete cart[id];
    }

    renderCart();
}

/*
|--------------------------------------------------------------------------
| REMOVE
|--------------------------------------------------------------------------
*/

function removeFromCart(id) {

    id = String(id);

    delete cart[id];

    renderCart();
}

/*
|--------------------------------------------------------------------------
| RENDER CART
|--------------------------------------------------------------------------
*/

function renderCart() {

    const cartItems =
        document.getElementById(
            'cartItems'
        );

    const cartCount =
        document.getElementById(
            'cartCount'
        );

    const hiddenInputs =
        document.getElementById(
            'hiddenInputs'
        );

    let subtotal = 0;

    let itemCount = 0;

    cartItems.innerHTML = '';

    hiddenInputs.innerHTML = '';

    const items =
        Object.values(cart);

    if (items.length === 0) {

        cartItems.innerHTML = `
            <div class="empty-cart">

                <div class="empty-cart-icon">
                    🛒
                </div>

                <strong>
                    Your cart is empty
                </strong>

                <p>
                    Click "+ Add" on a food item.
                </p>

            </div>
        `;
    }

    items.forEach(function(item) {

        const itemTotal =
            item.price *
            item.quantity;

        subtotal += itemTotal;

        itemCount +=
            item.quantity;

        const div =
            document.createElement(
                'div'
            );

        div.className =
            'cart-item';

        div.innerHTML = `
            <div>

                <div class="cart-item-name">
                    ${escapeHtml(item.name)}
                </div>

                <div class="cart-item-price">
                    Rs.
                    ${itemTotal.toFixed(2)}
                </div>

                <div class="qty-controls">

                    <button
                        type="button"
                        class="qty-btn"
                        onclick="decreaseQuantity('${item.id}')"
                    >
                        −
                    </button>

                    <span class="qty-value">
                        ${item.quantity}
                    </span>

                    <button
                        type="button"
                        class="qty-btn"
                        onclick="increaseQuantity('${item.id}')"
                    >
                        +
                    </button>

                </div>

            </div>

            <button
                type="button"
                class="remove-cart"
                onclick="removeFromCart('${item.id}')"
                title="Remove"
            >
                ×
            </button>
        `;

        cartItems.appendChild(div);

        /*
        | Hidden food ID
        */

        const foodInput =
            document.createElement(
                'input'
            );

        foodInput.type = 'hidden';

        foodInput.name =
            'food_id[]';

        foodInput.value =
            item.id;

        hiddenInputs.appendChild(
            foodInput
        );

        /*
        | Hidden quantity
        */

        const quantityInput =
            document.createElement(
                'input'
            );

        quantityInput.type = 'hidden';

        quantityInput.name =
            'quantity[]';

        quantityInput.value =
            item.quantity;

        hiddenInputs.appendChild(
            quantityInput
        );

    });

    const vat =
        subtotal * 0.13;

    const total =
        subtotal + vat;

    cartCount.textContent =
        itemCount;

    document.getElementById(
        'subtotalDisplay'
    ).textContent =
        subtotal.toFixed(2);

    document.getElementById(
        'vatDisplay'
    ).textContent =
        vat.toFixed(2);

    document.getElementById(
        'totalDisplay'
    ).textContent =
        total.toFixed(2);
}

/*
|--------------------------------------------------------------------------
| ESCAPE HTML
|--------------------------------------------------------------------------
*/

function escapeHtml(text) {

    const div =
        document.createElement(
            'div'
        );

    div.textContent =
        text;

    return div.innerHTML;
}

/*
|--------------------------------------------------------------------------
| CATEGORY FILTER
|--------------------------------------------------------------------------
*/

let activeCategory = 'all';

function filterCategory(
    category,
    button
) {

    activeCategory =
        category.toLowerCase();

    document
        .querySelectorAll(
            '.category-btn'
        )
        .forEach(function(btn) {

            btn.classList.remove(
                'active'
            );

        });

    button.classList.add(
        'active'
    );

    filterFoods();
}

/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

document
    .getElementById(
        'foodSearch'
    )
    .addEventListener(
        'input',
        filterFoods
    );

function filterFoods() {

    const search =
        document
            .getElementById(
                'foodSearch'
            )
            .value
            .toLowerCase()
            .trim();

    const cards =
        document.querySelectorAll(
            '.food-card'
        );

    let visible = 0;

    cards.forEach(function(card) {

        const name =
            card.dataset.name
            || '';

        const category =
            card.dataset.category
            || '';

        const matchesSearch =
            name.includes(search)
            ||
            category.includes(search);

        const matchesCategory =
            activeCategory === 'all'
            ||
            category === activeCategory;

        if (
            matchesSearch &&
            matchesCategory
        ) {

            card.style.display =
                'block';

            visible++;

        } else {

            card.style.display =
                'none';
        }

    });

    const noResults =
        document.getElementById(
            'noSearchResults'
        );

    if (noResults) {

        noResults.style.display =
            visible === 0
                ? 'block'
                : 'none';
    }
}

/*
|--------------------------------------------------------------------------
| HOTEL GUEST ROOM FIELD
|--------------------------------------------------------------------------
*/

function updateRoomField() {

    const hotelGuest =
        document.getElementById(
            'hotelguest'
        );

    const roomBox =
        document.getElementById(
            'roomBox'
        );

    const roomInput =
        document.getElementById(
            'room_id'
        );

    if (
        hotelGuest &&
        hotelGuest.checked
    ) {

        roomBox.style.display =
            'flex';

        roomInput.required =
            true;

    } else {

        roomBox.style.display =
            'none';

        roomInput.required =
            false;

        roomInput.value =
            '';
    }
}

/*
|--------------------------------------------------------------------------
| CUSTOMER TYPE EVENTS
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll(
        'input[name="customer_type"]'
    )
    .forEach(function(input) {

        input.addEventListener(
            'change',
            updateRoomField
        );

    });

/*
|--------------------------------------------------------------------------
| FORM VALIDATION
|--------------------------------------------------------------------------
*/

document
    .getElementById(
        'orderForm'
    )
    .addEventListener(
        'submit',
        function(event) {

            const selectedItems =
                Object.keys(cart);

            if (
                selectedItems.length === 0
            ) {

                event.preventDefault();

                alert(
                    'Please add at least one food item to the order.'
                );

                return false;
            }

            const customerName =
                document
                    .getElementById(
                        'customer_name'
                    )
                    .value
                    .trim();

            if (
                customerName === ''
            ) {

                event.preventDefault();

                alert(
                    'Please enter customer name.'
                );

                return false;
            }

            return true;
        }
    );

/*
|--------------------------------------------------------------------------
| INITIALIZE
|--------------------------------------------------------------------------
*/

document.addEventListener(
    'DOMContentLoaded',
    function() {

        updateRoomField();

        renderCart();

    }
);

</script>

</body>

</html>
```
