<?php
// ============================================================
// SUVA HOTEL - SWIMMING EXIT
// PostgreSQL Version
// File: /suva_hotel/swimming/exit.php
// ============================================================

require_once __DIR__ . '/../config/database.php';

// ============================================================
// DATABASE CONNECTION CHECK
// ============================================================

if (!isset($pgConnection) || !$pgConnection) {
    die("Database connection failed.");
}

// ============================================================
// VALIDATE ID
// ============================================================

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid swimming record ID.");
}

$id = (int) $_GET['id'];

if ($id <= 0) {
    die("Invalid swimming record ID.");
}

// ============================================================
// CHECK RECORD
// ============================================================

$check_sql = "
    SELECT id, visitor_name, status
    FROM swimming
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
        pg_last_error($pgConnection)
    );
}

// ============================================================
// RECORD NOT FOUND
// ============================================================

if (pg_num_rows($check_result) === 0) {
    die("Swimming record not found.");
}

$row = pg_fetch_assoc($check_result);

// ============================================================
// ALREADY COMPLETED
// ============================================================

if ($row['status'] === 'Completed') {

    header(
        "Location: view_one.php?id=" . $id
    );

    exit;
}

// ============================================================
// EXIT SWIMMER
// ============================================================

$update_sql = "
    UPDATE swimming
    SET
        exit_time = CURRENT_TIME,
        status = 'Completed'
    WHERE id = $1
";

$update_result = pg_query_params(
    $pgConnection,
    $update_sql,
    [$id]
);

// ============================================================
// SUCCESS
// ============================================================

if ($update_result) {

    header(
        "Location: view_one.php?id=" . $id
    );

    exit;

} else {

    die(
        "Database Error: " .
        pg_last_error($pgConnection)
    );
}

?>