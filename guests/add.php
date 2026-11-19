
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

$message = "";
$message_type = "";

/* =====================================================
   ADD GUEST
===================================================== */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $full_name = trim($_POST["full_name"] ?? "");
    $phone     = trim($_POST["phone"] ?? "");
    $email     = trim($_POST["email"] ?? "");
    $address   = trim($_POST["address"] ?? "");

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
           INSERT GUEST - POSTGRESQL
        ============================================= */

        $sql = "
            INSERT INTO guests
            (
                full_name,
                phone,
                email,
                address
            )
            VALUES ($1, $2, $3, $4)
        ";

        $params = [
            $full_name,
            $phone,
            $email !== "" ? $email : null,
            $address !== "" ? $address : null
        ];

        $result = pg_query_params(
            $pgConnection,
            $sql,
            $params
        );

        if ($result) {

            $message = "Guest added successfully!";
            $message_type = "success";

            /* Clear form */

            $_POST = [];

        } else {

            $message =
                "Error adding guest: " .
                pg_last_error($pgConnection);

            $message_type = "error";
        }
    }
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
        Add Guest - Suva Hotel
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

            box-shadow:
                0 2px 10px
                rgba(0, 0, 0, 0.1);
        }

        h1 {

            color: #1f3c88;

            margin-top: 0;
        }

        h2 {

            color: #333;
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

            border: 1px solid #ccc;

            border-radius: 5px;

            font-size: 15px;
        }

        input:focus,
        textarea:focus {

            outline: none;

            border-color: #1f3c88;
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

            font-size: 16px;

            font-weight: bold;
        }

        button:hover {

            background: #162d69;
        }

        .success {

            color: green;

            background: #e8f7ed;

            padding: 12px;

            border-radius: 5px;

            border: 1px solid #b7e4c7;
        }

        .error {

            color: red;

            background: #fdecec;

            padding: 12px;

            border-radius: 5px;

            border: 1px solid #f5b5b5;
        }

        a {

            color: #1f3c88;

            text-decoration: none;

            font-weight: bold;
        }

        a:hover {

            text-decoration: underline;
        }

        .user-info {

            color: #555;
        }

    </style>

</head>

<body>

<div class="container">

    <h1>
        🏨 Suva Hotel
    </h1>

    <h2>
        👤 Add New Guest
    </h2>

    <p class="user-info">

        Logged in as:

        <strong>

            <?php

            echo htmlspecialchars(
                $_SESSION["username"] ?? "User"
            );

            ?>

        </strong>

        (

        <?php

        echo htmlspecialchars(
            $_SESSION["role"] ?? "staff"
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


    <!-- =================================================
         ADD GUEST FORM
    ================================================== -->

    <form
        method="POST"
        action="add.php"
    >

        <!-- FULL NAME -->

        <label for="full_name">
            Guest Name:
        </label>

        <input
            type="text"
            id="full_name"
            name="full_name"
            value="<?php

                echo htmlspecialchars(
                    $_POST["full_name"] ?? ""
                );

            ?>"
            required
        >


        <!-- PHONE -->

        <label for="phone">
            Phone:
        </label>

        <input
            type="text"
            id="phone"
            name="phone"
            value="<?php

                echo htmlspecialchars(
                    $_POST["phone"] ?? ""
                );

            ?>"
            required
        >


        <!-- EMAIL -->

        <label for="email">
            Email:
        </label>

        <input
            type="email"
            id="email"
            name="email"
            value="<?php

                echo htmlspecialchars(
                    $_POST["email"] ?? ""
                );

            ?>"
        >


        <!-- ADDRESS -->

        <label for="address">
            Address:
        </label>

        <textarea
            id="address"
            name="address"
            rows="4"
        ><?php

            echo htmlspecialchars(
                $_POST["address"] ?? ""
            );

        ?></textarea>


        <!-- SUBMIT -->

        <button type="submit">
            ➕ Add Guest
        </button>

    </form>


    <br>

    <a href="view.php">
        ← View Guests
    </a>

    <br><br>


    <?php if (($_SESSION["role"] ?? "") === "admin"): ?>

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
```
