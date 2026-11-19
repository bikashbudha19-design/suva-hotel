<?php

require_once "config/database.php";

echo "PostgreSQL connection: SUCCESS<br>";
echo "Connection object: SUCCESS<br>";

$result = pg_query(
    $pgConnection,
    "SELECT current_database() AS database_name"
);

if ($result) {
    $row = pg_fetch_assoc($result);

    echo "Database: " . htmlspecialchars($row["database_name"]);
} else {
    echo "Query failed: " . pg_last_error($pgConnection);
}

?>