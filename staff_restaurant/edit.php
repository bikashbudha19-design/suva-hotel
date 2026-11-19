```php
<?php
require_once "../config/database.php";

/* =========================================================
   CHECK ORDER ID
   ========================================================= */

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Invalid restaurant order ID.");
}

$order_id = intval($_GET['id']);


/* =========================================================
   GET RESTAURANT ORDER
   ========================================================= */

$sql = "SELECT * FROM restaurant_orders WHERE id = ?";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Database Error: " . $conn->error);
}

$stmt->bind_param("i", $order_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows == 0) {
    die("Restaurant order not found.");
}

$order = $result->fetch_assoc();

$stmt->close();


/* =========================================================
   GET EXISTING ORDER ITEMS
   restaurant_order_items uses food_id
   products uses product_name
   ========================================================= */

$item_sql = "
    SELECT
        roi.id,
        roi.food_id,
        roi.quantity,
        roi.unit_price,
        roi.total_price,
        p.product_name AS food_name
    FROM restaurant_order_items roi
    LEFT JOIN products p
        ON roi.food_id = p.id
    WHERE roi.order_id = ?
    ORDER BY roi.id ASC
";

$item_stmt = $conn->prepare($item_sql);

if (!$item_stmt) {
    die("Item Database Error: " . $conn->error);
}

$item_stmt->bind_param("i", $order_id);
$item_stmt->execute();

$items_result = $item_stmt->get_result();

$items = [];

while ($item = $items_result->fetch_assoc()) {
    $items[] = $item;
}

$item_stmt->close();


/* =========================================================
   GET ONLY FOOD + BEVERAGE PRODUCTS

   Category:
   1 = Food
   2 = Beverages
   ========================================================= */

$product_sql = "
    SELECT
        id,
        product_name,
        selling_price
    FROM products
    WHERE category_id IN (1, 2)
    ORDER BY product_name ASC
";

$product_result = $conn->query($product_sql);

if (!$product_result) {
    die("Product Database Error: " . $conn->error);
}


/* =========================================================
   UPDATE ORDER
   ========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $customer_name = trim($_POST['customer_name'] ?? '');

    $order_period = $_POST['order_period'] ?? '';

    $order_type = $_POST['order_type'] ?? '';

    $customer_type = $_POST['customer_type'] ?? '';

    $payment_method = $_POST['payment_method'] ?? '';

    $food_ids = $_POST['food_id'] ?? [];

    $quantities = $_POST['quantity'] ?? [];


    /* =====================================================
       VALIDATION
       ===================================================== */

    if ($customer_name === '') {
        die("Customer name is required.");
    }

    if (empty($food_ids)) {
        die("Please add at least one food item.");
    }


    /* =====================================================
       CALCULATE SUBTOTAL
       ===================================================== */

    $subtotal = 0;

    $valid_items = [];


    foreach ($food_ids as $index => $food_id) {

        $food_id = intval($food_id);

        $quantity = floatval(
            $quantities[$index] ?? 0
        );


        if ($food_id <= 0 || $quantity <= 0) {
            continue;
        }


        /* ---------------------------------------------
           MAKE SURE PRODUCT IS FOOD OR BEVERAGE
           --------------------------------------------- */

        $price_sql = "
            SELECT
                selling_price
            FROM products
            WHERE id = ?
              AND category_id IN (1, 2)
        ";

        $price_stmt = $conn->prepare($price_sql);

        if (!$price_stmt) {
            die(
                "Price Database Error: " .
                $conn->error
            );
        }

        $price_stmt->bind_param(
            "i",
            $food_id
        );

        $price_stmt->execute();

        $price_result =
            $price_stmt->get_result();


        if ($price_result->num_rows == 0) {

            $price_stmt->close();

            continue;
        }


        $product =
            $price_result->fetch_assoc();


        $price =
            floatval(
                $product['selling_price']
            );


        $price_stmt->close();


        /* ---------------------------------------------
           CALCULATE ITEM TOTAL
           --------------------------------------------- */

        $item_total =
            $price * $quantity;


        $subtotal += $item_total;


        $valid_items[] = [

            'food_id' => $food_id,

            'quantity' => $quantity,

            'unit_price' => $price,

            'total_price' => $item_total

        ];
    }


    if (empty($valid_items)) {
        die(
            "No valid Food or Beverage item selected."
        );
    }


    /* =====================================================
       START TRANSACTION
       ===================================================== */

    $conn->begin_transaction();


    try {


        /* =================================================
           UPDATE MAIN RESTAURANT ORDER
           ================================================= */

        $update_sql = "
            UPDATE restaurant_orders
            SET
                customer_name = ?,
                order_type = ?,
                customer_type = ?,
                order_period = ?,
                subtotal = ?,
                payment_method = ?
            WHERE id = ?
        ";

        $update_stmt =
            $conn->prepare($update_sql);


        if (!$update_stmt) {

            throw new Exception(
                "Order Update Error: " .
                $conn->error
            );
        }


        $update_stmt->bind_param(

            "ssssdsi",

            $customer_name,

            $order_type,

            $customer_type,

            $order_period,

            $subtotal,

            $payment_method,

            $order_id

        );


        if (!$update_stmt->execute()) {

            throw new Exception(
                "Order Update Error: " .
                $update_stmt->error
            );
        }


        $update_stmt->close();


        /* =================================================
           DELETE OLD ORDER ITEMS
           ================================================= */

        $delete_sql = "
            DELETE FROM restaurant_order_items
            WHERE order_id = ?
        ";

        $delete_stmt =
            $conn->prepare($delete_sql);


        if (!$delete_stmt) {

            throw new Exception(
                "Delete Items Error: " .
                $conn->error
            );
        }


        $delete_stmt->bind_param(
            "i",
            $order_id
        );


        if (!$delete_stmt->execute()) {

            throw new Exception(
                "Delete Items Error: " .
                $delete_stmt->error
            );
        }


        $delete_stmt->close();


        /* =================================================
           INSERT UPDATED ITEMS

           restaurant_order_items:
           order_id
           food_id
           quantity
           unit_price
           total_price
           ================================================= */

        $insert_sql = "
            INSERT INTO restaurant_order_items
            (
                order_id,
                food_id,
                quantity,
                unit_price,
                total_price
            )
            VALUES (?, ?, ?, ?, ?)
        ";


        $insert_stmt =
            $conn->prepare($insert_sql);


        if (!$insert_stmt) {

            throw new Exception(
                "Insert Item Error: " .
                $conn->error
            );
        }


        foreach ($valid_items as $item) {

            $food_id =
                $item['food_id'];

            $quantity =
                $item['quantity'];

            $unit_price =
                $item['unit_price'];

            $total_price =
                $item['total_price'];


            $insert_stmt->bind_param(

                "iiddd",

                $order_id,

                $food_id,

                $quantity,

                $unit_price,

                $total_price

            );


            if (!$insert_stmt->execute()) {

                throw new Exception(
                    "Insert Item Error: " .
                    $insert_stmt->error
                );
            }
        }


        $insert_stmt->close();


        /* =================================================
           COMMIT
           ================================================= */

        $conn->commit();


        header(
            "Location: view.php?success=updated"
        );

        exit;


    } catch (Exception $e) {


        $conn->rollback();


        die(
            "Error updating restaurant order: " .
            $e->getMessage()
        );
    }
}

?>


<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>Edit Restaurant Order</title>


<style>

* {
    box-sizing: border-box;
}


body {

    margin: 0;

    font-family: Arial, sans-serif;

    background: #f4f6f9;

    color: #333;
}


.container {

    width: 90%;

    max-width: 1050px;

    margin: 40px auto;

    background: white;

    padding: 30px;

    border-radius: 12px;

    box-shadow:
        0 4px 18px rgba(0,0,0,0.10);
}


h1 {

    margin-top: 0;

    margin-bottom: 25px;
}


h2 {

    margin-top: 30px;

    margin-bottom: 15px;
}


.row {

    display: grid;

    grid-template-columns:
        1fr 1fr;

    gap: 20px;
}


.form-group {

    margin-bottom: 20px;
}


label {

    display: block;

    font-weight: bold;

    margin-bottom: 7px;
}


input,
select {

    width: 100%;

    padding: 11px;

    border: 1px solid #ccc;

    border-radius: 6px;

    font-size: 15px;
}


.items-section {

    margin-top: 20px;
}


.item-row {

    display: grid;

    grid-template-columns:
        1fr 130px 130px 45px;

    gap: 10px;

    margin-bottom: 12px;

    align-items: center;
}


.item-total {

    background: #f1f3f5;
}


.remove-btn {

    background: #dc3545;

    color: white;

    border: none;

    padding: 10px;

    border-radius: 6px;

    cursor: pointer;

    font-size: 18px;
}


.remove-btn:hover {

    background: #bb2d3b;
}


.add-btn {

    background: #198754;

    color: white;

    border: none;

    padding: 11px 18px;

    border-radius: 6px;

    cursor: pointer;

    margin-top: 5px;

    font-size: 15px;
}


.add-btn:hover {

    background: #157347;
}


.total-box {

    margin-top: 25px;

    padding: 18px;

    background: #f1f3f5;

    border-radius: 8px;

    text-align: right;

    font-size: 21px;

    font-weight: bold;
}


.buttons {

    margin-top: 25px;

    display: flex;

    gap: 10px;
}


.update-btn {

    background: #0d6efd;

    color: white;

    border: none;

    padding: 12px 25px;

    border-radius: 6px;

    cursor: pointer;

    font-size: 16px;
}


.update-btn:hover {

    background: #0b5ed7;
}


.cancel-btn {

    background: #6c757d;

    color: white;

    text-decoration: none;

    padding: 12px 25px;

    border-radius: 6px;
}


.cancel-btn:hover {

    background: #5c636a;
}


@media(max-width:700px) {

    .row {

        grid-template-columns: 1fr;
    }


    .item-row {

        grid-template-columns: 1fr;
    }

}

</style>

</head>


<body>


<div class="container">


<h1>✏️ Edit Restaurant Order</h1>


<form method="POST">


<!-- =====================================================
     CUSTOMER INFORMATION
     ===================================================== -->


<div class="row">


<div class="form-group">

<label>Customer Name</label>

<input
    type="text"
    name="customer_name"
    value="<?php

        echo htmlspecialchars(
            $order['customer_name']
        );

    ?>"
    required
>

</div>


<div class="form-group">

<label>Order Period</label>

<select
    name="order_period"
    required
>

<option value="Day"
<?php

if (
    $order['order_period']
    === 'Day'
) {

    echo 'selected';

}
?>
>
Day
</option>


<option value="Night"
<?php

if (
    $order['order_period']
    === 'Night'
) {

    echo 'selected';

}
?>
>
Night
</option>

</select>

</div>


<div class="form-group">

<label>Order Type</label>

<select
    name="order_type"
    required
>

<option value="Dine-In"
<?php

if (
    $order['order_type']
    === 'Dine-In'
) {

    echo 'selected';

}
?>
>
Dine-In
</option>


<option value="Takeaway"
<?php

if (
    $order['order_type']
    === 'Takeaway'
) {

    echo 'selected';

}
?>
>
Takeaway
</option>

</select>

</div>


<div class="form-group">

<label>Customer Type</label>

<select
    name="customer_type"
    required
>

<option value="Walk-In"
<?php

if (
    $order['customer_type']
    === 'Walk-In'
) {

    echo 'selected';

}
?>
>
Walk-In
</option>


<option value="Hotel Guest"
<?php

if (
    $order['customer_type']
    === 'Hotel Guest'
) {

    echo 'selected';

}
?>
>
Hotel Guest
</option>

</select>

</div>


<div class="form-group">

<label>Payment Method</label>

<select
    name="payment_method"
    required
>

<option value="Cash"
<?php

if (
    $order['payment_method']
    === 'Cash'
) {

    echo 'selected';

}
?>
>
Cash
</option>


<option value="QR"
<?php

if (
    $order['payment_method']
    === 'QR'
) {

    echo 'selected';

}
?>
>
QR
</option>


<option value="Card"
<?php

if (
    $order['payment_method']
    === 'Card'
) {

    echo 'selected';

}
?>
>
Card
</option>


<option value="Add to Room Bill"
<?php

if (
    $order['payment_method']
    === 'Add to Room Bill'
) {

    echo 'selected';

}
?>
>
Add to Room Bill
</option>

</select>

</div>


</div>


<!-- =====================================================
     FOOD ITEMS
     ===================================================== -->


<div class="items-section">


<h2>🍽️ Food / Beverage Items</h2>


<div id="items-container">


<?php

if (!empty($items)):

    foreach ($items as $item):

?>


<div class="item-row">


<select
    name="food_id[]"
    class="food-select"
    required
>

<option value="">
Select Food / Beverage
</option>


<?php

$product_result->data_seek(0);

while (
    $product =
    $product_result->fetch_assoc()
):

?>


<option
    value="<?php
        echo $product['id'];
    ?>"
    data-price="<?php
        echo $product['selling_price'];
    ?>"

    <?php

    if (
        $product['id']
        == $item['food_id']
    ) {

        echo 'selected';

    }

    ?>
>

<?php

echo htmlspecialchars(
    $product['product_name']
);

?>

</option>


<?php endwhile; ?>


</select>


<input
    type="number"
    name="quantity[]"
    class="quantity"
    value="<?php

        echo $item['quantity'];

    ?>"
    min="0.01"
    step="0.01"
    required
>


<input
    type="text"
    class="item-total"
    value="<?php

        echo number_format(
            $item['total_price'],
            2
        );

    ?>"
    readonly
>


<button
    type="button"
    class="remove-btn"
    onclick="removeItem(this)"
>
×
</button>


</div>


<?php

    endforeach;

else:

?>


<!-- No existing items -->

<div class="item-row">


<select
    name="food_id[]"
    class="food-select"
    required
>

<option value="">
Select Food / Beverage
</option>


<?php

$product_result->data_seek(0);

while (
    $product =
    $product_result->fetch_assoc()
):

?>


<option
    value="<?php
        echo $product['id'];
    ?>"
    data-price="<?php
        echo $product['selling_price'];
    ?>"
>

<?php

echo htmlspecialchars(
    $product['product_name']
);

?>

</option>


<?php endwhile; ?>


</select>


<input
    type="number"
    name="quantity[]"
    class="quantity"
    value="1"
    min="0.01"
    step="0.01"
    required
>


<input
    type="text"
    class="item-total"
    value="0.00"
    readonly
>


<button
    type="button"
    class="remove-btn"
    onclick="removeItem(this)"
>
×
</button>


</div>


<?php endif; ?>


</div>


<button
    type="button"
    class="add-btn"
    onclick="addItem()"
>
+ Add Food / Beverage
</button>


</div>


<!-- =====================================================
     SUBTOTAL
     ===================================================== -->


<div class="total-box">

Subtotal:

Rs.

<span id="subtotal">
0.00
</span>

</div>


<!-- =====================================================
     BUTTONS
     ===================================================== -->


<div class="buttons">


<button
    type="submit"
    class="update-btn"
>
💾 Update Order
</button>


<a
    href="view.php"
    class="cancel-btn"
>
Cancel
</a>


</div>


</form>


</div>


<script>

/* =========================================================
   UPDATE TOTALS
   ========================================================= */

function updateTotals() {

    let subtotal = 0;


    document
        .querySelectorAll(".item-row")
        .forEach(function(row) {


            const select =
                row.querySelector(
                    ".food-select"
                );


            const quantity =
                row.querySelector(
                    ".quantity"
                );


            const total =
                row.querySelector(
                    ".item-total"
                );


            if (
                !select ||
                !quantity ||
                !total
            ) {

                return;
            }


            const option =
                select.options[
                    select.selectedIndex
                ];


            const price =
                parseFloat(
                    option.getAttribute(
                        "data-price"
                    )
                ) || 0;


            const qty =
                parseFloat(
                    quantity.value
                ) || 0;


            const itemTotal =
                price * qty;


            total.value =
                itemTotal.toFixed(2);


            subtotal += itemTotal;

        });


    document
        .getElementById("subtotal")
        .innerText =
        subtotal.toFixed(2);
}


/* =========================================================
   ADD NEW ITEM
   ========================================================= */

function addItem() {

    const container =
        document.getElementById(
            "items-container"
        );


    const row =
        document.createElement("div");


    row.className =
        "item-row";


    row.innerHTML = `

        <select
            name="food_id[]"
            class="food-select"
            required
        >

            <option value="">
                Select Food / Beverage
            </option>

            <?php

            $product_result->data_seek(0);

            while (
                $product =
                $product_result->fetch_assoc()
            ):

            ?>

            <option
                value="<?php
                    echo $product['id'];
                ?>"
                data-price="<?php
                    echo $product['selling_price'];
                ?>"
            >

                <?php

                echo htmlspecialchars(
                    $product['product_name']
                );

                ?>

            </option>

            <?php endwhile; ?>

        </select>


        <input
            type="number"
            name="quantity[]"
            class="quantity"
            value="1"
            min="0.01"
            step="0.01"
            required
        >


        <input
            type="text"
            class="item-total"
            value="0.00"
            readonly
        >


        <button
            type="button"
            class="remove-btn"
            onclick="removeItem(this)"
        >
            ×
        </button>

    `;


    container.appendChild(row);


    updateTotals();
}


/* =========================================================
   REMOVE ITEM
   ========================================================= */

function removeItem(button) {

    const rows =
        document.querySelectorAll(
            ".item-row"
        );


    if (rows.length <= 1) {

        alert(
            "At least one food item is required."
        );

        return;
    }


    button
        .parentElement
        .remove();


    updateTotals();
}


/* =========================================================
   SELECT / QUANTITY CHANGE
   ========================================================= */

document.addEventListener(
    "change",
    function(e) {

        if (

            e.target.classList.contains(
                "food-select"
            )

            ||

            e.target.classList.contains(
                "quantity"
            )

        ) {

            updateTotals();
        }

    }
);


/* =========================================================
   QUANTITY INPUT
   ========================================================= */

document.addEventListener(
    "input",
    function(e) {

        if (
            e.target.classList.contains(
                "quantity"
            )
        ) {

            updateTotals();
        }

    }
);


/* =========================================================
   INITIAL CALCULATION
   ========================================================= */

updateTotals();

</script>


</body>

</html>
```
