
<?php
// ============================================================
// SUVA HOTEL - HOUSEKEEPING MANAGEMENT
// File: /housekeeping/index.php
// Database: PostgreSQL
// ============================================================

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config/database.php';

if (!isset($pgConnection)) {
    die("Database connection variable \$pgConnection was not found.");
}

if (isset($_SESSION['role']) &&
    !in_array($_SESSION['role'], ['admin', 'staff'], true)) {
    http_response_code(403);
    exit("Access denied.");
}

$allowedStatuses = [
    'Dirty',
    'Cleaning',
    'Clean',
    'Inspected',
    'Maintenance Required',
    'Do Not Disturb'
];

$message = '';
$error = '';


// ============================================================
// SAVE HOUSEKEEPING STATUS
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $roomId = filter_input(INPUT_POST, 'room_id', FILTER_VALIDATE_INT);
    $status = $_POST['status'] ?? '';
    $notes = trim($_POST['notes'] ?? '');

    if (!$roomId || !in_array($status, $allowedStatuses, true)) {
        $error = "Invalid room or housekeeping status.";
    } else {
        // Confirm the room exists.
        $roomCheck = pg_query_params(
            $pgConnection,
            "SELECT id FROM rooms WHERE id = $1",
            [$roomId]
        );

        if (!$roomCheck || pg_num_rows($roomCheck) === 0) {
            $error = "Room not found.";
        } else {
            $save = pg_query_params(
                $pgConnection,
                "INSERT INTO housekeeping_status
                    (room_id, status, notes, updated_by, updated_at)
                 VALUES ($1, $2, $3, $4, CURRENT_TIMESTAMP)
                 ON CONFLICT (room_id)
                 DO UPDATE SET
                    status = EXCLUDED.status,
                    notes = EXCLUDED.notes,
                    updated_by = EXCLUDED.updated_by,
                    updated_at = CURRENT_TIMESTAMP",
                [
                    $roomId,
                    $status,
                    $notes,
                    $_SESSION['user_id']
                ]
            );

            if ($save) {
                header("Location: index.php?saved=1");
                exit;
            } else {
                $error = "Could not save status: " .
                    pg_last_error($pgConnection);
            }
        }
    }
}

if (isset($_GET['saved'])) {
    $message = "Housekeeping status updated successfully.";
}


// ============================================================
// LOAD ROOMS AND HOUSEKEEPING STATUS
// ============================================================


   
$sql = "
    SELECT
        r.id,
        r.room_number,
        r.room_type,
        r.price_per_night,
        r.status AS room_status,

        CASE
            WHEN EXISTS (
                SELECT 1
                FROM bookings b
                WHERE b.room_id = r.id
                  AND LOWER(TRIM(b.status)) = 'checked in'
                  AND b.check_in::date <= CURRENT_DATE
                  AND b.check_out::date >= CURRENT_DATE
            )
            THEN 'Occupied'
            ELSE COALESCE(h.status, 'Dirty')
        END AS housekeeping_status,

        COALESCE(h.notes, '') AS notes,
        h.updated_at

    FROM rooms r

    LEFT JOIN housekeeping_status h
        ON h.room_id = r.id

    ORDER BY r.room_number
";


$result = pg_query($pgConnection, $sql);

if (!$result) {
    die(
        "Could not load rooms. Please confirm that the " .
        "housekeeping_status table exists. Database error: " .
        htmlspecialchars(pg_last_error($pgConnection))
    );
}

$rooms = [];
$counts = array_fill_keys(
    array_merge($allowedStatuses, ['Occupied']),
    0
);

while ($room = pg_fetch_assoc($result)) {
    $rooms[] = $room;

    $currentStatus = $room['housekeeping_status'];

    if (isset($counts[$currentStatus])) {
        $counts[$currentStatus]++;
    }
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function statusClass($status) {
    switch ($status) {
        case 'Occupied':
    return 'occupied';
        case 'Dirty':
            return 'dirty';
        case 'Cleaning':
            return 'cleaning';
        case 'Clean':
            return 'clean';
        case 'Inspected':
            return 'inspected';
        case 'Maintenance Required':
            return 'maintenance';
        case 'Do Not Disturb':
            return 'dnd';
        default:
            return 'dirty';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Housekeeping | Suva Hotel</title>

<style>
    /* =====================================================
   CINEMATIC HOUSEKEEPING SCENE
   Added without changing existing PHP/database logic
===================================================== */

.housekeeping-scene {
    position: relative;
    isolation: isolate;
    overflow: hidden;
    min-height: 360px;
    margin: 25px 0 30px;
    padding: 30px;
    border: 1px solid rgba(215, 181, 109, .35);
    border-radius: 20px;
   background: #102c24;
    box-shadow: 0 18px 45px rgba(0, 0, 0, .25);
}
/* Animated hotel-photo layers */
.housekeeping-photo {
    position: absolute;
    inset: 0;
    z-index: -2;
    overflow: hidden;
    pointer-events: none;
}

.housekeeping-photo-layer {
    position: absolute;
    inset: -5%;
    background-position: center;
    background-size: cover;
    background-repeat: no-repeat;
    opacity: 0;
    transform: scale(1.08);
    will-change: transform, opacity;
}

.housekeeping-photo-layer.photo-one {
    background-image: url(
        "https://images.unsplash.com/photo-1611892440504-42a792e24d32?auto=format&fit=crop&w=1800&q=85"
    );
}

.housekeeping-photo-layer.photo-two {
    background-image: url(
        "https://images.unsplash.com/photo-1618773928121-c32242e63f39?auto=format&fit=crop&w=1800&q=85"
    );
}

.housekeeping-photo-layer.photo-three {
    background-image: url(
        "https://images.unsplash.com/photo-1566665797739-1674de7a421a?auto=format&fit=crop&w=1800&q=85"
    );
}

/* Keep the luxury dark overlay above the photos */
.housekeeping-scene::after {
    z-index: -1;
    background: linear-gradient(
        90deg,
        rgba(3, 22, 16, .88),
        rgba(3, 22, 16, .38)
    );
}

@media (prefers-reduced-motion: reduce) {
    .housekeeping-photo-layer {
        transform: none !important;
    }
}

.housekeeping-scene::after {
    content: "";
    position: absolute;
    inset: 0;
    z-index: -1;
    pointer-events: none;
    background: linear-gradient(
        0deg,
        rgba(5, 20, 15, .95),
        transparent 75%
    );
}

.scene-heading {
    position: relative;
    z-index: 2;
    max-width: 600px;
}

.scene-heading .eyebrow {
    color: #e5c77e;
}

.scene-heading h2 {
    margin: 10px 0;
    color: #fff3d4;
    font-size: clamp(27px, 3.5vw, 42px);
}

.scene-heading p {
    color: #e1e7df;
    line-height: 1.7;
    max-width: 520px;
}

.staff-stage {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    align-items: end;
    margin-top: 28px;
}

.staff-figure {
    position: relative;
    min-height: 150px;
    padding: 14px 8px;
    text-align: center;
    border: 1px solid rgba(226, 195, 119, .28);
    border-radius: 14px;
    background: rgba(5, 27, 20, .76);
    backdrop-filter: blur(5px);
  opacity: 1;
transform: none;
}

.staff-illustration {
    display: block;
    width: 100%;
    font-size: 42px;
    line-height: 1.5;
    transform-origin: center bottom;
    filter: drop-shadow(0 5px 7px rgba(0, 0, 0, .3));
}

.staff-figure h3 {
    margin: 7px 0 5px;
    color: #f0d28c;
    font-size: 14px;
}

.staff-figure p {
    margin: 0;
    color: #d5e0d8;
    font-size: 12px;
    line-height: 1.5;
}

.scene-caption {
    margin-top: 18px;
    color: #f0d28c;
    font-size: 12px;
    letter-spacing: 1px;
    text-transform: uppercase;
}

@media (max-width: 700px) {
    .housekeeping-scene {
        padding: 20px;
        min-height: auto;
        background-position: center;
    }

    .staff-stage {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .staff-figure {
        min-height: 140px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .staff-figure,
    .staff-illustration {
        animation: none !important;
        transition: none !important;
    }
}
    .occupied {
    background: #24466a;
    color: #b6dcff;
}
:root {
    --green: #102c24;
    --green-light: #1b4436;
    --gold: #d7b56d;
    --bg: #091711;
    --card: #14251e;
    --text: #f6f2e8;
    --muted: #b5c0b8;
    --border: rgba(215,181,109,.25);
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    background:
        radial-gradient(ellipse at top right, #1b4031 0%, transparent 45%),
        var(--bg);
    color: var(--text);
    min-height: 100vh;
}

.topbar {
    background: rgba(10, 29, 22, .95);
    border-bottom: 1px solid var(--border);
    padding: 20px 5%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
}

.brand {
    color: var(--gold);
    font-size: 23px;
    font-weight: bold;
    letter-spacing: 1px;
}

.topbar a,
.back-link {
    color: var(--gold);
    text-decoration: none;
    border: 1px solid var(--border);
    padding: 10px 14px;
    border-radius: 8px;
}

.topbar a:hover,
.back-link:hover {
    background: var(--green-light);
}

.container {
    width: min(1400px, 92%);
    margin: 35px auto;
}

.hero {
    padding: 28px 0;
}

.eyebrow {
    color: var(--gold);
    text-transform: uppercase;
    letter-spacing: 3px;
    font-size: 12px;
}

h1 {
    font-size: clamp(30px, 4vw, 46px);
    margin: 12px 0;
}

.subtitle {
    color: var(--muted);
    line-height: 1.7;
    max-width: 750px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(155px, 1fr));
    gap: 14px;
    margin: 25px 0;
}

.stat {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 13px;
    padding: 20px;
}

.stat span {
    display: block;
    color: var(--muted);
    font-size: 13px;
    margin-bottom: 12px;
}

.stat strong {
    font-size: 27px;
    color: var(--gold);
}

.message {
    padding: 14px 17px;
    border-radius: 9px;
    margin: 15px 0;
    background: #183d2b;
    border: 1px solid #43845c;
}

.error {
    background: #451e1e;
    border-color: #a85252;
}

.toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin: 25px 0;
}

.toolbar input,
.toolbar select {
    background: #14251e;
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 12px;
    min-width: 180px;
}

.room-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(290px, 1fr));
    gap: 18px;
}

.room-card {
    background: linear-gradient(145deg, #172d23, #101e18);
    border: 1px solid var(--border);
    border-radius: 15px;
    padding: 22px;
    transition: transform .2s, border-color .2s;
}

.room-card:hover {
    transform: translateY(-3px);
    border-color: var(--gold);
}

.room-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}

.room-number {
    font-size: 24px;
    font-weight: bold;
}

.room-type {
    color: var(--muted);
    margin-top: 7px;
}

.badge {
    padding: 7px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
    text-align: center;
}

.dirty { background: #542b2b; color: #ffb6b6; }
.cleaning { background: #58441d; color: #ffe19b; }
.clean { background: #1d493a; color: #a8f0ce; }
.inspected { background: #24466a; color: #b6dcff; }
.maintenance { background: #5a2929; color: #ffc1a9; }
.dnd { background: #41414c; color: #e0e0ee; }

.info-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid rgba(255,255,255,.07);
    font-size: 13px;
}

.info-row span:first-child {
    color: var(--muted);
}

form {
    margin-top: 18px;
}

label {
    display: block;
    font-size: 13px;
    color: var(--muted);
    margin: 12px 0 7px;
}

input,
select,
textarea {
    width: 100%;
    padding: 11px;
    border-radius: 8px;
    background: #0b1912;
    color: var(--text);
    border: 1px solid var(--border);
    font: inherit;
}

textarea {
    resize: vertical;
    min-height: 65px;
}

button {
    width: 100%;
    margin-top: 15px;
    padding: 12px;
    background: var(--gold);
    color: #17251c;
    border: 0;
    border-radius: 8px;
    font-weight: bold;
    cursor: pointer;
}

button:hover {
    background: #efd08a;
}

.empty {
    text-align: center;
    padding: 40px;
    color: var(--muted);
    border: 1px dashed var(--border);
    border-radius: 12px;
}

.footer-note {
    color: var(--muted);
    font-size: 12px;
    margin-top: 30px;
    line-height: 1.7;
}

@media (max-width: 600px) {
    .container {
        width: 94%;
        margin: 20px auto;
    }

    .room-grid {
        grid-template-columns: 1fr;
    }
}
</style>


</head>

<body>

<header class="topbar">
    <div class="brand">SUVA HOTEL</div>
    <a href="../dashboard.php">← Back to Dashboard</a>
</header>
<!-- =====================================================
     ANIMATED HOUSEKEEPING STAFF SCENE
====================================================== -->
<section class="housekeeping-scene" aria-label="Housekeeping team">
    <div class="housekeeping-photo" aria-hidden="true">
    <div class="housekeeping-photo-layer photo-one"></div>
    <div class="housekeeping-photo-layer photo-two"></div>
    <div class="housekeeping-photo-layer photo-three"></div>
</div>
    <div class="scene-heading">
        <div class="eyebrow">Luxury Room Care</div>
        <h2>Every Detail, Perfectly Prepared</h2>
        <p>
            Our housekeeping team prepares every room with care,
            comfort and attention to detail.
        </p>
    </div>

    <div class="staff-stage">

        <article class="staff-figure">
            <span class="staff-illustration" aria-hidden="true">🧺</span>
            <h3>Linen Attendant</h3>
            <p>Fresh towels and linen</p>
        </article>

        <article class="staff-figure">
            <span class="staff-illustration" aria-hidden="true">🛏️</span>
            <h3>Room Attendant</h3>
            <p>Bed and room preparation</p>
        </article>

        <article class="staff-figure">
            <span class="staff-illustration" aria-hidden="true">🧹</span>
            <h3>Cleaning Staff</h3>
            <p>Cleaning and amenities</p>
        </article>

        <article class="staff-figure">
            <span class="staff-illustration" aria-hidden="true">📋</span>
            <h3>Room Inspector</h3>
            <p>Final quality inspection</p>
        </article>

    </div>

    <div class="scene-caption">
        Clean rooms · Comfortable stays · Happy guests
    </div>
</section>

<main class="container">

    <section class="hero">
        <div class="eyebrow">Room Operations</div>
        <h1>Housekeeping Management</h1>
        <p class="subtitle">
            Track dirty rooms, cleaning progress, clean rooms and
            inspection status. Room booking availability is managed
            separately from housekeeping.
        </p>
    </section>

    <?php if ($message !== ''): ?>
        <div class="message"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="message error"><?= h($error) ?></div>
    <?php endif; ?>

    <section class="stats">
        <?php foreach ($counts as $status => $count): ?>
            <div class="stat">
                <span><?= h($status) ?></span>
                <strong><?= (int)$count ?></strong>
            </div>
        <?php endforeach; ?>
    </section>

    <div class="toolbar">
        <input
            type="search"
            id="roomSearch"
            placeholder="Search room number or type..."
            aria-label="Search rooms"
        >

        <select id="statusFilter" aria-label="Filter housekeeping status">
            <option value="">All housekeeping statuses</option>
            <?php foreach ($allowedStatuses as $status): ?>
                <option value="<?= h($status) ?>"><?= h($status) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if (count($rooms) === 0): ?>
        <div class="empty">
            No rooms found. Add rooms from the Rooms section first.
        </div>
    <?php else: ?>

        <section class="room-grid" id="roomGrid">

            <?php foreach ($rooms as $room): ?>
                <?php
                    $houseStatus = $room['housekeeping_status'];
                    $roomStatus = strtolower($room['room_status'] ?? '');
                    $searchText = strtolower(
                        $room['room_number'] . ' ' . $room['room_type']
                    );
                ?>

                <article
                    class="room-card"
                    data-search="<?= h($searchText) ?>"
                    data-status="<?= h($houseStatus) ?>"
                >

                    <div class="room-header">
                        <div>
                            <div class="room-number">
                                Room <?= h($room['room_number']) ?>
                            </div>
                            <div class="room-type">
                                <?= h($room['room_type']) ?>
                            </div>
                        </div>

                        <span class="badge <?= h(statusClass($houseStatus)) ?>">
                            <?= h($houseStatus) ?>
                        </span>
                    </div>

                    <div class="info-row">
                        <span>Room system status</span>
                        <strong><?= h(ucfirst($roomStatus)) ?></strong>
                    </div>

                    <div class="info-row">
                        <span>Price per night</span>
                        <strong>
                            Rs. <?= number_format(
                                (float)$room['price_per_night'], 2
                            ) ?>
                        </strong>
                    </div>

                    <div class="info-row">
                        <span>Last updated</span>
                        <strong>
                            <?= h($room['updated_at'] ?: 'Not updated') ?>
                        </strong>
                    </div>

                    <form method="POST" action="index.php">
                        <input
                            type="hidden"
                            name="room_id"
                            value="<?= (int)$room['id'] ?>"
                        >

                        <label for="status-<?= (int)$room['id'] ?>">
                            Housekeeping status
                        </label>

                        <select
                            name="status"
                            id="status-<?= (int)$room['id'] ?>"
                            required
                        >
                            <?php foreach ($allowedStatuses as $status): ?>
                                <option
                                    value="<?= h($status) ?>"
                                    <?= $houseStatus === $status ? 'selected' : '' ?>
                                >
                                    <?= h($status) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="notes-<?= (int)$room['id'] ?>">
                            Cleaning notes
                        </label>

                        <textarea
                            name="notes"
                            id="notes-<?= (int)$room['id'] ?>"
                            maxlength="2000"
                            placeholder="Enter cleaning or inspection notes..."
                        ><?= h($room['notes']) ?></textarea>

                        <button type="submit">Save Room Status</button>
                    </form>

                </article>
            <?php endforeach; ?>

        </section>

        <div class="empty" id="noMatches" hidden>
            No rooms match your search or filter.
        </div>

    <?php endif; ?>

    <p class="footer-note">
        Important: Marking a room as Inspected here does not automatically
        make it available for booking. The room must also have an available
        room status and pass the booking date/overlap checks.
    </p>

</main>

<!-- GSAP animation library -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.14.2/gsap.min.js"></script>

<!-- Housekeeping staff animations -->
<script>
document.addEventListener("DOMContentLoaded", function () {
    const staff = document.querySelectorAll(".staff-figure");
    // Cinematic hotel-background photo animation
const photos = gsap.utils.toArray(".housekeeping-photo-layer");

if (
    photos.length &&
    !window.matchMedia("(prefers-reduced-motion: reduce)").matches
) {
    // Show the first image immediately
    gsap.set(photos, { opacity: 0 });
    gsap.set(photos[0], { opacity: 1, scale: 1.04 });

    // Slowly zoom and crossfade between hotel photographs
    const photoTimeline = gsap.timeline({
        repeat: -1,
        repeatDelay: 0
    });

    photos.forEach(function (photo, index) {
        const nextPhoto = photos[(index + 1) % photos.length];

        photoTimeline.to(photo, {
            scale: 1.13,
            xPercent: -1.5,
            duration: 7,
            ease: "none"
        });

        photoTimeline.to(nextPhoto, {
            opacity: 1,
            scale: 1.04,
            xPercent: 0,
            duration: 2,
            ease: "sine.inOut"
        }, "-=2");

        photoTimeline.set(photo, { opacity: 0 });
    });
}
    const illustrations = document.querySelectorAll(".staff-illustration");

    // Keep the scene visible if GSAP is unavailable
    if (!staff.length || typeof gsap === "undefined") {
        return;
    }

    // Respect visitors who prefer reduced motion
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
        return;
    }

    // Bring the four staff illustrations into view one by one
    gsap.fromTo(
        staff,
        {
            opacity: 0,
            y: 35
        },
        {
            opacity: 1,
            y: 0,
            duration: 0.8,
            stagger: 0.22,
            ease: "power3.out",
            clearProps: "transform"
        }
    );

    // Give each illustration a gentle, continuous movement
    illustrations.forEach(function (figure, index) {
        gsap.to(figure, {
            y: index % 2 === 0 ? -7 : 7,
            duration: 1.8 + index * 0.2,
            repeat: -1,
            yoyo: true,
            ease: "sine.inOut",
            delay: index * 0.18
        });
    });

    // Subtle highlight when hovering over a staff card
    staff.forEach(function (card) {
        card.addEventListener("mouseenter", function () {
            gsap.to(card, {
                y: -5,
                borderColor: "rgba(215, 181, 109, 0.9)",
                duration: 0.25,
                overwrite: "auto"
            });
        });

        card.addEventListener("mouseleave", function () {
            gsap.to(card, {
                y: 0,
                borderColor: "rgba(226, 195, 119, 0.28)",
                duration: 0.25,
                overwrite: "auto"
            });
        });
    });
});
</script>


<script>
const roomSearch = document.getElementById('roomSearch');
const statusFilter = document.getElementById('statusFilter');
const roomCards = document.querySelectorAll('.room-card');
const noMatches = document.getElementById('noMatches');

function filterRooms() {
    const search = roomSearch.value.toLowerCase().trim();
    const status = statusFilter.value;
    let visible = 0;

    roomCards.forEach(function(card) {
        const matchesSearch = card.dataset.search.includes(search);
        const matchesStatus =
            status === '' || card.dataset.status === status;

        const show = matchesSearch && matchesStatus;
        card.hidden = !show;

        if (show) visible++;
    });

    if (noMatches) {
        noMatches.hidden = visible !== 0;
    }
}

roomSearch.addEventListener('input', filterRooms);
statusFilter.addEventListener('change', filterRooms);
</script>

</body>
</html>
