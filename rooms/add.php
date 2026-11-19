<?php
// ============================================================
// SUVA HOTEL - ADD ROOM
// PostgreSQL Version
// File: /Suva_hotel/rooms/add.php
// ============================================================

session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ============================================================
// AUTHENTICATION
// ============================================================

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// ============================================================
// DATABASE
// ============================================================

require_once __DIR__ . '/../config/database.php';

// ============================================================
// ROLE CHECK
// ADMIN + STAFF CAN ADD ROOMS
// ============================================================

$role = strtolower(trim($_SESSION['role'] ?? ''));

if ($role !== 'admin' && $role !== 'staff') {
    header('Location: ../login.php');
    exit;
}

// ============================================================
// DATABASE CONNECTION CHECK
// ============================================================

if (!isset($pgConnection) || !$pgConnection) {
    die('Database connection is not available.');
}

// ============================================================
// HELPER
// ============================================================

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// ============================================================
// VARIABLES
// ============================================================

$error = '';

$roomNumber = trim($_POST['room_number'] ?? '');
$roomType = trim($_POST['room_type'] ?? '');
$pricePerNight = trim($_POST['price_per_night'] ?? '');
$status = strtolower(trim($_POST['status'] ?? 'available'));
$description = trim($_POST['description'] ?? '');

// ============================================================
// UPLOAD DIRECTORY
// ============================================================

$uploadDir = __DIR__ . '/uploads/';

if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        $error = 'Unable to create the room uploads folder.';
    }
}

// ============================================================
// FORM SUBMISSION
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {

    // ========================================================
    // VALIDATE ROOM NUMBER
    // ========================================================

    if ($roomNumber === '') {

        $error = 'Please enter the room number.';

    // ========================================================
    // VALIDATE ROOM TYPE
    // ========================================================

    } elseif ($roomType === '') {

        $error = 'Please select a room type.';

    // ========================================================
    // VALIDATE PRICE
    // ========================================================

    } elseif (
        $pricePerNight === '' ||
        !is_numeric($pricePerNight) ||
        (float)$pricePerNight < 0
    ) {

        $error = 'Please enter a valid room price.';

    // ========================================================
    // VALIDATE STATUS
    // ========================================================

    } elseif (
        !in_array(
            $status,
            ['available', 'occupied', 'maintenance'],
            true
        )
    ) {

        $error = 'Invalid room status.';

    // ========================================================
    // VALIDATE IMAGE EXISTS
    // ========================================================

    } elseif (
        !isset($_FILES['image']) ||
        $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE
    ) {

        $error = 'Please select a room picture.';

    } else {

        // ====================================================
        // CHECK DUPLICATE ROOM NUMBER
        // ====================================================

        $checkSql = '
            SELECT id
            FROM rooms
            WHERE room_number = $1
            LIMIT 1
        ';

        $checkResult = pg_query_params(
            $pgConnection,
            $checkSql,
            [$roomNumber]
        );

        if ($checkResult === false) {

            $error = 'Database error while checking room number: '
                . pg_last_error($pgConnection);

        } elseif (pg_num_rows($checkResult) > 0) {

            $error = 'Room number already exists. Please use another room number.';

        } else {

            // =================================================
            // FILE INFORMATION
            // =================================================

            $file = $_FILES['image'];

            // =================================================
            // UPLOAD ERROR
            // =================================================

            if ($file['error'] !== UPLOAD_ERR_OK) {

                $error = 'There was an error uploading the room picture.';

            // =================================================
            // FILE SIZE
            // =================================================

            } elseif ($file['size'] > 5 * 1024 * 1024) {

                $error = 'Image size must not exceed 5 MB.';

            // =================================================
            // EMPTY FILE
            // =================================================

            } elseif ($file['size'] <= 0) {

                $error = 'The selected image is empty.';

            } else {

                // =================================================
                // ALLOWED EXTENSIONS
                // =================================================

                $allowedExtensions = [
                    'jpg',
                    'jpeg',
                    'png',
                    'webp'
                ];

                $extension = strtolower(
                    pathinfo(
                        $file['name'],
                        PATHINFO_EXTENSION
                    )
                );

                if (!in_array(
                    $extension,
                    $allowedExtensions,
                    true
                )) {

                    $error =
                        'Invalid image format. Please upload JPG, JPEG, PNG or WEBP.';

                } else {

                    // =================================================
                    // VERIFY ACTUAL IMAGE
                    // =================================================

                    $imageInfo = @getimagesize($file['tmp_name']);

                    if ($imageInfo === false) {

                        $error =
                            'The selected file is not a valid image.';

                    } else {

                        // =================================================
                        // ALLOWED MIME TYPES
                        // =================================================

                        $allowedMimeTypes = [
                            'image/jpeg',
                            'image/png',
                            'image/webp'
                        ];

                        $mimeType = $imageInfo['mime'] ?? '';

                        if (!in_array(
                            $mimeType,
                            $allowedMimeTypes,
                            true
                        )) {

                            $error = 'Invalid image type.';

                        } else {

                            // =================================================
                            // SAFE ROOM NUMBER
                            // =================================================

                            $cleanRoomNumber = preg_replace(
                                '/[^a-zA-Z0-9_-]/',
                                '',
                                $roomNumber
                            );

                            if ($cleanRoomNumber === '') {
                                $cleanRoomNumber = 'room';
                            }

                            // =================================================
                            // CREATE UNIQUE FILE NAME
                            // =================================================

                            try {

                                $randomString = bin2hex(
                                    random_bytes(8)
                                );

                            } catch (Exception $exception) {

                                $randomString =
                                    uniqid('', true);
                            }

                            $uniqueName =
                                'room_' .
                                $cleanRoomNumber .
                                '_' .
                                date('Ymd_His') .
                                '_' .
                                $randomString .
                                '.' .
                                $extension;

                            $destination =
                                $uploadDir .
                                $uniqueName;

                            // =================================================
                            // MOVE IMAGE
                            // =================================================

                            if (!move_uploaded_file(
                                $file['tmp_name'],
                                $destination
                            )) {

                                $error =
                                    'Unable to save the uploaded room picture.';

                            } else {

                                // =================================================
                                // INSERT ROOM INTO POSTGRESQL
                                // =================================================

                                $insertSql = '
    INSERT INTO rooms
    (
        room_number,
        room_type,
        price_per_night,
        status,
        description,
        image
    )
    VALUES
    (
        $1,
        $2,
        $3,
        $4,
        $5,
        $6
    )
';
                              $insertParams = [
    $roomNumber,
    $roomType,
    (float)$pricePerNight,
    $status,
    $description,
    $uniqueName
];

                                $insertResult = pg_query_params(
                                    $pgConnection,
                                    $insertSql,
                                    $insertParams
                                );

                                // =================================================
                                // SUCCESS
                                // =================================================

                                if ($insertResult !== false) {

                                    header(
                                        'Location: view.php?success=added'
                                    );

                                    exit;

                                } else {

                                    // ---------------------------------------------
                                    // DELETE UPLOADED FILE IF DATABASE FAILED
                                    // ---------------------------------------------

                                    if (file_exists($destination)) {
                                        unlink($destination);
                                    }

                                    $error =
                                        'Error adding room to PostgreSQL: ' .
                                        pg_last_error($pgConnection);
                                }
                            }
                        }
                    }
                }
            }
        }
    }
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

    <title>Add Room | Suva Hotel</title>

    <style>

        * {
            box-sizing: border-box;
        }

        :root {
            --gold: #d4af37;
            --gold-light: #f4d675;
            --gold-dark: #a98218;

            --bg: #07090d;
            --panel: rgba(17, 20, 27, 0.94);
            --panel-2: rgba(25, 29, 38, 0.92);

            --text: #f4f4f4;
            --muted: #9ba3b2;

            --border: rgba(212, 175, 55, 0.22);

            --danger: #ff6b6b;
        }

        html {
            min-height: 100%;
        }

        body {
            margin: 0;
            min-height: 100vh;

            font-family:
                Inter,
                "Segoe UI",
                Arial,
                sans-serif;

            color: var(--text);

            background:
                linear-gradient(
                    rgba(4, 6, 10, 0.88),
                    rgba(4, 6, 10, 0.94)
                ),
                url("../assets/image/hotel-lighting.jpg");

            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }

        /* =====================================================
           HEADER
        ===================================================== */

        .header {
            min-height: 76px;

            padding: 0 34px;

            display: flex;
            align-items: center;
            justify-content: space-between;

            background:
                rgba(7, 9, 13, 0.92);

            border-bottom:
                1px solid var(--border);

            backdrop-filter: blur(18px);

            box-shadow:
                0 8px 30px rgba(0, 0, 0, 0.35);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 13px;
        }

        .logo {
            width: 46px;
            height: 46px;

            display: flex;
            align-items: center;
            justify-content: center;

            border-radius: 13px;

            background:
                linear-gradient(
                    135deg,
                    #1a1d25,
                    #0d0f14
                );

            border:
                1px solid var(--border);

            box-shadow:
                0 0 25px rgba(212, 175, 55, 0.10);

            font-size: 23px;
        }

        .brand h1 {
            margin: 0;

            font-size: 19px;

            letter-spacing: 2px;

            color: var(--gold-light);
        }

        .brand p {
            margin: 3px 0 0;

            font-size: 11px;

            color: var(--muted);

            letter-spacing: 0.5px;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;

            padding: 10px 16px;

            color: #eee;

            text-decoration: none;

            border-radius: 10px;

            background:
                rgba(255,255,255,0.05);

            border:
                1px solid rgba(255,255,255,0.10);

            transition: 0.25s;
        }

        .back-btn:hover {
            color: var(--gold-light);

            border-color:
                var(--border);

            background:
                rgba(212,175,55,0.08);

            transform:
                translateY(-2px);
        }

        /* =====================================================
           CONTAINER
        ===================================================== */

        .container {
            width: min(900px, 92%);

            margin: 38px auto 60px;
        }

        /* =====================================================
           CARD
        ===================================================== */

        .card {
            padding: 34px;

            background:
                linear-gradient(
                    145deg,
                    rgba(22, 25, 33, 0.96),
                    rgba(11, 13, 18, 0.96)
                );

            border:
                1px solid var(--border);

            border-radius: 22px;

            box-shadow:
                0 30px 80px rgba(0,0,0,0.48);

            backdrop-filter:
                blur(20px);
        }

        .card-header {
            text-align: center;

            margin-bottom: 28px;
        }

        .card-header .icon {
            width: 68px;
            height: 68px;

            margin: 0 auto 13px;

            display: flex;
            align-items: center;
            justify-content: center;

            border-radius: 18px;

            background:
                linear-gradient(
                    135deg,
                    rgba(212,175,55,0.16),
                    rgba(212,175,55,0.04)
                );

            border:
                1px solid var(--border);

            font-size: 31px;

            box-shadow:
                0 12px 35px rgba(212,175,55,0.08);
        }

        .card-header h2 {
            margin: 0;

            font-size: 28px;

            color: #fff;
        }

        .card-header p {
            margin: 8px 0 0;

            color: var(--muted);

            font-size: 13px;
        }

        /* =====================================================
           ERROR
        ===================================================== */

        .error {
            margin-bottom: 22px;

            padding: 13px 16px;

            border-radius: 11px;

            color: #ffb4b4;

            background:
                rgba(255, 70, 70, 0.08);

            border:
                1px solid rgba(255, 90, 90, 0.28);

            font-size: 13px;

            line-height: 1.5;
        }

        /* =====================================================
           INFO
        ===================================================== */

        .info {
            margin-bottom: 24px;

            padding: 15px 17px;

            border-radius: 12px;

            color: #cbd0da;

            background:
                rgba(212,175,55,0.055);

            border:
                1px solid rgba(212,175,55,0.16);

            border-left:
                3px solid var(--gold);

            font-size: 12px;

            line-height: 1.65;
        }

        .info strong {
            color: var(--gold-light);
        }

        /* =====================================================
           FORM
        ===================================================== */

        .form-row {
            display: grid;

            grid-template-columns:
                1fr 1fr;

            gap: 18px;
        }

        .form-group {
            margin-bottom: 19px;
        }

        label {
            display: block;

            margin-bottom: 8px;

            font-size: 13px;

            font-weight: 700;

            color: #dce0e7;
        }

        input,
        select,
        textarea {
            width: 100%;

            border: 1px solid rgba(255,255,255,0.10);

            outline: none;

            border-radius: 10px;

            padding: 13px 14px;

            color: #f3f4f6;

            background:
                rgba(5,7,11,0.72);

            font-family: inherit;

            font-size: 14px;

            transition:
                0.25s;
        }

        input::placeholder,
        textarea::placeholder {
            color: #687080;
        }

        input:hover,
        select:hover,
        textarea:hover {
            border-color:
                rgba(212,175,55,0.25);
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color:
                rgba(212,175,55,0.65);

            box-shadow:
                0 0 0 3px
                rgba(212,175,55,0.08);
        }

        select option {
            background: #11141a;

            color: #fff;
        }

        textarea {
            min-height: 115px;

            resize: vertical;

            line-height: 1.5;
        }

        /* =====================================================
           FILE UPLOAD
        ===================================================== */

        .upload-box {
            padding: 22px;

            text-align: center;

            border:
                1px dashed rgba(212,175,55,0.30);

            border-radius: 14px;

            background:
                rgba(212,175,55,0.025);

            transition:
                0.25s;
        }

        .upload-box:hover {
            border-color:
                rgba(212,175,55,0.60);

            background:
                rgba(212,175,55,0.045);
        }

        .upload-box input[type="file"] {
            width: 100%;

            padding: 9px;

            cursor: pointer;

            background:
                rgba(0,0,0,0.20);
        }

        .upload-help {
            margin-top: 10px;

            color: #858d9c;

            font-size: 11px;

            line-height: 1.6;
        }

        /* =====================================================
           PREVIEW
        ===================================================== */

        .preview {
            display: none;

            margin-top: 18px;

            padding: 14px;

            border-radius: 13px;

            background:
                rgba(0,0,0,0.25);

            border:
                1px solid rgba(255,255,255,0.08);
        }

        .preview strong {
            color: var(--gold-light);

            font-size: 12px;
        }

        .preview img {
            display: block;

            width: 100%;

            max-width: 480px;

            height: 250px;

            margin: 12px auto;

            object-fit: cover;

            border-radius: 10px;

            border:
                1px solid rgba(212,175,55,0.15);
        }

        .preview-name {
            color: #8d95a4;

            font-size: 11px;

            word-break: break-all;
        }

        /* =====================================================
           BUTTONS
        ===================================================== */

        .buttons {
            display: flex;

            gap: 12px;

            margin-top: 27px;
        }

        .button,
        button {
            min-height: 46px;

            border: none;

            border-radius: 10px;

            padding: 12px 20px;

            font-family: inherit;

            font-size: 13px;

            font-weight: 700;

            cursor: pointer;

            text-decoration: none;

            transition:
                0.25s;
        }

        .submit {
            flex: 1;

            color: #111;

            background:
                linear-gradient(
                    135deg,
                    var(--gold-light),
                    var(--gold),
                    var(--gold-dark)
                );

            box-shadow:
                0 10px 28px
                rgba(212,175,55,0.15);
        }

        .submit:hover {
            transform:
                translateY(-2px);

            box-shadow:
                0 14px 34px
                rgba(212,175,55,0.25);
        }

        .cancel {
            display: flex;

            align-items: center;
            justify-content: center;

            color: #d4d8df;

            background:
                rgba(255,255,255,0.06);

            border:
                1px solid rgba(255,255,255,0.10);
        }

        .cancel:hover {
            color: #fff;

            background:
                rgba(255,255,255,0.10);

            transform:
                translateY(-2px);
        }

        /* =====================================================
           MOBILE
        ===================================================== */

        @media (max-width: 700px) {

            .header {
                padding: 0 18px;
            }

            .brand p {
                display: none;
            }

            .brand h1 {
                font-size: 16px;
            }

            .logo {
                width: 42px;
                height: 42px;
            }

            .back-btn {
                padding: 9px 12px;

                font-size: 12px;
            }

            .container {
                width: 94%;

                margin-top: 24px;
            }

            .card {
                padding: 23px 18px;

                border-radius: 17px;
            }

            .card-header h2 {
                font-size: 24px;
            }

            .form-row {
                grid-template-columns: 1fr;

                gap: 0;
            }

            .buttons {
                flex-direction: column-reverse;
            }

            .button,
            button {
                width: 100%;
            }

            .preview img {
                height: 210px;
            }
        }

    </style>

</head>

<body>

    <!-- =====================================================
         HEADER
    ====================================================== -->

    <header class="header">

        <div class="brand">

            <div class="logo">
                🏨
            </div>

            <div>

                <h1>
                    SUVA HOTEL
                </h1>

                <p>
                    Hotel Management System
                </p>

            </div>

        </div>

        <a
            href="view.php"
            class="back-btn"
        >
            ← Rooms
        </a>

    </header>


    <!-- =====================================================
         MAIN
    ====================================================== -->

    <main class="container">

        <section class="card">

            <div class="card-header">

                <div class="icon">
                    🛏️
                </div>

                <h2>
                    Add New Room
                </h2>

                <p>
                    Add a new room to your Suva Hotel
                </p>

            </div>


            <?php if ($error !== ''): ?>

                <div class="error">
                    ❌
                    <?php echo e($error); ?>
                </div>

            <?php endif; ?>


            <div class="info">

                <strong>
                    📸 Room Picture
                </strong>

                <br>

                Your room picture will be stored securely in
                <strong>rooms/uploads/</strong>.

                <br>

                Allowed formats:
                <strong>JPG, JPEG, PNG, WEBP</strong>

                &nbsp;•&nbsp;

                Maximum size:
                <strong>5 MB</strong>.

            </div>


            <!-- =================================================
                 FORM
            ================================================== -->

            <form
                method="POST"
                action=""
                enctype="multipart/form-data"
            >

                <!-- ROOM NUMBER + TYPE -->

                <div class="form-row">

                    <div class="form-group">

                        <label for="room_number">
                            🚪 Room Number
                        </label>

                        <input
                            type="text"
                            id="room_number"
                            name="room_number"
                            placeholder="Example: 101"
                            value="<?php echo e($roomNumber); ?>"
                            maxlength="30"
                            required
                        >

                    </div>


                    <div class="form-group">

                        <label for="room_type">
                            🛏️ Room Type
                        </label>

                       
<div style="display:flex; flex-direction:column; gap:8px;">
    <label for="room_type">Room Type</label>

    <input
        type="text"
        id="room_type"
        name="room_type"
        list="room_types"
        placeholder="Select or enter a room type"
        value="<?php echo e($roomType); ?>"
        maxlength="100"
        required
    >

    <datalist id="room_types">
        <option value="Single">
        <option value="Double">
        <option value="Deluxe">
        <option value="Family">
        <option value="Standard">
        <option value="Suite">
        <option value="Twin">
        <option value="Triple">
        <option value="Executive">
        <option value="Presidential">
        <option value="Honeymoon">
        <option value="Connecting Room">
    </datalist>
</div>

                    </div>

                </div>


                <!-- PRICE + STATUS -->

                <div class="form-row">

                    <div class="form-group">

                        <label for="price_per_night">
                            💰 Price Per Night (Rs.)
                        </label>

                        <input
                            type="number"
                            id="price_per_night"
                            name="price_per_night"
                            step="0.01"
                            min="0"
                            placeholder="Example: 3500"
                            value="<?php echo e($pricePerNight); ?>"
                            required
                        >

                    </div>


                    <div class="form-group">

                        <label for="status">
                            📊 Room Status
                        </label>

                        <select
                            id="status"
                            name="status"
                        >

                            <option
                                value="available"
                                <?php
                                echo $status === 'available'
                                    ? 'selected'
                                    : '';
                                ?>
                            >
                                🟢 Available
                            </option>

                            <option
                                value="occupied"
                                <?php
                                echo $status === 'occupied'
                                    ? 'selected'
                                    : '';
                                ?>
                            >
                                🟠 Occupied
                            </option>

                            <option
                                value="maintenance"
                                <?php
                                echo $status === 'maintenance'
                                    ? 'selected'
                                    : '';
                                ?>
                            >
                                🔧 Maintenance
                            </option>

                        </select>

                    </div>

                </div>


                <!-- DESCRIPTION -->

                <div class="form-group">

                    <label for="description">
                        📝 Description
                    </label>

                    <textarea
                        id="description"
                        name="description"
                        placeholder="Describe the room, facilities, bed type, view, etc."
                    ><?php echo e($description); ?></textarea>

                </div>


                <!-- IMAGE -->

                <div class="form-group">

                    <label for="image">
                        📸 Room Picture
                    </label>

                    <div class="upload-box">

                        <input
                            type="file"
                            name="image"
                            id="image"
                            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                            required
                        >

                        <div class="upload-help">

                            Select a clear picture of the room.

                            <br>

                            JPG, JPEG, PNG or WEBP —
                            maximum 5 MB.

                        </div>


                        <!-- PREVIEW -->

                        <div
                            class="preview"
                            id="preview"
                        >

                            <strong>
                                Selected Room Picture
                            </strong>

                            <img
                                id="previewImage"
                                src=""
                                alt="Room Preview"
                            >

                            <div
                                class="preview-name"
                                id="previewName"
                            ></div>

                        </div>

                    </div>

                </div>


                <!-- BUTTONS -->

                <div class="buttons">

                    <a
                        href="view.php"
                        class="button cancel"
                    >
                        ← Cancel
                    </a>

                    <button
                        type="submit"
                        class="submit"
                    >
                        ➕ Add Room
                    </button>

                </div>

            </form>

        </section>

    </main>


    <!-- =====================================================
         JAVASCRIPT
    ====================================================== -->

    <script>

        const imageInput =
            document.getElementById('image');

        const preview =
            document.getElementById('preview');

        const previewImage =
            document.getElementById('previewImage');

        const previewName =
            document.getElementById('previewName');


        if (imageInput) {

            imageInput.addEventListener(
                'change',
                function () {

                    const file =
                        this.files[0];

                    if (!file) {

                        preview.style.display =
                            'none';

                        return;
                    }


                    // =============================================
                    // SIZE CHECK
                    // =============================================

                    const maxSize =
                        5 * 1024 * 1024;

                    if (file.size > maxSize) {

                        alert(
                            'Image is too large. Maximum size is 5 MB.'
                        );

                        this.value = '';

                        preview.style.display =
                            'none';

                        return;
                    }


                    // =============================================
                    // TYPE CHECK
                    // =============================================

                    const allowedTypes = [
                        'image/jpeg',
                        'image/png',
                        'image/webp'
                    ];

                    if (!allowedTypes.includes(file.type)) {

                        alert(
                            'Please select a JPG, JPEG, PNG or WEBP image.'
                        );

                        this.value = '';

                        preview.style.display =
                            'none';

                        return;
                    }


                    // =============================================
                    // PREVIEW
                    // =============================================

                    const reader =
                        new FileReader();

                    reader.onload =
                        function (event) {

                            previewImage.src =
                                event.target.result;

                            previewName.textContent =
                                file.name;

                            preview.style.display =
                                'block';
                        };

                    reader.readAsDataURL(file);

                }
            );

        }

    </script>

</body>

</html>