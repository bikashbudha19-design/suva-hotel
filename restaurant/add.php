
<?php

session_start();

/* =========================================================
   SUVA HOTEL
   RESTAURANT - ADD FOOD
   PostgreSQL Version

   File:
   C:\xampp\htdocs\Suva_hotel\restaurant\add.php

   Database:
   C:\xampp\htdocs\Suva_hotel\config\database.php
========================================================= */


/* =========================================================
   DATABASE CONNECTION
========================================================= */

require_once __DIR__ . '/../config/database.php';


/* =========================================================
   LOGIN PROTECTION
========================================================= */

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}


/* =========================================================
   CHECK DATABASE CONNECTION
========================================================= */

if (!isset($pgConnection) || !$pgConnection) {
    die("Database connection is not available.");
}


/* =========================================================
   VARIABLES
========================================================= */

$message = "";
$messageType = "";

$food_name = "";
$category = "";
$description = "";
$price = "";
$status = "Available";

$imageName = null;


/* =========================================================
   IMAGE DIRECTORY
========================================================= */

$imageDir = __DIR__ . "/images/";


if (!is_dir($imageDir)) {

    if (!mkdir($imageDir, 0755, true)) {

        die("Unable to create image directory.");

    }
}


/* =========================================================
   CHECK WHETHER DESCRIPTION COLUMN EXISTS
=========================================================

   Your current table does NOT have description.

   This query checks automatically.

   If description exists:
       it will be saved.

   If description does not exist:
       the INSERT will simply ignore it.

========================================================= */

$descriptionColumnExists = false;


$columnCheckSql = "
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = 'public'
      AND table_name = 'restaurant_foods'
      AND column_name = 'description'
    LIMIT 1
";


$columnCheckResult = pg_query(
    $pgConnection,
    $columnCheckSql
);


if ($columnCheckResult) {

    if (pg_num_rows($columnCheckResult) > 0) {

        $descriptionColumnExists = true;

    }
}


/* =========================================================
   FORM SUBMISSION
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {


    /* =====================================================
       GET FORM VALUES
    ===================================================== */

    $food_name = trim(
        $_POST["food_name"] ?? ""
    );

    $category = trim(
        $_POST["category"] ?? ""
    );

    $description = trim(
        $_POST["description"] ?? ""
    );

    $price = trim(
        $_POST["price"] ?? ""
    );

    $status = trim(
        $_POST["status"] ?? "Available"
    );


    /* =====================================================
       VALIDATE FOOD NAME
    ===================================================== */

    if ($food_name === "") {

        $message = "Food name is required.";
        $messageType = "error";

    }


    /* =====================================================
       VALIDATE PRICE
    ===================================================== */

    elseif (
        $price === "" ||
        !is_numeric($price) ||
        (float)$price < 0
    ) {

        $message = "Please enter a valid price.";
        $messageType = "error";

    }


    /* =====================================================
       VALIDATE STATUS
    ===================================================== */

    elseif (
        !in_array(
            $status,
            ["Available", "Unavailable"],
            true
        )
    ) {

        $message = "Invalid food status.";
        $messageType = "error";

    }


    /* =====================================================
       IMAGE PROCESSING
    ===================================================== */

    else {


        if (
            isset($_FILES["image"]) &&
            $_FILES["image"]["error"] !== UPLOAD_ERR_NO_FILE
        ) {


            /* =============================================
               CHECK UPLOAD ERROR
            ============================================= */

            if (
                $_FILES["image"]["error"] !==
                UPLOAD_ERR_OK
            ) {

                $message =
                    "There was an error uploading the image.";

                $messageType = "error";

            }


            /* =============================================
               CHECK FILE SIZE
            ============================================= */

            elseif (
                $_FILES["image"]["size"] >
                5 * 1024 * 1024
            ) {

                $message =
                    "Image size must not exceed 5 MB.";

                $messageType = "error";

            }


            else {

                $tmpName =
                    $_FILES["image"]["tmp_name"];


                /* =========================================
                   CHECK REAL IMAGE
                ========================================= */

                $imageInfo =
                    @getimagesize($tmpName);


                if ($imageInfo === false) {

                    $message =
                        "The uploaded file is not a valid image.";

                    $messageType = "error";

                }


                else {


                    /* =====================================
                       ALLOWED IMAGE TYPES
                    ===================================== */

                    $allowedMimeTypes = [
                        "image/jpeg",
                        "image/png",
                        "image/webp"
                    ];


                    $mimeType =
                        $imageInfo["mime"];


                    if (
                        !in_array(
                            $mimeType,
                            $allowedMimeTypes,
                            true
                        )
                    ) {

                        $message =
                            "Only JPG, JPEG, PNG and WEBP images are allowed.";

                        $messageType = "error";

                    }


                    else {


                        /* =================================
                           DETERMINE EXTENSION
                        ================================= */

                        switch ($mimeType) {

                            case "image/jpeg":

                                $extension = "jpg";

                                break;


                            case "image/png":

                                $extension = "png";

                                break;


                            case "image/webp":

                                $extension = "webp";

                                break;


                            default:

                                $extension = "";

                                break;
                        }


                        if ($extension === "") {

                            $message =
                                "Invalid image format.";

                            $messageType = "error";

                        }


                        else {


                            /* =============================
                               CREATE SAFE FOOD NAME
                            ============================= */

                            $safeName = preg_replace(
                                "/[^a-zA-Z0-9_-]/",
                                "_",
                                $food_name
                            );


                            if (
                                $safeName === null ||
                                $safeName === ""
                            ) {

                                $safeName = "food";

                            }


                            /* =============================
                               UNIQUE IMAGE NAME
                            ============================= */

                            try {

                                $randomString =
                                    bin2hex(
                                        random_bytes(5)
                                    );

                            } catch (Exception $e) {

                                $randomString =
                                    uniqid();

                            }


                            $imageName =
                                strtolower($safeName)
                                . "_"
                                . time()
                                . "_"
                                . $randomString
                                . "."
                                . $extension;


                            $destination =
                                $imageDir . $imageName;


                            /* =============================
                               MOVE IMAGE
                            ============================= */

                            if (
                                !move_uploaded_file(
                                    $tmpName,
                                    $destination
                                )
                            ) {

                                $message =
                                    "Could not save the uploaded image.";

                                $messageType = "error";

                                $imageName = null;

                            }

                        }

                    }

                }

            }

        }


        /* =================================================
           INSERT FOOD INTO DATABASE
        ================================================= */

        if ($messageType !== "error") {


            $priceValue =
                (float)$price;


            /* =============================================
               DESCRIPTION COLUMN EXISTS
            ============================================== */

            if ($descriptionColumnExists) {


                $sql = "
                    INSERT INTO restaurant_foods
                    (
                        food_name,
                        category,
                        description,
                        image,
                        price,
                        status
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
                ";


                $params = [

                    $food_name,

                    $category !== ""
                        ? $category
                        : null,

                    $description !== ""
                        ? $description
                        : null,

                    $imageName,

                    $priceValue,

                    $status

                ];

            }


            /* =============================================
               DESCRIPTION COLUMN DOES NOT EXIST
            ============================================== */

            else {


                $sql = "
                    INSERT INTO restaurant_foods
                    (
                        food_name,
                        category,
                        image,
                        price,
                        status
                    )
                    VALUES
                    (
                        $1,
                        $2,
                        $3,
                        $4,
                        $5
                    )
                ";


                $params = [

                    $food_name,

                    $category !== ""
                        ? $category
                        : null,

                    $imageName,

                    $priceValue,

                    $status

                ];

            }


            /* =============================================
               EXECUTE POSTGRESQL QUERY
            ============================================== */

            $result = pg_query_params(
                $pgConnection,
                $sql,
                $params
            );


            /* =============================================
               SUCCESS
            ============================================== */

            if ($result) {

                header(
                    "Location: view.php?success=added"
                );

                exit;

            }


            /* =============================================
               DATABASE ERROR
            ============================================== */

            else {


                /* -----------------------------------------
                   Delete uploaded image if database failed
                ----------------------------------------- */

                if (
                    $imageName !== null &&
                    file_exists(
                        $imageDir . $imageName
                    )
                ) {

                    @unlink(
                        $imageDir . $imageName
                    );

                }


                $message =
                    "Database Error: " .
                    pg_last_error(
                        $pgConnection
                    );

                $messageType = "error";

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

<title>
    Add Food Item - Suva Hotel
</title>


<style>

/* =========================================================
   RESET
========================================================= */

* {

    box-sizing: border-box;

}


/* =========================================================
   BODY
========================================================= */

body {

    margin: 0;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    background:
        #f4f7fb;

    color:
        #1f2937;

}


/* =========================================================
   CONTAINER
========================================================= */

.container {

    max-width:
        850px;

    margin:
        40px auto;

    padding:
        20px;

}


/* =========================================================
   HEADER
========================================================= */

.header {

    background:
        linear-gradient(
            135deg,
            #1d4ed8,
            #2563eb
        );

    color:
        white;

    padding:
        30px;

    border-radius:
        18px 18px 0 0;

}


.header h1 {

    margin:
        0 0 8px;

    font-size:
        28px;

}


.header p {

    margin:
        0;

    opacity:
        0.9;

}


/* =========================================================
   CARD
========================================================= */

.card {

    background:
        white;

    padding:
        30px;

    border-radius:
        0 0 18px 18px;

    box-shadow:
        0 10px 35px
        rgba(0, 0, 0, 0.08);

}


/* =========================================================
   MESSAGE
========================================================= */

.message {

    padding:
        14px 16px;

    border-radius:
        10px;

    margin-bottom:
        20px;

    font-weight:
        bold;

}


.message.error {

    background:
        #fee2e2;

    color:
        #991b1b;

}


/* =========================================================
   FORM GROUP
========================================================= */

.form-group {

    margin-bottom:
        20px;

}


/* =========================================================
   LABEL
========================================================= */

label {

    display:
        block;

    font-weight:
        bold;

    margin-bottom:
        7px;

}


.required {

    color:
        red;

}


/* =========================================================
   INPUTS
========================================================= */

input,
textarea,
select {

    width:
        100%;

    padding:
        13px 14px;

    border:
        1px solid #d1d5db;

    border-radius:
        9px;

    font-size:
        14px;

    outline:
        none;

    background:
        white;

}


input:focus,
textarea:focus,
select:focus {

    border-color:
        #2563eb;

    box-shadow:
        0 0 0 3px
        rgba(37, 99, 235, 0.1);

}


textarea {

    min-height:
        110px;

    resize:
        vertical;

}


/* =========================================================
   FILE BOX
========================================================= */

.file-box {

    border:
        2px dashed #cbd5e1;

    padding:
        25px;

    border-radius:
        12px;

    text-align:
        center;

    background:
        #f8fafc;

}


.file-box input {

    border:
        0;

    background:
        transparent;

}


/* =========================================================
   HELP TEXT
========================================================= */

.help {

    color:
        #6b7280;

    font-size:
        12px;

    margin-top:
        8px;

}


/* =========================================================
   IMAGE PREVIEW
========================================================= */

.preview {

    display:
        none;

    margin-top:
        15px;

}


.preview img {

    width:
        220px;

    height:
        160px;

    object-fit:
        cover;

    border-radius:
        12px;

    box-shadow:
        0 5px 20px
        rgba(0, 0, 0, 0.15);

}


/* =========================================================
   BUTTONS
========================================================= */

.buttons {

    display:
        flex;

    gap:
        12px;

    margin-top:
        25px;

}


.btn {

    display:
        inline-block;

    padding:
        13px 20px;

    border-radius:
        9px;

    text-decoration:
        none;

    border:
        0;

    cursor:
        pointer;

    font-weight:
        bold;

    font-size:
        14px;

}


.btn-primary {

    background:
        #2563eb;

    color:
        white;

}


.btn-primary:hover {

    background:
        #1d4ed8;

}


.btn-secondary {

    background:
        #e5e7eb;

    color:
        #374151;

}


.btn-secondary:hover {

    background:
        #d1d5db;

}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 600px) {

    .container {

        margin:
            10px auto;

        padding:
            10px;

    }


    .header {

        padding:
            22px;

    }


    .header h1 {

        font-size:
            23px;

    }


    .card {

        padding:
            20px;

    }


    .buttons {

        flex-direction:
            column;

    }


    .btn {

        text-align:
            center;

    }

}

</style>

</head>


<body>


<div class="container">


    <!-- =====================================================
         HEADER
    ====================================================== -->

    <div class="header">

        <h1>
            🍽️ Add Food Item
        </h1>

        <p>
            Add a new restaurant food item with its photo,
            price and availability.
        </p>

    </div>


    <!-- =====================================================
         FORM CARD
    ====================================================== -->

    <div class="card">


        <?php if ($message !== ""): ?>

            <div class="message error">

                ❌

                <?php
                echo htmlspecialchars(
                    $message
                );
                ?>

            </div>

        <?php endif; ?>


        <!-- =================================================
             ADD FOOD FORM
        ================================================== -->

        <form
            method="POST"
            enctype="multipart/form-data"
        >


            <!-- =============================================
                 FOOD NAME
            ============================================== -->

            <div class="form-group">

                <label for="food_name">

                    Food Name

                    <span class="required">
                        *
                    </span>

                </label>


                <input
                    type="text"
                    id="food_name"
                    name="food_name"
                    value="<?php
                        echo htmlspecialchars(
                            $food_name
                        );
                    ?>"
                    maxlength="150"
                    placeholder="Example: Chicken Momo"
                    required
                >

            </div>


            <!-- =============================================
                 CATEGORY
            ============================================== -->

            <div class="form-group">

                <label for="category">

                    Category

                </label>


                <input
                    type="text"
                    id="category"
                    name="category"
                    value="<?php
                        echo htmlspecialchars(
                            $category
                        );
                    ?>"
                    maxlength="100"
                    placeholder="Example: Momo, Chinese, Nepali"
                >

            </div>


            <!-- =============================================
                 DESCRIPTION
            ============================================== -->

            <div class="form-group">

                <label for="description">

                    Description

                </label>


                <textarea
                    id="description"
                    name="description"
                    placeholder="Enter food description..."
                ><?php
                    echo htmlspecialchars(
                        $description
                    );
                ?></textarea>


                <?php if (!$descriptionColumnExists): ?>

                    <div class="help">

                        Note: Your current database does not
                        have a description column, so this
                        description will not be saved.

                    </div>

                <?php endif; ?>

            </div>


            <!-- =============================================
                 PRICE
            ============================================== -->

            <div class="form-group">

                <label for="price">

                    Price

                    <span class="required">
                        *
                    </span>

                </label>


                <input
                    type="number"
                    id="price"
                    name="price"
                    value="<?php
                        echo htmlspecialchars(
                            $price
                        );
                    ?>"
                    min="0"
                    step="0.01"
                    placeholder="Example: 250"
                    required
                >

            </div>


            <!-- =============================================
                 STATUS
            ============================================== -->

            <div class="form-group">

                <label for="status">

                    Status

                </label>


                <select
                    id="status"
                    name="status"
                >

                    <option
                        value="Available"
                        <?php
                        echo
                            $status === "Available"
                            ? "selected"
                            : "";
                        ?>
                    >

                        Available

                    </option>


                    <option
                        value="Unavailable"
                        <?php
                        echo
                            $status === "Unavailable"
                            ? "selected"
                            : "";
                        ?>
                    >

                        Unavailable

                    </option>

                </select>

            </div>


            <!-- =============================================
                 IMAGE
            ============================================== -->

            <div class="form-group">

                <label for="image">

                    📷 Food Photo

                </label>


                <div class="file-box">


                    <input
                        type="file"
                        id="image"
                        name="image"
                        accept="
                            .jpg,
                            .jpeg,
                            .png,
                            .webp,
                            image/jpeg,
                            image/png,
                            image/webp
                        "
                    >


                    <div class="help">

                        JPG, JPEG, PNG or WEBP.

                        Maximum size:
                        5 MB.

                    </div>


                    <!-- IMAGE PREVIEW -->

                    <div
                        class="preview"
                        id="preview"
                    >

                        <img
                            id="previewImage"
                            src=""
                            alt="Food Image Preview"
                        >

                    </div>


                </div>

            </div>


            <!-- =============================================
                 BUTTONS
            ============================================== -->

            <div class="buttons">


                <button
                    type="submit"
                    class="btn btn-primary"
                >

                    ➕ Add Food

                </button>


                <a
                    href="view.php"
                    class="btn btn-secondary"
                >

                    ← Back

                </a>


            </div>


        </form>


    </div>


</div>


<!-- =========================================================
     IMAGE PREVIEW JAVASCRIPT
========================================================= -->

<script>

const imageInput =
    document.getElementById("image");


const preview =
    document.getElementById("preview");


const previewImage =
    document.getElementById("previewImage");


imageInput.addEventListener(
    "change",
    function () {


        const file =
            this.files[0];


        /* ================================================
           NO FILE
        ================================================= */

        if (!file) {

            preview.style.display =
                "none";

            previewImage.src =
                "";

            return;

        }


        /* ================================================
           ALLOWED TYPES
        ================================================= */

        const allowedTypes = [

            "image/jpeg",
            "image/png",
            "image/webp"

        ];


        if (
            !allowedTypes.includes(
                file.type
            )
        ) {

            alert(
                "Only JPG, JPEG, PNG and WEBP images are allowed."
            );

            this.value =
                "";

            preview.style.display =
                "none";

            previewImage.src =
                "";

            return;

        }


        /* ================================================
           MAXIMUM FILE SIZE
        ================================================= */

        if (
            file.size >
            5 * 1024 * 1024
        ) {

            alert(
                "Image size must not exceed 5 MB."
            );

            this.value =
                "";

            preview.style.display =
                "none";

            previewImage.src =
                "";

            return;

        }


        /* ================================================
           SHOW IMAGE PREVIEW
        ================================================= */

        const reader =
            new FileReader();


        reader.onload =
            function (event) {

                previewImage.src =
                    event.target.result;

                preview.style.display =
                    "block";

            };


        reader.readAsDataURL(file);

    }
);

</script>


</body>

</html>
