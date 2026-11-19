
<?php
// ============================================================
// SUVA HOTEL - PUBLIC WEBSITE
// Complete PostgreSQL-compatible index.php
// ============================================================

session_start();
require_once __DIR__ . '/config/database.php';

// ---------- DATABASE CONNECTION ----------
if (!isset($pgConnection) || !$pgConnection) {
    die("Database connection failed. Please check config/database.php.");
}

// ---------- HELPER FUNCTIONS ----------
function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function projectUrl($path = '') {
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    if ($base === '/' || $base === '.') {
        $base = '';
    }
    return $base . '/' . ltrim($path, '/');
}

function roomImage($filename) {
    $filename = basename(str_replace('\\', '/', (string)$filename));

    if ($filename !== '') {
        $filePath = __DIR__ . '/rooms/uploads/' . $filename;

        if (is_file($filePath)) {
            return projectUrl('rooms/uploads/' . rawurlencode($filename));
        }
    }

    return projectUrl('assets/images/about-hotel-room.png');
}

function foodImage($filename) {
    $filename = basename(str_replace('\\', '/', (string)$filename));

    if ($filename !== '') {
        $filePath = __DIR__ . '/restaurant/food/images/' . $filename;

        if (is_file($filePath)) {
            return projectUrl('restaurant/food/images/' . rawurlencode($filename));
        }

        $oldPath = __DIR__ . '/restaurant/food/uploads/' . $filename;

        if (is_file($oldPath)) {
            return projectUrl('restaurant/food/uploads/' . rawurlencode($filename));
        }
    }

    return projectUrl('assets/images/about-hotel-room.png');
}

// ---------- ROOM STATISTICS ----------
$totalRooms = 0;
$availableRooms = 0;
$bookedRooms = 0;
$rooms = [];

$result = @pg_query(
    $pgConnection,
    "SELECT COUNT(*) AS total,
            COUNT(*) FILTER (WHERE LOWER(TRIM(status)) = 'available') AS available,
            COUNT(*) FILTER (WHERE LOWER(TRIM(status)) = 'booked') AS booked
     FROM rooms"
);

if ($result) {
    $stats = pg_fetch_assoc($result);
    $totalRooms = (int)($stats['total'] ?? 0);
    $availableRooms = (int)($stats['available'] ?? 0);
    $bookedRooms = (int)($stats['booked'] ?? 0);
}

// ---------- LOAD ROOMS ----------
$result = @pg_query(
    $pgConnection,
    "SELECT id, room_number, room_type, price_per_night,
            status, description, image
     FROM rooms
     ORDER BY id DESC
     LIMIT 6"
);

if ($result) {
    while ($row = pg_fetch_assoc($result)) {
        $rooms[] = $row;
    }
}

// ---------- LOAD RESTAURANT FOODS ----------
$foods = [];

$result = @pg_query(
    $pgConnection,
    "SELECT id, food_name, category, image, price
     FROM restaurant_foods
     WHERE LOWER(TRIM(status)) = 'available'
     ORDER BY id DESC
     LIMIT 6"
);

if ($result) {
    while ($row = pg_fetch_assoc($result)) {
        $foods[] = $row;
    }
}

// ---------- ABOUT IMAGE ----------
// This is the newly generated image.
// Save it as assets/images/about-hotel-room.png.
$aboutImage = projectUrl('assets/images/about-hotel-room.png');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Suva Hotel | Comfort, Luxury & Hospitality</title>

    <meta name="description"
          content="Welcome to Suva Hotel. Enjoy comfortable rooms, delicious food and excellent hospitality.">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@500;600;700&display=swap"
          rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        :root {
            --gold: #c99b45;
            --gold-light: #e6c477;
            --dark: #171717;
            --dark-soft: #222222;
            --cream: #f8f6f1;
            --white: #ffffff;
            --text: #666666;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            color: var(--dark);
            background: var(--white);
            line-height: 1.7;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        img {
            display: block;
            max-width: 100%;
        }

        .container {
            width: min(1180px, 92%);
            margin: auto;
        }

        .section {
            padding: 90px 0;
        }

        .section-heading {
            text-align: center;
            margin-bottom: 45px;
        }

        .section-heading span {
            color: var(--gold);
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 3px;
            text-transform: uppercase;
        }

        .section-heading h2 {
            font-family: 'Playfair Display', serif;
            font-size: clamp(30px, 4vw, 43px);
            margin: 10px 0;
        }

        .section-heading p {
            color: var(--text);
            max-width: 620px;
            margin: auto;
        }

        /* NAVIGATION */
        .navbar {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: rgba(23, 23, 23, .97);
            color: white;
            box-shadow: 0 3px 20px rgba(0,0,0,.15);
        }

        .nav-inner {
            min-height: 78px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .logo {
            font-family: 'Playfair Display', serif;
            font-size: 27px;
            font-weight: 700;
            color: var(--gold-light);
        }

        .logo small {
            display: block;
            font-family: 'DM Sans', sans-serif;
            font-size: 9px;
            letter-spacing: 5px;
            text-align: center;
            color: white;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 25px;
            list-style: none;
        }

        .nav-links a {
            font-size: 13px;
            font-weight: 600;
            transition: .3s;
        }

        .nav-links a:hover {
            color: var(--gold-light);
        }

        .nav-book {
            background: var(--gold);
            color: #171717 !important;
            padding: 11px 19px;
            border-radius: 4px;
        }

        .menu-toggle {
            display: none;
            font-size: 25px;
            cursor: pointer;
            color: white;
        }

        /* HERO */
        

/* PREMIUM HERO WITH LIGHTING AND ANIMATION */

.hero {
    position: relative;
    min-height: 690px;
    display: flex;
    align-items: center;
    overflow: hidden;
    isolation: isolate;
    color: #fff;

    background:
        linear-gradient(
            90deg,
            rgba(15, 15, 15, 0.72) 0%,
            rgba(15, 15, 15, 0.42) 48%,
            rgba(15, 15, 15, 0.12) 100%
        ),
        url('assets/images/about-hotel-room.png') center 55% / cover no-repeat;
}

/* Warm golden light */
.hero::before {
    content: "";
    position: absolute;
    inset: 0;
    z-index: -1;
    background:
        radial-gradient(
            circle at 75% 30%,
            rgba(255, 209, 125, 0.25),
            transparent 35%
        ),
        linear-gradient(
            0deg,
            rgba(0, 0, 0, 0.30),
            transparent 55%
        );
    pointer-events: none;
}

/* Soft animated golden glow */
.hero::after {
    content: "";
    position: absolute;
    width: 450px;
    height: 450px;
    right: 5%;
    top: 10%;
    border-radius: 50%;
    background: rgba(231, 185, 101, 0.17);
    filter: blur(100px);
    z-index: -1;
    pointer-events: none;
}

.hero-content {
    max-width: 780px;
    padding: 120px 0;
    position: relative;
    z-index: 2;
}

.hero-label {
    display: inline-block;
    color: #f5ce82;
    letter-spacing: 5px;
    font-size: 14px;
    font-weight: 800;
    text-transform: uppercase;
    text-shadow: 0 2px 15px rgba(0,0,0,.3);
}

.hero h1 {
    font-family: 'Playfair Display', serif;
    font-size: clamp(43px, 6vw, 78px);
    line-height: 1.12;
    font-weight: 700;
    margin: 22px 0;
    color: #fff;
    text-shadow: 0 5px 30px rgba(0,0,0,.25);
}

.hero p {
    max-width: 620px;
    color: rgba(255,255,255,.94);
    font-size: 18px;
    line-height: 1.9;
    margin-bottom: 32px;
    text-shadow: 0 2px 12px rgba(0,0,0,.3);
}

.hero .btn {
    padding: 16px 30px;
    border-radius: 5px;
    transition: all .35s ease;
    box-shadow: 0 8px 25px rgba(0,0,0,.16);
}

.hero .btn-gold {
    background: linear-gradient(135deg, #e8bd69, #bd8d38);
    color: #171717;
}

.hero .btn-gold:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 35px rgba(219,171,82,.4);
}

.hero .btn-outline {
    border: 1px solid rgba(255,255,255,.8);
    background: rgba(255,255,255,.08);
    backdrop-filter: blur(8px);
}

.hero .btn-outline:hover {
    background: white;
    color: #171717;
    transform: translateY(-5px);
}

@media(max-width: 700px) {
    .hero {
        min-height: 600px;
        background-position: 60% center;
    }

    .hero-content {
        padding: 90px 0;
    }

    .hero h1 {
        font-size: 43px;
    }

    .hero p {
        font-size: 15px;
    }
}

        /* BOOKING BAR */
        .booking-wrap {
            position: relative;
            z-index: 2;
            margin-top: -45px;
        }

        .booking-box {
            background: white;
            padding: 25px;
            box-shadow: 0 10px 35px rgba(0,0,0,.12);
            border-radius: 7px;
        }

        .booking-form {
            display: grid;
            grid-template-columns: repeat(3, 1fr) auto;
            gap: 15px;
            align-items: end;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 7px;
        }

        .form-group input {
            width: 100%;
            padding: 13px;
            border: 1px solid #dddddd;
            border-radius: 4px;
            font-family: inherit;
        }

        /* ABOUT */
        .about-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            align-items: center;
            gap: 55px;
        }

        .about-image {
            position: relative;
        }

        .about-image img {
            width: 100%;
            height: 440px;
            object-fit: cover;
            border-radius: 8px;
            box-shadow: 15px 15px 0 #eee6d7;
        }

        .about-content .eyebrow {
            color: var(--gold);
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 3px;
        }

        .about-content h2 {
            font-family: 'Playfair Display', serif;
            font-size: 42px;
            line-height: 1.2;
            margin: 12px 0 20px;
        }

        .about-content p {
            color: var(--text);
            margin-bottom: 18px;
        }

        .about-features {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin: 25px 0;
        }

        .about-features div {
            font-weight: 700;
            font-size: 14px;
        }

        .about-features i {
            color: var(--gold);
            margin-right: 8px;
        }

        /* FACILITIES */
        .facilities {
            background: var(--cream);
        }

        .facility-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
        }

        .facility-card {
            text-align: center;
            background: white;
            padding: 30px 18px;
            border-radius: 7px;
            box-shadow: 0 5px 20px rgba(0,0,0,.04);
            transition: .3s;
        }

        .facility-card:hover {
            transform: translateY(-6px);
        }

        .facility-card i {
            font-size: 30px;
            color: var(--gold);
            margin-bottom: 15px;
        }

        .facility-card h3 {
            font-size: 16px;
            margin-bottom: 7px;
        }

        .facility-card p {
            color: var(--text);
            font-size: 13px;
        }

        /* ROOMS */
        .room-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 25px;
        }

        .room-card {
            border-radius: 7px;
            overflow: hidden;
            background: white;
            box-shadow: 0 7px 25px rgba(0,0,0,.09);
            transition: .3s;
        }

        .room-card:hover {
            transform: translateY(-6px);
        }

        .room-card img {
            width: 100%;
            height: 235px;
            object-fit: cover;
        }

        .room-info {
            padding: 22px;
        }

        .room-info h3 {
            font-family: 'Playfair Display', serif;
            font-size: 23px;
            margin-bottom: 8px;
            text-transform: capitalize;
        }

        .room-info p {
            color: var(--text);
            font-size: 13px;
            min-height: 45px;
        }

        .room-bottom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 18px;
            border-top: 1px solid #eeeeee;
            padding-top: 15px;
        }

        .room-price {
            color: var(--gold);
            font-size: 19px;
            font-weight: 800;
        }

        .room-price small {
            color: var(--text);
            font-size: 11px;
            font-weight: 400;
        }

        .room-status {
            font-size: 11px;
            color: #23834d;
            font-weight: 700;
        }

        /* STATS */
        .stats {
            background:
                linear-gradient(rgba(20,20,20,.88), rgba(20,20,20,.88)),
                url('assets/images/hotel_lighting.jpg') center/cover;
            color: white;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            text-align: center;
        }

        .stat-item i {
            font-size: 30px;
            color: var(--gold-light);
            margin-bottom: 12px;
        }

        .stat-item h3 {
            font-size: 35px;
            color: var(--gold-light);
        }

        .stat-item p {
            color: #dddddd;
            font-size: 13px;
        }

        /* RESTAURANT */
        .restaurant {
            background: var(--cream);
        }

        .food-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 25px;
        }

        .food-card {
            background: white;
            border-radius: 7px;
            overflow: hidden;
            box-shadow: 0 6px 22px rgba(0,0,0,.07);
        }

        .food-card img {
            width: 100%;
            height: 220px;
            object-fit: cover;
        }

        .food-info {
            padding: 20px;
        }

        .food-info h3 {
            font-family: 'Playfair Display', serif;
            font-size: 21px;
        }

        .food-info p {
            color: var(--text);
            font-size: 13px;
        }

        .food-price {
            display: block;
            color: var(--gold);
            font-weight: 800;
            font-size: 18px;
            margin-top: 12px;
        }

        /* GALLERY */
        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }

        .gallery-grid img {
            width: 100%;
            height: 230px;
            object-fit: cover;
            border-radius: 6px;
        }

        /* CTA */
        .cta {
            background: var(--dark);
            color: white;
            text-align: center;
            padding: 75px 20px;
        }

        .cta h2 {
            font-family: 'Playfair Display', serif;
            font-size: 38px;
            margin-bottom: 12px;
        }

        .cta p {
            color: #cccccc;
            margin-bottom: 25px;
        }

        /* FOOTER */
        footer {
            background: #111111;
            color: white;
            padding: 55px 0 20px;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 40px;
            margin-bottom: 35px;
        }

        footer h3 {
            color: var(--gold-light);
            margin-bottom: 15px;
            font-family: 'Playfair Display', serif;
        }

        footer p, footer li {
            color: #bbbbbb;
            font-size: 13px;
            margin-bottom: 9px;
        }

        footer ul {
            list-style: none;
        }

        footer a:hover {
            color: var(--gold-light);
        }

        .copyright {
            border-top: 1px solid #333333;
            padding-top: 20px;
            text-align: center;
            color: #999999;
            font-size: 12px;
        }

        .empty-message {
            grid-column: 1 / -1;
            text-align: center;
            color: var(--text);
            padding: 30px;
            background: white;
            border-radius: 6px;
        }

        /* RESPONSIVE */
        @media(max-width: 900px) {
            .nav-links {
                gap: 13px;
            }

            .facility-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .room-grid, .food-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .about-grid {
                gap: 30px;
            }
        }

        @media(max-width: 700px) {
            .menu-toggle {
                display: block;
            }

            .nav-links {
                display: none;
                position: absolute;
                top: 78px;
                left: 0;
                width: 100%;
                background: #171717;
                padding: 20px;
                flex-direction: column;
                align-items: stretch;
            }

            .nav-links.active {
                display: flex;
            }

            .nav-links a {
                display: block;
                padding: 8px;
            }

            .hero {
                min-height: 570px;
            }

            .booking-form {
                grid-template-columns: 1fr;
            }

            .about-grid {
                grid-template-columns: 1fr;
            }

            .about-image img {
                height: 300px;
            }

            .room-grid, .food-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .gallery-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .footer-grid {
                grid-template-columns: 1fr;
                gap: 25px;
            }

            .section {
                padding: 65px 0;
            }

            .about-content h2 {
                font-size: 34px;
            }
        }

        @media(max-width: 420px) {
            .facility-grid {
                grid-template-columns: 1fr;
            }

            .gallery-grid {
                grid-template-columns: 1fr;
            }

            .btn-outline {
                margin: 12px 0 0;
            }
        }
    </style>
</head>

<body>

<!-- NAVIGATION -->
<nav class="navbar">
    <div class="container nav-inner">

        <a href="#home" class="logo">
            SUVA HOTEL
            <small>COMFORT & HOSPITALITY</small>
        </a>

        <div class="menu-toggle" id="menuToggle">
            <i class="fas fa-bars"></i>
        </div>

        <ul class="nav-links" id="navLinks">
            <li><a href="#home">Home</a></li>
            <li><a href="#about">About</a></li>
            <li><a href="#facilities">Facilities</a></li>
            <li><a href="#rooms">Rooms</a></li>
            <li><a href="#restaurant">Restaurant</a></li>
            <li><a href="#gallery">Gallery</a></li>
            <li><a href="<?php echo e(projectUrl('login.php')); ?>">Staff Login</a></li>
            <li><a class="nav-book" href="<?php echo e(projectUrl('public_booking.php')); ?>">Book Now</a></li>
        </ul>

    </div>
</nav>

<!-- HERO -->
<section class="hero" id="home">
    <div class="container">
        <div class="hero-content">

            <span class="hero-label">WELCOME TO SUVA HOTEL</span>

            <h1>Experience Comfort, Luxury & Hospitality</h1>

            <p>
                Discover a relaxing stay with comfortable rooms,
                delicious food and warm hospitality.
                Your comfort is our priority.
            </p>

            <a href="<?php echo e(projectUrl('public_booking.php')); ?>"
               class="btn btn-gold">Book Your Stay</a>

            <a href="#about" class="btn btn-outline">Explore More</a>

        </div>
    </div>
</section>

<!-- BOOKING SEARCH -->
<div class="container booking-wrap">
    <div class="booking-box">

        <form class="booking-form"
              action="<?php echo e(projectUrl('public_booking.php')); ?>"
              method="GET">

            <div class="form-group">
                <label for="check_in">CHECK-IN DATE</label>
                <input type="date" id="check_in" name="check_in" required>
            </div>

            <div class="form-group">
                <label for="check_out">CHECK-OUT DATE</label>
                <input type="date" id="check_out" name="check_out" required>
            </div>

            <div class="form-group">
                <label for="guests">NUMBER OF GUESTS</label>
                <input type="number" id="guests" name="guests"
                       min="1" value="1" required>
            </div>

            <button type="submit" class="btn btn-gold">
                <i class="fas fa-calendar-check"></i> Check Availability
            </button>

        </form>

    </div>
</div>

<!-- ABOUT -->
<section class="section" id="about">
    <div class="container about-grid">

        <div class="about-image">
            <img src="<?php echo e($aboutImage); ?>"
                 alt="Modern and comfortable hotel room"
                 onerror="this.onerror=null;this.src='<?php echo e(projectUrl('assets/images/hotel_lighting.jpg')); ?>';">
        </div>

        <div class="about-content">

            <span class="eyebrow">ABOUT OUR HOTEL</span>

            <h2>A Perfect Place to Relax and Enjoy</h2>

            <p>
                Welcome to Suva Hotel, where comfort meets hospitality.
                We aim to provide our guests with a pleasant and relaxing
                experience in a welcoming environment.
            </p>

            <p>
                Whether you are travelling for business, spending time
                with family or enjoying a holiday, our hotel offers
                comfortable accommodation and quality services.
            </p>

            <div class="about-features">
                <div><i class="fas fa-check-circle"></i> Comfortable Rooms</div>
                <div><i class="fas fa-check-circle"></i> Quality Food</div>
                <div><i class="fas fa-check-circle"></i> Friendly Service</div>
                <div><i class="fas fa-check-circle"></i> Relaxing Stay</div>
            </div>

            <a href="#rooms" class="btn btn-gold">Explore Our Rooms</a>

        </div>

    </div>
</section>

<!-- FACILITIES -->
<section class="section facilities" id="facilities">
    <div class="container">

        <div class="section-heading">
            <span>OUR SERVICES</span>
            <h2>Hotel Facilities</h2>
            <p>Enjoy convenient services and facilities during your stay.</p>
        </div>

        <div class="facility-grid">

            <div class="facility-card">
                <i class="fas fa-bed"></i>
                <h3>Comfortable Rooms</h3>
                <p>Clean and comfortable accommodation.</p>
            </div>

            <div class="facility-card">
                <i class="fas fa-utensils"></i>
                <h3>Restaurant</h3>
                <p>Enjoy delicious meals and refreshments.</p>
            </div>

            <div class="facility-card">
                <i class="fas fa-swimming-pool"></i>
                <h3>Swimming Pool</h3>
                <p>Relax and enjoy your leisure time.</p>
            </div>

            <div class="facility-card">
                <i class="fas fa-wifi"></i>
                <h3>Free Wi-Fi</h3>
                <p>Stay connected during your visit.</p>
            </div>

        </div>
    </div>
</section>

<!-- ROOMS -->
<section class="section" id="rooms">
    <div class="container">

        <div class="section-heading">
            <span>ACCOMMODATION</span>
            <h2>Our Comfortable Rooms</h2>
            <p>Choose a room that suits your needs and enjoy your stay.</p>
        </div>

        <div class="room-grid">

            <?php if (!empty($rooms)): ?>

                <?php foreach ($rooms as $room): ?>

                    <div class="room-card">

                        <img
                            src="<?php echo e(roomImage($room['image'] ?? '')); ?>"
                            alt="<?php echo e($room['room_type'] ?? 'Hotel room'); ?>"
                            loading="lazy">

                        <div class="room-info">

                            <h3>
                                <?php echo e(ucwords($room['room_type'] ?? 'Guest Room')); ?>
                            </h3>

                            <p>
                                <?php
                                echo e(
                                    !empty($room['description'])
                                    ? $room['description']
                                    : 'Enjoy a comfortable and relaxing stay at Suva Hotel.'
                                );
                                ?>
                            </p>

                            <div class="room-bottom">

                                <span class="room-price">
                                    Rs. <?php echo number_format((float)($room['price_per_night'] ?? 0)); ?>
                                    <small>/ night</small>
                                </span>

                                <span class="room-status">
                                    <?php echo e(ucwords($room['status'] ?? '')); ?>
                                </span>

                            </div>

                            <p style="margin-top:10px;font-size:12px;">
                                Room No: <?php echo e($room['room_number'] ?? ''); ?>
                            </p>

                        </div>
                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <div class="empty-message">
                    <i class="fas fa-bed"></i>
                    <p>Room information will be available soon.</p>
                </div>

            <?php endif; ?>

        </div>

        <div style="text-align:center;margin-top:35px;">
            <a href="<?php echo e(projectUrl('public_booking.php')); ?>"
               class="btn btn-gold">Book a Room</a>
        </div>

    </div>
</section>

<!-- STATISTICS -->
<section class="section stats">
    <div class="container stats-grid">

        <div class="stat-item">
            <i class="fas fa-door-open"></i>
            <h3><?php echo $totalRooms; ?></h3>
            <p>Total Rooms</p>
        </div>

        <div class="stat-item">
            <i class="fas fa-check-circle"></i>
            <h3><?php echo $availableRooms; ?></h3>
            <p>Available Rooms</p>
        </div>

        <div class="stat-item">
            <i class="fas fa-calendar-check"></i>
            <h3><?php echo $bookedRooms; ?></h3>
            <p>Booked Rooms</p>
        </div>

        <div class="stat-item">
            <i class="fas fa-concierge-bell"></i>
            <h3>24/7</h3>
            <p>Hospitality</p>
        </div>

    </div>
</section>

<!-- RESTAURANT -->
<section class="section restaurant" id="restaurant">
    <div class="container">

        <div class="section-heading">
            <span>FOOD & DINING</span>
            <h2>Our Restaurant</h2>
            <p>Enjoy delicious food and refreshing drinks at Suva Hotel.</p>
        </div>

        <div class="food-grid">

            <?php if (!empty($foods)): ?>

                <?php foreach ($foods as $food): ?>

                    <div class="food-card">

                        <img
                            src="<?php echo e(foodImage($food['image'] ?? '')); ?>"
                            alt="<?php echo e($food['food_name'] ?? 'Restaurant food'); ?>"
                            loading="lazy">

                        <div class="food-info">

                            <h3><?php echo e($food['food_name'] ?? ''); ?></h3>

                            <p><?php echo e($food['category'] ?? ''); ?></p>

                            <span class="food-price">
                                Rs. <?php echo number_format((float)($food['price'] ?? 0)); ?>
                            </span>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <div class="empty-message">
                    <p>Restaurant menu will be available soon.</p>
                </div>

            <?php endif; ?>

        </div>

        <div style="text-align:center;margin-top:35px;">
            <a href="<?php echo e(projectUrl('restaurant/view.php')); ?>"
               class="btn btn-gold">Explore Restaurant</a>
        </div>

    </div>
</section>

<!-- GALLERY -->
<section class="section" id="gallery">
    <div class="container">

        <div class="section-heading">
            <span>PHOTO GALLERY</span>
            <h2>Discover Suva Hotel</h2>
            <p>A glimpse of comfort, hospitality and memorable experiences.</p>
        </div>

        <div class="gallery-grid">

            <img src="<?php echo e($aboutImage); ?>"
                 alt="Modern hotel bedroom" loading="lazy">

            <?php
            $galleryCount = 0;

            foreach ($rooms as $galleryRoom):
                if ($galleryCount >= 5) {
                    break;
                }
                $galleryCount++;
            ?>
                <img
                    src="<?php echo e(roomImage($galleryRoom['image'] ?? '')); ?>"
                    alt="Suva Hotel room"
                    loading="lazy">
            <?php endforeach; ?>

            <?php if ($galleryCount === 0): ?>
                <img src="<?php echo e(projectUrl('assets/images/hotel_lighting.jpg')); ?>"
                     alt="Hotel interior" loading="lazy">
            <?php endif; ?>

        </div>

    </div>
</section>

<!-- CALL TO ACTION -->
<section class="cta">
    <div class="container">

        <h2>Make Your Stay Memorable</h2>

        <p>
            Experience comfort, delicious food and warm hospitality
            at Suva Hotel.
        </p>

        <a href="<?php echo e(projectUrl('public_booking.php')); ?>"
           class="btn btn-gold">Reserve Your Room</a>

    </div>
</section>

<!-- FOOTER -->
<footer>
    <div class="container">

        <div class="footer-grid">

            <div>
                <h3>SUVA HOTEL</h3>

                <p>
                    Your comfort is our priority.
                    Enjoy a relaxing stay with quality services
                    and warm hospitality.
                </p>
            </div>

            <div>
                <h3>Quick Links</h3>

                <ul>
                    <li><a href="#home">Home</a></li>
                    <li><a href="#about">About Us</a></li>
                    <li><a href="#rooms">Our Rooms</a></li>
                    <li><a href="#restaurant">Restaurant</a></li>
                    <li><a href="<?php echo e(projectUrl('public_booking.php')); ?>">Booking</a></li>
                </ul>
            </div>

            <div>
                <h3>Contact</h3>

                <p><i class="fas fa-hotel"></i> Suva Hotel</p>
                <p><i class="fas fa-concierge-bell"></i> Hospitality & Accommodation</p>
                <p><i class="fas fa-clock"></i> Service available for our guests</p>
            </div>

        </div>

        <div class="copyright">
            &copy; <?php echo date('Y'); ?> Suva Hotel. All Rights Reserved.
        </div>

    </div>
</footer>

<script>
// MOBILE NAVIGATION
const menuToggle = document.getElementById('menuToggle');
const navLinks = document.getElementById('navLinks');

menuToggle.addEventListener('click', function () {
    navLinks.classList.toggle('active');

    const icon = menuToggle.querySelector('i');

    if (navLinks.classList.contains('active')) {
        icon.classList.remove('fa-bars');
        icon.classList.add('fa-times');
    } else {
        icon.classList.remove('fa-times');
        icon.classList.add('fa-bars');
    }
});

// CLOSE MOBILE MENU AFTER CLICKING A LINK
document.querySelectorAll('.nav-links a').forEach(function (link) {
    link.addEventListener('click', function () {
        navLinks.classList.remove('active');

        const icon = menuToggle.querySelector('i');
        icon.classList.remove('fa-times');
        icon.classList.add('fa-bars');
    });
});

// BOOKING DATE VALIDATION
const checkIn = document.getElementById('check_in');
const checkOut = document.getElementById('check_out');

const today = new Date();
const todayString = [
    today.getFullYear(),
    String(today.getMonth() + 1).padStart(2, '0'),
    String(today.getDate()).padStart(2, '0')
].join('-');

checkIn.min = todayString;
checkOut.min = todayString;

checkIn.addEventListener('change', function () {
    if (!checkIn.value) {
        return;
    }

    const nextDay = new Date(checkIn.value + 'T00:00:00');
    nextDay.setDate(nextDay.getDate() + 1);

    const nextDayString = [
        nextDay.getFullYear(),
        String(nextDay.getMonth() + 1).padStart(2, '0'),
        String(nextDay.getDate()).padStart(2, '0')
    ].join('-');

    checkOut.min = nextDayString;

    if (checkOut.value && checkOut.value <= checkIn.value) {
        checkOut.value = nextDayString;
    }
});
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.14.2/gsap.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.14.2/ScrollTrigger.min.js"></script>


<!-- GSAP LIBRARIES -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.13.0/gsap.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.13.0/ScrollTrigger.min.js"></script>

<!-- SUVA HOTEL GSAP ANIMATIONS -->
<script>
document.addEventListener("DOMContentLoaded", function () {

    if (typeof gsap === "undefined") return;

    gsap.registerPlugin(ScrollTrigger);

    // HERO ANIMATION
    const heroTimeline = gsap.timeline({
        defaults: { ease: "power3.out" }
    });

    heroTimeline
        .from(".hero-label", {
            y: 35,
            opacity: 0,
            duration: 1
        })
        .from(".hero h1", {
            y: 65,
            opacity: 0,
            duration: 1.2
        }, "-=0.5")
        .from(".hero p", {
            y: 35,
            opacity: 0,
            duration: 1
        }, "-=0.6")
        .from(".hero .btn", {
            y: 25,
            opacity: 0,
            duration: 0.8,
            stagger: 0.2
        }, "-=0.5");

    // HERO BACKGROUND ZOOM
    gsap.fromTo(".hero",
        { backgroundSize: "108% auto" },
        {
            backgroundSize: "100% auto",
            duration: 8,
            ease: "power1.out"
        }
    );

    // SECTION ANIMATIONS
    gsap.utils.toArray(".section-heading").forEach(function (heading) {
        gsap.from(heading.children, {
            y: 35,
            opacity: 0,
            duration: 0.9,
            stagger: 0.15,
            ease: "power3.out",
            scrollTrigger: {
                trigger: heading,
                start: "top 85%",
                once: true
            }
        });
    });

    // ROOM CARDS
    gsap.utils.toArray(".room-card").forEach(function (card, index) {
        gsap.from(card, {
            y: 55,
            opacity: 0,
            duration: 0.8,
            delay: (index % 3) * 0.12,
            scrollTrigger: {
                trigger: card,
                start: "top 90%",
                once: true
            }
        });
    });

    // FACILITY CARDS
    gsap.utils.toArray(".facility-card").forEach(function (card, index) {
        gsap.from(card, {
            y: 40,
            opacity: 0,
            duration: 0.7,
            delay: index * 0.1,
            scrollTrigger: {
                trigger: card,
                start: "top 90%",
                once: true
            }
        });
    });

    // RESTAURANT CARDS
    gsap.utils.toArray(".food-card").forEach(function (card, index) {
        gsap.from(card, {
            y: 45,
            opacity: 0,
            duration: 0.8,
            delay: (index % 3) * 0.12,
            scrollTrigger: {
                trigger: card,
                start: "top 90%",
                once: true
            }
        });
    });

    // ABOUT IMAGE AND CONTENT
    gsap.from(".about-image", {
        x: -65,
        opacity: 0,
        duration: 1.2,
        scrollTrigger: {
            trigger: ".about-grid",
            start: "top 80%",
            once: true
        }
    });

    gsap.from(".about-content", {
        x: 65,
        opacity: 0,
        duration: 1.2,
        scrollTrigger: {
            trigger: ".about-grid",
            start: "top 80%",
            once: true
        }
    });

});
</script>

</body>
</html>

</body>
</html>
