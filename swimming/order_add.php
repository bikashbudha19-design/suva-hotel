```php
<?php
// ============================================================
// SUVA HOTEL
// SWIMMING POOL FOOD & DRINK ORDER
// File: /suva_hotel/swimming/order_add.php
// PostgreSQL Version
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
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function failPage($message)
{
    echo '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Order Error | Suva Hotel</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                background: #f3f7fa;
                padding: 50px 20px;
                text-align: center;
            }
            .box {
                max-width: 600px;
                margin: auto;
                background: white;
                padding: 35px;
                border-radius: 18px;
                box-shadow: 0 10px 30px rgba(0,0,0,.08);
            }
            h2 {
                color: #b42318;
            }
            p {
                color: #667085;
                line-height: 1.6;
            }
            a {
                display: inline-block;
                margin-top: 15px;
                padding: 12px 18px;
                background: #086b80;
                color: white;
                text-decoration: none;
                border-radius: 10px;
            }
        </style>
    </head>
    <body>
        <div class="box">
            <h2>❌ Food Order Error</h2>
            <p>' . e($message) . '</p>
            <a href="javascript:history.back()">← Go Back</a>
        </div>
    </body>
    </html>';

    exit;
}

// ============================================================
// ONLY POST REQUEST
// ============================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    failPage('Invalid request method.');
}

// ============================================================
// GET FORM DATA
// ============================================================

$swimming_id = isset($_POST['swimming_id'])
    ? (int)$_POST['swimming_id']
    : 0;

$itemsJson = $_POST['items'] ?? '';

$payment_method = trim(
    $_POST['payment_method'] ?? 'Cash'
);

if ($swimming_id <= 0) {
    failPage('Invalid swimming ID.');
}

if ($itemsJson === '') {
    failPage('No food or drink items were selected.');
}

// ============================================================
// DECODE ITEMS
// ============================================================

$items = json_decode($itemsJson, true);

if (!is_array($items) || empty($items)) {
    failPage('No valid food or drink items were found.');
}

// ============================================================
// ALLOWED PAYMENT METHODS
// ============================================================

$allowedPayments = [
    'Cash',
    'Card',
    'Online',
    'Room Charge'
];

if (!in_array($payment_method, $allowedPayments, true)) {
    $payment_method = 'Cash';
}

// ============================================================
// LOAD SWIMMING RECORD
// ============================================================

$swimSql = "
    SELECT
        s.id,
        s.guest_id,
        s.booking_id,
        s.visitor_type,
        s.visitor_name,
        s.total_amount,
        s.status,
        g.full_name,
        g.phone,
        g.email
    FROM swimming s
    LEFT JOIN guests g
        ON g.id = s.guest_id
    WHERE s.id = $1
    LIMIT 1
";

$swimResult = pg_query_params(
    $pgConnection,
    $swimSql,
    [$swimming_id]
);

if (!$swimResult) {
    failPage(
        'Swimming database error: ' .
        pg_last_error($pgConnection)
    );
}

$swimmer = pg_fetch_assoc($swimResult);

if (!$swimmer) {
    failPage(
        'Swimming record #' .
        $swimming_id .
        ' was not found.'
    );
}

// ============================================================
// CHECK STATUS
// ============================================================

if (
    strtolower(
        trim((string)$swimmer['status'])
    ) === 'cancelled'
) {
    failPage(
        'This swimming session has been cancelled.'
    );
}

// ============================================================
// VALIDATE AND MERGE ITEMS
// ============================================================

$cleanItems = [];

foreach ($items as $item) {

    if (!is_array($item)) {
        continue;
    }

    if (
        !isset($item['food_id']) ||
        !isset($item['quantity'])
    ) {
        continue;
    }

    $food_id = (int)$item['food_id'];
    $quantity = (int)$item['quantity'];

    if ($food_id <= 0) {
        continue;
    }

    if ($quantity <= 0) {
        continue;
    }

    if ($quantity > 99) {
        $quantity = 99;
    }

    if (!isset($cleanItems[$food_id])) {
        $cleanItems[$food_id] = 0;
    }

    $cleanItems[$food_id] += $quantity;
}

if (empty($cleanItems)) {
    failPage(
        'No valid food or drink items were found.'
    );
}

// ============================================================
// GET FOOD ITEMS
//
// IMPORTANT:
// restaurant_foods uses:
// Available
// Unavailable
// ============================================================

$foodIds = array_keys($cleanItems);

$foodRows = [];

foreach ($foodIds as $food_id) {

    $foodSql = "
        SELECT
            id,
            food_name,
            category,
            price,
            status
        FROM restaurant_foods
        WHERE id = $1
        AND status = 'Available'
        LIMIT 1
    ";

    $foodResult = pg_query_params(
        $pgConnection,
        $foodSql,
        [$food_id]
    );

    if (!$foodResult) {
        failPage(
            'Unable to read restaurant food item #' .
            $food_id .
            ': ' .
            pg_last_error($pgConnection)
        );
    }

    $food = pg_fetch_assoc($foodResult);

    if (!$food) {
        failPage(
            'The selected food or drink item #' .
            $food_id .
            ' is not available. Please refresh the order page and try again.'
        );
    }

    $foodRows[] = [
        'id'       => (int)$food['id'],
        'name'     => $food['food_name'],
        'category' => $food['category'],
        'price'    => (float)$food['price'],
        'quantity' => $cleanItems[$food_id]
    ];
}

// ============================================================
// CALCULATE TOTALS
// ============================================================

$subtotal = 0;

foreach ($foodRows as $food) {

    $subtotal +=
        $food['price'] *
        $food['quantity'];
}

$vat = round(
    $subtotal * 0.13,
    2
);

$foodTotal = round(
    $subtotal + $vat,
    2
);

$swimmingCharge = (float)$swimmer['total_amount'];

$grandTotal = round(
    $swimmingCharge +
    $foodTotal,
    2
);

// ============================================================
// START TRANSACTION
// ============================================================

if (!pg_query($pgConnection, 'BEGIN')) {
    failPage(
        'Unable to start database transaction.'
    );
}

try {

    // ========================================================
    // CREATE RESTAURANT ORDER
    // ========================================================

    $customerName =
        trim((string)(
            $swimmer['full_name']
            ?: $swimmer['visitor_name']
            ?: 'Swimming Guest'
        ));

    $roomId = null;

    // --------------------------------------------------------
    // Get room from booking if available
    // --------------------------------------------------------

    if (!empty($swimmer['booking_id'])) {

        $roomSql = "
            SELECT room_id
            FROM bookings
            WHERE id = $1
            LIMIT 1
        ";

        $roomResult = pg_query_params(
            $pgConnection,
            $roomSql,
            [(int)$swimmer['booking_id']]
        );

        if ($roomResult) {

            $roomRow =
                pg_fetch_assoc($roomResult);

            if (
                $roomRow &&
                $roomRow['room_id'] !== null
            ) {
                $roomId =
                    (int)$roomRow['room_id'];
            }
        }
    }

    // --------------------------------------------------------
    // INSERT ORDER
    //
    // Exact confirmed restaurant_orders columns:
    //
    // id
    // customer_name
    // order_type
    // customer_type
    // room_id
    // order_period
    // subtotal
    // vat_amount
    // total_amount
    // payment_method
    // payment_status
    // order_status
    // created_at
    // swimming_id
    // --------------------------------------------------------

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
            order_status,
            swimming_id
        )
        VALUES
        (
            $1,
            'Swimming Pool',
            'Swimming Guest',
            $2,
            'Swimming',
            $3,
            $4,
            $5,
            $6,
            'Paid',
            'Completed',
            $7
        )
        RETURNING id
    ";

    $orderResult = pg_query_params(
        $pgConnection,
        $orderSql,
        [
            $customerName,
            $roomId,
            $subtotal,
            $vat,
            $foodTotal,
            $payment_method,
            $swimming_id
        ]
    );

    if (!$orderResult) {
        throw new Exception(
            'Could not create restaurant order: ' .
            pg_last_error($pgConnection)
        );
    }

    $orderRow =
        pg_fetch_assoc($orderResult);

    if (!$orderRow || empty($orderRow['id'])) {
        throw new Exception(
            'Restaurant order ID was not returned.'
        );
    }

    $order_id =
        (int)$orderRow['id'];

    // ========================================================
    // INSERT ORDER ITEMS
    // ========================================================

    foreach ($foodRows as $food) {

        $itemTotal = round(
            $food['price'] *
            $food['quantity'],
            2
        );

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

        $itemResult = pg_query_params(
            $pgConnection,
            $itemSql,
            [
                $order_id,
                $food['id'],
                $food['quantity'],
                $food['price'],
                $itemTotal
            ]
        );

        if (!$itemResult) {
            throw new Exception(
                'Could not save order item ' .
                $food['name'] .
                ': ' .
                pg_last_error($pgConnection)
            );
        }
    }

    // ========================================================
    // COMMIT
    // ========================================================

    if (!pg_query($pgConnection, 'COMMIT')) {
        throw new Exception(
            'Could not complete the database transaction.'
        );
    }

    // ========================================================
    // REDIRECT TO BILL
    // ========================================================

    header(
        'Location: order_bill.php?order_id=' .
        $order_id
    );

    exit;

} catch (Exception $e) {

    // ========================================================
    // ROLLBACK
    // ========================================================

    pg_query(
        $pgConnection,
        'ROLLBACK'
    );

    failPage(
        $e->getMessage()
    );
}
?>
```
