
<?php
// File: staff_management/salary.php
// Suva Hotel - Staff Salary Management
// PHP 8 / PostgreSQL

session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$loggedInId = (int) $_SESSION['user_id'];

$roleResult = pg_query_params(
    $pgConnection,
    'SELECT role FROM users WHERE id = $1',
    [$loggedInId]
);

if (!$roleResult || pg_num_rows($roleResult) !== 1) {
    http_response_code(403);
    exit('Access denied.');
}

$role = strtolower(trim(pg_fetch_result($roleResult, 0, 'role')));

if (!in_array($role, ['admin', 'manager'], true)) {
    http_response_code(403);
    exit('Only administrators and managers can manage salaries.');
}

function salaryEscape($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
 * First salary period:
 * Always use the exact first due date.
 *
 * Later salary periods:
 * Use the recurring payment day, or the first due date's day.
 * If the chosen day does not exist in a month, use that month's
 * last day.
 */
function salaryDateForMonth(
    DateTimeImmutable $firstDue,
    int $year,
    int $month,
    ?int $paymentDay
): string {
    if (
        $year === (int)$firstDue->format('Y') &&
        $month === (int)$firstDue->format('m')
    ) {
        return $firstDue->format('Y-m-d');
    }

    $day = $paymentDay ?: (int)$firstDue->format('d');

    $monthStart = new DateTimeImmutable(
        sprintf('%04d-%02d-01', $year, $month)
    );

    $lastDay = (int)$monthStart
        ->modify('last day of this month')
        ->format('d');

    $day = min($day, $lastDay);

    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

/*
 * Calculate the next scheduled due date.
 * This includes future salary dates, so employees can see
 * upcoming salaries before the due date arrives.
 */
function nextSalaryDueDate(
    string $firstDueString,
    ?int $paymentDay,
    DateTimeImmutable $today
): ?string {
    $firstDue = new DateTimeImmutable($firstDueString);

    $month = $firstDue->modify('first day of this month');
    $limit = $today->modify('first day of this month')
        ->modify('+24 months');

    while ($month <= $limit) {
        $dueDate = salaryDateForMonth(
            $firstDue,
            (int)$month->format('Y'),
            (int)$month->format('m'),
            $paymentDay
        );

        if (
            $dueDate >= $firstDue->format('Y-m-d') &&
            $dueDate >= $today->format('Y-m-d')
        ) {
            return $dueDate;
        }

        $month = $month->modify('+1 month');
    }

    return null;
}

$error = '';
$today = new DateTimeImmutable('today');
$todayString = $today->format('Y-m-d');

/* ---------------------------------------------------------
   SAVE OR UPDATE SALARY SETTINGS
--------------------------------------------------------- */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'save_salary'
) {
    $profileId = filter_input(
        INPUT_POST,
        'staff_profile_id',
        FILTER_VALIDATE_INT
    );

    $monthlySalary = trim($_POST['monthly_salary'] ?? '');
    $firstDue = trim($_POST['first_salary_due_date'] ?? '');
    $paymentDayInput = trim($_POST['payment_day'] ?? '');
    $active = isset($_POST['is_active']) ? 'true' : 'false';

    $paymentDay = null;

    if ($paymentDayInput !== '') {
        $paymentDay = filter_var(
            $paymentDayInput,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 31]]
        );
    }

    $dateObject = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $firstDue
    );

    $validDate = $dateObject &&
        $dateObject->format('Y-m-d') === $firstDue;

    $validSalary = $monthlySalary !== '' &&
        is_numeric($monthlySalary) &&
        is_finite((float)$monthlySalary) &&
        (float)$monthlySalary > 0;

    if (
        !$profileId ||
        !$validSalary ||
        !$validDate ||
        ($paymentDayInput !== '' && $paymentDay === false)
    ) {
        $error = 'Enter a valid employee, monthly salary greater than zero, first due date, and optional payment day.';
    } else {
        $profileCheck = pg_query_params(
            $pgConnection,
            'SELECT id FROM staff_profiles WHERE id = $1',
            [$profileId]
        );

        if (!$profileCheck || pg_num_rows($profileCheck) !== 1) {
            $error = 'The selected staff profile was not found.';
        } else {
            $saved = pg_query_params(
                $pgConnection,
                "INSERT INTO staff_salary_settings
                    (
                        staff_profile_id,
                        monthly_salary,
                        first_salary_due_date,
                        payment_day,
                        is_active
                    )
                 VALUES ($1, $2, $3, $4, $5)
                 ON CONFLICT (staff_profile_id)
                 DO UPDATE SET
                    monthly_salary = EXCLUDED.monthly_salary,
                    first_salary_due_date = EXCLUDED.first_salary_due_date,
                    payment_day = EXCLUDED.payment_day,
                    is_active = EXCLUDED.is_active,
                    updated_at = CURRENT_TIMESTAMP",
                [
                    $profileId,
                    number_format((float)$monthlySalary, 2, '.', ''),
                    $firstDue,
                    $paymentDay ?: null,
                    $active
                ]
            );

            if ($saved) {
                header('Location: salary.php?saved=1');
                exit;
            }

            error_log(
                'Salary settings save failed: ' .
                pg_last_error($pgConnection)
            );

            $error = 'Could not save salary settings. Check the database error log.';
        }
    }
}

/* ---------------------------------------------------------
   GENERATE SALARY RECORDS THAT HAVE REACHED THEIR DUE DATE

   Existing records are preserved using ON CONFLICT DO NOTHING.
   Future salary dates are not inserted into the due list early.
--------------------------------------------------------- */

$generationErrors = [];

$settingsResult = pg_query(
    $pgConnection,
    "SELECT
        staff_profile_id,
        monthly_salary,
        first_salary_due_date,
        payment_day
     FROM staff_salary_settings
     WHERE is_active = TRUE
     ORDER BY staff_profile_id"
);

if ($settingsResult) {
    while ($setting = pg_fetch_assoc($settingsResult)) {
        try {
            $firstDue = new DateTimeImmutable(
                $setting['first_salary_due_date']
            );

            $firstMonth = $firstDue->modify('first day of this month');
            $currentMonth = $today->modify('first day of this month');

            for (
                $month = $firstMonth;
                $month <= $currentMonth;
                $month = $month->modify('+1 month')
            ) {
                $year = (int)$month->format('Y');
                $monthNumber = (int)$month->format('m');

                $dueDate = salaryDateForMonth(
                    $firstDue,
                    $year,
                    $monthNumber,
                    $setting['payment_day'] !== null
                        ? (int)$setting['payment_day']
                        : null
                );

                // Do not generate a salary before its actual due date.
                if (
                    $dueDate < $firstDue->format('Y-m-d') ||
                    $dueDate > $todayString
                ) {
                    continue;
                }

                // Use the first day of the salary month as its identifier.
                $salaryMonth = $month->format('Y-m-d');

                $inserted = pg_query_params(
                    $pgConnection,
                    "INSERT INTO staff_salary_payments
                        (
                            staff_profile_id,
                            salary_month,
                            due_date,
                            amount_due,
                            amount_paid,
                            payment_status
                        )
                     VALUES ($1, $2, $3, $4, 0, 'Due')
                     ON CONFLICT (staff_profile_id, salary_month)
                     DO NOTHING",
                    [
                        $setting['staff_profile_id'],
                        $salaryMonth,
                        $dueDate,
                        $setting['monthly_salary']
                    ]
                );

                if (!$inserted) {
                    $generationErrors[] =
                        'Could not generate a salary record for staff profile ' .
                        (int)$setting['staff_profile_id'] . '.';

                    error_log(
                        'Salary record generation failed: ' .
                        pg_last_error($pgConnection)
                    );
                }
            }
        } catch (Throwable $e) {
            $generationErrors[] = 'A salary schedule could not be processed.';
            error_log('Salary generation error: ' . $e->getMessage());
        }
    }
} else {
    error_log(
        'Could not read salary settings: ' .
        pg_last_error($pgConnection)
    );

    $generationErrors[] = 'Salary schedules could not be checked. Please check the database.';
}

/* ---------------------------------------------------------
   LOAD STAFF AND SALARY SETTINGS
--------------------------------------------------------- */

$staff = [];

$staffResult = pg_query(
    $pgConnection,
    "SELECT
        p.id,
        p.staff_code,
        p.job_title,
        p.department,
        p.employment_status,
        u.full_name,
        u.username,
        s.monthly_salary,
        s.first_salary_due_date,
        s.payment_day,
        s.is_active
     FROM staff_profiles p
     JOIN users u ON u.id = p.user_id
     LEFT JOIN staff_salary_settings s
        ON s.staff_profile_id = p.id
     ORDER BY u.full_name"
);

if ($staffResult) {
    while ($row = pg_fetch_assoc($staffResult)) {
        $staff[] = $row;
    }
}

/* ---------------------------------------------------------
   UPCOMING SALARY DATES
--------------------------------------------------------- */

$upcomingRows = [];

foreach ($staff as $s) {
    if (
        $s['monthly_salary'] === null ||
        $s['first_salary_due_date'] === null ||
        $s['is_active'] !== 't'
    ) {
        continue;
    }

    $nextDue = nextSalaryDueDate(
        $s['first_salary_due_date'],
        $s['payment_day'] !== null
            ? (int)$s['payment_day']
            : null,
        $today
    );

    if ($nextDue !== null) {
        $upcomingRows[] = [
            'id' => (int)$s['id'],
            'full_name' => $s['full_name'],
            'staff_code' => $s['staff_code'],
            'monthly_salary' => $s['monthly_salary'],
            'next_due' => $nextDue
        ];
    }
}

usort(
    $upcomingRows,
    fn($a, $b) => strcmp($a['next_due'], $b['next_due'])
);

/* ---------------------------------------------------------
   LOAD OUTSTANDING SALARY RECORDS

   Balance is used as the primary condition, so a record with
   money still outstanding is not hidden by a stale status.
--------------------------------------------------------- */

$dueRows = [];

$dueResult = pg_query(
    $pgConnection,
    "SELECT
        sp.id,
        sp.staff_profile_id,
        sp.salary_month,
        sp.due_date,
        sp.amount_due,
        sp.amount_paid,
        sp.payment_status,
        p.staff_code,
        p.job_title,
        u.full_name,
        (sp.amount_due - sp.amount_paid) AS remaining
     FROM staff_salary_payments sp
     JOIN staff_profiles p
        ON p.id = sp.staff_profile_id
     JOIN users u
        ON u.id = p.user_id
     WHERE (sp.amount_due - sp.amount_paid) > 0.009
     ORDER BY sp.due_date ASC, u.full_name ASC"
);

if ($dueResult) {
    while ($row = pg_fetch_assoc($dueResult)) {
        $dueRows[] = $row;
    }
}

$totalOutstanding = 0.0;

foreach ($dueRows as $row) {
    $totalOutstanding += max(0, (float)$row['remaining']);
}

$configuredCount = count(
    array_filter(
        $staff,
        fn($s) => $s['monthly_salary'] !== null
    )
);

$activeScheduleCount = count($upcomingRows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Staff Salary Management | Suva Hotel</title>

<style>
:root {
    --bg: #071a13;
    --panel: #103326;
    --gold: #dfbd70;
    --text: #f7f4eb;
    --muted: #bdcec3;
    --red: #ffaaaa;
    --green: #9de1b6;
    --line: rgba(223,189,112,.22);
}

* { box-sizing: border-box; }

body {
    margin: 0;
    padding: 24px 14px;
    background:
        radial-gradient(circle at top right, #1a4934, transparent 45%),
        var(--bg);
    font-family: "Segoe UI", Arial, sans-serif;
    color: var(--text);
}

main {
    max-width: 1250px;
    margin: auto;
}

header, .panel {
    background: rgba(13,48,35,.96);
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 20px;
    box-shadow: 0 12px 35px rgba(0,0,0,.12);
}

.eyebrow {
    color: var(--gold);
    text-transform: uppercase;
    letter-spacing: 3px;
    font-size: 11px;
    font-weight: bold;
}

h1 {
    margin: 12px 0;
    font-size: clamp(27px,4vw,38px);
}

h2 { margin-top: 0; }

p, .muted {
    color: var(--muted);
    line-height: 1.6;
}

.stats {
    display: grid;
    grid-template-columns: repeat(auto-fit,minmax(190px,1fr));
    gap: 14px;
    margin-bottom: 20px;
}

.stat {
    padding: 20px;
    border-radius: 12px;
    background: #071f16;
    border: 1px solid var(--line);
}

.stat small { color: var(--muted); }

.stat strong {
    display: block;
    color: var(--gold);
    font-size: 25px;
    margin-top: 8px;
    overflow-wrap: anywhere;
}

.grid {
    display: grid;
    grid-template-columns: repeat(2,minmax(0,1fr));
    gap: 14px;
}

label {
    display: block;
    margin-bottom: 7px;
    color: #f1d994;
    font-size: 13px;
    font-weight: 700;
}

input, select {
    width: 100%;
    padding: 12px;
    border: 1px solid #dfbd7050;
    border-radius: 8px;
    background: #071f16;
    color: white;
    font: inherit;
}

input[type="checkbox"] { accent-color: var(--gold); }

.field { margin-bottom: 14px; }

button, .btn {
    display: inline-block;
    border: 0;
    border-radius: 8px;
    padding: 11px 15px;
    background: linear-gradient(135deg,#d2ae5c,#f3df9d);
    color: #13271c;
    text-decoration: none;
    font-weight: 800;
    cursor: pointer;
    white-space: nowrap;
}

.btn-secondary {
    background: transparent;
    border: 1px solid #dfbd7070;
    color: var(--text);
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

table {
    border-collapse: collapse;
    width: 100%;
    min-width: 760px;
}

th, td {
    text-align: left;
    padding: 13px 12px;
    border-bottom: 1px solid #ffffff18;
    vertical-align: top;
}

th {
    color: var(--gold);
    font-size: 12px;
    text-transform: uppercase;
    white-space: nowrap;
}

td { font-size: 14px; }

.badge {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    background: #493b1d;
    color: #ffe3a0;
    font-size: 12px;
    white-space: nowrap;
}

.badge-green {
    background: #183f2c;
    color: #a8edbd;
}

.badge-red {
    background: #482525;
    color: #ffbaba;
}

.error, .success, .notice {
    padding: 13px;
    border-radius: 9px;
    margin-bottom: 15px;
    line-height: 1.6;
}

.error {
    border: 1px solid #a54a4a;
    color: var(--red);
}

.success {
    border: 1px solid #438d5b;
    color: var(--green);
}

.notice {
    border: 1px solid #a58a43;
    color: #ffe3a0;
    background: rgba(73,59,29,.25);
}

.actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.section-intro { margin-top: -4px; }

@media(max-width:650px) {
    body { padding: 14px 9px; }
    .grid { grid-template-columns: 1fr; }
    header, .panel { padding: 17px; }
    .stat strong { font-size: 22px; }
}
</style>
</head>

<body>
<main>

<header>
    <div class="eyebrow">Suva Hotel · Payroll Office</div>
    <h1>Staff Salary Management</h1>
    <p>
        Set monthly salaries, track due payments, review upcoming salary dates,
        and maintain employee-by-employee salary records.
    </p>

    <div class="actions">
        <a class="btn btn-secondary" href="index.php">← Staff Directory</a>
        <a class="btn btn-secondary" href="salary_history.php">Salary History</a>
    </div>
</header>

<?php if ($error): ?>
    <div class="error"><?= salaryEscape($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="success">
        Salary settings saved successfully.
    </div>
<?php endif; ?>

<?php foreach ($generationErrors as $generationError): ?>
    <div class="notice"><?= salaryEscape($generationError) ?></div>
<?php endforeach; ?>

<section class="stats">
    <div class="stat">
        <small>Employees with salary settings</small>
        <strong><?= $configuredCount ?></strong>
    </div>

    <div class="stat">
        <small>Active salary schedules</small>
        <strong><?= $activeScheduleCount ?></strong>
    </div>

    <div class="stat">
        <small>Outstanding salary records</small>
        <strong><?= count($dueRows) ?></strong>
    </div>

    <div class="stat">
        <small>Total outstanding</small>
        <strong>Rs <?= number_format($totalOutstanding, 2) ?></strong>
    </div>
</section>

<section class="panel">
    <h2>Set or Update Monthly Salary</h2>
    <p class="muted">
        Choose an employee and enter the monthly salary and first due date.
        Future salary records will be generated when their due dates arrive.
    </p>

    <form method="post" action="salary.php">
        <input type="hidden" name="action" value="save_salary">

        <div class="grid">
            <div class="field">
                <label for="staff_profile_id">Employee *</label>

                <select name="staff_profile_id" id="staff_profile_id" required>
                    <option value="">Choose employee</option>

                    <?php foreach ($staff as $s): ?>
                        <option value="<?= (int)$s['id'] ?>">
                            <?= salaryEscape($s['full_name']) ?>
                            — <?= salaryEscape($s['staff_code']) ?>
                            (<?= salaryEscape($s['job_title']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="monthly_salary">Monthly Salary (Rs) *</label>
                <input
                    type="number"
                    name="monthly_salary"
                    id="monthly_salary"
                    min="0.01"
                    step="0.01"
                    required
                    placeholder="e.g. 20000"
                >
            </div>

            <div class="field">
                <label for="first_salary_due_date">First Salary Due Date *</label>
                <input
                    type="date"
                    name="first_salary_due_date"
                    id="first_salary_due_date"
                    required
                >
            </div>

            <div class="field">
                <label for="payment_day">Recurring Payment Day (optional)</label>
                <input
                    type="number"
                    name="payment_day"
                    id="payment_day"
                    min="1"
                    max="31"
                    placeholder="Blank = first due date's day"
                >
                <small class="muted">
                    For example, 31 uses the last day of shorter months.
                </small>
            </div>
        </div>

        <div class="field">
            <label>
                <input
                    type="checkbox"
                    name="is_active"
                    value="1"
                    checked
                    style="width:auto"
                >
                Salary schedule is active
            </label>
        </div>

        <button type="submit">Save Salary Settings</button>
    </form>
</section>

<section class="panel">
    <h2>Employee Salary Settings</h2>
    <p class="muted section-intro">
        View each employee's salary configuration and open their salary history.
    </p>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Department / Position</th>
                    <th>Monthly Salary</th>
                    <th>First Due Date</th>
                    <th>Payment Day</th>
                    <th>Schedule</th>
                    <th>History</th>
                </tr>
            </thead>

            <tbody>
            <?php if (!$staff): ?>
                <tr>
                    <td colspan="7">No staff profiles found. Register staff profiles first.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($staff as $s): ?>
                    <tr>
                        <td>
                            <strong><?= salaryEscape($s['full_name']) ?></strong><br>
                            <span class="muted"><?= salaryEscape($s['staff_code']) ?></span>
                        </td>

                        <td>
                            <?= salaryEscape($s['department']) ?><br>
                            <?= salaryEscape($s['job_title']) ?>
                        </td>

                        <td>
                            <?= $s['monthly_salary'] !== null
                                ? 'Rs ' . number_format((float)$s['monthly_salary'], 2)
                                : 'Not configured' ?>
                        </td>

                        <td>
                            <?= salaryEscape($s['first_salary_due_date'] ?: '—') ?>
                        </td>

                        <td>
                            <?= $s['payment_day'] !== null
                                ? (int)$s['payment_day']
                                : 'Monthly anniversary' ?>
                        </td>

                        <td>
                            <?php if ($s['monthly_salary'] === null): ?>
                                <span class="badge">Not set</span>
                            <?php elseif ($s['is_active'] === 't'): ?>
                                <span class="badge badge-green">Active</span>
                            <?php else: ?>
                                <span class="badge">Paused</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <a
                                class="btn btn-secondary"
                                href="salary_history.php?staff_profile_id=<?= (int)$s['id'] ?>"
                            >View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <h2>📅 Upcoming Salary Due</h2>
    <p class="muted">
        Active schedules appear here even when their due dates have not arrived.
        Upcoming dates are not counted as outstanding debts yet.
    </p>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Next Salary Due Date</th>
                    <th>Scheduled Amount</th>
                    <th>Schedule Status</th>
                    <th>History</th>
                </tr>
            </thead>

            <tbody>
            <?php if (!$upcomingRows): ?>
                <tr>
                    <td colspan="5">
                        No active salary schedules with a valid due date were found.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($upcomingRows as $r): ?>
                    <tr>
                        <td>
                            <strong><?= salaryEscape($r['full_name']) ?></strong><br>
                            <span class="muted"><?= salaryEscape($r['staff_code']) ?></span>
                        </td>

                        <td>
                            <?= salaryEscape($r['next_due']) ?>

                            <?php if ($r['next_due'] === $todayString): ?>
                                <br><span class="badge">Due today</span>
                            <?php elseif ($r['next_due'] > $todayString): ?>
                                <br><span class="badge badge-green">Upcoming</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            Rs <?= number_format((float)$r['monthly_salary'], 2) ?>
                        </td>

                        <td><span class="badge badge-green">Active</span></td>

                        <td>
                            <a
                                class="btn btn-secondary"
                                href="salary_history.php?staff_profile_id=<?= (int)$r['id'] ?>"
                            >View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <h2>🔔 Unpaid and Partially Paid Salaries</h2>
    <p class="muted">
        This table contains salary records with an outstanding balance.
        Past due dates are overdue; today's due date is marked separately.
    </p>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Salary Month</th>
                    <th>Due Date</th>
                    <th>Amount Due</th>
                    <th>Paid</th>
                    <th>Remaining</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
            <?php if (!$dueRows): ?>
                <tr>
                    <td colspan="8">
                        No outstanding salary records at present.
                        Check the upcoming salary dates above for the next scheduled payments.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($dueRows as $r): ?>
                    <tr>
                        <td>
                            <strong><?= salaryEscape($r['full_name']) ?></strong><br>
                            <span class="muted"><?= salaryEscape($r['staff_code']) ?></span>
                        </td>

                        <td>
                            <?= salaryEscape(
                                date('F Y', strtotime($r['salary_month']))
                            ) ?>
                        </td>

                        <td>
                            <?= salaryEscape($r['due_date']) ?>

                            <?php if ($r['due_date'] < $todayString): ?>
                                <br><span class="badge badge-red">Overdue</span>
                            <?php elseif ($r['due_date'] === $todayString): ?>
                                <br><span class="badge">Due today</span>
                            <?php endif; ?>
                        </td>

                        <td>Rs <?= number_format((float)$r['amount_due'], 2) ?></td>

                        <td>Rs <?= number_format((float)$r['amount_paid'], 2) ?></td>

                        <td>
                            <strong>
                                Rs <?= number_format(max(0, (float)$r['remaining']), 2) ?>
                            </strong>
                        </td>

                        <td>
                            <span class="badge">
                                <?= salaryEscape($r['payment_status']) ?>
                            </span>
                        </td>

                        <td>
                            <a
                                class="btn"
                                href="salary_pay.php?id=<?= (int)$r['id'] ?>"
                            >Record Payment</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<footer style="text-align:center;padding:12px;color:#bdcec3;font-size:12px">
    Suva Hotel · Payroll Office · Salary records are maintained in PostgreSQL
</footer>

</main>
</body>
</html>
