<?php
// ============================================================
// SUVA HOTEL - EDIT ROOM
// PostgreSQL Compatible
// File: /rooms/edit.php
// ============================================================

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once '../config/database.php';

$role = $_SESSION['role'] ?? '';

if (!in_array($role, ['admin', 'staff'], true)) {
    header('Location: ../login.php');
    exit;
}

$username = $_SESSION['username'] ?? 'User';

if (!isset($pgConnection) || !$pgConnection) {
    die('Database connection failed.');
}

// ------------------------------------------------------------
// ROOM ID
// ------------------------------------------------------------

$roomId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($roomId <= 0) {
    header('Location: view.php');
    exit;
}

// ------------------------------------------------------------
// HELPER FUNCTIONS
// ------------------------------------------------------------

function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function getRoomImageByType($roomType)
{
    $images = [
        'single'    => 'images/single.jpg',
        'double'    => 'images/deluxe-room.jpg',
        'deluxe'    => 'images/deluxe.jpg',
        'executive' => 'images/executive-room.jpg',
        'family'    => 'images/hero.jpg',
        'standard'  => 'images/standard-room.jpg',
        'suite'     => 'images/suite.jpg'
    ];

    $type = strtolower(trim((string) $roomType));

    return $images[$type] ?? 'images/standard-room.jpg';
}

// Read the allowed statuses from the actual database
// constraint rather than assuming the permitted values.
function getAllowedRoomStatuses($connection)
{
    $sql = "
        SELECT pg_get_constraintdef(oid) AS definition
        FROM pg_constraint
        WHERE conrelid = 'rooms'::regclass
          AND conname = 'rooms_status_check'
          AND contype = 'c'
        LIMIT 1
    ";

    $result = pg_query($connection, $sql);

    if (!$result) {
        return [];
    }

    $row = pg_fetch_assoc($result);

    if (!$row || empty($row['definition'])) {
        return [];
    }

    $definition = $row['definition'];

    // Extract quoted values from the CHECK constraint.
    preg_match_all(
        "/'((?:[^']|'')*)'/",
        $definition,
        $matches
    );

    $statuses = [];

    foreach ($matches[1] as $value) {
        $value = str_replace("''", "'", $value);
        $value = trim($value);

        if ($value !== '') {
            $statuses[strtolower($value)] = $value;
        }
    }

    return $statuses;
}

// ------------------------------------------------------------
// FETCH ROOM FROM DATABASE
// ------------------------------------------------------------

$result = pg_query_params(
    $pgConnection,
    'SELECT * FROM rooms WHERE id = $1 LIMIT 1',
    [$roomId]
);

if (!$result) {
    die(
        'Database error: ' .
        e(pg_last_error($pgConnection))
    );
}

$room = pg_fetch_assoc($result);

if (!$room) {
    die('Room not found.');
}

// ------------------------------------------------------------
// ROOM TYPES
// ------------------------------------------------------------

$roomTypes = [
    'single'    => 'Single Room',
    'double'    => 'Double Room',
    'deluxe'    => 'Deluxe Room',
    'executive' => 'Executive Room',
    'family'    => 'Family Room',
    'standard'  => 'Standard Room',
    'suite'     => 'Suite Room'
];

// ------------------------------------------------------------
// DATABASE-ALLOWED STATUSES
// ------------------------------------------------------------

$statusOptions = getAllowedRoomStatuses($pgConnection);

$statusLabels = [
    'available'   => 'Available',
    'occupied'    => 'Occupied',
    'reserved'    => 'Reserved',
    'maintenance' => 'Maintenance',
    'booked'      => 'Booked',
    'unavailable' => 'Unavailable'
];

// ------------------------------------------------------------
// INITIAL VALUES
// Load all values from the existing room.
// ------------------------------------------------------------

$roomNumber = $room['room_number'] ?? '';
$roomType = strtolower(trim($room['room_type'] ?? ''));
$price = $room['price_per_night'] ?? '';
$status = strtolower(trim($room['status'] ?? ''));
$description = $room['description'] ?? '';

$error = '';
$success = '';

// ------------------------------------------------------------
// UPDATE ROOM
// ------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Preserve submitted values if validation fails.
    $roomNumber = trim($_POST['room_number'] ?? '');
    $roomType = strtolower(trim($_POST['room_type'] ?? ''));
    $price = trim($_POST['price'] ?? '');
    $postedStatus = strtolower(trim($_POST['status'] ?? ''));
    $description = trim($_POST['description'] ?? '');

    if ($roomNumber === '') {

        $error = 'Room number is required.';

    } elseif (!array_key_exists($roomType, $roomTypes)) {

        $error = 'Please select a valid room type.';

    } elseif (
        $price === '' ||
        !is_numeric($price) ||
        !is_finite((float) $price)
    ) {

        $error = 'Please enter a valid room price.';

    } elseif ((float) $price < 0) {

        $error = 'Room price cannot be negative.';

    } elseif (strlen($roomNumber) > 100) {

        $error = 'Room number is too long.';

    } elseif (strlen($description) > 10000) {

        $error = 'Room description is too long.';

    } elseif (empty($statusOptions)) {

        $error = 'Unable to read the allowed room statuses from the database.';

    } elseif (!array_key_exists($postedStatus, $statusOptions)) {

        $error = 'Please select a room status allowed by the database.';

    } else {

        // Use the exact value permitted by PostgreSQL.
        $status = $statusOptions[$postedStatus];

        // Check for duplicate room numbers.
        $duplicateResult = pg_query_params(
            $pgConnection,
            'SELECT id
             FROM rooms
             WHERE room_number = $1
               AND id <> $2
             LIMIT 1',
            [$roomNumber, $roomId]
        );

        if (!$duplicateResult) {

            $error = 'Unable to check room number: ' .
                     pg_last_error($pgConnection);

        } elseif (pg_num_rows($duplicateResult) > 0) {

            $error = 'Another room already uses room number ' .
                     $roomNumber . '.';

        } else {

            // Correct PostgreSQL column: price_per_night.
            $updateResult = pg_query_params(
                $pgConnection,
                'UPDATE rooms
                 SET room_number = $1,
                     room_type = $2,
                     price_per_night = $3,
                     status = $4,
                     description = $5
                 WHERE id = $6',
                [
                    $roomNumber,
                    $roomType,
                    $price,
                    $status,
                    $description,
                    $roomId
                ]
            );

            if ($updateResult) {

                $success = 'Room updated successfully.';

                // Refresh room data after updating.
                $result = pg_query_params(
                    $pgConnection,
                    'SELECT * FROM rooms WHERE id = $1 LIMIT 1',
                    [$roomId]
                );

                if ($result) {
                    $updatedRoom = pg_fetch_assoc($result);

                    if ($updatedRoom) {
                        $room = $updatedRoom;

                        $roomNumber = $room['room_number'] ?? '';
                        $roomType = strtolower(
                            trim($room['room_type'] ?? '')
                        );
                        $price = $room['price_per_night'] ?? '';
                        $status = strtolower(
                            trim($room['status'] ?? '')
                        );
                        $description = $room['description'] ?? '';
                    }
                }

            } else {

                $error = 'Failed to update room: ' .
                         pg_last_error($pgConnection);
            }
        }
    }
}

$imagePath = getRoomImageByType($roomType);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Edit Room - Suva Hotel</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    background:
        linear-gradient(
            rgba(15, 23, 42, 0.82),
            rgba(15, 23, 42, 0.92)
        ),
        url("../assets/images/hotel-lighting.jpg")
        center / cover fixed;
    color: #1f2937;
    min-height: 100vh;
}

.topbar {
    background: rgba(15, 23, 42, 0.95);
    color: white;
    padding: 16px 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    box-shadow: 0 4px 15px #0004;
}

.topbar h2 {
    margin: 0;
    font-size: 22px;
}

.user-info {
    font-size: 14px;
}

.container {
    width: 95%;
    max-width: 1100px;
    margin: 35px auto;
    background: rgba(255, 255, 255, 0.97);
    border-radius: 18px;
    padding: 30px;
    box-shadow: 0 15px 45px #0005;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-bottom: 25px;
}

.page-header h1 {
    margin: 0;
    color: #111827;
}

.back-btn {
    display: inline-block;
    text-decoration: none;
    background: #374151;
    color: white;
    padding: 11px 18px;
    border-radius: 8px;
    font-size: 14px;
}

.back-btn:hover {
    background: #111827;
}

.message {
    padding: 14px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 14px;
    overflow-wrap: anywhere;
}

.success {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #86efac;
}

.error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fca5a5;
}

.form-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 35px;
}

.form-section {
    width: 100%;
    min-width: 0;
}

.form-group {
    margin-bottom: 20px;
}

label {
    display: block;
    margin-bottom: 8px;
    font-weight: bold;
    color: #374151;
}

input,
select,
textarea {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 15px;
    background: white;
    outline: none;
}

input:focus,
select:focus,
textarea:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px #2563eb20;
}

textarea {
    min-height: 130px;
    resize: vertical;
}

.image-box {
    border-radius: 15px;
    overflow: hidden;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    box-shadow: 0 8px 20px #0001;
}

.image-title {
    padding: 15px;
    background: #111827;
    color: white;
    font-weight: bold;
    text-align: center;
}

.room-preview {
    width: 100%;
    height: 360px;
    object-fit: cover;
    display: block;
    background: #e5e7eb;
}

.image-note {
    padding: 14px;
    text-align: center;
    font-size: 13px;
    color: #6b7280;
    background: white;
}

.image-path {
    margin-top: 10px;
    font-size: 12px;
    color: #6b7280;
    word-break: break-all;
}

.buttons {
    display: flex;
    gap: 12px;
    margin-top: 25px;
    flex-wrap: wrap;
}

.btn {
    border: 0;
    padding: 13px 22px;
    border-radius: 8px;
    font-size: 15px;
    font-weight: bold;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}

.btn-primary {
    background: #2563eb;
    color: white;
}

.btn-primary:hover {
    background: #1d4ed8;
}

.btn-secondary {
    background: #6b7280;
    color: white;
}

.btn-secondary:hover {
    background: #4b5563;
}

.room-id {
    display: inline-block;
    background: #e5e7eb;
    color: #374151;
    padding: 5px 10px;
    border-radius: 6px;
    font-size: 12px;
    margin-top: 8px;
}

.price-wrapper {
    position: relative;
}

.price-symbol {
    position: absolute;
    left: 13px;
    top: 12px;
    color: #6b7280;
    font-weight: bold;
    pointer-events: none;
}

.price-input {
    padding-left: 42px;
}

@media (max-width: 800px) {
    .form-layout {
        grid-template-columns: 1fr;
    }

    .container {
        padding: 20px;
    }

    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .room-preview {
        height: 300px;
    }

    .topbar {
        padding: 15px;
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>
</head>

<body>

<div class="topbar">
    <h2>🏨 Suva Hotel - Edit Room</h2>

    <div class="user-info">
        Logged in as:
        <strong><?php echo e($username); ?></strong>
    </div>
</div>

<div class="container">

    <div class="page-header">
        <div>
            <h1>✏️ Edit Room</h1>

            <div class="room-id">
                Room ID: <?php echo e($roomId); ?>
            </div>
        </div>

        <a href="view.php" class="back-btn">
            ← Back to Rooms
        </a>
    </div>

    <?php if ($success !== ''): ?>
        <div class="message success">
            ✅ <?php echo e($success); ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="message error">
            ❌ <?php echo e($error); ?>
        </div>
    <?php endif; ?>

    <?php if (empty($statusOptions)): ?>
        <div class="message error">
            The allowed room statuses could not be read.
            Check the rooms_status_check constraint in PostgreSQL.
        </div>
    <?php endif; ?>

    <form
        method="POST"
        action="edit.php?id=<?php echo e($roomId); ?>"
    >

        <div class="form-layout">

            <div class="form-section">

                <div class="form-group">
                    <label for="room_number">Room Number</label>

                    <input
                        type="text"
                        id="room_number"
                        name="room_number"
                        value="<?php echo e($roomNumber); ?>"
                        placeholder="Example: 106"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="room_type">Room Type</label>

                    <select
                        id="room_type"
                        name="room_type"
                        onchange="updateRoomImage()"
                        required
                    >
                        <option value="">-- Select Room Type --</option>

                        <?php foreach ($roomTypes as $value => $label): ?>
                            <option
                                value="<?php echo e($value); ?>"
                                <?php echo $roomType === $value
                                    ? 'selected'
                                    : ''; ?>
                            >
                                <?php echo e($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="price">Room Price per Night</label>

                    <div class="price-wrapper">
                        <span class="price-symbol">Rs.</span>

                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            id="price"
                            name="price"
                            class="price-input"
                            value="<?php echo e($price); ?>"
                            placeholder="4000"
                            required
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label for="status">Room Status</label>

                    <select
                        id="status"
                        name="status"
                        required
                    >
                        <?php foreach ($statusOptions as $value => $dbValue): ?>
                            <?php
                            $label = $statusLabels[$value]
                                ?? ucfirst($value);
                            ?>
                            <option
                                value="<?php echo e($value); ?>"
                                <?php echo $status === $value
                                    ? 'selected'
                                    : ''; ?>
                            >
                                <?php echo e($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="description">Description</label>

                    <textarea
                        id="description"
                        name="description"
                        placeholder="Enter room description..."
                    ><?php echo e($description); ?></textarea>
                </div>

                <div class="buttons">
                    <button
                        type="submit"
                        class="btn btn-primary"
                        <?php echo empty($statusOptions)
                            ? 'disabled'
                            : ''; ?>
                    >
                        💾 Update Room
                    </button>

                    <a href="view.php" class="btn btn-secondary">
                        Cancel
                    </a>
                </div>

            </div>

            <div class="form-section">
                <div class="image-box">

                    <div class="image-title">
                        📸 Room Image Preview
                    </div>

                    <img
                        id="roomPreview"
                        class="room-preview"
                        src="<?php echo e($imagePath); ?>"
                        alt="Room Preview"
                        onerror="this.onerror=null;this.src='images/standard-room.jpg';"
                    >

                    <div class="image-note">
                        Room image changes automatically
                        according to the selected room type.

                        <div class="image-path" id="imagePath">
                            <?php echo e($imagePath); ?>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </form>

</div>

<script>
function updateRoomImage() {
    const type = document
        .getElementById('room_type')
        .value
        .toLowerCase()
        .trim();

    const images = {
        single: 'single.jpg',
        double: 'deluxe-room.jpg',
        deluxe: 'deluxe.jpg',
        executive: 'executive-room.jpg',
        family: 'hero.jpg',
        standard: 'standard-room.jpg',
        suite: 'suite.jpg'
    };

    const path = 'images/' +
        (images[type] || 'standard-room.jpg');

    const preview = document.getElementById('roomPreview');
    const imagePath = document.getElementById('imagePath');

    preview.onerror = function () {
        this.onerror = null;
        this.src = 'images/standard-room.jpg';
        imagePath.textContent = 'images/standard-room.jpg';
    };

    preview.src = path;
    imagePath.textContent = path;
}
</script>

</body>
</html>
