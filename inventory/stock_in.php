<?php
require_once "../config/database.php";

$message = "";

/* Get suppliers */
$supplier_sql = "SELECT id, supplier_name FROM suppliers ORDER BY supplier_name ASC";
$supplier_result = $conn->query($supplier_sql);

/* Get products */
$product_sql = "SELECT id, name, unit, purchase_price
                FROM products
                ORDER BY name ASC";
$product_result = $conn->query($product_sql);


/* Save Stock IN */
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $supplier_id = (int) $_POST["supplier_id"];
    $product_id = (int) $_POST["product_id"];
    $quantity = (int) $_POST["quantity"];
    $purchase_price = (float) $_POST["purchase_price"];

    if ($supplier_id <= 0 || $product_id <= 0) {

        $message = "Please select supplier and product.";

    } elseif ($quantity <= 0) {

        $message = "Quantity must be greater than 0.";

    } elseif ($purchase_price < 0) {

        $message = "Purchase price cannot be negative.";

    } else {

        $total_amount = $quantity * $purchase_price;

        /* Start transaction */
        $conn->begin_transaction();

        try {

            /* Insert stock purchase */
            $sql = "INSERT INTO stock_in
                    (supplier_id, product_id, quantity, purchase_price, total_amount)
                    VALUES (?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);

            $stmt->bind_param(
                "iiidd",
                $supplier_id,
                $product_id,
                $quantity,
                $purchase_price,
                $total_amount
            );

            if (!$stmt->execute()) {
                throw new Exception("Could not save stock purchase.");
            }


            /* Increase product stock */
            $update_sql = "UPDATE products
                           SET quantity = quantity + ?
                           WHERE id = ?";

            $update_stmt = $conn->prepare($update_sql);

            $update_stmt->bind_param(
                "ii",
                $quantity,
                $product_id
            );

            if (!$update_stmt->execute()) {
                throw new Exception("Could not update product stock.");
            }


            /* Complete transaction */
            $conn->commit();

            $message = "Stock added successfully! Total purchase: Rs. "
                     . number_format($total_amount, 2);

        } catch (Exception $e) {

            $conn->rollback();

            $message = "Error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html>

<head>

    <title>Stock In - Suva Hotel</title>

</head>

<body>

<h1>Suva Hotel</h1>

<h2>Stock In / Purchase</h2>

<?php if ($message != ""): ?>

    <p>
        <?php echo htmlspecialchars($message); ?>
    </p>

<?php endif; ?>


<form method="POST">

    <label>Supplier:</label><br>

    <select name="supplier_id" required>

        <option value="">Select Supplier</option>

        <?php while ($supplier = $supplier_result->fetch_assoc()): ?>

            <option value="<?php echo $supplier["id"]; ?>">

                <?php echo htmlspecialchars($supplier["supplier_name"]); ?>

            </option>

        <?php endwhile; ?>

    </select>

    <br><br>


    <label>Product:</label><br>

    <select name="product_id" required>

        <option value="">Select Product</option>

        <?php while ($product = $product_result->fetch_assoc()): ?>

            <option
                value="<?php echo $product["id"]; ?>"
            >

                <?php echo htmlspecialchars($product["name"]); ?>

                -
                <?php echo htmlspecialchars($product["unit"]); ?>

            </option>

        <?php endwhile; ?>

    </select>

    <br><br>


    <label>Quantity Purchased:</label><br>

    <input
        type="number"
        name="quantity"
        min="1"
        required
    >

    <br><br>


    <label>Purchase Price per Unit:</label><br>

    <input
        type="number"
        name="purchase_price"
        step="0.01"
        min="0"
        required
    >

    <br><br>


    <button type="submit">
        Add Stock
    </button>

</form>

<br>

<a href="view.php">
    View Inventory
</a>

<br><br>

<a href="../dashboard.php">
    Back to Dashboard
</a>

</body>

</html>