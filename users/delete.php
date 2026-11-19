<?php

session_start();

require_once "../config/database.php";


/* ==========================================
   ADMIN ONLY
========================================== */

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION["role"] !== "admin") {
    header("Location: ../staff_dashboard/staff_dashboard.php");
    exit;
}


/* ==========================================
   GET USER ID
========================================== */

$user_id = (int)($_GET["id"] ?? 0);

if ($user_id <= 0) {
    header("Location: view.php");
    exit;
}


/* ==========================================
   PREVENT ADMIN FROM DELETING THEMSELVES
========================================== */

if ($user_id === (int)$_SESSION["user_id"]) {

    die("
        <h2>Cannot Delete Your Own Account</h2>

        <p>
            You are currently logged in with this account.
            You cannot delete your own admin account.
        </p>

        <a href='view.php'>
            Back to Staff / Users
        </a>
    ");
}


/* ==========================================
   CHECK USER EXISTS
========================================== */

$sql = "SELECT
            id,
            username,
            full_name,
            role
        FROM users
        WHERE id = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Database Error: " . $conn->error);
}

$stmt->bind_param(
    "i",
    $user_id
);

$stmt->execute();

$result = $stmt->get_result();

$user = $result->fetch_assoc();

$stmt->close();


if (!$user) {

    die("
        <h2>User Not Found</h2>

        <a href='view.php'>
            Back to Staff / Users
        </a>
    ");
}


/* ==========================================
   DELETE USER
========================================== */

$delete_sql =
    "DELETE FROM users
     WHERE id = ?";

$delete_stmt =
    $conn->prepare($delete_sql);

if (!$delete_stmt) {

    die(
        "Database Error: "
        . $conn->error
    );
}

$delete_stmt->bind_param(
    "i",
    $user_id
);


if ($delete_stmt->execute()) {

    $delete_stmt->close();

    header("Location: view.php");
    exit;

} else {

    $error =
        $delete_stmt->error;

    $delete_stmt->close();

    die(
        "Unable to delete user: "
        . htmlspecialchars($error)
    );
}

?>