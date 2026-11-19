
<?php
// =====================================================
// SUVA HOTEL - DELETE ROOM
// PostgreSQL Compatible
// File: rooms/delete.php
// =====================================================

session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// =====================================================
// LOGIN CHECK
// =====================================================

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// =====================================================
// ADMIN ONLY
// =====================================================

if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: view.php');
    exit;
}

// =====================================================
// DATABASE CONNECTION
// =====================================================

require_once __DIR__ . '/../config/database.php';

// =====================================================
// CHECK ROOM ID
// =====================================================

if (!isset($_GET['id']) || !ctype_digit((string)$_GET['id'])) {
    die('Invalid room ID.');
}

$id = (int) $_GET['id'];

if ($id <= 0) {
    die('Invalid room ID.');
}

// =====================================================
// CHECK ROOM EXISTS
// Uses the query() method provided by your wrapper.
// The ID is an integer validated above.
// =====================================================

$checkSql = "SELECT id FROM rooms WHERE id = " . $id;

$checkResult = $conn->query($checkSql);

if ($checkResult === false) {
    die('Database error while checking the room.');
}

$room = $checkResult->fetch_assoc();

if (!$room) {
    die('Room not found.');
}

// =====================================================
// DELETE ROOM
// =====================================================

// Check whether the room has any bookings.
$bookingSql = "SELECT id FROM bookings WHERE room_id = " . $id . " LIMIT 1";
$bookingResult = $conn->query($bookingSql);

if ($bookingResult === false) {
    die("Unable to check room bookings.");
}

if ($bookingResult->fetch_assoc()) {
    echo '<div style="font-family:Arial;max-width:600px;margin:60px auto;padding:25px;background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;">';
    echo '<h2 style="color:#c2410c;">Room Cannot Be Deleted</h2>';
    echo '<p>This room has booking records. To preserve booking history, it cannot be deleted.</p>';
    echo '<p>You can mark the room as maintenance or unavailable instead.</p>';
    echo '<a href="view.php">Return to Rooms</a>';
    echo '</div>';
    exit;
}

// Delete only if no bookings reference the room.
$deleteSql = "DELETE FROM rooms WHERE id = " . $id;
$deleteResult = $conn->query($deleteSql);

if ($deleteResult !== false) {
    header("Location: view.php");
    exit;
}

echo "Unable to delete this room. It may be referenced by other records.";

// =====================================================
// HANDLE DELETE ERROR
// =====================================================

http_response_code(500);

echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Room Deletion Error | Suva Hotel</title>
<style>
body {
    font-family: Arial, sans-serif;
    background: #f1f5f9;
    padding: 40px 20px;
    color: #1f2937;
}
.error-box {
    max-width: 600px;
    margin: 40px auto;
    padding: 30px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 5px 20px rgba(0,0,0,.08);
}
h2 { color: #dc2626; }
a { color: #1976d2; }
</style>
</head>
<body>
<div class="error-box">
<h2>Unable to Delete Room</h2>
<p>The database rejected the deletion. The room may have related
bookings or other records.</p>
<p><a href="view.php">Return to Rooms</a></p>
</div>
</body>
</html>';
?>