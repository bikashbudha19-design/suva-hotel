
<?php
/**
 * ============================================================
 * SUVA HOTEL - STAFF MANAGEMENT DIRECTORY
 * File: staff_management/index.php
 * PHP 8 + PostgreSQL
 * ============================================================
 */

session_start();

ini_set('display_errors', '1');
error_reporting(E_ALL);

/* 1. DATABASE CONNECTION */
require_once __DIR__ . '/../config/database.php';

/* 2. LOGIN AND PERMISSION PROTECTION */
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

/* Confirm permissions from the database */
$accessQuery = @pg_query_params(
    $pgConnection,
    "SELECT role FROM users WHERE id = $1",
    [$userId]
);

if (!$accessQuery || pg_num_rows($accessQuery) === 0) {
    http_response_code(403);
    exit('Access denied. Please log in again.');
}

$currentRole = strtolower(trim(
    (string) pg_fetch_result($accessQuery, 0, 'role')
));

if (!in_array($currentRole, ['admin', 'manager'], true)) {
    http_response_code(403);
    exit('Access denied. Only administrators and managers can manage staff.');
}

/* 3. HELPERS */
function staffEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function staffCount($connection, string $sql): int
{
    $result = @pg_query($connection, $sql);

    if (!$result) {
        return 0;
    }

    $value = (int) pg_fetch_result($result, 0, 0);
    pg_free_result($result);

    return $value;
}

/* 4. FILTERS */
$search = trim((string) ($_GET['search'] ?? ''));
$department = trim((string) ($_GET['department'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));

$allowedStatuses = [
    'Active',
    'On Leave',
    'Resigned',
    'Terminated'
];

if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

/* 5. LIVE STATISTICS */
$totalProfiles = staffCount(
    $pgConnection,
    'SELECT COUNT(*) FROM staff_profiles'
);

$activeStaff = staffCount(
    $pgConnection,
    "SELECT COUNT(*) FROM staff_profiles
     WHERE employment_status = 'Active'"
);

$totalPositions = staffCount(
    $pgConnection,
    'SELECT COUNT(*) FROM staff_position_history'
);

$totalHistory = staffCount(
    $pgConnection,
    'SELECT COUNT(*) FROM staff_history'
);

/* Login accounts that have no employee profile yet */
$unlinkedAccounts = staffCount(
    $pgConnection,
    "SELECT COUNT(*)
     FROM users u
     LEFT JOIN staff_profiles p ON p.user_id = u.id
     WHERE LOWER(u.role) IN ('admin', 'manager', 'staff')
       AND p.id IS NULL"
);

/* 6. DEPARTMENT FILTER OPTIONS */
$departments = [];

$departmentQuery = @pg_query(
    $pgConnection,
    "SELECT DISTINCT department
     FROM staff_profiles
     WHERE department IS NOT NULL
       AND department <> ''
     ORDER BY department"
);

if ($departmentQuery) {
    while ($row = pg_fetch_assoc($departmentQuery)) {
        $departments[] = $row['department'];
    }

    pg_free_result($departmentQuery);
}

/* 7. STAFF DIRECTORY
   Include existing login accounts even when their
   employee profiles have not been completed.
*/
$sql = "
    SELECT
        u.id AS user_id,
        u.username,
        u.full_name,
        u.role AS login_role,
        u.created_at AS account_created,

        p.id AS profile_id,
        p.staff_code,
        p.phone,
        p.email,
        p.department,
        p.job_title,
        p.employment_type,
        p.hire_date,
        p.employment_status

    FROM users u

    LEFT JOIN staff_profiles p
        ON p.user_id = u.id

    WHERE LOWER(u.role) IN ('admin', 'manager', 'staff')
";

$params = [];

if ($search !== '') {
    $params[] = '%' . $search . '%';
    $n = count($params);

    $sql .= "
        AND (
            COALESCE(u.full_name, '') ILIKE $$n
            OR COALESCE(u.username, '') ILIKE $$n
            OR COALESCE(p.staff_code, '') ILIKE $$n
            OR COALESCE(p.job_title, '') ILIKE $$n
            OR COALESCE(p.department, '') ILIKE $$n
        )
    ";
}

if ($department !== '') {
    $params[] = $department;
    $n = count($params);
    $sql .= " AND p.department = $$n";
}

if ($status !== '') {
    $params[] = $status;
    $n = count($params);
    $sql .= " AND p.employment_status = $$n";
}

$sql .= "
    ORDER BY
        CASE WHEN p.id IS NULL THEN 1 ELSE 0 END,
        p.hire_date DESC NULLS LAST,
        u.full_name ASC
";

$staffQuery = @pg_query_params($pgConnection, $sql, $params);

$staff = [];

if ($staffQuery) {
    while ($row = pg_fetch_assoc($staffQuery)) {
        $staff[] = $row;
    }

    pg_free_result($staffQuery);
} else {
    error_log('Staff directory query failed: ' .
        pg_last_error($pgConnection));
}

$displayName = $_SESSION['full_name']
    ?? $_SESSION['username']
    ?? 'Administrator';

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Staff Management | Suva Hotel</title>

<style>
:root {
    --green: #092b22;
    --green-light: #124638;
    --gold: #d8b66a;
    --gold-light: #f3df9d;
    --background: #071912;
    --panel: rgba(18, 49, 39, .88);
    --text: #f7f5ed;
    --muted: #b8c8bf;
    --border: rgba(216,182,106,.22);
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: "Segoe UI", Arial, sans-serif;
    color: var(--text);
    background:
        radial-gradient(ellipse at top right,
            rgba(38,104,75,.30), transparent 45%),
        linear-gradient(135deg, #061710, #0c2b20 55%, #061710);
    min-height: 100vh;
}

a {
    color: inherit;
    text-decoration: none;
}

.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
    padding: 20px 5%;
    background: rgba(4,19,13,.86);
    border-bottom: 1px solid var(--border);
}

.brand {
    color: var(--gold-light);
    font-size: 23px;
    font-weight: 800;
    letter-spacing: 2px;
}

.brand small {
    display: block;
    color: var(--muted);
    font-size: 10px;
    letter-spacing: 3px;
    margin-top: 5px;
}

.top-links {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.top-links a {
    color: var(--muted);
    font-size: 13px;
}

.top-links a:hover {
    color: var(--gold-light);
}

.container {
    width: min(1440px, 92%);
    margin: 34px auto 60px;
}

.hero {
    padding: clamp(26px, 5vw, 55px);
    border: 1px solid var(--border);
    border-radius: 24px;
    background:
        linear-gradient(115deg,
            rgba(8,39,28,.97),
            rgba(17,65,48,.84)),
        radial-gradient(circle at right, #406c4e, transparent);
    box-shadow: 0 20px 60px rgba(0,0,0,.2);
    position: relative;
    overflow: hidden;
}

.hero:after {
    content: "SH";
    position: absolute;
    right: 30px;
    bottom: -55px;
    font-size: clamp(120px, 22vw, 250px);
    font-weight: 900;
    color: rgba(216,182,106,.055);
    pointer-events: none;
}

.eyebrow {
    color: var(--gold);
    text-transform: uppercase;
    letter-spacing: 3px;
    font-size: 11px;
    font-weight: 800;
}

h1 {
    font-size: clamp(30px, 4vw, 49px);
    margin: 15px 0;
    line-height: 1.12;
}

.hero p {
    color: var(--muted);
    max-width: 650px;
    line-height: 1.8;
}

.quote {
    color: var(--gold-light) !important;
    font-style: italic;
    margin-top: 22px;
}

.button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 17px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: rgba(255,255,255,.045);
    color: var(--text);
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: .25s ease;
}

.button:hover {
    border-color: var(--gold);
    transform: translateY(-2px);
}

.button.gold {
    background: linear-gradient(135deg, #d4b264, #f0dda0);
    color: #142419;
    border: none;
}

.button.small {
    padding: 8px 11px;
    font-size: 12px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 17px;
    margin: 25px 0;
}

.stat {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 17px;
    padding: 23px;
    transition: .3s ease;
}

.stat:hover {
    transform: translateY(-4px);
    border-color: var(--gold);
}

.stat-label {
    color: var(--muted);
    font-size: 12px;
}

.stat-number {
    display: block;
    font-size: 32px;
    font-weight: 800;
    margin: 12px 0 5px;
    color: var(--gold-light);
}

.stat-note {
    color: var(--muted);
    font-size: 11px;
}

.section {
    margin-top: 28px;
    padding: 25px;
    border: 1px solid var(--border);
    border-radius: 19px;
    background: rgba(9,32,23,.8);
}

.section-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

h2 {
    font-size: 21px;
    margin: 0 0 7px;
}

.muted {
    color: var(--muted);
    font-size: 13px;
}

.filters {
    display: grid;
    grid-template-columns: minmax(180px, 2fr) repeat(2, minmax(140px, 1fr)) auto;
    gap: 11px;
    margin-bottom: 20px;
}

input, select {
    width: 100%;
    min-width: 0;
    padding: 12px;
    background: #102e23;
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 9px;
    outline: none;
}

input:focus, select:focus {
    border-color: var(--gold);
}

option {
    background: #102e23;
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
    border: 1px solid var(--border);
    border-radius: 12px;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1000px;
}

th, td {
    text-align: left;
    padding: 15px 13px;
    border-bottom: 1px solid rgba(255,255,255,.07);
    font-size: 12px;
    vertical-align: middle;
}

th {
    background: #153a2b;
    color: var(--gold-light);
    text-transform: uppercase;
    letter-spacing: .7px;
    font-size: 10px;
}

tbody tr:hover {
    background: rgba(216,182,106,.045);
}

tbody tr:last-child td {
    border-bottom: none;
}

.staff-name {
    font-weight: 700;
    font-size: 13px;
}

.subtext {
    color: var(--muted);
    font-size: 11px;
    margin-top: 5px;
}

.badge {
    display: inline-block;
    padding: 6px 9px;
    border-radius: 30px;
    font-size: 10px;
    font-weight: 800;
    background: rgba(216,182,106,.12);
    color: var(--gold-light);
    white-space: nowrap;
}

.badge.active {
    background: rgba(53,170,104,.16);
    color: #8ee0ac;
}

.badge.leave {
    background: rgba(232,180,70,.15);
    color: #f0cf79;
}

.badge.inactive {
    background: rgba(220,100,100,.14);
    color: #ffaaaa;
}

.badge.pending {
    background: rgba(160,175,170,.12);
    color: #d2ddd6;
}

.actions {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
}

.empty {
    text-align: center;
    padding: 45px 20px;
    color: var(--muted);
}

.empty strong {
    display: block;
    font-size: 18px;
    color: var(--gold-light);
    margin-bottom: 9px;
}

.footer {
    text-align: center;
    padding: 30px 15px;
    color: var(--muted);
    font-size: 11px;
}

@media (max-width: 950px) {
    .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .filters {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 560px) {
    .container {
        width: 94%;
        margin-top: 20px;
    }

    .topbar {
        padding: 17px 4%;
    }

    .stats {
        gap: 10px;
    }

    .stat {
        padding: 15px;
    }

    .stat-number {
        font-size: 26px;
    }

    .filters {
        grid-template-columns: 1fr;
    }

    .section {
        padding: 15px;
    }
}

.reveal {
    opacity: 0;
    transform: translateY(18px);
}

.reveal.visible {
    opacity: 1;
    transform: translateY(0);
    transition: opacity .7s ease, transform .7s ease;
}

@media (prefers-reduced-motion: reduce) {
    *, *:before, *:after {
        animation: none !important;
        transition: none !important;
        scroll-behavior: auto !important;
    }

    .reveal {
        opacity: 1;
        transform: none;
    }
}
</style>
</head>

<body>

<header class="topbar">
    <a href="../staff_dashboard/staff_dashboard.php" class="brand">
        SUVA HOTEL
        <small>STAFF MANAGEMENT SYSTEM</small>
    </a>

    <nav class="top-links">
        <span class="muted">
            <?= staffEscape($displayName) ?>
            (<?= staffEscape($currentRole) ?>)
        </span>

        <a href="../dashboard.php">Admin Dashboard</a>
        <a href="../staff_dashboard/staff_dashboard.php">
            Staff Dashboard
        </a>
        <a href="salary.php" class="btn">
    💰 Staff Salary Management
</a>
<a href="../staff_management/salary.php">
    💰 Staff Salary
</a>
<a href="create_account.php" class="btn">
    ➕ Create Staff Login
</a>
        <a href="../logout.php">Logout</a>
    </nav>
</header>

<main class="container">

    <section class="hero reveal">
        <div class="eyebrow">People • Performance • Progress</div>

        <h1>Our People,<br>Our Hospitality.</h1>

        <p>
            Manage your hotel team in one place. Maintain employee
            profiles, follow career progression, record position changes
            and preserve important employment history.
        </p>

        <p class="quote" id="staffQuote">
            “Exceptional hospitality begins with an exceptional team.”
        </p>

        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:24px;">
            <a class="button gold" href="add.php">
                + Register Staff
            </a>

            <a class="button" href="positions.php">
                Position History
            </a>

            <a class="button" href="history.php">
                Employment History
            </a>
        </div>
    </section>

    <section class="stats reveal">

        <div class="stat">
            <div class="stat-label">Registered Profiles</div>
            <span class="stat-number">
                <?= number_format($totalProfiles) ?>
            </span>
            <div class="stat-note">Employee records created</div>
        </div>

        <div class="stat">
            <div class="stat-label">Active Employees</div>
            <span class="stat-number">
                <?= number_format($activeStaff) ?>
            </span>
            <div class="stat-note">Currently marked active</div>
        </div>

        <div class="stat">
            <div class="stat-label">Position Records</div>
            <span class="stat-number">
                <?= number_format($totalPositions) ?>
            </span>
            <div class="stat-note">Promotions and transfers</div>
        </div>

        <div class="stat">
            <div class="stat-label">History Records</div>
            <span class="stat-number">
                <?= number_format($totalHistory) ?>
            </span>
            <div class="stat-note">Recorded employment events</div>
        </div>

    </section>

    <?php if ($unlinkedAccounts > 0): ?>
        <section class="section reveal">
            <div class="section-heading">
                <div>
                    <h2>Complete Employee Profiles</h2>
                    <div class="muted">
                        Existing login accounts without a staff profile.
                    </div>
                </div>

                <span class="badge pending">
                    <?= number_format($unlinkedAccounts) ?> to review
                </span>
            </div>

            <p class="muted">
                These accounts appear in the directory below.
                Use Register Staff to create a profile and link it to
                the correct existing account. Do not create duplicate
                login accounts for existing employees.
            </p>
        </section>
    <?php endif; ?>

    <section class="section reveal">

        <div class="section-heading">
            <div>
                <h2>Staff Directory</h2>
                <div class="muted">
                    Search employee accounts, positions and departments.
                </div>
            </div>

            <span class="badge">
                <?= number_format(count($staff)) ?> results
            </span>
        </div>

        <form method="GET" class="filters">

            <input
                type="search"
                name="search"
                placeholder="Search name, staff ID, job..."
                value="<?= staffEscape($search) ?>"
            >

            <select name="department">
                <option value="">All departments</option>

                <?php foreach ($departments as $dept): ?>
                    <option
                        value="<?= staffEscape($dept) ?>"
                        <?= $department === $dept ? 'selected' : '' ?>
                    >
                        <?= staffEscape($dept) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="status">
                <option value="">All employment statuses</option>

                <?php foreach ($allowedStatuses as $s): ?>
                    <option
                        value="<?= staffEscape($s) ?>"
                        <?= $status === $s ? 'selected' : '' ?>
                    >
                        <?= staffEscape($s) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="button gold">
                Search
            </button>

        </form>

        <?php if ($staffQuery === false): ?>

            <div class="empty">
                <strong>Unable to load staff records</strong>
                Please check the PHP error log and database connection.
            </div>

        <?php elseif (count($staff) === 0): ?>

            <div class="empty">
                <strong>No matching staff members</strong>
                Try another search or clear the filters.
                <br><br>
                <a class="button" href="index.php">Clear Filters</a>
            </div>

        <?php else: ?>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Staff ID</th>
                            <th>Department / Position</th>
                            <th>Contact</th>
                            <th>Joined</th>
                            <th>Status</th>
                            <th>Account Role</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($staff as $member): ?>

                        <?php
                        $hasProfile = !empty($member['profile_id']);

                        $employmentStatus = $hasProfile
                            ? $member['employment_status']
                            : 'Profile Needed';

                        $badgeClass = 'pending';

                        if ($employmentStatus === 'Active') {
                            $badgeClass = 'active';
                        } elseif ($employmentStatus === 'On Leave') {
                            $badgeClass = 'leave';
                        } elseif (in_array(
                            $employmentStatus,
                            ['Resigned', 'Terminated'],
                            true
                        )) {
                            $badgeClass = 'inactive';
                        }
                        ?>

                        <tr>
                            <td>
                                <div class="staff-name">
                                    <?= staffEscape(
                                        $member['full_name']
                                        ?: $member['username']
                                    ) ?>
                                </div>

                                <div class="subtext">
                                    @<?= staffEscape($member['username']) ?>
                                </div>
                            </td>

                            <td>
                                <?= $hasProfile
                                    ? staffEscape($member['staff_code'])
                                    : '—' ?>
                            </td>

                            <td>
                                <?php if ($hasProfile): ?>
                                    <div class="staff-name">
                                        <?= staffEscape($member['job_title']) ?>
                                    </div>

                                    <div class="subtext">
                                        <?= staffEscape($member['department']) ?>
                                    </div>
                                <?php else: ?>
                                    <span class="muted">
                                        Profile not completed
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= staffEscape(
                                    $member['phone'] ?: '—'
                                ) ?>

                                <?php if (!empty($member['email'])): ?>
                                    <div class="subtext">
                                        <?= staffEscape($member['email']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= staffEscape(
                                    $member['hire_date']
                                    ?: 'Not recorded'
                                ) ?>
                            </td>

                            <td>
                                <span class="badge <?= $badgeClass ?>">
                                    <?= staffEscape($employmentStatus) ?>
                                </span>
                            </td>

                            <td>
                                <span class="badge">
                                    <?= staffEscape($member['login_role']) ?>
                                </span>
                            </td>

                            <td>
                                <div class="actions">

                                    <?php if ($hasProfile): ?>

                                        <a class="button small"
                                           href="view.php?id=<?= (int)$member['profile_id'] ?>">
                                            Profile
                                        </a>

                                        <a class="button small"
                                           href="history.php?id=<?= (int)$member['profile_id'] ?>">
                                            History
                                        </a>

                                        <a class="button small"
                                           href="positions.php?id=<?= (int)$member['profile_id'] ?>">
                                            Positions
                                        </a>

                                    <?php else: ?>

                                        <a class="button gold small"
                                           href="add.php?user_id=<?= (int)$member['user_id'] ?>">
                                            Complete Profile
                                        </a>

                                    <?php endif; ?>

                                </div>
                            </td>
                        </tr>

                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

    </section>

    <footer class="footer">
        SUVA HOTEL · STAFF MANAGEMENT
        <br><br>
        “A great hotel is built by people who care.”
    </footer>

</main>

<script>
/* Lightweight reveal animation; no additional library required. */
document.addEventListener('DOMContentLoaded', function () {
    const elements = document.querySelectorAll('.reveal');

    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.08 });

        elements.forEach(function (element) {
            observer.observe(element);
        });
    } else {
        elements.forEach(function (element) {
            element.classList.add('visible');
        });
    }

    const quoteElement = document.getElementById('staffQuote');

    const quotes = [
        '“Exceptional hospitality begins with an exceptional team.”',
        '“Every role matters. Every guest matters.”',
        '“Professional people create unforgettable experiences.”',
        '“Great service is built on teamwork and care.”'
    ];

    let quoteIndex = 0;

    window.setInterval(function () {
        if (!quoteElement) return;

        quoteIndex = (quoteIndex + 1) % quotes.length;
        quoteElement.style.opacity = '0';

        window.setTimeout(function () {
            quoteElement.textContent = quotes[quoteIndex];
            quoteElement.style.opacity = '1';
        }, 300);
    }, 4500);

    if (quoteElement) {
        quoteElement.style.transition = 'opacity .3s ease';
    }
});
</script>

</body>
</html>
