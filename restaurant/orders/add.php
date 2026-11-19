<?php
// ============================================================
// SUVA HOTEL - CREATE RESTAURANT ORDER
// PostgreSQL Version
// File: /restaurant/orders/add.php
// ============================================================

session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);


// ============================================================
// AUTHENTICATION
// ============================================================

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit;
}


require_once __DIR__ . "/../../config/database.php";


// ============================================================
// DATABASE CONNECTION CHECK
// ============================================================

if (!isset($pgConnection) || !$pgConnection) {
    die("Database connection failed.");
}


// ============================================================
// USER ROLE
// ============================================================

$role = strtolower($_SESSION["role"] ?? "");

if ($role !== "admin" && $role !== "staff") {
    header("Location: ../../login.php");
    exit;
}


// ============================================================
// HELPER
// ============================================================

function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        "UTF-8"
    );
}


// ============================================================
// LOAD AVAILABLE FOODS
// ============================================================

$foods = [];$foodError = "";

$foodSql = "
    SELECT
        id,
        food_name,
        category,
        image,
        price,
        status
    FROM public.restaurant_foods
    WHERE LOWER(TRIM(COALESCE(status, ''))) = 'available'
    ORDER BY
        LOWER(TRIM(COALESCE(category, 'Other'))) ASC,
        LOWER(TRIM(food_name)) ASC,
        id ASC
";

$foodResult = pg_query($pgConnection,$foodSql);

if ($foodResult) {
    while ($foodRow = pg_fetch_assoc($foodResult)) {
        $foods[] =$foodRow;
    }
} else {
    $foodError = pg_last_error($pgConnection);
}


// ============================================================
// LOAD ACTIVE HOTEL ROOMS & GUESTS
// ============================================================

$activeBookings = [];

$bookingSql = "
    SELECT 
        b.id AS booking_id,
        b.room_id,
        r.room_number,
        g.full_name AS guest_name
    FROM public.bookings b
    JOIN public.rooms r ON b.room_id = r.id
    JOIN public.guests g ON b.guest_id = g.id
    WHERE LOWER(TRIM(COALESCE(b.status, ''))) IN ('checked-in', 'active', 'confirmed')
    ORDER BY r.room_number ASC
";

$bookingResult = @pg_query($pgConnection,$bookingSql);

if ($bookingResult) {
    while ($bRow = pg_fetch_assoc($bookingResult)) {
        $activeBookings[] =$bRow;
    }
} else {
    // Fallback: Load standard room list if bookings query fails
    $roomResult = pg_query($pgConnection,
        "SELECT id, room_number FROM public.rooms ORDER BY room_number ASC"
    );
    if ($roomResult) {
        while ($room = pg_fetch_assoc($roomResult)) {$activeBookings[] = [
                'room_id' => $room['id'],
                'room_number' => $room['room_number'],
                'guest_name' => ''
            ];
        }
    }
}


// ============================================================
// FOOD IMAGE HELPER
// ============================================================

function foodImage($image)
{
    $image = trim((string)$image);

    if ($image === "") {
        return "";
    }

    if (preg_match('/^https?:\/\//i', $image)) {
        return $image;
    }

    $image = str_replace("\\", "/", $image);
    $image = explode("?", $image)[0];
    $filename = basename($image);

    if ($filename === "" || $filename === "." || $filename === "..") {
        return "";
    }

    $currentServerPath = __DIR__ . "/../food/images/" . $filename;
    if (is_file($currentServerPath)) {
        return "../food/images/" . rawurlencode($filename);
    }

    $oldServerPath = __DIR__ . "/../food/uploads/" . $filename;
    if (is_file($oldServerPath)) {
        return "../food/uploads/" . rawurlencode($filename);
    }

    return "";
}


// ============================================================
// DASHBOARD URL
// ============================================================

if ($role === "admin") {
    $dashboardUrl = "../../dashboard.php";
} else {
    $dashboardUrl = "../../staff_dashboard/staff_dashboard.php";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Restaurant Order - Suva Hotel</title>

<style>
/* GLOBAL */
* { box-sizing: border-box; }
html { scroll-behavior: smooth; }
body {
    margin: 0;
    min-height: 100vh;
    font-family: Arial, Helvetica, sans-serif;
    color: #ffffff;
    background: linear-gradient(rgba(5, 8, 15, 0.91), rgba(5, 8, 15, 0.97)),
                url("../../assets/image/hotel-lighting.jpg") center / cover fixed;
}

/* HEADER */
.header {
    min-height: 74px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 12px 30px;
    background: rgba(8, 12, 22, 0.95);
    border-bottom: 1px solid rgba(217, 180, 91, 0.20);
    position: sticky;
    top: 0;
    z-index: 100;
    backdrop-filter: blur(15px);
}
.logo { font-size: 21px; font-weight: 800; white-space: nowrap; }
.logo span { color: #d9b45b; }
.header-actions { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; }
.btn {
    display: inline-flex; align-items: center; justify-content: center;
    min-height: 43px; padding: 10px 16px; border-radius: 9px;
    text-decoration: none; font-size: 13px; font-weight: 700;
    border: 1px solid transparent; transition: .2s ease;
}
.btn-dark { background: rgba(255,255,255,.07); color: #ffffff; border-color: rgba(255,255,255,.10); }
.btn-dark:hover { background: rgba(255,255,255,.13); transform: translateY(-1px); }
.btn-gold { background: #d9b45b; color: #111111; border-color: #d9b45b; }
.btn-gold:hover { filter: brightness(1.08); transform: translateY(-1px); }

/* CONTAINER & LAYOUT */
.container { width: min(1550px, 100%); margin: 0 auto; padding: 30px; }
.page-title { margin-bottom: 25px; }
.page-title h1 { margin: 0 0 7px; font-size: 31px; letter-spacing: -.5px; }
.page-title p { margin: 0; color: #9fa6b5; font-size: 14px; }

.order-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 440px;
    gap: 24px;
    align-items: start;
}

.panel {
    background: linear-gradient(145deg, rgba(18,24,38,.95), rgba(10,14,24,.96));
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 19px;
    padding: 23px;
    box-shadow: 0 25px 70px rgba(0,0,0,.30);
    backdrop-filter: blur(12px);
}
.panel-header {
    display: flex; align-items: center; justify-content: space-between;
    gap: 15px; margin-bottom: 20px;
}
.panel-header h2 { margin: 0; font-size: 20px; }
.food-count {
    padding: 7px 11px; border-radius: 20px;
    background: rgba(217,180,91,.10); border: 1px solid rgba(217,180,91,.25);
    color: #d9b45b; font-size: 12px; font-weight: 800; white-space: nowrap;
}

/* SEARCH & FILTERS */
.menu-tools { display: flex; gap: 10px; margin-bottom: 18px; flex-wrap: wrap; }
.search-box { flex: 1; min-width: 200px; }
.search-box input {
    width: 100%; padding: 12px 14px; border-radius: 10px;
    border: 1px solid rgba(255,255,255,.10); background: rgba(255,255,255,.05);
    color: #ffffff; outline: none; font-size: 14px;
}
.category-select {
    min-width: 170px; padding: 12px 14px; border-radius: 10px;
    border: 1px solid rgba(255,255,255,.10); background: #161b27;
    color: #ffffff; outline: none; font-size: 14px;
}

/* FOOD GRID & CARDS */
#foodGrid {
    max-height: calc(100vh - 280px); min-height: 250px;
    overflow-y: auto; overflow-x: hidden;
    padding: 6px; scrollbar-width: thin; scrollbar-color: #d9b45b #171d2a;
}
.food-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 18px;
}
.food-card {
    background: rgba(255,255,255,.035); border: 1px solid rgba(255,255,255,.08);
    border-radius: 15px; overflow: hidden; transition: .2s ease; position: relative;
}
.food-card:hover {
    transform: translateY(-4px); border-color: rgba(217,180,91,.55);
    box-shadow: 0 12px 30px rgba(0,0,0,.30);
}
.food-card.selected { border-color: #d9b45b; box-shadow: 0 0 0 2px rgba(217,180,91,.25); }

.food-image-wrapper { position: relative; width: 100%; height: 160px; background: #171d2a; }
.food-image { width: 100%; height: 100%; object-fit: cover; display: block; }
.food-image-placeholder {
    width: 100%; height: 100%; display: flex; align-items: center;
    justify-content: center; font-size: 45px;
}
.food-overlay {
    position: absolute; left: 10px; top: 10px; max-width: calc(100% - 20px);
    padding: 4px 10px; border-radius: 20px; background: rgba(0,0,0,.75);
    color: #ffffff; font-size: 11px; font-weight: 700; backdrop-filter: blur(4px);
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

.food-info { padding: 14px; }
.food-name { margin-bottom: 4px; color: #ffffff; font-size: 16px; font-weight: 700; line-height: 1.3; }
.food-category { margin-bottom: 12px; color: #8f97a8; font-size: 12px; }
.food-bottom { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.food-price { color: #d9b45b; font-size: 16px; font-weight: 800; }
.add-food {
    width: 38px; height: 38px; border: 0; border-radius: 9px;
    background: linear-gradient(135deg, #d9b45b, #f0cf79); color: #111111;
    font-size: 22px; font-weight: 900; cursor: pointer; transition: .18s ease;
}
.add-food:hover { transform: scale(1.08); filter: brightness(1.08); }

/* FORMS */
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 7px; color: #b7bdc9; font-size: 13px; font-weight: 600; }
.form-control {
    width: 100%; padding: 12px 13px; border-radius: 9px;
    border: 1px solid rgba(255,255,255,.12); background: rgba(255,255,255,.055);
    color: #ffffff; outline: none; font-size: 14px; transition: .2s ease;
}
.form-control option { background: #161b27; color: #ffffff; }
.form-control:focus { border-color: #d9b45b; box-shadow: 0 0 0 3px rgba(217,180,91,.08); }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.info-box {
    margin-bottom: 18px; padding: 11px 13px; border-radius: 9px;
    background: rgba(217,180,91,.07); border: 1px solid rgba(217,180,91,.16);
    color: #b9b1a0; font-size: 12px; line-height: 1.5;
}

/* CART */
.cart { margin-top: 22px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,.08); }
.cart h3 { margin: 0 0 12px; font-size: 16px; }
.cart-empty { padding: 28px 10px; text-align: center; color: #7f8795; border: 1px dashed rgba(255,255,255,.10); border-radius: 10px; }
.cart-item { display: grid; grid-template-columns: minmax(0,1fr) auto; gap: 12px; padding: 12px 0; border-bottom: 1px solid rgba(255,255,255,.07); }
.cart-name { color: #ffffff; font-weight: 700; font-size: 14px; }
.cart-price { margin-top: 2px; color: #d9b45b; font-size: 13px; }
.qty-controls { display: flex; align-items: center; gap: 7px; margin-top: 8px; }
.qty-btn { width: 26px; height: 26px; border: 0; border-radius: 6px; background: rgba(255,255,255,.09); color: #ffffff; cursor: pointer; }
.remove-btn { padding: 4px 6px; border: 0; background: transparent; color: #e57373; cursor: pointer; font-size: 12px; }
.cart-line-total { color: #ffffff; font-weight: 700; }
.total-row { display: flex; justify-content: space-between; padding: 6px 0; color: #aeb5c2; font-size: 14px; }
.total-row.final { margin-top: 7px; padding-top: 12px; border-top: 1px solid rgba(255,255,255,.12); color: #ffffff; font-size: 20px; font-weight: 800; }
.total-row.final span:last-child { color: #d9b45b; }

.place-order {
    width: 100%; margin-top: 20px; padding: 15px; border: 0; border-radius: 10px;
    background: linear-gradient(135deg, #d9b45b, #f0cf79); color: #111111;
    font-size: 16px; font-weight: 900; cursor: pointer; transition: .2s ease;
}
.place-order:hover { transform: translateY(-2px); filter: brightness(1.06); }

@media (max-width: 1150px) {
    .order-layout { grid-template-columns: 1fr; }
    #foodGrid { max-height: 60vh; }
}
</style>
</head>

<body>

<header class="header">
    <div class="logo">
        🍽️ <span>Suva Hotel</span> Restaurant
    </div>
    <div class="header-actions">
        <a href="<?= e($dashboardUrl) ?>" class="btn btn-dark">← Dashboard</a>
        <a href="../index.php" class="btn btn-dark">← Restaurant</a>
        <a href="index.php" class="btn btn-gold">Order Records</a>
    </div>
</header>

<div class="container">

    <div class="page-title">
        <h1>🍽️ Create New Order</h1>
        <p>Select food items, enter table/guest details, and place the restaurant order.</p>
    </div>

    <form method="POST" action="save.php" id="orderForm">
        <input type="hidden" name="cart_data" id="cartData">

        <div class="order-layout">

            <!-- FOOD MENU PANEL -->
            <div class="panel">
                <div class="panel-header">
                    <h2>Food Menu</h2>
                    <div class="food-count">
                        <span id="visibleCount"><?= count($foods) ?></span> Items
                    </div>
                </div>

                <?php if (!empty($foods)): ?>
                    <div class="menu-tools">
                        <div class="search-box">
                            <input type="text" id="foodSearch" placeholder="🔍 Search food..." autocomplete="off">
                        </div>

                        <select id="categoryFilter" class="category-select">
                            <option value="all">All Categories</option>
                            <?php
                            $categoryList = [];
                            foreach ($foods as$food) {
                                $cat = trim((string)($food["category"] ?? "Other"));
                                if ($cat === "") $cat = "Other";
                                $categoryList[strtolower($cat)] =$cat;
                            }
                            natcasesort($categoryList);
                            foreach ($categoryList as$category):
                            ?>
                                <option value="<?= e(strtolower($category)) ?>"><?= e($category) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php if ($foodError !== ""): ?>
                    <div style="color: #ff8b8b; padding: 15px; background: rgba(255,0,0,0.1); border-radius: 8px;">
                        <strong>Food Database Error:</strong> <?= e($foodError) ?>
                    </div>
                <?php elseif (empty($foods)): ?>
                    <div class="cart-empty">No available food items found.</div>
                <?php else: ?>

                    <div class="food-grid" id="foodGrid">
                        <?php foreach ($foods as$food): 
                            $imagePath = foodImage($food["image"] ?? "");
                            $foodName = trim((string)($food["food_name"] ?? "Food"));
                            $category = trim((string)($food["category"] ?? "Other"));
                            $price = (float)($food["price"] ?? 0);
                            $foodId = (int)($food["id"] ?? 0);
                        ?>
                            <div class="food-card" 
                                 data-name="<?= e(strtolower($foodName)) ?>" 
                                 data-category="<?= e(strtolower($category)) ?>" 
                                 data-food-id="<?= $foodId ?>">
                                
                                <div class="food-image-wrapper">
                                    <?php if ($imagePath !== ""): ?>
                                        <img src="<?= e($imagePath) ?>" class="food-image" alt="<?= e($foodName) ?>" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                        <div class="food-image-placeholder" style="display:none;">🍽️</div>
                                    <?php else: ?>
                                        <div class="food-image-placeholder">🍽️</div>
                                    <?php endif; ?>
                                    <div class="food-overlay"><?= e($category) ?></div>
                                </div>

                                <div class="food-info">
                                    <div class="food-name"><?= e($foodName) ?></div>
                                    <div class="food-category"><?= e($category) ?></div>
                                    <div class="food-bottom">
                                        <div class="food-price">Rs. <?= number_format($price, 2) ?></div>
                                        <button type="button" class="add-food" onclick='addFood(<?= $foodId ?>, <?= json_encode($foodName) ?>, <?=$price ?>)'>+</button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div id="noSearchResults" class="cart-empty" style="display:none; margin-top:15px;">
                        No food items match your search.
                    </div>

                <?php endif; ?>
            </div>


            <!-- ORDER DETAILS PANEL -->
            <div class="panel">
                <div class="panel-header">
                    <h2>Order Details</h2>
                </div>

                <div class="info-box">
                    Select table number, customer details, and food items to submit order.
                </div>

                <!-- CUSTOMER NAME -->
                <div class="form-group">
                    <label>Customer Name *</label>
                    <input type="text" name="customer_name" id="customerName" class="form-control" placeholder="Enter customer name" required autocomplete="off">
                </div>

                <!-- TABLE NUMBER + ORDER TYPE -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="tableNumber">Table Number</label>
                        <select name="table_number" id="tableNumber" class="form-control">
                            <option value="">Select Table Number</option>
                            <?php for ($t = 1; $t <= 20; $t++): ?>
                                <option value="Table <?= $t ?>">Table <?= $t ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Order Type</label>
                        <select name="order_type" class="form-control">
                            <option value="Dine-In">Dine-In</option>
                            <option value="Takeaway">Takeaway</option>
                        </select>
                    </div>
                </div>

                <!-- CUSTOMER TYPE + ROOM SELECT -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Customer Type</label>
                        <select name="customer_type" id="customerType" class="form-control" onchange="toggleRoom()">
                            <option value="Walk-In">Walk-In Guest</option>
                            <option value="Hotel Guest">Hotel Guest</option>
                        </select>
                    </div>

                    <div class="form-group" id="roomGroup" style="display:none;">
                        <label>Select Active Room / Guest</label>
                        <select name="room_id" id="roomId" class="form-control" onchange="autoFillGuestName()">
                            <option value="">Select Room</option>
                            <?php foreach ($activeBookings as$b): ?>
                                <option value="<?= (int)$b['room_id'] ?>" data-guest="<?= e($b['guest_name']) ?>">
                                    Room <?= e($b['room_number']) ?> <?= !empty($b['guest_name']) ? "(".e($b['guest_name']).")" : "" ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- ORDER PERIOD + PAYMENT -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Order Period</label>
                        <select name="order_period" class="form-control">
                            <option value="Day">Day</option>
                            <option value="Night">Night</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Payment Method</label>
                        <select name="payment_method" id="paymentMethod" class="form-control">
                            <option value="Cash">Cash</option>
                            <option value="QR">QR</option>
                            <option value="Card">Card</option>
                            <option value="Room Bill">Room Bill</option>
                        </select>
                    </div>
                </div>

                <!-- CART LIST -->
                <div class="cart">
                    <h3>Selected Items</h3>
                    <div id="cartContainer">
                        <div class="cart-empty">No food selected yet.</div>
                    </div>

                    <div class="cart-total">
                        <div class="total-row">
                            <span>Subtotal</span>
                            <span id="subtotal">Rs. 0.00</span>
                        </div>
                        <div class="total-row">
                            <span>VAT (13%)</span>
                            <span id="vat">Rs. 0.00</span>
                        </div>
                        <div class="total-row final">
                            <span>Total</span>
                            <span id="total">Rs. 0.00</span>
                        </div>
                    </div>

                    <button type="submit" class="place-order" onclick="return prepareOrder()">PLACE ORDER</button>
                </div>

            </div>

        </div>
    </form>
</div>

<script>
let cart = {};

function addFood(id, name, price) {
    id = String(id);
    if (cart[id]) {
        cart[id].quantity++;
    } else {
        cart[id] = { id: id, name: String(name), price: Number(price), quantity: 1 };
    }
    const card = document.querySelector('.food-card[data-food-id="' + id + '"]');
    if (card) card.classList.add("selected");
    renderCart();
}

function changeQuantity(id, amount) {
    id = String(id);
    if (!cart[id]) return;
    cart[id].quantity += amount;
    if (cart[id].quantity <= 0) {
        delete cart[id];
        const card = document.querySelector('.food-card[data-food-id="' + id + '"]');
        if (card) card.classList.remove("selected");
    }
    renderCart();
}

function removeFood(id) {
    id = String(id);
    delete cart[id];
    const card = document.querySelector('.food-card[data-food-id="' + id + '"]');
    if (card) card.classList.remove("selected");
    renderCart();
}

function renderCart() {
    const container = document.getElementById("cartContainer");
    const items = Object.values(cart);
    if (items.length === 0) {
        container.innerHTML = '<div class="cart-empty">No food selected yet.</div>';
        updateTotals(0);
        return;
    }

    let html = "";
    let subtotal = 0;
    items.forEach(function(item) {
        const lineTotal = item.price * item.quantity;
        subtotal += lineTotal;
        html += `
            <div class="cart-item">
                <div>
                    <div class="cart-name">${escapeHtml(item.name)}</div>
                    <div class="cart-price">Rs. ${item.price.toFixed(2)}</div>
                    <div class="qty-controls">
                        <button type="button" class="qty-btn" onclick="changeQuantity('${escapeJs(item.id)}', -1)">−</button>
                        <span style="font-weight:700; padding: 0 4px;">${item.quantity}</span>
                        <button type="button" class="qty-btn" onclick="changeQuantity('${escapeJs(item.id)}', 1)">+</button>
                        <button type="button" class="remove-btn" onclick="removeFood('${escapeJs(item.id)}')">Remove</button>
                    </div>
                </div>
                <div class="cart-line-total">Rs. ${lineTotal.toFixed(2)}</div>
            </div>`;
    });
    container.innerHTML = html;
    updateTotals(subtotal);
}

function updateTotals(subtotal) {
    const vat = subtotal * 0.13;
    const total = subtotal + vat;
    document.getElementById("subtotal").textContent = "Rs. " + subtotal.toFixed(2);
    document.getElementById("vat").textContent = "Rs. " + vat.toFixed(2);
    document.getElementById("total").textContent = "Rs. " + total.toFixed(2);
}

function toggleRoom() {
    const type = document.getElementById("customerType").value;
    const roomGroup = document.getElementById("roomGroup");
    const roomInput = document.getElementById("roomId");
    const paymentMethod = document.getElementById("paymentMethod");

    if (type === "Hotel Guest") {
        roomGroup.style.display = "block";
        roomInput.required = true;
    } else {
        roomGroup.style.display = "none";
        roomInput.required = false;
        roomInput.value = "";
        if (paymentMethod.value === "Room Bill") {
            paymentMethod.value = "Cash";
        }
    }
}

function autoFillGuestName() {
    const roomSelect = document.getElementById("roomId");
    const selectedOption = roomSelect.options[roomSelect.selectedIndex];
    const guestName = selectedOption.getAttribute("data-guest");
    const nameInput = document.getElementById("customerName");
    
    if (guestName && guestName.trim() !== "") {
        nameInput.value = guestName;
    }
}

function prepareOrder() {
    const items = Object.values(cart);
    if (items.length === 0) {
        alert("Please select at least one food item.");
        return false;
    }

    const customerName = document.getElementById("customerName");
    if (!customerName || customerName.value.trim() === "") {
        alert("Please enter the customer name.");
        if (customerName) customerName.focus();
        return false;
    }

    const customerType = document.getElementById("customerType");
    const roomId = document.getElementById("roomId");
    if (customerType && customerType.value === "Hotel Guest" && (!roomId || roomId.value === "")) {
        alert("Please select a room for the hotel guest.");
        if (roomId) roomId.focus();
        return false;
    }

    document.getElementById("cartData").value = JSON.stringify(items);
    return true;
}

function escapeHtml(val) {
    return String(val).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#039;");
}

function escapeJs(val) {
    return String(val).replace(/\\/g, "\\\\").replace(/'/g, "\\'");
}

function filterFoods() {
    const search = (document.getElementById("foodSearch")?.value || "").trim().toLowerCase();
    const category = (document.getElementById("categoryFilter")?.value || "").trim().toLowerCase();
    const cards = document.querySelectorAll(".food-card");
    let visible = 0;

    cards.forEach(function(card) {
        const name = (card.dataset.name || "").toLowerCase();
        const cardCat = (card.dataset.category || "").toLowerCase();
        const matchesSearch = name.includes(search);
        const matchesCat = category === "all" || cardCat === category;

        if (matchesSearch && matchesCat) {
            card.style.display = "";
            visible++;
        } else {
            card.style.display = "none";
        }
    });

    if (document.getElementById("visibleCount")) document.getElementById("visibleCount").textContent = visible;
    if (document.getElementById("noSearchResults")) document.getElementById("noSearchResults").style.display = visible === 0 ? "block" : "none";
}

document.addEventListener("DOMContentLoaded", function() {
    renderCart();
    toggleRoom();
    document.getElementById("foodSearch")?.addEventListener("input", filterFoods);
    document.getElementById("categoryFilter")?.addEventListener("change", filterFoods);
});
</script>

</body>
</html>