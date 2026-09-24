<?php

/**
 * change_email.php — correcting the address, without opening Settings.
 *
 * Someone who registered as "jhon@" instead of "john@" can never receive the
 * confirmation letter, so there has to be a way to fix the address from
 * inside the confirmation stage. That used to be Settings, which meant
 * pc_gate_open_routes() had to let an unconfirmed account into the whole
 * member shell — and with it the Security tab, Download My Data and Delete
 * Account. This page is that one field and nothing else, so the gate can stay
 * shut on everything else.
 *
 * It owns no security logic of its own. The form posts to the existing
 * settings/update_email.php endpoint, which requires the current password,
 * rate-limits to 5 attempts per 5 minutes, refuses an address somebody else
 * holds, moves the activity log across, clears the confirmation stamp and
 * sends a fresh link.
 *
 * Reached only by an account that is signed in and still unconfirmed. Anyone
 * else is sent where they actually belong.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../../services/EmailVerificationService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    header('Location: ' . url('login'));
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$state   = UserRepository::signInState($con, $user_id);

if (!$state) {
    // The account went while the session lived on.
    $_SESSION = [];
    header('Location: ' . url('login'));
    exit;
}

$role = (string)$state['role'];

/*
 * Confirmed already — in another tab, or on the phone the mail was read on.
 * There is nothing to correct here, and Settings is open to them again, so
 * send them on rather than showing a page about a stage they have passed.
 */
if (!EmailVerificationService::pendingFor($con, $role, $state['email_verified_at'] ?? null)) {
    header('Location: ' . SignInService::destination(
        $role,
        $state['status'],
        $state['verified'],
        $con,
        $state['email_verified_at'] ?? null
    ));
    exit;
}

$address = (string)(UserRepository::email($con, $user_id) ?? ($_SESSION['email'] ?? ''));

// An account made with Google has no password to confirm the change with, so
// the form below cannot work for it. In practice this is unreachable —
// google-login.php stamps email_verified_at, so a Google account never enters
// this stage — but the page says so plainly rather than failing at the POST.
$has_password = PasswordRepository::forUser($con, $user_id) !== null;

$csrf         = csrf_token();
$hero_art     = 'images/background.png';
$has_hero_art = is_file(PUBLIC_PATH . '/' . $hero_art);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change your email address — PeerConnect</title>
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
                <h2 class="auth-aside-h">A typo should not<br>cost you <em>an account</em>.</h2>
                <p class="auth-aside-p">
                    Put the right address in and we will send the confirmation
                    letter again. Nothing else about your account changes.
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
                                <span class="auth-float-t">A Fresh Link</span>
                                <span class="auth-float-s">Sent to the new address</span>
                            </span>
                        </div>

                        <div class="auth-float auth-float-2">
                            <span class="auth-float-ico" style="background:var(--info-bg);color:var(--info);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <rect x="4.5" y="10.5" width="15" height="9.5" rx="2.4" />
                                    <path stroke-linecap="round" d="M8.2 10.5V8a3.8 3.8 0 0 1 7.6 0v2.5" />
                                </svg>
                            </span>
                            <span>
                                <span class="auth-float-t">Your Password</span>
                                <span class="auth-float-s">Confirms it is you</span>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ── Panel ── -->
        <section class="auth-panel">
            <div class="auth-card">
                <div class="auth-top">
                    <a class="auth-back" href="<?= htmlspecialchars(url('email-pending')) ?>" aria-label="Back to confirmation">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 5.5 8.5 12l6.5 6.5" />
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

                <span class="auth-icon-badge" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                        <rect x="3" y="5.5" width="18" height="13" rx="2.5" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="m3.8 7 8.2 6 8.2-6" />
                    </svg>
                </span>

                <h1 class="auth-h1">Change your email address</h1>
                <p class="auth-sub">
                    Your account is registered to <strong><?= htmlspecialchars($address) ?></strong>.
                    Enter the correct address and we will send a new confirmation link there.
                </p>

                <?php // display:none as well as [hidden]: .auth-alert sets its own
                      // display, which wins over the attribute and left an empty
                      // red box sitting on the page. ?>
                <div class="auth-alert" id="ce-alert" role="status" hidden style="display:none">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="12" cy="12" r="9" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="m8 12.3 2.7 2.7L16 9.7" />
                    </svg>
                    <span id="ce-alert-text"></span>
                </div>

                <?php if (!$has_password): ?>
                    <p class="auth-sub">
                        This account was created with Google and has no password, so there is
                        nothing to confirm the change with. Use <strong>Forgot password</strong>
                        on the sign-in page to set one, then come back here.
                    </p>
                    <a class="auth-submit auth-submit-link" href="<?= htmlspecialchars(url('forgot-password')) ?>">
                        Set a password
                    </a>
                <?php else: ?>
                    <form id="ce-form" method="POST" action="<?= htmlspecialchars(url('account-update-email')) ?>" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

                        <div class="auth-field">
                            <label for="ce-email">New Email Address</label>
                            <div class="auth-input">
                                <svg class="auth-input-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <rect x="3" y="5.5" width="18" height="13" rx="2.5" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m3.8 7 8.2 6 8.2-6" />
                                </svg>
                                <input id="ce-email" type="email" name="new_email" required maxlength="100"
                                    autocomplete="email" placeholder="Enter the correct email">
                            </div>
                        </div>

                        <div class="auth-field">
                            <label for="ce-password">Your Password</label>
                            <div class="auth-input">
                                <svg class="auth-input-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <rect x="4.5" y="10.5" width="15" height="9.5" rx="2.4" />
                                    <path stroke-linecap="round" d="M8.2 10.5V8a3.8 3.8 0 0 1 7.6 0v2.5" />
                                </svg>
                                <input id="ce-password" type="password" name="confirm_password" required maxlength="20"
                                    autocomplete="current-password" placeholder="Enter your password">
                                <button type="button" class="auth-eye" onclick="togglePw('ce-password', this)" aria-label="Show password">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.8 12 5.8 21.5 12 21.5 12 18 18.2 12 18.2 2.5 12 2.5 12Z" />
                                        <circle cx="12" cy="12" r="3.1" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <button class="auth-submit" type="submit" id="ce-submit">Save and send the link</button>
                    </form>
                <?php endif; ?>

                <p class="auth-switch" style="margin-top:18px;">
                    <a href="<?= htmlspecialchars(url('email-pending')) ?>">Back to confirmation</a>
                </p>

                <!-- Signs out on the way, so it actually reaches the login
                     form: with the session live the gate sends this account
                     straight back to the pending page. -->
                <p class="auth-switch">
                    <a href="<?= htmlspecialchars(url('logout') . '?to=login') ?>">Back to log in</a>
                </p>
            </div>
        </section>
    </main>

    <script>
        function togglePw(id, btn) {
            const f = document.getElementById(id);
            if (!f) return;
            const show = f.type === 'password';
            f.type = show ? 'text' : 'password';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        }

        (function () {
            const form = document.getElementById('ce-form');
            if (!form) return;

            const alertBox = document.getElementById('ce-alert');
            const alertTxt = document.getElementById('ce-alert-text');
            const submit = document.getElementById('ce-submit');
            const email = document.getElementById('ce-email');
            const password = document.getElementById('ce-password');

            function say(text, ok) {
                alertTxt.textContent = text;
                alertBox.classList.toggle('auth-ok', !!ok);
                alertBox.hidden = false;
                alertBox.style.display = '';   // back to whatever .auth-alert says
            }

            // The endpoint answers JSON, so the page has to post it rather than
            // navigate — otherwise the browser shows the raw object.
            form.addEventListener('submit', function (e) {
                e.preventDefault();

                // Say what is missing before spending a request on it.
                if (email.value.trim() === '') { say('Enter the email address you want to use.', false); email.focus(); return; }
                if (!email.checkValidity()) { say('That does not look like a valid email address.', false); email.focus(); return; }
                if (password.value === '') { say('Enter your password so we know it is you.', false); password.focus(); return; }

                submit.disabled = true;
                const was = submit.textContent;
                submit.textContent = 'Saving…';

                fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d && d.success) {
                            say(d.message || 'Email updated.', true);
                            form.reset();
                            // Back to the waiting page, which now names the new
                            // address and offers Resend against it.
                            setTimeout(function () {
                                window.location.href = <?= json_encode(url('email-pending'), JSON_UNESCAPED_SLASHES) ?>;
                            }, 1500);
                            return;
                        }
                        say((d && d.message) || 'That could not be saved. Please try again.', false);
                        submit.disabled = false;
                        submit.textContent = was;
                    })
                    .catch(function () {
                        say('Could not reach the server. Check your connection and try again.', false);
                        submit.disabled = false;
                        submit.textContent = was;
                    });
            });
        })();
    </script>
</body>

</html>
