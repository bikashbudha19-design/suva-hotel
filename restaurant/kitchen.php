
<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
// ============================================================
// SUVA HOTEL - KITCHEN DISPLAY SYSTEM
// File: /restaurant/kitchen.php
// Database: PostgreSQL | PHP 8.0 compatible
// ============================================================

session_start();

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ============================================================
// 1. LOGIN AND ROLE CHECK
// ============================================================

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$role = strtolower(trim($_SESSION['role'] ?? ''));

if (!in_array($role, ['admin', 'staff', 'chef'], true)) {
    http_response_code(403);
    exit('Access denied. Chef or authorized staff only.');
}

require_once __DIR__ . '/../config/database.php';

if (!isset($pgConnection) || !$pgConnection) {
    http_response_code(500);
    exit('Database connection failed.');
}

// ============================================================
// 2. HELPER
// ============================================================

function kitchenEscape($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

// ============================================================
// 3. ACTIVE ORDER CONDITION
// ============================================================

$activeCondition = "
    LOWER(COALESCE(o.order_status, ''))
        NOT IN ('completed', 'cancelled')
    AND LOWER(COALESCE(o.kitchen_status, 'new'))
        <> 'served'
";

// ============================================================
// 4. POLLING ENDPOINT
// The browser checks for changes every five seconds.
// ============================================================

if (isset($_GET['poll'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $pollSql = "
        SELECT
            o.id,
            o.kitchen_status,
            o.created_at
        FROM public.restaurant_orders o
        WHERE $activeCondition
        ORDER BY o.created_at ASC, o.id ASC
    ";

    $pollResult = pg_query($pgConnection, $pollSql);

    if (!$pollResult) {
        error_log(
            'Kitchen polling failed: ' .
            pg_last_error($pgConnection)
        );

        http_response_code(500);
        echo json_encode([
            'error' => 'Unable to load kitchen orders.'
        ]);
        exit;
    }

    $pollOrders = [];

    while ($row = pg_fetch_assoc($pollResult)) {
        $pollOrders[] = [
            'id' => (int) $row['id'],
            'status' => $row['kitchen_status'] ?: 'New'
        ];
    }

    echo json_encode([
        'orders' => $pollOrders
    ]);
    exit;
}

// ============================================================
// 5. CSRF TOKEN
// ============================================================

if (empty($_SESSION['kitchen_csrf'])) {
    $_SESSION['kitchen_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['kitchen_csrf'];

// ============================================================
// 6. PROCESS CHEF ACTIONS
// ============================================================

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        $errorMessage = 'Your session verification failed. Refresh the page and try again.';
    } else {

        $orderId = filter_input(
            INPUT_POST,
            'order_id',
            FILTER_VALIDATE_INT
        );

        $action = $_POST['action'] ?? '';

        if (!$orderId || $orderId < 1) {
            $errorMessage = 'Invalid order number.';

        } elseif (!in_array($action, ['start', 'ready'], true)) {
            $errorMessage = 'Invalid kitchen action.';

        } else {

            if ($action === 'start') {

                $updateSql = "
                    UPDATE public.restaurant_orders
                    SET
                        kitchen_status = 'Preparing',
                        kitchen_started_at = CURRENT_TIMESTAMP
                    WHERE id = $1
                      AND LOWER(COALESCE(kitchen_status, 'new')) = 'new'
                      AND LOWER(COALESCE(order_status, ''))
                          NOT IN ('completed', 'cancelled')
                ";

            } else {

                $updateSql = "
                    UPDATE public.restaurant_orders
                    SET
                        kitchen_status = 'Ready',
                        food_ready_at = CURRENT_TIMESTAMP
                    WHERE id = $1
                      AND LOWER(COALESCE(kitchen_status, 'new'))
                          IN ('new', 'preparing')
                      AND LOWER(COALESCE(order_status, ''))
                          NOT IN ('completed', 'cancelled')
                ";
            }

            $updateResult = pg_query_params(
                $pgConnection,
                $updateSql,
                [$orderId]
            );

            if (!$updateResult) {

                error_log(
                    'Kitchen status update failed: ' .
                    pg_last_error($pgConnection)
                );

                $errorMessage = 'Could not update the order. Please try again.';

            } elseif (pg_affected_rows($updateResult) === 0) {

                $errorMessage = 'The order has already changed or is no longer active.';

            } else {

                header('Location: kitchen.php');
                exit;
            }
        }
    }
}

// ============================================================
// 7. LOAD ACTIVE ORDERS AND THEIR FOOD ITEMS
// ============================================================

$orderSql = "
    SELECT
        o.id AS order_id,
        o.customer_name,
        o.order_type,
        o.customer_type,
        o.table_number,
        o.room_id,
        o.created_at,
        o.kitchen_status,
        i.id AS item_id,
        i.quantity,
        f.food_name

    FROM public.restaurant_orders o

    LEFT JOIN public.restaurant_order_items i
        ON i.order_id = o.id

    LEFT JOIN public.restaurant_foods f
        ON f.id = i.food_id

    WHERE $activeCondition

    ORDER BY o.created_at ASC, o.id ASC, i.id ASC
";

$orderResult = pg_query($pgConnection, $orderSql);

if (!$orderResult) {
    error_log(
        'Kitchen order loading failed: ' .
        pg_last_error($pgConnection)
    );

    http_response_code(500);
    exit('Unable to load kitchen orders. Please check the database.');
}

$orders = [];

while ($row = pg_fetch_assoc($orderResult)) {

    $id = (int) $row['order_id'];

    if (!isset($orders[$id])) {

        $orders[$id] = [
            'id' => $id,
            'customer_name' => $row['customer_name'],
            'order_type' => $row['order_type'],
            'customer_type' => $row['customer_type'],
            'table_number' => $row['table_number'],
            'room_id' => $row['room_id'],
            'created_at' => $row['created_at'],
            'kitchen_status' => $row['kitchen_status'] ?: 'New',
            'items' => []
        ];
    }

    if ($row['item_id'] !== null) {
        $orders[$id]['items'][] = [
            'name' => $row['food_name'] ?: 'Unknown food item',
            'quantity' => $row['quantity']
        ];
    }
}

// Initial order states for browser polling.
$initialState = [];

foreach ($orders as $order) {
    $initialState[] = [
        'id' => $order['id'],
        'status' => $order['kitchen_status']
    ];
}

// Counts for dashboard.
$newCount = 0;
$preparingCount = 0;
$readyCount = 0;

foreach ($orders as $order) {

    $status = strtolower($order['kitchen_status']);

    if ($status === 'new') {
        $newCount++;
    } elseif ($status === 'preparing') {
        $preparingCount++;
    } elseif ($status === 'ready') {
        $readyCount++;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Suva Hotel - Kitchen Display</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f2f4f7;
    color: #1f2937;
}

header {
    background: #172033;
    color: white;
    padding: 20px;
}

.header-inner {
    max-width: 1200px;
    margin: auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

header h1 {
    margin: 0;
    font-size: 23px;
}

header a {
    color: white;
    text-decoration: none;
    background: #344158;
    padding: 10px 14px;
    border-radius: 7px;
}

main {
    max-width: 1200px;
    margin: auto;
    padding: 20px;
}

.toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.toolbar h2 {
    margin-bottom: 7px;
}

button {
    border: 0;
    padding: 12px 16px;
    border-radius: 8px;
    color: white;
    cursor: pointer;
    font-weight: bold;
    font-size: 14px;
}

button:focus-visible,
a:focus-visible {
    outline: 3px solid #60a5fa;
    outline-offset: 3px;
}

.sound-button {
    background: #374151;
}

.start-button {
    background: #2563eb;
    width: 100%;
}

.ready-button {
    background: #15803d;
    width: 100%;
}

.stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 22px;
}

.stat {
    background: white;
    border-radius: 10px;
    padding: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,.04);
}

.stat strong {
    display: block;
    font-size: 27px;
    margin-top: 7px;
}

.order-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 18px;
}

.order-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 3px 12px rgba(0,0,0,.07);
    border-top: 5px solid #2563eb;
}

.order-card.preparing {
    border-top-color: #d97706;
}

.order-card.ready {
    border-top-color: #16a34a;
}

.order-heading {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    align-items: flex-start;
}

.order-heading h2 {
    margin: 0;
    font-size: 22px;
}

.status {
    display: inline-block;
    border-radius: 20px;
    background: #dbeafe;
    color: #1d4ed8;
    padding: 6px 10px;
    font-size: 12px;
    font-weight: bold;
    white-space: nowrap;
}

.preparing .status {
    background: #fef3c7;
    color: #92400e;
}

.ready .status {
    background: #dcfce7;
    color: #166534;
}

.order-info {
    margin: 15px 0;
    line-height: 1.8;
    color: #4b5563;
    overflow-wrap: anywhere;
}

.items {
    border-top: 1px solid #e5e7eb;
    margin-top: 12px;
    padding-top: 12px;
}

.food-item {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid #f0f1f3;
}

.food-item strong {
    overflow-wrap: anywhere;
}

.quantity {
    font-weight: bold;
    white-space: nowrap;
}

.action-area {
    margin-top: 18px;
}

.empty {
    background: white;
    border-radius: 12px;
    text-align: center;
    padding: 50px 20px;
    color: #6b7280;
}

.error {
    background: #fee2e2;
    color: #991b1b;
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 16px;
}

.notice {
    background: #dcfce7;
    color: #166534;
    padding: 14px;
    border-radius: 8px;
    margin-bottom: 18px;
    font-weight: bold;
}

footer {
    text-align: center;
    padding: 25px;
    color: #6b7280;
    font-size: 13px;
}

@media (max-width: 600px) {
    main {
        padding: 12px;
    }

    header {
        padding: 16px 12px;
    }

    header h1 {
        font-size: 19px;
    }

    .stats {
        gap: 8px;
    }

    .stat {
        padding: 11px;
        font-size: 13px;
    }

    .stat strong {
        font-size: 23px;
    }
}
</style>
</head>

<body>

<header>
    <div class="header-inner">
        <h1>👨‍🍳 Suva Hotel Kitchen</h1>
        <a href="index.php">← Restaurant Dashboard</a>
    </div>
</header>

<main>

    <div class="toolbar">
        <div>
            <h2>Kitchen Orders</h2>
            <div>Orders refresh automatically every five seconds.</div>
        </div>

        <button
            type="button"
            class="sound-button"
            id="soundButton"
        >
            🔔 Enable Sound
        </button>
    </div>

    <div class="stats">
        <div class="stat">
            New Orders
            <strong><?= $newCount ?></strong>
        </div>

        <div class="stat">
            Preparing
            <strong><?= $preparingCount ?></strong>
        </div>

        <div class="stat">
            Ready
            <strong><?= $readyCount ?></strong>
        </div>
    </div>

    <div
        id="newOrderNotice"
        class="notice"
        style="display:none;"
        role="status"
        aria-live="polite"
    >
        🔔 New restaurant order received!
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="error"><?= kitchenEscape($errorMessage) ?></div>
    <?php endif; ?>

    <?php if (empty($orders)): ?>

        <div class="empty">
            <h2>🍽️ No Active Orders</h2>
            <p>New restaurant orders will appear here automatically.</p>
        </div>

    <?php else: ?>

        <div class="order-grid">

        <?php foreach ($orders as $order): ?>

            <?php
            $status = strtolower(trim($order['kitchen_status']));

            $isReady = ($status === 'ready');
            $isPreparing = ($status === 'preparing');

            $cardClass = '';

            if ($isReady) {
                $cardClass = 'ready';
            } elseif ($isPreparing) {
                $cardClass = 'preparing';
            }
            ?>

            <section
                class="order-card <?= kitchenEscape($cardClass) ?>"
                data-order-id="<?= (int) $order['id'] ?>"
                data-kitchen-status="<?= kitchenEscape($order['kitchen_status']) ?>"
            >

                <div class="order-heading">
                    <h2>Order #<?= (int) $order['id'] ?></h2>

                    <span class="status">
                        <?= kitchenEscape($order['kitchen_status']) ?>
                    </span>
                </div>

                <div class="order-info">

                    <div>
                        <strong>Customer:</strong>
                        <?= kitchenEscape($order['customer_name'] ?: 'Walk-in') ?>
                    </div>

                    <div>
                        <strong>Type:</strong>
                        <?= kitchenEscape($order['order_type'] ?: 'Not specified') ?>
                    </div>

                    <?php if (!empty($order['table_number'])): ?>
                        <div>
                            <strong>Table:</strong>
                            <?= kitchenEscape($order['table_number']) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (
                        strtolower($order['customer_type'] ?? '') === 'hotel guest'
                    ): ?>
                        <div>
                            <strong>Room ID:</strong>
                            <?= kitchenEscape($order['room_id'] ?: 'Not specified') ?>
                        </div>
                    <?php endif; ?>

                    <div>
                        <strong>Ordered:</strong>
                        <?= kitchenEscape($order['created_at']) ?>
                    </div>

                </div>

                <div class="items">
                    <strong>🍲 Dishes to Prepare</strong>

                    <?php if (empty($order['items'])): ?>

                        <p>No food items were found for this order.</p>

                    <?php else: ?>

                        <?php foreach ($order['items'] as $item): ?>

                            <div class="food-item">
                                <strong>
                                    <?= kitchenEscape($item['name']) ?>
                                </strong>

                                <span class="quantity">
                                    × <?= kitchenEscape($item['quantity']) ?>
                                </span>
                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>
                </div>

                <div class="action-area">

                    <?php if ($status === 'new'): ?>

                        <form method="POST" action="kitchen.php">
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= kitchenEscape($csrfToken) ?>"
                            >

                            <input
                                type="hidden"
                                name="order_id"
                                value="<?= (int) $order['id'] ?>"
                            >

                            <input
                                type="hidden"
                                name="action"
                                value="start"
                            >

                            <button class="start-button" type="submit">
                                ▶ Start Preparing
                            </button>
                        </form>

                    <?php elseif ($status === 'preparing'): ?>

                        <form method="POST" action="kitchen.php">
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= kitchenEscape($csrfToken) ?>"
                            >

                            <input
                                type="hidden"
                                name="order_id"
                                value="<?= (int) $order['id'] ?>"
                            >

                            <input
                                type="hidden"
                                name="action"
                                value="ready"
                            >

                            <button class="ready-button" type="submit">
                                ✓ Food Ready — Notify Waiter
                            </button>
                        </form>

                    <?php elseif ($isReady): ?>

                        <p>
                            ✅ Food is ready. The waiter can collect
                            this order from the waiter screen.
                        </p>

                    <?php endif; ?>

                </div>

            </section>

        <?php endforeach; ?>

        </div>

    <?php endif; ?>

</main>

<footer>
    Suva Hotel Restaurant · Kitchen Display System
</footer>

<script>
const initialOrders = <?= json_encode(
    $initialState,
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_QUOT |
    JSON_HEX_AMP
) ?>;

const signatureKey = 'suvaKitchenOrderSignature';
const soundKey = 'suvaKitchenSoundEnabled';

let soundEnabled = sessionStorage.getItem(soundKey) === 'yes';
let audioContext = null;
let pollingInProgress = false;

function signature(orders) {
    return JSON.stringify(
        orders.map(order => ({
            id: Number(order.id),
            status: String(order.status || 'New')
        }))
    );
}

function playBeep() {
    if (!soundEnabled) {
        return;
    }

    try {
        const AudioContextClass =
            window.AudioContext || window.webkitAudioContext;

        if (!AudioContextClass) {
            return;
        }

        audioContext = audioContext || new AudioContextClass();

        if (audioContext.state === 'suspended') {
            audioContext.resume();
        }

        const oscillator = audioContext.createOscillator();
        const gain = audioContext.createGain();

        oscillator.type = 'sine';
        oscillator.frequency.value = 880;
        gain.gain.value = 0.15;

        oscillator.connect(gain);
        gain.connect(audioContext.destination);

        oscillator.start();

        oscillator.stop(audioContext.currentTime + 0.35);

    } catch (error) {
        // Sound is optional; polling continues if audio is unavailable.
    }
}

function enableSound() {
    const AudioContextClass =
        window.AudioContext || window.webkitAudioContext;

    if (!AudioContextClass) {
        alert('This browser does not support notification sound.');
        return;
    }

    try {
        audioContext = audioContext || new AudioContextClass();

        const resumeResult = audioContext.resume();

        if (resumeResult && typeof resumeResult.catch === 'function') {
            resumeResult.catch(() => {});
        }

        soundEnabled = true;

        sessionStorage.setItem(soundKey, 'yes');

        document.getElementById('soundButton').textContent =
            '🔔 Sound Enabled';

        playBeep();

    } catch (error) {
        alert('Could not enable sound. Please try again.');
    }
}

document.getElementById('soundButton').addEventListener(
    'click',
    enableSound
);

if (soundEnabled) {
    document.getElementById('soundButton').textContent =
        '🔔 Sound Enabled';
}

// Establish a baseline when this browser tab first opens.
// Existing orders do not trigger a false new-order alert.
if (sessionStorage.getItem(signatureKey) === null) {
    sessionStorage.setItem(
        signatureKey,
        signature(initialOrders)
    );
}

// Show the notice after a new-order alert caused a page refresh.
if (sessionStorage.getItem('suvaKitchenNewOrder') === 'yes') {
    document.getElementById('newOrderNotice').style.display = 'block';

    sessionStorage.removeItem('suvaKitchenNewOrder');
}

async function checkForUpdates() {
    if (pollingInProgress) {
        return;
    }

    pollingInProgress = true;

    try {
        const response = await fetch('kitchen.php?poll=1', {
            cache: 'no-store',
            credentials: 'same-origin'
        });

        if (!response.ok) {
            return;
        }

        const data = await response.json();

        if (!Array.isArray(data.orders)) {
            return;
        }

        const oldSignature = sessionStorage.getItem(signatureKey);
        const newSignature = signature(data.orders);

        if (oldSignature === null) {
            sessionStorage.setItem(signatureKey, newSignature);
            return;
        }

        if (oldSignature === newSignature) {
            return;
        }

        let oldOrders = [];

        try {
            oldOrders = JSON.parse(oldSignature);
        } catch (error) {
            oldOrders = [];
        }

        const oldIds = new Set(
            oldOrders.map(order => Number(order.id))
        );

        const hasNewOrder = data.orders.some(order =>
            String(order.status).toLowerCase() === 'new' &&
            !oldIds.has(Number(order.id))
        );

        // Save the new baseline before refreshing the screen.
        sessionStorage.setItem(signatureKey, newSignature);

        if (hasNewOrder) {
            sessionStorage.setItem('suvaKitchenNewOrder', 'yes');
            playBeep();
        }

        // Allow the sound to begin before refreshing the display.
        setTimeout(() => {
            window.location.reload();
        }, hasNewOrder && soundEnabled ? 500 : 100);

    } catch (error) {
        // Temporary network errors are retried on the next poll.
    } finally {
        pollingInProgress = false;
    }
}

// Check for new orders and status changes every five seconds.
setInterval(checkForUpdates, 5000);
</script>

</body>
</html>
