<?php

/**
 * admin/login.php — Admin sign in.
 *
 * Separate from the portal login: credentials are only accepted for
 * users.role = 'admin'. Account creation lives on its own page
 * (App/views/admin/signup.php, route 'admin-signup'), which verifies the
 * email with a code before writing anything — this page never creates
 * accounts, so there is no weaker second path to becoming an admin.
 *
 * Brute-force protection matches the portal login: five failures locks the
 * form for five minutes, and a wrong email costs the same amount of time as
 * a wrong password so the form cannot be used to enumerate admin addresses.
 */

if (session_status() === PHP_SESSION_NONE) session_start();

include __DIR__ . '/../db.php';
require_once __DIR__ . '/../../services/RememberService.php';

// Already signed in as an admin — nothing to do here.
if (($_SESSION['role'] ?? '') === 'admin') {
    header('Location: ' . url('admin-dashboard'));
    exit;
}

// How many tries and how long the lock lasts come from System Settings →
// Security, so an admin can tighten or loosen it without a code change.
// Turning the lockout off entirely sets the ceiling out of reach rather than
// branching everywhere the count is consulted.
define('ADMIN_MAX_TRIES', pc_setting_bool($con, 'login_lockout_enable')
    ? max(1, pc_setting_int($con, 'login_max_attempts', 5))
    : PHP_INT_MAX);
define('ADMIN_LOCK_SECS', max(1, pc_setting_int($con, 'login_lockout_mins', 5)) * 60);

/**
 * A valid bcrypt hash to compare against when no admin matched, so a missing
 * account and a wrong password take the same time. Comparing against a
 * malformed string returns immediately and gives the difference away.
 */
const ADMIN_DUMMY_HASH = '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy';

$login_error = $_SESSION['admin_login_error'] ?? '';
unset($_SESSION['admin_login_error']);

// "Remember me" from a previous visit — only when merely arriving, never on a
// submitted form. (The portal login had the reverse bug once: posting real
// credentials while holding somebody else's cookie signed you in as them.)
$is_post = $_SERVER['REQUEST_METHOD'] === 'POST';
if (!$is_post && !isset($_GET['switch']) && ($remembered = RememberService::attempt($con))) {
    $who = $con->prepare("SELECT role FROM users WHERE user_id = ? LIMIT 1");
    $who->bind_param("i", $remembered);
    $who->execute();
    $role = $who->get_result()->fetch_assoc()['role'] ?? '';
    $who->close();

    if ($role === 'admin') {
        header('Location: ' . url('admin-dashboard'));
        exit;
    }
    // Someone else's remembered session: RememberService already signed them
    // in, so send them to their own side rather than leaving them here.
    header('Location: ' . url($role === 'mentor' ? 'mentor-dashboard' : 'mentee-dashboard'));
    exit;
}

if ($is_post && isset($_POST['admin_login'])) {
    $email    = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $remember = isset($_POST['remember']);

    // The same persistent store the member sign-in uses. This counter lived in
    // $_SESSION, so throwing the session cookie away between attempts reset it
    // to zero and the lockout never actually locked anyone out.
    $throttle    = substr('admin-login:' . strtolower($email) . '|' . pc_client_ip(), 0, 190);
    $retry_after = ADMIN_MAX_TRIES === PHP_INT_MAX
        ? 0
        : pc_throttle_retry_after($con, $throttle, ADMIN_LOCK_SECS, ADMIN_MAX_TRIES);

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $login_error = 'Security token mismatch. Please refresh and try again.';
    } elseif ($retry_after > 0) {
        $login_error = 'Too many failed attempts. Try again in '
            . floor($retry_after / 60) . 'm ' . ($retry_after % 60) . 's.';
    } else {

        if ($email === '' || $password === '') {
            $login_error = 'Enter your email and password.';
        } else {
            $stmt = $con->prepare("
                SELECT u.user_id, u.firstname, u.status, p.password_hash
                FROM users u
                JOIN emails e    ON e.user_id = u.user_id
                JOIN passwords p ON p.user_id = u.user_id
                WHERE e.email = ? AND u.role = 'admin'
                LIMIT 1
            ");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // Always spend the bcrypt time, matched or not.
            if ($row) {
                $ok = password_verify($password, $row['password_hash']);
            } else {
                password_verify($password, ADMIN_DUMMY_HASH);
                $ok = false;
            }

            if ($ok && $row['status'] === 'blocked') {
                $login_error = 'This account has been blocked.';
            } elseif ($ok) {
                // One good sign-in forgets the failures for this pair.
                pc_throttle_clear($con, $throttle);

                session_regenerate_id(true);
                $_SESSION['user_id']   = (int)$row['user_id'];
                $_SESSION['role']      = 'admin';
                $_SESSION['email']     = $email;
                $_SESSION['firstname'] = $row['firstname'];

                if ($remember) {
                    RememberService::remember($con, (int)$row['user_id']);
                }

                logMe($email, date('Y-m-d H:i:s'), 'admin login');

                header('Location: ' . url('admin-dashboard'));
                exit;
            } else {
                // One message for both cases: naming which half was wrong
                // tells an outsider which addresses are admin accounts.
                pc_throttle_hit($con, $throttle);
                $left = ADMIN_MAX_TRIES === PHP_INT_MAX
                    ? 1
                    : ADMIN_MAX_TRIES - pc_throttle_count($con, $throttle, ADMIN_LOCK_SECS);

                if ($left <= 0) {
                    // The old message said "5 minutes" whatever the admin had
                    // actually configured.
                    $mins = max(1, (int)round(ADMIN_LOCK_SECS / 60));
                    $login_error = 'Too many failed attempts. Try again in '
                        . $mins . ' minute' . ($mins === 1 ? '' : 's') . '.';
                } else {
                    $login_error = 'Incorrect email or password. '
                        . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left.';
                }
            }
        }
    }
}

$csrf        = csrf_token();
$home_url    = url('welcomepage');
$signup_url  = url('admin-signup');
$forgot_url  = url('forgot-password');
$google_url  = url('google-login') . '?mode=login&from=admin';
$support     = defined('MAIL_FROM') && MAIL_FROM !== '' ? MAIL_FROM : '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin Login — PeerConnect</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&display=swap" rel="stylesheet">
    <?php $pcAuthDir = 'back'; ?>
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
            --wash: #EEF5FD;
            --danger: #B42318;
            --danger-bg: #FEF3F2;
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

        .al-shell {
            min-height: 100%;
            display: grid;
            grid-template-columns: minmax(0, 1.02fr) minmax(0, 1fr);
        }

        /* ── Left ───────────────────────────────────────────────────────── */
        .al-left {
            position: relative;
            background-image: url("<?= htmlspecialchars(asset('images/adminBg.png')) ?>");
            background-size: cover;
            background-position: center;
            display: flex;
            isolation: isolate;
        }

        .al-left::before {
            content: "";
            position: absolute;
            inset: 0;
            z-index: 0;
            background: linear-gradient(180deg, rgba(7, 16, 51, .35), rgba(7, 16, 51, .55));
        }

        .al-panel {
            position: relative;
            z-index: 2;
            width: min(560px, 62%);
            padding: 46px 92px 40px 44px;
            display: flex;
            flex-direction: column;
            gap: 30px;
            color: #fff;
            background: linear-gradient(158deg, var(--navy) 0%, var(--navy-2) 58%, var(--navy-3) 100%);
            clip-path: polygon(0 0, 100% 0, calc(100% - 78px) 100%, 0 100%);
        }

        .al-brand { display: flex; align-items: center; gap: 13px; text-decoration: none; color: inherit; }
        .al-brand svg { width: 42px; height: 42px; flex: 0 0 42px; }
        .al-brand-name { font-size: 24px; font-weight: 700; line-height: 1.1; }
        .al-brand-name em { font-style: normal; color: var(--blue-2); }
        .al-brand-tag { font-size: 11.5px; color: rgba(255, 255, 255, .62); }

        .al-hero { display: flex; flex-direction: column; gap: 14px; }

        .al-hero h1 {
            margin: 0;
            font-size: clamp(30px, 3.4vw, 42px);
            font-weight: 700;
            line-height: 1.14;
            letter-spacing: -.02em;
            text-wrap: balance;
        }

        .al-hero h1 span { color: var(--sky); }

        .al-kicker {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
            color: var(--sky);
        }

        .al-hero p {
            margin: 0;
            max-width: 42ch;
            font-size: 15px;
            color: rgba(255, 255, 255, .76);
        }

        .al-points { display: flex; flex-direction: column; gap: 18px; }
        .al-point { display: flex; gap: 14px; align-items: flex-start; }

        .al-point-ico {
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

        .al-point-ico svg { width: 20px; height: 20px; }
        .al-point-t { font-size: 15px; font-weight: 600; margin: 2px 0 2px; }
        .al-point-d { font-size: 13.5px; color: rgba(255, 255, 255, .66); max-width: 34ch; }

        .al-foot { margin-top: auto; display: flex; flex-direction: column; gap: 12px; }

        /* Three segments, decorative — this page has no steps to track. */
        .al-rule { display: flex; gap: 6px; width: min(350px, 100%); }
        .al-rule i { height: 3px; border-radius: 99px; background: rgba(255, 255, 255, .18); flex: 1; }
        .al-rule i:first-child { background: var(--blue-2); }

        .al-foot span { font-size: 13px; color: rgba(255, 255, 255, .6); }

        .al-quote {
            position: absolute;
            z-index: 1;
            left: calc(min(560px, 62%) - 24px);
            top: 30%;
            width: min(300px, 31%);
            padding: 20px 22px 22px;
            border-radius: 14px;
            background: rgba(9, 18, 48, .52);
            border: 1px solid rgba(255, 255, 255, .16);
            backdrop-filter: blur(7px);
            -webkit-backdrop-filter: blur(7px);
            color: #fff;
        }

        .al-quote-mark { font-size: 30px; line-height: 1; color: rgba(255, 255, 255, .5); }
        .al-quote p { margin: 6px 0 14px; font-size: 17px; font-weight: 500; line-height: 1.38; }
        .al-quote i { display: block; width: 46px; height: 2px; background: rgba(255, 255, 255, .45); }

        /* ── Right ──────────────────────────────────────────────────────── */
        .al-right {
            display: flex;
            flex-direction: column;
            padding: 26px 34px 40px;
        }

        .al-back {
            align-self: flex-end;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 600;
            color: var(--navy-2);
            text-decoration: none;
            padding: 8px 4px;
        }

        .al-back:hover { color: var(--blue); }
        .al-back svg { width: 16px; height: 16px; }

        .al-mid { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; }

        .al-card {
            width: min(520px, 100%);
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 16px;
            box-shadow: 0 1px 2px rgba(16, 24, 40, .04), 0 24px 48px -32px rgba(16, 24, 40, .28);
            padding: 34px 36px 30px;
        }

        .al-shield {
            width: 62px;
            height: 62px;
            margin: 0 auto 16px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: var(--wash);
            color: var(--blue);
        }

        .al-shield svg { width: 28px; height: 28px; }

        .al-card h2 {
            margin: 0 0 5px;
            font-size: 27px;
            font-weight: 700;
            letter-spacing: -.02em;
            text-align: center;
            color: var(--navy-2);
        }

        .al-card-sub { margin: 0 0 24px; text-align: center; font-size: 14px; color: var(--ink-3); }

        .al-f { display: flex; flex-direction: column; gap: 6px; margin-bottom: 15px; }
        .al-f > label { font-size: 13.5px; font-weight: 600; color: var(--navy-2); }

        .al-in { position: relative; display: flex; align-items: center; }

        .al-in > svg:first-child {
            position: absolute;
            left: 13px;
            width: 17px;
            height: 17px;
            color: var(--ink-3);
            pointer-events: none;
        }

        .al-in input {
            width: 100%;
            height: 48px;
            padding: 0 14px 0 40px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #fff;
            font-family: inherit;
            font-size: 14.5px;
            color: var(--ink);
        }

        .al-in input::placeholder { color: #A7AEC0; }

        .al-in input:focus {
            outline: none;
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(27, 111, 209, .14);
        }

        .al-peek {
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

        .al-peek:hover { color: var(--ink-2); background: var(--line-2); }
        .al-peek svg { width: 17px; height: 17px; }
        .al-in:has(.al-peek) input { padding-right: 44px; }

        .al-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin: 4px 0 20px;
        }

        .al-check { display: inline-flex; align-items: center; gap: 9px; font-size: 14px; color: var(--ink-2); cursor: pointer; }
        .al-check input { width: 17px; height: 17px; accent-color: var(--blue); margin: 0; }

        .al-forgot { font-size: 14px; color: var(--blue); font-weight: 500; text-decoration: none; }
        .al-forgot:hover { text-decoration: underline; }

        .al-btn {
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

        .al-btn:hover { background: var(--navy-3); }
        .al-btn:focus-visible { outline: 3px solid rgba(27, 111, 209, .4); outline-offset: 2px; }
        .al-btn svg { width: 17px; height: 17px; }

        .al-or {
            display: flex;
            align-items: center;
            gap: 14px;
            margin: 20px 0;
            font-size: 13px;
            color: var(--ink-3);
        }

        .al-or::before,
        .al-or::after { content: ""; flex: 1; height: 1px; background: var(--line); }

        .al-google {
            width: 100%;
            height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 11px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #fff;
            color: var(--ink);
            font-family: inherit;
            font-size: 14.5px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
        }

        .al-google:hover { background: var(--line-2); }
        .al-google svg { width: 19px; height: 19px; }

        .al-note {
            display: flex;
            gap: 11px;
            align-items: flex-start;
            margin-top: 20px;
            padding: 14px 16px;
            border-radius: 10px;
            background: var(--wash);
            border: 1px solid #D5E6F8;
        }

        .al-note svg { flex: 0 0 18px; width: 18px; height: 18px; color: var(--blue); margin-top: 1px; }
        .al-note b { display: block; font-size: 13.5px; color: var(--navy-2); }
        .al-note span { font-size: 12.5px; color: var(--ink-2); }

        .al-help { margin: 20px 0 0; text-align: center; font-size: 14px; color: var(--ink-3); }
        .al-help a { color: var(--blue); font-weight: 600; text-decoration: none; }
        .al-help a:hover { text-decoration: underline; }

        .al-alert {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 13.5px;
            margin-bottom: 18px;
            background: var(--danger-bg);
            color: var(--danger);
            border: 1px solid #FBD3CE;
        }

        .al-alert svg { flex: 0 0 17px; width: 17px; height: 17px; margin-top: 1px; }

        /* ── Responsive ─────────────────────────────────────────────────── */
        @media (max-width: 1080px) {
            .al-shell { grid-template-columns: 1fr; }
            .al-left { min-height: 300px; }

            .al-panel {
                width: 100%;
                clip-path: none;
                padding: 30px 26px 26px;
                gap: 22px;
                background: linear-gradient(158deg, rgba(7, 16, 51, .95), rgba(22, 38, 92, .92));
            }

            .al-points { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
            .al-quote { display: none; }
            .al-right { padding: 18px 18px 46px; }
            .al-back { align-self: flex-start; }
        }

        @media (max-width: 620px) {
            .al-card { padding: 26px 20px 22px; border-radius: 14px; }
            .al-card h2 { font-size: 23px; }
            .al-points { grid-template-columns: 1fr; }
        }
    </style>
</head>

<body>
    <div class="al-shell">

        <!-- ══════════ Left ══════════ -->
        <div class="al-left pc-auth-panel">
            <div class="al-panel">
                <a class="al-brand" href="<?= htmlspecialchars($home_url) ?>">
                    <svg viewBox="0 0 48 48" fill="none" aria-hidden="true">
                        <circle cx="24" cy="24" r="21" fill="#fff" opacity=".12" />
                        <circle cx="24" cy="24" r="12.5" stroke="#fff" stroke-width="2.6" />
                        <path d="M18 22.4c0-2.2 1.8-4 4-4 1.8 0 2.9.8 3.2 2 .3-1.2 1.4-2 3.2-2 2.2 0 4 1.8 4 4 0 3.6-5 6-7.2 7-2.2-1-7.2-3.4-7.2-7Z" stroke="#8FC4F5" stroke-width="2" stroke-linejoin="round" />
                    </svg>
                    <span>
                        <span class="al-brand-name">PEER<em>CONNECT</em></span><br>
                        <span class="al-brand-tag">Mentoring. Growing. Together.</span>
                    </span>
                </a>

                <div class="al-hero">
                    <h1>Admin Access <span>For a Stronger Community</span></h1>
                    <p class="al-kicker">Monitor. Manage. Support. Empower.</p>
                    <p>The admin dashboard gives you the tools to keep PeerConnect safe, organized, and impactful for mentors and mentees.</p>
                </div>

                <div class="al-points">
                    <div class="al-point">
                        <span class="al-point-ico">
                            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" d="M5 20V11M12 20V4M19 20v-6" />
                            </svg>
                        </span>
                        <div>
                            <p class="al-point-t">Real-time Insights</p>
                            <p class="al-point-d">Track users, sessions, and program impact.</p>
                        </div>
                    </div>

                    <div class="al-point">
                        <span class="al-point-ico">
                            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7.5 3.2v5.1c0 4.3-3 8.2-7.5 9.7-4.5-1.5-7.5-5.4-7.5-9.7V6.2L12 3Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="m9 12 2 2 4-4" />
                            </svg>
                        </span>
                        <div>
                            <p class="al-point-t">Ensure a Safe Space</p>
                            <p class="al-point-d">Manage accounts and maintain community standards.</p>
                        </div>
                    </div>

                    <div class="al-point">
                        <span class="al-point-ico">
                            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM20 19c0-1.8-1.2-3.3-2.8-3.8M17 4.4a3 3 0 0 1 0 5.2" />
                            </svg>
                        </span>
                        <div>
                            <p class="al-point-t">Support Growth</p>
                            <p class="al-point-d">Help mentors and mentees succeed.</p>
                        </div>
                    </div>

                    <div class="al-point">
                        <span class="al-point-ico">
                            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 12h2m12 0h2M12 4v2m0 12v2M6.3 6.3l1.4 1.4m8.6 8.6 1.4 1.4m0-11.4-1.4 1.4m-8.6 8.6-1.4 1.4" />
                            </svg>
                        </span>
                        <div>
                            <p class="al-point-t">Efficient Management</p>
                            <p class="al-point-d">All the tools you need in one place.</p>
                        </div>
                    </div>
                </div>

                <div class="al-foot">
                    <div class="al-rule"><i></i><i></i><i></i></div>
                    <span>Empowering mentorship for a brighter tomorrow.</span>
                </div>
            </div>

            <figure class="al-quote">
                <div class="al-quote-mark">&ldquo;</div>
                <p>A well-managed community creates endless opportunities.</p>
                <i></i>
            </figure>
        </div>

        <!-- ══════════ Right ══════════ -->
        <div class="al-right">
            <a class="al-back" href="<?= htmlspecialchars($home_url) ?>">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 12H5m0 0 5.5-5.5M5 12l5.5 5.5" />
                </svg>
                Back to Home
            </a>

            <div class="al-mid">
                <div class="al-card pc-auth-card">
                    <div class="al-shield">
                        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7.5 3.2v5.1c0 4.3-3 8.2-7.5 9.7-4.5-1.5-7.5-5.4-7.5-9.7V6.2L12 3Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="m9 12 2 2 4-4" />
                        </svg>
                    </div>

                    <h2>Admin Login</h2>
                    <p class="al-card-sub">Access the PeerConnect admin dashboard.</p>

                    <?php if ($login_error !== ''): ?>
                        <div class="al-alert" role="alert">
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <circle cx="12" cy="12" r="9" />
                                <path stroke-linecap="round" d="M12 7.5v5M12 16h.01" />
                            </svg>
                            <span><?= htmlspecialchars($login_error) ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="post" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

                        <div class="al-f">
                            <label for="al-email">Email Address</label>
                            <div class="al-in">
                                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                    <rect x="3" y="5.5" width="18" height="13" rx="2.5" />
                                    <path stroke-linecap="round" d="m4 7 8 6 8-6" />
                                </svg>
                                <input id="al-email" name="email" type="email" required autocomplete="username"
                                    placeholder="Enter your admin email"
                                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="al-f">
                            <label for="al-pass">Password</label>
                            <div class="al-in">
                                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                    <rect x="4.5" y="10" width="15" height="10" rx="2" />
                                    <path stroke-linecap="round" d="M8 10V7.5a4 4 0 0 1 8 0V10" />
                                </svg>
                                <input id="al-pass" name="password" type="password" required autocomplete="current-password"
                                    placeholder="Enter your password">
                                <button type="button" class="al-peek" onclick="alPeek(this)" aria-label="Show password">
                                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" />
                                        <circle cx="12" cy="12" r="3" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="al-row">
                            <label class="al-check">
                                <input type="checkbox" name="remember" value="1">
                                Remember me
                            </label>
                            <a class="al-forgot" href="<?= htmlspecialchars($forgot_url) ?>">Forgot password?</a>
                        </div>

                        <button type="submit" name="admin_login" value="1" class="al-btn">
                            Sign In
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 12h15m0 0-5.5-5.5M19 12l-5.5 5.5" />
                            </svg>
                        </button>
                    </form>

                    <div class="al-or">or</div>

                    <a class="al-google" href="<?= htmlspecialchars($google_url) ?>">
                        <svg viewBox="0 0 48 48" aria-hidden="true">
                            <path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.6l6.7-6.7C35.6 2.6 30.2.5 24 .5 14.6.5 6.5 5.8 2.6 13.6l7.8 6c1.9-5.6 7.2-10.1 13.6-10.1Z" />
                            <path fill="#4285F4" d="M46.5 24.5c0-1.6-.15-3.2-.44-4.7H24v9.1h12.7c-.55 2.9-2.2 5.4-4.7 7.1l7.6 5.9c4.4-4.1 6.9-10.2 6.9-17.4Z" />
                            <path fill="#FBBC05" d="M10.4 28.4a14.5 14.5 0 0 1 0-8.8l-7.8-6a24 24 0 0 0 0 20.8l7.8-6Z" />
                            <path fill="#34A853" d="M24 47.5c6.2 0 11.5-2 15.3-5.6l-7.6-5.9c-2.1 1.4-4.8 2.3-7.7 2.3-6.4 0-11.7-4.5-13.6-10.1l-7.8 6C6.5 42.2 14.6 47.5 24 47.5Z" />
                        </svg>
                        Sign in with Google
                    </a>

                    <div class="al-note">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" />
                            <path stroke-linecap="round" d="M12 11v5M12 8v.01" />
                        </svg>
                        <span>
                            <b>Restricted to authorized administrators.</b>
                            Sign-ins are recorded with the date and the account used.
                        </span>
                    </div>

                    <p class="al-help">
                        <?php if ($support !== ''): ?>
                            Need help? <a href="mailto:<?= htmlspecialchars($support) ?>?subject=PeerConnect%20admin%20access">Contact system support</a>
                        <?php else: ?>
                            Need an admin account? <a href="<?= htmlspecialchars($signup_url) ?>">Create one</a> &mdash; you'll need the admin key.
                        <?php endif; ?>
                    </p>

                    <?php if ($support !== ''): ?>
                        <p class="al-help" style="margin-top:6px;">
                            Need an admin account? <a href="<?= htmlspecialchars($signup_url) ?>">Create one</a>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function alPeek(btn) {
            const f = btn.parentElement.querySelector('input');
            const shown = f.type === 'text';
            f.type = shown ? 'password' : 'text';
            btn.setAttribute('aria-label', shown ? 'Show password' : 'Hide password');
        }
    </script>
</body>

</html>
