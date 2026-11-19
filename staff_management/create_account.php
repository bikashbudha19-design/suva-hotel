<?php
/**
 * SUVA HOTEL - CREATE STAFF LOGIN ACCOUNT
 * File: staff_management/create_account.php
 * PHP 8 / PostgreSQL
 */

session_start();

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';

/* Authentication */
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

/* Only admin and manager */
$roleResult = pg_query_params(
    $pgConnection,
    'SELECT role FROM users WHERE id = $1',
    [$userId]
);

if (!$roleResult || pg_num_rows($roleResult) !== 1) {
    http_response_code(403);
    exit('Access denied.');
}

$currentRole = strtolower(trim(
    pg_fetch_result($roleResult, 0, 'role')
));

if (!in_array($currentRole, ['admin', 'manager'], true)) {
    http_response_code(403);
    exit('Only administrators and managers may create staff accounts.');
}

function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

$error = '';
$fullName = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($fullName === '' || $username === '' || $password === '') {
        $error = 'Please complete all required fields.';
    } elseif (strlen($fullName) > 150 || strlen($username) > 100) {
        $error = 'Name or username is too long.';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]+$/', $username)) {
        $error = 'Username may contain letters, numbers, dots, underscores and hyphens only.';
    } elseif (strlen($password) < 12) {
        $error = 'Password must contain at least 12 characters.';
    } elseif (strlen($password) > 72) {
        $error = 'Password is too long for this password hashing configuration.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        if ($passwordHash === false) {
            $error = 'Could not securely process the password.';
        } else {
            $insert = pg_query_params(
                $pgConnection,
                "INSERT INTO users (username, password, full_name, role)
                 VALUES ($1, $2, $3, 'staff')
                 RETURNING id",
                [
                    $username,
                    $passwordHash,
                    $fullName
                ]
            );

            if (!$insert) {
                $dbError = pg_last_error($pgConnection);

                if (stripos($dbError, 'unique') !== false ||
                    stripos($dbError, 'duplicate key') !== false) {
                    $error = 'This username already exists. Please choose another.';
                } else {
                    error_log('Staff account creation failed: ' . $dbError);
                    $error = 'Could not create the account. Check the PHP error log.';
                }
            } else {
                $newUserId = (int) pg_fetch_result($insert, 0, 'id');

                header(
                    'Location: add.php?user_id=' .
                    $newUserId .
                    '&account_created=1'
                );
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Create Staff Login | Suva Hotel</title>

<style>
* { box-sizing: border-box; }

body {
    margin: 0;
    padding: 25px 14px;
    min-height: 100vh;
    display: grid;
    place-items: center;
    font-family: "Segoe UI", Arial, sans-serif;
    color: #f7f4e9;
    background:
        radial-gradient(circle at top right, #1b5039, transparent 45%),
        linear-gradient(135deg, #061710, #0b2a20);
}

.card {
    width: 100%;
    max-width: 650px;
    padding: 32px;
    border: 1px solid rgba(216,182,106,.35);
    border-radius: 20px;
    background: rgba(12,39,29,.97);
    box-shadow: 0 20px 60px rgba(0,0,0,.3);
}

.eyebrow {
    color: #d8b66a;
    text-transform: uppercase;
    letter-spacing: 3px;
    font-size: 11px;
    font-weight: 800;
}

h1 { margin-bottom: 8px; }

p {
    color: #bdccc3;
    line-height: 1.7;
}

label {
    display: block;
    margin: 18px 0 8px;
    color: #f3df9d;
    font-weight: 700;
    font-size: 13px;
}

input {
    width: 100%;
    padding: 13px;
    border: 1px solid rgba(216,182,106,.3);
    border-radius: 9px;
    background: #102d22;
    color: white;
    font-size: 15px;
}

input:focus {
    outline: 2px solid #d8b66a;
    outline-offset: 1px;
}

button, .link {
    display: inline-block;
    margin-top: 22px;
    padding: 13px 18px;
    border: 0;
    border-radius: 9px;
    font-weight: 800;
    text-decoration: none;
    cursor: pointer;
}

button {
    background: linear-gradient(135deg, #d2ae5c, #f3df9d);
    color: #10271b;
}

.link {
    border: 1px solid rgba(216,182,106,.35);
    color: #f8f6ee;
}

.error {
    padding: 13px;
    border: 1px solid #a64c4c;
    border-radius: 9px;
    color: #ffcece;
    background: rgba(150,40,40,.2);
}

@media (max-width: 480px) {
    .card { padding: 22px; }
}
</style>
</head>
<body>

<main class="card">
    <div class="eyebrow">Suva Hotel · People & Excellence</div>
    <h1>Create Staff Login</h1>

    <p>
        Create a unique login account for a new employee.
        The account will use the Staff role, then you can enter
        the employee's department, position and joining history.
    </p>

    <?php if ($error !== ''): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="create_account.php" autocomplete="off">
        <label for="full_name">Employee Full Name *</label>
        <input
            type="text"
            id="full_name"
            name="full_name"
            maxlength="150"
            value="<?= e($fullName) ?>"
            autocomplete="name"
            required
        >

        <label for="username">Login Username *</label>
        <input
            type="text"
            id="username"
            name="username"
            maxlength="100"
            value="<?= e($username) ?>"
            autocomplete="username"
            required
        >

        <label for="password">Temporary Password *</label>
        <input
            type="password"
            id="password"
            name="password"
            minlength="12"
            maxlength="72"
            autocomplete="new-password"
            required
        >

        <label for="confirm_password">Confirm Password *</label>
        <input
            type="password"
            id="confirm_password"
            name="confirm_password"
            minlength="12"
            maxlength="72"
            autocomplete="new-password"
            required
        >

        <button type="submit">Create Account & Continue</button>
        <a class="link" href="index.php">Back to Staff Directory</a>
    </form>
</main>

</body>
</html>