```php
<?php
session_start();

// ============================================================
// SUVA HOTEL - DELETE GUEST
// PostgreSQL Version
// ============================================================

require_once "../config/database.php";

// ------------------------------------------------------------
// CHECK LOGIN
// ------------------------------------------------------------
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// ------------------------------------------------------------
// GET GUEST ID
// ------------------------------------------------------------
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid guest ID.");
}

$id = (int) $_GET['id'];

// ------------------------------------------------------------
// CHECK GUEST EXISTS
// ------------------------------------------------------------
$check_sql = "
    SELECT id, full_name
    FROM guests
    WHERE id = $1
    LIMIT 1
";

$check_result = pg_query_params(
    $pgConnection,
    $check_sql,
    [$id]
);

if (!$check_result) {
    die(
        "Database Error: " .
        htmlspecialchars(pg_last_error($pgConnection))
    );
}

if (pg_num_rows($check_result) === 0) {
    die("Guest not found.");
}

$guest = pg_fetch_assoc($check_result);

// ------------------------------------------------------------
// START POSTGRESQL TRANSACTION
// ------------------------------------------------------------
if (!pg_query($pgConnection, "BEGIN")) {
    die(
        "Could not start transaction: " .
        htmlspecialchars(pg_last_error($pgConnection))
    );
}

try {

    // --------------------------------------------------------
    // DELETE BILLING RECORDS FOR THIS GUEST
    // --------------------------------------------------------
    $billing_sql = "
        DELETE FROM billing
        WHERE guest_id = $1
    ";

    $billing_result = pg_query_params(
        $pgConnection,
        $billing_sql,
        [$id]
    );

    if (!$billing_result) {
        throw new Exception(
            "Error deleting billing records: " .
            pg_last_error($pgConnection)
        );
    }

    // --------------------------------------------------------
    // DELETE PAYMENTS RELATED TO BOOKINGS OF THIS GUEST
    // --------------------------------------------------------
    $payment_sql = "
        DELETE FROM payments
        WHERE booking_id IN (
            SELECT id
            FROM bookings
            WHERE guest_id = $1
        )
    ";

    $payment_result = pg_query_params(
        $pgConnection,
        $payment_sql,
        [$id]
    );

    if (!$payment_result) {
        throw new Exception(
            "Error deleting payment records: " .
            pg_last_error($pgConnection)
        );
    }

    // --------------------------------------------------------
    // DELETE BOOKINGS FOR THIS GUEST
    // --------------------------------------------------------
    $booking_sql = "
        DELETE FROM bookings
        WHERE guest_id = $1
    ";

    $booking_result = pg_query_params(
        $pgConnection,
        $booking_sql,
        [$id]
    );

    if (!$booking_result) {
        throw new Exception(
            "Error deleting booking records: " .
            pg_last_error($pgConnection)
        );
    }

    // --------------------------------------------------------
    // DELETE GUEST
    // --------------------------------------------------------
    $guest_sql = "
        DELETE FROM guests
        WHERE id = $1
    ";

    $guest_result = pg_query_params(
        $pgConnection,
        $guest_sql,
        [$id]
    );

    if (!$guest_result) {
        throw new Exception(
            "Error deleting guest: " .
            pg_last_error($pgConnection)
        );
    }

    // --------------------------------------------------------
    // COMMIT TRANSACTION
    // --------------------------------------------------------
    if (!pg_query($pgConnection, "COMMIT")) {
        throw new Exception(
            "Could not commit transaction: " .
            pg_last_error($pgConnection)
        );
    }

    // --------------------------------------------------------
    // SUCCESS
    // --------------------------------------------------------
    header("Location: view.php?message=deleted");
    exit;

} catch (Exception $e) {

    // --------------------------------------------------------
    // ROLLBACK TRANSACTION
    // --------------------------------------------------------
    pg_query($pgConnection, "ROLLBACK");

    die(
        "Error deleting guest: " .
        htmlspecialchars($e->getMessage())
    );
}
?>
```
