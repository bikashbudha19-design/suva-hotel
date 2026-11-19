<?php

session_start();

/* =====================================================
   CHECK LOGIN
===================================================== */

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once "../config/database.php";

$role = $_SESSION["role"] ?? "";


/* =====================================================
   ONLY ADMIN CAN DELETE
===================================================== */

if ($role !== "admin") {
    die("Access Denied: Only admin can delete expenses.");
}


/* =====================================================
   GET EXPENSE ID
===================================================== */

$id = isset($_GET["id"])
    ? (int)$_GET["id"]
    : 0;


if ($id <= 0) {
    die("Invalid expense ID.");
}


/* =====================================================
   CHECK EXPENSE EXISTS
===================================================== */

$sql = "SELECT id, expense_name, amount
        FROM expenses
        WHERE id = ?";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Database Error: " . $conn->error);
}

$stmt->bind_param("i", $id);

$stmt->execute();

$result = $stmt->get_result();

$expense = $result->fetch_assoc();

$stmt->close();


if (!$expense) {
    die("Expense not found.");
}


/* =====================================================
   DELETE EXPENSE
===================================================== */

$delete_sql = "DELETE FROM expenses WHERE id = ?";

$delete_stmt = $conn->prepare($delete_sql);

if (!$delete_stmt) {
    die("Database Error: " . $conn->error);
}

$delete_stmt->bind_param("i", $id);


if ($delete_stmt->execute()) {

    $delete_stmt->close();

    header("Location: view.php?deleted=1");
    exit;

} else {

    $error = $delete_stmt->error;

    $delete_stmt->close();

    die("Error deleting expense: " . $error);
}

?>