
<?php
/**
 * SUVA HOTEL - ADD STAFF PROFILE
 * File: staff_management/add.php
 * PHP 8 / PostgreSQL
 */

session_start();

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';

/* -------------------------------------------------
   1. AUTHENTICATION AND AUTHORIZATION
------------------------------------------------- */

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

$roleResult = pg_query_params(
    $pgConnection,
    'SELECT role FROM users WHERE id = $1',
    [$userId]
);

if (!$roleResult || pg_num_rows($roleResult) !== 1) {
    http_response_code(403);
    exit('Access denied.');
}

$currentRole = strtolower(trim(pg_fetch_result($roleResult, 0, 'role')));

if (!in_array($currentRole, ['admin', 'manager'], true)) {
    http_response_code(403);
    exit('Only administrators and managers may manage staff profiles.');
}

/* -------------------------------------------------
   2. HELPERS
------------------------------------------------- */

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$error = '';
$success = '';

/* -------------------------------------------------
   3. EXISTING LOGIN ACCOUNTS WITHOUT PROFILES
------------------------------------------------- */

$accountsResult = pg_query(
    $pgConnection,
    "SELECT u.id, u.username, u.full_name, u.role
     FROM users u
     LEFT JOIN staff_profiles p ON p.user_id = u.id
     WHERE LOWER(u.role) IN ('admin', 'manager', 'staff')
       AND p.id IS NULL
     ORDER BY u.full_name"
);

$accounts = [];

if ($accountsResult) {
    while ($row = pg_fetch_assoc($accountsResult)) {
        $accounts[] = $row;
    }
}

/* -------------------------------------------------
   4. PREFILL SELECTED ACCOUNT
------------------------------------------------- */

$selectedUserId = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedUserId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
}

/* -------------------------------------------------
   5. PROCESS FORM
------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $staffCode = trim($_POST['staff_code'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $jobTitle = trim($_POST['job_title'] ?? '');
    $employmentType = trim($_POST['employment_type'] ?? 'Full-time');
    $hireDate = trim($_POST['hire_date'] ?? '');
    $employmentStatus = trim($_POST['employment_status'] ?? 'Active');
    $notes = trim($_POST['notes'] ?? '');

    $allowedDepartments = [
        'Administration',
        'Front Office',
        'Housekeeping',
        'Restaurant',
        'Kitchen',
        'Maintenance',
        'Security',
        'Accounts',
        'Sales & Marketing',
        'Swimming Pool',
        'Other'
    ];

    $allowedTypes = [
        'Full-time',
        'Part-time',
        'Contract',
        'Temporary',
        'Intern'
    ];

    $allowedStatuses = [
        'Active',
        'On Leave',
        'Resigned',
        'Terminated'
    ];

    if (!$selectedUserId || $selectedUserId <= 0) {
        $error = 'Please select an existing login account.';
    } elseif ($staffCode === '' || $department === '' || $jobTitle === '') {
        $error = 'Staff ID, department and job title are required.';
    } elseif (!in_array($department, $allowedDepartments, true)) {
        $error = 'Please select a valid department.';
    } elseif (!in_array($employmentType, $allowedTypes, true)) {
        $error = 'Please select a valid employment type.';
    } elseif (!in_array($employmentStatus, $allowedStatuses, true)) {
        $error = 'Please select a valid employment status.';
    } elseif (strlen($staffCode) > 30 || strlen($department) > 100 || strlen($jobTitle) > 100) {
        $error = 'One or more fields exceed the permitted length.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($hireDate === '') {
        $error = 'Please enter the joining date.';
    } else {
        $dateCheck = DateTime::createFromFormat('!Y-m-d', $hireDate);

        if (!$dateCheck || $dateCheck->format('Y-m-d') !== $hireDate) {
            $error = 'Please enter a valid joining date.';
        } elseif ($hireDate > date('Y-m-d')) {
            $error = 'Joining date cannot be in the future.';
        }
    }

    if ($error === '') {

        pg_query($pgConnection, 'BEGIN');

        /*
         * Lock the account while creating its profile.
         * This prevents simultaneous duplicate registrations.
         */
        $accountCheck = pg_query_params(
            $pgConnection,
            "SELECT u.id, u.full_name, u.role
             FROM users u
             LEFT JOIN staff_profiles p ON p.user_id = u.id
             WHERE u.id = $1
               AND LOWER(u.role) IN ('admin', 'manager', 'staff')
               AND p.id IS NULL
             FOR UPDATE OF u",
            [$selectedUserId]
        );

        if (!$accountCheck || pg_num_rows($accountCheck) !== 1) {
            pg_query($pgConnection, 'ROLLBACK');
            $error = 'The selected account already has a profile or does not exist.';
        } else {

            $account = pg_fetch_assoc($accountCheck);

            $insert = pg_query_params(
                $pgConnection,
                "INSERT INTO staff_profiles (
                    user_id,
                    staff_code,
                    phone,
                    email,
                    address,
                    department,
                    job_title,
                    employment_type,
                    hire_date,
                    employment_status,
                    notes
                ) VALUES (
                    $1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11
                )
                RETURNING id",
                [
                    $selectedUserId,
                    $staffCode,
                    $phone !== '' ? $phone : null,
                    $email !== '' ? $email : null,
                    $address !== '' ? $address : null,
                    $department,
                    $jobTitle,
                    $employmentType,
                    $hireDate,
                    $employmentStatus,
                    $notes !== '' ? $notes : null
                ]
            );

            if (!$insert) {
                pg_query($pgConnection, 'ROLLBACK');

                $dbError = pg_last_error($pgConnection);

                if (stripos($dbError, 'staff_profiles_staff_code_key') !== false) {
                    $error = 'This Staff ID is already in use. Please choose another.';
                } else {
                    error_log('Staff profile insert failed: ' . $dbError);
                    $error = 'Could not create the staff profile. Check the PHP error log.';
                }
            } else {

                $profileId = (int) pg_fetch_result($insert, 0, 'id');

                $positionInsert = pg_query_params(
                    $pgConnection,
                    "INSERT INTO staff_position_history (
                        staff_profile_id,
                        previous_position,
                        new_position,
                        previous_department,
                        new_department,
                        effective_date,
                        reason,
                        recorded_by
                    ) VALUES (
                        $1, NULL, $2, NULL, $3, $4,
                        'Initial appointment', $5
                    )",
                    [
                        $profileId,
                        $jobTitle,
                        $department,
                        $hireDate,
                        $userId
                    ]
                );

                $historyInsert = pg_query_params(
                    $pgConnection,
                    "INSERT INTO staff_history (
                        staff_profile_id,
                        event_type,
                        event_title,
                        details,
                        event_date,
                        recorded_by
                    ) VALUES (
                        $1, 'Joining', 'Employee profile created',
                        $2, $3, $4
                    )",
                    [
                        $profileId,
                        'Initial position: ' . $jobTitle .
                            '; Department: ' . $department,
                        $hireDate,
                        $userId
                    ]
                );

                if (!$positionInsert || !$historyInsert) {
                    pg_query($pgConnection, 'ROLLBACK');
                    $error = 'Could not save the initial history. No profile changes were committed.';
                } else {
                    $commit = pg_query($pgConnection, 'COMMIT');

                    if (!$commit) {
                        pg_query($pgConnection, 'ROLLBACK');
                        $error = 'Database could not commit the staff profile.';
                    } else {
                        header('Location: view.php?id=' . $profileId . '&created=1');
                        exit;
                    }
                }
            }
        }
    }
}

/* Reload available accounts after validation errors */
$accountsResult = pg_query(
    $pgConnection,
    "SELECT u.id, u.username, u.full_name, u.role
     FROM users u
     LEFT JOIN staff_profiles p ON p.user_id = u.id
     WHERE LOWER(u.role) IN ('admin', 'manager', 'staff')
       AND p.id IS NULL
     ORDER BY u.full_name"
);

$accounts = [];

if ($accountsResult) {
    while ($row = pg_fetch_assoc($accountsResult)) {
        $accounts[] = $row;
    }
}

$departments = [
    'Administration',
    'Front Office',
    'Housekeeping',
    'Restaurant',
    'Kitchen',
    'Maintenance',
    'Security',
    'Accounts',
    'Sales & Marketing',
    'Swimming Pool',
    'Other'
];

$employmentTypes = [
    'Full-time',
    'Part-time',
    'Contract',
    'Temporary',
    'Intern'
];

$employmentStatuses = [
    'Active',
    'On Leave',
    'Resigned',
    'Terminated'
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Register Staff | Suva Hotel</title>

<style>
:root {
    --green: #092b22;
    --panel: #10382b;
    --gold: #d8b66a;
    --light: #f3df9d;
    --muted: #bdccc3;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    padding: 30px 15px;
    font-family: "Segoe UI", Arial, sans-serif;
    color: #f8f6ee;
    background:
        radial-gradient(circle at top right, #174c37, transparent 45%),
        linear-gradient(135deg, #061710, #0b2a20);
    min-height: 100vh;
}

.container {
    max-width: 900px;
    margin: auto;
}

.header {
    padding: 30px;
    border: 1px solid rgba(216,182,106,.3);
    border-radius: 20px 20px 0 0;
    background: linear-gradient(120deg, #103d2d, #09251b);
}

.eyebrow {
    color: var(--gold);
    letter-spacing: 3px;
    text-transform: uppercase;
    font-size: 11px;
    font-weight: 800;
}

h1 {
    margin: 12px 0;
    font-size: clamp(28px, 5vw, 40px);
}

p { color: var(--muted); line-height: 1.7; }

.form-panel {
    padding: 30px;
    background: rgba(12,39,29,.97);
    border: 1px solid rgba(216,182,106,.2);
    border-top: 0;
    border-radius: 0 0 20px 20px;
}

.grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 19px;
}

.field {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.full { grid-column: 1 / -1; }

label {
    font-size: 12px;
    color: var(--light);
    font-weight: 700;
}

input, select, textarea {
    width: 100%;
    padding: 13px;
    border-radius: 9px;
    border: 1px solid rgba(216,182,106,.25);
    background: #102d22;
    color: #fff;
    font: inherit;
    outline: none;
}

input:focus, select:focus, textarea:focus {
    border-color: var(--gold);
}

textarea {
    min-height: 100px;
    resize: vertical;
}

small {
    color: var(--muted);
    line-height: 1.5;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 25px;
}

.button {
    padding: 13px 19px;
    border-radius: 9px;
    font-weight: 800;
    border: 1px solid rgba(216,182,106,.35);
    background: transparent;
    color: #f8f6ee;
    text-decoration: none;
    cursor: pointer;
}

.primary {
    background: linear-gradient(135deg, #d2ae5c, #f3df9d);
    color: #10271b;
    border: none;
}

.alert {
    padding: 15px;
    margin-bottom: 22px;
    border-radius: 10px;
    line-height: 1.6;
}

.error {
    background: rgba(150,40,40,.2);
    border: 1px solid #a64c4c;
    color: #ffcece;
}

@media (max-width: 600px) {
    body { padding: 15px 10px; }
    .header, .form-panel { padding: 20px; }
    .grid { grid-template-columns: 1fr; }
    .full { grid-column: auto; }
}
</style>
</head>

<body>

<div class="container">

    <header class="header">
        <div class="eyebrow">Suva Hotel · People & Excellence</div>
        <h1>Register Staff Member</h1>
        <p>
            Create an employee profile linked to an existing hotel login.
            The initial position and joining history will be saved automatically.
        </p>
    </header>

    <main class="form-panel">

        <?php if ($error !== ''): ?>
            <div class="alert error">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <?php if (count($accounts) === 0 && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>

            <div class="alert error">
                All eligible login accounts already have profiles,
                or there are no eligible accounts to link.
            </div>

            <a class="button" href="index.php">Back to Staff Directory</a>

        <?php else: ?>

        <form method="POST" action="add.php" autocomplete="on">

            <div class="grid">

                <div class="field full">
                    <label for="user_id">Existing Login Account *</label>

                    <select name="user_id" id="user_id" required>
                        <option value="">Choose an account</option>

                        <?php foreach ($accounts as $account): ?>
                            <option
                                value="<?= (int)$account['id'] ?>"
                                <?= (string)$selectedUserId ===
                                    (string)$account['id'] ? 'selected' : '' ?>
                            >
                                <?= e($account['full_name']) ?>
                                (@<?= e($account['username']) ?>,
                                <?= e($account['role']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <small>
                        Select the employee's existing login.
                        This form will not create or modify passwords.
                    </small>
                </div>

                <div class="field">
                    <label for="staff_code">Staff ID *</label>
                    <input
                        type="text"
                        name="staff_code"
                        id="staff_code"
                        maxlength="30"
                        placeholder="e.g. SH-001"
                        value="<?= e($_POST['staff_code'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label for="phone">Phone Number</label>
                    <input
                        type="tel"
                        name="phone"
                        id="phone"
                        maxlength="30"
                        value="<?= e($_POST['phone'] ?? '') ?>"
                    >
                </div>

                <div class="field">
                    <label for="email">Email Address</label>
                    <input
                        type="email"
                        name="email"
                        id="email"
                        maxlength="150"
                        value="<?= e($_POST['email'] ?? '') ?>"
                    >
                </div>

                <div class="field">
                    <label for="hire_date">Joining Date *</label>
                    <input
                        type="date"
                        name="hire_date"
                        id="hire_date"
                        max="<?= date('Y-m-d') ?>"
                        value="<?= e($_POST['hire_date'] ?? date('Y-m-d')) ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label for="department">Department *</label>
                    <select name="department" id="department" required>
                        <option value="">Choose department</option>

                        <?php foreach ($departments as $item): ?>
                            <option
                                value="<?= e($item) ?>"
                                <?= ($_POST['department'] ?? '') === $item
                                    ? 'selected' : '' ?>
                            >
                                <?= e($item) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="job_title">Job Title / Position *</label>
                    <input
                        type="text"
                        name="job_title"
                        id="job_title"
                        maxlength="100"
                        placeholder="e.g. Receptionist"
                        value="<?= e($_POST['job_title'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label for="employment_type">Employment Type</label>
                    <select name="employment_type" id="employment_type">
                        <?php foreach ($employmentTypes as $item): ?>
                            <option
                                value="<?= e($item) ?>"
                                <?= ($_POST['employment_type'] ?? 'Full-time')
                                    === $item ? 'selected' : '' ?>
                            >
                                <?= e($item) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="employment_status">Employment Status</label>
                    <select name="employment_status" id="employment_status">
                        <?php foreach ($employmentStatuses as $item): ?>
                            <option
                                value="<?= e($item) ?>"
                                <?= ($_POST['employment_status'] ?? 'Active')
                                    === $item ? 'selected' : '' ?>
                            >
                                <?= e($item) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field full">
                    <label for="address">Address</label>
                    <textarea
                        name="address"
                        id="address"
                        placeholder="Employee address"
                    ><?= e($_POST['address'] ?? '') ?></textarea>
                </div>

                <div class="field full">
                    <label for="notes">Additional Notes</label>
                    <textarea
                        name="notes"
                        id="notes"
                        placeholder="Optional employment notes"
                    ><?= e($_POST['notes'] ?? '') ?></textarea>
                </div>

            </div>

            <div class="actions">
                <button type="submit" class="button primary">
                    Save Staff Profile
                </button>

                <a href="index.php" class="button">
                    Cancel
                </a>
            </div>

        </form>

        <?php endif; ?>

    </main>

</div>

</body>
</html>
