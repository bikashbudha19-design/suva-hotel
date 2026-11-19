<?php

echo "PHP Version: " . PHP_VERSION . "<br>";
echo "Loaded php.ini: " . php_ini_loaded_file() . "<br>";
echo "pgsql available: ";

if (function_exists("pg_connect")) {
    echo "YES";
} else {
    echo "NO";
}

?>