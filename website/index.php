
<?php
/**
 * =========================================================
 * SUVA HOTEL - PUBLIC HOMEPAGE
 * File: website/index.php
 * PHP 8 + PostgreSQL
 * =========================================================
 */

date_default_timezone_set('Asia/Kathmandu');

require_once __DIR__ . '/../config/database.php';

/* ---------------- DATABASE DATA ---------------- */

$totalRooms = 0;
$availableRooms = 0;
$featuredRooms = [];
$dbAvailable = isset($pgConnection) && $pgConnection !== false;

if ($dbAvailable) {

    // Room statistics
    $statsSql = "
        SELECT
            COUNT(*) AS total_rooms,
            COUNT(*) FILTER (
                WHERE LOWER(status) = 'available'
            ) AS available_rooms
        FROM rooms
    ";

    $statsResult = @pg_query($pgConnection, $statsSql);

    if ($statsResult) {
        $stats = pg_fetch_assoc($statsResult);

        $totalRooms = (int)($stats['total_rooms'] ?? 0);
        $availableRooms = (int)($stats['available_rooms'] ?? 0);

        pg_free_result($statsResult);
    }

    // Featured available rooms
    $roomsSql = "
        SELECT
            id,
            room_number,
            room_type,
            price_per_night,
            description,
            image
        FROM rooms
        WHERE LOWER(status) = 'available'
        ORDER BY id DESC
        LIMIT 3
    ";

    $roomsResult = @pg_query($pgConnection, $roomsSql);

    if ($roomsResult) {
        while ($room = pg_fetch_assoc($roomsResult)) {
            $featuredRooms[] = $room;
        }

        pg_free_result($roomsResult);
    }
}

/* ---------------- HELPER FUNCTION ---------------- */

function publicEscape($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

/* ---------------- PAGE TITLE ---------------- */

$pageTitle = 'Suva Hotel | Experience Comfort and Hospitality';

require_once __DIR__ . '/includes/header.php';
?>

<!-- ================= HERO SECTION ================= -->

<section class="hero-section">

    <div class="hero-overlay"></div>

    <div class="hero-content">

        <span class="hero-label">
            WELCOME TO SUVA HOTEL
        </span>

        <h1>
            A Beautiful Stay.<br>
            <span>A Memorable Experience.</span>
        </h1>

        <p>
            Discover comfort, warm hospitality and relaxing
            experiences at Suva Hotel.
        </p>

        <div class="hero-buttons">

            <a href="rooms.php" class="btn-gold">
                Explore Our Rooms
            </a>

            <a href="booking.php" class="btn-outline">
                Reserve Your Stay
            </a>

        </div>

        <div class="hero-note">
            <span>✦</span>
            Comfort · Hospitality · Excellence
        </div>

    </div>

    <div class="hero-bottom">
        YOUR JOURNEY TO COMFORT BEGINS HERE
    </div>

</section>


<!-- ================= WELCOME SECTION ================= -->

<section class="welcome-section">

    <div class="welcome-content">

        <span class="section-label">
            THE SUVA EXPERIENCE
        </span>

        <h2>
            More Than a Stay,<br>
            <span>A Place to Feel at Home.</span>
        </h2>

        <p>
            At Suva Hotel, we believe every guest deserves
            a welcoming experience. From comfortable rooms
            to delightful dining and relaxing facilities,
            we bring together the little details that make
            your stay special.
        </p>

        <a href="about.php" class="text-link">
            Discover Our Story →
        </a>

    </div>

    <div class="welcome-image">
        <div class="welcome-image-caption">
            <span>WELCOME</span>
            <strong>Feel the Difference</strong>
        </div>
    </div>

</section>


<!-- ================= HOTEL STATISTICS ================= -->

<section class="stats-section">

    <div class="stats-container">

        <div class="stat-item">
            <span class="stat-number">
                <?= number_format($totalRooms) ?>
            </span>
            <span class="stat-label">Total Rooms</span>
        </div>

        <div class="stat-item">
            <span class="stat-number">
                <?= number_format($availableRooms) ?>
            </span>
            <span class="stat-label">Currently Available</span>
        </div>

        <div class="stat-item">
            <span class="stat-number">24/7</span>
            <span class="stat-label">Hospitality</span>
        </div>

        <div class="stat-item">
            <span class="stat-number">100%</span>
            <span class="stat-label">Guest Focus</span>
        </div>

    </div>

</section>


<!-- ================= ROOMS SECTION ================= -->

<section class="rooms-section">

    <div class="section-heading">

        <span class="section-label">
            ACCOMMODATION
        </span>

        <h2>
            Find Your Perfect <span>Room</span>
        </h2>

        <p>
            Explore our available rooms and discover a
            comfortable place to relax.
        </p>

    </div>


    <div class="room-grid">

        <?php if (!empty($featuredRooms)): ?>

            <?php foreach ($featuredRooms as $room): ?>

                <?php
                   $roomFilename = basename(trim((string)($room['image'] ?? '')));

if ($roomFilename !== '') {
    $roomImage = '../rooms/uploads/' . rawurlencode($roomFilename);
} else {
    $roomImage = 'assets/images/room-placeholder.jpg';
}
                    $roomDescription = trim(
                        (string)($room['description'] ?? '')
                    );

                    if ($roomDescription === '') {
                        $roomDescription =
                            'Enjoy a comfortable and relaxing stay at Suva Hotel.';
                    }
                ?>

                <article class="room-card">

                    <div class="room-image">

                        <img
                            src="<?= publicEscape($roomImage) ?>"
                            alt="<?= publicEscape($room['room_type']) ?>"
                            onerror="this.onerror=null;this.src='assets/images/room-placeholder.jpg';"
                        >

                        <span class="room-status">
                            Available
                        </span>

                    </div>

                    <div class="room-details">

                        <span class="room-category">
                            <?= publicEscape($room['room_type']) ?>
                        </span>

                        <h3>
                            <?= publicEscape($room['room_type']) ?>
                            Room
                        </h3>

                        <p>
                            <?= publicEscape($roomDescription) ?>
                        </p>

                        <div class="room-card-bottom">

                            <div class="room-price">
                                <strong>
                                    Rs.
                                    <?= number_format(
                                        (float)$room['price_per_night'],
                                        2
                                    ) ?>
                                </strong>

                                <small>/ night</small>
                            </div>

                            <a
                                href="booking.php?room_id=<?= (int)$room['id'] ?>"
                                class="room-book-button"
                            >
                                Book Now →
                            </a>

                        </div>

                    </div>

                </article>

            <?php endforeach; ?>

        <?php else: ?>

            <div class="no-rooms">
                <h3>Rooms Coming Soon</h3>
                <p>
                    We are preparing our room collection.
                    Please contact us for booking information.
                </p>
            </div>

        <?php endif; ?>

    </div>

    <div class="center-button">
        <a href="rooms.php" class="btn-dark">
            View All Rooms
        </a>
    </div>

</section>


<!-- ================= FACILITIES ================= -->

<section class="facilities-section">

    <div class="section-heading light-heading">

        <span class="section-label">EXPERIENCE MORE</span>

        <h2>
            Everything You Need,<br>
            <span>Under One Roof.</span>
        </h2>

        <p>
            Enjoy the facilities and experiences available
            at Suva Hotel.
        </p>

    </div>

    <div class="facility-grid">

        <a href="restaurant.php" class="facility-card restaurant-card">
            <div>
                <span>01</span>
                <h3>Restaurant</h3>
                <p>Discover delightful dining experiences.</p>
                <strong>Explore Dining →</strong>
            </div>
        </a>

        <a href="swimming.php" class="facility-card swimming-card">
            <div>
                <span>02</span>
                <h3>Swimming Pool</h3>
                <p>Enjoy a refreshing and relaxing experience.</p>
                <strong>Discover More →</strong>
            </div>
        </a>

        <a href="events.php" class="facility-card events-card">
            <div>
                <span>03</span>
                <h3>Events & Celebrations</h3>
                <p>Make your special occasions memorable.</p>
                <strong>Plan Your Event →</strong>
            </div>
        </a>

    </div>

</section>


<!-- ================= BOOKING CALL TO ACTION ================= -->

<section class="booking-cta">

    <div class="booking-cta-content">

        <span class="section-label">
            YOUR STAY STARTS HERE
        </span>

        <h2>
            Ready to Experience<br>
            <span>Suva Hospitality?</span>
        </h2>

        <p>
            Send us your booking request.
            Our hotel team will review and confirm your reservation.
        </p>

        <a href="booking.php" class="btn-gold">
            Make a Reservation →
        </a>

    </div>

</section>


<!-- ================= CONTACT STRIP ================= -->

<section class="contact-strip">

    <div>
        <span class="section-label">WE ARE HERE FOR YOU</span>
        <h2>Have a Question?</h2>
        <p>Contact our team for booking and hotel information.</p>
    </div>

    <a href="contact.php" class="btn-dark">
        Contact Us →
    </a>

</section>


<?php
require_once __DIR__ . '/includes/footer.php';
?>
