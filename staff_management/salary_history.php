
<?php
// File: staff_management/salary_history.php
// Suva Hotel - Monthly Salary and Payment Transaction History

session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$loggedInId = (int)$_SESSION['user_id'];

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
    exit('Only administrators and managers can view salary history.');
}

function historyEscape($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function historyMoney($value): string
{
    return 'Rs ' . number_format((float)$value, 2);
}

$error = '';
$selectedProfileId = filter_input(
    INPUT_GET,
    'staff_profile_id',
    FILTER_VALIDATE_INT
);

if (!$selectedProfileId || $selectedProfileId < 1) {
    $selectedProfileId = 0;
}

/* ---------------------------------------------------------
   EMPLOYEE FILTER
--------------------------------------------------------- */

$staff = [];

$staffResult = pg_query(
    $pgConnection,
    "SELECT
        p.id,
        p.staff_code,
        p.department,
        p.job_title,
        u.full_name
     FROM staff_profiles p
     JOIN users u ON u.id = p.user_id
     ORDER BY u.full_name"
);

if ($staffResult) {
    while ($row = pg_fetch_assoc($staffResult)) {
        $staff[] = $row;
    }
}

/* Verify that the selected profile exists. */

if ($selectedProfileId > 0) {
    $validProfile = false;

    foreach ($staff as $person) {
        if ((int)$person['id'] === $selectedProfileId) {
            $validProfile = true;
            break;
        }
    }

    if (!$validProfile) {
        $error = 'The selected employee profile was not found.';
        $selectedProfileId = 0;
    }
}

/* ---------------------------------------------------------
   BUILD FILTER
--------------------------------------------------------- */

$filterSql = '';
$filterParams = [];

if ($selectedProfileId > 0) {
    $filterSql = ' WHERE sp.staff_profile_id = $1';
    $filterParams = [$selectedProfileId];
}

/* ---------------------------------------------------------
   MONTHLY SALARY SUMMARY
--------------------------------------------------------- */

$monthlyRows = [];

$monthlyResult = pg_query_params(
    $pgConnection,
    "SELECT
        sp.id,
        sp.staff_profile_id,
        sp.salary_month,
        sp.due_date,
        sp.amount_due,
        sp.amount_paid,
        sp.payment_status,
        sp.payment_method,
        sp.paid_at,
        sp.notes,
        p.staff_code,
        p.department,
        p.job_title,
        u.full_name,
        GREATEST(sp.amount_due - sp.amount_paid, 0) AS remaining
     FROM staff_salary_payments sp
     JOIN staff_profiles p ON p.id = sp.staff_profile_id
     JOIN users u ON u.id = p.user_id
     $filterSql
     ORDER BY sp.salary_month DESC, u.full_name ASC",
    $filterParams
);

if ($monthlyResult) {
    while ($row = pg_fetch_assoc($monthlyResult)) {
        $monthlyRows[] = $row;
    }
} else {
    error_log(
        'Salary history query failed: ' .
        pg_last_error($pgConnection)
    );
    $error = 'Could not load monthly salary records. Please check the database.';
}

/* ---------------------------------------------------------
   INDIVIDUAL PAYMENT TRANSACTIONS

   Transaction amounts are separate from the monthly summary.
   The two totals should not be added together.
--------------------------------------------------------- */

$transactionRows = [];

$transactionResult = pg_query_params(
    $pgConnection,
    "SELECT
        t.id,
        t.salary_payment_id,
        t.amount,
        t.payment_method,
        t.reference_note,
        t.recorded_by,
        t.paid_at,
        sp.staff_profile_id,
        sp.salary_month,
        sp.due_date,
        p.staff_code,
        p.department,
        p.job_title,
        u.full_name,
        recorder.full_name AS recorded_by_name
     FROM staff_salary_transactions t
     JOIN staff_salary_payments sp
        ON sp.id = t.salary_payment_id
     JOIN staff_profiles p
        ON p.id = sp.staff_profile_id
     JOIN users u
        ON u.id = p.user_id
     LEFT JOIN users recorder
        ON recorder.id = t.recorded_by
     $filterSql
     ORDER BY t.paid_at DESC, t.id DESC",
    $filterParams
);

if ($transactionResult) {
    while ($row = pg_fetch_assoc($transactionResult)) {
        $transactionRows[] = $row;
    }
} else {
    error_log(
        'Salary transaction history query failed: ' .
        pg_last_error($pgConnection)
    );

    $error = 'Could not load payment transactions. Verify the salary transaction table structure.';
}

/* ---------------------------------------------------------
   SUMMARY TOTALS
--------------------------------------------------------- */

$totalDue = 0.0;
$totalRecordedPaid = 0.0;
$totalRemaining = 0.0;

foreach ($monthlyRows as $row) {
    $totalDue += (float)$row['amount_due'];
    $totalRecordedPaid += (float)$row['amount_paid'];
    $totalRemaining += (float)$row['remaining'];
}

$totalTransactions = 0.0;

foreach ($transactionRows as $row) {
    $totalTransactions += (float)$row['amount'];
}

/* Employee filter label */

$selectedEmployee = null;

if ($selectedProfileId > 0) {
    foreach ($staff as $person) {
        if ((int)$person['id'] === $selectedProfileId) {
            $selectedEmployee = $person;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Salary History | Suva Hotel</title>

<style>
:root {
    --bg: #071a13;
    --panel: #103326;
    --gold: #dfbd70;
    --text: #f7f4eb;
    --muted: #bdcec3;
    --line: rgba(223,189,112,.23);
    --green: #9de1b6;
    --red: #ffaaaa;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    padding: 24px 14px;
    background:
        radial-gradient(circle at top right,#1a4934,transparent 45%),
        var(--bg);
    color: var(--text);
    font-family: "Segoe UI",Arial,sans-serif;
}

main {
    max-width: 1350px;
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
    font-weight: 800;
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
    grid-template-columns: repeat(auto-fit,minmax(185px,1fr));
    gap: 14px;
    margin-bottom: 20px;
}

.stat {
    background: #071f16;
    border: 1px solid var(--line);
    padding: 19px;
    border-radius: 12px;
}

.stat small { color: var(--muted); }

.stat strong {
    display: block;
    color: var(--gold);
    font-size: 23px;
    margin-top: 9px;
    overflow-wrap: anywhere;
}

label {
    display: block;
    margin-bottom: 8px;
    color: #f1d994;
    font-weight: 700;
    font-size: 13px;
}

select {
    width: 100%;
    padding: 12px;
    border: 1px solid #dfbd7050;
    border-radius: 8px;
    background: #071f16;
    color: white;
    font: inherit;
}

button, .btn {
    display: inline-block;
    padding: 11px 15px;
    border: 0;
    border-radius: 8px;
    background: linear-gradient(135deg,#d2ae5c,#f3df9d);
    color: #13271c;
    text-decoration: none;
    font-weight: 800;
    cursor: pointer;
}

.btn-secondary {
    background: transparent;
    border: 1px solid #dfbd7070;
    color: var(--text);
}

.actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.filter-grid {
    display: grid;
    grid-template-columns: minmax(0,1fr) auto;
    align-items: end;
    gap: 12px;
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    min-width: 900px;
    border-collapse: collapse;
}

th, td {
    padding: 12px;
    text-align: left;
    vertical-align: top;
    border-bottom: 1px solid #ffffff18;
}

th {
    color: var(--gold);
    text-transform: uppercase;
    font-size: 12px;
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
    color: var(--green);
}

.badge-red {
    background: #482525;
    color: var(--red);
}

.error {
    padding: 13px;
    border: 1px solid #a54a4a;
    border-radius: 9px;
    color: var(--red);
    margin-bottom: 15px;
}

.notice {
    padding: 14px;
    border: 1px solid var(--line);
    border-radius: 9px;
    background: rgba(7,31,22,.65);
    color: var(--muted);
    line-height: 1.6;
    margin-bottom: 15px;
}

@media(max-width:650px) {
    body { padding: 14px 9px; }
    header, .panel { padding: 17px; }
    .filter-grid { grid-template-columns: 1fr; }
    .stat strong { font-size: 21px; }
}
</style>
</head>

<body>
<main>

<header>
    <div class="eyebrow">Suva Hotel · Payroll Archive</div>
    <h1>Salary History</h1>

    <p>
        Review monthly salary records, individual payments, payment methods,
        and outstanding balances.
    </p>

    <?php if ($selectedEmployee): ?>
        <p>
            Viewing employee:
            <strong><?= historyEscape($selectedEmployee['full_name']) ?></strong>
            · <?= historyEscape($selectedEmployee['staff_code']) ?>
        </p>
    <?php endif; ?>

    <div class="actions">
        <a class="btn btn-secondary" href="salary.php">← Salary Management</a>
        <a class="btn btn-secondary" href="index.php">Staff Directory</a>
        <a class="btn btn-secondary" href="salary_history.php">All Employees</a>
    </div>
</header>

<?php if ($error): ?>
    <div class="error"><?= historyEscape($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['payment']) && $_GET['payment'] === 'recorded'): ?>
    <div class="notice">
        Payment recorded. Review the transaction history below to see its saved details.
    </div>
<?php endif; ?>

<section class="stats">
    <div class="stat">
        <small>Total Monthly Salary Amounts</small>
        <strong><?= historyEscape(historyMoney($totalDue)) ?></strong>
    </div>

    <div class="stat">
        <small>Total Recorded as Paid</small>
        <strong><?= historyEscape(historyMoney($totalRecordedPaid)) ?></strong>
    </div>

    <div class="stat">
        <small>Outstanding Balance</small>
        <strong><?= historyEscape(historyMoney($totalRemaining)) ?></strong>
    </div>

    <div class="stat">
        <small>Individual Payments Recorded</small>
        <strong><?= count($transactionRows) ?></strong>
        <div class="muted" style="font-size:12px;margin-top:7px">
            <?= historyEscape(historyMoney($totalTransactions)) ?> in transaction records
        </div>
    </div>
</section>

<section class="panel">
    <h2>Filter Employee</h2>

    <form method="get" action="salary_history.php">
        <div class="filter-grid">
            <div>
                <label for="staff_profile_id">Employee</label>
                <select name="staff_profile_id" id="staff_profile_id">
                    <option value="">All employees</option>

                    <?php foreach ($staff as $person): ?>
                        <option
                            value="<?= (int)$person['id'] ?>"
                            <?= $selectedProfileId === (int)$person['id']
                                ? 'selected'
                                : '' ?>
                        >
                            <?= historyEscape($person['full_name']) ?>
                            — <?= historyEscape($person['staff_code']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="actions">
                <button type="submit">Apply Filter</button>
                <a class="btn btn-secondary" href="salary_history.php">Reset</a>
            </div>
        </div>
    </form>
</section>

<section class="panel">
    <h2>Monthly Salary Records</h2>
    <p class="muted">
        One row represents an employee's salary for a particular month.
        The paid amount is the total recorded against that salary record.
    </p>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Salary Month</th>
                    <th>Due Date</th>
                    <th>Amount Due</th>
                    <th>Total Paid</th>
                    <th>Remaining</th>
                    <th>Status</th>
                    <th>Last Payment</th>
                    <th>Method</th>
                    <th>Notes</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
            <?php if (!$monthlyRows): ?>
                <tr>
                    <td colspan="11">No monthly salary records found for this filter.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($monthlyRows as $r): ?>
                    <?php
                    $remaining = max(0, (float)$r['remaining']);
                    $status = strtolower(trim($r['payment_status'] ?? 'due'));
                    ?>
                    <tr>
                        <td>
                            <strong><?= historyEscape($r['full_name']) ?></strong><br>
                            <span class="muted"><?= historyEscape($r['staff_code']) ?></span>
                        </td>

                        <td>
                            <?= historyEscape(
                                date('F Y', strtotime($r['salary_month']))
                            ) ?>
                        </td>

                        <td><?= historyEscape($r['due_date']) ?></td>

                        <td><?= historyEscape(historyMoney($r['amount_due'])) ?></td>

                        <td><?= historyEscape(historyMoney($r['amount_paid'])) ?></td>

                        <td>
                            <strong><?= historyEscape(historyMoney($remaining)) ?></strong>
                        </td>

                        <td>
                            <?php if ($remaining <= 0.009): ?>
                                <span class="badge badge-green">Paid</span>
                            <?php elseif ((float)$r['amount_paid'] > 0): ?>
                                <span class="badge">Partial</span>
                            <?php else: ?>
                                <span class="badge badge-red">
                                    <?= historyEscape($r['payment_status']) ?>
                                </span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?= $r['paid_at']
                                ? historyEscape($r['paid_at'])
                                : '—' ?>
                        </td>

                        <td><?= historyEscape($r['payment_method'] ?: '—') ?></td>

                        <td><?= historyEscape($r['notes'] ?: '—') ?></td>

                        <td>
                            <?php if ($remaining > 0.009): ?>
                                <a
                                    class="btn"
                                    href="salary_pay.php?id=<?= (int)$r['id'] ?>"
                                >Pay</a>
                            <?php else: ?>
                                <span class="badge badge-green">Settled</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <h2>Individual Payment Transactions</h2>

    <p class="muted">
        Each row below represents one separately recorded payment.
        Use this section to audit payment dates, amounts, methods, references,
        and the user who recorded each transaction.
    </p>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Payment Date</th>
                    <th>Employee</th>
                    <th>Salary Month</th>
                    <th>Payment Amount</th>
                    <th>Payment Method</th>
                    <th>Reference / Note</th>
                    <th>Recorded By</th>
                    <th>Salary Record</th>
                </tr>
            </thead>

            <tbody>
            <?php if (!$transactionRows): ?>
                <tr>
                    <td colspan="8">
                        No individual payment transactions have been recorded
                        for this filter yet.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($transactionRows as $t): ?>
                    <tr>
                        <td><?= historyEscape($t['paid_at']) ?></td>

                        <td>
                            <strong><?= historyEscape($t['full_name']) ?></strong><br>
                            <span class="muted"><?= historyEscape($t['staff_code']) ?></span>
                        </td>

                        <td>
                            <?= historyEscape(
                                date('F Y', strtotime($t['salary_month']))
                            ) ?>
                        </td>

                        <td>
                            <strong><?= historyEscape(historyMoney($t['amount'])) ?></strong>
                        </td>

                        <td><?= historyEscape($t['payment_method']) ?></td>

                        <td><?= historyEscape($t['reference_note'] ?: '—') ?></td>

                        <td>
                            <?= historyEscape($t['recorded_by_name'] ?: 'Unknown / unavailable') ?>
                        </td>

                        <td>#<?= (int)$t['salary_payment_id'] ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="notice" style="margin-top:16px;margin-bottom:0">
        <strong>Important accounting note:</strong>
        The monthly salary summary comes from
        <code>staff_salary_payments.amount_paid</code>.
        The individual payment total comes from
        <code>staff_salary_transactions</code>.
        These are two views of the same payroll activity, so do not add
        the two totals together. Payments made before transaction recording
        was implemented may appear in the monthly summary without appearing
        in the individual transaction list.
    </div>
</section>

<footer style="text-align:center;padding:12px;color:#bdcec3;font-size:12px">
    Suva Hotel · Payroll Archive · Authorized access only
</footer>

</main>
</body>
</html>
