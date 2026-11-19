```php
<?php

// ============================================================
// SUVA HOTEL - ADMIN PASSWORD GENERATOR
// File: /Suva_hotel/create_password.php
// ============================================================

$password = "Admin@2026#Suva";

$hash = password_hash($password, PASSWORD_DEFAULT);

echo "<h2>Admin Password Generator</h2>";

echo "<p>Password: <strong>Admin@2026#Suva</strong></p>";

echo "<p>Generated Hash:</p>";

echo "<textarea rows='4' cols='100'>";
echo htmlspecialchars($hash);
echo "</textarea>";

echo "<p>Hash Length: " . strlen($hash) . "</p>";

?>
```