
<?php

session_start();

/* =====================================================
   SUVA HOTEL - SAFE BOOKING DELETION
   Database: PostgreSQL
   File: /suva_hotel/bookings/delete.php
===================================================== */

/* =====================================================
   1. LOGIN CHECK
===================================================== */

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

/* =====================================================
   2. ADMIN ONLY
===================================================== */

if (($_SESSION["role"] ?? "") !== "admin") {
    header("Location: view.php");
    exit;
}

/* =====================================================
   3. POST REQUEST ONLY
===================================================== */

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit("Invalid request. Please use the Delete button.");
}

/* =====================================================
   4. DATABASE CONNECTION
===================================================== */

require_once __DIR__ . "/../config/database.php";

/* =====================================================
   5. VALIDATE BOOKING ID
===================================================== */

$id = $_POST["id"] ?? "";

if (
    !is_scalar($id) ||
    !ctype_digit((string) $id) ||
    (int) $id < 1
) {
    http_response_code(400);
    exit("Invalid booking ID. Please return to the Bookings page.");
}

$id = (int) $id;

/* =====================================================
   6. START TRANSACTION
===================================================== */

if (pg_query($pgConnection, "BEGIN") === false) {
    http_response_code(500);
    exit("Could not start the database transaction.");
}

try {

    /* ================================================
       7. FIND AND LOCK BOOKING
    ================================================ */

    $result = pg_query_params(
        $pgConnection,
        "SELECT id, room_id, status
         FROM bookings
         WHERE id = $1
         FOR UPDATE",
        [$id]
    );

    if ($result === false) {
        throw new Exception("Could not retrieve the booking.");
    }

    $booking = pg_fetch_assoc($result);

    if (!$booking) {
        throw new Exception("Booking not found.");
    }

    $status = strtolower(
        trim((string) ($booking["status"] ?? ""))
    );

    /* ================================================
       8. PROTECT BOOKING HISTORY
    ================================================ */

    if (!in_array($status, ["pending", "confirmed"], true)) {
        throw new Exception(
            "Deletion blocked. Only Pending or Confirmed bookings " .
            "can be considered for deletion."
        );
    }

    /* ================================================
       9. CHECK PAYMENT HISTORY
    ================================================ */

    $paymentResult = pg_query_params(
        $pgConnection,
        "SELECT 1
         FROM payments
         WHERE booking_id = $1
         LIMIT 1",
        [$id]
    );

    if ($paymentResult === false) {
        throw new Exception(
            "Could not verify payment history. No deletion was made."
        );
    }

    if (pg_num_rows($paymentResult) > 0) {
        throw new Exception(
            "Deletion blocked. This booking has payment history. " .
            "Keep the booking and its payment records."
        );
    }

    /* ================================================
       10. DELETE ELIGIBLE BOOKING
    ================================================ */

    $deleteResult = pg_query_params(
        $pgConnection,
        "DELETE FROM bookings WHERE id = $1",
        [$id]
    );

    if ($deleteResult === false) {
        throw new Exception(
            "Deletion failed. This booking may be linked to billing " .
            "or other records. Preserve the records and check the database."
        );
    }

    if (pg_affected_rows($deleteResult) !== 1) {
        throw new Exception("The booking was not deleted.");
    }

    /* ================================================
       11. COMMIT TRANSACTION
    ================================================ */

    if (pg_query($pgConnection, "COMMIT") === false) {
        throw new Exception("Could not complete the transaction.");
    }

    /* Do not change room status here.
       Room availability must reflect actual occupancy
       and other bookings. */

    header("Location: view.php");
    exit;

} catch (Throwable $e) {

    /* Roll back any incomplete database changes. */
    pg_query($pgConnection, "ROLLBACK");

    error_log(
        "Suva Hotel booking deletion blocked/failed for ID " .
        $id . ": " . $e->getMessage()
    );

    http_response_code(400);

    echo "<!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1'>
        <title>Booking Deletion</title>
    </head>
    <body style='font-family:Arial,sans-serif;padding:25px;'>
        <h2>Booking Not Deleted</h2>
        <p>" .
        htmlspecialchars($e->getMessage(), ENT_QUOTES, "UTF-8") .
        "</p>
        <p><a href='view.php'>Return to Bookings</a></p>
    </body>
    </html>";

    exit;
}
?>
