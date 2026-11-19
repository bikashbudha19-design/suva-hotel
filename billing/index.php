<?php
include "../config/db.php";

$message = "";

/* Create Bill */
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $booking_id = intval($_POST["booking_id"]);
    $food_charge = floatval($_POST["food_charge"]);

    /* Get booking information */
    $sql = "SELECT 
                b.id,
                b.total_amount,
                g.name AS guest_name,
                r.room_number
            FROM bookings b
            JOIN guests g ON b.guest_id = g.id
            JOIN rooms r ON b.room_id = r.id
            WHERE b.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $booking = $result->fetch_assoc();

    if ($booking) {

        $room_charge = floatval($booking["total_amount"]);

        /* VAT 13% */
        $subtotal = $room_charge + $food_charge;
        $tax = $subtotal * 0.13;

        $total_amount = $subtotal + $tax;

        /* Insert bill */
        /* Check if bill already exists */
$check_bill = "SELECT id, total_amount
               FROM billing
               WHERE booking_id = ?
               LIMIT 1";

$check_stmt = $conn->prepare($check_bill);
$check_stmt->bind_param("i", $booking_id);
$check_stmt->execute();

$existing_bill = $check_stmt->get_result()->fetch_assoc();

if ($existing_bill) {

    $message = "Bill already exists for Booking #"
             . $booking_id
             . ". Total: Rs. "
             . number_format($existing_bill["total_amount"], 2);

} else {

    /* Insert new bill */
    $insert = "INSERT INTO billing
               (booking_id, room_charge, food_charge, tax, total_amount)
               VALUES (?, ?, ?, ?, ?)";

    $stmt2 = $conn->prepare($insert);

    $stmt2->bind_param(
        "idddd",
        $booking_id,
        $room_charge,
        $food_charge,
        $tax,
        $total_amount
    );

    if ($stmt2->execute()) {

        $message = "Bill created successfully! Total: Rs. "
                 . number_format($total_amount, 2);

    } else {

        $message = "Error creating bill: " . $conn->error;
    }
}

        $stmt2 = $conn->prepare($insert);
        $stmt2->bind_param(
            "idddd",
            $booking_id,
            $room_charge,
            $food_charge,
            $tax,
            $total_amount
        );

        if ($stmt2->execute()) {
            $message = "Bill created successfully!";
        } else {
            $message = "Error creating bill: " . $conn->error;
        }

    } else {
        $message = "Booking not found.";
    }
}

/* Get bookings */
$bookings = $conn->query("
    SELECT 
        b.id,
        b.total_amount,
        g.name AS guest_name,
        r.room_number
    FROM bookings b
    JOIN guests g ON b.guest_id = g.id
    JOIN rooms r ON b.room_id = r.id
    ORDER BY b.id DESC
");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Create Bill - Suva Hotel</title>
</head>

<body>

<h1>Suva Hotel</h1>

<h2>Create Bill</h2>

<?php if ($message != ""): ?>
    <p><strong><?php echo htmlspecialchars($message); ?></strong></p>
<?php endif; ?>

<form method="POST">

    <label>Booking:</label><br>

    <select name="booking_id" required>

        <option value="">Select Booking</option>

        <?php while ($row = $bookings->fetch_assoc()): ?>

            <option value="<?php echo $row["id"]; ?>">

                Booking #<?php echo $row["id"]; ?>
                -
                <?php echo htmlspecialchars($row["guest_name"]); ?>
                -
                Room <?php echo htmlspecialchars($row["room_number"]); ?>
                -
                Rs. <?php echo number_format($row["total_amount"], 2); ?>

            </option>

        <?php endwhile; ?>

    </select>

    <br><br>

    <label>Food Charge:</label><br>

    <input
        type="number"
        name="food_charge"
        value="0"
        min="0"
        step="0.01"
    >

    <br><br>

    <button type="submit">Create Bill</button>

</form>

<br>

<p>
    <a href="../dashboard.php">Back to Dashboard</a>
</p>

</body>
</html>