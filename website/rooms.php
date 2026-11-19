
<?php
/**
 * ============================================================
 * SUVA HOTEL — PUBLIC ROOMS PAGE
 * File: website/rooms.php
 * PHP 8+ | PostgreSQL | XAMPP
 *
 * Uses the existing hotel database and room photographs.
 * Does not modify existing booking or check-in functionality.
 * ============================================================
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('Asia/Kathmandu');

require_once __DIR__ . '/../config/database.php';

$pageTitle = 'Rooms & Suites | Suva Hotel';

/* ------------------------------------------------------------
   HELPER: ESCAPE OUTPUT
------------------------------------------------------------ */

function roomsEscape($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/* ------------------------------------------------------------
   HELPER: FORMAT ROOM TYPE
------------------------------------------------------------ */

function formatRoomType($value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return 'Guest Room';
    }

    return ucwords(strtolower($value));
}

/* ------------------------------------------------------------
   GET AVAILABLE ROOMS FROM POSTGRESQL
------------------------------------------------------------ */

$rooms = [];
$roomQueryError = false;

$sql = "
    SELECT
        id,
        room_number,
        room_type,
        price_per_night,
        description,
        image,
        status
    FROM rooms
    WHERE LOWER(TRIM(status)) = 'available'
    ORDER BY price_per_night ASC, room_number ASC
";

$result = pg_query($pgConnection, $sql);

if ($result !== false) {
    while ($row = pg_fetch_assoc($result)) {
        $rooms[] = $row;
    }

    pg_free_result($result);
} else {
    $roomQueryError = true;

    // Log the technical error without exposing database details.
    error_log('Suva Hotel rooms.php: Could not retrieve available rooms.');
}

/* ------------------------------------------------------------
   PAGE HEADER
------------------------------------------------------------ */

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* ============================================================
   SUVA HOTEL — ROOMS PAGE STYLES
============================================================ */

.rooms-page {
    background: #faf8f2;
    color: #24372f;
    min-height: 100vh;
}

.rooms-hero {
    position: relative;
    overflow: hidden;
    padding: 90px 24px 85px;
    background:
        radial-gradient(
            circle at 85% 20%,
            rgba(202, 166, 94, 0.18),
            transparent 32%
        ),
        linear-gradient(135deg, #123c30, #0b2922);
    color: #fffdf7;
    text-align: center;
}

.rooms-hero::before,
.rooms-hero::after {
    content: "";
    position: absolute;
    border: 1px solid rgba(220, 190, 125, 0.25);
    border-radius: 50%;
    pointer-events: none;
}

.rooms-hero::before {
    width: 300px;
    height: 300px;
    top: -180px;
    left: -90px;
}

.rooms-hero::after {
    width: 420px;
    height: 420px;
    right: -190px;
    bottom: -280px;
}

.rooms-hero-inner {
    position: relative;
    z-index: 1;
    max-width: 850px;
    margin: 0 auto;
}

.rooms-eyebrow {
    display: inline-block;
    margin-bottom: 20px;
    color: #e3c98f;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 4px;
    text-transform: uppercase;
}

.rooms-hero h1 {
    margin: 0 0 20px;
    color: #fffdf7;
    font-family: "Playfair Display", Georgia, serif;
    font-size: clamp(38px, 6vw, 68px);
    font-weight: 500;
    line-height: 1.15;
}

.rooms-hero p {
    max-width: 620px;
    margin: 0 auto;
    color: #e4e8e0;
    font-size: 16px;
    line-height: 1.9;
}

.rooms-hero-line {
    width: 70px;
    height: 2px;
    margin: 28px auto 0;
    background: #d9b96e;
}

.rooms-content {
    width: min(1220px, calc(100% - 40px));
    margin: 0 auto;
    padding: 65px 0 85px;
}

.rooms-intro {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 24px;
    margin-bottom: 32px;
}

.rooms-intro-label {
    display: block;
    margin-bottom: 12px;
    color: #9b7a3e;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 3px;
    text-transform: uppercase;
}

.rooms-intro h2 {
    margin: 0 0 12px;
    color: #153b2f;
    font-family: "Playfair Display", Georgia, serif;
    font-size: clamp(30px, 4vw, 43px);
    font-weight: 500;
}

.rooms-intro p {
    max-width: 650px;
    margin: 0;
    color: #6d766e;
    font-size: 15px;
    line-height: 1.8;
}

.rooms-count {
    flex-shrink: 0;
    padding: 12px 17px;
    border: 1px solid #e2d8c1;
    border-radius: 3px;
    background: #fffdf8;
    color: #234c3c;
    font-size: 13px;
    font-weight: 700;
}

.rooms-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 27px;
}

.room-card {
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid #ece7dc;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 8px 25px rgba(30, 53, 41, 0.055);
    transition:
        transform 0.3s ease,
        box-shadow 0.3s ease,
        border-color 0.3s ease;
}

.room-card:hover {
    transform: translateY(-7px);
    border-color: #d7c18c;
    box-shadow: 0 18px 38px rgba(30, 53, 41, 0.13);
}

.room-image-wrap {
    position: relative;
    overflow: hidden;
    height: 245px;
    background:
        linear-gradient(135deg, #e8e3d6, #d4ddcf);
}

.room-image-wrap img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.6s ease;
}

.room-card:hover .room-image-wrap img {
    transform: scale(1.055);
}

.room-image-placeholder {
    display: flex;
    width: 100%;
    height: 100%;
    align-items: center;
    justify-content: center;
    padding: 20px;
    color: #476453;
    font-family: "Playfair Display", Georgia, serif;
    font-size: 23px;
    text-align: center;
}

.room-status {
    position: absolute;
    top: 16px;
    left: 16px;
    padding: 8px 12px;
    border: 1px solid rgba(255, 255, 255, 0.5);
    border-radius: 3px;
    background: rgba(20, 63, 47, 0.94);
    color: #fff;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1.2px;
    text-transform: uppercase;
}

.room-number {
    position: absolute;
    right: 14px;
    bottom: 14px;
    padding: 8px 11px;
    border-radius: 3px;
    background: rgba(255, 253, 247, 0.95);
    color: #183e30;
    font-size: 12px;
    font-weight: 700;
}

.room-card-body {
    display: flex;
    flex: 1;
    flex-direction: column;
    padding: 23px 22px 22px;
}

.room-category {
    margin-bottom: 10px;
    color: #a17e3d;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 2px;
    text-transform: uppercase;
}

.room-card h3 {
    margin: 0 0 12px;
    color: #183d30;
    font-family: "Playfair Display", Georgia, serif;
    font-size: 25px;
    font-weight: 500;
}

.room-description {
    flex: 1;
    min-height: 55px;
    margin: 0 0 20px;
    color: #6e766f;
    font-size: 13px;
    line-height: 1.85;
    overflow-wrap: anywhere;
}

.room-divider {
    height: 1px;
    margin-bottom: 19px;
    background: #eee9df;
}

.room-card-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.room-price-label {
    display: block;
    margin-bottom: 5px;
    color: #8a918b;
    font-size: 10px;
    letter-spacing: 1px;
    text-transform: uppercase;
}

.room-price {
    color: #153c2e;
    font-size: 22px;
    font-weight: 800;
    white-space: nowrap;
}

.room-price small {
    color: #7b827c;
    font-size: 11px;
    font-weight: 400;
}

.room-book-button {
    display: inline-flex;
    min-height: 43px;
    align-items: center;
    justify-content: center;
    padding: 11px 14px;
    border: 1px solid #174a38;
    border-radius: 3px;
    background: #174a38;
    color: #fffdf7;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
    transition: background 0.25s ease, color 0.25s ease;
}

.room-book-button:hover {
    background: #d7bd7b;
    border-color: #d7bd7b;
    color: #173b2e;
}

.rooms-notice {
    margin-top: 30px;
    padding: 18px 22px;
    border-left: 3px solid #c6a45c;
    background: #f0eee5;
    color: #59675d;
    font-size: 13px;
    line-height: 1.8;
}

.rooms-empty {
    grid-column: 1 / -1;
    padding: 55px 25px;
    border: 1px solid #e7e0d2;
    border-radius: 8px;
    background: #fff;
    text-align: center;
}

.rooms-empty h3 {
    margin: 0 0 12px;
    color: #183d30;
    font-family: "Playfair Display", Georgia, serif;
    font-size: 29px;
    font-weight: 500;
}

.rooms-empty p {
    margin: 0 0 22px;
    color: #68756b;
    line-height: 1.8;
}

.rooms-empty a {
    display: inline-block;
    padding: 13px 20px;
    border-radius: 3px;
    background: #174a38;
    color: #fff;
    text-decoration: none;
}

.rooms-cta {
    padding: 62px 24px;
    background: #e9e7dc;
    text-align: center;
}

.rooms-cta-inner {
    max-width: 760px;
    margin: 0 auto;
}

.rooms-cta span {
    color: #9a793d;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 3px;
    text-transform: uppercase;
}

.rooms-cta h2 {
    margin: 15px 0;
    color: #153b2f;
    font-family: "Playfair Display", Georgia, serif;
    font-size: clamp(29px, 4vw, 42px);
    font-weight: 500;
}

.rooms-cta p {
    margin: 0 auto 25px;
    color: #657067;
    font-size: 14px;
    line-height: 1.9;
}

.rooms-cta a {
    display: inline-block;
    padding: 14px 23px;
    border: 1px solid #174a38;
    border-radius: 3px;
    background: #174a38;
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    transition: 0.25s ease;
}

.rooms-cta a:hover {
    border-color: #c6a45c;
    background: #c6a45c;
    color: #173b2e;
}

/* Tablet */
@media (max-width: 950px) {
    .rooms-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 22px;
    }

    .room-image-wrap {
        height: 225px;
    }
}

/* Mobile */
@media (max-width: 600px) {
    .rooms-hero {
        padding: 65px 20px;
    }

    .rooms-content {
        width: calc(100% - 30px);
        padding: 45px 0 55px;
    }

    .rooms-intro {
        align-items: flex-start;
        flex-direction: column;
        gap: 18px;
    }

    .rooms-grid {
        grid-template-columns: 1fr;
        gap: 22px;
    }

    .room-image-wrap {
        height: 250px;
    }

    .room-card-body {
        padding: 21px 18px;
    }

    .room-card h3 {
        font-size: 23px;
    }

    .room-price {
        font-size: 20px;
    }

    .room-book-button {
        padding: 11px 12px;
    }

    .rooms-cta {
        padding: 48px 20px;
    }
}
</style>

<div class="rooms-page">

    <!-- =====================================================
         HERO SECTION
    ====================================================== -->

    <section class="rooms-hero">
        <div class="rooms-hero-inner">

            <span class="rooms-eyebrow">
                Accommodation · Comfort · Hospitality
            </span>

            <h1>Find Your Perfect<br>Place to Stay.</h1>

            <p>
                Discover welcoming rooms, thoughtful comforts and
                warm hospitality. Explore our available accommodation
                and choose the room that suits your stay.
            </p>

            <div class="rooms-hero-line"></div>

        </div>
    </section>


    <!-- =====================================================
         AVAILABLE ROOMS
    ====================================================== -->

    <section class="rooms-content">

        <div class="rooms-intro">
            <div>
                <span class="rooms-intro-label">
                    Your Comfort Comes First
                </span>

                <h2>Our Rooms &amp; Accommodation</h2>

                <p>
                    Explore our currently available rooms, view their
                    prices and details, and send a reservation request
                    to the Suva Hotel team.
                </p>
            </div>

            <?php if (!$roomQueryError): ?>
                <div class="rooms-count">
                    <?= count($rooms) ?>
                    <?= count($rooms) === 1 ? 'Room' : 'Rooms' ?> Available
                </div>
            <?php endif; ?>
        </div>


        <div class="rooms-grid">

            <?php if ($roomQueryError): ?>

                <div class="rooms-empty">
                    <h3>Rooms Are Temporarily Unavailable</h3>

                    <p>
                        We could not load room information right now.
                        Please refresh the page or contact our hotel team.
                    </p>

                    <a href="contact.php">Contact Us</a>
                </div>

            <?php elseif (empty($rooms)): ?>

                <div class="rooms-empty">
                    <h3>Every Stay Begins with a Welcome</h3>

                    <p>
                        There are currently no rooms marked as available.
                        Please contact our team to ask about your preferred
                        dates and accommodation.
                    </p>

                    <a href="contact.php">Contact Our Team</a>
                </div>

            <?php else: ?>

                <?php foreach ($rooms as $room): ?>

                    <?php
                    $roomId = (int)$room['id'];

                    $roomNumber = trim(
                        (string)($room['room_number'] ?? '')
                    );

                    $roomType = formatRoomType(
                        $room['room_type'] ?? ''
                    );

                    $roomDescription = trim(
                        (string)($room['description'] ?? '')
                    );

                    // Avoid showing placeholder descriptions as real details.
                    if (
                        $roomDescription === '' ||
                        in_array(
                            strtolower($roomDescription),
                            ['best', 'good', 'n/a', 'none', 'null'],
                            true
                        )
                    ) {
                        $roomDescription =
                            'A welcoming room designed to provide a '
                            . 'comfortable and relaxing stay.';
                    }

                    // Use the existing room image folder.
                    // basename() prevents directory traversal through DB values.
                    $imageFilename = basename(
                        trim((string)($room['image'] ?? ''))
                    );

                    $roomImage = '';

                    if (
                        $imageFilename !== '' &&
                        $imageFilename !== '.' &&
                        $imageFilename !== '..'
                    ) {
                        $roomImage =
                            '../rooms/uploads/' .
                            rawurlencode($imageFilename);
                    }

                    $roomPrice = (float)(
                        $room['price_per_night'] ?? 0
                    );
                    ?>

                    <article class="room-card">

                        <div class="room-image-wrap">

                            <?php if ($roomImage !== ''): ?>

                                <img
                                    src="<?= roomsEscape($roomImage) ?>"
                                    alt="<?= roomsEscape($roomType) ?> room at Suva Hotel"
                                    loading="lazy"
                                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                >

                                <div
                                    class="room-image-placeholder"
                                    style="display:none;"
                                >
                                    <?= roomsEscape($roomType) ?>
                                </div>

                            <?php else: ?>

                                <div class="room-image-placeholder">
                                    <?= roomsEscape($roomType) ?>
                                </div>

                            <?php endif; ?>

                            <span class="room-status">
                                Available
                            </span>

                            <?php if ($roomNumber !== ''): ?>
                                <span class="room-number">
                                    Room <?= roomsEscape($roomNumber) ?>
                                </span>
                            <?php endif; ?>

                        </div>


                        <div class="room-card-body">

                            <span class="room-category">
                                Suva Hotel Accommodation
                            </span>

                            <h3>
                                <?= roomsEscape($roomType) ?> Room
                            </h3>

                            <p class="room-description">
                                <?= roomsEscape($roomDescription) ?>
                            </p>

                            <div class="room-divider"></div>

                            <div class="room-card-bottom">

                                <div class="room-price-area">
                                    <span class="room-price-label">
                                        Price per night
                                    </span>

                                    <div class="room-price">
                                        Rs.
                                        <?= number_format($roomPrice, 2) ?>
                                        <small>/ night</small>
                                    </div>
                                </div>

                                <a
                                    class="room-book-button"
                                    href="booking.php?room_id=<?= $roomId ?>"
                                    aria-label="Request booking for <?= roomsEscape($roomType) ?> Room"
                                >
                                    Book This Room &rarr;
                                </a>

                            </div>

                        </div>

                    </article>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>


        <div class="rooms-notice">
            <strong>Reservation information:</strong>
            Room availability can change. Sending a reservation request
            does not mean that your booking is confirmed. Our hotel team
            will review your request and confirm your reservation.
        </div>

    </section>


    <!-- =====================================================
         RESERVATION CALL TO ACTION
    ====================================================== -->

    <section class="rooms-cta">
        <div class="rooms-cta-inner">

            <span>A Memorable Stay Awaits</span>

            <h2>Your Comfort Is Our Priority.</h2>

            <p>
                Have questions about room facilities, availability or
                your planned stay? Our team is ready to help you choose
                the right accommodation.
            </p>

            <a href="contact.php">
                Talk to Our Team &rarr;
            </a>

        </div>
    </section>

</div>

<?php
/* ------------------------------------------------------------
   PAGE FOOTER
------------------------------------------------------------ */

require_once __DIR__ . '/includes/footer.php';
?>
