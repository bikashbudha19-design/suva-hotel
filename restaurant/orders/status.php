
<?php
session_start();

/*
=========================================================
 SUVA HOTEL - RESTAURANT ORDER STATUS MANAGEMENT
 File: restaurant/orders/status.php

 IMPORTANT:
 Updates only restaurant_orders.order_status.
 Does not modify payment or billing records.
=========================================================
*/

// 1. LOGIN AND ROLE CHECK
if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit;
}

$role = strtolower(trim($_SESSION["role"] ?? ""));

if (!in_array($role, ["admin", "staff"], true)) {
    http_response_code(403);
    exit("Access denied.");
}

// 2. DATABASE CONNECTION
require_once __DIR__ . "/../../config/database.php";

if (!$pgConnection) {
    exit("PostgreSQL connection failed.");
}

// 3. HELPERS
function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        "UTF-8"
    );
}

function redirectToOrder($id)
{
    header("Location: status.php?id=" . (int)$id);
    exit;
}

// 4. VALIDATE ORDER ID
$orderId = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT
);

if (!$orderId || $orderId <= 0) {
    http_response_code(400);
    exit("Invalid order ID.");
}

// 5. CSRF TOKEN
if (empty($_SESSION["restaurant_status_token"])) {
    $_SESSION["restaurant_status_token"] =
        bin2hex(random_bytes(32));
}

// 6. HANDLE STATUS UPDATE
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $token = $_POST["csrf_token"] ?? "";

    if (
        !is_string($token) ||
        !hash_equals(
            $_SESSION["restaurant_status_token"],
            $token
        )
    ) {
        http_response_code(403);
        exit("Invalid security token. Please reopen this page.");
    }

    $newStatus = $_POST["new_status"] ?? "";

    // Only these transitions are permitted.
    $transitions = [
        "Pending"   => "Preparing",
        "Preparing" => "Ready",
        "Ready"     => "Completed"
    ];

    if (!in_array($newStatus, array_values($transitions), true)) {
        $_SESSION["status_message"] =
            "Invalid status selected.";
        redirectToOrder($orderId);
    }

    // Get the current status from the database.
    $currentResult = pg_query_params(
        $pgConnection,
        "SELECT order_status
         FROM restaurant_orders
         WHERE id = $1",
        [$orderId]
    );

    if (
        !$currentResult ||
        pg_num_rows($currentResult) !== 1
    ) {
        http_response_code(404);
        exit("Restaurant order not found.");
    }

    $currentOrder = pg_fetch_assoc($currentResult);
    $currentStatus = trim($currentOrder["order_status"] ?? "");

    // Prevent skipping steps or updating completed orders.
    if (
        !isset($transitions[$currentStatus]) ||
        $transitions[$currentStatus] !== $newStatus
    ) {
        $_SESSION["status_message"] =
            "This status transition is not allowed. Please refresh and try again.";
        redirectToOrder($orderId);
    }

    // Update ONLY order_status, and only if it has not changed.
    $updateResult = pg_query_params(
        $pgConnection,
        "UPDATE restaurant_orders
         SET order_status = $1
         WHERE id = $2
           AND order_status = $3
         RETURNING id",
        [
            $newStatus,
            $orderId,
            $currentStatus
        ]
    );

    if ($updateResult && pg_num_rows($updateResult) === 1) {
        $_SESSION["status_message"] =
            "Order #" . $orderId .
            " status updated to " . $newStatus . ".";
    } else {
        $_SESSION["status_message"] =
            "Update failed. The order may have changed. Please refresh.";
    }

    redirectToOrder($orderId);
}

// 7. FETCH ORDER DETAILS
$orderResult = pg_query_params(
    $pgConnection,
    "SELECT
        id,
        customer_name,
        customer_type,
        order_type,
        order_period,
        total_amount,
        payment_method,
        payment_status,
        order_status,
        created_at
     FROM restaurant_orders
     WHERE id = $1",
    [$orderId]
);

if (!$orderResult || pg_num_rows($orderResult) !== 1) {
    http_response_code(404);
    exit("Restaurant order not found.");
}

$order = pg_fetch_assoc($orderResult);
$currentStatus = trim($order["order_status"] ?? "");

$transitions = [
    "Pending"   => "Preparing",
    "Preparing" => "Ready",
    "Ready"     => "Completed"
];

$nextStatus = $transitions[$currentStatus] ?? null;

$message = $_SESSION["status_message"] ?? "";
unset($_SESSION["status_message"]);

$csrfToken = $_SESSION["restaurant_status_token"];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Order Status #<?= (int)$orderId ?> - Suva Hotel</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 20px;
    font-family: Arial, Helvetica, sans-serif;
    background: #f1f5f9;
    color: #1f2937;
}

.container {
    max-width: 800px;
    margin: 25px auto;
}

.header {
    background: linear-gradient(135deg, #064e3b, #0f766e);
    color: white;
    padding: 25px;
    border-radius: 16px 16px 0 0;
}

.header h1 {
    margin: 0 0 8px;
}

.card {
    background: white;
    padding: 25px;
    border-radius: 0 0 16px 16px;
    box-shadow: 0 8px 25px rgba(0,0,0,.06);
}

.details {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 15px;
}

.detail {
    background: #f8fafc;
    padding: 14px;
    border-radius: 10px;
    overflow-wrap: anywhere;
}

.detail small {
    display: block;
    color: #64748b;
    margin-bottom: 7px;
}

.status {
    display: inline-block;
    padding: 7px 12px;
    border-radius: 20px;
    background: #fef3c7;
    color: #92400e;
    font-weight: bold;
}

.message {
    padding: 13px;
    background: #ecfdf5;
    color: #065f46;
    border-radius: 9px;
    margin-bottom: 20px;
}

button, .link {
    display: inline-block;
    border: 0;
    border-radius: 9px;
    padding: 12px 17px;
    font-weight: bold;
    text-decoration: none;
    cursor: pointer;
    font-size: 14px;
}

button {
    background: #0f766e;
    color: white;
}

.link {
    background: #e2e8f0;
    color: #1f2937;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 25px;
}

.note {
    color: #64748b;
    font-size: 13px;
    line-height: 1.6;
}

@media (max-width: 550px) {
    .details {
        grid-template-columns: 1fr;
    }

    body {
        padding: 10px;
    }

    .header, .card {
        padding: 18px;
    }
}
</style>
</head>

<body>
<div class="container">

    <div class="header">
        <h1>🍽️ Order #<?= (int)$orderId ?></h1>
        <div>Suva Hotel Restaurant — Kitchen Status</div>
    </div>

    <div class="card">

        <?php if ($message !== ""): ?>
            <div class="message"><?= e($message) ?></div>
        <?php endif; ?>

        <h2>Order Information</h2>

        <div class="details">
            <div class="detail">
                <small>Customer</small>
                <strong><?= e($order["customer_name"]) ?></strong>
            </div>

            <div class="detail">
                <small>Customer Type</small>
                <strong><?= e($order["customer_type"]) ?></strong>
            </div>

            <div class="detail">
                <small>Order Type</small>
                <strong><?= e($order["order_type"]) ?></strong>
            </div>

            <div class="detail">
                <small>Order Period</small>
                <strong><?= e($order["order_period"]) ?></strong>
            </div>

            <div class="detail">
                <small>Current Order Status</small>
                <span class="status"><?= e($currentStatus) ?></span>
            </div>

            <div class="detail">
                <small>Order Total</small>
                <strong>Rs. <?= number_format((float)$order["total_amount"], 2) ?></strong>
            </div>

            <div class="detail">
                <small>Payment Method</small>
                <strong><?= e($order["payment_method"]) ?></strong>
            </div>

            <div class="detail">
                <small>Payment Status</small>
                <strong><?= e($order["payment_status"]) ?></strong>
            </div>
        </div>

        <h2>Kitchen Progress</h2>

        <?php if ($nextStatus !== null): ?>

            <p>
                Current stage: <strong><?= e($currentStatus) ?></strong>
            </p>

            <p>
                Next stage: <strong><?= e($nextStatus) ?></strong>
            </p>

            <form method="POST"
                  action="status.php?id=<?= (int)$orderId ?>"
                  onsubmit="return confirm('Update this order to <?= e($nextStatus) ?>?');">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >

                <input
                    type="hidden"
                    name="new_status"
                    value="<?= e($nextStatus) ?>"
                >

                <button type="submit">
                    Update to <?= e($nextStatus) ?>
                </button>
            </form>

        <?php elseif (strcasecmp($currentStatus, "Completed") === 0): ?>

            <p>✅ This order is marked Completed.</p>

        <?php else: ?>

            <p>
                No automatic next step is available for this status.
                Please check the order before making any changes.
            </p>

        <?php endif; ?>

        <p class="note">
            Order status is separate from payment status.
            This page does not modify payment history, payment method,
            VAT, order totals, or food items.
        </p>

        <div class="actions">
            <a class="link" href="view.php?id=<?= (int)$orderId ?>">
                ← Order Details
            </a>

            <a class="link" href="index.php">
                All Orders
            </a>
        </div>

    </div>
</div>
</body>
</html>
