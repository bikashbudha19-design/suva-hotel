```php
<?php

session_start();

/*
|--------------------------------------------------------------------------
| Login Check
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once "../config/database.php";


/*
|--------------------------------------------------------------------------
| Check Billing ID
|--------------------------------------------------------------------------
*/

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid Bill ID.");
}

$id = (int) $_GET["id"];


/*
|--------------------------------------------------------------------------
| Check if Bill Exists
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT id
    FROM billing
    WHERE id = $1
    LIMIT 1
";

$result = pg_query_params(
    $pgConnection,
    $sql,
    [$id]
);

if (!$result) {
    die(
        "Database Error: " .
        htmlspecialchars(
            pg_last_error($pgConnection)
        )
    );
}


if (pg_num_rows($result) === 0) {
    die("Bill not found.");
}


/*
|--------------------------------------------------------------------------
| Delete Bill
|--------------------------------------------------------------------------
*/

$delete_sql = "
    DELETE FROM billing
    WHERE id = $1
";

$delete_result = pg_query_params(
    $pgConnection,
    $delete_sql,
    [$id]
);


if ($delete_result) {

    /*
    |----------------------------------------------------------------------
    | Redirect back to billing page
    |----------------------------------------------------------------------
    */

    header("Location: view.php?message=deleted");
    exit;

} else {

    $error = pg_last_error($pgConnection);

    die(
        "Error deleting bill: " .
        htmlspecialchars($error)
    );
}

?>
```
