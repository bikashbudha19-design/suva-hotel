
<?php

// ============================================================
// SUVA HOTEL - SECURE POSTGRESQL CONNECTION
// Local and cloud hosting compatible
// ============================================================

// Load private local configuration if available.
$localConfigFile = __DIR__ . '/database.local.php';

$localConfig = [];

if (file_exists($localConfigFile)) {
    $localConfig = require $localConfigFile;
    if (!is_array($localConfig)) {
        $localConfig = [];
    }
}

// Environment variables take priority for cloud hosting.
$host = getenv('DB_HOST') ?: ($localConfig['host'] ?? '127.0.0.1');
$port = getenv('DB_PORT') ?: ($localConfig['port'] ?? '5432');
$database = getenv('DB_NAME') ?: ($localConfig['database'] ?? 'suva_hotel');
$username = getenv('DB_USER') ?: ($localConfig['username'] ?? 'postgres');
$password = getenv('DB_PASSWORD') ?: ($localConfig['password'] ?? '');

// ============================================================
// CONNECT TO POSTGRESQL
// ============================================================

$connectionString =
    "host=" . $host .
    " port=" . $port .
    " dbname=" . $database .
    " user=" . $username .
    " password=" . $password;

$pgConnection = pg_connect($connectionString);

if (!$pgConnection) {
    error_log("Suva Hotel: PostgreSQL connection failed.");
    die("Database connection failed. Please check database configuration.");
}

// ============================================================
// RESULT COMPATIBILITY CLASS
// ============================================================

class PostgreSQLResult
{
    private $result;

    public function __construct($result)
    {
        $this->result = $result;
    }

    public function fetch_assoc()
    {
        if (!$this->result) {
            return false;
        }

        return pg_fetch_assoc($this->result);
    }
}

// ============================================================
// CONNECTION COMPATIBILITY CLASS
// ============================================================

class PostgreSQLConnection
{
    private $connection;

    public function __construct($connection)
    {
        $this->connection = $connection;
    }

    public function query($sql)
    {
        $result = pg_query($this->connection, $sql);

        if ($result === false) {
            error_log(
                "PostgreSQL Query Error: " .
                pg_last_error($this->connection)
            );

            return false;
        }

        return new PostgreSQLResult($result);
    }

    public function close()
    {
        if ($this->connection) {
            pg_close($this->connection);
            $this->connection = null;
        }
    }
}

// ============================================================
// CREATE CONNECTION OBJECT
// ============================================================

$conn = new PostgreSQLConnection($pgConnection);

?>
