
<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];

$auth = pg_query_params(
    $pgConnection,
    'SELECT role FROM users WHERE id = $1',
    [$userId]
);

if (!$auth || pg_num_rows($auth) !== 1) {
    http_response_code(403);
    exit('Access denied.');
}

$role = strtolower(trim(pg_fetch_result($auth, 0, 'role')));

if (!in_array($role, ['admin', 'manager'], true)) {
    http_response_code(403);
    exit('Only administrators and managers can view staff profiles.');
}

function staffViewEscape($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$profileId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$profileId || $profileId < 1) {
    http_response_code(400);
    exit('Invalid staff profile ID.');
}

$result = pg_query_params(
    $pgConnection,
    "SELECT p.*, u.username, u.full_name, u.role AS login_role
     FROM staff_profiles p
     JOIN users u ON u.id = p.user_id
     WHERE p.id = $1",
    [$profileId]
);

if (!$result || pg_num_rows($result) !== 1) {
    http_response_code(404);
    exit('Staff profile not found.');
}

$staff = pg_fetch_assoc($result);

$salaryResult = pg_query_params(
    $pgConnection,
    "SELECT monthly_salary, first_salary_due_date, payment_day, is_active
     FROM staff_salary_settings
     WHERE staff_profile_id = $1",
    [$profileId]
);

$salary = ($salaryResult && pg_num_rows($salaryResult) > 0)
    ? pg_fetch_assoc($salaryResult)
    : null;

$historyResult = pg_query_params(
    $pgConnection,
    "SELECT event_type, event_title, details, event_date
     FROM staff_history
     WHERE staff_profile_id = $1
     ORDER BY event_date DESC, id DESC",
    [$profileId]
);

$positionResult = pg_query_params(
    $pgConnection,
    "SELECT previous_position, new_position,
            previous_department, new_department,
            effective_date, reason
     FROM staff_position_history
     WHERE staff_profile_id = $1
     ORDER BY effective_date DESC, id DESC",
    [$profileId]
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Staff Profile | Suva Hotel</title>
<style>
*{box-sizing:border-box}
body{margin:0;padding:24px 14px;background:radial-gradient(circle at top right,#194832,transparent 45%),#071a13;color:#f8f6ee;font-family:Segoe UI,Arial,sans-serif}
main{max-width:1100px;margin:auto}
.panel{padding:23px;margin-bottom:20px;background:#103326;border:1px solid #dfbd7040;border-radius:16px}
.eyebrow{color:#dfbd70;letter-spacing:3px;font-size:11px;font-weight:bold}
h1{font-size:clamp(27px,4vw,38px);margin:12px 0}
h2{color:#f3df9d}
p,small{color:#bdcec3;line-height:1.7}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px}
.item{padding:15px;background:#071f16;border:1px solid #ffffff12;border-radius:10px;overflow-wrap:anywhere}
.item small{display:block;font-size:11px;text-transform:uppercase;letter-spacing:1px}
.item strong{display:block;margin-top:7px}
.btn{display:inline-block;padding:11px 15px;border-radius:8px;background:linear-gradient(135deg,#d2ae5c,#f3df9d);color:#10271b;text-decoration:none;font-weight:bold;margin:5px 5px 5px 0}
.secondary{background:transparent;color:white;border:1px solid #dfbd7070}
table{border-collapse:collapse;width:100%;min-width:600px}
th,td{text-align:left;padding:12px;border-bottom:1px solid #ffffff18;vertical-align:top}
th{color:#dfbd70}
.table-wrap{overflow-x:auto}
.notice{padding:13px;background:#493b1d;color:#ffe3a0;border-radius:9px;margin-bottom:15px}
@media(max-width:600px){.panel{padding:16px}}
</style>
</head>
<body>
<main>

<section class="panel">
    <div class="eyebrow">Suva Hotel · People & Excellence</div>
    <h1><?= staffViewEscape($staff['full_name']) ?></h1>
    <p>
        Employee profile · <?= staffViewEscape($staff['staff_code']) ?>
    </p>

    <?php if (isset($_GET['created'])): ?>
        <div class="notice">Staff profile created successfully.</div>
    <?php endif; ?>

    <a class="btn secondary" href="index.php">← Staff Directory</a>
    <a class="btn" href="salary.php">💰 Salary Management</a>
    <a class="btn secondary" href="salary_history.php?staff_profile_id=<?= (int)$profileId ?>">Salary History</a>
</section>

<section class="panel">
    <h2>Employee Information</h2>
    <div class="grid">
        <div class="item"><small>Staff ID</small><strong><?= staffViewEscape($staff['staff_code']) ?></strong></div>
        <div class="item"><small>Full Name</small><strong><?= staffViewEscape($staff['full_name']) ?></strong></div>
        <div class="item"><small>Login Username</small><strong><?= staffViewEscape($staff['username']) ?></strong></div>
        <div class="item"><small>Login Role</small><strong><?= staffViewEscape($staff['login_role']) ?></strong></div>
        <div class="item"><small>Department</small><strong><?= staffViewEscape($staff['department']) ?></strong></div>
        <div class="item"><small>Job Title</small><strong><?= staffViewEscape($staff['job_title']) ?></strong></div>
        <div class="item"><small>Employment Type</small><strong><?= staffViewEscape($staff['employment_type']) ?></strong></div>
        <div class="item"><small>Employment Status</small><strong><?= staffViewEscape($staff['employment_status']) ?></strong></div>
        <div class="item"><small>Joining Date</small><strong><?= staffViewEscape($staff['hire_date']) ?></strong></div>
        <div class="item"><small>Phone</small><strong><?= staffViewEscape($staff['phone'] ?: 'Not provided') ?></strong></div>
        <div class="item"><small>Email</small><strong><?= staffViewEscape($staff['email'] ?: 'Not provided') ?></strong></div>
        <div class="item"><small>Address</small><strong><?= nl2br(staffViewEscape($staff['address'] ?: 'Not provided')) ?></strong></div>
    </div>

    <?php if (!empty($staff['notes'])): ?>
        <h2>Additional Notes</h2>
        <p><?= nl2br(staffViewEscape($staff['notes'])) ?></p>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Salary Information</h2>

    <?php if ($salary): ?>
        <div class="grid">
            <div class="item"><small>Monthly Salary</small><strong>Rs <?= number_format((float)$salary['monthly_salary'], 2) ?></strong></div>
            <div class="item"><small>First Salary Due</small><strong><?= staffViewEscape($salary['first_salary_due_date']) ?></strong></div>
            <div class="item"><small>Payment Day</small><strong><?= staffViewEscape($salary['payment_day'] ?: 'Monthly anniversary') ?></strong></div>
            <div class="item"><small>Schedule Status</small><strong><?= $salary['is_active'] === 't' ? 'Active' : 'Paused' ?></strong></div>
        </div>
    <?php else: ?>
        <p>Salary has not been configured for this employee yet.</p>
        <a class="btn" href="salary.php">Set Employee Salary</a>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Position History</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Effective Date</th><th>Previous Position</th><th>New Position</th><th>Previous Department</th><th>New Department</th><th>Reason</th></tr></thead>
        <tbody>
        <?php if (!$positionResult || pg_num_rows($positionResult) === 0): ?>
            <tr><td colspan="6">No position history recorded.</td></tr>
        <?php else: while ($p = pg_fetch_assoc($positionResult)): ?>
            <tr>
                <td><?= staffViewEscape($p['effective_date']) ?></td>
                <td><?= staffViewEscape($p['previous_position'] ?: '—') ?></td>
                <td><?= staffViewEscape($p['new_position']) ?></td>
                <td><?= staffViewEscape($p['previous_department'] ?: '—') ?></td>
                <td><?= staffViewEscape($p['new_department']) ?></td>
                <td><?= staffViewEscape($p['reason'] ?: '—') ?></td>
            </tr>
        <?php endwhile; endif; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="panel">
    <h2>Employment History</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Date</th><th>Event</th><th>Title</th><th>Details</th></tr></thead>
        <tbody>
        <?php if (!$historyResult || pg_num_rows($historyResult) === 0): ?>
            <tr><td colspan="4">No employment history recorded.</td></tr>
        <?php else: while ($h = pg_fetch_assoc($historyResult)): ?>
            <tr>
                <td><?= staffViewEscape($h['event_date']) ?></td>
                <td><?= staffViewEscape($h['event_type']) ?></td>
                <td><?= staffViewEscape($h['event_title']) ?></td>
                <td><?= nl2br(staffViewEscape($h['details'])) ?></td>
            </tr>
        <?php endwhile; endif; ?>
        </tbody>
    </table>
    </div>
</section>

</main>
</body>
</html>
