<?php

// ============================================================
// SUVA HOTEL - POSTGRESQL DATABASE CONNECTION
// File: /Suva_hotel/config/database.php
// ============================================================

$host = "127.0.0.1";
$port = "5432";
$database = "suva_hotel";
$username = "postgres";
$password = "123123";

// ============================================================
// CONNECT TO POSTGRESQL
// ============================================================

$pgConnection = pg_connect(
    "host=$host port=$port dbname=$database user=$username password=$password"
);

if (!$pgConnection) {
    die("PostgreSQL database connection failed.");
}

// ============================================================
// RESULT COMPATIBILITY CLASS
// Allows old code to use:
// $result->fetch_assoc()
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
// Allows old code to use:
// $conn->query($sql)
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
        $result = pg_query(
            $this->connection,
            $sql
        );

        if ($result === false) {

            error_log(
                "PostgreSQL Query Error: " .
                pg_last_error($this->connection) .
                " | SQL: " .
                $sql
            );

            return false;
        }

        return new PostgreSQLResult($result);
    }

    public function close()
    {
        if ($this->connection) {

            pg_close(
                $this->connection
            );

            $this->connection = null;
        }
    }
}

// ============================================================
// CREATE COMPATIBILITY CONNECTION OBJECT
// ============================================================

$conn = new PostgreSQLConnection(
    $pgConnection
);

?>