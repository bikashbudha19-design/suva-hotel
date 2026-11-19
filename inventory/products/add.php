<?php

require_once "../../config/database.php";

$message = "";
$message_type = "";


/* Add Product */

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $product_name = trim($_POST["product_name"]);
    $category_id = (int) $_POST["category_id"];
    $unit = trim($_POST["unit"]);

    $purchase_price = (float) $_POST["purchase_price"];
    $selling_price = (float) $_POST["selling_price"];
    $current_stock = (int) $_POST["quantity"];
    $minimum_stock = (int) $_POST["minimum_stock"];


    /* Validation */

    if ($product_name == "") {

        $message = "Product name is required.";
        $message_type = "error";

    } elseif ($category_id <= 0) {

        $message = "Category is required.";
        $message_type = "error";

    } elseif ($unit == "") {

        $message = "Unit is required.";
        $message_type = "error";

    } elseif ($purchase_price < 0) {

        $message = "Purchase price cannot be negative.";
        $message_type = "error";

    } elseif ($selling_price < 0) {

        $message = "Selling price cannot be negative.";
        $message_type = "error";

    } elseif ($current_stock < 0) {

        $message = "Quantity cannot be negative.";
        $message_type = "error";

    } elseif ($minimum_stock < 0) {

        $message = "Minimum stock cannot be negative.";
        $message_type = "error";

    } else {

        /* Insert Product */

        $sql = "INSERT INTO products
                (
                    category_id,
                    product_name,
                    unit,
                    purchase_price,
                    selling_price,
                    current_stock,
                    minimum_stock
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {

            die("Database Error: " . $conn->error);

        }

        $stmt->bind_param(
            "issddii",
            $category_id,
            $product_name,
            $unit,
            $purchase_price,
            $selling_price,
            $current_stock,
            $minimum_stock
        );


        if ($stmt->execute()) {

            $message = "Product added successfully!";
            $message_type = "success";

        } else {

            $message = "Error adding product: " . $stmt->error;
            $message_type = "error";

        }

        $stmt->close();
    }
}


/* Get Categories */

$category_sql = "SELECT id, category_name
                 FROM categories
                 ORDER BY category_name ASC";

$category_result = $conn->query($category_sql);

if (!$category_result) {

    die("Category Database Error: " . $conn->error);

}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Add Product - Suva Hotel</title>

</head>

<body>

<h1>Suva Hotel</h1>

<h2>Add Product</h2>


<?php if ($message != ""): ?>

    <?php if ($message_type == "success"): ?>

        <p style="color: green;">
            <strong>
                <?php echo htmlspecialchars($message); ?>
            </strong>
        </p>

    <?php else: ?>

        <p style="color: red;">
            <strong>
                <?php echo htmlspecialchars($message); ?>
            </strong>
        </p>

    <?php endif; ?>

<?php endif; ?>


<form method="POST">


    <!-- Product Name -->

    <label>
        Product Name:
    </label>

    <br>

    <input
        type="text"
        name="product_name"
        required
    >

    <br><br>


    <!-- Category -->

    <label>
        Category:
    </label>

    <br>

    <select name="category_id" required>

        <option value="">
            -- Select Category --
        </option>

        <?php while ($category = $category_result->fetch_assoc()): ?>

            <option value="<?php echo $category["id"]; ?>">

                <?php echo htmlspecialchars(
                    $category["category_name"]
                ); ?>

            </option>

        <?php endwhile; ?>

    </select>

    <br><br>


    <!-- Unit -->

    <label>
        Unit:
    </label>

    <br>

    <input
        type="text"
        name="unit"
        placeholder="Piece, Bottle, Kg, Liter..."
        required
    >

    <br><br>


    <!-- Purchase Price -->

    <label>
        Purchase Price:
    </label>

    <br>

    <input
        type="number"
        name="purchase_price"
        min="0"
        step="0.01"
        required
    >

    <br><br>


    <!-- Selling Price -->

    <label>
        Selling Price:
    </label>

    <br>

    <input
        type="number"
        name="selling_price"
        min="0"
        step="0.01"
        required
    >

    <br><br>


    <!-- Quantity -->

    <label>
        Quantity:
    </label>

    <br>

    <input
        type="number"
        name="quantity"
        min="0"
        required
    >

    <br><br>


    <!-- Minimum Stock -->

    <label>
        Minimum Stock:
    </label>

    <br>

    <input
        type="number"
        name="minimum_stock"
        min="0"
        required
    >

    <br><br>


    <button type="submit">
        Add Product
    </button>

</form>


<br>

<a href="view.php">
    View Products
</a>

<br><br>

<a href="../../dashboard.php">
    Back to Dashboard
</a>


</body>

</html>