<?php

/**
 * forgot_password.php — step 1 of the reset: ask for the email address.
 *
 * The single most important rule on this page is that it must answer the same
 * way whether or not the address has an account. Anything that differs — the
 * wording, the presence of an error, even how long the request takes — turns
 * the form into a way of testing which of your users are registered here.
 * So: one message, always, and the CAPTCHA runs before the lookup either way.
 *
 * Real failures (SMTP down, a throttled account) are recorded in the server
 * log and in `logs`, never shown.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../../services/PasswordResetService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Signed in already? Then there is nothing to recover — change the password
// from settings instead. The exception is an account made with Google, which
// has no password to change: this form is how it adds one, and Settings
// sends it here.
if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])
    && PasswordRepository::exists($con, (int)$_SESSION['user_id'])) {
    header("Location: " . url($_SESSION['role'] === 'mentor' ? 'mentor-settings' : 'mentee-settings'));
    exit;
}

$error = '';
$sent  = false;
$email_value = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forgot'])) {
    $email_value = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');

    if (!verify_csrf()) {
        $error = "Invalid request. Please try again.";
    } elseif (!rate_limit('forgot_password', 5, 600)) {
        // Per-session cap on top of the per-account one in the service, so a
        // single visitor cannot walk a list of addresses through the form.
        $error = "Too many requests. Please wait a few minutes and try again.";
    } elseif ($email_value === '') {
        $error = "Please enter your email address.";
    } elseif (strlen($email_value) > 100) {
        $error = "That email address is too long.";
    } elseif (!filter_var($email_value, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } else {
        if (!CaptchaService::verify($_POST['g-recaptcha-response'] ?? null)) {
            $error = "Please complete the CAPTCHA verification.";
        } else {
            $result = PasswordResetService::request($con, $email_value);
            PasswordResetService::purge($con);

            // Deliberately identical for every outcome: address unknown,
            // account blocked, throttled, or mail actually sent.
            $sent = true;

            logMe(
                $email_value,
                date('Y-m-d H:i:s'),
                'password reset requested (' . ($result['sent'] ? 'sent' : ($result['throttled'] ? 'throttled' : 'no-op')) . ')'
            );
        }
    }
}

$recaptcha_site_key = CaptchaService::siteKey();
$hero_art     = 'images/background.png';
$has_hero_art = is_file(PUBLIC_PATH . '/' . $hero_art);
$csrf         = csrf_token();
// Back always goes to the login page here — that is where the link lives, and
// it is where the visitor needs to end up either way.
$back_url     = url('login');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot your password — PeerConnect</title>
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
                <h2 class="auth-aside-h">It happens to<br>everyone. Let's get you <em>back in</em>.</h2>
                <p class="auth-aside-p">
                    Enter the email you signed up with and we will send you a link
                    to choose a new password.
                </p>
                <?php if ($has_hero_art): ?>
                    <div class="auth-art">
                        <img class="auth-aside-art" src="<?= htmlspecialchars(asset($hero_art)) ?>" alt="" width="1536" height="1024">

                        <div class="auth-float auth-float-1">
                            <span class="auth-float-ico" style="background:var(--mint-faint);color:var(--mint-deep);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <rect x="3" y="5.5" width="18" height="13" rx="2.5" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m3.8 7 8.2 6 8.2-6" />
                                </svg>
                            </span>
                            <span>
                                <span class="auth-float-t">Check Your Inbox</span>
                                <span class="auth-float-s">A link arrives in moments</span>
                            </span>
                        </div>

                        <div class="auth-float auth-float-2">
                            <span class="auth-float-ico" style="background:var(--info-bg);color:var(--info);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <circle cx="12" cy="12" r="8.5" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 7.5V12l3 1.8" />
                                </svg>
                            </span>
                            <span>
                                <span class="auth-float-t">Valid for One Hour</span>
                                <span class="auth-float-s">Then it expires on its own</span>
                            </span>
                        </div>

                        <div class="auth-float auth-float-3">
                            <span class="auth-float-ico" style="background:var(--success-bg);color:var(--success);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <rect x="4.5" y="10.5" width="15" height="9.5" rx="2.4" />
                                    <path stroke-linecap="round" d="M8.2 10.5V8a3.8 3.8 0 0 1 7.6 0v2.5" />
                                </svg>
                            </span>
                            <span>
                                <span class="auth-float-t">Only You Can Use It</span>
                                <span class="auth-float-s">Single use, then it is spent</span>
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
                    <a class="auth-back" href="<?= htmlspecialchars($back_url) ?>" aria-label="Back to log in">
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

                <?php if ($sent): ?>
                    <span class="auth-icon-badge" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                            <rect x="3" y="5.5" width="18" height="13" rx="2.5" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="m3.8 7 8.2 6 8.2-6" />
                        </svg>
                    </span>

                    <h1 class="auth-h1">Check your email</h1>
                    <p class="auth-sub">
                        If an account exists for <strong><?= htmlspecialchars($email_value) ?></strong>,
                        we have sent it a link to reset the password. The link works once and
                        expires in an hour.
                    </p>

                    <div class="auth-alert auth-ok" role="status">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8 12.3 2.7 2.7L16 9.7" />
                        </svg>
                        <span>Nothing after a few minutes? Check your spam folder, then try again.</span>
                    </div>

                    <a class="auth-submit auth-submit-link" href="<?= htmlspecialchars(url('login')) ?>">Back to Log In</a>

                    <p class="auth-switch">
                        Wrong address?
                        <a href="<?= htmlspecialchars(url('forgot-password')) ?>">Try a different one</a>
                    </p>
                <?php else: ?>
                    <h1 class="auth-h1">Forgot your password?</h1>
                    <p class="auth-sub">Enter your email and we will send you a link to choose a new one.</p>

                    <?php if ($error !== ''): ?>
                        <div class="auth-alert" role="alert">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="12" cy="12" r="9" />
                                <path stroke-linecap="round" d="M12 7.5v5M12 16.2v.3" />
                            </svg>
                            <span><?= htmlspecialchars($error) ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="<?= htmlspecialchars(url('forgot-password')) ?>" id="forgotForm" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

                        <div class="auth-field">
                            <label for="fp-email">Email Address</label>
                            <div class="auth-input">
                                <svg class="auth-input-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <rect x="3" y="5.5" width="18" height="13" rx="2.5" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m3.8 7 8.2 6 8.2-6" />
                                </svg>
                                <input id="fp-email" type="email" name="email" required maxlength="100"
                                    autocomplete="email" autofocus placeholder="Enter your email"
                                    value="<?= htmlspecialchars($email_value) ?>">
                            </div>
                        </div>

                        <?php if ($recaptcha_site_key !== ''): ?>
                            <div class="auth-captcha">
                                <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars($recaptcha_site_key) ?>"></div>
                            </div>
                        <?php endif; ?>

                        <button class="auth-submit" type="submit" name="forgot">Send Reset Link</button>
                    </form>

                    <p class="auth-switch">
                        Remembered it?
                        <a href="<?= htmlspecialchars(url('login')) ?>">Back to Log In</a>
                    </p>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <script>
        (function() {
            const form = document.getElementById('forgotForm');
            if (!form) return;   // the confirmation view has no form
            const email = document.getElementById('fp-email');
            form.addEventListener('submit', function(e) {
                if (!email.value.trim()) {
                    e.preventDefault();
                    email.focus();
                }
            });
        })();
    </script>

    <?php require __DIR__ . '/../includes/page_transition.php'; ?>
    <?php if ($recaptcha_site_key !== ''): ?>
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php endif; ?>
</body>

</html>
