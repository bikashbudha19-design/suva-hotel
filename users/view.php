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
   GET USERS
========================================== */

$sql = "SELECT
            id,
            username,
            full_name,
            role,
            created_at
        FROM users
        ORDER BY id DESC";

$result = $conn->query($sql);

if (!$result) {
    die("Database Error: " . $conn->error);
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
        Staff / Users - Suva Hotel
    </title>

</head>

<body>

<h1>
    Suva Hotel
</h1>

<h2>
    Staff / Users
</h2>

<p>
    Welcome,
    <strong>
        <?php
        echo htmlspecialchars(
            $_SESSION["username"]
        );
        ?>
    </strong>
</p>

<hr>


<!-- ADD USER -->

<p>

    <a href="add.php">
        ➕ Add Staff / User
    </a>

</p>


<!-- USERS TABLE -->

<table
    border="1"
    cellpadding="10"
    cellspacing="0"
>

    <tr>

        <th>
            ID
        </th>

        <th>
            Username
        </th>

        <th>
            Full Name
        </th>

        <th>
            Role
        </th>

        <th>
            Created At
        </th>

        <th>
            Action
        </th>

    </tr>


    <?php if ($result->num_rows > 0): ?>

        <?php while ($row = $result->fetch_assoc()): ?>

            <tr>

                <td>
                    <?php
                    echo $row["id"];
                    ?>
                </td>

                <td>
                    <?php
                    echo htmlspecialchars(
                        $row["username"]
                    );
                    ?>
                </td>

                <td>
                    <?php
                    echo htmlspecialchars(
                        $row["full_name"]
                    );
                    ?>
                </td>

                <td>

                    <?php

                    if ($row["role"] === "admin") {

                        echo
                            '<strong style="color:blue;">'
                            . 'Admin'
                            . '</strong>';

                    } else {

                        echo
                            '<strong style="color:green;">'
                            . 'Staff'
                            . '</strong>';

                    }

                    ?>

                </td>

                <td>

                    <?php

                    echo htmlspecialchars(
                        $row["created_at"]
                    );

                    ?>

                </td>

                <td>

                    <a
                        href="delete.php?id=<?php echo $row["id"]; ?>"
                        onclick="return confirm('Are you sure you want to delete this user?');"
                    >
                        Delete
                    </a>

                </td>

            </tr>

        <?php endwhile; ?>

    <?php else: ?>

        <tr>

            <td colspan="6">

                No users found.

            </td>

        </tr>

    <?php endif; ?>

</table>


<br>

<a href="../dashboard.php">
    ← Back to Admin Dashboard
</a>


</body>

</html>