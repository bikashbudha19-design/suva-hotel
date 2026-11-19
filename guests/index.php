
<?php
// ============================================================
// SUVA HOTEL - GUEST MANAGEMENT
// File: /guests/index.php
// Database: PostgreSQL
// ============================================================

session_start();

ini_set('display_errors', '1');
error_reporting(E_ALL);

// ------------------------------------------------------------
// 1. LOGIN PROTECTION
// ------------------------------------------------------------
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// ------------------------------------------------------------
// 2. ALLOW ADMIN AND STAFF
// ------------------------------------------------------------
$role = strtolower(trim($_SESSION['role'] ?? ''));

if (!in_array($role, ['admin', 'staff'], true)) {
    http_response_code(403);
    exit('Access denied. You are not authorized to view this page.');
}

// ------------------------------------------------------------
// 3. DATABASE CONNECTION
// ------------------------------------------------------------
require_once __DIR__ . '/../config/database.php';

if (!isset($pgConnection) || !$pgConnection) {
    exit('Database connection failed. Please check config/database.php.');
}

// ------------------------------------------------------------
// 4. SEARCH
// ------------------------------------------------------------
$search = trim($_GET['search'] ?? '');
$guests = [];
$errorMessage = '';

$sql = "
    SELECT id, full_name, phone, email, address, created_at
    FROM guests
";

if ($search !== '') {
    $sql .= "
        WHERE full_name ILIKE $1
           OR COALESCE(phone, '') ILIKE $1
           OR COALESCE(email, '') ILIKE $1
           OR COALESCE(address, '') ILIKE $1
    ";
}

$sql .= " ORDER BY id DESC";

if ($search !== '') {
    $result = pg_query_params(
        $pgConnection,
        $sql,
        ['%' . $search . '%']
    );
} else {
    $result = pg_query($pgConnection, $sql);
}

if ($result === false) {
    $errorMessage = 'Unable to load guest records. Please check your guests table and database connection.';
} else {
    while ($row = pg_fetch_assoc($result)) {
        $guests[] = $row;
    }
}

// ------------------------------------------------------------
// 5. SAFE OUTPUT HELPER
// ------------------------------------------------------------
function guestEscape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function guestDate($value): string
{
    if (empty($value)) {
        return '—';
    }

    $timestamp = strtotime($value);

    return $timestamp ? date('d M Y, h:i A', $timestamp) : guestEscape($value);
}

$totalGuests = count($guests);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Guest Management | Suva Hotel</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">

    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.14.2/gsap.min.js"></script>

    <style>
        :root {
            --bg: #08130f;
            --panel: #10251c;
            --panel-light: #163327;
            --gold: #d9b66f;
            --gold-light: #f2dca2;
            --text: #f6f4ed;
            --muted: #aabbb0;
            --border: rgba(217, 182, 111, .22);
            --green: #4c9971;
            --red: #d97878;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            background:
                radial-gradient(ellipse at 10% 0%, rgba(38, 91, 63, .22), transparent 35%),
                radial-gradient(ellipse at 95% 25%, rgba(143, 111, 48, .09), transparent 30%),
                var(--bg);
            font-family: 'DM Sans', sans-serif;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button, input {
            font: inherit;
        }

        .topbar {
            min-height: 76px;
            padding: 15px 5%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            flex-wrap: wrap;
            border-bottom: 1px solid var(--border);
            background: rgba(6, 17, 12, .86);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 13px;
        }

        .brand-icon {
            width: 45px;
            height: 45px;
            display: grid;
            place-items: center;
            border: 1px solid var(--gold);
            border-radius: 13px;
            color: var(--gold-light);
            font-family: Georgia, serif;
            font-size: 25px;
        }

        .brand-name {
            font-family: 'Playfair Display', serif;
            font-size: 23px;
            letter-spacing: 1px;
        }

        .brand-subtitle {
            margin-top: 3px;
            color: var(--muted);
            font-size: 10px;
            letter-spacing: 2.5px;
        }

        .user-area {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            color: var(--muted);
            font-size: 13px;
        }

        .user-area strong {
            color: var(--gold-light);
        }

        .logout {
            padding: 9px 15px;
            border: 1px solid var(--border);
            border-radius: 9px;
            transition: .25s;
        }

        .logout:hover {
            color: var(--gold-light);
            border-color: var(--gold);
        }

        .container {
            width: min(1450px, 92%);
            margin: 35px auto 65px;
        }

        .breadcrumb {
            margin-bottom: 25px;
            color: var(--muted);
            font-size: 13px;
        }

        .breadcrumb a:hover {
            color: var(--gold-light);
        }

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 28px;
        }

        .eyebrow {
            margin: 0 0 10px;
            color: var(--gold);
            font-size: 11px;
            letter-spacing: 3px;
            text-transform: uppercase;
        }

        h1 {
            margin: 0;
            font-family: 'Playfair Display', serif;
            font-size: clamp(32px, 4vw, 48px);
            font-weight: 600;
        }

        .heading-description {
            margin: 12px 0 0;
            color: var(--muted);
            line-height: 1.8;
        }

        .button {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            min-height: 44px;
            padding: 12px 18px;
            border: 1px solid var(--gold);
            border-radius: 10px;
            background: var(--gold);
            color: #142016;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: transform .25s, background .25s;
        }

        .button:hover {
            transform: translateY(-2px);
            background: var(--gold-light);
        }

        /* HOSPITALITY QUOTE */

        .guest-quote {
            position: relative;
            overflow: hidden;
            margin: 30px 0;
            padding: 40px 24px;
            text-align: center;
            border: 1px solid var(--border);
            border-radius: 20px;
            background:
                radial-gradient(ellipse at 50% 0%, rgba(51, 112, 76, .48), transparent 70%),
                linear-gradient(135deg, #102c21, #0b1912);
            box-shadow: 0 20px 60px rgba(0, 0, 0, .19);
        }

        .quote-mark {
            color: var(--gold);
            font-family: Georgia, serif;
            font-size: 65px;
            line-height: .8;
            opacity: .85;
        }

        .quote-label {
            margin: 16px 0;
            color: var(--gold);
            font-size: 10px;
            letter-spacing: 3px;
        }

        .quote-line {
            max-width: 800px;
            margin: 0 auto;
            color: var(--text);
            font-family: 'Playfair Display', serif;
            font-size: clamp(25px, 4vw, 43px);
            line-height: 1.4;
            font-weight: 500;
        }

        .quote-highlight {
            display: inline-block;
            color: var(--gold-light);
        }

        .quote-subtitle {
            margin: 17px 0 0;
            color: #bdcec1;
            font-size: 14px;
            line-height: 1.8;
        }

        .quote-gold-line {
            width: 75px;
            height: 2px;
            margin: 25px auto 0;
            background: var(--gold);
            transform-origin: center;
        }

        /* SUMMARY */

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin: 28px 0;
        }

        .summary-card {
            position: relative;
            overflow: hidden;
            padding: 24px;
            border: 1px solid var(--border);
            border-radius: 15px;
            background: linear-gradient(145deg, #142c20, #0d1e15);
        }

        .summary-card::after {
            content: '';
            position: absolute;
            width: 100px;
            height: 100px;
            right: -35px;
            bottom: -45px;
            border: 1px solid rgba(217, 182, 111, .15);
            border-radius: 50%;
        }

        .summary-label {
            color: var(--muted);
            font-size: 12px;
        }

        .summary-value {
            margin-top: 12px;
            color: var(--gold-light);
            font-family: 'Playfair Display', serif;
            font-size: 35px;
        }

        /* SEARCH */

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin: 30px 0 18px;
        }

        .section-title {
            margin: 0;
            font-family: 'Playfair Display', serif;
            font-size: 24px;
        }

        .search-form {
            display: flex;
            gap: 8px;
            width: min(100%, 470px);
        }

        .search-input {
            flex: 1;
            min-width: 0;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 9px;
            outline: none;
            background: #0e2118;
            color: var(--text);
        }

        .search-input:focus {
            border-color: var(--gold);
        }

        .search-button {
            padding: 10px 15px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: var(--panel-light);
            color: var(--gold-light);
            cursor: pointer;
        }

        /* TABLE */

        .table-panel {
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 15px;
            background: rgba(13, 31, 22, .88);
        }

        .table-scroll {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        th {
            padding: 16px 18px;
            text-align: left;
            color: var(--gold-light);
            background: rgba(217, 182, 111, .07);
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        td {
            padding: 17px 18px;
            border-top: 1px solid rgba(217, 182, 111, .10);
            color: #e0e8e1;
            font-size: 13px;
            vertical-align: middle;
        }

        tbody tr {
            transition: background .2s;
        }

        tbody tr:hover {
            background: rgba(58, 108, 75, .14);
        }

        .guest-name {
            color: #fff8e9;
            font-weight: 600;
        }

        .guest-id {
            color: var(--gold);
            font-size: 12px;
        }

        .muted {
            color: var(--muted);
        }

        .actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .action-link {
            display: inline-block;
            padding: 7px 10px;
            border: 1px solid var(--border);
            border-radius: 7px;
            font-size: 12px;
            transition: .2s;
        }

        .edit-link:hover {
            color: var(--gold-light);
            border-color: var(--gold);
        }

        .delete-link {
            color: #f0aaaa;
            border-color: rgba(217, 120, 120, .3);
        }

        .delete-link:hover {
            border-color: var(--red);
            background: rgba(217, 120, 120, .08);
        }

        .empty-state {
            padding: 45px 20px;
            text-align: center;
            color: var(--muted);
            line-height: 1.9;
        }

        .empty-icon {
            margin-bottom: 10px;
            font-size: 34px;
        }

        .alert {
            margin: 20px 0;
            padding: 15px;
            border: 1px solid rgba(217, 120, 120, .4);
            border-radius: 10px;
            color: #ffd0d0;
            background: rgba(120, 40, 40, .14);
        }

        .footer {
            padding: 25px 10px 0;
            text-align: center;
            color: #7f9686;
            font-size: 11px;
            letter-spacing: 1px;
        }

        .back-link {
            display: inline-block;
            margin-top: 22px;
            color: var(--gold-light);
            font-size: 13px;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        @media (max-width: 700px) {
            .container {
                width: 94%;
                margin-top: 25px;
            }

            .summary-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .summary-card {
                padding: 19px;
            }

            .summary-value {
                font-size: 29px;
            }

            .guest-quote {
                padding: 30px 17px;
            }

            .quote-label {
                letter-spacing: 1.5px;
            }

            .page-heading {
                align-items: flex-start;
            }

            .search-form {
                width: 100%;
            }

            .brand-name {
                font-size: 20px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                scroll-behavior: auto !important;
                animation-duration: .01ms !important;
                transition-duration: .01ms !important;
            }
        }
    </style>
</head>

<body>

<header class="topbar">
    <a class="brand" href="../dashboard.php">
        <div class="brand-icon">S</div>
        <div>
            <div class="brand-name">SUVA HOTEL</div>
            <div class="brand-subtitle">HOTEL MANAGEMENT SYSTEM</div>
        </div>
    </a>

    <div class="user-area">
        <span>
            Logged in as:
            <strong><?= guestEscape($_SESSION['username'] ?? $_SESSION['full_name'] ?? 'User') ?></strong>
            (<?= guestEscape($role) ?>)
        </span>

        <a class="logout" href="../logout.php">Logout ↗</a>
    </div>
</header>

<main class="container">

    <div class="breadcrumb">
        <a href="../dashboard.php">Dashboard</a>
        &nbsp; / &nbsp;
        <span>Guest Management</span>
    </div>

    <section class="page-heading intro-item">
        <div>
            <p class="eyebrow">Hospitality begins with people</p>
            <h1>Guest Management</h1>
            <p class="heading-description">
                Welcome every guest with care. Manage guest information
                in one convenient place.
            </p>
        </div>

        <a class="button" href="add.php">
            <span>＋</span> Add New Guest
        </a>
    </section>

    <section class="guest-quote">
        <div class="quote-mark">“</div>
        <p class="quote-label">THE SUVA HOSPITALITY PHILOSOPHY</p>

        <h2 class="quote-line">
            Every guest deserves
            <span class="quote-highlight">a memorable welcome.</span>
        </h2>

        <p class="quote-subtitle">
            Genuine care turns a stay into a beautiful memory.
        </p>

        <div class="quote-gold-line"></div>
    </section>

    <section class="summary-grid">
        <div class="summary-card summary-item">
            <div class="summary-label">Guest Records Displayed</div>
            <div class="summary-value"><?= number_format($totalGuests) ?></div>
        </div>

        <div class="summary-card summary-item">
            <div class="summary-label">Current View</div>
            <div class="summary-value" style="font-size:25px">
                <?= $search !== '' ? 'Search Results' : 'All Guests' ?>
            </div>
        </div>

        <div class="summary-card summary-item">
            <div class="summary-label">Guest Experience</div>
            <div class="summary-value" style="font-size:25px">
                Personal Care
            </div>
        </div>
    </section>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert">
            <?= guestEscape($errorMessage) ?>
        </div>
    <?php else: ?>

        <section class="toolbar">
            <h2 class="section-title">Guest Directory</h2>

            <form class="search-form" method="GET" action="index.php">
                <input
                    class="search-input"
                    type="search"
                    name="search"
                    placeholder="Search name, phone, email or address..."
                    value="<?= guestEscape($search) ?>"
                    aria-label="Search guests"
                >

                <button class="search-button" type="submit">
                    Search
                </button>

                <?php if ($search !== ''): ?>
                    <a class="search-button" href="index.php">Clear</a>
                <?php endif; ?>
            </form>
        </section>

        <section class="table-panel">
            <?php if (count($guests) > 0): ?>

                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Guest Name</th>
                                <th>Phone</th>
                                <th>Email</th>
                                <th>Address</th>
                                <th>Registered</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($guests as $guest): ?>
                                <tr>
                                    <td class="guest-id">
                                        #<?= guestEscape($guest['id']) ?>
                                    </td>

                                    <td class="guest-name">
                                        <?= guestEscape($guest['full_name']) ?>
                                    </td>

                                    <td>
                                        <?= guestEscape($guest['phone'] ?: '—') ?>
                                    </td>

                                    <td>
                                        <?= guestEscape($guest['email'] ?: '—') ?>
                                    </td>

                                    <td>
                                        <?= guestEscape($guest['address'] ?: '—') ?>
                                    </td>

                                    <td class="muted">
                                        <?= guestDate($guest['created_at'] ?? '') ?>
                                    </td>

                                    <td>
                                        <div class="actions">
                                            <a
                                                class="action-link edit-link"
                                                href="edit.php?id=<?= urlencode((string)$guest['id']) ?>"
                                            >Edit</a>

                                            <a
                                                class="action-link delete-link"
                                                href="delete.php?id=<?= urlencode((string)$guest['id']) ?>"
                                                onclick="return confirm('Are you sure you want to delete this guest? Check for related bookings before continuing.');"
                                            >Delete</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php else: ?>

                <div class="empty-state">
                    <div class="empty-icon">♧</div>

                    <?php if ($search !== ''): ?>
                        <strong>No matching guests found.</strong>
                        <div>Try another name, phone number, email or address.</div>
                        <p><a class="back-link" href="index.php">View all guests</a></p>
                    <?php else: ?>
                        <strong>No guest records found.</strong>
                        <div>Add a guest to begin building your guest directory.</div>
                        <p><a class="button" href="add.php">＋ Add First Guest</a></p>
                    <?php endif; ?>
                </div>

            <?php endif; ?>
        </section>

    <?php endif; ?>

    <a class="back-link" href="../dashboard.php">← Back to Dashboard</a>

    <footer class="footer">
        SUVA HOTEL &nbsp; • &nbsp; EXCEPTIONAL CARE, MEMORABLE STAYS
    </footer>

</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    if (typeof gsap === 'undefined') {
        return;
    }

    gsap.from('.intro-item', {
        opacity: 0,
        y: 20,
        duration: 0.8,
        ease: 'power2.out'
    });

    const quote = document.querySelector('.guest-quote');

    if (quote) {
        const timeline = gsap.timeline({
            delay: 0.15,
            defaults: { ease: 'power2.out' }
        });

        timeline.from('.quote-mark', {
            opacity: 0,
            y: -15,
            duration: 0.6
        });

        timeline.from('.quote-label', {
            opacity: 0,
            y: 10,
            duration: 0.6
        }, '-=0.25');

        timeline.from('.quote-line', {
            opacity: 0,
            y: 18,
            duration: 0.8
        }, '-=0.2');

        timeline.from('.quote-subtitle', {
            opacity: 0,
            y: 8,
            duration: 0.6
        }, '-=0.3');

        timeline.from('.quote-gold-line', {
            scaleX: 0,
            duration: 0.8
        }, '-=0.2');

        gsap.to('.quote-highlight', {
            y: -3,
            duration: 2.1,
            repeat: -1,
            yoyo: true,
            ease: 'sine.inOut'
        });
    }

    gsap.from('.summary-item', {
        opacity: 0,
        y: 18,
        duration: 0.65,
        stagger: 0.12,
        delay: 0.3,
        ease: 'power2.out'
    });

    gsap.from('.table-panel', {
        opacity: 0,
        y: 16,
        duration: 0.7,
        delay: 0.35,
        ease: 'power2.out'
    });
});
</script>

</body>
</html>

