<?php
/**
 * ============================================================
 * SUVA HOTEL - STAFF RESTAURANT ORDER ENTRY
 * PostgreSQL / PHP 8
 * File: staff_restaurant/add.php
 * ============================================================
 */

session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

/* ------------------------------------------------------------
   1. LOGIN PROTECTION
------------------------------------------------------------ */

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$username =$_SESSION['username'] ?? 'Staff';
$role = strtolower($_SESSION['role'] ?? 'staff');

/* ------------------------------------------------------------
   2. DATABASE CONNECTION
------------------------------------------------------------ */

require_once __DIR__ . '/../config/database.php';

if (!isset($pgConnection) || !$pgConnection) {
    exit('Database connection failed. Check config/database.php.');
}

/* ------------------------------------------------------------
   3. HELPERS
------------------------------------------------------------ */

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function queryOrFail($connection, string$sql)
{
    $result = pg_query($connection,$sql);

    if ($result === false) {
        error_log('Staff restaurant query error: ' . pg_last_error($connection));
        throw new RuntimeException(
            'A database query failed. Please check your restaurant table structure.'
        );
    }

    return $result;
}

function getTableColumns($connection, string$table): array
{
    $sql = "
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = $1
        ORDER BY ordinal_position
    ";

    $result = pg_query_params($connection, $sql, [$table]);

    if ($result === false) {
        return [];
    }

    $columns = [];

    while ($row = pg_fetch_assoc($result)) {
        $columns[] =$row['column_name'];
    }

    pg_free_result($result);

    return $columns;
}

/* ------------------------------------------------------------
   4. LOAD RESTAURANT FOOD MENU
------------------------------------------------------------ */

$foods = [];$errorMessage = '';
$successMessage = '';$menuTable = 'restaurant_foods';

try {
    $foodColumns = getTableColumns($pgConnection,$menuTable);

    if (!$foodColumns) {
        throw new RuntimeException(
            'The restaurant_foods table was not found in the current PostgreSQL schema.'
        );
    }

    $foodNameColumn = in_array('food_name',$foodColumns, true)
        ? 'food_name'
        : (in_array('name', $foodColumns, true) ? 'name' : null);

    if (!$foodNameColumn || !in_array('price', $foodColumns, true)) {
        throw new RuntimeException(
            'restaurant_foods must contain a food_name or name column and a price column.'
        );
    }

    $selectColumns = [
        'id',
        $foodNameColumn . ' AS food_name',
        'price'
    ];

    foreach (['category', 'image', 'description', 'status'] as $optionalColumn) {
        if (in_array($optionalColumn,$foodColumns, true)) {
            $selectColumns[] =$optionalColumn;
        }
    }

    $foodSql = 'SELECT ' . implode(', ', $selectColumns) . ' FROM restaurant_foods';

    if (in_array('status', $foodColumns, true)) {$foodSql .= " WHERE LOWER(CAST(status AS TEXT)) IN ('available', 'active', '1', 'true')";
    }

    $foodSql .= ' ORDER BY ' .$foodNameColumn . ' ASC';

    $foodResult = queryOrFail($pgConnection,$foodSql);

    while ($food = pg_fetch_assoc($foodResult)) {
        $foods[] =$food;
    }

    pg_free_result($foodResult);

} catch (Throwable $e) {
    $errorMessage =$e->getMessage();
}

/* ------------------------------------------------------------
   5. PROCESS ORDER FORM
------------------------------------------------------------ */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errorMessage) {
    $customerName = trim($_POST['customer_name'] ?? '');
    $customerType = trim($_POST['customer_type'] ?? 'Walk-In');
    $orderType    = trim($_POST['order_type'] ?? 'Dine-In');
    $orderPeriod  = trim($_POST['order_period'] ?? 'Day');
    $tableNumber  = trim($_POST['table_number'] ?? '');
    $notes        = trim($_POST['notes'] ?? '');
    $foodIds      =$_POST['food_id'] ?? [];
    $quantities   =$_POST['quantity'] ?? [];

    $allowedCustomerTypes = ['Hotel Guest', 'Swimming Guest', 'Walk-In'];
    $allowedOrderTypes    = ['Dine-In', 'Swimming Pool', 'Takeaway'];$allowedPeriods       = ['Day', 'Night', 'Swimming'];

    if ($customerName === '') {$errorMessage = 'Please enter customer name.';
    } elseif (!in_array($customerType, $allowedCustomerTypes, true)) {$errorMessage = 'Invalid customer type.';
    } elseif (!in_array($orderType, $allowedOrderTypes, true)) {$errorMessage = 'Invalid order type.';
    } elseif (!in_array($orderPeriod, $allowedPeriods, true)) {$errorMessage = 'Invalid order period.';
    } elseif (empty($foodIds) || !is_array($foodIds)) {$errorMessage = 'Please select at least one food item.';
    } else {
        $selectedItems = [];$totalAmount   = 0.00;

        foreach ($foodIds as $rawFoodId) {$foodId   = filter_var($rawFoodId, FILTER_VALIDATE_INT);$quantity = filter_var($quantities[$foodId] ?? 1, FILTER_VALIDATE_INT);

           if (!$foodId || !$quantity || $quantity < 1) {
                continue;
            }

            $foodResult = pg_query_params($pgConnection,
                "SELECT id, food_name, price FROM restaurant_foods WHERE id = $1",
                [$foodId]
            );

            if ($foodResult &&$food = pg_fetch_assoc($foodResult)) {$price = (float)$food['price'];$lineTotal = $price * $quantity;
                $totalAmount +=$lineTotal;

                $selectedItems[] = [
                    'food_id'    => (int)$food['id'],
                    'food_name'  => $food['food_name'],
                    'quantity'   => $quantity,
                    'price'      => $price,
                    'line_total' => $lineTotal
                ];
                pg_free_result($foodResult);
            }
        }

        if (empty($selectedItems) || $totalAmount <= 0) {$errorMessage = 'Total order amount must be greater than zero.';
        } else {
            $insertOrderSql = "
                INSERT INTO restaurant_orders 
                (customer_name, customer_type, order_type, order_period, table_number, notes, total_amount, order_status, payment_status, created_at)
                VALUES ($1, $2, $3, $4, $5, $6, $7, 'Pending', 'Unpaid', NOW())
                RETURNING id
            ";

            $res = pg_query_params($pgConnection, $insertOrderSql, [$customerName,
                $customerType,$orderType,
                $orderPeriod,$tableNumber,
                $notes,
                $totalAmount
            ]);

            if ($res && $row = pg_fetch_assoc($res)) {
                $orderId =$row['id'];
                
                foreach ($selectedItems as$item) {
                    pg_query_params($pgConnection, "
                        INSERT INTO restaurant_order_items (order_id, food_id, quantity, price)
                        VALUES ($1, $2, $3, $4)
                    ", [$orderId,$item['food_id'], $item['quantity'],$item['price']]);
                }

                $successMessage = "Order #{$orderId} created successfully with Total: Rs. " . number_format($totalAmount, 2); } else {$errorMessage = "Failed to save order. Please check database constraints.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Restaurant Order - Suva Hotel POS</title>
    
    <!-- GSAP Animation Engine -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script>

    <style>
        :root {
            --green: #073b2b;
            --green-dark: #03251b;
            --green-mid: #0d6044;
            --gold: #d5b15b;
            --gold-light: #f4e4b0;
            --cream: #f7f6f0;
            --white: #ffffff;
            --text: #20332b;
            --muted: #7c8981;
            --border: #e4e9e1;
            --shadow: 0 14px 40px rgba(3, 37, 27, 0.09);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: var(--text);
            background: radial-gradient(ellipse at top right, rgba(213, 177, 91, .10), transparent 35%), #f3f5f0;
            font-family: "Segoe UI", Arial, sans-serif;
            line-height: 1.6;
        }

        .topbar {
            background: linear-gradient(120deg, var(--green-dark), var(--green), #10533c);
            color: white;
            padding: 20px clamp(18px, 5vw, 70px);
            border-bottom: 3px solid var(--gold);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .brand {
            font-family: Georgia, serif;
            font-size: 24px;
            font-weight: 800;
            letter-spacing: 2px;
        }

        .brand span {
            color: var(--gold-light);
        }

        .user {
            color: #e1e9e3;
            font-size: 13px;
            background: rgba(255, 255, 255, .08);
            border: 1px solid rgba(255, 255, 255, .12);
            padding: 8px 16px;
            border-radius: 30px;
        }

        .container {
            width: min(1180px, 94%);
            margin: 30px auto 60px;
        }

        .heading h1 {
            margin: 0 0 8px;
            color: var(--green);
            font-family: Georgia, serif;
            font-size: clamp(28px, 4vw, 38px);
        }

        .heading p {
            margin: 0 0 20px;
            color: var(--muted);
            font-size: 14px;
        }

        .card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 25px;
            box-shadow: var(--shadow);
        }

        .card h2 {
            margin: 0 0 20px;
            color: var(--green);
            font-size: 18px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 12px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .field label {
            color: #34493d;
            font-size: 13px;
            font-weight: 700;
        }

        input:not([type="checkbox"]), select, textarea {
            width: 100%;
            min-height: 44px;
            padding: 10px 14px;
            color: var(--text);
            background: #fcfdfb;
            border: 1px solid #dce4db;
            border-radius: 8px;
            font-size: 14px;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--gold);
            box-shadow: 0 0 0 3px rgba(213, 177, 91, .15);
        }

        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }

        .food-card {
            background: #fff;
            border: 1px solid #e0e8df;
            border-radius: 14px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: transform .25s ease, box-shadow .25s ease;
        }

        .food-image-wrapper {
            position: relative;
            height: 160px;
            overflow: hidden;
            background: #eef2eb;
        }

        .food-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform .4s ease;
        }

        .food-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(7, 59, 43, 0.85);
            color: #fff;
            font-size: 11px;
            padding: 4px 10px;
            border-radius: 20px;
            backdrop-filter: blur(4px);
        }

        .food-details {
            padding: 16px;
            display: flex;
            flex-direction: column;
            flex-grow: 1;
            justify-content: space-between;
        }

        .food-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 8px;
        }

        .food-title {
            margin: 0;
            font-size: 16px;
            color: var(--green);
            font-weight: 700;
        }

        .food-price {
            background: #f8efcf;
            color: #785b17;
            font-size: 13px;
            font-weight: 800;
            padding: 4px 8px;
            border-radius: 6px;
            white-space: nowrap;
        }

        .food-desc {
            font-size: 12px;
            color: var(--muted);
            margin: 0 0 16px;
            line-height: 1.4;
        }

        .food-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding-top: 12px;
            border-top: 1px solid var(--border);
        }

        .select-checkbox-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 600;
            color: var(--green);
            cursor: pointer;
        }

        .qty-control {
            display: flex;
            align-items: center;
            border: 1px solid #dce4db;
            border-radius: 6px;
            overflow: hidden;
        }

        .qty-btn {
            background: #f2f5ef;
            border: none;
            padding: 6px 10px;
            cursor: pointer;
            font-weight: bold;
            color: var(--green);
        }

        .qty-input {
            width: 45px !important;
            min-height: unset !important;
            border: none !important;
            text-align: center;
            padding: 4px !important;
            border-radius: 0 !important;
        }

        .sticky-summary {
            position: sticky;
            bottom: 15px;
            z-index: 10;
            background: rgba(255, 253, 244, 0.96);
            border: 1px solid #dfcb86;
            padding: 16px 24px;
            border-radius: 14px;
            box-shadow: 0 8px 25px rgba(3, 37, 27, 0.12);
            backdrop-filter: blur(8px);
            margin-top: 25px;
        }

        .summary-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .summary-label {
            font-size: 12px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .total-amount-display {
            font-size: 26px;
            font-weight: 800;
            color: var(--green);
        }

        .btn-submit-order {
            background: linear-gradient(120deg, var(--green-dark), var(--green-mid));
            color: white;
            border: 1px solid var(--gold);
            padding: 12px 28px;
            font-size: 14px;
            font-weight: 700;
            border-radius: 8px;
            cursor: pointer;
            transition: transform .2s ease;
        }

        .btn-submit-order:hover {
            transform: translateY(-2px);
        }

        .alert {
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-error {
            background: #fff0ee;
            color: #8e2929;
            border: 1px solid #efc9c4;
        }

        .alert-success {
            background: #eaf7ed;
            color: #175b35;
            border: 1px solid #c2e4c9;
        }
    </style>
</head>
<body>

<div class="topbar">
    <div class="brand">SUVA <span>HOTEL</span></div>
    <div class="user">Logged in as: <?= h($username) ?> (<?= h($role) ?>)</div>
</div>

<div class="container">
    <div class="heading">
        <h1>Create Restaurant Order</h1>
        <p>Record customer orders and send them directly to the kitchen workflow.</p>
    </div>

    <?php if ($errorMessage): ?>
        <div class="alert alert-error"><?= h($errorMessage) ?></div>
    <?php endif; ?>

    <?php if ($successMessage): ?>
        <div class="alert alert-success"><?= h($successMessage) ?></div>
    <?php endif; ?>

    <form method="POST" action="add.php" id="orderForm">
        <!-- Customer Info Card -->
        <div class="card">
            <h2>01 — Customer Details</h2>
            <div class="grid">
                <div class="field">
                    <label for="customer_name">Customer Name *</label>
                    <input type="text" name="customer_name" id="customer_name" required placeholder="e.g. Shyam Poudel" value="<?= h($_POST['customer_name'] ?? '') ?>">
                </div>

                <div class="field">
                    <label for="customer_type">Customer Type *</label>
                    <select name="customer_type" id="customer_type" required>
                        <option value="Walk-In">Walk-In</option>
                        <option value="Hotel Guest">Hotel Guest</option>
                        <option value="Swimming Guest">Swimming Guest</option>
                    </select>
                </div>

                <div class="field">
                    <label for="order_type">Order Type *</label>
                    <select name="order_type" id="order_type" required>
                        <option value="Dine-In">Dine-In</option>
                        <option value="Takeaway">Takeaway</option>
                        <option value="Swimming Pool">Swimming Pool</option>
                    </select>
                </div>

                <div class="field">
                    <label for="order_period">Order Period *</label>
                    <select name="order_period" id="order_period" required>
                        <option value="Day">Day</option>
                        <option value="Night">Night</option>
                        <option value="Swimming">Swimming</option>
                    </select>
                </div>

                <div class="field">
                    <label for="table_number">Table Number</label>
                    <input type="text" name="table_number" id="table_number" placeholder="e.g. 9" value="<?= h($_POST['table_number'] ?? '') ?>">
                </div>

                <div class="field" style="grid-column: 1 / -1;">
                    <label for="notes">Order Notes</label>
                    <textarea name="notes" id="notes" placeholder="Special instructions or requests..."><?= h($_POST['notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Food Menu Selection -->
        <div class="card">
            <h2>02 — Select Food Items</h2>
            <div class="menu-grid">
                <?php foreach ($foods as$food): ?>
                    <?php 
                      $foodId = (int)$food['id'];

$imageFile = trim((string)($food['image'] ?? ''));

if ($imageFile !== '') {
    // Use the existing restaurant food image folder.
    $imagePath = '../restaurant/food/images/' . rawurlencode(basename($imageFile));
} else {
    $imagePath = '../restaurant/food/images/default-food.jpg';
}
                    ?>
                    <div class="food-card" data-food-id="<?= $foodId ?>">
                        <div class="food-image-wrapper">
                            <img src="<?= $imagePath ?>" alt="<?= h($food['food_name']) ?>" class="food-img" loading="lazy" onerror="this.src='https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80';">
                            <span class="food-badge"><?= h($food['category'] ?? 'Special') ?></span>
                        </div>
                        
                        <div class="food-details">
                            <div>
                                <div class="food-header">
                                    <h3 class="food-title"><?= h($food['food_name']) ?></h3>
                                    <span class="food-price">Rs. <?= number_format((float)$food['price'], 2) ?></span>
                                </div>
                                <p class="food-desc"><?= h($food['description'] ?? 'Freshly prepared using authentic ingredients.') ?></p>
                            </div>
                            
                            <div class="food-actions">
                                <label class="select-checkbox-label">
                                    <input type="checkbox" name="food_id[]" value="<?= $foodId ?>" class="food-checkbox" onchange="calculateGrandTotal()">
                                    <span>Add</span>
                                </label>
                                <div class="qty-control">
                                    <button type="button" class="qty-btn" onclick="updateQty(<?= $foodId ?>, -1)">-</button>
                                    <input type="number" name="quantity[<?= $foodId ?>]" id="qty-<?= $foodId ?>" value="1" min="1" max="100" class="qty-input" onchange="calculateGrandTotal()">
                                    <button type="button" class="qty-btn" onclick="updateQty(<?= $foodId ?>, 1)">+</button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Floating Order Summary Bar -->
        <div class="sticky-summary">
            <div class="summary-content">
                <div>
                    <span class="summary-label">Live Order Total</span>
                    <div class="total-amount-display" id="grandTotalDisplay">Rs. 0.00</div>
                </div>
                <button type="submit" class="btn-submit-order">Submit Order</button>
            </div>
        </div>
    </form>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    gsap.registerPlugin(ScrollTrigger);

    // Fade and slide-in for food cards
    gsap.from(".food-card", {
        duration: 0.6,
        y: 30,
        opacity: 0,
        stagger: 0.05,
        ease: "power2.out"
    });

    // Animate sticky summary bar entrance
    gsap.from(".sticky-summary", {
        duration: 0.8,
        y: 60,
        opacity: 0,
        ease: "back.out(1.2)",
        delay: 0.3
    });

    // Hover scale effect on food cards
    document.querySelectorAll(".food-card").forEach(card => {
        card.addEventListener("mouseenter", () => {
            gsap.to(card, { y: -5, duration: 0.2, ease: "power1.out" });
            gsap.to(card.querySelector(".food-img"), { scale: 1.06, duration: 0.3 });
        });
        card.addEventListener("mouseleave", () => {
            gsap.to(card, { y: 0, duration: 0.2, ease: "power1.out" });
            gsap.to(card.querySelector(".food-img"), { scale: 1, duration: 0.3 });
        });
    });
});

function updateQty(foodId, change) {
    const qtyInput = document.getElementById(`qty-${foodId}`);
    let val = parseInt(qtyInput.value) || 1;
    val = Math.max(1, val + change);
    qtyInput.value = val;
    calculateGrandTotal();
}

function calculateGrandTotal() {
    let total = 0;
    document.querySelectorAll(".food-card").forEach(card => {
        const checkbox = card.querySelector(".food-checkbox");
        if (checkbox && checkbox.checked) {
            const priceText = card.querySelector(".food-price").textContent.replace(/[^0-9.]/g, '');
            const price = parseFloat(priceText) || 0;
            const qty = parseInt(card.querySelector(".qty-input").value) || 1;
            total += price * qty;
        }
    });

    const display = document.getElementById("grandTotalDisplay");
    display.textContent = "Rs. " + total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    
    gsap.fromTo(display, { scale: 1.1, color: "#d5b15b" }, { scale: 1, color: "#073b2b", duration: 0.25 });
}
const orderTypeSelect = document.getElementById('order_type');
const orderPeriodSelect = document.getElementById('order_period');

function syncOrderPeriod() {
    if (orderTypeSelect.value === 'Swimming Pool') {
        orderPeriodSelect.value = 'Swimming';
        orderPeriodSelect.disabled = true;
    } else {
        orderPeriodSelect.disabled = false;

        if (orderPeriodSelect.value === 'Swimming') {
            orderPeriodSelect.value = 'Day';
        }
    }
}

orderTypeSelect.addEventListener('change', syncOrderPeriod);
syncOrderPeriod();
</script>

</body>
</html>