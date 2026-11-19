
<?php
// ============================================================
// SUVA HOTEL - SAVE RESTAURANT ORDER
// PostgreSQL | PHP 8.0 compatible
// File: restaurant/orders/save.php
// ============================================================

session_start();

// Do not display database errors to customers.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ============================================================
// 1. AUTHENTICATION
// ============================================================

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

$role = strtolower($_SESSION['role'] ?? '');

if (!in_array($role, ['admin', 'staff'], true)) {
    header('Location: ../../login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: add.php');
    exit;
}

// ============================================================
// 2. DATABASE CONNECTION
// ============================================================

require_once __DIR__ . '/../../config/database.php';

if (!isset($pgConnection) || !$pgConnection) {
    error_log('Suva Hotel: PostgreSQL connection unavailable.');
    http_response_code(500);
    exit('Database connection failed. Please contact the administrator.');
}

// ============================================================
// 3. READ FORM DATA
// ============================================================

$customerName  = trim($_POST['customer_name'] ?? '');
$orderType     = trim($_POST['order_type'] ?? 'Dine-In');
$customerType  = trim($_POST['customer_type'] ?? 'Walk-In');
$orderPeriod   = trim($_POST['order_period'] ?? 'Day');
$paymentMethod = trim($_POST['payment_method'] ?? 'Cash');

// Table number is optional for orders that do not use a table.
$tableNumber = trim($_POST['table_number'] ?? '');

if ($tableNumber === '') {
    $tableNumber = null;
}

$cartDataRaw = $_POST['cart_data'] ?? '';

if ($customerName === '') {
    exit('Customer name is missing. Please return to the order form.');
}

if ($cartDataRaw === '') {
    exit(
        'No food items were received. The order form did not submit ' .
        'cart_data. Please return to add.php and check its JavaScript.'
    );
}

// ============================================================
// 4. DECODE AND VALIDATE CART
// ============================================================

$cartItems = json_decode($cartDataRaw, true);

if (
    json_last_error() !== JSON_ERROR_NONE ||
    !is_array($cartItems) ||
    count($cartItems) === 0
) {
    exit(
        'The food cart is empty or invalid. Please return to the order ' .
        'form, select food items, and try again.'
    );
}

// Prevent an excessively large submitted cart.
if (count($cartItems) > 100) {
    exit('Too many cart items. Please reduce the number of items.');
}

// ============================================================
// 5. VALIDATE ROOM FOR HOTEL GUESTS
// ============================================================

$roomId = null;

if ($customerType === 'Hotel Guest') {
    $submittedRoomId = filter_var(
        $_POST['room_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!$submittedRoomId || $submittedRoomId <= 0) {
        exit('Please select a valid hotel room.');
    }

    $roomId = $submittedRoomId;
}

// ============================================================
// 6. VERIFY FOOD IDS, QUANTITIES AND DATABASE PRICES
// ============================================================

$processedItems = [];
$subtotal = 0.00;

$foodSql = '
    SELECT id, price
    FROM public.restaurant_foods
    WHERE id = $1
';

foreach ($cartItems as $item) {
    if (!is_array($item)) {
        exit('Invalid food item in cart. Please rebuild the order.');
    }

    $foodId = filter_var(
        $item['id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $qtyRaw = $item['quantity'] ?? null;

    if (
        !$foodId ||
        $foodId <= 0 ||
        !is_numeric($qtyRaw)
    ) {
        exit('A food item has an invalid ID or quantity.');
    }

    $qty = (float)$qtyRaw;

    if (!is_finite($qty) || $qty <= 0 || $qty > 10000) {
        exit('A food item has an invalid quantity.');
    }

    $foodRes = pg_query_params(
        $pgConnection,
        $foodSql,
        [$foodId]
    );

    if (!$foodRes) {
        error_log(
            'Suva Hotel food lookup failed: ' .
            pg_last_error($pgConnection)
        );

        http_response_code(500);
        exit('Could not verify food prices. Please try again.');
    }

    if (pg_num_rows($foodRes) !== 1) {
        exit(
            'A selected food item no longer exists. ' .
            'Please rebuild the order.'
        );
    }

    $foodRow = pg_fetch_assoc($foodRes);
    $unitPrice = (float)$foodRow['price'];

    if (!is_finite($unitPrice) || $unitPrice < 0) {
        exit(
            'Invalid food price found. Please contact the administrator.'
        );
    }

    $lineTotal = round($unitPrice * $qty, 2);
    $subtotal += $lineTotal;

    $processedItems[] = [
        'food_id'     => $foodId,
        'quantity'    => $qty,
        'unit_price'  => $unitPrice,
        'total_price' => $lineTotal
    ];
}

if (count($processedItems) === 0) {
    exit('No valid food items were found.');
}

// ============================================================
// 7. CALCULATE TOTALS
// VAT: 13%
// ============================================================

$subtotal = round($subtotal, 2);
$vatRate = 0.13;
$vatAmount = round($subtotal * $vatRate, 2);
$total = round($subtotal + $vatAmount, 2);

// ============================================================
// 8. INITIAL STATUSES
// ============================================================

// Existing order lifecycle status.
$orderStatus = 'Pending';

// Kitchen preparation status is separate.
$kitchenStatus = 'New';

// Never trust a browser field as proof of payment.
// Payment remains Unpaid until verified by the payment workflow.
$paymentStatus = 'Unpaid';

// ============================================================
// 9. SAVE ORDER AND ITEMS IN ONE TRANSACTION
// ============================================================

if (!pg_query($pgConnection, 'BEGIN')) {
    error_log('Suva Hotel: Could not start order transaction.');

    http_response_code(500);
    exit('Could not start saving the order. Please try again.');
}

try {
    // --------------------------------------------------------
    // 9A. INSERT ORDER HEADER
    // --------------------------------------------------------

    $orderSql = '
        INSERT INTO public.restaurant_orders (
            customer_name,
            order_type,
            customer_type,
            room_id,
            table_number,
            order_period,
            subtotal,
            vat_amount,
            total_amount,
            payment_method,
            payment_status,
            order_status,
            kitchen_status,
            created_at
        )
        VALUES (
            $1, $2, $3, $4, $5, $6, $7,
            $8, $9, $10, $11, $12, $13, NOW()
        )
        RETURNING id
    ';

    $orderParams = [
        $customerName,
        $orderType,
        $customerType,
        $roomId,
        $tableNumber,
        $orderPeriod,
        $subtotal,
        $vatAmount,
        $total,
        $paymentMethod,
        $paymentStatus,
        $orderStatus,
        $kitchenStatus
    ];

    $orderRes = pg_query_params(
        $pgConnection,
        $orderSql,
        $orderParams
    );

    if (!$orderRes) {
        throw new Exception(
            'Order header insert failed: ' .
            pg_last_error($pgConnection)
        );
    }

    $orderRow = pg_fetch_assoc($orderRes);

    if (!$orderRow || empty($orderRow['id'])) {
        throw new Exception('The new order ID was not returned.');
    }

    $orderId = (int)$orderRow['id'];

    // --------------------------------------------------------
    // 9B. INSERT ORDER ITEMS
    // --------------------------------------------------------

    $itemSql = '
        INSERT INTO public.restaurant_order_items (
            order_id,
            food_id,
            quantity,
            unit_price,
            total_price,
            created_at
        )
        VALUES ($1, $2, $3, $4, $5, NOW())
    ';

    foreach ($processedItems as $pItem) {
        $itemRes = pg_query_params(
            $pgConnection,
            $itemSql,
            [
                $orderId,
                $pItem['food_id'],
                $pItem['quantity'],
                $pItem['unit_price'],
                $pItem['total_price']
            ]
        );

        if (!$itemRes) {
            throw new Exception(
                'Order item insert failed: ' .
                pg_last_error($pgConnection)
            );
        }
    }

    // --------------------------------------------------------
    // 9C. COMMIT TRANSACTION
    // --------------------------------------------------------

    if (!pg_query($pgConnection, 'COMMIT')) {
        throw new Exception(
            'Order transaction commit failed: ' .
            pg_last_error($pgConnection)
        );
    }

    // --------------------------------------------------------
    // 9D. REDIRECT AFTER SUCCESS
    // --------------------------------------------------------

    header(
        'Location: index.php?success=order_created&id=' .
        $orderId
    );
    exit;

} catch (Throwable $e) {
    pg_query($pgConnection, 'ROLLBACK');

    error_log(
        'Suva Hotel restaurant order save failed: ' .
        $e->getMessage()
    );

    http_response_code(500);

    exit(
        'The order could not be saved. Please check the application ' .
        'error log before trying again.'
    );
}
