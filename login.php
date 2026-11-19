<?php
// ============================================================
// SUVA HOTEL - PREMIUM LOGIN
// PostgreSQL Version
// File: /Suva_hotel/login.php
//
// SECURITY:
// - Maximum 4 failed login attempts
// - 15 minute lockout after 4 failed attempts
// - Successful login resets attempts
// ============================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . "/config/database.php";

// ============================================================
// SETTINGS
// ============================================================

$MAX_ATTEMPTS = 4;
$LOCKOUT_SECONDS = 15 * 60;

$error = "";
$success = "";

// ============================================================
// INITIALIZE LOGIN SECURITY SESSION
// ============================================================

if (!isset($_SESSION["login_attempts"])) {
    $_SESSION["login_attempts"] = 0;
}

if (!isset($_SESSION["login_lockout_until"])) {
    $_SESSION["login_lockout_until"] = 0;
}

// ============================================================
// CHECK LOCKOUT
// ============================================================

$now = time();
$lockoutUntil = (int) $_SESSION["login_lockout_until"];

if ($lockoutUntil > 0 && $now >= $lockoutUntil) {

    // Lockout finished
    $_SESSION["login_attempts"] = 0;
    $_SESSION["login_lockout_until"] = 0;

    $lockoutUntil = 0;
}

// ============================================================
// HANDLE LOGIN
// ============================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // --------------------------------------------------------
    // BLOCK LOGIN DURING LOCKOUT
    // --------------------------------------------------------

    if ($_SESSION["login_lockout_until"] > time()) {

        $remaining = $_SESSION["login_lockout_until"] - time();

        $minutes = floor($remaining / 60);
        $seconds = $remaining % 60;

        $error = "Too many failed attempts. Please wait "
               . $minutes . "m "
               . str_pad($seconds, 2, "0", STR_PAD_LEFT)
               . "s.";

    } else {

        $username = trim($_POST["username"] ?? "");
        $password = $_POST["password"] ?? "";

        // ----------------------------------------------------
        // VALIDATION
        // ----------------------------------------------------

        if ($username === "" || $password === "") {

            $error = "Please enter both username and password.";

        } else {

            // ------------------------------------------------
            // FIND USER
            // ------------------------------------------------

            $sql = "
                SELECT id, username, role, password
                FROM users
                WHERE username = $1
                LIMIT 1
            ";

            $result = pg_query_params(
                $pgConnection,
                $sql,
                [$username]
            );

            $user = false;

            if ($result) {
                $user = pg_fetch_assoc($result);
            }

            // ------------------------------------------------
            // VERIFY PASSWORD
            // ------------------------------------------------

            if (!$user || !password_verify($password, $user["password"])) {

                $_SESSION["login_attempts"]++;

                $attempts = $_SESSION["login_attempts"];

                if ($attempts >= $MAX_ATTEMPTS) {

                    // ----------------------------------------
                    // LOCK ACCOUNT
                    // ----------------------------------------

                    $_SESSION["login_lockout_until"] =
                        time() + $LOCKOUT_SECONDS;

                    $error = "Too many failed login attempts. "
                           . "Login is locked for 15 minutes.";

                } else {

                    $remainingAttempts =
                        $MAX_ATTEMPTS - $attempts;

                    $error = "Invalid username or password. "
                           . $remainingAttempts
                           . " attempt"
                           . ($remainingAttempts == 1 ? "" : "s")
                           . " remaining.";
                }

            } else {

                // ------------------------------------------------
                // SUCCESSFUL LOGIN
                // ------------------------------------------------

                session_regenerate_id(true);

                $_SESSION["user_id"] = $user["id"];
                $_SESSION["username"] = $user["username"];
                $_SESSION["role"] = strtolower(trim($user["role"]));

                // Reset login security
                $_SESSION["login_attempts"] = 0;
                $_SESSION["login_lockout_until"] = 0;

                // ------------------------------------------------
                // REDIRECT BY ROLE
                // ------------------------------------------------

                if ($_SESSION["role"] === "admin") {

                    header("Location: dashboard.php");
                    exit;

                } elseif ($_SESSION["role"] === "staff") {

                    header("Location: staff_dashboard/staff_dashboard.php");
                    exit;

                } else {

                    $error = "Your account has an invalid role.";

                    // Remove login session if role is invalid
                    unset($_SESSION["user_id"]);
                    unset($_SESSION["username"]);
                    unset($_SESSION["role"]);
                }
            }
        }
    }
}

// ============================================================
// LOGIN STATUS
// ============================================================

$currentAttempts = (int) $_SESSION["login_attempts"];

$attemptsRemaining =
    max(0, $MAX_ATTEMPTS - $currentAttempts);

$isLocked =
    $_SESSION["login_lockout_until"] > time();

$lockoutRemaining =
    $isLocked
        ? $_SESSION["login_lockout_until"] - time()
        : 0;

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Suva Hotel | Login</title>

    <!-- Google Font -->
    <link
        href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap"
        rel="stylesheet"
    >

    <!-- Font Awesome -->
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html,
        body {
            width: 100%;
            min-height: 100%;
            <img src="assets/images/hotel-lighting.jpg"
     style="position:fixed; inset:0; width:100%; height:100%; object-fit:cover; z-index:-1;">
        }

        body {

            min-height: 100vh;

            /*
             * HOTEL BACKGROUND IMAGE
             *
             * login.php is inside /Suva_hotel/
             * Image is inside /Suva_hotel/assets/image/
             */

            background:
                linear-gradient(
                    rgba(5, 7, 12, 0.42),
                    rgba(5, 7, 12, 0.58)
                ),
                url("assets/images/hotel-lighting.jpg")
                center center / cover no-repeat fixed;

            font-family: "Montserrat", sans-serif;

            color: #ffffff;

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 20px;

            position: relative;

            overflow-x: hidden;
        }

        /* =====================================================
           DARK OVERLAY
        ===================================================== */

        body::before {

            content: "";

            position: fixed;

            inset: 0;

            background:
                radial-gradient(
                    circle at center,
                    rgba(212, 175, 55, 0.06),
                    transparent 55%
                );

            pointer-events: none;

            z-index: 0;
        }

        /* =====================================================
           LOGIN CARD
        ===================================================== */

        .login-card {

            width: 100%;

            max-width: 390px;

            padding: 30px 32px 27px;

            position: relative;

            z-index: 2;

            background:
                rgba(10, 12, 18, 0.86);

            border:
                1px solid rgba(212, 175, 55, 0.28);

            border-radius: 18px;

            box-shadow:
                0 25px 70px rgba(0, 0, 0, 0.65),
                0 0 35px rgba(212, 175, 55, 0.08);

            backdrop-filter: blur(13px);

            -webkit-backdrop-filter: blur(13px);
        }

        /* =====================================================
           TOP GOLD LINE
        ===================================================== */

        .login-card::before {

            content: "";

            position: absolute;

            top: 0;

            left: 50%;

            transform: translateX(-50%);

            width: 95px;

            height: 3px;

            background: #d4af37;

            border-radius: 0 0 5px 5px;

            box-shadow:
                0 0 15px rgba(212, 175, 55, 0.5);
        }

        /* =====================================================
           BRAND
        ===================================================== */

        .brand {

            text-align: center;

            margin-bottom: 23px;
        }

        .brand-icon {

            width: 55px;

            height: 55px;

            margin: 0 auto 10px;

            border-radius: 50%;

            border:
                1px solid rgba(212, 175, 55, 0.65);

            display: flex;

            align-items: center;

            justify-content: center;

            color: #d4af37;

            font-size: 22px;

            background:
                rgba(212, 175, 55, 0.08);

            box-shadow:
                0 0 20px rgba(212, 175, 55, 0.12);
        }

        .brand h1 {

            font-family: "Playfair Display", serif;

            font-size: 29px;

            letter-spacing: 2px;

            color: #d4af37;

            margin-bottom: 4px;
        }

        .brand p {

            font-size: 10px;

            letter-spacing: 3px;

            color: rgba(255, 255, 255, 0.60);

            text-transform: uppercase;
        }

        /* =====================================================
           ERROR
        ===================================================== */

        .error {

            background:
                rgba(220, 53, 69, 0.12);

            border:
                1px solid rgba(220, 53, 69, 0.35);

            color: #ff9b9b;

            padding: 10px 12px;

            border-radius: 8px;

            font-size: 12px;

            line-height: 1.5;

            margin-bottom: 16px;

            text-align: center;
        }

        /* =====================================================
           SUCCESS
        ===================================================== */

        .success {

            background:
                rgba(40, 167, 69, 0.12);

            border:
                1px solid rgba(40, 167, 69, 0.35);

            color: #8ee6a0;

            padding: 10px 12px;

            border-radius: 8px;

            font-size: 12px;

            margin-bottom: 16px;

            text-align: center;
        }

        /* =====================================================
           FORM
        ===================================================== */

        .form-group {

            margin-bottom: 14px;
        }

        .form-group label {

            display: block;

            font-size: 11px;

            font-weight: 600;

            letter-spacing: 0.8px;

            color: rgba(255, 255, 255, 0.72);

            margin-bottom: 7px;
        }

        .input-box {

            position: relative;
        }

        .input-box > i {

            position: absolute;

            left: 13px;

            top: 50%;

            transform: translateY(-50%);

            color: #d4af37;

            font-size: 13px;

            pointer-events: none;
        }

        .input-box input {

            width: 100%;

            height: 43px;

            padding:
                0 42px
                0 38px;

            background:
                rgba(255, 255, 255, 0.055);

            border:
                1px solid rgba(255, 255, 255, 0.12);

            border-radius: 8px;

            outline: none;

            color: #ffffff;

            font-family: inherit;

            font-size: 13px;

            transition: 0.25s;
        }

        .input-box input::placeholder {

            color:
                rgba(255, 255, 255, 0.32);
        }

        .input-box input:focus {

            border-color:
                rgba(212, 175, 55, 0.75);

            background:
                rgba(255, 255, 255, 0.075);

            box-shadow:
                0 0 0 3px rgba(212, 175, 55, 0.07);
        }

        .input-box input:disabled {

            opacity: 0.45;

            cursor: not-allowed;
        }

        /* =====================================================
           PASSWORD TOGGLE
        ===================================================== */

        .password-toggle {

            position: absolute;

            right: 13px;

            top: 50%;

            transform: translateY(-50%);

            color:
                rgba(255, 255, 255, 0.45);

            cursor: pointer;

            font-size: 13px;

            transition: 0.2s;
        }

        .password-toggle:hover {

            color: #d4af37;
        }

        /* =====================================================
           ATTEMPTS REMAINING
        ===================================================== */

        .attempt-status {

            margin-top: -3px;

            margin-bottom: 15px;

            text-align: center;

            font-size: 11px;

            color:
                rgba(255, 255, 255, 0.55);
        }

        .attempt-status strong {

            color: #d4af37;

            font-weight: 600;
        }

        .attempt-dots {

            display: flex;

            justify-content: center;

            gap: 6px;

            margin-top: 7px;
        }

        .attempt-dot {

            width: 7px;

            height: 7px;

            border-radius: 50%;

            background:
                rgba(255, 255, 255, 0.18);

            border:
                1px solid rgba(255, 255, 255, 0.15);

            transition: 0.25s;
        }

        .attempt-dot.used {

            background: #c94b4b;

            border-color: #c94b4b;

            box-shadow:
                0 0 7px rgba(201, 75, 75, 0.35);
        }

        /* =====================================================
           LOCKOUT
        ===================================================== */

        .lockout {

            margin-top: 4px;

            margin-bottom: 15px;

            padding: 11px;

            text-align: center;

            border-radius: 8px;

            background:
                rgba(220, 53, 69, 0.10);

            border:
                1px solid rgba(220, 53, 69, 0.30);

            color: #ff9b9b;

            font-size: 11px;

            line-height: 1.6;
        }

        .lockout i {

            margin-right: 5px;
        }

        .countdown {

            color: #d4af37;

            font-weight: 700;

            font-size: 13px;
        }

        /* =====================================================
           LOGIN BUTTON
        ===================================================== */

        .login-btn {

            width: 100%;

            height: 44px;

            border: none;

            border-radius: 8px;

            background:
                linear-gradient(
                    135deg,
                    #d4af37,
                    #b8860b
                );

            color: #111;

            font-family: inherit;

            font-size: 12px;

            font-weight: 700;

            letter-spacing: 1.2px;

            text-transform: uppercase;

            cursor: pointer;

            transition: 0.25s;

            box-shadow:
                0 7px 18px rgba(212, 175, 55, 0.20);
        }

        .login-btn:hover:not(:disabled) {

            transform: translateY(-1px);

            box-shadow:
                0 10px 25px rgba(212, 175, 55, 0.30);
        }

        .login-btn:active:not(:disabled) {

            transform: translateY(0);
        }

        .login-btn:disabled {

            opacity: 0.40;

            cursor: not-allowed;

            box-shadow: none;
        }

        /* =====================================================
           FOOTER
        ===================================================== */

        .login-footer {

            margin-top: 19px;

            padding-top: 15px;

            border-top:
                1px solid rgba(255, 255, 255, 0.08);

            text-align: center;

            font-size: 9px;

            letter-spacing: 0.8px;

            color:
                rgba(255, 255, 255, 0.35);
        }

        .login-footer span {

            color: #d4af37;
        }

        /* =====================================================
           MOBILE
        ===================================================== */

        @media (max-width: 480px) {

            body {

                padding: 14px;

            }

            .login-card {

                max-width: 370px;

                padding:
                    27px 23px 23px;
            }

            .brand h1 {

                font-size: 26px;
            }

            .brand-icon {

                width: 50px;

                height: 50px;
            }
        }

        /* =====================================================
           SHORT LAPTOP / SMALL HEIGHT
        ===================================================== */

        @media (max-height: 650px) {

            body {

                align-items: flex-start;

                padding-top: 15px;

                padding-bottom: 15px;
            }

            .login-card {

                padding-top: 22px;

                padding-bottom: 19px;
            }

            .brand {

                margin-bottom: 15px;
            }

            .brand-icon {

                width: 43px;

                height: 43px;

                margin-bottom: 7px;

                font-size: 17px;
            }

            .brand h1 {

                font-size: 24px;
            }

            .form-group {

                margin-bottom: 10px;
            }

            .input-box input {

                height: 39px;
            }

            .login-btn {

                height: 40px;
            }

            .login-footer {

                margin-top: 12px;

                padding-top: 10px;
            }
        }

    </style>

</head>

<body>

    <div class="login-card">

        <!-- ==================================================
             BRAND
        =================================================== -->

        <div class="brand">

            <div class="brand-icon">
                <i class="fa-solid fa-hotel"></i>
            </div>

            <h1>SUVA HOTEL</h1>

            <p>Management System</p>

        </div>


        <!-- ==================================================
             ERROR MESSAGE
        =================================================== -->

        <?php if ($error !== ""): ?>

            <div class="error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?= htmlspecialchars($error) ?>
            </div>

        <?php endif; ?>


        <!-- ==================================================
             SUCCESS MESSAGE
        =================================================== -->

        <?php if ($success !== ""): ?>

            <div class="success">
                <i class="fa-solid fa-circle-check"></i>
                <?= htmlspecialchars($success) ?>
            </div>

        <?php endif; ?>


        <!-- ==================================================
             LOCKOUT MESSAGE
        =================================================== -->

        <?php if ($isLocked): ?>

            <div class="lockout">

                <div>
                    <i class="fa-solid fa-lock"></i>
                    Login temporarily locked
                </div>

                <div>
                    Try again in
                    <span
                        class="countdown"
                        id="countdown"
                    >
                        15:00
                    </span>
                </div>

            </div>

        <?php endif; ?>


        <!-- ==================================================
             LOGIN FORM
        =================================================== -->

        <form
            method="POST"
            action=""
            autocomplete="off"
        >

            <!-- USERNAME -->

            <div class="form-group">

                <label for="username">
                    Username
                </label>

                <div class="input-box">

                    <i class="fa-solid fa-user"></i>

                    <input
                        type="text"
                        id="username"
                        name="username"
                        placeholder="Enter your username"
                        autocomplete="username"
                        maxlength="100"
                        <?= $isLocked ? "disabled" : "" ?>
                        required
                    >

                </div>

            </div>


            <!-- PASSWORD -->

            <div class="form-group">

                <label for="password">
                    Password
                </label>

                <div class="input-box">

                    <i class="fa-solid fa-lock"></i>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        maxlength="255"
                        <?= $isLocked ? "disabled" : "" ?>
                        required
                    >

                    <span
                        class="password-toggle"
                        id="passwordToggle"
                    >
                        <i class="fa-solid fa-eye"></i>
                    </span>

                </div>

            </div>


            <!-- ==================================================
                 LOGIN ATTEMPTS REMAINING
            =================================================== -->

            <div class="attempt-status">

                Login attempts remaining:
                <strong>
                    <?= $attemptsRemaining ?> / <?= $MAX_ATTEMPTS ?>
                </strong>

                <div class="attempt-dots">

                    <?php for ($i = 1; $i <= $MAX_ATTEMPTS; $i++): ?>

                        <span
                            class="attempt-dot <?= $i <= $currentAttempts ? "used" : "" ?>"
                        ></span>

                    <?php endfor; ?>

                </div>

            </div>


            <!-- ==================================================
                 LOGIN BUTTON
            =================================================== -->

            <button
                type="submit"
                class="login-btn"
                <?= $isLocked ? "disabled" : "" ?>
            >

                <?php if ($isLocked): ?>

                    <i class="fa-solid fa-lock"></i>
                    Locked

                <?php else: ?>

                    <i class="fa-solid fa-right-to-bracket"></i>
                    Login

                <?php endif; ?>

            </button>

        </form>


        <!-- ==================================================
             FOOTER
        =================================================== -->

        <div class="login-footer">

            <span>SUVA HOTEL</span>
            &nbsp;•&nbsp;
            Secure Management Portal

        </div>

    </div>


    <!-- ======================================================
         JAVASCRIPT
    ======================================================= -->

    <script>

        // ====================================================
        // PASSWORD SHOW / HIDE
        // ====================================================

        const passwordInput =
            document.getElementById("password");

        const passwordToggle =
            document.getElementById("passwordToggle");

        if (passwordToggle && passwordInput) {

            passwordToggle.addEventListener("click", function () {

                const icon =
                    this.querySelector("i");

                if (passwordInput.type === "password") {

                    passwordInput.type = "text";

                    icon.classList.remove("fa-eye");

                    icon.classList.add("fa-eye-slash");

                } else {

                    passwordInput.type = "password";

                    icon.classList.remove("fa-eye-slash");

                    icon.classList.add("fa-eye");
                }

            });

        }


        // ====================================================
        // LOCKOUT COUNTDOWN
        // ====================================================

        <?php if ($isLocked): ?>

        let remaining =
            <?= max(0, (int)$lockoutRemaining) ?>;

        const countdown =
            document.getElementById("countdown");

        function updateCountdown() {

            if (remaining <= 0) {

                if (countdown) {
                    countdown.textContent = "00:00";
                }

                location.reload();

                return;
            }

            const minutes =
                Math.floor(remaining / 60);

            const seconds =
                remaining % 60;

            if (countdown) {

                countdown.textContent =
                    String(minutes).padStart(2, "0")
                    + ":"
                    + String(seconds).padStart(2, "0");
            }

            remaining--;
        }

        updateCountdown();

        setInterval(updateCountdown, 1000);

        <?php endif; ?>

    </script>

</body>

</html>