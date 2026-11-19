
<?php
// File: staff_management/salary_pay.php
// Suva Hotel — Individual Salary Payment Recording

session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$loggedInId = (int) $_SESSION['user_id'];

/* ---------------- ACCESS CONTROL ---------------- */

$auth = pg_query_params(
    $pgConnection,
    'SELECT role FROM users WHERE id = $1',
    [$loggedInId]
);

if (!$auth || pg_num_rows($auth) !== 1) {
    http_response_code(403);
    exit('Access denied.');
}

$role = strtolower(trim(pg_fetch_result($auth, 0, 'role')));

if (!in_array($role, ['admin', 'manager'], true)) {
    http_response_code(403);
    exit('Only administrators and managers can record salary payments.');
}

function payEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/* ---------------- SALARY RECORD ID ---------------- */

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
}

if (!$id || $id < 1) {
    http_response_code(400);
    exit('Invalid salary record ID.');
}

$error = '';
$paymentAmount = '';
$method = '';
$notes = '';

/* ---------------- PROCESS PAYMENT ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $paymentAmount = trim($_POST['payment_amount'] ?? '');
    $method = trim($_POST['payment_method'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $allowedMethods = [
        'Cash',
        'Bank Transfer',
        'Mobile Banking',
        'Other'
    ];

    if (
        $paymentAmount === '' ||
        !is_numeric($paymentAmount) ||
        !is_finite((float) $paymentAmount) ||
        (float) $paymentAmount <= 0
    ) {
        $error = 'Enter a valid payment amount greater than zero.';
    } elseif (
        !in_array($method, $allowedMethods, true)
    ) {
        $error = 'Select a valid payment method.';
    } elseif (strlen($notes) > 2000) {
        $error = 'The payment reference must not exceed 2000 characters.';
    } elseif ((float) $paymentAmount > 9999999999.99) {
        $error = 'The payment amount is too large.';
    } else {
        $amount = round((float) $paymentAmount, 2);

        if ($amount <= 0) {
            $error = 'Enter a valid payment amount.';
        } elseif (!pg_query($pgConnection, 'BEGIN')) {
            $error = 'Could not start the payment transaction.';
        } else {
            /*
             * Lock the salary row so concurrent payments cannot
             * both spend the same remaining balance.
             */
            $recordResult = pg_query_params(
                $pgConnection,
                "SELECT id, amount_due, amount_paid, payment_status
                 FROM staff_salary_payments
                 WHERE id = $1
                 FOR UPDATE",
                [$id]
            );

            $transactionOK = false;

            if (
                !$recordResult ||
                pg_num_rows($recordResult) !== 1
            ) {
                $error = 'Salary record not found.';
            } else {
                $record = pg_fetch_assoc($recordResult);

                $due = round((float) $record['amount_due'], 2);
                $previousPaid = round((float) $record['amount_paid'], 2);
                $remaining = max(0, round($due - $previousPaid, 2));

                if ($remaining <= 0 || $record['payment_status'] === 'Paid') {
                    $error = 'This salary has already been fully paid.';
                } elseif ($amount > $remaining) {
                    $error = 'Payment exceeds the remaining balance of Rs '
                        . number_format($remaining, 2) . '.';
                } else {
                    $newPaid = round($previousPaid + $amount, 2);
                    $newStatus = $newPaid >= $due ? 'Paid' : 'Partial';

                    /*
                     * 1. Record this payment independently.
                     */
                    $insert = pg_query_params(
                        $pgConnection,
                        "INSERT INTO staff_salary_transactions
                            (
                                salary_payment_id,
                                amount,
                                payment_method,
                                reference_note,
                                recorded_by,
                                paid_at
                            )
                         VALUES ($1, $2, $3, $4, $5, CURRENT_TIMESTAMP)",
                        [
                            $id,
                            number_format($amount, 2, '.', ''),
                            $method,
                            $notes,
                            $loggedInId
                        ]
                    );

                    if ($insert) {
                        /*
                         * 2. Update the monthly salary summary.
                         * Both operations commit together.
                         */
                        $update = pg_query_params(
                            $pgConnection,
                            "UPDATE staff_salary_payments
                             SET amount_paid = $1,
                                 payment_status = $2,
                                 payment_method = $3,
                                 paid_at = CURRENT_TIMESTAMP,
                                 notes = CASE
                                     WHEN COALESCE(notes, '') = '' THEN $4
                                     WHEN $4 = '' THEN notes
                                     ELSE notes || E'\\n' || $4
                                 END,
                                 recorded_by = $5,
                                 updated_at = CURRENT_TIMESTAMP
                             WHERE id = $6",
                            [
                                number_format($newPaid, 2, '.', ''),
                                $newStatus,
                                $method,
                                $notes,
                                $loggedInId,
                                $id
                            ]
                        );

                        if ($update && pg_affected_rows($update) === 1) {
                            $transactionOK = true;
                        } else {
                            $error = 'Could not update the salary summary.';
                            error_log(
                                'Salary summary update failed: '
                                . pg_last_error($pgConnection)
                            );
                        }
                    } else {
                        $error = 'Could not save the payment transaction.';
                        error_log(
                            'Salary transaction insert failed: '
                            . pg_last_error($pgConnection)
                        );
                    }
                }
            }

            if ($transactionOK) {
                if (pg_query($pgConnection, 'COMMIT')) {
                    header(
                        'Location: salary_history.php?staff_profile_id='
                        . (int) ($_POST['staff_profile_id'] ?? 0)
                        . '&payment=recorded'
                    );
                    exit;
                }

                $error = 'Payment commit failed. Please check the database before retrying.';
                error_log(
                    'Salary payment commit failed: '
                    . pg_last_error($pgConnection)
                );
            } else {
                pg_query($pgConnection, 'ROLLBACK');
            }
        }
    }
}

/* ---------------- LOAD SALARY SUMMARY ---------------- */

$result = pg_query_params(
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
        p.department,
        u.full_name
     FROM staff_salary_payments sp
     JOIN staff_profiles p ON p.id = sp.staff_profile_id
     JOIN users u ON u.id = p.user_id
     WHERE sp.id = $1",
    [$id]
);

if (!$result || pg_num_rows($result) !== 1) {
    http_response_code(404);
    exit('Salary record not found.');
}

$r = pg_fetch_assoc($result);

$remaining = max(
    0,
    round((float) $r['amount_due'] - (float) $r['amount_paid'], 2)
);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Record Salary Payment | Suva Hotel</title>

<style>
* { box-sizing: border-box; }

body {
    margin: 0;
    padding: 25px 14px;
    background:
        radial-gradient(circle at top right, #1a4934, transparent 45%),
        #071a13;
    color: #f8f6ee;
    font-family: "Segoe UI", Arial, sans-serif;
}

main { max-width: 720px; margin: auto; }

.panel {
    background: #103326;
    border: 1px solid #dfbd7050;
    border-radius: 18px;
    padding: 26px;
    box-shadow: 0 18px 50px #0004;
}

.eyebrow {
    color: #dfbd70;
    letter-spacing: 3px;
    font-size: 11px;
    font-weight: bold;
}

h1 { color: #f3df9d; margin: 13px 0; }

p, small, .muted { color: #bdcec3; }

.summary {
    padding: 17px;
    background: #071f16;
    border: 1px solid #dfbd7025;
    border-radius: 12px;
    line-height: 2;
    margin: 20px 0;
    overflow-wrap: anywhere;
}

label {
    display: block;
    margin: 17px 0 7px;
    color: #f3df9d;
    font-weight: 700;
}

input, select, textarea {
    width: 100%;
    padding: 12px;
    background: #071f16;
    border: 1px solid #dfbd7060;
    color: white;
    border-radius: 8px;
    font: inherit;
}

textarea { min-height: 90px; resize: vertical; }

button, .btn {
    display: inline-block;
    padding: 12px 16px;
    margin: 16px 8px 0 0;
    background: linear-gradient(135deg, #d2ae5c, #f3df9d);
    border: 0;
    border-radius: 8px;
    color: #10271b;
    font-weight: 800;
    text-decoration: none;
    cursor: pointer;
}

.btn-secondary {
    background: transparent;
    border: 1px solid #dfbd7070;
    color: white;
}

.error {
    color: #ffbaba;
    background: #4b1d1d55;
    border: 1px solid #a54a4a;
    border-radius: 8px;
    padding: 12px;
    line-height: 1.6;
}

.notice {
    padding: 13px;
    border-radius: 8px;
    background: #173d2d;
    color: #f3df9d;
    line-height: 1.6;
}

.actions { display: flex; flex-wrap: wrap; gap: 4px; }

@media (max-width: 480px) {
    .panel { padding: 18px; }
    .actions a, .actions button { width: 100%; text-align: center; }
}
</style>
</head>

<body>
<main>
<section class="panel">

    <div class="eyebrow">SUVA HOTEL · PAYROLL</div>
    <h1>Record Salary Payment</h1>
    <p>Record each salary payment separately for accurate payroll history.</p>

    <?php if ($error !== ''): ?>
        <div class="error"><?= payEscape($error) ?></div>
    <?php endif; ?>

    <div class="summary">
        <strong><?= payEscape($r['full_name']) ?></strong><br>

        <small>
            <?= payEscape($r['staff_code']) ?>
            · <?= payEscape($r['department']) ?>
            · <?= payEscape($r['job_title']) ?>
        </small><br>

        Salary month:
        <strong><?= payEscape(date('F Y', strtotime($r['salary_month']))) ?></strong><br>

        Due date: <?= payEscape($r['due_date']) ?><br>

        Salary amount:
        <strong>Rs <?= number_format((float) $r['amount_due'], 2) ?></strong><br>

        Previously paid:
        Rs <?= number_format((float) $r['amount_paid'], 2) ?><br>

        <strong>Remaining: Rs <?= number_format($remaining, 2) ?></strong>
    </div>

    <?php if ($remaining <= 0): ?>

        <div class="notice">This salary has already been fully paid.</div>

        <a class="btn btn-secondary" href="salary_history.php">
            View Salary History
        </a>

    <?php else: ?>

        <form method="post">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">

            <input
                type="hidden"
                name="staff_profile_id"
                value="<?= (int) $r['staff_profile_id'] ?>"
            >

            <label for="payment_amount">Payment Amount (Rs) *</label>
            <input
                type="number"
                name="payment_amount"
                id="payment_amount"
                min="0.01"
                max="<?= number_format($remaining, 2, '.', '') ?>"
                step="0.01"
                value="<?= payEscape($paymentAmount) ?>"
                required
            >

            <label for="payment_method">Payment Method *</label>
            <select name="payment_method" id="payment_method" required>
                <option value="">Select method</option>
                <?php foreach ([
                    'Cash',
                    'Bank Transfer',
                    'Mobile Banking',
                    'Other'
                ] as $paymentOption): ?>
                    <option
                        value="<?= payEscape($paymentOption) ?>"
                        <?= $method === $paymentOption ? 'selected' : '' ?>
                    >
                        <?= payEscape($paymentOption) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="notes">Payment Note / Reference</label>
            <textarea
                name="notes"
                id="notes"
                maxlength="2000"
                placeholder="Receipt number or payment reference (optional)"
            ><?= payEscape($notes) ?></textarea>

            <div class="actions">
                <button type="submit">Confirm Payment Record</button>
                <a class="btn btn-secondary" href="salary_history.php">
                    Cancel
                </a>
            </div>
        </form>

    <?php endif; ?>

</section>
</main>
</body>
</html>
