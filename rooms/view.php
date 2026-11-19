
<?php
// ============================================================
// SUVA HOTEL - ROOMS VIEW
// PostgreSQL Compatible | Uploaded Room Photos
// File: rooms/view.php
// ============================================================

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';

// ============================================================
// ROOM FEATURES
// ============================================================

function getRoomFeatures($roomType)
{
    $roomType = strtolower(trim($roomType ?? ''));

    switch ($roomType) {
        case 'deluxe':
            return [
                'King Bed',
                'Free Wi-Fi',
                'Smart TV',
                'Air Conditioning',
                'Mini Bar'
            ];

        case 'family':
            return [
                '2 Beds',
                'Free Wi-Fi',
                'Smart TV',
                'Air Conditioning',
                'Extra Space'
            ];

        case 'standard':
            return [
                'Comfortable Bed',
                'Free Wi-Fi',
                'TV',
                'Air Conditioning'
            ];

        case 'suite':
        case 'presidential':
            return [
                'King Bed',
                'Free Wi-Fi',
                'Smart TV',
                'Living Area',
                'Mini Bar',
                'Air Conditioning'
            ];

        case 'single':
            return [
                'Single Bed',
                'Free Wi-Fi',
                'TV',
                'Air Conditioning'
            ];

        case 'double':
            return [
                'Double Bed',
                'Free Wi-Fi',
                'TV',
                'Air Conditioning'
            ];

        case 'executive':
            return [
                'Premium Bed',
                'Free Wi-Fi',
                'Smart TV',
                'Air Conditioning'
            ];

        default:
            return [
                'Comfortable Bed',
                'Free Wi-Fi',
                'TV'
            ];
    }
}

// ============================================================
// DEFAULT ROOM PREVIEW IMAGES
// Used only when an uploaded image is unavailable.
// Paths are relative to rooms/view.php.
// ============================================================

$fallbackImages = [
    'single'       => 'images/single.jpg',
    'double'       => 'images/deluxe-room.jpg',
    'deluxe'       => 'images/deluxe.jpg',
    'executive'    => 'images/executive-room.jpg',
    'family'       => 'images/hero.jpg',
    'standard'     => 'images/standard-room.jpg',
    'suite'        => 'images/suite.jpg',
    'presidential' => 'images/suite.jpg'
];

// ============================================================
// GET ROOMS FROM POSTGRESQL
// ============================================================

$sql = "
    SELECT
        r.id,
        r.room_number,
        r.room_type,
        r.price_per_night,
        r.status AS room_status,
        r.description,
        r.image,
        r.created_at,

        CASE
            WHEN LOWER(r.status) = 'maintenance'
                THEN 'maintenance'

         
WHEN EXISTS (
    SELECT 1
    FROM bookings b
    WHERE b.room_id = r.id
      AND LOWER(TRIM(b.status))
          NOT IN ('cancelled', 'checked out', 'checked_out', 'checked-out')
      AND b.check_in <= CURRENT_DATE
      AND b.check_out > CURRENT_DATE
)
THEN 'occupied'


            ELSE 'available'
        END AS current_status

    FROM rooms r
    ORDER BY r.room_number ASC
";

$result = $conn->query($sql);

$rooms = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rooms[] = $row;
    }
}

// ============================================================
// ROOM STATISTICS
// ============================================================

$totalRooms = count($rooms);
$availableRooms = 0;
$occupiedRooms = 0;
$maintenanceRooms = 0;

foreach ($rooms as $room) {
    $status = strtolower(trim($room['current_status'] ?? ''));

    if ($status === 'available') {
        $availableRooms++;
    } elseif ($status === 'occupied') {
        $occupiedRooms++;
    } elseif ($status === 'maintenance') {
        $maintenanceRooms++;
    }
}

// ============================================================
// HELPER: ESCAPE HTML OUTPUT
// ============================================================

function e($value)
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Rooms | Suva Hotel</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: linear-gradient(135deg, #eef5ff, #f8fafc);
            color: #1f2937;
            min-height: 100vh;
        }

        .header {
            background: linear-gradient(135deg, #0f4c81, #1976d2);
            color: white;
            padding: 26px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
        }

        .header h1 {
            font-size: 30px;
            margin-bottom: 6px;
        }

        .header p {
            font-size: 14px;
            opacity: 0.9;
        }

        .header-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-block;
            padding: 11px 18px;
            border-radius: 9px;
            text-decoration: none;
            font-weight: bold;
            transition: 0.3s;
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .dashboard-btn {
            background: white;
            color: #0f4c81;
        }

        .add-btn {
            background: #16a34a;
            color: white;
        }

        .container {
            max-width: 1400px;
            margin: 35px auto;
            padding: 0 25px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 35px;
        }

        .stat {
            background: white;
            padding: 23px;
            border-radius: 16px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.07);
            border-left: 5px solid #1976d2;
        }

        .stat.available {
            border-left-color: #16a34a;
        }

        .stat.occupied {
            border-left-color: #f59e0b;
        }

        .stat.maintenance {
            border-left-color: #dc2626;
        }

        .stat-title {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 8px;
        }

        .stat-number {
            font-size: 31px;
            font-weight: bold;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 22px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .section-header h2 {
            font-size: 27px;
            margin-bottom: 5px;
        }

        .section-header p {
            color: #6b7280;
            font-size: 14px;
        }

        .room-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 25px;
        }

        .room-card {
            background: white;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 6px 25px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .room-card:hover {
            transform: translateY(-7px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.14);
        }

        .room-image-container {
            position: relative;
            width: 100%;
            height: 240px;
            background: linear-gradient(135deg, #dbeafe, #f8fafc);
            overflow: hidden;
        }

        .room-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform 0.5s;
        }

        .room-card:hover .room-image {
            transform: scale(1.06);
        }

        .ai-badge {
            position: absolute;
            left: 14px;
            top: 14px;
            background: rgba(0, 0, 0, 0.72);
            color: white;
            padding: 7px 11px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            backdrop-filter: blur(5px);
        }

        .status {
            position: absolute;
            right: 14px;
            top: 14px;
            padding: 7px 13px;
            border-radius: 20px;
            color: white;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-available {
            background: #16a34a;
        }

        .status-occupied {
            background: #f59e0b;
        }

        .status-maintenance {
            background: #dc2626;
        }

        .room-content {
            padding: 22px;
        }

        .room-number {
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .room-type {
            color: #1976d2;
            font-weight: bold;
            text-transform: capitalize;
            margin-bottom: 14px;
        }

        .price {
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 13px;
        }

        .price span {
            color: #6b7280;
            font-size: 13px;
            font-weight: normal;
        }

        .description {
            color: #6b7280;
            line-height: 1.6;
            font-size: 14px;
            min-height: 45px;
            margin-bottom: 17px;
            overflow-wrap: anywhere;
        }

        .features {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            margin-bottom: 20px;
        }

        .feature {
            background: #eef5ff;
            color: #185abc;
            padding: 6px 9px;
            border-radius: 6px;
            font-size: 11px;
        }

        .actions {
            display: flex;
            gap: 9px;
        }

        .action {
            flex: 1;
            text-align: center;
            padding: 10px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: bold;
            transition: 0.3s;
        }

        .action:hover {
            transform: translateY(-2px);
        }

        .edit {
            background: #e0f2fe;
            color: #0369a1;
        }

        .delete {
            background: #fee2e2;
            color: #dc2626;
        }

        .empty {
            background: white;
            padding: 70px 20px;
            text-align: center;
            border-radius: 18px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.07);
        }

        .empty h3 {
            font-size: 24px;
            margin-bottom: 10px;
        }

        .empty p {
            color: #6b7280;
            margin-bottom: 20px;
        }

        @media (max-width: 1100px) {
            .room-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 650px) {
            .header {
                padding: 22px;
            }

            .header h1 {
                font-size: 25px;
            }

            .container {
                padding: 0 15px;
            }

            .room-grid,
            .stats {
                grid-template-columns: 1fr;
            }

            .room-image-container {
                height: 220px;
            }
        }
    </style>
</head>

<body>

<header class="header">
    <div>
        <h1>🏨 Suva Hotel Rooms</h1>
        <p>Room photos • PostgreSQL • Smart room management</p>
    </div>

    <div class="header-buttons">
        <a href="../dashboard.php" class="btn dashboard-btn">
            ← Dashboard
        </a>

        <a href="add.php" class="btn add-btn">
            + Add Room
        </a>
    </div>
</header>

<div class="container">

    <!-- ROOM STATISTICS -->
    <div class="stats">
        <div class="stat">
            <div class="stat-title">Total Rooms</div>
            <div class="stat-number"><?= $totalRooms ?></div>
        </div>

        <div class="stat available">
            <div class="stat-title">Available Rooms</div>
            <div class="stat-number"><?= $availableRooms ?></div>
        </div>

        <div class="stat occupied">
            <div class="stat-title">Occupied Rooms</div>
            <div class="stat-number"><?= $occupiedRooms ?></div>
        </div>

        <div class="stat maintenance">
            <div class="stat-title">Maintenance</div>
            <div class="stat-number"><?= $maintenanceRooms ?></div>
        </div>
    </div>

    <!-- SECTION HEADER -->
    <div class="section-header">
        <div>
            <h2>Our Rooms</h2>
            <p>Each room displays its uploaded photo when available.</p>
        </div>

        <p><?= $totalRooms ?> room(s)</p>
    </div>

    <!-- ROOM CARDS -->
    <?php if ($totalRooms > 0): ?>

        <div class="room-grid">

            <?php foreach ($rooms as $room): ?>

                <?php
                $roomType = strtolower(trim($room['room_type'] ?? ''));
                $status = strtolower(trim($room['current_status'] ?? 'available'));

                // ------------------------------------------------
                // PRIORITY 1: PHOTO UPLOADED FOR THIS ROOM
                // ------------------------------------------------

                
$uploadedImage = trim($room['image'] ?? '');

$image = '';
$isUploadedImage = false;

if ($uploadedImage !== '') {

    $filename = basename(
        str_replace('\\', '/', $uploadedImage)
    );

    $localPath = __DIR__ . '/uploads/' . $filename;

    if (is_file($localPath)) {
        $image = '/suva_hotel/rooms/uploads/' .
                 rawurlencode($filename);

        $isUploadedImage = true;
    }
}

if ($image === '') {
    $fallback = $fallbackImages[$roomType]
        ?? 'images/standard-room.jpg';

    $image = '/suva_hotel/rooms/' . $fallback;
}

$features = getRoomFeatures($roomType);
$statusClass = 'status-' . $status;


                // ------------------------------------------------
                // PRIORITY 2: DEFAULT ROOM-TYPE PREVIEW
                // ------------------------------------------------

                if ($image === '') {
                    $image = $fallbackImages[$roomType]
                        ?? 'images/standard-room.jpg';
                }

                $features = getRoomFeatures($roomType);
                $statusClass = 'status-' . $status;
                ?>

                <div class="room-card">

                    <!-- ROOM PHOTO -->
                    <div class="room-image-container">

                      
<img
    src="<?= e($image) ?>"
    alt="<?= e($room['room_type']) ?> Room"
    class="room-image"
    loading="lazy"
    onerror="this.onerror=null;this.src='/suva_hotel/images/no-room.jpg';"
>

                        <div class="ai-badge">
                            <?= $isUploadedImage
                                ? '📸 Room Photo'
                                : '🏨 Room Preview' ?>
                        </div>

                        <div class="status <?= e($statusClass) ?>">
                            <?= e($status) ?>
                        </div>

                    </div>

                    <!-- ROOM DETAILS -->
                    <div class="room-content">

                        <div class="room-number">
                            Room <?= e($room['room_number']) ?>
                        </div>

                        <div class="room-type">
                            <?= e($room['room_type']) ?>
                        </div>

                        <div class="price">
                            Rs. <?= number_format(
                                (float)$room['price_per_night'],
                                2
                            ) ?>
                            <span>/ night</span>
                        </div>

                        <div class="description">
                            <?php if (!empty($room['description'])): ?>
                                <?= e($room['description']) ?>
                            <?php else: ?>
                                Comfortable and beautifully designed room for your stay.
                            <?php endif; ?>
                        </div>

                        <div class="features">
                            <?php foreach ($features as $feature): ?>
                                <span class="feature">
                                    ✓ <?= e($feature) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>

                        <div class="actions">

                            <a
                                href="edit.php?id=<?= (int)$room['id'] ?>"
                                class="action edit"
                            >
                                ✏ Edit
                            </a>

                            <a
                                href="delete.php?id=<?= (int)$room['id'] ?>"
                                class="action delete"
                                onclick="return confirm('Are you sure you want to delete this room?');"
                            >
                                🗑 Delete
                            </a>

                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="empty">
            <h3>No Rooms Found</h3>
            <p>Add your first room to start managing rooms.</p>

            <a href="add.php" class="btn add-btn">
                + Add First Room
            </a>
        </div>

    <?php endif; ?>

</div>

</body>
</html>