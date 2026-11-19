<?php

require_once __DIR__ . '/config/database.php';

echo "<h2>PostgreSQL Connection Test</h2>";

if (!$pgConnection) {
    die("Connection failed.");
}

echo "✅ Connected to PostgreSQL<br><br>";


// Test users table
echo "<h3>Users Test</h3>";

$sql = "
    SELECT id, username, role
    FROM users
    ORDER BY id
";

$result = pg_query($pgConnection, $sql);

if ($result === false) {
    die("Users table error: " . pg_last_error($pgConnection));
}

echo "<table border='1' cellpadding='10'>";
echo "<tr>";
echo "<th>ID</th>";
echo "<th>Username</th>";
echo "<th>Role</th>";
echo "</tr>";

while ($row = pg_fetch_assoc($result)) {

    echo "<tr>";

    echo "<td>" .
        htmlspecialchars($row["id"]) .
        "</td>";

    echo "<td>" .
        htmlspecialchars($row["username"]) .
        "</td>";

    echo "<td>" .
        htmlspecialchars($row["role"]) .
        "</td>";

    echo "</tr>";
}

echo "</table>";


// Test login_attempts table
echo "<h3>Login Attempts Test</h3>";

$sql = "
    SELECT
        id,
        username,
        ip_address,
        success,
        attempted_at
    FROM login_attempts
    ORDER BY id DESC
    LIMIT 10
";

$result = pg_query($pgConnection, $sql);

if ($result === false) {
    die(
        "Login attempts table error: " .
        pg_last_error($pgConnection)
    );
}

echo "✅ login_attempts table works.<br><br>";


// Test password verification for admin
echo "<h3>Password Verification Test</h3>";

$sql = "
    SELECT username, password
    FROM users
    WHERE username = 'admin'
    LIMIT 1
";

$result = pg_query($pgConnection, $sql);

if ($result === false) {
    die(
        "Password query error: " .
        pg_last_error($pgConnection)
    );
}

$user = pg_fetch_assoc($result);

if (!$user) {

    echo "❌ Admin user not found.";

} else {

    echo "Admin user found.<br>";

    echo "Username: " .
        htmlspecialchars($user["username"]) .
        "<br>";

    echo "Password hash exists: ";

    if (!empty($user["password"])) {
        echo "✅ YES<br>";
    } else {
        echo "❌ NO<br>";
    }
}

?>