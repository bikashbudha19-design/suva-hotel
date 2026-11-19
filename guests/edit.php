
<?php

session_start();

/* =====================================================
   LOGIN PROTECTION
===================================================== */

if (!isset($_SESSION["user_id"])) {

    header("Location: ../login.php");
    exit;
}

require_once "../config/database.php";


/* =====================================================
   CHECK GUEST ID
===================================================== */

if (
    !isset($_GET["id"]) ||
    !is_numeric($_GET["id"])
) {

    die("Invalid guest ID.");

}

$id = (int) $_GET["id"];

$message = "";
$message_type = "";


/* =====================================================
   UPDATE GUEST
===================================================== */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $full_name = trim(
        $_POST["full_name"] ?? ""
    );

    $phone = trim(
        $_POST["phone"] ?? ""
    );

    $email = trim(
        $_POST["email"] ?? ""
    );

    $address = trim(
        $_POST["address"] ?? ""
    );


    /* =================================================
       VALIDATION
    ================================================= */

    if ($full_name === "") {

        $message = "Guest name is required.";
        $message_type = "error";

    } elseif ($phone === "") {

        $message = "Phone number is required.";
        $message_type = "error";

    } else {

        /* =============================================
           UPDATE GUEST - POSTGRESQL
        ============================================= */

        $sql = "
            UPDATE guests
            SET
                full_name = $1,
                phone = $2,
                email = $3,
                address = $4
            WHERE id = $5
        ";

        $params = [
            $full_name,
            $phone,
            $email !== "" ? $email : null,
            $address !== "" ? $address : null,
            $id
        ];

        $result = pg_query_params(
            $pgConnection,
            $sql,
            $params
        );


        if ($result) {

            $message = "Guest updated successfully!";
            $message_type = "success";

        } else {

            $message =
                "Error updating guest: " .
                pg_last_error($pgConnection);

            $message_type = "error";
        }
    }
}


/* =====================================================
   GET GUEST
===================================================== */

$sql = "
    SELECT
        id,
        full_name,
        phone,
        email,
        address
    FROM guests
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


if (pg_num_rows($result) !== 1) {

    die("Guest not found.");

}


$guest = pg_fetch_assoc($result);


/* =====================================================
   SAFE VALUES
===================================================== */

$full_name =
    $guest["full_name"] ?? "";

$phone =
    $guest["phone"] ?? "";

$email =
    $guest["email"] ?? "";

$address =
    $guest["address"] ?? "";

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
        Edit Guest - Suva Hotel
    </title>


    <style>

        body {

            font-family: Arial, sans-serif;

            background: #f4f6f8;

            margin: 0;

            padding: 30px;
        }


        .container {

            max-width: 600px;

            margin: auto;

            background: white;

            padding: 30px;

            border-radius: 10px;
        }


        h1 {

            color: #1f3c88;
        }


        label {

            display: block;

            margin-top: 15px;

            font-weight: bold;
        }


        input,
        textarea {

            width: 100%;

            padding: 10px;

            margin-top: 6px;

            box-sizing: border-box;
        }


        button {

            width: 100%;

            padding: 12px;

            margin-top: 20px;

            background: #1f3c88;

            color: white;

            border: none;

            border-radius: 5px;

            cursor: pointer;
        }


        button:hover {

            background: #162d68;
        }


        .success {

            color: green;

            background: #e8f7ed;

            padding: 12px;

            border-radius: 5px;
        }


        .error {

            color: red;

            background: #fdecec;

            padding: 12px;

            border-radius: 5px;
        }


        a {

            color: #1f3c88;

            text-decoration: none;
        }

    </style>

</head>


<body>


<div class="container">


    <h1>
        🏨 Suva Hotel
    </h1>


    <h2>
        ✏️ Edit Guest
    </h2>


    <p>

        Logged in as:

        <strong>

            <?php

            echo htmlspecialchars(
                $_SESSION["username"] ?? ""
            );

            ?>

        </strong>

        (

        <?php

        echo htmlspecialchars(
            $_SESSION["role"] ?? ""
        );

        ?>

        )

    </p>


    <hr>


    <?php if ($message !== ""): ?>

        <p class="<?php echo htmlspecialchars($message_type); ?>">

            <strong>

                <?php

                echo htmlspecialchars($message);

                ?>

            </strong>

        </p>

    <?php endif; ?>


    <form method="POST">


        <!-- FULL NAME -->

        <label>
            Guest Name:
        </label>

        <input
            type="text"
            name="full_name"
            value="<?php
                echo htmlspecialchars($full_name);
            ?>"
            required
        >


        <!-- PHONE -->

        <label>
            Phone:
        </label>

        <input
            type="text"
            name="phone"
            value="<?php
                echo htmlspecialchars($phone);
            ?>"
            required
        >


        <!-- EMAIL -->

        <label>
            Email:
        </label>

        <input
            type="email"
            name="email"
            value="<?php
                echo htmlspecialchars($email);
            ?>"
        >


        <!-- ADDRESS -->

        <label>
            Address:
        </label>

        <textarea
            name="address"
            rows="4"
        ><?php
            echo htmlspecialchars($address);
        ?></textarea>


        <button type="submit">
            💾 Update Guest
        </button>

    </form>


    <br>


    <a href="view.php">
        ← Back to Guests
    </a>


    <br><br>


    <?php if (
        isset($_SESSION["role"]) &&
        $_SESSION["role"] === "admin"
    ): ?>

        <a href="../dashboard.php">
            ← Admin Dashboard
        </a>

    <?php else: ?>

        <a href="../staff_dashboard/staff_dashboard.php">
            ← Staff Dashboard
        </a>

    <?php endif; ?>


</div>


</body>

</html>
