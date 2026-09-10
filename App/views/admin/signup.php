<?php

/**
 * admin/signup.php — Create Admin Account.
 *
 * Three real steps, because the page shows a three-step indicator:
 *
 *   1. Account details — everything including the admin key. Nothing is
 *      written to the database here; a verification code is emailed instead
 *      and the pending account is held in the session as a bcrypt hash.
 *   2. Verification    — the emailed code. Five attempts, fifteen minutes.
 *   3. Complete        — only now do the users/emails/passwords rows get
 *      written, in one transaction.
 *
 * Holding the pending signup in the session rather than in a table means an
 * abandoned attempt leaves nothing behind to clean up, and an unverified
 * admin row can never exist even for a moment.
 *
 * The admin key comes from ADMIN_INVITE_KEY in .env and fails closed: if the
 * variable is missing, no key is accepted rather than an empty one working.
 */

if (session_status() === PHP_SESSION_NONE) session_start();

include __DIR__ . '/../db.php';
require_once __DIR__ . '/../../services/EmailService.php';

// Already signed in as an admin — nothing to create.
if (($_SESSION['role'] ?? '') === 'admin') {
    header('Location: ' . url('admin-dashboard'));
    exit;
}

const ADM_CODE_TTL      = 900;  // 15 minutes
const ADM_MAX_ATTEMPTS  = 5;
const ADM_RESEND_WAIT   = 60;   // seconds between sends

$error   = '';
$notice  = '';
$step    = 1;
$old     = ['full_name' => '', 'email' => '', 'username' => ''];

/** The pending signup, or null once it has expired. */
$pending = $_SESSION['admin_signup'] ?? null;
if ($pending && ($pending['expires'] ?? 0) < time()) {
    unset($_SESSION['admin_signup']);
    $pending = null;
    $error   = 'That verification code expired. Please enter your details again.';
}
if ($pending) {
    $step = 2;
    $old  = ['full_name' => $pending['full_name'], 'email' => $pending['email'], 'username' => $pending['username']];
}
if (!empty($_SESSION['admin_signup_done'])) {
    $step = 3;
}

/** Six digits, and never echoed back to the page. */
function adm_new_code(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function adm_send_code(string $to, string $name, string $code): array
{
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:auto">'
        . '<h2 style="color:#020547;margin:0 0 6px">Confirm your admin account</h2>'
        . '<p style="color:#3d424d;font-size:14px;line-height:1.6">Hi ' . htmlspecialchars($name) . ', use this code to finish creating your PeerConnect admin account.</p>'
        . '<p style="font-size:30px;letter-spacing:8px;font-weight:700;color:#020547;background:#eaf6fb;'
        . 'padding:16px;text-align:center;border-radius:12px;margin:18px 0">' . htmlspecialchars($code) . '</p>'
        . '<p style="color:#717680;font-size:12.5px;line-height:1.6">The code is valid for 15 minutes. '
        . 'If you did not request an admin account, you can ignore this email — nothing has been created.</p>'
        . '</div>';

    return EmailService::send($to, 'Your PeerConnect admin verification code', $html);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch. Please refresh the page and try again.';
    }

    // ── Step 1: account details ──────────────────────────────────────────
    elseif ($action === 'details') {
        $step       = 1;
        $full_name  = trim(strip_tags($_POST['full_name'] ?? ''));
        $email      = trim($_POST['email'] ?? '');
        $username   = trim(strip_tags($_POST['username'] ?? ''));
        $password   = (string)($_POST['password'] ?? '');
        $confirm    = (string)($_POST['confirm'] ?? '');
        $admin_key  = trim((string)($_POST['admin_key'] ?? ''));
        $agreed     = isset($_POST['agree']);
        $old        = ['full_name' => $full_name, 'email' => $email, 'username' => $username];

        // No fallback: an unset ADMIN_INVITE_KEY must reject every key.
        $expected_key = (string)($_ENV['ADMIN_INVITE_KEY'] ?? '');

        if ($full_name === '' || $email === '' || $username === '' || $password === '') {
            $error = 'Fill in every field to continue.';
        } elseif (mb_strlen($full_name) > 100 || mb_strlen($username) > 30 || mb_strlen($email) > 255) {
            $error = 'One of those values is longer than we can store.';
        } elseif (!preg_match('/^[A-Za-z0-9_.]{3,30}$/', $username)) {
            $error = 'Usernames use 3–30 letters, numbers, dots or underscores.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'That email address does not look right.';
        } elseif (strlen($password) < 8) {
            $error = 'Use at least 8 characters for your password.';
        } elseif ($password !== $confirm) {
            $error = 'The two passwords do not match.';
        } elseif (!$agreed) {
            $error = 'Please accept the Terms of Service and Privacy Policy to continue.';
        } elseif ($expected_key === '' || !hash_equals($expected_key, $admin_key)) {
            // Same message either way — a distinct "not configured" error would
            // tell an outsider that no key can currently work.
            $error = 'That admin key is not valid. Ask the system owner for the current key.';
        } elseif (!EmailService::isConfigured()) {
            $error = 'Email is not configured on this server, so the verification code cannot be sent. Contact the system owner.';
        } else {
            $dupe = $con->prepare("SELECT 1 FROM emails WHERE email = ? LIMIT 1");
            $dupe->bind_param("s", $email);
            $dupe->execute();
            $email_taken = (bool)$dupe->get_result()->fetch_row();
            $dupe->close();

            $du = $con->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
            $du->bind_param("s", $username);
            $du->execute();
            $user_taken = (bool)$du->get_result()->fetch_row();
            $du->close();

            if ($email_taken) {
                $error = 'That email already has an account. Sign in instead.';
            } elseif ($user_taken) {
                $error = 'That username is taken. Try another.';
            } elseif (!rate_limit('admin_signup_send', 6, 900)) {
                // Checked here rather than at the top of the branch: the limit
                // exists to cap how many emails one visitor can trigger, not
                // to punish someone mistyping their own password.
                $error = 'Too many verification codes requested. Please wait a few minutes.';
            } else {
                $code  = adm_new_code();
                $parts = preg_split('/\s+/', $full_name, 2);
                $sent  = adm_send_code($email, $parts[0], $code);

                if (!$sent['success']) {
                    error_log('Admin signup code send failed: ' . ($sent['error'] ?? 'unknown'));
                    $error = 'We could not send the verification code. Please try again in a moment.';
                } else {
                    $_SESSION['admin_signup'] = [
                        'full_name' => $full_name,
                        'firstname' => $parts[0],
                        'lastname'  => $parts[1] ?? '',
                        'email'     => $email,
                        'username'  => $username,
                        // The password never sits in the session as plaintext.
                        'hash'      => password_hash($password, PASSWORD_BCRYPT),
                        'code'      => hash('sha256', $code),
                        'expires'   => time() + ADM_CODE_TTL,
                        'attempts'  => 0,
                        'last_sent' => time(),
                    ];
                    $pending = $_SESSION['admin_signup'];
                    $step    = 2;
                    $notice  = 'We sent a 6-digit code to ' . $email . '.';
                }
            }
        }
    }

    // ── Resend ───────────────────────────────────────────────────────────
    elseif ($action === 'resend' && $pending) {
        $step = 2;
        $wait = ADM_RESEND_WAIT - (time() - (int)$pending['last_sent']);
        if ($wait > 0) {
            $error = 'Please wait ' . $wait . ' more second' . ($wait === 1 ? '' : 's') . ' before asking for another code.';
        } elseif (!rate_limit('admin_signup_resend', 5, 900)) {
            $error = 'That is too many codes for now. Please try again later.';
        } else {
            $code = adm_new_code();
            $sent = adm_send_code($pending['email'], $pending['firstname'], $code);
            if (!$sent['success']) {
                error_log('Admin signup resend failed: ' . ($sent['error'] ?? 'unknown'));
                $error = 'We could not send another code just now. Please try again shortly.';
            } else {
                $_SESSION['admin_signup']['code']      = hash('sha256', $code);
                $_SESSION['admin_signup']['expires']   = time() + ADM_CODE_TTL;
                $_SESSION['admin_signup']['attempts']  = 0;
                $_SESSION['admin_signup']['last_sent'] = time();
                $pending = $_SESSION['admin_signup'];
                $notice  = 'A new code is on its way to ' . $pending['email'] . '.';
            }
        }
    }

    // ── Start over ───────────────────────────────────────────────────────
    elseif ($action === 'restart') {
        unset($_SESSION['admin_signup']);
        $pending = null;
        $step    = 1;
        $old     = ['full_name' => '', 'email' => '', 'username' => ''];
    }

    // ── Step 2: verify, then create ──────────────────────────────────────
    elseif ($action === 'verify' && $pending) {
        $step = 2;
        $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));

        if (!rate_limit('admin_signup_verify', 12, 600)) {
            $error = 'Too many tries. Please wait a few minutes.';
        } elseif ($code === '') {
            $error = 'Enter the 6-digit code from your email.';
        } elseif (!hash_equals($pending['code'], hash('sha256', $code))) {
            $_SESSION['admin_signup']['attempts'] = (int)$pending['attempts'] + 1;
            $left = ADM_MAX_ATTEMPTS - $_SESSION['admin_signup']['attempts'];
            if ($left <= 0) {
                unset($_SESSION['admin_signup']);
                $pending = null;
                $step    = 1;
                $error   = 'Too many incorrect codes. Please start again.';
            } else {
                $pending = $_SESSION['admin_signup'];
                $error   = 'That code is not right. ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left.';
            }
        } else {
            // Re-check both uniqueness constraints: someone may have taken the
            // email or username during the fifteen minutes we were waiting.
            $dupe = $con->prepare("SELECT 1 FROM emails WHERE email = ? LIMIT 1");
            $dupe->bind_param("s", $pending['email']);
            $dupe->execute();
            $email_taken = (bool)$dupe->get_result()->fetch_row();
            $dupe->close();

            $du = $con->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
            $du->bind_param("s", $pending['username']);
            $du->execute();
            $user_taken = (bool)$du->get_result()->fetch_row();
            $du->close();

            if ($email_taken || $user_taken) {
                unset($_SESSION['admin_signup']);
                $pending = null;
                $step    = 1;
                $error   = 'That email or username was registered while you were verifying. Please start again.';
            } else {
                $con->begin_transaction();
                try {
                    // users.email as well as the emails row. Leaving it NULL
                    // here is how the admin account came to be missing from
                    // every report that joins on users.email.
                    $ins = $con->prepare("INSERT INTO users (firstname, lastname, username, role, status, verified, email) VALUES (?,?,?,'admin','active',1,?)");
                    $ins->bind_param("ssss", $pending['firstname'], $pending['lastname'], $pending['username'], $pending['email']);
                    $ins->execute();
                    $uid = (int)$ins->insert_id;
                    $ins->close();

                    $ins2 = $con->prepare("INSERT INTO emails (user_id, email) VALUES (?,?)");
                    $ins2->bind_param("is", $uid, $pending['email']);
                    $ins2->execute();
                    $ins2->close();

                    $ins3 = $con->prepare("INSERT INTO passwords (user_id, password_hash) VALUES (?,?)");
                    $ins3->bind_param("is", $uid, $pending['hash']);
                    $ins3->execute();
                    $ins3->close();

                    $con->commit();
                    $created_email = $pending['email'];
                    $_SESSION['admin_signup_done'] = $pending['full_name'];
                    unset($_SESSION['admin_signup']);
                    $pending = null;
                    $step    = 3;
                } catch (Throwable $e) {
                    $con->rollback();
                    error_log('Admin signup insert failed: ' . $e->getMessage());
                    $error = 'We could not create the account. Please try again.';
                }

                // Outside the try on purpose: the account exists either way,
                // and a failed audit write must not report success as failure.
                if ($step === 3) {
                    try {
                        logMe($created_email, date('Y-m-d H:i:s'), 'admin account created');
                    } catch (Throwable $e) {
                        error_log('Admin signup log failed: ' . $e->getMessage());
                    }
                }
            }
        }
    }
}

$csrf       = csrf_token();
$login_url  = url('admin-login');
$home_url   = url('welcomepage');
$done_name  = $_SESSION['admin_signup_done'] ?? '';
if ($step === 3) {
    // One-shot: refreshing the finished page returns to a blank form.
    unset($_SESSION['admin_signup_done']);
}
$masked = '';
if ($pending) {
    $bits   = explode('@', $pending['email']);
    $masked = mb_substr($bits[0], 0, 2) . str_repeat('•', max(3, mb_strlen($bits[0]) - 2)) . '@' . ($bits[1] ?? '');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Create Admin Account — PeerConnect</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&display=swap" rel="stylesheet">
    <?php $pcAuthDir = 'forward'; ?>
    <?php require __DIR__ . '/includes/auth_transition.php'; ?>
    <style>
        :root {
            --navy: #071033;
            --navy-2: #0D1B47;
            --navy-3: #16265C;
            --blue: #1B6FD1;
            --blue-2: #4E9BEA;
            --sky: #8FC4F5;
            --ink: #101828;
            --ink-2: #475069;
            --ink-3: #7A8299;
            --line: #DFE4EE;
            --line-2: #EDF0F6;
            --field: #FFFFFF;
            --wash: #EEF5FD;
            --danger: #B42318;
            --danger-bg: #FEF3F2;
            --ok: #15734F;
            --ok-bg: #E6F5EE;
            --radius: 12px;
        }

        * { box-sizing: border-box; }

        html, body { height: 100%; }

        body {
            margin: 0;
            background: #F4F7FC;
            color: var(--ink);
            font-family: 'DM Sans', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
            font-size: 15px;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
        }

        .as-shell {
            min-height: 100%;
            display: grid;
            grid-template-columns: minmax(0, 1.02fr) minmax(0, 1fr);
        }

        /* ── Left: navy panel over the photo, with an angled seam ───────── */
        .as-left {
            position: relative;
            background-image: url("<?= htmlspecialchars(asset('images/adminBg.png')) ?>");
            background-size: cover;
            background-position: center;
            display: flex;
            isolation: isolate;
        }

        .as-left::before {
            content: "";
            position: absolute;
            inset: 0;
            z-index: 0;
            background: linear-gradient(180deg, rgba(7, 16, 51, .35), rgba(7, 16, 51, .55));
        }

        .as-panel {
            position: relative;
            z-index: 2;
            width: min(560px, 62%);
            padding: 46px 44px 40px;
            display: flex;
            flex-direction: column;
            gap: 30px;
            color: #fff;
            background: linear-gradient(158deg, var(--navy) 0%, var(--navy-2) 58%, var(--navy-3) 100%);
            /* The angled seam the photo shows through. */
            clip-path: polygon(0 0, 100% 0, calc(100% - 78px) 100%, 0 100%);
            padding-right: 92px;
        }

        .as-brand { display: flex; align-items: center; gap: 13px; }

        .as-brand svg { width: 42px; height: 42px; flex: 0 0 42px; }

        .as-brand-name {
            font-size: 24px;
            font-weight: 700;
            letter-spacing: .01em;
            line-height: 1.1;
        }

        .as-brand-name em { font-style: normal; color: var(--blue-2); }

        .as-brand-tag { font-size: 11.5px; color: rgba(255, 255, 255, .62); }

        .as-hero { display: flex; flex-direction: column; gap: 14px; }

        .as-hero h1 {
            margin: 0;
            font-size: clamp(30px, 3.4vw, 42px);
            font-weight: 700;
            line-height: 1.14;
            letter-spacing: -.02em;
            text-wrap: balance;
        }

        .as-hero h1 span { color: var(--sky); }

        .as-hero p {
            margin: 0;
            max-width: 42ch;
            font-size: 15px;
            color: rgba(255, 255, 255, .76);
        }

        .as-points { display: flex; flex-direction: column; gap: 18px; margin-top: 2px; }

        .as-point { display: flex; gap: 14px; align-items: flex-start; }

        .as-point-ico {
            flex: 0 0 42px;
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            background: rgba(255, 255, 255, .09);
            border: 1px solid rgba(255, 255, 255, .16);
            color: var(--sky);
        }

        .as-point-ico svg { width: 20px; height: 20px; }

        .as-point-t { font-size: 15px; font-weight: 600; margin: 2px 0 2px; }

        .as-point-d { font-size: 13.5px; color: rgba(255, 255, 255, .66); max-width: 34ch; }

        .as-foot { margin-top: auto; display: flex; flex-direction: column; gap: 12px; }

        /* Real progress: the bar tracks the step you are on. */
        .as-bar {
            height: 4px;
            width: min(330px, 100%);
            border-radius: 99px;
            background: rgba(255, 255, 255, .16);
            overflow: hidden;
        }

        .as-bar i {
            display: block;
            height: 100%;
            border-radius: 99px;
            background: var(--blue-2);
            width: <?= [1 => '33%', 2 => '66%', 3 => '100%'][$step] ?>;
            transition: width .4s ease;
        }

        .as-foot span { font-size: 13px; color: rgba(255, 255, 255, .6); }

        /* The pull-quote card floating over the photo. */
        .as-quote {
            position: absolute;
            z-index: 1;
            left: calc(min(560px, 62%) - 28px);
            top: 27%;
            width: min(290px, 30%);
            padding: 20px 22px 22px;
            border-radius: 14px;
            background: rgba(9, 18, 48, .52);
            border: 1px solid rgba(255, 255, 255, .16);
            backdrop-filter: blur(7px);
            -webkit-backdrop-filter: blur(7px);
            color: #fff;
        }

        .as-quote-mark { font-size: 30px; line-height: 1; color: rgba(255, 255, 255, .5); }

        .as-quote p {
            margin: 6px 0 14px;
            font-size: 17px;
            font-weight: 500;
            line-height: 1.38;
        }

        .as-quote i { display: block; width: 46px; height: 2px; background: rgba(255, 255, 255, .45); }

        /* ── Right: the form ────────────────────────────────────────────── */
        .as-right {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 34px;
        }

        .as-card {
            width: min(620px, 100%);
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 16px;
            box-shadow: 0 1px 2px rgba(16, 24, 40, .04), 0 24px 48px -32px rgba(16, 24, 40, .28);
            padding: 34px 36px 32px;
        }

        .as-card h2 {
            margin: 0 0 6px;
            font-size: 27px;
            font-weight: 700;
            letter-spacing: -.02em;
            text-align: center;
            color: var(--navy-2);
        }

        .as-card-sub {
            margin: 0 auto 24px;
            max-width: 46ch;
            text-align: center;
            font-size: 14px;
            color: var(--ink-3);
        }

        /* ── Stepper ────────────────────────────────────────────────────── */
        .as-steps {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            margin-bottom: 28px;
        }

        .as-step { position: relative; display: flex; flex-direction: column; align-items: center; gap: 8px; }

        /* The connector sits behind the dots, drawn from each step to the next. */
        .as-step:not(:last-child)::after {
            content: "";
            position: absolute;
            top: 17px;
            left: calc(50% + 22px);
            right: calc(-50% + 22px);
            height: 2px;
            background: var(--line);
        }

        .as-step.done:not(:last-child)::after { background: var(--blue); }

        .as-dot {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: var(--wash);
            color: var(--ink-3);
            font-size: 14px;
            font-weight: 700;
            border: 2px solid transparent;
            position: relative;
            z-index: 1;
        }

        .as-step.on .as-dot { background: var(--blue); color: #fff; }
        .as-step.done .as-dot { background: #fff; color: var(--blue); border-color: var(--blue); }
        .as-step.done .as-dot svg { width: 15px; height: 15px; }

        .as-step-l { font-size: 12.5px; color: var(--ink-3); text-align: center; }
        .as-step.on .as-step-l { color: var(--navy-2); font-weight: 600; }
        .as-step.done .as-step-l { color: var(--ink-2); }

        /* ── Fields ─────────────────────────────────────────────────────── */
        .as-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px 18px; }
        .as-full { grid-column: 1 / -1; }

        .as-f { display: flex; flex-direction: column; gap: 6px; }

        .as-f > label {
            font-size: 13.5px;
            font-weight: 600;
            color: var(--navy-2);
        }

        .as-in { position: relative; display: flex; align-items: center; }

        .as-in > svg:first-child {
            position: absolute;
            left: 13px;
            width: 17px;
            height: 17px;
            color: var(--ink-3);
            pointer-events: none;
        }

        .as-in input {
            width: 100%;
            height: 46px;
            padding: 0 14px 0 40px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: var(--field);
            font-family: inherit;
            font-size: 14.5px;
            color: var(--ink);
        }

        .as-in input::placeholder { color: #A7AEC0; }

        .as-in input:focus {
            outline: none;
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(27, 111, 209, .14);
        }

        .as-peek {
            position: absolute;
            right: 8px;
            width: 32px;
            height: 32px;
            display: grid;
            place-items: center;
            border: none;
            background: none;
            color: var(--ink-3);
            cursor: pointer;
            border-radius: 7px;
        }

        .as-peek:hover { color: var(--ink-2); background: var(--line-2); }
        .as-peek svg { width: 17px; height: 17px; }
        .as-in:has(.as-peek) input { padding-right: 44px; }

        /* ── Admin key block ────────────────────────────────────────────── */
        .as-key {
            margin-top: 18px;
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 16px 22px;
            padding: 16px 18px;
            background: var(--wash);
            border: 1px solid #D5E6F8;
            border-radius: var(--radius);
        }

        .as-key .as-in input { background: #fff; }

        .as-key-help { display: flex; gap: 11px; align-items: flex-start; }

        .as-key-help > span {
            flex: 0 0 26px;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: #fff;
            border: 1px solid #CBDFF5;
            color: var(--blue);
            font-size: 13px;
            font-weight: 700;
        }

        .as-key-help p { margin: 0; font-size: 12.5px; line-height: 1.5; color: var(--ink-2); }
        .as-key-help b { display: block; color: var(--navy-2); font-size: 13px; margin-bottom: 2px; }

        /* ── Agree + submit ─────────────────────────────────────────────── */
        .as-agree {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin: 20px 0 18px;
            font-size: 13.5px;
            color: var(--ink-2);
        }

        .as-agree input {
            margin: 2px 0 0;
            width: 17px;
            height: 17px;
            accent-color: var(--blue);
            flex: 0 0 17px;
        }

        .as-link {
            border: none;
            background: none;
            padding: 0;
            font: inherit;
            color: var(--blue);
            text-decoration: underline;
            cursor: pointer;
        }

        .as-link:hover { color: #124F97; }

        .as-btn {
            width: 100%;
            height: 50px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            border: none;
            border-radius: 10px;
            background: var(--navy-2);
            color: #fff;
            font-family: inherit;
            font-size: 15.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s;
        }

        .as-btn:hover { background: #16265C; }
        .as-btn:focus-visible { outline: 3px solid rgba(27, 111, 209, .4); outline-offset: 2px; }
        .as-btn svg { width: 17px; height: 17px; }

        .as-btn-ghost {
            background: none;
            color: var(--ink-2);
            border: 1px solid var(--line);
            height: 44px;
            font-size: 14.5px;
        }

        .as-btn-ghost:hover { background: var(--line-2); color: var(--ink); }

        .as-alt {
            margin: 18px 0 0;
            text-align: center;
            font-size: 14px;
            color: var(--ink-3);
        }

        .as-alt a { color: var(--blue); font-weight: 600; text-decoration: none; }
        .as-alt a:hover { text-decoration: underline; }

        /* ── Messages ───────────────────────────────────────────────────── */
        .as-msg {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 13.5px;
            margin-bottom: 18px;
        }

        .as-msg svg { flex: 0 0 17px; width: 17px; height: 17px; margin-top: 1px; }
        .as-err { background: var(--danger-bg); color: var(--danger); border: 1px solid #FBD3CE; }
        .as-ok { background: var(--ok-bg); color: var(--ok); border: 1px solid #C6E7D8; }

        /* ── Step 2: code ───────────────────────────────────────────────── */
        .as-code {
            width: 100%;
            height: 62px;
            text-align: center;
            font-size: 30px;
            font-weight: 700;
            letter-spacing: .42em;
            text-indent: .42em;
            border: 1px solid var(--line);
            border-radius: 12px;
            font-family: inherit;
            color: var(--navy-2);
            background: var(--field);
        }

        .as-code:focus {
            outline: none;
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(27, 111, 209, .14);
        }

        .as-sent {
            text-align: center;
            font-size: 14px;
            color: var(--ink-2);
            margin: 0 0 20px;
        }

        .as-sent b { color: var(--navy-2); }

        .as-row { display: flex; gap: 10px; margin-top: 14px; }
        .as-row form { flex: 1; }

        /* ── Step 3: done ───────────────────────────────────────────────── */
        .as-done { text-align: center; padding: 8px 0 4px; }

        .as-done-ico {
            width: 66px;
            height: 66px;
            margin: 0 auto 18px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: var(--ok-bg);
            color: var(--ok);
        }

        .as-done-ico svg { width: 30px; height: 30px; }
        .as-done p { margin: 0 auto 22px; max-width: 40ch; color: var(--ink-2); font-size: 14.5px; }

        /* ── Responsive ─────────────────────────────────────────────────── */
        @media (max-width: 1080px) {
            .as-shell { grid-template-columns: 1fr; }

            .as-left { min-height: 300px; }

            .as-panel {
                width: 100%;
                clip-path: none;
                padding: 30px 26px 26px;
                gap: 22px;
                background: linear-gradient(158deg, rgba(7, 16, 51, .95), rgba(22, 38, 92, .92));
            }

            .as-points { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }

            .as-quote { display: none; }

            .as-right { padding: 26px 18px 46px; }
        }

        @media (max-width: 620px) {
            .as-grid { grid-template-columns: 1fr; }
            .as-key { grid-template-columns: 1fr; }
            .as-card { padding: 24px 20px 22px; border-radius: 14px; }
            .as-card h2 { font-size: 23px; }
            .as-step-l { font-size: 11px; }
            .as-points { grid-template-columns: 1fr; }
            .as-code { font-size: 24px; letter-spacing: .3em; text-indent: .3em; }
        }
    </style>
</head>

<body>
    <div class="as-shell">

        <!-- ══════════ Left ══════════ -->
        <div class="as-left pc-auth-panel">
            <div class="as-panel">
                <a class="as-brand" href="<?= htmlspecialchars($home_url) ?>" style="text-decoration:none;color:inherit;">
                    <svg viewBox="0 0 48 48" fill="none" aria-hidden="true">
                        <circle cx="24" cy="24" r="21" fill="#fff" opacity=".12" />
                        <circle cx="24" cy="24" r="12.5" stroke="#fff" stroke-width="2.6" />
                        <path d="M18 22.4c0-2.2 1.8-4 4-4 1.8 0 2.9.8 3.2 2 .3-1.2 1.4-2 3.2-2 2.2 0 4 1.8 4 4 0 3.6-5 6-7.2 7-2.2-1-7.2-3.4-7.2-7Z" stroke="#8FC4F5" stroke-width="2" stroke-linejoin="round" />
                    </svg>
                    <span>
                        <span class="as-brand-name">PEER<em>CONNECT</em></span><br>
                        <span class="as-brand-tag">Mentoring. Growing. Together.</span>
                    </span>
                </a>

                <div class="as-hero">
                    <h1>Join the Team That Builds <span>Brighter Futures</span></h1>
                    <p>Create your admin account and help us maintain a safe, supportive, and impactful mentorship community.</p>
                </div>

                <div class="as-points">
                    <div class="as-point">
                        <span class="as-point-ico">
                            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7.5 3.2v5.1c0 4.3-3 8.2-7.5 9.7-4.5-1.5-7.5-5.4-7.5-9.7V6.2L12 3Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="m9 12 2 2 4-4" />
                            </svg>
                        </span>
                        <div>
                            <p class="as-point-t">Secure Access</p>
                            <p class="as-point-d">Protected with an admin key and an emailed verification code.</p>
                        </div>
                    </div>

                    <div class="as-point">
                        <span class="as-point-ico">
                            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 12h2m12 0h2M12 4v2m0 12v2M6.3 6.3l1.4 1.4m8.6 8.6 1.4 1.4m0-11.4-1.4 1.4m-8.6 8.6-1.4 1.4" />
                            </svg>
                        </span>
                        <div>
                            <p class="as-point-t">Powerful Tools</p>
                            <p class="as-point-d">Manage users, verifications, badges, categories and reports.</p>
                        </div>
                    </div>

                    <div class="as-point">
                        <span class="as-point-ico">
                            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" d="M5 20V11M12 20V4M19 20v-6" />
                            </svg>
                        </span>
                        <div>
                            <p class="as-point-t">Make an Impact</p>
                            <p class="as-point-d">See how mentoring is going across the whole community.</p>
                        </div>
                    </div>

                    <div class="as-point">
                        <span class="as-point-ico">
                            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM20 19c0-1.8-1.2-3.3-2.8-3.8M17 4.4a3 3 0 0 1 0 5.2" />
                            </svg>
                        </span>
                        <div>
                            <p class="as-point-t">Be Part of the Mission</p>
                            <p class="as-point-d">Support mentorship. Empower growth.</p>
                        </div>
                    </div>
                </div>

                <div class="as-foot">
                    <div class="as-bar"><i></i></div>
                    <span>A stronger community together.</span>
                </div>
            </div>

            <figure class="as-quote">
                <div class="as-quote-mark">&ldquo;</div>
                <p>Great communities don't just happen &mdash; they're built by people who care.</p>
                <i></i>
            </figure>
        </div>

        <!-- ══════════ Right ══════════ -->
        <div class="as-right">
            <div class="as-card pc-auth-card">

                <?php if ($step === 3): ?>
                    <h2>You're all set</h2>
                    <p class="as-card-sub">Your admin account is ready to use.</p>
                <?php elseif ($step === 2): ?>
                    <h2>Check your email</h2>
                    <p class="as-card-sub">We sent a 6-digit code to confirm this is really you.</p>
                <?php else: ?>
                    <h2>Create Admin Account</h2>
                    <p class="as-card-sub">Fill in your information and use the admin key provided by the system owner.</p>
                <?php endif; ?>

                <!-- Stepper -->
                <div class="as-steps">
                    <?php
                    $labels = [1 => 'Account Details', 2 => 'Verification', 3 => 'Complete'];
                    foreach ($labels as $n => $label):
                        $cls = $n < $step ? 'done' : ($n === $step ? 'on' : '');
                    ?>
                        <div class="as-step <?= $cls ?>">
                            <span class="as-dot">
                                <?php if ($n < $step): ?>
                                    <svg fill="none" stroke="currentColor" stroke-width="2.6" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12.5 9.5 17 19 7" />
                                    </svg>
                                <?php else: ?><?= $n ?><?php endif; ?>
                            </span>
                            <span class="as-step-l"><?= $label ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="as-msg as-err" role="alert">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" />
                            <path stroke-linecap="round" d="M12 7.5v5M12 16h.01" />
                        </svg>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php elseif ($notice !== ''): ?>
                    <div class="as-msg as-ok" role="status">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.5 12.4 2.3 2.3 4.9-5.4" />
                        </svg>
                        <span><?= htmlspecialchars($notice) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($step === 1): ?>
                    <!-- ── Step 1 ── -->
                    <form method="POST" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="details">

                        <div class="as-grid">
                            <div class="as-f">
                                <label for="f-name">Full Name</label>
                                <div class="as-in">
                                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                        <circle cx="12" cy="8" r="3.6" />
                                        <path stroke-linecap="round" d="M5 20c.7-3.5 3.5-5.4 7-5.4s6.3 1.9 7 5.4" />
                                    </svg>
                                    <input id="f-name" name="full_name" maxlength="100" required
                                        placeholder="Enter your full name" value="<?= htmlspecialchars($old['full_name']) ?>">
                                </div>
                            </div>

                            <div class="as-f">
                                <label for="f-email">Email Address</label>
                                <div class="as-in">
                                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                        <rect x="3" y="5.5" width="18" height="13" rx="2.5" />
                                        <path stroke-linecap="round" d="m4 7 8 6 8-6" />
                                    </svg>
                                    <input id="f-email" name="email" type="email" maxlength="255" required
                                        placeholder="Enter your official email" value="<?= htmlspecialchars($old['email']) ?>">
                                </div>
                            </div>

                            <div class="as-f">
                                <label for="f-user">Username</label>
                                <div class="as-in">
                                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" />
                                    </svg>
                                    <input id="f-user" name="username" maxlength="30" required
                                        placeholder="Choose a username" value="<?= htmlspecialchars($old['username']) ?>">
                                </div>
                            </div>

                            <div class="as-f">
                                <label for="f-pass">Password</label>
                                <div class="as-in">
                                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                        <rect x="4.5" y="10" width="15" height="10" rx="2" />
                                        <path stroke-linecap="round" d="M8 10V7.5a4 4 0 0 1 8 0V10" />
                                    </svg>
                                    <input id="f-pass" name="password" type="password" required minlength="8"
                                        placeholder="Create a password" autocomplete="new-password">
                                    <button type="button" class="as-peek" onclick="asPeek('f-pass', this)" aria-label="Show password">
                                        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" />
                                            <circle cx="12" cy="12" r="3" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div class="as-f as-full">
                                <label for="f-confirm">Confirm Password</label>
                                <div class="as-in">
                                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                        <rect x="4.5" y="10" width="15" height="10" rx="2" />
                                        <path stroke-linecap="round" d="M8 10V7.5a4 4 0 0 1 8 0V10" />
                                    </svg>
                                    <input id="f-confirm" name="confirm" type="password" required minlength="8"
                                        placeholder="Confirm your password" autocomplete="new-password">
                                    <button type="button" class="as-peek" onclick="asPeek('f-confirm', this)" aria-label="Show password">
                                        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" />
                                            <circle cx="12" cy="12" r="3" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="as-key">
                            <div class="as-f">
                                <label for="f-key">Admin Key</label>
                                <div class="as-in">
                                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                        <circle cx="8" cy="16" r="3.5" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m10.5 13.5 8-8M16 8l2 2M19 5l2 2" />
                                    </svg>
                                    <input id="f-key" name="admin_key" required placeholder="Enter admin key" autocomplete="off">
                                </div>
                            </div>
                            <div class="as-key-help">
                                <span>?</span>
                                <p>
                                    <b>What is an admin key?</b>
                                    A secure code held by the system owner. It authorises creating an admin
                                    account, and is checked before any code is emailed.
                                </p>
                            </div>
                        </div>

                        <label class="as-agree">
                            <input type="checkbox" name="agree" value="1" required>
                            <span>I agree to the
                                <button type="button" class="as-link" onclick="openLegal('terms')">Terms of Service</button>
                                and
                                <button type="button" class="as-link" onclick="openLegal('privacy')">Privacy Policy</button>.
                            </span>
                        </label>

                        <button type="submit" class="as-btn">
                            Continue
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 12h15m0 0-5.5-5.5M19 12l-5.5 5.5" />
                            </svg>
                        </button>
                    </form>

                    <p class="as-alt">Already have an account? <a href="<?= htmlspecialchars($login_url) ?>">Sign In</a></p>

                <?php elseif ($step === 2): ?>
                    <!-- ── Step 2 ── -->
                    <p class="as-sent">Enter the code we sent to <b><?= htmlspecialchars($masked) ?></b></p>

                    <form method="POST" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="verify">
                        <input class="as-code" name="code" inputmode="numeric" autocomplete="one-time-code"
                            maxlength="6" pattern="[0-9]{6}" placeholder="000000" required autofocus
                            aria-label="6-digit verification code">
                        <button type="submit" class="as-btn" style="margin-top:16px;">
                            Verify and create account
                        </button>
                    </form>

                    <div class="as-row">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="action" value="resend">
                            <button type="submit" class="as-btn as-btn-ghost">Resend code</button>
                        </form>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="action" value="restart">
                            <button type="submit" class="as-btn as-btn-ghost">Change details</button>
                        </form>
                    </div>

                    <p class="as-alt">The code is valid for 15 minutes.</p>

                <?php else: ?>
                    <!-- ── Step 3 ── -->
                    <div class="as-done">
                        <div class="as-done-ico">
                            <svg fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 12.5 9.5 17 19 7" />
                            </svg>
                        </div>
                        <p>
                            <?= $done_name !== '' ? htmlspecialchars($done_name) . ', your' : 'Your' ?>
                            admin account has been created and verified. Sign in to reach the dashboard.
                        </p>
                        <a class="as-btn" href="<?= htmlspecialchars($login_url) ?>" style="text-decoration:none;">
                            Go to admin sign in
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 12h15m0 0-5.5-5.5M19 12l-5.5 5.5" />
                            </svg>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php require __DIR__ . '/../includes/legal_modal.php'; ?>

    <script>
        function asPeek(id, btn) {
            const f = document.getElementById(id);
            const shown = f.type === 'text';
            f.type = shown ? 'password' : 'text';
            btn.setAttribute('aria-label', shown ? 'Show password' : 'Hide password');
        }

        // Digits only in the code box, and submit as soon as six are in.
        (function() {
            const box = document.querySelector('.as-code');
            if (!box) return;
            box.addEventListener('input', () => {
                box.value = box.value.replace(/\D/g, '').slice(0, 6);
                if (box.value.length === 6) box.form.requestSubmit();
            });
        })();
    </script>
</body>

</html>
