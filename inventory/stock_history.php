<?php
require_once "../config/database.php";

/* Get stock purchase history */
$sql = "SELECT 
            stock_in.id,
            suppliers.supplier_name,
            products.name AS product_name,
            products.unit,
            stock_in.quantity,
            stock_in.purchase_price,
            stock_in.total_amount,
            stock_in.purchase_date
        FROM stock_in
        JOIN suppliers 
            ON stock_in.supplier_id = suppliers.id
        JOIN products 
            ON stock_in.product_id = products.id
        ORDER BY stock_in.id DESC";

$result = $conn->query($sql);

if (!$result) {
    die("Database Error: " . $conn->error);
}
?>

<!DOCTYPE html>
<html>

<head>

    <title>Stock History - Suva Hotel</title>

</head>

<body>

<h1>Suva Hotel</h1>

<h2>Stock In History</h2>

<a href="stock_in.php">
    Add Stock
</a>

<br><br>

<table border="1" cellpadding="10">

    <tr>
        <th>ID</th>
        <th>Supplier</th>
        <th>Product</th>
        <th>Unit</th>
        <th>Quantity</th>
        <th>Purchase Price</th>
        <th>Total Amount</th>
        <th>Purchase Date</th>
    </tr>

    <?php if ($result->num_rows > 0): ?>

        <?php while ($row = $result->fetch_assoc()): ?>

            <tr>

                <td>
                    <?php echo $row["id"]; ?>
                </td>

                <td>
                    <?php echo htmlspecialchars($row["supplier_name"]); ?>
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
                    Rs. <?php echo number_format($row["purchase_price"], 2); ?>
                </td>

                <td>
                    Rs. <?php echo number_format($row["total_amount"], 2); ?>
                </td>

                <td>
                    <?php echo $row["purchase_date"]; ?>
                </td>

            </tr>

        <?php endwhile; ?>

    <?php else: ?>

        <tr>

            <td colspan="8">
                No stock purchase history found.
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