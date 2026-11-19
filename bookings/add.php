<?php
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

$role = $_SESSION['role'] ?? '';
$message = '';
$message_type = '';
$booking_created_id = 0;
$booking_sms_phone = '';

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$guest_result = pg_query($pgConnection, 'SELECT id, full_name FROM guests ORDER BY full_name ASC');
if (!$guest_result) {
    die('Guest Database Error: ' . h(pg_last_error($pgConnection)));
}

$room_result = pg_query($pgConnection, "
    SELECT id, room_number, room_type, price_per_night, price_per_hour, status
    FROM rooms
    WHERE status = 'available'
    ORDER BY room_number ASC
");
if (!$room_result) {
    die('Room Database Error: ' . h(pg_last_error($pgConnection)));
}
$rooms = [];
while ($room = pg_fetch_assoc($room_result)) {
    $rooms[] = $room;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_token = $_POST['csrf_token'] ?? '';
    if (!is_string($posted_token) || !hash_equals($_SESSION['csrf_token'], $posted_token)) {
        $message = 'Security check failed. Please reload the page and try again.';
        $message_type = 'error';
    } else {
        $guest_id = filter_var($_POST['guest_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $room_id = filter_var($_POST['room_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $stay_type = ($_POST['stay_type'] ?? 'nightly') === 'hourly' ? 'hourly' : 'nightly';
        $check_in = trim((string)($_POST['check_in'] ?? ''));
        $check_out = trim((string)($_POST['check_out'] ?? ''));
        $check_in_at_raw = trim((string)($_POST['check_in_at'] ?? ''));
        $check_out_at_raw = trim((string)($_POST['check_out_at'] ?? ''));
        $adults = filter_var($_POST['adults'] ?? 1, FILTER_VALIDATE_INT);
        $children = filter_var($_POST['children'] ?? 0, FILTER_VALIDATE_INT);
        $adults = $adults === false ? 0 : $adults;
        $children = $children === false ? -1 : $children;

        $start = null;
        $end = null;
        $duration_label = '';
        $units = 0.0;
        $price = 0.0;
        $room_charge = 0.0;
        $vat = 0.0;
        $total_amount = 0.0;

        if ($guest_id <= 0) {
            $message = 'Please select a guest.';
        } elseif ($room_id <= 0) {
            $message = 'Please select a room.';
        } elseif ($adults < 1) {
            $message = 'At least one adult is required.';
        } elseif ($children < 0) {
            $message = 'Children cannot be negative.';
        } else {
            if ($stay_type === 'hourly') {
                $start = DateTime::createFromFormat('!Y-m-d\TH:i', $check_in_at_raw);
                $end = DateTime::createFromFormat('!Y-m-d\TH:i', $check_out_at_raw);
                $start_errors = DateTime::getLastErrors();
                $end_errors = DateTime::getLastErrors();
                $start_bad = !$start || ($start_errors && ($start_errors['warning_count'] || $start_errors['error_count']));
                $end_bad = !$end || ($end_errors && ($end_errors['warning_count'] || $end_errors['error_count']));
                if ($start_bad || $end_bad) {
                    $message = 'Please enter valid hourly check-in and check-out date/time values.';
                } elseif ($end <= $start) {
                    $message = 'Hourly check-out must be after check-in.';
                } else {
                    $check_in = $start->format('Y-m-d');
                    $check_out = $end->format('Y-m-d');
                }
            } else {
                $start = DateTime::createFromFormat('!Y-m-d', $check_in);
                $end = DateTime::createFromFormat('!Y-m-d', $check_out);
                $start_errors = DateTime::getLastErrors();
                $end_errors = DateTime::getLastErrors();
                $start_bad = !$start || ($start_errors && ($start_errors['warning_count'] || $start_errors['error_count'])) || $start->format('Y-m-d') !== $check_in;
                $end_bad = !$end || ($end_errors && ($end_errors['warning_count'] || $end_errors['error_count'])) || $end->format('Y-m-d') !== $check_out;
                if ($start_bad || $end_bad) {
                    $message = 'Please enter valid check-in and check-out dates.';
                } elseif ($end <= $start) {
                    $message = 'Check-out date must be after check-in date.';
                }
            }

            if ($message === '') {
                $guest_check = pg_query_params($pgConnection, 'SELECT id FROM guests WHERE id = $1', [$guest_id]);
                if (!$guest_check || !pg_fetch_assoc($guest_check)) {
                    $message = 'Selected guest does not exist.';
                } else {
                    $room_check = pg_query_params($pgConnection, "
                        SELECT id, room_number, room_type, price_per_night, price_per_hour, status
                        FROM rooms WHERE id = $1
                    ", [$room_id]);
                    $room_data = $room_check ? pg_fetch_assoc($room_check) : false;
                    if (!$room_data) {
                        $message = 'Selected room does not exist.';
                    } elseif (strtolower((string)$room_data['status']) !== 'available') {
                        $message = 'This room is currently not available. Please select an available room.';
                    } elseif ($stay_type === 'hourly' && ($room_data['price_per_hour'] === null || (float)$room_data['price_per_hour'] <= 0)) {
                        $message = 'This room does not have a valid hourly rate. Please set its hourly rate first.';
                    } else {
                        // Legacy nightly bookings occupy their date range; hourly bookings use exact timestamps.
                        $overlap_sql = "
                            SELECT id FROM bookings
                            WHERE room_id = $1
                              AND LOWER(COALESCE(status, '')) <> 'cancelled'
                              AND (
                                CASE
                                  WHEN LOWER(COALESCE(stay_type, 'nightly')) = 'hourly' AND check_in_at IS NOT NULL
                                    THEN check_in_at
                                  ELSE check_in::timestamp
                                END
                              ) < $2::timestamp
                              AND (
                                CASE
                                  WHEN LOWER(COALESCE(stay_type, 'nightly')) = 'hourly' AND check_out_at IS NOT NULL
                                    THEN check_out_at
                                  ELSE check_out::timestamp
                                END
                              ) > $3::timestamp
                            LIMIT 1
                        ";
                        $overlap_start = $start->format('Y-m-d H:i:s');
                        $overlap_end = $end->format('Y-m-d H:i:s');
                        $overlap = pg_query_params($pgConnection, $overlap_sql, [$room_id, $overlap_end, $overlap_start]);
                        if (!$overlap) {
                            $message = 'Booking overlap check failed: ' . pg_last_error($pgConnection);
                        } elseif (pg_fetch_assoc($overlap)) {
                            $message = 'Room ' . $room_data['room_number'] . ' is already booked for part or all of the selected period.';
                        } else {
                            if ($stay_type === 'hourly') {
                                $price = (float)$room_data['price_per_hour'];
                                $seconds = $end->getTimestamp() - $start->getTimestamp();
                                $units = $seconds / 3600;
                                $duration_label = number_format($units, 2) . ' hours';
                                $check_in_at_db = $start->format('Y-m-d H:i:s');
                                $check_out_at_db = $end->format('Y-m-d H:i:s');
                            } else {
                                $price = (float)$room_data['price_per_night'];
                                $units = (float)$start->diff($end)->days;
                                $duration_label = number_format($units, 0) . ' night(s)';
                                $check_in_at_db = null;
                                $check_out_at_db = null;
                            }
                            $room_charge = round($price * $units, 2);
                            $vat = round($room_charge * 0.13, 2);
                            $total_amount = round($room_charge + $vat, 2);

                            $insert_sql = "
                                INSERT INTO bookings
                                    (guest_id, room_id, check_in, check_out, adults, children, status,
                                     total_amount, stay_type, check_in_at, check_out_at)
                                VALUES ($1, $2, $3, $4, $5, $6, 'Confirmed', $7, $8, $9, $10)
                                RETURNING id
                            ";
                            $insert = pg_query_params($pgConnection, $insert_sql, [
                                $guest_id, $room_id, $check_in, $check_out, $adults, $children,
                                $total_amount, $stay_type, $check_in_at_db, $check_out_at_db
                            ]);
                            if (!$insert) {
                                $message = 'Error creating booking: ' . pg_last_error($pgConnection);
                            } else {
                                $new_booking = pg_fetch_assoc($insert);
                                $booking_created_id = (int)$new_booking['id'];

                                // Keep existing nightly status behavior. Hourly bookings remain available in the room list;
                                // exact timestamp overlap checks prevent conflicting bookings.
                                if ($stay_type === 'nightly') {
                                    $updated = pg_query_params($pgConnection, "UPDATE rooms SET status = 'occupied' WHERE id = $1", [$room_id]);
                                    if (!$updated) {
                                        $message = 'Booking #' . $booking_created_id . ' was created, but room status could not be updated: ' . pg_last_error($pgConnection);
                                        $message_type = 'error';
                                    }
                                }
                                if ($message_type !== 'error') {
                                    $message = 'Booking created successfully! Room ' . $room_data['room_number'] .
                                        ' | Stay: ' . $duration_label . ' | Room charge: Rs. ' . number_format($room_charge, 2) .
                                        ' | VAT 13%: Rs. ' . number_format($vat, 2) .
                                        ' | Total: Rs. ' . number_format($total_amount, 2);
                                    $message_type = 'success';
                                }
                                $phone_result = pg_query_params($pgConnection, "
                                    SELECT g.phone FROM bookings b JOIN guests g ON g.id = b.guest_id WHERE b.id = $1
                                ", [$booking_created_id]);
                                if ($phone_result && ($phone_row = pg_fetch_assoc($phone_result))) {
                                    $booking_sms_phone = trim((string)($phone_row['phone'] ?? ''));
                                }
                                if ($message_type === 'success') {
                                    $_POST = [];
                                }
                            }
                        }
                    }
                }
            }
        }
        if ($message !== '' && $message_type === '') {
            $message_type = 'error';
        }
    }
}

// Re-query guests because result cursors may have been consumed after POST handling.
$guest_result = pg_query($pgConnection, 'SELECT id, full_name FROM guests ORDER BY full_name ASC');
if (!$guest_result) {
    die('Guest Database Error: ' . h(pg_last_error($pgConnection)));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Booking - Suva Hotel</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f6f8;margin:0;padding:24px;color:#222}.container{max-width:760px;margin:auto;background:#fff;padding:26px;border-radius:12px;box-shadow:0 4px 15px rgba(0,0,0,.1)}h1{color:#1f3c88}h2{color:#333}label{font-weight:bold;display:block;margin-top:15px}select,input{width:100%;padding:11px;margin-top:6px;box-sizing:border-box;border:1px solid #ccc;border-radius:6px}button{width:100%;margin-top:22px;padding:13px;background:#1f3c88;color:white;border:0;border-radius:6px;cursor:pointer;font-size:16px}button:hover{background:#162d68}.success{background:#e9f7ef;color:#176b35;padding:12px;border-radius:6px}.error{background:#fdeaea;color:#a11;padding:12px;border-radius:6px}.info{background:#eef4ff;padding:12px;border-radius:6px;margin:15px 0}.total-box{margin-top:20px;background:#f1f5ff;padding:15px;border-radius:8px}.links{margin-top:20px}.links a{color:#1f3c88;text-decoration:none;margin-right:15px}.links a:hover{text-decoration:underline}.hidden{display:none}.sms-box{background:#eaf7ee;border:1px solid #b7dfc3;padding:18px;margin:20px 0;border-radius:10px}.small-note{color:#555;font-size:13px}
</style>
</head>
<body>
<div class="container">
<h1>🏨 Suva Hotel</h1>
<h2>📋 Create New Booking</h2>
<?php if ($message !== ''): ?>
    <?php if ($message_type === 'success' && $booking_created_id > 0): ?>
    <div class="sms-box">
        <h3>📱 Booking Confirmation SMS</h3>
        <p>Booking #<?= (int)$booking_created_id ?> has been created successfully.</p>
        <?php if ($booking_sms_phone !== ''): ?>
            <p>Guest phone: <?= h($booking_sms_phone) ?></p>
            <form method="post" action="../send_booking_confirmation_sms.php" onsubmit="return confirm('Send a booking confirmation SMS to this guest?');">
                <input type="hidden" name="booking_id" value="<?= (int)$booking_created_id ?>">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <button type="submit" style="background:#198754">📩 Send Booking Confirmation SMS</button>
            </form>
        <?php else: ?>
            <p style="color:#b02a37">No phone number is saved for this guest. Please update the guest details before sending an SMS.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="<?= h($message_type) ?>"><?= h($message) ?></div>
<?php endif; ?>
<div class="info">💡 Choose a nightly or hourly stay. The system checks overlapping bookings. Hourly charges are calculated proportionally to the exact duration; VAT remains 13%.</div>
<form method="post" id="bookingForm">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
<label for="guest_id">👤 Guest</label>
<select name="guest_id" id="guest_id" required>
<option value="">-- Select Guest --</option>
<?php while ($guest = pg_fetch_assoc($guest_result)): ?>
<option value="<?= h($guest['id']) ?>" <?= (isset($_POST['guest_id']) && $_POST['guest_id'] == $guest['id']) ? 'selected' : '' ?>><?= h($guest['full_name']) ?></option>
<?php endwhile; ?>
</select>
<label for="room_id">🏨 Room</label>
<select name="room_id" id="room_id" required>
<option value="">-- Select Available Room --</option>
<?php foreach ($rooms as $room): ?>
<option value="<?= h($room['id']) ?>"
 data-night-price="<?= h($room['price_per_night']) ?>"
 data-hour-price="<?= h($room['price_per_hour'] ?? '') ?>"
 <?= (isset($_POST['room_id']) && $_POST['room_id'] == $room['id']) ? 'selected' : '' ?>>
Room <?= h($room['room_number']) ?> - <?= h($room['room_type']) ?> | Night: Rs. <?= number_format((float)$room['price_per_night'],2) ?> | Hour: <?= $room['price_per_hour'] === null ? 'not set' : 'Rs. '.number_format((float)$room['price_per_hour'],2) ?>
</option>
<?php endforeach; ?>
</select>
<label for="stay_type">🕒 Booking Type</label>
<select name="stay_type" id="stay_type">
<option value="nightly" <?= (($_POST['stay_type'] ?? 'nightly') === 'nightly') ? 'selected' : '' ?>>Nightly booking</option>
<option value="hourly" <?= (($_POST['stay_type'] ?? '') === 'hourly') ? 'selected' : '' ?>>Hourly booking</option>
</select>
<div id="nightlyFields">
<label for="check_in">📅 Check-in date</label>
<input type="date" name="check_in" id="check_in" value="<?= h($_POST['check_in'] ?? '') ?>">
<label for="check_out">📅 Check-out date</label>
<input type="date" name="check_out" id="check_out" value="<?= h($_POST['check_out'] ?? '') ?>">
</div>
<div id="hourlyFields" class="hidden">
<label for="check_in_at">🕒 Hourly check-in date and time</label>
<input type="datetime-local" name="check_in_at" id="check_in_at" value="<?= h($_POST['check_in_at'] ?? '') ?>" disabled>
<label for="check_out_at">🕒 Hourly check-out date and time</label>
<input type="datetime-local" name="check_out_at" id="check_out_at" value="<?= h($_POST['check_out_at'] ?? '') ?>" disabled>
<p class="small-note">Partial hours are charged proportionally. Example: 1.5 hours costs 1.5 × the room's hourly rate.</p>
</div>
<label for="adults">👨 Adults</label>
<input type="number" name="adults" id="adults" value="<?= h($_POST['adults'] ?? '1') ?>" min="1" required>
<label for="children">👶 Children</label>
<input type="number" name="children" id="children" value="<?= h($_POST['children'] ?? '0') ?>" min="0" required>
<div class="total-box">
<strong>💰 Booking Calculation (preview)</strong>
<p id="rateLabel">Price per night: Rs. <span id="price">0.00</span></p>
<p id="durationLabel">Number of nights: <span id="duration">0</span></p>
<p>Room charge: Rs. <span id="room_charge">0.00</span></p>
<p>VAT 13%: Rs. <span id="vat">0.00</span></p>
<h3>Total amount: Rs. <span id="total">0.00</span></h3>
<p class="small-note">The server recalculates the final amount when you submit.</p>
</div>
<button type="submit">💾 Create Booking</button>
</form>
<div class="links"><a href="view.php">📋 View Bookings</a>
<?php if ($role === 'admin'): ?><a href="../dashboard.php">← Admin Dashboard</a><?php else: ?><a href="../staff_dashboard/staff_dashboard.php">← Staff Dashboard</a><?php endif; ?>
</div>
</div>
<script>
const roomSelect = document.getElementById('room_id');
const stayType = document.getElementById('stay_type');
const nightlyFields = document.getElementById('nightlyFields');
const hourlyFields = document.getElementById('hourlyFields');
const checkIn = document.getElementById('check_in');
const checkOut = document.getElementById('check_out');
const checkInAt = document.getElementById('check_in_at');
const checkOutAt = document.getElementById('check_out_at');
function money(n){return (Number.isFinite(n) ? n : 0).toFixed(2);}
function resetPreview(){
 document.getElementById('price').textContent='0.00';
 document.getElementById('duration').textContent='0';
 document.getElementById('room_charge').textContent='0.00';
 document.getElementById('vat').textContent='0.00';
 document.getElementById('total').textContent='0.00';
}
function toggleStayFields(){
 const hourly = stayType.value === 'hourly';
 nightlyFields.classList.toggle('hidden', hourly);
 hourlyFields.classList.toggle('hidden', !hourly);
 checkIn.required = !hourly; checkOut.required = !hourly;
 checkIn.disabled = hourly; checkOut.disabled = hourly;
 checkInAt.required = hourly; checkOutAt.required = hourly;
 checkInAt.disabled = !hourly; checkOutAt.disabled = !hourly;
 document.getElementById('rateLabel').firstChild.textContent = hourly ? 'Price per hour: Rs. ' : 'Price per night: Rs. ';
 document.getElementById('durationLabel').firstChild.textContent = hourly ? 'Duration: ' : 'Number of nights: ';
 calculateBooking();
}
function calculateBooking(){
 const option = roomSelect.options[roomSelect.selectedIndex];
 if (!option || !option.value) { resetPreview(); return; }
 const hourly = stayType.value === 'hourly';
 const price = Number(hourly ? option.dataset.hourPrice : option.dataset.nightPrice);
 if (!Number.isFinite(price) || price <= 0) { resetPreview(); return; }
 let units = 0;
 if (hourly) {
   if (!checkInAt.value || !checkOutAt.value) { resetPreview(); document.getElementById('price').textContent=money(price); return; }
   const ms = new Date(checkOutAt.value).getTime() - new Date(checkInAt.value).getTime();
   if (ms <= 0) { resetPreview(); document.getElementById('price').textContent=money(price); return; }
   units = ms / 3600000;
   document.getElementById('duration').textContent = units.toFixed(2) + ' hour(s)';
 } else {
   if (!checkIn.value || !checkOut.value) { resetPreview(); document.getElementById('price').textContent=money(price); return; }
   const a = new Date(checkIn.value + 'T00:00:00');
   const b = new Date(checkOut.value + 'T00:00:00');
   const ms = b.getTime() - a.getTime();
   if (ms <= 0) { resetPreview(); document.getElementById('price').textContent=money(price); return; }
   units = ms / 86400000;
   document.getElementById('duration').textContent = units.toFixed(0) + ' night(s)';
 }
 const charge = Math.round((price * units + Number.EPSILON) * 100) / 100;
 const vat = Math.round((charge * 0.13 + Number.EPSILON) * 100) / 100;
 document.getElementById('price').textContent = money(price);
 document.getElementById('room_charge').textContent = money(charge);
 document.getElementById('vat').textContent = money(vat);
 document.getElementById('total').textContent = money(charge + vat);
}
stayType.addEventListener('change', toggleStayFields);
roomSelect.addEventListener('change', calculateBooking);
[checkIn,checkOut,checkInAt,checkOutAt].forEach(el=>el.addEventListener('change',calculateBooking));
toggleStayFields();
</script>
</body>
</html>
