<?php
require_once "../config/database.php";

$message = "";

/* Get products */
$product_sql = "SELECT id, name, unit, quantity
                FROM products
                WHERE quantity > 0
                ORDER BY name ASC";

$product_result = $conn->query($product_sql);

if (!$product_result) {
    die("Product Database Error: " . $conn->error);
}


/* Process Stock Out */
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $product_id = (int) $_POST["product_id"];
    $quantity = (int) $_POST["quantity"];
    $reason = trim($_POST["reason"]);

    if ($product_id <= 0) {

        $message = "Please select a product.";

    } elseif ($quantity <= 0) {

        $message = "Quantity must be greater than 0.";

    } else {

        /* Get current stock */
        $check_sql = "SELECT name, quantity
                      FROM products
                      WHERE id = ?";

        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $product_id);
        $check_stmt->execute();

        $check_result = $check_stmt->get_result();
        $product = $check_result->fetch_assoc();

        if (!$product) {

            $message = "Product not found.";

        } elseif ($quantity > $product["quantity"]) {

            $message = "Not enough stock. Available stock: "
                     . $product["quantity"];

        } else {

            /* Reduce product quantity */
            $update_sql = "UPDATE products
                           SET quantity = quantity - ?
                           WHERE id = ?";

            $update_stmt = $conn->prepare($update_sql);

            $update_stmt->bind_param(
                "ii",
                $quantity,
                $product_id
            );

            if ($update_stmt->execute()) {

                /* Save Stock Out History */
                $history_sql = "INSERT INTO stock_out
                                (product_id, quantity, reason)
                                VALUES (?, ?, ?)";

                $history_stmt = $conn->prepare($history_sql);

                $history_stmt->bind_param(
                    "iis",
                    $product_id,
                    $quantity,
                    $reason
                );

                if ($history_stmt->execute()) {

                    $message = "Stock removed successfully! "
                             . $quantity . " "
                             . $product["name"]
                             . " removed.";

                } else {

                    $message = "Stock decreased, but history was not saved: "
                             . $conn->error;
                }

            } else {

                $message = "Error updating stock: "
                         . $conn->error;
            }
        }
    }

    /* Reload products */
    $product_result = $conn->query($product_sql);
}
?>

<!DOCTYPE html>
<html>

<head>

    <title>Stock Out - Suva Hotel</title>

</head>

<body>

<h1>Suva Hotel</h1>

<h2>Stock Out</h2>

<p>
    Remove products from inventory when they are sold or used.
</p>

<?php if ($message != ""): ?>

    <p>
        <strong>
            <?php echo htmlspecialchars($message); ?>
        </strong>
    </p>

<?php endif; ?>


<form method="POST">

    <label>Product:</label>

    <br>

    <select name="product_id" required>

        <option value="">
            Select Product
        </option>

        <?php while ($product = $product_result->fetch_assoc()): ?>

            <option value="<?php echo $product["id"]; ?>">

                <?php echo htmlspecialchars($product["name"]); ?>

                -
                <?php echo htmlspecialchars($product["unit"]); ?>

                -
                Stock:
                <?php echo $product["quantity"]; ?>

            </option>

        <?php endwhile; ?>

    </select>

    <br><br>


    <label>Quantity:</label>

    <br>

    <input
        type="number"
        name="quantity"
        min="1"
        required
    >

    <br><br>


    <label>Reason:</label>

    <br>

    <input
        type="text"
        name="reason"
        placeholder="Example: Sold to customer"
    >

    <br><br>


    <button type="submit">
        Remove Stock
    </button>

</form>

<br>

<a href="view.php">
    View Inventory
</a>

<br><br>

<a href="stock_history.php">
    Stock In History
</a>

<br><br>

<a href="stock_out_history.php">
    Stock Out History
</a>

<br><br>

<a href="../dashboard.php">
    Back to Dashboard
</a>

</body>

</html>