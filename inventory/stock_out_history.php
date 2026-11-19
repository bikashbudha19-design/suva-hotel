<?php
require_once "../config/database.php";

$sql = "SELECT
            stock_out.id,
            products.name AS product_name,
            products.unit,
            stock_out.quantity,
            stock_out.reason,
            stock_out.stock_out_date
        FROM stock_out
        JOIN products
            ON stock_out.product_id = products.id
        ORDER BY stock_out.id DESC";

$result = $conn->query($sql);

if (!$result) {
    die("Database Error: " . $conn->error);
}
?>

<!DOCTYPE html>
<html>

<head>

    <title>Stock Out History - Suva Hotel</title>

</head>

<body>

<h1>Suva Hotel</h1>

<h2>Stock Out History</h2>

<a href="stock_out.php">
    Remove Stock
</a>

<br><br>

<table border="1" cellpadding="10">

    <tr>
        <th>ID</th>
        <th>Product</th>
        <th>Unit</th>
        <th>Quantity</th>
        <th>Reason</th>
        <th>Date</th>
    </tr>

    <?php if ($result->num_rows > 0): ?>

        <?php while ($row = $result->fetch_assoc()): ?>

            <tr>

                <td>
                    <?php echo $row["id"]; ?>
                </td>

                <td>
                    <?php echo htmlspecialchars($row["product_name"]); ?>
                </td>

                <td>
                    <?php echo htmlspecialchars($row["unit"]); ?>
                </td>

                <td>
                    <?php echo $row["quantity"]; ?>
                </td>

                <td>
                    <?php echo htmlspecialchars($row["reason"]); ?>
                </td>

                <td>
                    <?php echo $row["stock_out_date"]; ?>
                </td>

            </tr>

        <?php endwhile; ?>

    <?php else: ?>

        <tr>

            <td colspan="6">
                No stock out history found.
            </td>

        </tr>

    <?php endif; ?>

</table>

<br><br>

<a href="view.php">
    View Inventory
</a>

<br><br>

<a href="../dashboard.php">
    Back to Dashboard
</a>

</body>

</html>