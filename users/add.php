<?php

session_start();

require_once "../config/database.php";

/* ==========================================
   ADMIN ONLY
========================================== */

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION["role"] !== "admin") {
    header("Location: ../staff_dashboard/staff_dashboard.php");
    exit;
}

$message = "";
$message_type = "";


/* ==========================================
   ADD USER
========================================== */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";
    $full_name = trim($_POST["full_name"] ?? "");
    $role = $_POST["role"] ?? "staff";


    /* VALIDATION */

    if ($username === "") {

        $message = "Please enter a username.";
        $message_type = "error";

    } elseif ($password === "") {

        $message = "Please enter a password.";
        $message_type = "error";

    } elseif ($full_name === "") {

        $message = "Please enter full name.";
        $message_type = "error";

    } elseif (!in_array($role, ["admin", "staff"])) {

        $message = "Invalid role.";
        $message_type = "error";

    } else {


        /* CHECK USERNAME */

        $check_sql =
            "SELECT id
             FROM users
             WHERE username = ?
             LIMIT 1";

        $check_stmt =
            $conn->prepare($check_sql);

        $check_stmt->bind_param(
            "s",
            $username
        );

        $check_stmt->execute();

        $check_result =
            $check_stmt->get_result();

        if ($check_result->num_rows > 0) {

            $message =
                "Username already exists.";

            $message_type = "error";

        } else {


            /* INSERT USER */

            $insert_sql =
                "INSERT INTO users
                (
                    username,
                    password,
                    full_name,
                    role
                )
                VALUES (?, ?, ?, ?)";

            $insert_stmt =
                $conn->prepare($insert_sql);

            if (!$insert_stmt) {

                $message =
                    "Database Error: "
                    . $conn->error;

                $message_type = "error";

            } else {

                /*
                 * Keeping the same password format
                 * as your current login.php.
                 */

                $insert_stmt->bind_param(
                    "ssss",
                    $username,
                    $password,
                    $full_name,
                    $role
                );

                if ($insert_stmt->execute()) {

                    $message =
                        "User added successfully!";

                    $message_type =
                        "success";

                    $_POST = [];

                } else {

                    $message =
                        "Error adding user: "
                        . $insert_stmt->error;

                    $message_type =
                        "error";
                }

                $insert_stmt->close();
            }
        }

        $check_stmt->close();
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
        Add Staff / User - Suva Hotel
    </title>

    <style>

        body {
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            padding: 30px;
        }

        .container {
            max-width: 550px;
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
        select {
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
            cursor: pointer;
        }

        .success {
            background: #e9f8ee;
            color: green;
            padding: 12px;
        }

        .error {
            background: #fdecec;
            color: red;
            padding: 12px;
        }

    </style>

</head>

<body>

<div class="container">

    <h1>
        Suva Hotel
    </h1>

    <h2>
        Add Staff / User
    </h2>


    <?php if ($message !== ""): ?>

        <p class="<?php echo $message_type; ?>">

            <strong>

                <?php
                echo htmlspecialchars($message);
                ?>

            </strong>

        </p>

    <?php endif; ?>


    <form method="POST">


        <!-- USERNAME -->

        <label>
            Username
        </label>

        <input
            type="text"
            name="username"
            required
            value="<?php
                echo htmlspecialchars(
                    $_POST["username"] ?? ""
                );
            ?>"
        >


        <!-- FULL NAME -->

        <label>
            Full Name
        </label>

        <input
            type="text"
            name="full_name"
            required
            value="<?php
                echo htmlspecialchars(
                    $_POST["full_name"] ?? ""
                );
            ?>"
        >


        <!-- PASSWORD -->

        <label>
            Password
        </label>

        <input
            type="password"
            name="password"
            required
        >


        <!-- ROLE -->

        <label>
            Role
        </label>

        <select name="role" required>

            <option value="staff">
                Staff
            </option>

            <option value="admin">
                Admin
            </option>

        </select>


        <!-- SUBMIT -->

        <button type="submit">
            Add User
        </button>

    </form>


    <br>


    <a href="view.php">
        ← Back to Staff / Users
    </a>

    |

    <a href="../dashboard.php">
        Admin Dashboard
    </a>

</div>

</body>

</html>