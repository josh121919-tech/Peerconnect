<?php

/**
 * reset_password.php — step 2 of the reset: choose the new password.
 *
 * The link arrives as ?s=<selector>&t=<validator>. That pair is checked once
 * on arrival and then moved into the session, and the browser is redirected to
 * the bare URL. Three reasons:
 *
 *   - the token stops sitting in the address bar, browser history and the
 *     server access log,
 *   - it is not re-sent as a Referer to reCAPTCHA or any other third party,
 *   - a refresh after submitting cannot replay it.
 *
 * The password rules are the same ones signup enforces, checked again here in
 * PHP; the JavaScript below only mirrors them so the form can never call a
 * password acceptable that the server will reject.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../../services/PasswordResetService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** How long the checked-out token may sit in the session before it is stale. */
const PC_RESET_SESSION_TTL = 900;   // 15 minutes to type a password

$error   = '';
$invalid = false;   // token missing, wrong, spent or expired

// ── Arriving from the email ────────────────────────────────────────────
if (isset($_GET['s']) || isset($_GET['t'])) {
    $found = PasswordResetService::lookup(
        $con,
        (string)($_GET['s'] ?? ''),
        (string)($_GET['t'] ?? '')
    );

    if ($found) {
        // Hand a fresh CSRF token to this browser along with the reset, so a
        // token minted for someone else's session cannot be used here.
        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);

        $_SESSION['pw_reset'] = [
            'reset_id'   => $found['reset_id'],
            'user_id'    => $found['user_id'],
            'email'      => $found['email'],
            'firstname'  => $found['firstname'],
            'checked_at' => time(),
        ];
    } else {
        unset($_SESSION['pw_reset']);
        $_SESSION['pw_reset_invalid'] = true;
    }

    // Same destination either way, so the URL never says which it was.
    header("Location: " . url('reset-password'));
    exit;
}

if (!empty($_SESSION['pw_reset_invalid'])) {
    unset($_SESSION['pw_reset_invalid']);
    $invalid = true;
}

$reset = $_SESSION['pw_reset'] ?? null;

// A token parked in the session for too long is treated as gone.
if ($reset && (time() - (int)$reset['checked_at']) > PC_RESET_SESSION_TTL) {
    unset($_SESSION['pw_reset']);
    $reset   = null;
    $invalid = true;
}

if (!$reset && !$invalid) {
    // Landed here with no link at all.
    $invalid = true;
}

// ── Submitting the new password ────────────────────────────────────────
// The same rule as sign-up and Settings, minimum length included.
$pw_min = PasswordPolicy::minLength($con);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset']) && $reset) {
    // A field sent as a list counts as empty.
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm  = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

    if (!verify_csrf()) {
        $error = "Invalid request. Please try again.";
    } elseif ($password === '' || $confirm === '') {
        $error = "Please fill in both password fields.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (($pw_problem = PasswordPolicy::problem($password, $pw_min)) !== null) {
        $error = $pw_problem;
    } else {
        $ok = PasswordResetService::complete($con, (int)$reset['reset_id'], (int)$reset['user_id'], $password);

        if ($ok) {
            logMe($reset['email'], date('Y-m-d H:i:s'), "password reset completed");

            unset($_SESSION['pw_reset']);
            // The reset just invalidated every remembered login for this
            // account, including any cookie this browser holds.
            require_once __DIR__ . '/../../services/RememberService.php';
            RememberService::forget($con);

            // Clear any lockout left over from the failed attempts that sent
            // them here in the first place. The counter now lives in
            // auth_throttle rather than the session, so it is cleared by
            // bucket — the same key auth/login.php records failures under.
            pc_throttle_clear($con, substr('login:' . strtolower($reset['email']) . '|' . pc_client_ip(), 0, 190));

            $_SESSION['login_notice'] = "Your password has been changed. Please log in with your new password.";
            header("Location: " . url('login'));
            exit;
        }

        // complete() only fails when the row was claimed in the meantime or
        // the write itself failed; either way this link is finished.
        unset($_SESSION['pw_reset']);
        $reset   = null;
        $invalid = true;
        $error   = '';
    }
}

$hero_art     = 'images/background.png';
$has_hero_art = is_file(PUBLIC_PATH . '/' . $hero_art);
$csrf         = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title>Choose a new password — PeerConnect</title>
    <?php require_once __DIR__ . '/../includes/design_system.php'; ?>
    <?php require_once __DIR__ . '/auth_styles.php'; ?>
    <?php require_once __DIR__ . '/signup_styles.php'; ?>
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
                <h2 class="auth-aside-h">One new password<br>and you are <em>back</em>.</h2>
                <p class="auth-aside-p">
                    Choosing a new password clears every saved login and retires
                    any reset link still outstanding.
                </p>
                <?php if ($has_hero_art): ?>
                    <div class="auth-art">
                        <img class="auth-aside-art" src="<?= htmlspecialchars(asset($hero_art)) ?>" alt="" width="1536" height="1024">

                        <div class="auth-float auth-float-1">
                            <span class="auth-float-ico" style="background:var(--mint-faint);color:var(--mint-deep);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <rect x="4.5" y="10.5" width="15" height="9.5" rx="2.4" />
                                    <path stroke-linecap="round" d="M8.2 10.5V8a3.8 3.8 0 0 1 7.6 0v2.5" />
                                </svg>
                            </span>
                            <span>
                                <span class="auth-float-t">Pick Something Strong</span>
                                <span class="auth-float-s">Long beats complicated</span>
                            </span>
                        </div>

                        <div class="auth-float auth-float-2">
                            <span class="auth-float-ico" style="background:var(--info-bg);color:var(--info);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.5 5 6.5v5c0 4.2 2.9 7.6 7 9 4.1-1.4 7-4.8 7-9v-5l-7-3Z" />
                                </svg>
                            </span>
                            <span>
                                <span class="auth-float-t">Saved Logins Cleared</span>
                                <span class="auth-float-s">Every "Remember me" is revoked</span>
                            </span>
                        </div>

                        <div class="auth-float auth-float-3">
                            <span class="auth-float-ico" style="background:var(--success-bg);color:var(--success);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <circle cx="12" cy="12" r="9" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m8 12.3 2.7 2.7L16 9.7" />
                                </svg>
                            </span>
                            <span>
                                <span class="auth-float-t">Straight Back In</span>
                                <span class="auth-float-s">Log in with the new one</span>
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
                    <a class="auth-back" href="<?= htmlspecialchars(url('login')) ?>" aria-label="Back to log in">
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

                <?php if ($invalid): ?>
                    <h1 class="auth-h1">This link is no longer valid</h1>
                    <p class="auth-sub">
                        Reset links work once and expire an hour after they are sent.
                        This one has already been used, has run out, or was not complete.
                    </p>

                    <div class="auth-alert" role="alert">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" />
                            <path stroke-linecap="round" d="M12 7.5v5M12 16.2v.3" />
                        </svg>
                        <span>Request a fresh link and it will arrive in a moment.</span>
                    </div>

                    <a class="auth-submit auth-submit-link" href="<?= htmlspecialchars(url('forgot-password')) ?>">Request a New Link</a>

                    <p class="auth-switch">
                        Remembered your password?
                        <a href="<?= htmlspecialchars(url('login')) ?>">Back to Log In</a>
                    </p>
                <?php else: ?>
                    <h1 class="auth-h1">Choose a new password</h1>
                    <p class="auth-sub">
                        For <strong><?= htmlspecialchars($reset['email']) ?></strong>.
                        Pick something you have not used here before.
                    </p>

                    <?php if ($error !== ''): ?>
                        <div class="auth-alert" role="alert">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="12" cy="12" r="9" />
                                <path stroke-linecap="round" d="M12 7.5v5M12 16.2v.3" />
                            </svg>
                            <span><?= htmlspecialchars($error) ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="<?= htmlspecialchars(url('reset-password')) ?>" id="resetForm" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

                        <div class="auth-field">
                            <label for="rp-password">New Password</label>
                            <div class="auth-input">
                                <svg class="auth-input-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <rect x="4.5" y="10.5" width="15" height="9.5" rx="2.4" />
                                    <path stroke-linecap="round" d="M8.2 10.5V8a3.8 3.8 0 0 1 7.6 0v2.5" />
                                </svg>
                                <input id="rp-password" type="password" name="password" required maxlength="20"
                                    autocomplete="new-password" autofocus placeholder="Create a new password">
                                <button type="button" class="auth-eye" onclick="togglePw('rp-password', this)" aria-label="Show password">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.8 12 5.8 21.5 12 21.5 12 18 18.2 12 18.2 2.5 12 2.5 12Z" />
                                        <circle cx="12" cy="12" r="3.1" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="auth-field">
                            <label for="rp-confirm">Confirm New Password</label>
                            <div class="auth-input">
                                <svg class="auth-input-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <rect x="4.5" y="10.5" width="15" height="9.5" rx="2.4" />
                                    <path stroke-linecap="round" d="M8.2 10.5V8a3.8 3.8 0 0 1 7.6 0v2.5" />
                                </svg>
                                <input id="rp-confirm" type="password" name="confirm_password" required maxlength="20"
                                    autocomplete="new-password" placeholder="Confirm your new password">
                                <button type="button" class="auth-eye" onclick="togglePw('rp-confirm', this)" aria-label="Show password">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.8 12 5.8 21.5 12 21.5 12 18 18.2 12 18.2 2.5 12 2.5 12Z" />
                                        <circle cx="12" cy="12" r="3.1" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="su-pwmeta">
                            <div class="su-meter" aria-hidden="true">
                                <span></span><span></span><span></span><span></span><span></span>
                            </div>
                            <p class="su-strength" id="rp-strength"></p>

                            <ul class="su-rules" id="rp-rules">
                                <li data-rule="len"><span class="su-rule-i"></span><?= $pw_min ?>&ndash;20 characters</li>
                                <li data-rule="upper"><span class="su-rule-i"></span>Uppercase</li>
                                <li data-rule="lower"><span class="su-rule-i"></span>Lowercase</li>
                                <li data-rule="num"><span class="su-rule-i"></span>Number</li>
                                <li data-rule="special"><span class="su-rule-i"></span>@ # $ % ^ &amp; * ! ?</li>
                            </ul>
                            <p class="su-err" id="rp-charset-err" hidden>That password uses a character the site does not accept.</p>
                            <p class="su-err" id="rp-match-err" hidden>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <circle cx="12" cy="12" r="9" />
                                    <path stroke-linecap="round" d="M12 7.5v5M12 16.2v.3" />
                                </svg>
                                Passwords do not match.
                            </p>
                        </div>

                        <button class="auth-submit" type="submit" name="reset">Change Password</button>
                    </form>

                    <p class="auth-switch">
                        Changed your mind?
                        <a href="<?= htmlspecialchars(url('login')) ?>">Back to Log In</a>
                    </p>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <script>
        function togglePw(id, btn) {
            const input = document.getElementById(id);
            const showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            btn.classList.toggle('is-on', !showing);
        }

        (function() {
            const form = document.getElementById('resetForm');
            if (!form) return;   // the "link expired" view has no form

            const pw = document.getElementById('rp-password');
            const confirm = document.getElementById('rp-confirm');
            const meter = document.querySelectorAll('.su-meter span');
            const strengthTxt = document.getElementById('rp-strength');
            const rules = document.getElementById('rp-rules');
            const charsetErr = document.getElementById('rp-charset-err');
            const matchErr = document.getElementById('rp-match-err');

            // Mirrors the server regex above, character class included.
            const ALLOWED = /^[A-Za-z\d@#$%^&*!?]*$/;

            function checks(v) {
                return {
                    len: v.length >= <?= (int)$pw_min ?> && v.length <= 20,
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

            function repaint() {
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
                    strengthTxt.className = 'su-strength';
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

                matchErr.hidden = !(confirm.value !== '' && confirm.value !== pw.value);
            }

            [pw, confirm].forEach(el => el.addEventListener('input', repaint));
            repaint();

            form.addEventListener('submit', function(e) {
                let stop = null;
                if (!passwordOk(pw.value)) stop = pw;
                else if (confirm.value !== pw.value) stop = confirm;

                if (stop) {
                    e.preventDefault();
                    repaint();
                    stop.focus();
                }
            });
        })();
    </script>

    <?php require __DIR__ . '/../includes/page_transition.php'; ?>
</body>

</html>
