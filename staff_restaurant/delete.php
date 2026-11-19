<?php

require_once "../config/database.php";

$id = isset($_GET["id"])
    ? (int)$_GET["id"]
    : 0;

if ($id <= 0) {
    die("Invalid order ID.");
}


/* =========================================================
   DELETE ORDER ITEMS FIRST
   ========================================================= */

$stmt = $conn->prepare("
    DELETE FROM restaurant_order_items
    WHERE order_id = ?
");

$stmt->bind_param(
    "i",
    $id
);

$stmt->execute();

$stmt->close();


/* =========================================================
   DELETE ORDER
   ========================================================= */

$stmt = $conn->prepare("
    DELETE FROM restaurant_orders
    WHERE id = ?
");

$stmt->bind_param(
    "i",
    $id
);

$stmt->execute();

$stmt->close();


/* =========================================================
   RETURN
   ========================================================= */

header(
    "Location: view.php"
);

exit;

?>