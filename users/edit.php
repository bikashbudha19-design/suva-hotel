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


/* ==========================================
   GET USER ID
========================================== */

$user_id = (int)($_GET["id"] ?? 0);

if ($user_id <= 0) {
    header("Location: view.php");
    exit;
}


/* ==========================================
   GET USER
========================================== */

$sql = "SELECT
            id,
            username,
            full_name,
            role
        FROM users
        WHERE id = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Database Error: " . $conn->error);
}

$stmt->bind_param("i", $user_id);

$stmt->execute();

$result = $stmt->get_result();

$user = $result->fetch_assoc();

$stmt->close();


if (!$user) {
    die("User not found.");
}


/* ==========================================
   UPDATE USER
========================================== */

$message = "";
$message_type = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim(
        $_POST["username"] ?? ""
    );

    $full_name = trim(
        $_POST["full_name"] ?? ""
    );

    $role = $_POST["role"] ?? "staff";

    $new_password = $_POST["password"] ?? "";


    /* ==========================================
       VALIDATION
    ========================================== */

    if ($username === "") {

        $message = "Username is required.";
        $message_type = "error";

    } elseif ($full_name === "") {

        $message = "Full name is required.";
        $message_type = "error";

    } elseif (
        !in_array(
            $role,
            ["admin", "staff"],
            true
        )
    ) {

        $message = "Invalid role.";
        $message_type = "error";

    } else {


        /* ==========================================
           CHECK DUPLICATE USERNAME
        ========================================== */

        $check_sql =
            "SELECT id
             FROM users
             WHERE username = ?
             AND id != ?
             LIMIT 1";

        $check_stmt =
            $conn->prepare($check_sql);

        $check_stmt->bind_param(
            "si",
            $username,
            $user_id
        );

        $check_stmt->execute();

        $check_result =
            $check_stmt->get_result();

        if ($check_result->num_rows > 0) {

            $message =
                "Username already exists.";

            $message_type = "error";

        } else {


            /* ==========================================
               UPDATE WITH PASSWORD
            ========================================== */

            if ($new_password !== "") {

                $update_sql =
                    "UPDATE users
                     SET
                        username = ?,
                        full_name = ?,
                        password = ?,
                        role = ?
                     WHERE id = ?";

                $update_stmt =
                    $conn->prepare(
                        $update_sql
                    );

                $update_stmt->bind_param(
                    "ssssi",
                    $username,
                    $full_name,
                    $new_password,
                    $role,
                    $user_id
                );

            } else {


                /* ==========================================
                   UPDATE WITHOUT PASSWORD
                ========================================== */

                $update_sql =
                    "UPDATE users
                     SET
                        username = ?,
                        full_name = ?,
                        role = ?
                     WHERE id = ?";

                $update_stmt =
                    $conn->prepare(
                        $update_sql
                    );

                $update_stmt->bind_param(
                    "sssi",
                    $username,
                    $full_name,
                    $role,
                    $user_id
                );
            }


            /* ==========================================
               EXECUTE UPDATE
            ========================================== */

            if ($update_stmt->execute()) {

                /*
                 * If the currently logged-in admin
                 * changes their username, update
                 * the session too.
                 */

                if (
                    $user_id ===
                    (int)$_SESSION["user_id"]
                ) {

                    $_SESSION["username"] =
                        $username;

                    $_SESSION["role"] =
                        $role;
                }


                header(
                    "Location: view.php"
                );

                exit;

            } else {

                $message =
                    "Update failed: "
                    . $update_stmt->error;

                $message_type =
                    "error";
            }

            $update_stmt->close();
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
        Edit User - Suva Hotel
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
        Edit Staff / User
    </h2>


    <?php if ($message !== ""): ?>

        <p class="<?php echo $message_type; ?>">

            <strong>
                <?php
                echo htmlspecialchars(
                    $message
                );
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
            value="<?php
                echo htmlspecialchars(
                    $user["username"]
                );
            ?>"
            required
        >


        <!-- FULL NAME -->

        <label>
            Full Name
        </label>

        <input
            type="text"
            name="full_name"
            value="<?php
                echo htmlspecialchars(
                    $user["full_name"]
                );
            ?>"
            required
        >


        <!-- PASSWORD -->

        <label>
            New Password
        </label>

        <input
            type="password"
            name="password"
            placeholder="Leave blank to keep current password"
        >


        <!-- ROLE -->

        <label>
            Role
        </label>

        <select name="role" required>

            <option
                value="staff"
                <?php
                echo $user["role"] === "staff"
                    ? "selected"
                    : "";
                ?>
            >
                Staff
            </option>

            <option
                value="admin"
                <?php
                echo $user["role"] === "admin"
                    ? "selected"
                    : "";
                ?>
            >
                Admin
            </option>

        </select>


        <button type="submit">
            Update User
        </button>

    </form>


    <br>

    <a href="view.php">
        ← Back to Staff / Users
    </a>

</div>

</body>

</html>