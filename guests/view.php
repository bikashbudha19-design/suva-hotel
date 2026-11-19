
<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once "../config/database.php";

$role = $_SESSION["role"] ?? "staff";

/* =====================================================
   GET GUESTS - POSTGRESQL
===================================================== */

$sql = "SELECT
            id,
            full_name,
            phone,
            email,
            address,
            created_at
        FROM guests
        ORDER BY id DESC";

$result = pg_query($pgConnection, $sql);

if (!$result) {
    die("Database Error: " . pg_last_error($pgConnection));
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

    <title>Guests - Suva Hotel</title>

    <style>

        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 30px;
        }

        .container {
            max-width: 1200px;
            margin: auto;
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        h1 {
            margin-top: 0;
            color: #1f2937;
        }

        h2 {
            color: #374151;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .add-btn {
            display: inline-block;
            padding: 10px 16px;
            background: #2563eb;
            color: white;
            text-decoration: none;
            border-radius: 6px;
        }

        .add-btn:hover {
            background: #1d4ed8;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th {
            background: #1f2937;
            color: white;
            padding: 12px;
            text-align: left;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
        }

        tr:hover {
            background: #f9fafb;
        }

        .edit {
            color: #2563eb;
            text-decoration: none;
            font-weight: bold;
        }

        .delete {
            color: #dc2626;
            text-decoration: none;
            font-weight: bold;
        }

        .back-btn {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 15px;
            background: #6b7280;
            color: white;
            text-decoration: none;
            border-radius: 6px;
        }

        .back-btn:hover {
            background: #4b5563;
        }

        .no-data {
            text-align: center;
            color: #666;
            padding: 20px;
        }

        .user-info {
            color: #555;
        }

    </style>

</head>

<body>

<div class="container">

    <h1>🏨 Suva Hotel</h1>

    <h2>👤 Guests</h2>

    <p class="user-info">

        Logged in as:

        <strong>
            <?php
            echo htmlspecialchars($_SESSION["username"] ?? "User");
            ?>
        </strong>

        (
        <?php
        echo htmlspecialchars($role);
        ?>
        )

    </p>

    <hr>

    <!-- TOP BAR -->

    <div class="top-bar">

        <a href="add.php" class="add-btn">
            ➕ Add New Guest
        </a>

    </div>


    <!-- GUEST TABLE -->

    <table>

        <thead>

            <tr>

                <th>ID</th>

                <th>Full Name</th>

                <th>Phone</th>

                <th>Email</th>

                <th>Address</th>

                <th>Created At</th>

                <th>Actions</th>

            </tr>

        </thead>

        <tbody>

        <?php

        if (pg_num_rows($result) > 0):

            while ($row = pg_fetch_assoc($result)):

        ?>

            <tr>

                <!-- ID -->

                <td>
                    <?php
                    echo htmlspecialchars($row["id"]);
                    ?>
                </td>


                <!-- FULL NAME -->

                <td>
                    <?php
                    echo htmlspecialchars(
                        $row["full_name"] ?? ""
                    );
                    ?>
                </td>


                <!-- PHONE -->

                <td>
                    <?php

                    if (!empty($row["phone"])) {

                        echo htmlspecialchars(
                            $row["phone"]
                        );

                    } else {

                        echo "N/A";

                    }

                    ?>
                </td>


                <!-- EMAIL -->

                <td>

                    <?php

                    if (!empty($row["email"])) {

                        echo htmlspecialchars(
                            $row["email"]
                        );

                    } else {

                        echo "N/A";

                    }

                    ?>

                </td>


                <!-- ADDRESS -->

                <td>

                    <?php

                    if (!empty($row["address"])) {

                        echo htmlspecialchars(
                            $row["address"]
                        );

                    } else {

                        echo "N/A";

                    }

                    ?>

                </td>


                <!-- CREATED AT -->

                <td>

                    <?php

                    if (!empty($row["created_at"])) {

                        echo htmlspecialchars(
                            $row["created_at"]
                        );

                    } else {

                        echo "N/A";

                    }

                    ?>

                </td>


                <!-- ACTIONS -->

                <td>

                    <!-- EDIT AVAILABLE TO STAFF AND ADMIN -->

                    <a
                        href="edit.php?id=<?php echo urlencode($row["id"]); ?>"
                        class="edit"
                    >
                        ✏️ Edit
                    </a>


                    <?php if ($role === "admin"): ?>

                        |

                        <!-- DELETE ADMIN ONLY -->

                        <a
                            href="delete.php?id=<?php echo urlencode($row["id"]); ?>"
                            class="delete"
                            onclick="return confirm('Are you sure you want to delete this guest?');"
                        >
                            🗑️ Delete
                        </a>

                    <?php endif; ?>

                </td>

            </tr>

        <?php

            endwhile;

        else:

        ?>

            <tr>

                <td colspan="7" class="no-data">

                    No guests found.

                </td>

            </tr>

        <?php endif; ?>

        </tbody>

    </table>


    <!-- BACK BUTTON -->

    <?php if ($role === "admin"): ?>

        <a
            href="../dashboard.php"
            class="back-btn"
        >
            ← Back to Admin Dashboard
        </a>

    <?php else: ?>

        <a
            href="../staff_dashboard/staff_dashboard.php"
            class="back-btn"
        >
            ← Back to Staff Dashboard
        </a>

    <?php endif; ?>

</div>

</body>

</html>
