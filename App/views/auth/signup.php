<?php

/**
 * signup.php — create an account.
 *
 * Moved out of the modal in home.view.php with every guard intact: CSRF,
 * reCAPTCHA, the name/email/password validation, the duplicate-email check
 * and the transactional insert across users / emails / passwords.
 */

require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$signup_error = '';
if (isset($_SESSION['signup_error'])) {
    $signup_error = $_SESSION['signup_error'];
    unset($_SESSION['signup_error']);
}

// Already signed in? Nothing to create.
if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    header("Location: " . url($_SESSION['role'] === 'mentor' ? 'mentor-dashboard' : 'mentee-dashboard'));
    exit;
}

// The form collects the three name parts separately, exactly as the users
// table stores them — no splitting or guessing where a surname begins.
$form = ['firstname' => '', 'middlename' => '', 'lastname' => '', 'email' => '', 'role' => ''];

/*
 * Registration can be closed from System Settings → General. Closing it has
 * to hold on the POST as well as hide the form — otherwise a page left open
 * in a tab still creates accounts after it was turned off.
 */
$registration_open = pc_setting_bool($con, 'allow_registration');
$pw_min = max(6, min(20, pc_setting_int($con, 'password_min_length', 8)));

if (!$registration_open && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signup'])) {
    $signup_error = 'New registrations are closed at the moment. Please check back later.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signup'])) {
    if (!verify_csrf()) {
        $_SESSION['signup_error'] = "Invalid request. Please try again.";
        header("Location: " . url('signup'));
        exit;
    }

    $form['firstname']  = trim((string)($_POST['firstname'] ?? ''));
    $form['middlename'] = trim((string)($_POST['middlename'] ?? ''));
    $form['lastname']   = trim((string)($_POST['lastname'] ?? ''));
    $form['email']      = trim((string)($_POST['email'] ?? ''));
    $form['role']       = (string)($_POST['role'] ?? '');

    $firstname  = $form['firstname'];
    $middlename = $form['middlename'];
    $lastname   = $form['lastname'];
    $email      = $form['email'];
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm_password'] ?? '');
    $role     = $form['role'];
    $agreed   = !empty($_POST['agree_terms']);

    $name_ok = '/^[a-zA-Z0-9 ,.\-_\'"]+$/u';

    if ($firstname === '' || $lastname === '') {
        $signup_error = "First name and last name are both required.";
    } elseif (!preg_match($name_ok, $firstname)) {
        $signup_error = "First name contains invalid characters.";
    } elseif ($middlename !== '' && !preg_match($name_ok, $middlename)) {
        $signup_error = "Middle name contains invalid characters.";
    } elseif (!preg_match($name_ok, $lastname)) {
        $signup_error = "Last name contains invalid characters.";
    } elseif (strlen($email) > 100) {
        $signup_error = "That email address is too long.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $signup_error = "Invalid email format.";
    } elseif ($password !== $confirm) {
        $signup_error = "Passwords do not match.";
    } elseif (!preg_match("/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@#$%^&*!?])[A-Za-z\d@#$%^&*!?]{" . $pw_min . ",20}$/", $password)) {
        $signup_error = "Password must be {$pw_min}–20 characters and include uppercase, lowercase, a number, and one of @#$%^&*!?";
    } elseif (!in_array($role, ['mentee', 'mentor'], true)) {
        $signup_error = "Please choose how you want to use PeerConnect.";
    } elseif (!$agreed) {
        $signup_error = "Please accept the Terms of Service and Privacy Policy to continue.";
    } else {
        $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';
        // Settings can turn the check off; an empty secret already did.
        $recaptcha_secret   = pc_setting_bool($con, 'captcha_enable') ? ($_ENV['RECAPTCHA_SECRET_KEY'] ?? '') : '';
        $verify        = @file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret={$recaptcha_secret}&response={$recaptcha_response}");
        $response_data = $verify !== false ? json_decode($verify) : null;

        if (!$response_data || empty($response_data->success)) {
            $signup_error = "Please complete the CAPTCHA verification.";
        } else {
            // Both tables: `emails` is the historic home, `users.email` the
            // current one. Checking only `emails` (as the old handler did)
            // would let a duplicate through for any account created since.
            $dupe = $con->prepare("
                SELECT 1 FROM users  WHERE email = ?
                UNION
                SELECT 1 FROM emails WHERE email = ?
                LIMIT 1
            ");
            $dupe->bind_param("ss", $email, $email);
            $dupe->execute();
            $dupe->store_result();
            $already = $dupe->num_rows > 0;
            $dupe->close();

            if ($already) {
                $signup_error = "This email is already registered. Try logging in instead.";
            } else {
                $con->begin_transaction();
                try {
                    $stmt1 = $con->prepare("INSERT INTO users (firstname, middlename, lastname, role, email) VALUES (?, ?, ?, ?, ?)");
                    $stmt1->bind_param("sssss", $firstname, $middlename, $lastname, $role, $email);
                    $stmt1->execute();
                    $new_user_id = $stmt1->insert_id;
                    $stmt1->close();

                    $stmt2 = $con->prepare("INSERT INTO emails (user_id, email) VALUES (?, ?)");
                    $stmt2->bind_param("is", $new_user_id, $email);
                    $stmt2->execute();
                    $stmt2->close();

                    $hashed = password_hash($password, PASSWORD_BCRYPT);
                    $stmt3 = $con->prepare("INSERT INTO passwords (user_id, password_hash) VALUES (?, ?)");
                    $stmt3->bind_param("is", $new_user_id, $hashed);
                    $stmt3->execute();
                    $stmt3->close();

                    $con->commit();

                    // Sign them straight in and send them to step 3, the way
                    // Google sign-up already does. The old handler left people
                    // on the form with "you can now log in", which meant typing
                    // the password they had just chosen all over again.
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $new_user_id;
                    $_SESSION['role']    = $role;
                    $_SESSION['email']   = $email;

                    logMe($email, date('Y-m-d H:i:s'), "user signup");
                    header("Location: " . url($role === 'mentor' ? 'mentor-verification' : 'mentee-verification'));
                    exit;
                } catch (Throwable $e) {
                    $con->rollback();
                    error_log('Signup failed: ' . $e->getMessage());
                    $signup_error = "Something went wrong. Please try again.";
                }
            }
        }
    }
}

$recaptcha_site_key = pc_setting_bool($con, 'captcha_enable') ? ($_ENV['RECAPTCHA_SITE_KEY'] ?? '') : '';
$hero_art     = 'images/background.png';
$has_hero_art = is_file(PUBLIC_PATH . '/' . $hero_art);
$csrf         = csrf_token();
// Whichever page they arrived from, or the landing page. See pc_back_url().
$back_url     = pc_back_url();

/** The four panels beside the form. Each describes something the app does. */
$sell_points = [
    ['icon' => 'users',  'tint' => 'mint',    'title' => 'Meaningful Connections', 'text' => 'Build supportive mentor–mentee relationships with students on your own campus.'],
    ['icon' => 'book',   'tint' => 'purple',  'title' => 'Learn & Grow Together',  'text' => 'Share knowledge in one-on-one and group sessions, and take assessments together.'],
    ['icon' => 'target', 'tint' => 'warning', 'title' => 'Track Your Progress',    'text' => 'Watch completed sessions, assessment scores and mentorship progress build up on your dashboard.'],
    ['icon' => 'trophy', 'tint' => 'info',    'title' => 'Earn Recognition',       'text' => 'Mentors collect badges and a place on the community leaderboard for the time they give.'],
];
$sell_tints = [
    'mint'    => ['var(--mint-faint)', 'var(--mint-deep)'],
    'purple'  => ['var(--purple-bg)',  'var(--purple)'],
    'warning' => ['var(--warning-bg)', 'var(--warning)'],
    'info'    => ['var(--info-bg)',    'var(--info)'],
];
$sell_icons = [
    'users'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.5 19c0-2.1-1.7-3.8-3.8-3.8h-.4C9.2 15.2 7.5 16.9 7.5 19"/><circle cx="11.5" cy="9" r="3.2"/><path stroke-linecap="round" d="M17.5 12.2a2.6 2.6 0 0 0 0-5M20.5 18c0-1.6-1-3-2.5-3.5"/>',
    'book'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6.5C10.4 5.2 8.4 4.5 6 4.5H4v13h2c2.4 0 4.4.7 6 2 1.6-1.3 3.6-2 6-2h2v-13h-2c-2.4 0-4.4.7-6 2Z"/><path stroke-linecap="round" d="M12 6.5v13"/>',
    'target' => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r="1"/>',
    'trophy' => '<path stroke-linecap="round" stroke-linejoin="round" d="M7 4h10v5a5 5 0 0 1-10 0V4Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M7 6H4.5v1.5A3.5 3.5 0 0 0 8 11M17 6h2.5v1.5A3.5 3.5 0 0 1 16 11M12 14v4M8.5 21h7"/>',
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create your account — PeerConnect</title>
    <?php require_once __DIR__ . '/../includes/design_system.php'; ?>
    <?php require_once __DIR__ . '/auth_styles.php'; ?>
    <?php require_once __DIR__ . '/signup_styles.php'; ?>
</head>

<body class="auth-body">
    <main class="auth-split su-split">
        <!-- ── Form panel ── -->
        <section class="auth-panel">
            <div class="auth-card su-card">
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

                <h1 class="auth-h1">Create your PEER<em class="su-em">CONNECT</em> account</h1>
                <p class="auth-sub">Start building meaningful connections and growing together.</p>

                <?php if ($signup_error !== ''): ?>
                    <div class="auth-alert" role="alert">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" />
                            <path stroke-linecap="round" d="M12 7.5v5M12 16.2v.3" />
                        </svg>
                        <span><?= htmlspecialchars($signup_error) ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="<?= htmlspecialchars(url('signup')) ?>" id="signupForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

                    <div class="su-names">
                        <div class="auth-field">
                            <label for="su-first">First Name</label>
                            <div class="auth-input">
                                <svg class="auth-input-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <circle cx="12" cy="8.5" r="3.6" />
                                    <path stroke-linecap="round" d="M5 19.5c0-3.1 3.1-5.4 7-5.4s7 2.3 7 5.4" />
                                </svg>
                                <input id="su-first" type="text" name="firstname" required maxlength="50"
                                    autocomplete="given-name" placeholder="First name"
                                    value="<?= htmlspecialchars($form['firstname']) ?>">
                            </div>
                        </div>

                        <div class="auth-field">
                            <label for="su-middle">Middle Name <span class="su-opt">(optional)</span></label>
                            <div class="auth-input">
                                <input id="su-middle" type="text" name="middlename" maxlength="100"
                                    autocomplete="additional-name" placeholder="Middle name"
                                    value="<?= htmlspecialchars($form['middlename']) ?>">
                            </div>
                        </div>

                        <div class="auth-field">
                            <label for="su-last">Last Name</label>
                            <div class="auth-input">
                                <input id="su-last" type="text" name="lastname" required maxlength="50"
                                    autocomplete="family-name" placeholder="Last name"
                                    value="<?= htmlspecialchars($form['lastname']) ?>">
                            </div>
                        </div>
                    </div>

                    <div class="auth-field">
                        <label for="su-email">Email Address</label>
                        <div class="auth-input">
                            <svg class="auth-input-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <rect x="3" y="5.5" width="18" height="13" rx="2.5" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="m3.8 7 8.2 6 8.2-6" />
                            </svg>
                            <input id="su-email" type="email" name="email" required maxlength="100"
                                autocomplete="email" placeholder="Enter your email"
                                value="<?= htmlspecialchars($form['email']) ?>">
                            <span class="su-tick" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m5 12.5 4.5 4.5L19 7" />
                                </svg>
                            </span>
                        </div>
                    </div>

                    <div class="su-two">
                        <div class="auth-field su-nomargin">
                            <label for="su-password">Password</label>
                            <div class="auth-input">
                                <svg class="auth-input-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <rect x="4.5" y="10.5" width="15" height="9.5" rx="2.4" />
                                    <path stroke-linecap="round" d="M8.2 10.5V8a3.8 3.8 0 0 1 7.6 0v2.5" />
                                </svg>
                                <input id="su-password" type="password" name="password" required maxlength="20"
                                    autocomplete="new-password" placeholder="Create a password">
                                <button type="button" class="auth-eye" onclick="togglePw('su-password', this)" aria-label="Show password">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.8 12 5.8 21.5 12 21.5 12 18 18.2 12 18.2 2.5 12 2.5 12Z" />
                                        <circle cx="12" cy="12" r="3.1" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="auth-field su-nomargin">
                            <label for="su-confirm">Confirm Password</label>
                            <div class="auth-input">
                                <svg class="auth-input-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <rect x="4.5" y="10.5" width="15" height="9.5" rx="2.4" />
                                    <path stroke-linecap="round" d="M8.2 10.5V8a3.8 3.8 0 0 1 7.6 0v2.5" />
                                </svg>
                                <input id="su-confirm" type="password" name="confirm_password" required maxlength="20"
                                    autocomplete="new-password" placeholder="Confirm your password">
                                <button type="button" class="auth-eye" onclick="togglePw('su-confirm', this)" aria-label="Show password">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.8 12 5.8 21.5 12 21.5 12 18 18.2 12 18.2 2.5 12 2.5 12Z" />
                                        <circle cx="12" cy="12" r="3.1" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="su-pwmeta">
                        <div class="su-meter" aria-hidden="true">
                            <span></span><span></span><span></span><span></span><span></span>
                        </div>
                        <p class="su-strength" id="su-strength"></p>

                        <!-- Exactly the rules the server enforces, so the form can
                             never call a password strong that the server rejects. -->
                        <ul class="su-rules" id="su-rules">
                            <li data-rule="len"><span class="su-rule-i"></span>8&ndash;20 characters</li>
                            <li data-rule="upper"><span class="su-rule-i"></span>Uppercase</li>
                            <li data-rule="lower"><span class="su-rule-i"></span>Lowercase</li>
                            <li data-rule="num"><span class="su-rule-i"></span>Number</li>
                            <li data-rule="special"><span class="su-rule-i"></span>@ # $ % ^ &amp; * ! ?</li>
                        </ul>
                        <p class="su-err" id="su-charset-err" hidden>That password uses a character the site does not accept.</p>
                        <p class="su-err" id="su-match-err" hidden>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="12" cy="12" r="9" />
                                <path stroke-linecap="round" d="M12 7.5v5M12 16.2v.3" />
                            </svg>
                            Passwords do not match.
                        </p>
                    </div>

                    <fieldset class="su-roles">
                        <legend>How do you want to use PEERCONNECT?</legend>
                        <div class="su-role-grid">
                            <label class="su-role">
                                <input type="radio" name="role" value="mentee" required <?= $form['role'] === 'mentee' ? 'checked' : '' ?>>
                                <span class="su-role-ico" style="background:var(--mint-faint);color:var(--mint-deep);">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m12 4 9 4.5-9 4.5-9-4.5L12 4Z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.5 10.5V16c0 1.4 2.5 2.5 5.5 2.5s5.5-1.1 5.5-2.5v-5.5" />
                                    </svg>
                                </span>
                                <span class="su-role-txt">
                                    <span class="su-role-t">MENTEE</span>
                                    <span class="su-role-d">I want to find a mentor and receive guidance.</span>
                                </span>
                                <span class="su-role-dot" aria-hidden="true"></span>
                            </label>

                            <label class="su-role">
                                <input type="radio" name="role" value="mentor" <?= $form['role'] === 'mentor' ? 'checked' : '' ?>>
                                <span class="su-role-ico" style="background:var(--purple-bg);color:var(--purple);">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 4h10v5a5 5 0 0 1-10 0V4Z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 6H4.5v1.5A3.5 3.5 0 0 0 8 11M17 6h2.5v1.5A3.5 3.5 0 0 1 16 11M12 14v4M8.5 21h7" />
                                    </svg>
                                </span>
                                <span class="su-role-txt">
                                    <span class="su-role-t">MENTOR</span>
                                    <span class="su-role-d">I want to share my experience and mentor others.</span>
                                </span>
                                <span class="su-role-dot" aria-hidden="true"></span>
                            </label>
                        </div>
                    </fieldset>

                    <label class="auth-check su-terms">
                        <input type="checkbox" name="agree_terms" value="1" id="su-terms" required>
                        <span>
                            I agree to the
                            <button type="button" class="auth-link" onclick="openLegal('terms')">Terms of Service</button>
                            and
                            <button type="button" class="auth-link" onclick="openLegal('privacy')">Privacy Policy</button>.
                        </span>
                    </label>

                    <?php if ($recaptcha_site_key !== ''): ?>
                        <div class="auth-captcha">
                            <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars($recaptcha_site_key) ?>"></div>
                        </div>
                    <?php endif; ?>

                    <button class="auth-submit" type="submit" name="signup" id="su-submit">Create Account</button>
                </form>

                <div class="auth-or"><span>OR</span></div>

                <button type="button" class="auth-google" id="su-google" onclick="beginGoogleSignup()">
                    <svg viewBox="0 0 48 48" aria-hidden="true">
                        <path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.6l6.7-6.7C35.6 2.6 30.2 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.8 6.1C12.3 13.3 17.6 9.5 24 9.5Z" />
                        <path fill="#4285F4" d="M46.6 24.6c0-1.6-.1-3.1-.4-4.6H24v9.1h12.7c-.6 3-2.3 5.5-4.8 7.2l7.5 5.8c4.4-4 6.9-10 6.9-17.5Z" />
                        <path fill="#FBBC05" d="M10.4 28.7a14.5 14.5 0 0 1 0-9.4l-7.8-6.1a24 24 0 0 0 0 21.6l7.8-6.1Z" />
                        <path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.9l-7.5-5.8c-2.1 1.4-4.8 2.2-8.4 2.2-6.4 0-11.7-3.8-13.6-9.8l-7.8 6.1C6.5 42.6 14.6 48 24 48Z" />
                    </svg>
                    Sign up with Google
                </button>
                <p class="su-google-hint" id="su-google-hint">Choose Mentee or Mentor above first.</p>

                <p class="auth-switch">
                    Already have an account?
                    <a href="<?= htmlspecialchars(url('login')) ?>">Log In</a>
                </p>
            </div>
        </section>

        <!-- ── Story panel ── -->
        <section class="auth-aside su-aside">
            <div class="auth-deco" aria-hidden="true">
                <span class="auth-blob auth-blob-1"></span>
                <span class="auth-blob auth-blob-2"></span>
                <span class="auth-dots auth-dots-1"></span>
            </div>

            <div class="auth-aside-inner">
                <h2 class="auth-aside-h">Your next connection<br>could <em>inspire</em> your next step.</h2>
                <p class="auth-aside-p su-aside-p">Learn. Share. Grow. Together.</p>

                <?php if ($has_hero_art): ?>
                    <div class="auth-art">
                        <img class="auth-aside-art" src="<?= htmlspecialchars(asset($hero_art)) ?>" alt="" width="1536" height="1024" loading="lazy">

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

                <div class="su-sell">
                    <?php foreach ($sell_points as $p): ?>
                        <?php [$bg, $fg] = $sell_tints[$p['tint']]; ?>
                        <div class="su-sell-item">
                            <span class="su-sell-ico" style="background:<?= $bg ?>;color:<?= $fg ?>;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><?= $sell_icons[$p['icon']] ?></svg>
                            </span>
                            <h3><?= htmlspecialchars($p['title']) ?></h3>
                            <p><?= htmlspecialchars($p['text']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>

                <blockquote class="su-quote">
                    <span class="su-quote-mark" aria-hidden="true">&ldquo;</span>
                    <span>The best way to grow is to learn together.<br>
                        <strong>PEERCONNECT</strong> makes it possible.</span>
                </blockquote>
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

        (function() {
            const first = document.getElementById('su-first');
            const last = document.getElementById('su-last');
            const email = document.getElementById('su-email');
            const pw = document.getElementById('su-password');
            const confirm = document.getElementById('su-confirm');
            const terms = document.getElementById('su-terms');
            const roles = Array.from(document.querySelectorAll('input[name=role]'));
            const meter = document.querySelectorAll('.su-meter span');
            const strengthTxt = document.getElementById('su-strength');
            const rules = document.getElementById('su-rules');
            const charsetErr = document.getElementById('su-charset-err');
            const matchErr = document.getElementById('su-match-err');
            const googleHint = document.getElementById('su-google-hint');

            // Mirrors the server: 8-20, lower, upper, digit, one of @#$%^&*!?,
            // and nothing outside that alphabet.
            const ALLOWED = /^[A-Za-z\d@#$%^&*!?]*$/;

            function checks(v) {
                return {
                    len: v.length >= 8 && v.length <= 20,
                    upper: /[A-Z]/.test(v),
                    lower: /[a-z]/.test(v),
                    num: /\d/.test(v),
                    special: /[@#$%^&*!?]/.test(v)
                };
            }

            function passwordOk(v) {
                const c = checks(v);
                return ALLOWED.test(v) && c.len && c.upper && c.lower && c.num && c.special;
            }

            function nameOk() {
                return first.value.trim() !== '' && last.value.trim() !== '';
            }

            function emailOk() {
                return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email.value.trim());
            }

            function paintPassword() {
                const v = pw.value;
                const c = checks(v);
                const passed = Object.values(c).filter(Boolean).length;

                rules.querySelectorAll('li').forEach(li => {
                    li.classList.toggle('ok', !!c[li.dataset.rule]);
                });
                charsetErr.hidden = v === '' || ALLOWED.test(v);

                meter.forEach((bar, i) => {
                    bar.classList.toggle('on', v !== '' && i < passed);
                    bar.classList.toggle('full', v !== '' && passed === 5 && ALLOWED.test(v));
                });

                if (v === '') {
                    strengthTxt.textContent = '';
                } else if (passwordOk(v)) {
                    strengthTxt.textContent = 'Strong';
                    strengthTxt.className = 'su-strength is-strong';
                } else if (passed >= 3) {
                    strengthTxt.textContent = 'Almost there';
                    strengthTxt.className = 'su-strength is-mid';
                } else {
                    strengthTxt.textContent = 'Too weak';
                    strengthTxt.className = 'su-strength is-weak';
                }
            }

            function paintMatch() {
                matchErr.hidden = !(confirm.value !== '' && confirm.value !== pw.value);
            }

            function paintTicks() {
                email.closest('.auth-input').classList.toggle('is-valid', emailOk());
                googleHint.hidden = roles.some(r => r.checked);
            }

            function repaint() {
                paintPassword();
                paintMatch();
                paintTicks();
            }

            [first, last, email, pw, confirm].forEach(el => el.addEventListener('input', repaint));
            roles.forEach(r => r.addEventListener('change', repaint));
            terms.addEventListener('change', repaint);
            repaint();

            // Stop the obvious mistakes here; the server checks all of it again.
            document.getElementById('signupForm').addEventListener('submit', function(e) {
                let stop = null;
                if (first.value.trim() === '') stop = first;
                else if (last.value.trim() === '') stop = last;
                else if (!emailOk()) stop = email;
                else if (!passwordOk(pw.value)) stop = pw;
                else if (confirm.value !== pw.value) stop = confirm;
                else if (!roles.some(r => r.checked)) stop = roles[0];
                else if (!terms.checked) stop = terms;

                if (stop) {
                    e.preventDefault();
                    repaint();
                    stop.focus();
                    stop.scrollIntoView({ block: 'center', behavior: 'smooth' });
                }
            });

            window.beginGoogleSignup = function() {
                const picked = roles.find(r => r.checked);
                if (!picked) {
                    // Google sign-up creates the account immediately, so the
                    // role has to be known before we hand off.
                    googleHint.hidden = false;
                    googleHint.classList.add('is-warn');
                    roles[0].focus();
                    roles[0].closest('.su-role-grid').scrollIntoView({ block: 'center', behavior: 'smooth' });
                    return;
                }
                window.location.href = '<?= url('google-login') ?>?mode=signup&role=' + encodeURIComponent(picked.value);
            };
        })();
    </script>

    <?php require __DIR__ . '/../includes/page_transition.php'; ?>
    <?php if ($recaptcha_site_key !== ''): ?>
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php endif; ?>
</body>

</html>
