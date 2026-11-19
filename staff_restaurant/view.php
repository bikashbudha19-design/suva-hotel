<?php
require_once "../config/database.php";

$orders = [];

$sql = "
    SELECT
        ro.id,
        ro.customer_name,
        ro.customer_type,
        ro.order_type,
        ro.order_period,
        ro.subtotal
    FROM restaurant_orders ro
    ORDER BY ro.id DESC
";

$result = $conn->query($sql);

if ($result) {

    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }

}

$today_total = 0;

$sql = "
    SELECT COALESCE(SUM(subtotal),0) AS total
    FROM restaurant_orders
    WHERE created_at::date = CURRENT_DATE
";

$result = $conn->query($sql);

if ($result) {

    $row = $result->fetch_assoc();

    $today_total =
        (float)$row["total"];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<title>Staff Restaurant Orders</title>

<style>

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f4f7fb;
}

.container {
    max-width: 1200px;
    margin: 30px auto;
    padding: 20px;
}

.header {
    background: white;
    padding: 25px;
    border-radius: 15px;
    margin-bottom: 20px;
}

.header h1 {
    color: #b45309;
}

.actions {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
}

.btn {
    padding: 12px 18px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: bold;
}

.add {
    background: #16a34a;
    color: white;
}

.back {
    background: #64748b;
    color: white;
}

.stats {
    background: white;
    padding: 20px;
    border-radius: 15px;
    margin-bottom: 20px;
}

.revenue {
    font-size: 28px;
    font-weight: bold;
    color: #b45309;
}

.table-box {
    background: white;
    padding: 20px;
    border-radius: 15px;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th,
td {
    padding: 13px;
    border-bottom: 1px solid #eee;
    text-align: left;
}

th {
    background: #fff7ed;
}

.action {
    padding: 7px 10px;
    border-radius: 6px;
    text-decoration: none;
}

.view {
    background: #2563eb;
    color: white;
}

.delete {
    background: #dc2626;
    color: white;
}

</style>

</head>

<body>

<div class="container">

<div class="header">

<h1>🍽️ Staff Restaurant Orders</h1>

<p>
Manage restaurant food orders
</p>

</div>


<div class="actions">

<a
    href="add.php"
    class="btn add"
>
+ New Restaurant Order
</a>

<a
    href="../staff_dashboard/staff_dashboard.php"
    class="btn back"
>
← Staff Dashboard
</a>

</div>


<div class="stats">

Today's Restaurant Revenue

<div class="revenue">

Rs.
<?= number_format(
    $today_total,
    2
) ?>

</div>

</div>


<div class="table-box">

<table>

<thead>

<tr>

<th>ID</th>

<th>Customer</th>

<th>Type</th>

<th>Order Type</th>

<th>Period</th>

<th>Total</th>

<th>Actions</th>

</tr>

</thead>

<tbody>

<?php if (count($orders) > 0): ?>

<?php foreach ($orders as $order): ?>

<tr>

<td>
#<?= (int)$order["id"] ?>
</td>

<td>
<?= htmlspecialchars(
    $order["customer_name"]
) ?>
</td>

<td>
<?= htmlspecialchars(
    $order["customer_type"]
) ?>
</td>

<td>
<?= htmlspecialchars(
    $order["order_type"]
) ?>
</td>

<td>
<?= htmlspecialchars(
    $order["order_period"]
) ?>
</td>

<td>
Rs.
<?= number_format(
    (float)$order["subtotal"],
    2
) ?>
</td>

<td>

<a
    href="view_one.php?id=<?= (int)$order["id"] ?>"
    class="action view"
>
View
</a>

<a
    href="delete.php?id=<?= (int)$order["id"] ?>"
    class="action delete"
    onclick="return confirm('Delete this restaurant order?')"
>
Delete
</a>

</td>

</tr>

<?php endforeach; ?>

<?php else: ?>

<tr>

<td colspan="7">
No restaurant orders found.
</td>

</tr>

<?php endif; ?>

</tbody>

</table>

</div>

</div>

</body>

</html>