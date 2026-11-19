
<?php

session_start();

/* =========================================================
   SUVA HOTEL
   RESTAURANT ORDER DELETE
   PostgreSQL Version

   File:
   C:\xampp\htdocs\Suva_hotel\restaurant\delete.php
========================================================= */


/* =========================================================
   DATABASE CONNECTION
========================================================= */

require_once __DIR__ . '/../config/database.php';


/* =========================================================
   LOGIN PROTECTION
========================================================= */

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}


/* =========================================================
   CHECK DATABASE CONNECTION
========================================================= */

if (!isset($pgConnection) || !$pgConnection) {
    die("Database connection is not available.");
}


/* =========================================================
   CHECK ORDER ID
========================================================= */

if (
    !isset($_GET["id"]) ||
    !is_numeric($_GET["id"])
) {

    die("Invalid Order ID.");

}


$id = (int)$_GET["id"];


/* =========================================================
   START TRANSACTION
========================================================= */

$transaction_started = false;

try {

    /* =====================================================
       START POSTGRESQL TRANSACTION
    ===================================================== */

    $begin = pg_query(
        $pgConnection,
        "BEGIN"
    );

    if (!$begin) {

        throw new Exception(
            "Could not start database transaction: " .
            pg_last_error($pgConnection)
        );

    }

    $transaction_started = true;


    /* =====================================================
       1. DELETE ORDER ITEMS
    ===================================================== */

    $sql = "
        DELETE FROM restaurant_order_items
        WHERE order_id = $1
    ";


    $result = pg_query_params(
        $pgConnection,
        $sql,
        [$id]
    );


    if (!$result) {

        throw new Exception(
            "Could not delete order items: " .
            pg_last_error($pgConnection)
        );

    }


    /* =====================================================
       2. DELETE RESTAURANT ORDER
    ===================================================== */

    $sql = "
        DELETE FROM restaurant_orders
        WHERE id = $1
    ";


    $result = pg_query_params(
        $pgConnection,
        $sql,
        [$id]
    );


    if (!$result) {

        throw new Exception(
            "Could not delete order: " .
            pg_last_error($pgConnection)
        );

    }


    /* =====================================================
       CHECK WHETHER ORDER WAS ACTUALLY DELETED
    ===================================================== */

    $deleted_rows = pg_affected_rows($result);


    if ($deleted_rows === 0) {

        throw new Exception(
            "Order #$id was not found."
        );

    }


    /* =====================================================
       3. COMMIT TRANSACTION
    ===================================================== */

    $commit = pg_query(
        $pgConnection,
        "COMMIT"
    );


    if (!$commit) {

        throw new Exception(
            "Could not complete database transaction: " .
            pg_last_error($pgConnection)
        );

    }


    $transaction_started = false;


    /* =====================================================
       4. RETURN TO ORDER LIST
    ===================================================== */

    header(
        "Location: view.php?deleted=1"
    );

    exit;


} catch (Exception $e) {


    /* =====================================================
       ROLLBACK TRANSACTION
    ===================================================== */

    if ($transaction_started) {

        pg_query(
            $pgConnection,
            "ROLLBACK"
        );

    }


    ?>

    <!DOCTYPE html>

    <html lang="en">

    <head>

        <meta charset="UTF-8">

        <meta
            name="viewport"
            content="width=device-width, initial-scale=1.0"
        >

        <title>
            Delete Error - Suva Hotel
        </title>


        <style>

            * {
                box-sizing: border-box;
            }


            body {

                font-family:
                    Arial,
                    sans-serif;

                background:
                    #f4f6f9;

                padding:
                    40px;

                margin:
                    0;

            }


            .box {

                max-width:
                    600px;

                margin:
                    auto;

                background:
                    white;

                padding:
                    30px;

                border-radius:
                    10px;

                box-shadow:
                    0 4px 15px
                    rgba(0,0,0,0.1);

            }


            h2 {

                color:
                    #dc3545;

                margin-top:
                    0;

            }


            .error {

                background:
                    #f8d7da;

                color:
                    #842029;

                padding:
                    15px;

                border-radius:
                    6px;

                line-height:
                    1.6;

            }


            a {

                display:
                    inline-block;

                margin-top:
                    20px;

                padding:
                    10px 15px;

                background:
                    #173b6c;

                color:
                    white;

                text-decoration:
                    none;

                border-radius:
                    5px;

            }


            a:hover {

                background:
                    #102d52;

            }

        </style>

    </head>


    <body>


        <div class="box">


            <h2>
                ❌ Delete Failed
            </h2>


            <div class="error">

                <?php

                echo htmlspecialchars(
                    $e->getMessage()
                );

                ?>

            </div>


            <a href="view.php">

                ← Back to Restaurant Orders

            </a>


        </div>


    </body>

    </html>

    <?php

}

?>

