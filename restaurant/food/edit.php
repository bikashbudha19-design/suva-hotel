
<?php
session_start();

require_once __DIR__ . '/../../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| POSTGRESQL CONNECTION
|--------------------------------------------------------------------------
| Your database.php must provide a PostgreSQL connection resource
| named $pgConnection.
*/

if (!isset($pgConnection) || !$pgConnection) {
    die("PostgreSQL connection is not available. Please check config/database.php.");
}

/*
|--------------------------------------------------------------------------
| GET FOOD ID
|--------------------------------------------------------------------------
*/

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    die("Invalid food ID.");
}

/*
|--------------------------------------------------------------------------
| IMAGE DIRECTORY
|--------------------------------------------------------------------------
*/

$imageDir = __DIR__ . '/images/';

if (!is_dir($imageDir)) {
    mkdir($imageDir, 0755, true);
}

/*
|--------------------------------------------------------------------------
| FETCH FOOD DETAILS
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT id, food_name, category, description, image, price, status
    FROM public.restaurant_foods
    WHERE id = $1
    LIMIT 1
";

$result = pg_query_params($pgConnection, $sql, [$id]);

if (!$result) {
    die("Database error: " . htmlspecialchars(pg_last_error($pgConnection)));
}

$food = pg_fetch_assoc($result);

if (!$food) {
    die("Food item not found.");
}

$food_name = $food['food_name'];
$category = $food['category'] ?? '';
$description = $food['description'] ?? '';
$oldImage = $food['image'] ?? '';
$price = $food['price'];
$status = $food['status'] ?? 'Available';

$message = '';
$messageType = '';

/*
|--------------------------------------------------------------------------
| UPDATE FOOD
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $food_name = trim($_POST['food_name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = trim($_POST['price'] ?? '');
    $status = $_POST['status'] ?? 'Available';

    $removeImage = isset($_POST['remove_image'])
        && $_POST['remove_image'] === '1';

    $newImageName = $oldImage;
    $uploadedNewImage = false;
    $imageToDelete = '';

    /*
    |----------------------------------------------------------------------
    | VALIDATION
    |----------------------------------------------------------------------
    */

    if ($food_name === '') {
        $message = 'Food name is required.';
        $messageType = 'error';

    } elseif ($price === '' || !is_numeric($price) || (float)$price < 0) {
        $message = 'Please enter a valid price.';
        $messageType = 'error';

    } elseif (!in_array($status, ['Available', 'Unavailable'], true)) {
        $message = 'Invalid food status.';
        $messageType = 'error';

    } else {

        /*
        |------------------------------------------------------------------
        | REMOVE IMAGE OR UPLOAD A NEW ONE
        |------------------------------------------------------------------
        */

        if ($removeImage) {
            $newImageName = null;
            $imageToDelete = $oldImage;
        }

        if (
            isset($_FILES['image']) &&
            $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE
        ) {

            if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                $message = 'There was an error uploading the image.';
                $messageType = 'error';

            } elseif ($_FILES['image']['size'] > 5 * 1024 * 1024) {
                $message = 'Image size must not exceed 5 MB.';
                $messageType = 'error';

            } else {

                $tmpName = $_FILES['image']['tmp_name'];
                $imageInfo = @getimagesize($tmpName);

                if ($imageInfo === false) {
                    $message = 'The uploaded file is not a valid image.';
                    $messageType = 'error';

                } else {

                    $mimeType = $imageInfo['mime'] ?? '';

                    $allowedTypes = [
                        'image/jpeg' => 'jpg',
                        'image/png'  => 'png',
                        'image/webp' => 'webp'
                    ];

                    if (!isset($allowedTypes[$mimeType])) {
                        $message = 'Only JPG, JPEG, PNG and WEBP images are allowed.';
                        $messageType = 'error';

                    } else {

                        $extension = $allowedTypes[$mimeType];

                        $safeName = preg_replace(
                            '/[^a-zA-Z0-9_-]/',
                            '_',
                            $food_name
                        );

                        $newFileName = strtolower($safeName)
                            . '_' . time()
                            . '_' . bin2hex(random_bytes(4))
                            . '.' . $extension;

                        $destination = $imageDir . $newFileName;

                        if (!move_uploaded_file($tmpName, $destination)) {
                            $message = 'Could not save the uploaded image.';
                            $messageType = 'error';

                        } else {
                            $newImageName = $newFileName;
                            $uploadedNewImage = true;

                            if ($oldImage !== '') {
                                $imageToDelete = $oldImage;
                            }
                        }
                    }
                }
            }
        }

        /*
        |------------------------------------------------------------------
        | SAVE CHANGES TO POSTGRESQL
        |------------------------------------------------------------------
        */

        if ($messageType !== 'error') {

            $updateSql = "
                UPDATE public.restaurant_foods
                SET
                    food_name = $1,
                    category = $2,
                    description = $3,
                    image = $4,
                    price = $5,
                    status = $6
                WHERE id = $7
            ";

            $updateResult = pg_query_params(
                $pgConnection,
                $updateSql,
                [
                    $food_name,
                    $category === '' ? null : $category,
                    $description === '' ? null : $description,
                    $newImageName === '' ? null : $newImageName,
                    (float)$price,
                    $status,
                    $id
                ]
            );

            if ($updateResult) {

                /*
                 * Delete the previous image only after the database
                 * update succeeds.
                 */
                if (
                    $imageToDelete !== '' &&
                    $imageToDelete !== $newImageName
                ) {
                    $oldPath = $imageDir . basename($imageToDelete);

                    if (is_file($oldPath)) {
                        @unlink($oldPath);
                    }
                }

                header('Location: view.php?success=updated');
                exit;

            } else {

                $message = 'Database error: '
                    . pg_last_error($pgConnection);

                $messageType = 'error';

                /*
                 * If saving failed, remove the newly uploaded file.
                 */
                if ($uploadedNewImage && $newImageName !== '') {
                    $newPath = $imageDir . basename($newImageName);

                    if (is_file($newPath)) {
                        @unlink($newPath);
                    }
                }
            }
        }
    }
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$imageUrl = '';

if ($oldImage !== '') {
    $imageUrl = 'images/' . rawurlencode(basename($oldImage));
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Food - Suva Hotel</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    background: #f4f7fb;
    color: #1f2937;
}

.container {
    max-width: 850px;
    margin: 40px auto;
    padding: 20px;
}

.header {
    background: linear-gradient(135deg, #7c3aed, #2563eb);
    color: white;
    padding: 30px;
    border-radius: 18px 18px 0 0;
}

.header h1 {
    margin: 0 0 8px;
}

.header p {
    margin: 0;
    opacity: .9;
}

.card {
    background: white;
    padding: 30px;
    border-radius: 0 0 18px 18px;
    box-shadow: 0 10px 35px rgba(0,0,0,.08);
}

.message {
    padding: 14px;
    border-radius: 10px;
    margin-bottom: 20px;
}

.error {
    background: #fee2e2;
    color: #991b1b;
}

label {
    display: block;
    font-weight: bold;
    margin-bottom: 7px;
}

.form-group {
    margin-bottom: 20px;
}

input:not([type="checkbox"]):not([type="file"]),
textarea,
select {
    width: 100%;
    padding: 13px;
    border: 1px solid #d1d5db;
    border-radius: 9px;
    font-size: 14px;
}

textarea {
    min-height: 110px;
    resize: vertical;
}

.current-image {
    margin-bottom: 15px;
}

.current-image img {
    width: 280px;
    max-width: 100%;
    height: 190px;
    object-fit: contain;
    background: #f8fafc;
    border-radius: 14px;
    box-shadow: 0 6px 20px rgba(0,0,0,.10);
}

.no-image {
    width: 280px;
    height: 190px;
    border-radius: 14px;
    background: #f3f4f6;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 60px;
    color: #9ca3af;
}

.file-box {
    border: 2px dashed #cbd5e1;
    padding: 20px;
    border-radius: 12px;
    background: #f8fafc;
}

.help {
    color: #6b7280;
    font-size: 12px;
    margin-top: 7px;
}

.preview {
    display: none;
    margin-top: 15px;
}

.preview img {
    width: 220px;
    max-width: 100%;
    height: 150px;
    object-fit: contain;
    background: #f8fafc;
    border-radius: 12px;
}

.remove-box {
    margin-top: 12px;
}

.remove-box label {
    display: inline;
    font-weight: normal;
}

.buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 25px;
}

.btn {
    padding: 13px 20px;
    border: 0;
    border-radius: 9px;
    text-decoration: none;
    cursor: pointer;
    font-weight: bold;
    font-size: 14px;
}

.btn-primary {
    background: #2563eb;
    color: white;
}

.btn-secondary {
    background: #e5e7eb;
    color: #374151;
}

@media (max-width: 600px) {
    .container {
        margin: 10px auto;
        padding: 12px;
    }

    .card {
        padding: 20px;
    }
}
</style>
</head>

<body>
<div class="container">

    <div class="header">
        <h1>✏️ Edit Food Item</h1>
        <p>Update food information, price, status or photo.</p>
    </div>

    <div class="card">

        <?php if ($message !== ''): ?>
            <div class="message error">
                ❌ <?php echo e($message); ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">

            <div class="form-group">
                <label for="food_name">Food Name *</label>
                <input
                    type="text"
                    id="food_name"
                    name="food_name"
                    value="<?php echo e($food_name); ?>"
                    maxlength="150"
                    required
                >
            </div>

            <div class="form-group">
                <label for="category">Category</label>
                <input
                    type="text"
                    id="category"
                    name="category"
                    value="<?php echo e($category); ?>"
                    maxlength="100"
                >
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description"><?php echo e($description); ?></textarea>
            </div>

            <div class="form-group">
                <label for="price">Price (NPR) *</label>
                <input
                    type="number"
                    id="price"
                    name="price"
                    value="<?php echo e($price); ?>"
                    min="0"
                    step="0.01"
                    required
                >
            </div>

            <div class="form-group">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="Available" <?php echo $status === 'Available' ? 'selected' : ''; ?>>
                        Available
                    </option>
                    <option value="Unavailable" <?php echo $status === 'Unavailable' ? 'selected' : ''; ?>>
                        Unavailable
                    </option>
                </select>
            </div>

            <div class="form-group">
                <label>📷 Current Food Photo</label>

                <div class="current-image">
                    <?php if ($imageUrl !== ''): ?>
                        <img
                            src="<?php echo e($imageUrl); ?>"
                            alt="Current food photo"
                        >
                    <?php else: ?>
                        <div class="no-image">🍽️</div>
                    <?php endif; ?>
                </div>

                <?php if ($oldImage !== ''): ?>
                    <div class="remove-box">
                        <input
                            type="checkbox"
                            name="remove_image"
                            value="1"
                            id="remove_image"
                        >
                        <label for="remove_image">Remove current photo</label>
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="image">📷 Upload New Photo</label>

                <div class="file-box">
                    <input
                        type="file"
                        name="image"
                        id="image"
                        accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                    >

                    <div class="help">
                        JPG, JPEG, PNG or WEBP. Maximum size: 5 MB.
                    </div>

                    <div class="preview" id="preview">
                        <img id="previewImage" src="" alt="New photo preview">
                    </div>
                </div>
            </div>

            <div class="buttons">
                <button type="submit" class="btn btn-primary">
                    💾 Update Food
                </button>

                <a href="view.php" class="btn btn-secondary">
                    ← Back
                </a>
            </div>

        </form>
    </div>
</div>

<script>
document.getElementById('image').addEventListener('change', function () {
    const file = this.files[0];

    if (!file) {
        return;
    }

    const allowed = ['image/jpeg', 'image/png', 'image/webp'];

    if (!allowed.includes(file.type)) {
        alert('Only JPG, JPEG, PNG and WEBP images are allowed.');
        this.value = '';
        return;
    }

    if (file.size > 5 * 1024 * 1024) {
        alert('Image size must not exceed 5 MB.');
        this.value = '';
        return;
    }

    const reader = new FileReader();

    reader.onload = function (event) {
        document.getElementById('previewImage').src = event.target.result;
        document.getElementById('preview').style.display = 'block';
    };

    reader.readAsDataURL(file);
});
</script>

</body>
</html>