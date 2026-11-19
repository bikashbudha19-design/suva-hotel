<?php

require_once "../../config/database.php";


/* Check Product ID */

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {

    die("Invalid Product ID.");

}

$id = (int) $_GET["id"];


/* Delete Product */

$sql = "DELETE FROM products WHERE id = ?";

$stmt = $conn->prepare($sql);

if (!$stmt) {

    die("Database Error: " . $conn->error);

}

$stmt->bind_param("i", $id);


if ($stmt->execute()) {

    $stmt->close();

    header("Location: view.php");
    exit;

} else {

    die("Error deleting product: " . $stmt->error);

}

?>