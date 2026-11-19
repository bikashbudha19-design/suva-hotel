
<?php
// ============================================================
// SUVA HOTEL - ADD EXPENSE
// PostgreSQL Version
// File: /expenses/add.php
// ============================================================

session_start();

// ------------------------------------------------------------
// 1. LOGIN AND ROLE CHECK
// ------------------------------------------------------------

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

$role = $_SESSION["role"] ?? "";

if ($role !== "admin" && $role !== "staff") {
    header("Location: ../login.php");
    exit;
}

// ------------------------------------------------------------
// 2. DATABASE CONNECTION
// ------------------------------------------------------------

require_once __DIR__ . "/../config/database.php";

// Use the native PostgreSQL connection, not the wrapper's
// unsupported MySQLi-style prepare() method.
if (!isset($pgConnection) || !$pgConnection) {
    die("Database connection failed. Please check config/database.php.");
}

// ------------------------------------------------------------
// 3. CSRF TOKEN
// ------------------------------------------------------------

if (empty($_SESSION["expense_csrf_token"])) {
    $_SESSION["expense_csrf_token"] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["expense_csrf_token"];

// ------------------------------------------------------------
// 4. FORM DEFAULTS
// ------------------------------------------------------------

$expenseDate = date("Y-m-d");
$expenseName = "";
$category = "";
$amount = "";
$description = "";

$errors = [];
$success = isset($_GET["success"]) && $_GET["success"] === "1";

$allowedCategories = [
    "Staff Salary",
    "Product Purchase",
    "Electricity",
    "Water",
    "Internet",
    "Maintenance",
    "Cleaning",
    "Other"
];

// ------------------------------------------------------------
// 5. PROCESS FORM
// ------------------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $expenseDate = trim($_POST["expense_date"] ?? "");
    $expenseName = trim($_POST["expense_name"] ?? "");
    $category = trim($_POST["category"] ?? "");
    $amount = trim($_POST["amount"] ?? "");
    $description = trim($_POST["description"] ?? "");

    $submittedToken = $_POST["csrf_token"] ?? "";

    // CSRF protection
    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        $errors[] = "Invalid form session. Please reload the page and try again.";
    }

    // Required fields
    if ($expenseDate === "") {
        $errors[] = "Please select the expense date.";
    } else {
        $dateObject = DateTime::createFromFormat("!Y-m-d", $expenseDate);

        if (
            !$dateObject ||
            $dateObject->format("Y-m-d") !== $expenseDate
        ) {
            $errors[] = "Please enter a valid expense date.";
        }
    }

    if ($expenseName === "") {
        $errors[] = "Please enter the expense name.";
    } elseif (strlen($expenseName) > 255) {
        $errors[] = "The expense name must not exceed 255 characters.";
    }

    if (!in_array($category, $allowedCategories, true)) {
        $errors[] = "Please select a valid expense category.";
    }

    if (
        $amount === "" ||
        !is_numeric($amount) ||
        !is_finite((float)$amount) ||
        (float)$amount <= 0
    ) {
        $errors[] = "Enter a valid amount greater than zero.";
    } elseif (strlen($amount) > 30) {
        $errors[] = "The amount entered is too large.";
    }

    if (strlen($description) > 10000) {
        $errors[] = "The description is too long.";
    }

    // Insert only after validation succeeds
    if (empty($errors)) {

        $sql = "INSERT INTO expenses
                    (
                        expense_date,
                        expense_name,
                        category,
                        amount,
                        description
                    )
                VALUES ($1, $2, $3, $4, $5)";

        $result = pg_query_params(
            $pgConnection,
            $sql,
            [
                $expenseDate,
                $expenseName,
                $category,
                $amount,
                $description !== "" ? $description : null
            ]
        );

        if ($result) {

            // Rotate token after successful submission
            $_SESSION["expense_csrf_token"] =
                bin2hex(random_bytes(32));

            header("Location: add.php?success=1");
            exit;

        } else {
            error_log(
                "Suva Hotel expense insert failed: " .
                pg_last_error($pgConnection)
            );

            $errors[] = "The expense could not be saved. Please check the database connection and try again.";
        }
    }
}

// ------------------------------------------------------------
// 6. HTML ESCAPING HELPER
// ------------------------------------------------------------

function expenseEscape($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        "UTF-8"
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Add Expense - Suva Hotel</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px;
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            color: #263238;
        }

        .container {
            max-width: 800px;
            margin: 25px auto;
            padding: 30px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.10);
        }

        h1 {
            margin-top: 0;
            color: #1f3c88;
        }

        .subtitle {
            color: #666;
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccd2da;
            border-radius: 6px;
            font: inherit;
            background: #fff;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: 2px solid #1f3c88;
            outline-offset: 1px;
        }

        textarea {
            min-height: 110px;
            resize: vertical;
        }

        .buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 25px;
        }

        .button {
            display: inline-block;
            border: none;
            border-radius: 6px;
            padding: 12px 18px;
            background: #1f3c88;
            color: white;
            text-decoration: none;
            cursor: pointer;
            font-size: 15px;
        }

        .button:hover {
            background: #162d68;
        }

        .secondary {
            background: #59636e;
        }

        .secondary:hover {
            background: #3f4850;
        }

        .message {
            padding: 13px 16px;
            margin-bottom: 20px;
            border-radius: 6px;
        }

        .success {
            background: #e4f7e8;
            color: #17652a;
            border: 1px solid #b5e2bf;
        }

        .error {
            background: #fff0f0;
            color: #9d1c1c;
            border: 1px solid #efb8b8;
        }

        .required {
            color: #c62828;
        }

        @media (max-width: 600px) {
            body {
                padding: 12px;
            }

            .container {
                margin: 10px auto;
                padding: 20px;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <h1>🏨 Suva Hotel</h1>
    <div class="subtitle">💸 Record a new hotel expense</div>

    <?php if ($success): ?>
        <div class="message success" role="status">
            Expense saved successfully!
            You can add another expense or view your records.
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="message error" role="alert">
            <strong>Please correct the following:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= expenseEscape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="add.php">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= expenseEscape($csrfToken) ?>"
        >

        <div class="form-group">
            <label for="expense_date">
                Expense Date <span class="required">*</span>
            </label>

            <input
                type="date"
                id="expense_date"
                name="expense_date"
                value="<?= expenseEscape($expenseDate) ?>"
                required
            >
        </div>

        <div class="form-group">
            <label for="expense_name">
                Expense Name <span class="required">*</span>
            </label>

            <input
                type="text"
                id="expense_name"
                name="expense_name"
                maxlength="255"
                value="<?= expenseEscape($expenseName) ?>"
                placeholder="e.g. Kitchen supplies"
                required
            >
        </div>

        <div class="form-group">
            <label for="category">
                Category <span class="required">*</span>
            </label>

            <select id="category" name="category" required>
                <option value="">-- Select Category --</option>

                <?php foreach ($allowedCategories as $item): ?>
                    <option
                        value="<?= expenseEscape($item) ?>"
                        <?= $category === $item ? "selected" : "" ?>
                    >
                        <?= expenseEscape($item) ?>
                    </option>
                <?php endforeach; ?>

            </select>
        </div>

        <div class="form-group">
            <label for="amount">
                Amount (Rs.) <span class="required">*</span>
            </label>

            <input
                type="number"
                id="amount"
                name="amount"
                min="0.01"
                step="0.01"
                value="<?= expenseEscape($amount) ?>"
                placeholder="e.g. 1500.00"
                required
            >
        </div>

        <div class="form-group">
            <label for="description">Description (optional)</label>

            <textarea
                id="description"
                name="description"
                maxlength="10000"
                placeholder="Enter additional details..."
            ><?= expenseEscape($description) ?></textarea>
        </div>

        <div class="buttons">
            <button type="submit" class="button">
                💾 Save Expense
            </button>

            <a href="view.php" class="button secondary">
                📋 View Expenses
            </a>

            <a href="../dashboard.php" class="button secondary">
                🏠 Main Dashboard
            </a>
        </div>

    </form>

</div>

</body>
</html>
