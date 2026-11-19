
<?php
// SUVA HOTEL - Guest Welcome QR Generator

session_start();

// Protect this page: hotel staff must log in first.
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';

$db = $pgConnection ?? false;

if (!$db) {
    exit("Database connection is unavailable.");
}

// Escape values before displaying them in HTML.
function h($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

// CSRF protection for the staff form.
if (empty($_SESSION['welcome_csrf'])) {
    $_SESSION['welcome_csrf'] = bin2hex(
        random_bytes(32)
    );
}

// Build the default URL from the address used to open this page.
$protocol = (
    !empty($_SERVER['HTTPS']) &&
    $_SERVER['HTTPS'] !== 'off'
) ? 'https' : 'http';

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

$directory = str_replace(
    '\\',
    '/',
    dirname($_SERVER['SCRIPT_NAME'] ?? '/guest_welcome.php')
);

$directory = ($directory === '/')
    ? ''
    : rtrim($directory, '/');

$defaultBaseUrl = $protocol . '://' . $host . $directory;

$baseUrl = trim($_POST['base_url'] ?? $defaultBaseUrl);
$error = '';
$generatedLink = '';
$bookingId = (int)($_POST['booking_id'] ?? 0);

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'generate'
) {
    $csrf = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrf) ||
        !hash_equals($_SESSION['welcome_csrf'], $csrf)
    ) {
        $error = 'Security check failed. Reload the page and try again.';
    } elseif ($bookingId <= 0) {
        $error = 'Please select a booking.';
    } else {
        $baseUrl = rtrim($baseUrl, '/');
        $parts = parse_url($baseUrl);

        $validBaseUrl =
            filter_var($baseUrl, FILTER_VALIDATE_URL) !== false &&
            is_array($parts) &&
            isset($parts['host']) &&
            in_array(
                strtolower($parts['scheme'] ?? ''),
                ['http', 'https'],
                true
            ) &&
            !isset($parts['query']) &&
            !isset($parts['fragment']);

        if (!$validBaseUrl) {
            $error = 'Enter a valid base URL, including http:// or https://.';
        } else {
            $bookingCheck = pg_query_params(
                $db,
                'SELECT id
                 FROM public.bookings
                 WHERE id = $1',
                [$bookingId]
            );

            if (!$bookingCheck || pg_num_rows($bookingCheck) === 0) {
                $error = 'The selected booking was not found.';
            } else {
                // The original token is shown only when generated.
                // PostgreSQL stores only its SHA-256 hash.
                try {
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);

                    $began = pg_query($db, 'BEGIN');

                    if (!$began) {
                        throw new RuntimeException('Could not start transaction.');
                    }

                    // Revoke any previous active link for this booking.
                    $revoked = pg_query_params(
                        $db,
                        'UPDATE public.guest_welcome_links
                         SET is_active = FALSE,
                             revoked_at = CURRENT_TIMESTAMP
                         WHERE booking_id = $1
                           AND is_active = TRUE',
                        [$bookingId]
                    );

                    if (!$revoked) {
                        throw new RuntimeException(
                            'Could not revoke previous link.'
                        );
                    }

                    $inserted = pg_query_params(
                        $db,
                        'INSERT INTO public.guest_welcome_links
                            (booking_id, token_hash, is_active)
                         VALUES ($1, $2, TRUE)',
                        [$bookingId, $tokenHash]
                    );

                    if (!$inserted) {
                        throw new RuntimeException(
                            'Could not save welcome link.'
                        );
                    }

                    if (!pg_query($db, 'COMMIT')) {
                        throw new RuntimeException(
                            'Could not commit transaction.'
                        );
                    }

                    $generatedLink =
                        $baseUrl .
                        '/welcome.php?t=' .
                        rawurlencode($token);

                } catch (Throwable $e) {
                    @pg_query($db, 'ROLLBACK');

                    error_log(
                        'Suva Hotel QR error: ' . $e->getMessage()
                    );

                    $error =
                        'Could not generate the QR link. ' .
                        'Check that the guest_welcome_links table exists.';
                }
            }
        }
    }
}

// Get bookings for the selection list.
$bookingsResult = pg_query(
    $db,
    'SELECT
        b.id,
        g.full_name,
        r.room_number,
        b.check_in,
        b.check_out,
        b.status
     FROM public.bookings b
     JOIN public.guests g ON g.id = b.guest_id
     LEFT JOIN public.rooms r ON r.id = b.room_id
     ORDER BY b.id DESC'
);

if (!$bookingsResult) {
    error_log('Suva Hotel booking list: ' . pg_last_error($db));
    exit(
        'Could not load bookings. Check the database table structure.'
    );
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Guest Welcome QR | SUVA HOTEL</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 24px;
            background: #f3f5f8;
            color: #202b3c;
            font-family: Arial, sans-serif;
        }

        .container {
            max-width: 850px;
            margin: 20px auto;
        }

        .header {
            padding: 26px;
            border-radius: 14px 14px 0 0;
            background: #15243a;
            color: white;
        }

        .header h1 {
            margin: 0 0 8px;
            letter-spacing: 2px;
        }

        .panel {
            padding: 25px;
            margin-bottom: 20px;
            background: white;
            border-radius: 0 0 14px 14px;
            box-shadow: 0 5px 20px #15243a0c;
        }

        label {
            display: block;
            margin: 18px 0 8px;
            font-weight: bold;
        }

        input, select {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd2dc;
            border-radius: 7px;
            background: white;
            font-size: 15px;
        }

        button {
            margin-top: 20px;
            padding: 13px 18px;
            border: 0;
            border-radius: 7px;
            background: #b99252;
            color: #fff;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
        }

        .message {
            padding: 14px;
            margin-bottom: 18px;
            border-radius: 7px;
        }

        .error {
            background: #fff0f0;
            color: #a51d2d;
        }

        .result {
            margin-top: 25px;
            padding: 22px;
            border: 1px solid #d9e4dc;
            border-radius: 12px;
            text-align: center;
        }

        #qrcode {
            display: flex;
            justify-content: center;
            margin: 20px auto;
            min-height: 20px;
        }

        .link {
            overflow-wrap: anywhere;
            padding: 12px;
            background: #f3f5f8;
            border-radius: 7px;
            text-align: left;
        }

        .note {
            color: #596579;
            font-size: 14px;
            line-height: 1.6;
        }

        a {
            color: #264c80;
        }

        @media (max-width: 600px) {
            body {
                padding: 12px;
            }

            .panel {
                padding: 17px;
            }
        }
    </style>
</head>

<body>
<div class="container">

    <div class="header">
        <h1>SUVA HOTEL</h1>
        <div>Guest Welcome Management</div>
    </div>

    <div class="panel">

        <h2>Generate Guest QR Code</h2>

        <p class="note">
            Select a booking and create a personal welcome link.
            Only the newest active link for that booking will work.
        </p>

        <?php if ($error !== ''): ?>
            <div class="message error">
                <?= h($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= h($_SESSION['welcome_csrf']) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="generate"
            >

            <label for="booking_id">Select Guest Booking</label>

            <select id="booking_id" name="booking_id" required>
                <option value="">-- Select a booking --</option>

                <?php while ($row = pg_fetch_assoc($bookingsResult)): ?>

                    <option
                        value="<?= (int)$row['id'] ?>"
                        <?= $bookingId === (int)$row['id']
                            ? 'selected'
                            : '' ?>
                    >
                        <?= h(
                            'Booking #' . $row['id'] .
                            ' - ' . $row['full_name'] .
                            ' - Room ' . ($row['room_number'] ?? 'Not assigned') .
                            ' - ' . $row['check_in'] .
                            ' to ' . $row['check_out']
                        ) ?>
                    </option>

                <?php endwhile; ?>
            </select>

            <label for="base_url">
                Website Base URL
            </label>

            <input
                type="url"
                id="base_url"
                name="base_url"
                value="<?= h($baseUrl) ?>"
                placeholder="https://your-hotel-domain.com/Suva_hotel"
                required
            >

            <p class="note">
                For phone scanning, this must be an address reachable
                by the guest's phone. Do not use localhost for a guest
                scanning from another device.
            </p>

            <button type="submit">
                Generate QR Code
            </button>

        </form>

        <?php if ($generatedLink !== ''): ?>

            <div class="result">
                <h2>QR Code Created</h2>

                <p>
                    Scan this code with a phone to open the guest
                    welcome page.
                </p>

                <div id="qrcode"></div>

                <div class="link" id="generated_link">
                    <?= h($generatedLink) ?>
                </div>

                <button type="button" id="copy_link">
                    Copy Welcome Link
                </button>

                <p class="note">
                    Keep this QR code private and provide it only to
                    the intended guest. Generating a replacement
                    deactivates the previous link for this booking.
                </p>

                <div id="qr_error" class="note"></div>
            </div>

            <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

            <script>
                const welcomeLink = <?= json_encode(
                    $generatedLink,
                    JSON_HEX_TAG |
                    JSON_HEX_APOS |
                    JSON_HEX_QUOT |
                    JSON_HEX_AMP
                ) ?>;

                const qrBox = document.getElementById('qrcode');

                if (typeof QRCode !== 'undefined') {
                    new QRCode(qrBox, {
                        text: welcomeLink,
                        width: 230,
                        height: 230,
                        correctLevel: QRCode.CorrectLevel.M
                    });
                } else {
                    document.getElementById('qr_error').textContent =
                        'QR generator did not load. Check your internet connection.';
                }

                document.getElementById('copy_link')
                    .addEventListener('click', async function () {
                        try {
                            await navigator.clipboard.writeText(welcomeLink);
                            this.textContent = 'Copied!';
                        } catch (error) {
                            this.textContent =
                                'Copy unavailable. Select and copy the link above.';
                        }
                    });
            </script>

        <?php endif; ?>

        <p>
            <a href="dashboard.php">← Back to Dashboard</a>
        </p>

    </div>
</div>
</body>
</html>
