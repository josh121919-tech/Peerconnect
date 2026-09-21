<?php

/**
 * login.php — the sign-in page.
 *
 * Lifted out of the modal that used to live in home.view.php. Every guard came
 * with it unchanged: CSRF, the 5-attempt / 3-minute lockout, the length and
 * format checks, and the reCAPTCHA verification.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../../services/RememberService.php';
require_once __DIR__ . '/../../services/LoginTokenService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$login_error = '';
// pc_enforce_account_status() lands here with ?blocked=1 after signing out an
// account an admin blocked mid-session. Without this the redirect looked like
// an ordinary logout and the person had no idea why.
if (isset($_GET['blocked'])) {
    $login_error = SignInService::BLOCKED_MESSAGE;
}
if (isset($_SESSION['login_error'])) {
    $login_error = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

// Set by reset_password.php after a successful change, so the visitor is told
// why they are being asked to sign in again.
$login_notice = '';
if (isset($_SESSION['login_notice'])) {
    $login_notice = $_SESSION['login_notice'];
    unset($_SESSION['login_notice']);
}

/**
 * A real bcrypt hash (cost 10) of a random value nobody holds, used to keep an
 * unknown email as slow to reject as a known one. See where it is used below.
 */
if (!defined('PC_LOGIN_DUMMY_HASH')) {
    define('PC_LOGIN_DUMMY_HASH', '$2y$10$33tyLMF9N4RKQe695YaOKOwcr4QIDFaGDjhVBd5PCi2yTw9r6RonG');
}

// Already signed in? Nothing to do here — but where they go next is read from
// the account, not from the session.
//
// This used to switch on $_SESSION['role'] alone and send anyone holding a
// session to a dashboard. Signup created a session of its own, so registering
// and then coming back to this page walked into the application without ever
// entering a password: the reported bug. The state now comes from the
// database every time, and pc_verification_gate() enforces the same answer on
// every other page.
if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    $current = UserRepository::signInState($con, (int)$_SESSION['user_id']);
    if ($current) {
        header("Location: " . SignInService::destination(
            (string)$current['role'],
            $current['status'],
            $current['verified'],
            $con,
            $current['email_verified_at'] ?? null
        ));
        exit;
    }
    // The account went away while the session lived on. Drop the session and
    // let the form render rather than redirecting into nothing.
    $_SESSION = [];
}

// A valid "Remember me" cookie signs the visitor straight back in — but only
// when they are merely arriving.
//
// It must NOT run for a submitted form. attempt() used to fire on every
// request to this page, so posting valid credentials while the browser held
// somebody else's remember cookie signed you in as THEM: the credentials were
// never looked at. On a shared campus machine that means the second student to
// sit down lands in the first one's account.
//
// ?switch=1 forces the form for the same reason — a way to reach it without
// first hunting down the previous person's Log Out.
$is_login_post = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login']);
$wants_form    = isset($_GET['switch']);

if (!$is_login_post && !$wants_form && ($remembered = RememberService::attempt($con))) {
    $row = UserRepository::signInState($con, (int)$remembered);
    if ($row) {
        header("Location: " . SignInService::destination(
            (string)$row['role'],
            $row['status'],
            $row['verified'],
            $con,
            $row['email_verified_at'] ?? null
        ));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (!verify_csrf()) {
        $_SESSION['login_error'] = "Invalid request. Please try again.";
        header("Location: " . url('login'));
        exit;
    }

    // A field sent as a list counts as empty; trim() used to fail on it.
    $field    = fn(string $name): string => is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
    $email    = trim($field('email'));
    $password = trim($field('password'));
    $remember = !empty($_POST['remember']);

    // How many tries and how long the lock lasts come from System Settings →
    // Security — the same source admin/login.php already reads. This handler
    // used to hardcode 5 tries and 3 minutes, so the numbers an admin chose on
    // that page did nothing here.
    $lock_on   = pc_setting_bool($con, 'login_lockout_enable');
    $max_tries = max(1, pc_setting_int($con, 'login_max_attempts', 5));
    $lock_secs = max(1, pc_setting_int($con, 'login_lockout_mins', 5)) * 60;

    // Keyed on the account and the caller's address, stored in the database.
    // The counter used to live in $_SESSION, so anyone who discarded the
    // session cookie between attempts was never locked out at all — it only
    // ever stopped honest users who mistyped.
    $throttle    = substr('login:' . strtolower($email) . '|' . pc_client_ip(), 0, 190);
    $retry_after = $lock_on ? pc_throttle_retry_after($con, $throttle, $lock_secs, $max_tries) : 0;

    if ($retry_after > 0) {
        $login_error = "Too many failed attempts. Try again in " . floor($retry_after / 60) . "m " . ($retry_after % 60) . "s.";
    } else {

        if (strlen($email) > 100 || strlen($password) > 20) {
            $login_error = "Invalid input length.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $login_error = "Invalid email format.";
        }

        if ($login_error === '') {
            if (!CaptchaService::verify($_POST['g-recaptcha-response'] ?? null)) {
                $login_error = "Please complete the CAPTCHA verification.";
            } elseif ($email === '' || $password === '') {
                $login_error = "All fields are required.";
            } else {
                $account     = UserRepository::signInByEmail($con, $email);
                $found       = $account !== null;
                $user_id     = $account['user_id'] ?? null;
                $role        = $account['role'] ?? null;
                $user_status = $account['status'] ?? null;
                $verified    = $account['verified'] ?? null;
                $confirmed   = $account['email_verified_at'] ?? null;

                // An account made with Google has no password, and cannot
                // sign in with one until its owner sets one.
                $password_id   = null;
                $password_hash = '';
                if ($found) {
                    $pw = PasswordRepository::forUser($con, (int)$user_id);
                    if ($pw === null) {
                        $found = false;
                    } else {
                        $password_id   = $pw['password_id'];
                        $password_hash = $pw['password_hash'];
                    }
                }

                // Verify the password FIRST, then decide what to say.
                //
                // The blocked check used to sit ahead of this, so typing any
                // address with any junk password told you whether that account
                // existed and was blocked — the exact enumeration hole the
                // generic message below exists to close. It also skipped the
                // attempt counter, so those probes were unlimited.
                //
                // Someone who proves they own the account is still told plainly
                // why they cannot get in.
                $password_ok = $found && password_verify($password, $password_hash);

                // Spend the same time on an unknown address as on a real one.
                // Without this a missing account answers in well under a
                // millisecond while a real one costs the ~48ms bcrypt takes
                // here, which is an enumeration oracle of its own.
                //
                // The hash below is real (cost 10, matching PASSWORD_BCRYPT's
                // default) and hashes a random value nobody holds. It has to be
                // a well-formed bcrypt string: password_verify() rejects a
                // malformed one immediately and does no work, which would
                // defeat the whole point.
                if (!$found) {
                    password_verify($password, PC_LOGIN_DUMMY_HASH);
                }

                if ($password_ok && $user_status === 'blocked') {
                    $login_error = SignInService::BLOCKED_MESSAGE;
                } elseif ($password_ok) {
                    // One good sign-in forgets the failures for this pair.
                    pc_throttle_clear($con, $throttle);

                    // Retires whatever token this account was holding, so
                    // `tokens` cannot grow without bound the way it used to.
                    // See LoginTokenService.
                    $token = LoginTokenService::issue($con, (int)$password_id);

                    // SECURITY: new session id, so a fixed one cannot be reused.
                    session_regenerate_id(true);

                    $_SESSION['user_id']     = $user_id;
                    $_SESSION['role']        = $role;
                    $_SESSION['email']       = $email;
                    $_SESSION['token']       = $token;
                    // logout.php deletes the token row only when it knows which
                    // password row to clear. The old handler never set this, so
                    // that cleanup never ran and `tokens` grew forever.
                    $_SESSION['password_id'] = $password_id;

                    if ($remember) {
                        RememberService::remember($con, (int)$user_id);
                    }

                    logMe($email, date('Y-m-d H:i:s'), "user login");
                    header("Location: " . SignInService::destination(
                        (string)$role,
                        $user_status,
                        $verified,
                        $con,
                        $confirmed
                    ));
                    exit;
                } else {
                    // One message whether the address is unknown or the password
                    // is wrong. Saying "Email not found" (as the old handler did)
                    // let anyone test which addresses have accounts.
                    pc_throttle_hit($con, $throttle);
                    $remaining = $lock_on
                        ? $max_tries - pc_throttle_count($con, $throttle, $lock_secs)
                        : 1;

                    if ($remaining <= 0) {
                        $mins = max(1, (int)round($lock_secs / 60));
                        $login_error = "Too many failed attempts. You are locked out for {$mins} minute(s).";
                    } else {
                        $login_error = "Invalid email or password. {$remaining} attempt(s) remaining.";
                    }
                }
            }
        }
    }
}

$recaptcha_site_key = CaptchaService::siteKey();
$hero_art     = 'images/background.png';
$has_hero_art = is_file(PUBLIC_PATH . '/' . $hero_art);
$csrf         = csrf_token();
// Whichever page they arrived from, or the landing page. See pc_back_url().
$back_url     = pc_back_url();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log in — PeerConnect</title>
    <?php require_once __DIR__ . '/../includes/design_system.php'; ?>
    <?php require_once __DIR__ . '/auth_styles.php'; ?>
</head>

<body class="auth-body">
    <main class="auth-split">
        <!-- ── Story panel ── -->
        <section class="auth-aside" aria-hidden="true">
            <div class="auth-deco" aria-hidden="true">
                <span class="auth-blob auth-blob-1"></span>
                <span class="auth-blob auth-blob-2"></span>
                <span class="auth-dots auth-dots-1"></span>
                <span class="auth-dots auth-dots-2"></span>
            </div>

            <div class="auth-aside-inner">
                <h2 class="auth-aside-h">Great connections can<br>change the way you <em>learn</em>.</h2>
                <p class="auth-aside-p">
                    PeerConnect brings mentors and learners together to share knowledge,
                    grow skills, and achieve goals.
                </p>
                <?php if ($has_hero_art): ?>
                    <div class="auth-art">
                        <img class="auth-aside-art" src="<?= htmlspecialchars(asset($hero_art)) ?>" alt="" width="1536" height="1024">

                        <div class="auth-float auth-float-1">
                            <span class="auth-float-ico" style="background:var(--mint-faint);color:var(--mint-deep);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.5C10.4 5.2 8.4 4.5 6 4.5H4v13h2c2.4 0 4.4.7 6 2 1.6-1.3 3.6-2 6-2h2v-13h-2c-2.4 0-4.4.7-6 2Z" />
                                    <path stroke-linecap="round" d="M12 6.5v13" />
                                </svg>
                            </span>
                            <span>
                                <span class="auth-float-t">Share Knowledge</span>
                                <span class="auth-float-s">Learn and grow together</span>
                            </span>
                        </div>

                        <div class="auth-float auth-float-2">
                            <span class="auth-float-ico" style="background:var(--info-bg);color:var(--info);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <circle cx="12" cy="12" r="8.5" />
                                    <circle cx="12" cy="12" r="4.5" />
                                    <circle cx="12" cy="12" r="1" />
                                </svg>
                            </span>
                            <span>
                                <span class="auth-float-t">Achieve Goals</span>
                                <span class="auth-float-s">Stay focused and reach your goals</span>
                            </span>
                        </div>

                        <div class="auth-float auth-float-3">
                            <span class="auth-float-ico" style="background:var(--success-bg);color:var(--success);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.5 19c0-2.1-1.7-3.8-3.8-3.8h-.4C9.2 15.2 7.5 16.9 7.5 19" />
                                    <circle cx="11.5" cy="9" r="3.2" />
                                    <path stroke-linecap="round" d="M17.5 12.2a2.6 2.6 0 0 0 0-5M20.5 18c0-1.6-1-3-2.5-3.5" />
                                </svg>
                            </span>
                            <span>
                                <span class="auth-float-t">Build Connections</span>
                                <span class="auth-float-s">Connect with the right people</span>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ── Form panel ── -->
        <section class="auth-panel">
            <div class="auth-card">
                <div class="auth-top">
                    <a class="auth-back" href="<?= htmlspecialchars($back_url) ?>" aria-label="Go back">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.5 5-7 7 7 7" />
                        </svg>
                    </a>

                <a class="auth-brand" href="<?= htmlspecialchars(url('welcomepage')) ?>">
                    <?php pc_brand_mark('auth-brand-svg'); ?>
                    <span class="auth-brand-txt">
                        <span class="auth-brand-name">PEER<em>CONNECT</em></span>
                        <span class="auth-brand-tag">Mentoring. Growing. Together.</span>
                    </span>
                    </a>
                </div>

                <h1 class="auth-h1">Welcome back</h1>
                <p class="auth-sub">Log in to continue your mentorship journey.</p>

                <?php if ($login_notice !== ''): ?>
                    <div class="auth-alert auth-ok" role="status">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8 12.3 2.7 2.7L16 9.7" />
                        </svg>
                        <span><?= htmlspecialchars($login_notice) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($login_error !== ''): ?>
                    <div class="auth-alert" role="alert">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" />
                            <path stroke-linecap="round" d="M12 7.5v5M12 16.2v.3" />
                        </svg>
                        <span><?= htmlspecialchars($login_error) ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="<?= htmlspecialchars(url('login')) ?>" id="loginForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

                    <div class="auth-field">
                        <label for="login-email">Email Address</label>
                        <div class="auth-input">
                            <svg class="auth-input-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <rect x="3" y="5.5" width="18" height="13" rx="2.5" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="m3.8 7 8.2 6 8.2-6" />
                            </svg>
                            <input id="login-email" type="email" name="email" required maxlength="100"
                                autocomplete="email" placeholder="Enter your email"
                                value="<?= htmlspecialchars($email ?? '') ?>">
                        </div>
                    </div>

                    <div class="auth-field">
                        <label for="login-password">Password</label>
                        <div class="auth-input">
                            <svg class="auth-input-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <rect x="4.5" y="10.5" width="15" height="9.5" rx="2.4" />
                                <path stroke-linecap="round" d="M8.2 10.5V8a3.8 3.8 0 0 1 7.6 0v2.5" />
                            </svg>
                            <input id="login-password" type="password" name="password" required maxlength="20"
                                autocomplete="current-password" placeholder="Enter your password">
                            <button type="button" class="auth-eye" onclick="togglePw('login-password', this)" aria-label="Show password">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.8 12 5.8 21.5 12 21.5 12 18 18.2 12 18.2 2.5 12 2.5 12Z" />
                                    <circle cx="12" cy="12" r="3.1" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="auth-row">
                        <label class="auth-check">
                            <input type="checkbox" name="remember" value="1" <?= !empty($_POST['remember']) ? 'checked' : '' ?>>
                            <span>Remember me</span>
                        </label>

                        <a class="auth-forgot" href="<?= htmlspecialchars(url('forgot-password')) ?>">Forgot password?</a>
                    </div>

                    <?php if ($recaptcha_site_key !== ''): ?>
                        <div class="auth-captcha">
                            <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars($recaptcha_site_key) ?>"></div>
                        </div>
                    <?php endif; ?>

                    <button class="auth-submit" type="submit" name="login">Log In</button>
                </form>

                <div class="auth-or"><span>OR</span></div>

                <button type="button" class="auth-google" onclick="beginGoogleLogin()">
                    <svg viewBox="0 0 48 48" aria-hidden="true">
                        <path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.6l6.7-6.7C35.6 2.6 30.2 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.8 6.1C12.3 13.3 17.6 9.5 24 9.5Z" />
                        <path fill="#4285F4" d="M46.6 24.6c0-1.6-.1-3.1-.4-4.6H24v9.1h12.7c-.6 3-2.3 5.5-4.8 7.2l7.5 5.8c4.4-4 6.9-10 6.9-17.5Z" />
                        <path fill="#FBBC05" d="M10.4 28.7a14.5 14.5 0 0 1 0-9.4l-7.8-6.1a24 24 0 0 0 0 21.6l7.8-6.1Z" />
                        <path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.9l-7.5-5.8c-2.1 1.4-4.8 2.2-8.4 2.2-6.4 0-11.7-3.8-13.6-9.8l-7.8 6.1C6.5 42.6 14.6 48 24 48Z" />
                    </svg>
                    Continue with Google
                </button>

                <p class="auth-switch">
                    Don't have an account?
                    <a href="<?= htmlspecialchars(url('signup')) ?>">Create an account</a>
                </p>

                <p class="auth-legal">
                    By continuing you agree to our
                    <button type="button" class="auth-link" onclick="openLegal('terms')">Terms of Service</button>
                    and
                    <button type="button" class="auth-link" onclick="openLegal('privacy')">Privacy Policy</button>.
                </p>
            </div>
        </section>
    </main>

    <?php require __DIR__ . '/../includes/legal_modal.php'; ?>

    <script>
        function togglePw(id, btn) {
            const input = document.getElementById(id);
            const showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            btn.classList.toggle('is-on', !showing);
        }

        function beginGoogleLogin() {
            window.location.href = '<?= url('google-login') ?>?mode=login';
        }

        /*
         * Say what is missing.
         *
         * The form carries novalidate, so the browser's own "please fill this
         * in" bubble never appears — this used to move the cursor into the
         * empty box and nothing else, which from the far side of the screen
         * looks like the button simply not working.
         */
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const email = document.getElementById('login-email');
            const pw = document.getElementById('login-password');

            let stop = null, say = '';
            if (!email.value.trim()) {
                stop = email;
                say = 'Enter your email address first.';
            } else if (!pw.value) {
                stop = pw;
                say = 'Enter your password.';
            }

            /*
             * The captcha is verified on the server as well, and that check is
             * the one that matters — this only spares a round trip that would
             * come back with the form to fill in again.
             */
            if (!stop && typeof grecaptcha !== 'undefined' && document.querySelector('.g-recaptcha')) {
                let answered = '';
                try { answered = grecaptcha.getResponse(); } catch (err) { answered = ''; }
                if (answered === '') {
                    stop = document.querySelector('.g-recaptcha');
                    say = 'Please complete the “I’m not a robot” check.';
                }
            }

            if (stop) {
                e.preventDefault();
                pcToast(say, 'error', 5000);
                if (typeof stop.focus === 'function') stop.focus();
            }
        });
    </script>

    <?php require __DIR__ . '/../includes/page_transition.php'; ?>
    <?php if ($recaptcha_site_key !== ''): ?>
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php endif; ?>
</body>

</html>
