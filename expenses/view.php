<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once "../config/database.php";

$role = $_SESSION["role"] ?? "";

if ($role !== "admin" && $role !== "staff") {
    header("Location: ../login.php");
    exit;
}


/* =====================================================
   GET EXPENSES
===================================================== */

$sql = "SELECT
            id,
            expense_date,
            expense_name,
            category,
            amount,
            description,
            created_at
        FROM expenses
        ORDER BY expense_date DESC, id DESC";

$result = $conn->query($sql);

if (!$result) {
    die("Database Error: " . $conn->error);
}


/* =====================================================
   TOTAL EXPENSE
===================================================== */

$total_expense = 0;

$expenses = [];

while ($row = $result->fetch_assoc()) {

    $expenses[] = $row;

    $total_expense += (float)$row["amount"];
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>Expenses - Suva Hotel</title>


<style>

* {
    box-sizing: border-box;
}

body {

    font-family: Arial, sans-serif;

    background: #f4f6f8;

    margin: 0;

    padding: 30px;

}


.container {

    max-width: 1400px;

    margin: auto;

    background: white;

    padding: 25px;

    border-radius: 12px;

    box-shadow:
        0 4px 15px
        rgba(0,0,0,0.10);

}


.header {

    display: flex;

    justify-content: space-between;

    align-items: center;

    flex-wrap: wrap;

    gap: 15px;

}


h1 {

    color: #1f3c88;

    margin: 0;

}


h2 {

    color: #333;

    margin-top: 5px;

}


.top-buttons {

    margin: 20px 0;

}


.top-buttons a {

    display: inline-block;

    padding: 11px 16px;

    margin-right: 8px;

    margin-bottom: 8px;

    background: #1f3c88;

    color: white;

    text-decoration: none;

    border-radius: 6px;

}


.top-buttons a:hover {

    background: #162d68;

}


/* =====================================================
   SUMMARY
===================================================== */

.summary {

    display: flex;

    gap: 20px;

    margin: 20px 0;

    flex-wrap: wrap;

}


.summary-card {

    min-width: 250px;

    padding: 20px;

    border-radius: 10px;

    background: #eef3ff;

}


.summary-card h3 {

    margin: 0 0 10px 0;

    color: #555;

}


.summary-card .amount {

    font-size: 28px;

    font-weight: bold;

    color: #1f3c88;

}


/* =====================================================
   TABLE
===================================================== */

.table-wrapper {

    overflow-x: auto;

}


table {

    width: 100%;

    border-collapse: collapse;

    min-width: 1000px;

}


th {

    background: #1f3c88;

    color: white;

    padding: 13px;

    text-align: center;

}


td {

    padding: 11px;

    border: 1px solid #ddd;

    text-align: center;

}


tr:nth-child(even) {

    background: #f8f9fa;

}


tr:hover {

    background: #eef3ff;

}


/* =====================================================
   CATEGORY
===================================================== */

.category {

    padding: 5px 9px;

    border-radius: 15px;

    font-size: 13px;

    font-weight: bold;

    display: inline-block;

}


.salary {

    background: #e8f0ff;

    color: #1f3c88;

}


.product {

    background: #fff3cd;

    color: #856404;

}


.electricity {

    background: #fff0c2;

    color: #7a5900;

}


.water {

    background: #dff5ff;

    color: #006b8f;

}


.internet {

    background: #eee3ff;

    color: #663399;

}


.maintenance {

    background: #ffe2e2;

    color: #a00000;

}


.cleaning {

    background: #e2f7e2;

    color: #267326;

}


.other {

    background: #eeeeee;

    color: #444;

}


/* =====================================================
   ACTIONS
===================================================== */

.actions {

    white-space: nowrap;

}


.edit {

    color: #1565c0;

    text-decoration: none;

    font-weight: bold;

}


.delete {

    color: #d32f2f;

    text-decoration: none;

    font-weight: bold;

}


.edit:hover,
.delete:hover {

    text-decoration: underline;

}


/* =====================================================
   EMPTY
===================================================== */

.empty {

    padding: 30px;

    text-align: center;

    color: #777;

}


/* =====================================================
   FOOTER
===================================================== */

.footer-links {

    margin-top: 25px;

}


.footer-links a {

    color: #1f3c88;

    text-decoration: none;

    margin-right: 15px;

}


.footer-links a:hover {

    text-decoration: underline;

}

</style>

</head>


<body>


<div class="container">


<!-- =====================================================
     HEADER
===================================================== -->

<div class="header">

    <div>

        <h1>🏨 Suva Hotel</h1>

        <h2>💸 Expense Management</h2>

    </div>

</div>


<!-- =====================================================
     BUTTONS
===================================================== -->

<div class="top-buttons">

    <a href="add.php">
        ➕ Add New Expense
    </a>


    <?php if ($role === "admin"): ?>

        <a href="../dashboard.php">
            ← Admin Dashboard
        </a>

    <?php else: ?>

        <a href="../staff_dashboard/staff_dashboard.php">
            ← Staff Dashboard
        </a>

    <?php endif; ?>

</div>


<!-- =====================================================
     SUMMARY
===================================================== -->

<div class="summary">

    <div class="summary-card">

        <h3>
            💸 Total Expenses
        </h3>

        <div class="amount">

            Rs.
            <?php
            echo number_format(
                $total_expense,
                2
            );
            ?>

        </div>

    </div>


    <div class="summary-card">

        <h3>
            📋 Number of Expenses
        </h3>

        <div class="amount">

            <?php
            echo count($expenses);
            ?>

        </div>

    </div>

</div>


<!-- =====================================================
     EXPENSE TABLE
===================================================== -->

<div class="table-wrapper">

<table>

<thead>

<tr>

    <th>ID</th>

    <th>Date</th>

    <th>Expense Name</th>

    <th>Category</th>

    <th>Amount</th>

    <th>Description</th>

    <th>Created At</th>

    <th>Actions</th>

</tr>

</thead>


<tbody>


<?php if (count($expenses) > 0): ?>


<?php foreach ($expenses as $row): ?>


<?php

$category = $row["category"];

$category_class = "other";


if ($category === "Staff Salary") {

    $category_class = "salary";

} elseif ($category === "Product Purchase") {

    $category_class = "product";

} elseif ($category === "Electricity") {

    $category_class = "electricity";

} elseif ($category === "Water") {

    $category_class = "water";

} elseif ($category === "Internet") {

    $category_class = "internet";

} elseif ($category === "Maintenance") {

    $category_class = "maintenance";

} elseif ($category === "Cleaning") {

    $category_class = "cleaning";

}

?>


<tr>


<!-- ID -->

<td>

    <?php
    echo $row["id"];
    ?>

</td>


<!-- DATE -->

<td>

    <?php

    echo date(
        "d M Y",
        strtotime($row["expense_date"])
    );

    ?>

</td>


<!-- NAME -->

<td>

    <strong>

        <?php

        echo htmlspecialchars(
            $row["expense_name"]
        );

        ?>

    </strong>

</td>


<!-- CATEGORY -->

<td>

    <span class="category <?php echo $category_class; ?>">

        <?php

        echo htmlspecialchars(
            $category
        );

        ?>

    </span>

</td>


<!-- AMOUNT -->

<td>

    <strong>

        Rs.

        <?php

        echo number_format(
            (float)$row["amount"],
            2
        );

        ?>

    </strong>

</td>


<!-- DESCRIPTION -->

<td>

    <?php

    if (!empty($row["description"])) {

        echo htmlspecialchars(
            $row["description"]
        );

    } else {

        echo "-";

    }

    ?>

</td>


<!-- CREATED -->

<td>

    <?php

    echo date(
        "d M Y H:i",
        strtotime($row["created_at"])
    );

    ?>

</td>


<!-- ACTIONS -->

<td class="actions">


<a
    class="edit"
    href="edit.php?id=<?php echo $row["id"]; ?>"
>
    ✏️ Edit
</a>


&nbsp; | &nbsp;


<?php if ($role === "admin"): ?>

<a
    class="delete"
    href="delete.php?id=<?php echo $row["id"]; ?>"
    onclick="return confirm(
        'Are you sure you want to delete this expense?'
    );"
>
    🗑️ Delete
</a>

<?php else: ?>

<span style="color:#999;">
    Delete restricted
</span>

<?php endif; ?>


</td>


</tr>


<?php endforeach; ?>


<?php else: ?>


<tr>

<td colspan="8" class="empty">

    No expenses recorded yet.

    <br><br>

    <a href="add.php">

        ➕ Add Your First Expense

    </a>

</td>

</tr>


<?php endif; ?>


</tbody>


</table>

</div>


<!-- =====================================================
     FOOTER
===================================================== -->

<div class="footer-links">

    <a href="../dashboard.php">
        🏠 Main Dashboard
    </a>

    <a href="add.php">
        ➕ Add Expense
    </a>

</div>


</div>


</body>

</html>