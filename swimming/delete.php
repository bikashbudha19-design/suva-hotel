<?php
// ============================================================
// SUVA HOTEL - DELETE SWIMMING RECORD
// PostgreSQL Version
// File: /suva_hotel/swimming/delete.php
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
// CHECK IF RECORD EXISTS
// ============================================================

$check_sql = "
    SELECT id
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
    die("Database Error: " . pg_last_error($pgConnection));
}

if (pg_num_rows($check_result) === 0) {
    die("Swimming record not found.");
}

// ============================================================
// DELETE RECORD
// ============================================================

$delete_sql = "
    DELETE FROM swimming
    WHERE id = $1
";

$delete_result = pg_query_params(
    $pgConnection,
    $delete_sql,
    [$id]
);

if (!$delete_result) {
    die("Database Error: " . pg_last_error($pgConnection));
}

// ============================================================
// SUCCESS - REDIRECT
// ============================================================

header("Location: view.php?deleted=1");
exit;

?>