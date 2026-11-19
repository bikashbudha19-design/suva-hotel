
<?php
/**
 * SUVA HOTEL - PUBLIC WEBSITE HEADER
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pageTitle = $pageTitle ?? 'Suva Hotel | Luxury Hospitality';
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= htmlspecialchars($pageTitle) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>

<header class="site-header">

    <div class="header-container">

        <a href="index.php" class="brand">
            <span class="brand-icon">S</span>

            <span class="brand-text">
                <strong>SUVA HOTEL</strong>
                <small>HOSPITALITY & COMFORT</small>
            </span>
        </a>

        <nav class="main-nav">

            <a href="index.php"
               class="<?= $currentPage === 'index.php' ? 'active' : '' ?>">
                Home
            </a>

            <a href="rooms.php"
               class="<?= $currentPage === 'rooms.php' ? 'active' : '' ?>">
                Rooms
            </a>

            <a href="booking.php"
               class="<?= $currentPage === 'booking.php' ? 'active' : '' ?>">
                Reservations
            </a>

            <a href="restaurant.php"
               class="<?= $currentPage === 'restaurant.php' ? 'active' : '' ?>">
                Restaurant
            </a>

            <a href="swimming.php"
               class="<?= $currentPage === 'swimming.php' ? 'active' : '' ?>">
                Swimming
            </a>

            <a href="events.php"
               class="<?= $currentPage === 'events.php' ? 'active' : '' ?>">
                Events
            </a>

            <a href="about.php"
               class="<?= $currentPage === 'about.php' ? 'active' : '' ?>">
                About
            </a>

            <a href="contact.php"
               class="<?= $currentPage === 'contact.php' ? 'active' : '' ?>">
                Contact
            </a>

        </nav>

        <a href="booking.php" class="header-button">
            Book Your Stay
        </a>

    </div>

</header>

<main>
