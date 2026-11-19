
<?php
/**
 * ============================================================
 * SUVA HOTEL — EDIT EXPENSE
 * File: expenses/edit.php
 * PHP 8+ | PostgreSQL | XAMPP
 *
 * Features:
 * - Login protection
 * - Admin and staff access
 * - PostgreSQL parameterized queries
 * - Expense validation
 * - Edit existing expenses
 * - Error handling
 * - Responsive form design
 * ============================================================
 */

session_start();

/* =====================================================
   CHECK LOGIN
===================================================== */

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

/* =====================================================
   DATABASE CONNECTION
===================================================== */

require_once "../config/database.php";

$role = $_SESSION["role"] ?? "";

/*
 * The database configuration should provide:
 * $pgConnection = pg_connect(...);
 */

/* =====================================================
   CHECK ROLE
===================================================== */

if ($role !== "admin" && $role !== "staff") {
    header("Location: ../login.php");
    exit;
}

/* =====================================================
   CHECK DATABASE CONNECTION
===================================================== */

if (
    !isset($pgConnection) ||
    $pgConnection === false
) {
    error_log("Expense edit: PostgreSQL connection unavailable.");
    die("Database connection failed. Please contact the administrator.");
}

/* =====================================================
   GET EXPENSE ID
===================================================== */

$id = isset($_GET["id"])
    ? (int) $_GET["id"]
    : 0;

if ($id <= 0) {
    die("Invalid expense ID.");
}

/* =====================================================
   VARIABLES
===================================================== */

$message = "";
$message_type = "";

$expense_date = "";
$expense_name = "";
$category = "";
$amount = "";
$description = "";

/* =====================================================
   GET EXISTING EXPENSE
===================================================== */

$sql = "SELECT
            id,
            expense_date,
            expense_name,
            category,
            amount,
            description
        FROM expenses
        WHERE id = $1";

$result = pg_query_params(
    $pgConnection,
    $sql,
    [$id]
);

if ($result === false) {
    error_log(
        "Expense lookup failed: " .
        pg_last_error($pgConnection)
    );

    die("Database Error: Unable to retrieve the expense.");
}

$expense = pg_fetch_assoc($result);

pg_free_result($result);

if (!$expense) {
    die("Expense not found.");
}

/* =====================================================
   LOAD EXISTING EXPENSE DATA
===================================================== */

$expense_date = $expense["expense_date"] ?? "";
$expense_name = $expense["expense_name"] ?? "";
$category = $expense["category"] ?? "";
$amount = $expense["amount"] ?? "";
$description = $expense["description"] ?? "";

/* =====================================================
   UPDATE EXPENSE
===================================================== */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /* -----------------------------------------------
       GET FORM VALUES
    ------------------------------------------------ */

    $expense_date = trim($_POST["expense_date"] ?? "");
    $expense_name = trim($_POST["expense_name"] ?? "");
    $category = trim($_POST["category"] ?? "");

    $amount_input = trim($_POST["amount"] ?? "");

    $description = trim($_POST["description"] ?? "");

    /* -----------------------------------------------
       VALIDATION
    ------------------------------------------------ */

    if ($expense_date === "") {

        $message = "Please select expense date.";
        $message_type = "error";

    } elseif (
        !preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $expense_date
        ) ||
        !checkdate(
            (int) substr($expense_date, 5, 2),
            (int) substr($expense_date, 8, 2),
            (int) substr($expense_date, 0, 4)
        )
    ) {

        $message = "Please enter a valid expense date.";
        $message_type = "error";

    } elseif ($expense_name === "") {

        $message = "Please enter expense name.";
        $message_type = "error";

    } elseif ($category === "") {

        $message = "Please select expense category.";
        $message_type = "error";

    } elseif (
        $amount_input === "" ||
        !is_numeric($amount_input) ||
        (float) $amount_input <= 0
    ) {

        $message = "Amount must be greater than 0.";
        $message_type = "error";

    } else {

        $amount = (float) $amount_input;

        /* -------------------------------------------
           UPDATE DATABASE USING POSTGRESQL
        -------------------------------------------- */

        $update_sql = "UPDATE expenses
                       SET
                           expense_date = $1,
                           expense_name = $2,
                           category = $3,
                           amount = $4,
                           description = $5
                       WHERE id = $6";

        $update_result = pg_query_params(
            $pgConnection,
            $update_sql,
            [
                $expense_date,
                $expense_name,
                $category,
                $amount,
                $description,
                $id
            ]
        );

        /* -------------------------------------------
           CHECK UPDATE RESULT
        -------------------------------------------- */

        if ($update_result !== false) {

            pg_free_result($update_result);

            header("Location: view.php?updated=1");
            exit;

        } else {

            error_log(
                "Expense update failed: " .
                pg_last_error($pgConnection)
            );

            $message = "Error updating expense. Please try again.";
            $message_type = "error";
        }
    }
}

/* =====================================================
   HTML ESCAPE HELPER
===================================================== */

function expenseEscape($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
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

    <title>Edit Expense - Suva Hotel</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            margin: 0;
            padding: 30px;
            color: #263238;
        }

        .container {
            max-width: 700px;
            margin: auto;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.10);
        }

        h1 {
            color: #1f3c88;
            margin-top: 0;
            margin-bottom: 5px;
        }

        h2 {
            color: #333;
            margin-bottom: 25px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-top: 15px;
            margin-bottom: 6px;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 15px;
            background: #fff;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #1f3c88;
            box-shadow: 0 0 0 3px rgba(31, 60, 136, 0.10);
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        button {
            width: 100%;
            padding: 13px;
            margin-top: 25px;
            background: #1f3c88;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        button:hover {
            background: #162d68;
        }

        .success {
            background: #e8f7ee;
            color: #16753b;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .error {
            background: #fdeaea;
            color: #c62828;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .links {
            margin-top: 25px;
            text-align: center;
        }

        .links a {
            display: inline-block;
            margin: 5px;
            padding: 10px 15px;
            background: #eee;
            color: #333;
            text-decoration: none;
            border-radius: 5px;
            transition: background 0.2s ease;
        }

        .links a:hover {
            background: #ddd;
        }

        .info {
            background: #eef3ff;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            color: #333;
        }

        @media (max-width: 600px) {

            body {
                padding: 12px;
            }

            .container {
                padding: 20px;
            }

            h1 {
                font-size: 24px;
            }

            h2 {
                font-size: 20px;
            }

            .links a {
                display: block;
                margin: 10px 0;
            }

        }

    </style>

</head>

<body>

<div class="container">

    <h1>🏨 Suva Hotel</h1>

    <h2>✏️ Edit Expense</h2>

    <div class="info">

        Editing Expense ID:

        <strong>
            #<?php echo expenseEscape($id); ?>
        </strong>

    </div>

    <?php if ($message !== ""): ?>

        <div
            class="<?php echo expenseEscape($message_type); ?>"
            role="alert"
        >
            <?php echo expenseEscape($message); ?>
        </div>

    <?php endif; ?>

    <form method="POST" action="">

        <!-- DATE -->

        <label for="expense_date">
            📅 Expense Date
        </label>

        <input
            type="date"
            id="expense_date"
            name="expense_date"
            value="<?php echo expenseEscape($expense_date); ?>"
            required
        >

        <!-- EXPENSE NAME -->

        <label for="expense_name">
            📝 Expense Name
        </label>

        <input
            type="text"
            id="expense_name"
            name="expense_name"
            value="<?php echo expenseEscape($expense_name); ?>"
            maxlength="255"
            required
        >

        <!-- CATEGORY -->

        <label for="category">
            📂 Category
        </label>

        <select
            id="category"
            name="category"
            required
        >

            <option
                value=""
                <?php echo $category === "" ? "selected" : ""; ?>
            >
                -- Select Category --
            </option>

            <option
                value="Staff Salary"
                <?php echo $category === "Staff Salary" ? "selected" : ""; ?>
            >
                👨‍💼 Staff Salary
            </option>

            <option
                value="Product Purchase"
                <?php echo $category === "Product Purchase" ? "selected" : ""; ?>
            >
                📦 Product Purchase
            </option>

            <option
                value="Electricity"
                <?php echo $category === "Electricity" ? "selected" : ""; ?>
            >
                💡 Electricity
            </option>

            <option
                value="Water"
                <?php echo $category === "Water" ? "selected" : ""; ?>
            >
                💧 Water
            </option>

            <option
                value="Internet"
                <?php echo $category === "Internet" ? "selected" : ""; ?>
            >
                🌐 Internet
            </option>

            <option
                value="Maintenance"
                <?php echo $category === "Maintenance" ? "selected" : ""; ?>
            >
                🔧 Maintenance
            </option>

            <option
                value="Cleaning"
                <?php echo $category === "Cleaning" ? "selected" : ""; ?>
            >
                🧹 Cleaning
            </option>

            <option
                value="Other"
                <?php echo $category === "Other" ? "selected" : ""; ?>
            >
                📋 Other
            </option>

        </select>

        <!-- AMOUNT -->

        <label for="amount">
            💰 Amount (Rs.)
        </label>

        <input
            type="number"
            id="amount"
            name="amount"
            value="<?php echo expenseEscape($amount); ?>"
            min="0.01"
            step="0.01"
            required
        >

        <!-- DESCRIPTION -->

        <label for="description">
            📄 Description
        </label>

        <textarea
            id="description"
            name="description"
            placeholder="Optional details..."
        ><?php echo expenseEscape($description); ?></textarea>

        <!-- UPDATE BUTTON -->

        <button type="submit">
            💾 Update Expense
        </button>

    </form>

    <!-- NAVIGATION -->

    <div class="links">

        <a href="view.php">
            ← Back to Expenses
        </a>

        <?php if ($role === "admin"): ?>

            <a href="../dashboard.php">
                🏠 Admin Dashboard
            </a>

        <?php else: ?>

            <a href="../staff_dashboard/staff_dashboard.php">
                🏠 Staff Dashboard
            </a>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
