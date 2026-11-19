<?php
session_start();

require_once __DIR__ . '/../../config/database.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection is not available.");
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    $_SESSION['food_message'] = "Invalid food ID.";
    $_SESSION['food_message_type'] = 'error';

    header("Location: index.php");
    exit;
}

/*
 * First check whether the food exists.
 */
$stmt = $conn->prepare(
    "SELECT food_name
     FROM restaurant_foods
     WHERE id = ?"
);

$stmt->bind_param("i", $id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {

    $_SESSION['food_message'] = "Food item not found.";
    $_SESSION['food_message_type'] = 'error';

    header("Location: index.php");
    exit;
}

$food = $result->fetch_assoc();

$stmt->close();

/*
 * Delete the food.
 */
$stmt = $conn->prepare(
    "DELETE FROM restaurant_foods
     WHERE id = ?"
);

$stmt->bind_param("i", $id);

if ($stmt->execute()) {

    $_SESSION['food_message'] =
        "Food item '" . $food['food_name'] . "' deleted successfully.";

    $_SESSION['food_message_type'] = 'success';

} else {

    $_SESSION['food_message'] =
        "Unable to delete food item: " . $stmt->error;

    $_SESSION['food_message_type'] = 'error';
}

$stmt->close();

header("Location: index.php");
exit;